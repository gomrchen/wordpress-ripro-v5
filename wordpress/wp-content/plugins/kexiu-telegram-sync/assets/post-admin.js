(function ($) {
	'use strict';

	$('#kexiu-tg-sync-now').on('click', function () {
		var $btn = $(this);
		var postId = $btn.data('post-id');
		var original = $btn.text();

		$btn.prop('disabled', true).text(kexiuTelegramSync.i18n.working);

		$.post(kexiuTelegramSync.ajaxUrl, {
			action: 'kexiu_tg_sync_post',
			nonce: kexiuTelegramSync.nonce,
			post_id: postId
		})
			.done(function (response) {
				if (response && response.success) {
					window.alert(response.data.message || kexiuTelegramSync.i18n.success);
					window.location.reload();
				} else {
					window.alert((response && response.data && response.data.message) || kexiuTelegramSync.i18n.error);
				}
			})
			.fail(function () {
				window.alert(kexiuTelegramSync.i18n.error);
			})
			.always(function () {
				$btn.prop('disabled', false).text(original);
			});
	});
})(jQuery);
