<?php
/**
 * 按 ripro.rizhuti.com 对齐：首页模块、顶栏菜单、站点信息、关闭 ritheme 广告位
 *
 *   docker compose exec -T wordpress php wp-content/themes/ripro-v5/inc/tools/align-official-site.php
 */
if (php_sapi_name() !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 5);
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/nav-menu.php';
require_once get_template_directory() . '/inc/tools/apply-demo-reference.php';

@set_time_limit(0);

/**
 * @param array<string, array<string, mixed>> $widgets_by_id
 */
function ripro_v5_official_set_home_widgets(array $widgets_by_id) {
    $placed = ripro_v5_demo_register_widgets($widgets_by_id);
    $sidebars_widgets = get_option('sidebars_widgets', array());
    if (!is_array($sidebars_widgets)) {
        $sidebars_widgets = array();
    }
    $sidebars_widgets['home-center'] = $placed;
    update_option('sidebars_widgets', $sidebars_widgets);
    return $placed;
}

function ripro_v5_official_home_widgets_from_demo() {
    $raw = wp_remote_get(RIPRO_DEMO_WIDGETS_URL, array('timeout' => 60));
    if (is_wp_error($raw)) {
        return $raw;
    }
    $data = json_decode(wp_remote_retrieve_body($raw), true);
    if (!is_array($data) || empty($data[RIPRO_DEMO_SIDEBAR_SRC])) {
        return new WP_Error('widgets', 'demo widgets missing sidebar');
    }
    $src = $data[RIPRO_DEMO_SIDEBAR_SRC];
    $pick = static function (array $src, string $key) {
        return (isset($src[$key]) && is_array($src[$key])) ? $src[$key] : null;
    };

    $ordered = array();
    if ($w = $pick($src, 'ri_home_search_widget-5')) {
        $ordered['ri_home_search_widget-5'] = $w;
    }
    $ordered['ri_home_dynamic_widget-1'] = array('title' => '网站动态');
    if ($w = $pick($src, 'ri_home_catbox_widget-8')) {
        $ordered['ri_home_catbox_widget-8'] = $w;
    }
    if ($w = $pick($src, 'ri_home_division_widget-3')) {
        $ordered['ri_home_division_widget-3'] = $w;
    }
    if ($w = $pick($src, 'ri_home_cmspost_widget-9')) {
        $ordered['ri_home_cmspost_widget-9'] = $w;
    }
    if ($w = $pick($src, 'ri_home_lastpost_widget-6')) {
        $ordered['ri_home_lastpost_widget-6'] = $w;
    }

    return ripro_v5_official_set_home_widgets($ordered);
}

function ripro_v5_official_menu_item($menu_id, $title, $url, $parent = 0, $position = 0) {
    return wp_update_nav_menu_item($menu_id, 0, array(
        'menu-item-title'     => $title,
        'menu-item-url'       => $url,
        'menu-item-status'    => 'publish',
        'menu-item-parent-id' => (int) $parent,
        'menu-item-position'  => (int) $position,
        'menu-item-type'      => 'custom',
    ));
}

