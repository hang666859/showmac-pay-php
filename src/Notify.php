<?php

declare(strict_types=1);

namespace ShowMac\Pay;

/**
 * 异步通知处理。
 *
 * 网关用 **form 表单** POST 通知，参数与签名算法和请求一致。
 * 接收方必须做三件事：验签 -> 幂等 -> 返回 "success"。
 */
final class Notify
{
    public const STATE_PAY_SUCCESS = '2';

    /**
     * 校验通知并返回参数；不合法时抛 \InvalidArgumentException，调用方应回 "fail"。
     *
     * @param array<string,mixed> $params
     * @param list<string>        $required 除签名外必须非空的字段
     * @return array<string,mixed>
     *
     * @throws \InvalidArgumentException
     */
    public static function verify(
        array $params,
        #[\SensitiveParameter] string $apiKey,
        array $required = ['mchOrderNo'],
    ): array {
        if (trim((string) ($params['sign'] ?? '')) === '') {
            throw new \InvalidArgumentException('通知缺少 sign');
        }
        // 签名会过滤空值：只带 sign 的空报文也能算出合法签名（等价于对 key=<apiKey> 求 MD5），
        // 所以验签之后必须再校验业务字段，否则等于放行空通知。
        if (!Signer::verify($params, $apiKey)) {
            throw new \InvalidArgumentException('通知验签失败');
        }
        foreach ($required as $field) {
            if (trim((string) ($params[$field] ?? '')) === '') {
                throw new \InvalidArgumentException("通知缺少必要字段 {$field}");
            }
        }

        return $params;
    }

    /** 通知/查单结果是否表示支付成功（条码支付是同步返回，下单响应里用 orderState）。 */
    public static function isPaid(array $params): bool
    {
        $state = (string) ($params['state'] ?? $params['orderState'] ?? '');

        return $state === self::STATE_PAY_SUCCESS;
    }

    /** 取用于幂等的单号：支付通知取 mchOrderNo，退款通知取 mchRefundNo。 */
    public static function orderNo(array $params): ?string
    {
        $no = $params['mchOrderNo'] ?? $params['mchRefundNo'] ?? null;

        return $no === null ? null : (string) $no;
    }
}
