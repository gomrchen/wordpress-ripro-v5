<?php
/**
 * Sync published posts to Telegram.
 */

defined( 'ABSPATH' ) || exit;

class Kexiu_TG_Post_Sync {

	const META_SYNCED    = '_kexiu_tg_synced';
	const META_MESSAGE   = '_kexiu_tg_message_id';
	const META_MESSAGES  = '_kexiu_tg_messages';
	const META_ERROR     = '_kexiu_tg_last_error';
	const META_SYNCED_AT = '_kexiu_tg_synced_at';

	/** @var array<int, true> */
	private static $pending_deferred_sync = [];

	public static function init() {
		add_action( 'transition_post_status', [ __CLASS__, 'maybe_sync_post' ], 20, 3 );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
		add_action( 'wp_ajax_kexiu_tg_sync_post', [ __CLASS__, 'ajax_sync_post' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_post_scripts' ] );
		add_action( 'shutdown', [ __CLASS__, 'shutdown_deferred_sync' ], 100000 );
		add_action( 'kexiu_ai_post_content_finalized', [ __CLASS__, 'on_ai_content_finalized' ], 10, 1 );
		add_action( 'kexiu_ai_post_published', [ __CLASS__, 'on_ai_post_published' ], 10, 1 );
		add_action( 'kexiu_tg_deferred_sync', [ __CLASS__, 'on_cron_deferred_sync' ] );
	}

	public static function enqueue_post_scripts( $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		wp_enqueue_script(
			'kexiu-telegram-sync-post',
			KEXIU_TG_SYNC_URL . 'assets/post-admin.js',
			[ 'jquery' ],
			KEXIU_TG_SYNC_VERSION,
			true
		);

		wp_localize_script(
			'kexiu-telegram-sync-post',
			'kexiuTelegramSync',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'kexiu_tg_sync_post' ),
				'i18n'    => [
					'working' => '同步中…',
					'success' => '已同步到 Telegram',
					'error'   => '同步失败',
				],
			]
		);
	}

	public static function add_meta_box() {
		$settings = Kexiu_Telegram_Sync::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		foreach ( (array) $settings['post_types'] as $type ) {
			add_meta_box(
				'kexiu-telegram-sync',
				'Telegram 同步',
				[ __CLASS__, 'render_meta_box' ],
				$type,
				'side',
				'default'
			);
		}
	}

