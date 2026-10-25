<?php

defined('WPINC') || exit;

if (!_cao('is_sns_qq')) {
    zb_wp_die('未开启QQ登录功能', '非法访问');exit;
}

$opt = (array) _cao('sns_qq', array());

if (empty($opt['app_id']) || empty($opt['app_secret'])) {
    zb_wp_die('请配置QQ登录参数', '配置错误');exit;
}

$Config = array(
    'app_id'     => trim((string) $opt['app_id']),
    'app_secret' => trim((string) $opt['app_secret']),
    'scope'      => 'get_user_info',
    'callback'   => get_oauth_permalink('qq','callback'),
);

$OAuth = new \Yurun\OAuthLogin\QQ\OAuth2($Config['app_id'], $Config['app_secret'], $Config['callback']);
$OAuth->state = 'state';
$url   = $OAuth->getAuthUrl();

ob_start();
header('location:' . $url);
exit;