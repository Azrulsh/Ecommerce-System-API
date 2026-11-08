<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderWorkflowService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// Standalone worker for genuine MySQL contention tests, never a public application route.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$environment = Dotenv\Dotenv::parse(file_get_contents($root.'/.env.testing'));
if (($environment['DB_DATABASE'] ?? null) !== 'ecommerce_api_testing'
    || ($environment['DB_HOST'] ?? null) !== '127.0.0.1'
    || (string) ($environment['DB_PORT'] ?? '') !== '3306') {
    throw new RuntimeException('Unsafe cancellation worker database configuration.');
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
    throw new RuntimeException('Unsafe cancellation worker actual database.');
}
DB::statement('SET SESSION innodb_lock_wait_timeout = 20');
echo json_encode(['connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id]).PHP_EOL;
flush();
try {
    $order = app(OrderWorkflowService::class)->transition(Order::findOrFail((int) $argv[1]), OrderStatus::Cancelled);
    echo json_encode(['status' => 200, 'order_id' => $order->id]).PHP_EOL;
} catch (HttpExceptionInterface $exception) {
    echo json_encode(['status' => $exception->getStatusCode()]).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['status' => 500, 'error' => $exception->getMessage()]).PHP_EOL;
    exit(1);
}
