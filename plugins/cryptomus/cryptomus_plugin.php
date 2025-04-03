<?php

class cryptomus_plugin
{
	static public $info = [
		'name'        => 'cryptomus', //支付插件英文名称，需和目录名称一致，不能有重复
		'showname'    => 'Cryptomus加密货币支付', //支付插件显示名称
		'author'      => 'caileisir', //支付插件作者
		'link'        => 'https://github.com/caileisir/cryptomus_epay', //支付插件作者链接
		'types'       => ['crypto'], //支付插件支持的支付方式
		'currencies'  => ['CNY', 'USD', 'EUR'], //插件支持的默认货币，此处设置为人民币
		'inputs' => [ //支付插件要求传入的参数以及参数显示名称
			'appurl' => [
				'name' => 'API地址',
				'type' => 'input',
				'note' => '默认值：https://api.cryptomus.com/v1/',
			],
			'appid' => [
				'name' => '商户ID',
				'type' => 'input',
				'note' => '在Cryptomus商户后台获取的Merchant ID',
			],
			'appkey' => [
				'name' => 'API密钥',
				'type' => 'input',
				'note' => '在Cryptomus商户后台获取的API Key',
			],
			'currency' => [
				'name' => '默认货币',
				'type' => 'select',
				'options' => [
					'CNY' => '人民币(CNY)',
					'USD' => '美元(USD)',
					'EUR' => '欧元(EUR)',
				],
				'default' => 'CNY',
				'note' => '选择默认计价货币',
			],
			'lifetime' => [
				'name' => '订单有效期(小时)',
				'type' => 'select',
				'options' => [
					'1' => '1小时',
					'3' => '3小时',
					'6' => '6小时',
					'12' => '12小时',
					'24' => '24小时',
				],
				'default' => '3',
			],
			'is_payment_multiple' => [
				'name' => '允许多次支付',
				'type' => 'select',
				'options' => [
					'1' => '是',
					'0' => '否',
				],
				'default' => '1',
				'note' => '是否允许用户多次支付同一订单',
			],
		],
		'select' => null,
		'note' => '使用Cryptomus接收加密货币付款，支持比特币、以太坊等多种加密货币。', //支付密钥填写说明
		'bindwxmp' => false, //是否支持绑定微信公众号
		'bindwxa' => false, //是否支持绑定微信小程序
	];

	static public function submit(){
		global $siteurl, $channel, $order, $ordername, $sitename, $conf;

		require_once(PAY_ROOT."inc/cryptomus/vendor/autoload.php");
		require_once(PAY_ROOT."inc/cryptomus.config.php");

		$apiKey = $cryptomus_config['api_key'];
		$merchantId = $cryptomus_config['merchant_id'];
		$lifetime = $cryptomus_config['lifetime'] ?? 3; // 默认3小时
		$is_payment_multiple = $cryptomus_config['is_payment_multiple'] ?? true;
		$currency = $cryptomus_config['currency'] ?? 'CNY'; // 获取设置的默认货币

		// 创建请求参数
		$params = [
			'amount' => (string)$order['realmoney'],
			'currency' => $currency, // 使用配置的默认货币
			'order_id' => TRADE_NO,
			'url_return' => $siteurl.'pay/return/'.TRADE_NO.'/',
			'url_callback' => $conf['localurl'].'pay/notify/'.TRADE_NO.'/',
			'is_payment_multiple' => (bool)$is_payment_multiple,
			'lifetime' => (string)(3600 * (int)$lifetime),
			'is_refresh' => false,
			'plugin_name' => 'custom_payment_system'
		];
		
		try {
			// 使用官方SDK创建支付
			$payment = \Cryptomus\Api\Client::payment($apiKey, $merchantId);
			$paymentCreate = $payment->create($params);
			
			if(isset($paymentCreate['url'])) {
				// 成功创建支付，跳转到支付页面
				return ['type'=>'jump','url'=>$paymentCreate['url']];
			} else {
				// 支付创建失败
				return ['type'=>'error','msg'=>'创建支付失败，请联系管理员'];
			}
		} catch (\Exception $e) {
			// 异常处理
			return ['type'=>'error','msg'=>'支付系统错误：'.$e->getMessage()];
		}
	}

	// 异步回调
	static public function notify(){
		global $channel, $order;

		require_once(PAY_ROOT."inc/cryptomus/vendor/autoload.php");
		require_once(PAY_ROOT."inc/cryptomus.config.php");

		// 获取回调数据
		$json = file_get_contents('php://input');
		$data = json_decode($json, true);
		
		if(empty($data) || empty($data['payload']) || empty($data['signature'])) {
			return ['type'=>'html','data'=>'fail'];
		}
		
		// 验证签名
		$signature = $data['signature'];
		$payload = json_encode($data['payload']);
		$calculatedSignature = hash_hmac('sha512', $payload, $cryptomus_config['api_key']);
		
		if(hash_equals($calculatedSignature, $signature)) {
			// 签名验证成功
			$payment_data = $data['payload'] ?? [];
			
			// 订单号
			$order_id = $payment_data['order_id'] ?? '';
			
			// 交易号
			$trade_no = $payment_data['uuid'] ?? '';
			
			// 支付状态
			$status = $payment_data['status'] ?? '';
			
			// 支付金额
			$amount = $payment_data['amount'] ?? 0;
			
			// 检查支付状态
			$success = !empty($payment_data['is_final']) && ($status === 'paid' || $status === 'paid_over');
			
			if($success && $order_id == TRADE_NO) {
				// 验证订单金额
				if(round($amount, 2) >= round($order['realmoney'], 2)) {
					// 处理订单支付成功
					processNotify($order, $trade_no);
				}
				return ['type'=>'html','data'=>'success'];
			}
		}
		
		// 验证失败
		return ['type'=>'html','data'=>'fail'];
	}

	// 同步回调
	static public function return(){
		global $channel, $order;

		require_once(PAY_ROOT."inc/cryptomus/vendor/autoload.php");
		require_once(PAY_ROOT."inc/cryptomus.config.php");

		$apiKey = $cryptomus_config['api_key'];
		$merchantId = $cryptomus_config['merchant_id'];
		
		try {
			// 使用官方SDK查询订单
			$payment = \Cryptomus\Api\Client::payment($apiKey, $merchantId);
			$params = ['order_id' => TRADE_NO];
			$result = $payment->info($params);
			
			if(!empty($result)) {
				$status = $result['status'] ?? '';
				$uuid = $result['uuid'] ?? '';
				
				if($status === 'paid' || $status === 'paid_over') {
					// 支付成功
					processReturn($order, $uuid);
				} else if($status === 'partially_paid') {
					return ['type'=>'error','msg'=>'订单金额支付不足，请联系客服'];
				} else if($status === 'confirming') {
					return ['type'=>'error','msg'=>'支付正在确认中，请稍后刷新页面'];
				} else {
					return ['type'=>'error','msg'=>'订单支付未完成，状态：'.$status];
				}
			} else {
				return ['type'=>'error','msg'=>'订单查询失败'];
			}
		} catch (\Exception $e) {
			return ['type'=>'error','msg'=>'查询订单失败：'.$e->getMessage()];
		}
	}
}
