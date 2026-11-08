<?php

use App\Models\Category;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Discount\Money;
use App\Services\UserPrivilegeService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Standalone isolated HTTP verification bootstrap, never a public application route.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$environment = Dotenv\Dotenv::parse(file_get_contents($root.'/.env.testing'));
if (($environment['DB_DATABASE'] ?? null) !== 'ecommerce_api_testing'
    || ($environment['DB_HOST'] ?? null) !== '127.0.0.1'
    || (string) ($environment['DB_PORT'] ?? '') !== '3306') {
    throw new RuntimeException('Unsafe HTTP smoke database configuration.');
}
$environment = array_replace($environment, ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql',
    'DB_DATABASE' => 'ecommerce_api_testing', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array']);
foreach ($environment as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached() || ! $app->environment('testing')
    || config('database.default') !== 'mysql'
    || ! empty(config('database.connections.mysql.url'))
    || ! empty(config('database.connections.mysql.read'))
    || ! empty(config('database.connections.mysql.write'))
    || DB::selectOne('SELECT DATABASE() AS name')->name !== 'ecommerce_api_testing') {
    throw new RuntimeException('Unsafe HTTP smoke actual database.');
}

// Real TCP HTTP requests, with temporary committed fixtures scoped to this run.
// No tokens or credentials are printed. The child server inherits only the verified testing environment.
$listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (! $listener) {
    throw new RuntimeException('Cannot allocate a loopback port.');
}
$address = stream_socket_get_name($listener, false);
fclose($listener);
$server = null;
$users = [];
$product = $category = $discount = null;
$extraCategories = [];
$checks = 0;
$request = function (string $method, string $path, int $expected, ?string $token = null, ?array $body = null, array $headers = []) use ($address, &$checks): array {
    $curl = curl_init('http://'.$address.$path);
    $requestHeaders = ['Accept: application/json', 'Content-Type: application/json', ...$headers];
    if ($token !== null) {
        $requestHeaders[] = 'Authorization: Bearer '.$token;
    }
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $requestHeaders, CURLOPT_TIMEOUT => 10]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($status !== $expected) {
        // Never print request headers or returned authentication tokens.
        throw new RuntimeException("Unexpected HTTP status $method $path: $status, expected $expected. $error");
    }
    $checks++;

    return $response === '' ? [] : json_decode($response, true, flags: JSON_THROW_ON_ERROR);
};
$verify = function (bool $condition, string $description): void {
    if (! $condition) {
        throw new RuntimeException('HTTP smoke assertion failed: '.$description);
    }
};

