<?php
/**
 * ZibPay payment access guard.
 */

defined( 'ABSPATH' ) || exit;

final class Kexiu_ZibPay_Payment_Guard {

	public static function register() {
		add_action( 'wp_ajax_initiate_pay', [ __CLASS__, 'guard_initiate_pay' ], 1 );
		add_action( 'wp_ajax_nopriv_initiate_pay', [ __CLASS__, 'guard_initiate_pay' ], 1 );

		add_action( 'wp_ajax_order_pay_modal', [ __CLASS__, 'guard_order_pay_modal' ], 1 );
		add_action( 'wp_ajax_nopriv_order_pay_modal', [ __CLASS__, 'guard_order_pay_modal' ], 1 );

		if ( function_exists( 'zibpay_initiate_balance' ) ) {
			remove_filter( 'zibpay_initiate_balance', 'zibpay_initiate_balance' );
			add_filter( 'zibpay_initiate_balance', [ __CLASS__, 'guard_balance_pay' ], 10, 1 );
		}
	}

	public static function guard_initiate_pay() {
		$payment_id = ! empty( $_REQUEST['payment_id'] ) ? (int) $_REQUEST['payment_id'] : 0;
		if ( ! $payment_id ) {
			return;
		}

		if ( self::user_can_access_payment( $payment_id ) ) {
			return;
		}

		self::deny_json( __( '无权操作此订单', 'kexiu-zibpay-security' ) );
	}

	public static function guard_order_pay_modal() {
		$payment_id = ! empty( $_REQUEST['payment_id'] ) ? (int) $_REQUEST['payment_id'] : 0;
		if ( ! $payment_id ) {
			return;
		}

		if ( self::user_can_access_payment( $payment_id ) ) {
			return;
		}

		self::deny_modal( __( '无权查看此订单', 'kexiu-zibpay-security' ) );
	}

	/**
	 * @param array<string, mixed> $order_data
	 * @return array<string, mixed>
	 */
	public static function guard_balance_pay( $order_data ) {
		if ( ! is_array( $order_data ) ) {
			return [ 'error' => 1, 'msg' => __( '订单数据异常', 'kexiu-zibpay-security' ) ];
		}

		$order_user_id   = (int) ( $order_data['user_id'] ?? 0 );
		$current_user_id = get_current_user_id();

		if ( $order_user_id > 0 && $order_user_id !== $current_user_id ) {
			return [ 'error' => 1, 'msg' => __( '无权使用该订单的余额支付', 'kexiu-zibpay-security' ) ];
		}

		if ( ! function_exists( 'zibpay_initiate_balance' ) ) {
			return [ 'error' => 1, 'msg' => __( '余额支付不可用', 'kexiu-zibpay-security' ) ];
		}

		return zibpay_initiate_balance( $order_data );
	}

	public static function user_can_access_payment( $payment_id ) {
		$payment_id = (int) $payment_id;
		if ( $payment_id <= 0 || ! class_exists( 'zibpay' ) ) {
			return true;
		}

		if ( function_exists( 'zib_current_user_can' ) && zib_current_user_can( 'view_order' ) ) {
			return true;
		}

		$order_rows = zibpay::get_order_by_payment_id( $payment_id );
		if ( empty( $order_rows[0] ) ) {
			return true;
		}

		$order         = $order_rows[0];
		$order_user_id = (int) ( $order['user_id'] ?? 0 );
		$current_id    = get_current_user_id();

		if ( $order_user_id > 0 ) {
			return $order_user_id === $current_id;
		}

		return self::guest_owns_order( $order );
	}

	/**
	 * @param array<string, mixed> $order
	 */
	private static function guest_owns_order( $order ) {
		$order_id  = (int) ( $order['id'] ?? 0 );
		$post_id   = (int) ( $order['post_id'] ?? 0 );
		$order_num = trim( (string) ( $order['order_num'] ?? '' ) );

		if ( ! $order_id ) {
			return false;
		}

		$wait_ids = function_exists( 'zibpay_get_cookie_wait_pay_ids' )
			? array_map( 'intval', zibpay_get_cookie_wait_pay_ids() )
			: self::parse_wait_pay_ids_cookie();

		if ( in_array( $order_id, $wait_ids, true ) ) {
			return true;
		}

		if ( $post_id && $order_num ) {
			$cookie_key = 'zibpay_' . $post_id;
			if ( ! empty( $_COOKIE[ $cookie_key ] ) && trim( (string) $_COOKIE[ $cookie_key ] ) === $order_num ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return int[]
	 */
	private static function parse_wait_pay_ids_cookie() {
		$raw = ! empty( $_COOKIE['zibpay_wait_pay_ids'] ) ? (string) $_COOKIE['zibpay_wait_pay_ids'] : '';
		if ( '' === $raw ) {
			return [];
		}

		$ids = array_filter( array_map( 'intval', explode( ',', $raw ) ) );

		return array_values( $ids );
	}

	private static function deny_json( $message ) {
		if ( function_exists( 'zib_send_json_error' ) ) {
			zib_send_json_error(
				[
					'code' => 'forbidden',
					'msg'  => $message,
				]
			);
		}

		wp_send_json_error( [ 'msg' => $message ], 403 );
	}

	private static function deny_modal( $message ) {
		if ( function_exists( 'zib_ajax_notice_modal' ) ) {
			zib_ajax_notice_modal( 'danger', $message );
		}

		wp_die( esc_html( $message ), '', [ 'response' => 403 ] );
	}
}
