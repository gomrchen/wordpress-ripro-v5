(function () {
	tinymce.PluginManager.add('kexiu_gallery', function (editor) {
		editor.addButton('kexiu_gallery', {
			title: '一键整理截图画廊',
			icon: 'gallery',
			onclick: function () {
				if (typeof window.kexiuFormatScreenshotGallery === 'function') {
					window.kexiuFormatScreenshotGallery();
				}
			}
		});
	});
})();
