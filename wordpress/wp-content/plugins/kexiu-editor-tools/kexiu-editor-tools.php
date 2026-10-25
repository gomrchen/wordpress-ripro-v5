<?php
/**
 * Plugin Name: Kexiu Editor Tools
 * Description: 文章编辑器一键整理截图画廊（kexiu-screenshots 双列布局）
 * Version: 1.1.0
 * Author: Kexiu
 * Text Domain: kexiu-editor-tools
 */

defined( 'ABSPATH' ) || exit;

define( 'KEXIU_EDITOR_TOOLS_FILE', __FILE__ );
define( 'KEXIU_EDITOR_TOOLS_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-gallery-formatter.php';

final class Kexiu_Editor_Tools {

	public static function init() {
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin' ] );
		add_action( 'wp_ajax_kexiu_format_screenshot_gallery', [ __CLASS__, 'ajax_format_gallery' ] );
		add_filter( 'mce_external_plugins', [ __CLASS__, 'tinymce_plugin' ] );
		add_filter( 'mce_buttons', [ __CLASS__, 'tinymce_button' ] );
	}

	public static function add_meta_box() {
		foreach ( [ 'post', 'page' ] as $type ) {
			add_meta_box(
				'kexiu-gallery-tools',
				'截图画廊工具',
				[ __CLASS__, 'render_meta_box' ],
				$type,
				'side',
				'high'
			);
		}
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'kexiu_format_gallery', 'kexiu_gallery_nonce' );
		?>
		<p style="margin-top:0;">将正文中的截图自动整理为画廊：竖版双列、横版双列，并保留介绍、表格、海报等内容。</p>
		<button type="button" class="button button-primary button-large" id="kexiu-format-gallery-btn" style="width:100%;">
			一键整理截图画廊
		</button>
		<p class="description" style="margin-bottom:0;">
			竖版与横版截图均会纳入；封面、海报、Logo 等会自动保留原位。
		</p>
		<?php
	}

	public static function enqueue_admin( $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		wp_enqueue_script(
			'kexiu-editor-tools',
			KEXIU_EDITOR_TOOLS_URL . 'assets/admin.js',
			[ 'jquery' ],
			'1.1.0',
			true
		);

		wp_localize_script(
			'kexiu-editor-tools',
			'kexiuEditorTools',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'kexiu_format_gallery' ),
				'i18n'    => [
					'button'   => '整理截图',
					'working'  => '整理中…',
					'done'     => '已整理 %d 张截图（竖版 %v 张，横版 %h 张）。',
					'none'     => '未找到可整理的截图。',
					'confirm'  => '将根据当前正文重新生成截图画廊，是否继续？',
					'error'    => '整理失败，请稍后重试。',
				],
			]
		);
	}

	public static function tinymce_plugin( $plugins ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && in_array( $screen->base, [ 'post', 'post-new' ], true ) ) {
			$plugins['kexiu_gallery'] = KEXIU_EDITOR_TOOLS_URL . 'assets/tinymce-plugin.js';
		}
		return $plugins;
	}

	public static function tinymce_button( $buttons ) {
		$buttons[] = 'kexiu_gallery';
		return $buttons;
	}

	public static function ajax_format_gallery() {
		check_ajax_referer( 'kexiu_format_gallery', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => '权限不足' ], 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => '无效的文章' ], 400 );
		}

		$formatter = new Kexiu_Gallery_Formatter();
		$result    = $formatter->format( $content, $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}
}

Kexiu_Editor_Tools::init();
