(function ($) {
	'use strict';

	function getEditorContent() {
		if (window.tinyMCE && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden()) {
			return tinyMCE.activeEditor.getContent();
		}
		return $('#content').val() || '';
	}

	function setEditorContent(content) {
		if (window.tinyMCE && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden()) {
			tinyMCE.activeEditor.setContent(content);
		}
		$('#content').val(content);
	}

	function getPostId() {
		return parseInt($('#post_ID').val(), 10) || 0;
	}

	function formatGallery($btn) {
		var postId = getPostId();

		if (!postId) {
			window.alert('请先保存文章草稿后再整理截图。');
			return;
		}

		if (!window.confirm(kexiuEditorTools.i18n.confirm)) {
			return;
		}

		var originalText = $btn.text();
		$btn.prop('disabled', true).text(kexiuEditorTools.i18n.working);

		$.post(kexiuEditorTools.ajaxUrl, {
			action: 'kexiu_format_screenshot_gallery',
			nonce: kexiuEditorTools.nonce,
			post_id: postId,
			content: getEditorContent()
		})
			.done(function (response) {
				if (!response || !response.success) {
					window.alert((response && response.data && response.data.message) || kexiuEditorTools.i18n.error);
					return;
				}

				setEditorContent(response.data.content);
				var msg = kexiuEditorTools.i18n.done
					.replace('%d', response.data.count)
					.replace('%v', response.data.vertical || 0)
					.replace('%h', response.data.horizontal || 0);
				window.alert(msg);
			})
			.fail(function () {
				window.alert(kexiuEditorTools.i18n.error);
			})
			.always(function () {
				$btn.prop('disabled', false).text(originalText);
			});
	}

	$(function () {
		$('#kexiu-format-gallery-btn').on('click', function () {
			formatGallery($(this));
		});
	});

	window.kexiuFormatScreenshotGallery = function () {
		var $btn = $('#kexiu-format-gallery-btn');
		if ($btn.length) {
			formatGallery($btn);
		}
	};
})(jQuery);
