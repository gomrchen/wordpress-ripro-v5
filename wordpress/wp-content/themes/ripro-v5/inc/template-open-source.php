<?php
/**
 * 开源版：前台链接/HTML 与正版推广项对齐（保留后台可改，渲染与一次性迁移写库）
 */
defined('ABSPATH') || exit;

/**
 * 官网演示对齐模式：保留购买主题/ritheme 友链等，不做开源替换
 */
function ripro_v5_official_demo_ui_enabled() {
    return (bool) get_option('ripro_v5_official_demo_ui', false);
}

/**
 * 微信登录配置（合并默认键，避免演示数据缺 sns_weixin_mod 等导致 Warning）
 *
 * @return array<string, mixed>
 */
function ripro_v5_get_sns_weixin() {
    $opt = _cao('sns_weixin', array());
    if (!is_array($opt)) {
        $opt = array();
    }
    $defaults = array(
        'sns_weixin_mod'    => 'open',
        'app_id'            => '',
        'app_secret'        => '',
        'mp_app_id'         => '',
        'mp_app_secret'     => '',
        'mp_app_token'      => '',
        'custom_wxmenu_opt' => array(),
    );
    return array_merge($defaults, $opt);
}

/**
 * 补齐主题选项缺口（演示 JSON 常缺字段）
 */
function ripro_v5_migrate_option_gaps_once() {
    if (get_option('ripro_v5_option_gaps_v1')) {
        return;
    }
    if (!defined('_OPTIONS_PRE')) {
        return;
    }
    $opts = get_option(_OPTIONS_PRE, array());
    if (!is_array($opts)) {
        $opts = array();
    }
    $changed = false;

    $wx = isset($opts['sns_weixin']) && is_array($opts['sns_weixin']) ? $opts['sns_weixin'] : array();
    $wx_defaults = array(
        'sns_weixin_mod'    => (!empty($wx['app_id']) ? 'open' : 'mp'),
        'app_id'            => '',
        'app_secret'        => '',
        'mp_app_id'         => '',
        'mp_app_secret'     => '',
        'mp_app_token'      => '',
        'custom_wxmenu_opt' => array(),
    );
    $wx_merged = array_merge($wx_defaults, $wx);
    if ($wx_merged !== $wx) {
        $opts['sns_weixin'] = $wx_merged;
        $changed = true;
    }

    if (!isset($opts['sns_qq']) || !is_array($opts['sns_qq'])) {
        $opts['sns_qq'] = array('app_id' => '', 'app_secret' => '');
        $changed = true;
    } else {
        foreach (array('app_id' => '', 'app_secret' => '') as $k => $v) {
            if (!array_key_exists($k, $opts['sns_qq'])) {
                $opts['sns_qq'][$k] = $v;
                $changed = true;
            }
        }
    }

    if (!isset($opts['epay']) || !is_array($opts['epay'])) {
        $opts['epay'] = array(
            'pid'         => '',
            'key'         => '',
            'apiurl'      => '',
            'is_alipay'   => false,
            'is_weixin'   => false,
        );
        $changed = true;
    }

    if (!array_key_exists('is_sns_qq', $opts)) {
        $opts['is_sns_qq'] = false;
        $changed = true;
    }
    if (!array_key_exists('is_sns_weixin', $opts)) {
        $opts['is_sns_weixin'] = false;
        $changed = true;
    }

    if ($changed) {
        update_option(_OPTIONS_PRE, $opts);
    }
    update_option('ripro_v5_option_gaps_v1', 1);
}

add_action('after_setup_theme', 'ripro_v5_migrate_option_gaps_once', 25);

/**
 * Font Awesome 5 类名规范化（演示数据里仍有 fa fa-diamond 等 v4 写法）
 *
 * @param array<int, array<string, string>> $items
 * @return array<int, array<string, string>>
 */
function ripro_v5_normalize_rollbar_icons($items) {
    if (!is_array($items)) {
        return array();
    }
    foreach ($items as &$item) {
        if (!is_array($item) || empty($item['icon'])) {
            continue;
        }
        $icon = trim((string) $item['icon']);
        if ($icon === 'fa fa-diamond' || $icon === 'fa-diamond') {
            $item['icon'] = 'fas fa-gem';
        }
    }
    unset($item);
    return $items;
}

/**
 * @param array<int, array<string, string>> $items
 * @return array<int, array<string, string>>
 */
