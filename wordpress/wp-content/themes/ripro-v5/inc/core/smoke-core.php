<?php
/**
 * 核心模块联调冒烟（CLI：wp-load + require 本文件）
 */
defined('ABSPATH') || exit;

$fail = 0;

function smoke_line($name, $ok, $detail = '') {
    global $fail;
    if (!$ok) {
        $fail++;
    }
    fwrite(STDERR, ($ok ? 'OK' : 'FAIL') . "  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n");
}

/** 容器内 siteurl 常为 127.0.0.1:8080，改走 compose 内网 nginx */
function smoke_route_url($path) {
    $path = ltrim($path, '/');
    $home = home_url('/' . $path);
    $host = wp_parse_url($home, PHP_URL_HOST);
    if (in_array($host, array('127.0.0.1', 'localhost'), true)) {
        return 'http://nginx/' . $path;
    }
    return $home;
}

function smoke_route_host_header() {
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    $port = wp_parse_url(home_url(), PHP_URL_PORT);
    if ($host && $port) {
        return $host . ':' . $port;
    }
    return $host ? $host : 'localhost';
}

function smoke_route_get($path, $extra_args = array()) {
    $args = array_merge(
        array(
            'timeout'     => 15,
            'redirection' => 0,
            'headers'     => array('Host' => smoke_route_host_header()),
        ),
        $extra_args
    );
    return wp_remote_get(smoke_route_url($path), $args);
}

smoke_line('class ZB_Shop', class_exists('ZB_Shop'));
smoke_line('class ZB_Pay', class_exists('ZB_Pay'));
smoke_line('no ZB_Lic (open source)', !class_exists('ZB_Lic'));

$pay_methods = array(
    'alipay_app_web_pay',
    'alipay_app_wap_pay',
    'alipay_app_qr_pay',
    'weixin_qr_pay',
    'weixin_h5_pay',
    'weixin_jsapi_pay',
    'hupijiao_pay',
    'new_xunhu_pay',
    'epay_pay',
    'paypal_pay',
);
try {
    $pay = new ZB_Pay();
    smoke_line('ZB_Pay construct', is_object($pay));
    foreach ($pay_methods as $m) {
        smoke_line("ZB_Pay::{$m}", method_exists($pay, $m));
    }
} catch (Throwable $e) {
    smoke_line('ZB_Pay construct', false, $e->getMessage());
}

global $wpdb;
if (!empty($wpdb->cao_order_tbl)) {
    $trade = 'SMOKE' . time();
    $wpdb->insert(
        $wpdb->cao_order_tbl,
        array(
            'post_id'        => 1,
            'user_id'        => 1,
            'order_type'     => 2,
            'order_trade_no' => $trade,
            'order_price'    => 1,
            'create_time'    => time(),
            'pay_type'       => 1,
            'pay_status'     => 0,
        )
    );
    $cb = ZB_Shop::pay_notfiy_callback($trade, 'SMOKE-PAY-' . time());
    $row = ZB_Shop::get_order($trade);
    smoke_line('ZB_Shop pay_notfiy_callback', $cb === true && $row && (int) $row->pay_status === 1);
    $wpdb->delete($wpdb->cao_order_tbl, array('order_trade_no' => $trade));
} else {
    smoke_line('cao_order_tbl', false, 'table missing');
}

$shop_dir = get_template_directory() . '/inc/shop';
$callback_routes = array(
    'alipay/notify',
    'alipay/return',
    'weixinpay/notify',
    'weixinpay/return',
    'hpjpay/notify_wx',
    'hpjpay/notify_ali',
    'hpjpay/return',
    'xhpay/notify_wx',
    'xhpay/notify_ali',
    'xhpay/return',
    'epay/notify',
    'epay/return',
    'paypal/return',
    'paypal/cancel',
);
$missing_cb = array();
foreach ($callback_routes as $route) {
    $file = $shop_dir . '/' . str_replace('/', DIRECTORY_SEPARATOR, $route) . '.php';
    if (!is_file($file)) {
        $missing_cb[] = $route;
    }
}
smoke_line('pay callback scripts', empty($missing_cb), empty($missing_cb) ? count($callback_routes) . ' routes' : implode(', ', $missing_cb));

$response = smoke_route_get('pay/callback/alipay/notify/');
if (is_wp_error($response)) {
    smoke_line('HTTP alipay notify route', false, $response->get_error_message());
} else {
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = trim((string) wp_remote_retrieve_body($response));
    smoke_line('HTTP alipay notify route', $code === 200 && $body === 'error', "code={$code} body={$body}");
}

$response = smoke_route_get('pay/callback/paypal/cancel/');
if (is_wp_error($response)) {
    smoke_line('HTTP paypal cancel route', false, $response->get_error_message());
} else {
    $code = (int) wp_remote_retrieve_response_code($response);
    smoke_line('HTTP paypal cancel route', $code === 302 || $code === 301, 'code=' . $code);
}

$return_routes = array(
    'alipay/return'     => true,
    'weixinpay/return'  => true,
    'hpjpay/return'     => true,
    'epay/return'       => true,
    'xhpay/return'      => true,
);
foreach ($return_routes as $route => $_) {
    $response = smoke_route_get('pay/callback/' . $route . '/');
    if (is_wp_error($response)) {
        smoke_line("HTTP {$route}", false, $response->get_error_message());
        continue;
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    smoke_line("HTTP {$route}", $code === 302 || $code === 301, 'code=' . $code);
}

smoke_line('ZB_Shop::order_success_redirect_url', method_exists('ZB_Shop', 'order_success_redirect_url'));
$null_redirect = ZB_Shop::order_success_redirect_url(null);
smoke_line('order redirect default', is_string($null_redirect) && $null_redirect !== '');

$rollbar = apply_filters('ripro_footer_rollbar_items', array(
    array('title' => '购买主题', 'href' => 'https://ritheme.com/', 'icon' => 'fab fa-shopware'),
));
$rollbar_ok = !empty($rollbar[0]['href']) && strpos($rollbar[0]['href'], 'ritheme.com') === false;
smoke_line('open-source rollbar filter', $rollbar_ok, $rollbar_ok ? 'promo→ticket' : 'still ritheme');

$friends = apply_filters('ripro_footer_friend_links', array(
    array('title' => 'RiTheme主题官网', 'href' => 'https://ritheme.com/'),
));
$friend_ok = !empty($friends[0]['href']) && strpos($friends[0]['href'], 'ritheme.com') === false;
smoke_line('open-source friend links filter', $friend_ok);

smoke_line('homepage', true);
fwrite(STDERR, $fail === 0 ? "SMOKE_PASS\n" : "SMOKE_FAIL count={$fail}\n");
exit($fail === 0 ? 0 : 1);
