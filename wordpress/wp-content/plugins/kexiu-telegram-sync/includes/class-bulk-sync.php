<?php
/**
 * Bulk sync historical posts to Telegram.
 */

defined( 'ABSPATH' ) || exit;

class Kexiu_TG_Bulk_Sync {

	public static function init() {
		add_action( 'wp_ajax_kexiu_tg_bulk_sync', [ __CLASS__, 'ajax_bulk_sync' ] );
		add_action( 'wp_ajax_kexiu_tg_clear_logs', [ __CLASS__, 'ajax_clear_logs' ] );
	}

	/**
	 * @return array{ids: int[], total: int}
	 */
	public static function query_post_ids( $mode, $category_id = 0, $offset = 0, $limit = 5 ) {
		$settings = Kexiu_Telegram_Sync::get_settings();
		$offset   = max( 0, (int) $offset );
		$limit    = max( 1, min( 20, (int) $limit ) );

		$args = [
			'post_type'      => (array) $settings['post_types'],
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => false,
		];

		if ( $category_id > 0 ) {
			$args['cat'] = (int) $category_id;
		}

		if ( 'not_synced' === $mode ) {
			$args['meta_query'] = [
				[
					'key'     => Kexiu_TG_Post_Sync::META_SYNCED,
					'compare' => 'NOT EXISTS',
				],
			];
		}

		$query = new WP_Query( $args );

		return [
			'ids'   => array_map( 'intval', $query->posts ),
			'total' => (int) $query->found_posts,
		];
	}

	/**
	 * @return integer
	 */
	public static function count_posts( $mode, $category_id = 0 ) {
		$result = self::query_post_ids( $mode, $category_id, 0, 1 );

		return (int) $result['total'];
	}

	public static function ajax_bulk_sync() {
		check_ajax_referer( 'kexiu_tg_bulk_sync', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => '权限不足' ], 403 );
		}

		$mode        = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'not_synced';
		$category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		$offset      = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$resync      = ! empty( $_POST['resync'] );

		if ( ! in_array( $mode, [ 'not_synced', 'all' ], true ) ) {
			$mode = 'not_synced';
		}

		$batch = self::query_post_ids( $mode, $category_id, $offset, 2 );
		$done  = 0;
		$errors = [];
		$rate_limited = false;

		foreach ( $batch['ids'] as $post_id ) {
			if ( ! Kexiu_TG_Post_Sync::post_matches_filters( $post_id ) ) {
				Kexiu_TG_Sync_Log::write( $post_id, '', 'bulk', 'skip', '不符合分类/标签过滤条件' );
				$done++;
				continue;
			}

			$result = Kexiu_TG_Post_Sync::sync_post( $post_id, true, [ 'resync' => $resync ] );

			if ( is_wp_error( $result ) ) {
				$msg = Kexiu_Telegram_Sync::format_api_error( $result );
				$errors[] = sprintf( '#%d: %s', $post_id, $msg );

				if ( Kexiu_TG_Telegram_API::is_rate_limit_error( $result ) ) {
					$rate_limited = true;
					break;
				}
			}

			$done++;
			sleep( 4 );
		}

		$next_offset = $offset + $done;
		$finished    = ! $rate_limited && $next_offset >= $batch['total'];

		$message = $finished
			? '批量同步完成'
			: sprintf( '已处理 %d / %d', $next_offset, $batch['total'] );

		if ( $rate_limited ) {
			$message .= '（触发 Telegram 限流，请稍后再继续）';
		}

		wp_send_json_success(
			[
				'processed'    => $done,
				'offset'       => $next_offset,
				'total'        => $batch['total'],
				'finished'     => $finished,
				'rate_limited' => $rate_limited,
				'errors'       => $errors,
				'message'      => $message,
			]
		);
	}

	public static function ajax_clear_logs() {
		check_ajax_referer( 'kexiu_tg_bulk_sync', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => '权限不足' ], 403 );
		}

		Kexiu_TG_Sync_Log::clear();

		wp_send_json_success( [ 'message' => '日志已清空' ] );
	}
}
