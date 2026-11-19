<?php

declare(strict_types=1);

namespace ShowMac\Pay;

/**
 * 客户端异常。
 *
 * code 为网关业务码（0 成功），网络/HTTP 异常时为 null；raw 保留原始响应便于排查。
 */
class PayException extends \RuntimeException
{
    public function __construct(
        public readonly ?int $bizCode,
        string $message,
        public readonly mixed $raw = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, (int) ($bizCode ?? 0), $previous);
    }
}
