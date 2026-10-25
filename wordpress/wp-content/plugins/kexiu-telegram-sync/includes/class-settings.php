<?php
/**
 * Plugin settings page.
 */

defined( 'ABSPATH' ) || exit;

class Kexiu_TG_Settings {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'wp_ajax_kexiu_tg_test_connection', [ __CLASS__, 'ajax_test_connection' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
	}

	public static function add_menu() {
		add_options_page(
			'Telegram 同步',
			'Telegram 同步',
			'manage_options',
			'kexiu-telegram-sync',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function register_settings() {
		register_setting(
			'kexiu_telegram_sync_group',
			'kexiu_telegram_sync',
			[ __CLASS__, 'sanitize_settings' ]
		);
	}

	/**
	 * @param array $input Raw settings.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$current  = Kexiu_Telegram_Sync::get_settings();
		$input    = is_array( $input ) ? $input : [];
		$types    = array_keys( get_post_types( [ 'public' => true ] ) );
		$selected = isset( $input['post_types'] ) ? (array) $input['post_types'] : [ 'post' ];
		$selected = array_values( array_intersect( $selected, $types ) );

		if ( empty( $selected ) ) {
			$selected = [ 'post' ];
		}

		$category_ids = isset( $input['category_ids'] ) ? array_map( 'absint', (array) $input['category_ids'] ) : [];
		$tag_ids      = isset( $input['tag_ids'] ) ? array_map( 'absint', (array) $input['tag_ids'] ) : [];

		$strategies = [ 'url', 'upload', 'url_then_upload' ];
		$strategy   = $input['image_strategy'] ?? $current['image_strategy'];
		if ( ! in_array( $strategy, $strategies, true ) ) {
			$strategy = 'url_then_upload';
		}

		$channels = [];
		if ( isset( $input['channels'] ) && is_array( $input['channels'] ) ) {
			$channels = Kexiu_Telegram_Sync::normalize_channels( $input['channels'] );
		}

		$bot_token  = sanitize_text_field( $input['bot_token'] ?? $current['bot_token'] );
		$channel_id = sanitize_text_field( $input['channel_id'] ?? $current['channel_id'] );

		if ( empty( $channels ) && $bot_token && $channel_id ) {
			$channels = [
				[
					'id'         => 'primary',
					'label'      => '主频道',
					'enabled'    => true,
					'bot_token'  => $bot_token,
					'channel_id' => $channel_id,
				],
			];
		}

		$primary = Kexiu_Telegram_Sync::get_primary_channel( $channels );
		if ( $primary ) {
			$bot_token  = $primary['bot_token'];
			$channel_id = $primary['channel_id'];
		}

		return [
			'enabled'          => ! empty( $input['enabled'] ),
			'bot_token'        => $bot_token,
			'channel_id'       => $channel_id,
			'channels'         => $channels,
			'api_proxy_s'      => ! empty( $input['api_proxy_s'] ),
			'api_proxy'        => self::sanitize_proxy( $input['api_proxy'] ?? $current['api_proxy'] ),
			'api_proxy_user'   => sanitize_text_field( $input['api_proxy_user'] ?? $current['api_proxy_user'] ),
			'api_proxy_pass'   => '' !== trim( (string) ( $input['api_proxy_pass'] ?? '' ) )
				? sanitize_text_field( $input['api_proxy_pass'] )
				: ( $current['api_proxy_pass'] ?? '' ),
			'post_types'       => $selected,
			'sync_on_update'   => ! empty( $input['sync_on_update'] ),
			'edit_on_update'   => ! empty( $input['edit_on_update'] ),
			'max_images'       => max( 1, min( 10, absint( $input['max_images'] ?? 6 ) ) ),
			'text_only'        => ! empty( $input['text_only'] ),
			'image_strategy'   => $strategy,
			'category_ids'     => array_values( array_filter( $category_ids ) ),
			'tag_ids'          => array_values( array_filter( $tag_ids ) ),
			'parse_mode'       => in_array( $input['parse_mode'] ?? 'HTML', [ 'HTML', 'MarkdownV2' ], true ) ? $input['parse_mode'] : 'HTML',
			'template'         => wp_kses_post( $input['template'] ?? $current['template'] ),
		];
	}

	/**
	 * @param string $proxy Raw proxy URL.
	 * @return string
	 */
	private static function sanitize_proxy( $proxy ) {
		$proxy = trim( (string) $proxy );
		if ( '' === $proxy ) {
			return '';
		}

		if ( ! preg_match( '#^(https?|socks5h?|socks4a?)://[^\s]+#i', $proxy ) && ! preg_match( '#^[\w.-]+:\d+$#', $proxy ) ) {
			add_settings_error(
				'kexiu_telegram_sync',
				'invalid_api_proxy',
				'API 代理格式无效，请使用如 socks5://127.0.0.1:7890、http://127.0.0.1:7890 或 127.0.0.1:7890'
			);
			return '';
		}

		return $proxy;
	}

	public static function enqueue_scripts( $hook ) {
		if ( 'settings_page_kexiu-telegram-sync' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'kexiu-telegram-sync-settings',
			KEXIU_TG_SYNC_URL . 'assets/settings-admin.js',
			[ 'jquery' ],
			KEXIU_TG_SYNC_VERSION,
			true
		);

		wp_localize_script(
			'kexiu-telegram-sync-settings',
			'kexiuTelegramSettings',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'kexiu_tg_test_connection' ),
				'bulkNonce' => wp_create_nonce( 'kexiu_tg_bulk_sync' ),
			]
		);
	}

