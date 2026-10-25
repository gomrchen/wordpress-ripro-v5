<?php
/**
 * 子比主题深度配置（本地开发）
 * 用法：在 wordpress 目录用 wp eval-file 执行
 */

if (!defined('ABSPATH')) {
    exit(1);
}

echo "== 1. WordPress 基础 ==\n";
update_option('blogname', '万希');
update_option('blogdescription', '源码 · 希望 · 万象');
update_option('timezone_string', 'Asia/Shanghai');
update_option('date_format', 'Y-m-d');
update_option('time_format', 'H:i');
update_option('WPLANG', 'zh_CN');
update_option('start_of_week', 1);
update_option('users_can_register', 1);
update_option('default_comment_status', 'open');
update_option('default_ping_status', 'closed');
update_option('show_on_front', 'posts');
update_option('posts_per_page', 12);
update_option('thumbnail_size_w', 300);
update_option('thumbnail_size_h', 200);
update_option('medium_size_w', 720);
update_option('medium_size_h', 480);

// 固定链接（子比必须）
if (function_exists('WP_CLI')) {
    WP_CLI::runcommand('rewrite structure "/%post_id%.html" --hard');
} else {
    update_option('permalink_structure', '/%post_id%.html');
    flush_rewrite_rules(true);
}
echo "permalink OK\n";

echo "== 2. 触发 CSF 保存默认主题选项 ==\n";
$_GET['page'] = 'zibll_options';
$_REQUEST['page'] = 'zibll_options';
if (!defined('WP_ADMIN')) {
    define('WP_ADMIN', true);
}
// 重新跑后台选项构建（after_setup_theme 已过，手动调用）
if (function_exists('zib_csf_admin_options')) {
    zib_csf_admin_options();
}
// 强制从 CSF 实例落盘默认值
if (class_exists('CSF') && !empty(CSF::$args['admin_options']['zibll_options'])) {
    $inst = CSF::$args['admin_options']['zibll_options'];
    // 部分版本对象存在 get_default / set
}
$opts = get_option('zibll_options');
if (!is_array($opts) || empty($opts)) {
    // 手工写入一套深度默认（覆盖关键项；未列出的仍可用主题内置 default）
    $opts = array();
}
echo 'zibll_options keys before: ' . (is_array($opts) ? count($opts) : 0) . "\n";

echo "== 3. 深度覆盖本地配置 ==\n";
$deep = array(
    // SEO
    'seo_connector'           => ' - ',
    'seo_home_title'          => '子比本地开发站',
    'seo_home_keywords'       => 'WordPress,子比主题,本地开发',
    'seo_home_desc'           => '基于子比主题 8.9 的本地开发环境，用于二次开发与功能验证。',
    'seo_list_img_s'          => true,
    'post_img_auto_alt'       => true,
    'no_categoty'             => true, // 分类 url 去 category

    // 外观 / 模式
    'theme_mode'              => 'white-theme', // 日间，便于开发
    'theme_mode_button'       => array('pc_nav', 'm_menu'),
    'nav_fixed'               => true,
    'nav_scroll_down_hide'    => false,
    'nav_newposts'            => true,
    'footer_tabbar_s'         => true,
    'sidebar_home_s'          => false,

    // 性能
    'lazy_posts_thumb'        => true,
    'lazy_avatar'             => true,
    'lazy_comment'            => true,
    'lazy_sider'              => true,
    'lazy_cover'              => true,
    'lazy_other'              => true,
    'lazy_animation'          => 'blur',
    'paging_ajax_s'           => '1',
    'paging_ajax_ias_s'       => true,

    // 列表
    'home_posts_list_s'       => true,
    'home_list1_orderby_s'    => true,
    'img_position'            => 'left',
    'thumb_postfirstimg_s'    => true,
    'thumb_catimg_s'          => true,
    'ajax_trigger'            => '<i class="fa fa-angle-right"></i>加载更多',
    'ajax_nomore'             => '没有更多内容了',

    // 用户
    'user_sign_type'          => 'modal',
    'user_center_rewrite_slug'=> 'user',
    'show_logo'               => true,

    // 页脚
    'footer_contact_m_s'      => true,
    'footer_contact_qq'       => '',
    'footer_contact_email'    => 'admin@example.com',
    'footer_mini_img_m_s'     => false,
    'footer'                  => 'Copyright © ' . date('Y') . ' · <a href="' . home_url() . '">子比本地开发站</a> · 仅供本地开发',

    // 社区 / 商城开关（本地开发建议开启模块便于测）
    'bbs_s'                   => true,
    'shop_s'                  => true,

    // 调试友好
    'disabled_ajax_pjax'      => false,
);

$opts = array_merge(is_array($opts) ? $opts : array(), $deep);
update_option('zibll_options', $opts);
echo 'zibll_options keys after: ' . count($opts) . "\n";

