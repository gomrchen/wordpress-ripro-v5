<?php
defined('ABSPATH') || exit;
###############################################################################

#主题版本信息
define('_THEME_VERSION', '10.5'); //主题版本
define('_THEME_DEBUG', 0); //调试模式控制切勿开启

###############################################################################



// 调试模式控制错误日志显示
function set_error_reporting() {
    if (defined('WP_DEBUG') && WP_DEBUG || _THEME_DEBUG) {
        error_reporting(E_ALL);
    } else {
        error_reporting(0); // 关闭报错，防止乱码
    }
}
add_action('init', 'set_error_reporting');


/**
 * Sets up theme defaults and registers support for various WordPress features.
 */
function zb_setup() {

    
    load_theme_textdomain('ripro', get_template_directory() . '/languages');

    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('post-formats', array('image', 'video', 'audio'));

    // add link manager // 开启友情链接功能
    add_filter('pre_option_link_manager_enabled', '__return_true');
    
    // This theme uses wp_nav_menu() in one location.
    register_nav_menus(
        array(
            'main-menu' => esc_html__('全站顶部菜单', 'ripro'),
        )
    );

    add_theme_support(
        'html5',
        array(
            'search-form',
            'comment-form',
            'comment-list',
            'gallery',
            'caption',
            'style',
            'script',
        )
    );

    // Add theme support for selective refresh for widgets.
    add_theme_support('customize-selective-refresh-widgets');

    // 删除render_block 过滤器
    remove_filter('render_block', 'wp_render_duotone_support');
    remove_filter('render_block', 'wp_restore_group_inner_container');
    remove_filter('render_block', 'wp_render_layout_support_flag');

    // 第一启用主题时候插入订单表
    $theme_status_key = 'ripro_theme_setup_v50';
    $the_theme_status = get_option($theme_status_key);

    //初始化安装
    require get_template_directory() . '/inc/setup-db.php';
    $ZB_SetupDb = new ZB_SetupDb();

    if (empty($the_theme_status)) {
        //安装数据库操作
        if ($ZB_SetupDb->install_db()) {
            update_option($theme_status_key, '1');
        }

        //重写固定连接规则
        flush_rewrite_rules(false);
    }

}
add_action('after_setup_theme', 'zb_setup');

/**
 * Register widget area.
 */
function zb_widgets_init() {

    register_sidebar(array(
        'name'          => esc_html__('首页模块', 'ripro'),
        'id'            => 'home-center',
        'description'   => esc_html__('首页模块主内容区域', 'ripro'),
        'before_widget' => '<div id="%1$s" class="home-widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '',
        'after_title'   => '',
    ));

    // 尝试从缓存中获取侧边栏文章展示查询结果
    $cache_key = 'site_mod_page_template';
    $widget_pages = wp_cache_get($cache_key);
    if ($widget_pages === false) {
        // 如果缓存中不存在查询结果，则执行查询并将结果存入缓存
        $widget_pages = get_pages( array(
          'meta_key' => '_wp_page_template',
          'meta_value' => 'page-templates/page-widget.php'
        ) );
        wp_cache_set($cache_key, $widget_pages);
    }


    // Loop through the pages and output their IDs
    foreach ( $widget_pages as $page ) {
       register_sidebar(array(
            'name'          => $page->post_title,
            'id'            => 'home-center-'.$page->ID,
            'description'   => esc_html__('自定义模块主内容区域', 'ripro'),
            'before_widget' => '<div id="%1$s" class="home-widget %2$s">',
            'after_widget'  => '</div>',
            'before_title'  => '',
            'after_title'   => '',
        ));
    }

    register_sidebar(array(
        'name'          => esc_html__('文章侧边栏', 'ripro'),
        'id'            => 'single-sidebar',
        'before_widget' => '<div id="%1$s" class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h5 class="widget-title">',
        'after_title'   => '</h5>',
    ));

    foreach (array(
        'WP_Widget_Calendar',
        'WP_Widget_RSS',
        'WP_Widget_Meta',
        'WP_Widget_Search',
        'WP_Widget_Archives',
        'WP_Widget_Pages',
        'WP_Widget_Media_Gallery',
        'WP_Widget_Recent_Comments',
        'WP_Nav_Menu_Widget',
    ) as $widget_class) {
        unregister_widget($widget_class);
    }
}
add_action('widgets_init', 'zb_widgets_init');


// Require Composer's autoloading file
if (is_file($composer = get_template_directory() . '/vendor/autoload.php')) {
    require_once $composer;


}


//动态加载主题所需文件
function load_ripto_theme_files( $is_debug = false ) {
    $includes = array(
        'template-csf.php',          // 设置框架
        'template-clean.php',        // 主题基本优化
        'template-filter.php',       // 主题钩子
        'template-assets.php',       // js 和 CSS
        'template-admin.php',        // 后台定制和后台安全优化
        'template-post-type.php',    // 自定义类型
        'template-rewrite.php',      // 伪静态路由
        'template-mail.php',         // 消息推送 webhook
        'template-ajax.php',         // AJAX 接口集成
        'template-walker.php',       // bootstrap 菜单类
        'template-seo.php',          // 内置 SEO
        'template-tags.php',         // 主题功能标签
        'template-shop.php',         // 主题 shop
        'template-class.php',        // 主题 class
        'template-open-source.php',  // 开源版前台链接对齐
        '/core/template-core.php'    // 主题 class
    );

    foreach ($includes as $file) {
        require_once get_template_directory() . '/inc/' . $file;
    }

}

load_ripto_theme_files(false);

###################################### RITHEME.COM END #########################################