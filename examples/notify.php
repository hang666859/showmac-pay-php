<?php

declare(strict_types=1);

/**
 * 异步通知接收示例（原生 PHP，无框架）。
 *
 * 三件事缺一不可：验签 -> 幂等 -> 返回 "success"。
 *
 * 部署要点：
 *   1. 网关用 form 表单 POST 通知，必须从 $_POST 取值，不能把 php://input 当 JSON 解；
 *   2. 响应体必须正好是 success（小写、无引号、无 BOM、无多余空白）；
 *   3. 必须在 20 秒内响应，网关最多重试 6 次（间隔 0/30/60/90/120/150 秒）。
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

use ShowMac\Pay\Notify;

$apiKey = (string) getenv('SHOWMAC_PAY_API_KEY');
$stateDir = sys_get_temp_dir() . '/showmac-idem';
@mkdir($stateDir, 0700, true);

function reply(string $body): never
{
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo $body;
    exit;
}

$params = $_POST;
if ($params === []) {                      // 兜底：万一以 JSON 提交
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $params = $decoded;
    }
}

try {
    $params = Notify::verify($params, $apiKey);
} catch (InvalidArgumentException $e) {
    error_log('[showmac] 通知被拒: ' . $e->getMessage());
    reply('fail');
}

$no = (string) Notify::orderNo($params);
$markFile = $stateDir . '/' . sha1($no);
if (is_file($markFile)) {
    error_log("[showmac] 重复通知，忽略: {$no}");
    reply('success');                      // 已处理过仍必须回 success
}

if (Notify::isPaid($params)) {
    // 真实项目：这里发货 / 开通服务，用 mchOrderNo 唯一索引保证幂等；
    // 落库成功后写标记，落库失败则不标记，让网关重试。
    error_log(sprintf(
        '[showmac] 支付成功 payOrderId=%s mchOrderNo=%s amount=%s',
        $params['payOrderId'] ?? '-',
        $no,
        $params['amount'] ?? '-',
    ));
} else {
    error_log("[showmac] state={$params['state']} no={$no}");
}

file_put_contents($markFile, date('c'));
reply('success');
