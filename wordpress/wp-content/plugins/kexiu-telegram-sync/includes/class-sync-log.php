<?php
/**
 * Sync activity log (stored in wp_options, capped).
 */

defined( 'ABSPATH' ) || exit;

class Kexiu_TG_Sync_Log {

	const OPTION_KEY = 'kexiu_tg_sync_logs';
	const MAX_ENTRIES = 500;

	/**
	 * @param array $entry Log fields.
	 */
	public static function add( array $entry ) {
		$entry = wp_parse_args(
			$entry,
			[
				'time'       => time(),
				'post_id'    => 0,
				'channel_id' => '',
				'action'     => 'sync',
				'status'     => 'ok',
				'message'    => '',
			]
		);

		$logs   = self::get_all();
		$logs[] = $entry;

		if ( count( $logs ) > self::MAX_ENTRIES ) {
			$logs = array_slice( $logs, -self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $logs, false );
	}

	/**
	 * @param integer $limit Max entries.
	 * @return array
	 */
	public static function get( $limit = 50 ) {
		$logs = self::get_all();
		$limit = max( 1, (int) $limit );

		return array_slice( array_reverse( $logs ), 0, $limit );
	}

	/**
	 * @return array
	 */
	public static function get_all() {
		$logs = get_option( self::OPTION_KEY, [] );

		return is_array( $logs ) ? $logs : [];
	}

	public static function clear() {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * @param integer $post_id Post ID.
	 * @param string  $channel_id Channel ID.
	 * @param string  $action Action name.
	 * @param string  $status ok|error|skip.
	 * @param string  $message Detail message.
	 */
	public static function write( $post_id, $channel_id, $action, $status, $message = '' ) {
		self::add(
			[
				'post_id'    => (int) $post_id,
				'channel_id' => (string) $channel_id,
				'action'     => (string) $action,
				'status'     => (string) $status,
				'message'    => (string) $message,
			]
		);
	}
}
