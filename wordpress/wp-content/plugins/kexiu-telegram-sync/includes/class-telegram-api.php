<?php
/**
 * Telegram Bot API client.
 */

defined( 'ABSPATH' ) || exit;

class Kexiu_TG_Telegram_API {

	private $token;
	private $proxy_config;

	/**
	 * @param string       $bot_token    Bot token.
	 * @param array|string $proxy_config Proxy URL string or parsed config array.
	 */
	public function __construct( $bot_token, $proxy_config = null ) {
		$this->token = trim( (string) $bot_token );

		if ( is_string( $proxy_config ) ) {
			$proxy_config = self::parse_proxy_config( $proxy_config );
		}

		$this->proxy_config = is_array( $proxy_config ) ? $proxy_config : null;
	}

	public function is_configured() {
		return '' !== $this->token;
	}

	/**
	 * @param string $proxy_url  Proxy URL, e.g. socks5://127.0.0.1:7890
	 * @param string $proxy_user Optional username.
	 * @param string $proxy_pass Optional password.
	 * @return array|null
	 */
	public static function parse_proxy_config( $proxy_url, $proxy_user = '', $proxy_pass = '' ) {
		$proxy_url = trim( (string) $proxy_url );
		if ( '' === $proxy_url ) {
			return null;
		}

		if ( ! preg_match( '#^[a-z0-9+.-]+://#i', $proxy_url ) ) {
			$proxy_url = 'socks5://' . $proxy_url;
		}

		$parts = wp_parse_url( $proxy_url );
		if ( empty( $parts['host'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'socks5' ) );
		$type   = CURLPROXY_HTTP;

		if ( in_array( $scheme, [ 'socks5', 'socks5h' ], true ) ) {
			$type = CURLPROXY_SOCKS5_HOSTNAME;
		} elseif ( in_array( $scheme, [ 'socks4', 'socks4a' ], true ) ) {
			$type = CURLPROXY_SOCKS4;
		}

		$user = trim( (string) $proxy_user );
		$pass = (string) $proxy_pass;

		if ( '' === $user && isset( $parts['user'] ) ) {
			$user = rawurldecode( (string) $parts['user'] );
		}

		if ( '' === $pass && isset( $parts['pass'] ) ) {
			$pass = rawurldecode( (string) $parts['pass'] );
		}

		return [
			'type' => $type,
			'host' => $parts['host'],
			'port' => (int) ( $parts['port'] ?? ( 'http' === $scheme || 'https' === $scheme ? 8080 : 1080 ) ),
			'user' => $user,
			'pass' => $pass,
		];
	}

	/**
	 * @param string $channel_id Channel @username or numeric ID.
	 * @param string $text       Message text.
	 * @param array  $args       Extra API args.
	 * @return array|WP_Error
	 */
	public function send_message( $channel_id, $text, $args = [] ) {
		return $this->request(
			'sendMessage',
			array_merge(
				[
					'chat_id'                  => $channel_id,
					'text'                     => $text,
					'parse_mode'               => $args['parse_mode'] ?? 'HTML',
					'disable_web_page_preview' => false,
				],
				$args
			)
		);
	}

	/**
	 * @param string $channel_id Channel ID.
	 * @param string $photo_url  Public image URL.
	 * @param string $caption    Photo caption.
	 * @param array  $args       Extra API args.
	 * @return array|WP_Error
	 */
	public function send_photo( $channel_id, $photo_url, $caption, $args = [] ) {
		return $this->request(
			'sendPhoto',
			array_merge(
				[
					'chat_id'    => $channel_id,
					'photo'      => $photo_url,
					'caption'    => $caption,
					'parse_mode' => $args['parse_mode'] ?? 'HTML',
				],
				$args
			)
		);
	}

	/**
	 * @param string $channel_id Channel ID.
	 * @param string $file_path  Local file path.
	 * @param string $caption    Photo caption.
	 * @param array  $args       Extra API args.
	 * @return array|WP_Error
	 */
	public function send_photo_file( $channel_id, $file_path, $caption, $args = [] ) {
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'tg_file_missing', '图片文件不可读：' . $file_path );
		}

