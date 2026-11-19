<?php

declare(strict_types=1);

namespace ShowMac\Pay\Tests;

use PHPUnit\Framework\TestCase;
use ShowMac\Pay\Notify;
use ShowMac\Pay\Signer;

final class NotifyTest extends TestCase
{
    public function testVerifyOk(): void
    {
        $params = ['mchOrderNo' => 'mho1', 'state' => '2', 'amount' => '1'];
        $params['sign'] = Signer::sign($params, 'K');
        self::assertSame('mho1', Notify::verify($params, 'K')['mchOrderNo']);
        self::assertTrue(Notify::isPaid($params));
        self::assertSame('mho1', Notify::orderNo($params));
    }

    public function testRejectsMissingSign(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('缺少 sign');
        Notify::verify(['mchOrderNo' => 'mho1'], 'K');
    }

    public function testRejectsSignOnlyPayload(): void
    {
        // 只带 sign 的空报文也能算出合法签名，必须靠必填字段拦住
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mchOrderNo');
        Notify::verify(['sign' => Signer::sign([], 'K')], 'K');
    }

    public function testRejectsTamperedAmount(): void
    {
        $params = ['mchOrderNo' => 'mho1', 'amount' => '1'];
        $params['sign'] = Signer::sign($params, 'K');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('验签失败');
        Notify::verify(['amount' => '100'] + $params, 'K');
    }

    public function testRefundNotifyUsesMchRefundNo(): void
    {
        $params = ['mchRefundNo' => 'r1', 'state' => '2'];
        $params['sign'] = Signer::sign($params, 'K');
        Notify::verify($params, 'K', ['mchRefundNo']);
        self::assertSame('r1', Notify::orderNo($params));
    }
}
