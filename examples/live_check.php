<?php

declare(strict_types=1);

/**
 * 对真实网关的联调脚本（不纳入单元测试，需要本地网关与测试商户）。
 *
 * 用法：
 *   MCH_NO=... APP_ID=... API_KEY=... GATEWAY=http://127.0.0.1:9216 \
 *       php examples/live_check.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    // 没跑 composer install 时兜底：直接按 PSR-4 加载 src/
    spl_autoload_register(static function (string $class): void {
        $prefix = 'ShowMac\\Pay\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

use ShowMac\Pay\Client;
use ShowMac\Pay\PayException;

$client = new Client(
    mchNo: (string) getenv('MCH_NO'),
    appId: (string) getenv('APP_ID'),
    apiKey: (string) getenv('API_KEY'),
    apiBase: getenv('GATEWAY') ?: 'http://127.0.0.1:9216',
);

echo "== 统一下单 ==\n";
$order = $client->unifiedOrder(
    mchOrderNo: 'live' . (int) floor(microtime(true) * 1000),
    wayCode: 'WX_NATIVE',
    amount: 1,
    subject: 'SDK 联调',
    body: 'live check',
    notifyUrl: 'http://127.0.0.1:9/notify/pay',
);
echo "  payOrderId = {$order['payOrderId']}\n";
echo "  orderState = {$order['orderState']} | payDataType = " . ($order['payDataType'] ?? '-') . "\n";

echo "== 查询订单 ==\n";
$query = $client->queryOrder(payOrderId: $order['payOrderId']);
echo "  state = {$query['state']} | amount = {$query['amount']}\n";

echo "== 关单（未支付订单可能返回业务错误）==\n";
try {
    $closed = $client->closeOrder(payOrderId: $order['payOrderId']);
    echo '  state = ' . ($closed['state'] ?? '-') . "\n";
} catch (PayException $e) {
    echo "  PayException: [{$e->bizCode}] {$e->getMessage()}\n";
}
