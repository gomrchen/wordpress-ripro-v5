(function ($) {
	'use strict';

	function testConnection() {
		var $btn = $('#kexiu-tg-test-connection');
		var $result = $('#kexiu-tg-test-result');

		$btn.prop('disabled', true);
		$result.text('测试中…');

		$.post(kexiuTelegramSettings.ajaxUrl, {
			action: 'kexiu_tg_test_connection',
			nonce: kexiuTelegramSettings.nonce
		})
			.done(function (response) {
				if (response && response.success) {
					$result.css('color', '#008a20').text(response.data.message);
				} else {
					$result.css('color', '#b32d2e').text((response && response.data && response.data.message) || '测试失败');
				}
			})
			.fail(function () {
				$result.css('color', '#b32d2e').text('请求失败，请先保存设置');
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
	}

	function getChannelRowHtml(index) {
		return (
			'<tr class="kexiu-tg-channel-row">' +
			'<td><input type="checkbox" name="kexiu_telegram_sync[channels][' + index + '][enabled]" value="1" checked />' +
			'<input type="hidden" name="kexiu_telegram_sync[channels][' + index + '][id]" value="ch' + index + '" /></td>' +
			'<td><input type="text" class="regular-text" name="kexiu_telegram_sync[channels][' + index + '][label]" placeholder="频道名称" /></td>' +
			'<td><input type="password" class="regular-text code" name="kexiu_telegram_sync[channels][' + index + '][bot_token]" autocomplete="off" /></td>' +
			'<td><input type="text" class="regular-text code" name="kexiu_telegram_sync[channels][' + index + '][channel_id]" placeholder="@channel" /></td>' +
			'<td><button type="button" class="button kexiu-tg-remove-channel">删除</button></td>' +
			'</tr>'
		);
	}

	function runBulkSync(offset) {
		var $status = $('#kexiu-tg-bulk-status');
		var $btn = $('#kexiu-tg-bulk-start');

		$.post(kexiuTelegramSettings.ajaxUrl, {
			action: 'kexiu_tg_bulk_sync',
			nonce: kexiuTelegramSettings.bulkNonce,
			mode: $('#kexiu-tg-bulk-mode').val(),
			category_id: $('#kexiu-tg-bulk-category').val(),
			resync: $('#kexiu-tg-bulk-resync').is(':checked') ? 1 : 0,
			offset: offset || 0
		})
			.done(function (response) {
				if (!response || !response.success) {
					$status.css('color', '#b32d2e').text((response && response.data && response.data.message) || '批量同步失败');
					$btn.prop('disabled', false);
					return;
				}

				var data = response.data;
				$status.css('color', '#1d2327').text(data.message || '处理中…');

				if (data.errors && data.errors.length) {
					$status.append(' · 错误: ' + data.errors.join('; '));
				}

				if (data.rate_limited) {
					$status.css('color', '#b32d2e').append(' — 触发限流，10 秒后自动继续…');
					setTimeout(function () {
						runBulkSync(data.offset);
					}, 10000);
					return;
				}

				if (data.finished) {
					$status.css('color', '#008a20').text('批量同步完成');
					$btn.prop('disabled', false);
					return;
				}

				runBulkSync(data.offset);
			})
			.fail(function () {
				$status.css('color', '#b32d2e').text('请求失败');
				$btn.prop('disabled', false);
			});
	}

	$('#kexiu-tg-test-connection').on('click', testConnection);

	$('#kexiu-tg-add-channel').on('click', function () {
		var index = $('#kexiu-tg-channels-table tbody tr').length;
		$('#kexiu-tg-channels-table tbody').append(getChannelRowHtml(index));
	});

	$('#kexiu-tg-channels-table').on('click', '.kexiu-tg-remove-channel', function () {
		var $rows = $('#kexiu-tg-channels-table tbody tr');
		if ($rows.length <= 1) {
			return;
		}
		$(this).closest('tr').remove();
	});

	$('#kexiu-tg-bulk-start').on('click', function () {
		var $btn = $(this);
		$btn.prop('disabled', true);
		$('#kexiu-tg-bulk-status').css('color', '#1d2327').text('开始批量同步…');
		runBulkSync(0);
	});

	$('#kexiu-tg-clear-logs').on('click', function () {
		var $btn = $(this);
		$btn.prop('disabled', true);

		$.post(kexiuTelegramSettings.ajaxUrl, {
			action: 'kexiu_tg_clear_logs',
			nonce: kexiuTelegramSettings.bulkNonce
		})
			.done(function (response) {
				if (response && response.success) {
					window.location.reload();
				}
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
	});
})(jQuery);
