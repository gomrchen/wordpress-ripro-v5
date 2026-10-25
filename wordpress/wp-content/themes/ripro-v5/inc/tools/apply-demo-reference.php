<?php
/**
 * 一键对齐官方演示站首页布局（ripro.rizhuti.com 演示数据）
 *
 * 用法（容器内）：
 *   php wp-content/themes/ripro-v5/inc/tools/apply-demo-reference.php
 */
const RIPRO_DEMO_WIDGETS_URL  = 'https://ripro.rizhuti.com/ripro-demo-file/ripro-v5-widgets.json';
const RIPRO_DEMO_OPTIONS_URL  = 'https://ripro.rizhuti.com/ripro-demo-file/ripro-v5-options.json';
const RIPRO_DEMO_SIDEBAR_SRC  = 'home-center-11634';

/**
 * @param array<string, mixed> $instance
 * @return array<string, mixed>
 */
function ripro_v5_demo_rewrite_urls_in_value($instance) {
    if (is_array($instance)) {
        foreach ($instance as $k => $v) {
            $instance[$k] = ripro_v5_demo_rewrite_urls_in_value($v);
        }
        return $instance;
    }
    if (!is_string($instance) || $instance === '') {
        return $instance;
    }
    $home = home_url('/');
    $map  = array(
        'http://cao.ylit.cc/'       => $home,
        'http://cao.ylit.cc'        => rtrim($home, '/'),
        'https://ripro.rizhuti.com' => rtrim($home, '/'),
    );
    foreach ($map as $from => $to) {
        $instance = str_replace($from, $to, $instance);
    }
    return $instance;
}

/**
 * @param array<string, array<string, mixed>> $widgets_by_id
 * @return list<string>
 */
function ripro_v5_demo_register_widgets(array $widgets_by_id) {
    $placed = array();
    foreach ($widgets_by_id as $widget_id => $instance) {
        if (!preg_match('/^(.+)-(\d+)$/', (string) $widget_id, $m)) {
            continue;
        }
        $id_base     = $m[1];
        $option_name = 'widget_' . $id_base;
        $widget_opts = get_option($option_name, array());
        if (!is_array($widget_opts)) {
            $widget_opts = array();
        }
        $next = 0;
        foreach ($widget_opts as $k => $v) {
            if (is_numeric($k)) {
                $next = max($next, (int) $k);
            }
        }
        $next++;
        $widget_opts[$next]        = ripro_v5_demo_rewrite_urls_in_value($instance);
        $widget_opts['_multiwidget'] = 1;
        update_option($option_name, $widget_opts);
        $placed[] = $id_base . '-' . $next;
    }
    return $placed;
}

/**
 * 官方演示首页模块顺序（流光搜索 + 网站动态 + 分类盒 + 其余模块）
 */
function ripro_v5_apply_demo_home_widgets() {
    $raw = wp_remote_get(RIPRO_DEMO_WIDGETS_URL, array('timeout' => 60));
    if (is_wp_error($raw)) {
        return $raw;
    }
    $body = wp_remote_retrieve_body($raw);
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data[RIPRO_DEMO_SIDEBAR_SRC]) || !is_array($data[RIPRO_DEMO_SIDEBAR_SRC])) {
        return new WP_Error('demo_widgets', '无法解析演示小工具 JSON');
    }
    $src = $data[RIPRO_DEMO_SIDEBAR_SRC];

    $pick = static function (array $src, string $key) {
        return isset($src[$key]) && is_array($src[$key]) ? $src[$key] : null;
    };

    $ordered = array();
    if ($search = $pick($src, 'ri_home_search_widget-5')) {
        $ordered['ri_home_search_widget-5'] = $search;
    }
    $ordered['ri_home_dynamic_widget-demo'] = array(
        'title' => '网站动态',
    );
    if ($catbox = $pick($src, 'ri_home_catbox_widget-8')) {
        $ordered['ri_home_catbox_widget-8'] = $catbox;
    }
    if ($division = $pick($src, 'ri_home_division_widget-3')) {
        $ordered['ri_home_division_widget-3'] = $division;
    }
    if ($last = $pick($src, 'ri_home_lastpost_widget-6')) {
        $ordered['ri_home_lastpost_widget-6'] = $last;
    } elseif ($pick($src, 'ri_home_cmspost_widget-9')) {
        $ordered['ri_home_cmspost_widget-9'] = $src['ri_home_cmspost_widget-9'];
    }

    $to_register = array();
    foreach ($ordered as $id => $instance) {
        if ($id === 'ri_home_dynamic_widget-demo') {
            $to_register['ri_home_dynamic_widget-1'] = $instance;
            continue;
        }
        $to_register[$id] = $instance;
    }

    $placed = ripro_v5_demo_register_widgets($to_register);

    $sidebars_widgets = get_option('sidebars_widgets', array());
    if (!is_array($sidebars_widgets)) {
        $sidebars_widgets = array();
    }
    $sidebars_widgets['home-center'] = $placed;
    update_option('sidebars_widgets', $sidebars_widgets);

    return $placed;
}

