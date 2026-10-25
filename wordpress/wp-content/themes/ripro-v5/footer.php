
</main>
<!-- **************** MAIN CONTENT END **************** -->

<!-- =======================
Footer START -->
<footer class="site-footer py-md-4 py-2 mt-2 mt-md-4">
	<div class="container">

		<?php get_template_part( 'template-parts/footer/widget');?>

		<div class="text-center small w-100">
			<div><?php echo _cao('site_copyright_text','Copyright © 2023 <a target="_blank" href="http://ritheme.com/">RiPro-V5</a> - All rights reserved');?></div>
			<div class=""><?php echo _cao('site_ipc_text','') . _cao('site_ipc2_text','');?></div>
		</div>

		<?php get_template_part( 'template-parts/footer/links');?>

		<?php if (defined('WP_DEBUG') && WP_DEBUG == true) {
			echo '<p id="debug-info" class="m-0 small text-primary w-100 text-center">'.sprintf('SQL：%s',get_num_queries()).'<span class="sep"> | </span>'.sprintf('Pages：%ss',timer_stop(0,5)).'</p>';
		}?>

	</div>
</footer>
<!-- =======================
Footer END -->


<!-- Back to top rollbar-->
<?php get_template_part('template-parts/footer/rollbar');?>
<div class="back-top"><i class="fas fa-caret-up"></i></div>

<!-- m-navbar -->
<?php get_template_part('template-parts/footer/m-navbar');?>

<!-- dimmer-->
<div class="dimmer"></div>
<?php get_template_part( 'template-parts/footer/off-canvas');?>


<?php wp_footer();?>

<!-- 自定义js代码 统计代码 -->
<?php if ( !empty(_cao('site_web_js')) ) echo _cao('site_web_js');?>
<!-- 自定义js代码 统计代码 END -->

<?php if ( !empty(_cao('is_site_translate_js',1)) ) : ?>



<?php 
// 1. 获取后台设置的语言选项值（如 auto, zh_CN, en_US 等）
$site_locale_setting = _cao('site_default_locale','auto');

// 如果后台选的是 'auto' 或为空，我们自动获取 WordPress 系统当前实际的语言代码
if (empty($site_locale_setting) || 'auto' === $site_locale_setting) {
    $site_locale_setting = get_locale(); 
}

// 2. 建立 WordPress 语言代码到 translate.js 语种名称的映射表
$translate_js_map = array(
    'zh_CN' => 'chinese_simplified',  // 简体中文
    'en_US' => 'english',             // 英文
    'zh_TW' => 'chinese_traditional', // 繁体中文
    'ko_KR' => 'korean',              // 韩语
    'ja_JP' => 'japanese',            // 日语
);

// 3. 匹配对应的目标语言，如果没有匹配到，则默认不自动翻译（保持本地语种）
$target_language = isset($translate_js_map[$site_locale_setting]) ? $translate_js_map[$site_locale_setting] : 'chinese_simplified';
?>



<script src="<?php echo get_template_directory_uri();?>/assets/js/translate.js"></script>
<script>
    translate.service.use('client.edge'); // 设置机器翻译服务通道
    translate.listener.start(); // 开启页面元素动态监控
    
    // 设置本地语种（即你网页原本是用什么语言写的，RiPro一般默认是简体中文）
    translate.language.setLocal('chinese_simplified'); 
    
    // 允许切换的语种列表
    translate.selectLanguageTag.languages = 'chinese_simplified,english,chinese_traditional,japanese,korean';
    
    // 🚀 【核心功能】从 PHP 动态输出后台选中的默认目标语种
    translate.language.setDefaultTo('<?php echo esc_js($target_language); ?>');
    
    translate.execute(); // 完成翻译初始化，进行翻译
</script>
<?php endif; ?>

</body>
</html>
