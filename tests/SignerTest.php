<?php

declare(strict_types=1);

namespace ShowMac\Pay\Tests;

use PHPUnit\Framework\TestCase;
use ShowMac\Pay\Notify;
use ShowMac\Pay\PayException;
use ShowMac\Pay\Signer;

/**
 * 签名与通知的纯函数测试（不依赖网络）。
 */
final class SignerTest extends TestCase
{
    /** @return array<int,array{string,array<string,mixed>,string,string}> */
    public static function vectorProvider(): array
    {
        $file = dirname(__DIR__, 2) . '/test-vectors/sign-vectors.json';
        $json = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($json['vectors'] as $v) {
            $cases[] = [$v['name'], $v['params'], $v['apiKey'], $v['expected']];
        }

        return $cases;
    }

    /** @return array<int,array{string,array<string,mixed>,string,string}> */
    public static function canonicalProvider(): array
    {
        $file = dirname(__DIR__, 2) . '/test-vectors/sign-vectors.json';
        $json = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($json['vectors'] as $v) {
            $cases[] = [$v['name'], $v['params'], $v['apiKey'], $v['canonical']];
        }

        return $cases;
    }

    /**
     * 向量期望值即 MD5(规范化字符串 + key)，与语言和库无关。
     *
     * @dataProvider vectorProvider
     */
    public function testSignMatchesVector(string $name, array $params, string $apiKey, string $expected): void
    {
        self::assertSame($expected, Signer::sign($params, $apiKey), $name);
    }

    /**
     * 规范化字符串本身也要对：签名对、串不对，说明是碰巧撞上的。
     *
     * @dataProvider canonicalProvider
     */
    public function testSignStrMatchesCanonical(string $name, array $params, string $apiKey, string $canonical): void
    {
        self::assertSame($canonical . 'key=' . $apiKey, Signer::signStr($params, $apiKey), $name);
    }

    public function testEmptyValuesFiltered(): void
    {
        self::assertSame('a=1&key=K', Signer::signStr(['a' => '1', 'b' => '', 'c' => null], 'K'));
    }

    public function testZeroIsNotEmpty(): void
    {
        self::assertStringContainsString('amount=0&', Signer::signStr(['amount' => 0, 'a' => '1'], 'K'));
        self::assertNotSame(Signer::sign(['a' => '1'], 'K'), Signer::sign(['amount' => 0, 'a' => '1'], 'K'));
    }

    public function testBoolUsesJavaLowercase(): void
    {
        self::assertSame('paid=true&key=K', Signer::signStr(['paid' => true], 'K'));
        self::assertSame('paid=false&key=K', Signer::signStr(['paid' => false], 'K'));
    }

    public function testFloatAmountNormalizedToInt(): void
    {
        self::assertSame(
            Signer::signStr(['amount' => 1], 'K'),
            Signer::signStr(['amount' => 1.0], 'K'),
        );
    }

    public function testVerifyIgnoresCase(): void
    {
        $params = ['a' => '1', 'b' => '2'];
        $params['sign'] = strtolower(Signer::sign($params, 'K'));
        self::assertTrue(Signer::verify($params, 'K'));
    }

    public function testVerifyRejectsTampered(): void
    {
        $params = ['mchOrderNo' => 'mho1', 'amount' => '1'];
        $params['sign'] = Signer::sign($params, 'K');
        self::assertFalse(Signer::verify(['amount' => '100'] + $params, 'K'));
    }
}