function ripro_v5_filter_rollbar_items($items) {
    if (ripro_v5_official_demo_ui_enabled()) {
        return ripro_v5_normalize_rollbar_icons($items);
    }
    if (!is_array($items)) {
        return array();
    }
    $out = array();
    foreach ($items as $item) {
        if (!is_array($item) || empty($item['href'])) {
            continue;
        }
        $title = isset($item['title']) ? (string) $item['title'] : '';
        $href  = isset($item['href']) ? (string) $item['href'] : '';
        if ($title === '购买主题' || preg_match('#ritheme\.com#i', $href)) {
            $item['title']    = __('工单反馈', 'ripro');
            $item['icon']     = 'fas fa-life-ring';
            $item['href']     = home_url('/user/ticket');
            $item['is_blank'] = false;
        }
        if (!empty($item['icon']) && $item['icon'] === 'fa fa-diamond') {
            $item['icon'] = 'fas fa-gem';
        }
        $out[] = $item;
    }
    return $out;
}

/**
 * @param array<int, array<string, string>> $items
 * @return array<int, array<string, string>>
 */
function ripro_v5_filter_friend_links($items) {
    if (ripro_v5_official_demo_ui_enabled()) {
        return is_array($items) ? $items : array();
    }
    if (!is_array($items)) {
        return array();
    }
    $title_map = array(
        'RiTheme主题官网'    => home_url('/user'),
        '日主题官网'         => home_url('/tags'),
        'RiPro-V5主题官方'  => home_url('/vip-prices'),
    );
    $out = array();
    foreach ($items as $item) {
        if (!is_array($item) || empty($item['href'])) {
            continue;
        }
        $title = isset($item['title']) ? (string) $item['title'] : '';
        $href  = (string) $item['href'];
        if (preg_match('#ritheme\.com#i', $href)) {
            $item['href'] = isset($title_map[$title]) ? $title_map[$title] : home_url('/');
        }
        $out[] = $item;
    }
    return $out;
}

/**
 * 富文本里的外链/素材替换（页脚、公告等）
 */
function ripro_v5_filter_promo_html($html) {
    if (ripro_v5_official_demo_ui_enabled()) {
        return $html;
    }
    if (!is_string($html) || $html === '') {
        return $html;
    }
    $home = esc_url(home_url('/'));
    $html = preg_replace('#https?://(www\.)?ritheme\.com[^"\'\s<]*#i', $home, $html);
    $html = str_replace('ritheme-qr.png', 'logo.png', $html);
    $html = preg_replace('#/wp-content/themes/ripro-v5/assets/img/adds[^"\']*#i', get_template_directory_uri() . '/assets/img/logo.png', $html);
    return $html;
}

function ripro_v5_default_copyright_html() {
    $name = get_bloginfo('name');
    $year = wp_date('Y');
    return sprintf(
        'Copyright © %s %s - All rights reserved',
        esc_html($year),
        esc_html($name)
    );
}

/**
 * 一次性把数据库里已保存的推广项改为站内（仅执行一次）
 */
function ripro_v5_migrate_open_source_options_once() {
    if (ripro_v5_official_demo_ui_enabled()) {
        return;
    }
    if (get_option('ripro_v5_open_source_opts_v1')) {
        return;
    }
    if (!defined('_OPTIONS_PRE')) {
        return;
    }
    $opts = get_option(_OPTIONS_PRE, array());
    if (!is_array($opts) || empty($opts)) {
        update_option('ripro_v5_open_source_opts_v1', 1);
        return;
    }
    if (!empty($opts['site_footer_rollbar']) && is_array($opts['site_footer_rollbar'])) {
        $opts['site_footer_rollbar'] = ripro_v5_filter_rollbar_items($opts['site_footer_rollbar']);
    }
    if (!empty($opts['site_footer_links']) && is_array($opts['site_footer_links'])) {
        $opts['site_footer_links'] = ripro_v5_filter_friend_links($opts['site_footer_links']);
    }
    if (!empty($opts['site_contact_desc'])) {
        $opts['site_contact_desc'] = ripro_v5_filter_promo_html((string) $opts['site_contact_desc']);
    }
    if (!empty($opts['site_copyright_text'])) {
        $opts['site_copyright_text'] = ripro_v5_filter_promo_html((string) $opts['site_copyright_text']);
    }
    if (!empty($opts['site_notice_popup'])) {
        $opts['site_notice_popup'] = ripro_v5_filter_promo_html((string) $opts['site_notice_popup']);
    }
    update_option(_OPTIONS_PRE, $opts);
    update_option('ripro_v5_open_source_opts_v1', 1);
}

