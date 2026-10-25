<?php
/**
 * Plugin Name: Kexiu ZibPay Security
 * Description: 加固子比 ZibPay 订单接口：防止订单 IDOR 枚举与余额支付 CSRF，不修改主题文件。
 * Version: 1.0.0
 * Author: Kexiu
 * Text Domain: kexiu-zibpay-security
 * Requires at least: 5.8
 */

defined( 'ABSPATH' ) || exit;

define( 'KEXIU_ZIBPAY_SECURITY_FILE', __FILE__ );
define( 'KEXIU_ZIBPAY_SECURITY_VERSION', '1.0.0' );

require_once __DIR__ . '/includes/class-payment-guard.php';

final class Kexiu_ZibPay_Security {

	public static function init() {
		add_action( 'after_setup_theme', [ __CLASS__, 'boot' ], 20 );
	}

	public static function boot() {
		if ( ! class_exists( 'zibpay' ) && ! class_exists( 'ZibPay' ) ) {
			return;
		}

		Kexiu_ZibPay_Payment_Guard::register();
	}
}

Kexiu_ZibPay_Security::init();