try {
    foreach (range(1, 3) as $number) {
        $users[] = User::factory()->create();
    }
    [$admin, $customer, $other] = $users;
    app(UserPrivilegeService::class)->assign($admin, true, false);
    $adminToken = $admin->createToken('isolated-http-smoke')->plainTextToken;
    $token = $customer->createToken('isolated-http-smoke')->plainTextToken;
    $otherToken = $other->createToken('isolated-http-smoke')->plainTextToken;
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create(['price' => '19.99', 'stock_quantity' => 10]);
    // A direct child avoids Windows taskkill/tree discovery, which restricted shells may block.
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $server = proc_open([PHP_BINARY, '-S', $address,
        base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
        [0 => ['file', $nullDevice, 'r'], 1 => ['file', $nullDevice, 'a'], 2 => ['file', $nullDevice, 'a']],
        $pipes, public_path(), null, ['bypass_shell' => true]);
    $verify(is_resource($server), 'server process created');
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $probe = @stream_socket_client('tcp://'.$address, $probeCode, $probeError, 0.1);
        if ($probe) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    $verify($ready, 'loopback server started');
    $request('GET', '/api/products/'.$product->id, 200);
    $legacy = $request('GET', '/discount?type=percentage&original=100&value=10', 200);
    $verify((float) $legacy['discounted_amount'] === 90.0, 'legacy discount remains correct');
    $request('GET', '/api/admin/orders', 401);
    $request('GET', '/api/admin/orders/999999999', 403, $token);
    $request('GET', '/api/wishlist', 401);
    $baseline = $request('GET', '/api/admin/sales-summary', 200, $adminToken)['data'];
    $review = $request('POST', "/api/products/$product->id/reviews", 201, $token, ['rating' => 4, 'comment' => 'Tested over HTTP.']);
    $reviewId = $review['data']['id'];
    $request('PATCH', "/api/reviews/$reviewId", 404, $otherToken, ['rating' => 1]);
    $request('PATCH', "/api/reviews/$reviewId", 200, $token, ['rating' => 5]);
    $reviews = $request('GET', "/api/products/$product->id/reviews?per_page=1", 200);
    $verify($reviews['summary']['average_rating'] === '5.00', 'review average');
    $verify(array_keys($reviews['data'][0]['reviewer']) === ['id', 'name'], 'reviewer privacy');
    $request('POST', '/api/wishlist/items', 201, $token, ['product_id' => $product->id]);
    $request('POST', '/api/wishlist/items', 200, $token, ['product_id' => $product->id]);
    $wishlist = $request('GET', '/api/wishlist', 200, $otherToken);
    $verify($wishlist['data'] === [], 'cross-user wishlist isolation');
    $request('DELETE', "/api/wishlist/items/$product->id", 404, $otherToken);
    $request('DELETE', "/api/wishlist/items/$product->id", 204, $token);
    $slug = 'http-smoke-'.strtolower(Str::random(12));
    $created = $request('POST', '/api/admin/categories', 201, $adminToken, ['name' => 'HTTP smoke', 'slug' => $slug]);
    $extraCategories[] = $created['data']['id'];
    $request('PATCH', '/api/admin/categories/'.$extraCategories[0], 200, $adminToken, ['name' => 'Updated HTTP category']);
    $request('DELETE', '/api/admin/categories/'.$extraCategories[0], 204, $adminToken);
    $request('DELETE', "/api/admin/categories/$category->id", 409, $adminToken);
    $created = $request('POST', '/api/admin/discounts', 201, $adminToken, ['name' => 'HTTP sale', 'type' => 'percentage', 'value' => '10.00']);
    $discount = Discount::findOrFail($created['data']['id']);
    $request('POST', "/api/admin/discounts/$discount->id/products", 200, $adminToken, ['product_ids' => [$product->id]]);
    $price = $request('GET', "/api/products/$product->id/price", 200);
    $verify($price['data']['final_price'] === '17.99', '10 percent discount rounds to RM2.00 savings');
    $request('POST', '/api/cart/items', 201, $token, ['product_id' => $product->id, 'quantity' => 3]);
    $checkout = $request('POST', '/api/checkout', 201, $token, [], ['Idempotency-Key: http-phase-five']);
    $orderId = $checkout['data']['id'];
    $verify($checkout['data']['grand_total'] === '53.97' && $product->fresh()->stock_quantity === 7, 'checkout snapshots and inventory');
    $paid = $request('GET', '/api/admin/sales-summary', 200, $adminToken)['data'];
    $verify($paid['total_orders'] === $baseline['total_orders'] + 1, 'report counts paid order');
    $verify(Money::minor($paid['net_sales']) - Money::minor($baseline['net_sales']) === 5397, 'report recognizes exact RM53.97');
    $request('PATCH', "/api/admin/orders/$orderId/status", 403, $token, ['status' => 'cancelled']);
    $request('PATCH', "/api/admin/orders/$orderId/status", 409, $adminToken, ['status' => 'cancelled'], ['X-Mock-Refund: fail']);
    $verify($product->fresh()->stock_quantity === 7, 'refund failure rolls back inventory restoration');
    $cancelled = $request('PATCH', "/api/admin/orders/$orderId/status", 200, $adminToken, ['status' => 'cancelled']);
    $replayed = $request('PATCH', "/api/admin/orders/$orderId/status", 200, $adminToken, ['status' => 'cancelled']);
    $verify($cancelled === $replayed && $product->fresh()->stock_quantity === 10, 'cancellation is idempotent');
    $verify($cancelled['data']['payment']['refund']['amount'] === '53.97', 'refund amount is exact');
    $summary = $request('GET', '/api/admin/sales-summary', 200, $adminToken)['data'];
    $verify($summary['net_sales'] === $baseline['net_sales'], 'cancelled sale excluded from recognized revenue');
    $request('GET', "/api/orders/$orderId", 404, $otherToken);
    $request('GET', "/api/orders/$orderId", 200, $token);
    $request('DELETE', "/api/admin/discounts/$discount->id/products/$product->id", 204, $adminToken);
    $request('DELETE', "/api/admin/discounts/$discount->id", 204, $adminToken);
    $request('DELETE', "/api/reviews/$reviewId", 204, $adminToken);
    echo json_encode(['database' => 'ecommerce_api_testing', 'http_checks' => $checks,
        'checkout_total' => '53.97', 'refund_amount' => '53.97', 'stock_before_after' => [10, 10],
        'recognized_sales_after_cancellation' => $summary['net_sales'], 'result' => 'passed'], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (! $app->environment('testing') || DB::selectOne('SELECT DATABASE() AS name')->name !== 'ecommerce_api_testing') {
        throw new RuntimeException('Refusing HTTP fixture cleanup outside isolated testing database.');
    }
    $ids = array_map(fn ($user) => $user->id, $users);
    Order::whereIn('user_id', $ids)->delete();
    foreach ($users as $user) {
        // Sanctum's polymorphic tokens have no database cascade on a bulk user delete.
        $user->tokens()->delete();
    }
    User::whereIn('id', $ids)->delete();
    if ($discount) {
        $discount->delete();
    }
    if ($product) {
        $product->forceDelete();
    }
    Category::whereIn('id', $extraCategories)->delete();
    if ($category) {
        $category->delete();
    }
}
