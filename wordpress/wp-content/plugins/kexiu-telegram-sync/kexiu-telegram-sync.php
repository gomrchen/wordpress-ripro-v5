<?php
/**
 * Plugin Name: Kexiu Telegram Sync
 * Description: 文章发布后自动同步到 Telegram 频道（图片、简介、链接）
 * Version: 1.3.1
 * Author: Kexiu
 * Text Domain: kexiu-telegram-sync
 */

defined( 'ABSPATH' ) || exit;

define( 'KEXIU_TG_SYNC_FILE', __FILE__ );
define( 'KEXIU_TG_SYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'KEXIU_TG_SYNC_VERSION', '1.3.1' );

require_once __DIR__ . '/includes/class-sync-log.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-telegram-api.php';
require_once __DIR__ . '/includes/class-post-sync.php';
require_once __DIR__ . '/includes/class-bulk-sync.php';

final class Kexiu_Telegram_Sync {

	public static function init() {
		Kexiu_TG_Settings::init();
		Kexiu_TG_Post_Sync::init();
		Kexiu_TG_Bulk_Sync::init();
	}

	public static function get_settings() {
		$defaults = [
			'enabled'          => false,
			'bot_token'        => '',
			'channel_id'       => '',
			'channels'         => [],
			'api_proxy_s'      => false,
			'api_proxy'        => '',
			'api_proxy_user'   => '',
			'api_proxy_pass'   => '',
			'post_types'       => [ 'post' ],
			'sync_on_update'   => false,
			'edit_on_update'   => true,
			'max_images'       => 6,
			'text_only'        => false,
			'image_strategy'   => 'url_then_upload',
			'category_ids'     => [],
			'tag_ids'          => [],
			'parse_mode'       => 'HTML',
			'template'         => "<b>{title}</b>\n\n{excerpt}\n\n🔗 <a href=\"{url}\">阅读原文</a>",
		];

		$settings = get_option( 'kexiu_telegram_sync', [] );
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$raw_settings = $settings;
		$settings     = wp_parse_args( $settings, $defaults );

		if ( ! array_key_exists( 'api_proxy_s', $raw_settings ) && ! empty( $settings['api_proxy'] ) ) {
			$settings['api_proxy_s'] = true;
		}

		if ( empty( $settings['parse_mode'] ) ) {
			$settings['parse_mode'] = 'HTML';
		}

		$settings['category_ids'] = array_values( array_filter( array_map( 'absint', (array) $settings['category_ids'] ) ) );
		$settings['tag_ids']      = array_values( array_filter( array_map( 'absint', (array) $settings['tag_ids'] ) ) );

		if ( empty( $settings['channels'] ) && ! empty( $settings['bot_token'] ) && ! empty( $settings['channel_id'] ) ) {
			$settings['channels'] = [
				[
					'id'         => 'primary',
					'label'      => '主频道',
					'enabled'    => true,
					'bot_token'  => $settings['bot_token'],
					'channel_id' => $settings['channel_id'],
				],
			];
		}

		$settings['channels'] = self::normalize_channels( (array) $settings['channels'] );

		$primary = self::get_primary_channel( $settings['channels'] );
		if ( $primary ) {
			$settings['bot_token']  = $primary['bot_token'];
			$settings['channel_id'] = $primary['channel_id'];
		}

		return $settings;
	}

	/**
	 * @param array $channels Raw channel rows.
	 * @return array
	 */
	public static function normalize_channels( array $channels ) {
		$normalized = [];

		foreach ( $channels as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$id = sanitize_key( $row['id'] ?? '' );
			if ( '' === $id ) {
				$id = 'ch' . ( $index + 1 );
			}

			$bot   = sanitize_text_field( $row['bot_token'] ?? '' );
			$chat  = sanitize_text_field( $row['channel_id'] ?? '' );

			if ( '' === $bot || '' === $chat ) {
				continue;
			}

			$normalized[] = [
				'id'         => $id,
				'label'      => sanitize_text_field( $row['label'] ?? $chat ),
				'enabled'    => ! empty( $row['enabled'] ),
				'bot_token'  => $bot,
				'channel_id' => $chat,
			];
		}

		return $normalized;
	}

	/**
	 * @param array $channels Channel list.
	 * @return array|null
	 */
	public static function get_primary_channel( array $channels ) {
		foreach ( $channels as $channel ) {
			if ( ! empty( $channel['enabled'] ) ) {
				return $channel;
			}
		}

		return $channels[0] ?? null;
	}

	/**
	 * @return array[]
	 */
	public static function get_active_channels( $settings = null ) {
		if ( null === $settings ) {
			$settings = self::get_settings();
		}

		$active = [];
		foreach ( (array) $settings['channels'] as $channel ) {
			if ( empty( $channel['enabled'] ) ) {
				continue;
			}
			if ( empty( $channel['bot_token'] ) || empty( $channel['channel_id'] ) ) {
				continue;
			}
			$active[] = $channel;
		}

		return $active;
	}

	public static function update_settings( array $settings ) {
		update_option( 'kexiu_telegram_sync', wp_parse_args( $settings, self::get_settings() ) );
	}

	/**
	 * @return array|null
	 */
	public static function get_proxy_config() {
		$settings = self::get_settings();

		if ( empty( $settings['api_proxy_s'] ) ) {
			return null;
		}

		return Kexiu_TG_Telegram_API::parse_proxy_config(
			$settings['api_proxy'] ?? '',
			$settings['api_proxy_user'] ?? '',
			$settings['api_proxy_pass'] ?? ''
		);
	}

	/**
	 * @return Kexiu_TG_Telegram_API
	 */
	public static function get_api() {
		$settings = self::get_settings();

		return new Kexiu_TG_Telegram_API( $settings['bot_token'], self::get_proxy_config() );
	}

	/**
	 * @param array $channel Channel config row.
	 * @return Kexiu_TG_Telegram_API
	 */
	public static function get_api_for_channel( array $channel ) {
		return new Kexiu_TG_Telegram_API( $channel['bot_token'] ?? '', self::get_proxy_config() );
	}

	/**
	 * @param WP_Error $error API error.
	 * @return string
	 */
	public static function format_api_error( WP_Error $error ) {
		$message = $error->get_error_message();

		if ( false !== stripos( $message, 'cURL error 28' ) ) {
			$message .= '。Bot 验证接口正常，但 sendMessage 发消息超时，多为当前服务器出口 IP 被 Telegram 限制，或机房屏蔽了发消息接口。可尝试：① 填写其他出口的 API 代理；② 换服务器/IP；③ 用 Cloudflare Worker 中转 Bot API。';
		}

		return $message;
	}
}

Kexiu_Telegram_Sync::init();