		return $this->request_multipart(
			'sendPhoto',
			array_merge(
				[
					'chat_id'    => $channel_id,
					'caption'    => $caption,
					'parse_mode' => $args['parse_mode'] ?? 'HTML',
				],
				$args
			),
			[
				'photo' => $file_path,
			]
		);
	}

	/**
	 * @param string $channel_id Channel ID.
	 * @param array  $media      InputMedia array.
	 * @return array|WP_Error
	 */
	public function send_media_group( $channel_id, array $media ) {
		return $this->request(
			'sendMediaGroup',
			[
				'chat_id' => $channel_id,
				'media'   => $media,
			]
		);
	}

	/**
	 * @param string $channel_id  Channel ID.
	 * @param array  $media_items Each item: path, caption (optional), parse_mode (optional).
	 * @return array|WP_Error
	 */
	public function send_media_group_files( $channel_id, array $media_items ) {
		$media   = [];
		$files   = [];
		$caption = '';
		$parse   = 'HTML';

		foreach ( $media_items as $index => $item ) {
			$field = 'file' . $index;
			$path  = $item['path'] ?? '';

			if ( ! is_readable( $path ) ) {
				return new WP_Error( 'tg_file_missing', '相册图片不可读：' . $path );
			}

			$files[ $field ] = $path;
			$entry           = [
				'type'  => 'photo',
				'media' => 'attach://' . $field,
			];

			if ( 0 === $index && ! empty( $item['caption'] ) ) {
				$entry['caption']    = $item['caption'];
				$entry['parse_mode'] = $item['parse_mode'] ?? 'HTML';
				$caption             = $entry['caption'];
				$parse               = $entry['parse_mode'];
			}

			$media[] = $entry;
		}

		return $this->request_multipart(
			'sendMediaGroup',
			[
				'chat_id' => $channel_id,
				'media'   => wp_json_encode( $media ),
			],
			$files
		);
	}

	/**
	 * @param string $channel_id Channel ID.
	 * @param int    $message_id Message ID.
	 * @param string $text       New text.
	 * @param array  $args       Extra args.
	 * @return array|WP_Error
	 */
	public function edit_message_text( $channel_id, $message_id, $text, $args = [] ) {
		return $this->request(
			'editMessageText',
			array_merge(
				[
					'chat_id'    => $channel_id,
					'message_id' => (int) $message_id,
					'text'       => $text,
					'parse_mode' => $args['parse_mode'] ?? 'HTML',
				],
				$args
			)
		);
	}

	/**
	 * @param string $channel_id Channel ID.
	 * @param int    $message_id Message ID.
	 * @param string $caption    New caption.
	 * @param array  $args       Extra args.
	 * @return array|WP_Error
	 */
	public function edit_message_caption( $channel_id, $message_id, $caption, $args = [] ) {
		return $this->request(
			'editMessageCaption',
			array_merge(
				[
					'chat_id'    => $channel_id,
					'message_id' => (int) $message_id,
					'caption'    => $caption,
					'parse_mode' => $args['parse_mode'] ?? 'HTML',
				],
				$args
			)
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public function get_me() {
		return $this->request( 'getMe', [] );
	}

	/**
	 * @param resource|\CurlHandle $handle cURL handle.
	 */
	public function configure_curl_proxy( $handle ) {
		if ( ! $this->proxy_config || ! function_exists( 'curl_setopt' ) ) {
			return;
		}

		curl_setopt( $handle, CURLOPT_PROXYTYPE, $this->proxy_config['type'] );
		curl_setopt( $handle, CURLOPT_PROXY, $this->proxy_config['host'] . ':' . $this->proxy_config['port'] );

		if ( '' !== $this->proxy_config['user'] ) {
			curl_setopt( $handle, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
			curl_setopt( $handle, CURLOPT_PROXYUSERPWD, $this->proxy_config['user'] . ':' . $this->proxy_config['pass'] );
		}
	}

	/**
	 * @param string $method API method.
	 * @param array  $params Request params.
	 * @param bool   $encode_media_json Media param already JSON string.
	 * @return array|WP_Error
	 */
	private function request( $method, array $params ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'tg_no_token', '未配置 Bot Token' );
		}

		$url = 'https://api.telegram.org/bot' . $this->token . '/' . $method;

		$params = array_filter(
			$params,
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		return $this->dispatch( $url, $params, false );
	}

	/**
	 * @param string $method API method.
	 * @param array  $params Form fields.
	 * @param array  $files  field => file path.
	 * @return array|WP_Error
	 */
	private function request_multipart( $method, array $params, array $files ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'tg_no_token', '未配置 Bot Token' );
		}

		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'tg_no_curl', '服务器未启用 cURL，无法上传图片' );
		}

		$url = 'https://api.telegram.org/bot' . $this->token . '/' . $method;

		return $this->dispatch( $url, $params, $files );
	}

	/**
	 * @param string     $url   Request URL.
	 * @param array      $params Body params.
	 * @param array|bool $files File map or false for JSON.
	 * @return array|WP_Error
	 */
	private function dispatch( $url, array $params, $files ) {
		if ( is_array( $files ) && ! empty( $files ) ) {
			return $this->dispatch_curl_multipart( $url, $params, $files );
		}

		$request_args = [
			'timeout' => $this->proxy_config ? 90 : 45,
			'headers' => [
				'Content-Type' => 'application/json; charset=utf-8',
			],
			'body'    => wp_json_encode( $params ),
		];

		$curl_hook = null;
		if ( $this->proxy_config ) {
			$curl_hook = function ( $handle, $parsed_args, $request_url ) {
				if ( 0 !== strpos( (string) $request_url, 'https://api.telegram.org/' ) ) {
					return;
				}
				$this->configure_curl_proxy( $handle );
			};
			add_action( 'http_api_curl', $curl_hook, 10, 3 );
		}

		$response = wp_remote_post( $url, $request_args );

		if ( $curl_hook ) {
			remove_action( 'http_api_curl', $curl_hook, 10 );
		}

		return $this->parse_response( $response );
	}

	/**
	 * @param string $url    URL.
	 * @param array  $params Fields.
	 * @param array  $files  Files.
	 * @return array|WP_Error
	 */
	private function dispatch_curl_multipart( $url, array $params, array $files ) {
		$ch = curl_init( $url );
		if ( ! $ch ) {
			return new WP_Error( 'tg_curl_init', '无法初始化 cURL' );
		}

		$post_fields = $params;
		foreach ( $files as $field => $path ) {
			$post_fields[ $field ] = new CURLFile( $path, self::mime_for_path( $path ), basename( $path ) );
		}

		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_fields );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 20 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, $this->proxy_config ? 120 : 90 );

		$this->configure_curl_proxy( $ch );

		$body = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$err  = curl_error( $ch );
		curl_close( $ch );

		if ( false === $body ) {
			return new WP_Error( 'tg_curl_error', $err ?: 'cURL 请求失败' );
		}

		$decoded = json_decode( $body, true );
		if ( $code >= 400 || empty( $decoded['ok'] ) ) {
			$message = $decoded['description'] ?? 'Telegram API 错误';
			return new WP_Error( 'tg_api_error', (string) $message, [ 'status' => $code, 'body' => $decoded ] );
		}

		return $decoded['result'];
	}

	/**
	 * @param string $path File path.
	 * @return string
	 */
	private static function mime_for_path( $path ) {
		$type = wp_check_filetype( basename( $path ) );

		return $type['type'] ?: 'application/octet-stream';
	}

	/**
	 * @param array|WP_Error $response HTTP response.
	 * @return array|WP_Error
	 */
	private function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || empty( $body['ok'] ) ) {
			$message = $body['description'] ?? wp_remote_retrieve_response_message( $response );
			return new WP_Error( 'tg_api_error', (string) $message, [ 'status' => $code, 'body' => $body ] );
		}

		return $body['result'];
	}

	/**
	 * @param WP_Error $error API error.
	 */
	public static function is_url_fetch_error( WP_Error $error ) {
		$message = strtolower( $error->get_error_message() );

		return false !== strpos( $message, 'failed to get http url content' )
			|| false !== strpos( $message, 'wrong type of the web page content' )
			|| false !== strpos( $message, 'wrong http url' );
	}

	/**
	 * @param WP_Error $error API error.
	 */
	public static function is_rate_limit_error( WP_Error $error ) {
		return false !== stripos( $error->get_error_message(), 'Too Many Requests' );
	}

	/**
	 * @param WP_Error $error API error.
	 * @return int Seconds to wait.
	 */
	public static function get_retry_after( WP_Error $error ) {
		if ( preg_match( '/retry after (\d+)/i', $error->get_error_message(), $matches ) ) {
			return max( 1, (int) $matches[1] );
		}

		return 30;
	}
}
