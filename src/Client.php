<?php

declare(strict_types=1);

namespace ShowMac\Pay;

/**
 * 支付网关客户端。每个商户/应用一个实例，不要用全局可变配置。
 *
 * ```php
 * $client = new Client('M1621873433953', '60cc09bce4b0f1c0b83761c9', $apiKey, 'https://pay.example.com');
 * $order = $client->unifiedOrder('mho' . time(), 'WX_NATIVE', 100, '商品标题', '商品描述',
 *     notifyUrl: 'https://your-domain.com/notify/pay');
 * ```
 *
 * 请求构造遵循网关的 HTTP 接口规范：POST + application/json，
 * body 里带 version / signType / reqTime / sign。
 */
final class Client
{
    private const API_VERSION = '1.0';
    private const SIGN_TYPE = 'MD5';
    private const TIMEOUT = 20;

    public function __construct(
        private readonly string $mchNo,
        private readonly string $appId,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $apiBase,
        private readonly int $timeoutSeconds = self::TIMEOUT,
        /** 是否校验响应签名。**生产环境务必保持 true**。 */
        private readonly bool $verifyResponse = true,
    ) {
        if ($mchNo === '' || $appId === '' || $apiKey === '') {
            throw new \InvalidArgumentException('mchNo / appId / apiKey 均不能为空');
        }
        if ($apiBase === '') {
            throw new \InvalidArgumentException('apiBase 不能为空');
        }
    }

    // ------------------------------------------------------------ 底层 HTTP

    /**
     * 发请求并返回响应里的 data；业务失败抛 PayException。
     *
     * @param array<string,mixed> $biz
     * @return array<string,mixed>
     *
     * @throws PayException
     */
    public function post(string $uri, array $biz): array
    {
        $params = [];
        foreach ($biz as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $params[$k] = $v;
        }
        $params['mchNo']    = $this->mchNo;
        $params['appId']    = $this->appId;
        $params['version']  = self::API_VERSION;
        $params['signType'] = self::SIGN_TYPE;
        $params['reqTime']  = (string) (int) floor(microtime(true) * 1000);
        $params['sign']     = Signer::sign($params, $this->apiKey);

        $body = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new PayException(null, '请求参数 JSON 编码失败: ' . json_last_error_msg());
        }

