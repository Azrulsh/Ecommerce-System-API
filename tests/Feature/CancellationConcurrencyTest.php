<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

// Separate committed connections are essential: transaction-wrapped fixtures cannot prove contention.
class CancellationConcurrencyTest extends TestCase
{
    private array $userIds = [];

    private ?int $productId = null;

    private ?int $categoryId = null;

    private array $workers = [];

    private function runCancellations(bool $sameOrder): void
    {
        $category = Category::factory()->create();
        $this->categoryId = $category->id;
        $product = Product::factory()->for($category)->create(['price' => '29.99', 'stock_quantity' => 10]);
        $this->productId = $product->id;
        $orders = [];
        foreach (range(1, $sameOrder ? 1 : 2) as $number) {
            $customer = User::factory()->create();
            $this->userIds[] = $customer->id;
            $cart = $customer->cart()->create();
            $cart->items()->create(['product_id' => $product->id, 'quantity' => 2]);
            $orders[] = app(CheckoutService::class)->checkout($customer, 'cancel-race')['order'];
        }
        $targets = $sameOrder ? [$orders[0], $orders[0]] : $orders;
        DB::beginTransaction();
        try {
            Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            foreach ($targets as $order) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/cancellation-worker.php'), (string) $order->id], base_path());
                $process->setTimeout(30);
                $process->start();
                $this->workers[] = $process;
            }
            $observed = false;
            $deadline = microtime(true) + 12;
            while (microtime(true) < $deadline) {
                $ids = [];
                foreach ($this->workers as $worker) {
                    if (preg_match('/"connection_id":(\d+)/', $worker->getOutput(), $match)) {
                        $ids[] = (int) $match[1];
                    }
                    if (! $worker->isRunning()) {
                        $this->fail('Cancellation worker exited before contention: '.$worker->getOutput().$worker->getErrorOutput());
                    }
                }
                if (count($ids) === 2) {
                    $waiting = DB::selectOne('SELECT COUNT(DISTINCT trx.trx_mysql_thread_id) AS total
                        FROM information_schema.INNODB_LOCK_WAITS waits
                        JOIN information_schema.INNODB_TRX trx ON trx.trx_id = waits.requesting_trx_id
                        WHERE trx.trx_mysql_thread_id IN (?, ?)', $ids)->total;
                    if ((int) $waiting === 2) {
                        $observed = true;
                        break;
                    }
                }
                usleep(250000);
            }
            $this->assertTrue($observed, 'Both cancellation transactions must actually overlap and wait on row locks.');
        } finally {
            DB::rollBack();
        }
        foreach ($this->workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getOutput().$worker->getErrorOutput());
            $lines = array_values(array_filter(explode("\n", trim($worker->getOutput()))));
            $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(200, $result['status']);
        }
        $this->assertSame(10, $product->fresh()->stock_quantity, 'Each order restores its purchased quantity exactly once.');
        $this->assertSame(count($orders), DB::table('refunds')->whereIn('payment_id', array_map(fn ($order) => $order->payment->id, $orders))->count());
        foreach ($orders as $order) {
            $this->assertSame('cancelled', $order->fresh()->status->value);
            $this->assertNotNull($order->fresh()->stock_restored_at);
            $this->assertSame('refunded', $order->payment->fresh()->status);
            $this->assertSame('59.98', $order->payment->refund->amount);
        }
    }

    public function test_overlapping_retries_refund_and_restore_the_same_order_only_once(): void
    {
        $this->runCancellations(true);
    }

    public function test_overlapping_cancellations_of_different_orders_do_not_lose_stock_increments(): void
    {
        $this->runCancellations(false);
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
                throw new \RuntimeException('Refusing cancellation fixture cleanup outside the isolated testing database.');
            }
            Order::whereIn('user_id', $this->userIds)->delete();
            User::whereIn('id', $this->userIds)->delete();
            if ($this->productId !== null) {
                Product::withTrashed()->whereKey($this->productId)->forceDelete();
            }
            if ($this->categoryId !== null) {
                Category::whereKey($this->categoryId)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