	public static function ajax_test_connection() {
		check_ajax_referer( 'kexiu_tg_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => '权限不足' ], 403 );
		}

		$settings = Kexiu_Telegram_Sync::get_settings();
		$channels = Kexiu_Telegram_Sync::get_active_channels( $settings );

		if ( empty( $channels ) ) {
			wp_send_json_error( [ 'message' => '请先配置并启用至少一个频道' ] );
		}

		$channel = $channels[0];
		$api     = Kexiu_Telegram_Sync::get_api_for_channel( $channel );
		$me      = $api->get_me();

		if ( is_wp_error( $me ) ) {
			wp_send_json_error( [ 'message' => Kexiu_Telegram_Sync::format_api_error( $me ) ] );
		}

		$test_text = '✅ Telegram 同步测试成功 - ' . get_bloginfo( 'name' ) . ' ' . wp_date( 'Y-m-d H:i:s' );
		$sent      = $api->send_message( $channel['channel_id'], $test_text, [ 'parse_mode' => 'HTML' ] );

		if ( is_wp_error( $sent ) ) {
			wp_send_json_error( [
				'message' => 'Bot 已连接（@' . ( $me['username'] ?? '' ) . '），但发送到「' . $channel['channel_id'] . '」失败：' . Kexiu_Telegram_Sync::format_api_error( $sent ),
			] );
		}

		wp_send_json_success( [
			'message' => '连接成功，已向「' . $channel['label'] . '」发送测试消息。',
		] );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings     = Kexiu_Telegram_Sync::get_settings();
		$post_types   = get_post_types( [ 'public' => true ], 'objects' );
		$categories   = get_categories( [ 'hide_empty' => false ] );
		$tags         = get_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => false ] );
		$channels     = ! empty( $settings['channels'] ) ? $settings['channels'] : [
			[
				'id'         => 'primary',
				'label'      => '主频道',
				'enabled'    => true,
				'bot_token'  => $settings['bot_token'],
				'channel_id' => $settings['channel_id'],
			],
		];
		$not_synced   = Kexiu_TG_Bulk_Sync::count_posts( 'not_synced' );
		$all_posts    = Kexiu_TG_Bulk_Sync::count_posts( 'all' );
		$logs         = Kexiu_TG_Sync_Log::get( 30 );
		?>
		<div class="wrap">
			<h1>Telegram 频道同步</h1>
			<p>文章发布后自动推送到 Telegram 频道，支持多频道、批量同步、更新编辑消息。</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'kexiu_telegram_sync_group' ); ?>

				<h2 class="title">基本设置</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">启用同步</th>
						<td>
							<label>
								<input type="checkbox" name="kexiu_telegram_sync[enabled]" value="1" <?php checked( $settings['enabled'] ); ?> />
								发布文章后自动同步到 Telegram
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">SOCKS5 代理</th>
						<td>
							<label>
								<input type="checkbox" name="kexiu_telegram_sync[api_proxy_s]" value="1" <?php checked( ! empty( $settings['api_proxy_s'] ) ); ?> />
								启用 SOCKS5 代理
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kexiu_tg_api_proxy">API 代理</label></th>
						<td>
							<input type="text" class="regular-text code" id="kexiu_tg_api_proxy" name="kexiu_telegram_sync[api_proxy]" value="<?php echo esc_attr( $settings['api_proxy'] ); ?>" placeholder="socks5://127.0.0.1:7890" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kexiu_tg_api_proxy_user">代理用户名</label></th>
						<td>
							<input type="text" class="regular-text" id="kexiu_tg_api_proxy_user" name="kexiu_telegram_sync[api_proxy_user]" value="<?php echo esc_attr( $settings['api_proxy_user'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kexiu_tg_api_proxy_pass">代理密码</label></th>
						<td>
							<input type="password" class="regular-text" id="kexiu_tg_api_proxy_pass" name="kexiu_telegram_sync[api_proxy_pass]" value="" placeholder="<?php echo esc_attr( ! empty( $settings['api_proxy_pass'] ) ? '已设置，留空则不修改' : '' ); ?>" />
						</td>
					</tr>
				</table>

				<h2 class="title">频道配置（支持多 Bot / 多频道）</h2>
				<p class="description">可添加多个频道，同一篇文章会同步到所有已启用的频道。</p>
				<table class="widefat striped" id="kexiu-tg-channels-table" style="max-width:960px;">
					<thead>
						<tr>
							<th>启用</th>
							<th>名称</th>
							<th>Bot Token</th>
							<th>频道 ID</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $channels as $index => $channel ) : ?>
							<tr class="kexiu-tg-channel-row">
								<td>
									<input type="checkbox" name="kexiu_telegram_sync[channels][<?php echo (int) $index; ?>][enabled]" value="1" <?php checked( ! empty( $channel['enabled'] ) ); ?> />
									<input type="hidden" name="kexiu_telegram_sync[channels][<?php echo (int) $index; ?>][id]" value="<?php echo esc_attr( $channel['id'] ?? 'ch' . $index ); ?>" />
								</td>
								<td>
									<input type="text" class="regular-text" name="kexiu_telegram_sync[channels][<?php echo (int) $index; ?>][label]" value="<?php echo esc_attr( $channel['label'] ?? '' ); ?>" placeholder="主频道" />
								</td>
								<td>
									<input type="password" class="regular-text code" name="kexiu_telegram_sync[channels][<?php echo (int) $index; ?>][bot_token]" value="<?php echo esc_attr( $channel['bot_token'] ?? '' ); ?>" autocomplete="off" />
								</td>
								<td>
									<input type="text" class="regular-text code" name="kexiu_telegram_sync[channels][<?php echo (int) $index; ?>][channel_id]" value="<?php echo esc_attr( $channel['channel_id'] ?? '' ); ?>" placeholder="@channel" />
								</td>
								<td>
									<button type="button" class="button kexiu-tg-remove-channel">删除</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button type="button" class="button" id="kexiu-tg-add-channel">添加频道</button>
					<button type="button" class="button button-secondary" id="kexiu-tg-test-connection">测试连接</button>
					<span id="kexiu-tg-test-result" style="margin-left:12px;"></span>
				</p>

				<h2 class="title">同步规则</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">同步文章类型</th>
						<td>
							<?php foreach ( $post_types as $type => $object ) : ?>
								<label style="display:block;margin-bottom:6px;">
									<input type="checkbox" name="kexiu_telegram_sync[post_types][]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, (array) $settings['post_types'], true ) ); ?> />
									<?php echo esc_html( $object->labels->singular_name . ' (' . $type . ')' ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">分类过滤</th>
						<td>
							<p class="description" style="margin-top:0;">不选则同步所有分类；选中后仅同步属于这些分类的文章。</p>
							<div style="max-height:160px;overflow:auto;border:1px solid #c3c4c7;padding:8px;background:#fff;">
								<?php foreach ( $categories as $cat ) : ?>
									<label style="display:block;margin-bottom:4px;">
										<input type="checkbox" name="kexiu_telegram_sync[category_ids][]" value="<?php echo (int) $cat->term_id; ?>" <?php checked( in_array( (int) $cat->term_id, (array) $settings['category_ids'], true ) ); ?> />
										<?php echo esc_html( $cat->name ); ?>
									</label>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row">标签过滤</th>
						<td>
							<p class="description" style="margin-top:0;">不选则不过滤标签；选中后文章须包含至少一个所选标签。</p>
							<div style="max-height:160px;overflow:auto;border:1px solid #c3c4c7;padding:8px;background:#fff;">
								<?php if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) : ?>
									<?php foreach ( $tags as $tag ) : ?>
										<label style="display:block;margin-bottom:4px;">
											<input type="checkbox" name="kexiu_telegram_sync[tag_ids][]" value="<?php echo (int) $tag->term_id; ?>" <?php checked( in_array( (int) $tag->term_id, (array) $settings['tag_ids'], true ) ); ?> />
											<?php echo esc_html( $tag->name ); ?>
										</label>
									<?php endforeach; ?>
								<?php else : ?>
									<p>暂无标签</p>
								<?php endif; ?>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row">更新时同步</th>
						<td>
							<label>
								<input type="checkbox" name="kexiu_telegram_sync[sync_on_update]" value="1" <?php checked( $settings['sync_on_update'] ); ?> />
								文章更新后再次同步
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">更新时编辑消息</th>
						<td>
							<label>
								<input type="checkbox" name="kexiu_telegram_sync[edit_on_update]" value="1" <?php checked( $settings['edit_on_update'] ); ?> />
								优先编辑已有 Telegram 消息（纯文字或单图）；多图相册会发新消息
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">纯文字推送</th>
						<td>
							<label>
								<input type="checkbox" name="kexiu_telegram_sync[text_only]" value="1" <?php checked( $settings['text_only'] ); ?> />
								不发送图片，仅推送文字摘要和链接
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kexiu_tg_image_strategy">图片发送策略</label></th>
						<td>
							<select id="kexiu_tg_image_strategy" name="kexiu_telegram_sync[image_strategy]">
								<option value="url_then_upload" <?php selected( $settings['image_strategy'], 'url_then_upload' ); ?>>先 URL，失败则本地上传（推荐）</option>
								<option value="url" <?php selected( $settings['image_strategy'], 'url' ); ?>>仅 URL（Telegram 自行拉图）</option>
								<option value="upload" <?php selected( $settings['image_strategy'], 'upload' ); ?>>仅本地上传（解决 TG 无法访问图片域名）</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kexiu_tg_max_images">最多图片数</label></th>
						<td>
							<input type="number" min="1" max="10" id="kexiu_tg_max_images" name="kexiu_telegram_sync[max_images]" value="<?php echo esc_attr( $settings['max_images'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kexiu_tg_parse_mode">解析模式</label></th>
						<td>
							<select id="kexiu_tg_parse_mode" name="kexiu_telegram_sync[parse_mode]">
								<option value="HTML" <?php selected( $settings['parse_mode'], 'HTML' ); ?>>HTML</option>
								<option value="MarkdownV2" <?php selected( $settings['parse_mode'], 'MarkdownV2' ); ?>>MarkdownV2</option>
							</select>
							<p class="description">MarkdownV2 模式下模板中的固定符号须自行转义；变量内容会自动转义。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kexiu_tg_template">消息模板</label></th>
						<td>
							<textarea class="large-text code" rows="8" id="kexiu_tg_template" name="kexiu_telegram_sync[template]"><?php echo esc_textarea( $settings['template'] ); ?></textarea>
							<p class="description">变量：<code>{title}</code> <code>{excerpt}</code> <code>{url}</code> <code>{site}</code></p>
						</td>
					</tr>
				</table>

				<?php submit_button( '保存设置' ); ?>
			</form>

			<hr>
			<h2>批量同步历史文章</h2>
			<p>
				未同步：<strong><?php echo (int) $not_synced; ?></strong> 篇 ·
				全部已发布：<strong><?php echo (int) $all_posts; ?></strong> 篇
			</p>
			<p>
				<label>
					模式：
					<select id="kexiu-tg-bulk-mode">
						<option value="not_synced">仅未同步</option>
						<option value="all">全部已发布</option>
					</select>
				</label>
				<label style="margin-left:12px;">
					分类：
					<select id="kexiu-tg-bulk-category">
						<option value="0">全部分类</option>
						<?php foreach ( $categories as $cat ) : ?>
							<option value="<?php echo (int) $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label style="margin-left:12px;">
					<input type="checkbox" id="kexiu-tg-bulk-resync" value="1" />
					强制重推（已同步的也会重新发送）
				</label>
			</p>
			<p>
				<button type="button" class="button button-primary" id="kexiu-tg-bulk-start">开始批量同步</button>
				<span id="kexiu-tg-bulk-status" style="margin-left:12px;"></span>
			</p>

			<hr>
			<h2>同步日志</h2>
			<p>
				<button type="button" class="button" id="kexiu-tg-clear-logs">清空日志</button>
			</p>
			<table class="widefat striped" style="max-width:960px;">
				<thead>
					<tr>
						<th>时间</th>
						<th>文章</th>
						<th>频道</th>
						<th>操作</th>
						<th>状态</th>
						<th>说明</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr><td colspan="6">暂无日志</td></tr>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) ( $log['time'] ?? 0 ) ) ); ?></td>
								<td>
									<?php if ( ! empty( $log['post_id'] ) ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( (int) $log['post_id'] ) ); ?>">#<?php echo (int) $log['post_id']; ?></a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $log['channel_id'] ?? '' ); ?></code></td>
								<td><?php echo esc_html( $log['action'] ?? '' ); ?></td>
								<td><?php echo esc_html( $log['status'] ?? '' ); ?></td>
								<td><?php echo esc_html( $log['message'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