        $ch = curl_init(rtrim($this->apiBase, '/') . '/' . ltrim($uri, '/'));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=UTF-8',
                'Accept: application/json',
                'Accept-Charset: UTF-8',
                'Accept-Language: zh-CN',
                'User-Agent: showmac-pay-php/1.0.0',
            ],
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // 不调 curl_close()：PHP 8.0+ 起它已无作用且 8.5 起被废弃，
        // 句柄离开作用域后由 GC 释放。
        unset($ch);

        if ($raw === false) {
            throw new PayException(null, '网络异常: ' . $err);
        }

        $result = json_decode((string) $raw, true);
        if (!is_array($result)) {
            throw new PayException(
                null,
                "响应不是合法 JSON (HTTP {$code}): " . substr((string) $raw, 0, 200),
            );
        }

        if (($result['code'] ?? null) !== 0) {
            throw new PayException(
                is_int($result['code'] ?? null) ? $result['code'] : null,
                (string) ($result['msg'] ?? '未知错误'),
                $result,
            );
        }

        $this->checkResponseSign($result);

        return $result['data'] ?? [];
    }

    /**
     * 响应里的 sign 只覆盖 data，不含 code/msg。
     *
     * @param array<string,mixed> $result
     */
    private function checkResponseSign(array $result): void
    {
        if (!$this->verifyResponse) {
            return;
        }
        $data = $result['data'] ?? null;
        $respSign = $result['sign'] ?? null;
        if ($data === null) {
            if (!empty($respSign)) {
                throw new PayException(0, '响应 data 为空但存在 sign，报文异常', $result);
            }

            return;
        }
        $expected = Signer::sign($data, $this->apiKey);
        if (strtoupper((string) $respSign) !== $expected) {
            throw new PayException(0, "响应验签失败: 收到 {$respSign} 本地 {$expected}", $result);
        }
    }

    // ------------------------------------------------------------ 业务接口

    /**
     * 统一下单。$amount 单位：分。
     *
     * @param array<string,mixed>|null $channelExtra 内部会序列化成紧凑 JSON 参与签名
     * @return array<string,mixed>
     */
    public function unifiedOrder(
        string $mchOrderNo,
        string $wayCode,
        int $amount,
        string $subject,
        string $body,
        string $clientIp = '127.0.0.1',
        string $currency = 'CNY',
        ?string $notifyUrl = null,
        ?string $returnUrl = null,
        ?int $expiredTime = null,
        ?array $channelExtra = null,
        ?string $extParam = null,
        ?int $divisionMode = null,
    ): array {
        $biz = [
            'mchOrderNo'   => $mchOrderNo,
            'wayCode'      => $wayCode,
            'amount'       => $amount,
            'currency'     => $currency,
            'clientIp'     => $clientIp,
            'subject'      => $subject,
            'body'         => $body,
            'notifyUrl'    => $notifyUrl,
            'returnUrl'    => $returnUrl,
            'expiredTime'  => $expiredTime,
            'extParam'     => $extParam,
            'divisionMode' => $divisionMode,
        ];
        if ($channelExtra !== null) {
            $biz['channelExtra'] = json_encode(
                $channelExtra,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        return $this->post('api/pay/unifiedOrder', $biz);
    }

    /** 查询支付订单。两个单号至少传一个。 */
    public function queryOrder(?string $mchOrderNo = null, ?string $payOrderId = null): array
    {
        $this->requireOne($mchOrderNo, $payOrderId, 'mchOrderNo / payOrderId');

        return $this->post('api/pay/query', [
            'mchOrderNo' => $mchOrderNo,
            'payOrderId' => $payOrderId,
        ]);
    }

    /** 关闭支付订单。 */
    public function closeOrder(?string $mchOrderNo = null, ?string $payOrderId = null): array
    {
        $this->requireOne($mchOrderNo, $payOrderId, 'mchOrderNo / payOrderId');

        return $this->post('api/pay/close', [
            'mchOrderNo' => $mchOrderNo,
            'payOrderId' => $payOrderId,
        ]);
    }

    /**
     * 发起退款。$refundAmount 单位：分。
     *
     * 注意 currency 与 refundReason 在网关侧是必填（@NotBlank），漏传会返回
     * 「货币代码不能为空」「退款原因不能为空」，与签名无关。
     *
     * @param array<string,mixed>|null $channelExtra
     * @return array<string,mixed>
     */
    public function refund(
        string $mchRefundNo,
        int $refundAmount,
        string $refundReason,
        ?string $mchOrderNo = null,
        ?string $payOrderId = null,
        string $currency = 'CNY',
        string $clientIp = '127.0.0.1',
        ?string $notifyUrl = null,
        ?array $channelExtra = null,
        ?string $extParam = null,
    ): array {
        $this->requireOne($mchOrderNo, $payOrderId, 'mchOrderNo / payOrderId');

        $biz = [
            'mchRefundNo'  => $mchRefundNo,
            'refundAmount' => $refundAmount,
            'currency'     => $currency,
            'refundReason' => $refundReason,
            'clientIp'     => $clientIp,
            'mchOrderNo'   => $mchOrderNo,
            'payOrderId'   => $payOrderId,
            'notifyUrl'    => $notifyUrl,
            'extParam'     => $extParam,
        ];
        if ($channelExtra !== null) {
            $biz['channelExtra'] = json_encode(
                $channelExtra,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        return $this->post('api/refund/refundOrder', $biz);
    }

    /** 查询退款订单。 */
    public function queryRefund(?string $mchRefundNo = null, ?string $refundOrderId = null): array
    {
        $this->requireOne($mchRefundNo, $refundOrderId, 'mchRefundNo / refundOrderId');

        return $this->post('api/refund/query', [
            'mchRefundNo'   => $mchRefundNo,
            'refundOrderId' => $refundOrderId,
        ]);
    }

    private function requireOne(?string $a, ?string $b, string $names): void
    {
        if (($a === null || $a === '') && ($b === null || $b === '')) {
            throw new \InvalidArgumentException("{$names} 至少传一个");
        }
    }
}
