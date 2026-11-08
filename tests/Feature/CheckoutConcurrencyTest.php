<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

// Committed fixtures are required for child connections. Cleanup is limited to owned IDs.
// Deliberately does not use DatabaseTransactions or reset the testing database.
class CheckoutConcurrencyTest extends TestCase
{
    private array $userIds = [];

    private array $productIds = [];

    private ?int $categoryId = null;

    private array $workers = [];

    private function fixtures(bool $sameCustomer): array
    {
        $category = Category::factory()->create();
        $this->categoryId = $category->id;
        $product = Product::factory()->for($category)->create(['price' => '10.00', 'stock_quantity' => 1]);
        $this->productIds[] = $product->id;
        $first = User::factory()->create();
        $this->userIds[] = $first->id;
        $second = $sameCustomer ? $first : User::factory()->create();
        $this->userIds = array_values(array_unique([...$this->userIds, $second->id]));
        foreach (array_unique([$first->id, $second->id]) as $userId) {
            $cart = User::findOrFail($userId)->cart()->create();
            $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        }

        return [$product, $first, $second];
    }

    private function contend(Product $product, User $first, User $second, bool $sameKey): array
    {
        DB::beginTransaction();
        try {
            Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            foreach ([[$first, 'race-key-a'], [$second, $sameKey ? 'race-key-a' : 'race-key-b']] as [$customer, $key]) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/checkout-worker.php'), (string) $customer->id, $key], base_path());
                $process->setTimeout(30);
                $process->start();
                $this->workers[] = $process;
            }
            $deadline = microtime(true) + 12;
            $observed = false;
            while (microtime(true) < $deadline) {
                $ids = [];
                foreach ($this->workers as $worker) {
                    if (preg_match('/"connection_id":(\d+)/', $worker->getOutput(), $matches)) {
                        $ids[] = (int) $matches[1];
                    }
                    if (! $worker->isRunning()) {
                        $this->fail('Checkout worker exited before contention: '.$worker->getOutput().$worker->getErrorOutput());
                    }
                }
                if (count($ids) === 2) {
                    // Both independently connected transactions must actually be waiting on row locks.
                    $waiting = DB::selectOne('SELECT COUNT(DISTINCT trx.trx_mysql_thread_id) AS total
                        FROM information_schema.INNODB_LOCK_WAITS waits
                        JOIN information_schema.INNODB_TRX trx ON trx.trx_id = waits.requesting_trx_id
                        WHERE trx.trx_mysql_thread_id IN (?, ?)', $ids)->total;
                    if ((int) $waiting === 2) {
                        $observed = true;
                        break;
                    }
                }
                // InnoDB information-schema snapshots are cached; leave time for refresh.
                usleep(250000);
            }
            $diagnostics = array_map(fn ($worker) => $worker->getOutput().$worker->getErrorOutput(), $this->workers);
            $this->assertTrue($observed, 'Did not observe both MySQL transactions concurrently blocked; sequential outcomes are not sufficient. Workers: '.json_encode($diagnostics));
        } finally {
            DB::rollBack(); // Release only the test's temporary inventory barrier.
        }
        $results = [];
        foreach ($this->workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getOutput().$worker->getErrorOutput());
            $lines = array_values(array_filter(explode("\n", trim($worker->getOutput()))));
            $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    public function test_competing_customers_cannot_oversell_the_last_unit(): void
    {
        [$product, $first, $second] = $this->fixtures(false);
        $results = $this->contend($product, $first, $second, false);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertSame(0, $product->fresh()->stock_quantity);
        $this->assertSame(1, Order::whereIn('user_id', $this->userIds)->count());
        $this->assertSame(1, Payment::whereHas('order', fn ($orders) => $orders->whereIn('user_id', $this->userIds))->count());
        $this->assertSame(1, DB::table('cart_items')->join('carts', 'carts.id', '=', 'cart_items.cart_id')->whereIn('user_id', $this->userIds)->count());
    }

    public function test_simultaneous_retries_create_one_order_and_one_payment(): void
    {
        [$product, $first, $second] = $this->fixtures(true);
        $results = $this->contend($product, $first, $second, true);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 201], $statuses);
        $this->assertSame($results[0]['order_id'], $results[1]['order_id']);
        $this->assertSame(0, $product->fresh()->stock_quantity);
        $this->assertSame(1, Order::whereIn('user_id', $this->userIds)->count());
        $this->assertSame(1, Payment::whereHas('order', fn ($orders) => $orders->whereIn('user_id', $this->userIds))->count());
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            if (! $this->app || ! $this->app->environment('testing')
                || DB::selectOne('SELECT DATABASE() AS name')->name !== 'ecommerce_api_testing') {
                throw new \RuntimeException('Refusing concurrency fixture cleanup outside the isolated testing database.');
            }
            // The base TestCase has already verified the actual isolated test connection.
            Order::whereIn('user_id', $this->userIds)->delete();
            User::whereIn('id', $this->userIds)->delete();
            Product::withTrashed()->whereIn('id', $this->productIds)->forceDelete();
            if ($this->categoryId !== null) {
                Category::whereKey($this->categoryId)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
