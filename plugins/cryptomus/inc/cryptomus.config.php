<?php
/* *
 * Cryptomus配置文件
 */

// API地址
$cryptomus_config['api_url'] = $channel['appurl'] ?: 'https://api.cryptomus.com/v1/';

// 商户ID
$cryptomus_config['merchant_id'] = $channel['appid'];

// API密钥
$cryptomus_config['api_key'] = $channel['appkey'];

// 订单有效期(小时)
$cryptomus_config['lifetime'] = $channel['lifetime'] ?: 3;

// 是否允许多次支付
$cryptomus_config['is_payment_multiple'] = $channel['is_payment_multiple'] ?: 1;

// 设置默认货币
$cryptomus_config['currency'] = $channel['currency'] ?: 'CNY';