	public static function render_meta_box( $post ) {
		$synced_at = get_post_meta( $post->ID, self::META_SYNCED_AT, true );
		$error     = get_post_meta( $post->ID, self::META_ERROR, true );
		$messages  = self::get_message_map( $post->ID );
		?>
		<p style="margin-top:0;">
			<?php if ( $synced_at ) : ?>
				上次同步：<br><code><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $synced_at ) ); ?></code>
			<?php else : ?>
				尚未同步到 Telegram 频道。
			<?php endif; ?>
		</p>
		<?php if ( ! empty( $messages ) ) : ?>
			<p style="font-size:12px;">
				<?php foreach ( $messages as $channel_id => $message_id ) : ?>
					<code><?php echo esc_html( $channel_id ); ?></code> → #<?php echo (int) $message_id; ?><br>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>
		<?php if ( $error ) : ?>
			<p style="color:#b32d2e;"><strong>最近错误：</strong><br><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>
		<button type="button" class="button button-secondary" id="kexiu-tg-sync-now" data-post-id="<?php echo esc_attr( $post->ID ); ?>" style="width:100%;">
			立即同步到 Telegram
		</button>
		<?php
	}

	public static function ajax_sync_post() {
		check_ajax_referer( 'kexiu_tg_sync_post', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => '权限不足' ], 403 );
		}

		$result = self::sync_post( $post_id, true, [ 'resync' => ! empty( $_POST['resync'] ) ] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => Kexiu_Telegram_Sync::format_api_error( $result ) ] );
		}

		wp_send_json_success( [ 'message' => '已同步到 Telegram 频道' ] );
	}

	/**
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function maybe_sync_post( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status ) {
			return;
		}

		$settings = Kexiu_Telegram_Sync::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, (array) $settings['post_types'], true ) ) {
			return;
		}

		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		if ( ! self::post_matches_filters( $post->ID, $settings ) ) {
			return;
		}

		$already_synced = get_post_meta( $post->ID, self::META_SYNCED, true );
		if ( $already_synced && empty( $settings['sync_on_update'] ) && 'publish' === $old_status ) {
			return;
		}

		if ( 'publish' === $old_status && empty( $settings['sync_on_update'] ) ) {
			return;
		}

		if ( self::should_defer_sync( $post ) ) {
			self::queue_deferred_sync( $post->ID );
			return;
		}

		self::sync_post( $post->ID, false );
	}

	public static function on_ai_content_finalized( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || get_post_meta( $post_id, self::META_SYNCED, true ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		if ( self::should_defer_sync( $post ) ) {
			self::queue_deferred_sync( $post_id );
			return;
		}

		self::unschedule_deferred_sync( $post_id );
		unset( self::$pending_deferred_sync[ $post_id ] );
		self::sync_post( $post_id, false );
	}

	public static function on_ai_post_published( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || get_post_meta( $post_id, self::META_SYNCED, true ) ) {
			return;
		}

		self::queue_deferred_sync( $post_id );
	}

	public static function shutdown_deferred_sync() {
		if ( empty( self::$pending_deferred_sync ) ) {
			return;
		}

		foreach ( array_keys( self::$pending_deferred_sync ) as $post_id ) {
			$post_id = (int) $post_id;
			if ( get_post_meta( $post_id, self::META_SYNCED, true ) ) {
				unset( self::$pending_deferred_sync[ $post_id ] );
				self::unschedule_deferred_sync( $post_id );
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				unset( self::$pending_deferred_sync[ $post_id ] );
				continue;
			}

			if ( ! self::post_matches_filters( $post_id ) ) {
				unset( self::$pending_deferred_sync[ $post_id ] );
				continue;
			}

			if ( self::should_defer_sync( $post ) ) {
				continue;
			}

			unset( self::$pending_deferred_sync[ $post_id ] );
			self::unschedule_deferred_sync( $post_id );
			self::sync_post( $post_id, false );
		}
	}

	public static function on_cron_deferred_sync( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || get_post_meta( $post_id, self::META_SYNCED, true ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		if ( self::should_defer_sync( $post ) ) {
			self::queue_deferred_sync( $post_id );
			return;
		}

		unset( self::$pending_deferred_sync[ $post_id ] );
		self::sync_post( $post_id, false );
	}

	/**
	 * @param WP_Post $post Post object.
	 */
	private static function should_defer_sync( $post ) {
		$content = trim( (string) $post->post_content );

		if ( strlen( $content ) < 200 ) {
			return true;
		}

		if ( false !== strpos( $content, 'erphp-ai-image-tip' ) ) {
			return true;
		}

		return false;
	}

	private static function queue_deferred_sync( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return;
		}

		self::$pending_deferred_sync[ $post_id ] = true;

		if ( ! wp_next_scheduled( 'kexiu_tg_deferred_sync', [ $post_id ] ) ) {
			wp_schedule_single_event( time() + 120, 'kexiu_tg_deferred_sync', [ $post_id ] );
		}
	}

	private static function unschedule_deferred_sync( $post_id ) {
		$timestamp = wp_next_scheduled( 'kexiu_tg_deferred_sync', [ (int) $post_id ] );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'kexiu_tg_deferred_sync', [ (int) $post_id ] );
		}
	}

	/**
	 * @param integer $post_id  Post ID.
	 * @param array   $settings Settings (optional).
	 */
	public static function post_matches_filters( $post_id, $settings = null ) {
		if ( null === $settings ) {
			$settings = Kexiu_Telegram_Sync::get_settings();
		}

		$category_ids = (array) $settings['category_ids'];
		if ( ! empty( $category_ids ) ) {
			$post_cats = wp_get_post_categories( $post_id );
			if ( empty( array_intersect( $post_cats, $category_ids ) ) ) {
				return false;
			}
		}

		$tag_ids = (array) $settings['tag_ids'];
		if ( ! empty( $tag_ids ) ) {
			$post_tags = wp_get_post_tags( $post_id, [ 'fields' => 'ids' ] );
			if ( empty( array_intersect( $post_tags, $tag_ids ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param integer $post_id Post ID.
	 * @param boolean $force   Manual sync bypasses enabled flag.
	 * @param array   $options resync, skip_filters.
	 * @return true|WP_Error
	 */
	public static function sync_post( $post_id, $force = false, $options = [] ) {
		$settings = Kexiu_Telegram_Sync::get_settings();
		$resync   = ! empty( $options['resync'] );

		if ( empty( $settings['enabled'] ) && ! $force ) {
			return new WP_Error( 'tg_disabled', 'Telegram 同步未启用' );
		}

		$channels = Kexiu_Telegram_Sync::get_active_channels( $settings );
		if ( empty( $channels ) ) {
			return new WP_Error( 'tg_not_configured', '请先配置至少一个启用的频道' );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'tg_not_published', '文章尚未发布' );
		}

		if ( empty( $options['skip_filters'] ) && ! self::post_matches_filters( $post_id, $settings ) ) {
			Kexiu_TG_Sync_Log::write( $post_id, '', 'sync', 'skip', '不符合分类/标签过滤' );
			return new WP_Error( 'tg_filtered', '文章不符合分类/标签过滤条件' );
		}

		if ( $resync ) {
			delete_post_meta( $post_id, self::META_SYNCED );
			delete_post_meta( $post_id, self::META_MESSAGE );
			delete_post_meta( $post_id, self::META_MESSAGES );
		}

		$errors = [];
		foreach ( $channels as $channel ) {
			$result = self::sync_post_to_channel( $post, $channel, $settings, $force, $resync );
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf( '%s: %s', $channel['channel_id'], Kexiu_Telegram_Sync::format_api_error( $result ) );
			}
		}

		if ( ! empty( $errors ) ) {
			$message = implode( '; ', $errors );
			update_post_meta( $post_id, self::META_ERROR, $message );
			return new WP_Error( 'tg_partial_fail', $message );
		}

		delete_post_meta( $post_id, self::META_ERROR );
		update_post_meta( $post_id, self::META_SYNCED, '1' );
		update_post_meta( $post_id, self::META_SYNCED_AT, time() );

		return true;
	}

	/**
	 * @param WP_Post $post     Post.
	 * @param array   $channel  Channel row.
	 * @param array   $settings Settings.
	 * @param boolean $force    Force flag.
	 * @param boolean $resync   Resync flag.
	 * @return true|WP_Error
	 */
	private static function sync_post_to_channel( $post, array $channel, array $settings, $force, $resync ) {
		$channel_id = $channel['channel_id'];
		$api        = Kexiu_Telegram_Sync::get_api_for_channel( $channel );
		$caption    = self::build_caption( $post, $settings );
		$parse_mode = ! empty( $settings['parse_mode'] ) ? (string) $settings['parse_mode'] : 'HTML';
		$images     = empty( $settings['text_only'] )
			? self::collect_image_assets( $post->ID, (int) $settings['max_images'] )
			: [];

		$strategy   = $settings['image_strategy'] ?? 'url_then_upload';
		$message_id = self::get_stored_message_id( $post->ID, $channel_id );
		$can_edit   = ! $resync
			&& ! empty( $settings['sync_on_update'] )
			&& ! empty( $settings['edit_on_update'] )
			&& $message_id > 0;

		if ( $can_edit ) {
			$edited = self::try_edit_message( $api, $channel_id, $message_id, $caption, $images, $parse_mode );
			if ( true === $edited ) {
				Kexiu_TG_Sync_Log::write( $post->ID, $channel_id, 'edit', 'ok', '已编辑 Telegram 消息' );
				return true;
			}
		}

		$result = self::api_call_with_retry(
			static function () use ( $api, $channel_id, $caption, $images, $parse_mode, $strategy ) {
				return self::send_to_channel( $api, $channel_id, $caption, $images, $parse_mode, $strategy );
			}
		);

		if ( is_wp_error( $result ) ) {
			Kexiu_TG_Sync_Log::write( $post->ID, $channel_id, 'sync', 'error', Kexiu_Telegram_Sync::format_api_error( $result ) );
			return $result;
		}

		$new_id = self::extract_message_id( $result );
		if ( $new_id > 0 ) {
			self::store_message_id( $post->ID, $channel_id, $new_id );
		}

		Kexiu_TG_Sync_Log::write(
			$post->ID,
			$channel_id,
			'sync',
			'ok',
			sprintf( '已推送（%d 张图）', count( $images ) )
		);

		return true;
	}

	/**
	 * @param Kexiu_TG_Telegram_API $api        API.
	 * @param string                $channel_id Channel.
	 * @param int                   $message_id Message ID.
	 * @param string                $caption    Caption.
	 * @param array                 $images     Image assets.
	 * @param string                $parse_mode Parse mode.
	 * @return true|false
	 */
	private static function try_edit_message( $api, $channel_id, $message_id, $caption, array $images, $parse_mode ) {
		$args = [ 'parse_mode' => $parse_mode ];

		if ( empty( $images ) ) {
			$result = $api->edit_message_text( $channel_id, $message_id, $caption, $args );
			return ! is_wp_error( $result );
		}

		if ( 1 === count( $images ) ) {
			$result = $api->edit_message_caption( $channel_id, $message_id, $caption, $args );
			if ( ! is_wp_error( $result ) ) {
				return true;
			}

			if ( false !== stripos( $result->get_error_message(), 'no caption' ) ) {
				$text_result = $api->edit_message_text( $channel_id, $message_id, $caption, $args );
				return ! is_wp_error( $text_result );
			}

			return false;
		}

		return false;
	}

	/**
	 * @param array|WP_Error $result API result.
	 */
	private static function extract_message_id( $result ) {
		if ( is_wp_error( $result ) ) {
			return 0;
		}

		if ( is_array( $result ) && isset( $result[0]['message_id'] ) ) {
			return (int) $result[0]['message_id'];
		}

		if ( is_array( $result ) && isset( $result['message_id'] ) ) {
			return (int) $result['message_id'];
		}

		return 0;
	}

	/**
	 * @param integer $post_id Post ID.
	 * @return array<string, int>
	 */
	public static function get_message_map( $post_id ) {
		$raw = get_post_meta( $post_id, self::META_MESSAGES, true );
		if ( is_array( $raw ) ) {
			return $raw;
		}

		$legacy = (int) get_post_meta( $post_id, self::META_MESSAGE, true );
		if ( $legacy > 0 ) {
			$settings = Kexiu_Telegram_Sync::get_settings();
			$key      = $settings['channel_id'] ?: 'primary';
			return [ $key => $legacy ];
		}

		return [];
	}

	/**
	 * @param integer $post_id    Post ID.
	 * @param string  $channel_id Channel ID.
	 */
	private static function get_stored_message_id( $post_id, $channel_id ) {
		$map = self::get_message_map( $post_id );

		return (int) ( $map[ $channel_id ] ?? 0 );
	}

	/**
	 * @param integer $post_id    Post ID.
	 * @param string  $channel_id Channel ID.
	 * @param integer $message_id Message ID.
	 */
	private static function store_message_id( $post_id, $channel_id, $message_id ) {
		$map                 = self::get_message_map( $post_id );
		$map[ $channel_id ]  = (int) $message_id;
		update_post_meta( $post_id, self::META_MESSAGES, $map );
		update_post_meta( $post_id, self::META_MESSAGE, (int) $message_id );
	}

	/**
	 * @param WP_Post $post     Post object.
	 * @param array   $settings Plugin settings.
	 */
	private static function build_caption( $post, $settings ) {
		$excerpt = has_excerpt( $post ) ? $post->post_excerpt : $post->post_content;
		$excerpt = wp_strip_all_tags( $excerpt );
		$excerpt = html_entity_decode( $excerpt, ENT_QUOTES, 'UTF-8' );
		$excerpt = preg_replace( '/\s+/u', ' ', trim( $excerpt ) );
		$excerpt = wp_trim_words( $excerpt, 80, '…' );

		$parse_mode = ! empty( $settings['parse_mode'] ) ? (string) $settings['parse_mode'] : 'HTML';
		$title      = get_the_title( $post );
		$site       = get_bloginfo( 'name' );
		$url        = get_permalink( $post );

		if ( 'MarkdownV2' === $parse_mode ) {
			$replacements = [
				'{title}'   => self::escape_markdown_v2( $title ),
				'{excerpt}' => self::escape_markdown_v2( $excerpt ),
				'{url}'     => self::escape_markdown_v2_url( $url ),
				'{site}'    => self::escape_markdown_v2( $site ),
			];
		} else {
			$replacements = [
				'{title}'   => self::escape_html( $title ),
				'{excerpt}' => self::escape_html( $excerpt ),
				'{url}'     => esc_url( $url ),
				'{site}'    => self::escape_html( $site ),
			];
		}

		$caption = strtr( (string) $settings['template'], $replacements );
		return self::trim_caption( $caption, 1024 );
	}

	private static function escape_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	private static function escape_markdown_v2( $text ) {
		return preg_replace( '/([_*\[\]()~`>#+\-=|{}.!\\\\])/', '\\\\$1', (string) $text );
	}

	private static function escape_markdown_v2_url( $url ) {
		return str_replace( ')', '\\)', (string) $url );
	}

	private static function trim_caption( $caption, $limit ) {
		if ( mb_strlen( $caption ) <= $limit ) {
			return $caption;
		}
		return mb_substr( $caption, 0, $limit - 1 ) . '…';
	}

	/**
	 * @param integer $post_id    Post ID.
	 * @param integer $max_images Max images.
	 * @return array<int, array{url: string, path: string|null, attachment_id: int}>
	 */
	private static function collect_image_assets( $post_id, $max_images ) {
		$assets = [];
		$seen   = [];

		$thumb_id = get_post_thumbnail_id( $post_id );
		if ( $thumb_id ) {
			$asset = self::asset_from_attachment( $thumb_id );
			if ( $asset && ! isset( $seen[ $asset['url'] ] ) ) {
				$assets[]              = $asset;
				$seen[ $asset['url'] ] = true;
			}
		}

		$post = get_post( $post_id );
		if ( $post && $post->post_content ) {
			$attachment_ids = [];

			if ( preg_match_all( '/wp-image-(\d+)/', $post->post_content, $matches ) ) {
				$attachment_ids = array_merge( $attachment_ids, array_map( 'intval', $matches[1] ) );
			}

			if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $img_matches ) ) {
				foreach ( $img_matches[1] as $src ) {
					$url = self::normalize_image_url( $src );
					if ( ! $url || ! self::is_syncable_image_url( $url ) ) {
						continue;
					}

					$attachment_id = attachment_url_to_postid( $url );
					if ( $attachment_id ) {
						$attachment_ids[] = (int) $attachment_id;
						continue;
					}

					if ( isset( $seen[ $url ] ) ) {
						continue;
					}

					$assets[] = [
						'url'           => $url,
						'path'          => self::url_to_local_path( $url ),
						'attachment_id' => 0,
					];
					$seen[ $url ] = true;

					if ( count( $assets ) >= $max_images ) {
						break;
					}
				}
			}

			foreach ( array_unique( $attachment_ids ) as $attachment_id ) {
				if ( count( $assets ) >= $max_images ) {
					break;
				}

				$asset = self::asset_from_attachment( $attachment_id );
				if ( ! $asset || isset( $seen[ $asset['url'] ] ) ) {
					continue;
				}

				$assets[]              = $asset;
				$seen[ $asset['url'] ] = true;
			}
		}

		return array_slice( $assets, 0, $max_images );
	}

	/**
	 * @param integer $attachment_id Attachment ID.
	 * @return array|null
	 */
	private static function asset_from_attachment( $attachment_id ) {
		$url = wp_get_attachment_image_url( (int) $attachment_id, 'large' );
		if ( ! $url ) {
			return null;
		}

		$path = get_attached_file( (int) $attachment_id );

		return [
			'url'           => $url,
			'path'          => ( $path && is_readable( $path ) ) ? $path : null,
			'attachment_id' => (int) $attachment_id,
		];
	}

	private static function normalize_image_url( $url ) {
		$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );
		if ( '' === $url ) {
			return '';
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}

		return esc_url_raw( $url );
	}

	private static function is_syncable_image_url( $url ) {
		if ( preg_match( '#^data:#i', $url ) ) {
			return false;
		}

		if ( preg_match( '#\.svg(\?|$)#i', $url ) ) {
			return false;
		}

		return (bool) preg_match( '#\.(jpe?g|png|gif|webp)(\?|$)#i', $url );
	}

	/**
	 * @param string $url Image URL.
	 * @return string|null
	 */
	private static function url_to_local_path( $url ) {
		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id ) {
			$path = get_attached_file( $attachment_id );
			return ( $path && is_readable( $path ) ) ? $path : null;
		}

		$upload = wp_upload_dir();
		if ( empty( $upload['baseurl'] ) || empty( $upload['basedir'] ) ) {
			return null;
		}

		if ( 0 === strpos( $url, $upload['baseurl'] ) ) {
			$relative = substr( $url, strlen( $upload['baseurl'] ) );
			$path     = $upload['basedir'] . $relative;
			$path     = preg_replace( '#\?.*$#', '', $path );

			return is_readable( $path ) ? $path : null;
		}

		return null;
	}

	/**
	 * @param Kexiu_TG_Telegram_API $api        API.
	 * @param string                $channel_id Channel.
	 * @param string                $caption    Caption.
	 * @param array                 $images     Assets.
	 * @param string                $parse_mode Parse mode.
	 * @param string                $strategy   url|upload|url_then_upload.
	 * @return array|WP_Error
	 */
	private static function send_to_channel( $api, $channel_id, $caption, array $images, $parse_mode, $strategy ) {
		$args = [ 'parse_mode' => $parse_mode ];

		if ( empty( $images ) ) {
			return $api->send_message( $channel_id, $caption, $args );
		}

		if ( 'upload' === $strategy ) {
			return self::send_images_upload( $api, $channel_id, $caption, $images, $parse_mode );
		}

		if ( 'url' === $strategy ) {
			return self::send_images_url( $api, $channel_id, $caption, $images, $parse_mode );
		}

		$result = self::send_images_url( $api, $channel_id, $caption, $images, $parse_mode );
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! Kexiu_TG_Telegram_API::is_url_fetch_error( $result ) ) {
			return $result;
		}

		return self::send_images_upload( $api, $channel_id, $caption, $images, $parse_mode );
	}

	/**
	 * @param Kexiu_TG_Telegram_API $api        API.
	 * @param string                $channel_id Channel.
	 * @param string                $caption    Caption.
	 * @param array                 $images     Assets.
	 * @param string                $parse_mode Parse mode.
	 * @return array|WP_Error
	 */
	private static function send_images_url( $api, $channel_id, $caption, array $images, $parse_mode ) {
		$urls = array_map(
			static function ( $item ) {
				return $item['url'];
			},
			$images
		);

		$args = [ 'parse_mode' => $parse_mode ];

		if ( 1 === count( $urls ) ) {
			return $api->send_photo( $channel_id, $urls[0], $caption, $args );
		}

		$media = [];
		foreach ( $urls as $index => $url ) {
			$item = [
				'type'  => 'photo',
				'media' => $url,
			];
			if ( 0 === $index ) {
				$item['caption']    = $caption;
				$item['parse_mode'] = $parse_mode;
			}
			$media[] = $item;
		}

		return $api->send_media_group( $channel_id, $media );
	}

	/**
	 * @param Kexiu_TG_Telegram_API $api        API.
	 * @param string                $channel_id Channel.
	 * @param string                $caption    Caption.
	 * @param array                 $images     Assets.
	 * @param string                $parse_mode Parse mode.
	 * @return array|WP_Error
	 */
	private static function send_images_upload( $api, $channel_id, $caption, array $images, $parse_mode ) {
		$paths = [];
		foreach ( $images as $image ) {
			$path = $image['path'];
			if ( $path && is_readable( $path ) ) {
				$paths[] = $path;
			}
		}

		if ( empty( $paths ) ) {
			return self::send_images_url( $api, $channel_id, $caption, $images, $parse_mode );
		}

		$args = [ 'parse_mode' => $parse_mode ];

		if ( 1 === count( $paths ) ) {
			return $api->send_photo_file( $channel_id, $paths[0], $caption, $args );
		}

		$items = [];
		foreach ( $paths as $index => $path ) {
			$items[] = [
				'path'       => $path,
				'caption'    => 0 === $index ? $caption : '',
				'parse_mode' => $parse_mode,
			];
		}

		return $api->send_media_group_files( $channel_id, $items );
	}

	/**
	 * @param callable $callback API call.
	 * @return mixed
	 */
	private static function api_call_with_retry( $callback ) {
		$result = $callback();

		if ( is_wp_error( $result ) && Kexiu_TG_Telegram_API::is_rate_limit_error( $result ) ) {
			sleep( Kexiu_TG_Telegram_API::get_retry_after( $result ) + 1 );
			$result = $callback();
		}

		return $result;
	}
}
