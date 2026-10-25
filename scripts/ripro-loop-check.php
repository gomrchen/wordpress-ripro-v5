<?php
/**
 * 联调循环检查（docker/scripts，非主题运行时）
 *   docker compose exec -T wordpress php scripts/ripro-loop-check.php
 */
if (php_sapi_name() !== 'cli') {
    exit(1);
}

$root = __DIR__ . '/..';
require $root . '/wp-load.php';

$out = fopen('php://stderr', 'w');
$fail = 0;
$line = static function ($n, $ok, $d = '') use (&$fail, $out) {
    if (!$ok) {
        $fail++;
    }
    fwrite($out, ($ok ? 'OK' : 'FAIL') . "  {$n}" . ($d !== '' ? " — {$d}" : '') . "\n");
};

$theme = get_template_directory();
$host  = wp_parse_url(home_url(), PHP_URL_HOST);
$port  = wp_parse_url(home_url(), PHP_URL_PORT);
$hh    = ($host && $port) ? "{$host}:{$port}" : ($host ? $host : 'localhost');

$get = static function ($path) use ($host, $hh) {
    $path = ltrim($path, '/');
    $url  = in_array($host, array('127.0.0.1', 'localhost'), true)
        ? 'http://nginx/' . $path
        : home_url('/' . $path);
    return wp_remote_get(
        $url,
        array(
            'timeout'     => 20,
            'redirection' => 0,
            'headers'     => array('Host' => $hh),
        )
    );
};

$code_body = static function ($path) use ($get) {
    $r = $get($path);
    if (is_wp_error($r)) {
        return array(0, $r->get_error_message(), '');
    }
    return array(
        (int) wp_remote_retrieve_response_code($r),
        '',
        (string) wp_remote_retrieve_body($r),
    );
};

fwrite($out, "=== BOOT ===\n");
$line('ZB_Shop', class_exists('ZB_Shop'));
$line('ZB_Pay', class_exists('ZB_Pay'));
$line('no ZB_Lic', !class_exists('ZB_Lic'));
$line('no smoke leftovers', !is_file($theme . '/inc/tools/smoke-core.php') && !is_file($theme . '/inc/core/_probe_shop.php'));

fwrite($out, "=== OPTIONS ===\n");
$wx = function_exists('ripro_v5_get_sns_weixin') ? ripro_v5_get_sns_weixin() : array();
$line('sns_weixin_mod', !empty($wx['sns_weixin_mod']), isset($wx['sns_weixin_mod']) ? $wx['sns_weixin_mod'] : '');
$line('epay array', is_array(_cao('epay')));
$opts = get_option(_OPTIONS_PRE, array());
$line('options', is_array($opts) && count($opts) > 50, 'keys=' . count((array) $opts));
$line('official_demo_ui', (bool) get_option('ripro_v5_official_demo_ui'));
$line('shop open', function_exists('is_site_shop') && is_site_shop());

fwrite($out, "=== HTTP ===\n");
$pages = array(
    ''             => 200,
    'login/'       => 200,
    'register/'    => 200,
    'vip-prices/'  => 200,
    'tags/'        => 200,
    'user/'        => 302,
);
foreach ($pages as $p => $ex) {
    list($c, $e, $b) = $code_body($p);
    $warn = (bool) preg_match('/Undefined array key|Warning:|Fatal error:/i', $b);
    $line('HTTP ' . ($p === '' ? '/' : $p), $c === (int) $ex, ($e !== '' ? $e : "code={$c}") . ($warn ? ' WARN' : ''));
    if ($p === 'login/') {
        $line('login no warn', !$warn);
    }
}

list($c, $e, $b) = $code_body('');
$line('home FA faces', strpos($b, 'ripro-fontawesome-faces') !== false);
$line('home rollbar', strpos($b, 'fas fa-home') !== false);
$line('home division', strpos($b, 'division-icon') !== false);

fwrite($out, "=== PAY / ORDER ===\n");
list($c, $e, $b) = $code_body('pay/callback/alipay/notify/');
$line('alipay notify', $c === 200 && trim($b) === 'error', "code={$c} body=" . trim($b));
foreach (array('alipay/return', 'weixinpay/return', 'paypal/cancel') as $r) {
    list($c) = $code_body('pay/callback/' . $r . '/');
    $line('pay ' . $r, in_array($c, array(301, 302), true), 'code=' . $c);
}

global $wpdb;
$trade = 'LOOP' . time();
$wpdb->insert(
    $wpdb->cao_order_tbl,
    array(
        'post_id'        => 1,
        'user_id'        => 1,
        'order_type'     => 1,
        'order_trade_no' => $trade,
        'order_price'    => 1,
        'create_time'    => time(),
        'pay_type'       => 1,
        'pay_status'     => 0,
    )
);
$ok  = ZB_Shop::pay_notfiy_callback($trade, 'P' . time());
$row = ZB_Shop::get_order($trade);
$line('order callback', $ok && $row && (int) $row->pay_status === 1);
$wpdb->delete($wpdb->cao_order_tbl, array('order_trade_no' => $trade));

fwrite($out, "=== ALIGN ===\n");
$sw = get_option('sidebars_widgets', array());
$hw = isset($sw['home-center']) ? count((array) $sw['home-center']) : 0;
$line('home-center', $hw >= 4, "count={$hw}");
$menu = wp_get_nav_menu_object('main');
$mi   = $menu ? wp_get_nav_menu_items($menu->term_id) : array();
$line('nav main', is_array($mi) && count($mi) > 0, 'items=' . count((array) $mi));
$roll = apply_filters(
    'ripro_footer_rollbar_items',
    array(array('title' => '购买主题', 'href' => 'https://ritheme.com/', 'icon' => 'fab fa-shopware'))
);
$line(
    'rollbar ritheme',
    !empty($roll[0]['href']) && stripos($roll[0]['href'], 'ritheme.com') !== false
);

list($c) = $code_body('wp-content/themes/ripro-v5/assets/css/font-awesome/webfonts/fa-solid-900.woff2');
$line('fa woff2', $c === 200, 'code=' . $c);

fwrite($out, $fail === 0 ? "LOOP_PASS\n" : "LOOP_FAIL count={$fail}\n");
exit($fail === 0 ? 0 : 1);