echo "== 4. 创建必要页面 ==\n";
function zib_setup_page($title, $slug, $template = '', $content = '')
{
    $exist = get_page_by_path($slug);
    if ($exist) {
        echo "page exists: {$slug} (#{$exist->ID})\n";
        return (int) $exist->ID;
    }
    $id = wp_insert_post(array(
        'post_title'   => $title,
        'post_name'    => $slug,
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => $content,
        'post_author'  => 1,
    ));
    if ($id && !is_wp_error($id) && $template) {
        update_post_meta($id, '_wp_page_template', $template);
    }
    echo "page created: {$slug} (#{$id})\n";
    return (int) $id;
}

$user_sign = zib_setup_page('用户登录', 'user-sign', 'pages/user-sign.php');
$user_auth = zib_setup_page('用户认证', 'user-auth', 'pages/user-auth.php');
$links     = zib_setup_page('网址导航', 'links', 'pages/links.php');
$archives  = zib_setup_page('文章归档', 'archives', 'pages/archives.php');
$newposts  = zib_setup_page('发布文章', 'newposts', 'pages/newposts.php');
$forums    = zib_setup_page('社区论坛', 'forums', 'pages/forums.php');
$download  = zib_setup_page('资源下载', 'download', 'pages/download.php');

// 写入主题选项中的页面关联（常见 id，若主题用到会生效）
$page_map = array(
    'user_sign_page' => $user_sign,
    'user_auth_page' => $user_auth,
    'links_page'     => $links,
    'archives_page'  => $archives,
    'newposts_page'  => $newposts,
    'forums_page'    => $forums,
    'download_page'  => $download,
);
$opts = get_option('zibll_options');
foreach ($page_map as $k => $v) {
    if ($v) {
        $opts[$k] = $v;
    }
}
update_option('zibll_options', $opts);

echo "== 5. 分类 / 示例文章 / 菜单 ==\n";
$cats = array(
    'news'    => '资讯动态',
    'tutorial'=> '教程指南',
    'dev'     => '开发笔记',
);
$cat_ids = array();
foreach ($cats as $slug => $name) {
    $t = term_exists($slug, 'category');
    if (!$t) {
        $t = wp_insert_term($name, 'category', array('slug' => $slug));
    }
    $cat_ids[$slug] = is_array($t) ? (int) $t['term_id'] : (int) $t;
    echo "cat {$slug}={$cat_ids[$slug]}\n";
}

$sample_posts = array(
    array('欢迎使用子比主题本地站', 'news', "这是本地开发站的第一篇文章。\n\n主题版本 8.9，已完成深度基础配置。可在后台「zibll主题设置」继续细化。"),
    array('如何开始二次开发', 'tutorial', "推荐在主题目录新建 <code>func.php</code> 写自定义代码，避免升级覆盖。\n\nDocker 目录已挂载，改 PHP/CSS 会实时生效。"),
    array('Docker 开发环境说明', 'dev', "访问地址：http://localhost:8080\n后台：/wp-admin\n账号见记事本。"),
);
foreach ($sample_posts as $sp) {
    $exists = get_page_by_title($sp[0], OBJECT, 'post');
    if ($exists) {
        echo "post exists: {$sp[0]}\n";
        continue;
    }
    $pid = wp_insert_post(array(
        'post_title'   => $sp[0],
        'post_content' => $sp[2],
        'post_status'  => 'publish',
        'post_type'    => 'post',
        'post_author'  => 1,
        'post_category'=> array($cat_ids[$sp[1]]),
    ));
    echo "post #{$pid}: {$sp[0]}\n";
}

// 主菜单
$menu_name = '主导航';
$menu = wp_get_nav_menu_object($menu_name);
if (!$menu) {
    $menu_id = wp_create_nav_menu($menu_name);
} else {
    $menu_id = (int) $menu->term_id;
}
// 清空后重建简单项
$items = wp_get_nav_menu_items($menu_id);
if ($items) {
    foreach ($items as $it) {
        wp_delete_post($it->ID, true);
    }
}
wp_update_nav_menu_item($menu_id, 0, array(
    'menu-item-title'  => '首页',
    'menu-item-url'    => home_url('/'),
    'menu-item-status' => 'publish',
    'menu-item-type'   => 'custom',
));
foreach (array($forums => '社区', $links => '导航', $archives => '归档') as $pid => $label) {
    if (!$pid) continue;
    wp_update_nav_menu_item($menu_id, 0, array(
        'menu-item-title'     => $label,
        'menu-item-object'    => 'page',
        'menu-item-object-id' => $pid,
        'menu-item-type'      => 'post_type',
        'menu-item-status'    => 'publish',
    ));
}
$locations = get_theme_mod('nav_menu_locations');
if (!is_array($locations)) {
    $locations = array();
}
// 子比常见 location：topbar / mainmenu 等，尽量多绑
foreach (array('main', 'top', 'mobile', 'topmenu', 'mainmenu', 'nav') as $loc) {
    $locations[$loc] = $menu_id;
}
set_theme_mod('nav_menu_locations', $locations);
echo "menu #{$menu_id} OK\n";

flush_rewrite_rules(true);
echo "== DONE ==\n";
echo "后台主题设置: " . admin_url('admin.php?page=zibll_options') . "\n";
echo "前台: " . home_url('/') . "\n";
