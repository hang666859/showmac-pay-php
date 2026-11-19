<?php

declare(strict_types=1);

namespace ShowMac\Pay\Tests;

use PHPUnit\Framework\TestCase;
use ShowMac\Pay\Client;
use ShowMac\Pay\PayException;

/**
 * 客户端行为测试。用 PHP 内置服务器起一个假网关，校验签名与字段注入是否与 Java SDK 一致。
 */
final class ClientTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $base = '';
    private static string $docroot = '';

    public static function setUpBeforeClass(): void
    {
        self::$docroot = sys_get_temp_dir() . '/showmac-fake-gateway-' . getmypid();
        @mkdir(self::$docroot, 0700, true);
        file_put_contents(self::$docroot . '/router.php', <<<'PHP'
<?php
$apiKey = getenv('FAKE_API_KEY') ?: 'K';
$raw = file_get_contents('php://input');
file_put_contents(sys_get_temp_dir() . '/showmac-last-request.json', $raw);
$body = json_decode($raw, true) ?: [];
$mode = $_SERVER['REQUEST_URI'] ?? '';
if (str_contains($mode, '__business_error__')) {
    $resp = ['code' => 9999, 'msg' => '验签失败'];
} else {
    $data = ['payOrderId' => 'P1', 'orderState' => 1, 'refundOrderId' => 'R1', 'state' => 1];
    $items = [];
    foreach ($data as $k => $v) { $items[] = "$k=$v&"; }
    sort($items, SORT_STRING | SORT_FLAG_CASE);
    $resp = ['code' => 0, 'msg' => 'SUCCESS', 'data' => $data,
             'sign' => strtoupper(md5(implode('', $items) . 'key=' . $apiKey))];
}
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($resp, JSON_UNESCAPED_UNICODE);
PHP);

        $port = self::freePort();
        self::$base = "http://127.0.0.1:{$port}";
        $cmd = sprintf(
            'FAKE_API_KEY=K %s -S 127.0.0.1:%d %s/router.php > /dev/null 2>&1 &',
            escapeshellarg(PHP_BINARY), $port, escapeshellarg(self::$docroot),
        );
        exec($cmd);
        usleep(400_000);
    }

    public static function tearDownAfterClass(): void
    {
        exec('pkill -f ' . escapeshellarg(self::$docroot . '/router.php'));
        @unlink(sys_get_temp_dir() . '/showmac-last-request.json');
    }

    private static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function client(): Client
    {
        return new Client('M1', 'A1', 'K', self::$base, 5);
    }

    private static function lastRequest(): array
    {
        return json_decode((string) file_get_contents(sys_get_temp_dir() . '/showmac-last-request.json'), true);
    }

    public function testUnifiedOrderInjectsPublicFields(): void
    {
        $res = self::client()->unifiedOrder('mho1', 'WX_NATIVE', 1, '标题', '描述',
            channelExtra: ['payDataType' => 'codeImgUrl']);

        $body = self::lastRequest();
        self::assertSame('M1', $body['mchNo']);
        self::assertSame('A1', $body['appId']);
        self::assertSame('1.0', $body['version']);
        self::assertSame('MD5', $body['signType']);
        self::assertSame(13, strlen($body['reqTime']));
        self::assertSame('{"payDataType":"codeImgUrl"}', $body['channelExtra']);
        self::assertSame('P1', $res['payOrderId']);
    }

    public function testRequiresOrderNo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('至少传一个');
        self::client()->queryOrder();
    }

    public function testRefundSendsRequiredFields(): void
    {
        self::client()->refund('r1', 1, '用户取消', null, 'P1');
        $body = self::lastRequest();
        self::assertSame('CNY', $body['currency']);
        self::assertSame('用户取消', $body['refundReason']);
    }

    public function testBusinessErrorRaises(): void
    {
        $client = new Client('M1', 'A1', 'K', self::$base . '/__business_error__', 5);
        try {
            $client->queryOrder('mho1');
            self::fail('应当抛 PayException');
        } catch (PayException $e) {
            self::assertSame(9999, $e->bizCode);
            self::assertStringContainsString('验签失败', $e->getMessage());
        }
    }

    public function testNetworkError(): void
    {
        $client = new Client('M1', 'A1', 'K', 'http://127.0.0.1:1', 2);
        $this->expectException(PayException::class);
        $this->expectExceptionMessage('网络异常');
        $client->queryOrder('mho1');
    }
}
