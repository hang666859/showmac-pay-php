<?php

declare(strict_types=1);

namespace ShowMac\Pay;

/**
 * 签名算法。与网关服务端的签名规则一致（排序 + 拼接 + MD5 大写）。
 *
 * 这里只是按公开的接口规范实现，没有引用任何 Java 代码。
 * 自测向量见 sdk/test-vectors/sign-vectors.json，不依赖任何 SDK 即可复核。
 *
 * 容易踩的点：
 *  - 空值过滤：null 与 "" 不参与签名，但 0 / false 要参与（所以只能用全等比较）。
 *  - 排序：按整个 "key=value&" 片段做大小写不敏感比较，对应 usort + strcasecmp。
 *  - 布尔值：网关端是 "true"/"false"，PHP 必须先显式转换（否则是 "1"/""）。
 */
final class Signer
{
    /**
     * 拼接待签名字符串（调试用）。
     *
     * @param array<string,mixed> $params
     */
    public static function signStr(array $params, #[\SensitiveParameter] string $apiKey): string
    {
        $items = [];
        foreach ($params as $key => $value) {
            $text = self::normalize($value);
            if ($text === null) {
                continue;
            }
            $items[] = $key . '=' . $text . '&';
        }
        usort($items, static fn (string $a, string $b): int => strcasecmp($a, $b));

        return implode('', $items) . 'key=' . $apiKey;
    }

    /**
     * 计算签名：MD5 后转大写。
     *
     * @param array<string,mixed> $params
     */
    public static function sign(array $params, #[\SensitiveParameter] string $apiKey): string
    {
        return strtoupper(md5(self::signStr($params, $apiKey)));
    }

    /**
     * 校验签名。$params 可以含 sign，会自动剔除；比较不区分大小写。
     *
     * @param array<string,mixed> $params
     */
    public static function verify(array $params, #[\SensitiveParameter] string $apiKey): bool
    {
        $got = strtoupper(trim((string) ($params['sign'] ?? '')));
        if ($got === '') {
            return false;
        }
        unset($params['sign']);

        return hash_equals($got, self::sign($params, $apiKey));
    }

    /** 返回参与签名的字符串；null 表示该参数不参与。 */
    private static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_float($value) && floor($value) === $value) {
            // 网关端金额是整数（Long），浮点如 1.0 直接按 1 处理
            return (string) (int) $value;
        }
        $text = (string) $value;

        return $text === '' ? null : $text;
    }
}