/**
 * @return true|WP_Error
 */
function ripro_v5_apply_demo_theme_options() {
    $raw = wp_remote_get(RIPRO_DEMO_OPTIONS_URL, array('timeout' => 60));
    if (is_wp_error($raw)) {
        return $raw;
    }
    $demo = json_decode(wp_remote_retrieve_body($raw), true);
    if (!is_array($demo)) {
        return new WP_Error('demo_options', '无法解析演示主题选项 JSON');
    }

    if (!defined('_OPTIONS_PRE')) {
        return new WP_Error('options_pre', '_OPTIONS_PRE 未定义');
    }

    $opts = get_option(_OPTIONS_PRE, array());
    if (!is_array($opts)) {
        $opts = array();
    }

    $keys = array(
        'assets_cdn_mod',
        'is_site_home_header_transparent',
        'is_site_pro_search_title',
        'is_site_pro_search',
        'pro_search_select_depth',
        'pro_search_select_order',
        'site_container_width',
        'is_post_one_thumbnail',
        'site_thumb_size_type',
        'site_thumb_fit_type',
        'remove_site_search',
    );
    foreach ($keys as $key) {
        if (array_key_exists($key, $demo)) {
            $opts[$key] = $demo[$key];
        }
    }

    $home = home_url('/');
    $rollbar = array(
        array('title' => '首页', 'icon' => 'fas fa-home', 'href' => $home, 'is_blank' => ''),
        array('title' => '用户中心', 'icon' => 'far fa-user', 'href' => home_url('/user'), 'is_blank' => ''),
        array('title' => '会员介绍', 'icon' => 'fas fa-gem', 'href' => home_url('/vip-prices'), 'is_blank' => ''),
        array('title' => 'QQ客服', 'icon' => 'fab fa-qq', 'href' => 'http://wpa.qq.com/msgrd?v=3&uin=6666666&site=qq&menu=yes', 'is_blank' => ''),
        array('title' => '购买主题', 'icon' => 'fab fa-shopware', 'href' => 'https://ritheme.com/', 'is_blank' => '1'),
    );
    if (function_exists('ripro_v5_filter_rollbar_items')) {
        $rollbar = ripro_v5_filter_rollbar_items($rollbar);
    }
    $opts['site_footer_rollbar'] = $rollbar;

    if (!empty($demo['site_footer_widget_link1']) && is_array($demo['site_footer_widget_link1'])) {
        $opts['site_footer_widget_link1'] = ripro_v5_demo_rewrite_urls_in_value($demo['site_footer_widget_link1']);
    }
    if (!empty($demo['site_footer_widget_link2']) && is_array($demo['site_footer_widget_link2'])) {
        $opts['site_footer_widget_link2'] = ripro_v5_demo_rewrite_urls_in_value($demo['site_footer_widget_link2']);
    }

    update_option(_OPTIONS_PRE, $opts);
    delete_transient('main-menu-cache');

    return true;
}

function ripro_v5_apply_demo_reference() {
    $widgets = ripro_v5_apply_demo_home_widgets();
    if (is_wp_error($widgets)) {
        return $widgets;
    }
    $options = ripro_v5_apply_demo_theme_options();
    if (is_wp_error($options)) {
        return $options;
    }
    return array(
        'widgets' => $widgets,
        'options' => 'ok',
    );
}

if (php_sapi_name() === 'cli' && isset($GLOBALS['argv'][0]) && basename($GLOBALS['argv'][0]) === 'apply-demo-reference.php') {
    $root = dirname(__DIR__, 5);
    if (!is_file($root . '/wp-load.php')) {
        fwrite(STDERR, "wp-load.php not found at {$root}\n");
        exit(1);
    }
    require $root . '/wp-load.php';
    $result = ripro_v5_apply_demo_reference();
    if (is_wp_error($result)) {
        fwrite(STDERR, $result->get_error_message() . "\n");
        exit(1);
    }
    echo "DEMO_REFERENCE_OK\n";
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

if (!defined('ABSPATH')) {
    return;
}