add_filter('ripro_footer_rollbar_items', 'ripro_v5_filter_rollbar_items', 10, 1);
add_filter('ripro_footer_friend_links', 'ripro_v5_filter_friend_links', 10, 1);
add_filter('ripro_footer_widget_links', 'ripro_v5_filter_friend_links', 10, 1);
add_filter('ripro_footer_contact_html', 'ripro_v5_filter_promo_html', 10, 1);
add_filter('ripro_footer_copyright_html', 'ripro_v5_filter_promo_html', 10, 1);

add_action('after_setup_theme', 'ripro_v5_migrate_open_source_options_once', 20);

/**
 * 右下角悬浮菜单：修正中文 letter-spacing；不覆盖 FA 的 font-family（否则品牌图标会空白）
 */
function ripro_v5_rollbar_icon_styles() {
    if (is_admin()) {
        return;
    }
    $css = '
.rollbar .actions li > a {
    width: auto !important;
    min-width: 3.25rem;
    height: auto !important;
    padding: 0.55rem 0.35rem;
}
.rollbar .actions li > a > i {
    display: block;
    font-size: 1.15rem;
    line-height: 1;
    min-height: 1.15rem;
    margin-bottom: 0.35rem;
    -webkit-font-smoothing: antialiased;
}
.rollbar .actions li > a > span {
    letter-spacing: 0 !important;
    white-space: nowrap;
    font-size: 0.68rem;
    line-height: 1.25;
    word-break: keep-all;
}
.division-icon > i.fab {
    font-family: "Font Awesome 5 Brands" !important;
    font-weight: 400;
    font-style: normal;
    font-size: 1.5rem;
    color: #fff;
}
.division-icon > i.fas,
.division-icon > i.far {
    font-family: "Font Awesome 5 Free" !important;
    font-style: normal;
    font-size: 1.5rem;
    color: #fff;
}
.division-icon > i.fas { font-weight: 900; }
.division-icon > i.far { font-weight: 400; }
';
    wp_add_inline_style('csf-fa5', $css);
}

add_action('wp_enqueue_scripts', 'ripro_v5_rollbar_icon_styles', 120);

/**
 * 预加载本地 Font Awesome webfont，避免字体未就绪时 <i> 塌缩只剩文字
 */
function ripro_v5_preload_fontawesome_webfonts() {
    if (is_admin() || _cao('assets_cdn_mod', 'theme') !== 'theme') {
        return;
    }
    $base = get_template_directory_uri() . '/assets/css/font-awesome/webfonts/';
    foreach (array('fa-solid-900.woff2', 'fa-regular-400.woff2', 'fa-brands-400.woff2') as $file) {
        $href = esc_url($base . $file);
        echo '<link rel="preload" href="' . $href . '" as="font" type="font/woff2" crossorigin>' . "\n";
    }
}
add_action('wp_head', 'ripro_v5_preload_fontawesome_webfonts', 1);

/**
 * 内联 @font-face（绝对 URL），避免相对路径或加载顺序导致 webfont 未命中
 */
function ripro_v5_fontawesome_faces_inline() {
    if (is_admin() || _cao('assets_cdn_mod', 'theme') !== 'theme') {
        return;
    }
    $base = trailingslashit(get_template_directory_uri()) . 'assets/css/font-awesome/webfonts/';
    $solid2   = esc_url($base . 'fa-solid-900.woff2');
    $solid    = esc_url($base . 'fa-solid-900.woff');
    $regular2 = esc_url($base . 'fa-regular-400.woff2');
    $regular  = esc_url($base . 'fa-regular-400.woff');
    $brand2   = esc_url($base . 'fa-brands-400.woff2');
    $brand    = esc_url($base . 'fa-brands-400.woff');
    echo '<style id="ripro-fontawesome-faces">';
    echo "@font-face{font-family:'Font Awesome 5 Free';font-style:normal;font-weight:900;font-display:block;src:url('{$solid2}') format('woff2'),url('{$solid}') format('woff');}";
    echo "@font-face{font-family:'Font Awesome 5 Free';font-style:normal;font-weight:400;font-display:block;src:url('{$regular2}') format('woff2'),url('{$regular}') format('woff');}";
    echo "@font-face{font-family:'Font Awesome 5 Brands';font-style:normal;font-weight:400;font-display:block;src:url('{$brand2}') format('woff2'),url('{$brand}') format('woff');}";
    echo '</style>' . "\n";
}
add_action('wp_head', 'ripro_v5_fontawesome_faces_inline', 3);