function ripro_v5_official_rebuild_main_menu() {
    $menu_name = 'main';
    $menu      = wp_get_nav_menu_object($menu_name);
    if (!$menu) {
        $menu_id = wp_create_nav_menu($menu_name);
    } else {
        $menu_id = (int) $menu->term_id;
        $items   = wp_get_nav_menu_items($menu_id);
        if (is_array($items)) {
            foreach ($items as $item) {
                wp_delete_post((int) $item->ID, true);
            }
        }
    }

    $home = home_url('/');

    $home_id = ripro_v5_official_menu_item($menu_id, '首页', $home);
    foreach (array('home-1', 'home-2', 'home-3', 'home-4', 'home-5', 'home-6') as $slug) {
        $page = get_page_by_path($slug);
        if ($page) {
            ripro_v5_official_menu_item($menu_id, $page->post_title, get_permalink($page), $home_id);
        }
    }

    $article_id = ripro_v5_official_menu_item($menu_id, '文章展示', '#');
    $samples    = get_posts(array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 6,
        'orderby'        => 'date',
    ));
    foreach ($samples as $post) {
        ripro_v5_official_menu_item($menu_id, $post->post_title, get_permalink($post), $article_id);
    }

    $wp_cat  = get_category_by_slug('wordpress') ?: get_category_by_slug('wordpress教程');
    $list_url = $wp_cat ? get_category_link($wp_cat->term_id) : home_url('/');
    $list_id = ripro_v5_official_menu_item($menu_id, '列表展示', $list_url);
    $cats    = get_categories(array('hide_empty' => true, 'number' => 8, 'orderby' => 'count', 'order' => 'DESC'));
    foreach ($cats as $cat) {
        ripro_v5_official_menu_item($menu_id, $cat->name, get_category_link($cat->term_id), $list_id);
    }

    $series_id = ripro_v5_official_menu_item($menu_id, '专题', home_url('/series/'));
    if (taxonomy_exists('series')) {
        $terms = get_terms(array('taxonomy' => 'series', 'hide_empty' => false, 'number' => 5));
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                ripro_v5_official_menu_item($menu_id, $term->name, get_term_link($term), $series_id);
            }
        }
    }

    ripro_v5_official_menu_item($menu_id, 'VIP介绍', home_url('/vip-prices'));
    ripro_v5_official_menu_item($menu_id, '获取本站主题', 'https://ritheme.com/theme/ripro-v5.html');

    set_theme_mod('nav_menu_locations', array('main-menu' => $menu_id));
    delete_transient('main-menu-cache');

    return $menu_id;
}

function ripro_v5_official_theme_options() {
    if (!defined('_OPTIONS_PRE')) {
        return new WP_Error('options', 'no _OPTIONS_PRE');
    }
    $raw  = wp_remote_get(RIPRO_DEMO_OPTIONS_URL, array('timeout' => 60));
    $demo = json_decode(wp_remote_retrieve_body($raw), true);
    if (!is_array($demo)) {
        return new WP_Error('options', 'demo options json');
    }

    $home = home_url('/');
    array_walk_recursive($demo, function (&$value) use ($home) {
        if (!is_string($value)) {
            return;
        }
        $value = str_replace(
            array('http://cao.ylit.cc/', 'http://cao.ylit.cc', 'https://ripro.rizhuti.com'),
            array($home, rtrim($home, '/'), rtrim($home, '/')),
            $value
        );
    });

    update_option(_OPTIONS_PRE, $demo);

    $opts = get_option(_OPTIONS_PRE, array());
    $ad_slugs = array('ad_archive_top', 'ad_archive_bottum', 'ad_single_top', 'ad_single_bottum');
    foreach ($ad_slugs as $slug) {
        $opts[$slug] = '';
        foreach (array('_pc', '_mobile') as $suffix) {
            $key = $slug . $suffix;
            if (!empty($opts[$key]) && function_exists('ripro_v5_filter_promo_html')) {
                $opts[$key] = ripro_v5_filter_promo_html((string) $opts[$key]);
            }
        }
    }
    update_option(_OPTIONS_PRE, $opts);
    update_option('ripro_v5_official_demo_ui', 1);

    return true;
}

function ripro_v5_official_site_identity() {
    update_option('blogname', 'RiPro主题_资源站首选优质内容变现主题实践');
    update_option('blogdescription', 'RiPro是Ritheme全新开发甄品VIP会员资源/素材虚拟商城主题');
}

function ripro_v5_align_official_site() {
    $widgets = ripro_v5_official_home_widgets_from_demo();
    if (is_wp_error($widgets)) {
        return $widgets;
    }
    $menu = ripro_v5_official_rebuild_main_menu();
    $opts = ripro_v5_official_theme_options();
    if (is_wp_error($opts)) {
        return $opts;
    }
    ripro_v5_official_site_identity();
    flush_rewrite_rules(false);

    return array(
        'home_widgets' => $widgets,
        'menu_id'      => $menu,
        'options'      => 'ok',
    );
}

$result = ripro_v5_align_official_site();
if (is_wp_error($result)) {
    fwrite(STDERR, $result->get_error_message() . "\n");
    exit(1);
}
echo "OFFICIAL_ALIGN_OK\n";
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
