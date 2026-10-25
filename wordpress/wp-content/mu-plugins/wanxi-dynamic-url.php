<?php
/**
 * Plugin Name: Wanxi Dynamic URL (local/LAN)
 * Description: 按当前访问 Host 切换 siteurl/home，方便 localhost 与内网 IP 联调。
 */
if (!defined('ABSPATH')) {
    exit;
}

add_filter('option_home', 'wanxi_dynamic_home_url', 1);
add_filter('option_siteurl', 'wanxi_dynamic_home_url', 1);

function wanxi_request_base()
{
    if (empty($_SERVER['HTTP_HOST'])) {
        return '';
    }
    $host = $_SERVER['HTTP_HOST'];
    // 仅本机/内网联调：localhost、127.*、192.168.*、10.*、172.16-31.*
    if (!preg_match('/^(localhost|127\.|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/i', $host)) {
        return '';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme = $https ? 'https' : 'http';
    return $scheme . '://' . $host;
}

function wanxi_dynamic_home_url($url)
{
    $base = wanxi_request_base();
    return $base ? $base : $url;
}

/** 正文里写死的 localhost 链接，按当前 Host 替换，方便手机内网看图 */
add_filter('the_content', 'wanxi_rewrite_local_urls_in_html', 20);
add_filter('widget_text', 'wanxi_rewrite_local_urls_in_html', 20);

function wanxi_rewrite_local_urls_in_html($html)
{
    $base = wanxi_request_base();
    if (!$base || !is_string($html) || $html === '') {
        return $html;
    }
    return str_replace(
        array(
            'http://localhost:8080',
            'https://localhost:8080',
            'http://127.0.0.1:8080',
            'https://127.0.0.1:8080',
        ),
        $base,
        $html
    );
}
