<?php
/**
 * 开源版：前台链接/HTML 与正版推广项对齐（保留后台可改，渲染与一次性迁移写库）
 */
defined('ABSPATH') || exit;

/**
 * @param array<int, array<string, string>> $items
 * @return array<int, array<string, string>>
 */
function ripro_v5_filter_rollbar_items($items) {
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
 * 右下角悬浮菜单：中文标签 + Font Awesome 图标显示修正
 */
function ripro_v5_rollbar_icon_styles() {
    if (is_admin()) {
        return;
    }
    $css = '
.rollbar .actions li > a {
    width: auto;
    min-width: 3.25rem;
    padding: 0.55rem 0.35rem;
}
.rollbar .actions li > a > i {
    font-family: "Font Awesome 5 Free";
    font-weight: 900;
    font-style: normal;
    font-size: 1.15rem;
    line-height: 1;
    display: block;
    margin-bottom: 0.35rem;
    -webkit-font-smoothing: antialiased;
}
.rollbar .actions li > a > i.far {
    font-weight: 400;
}
.rollbar .actions li > a > i.fab {
    font-family: "Font Awesome 5 Brands";
    font-weight: 400;
}
.rollbar .actions li > a > span {
    letter-spacing: 0;
    white-space: nowrap;
    font-size: 0.68rem;
    line-height: 1.25;
    word-break: keep-all;
}
';
    wp_add_inline_style('main', $css);
}

add_action('wp_enqueue_scripts', 'ripro_v5_rollbar_icon_styles', 120);
