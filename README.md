# showmac/pay-sdk（PHP）

ShowMac 支付网关的 PHP 客户端。只依赖 `ext-curl` 与 `ext-json`，PHP 8.1+。

> 当前尚未发布到 Packagist。在本项目的 `composer.json` 里加 path 仓库即可引入：
>
> ```json
> "repositories": [{ "type": "path", "url": "../sdk/php" }],
> "require": { "showmac/pay-sdk": "*" }
> ```
>
> 发布后安装命令为 `composer require showmac/pay-sdk`。

## 快速开始

```php
<?php

use ShowMac\Pay\Client;

$client = new Client(
    mchNo:  'M1621873433953',
    appId:  '60cc09bce4b0f1c0b83761c9',
    apiKey: getenv('SHOWMAC_PAY_API_KEY'),   // 应用密钥，不要硬编码
    apiBase: 'https://<支付网关域名>',
);

$order = $client->unifiedOrder(
    mchOrderNo: 'mho' . time(),
    wayCode: 'WX_NATIVE',
    amount: 100,                              // 单位：分，必须是整数
    subject: '商品标题',
    body: '商品描述',
    notifyUrl: 'https://your-domain.com/notify/pay',
    returnUrl: 'https://your-domain.com/pay/result',
    channelExtra: ['payDataType' => 'codeImgUrl'],   // 需要时传，内部自动转 JSON
);

echo $order['payOrderId'], $order['payDataType'], $order['payData'];
```

其余接口：

```php
$client->queryOrder(payOrderId: 'P...');
$client->closeOrder(payOrderId: 'P...');
$client->refund(mchRefundNo: 'r1', refundAmount: 100, refundReason: '用户取消', payOrderId: 'P...');
$client->queryRefund(mchRefundNo: 'r1');
```

## 异步通知

网关用 **form 表单** POST 通知。接收方三件事：验签 → 幂等 → 返回 `success`。

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use ShowMac\Pay\Notify;

$params = $_POST;   // 通知是表单，不是 JSON

try {
    $params = Notify::verify($params, getenv('SHOWMAC_PAY_API_KEY'));
} catch (\InvalidArgumentException $e) {
    error_log('通知被拒: ' . $e->getMessage());
    echo 'fail';
    exit;
}

if (Notify::isPaid($params) && !alreadyProcessed(Notify::orderNo($params))) {
    markPaid($params);          // 用唯一索引保证幂等
}

echo 'success';                 // 必须是正好这七个字符
exit;
```

`Notify::verify()` 除了验签，还会检查 `mchOrderNo` 非空——因为签名算法会过滤空值，
**只带 `sign` 的空报文也能算出合法签名**，只看签名等于放行空通知。

## 行为说明

- 请求固定 `POST` + `application/json`，自动注入 `mchNo` / `appId` / `version` /
  `signType` / `reqTime` / `sign`，这六个字段**不要自己再传**。
- 响应 `sign` 只覆盖 `data`（不含 `code`/`msg`），默认校验；关掉等于放弃防伪造。
- 业务失败抛 `PayException`（`->bizCode` / `->getMessage()` / `->raw`）。
- 多商户：每个商户各建一个 `Client` 实例，不要用全局配置。

## 开发

```bash
composer install
composer test          # 21 个用例，含跨语言签名向量与假网关交互测试
```
