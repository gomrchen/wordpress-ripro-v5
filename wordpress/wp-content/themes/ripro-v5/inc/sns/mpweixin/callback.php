<?php



defined('WPINC') || exit;

$opt = function_exists('ripro_v5_get_sns_weixin') ? ripro_v5_get_sns_weixin() : (array) _cao('sns_weixin', array());

if (empty($opt['mp_app_id']) || empty($opt['mp_app_secret']) || empty($opt['mp_app_token'])) {
    zb_wp_die('请配置微信公众号登录参数', '配置错误');exit;
}

//配置参数
$Config = array(
    'app_id'         => trim((string) $opt['mp_app_id']),
    'app_secret'     => trim((string) $opt['mp_app_secret']),
    'token'          => trim((string) $opt['mp_app_token']),
    'sns_weixin_mod' => trim((string) $opt['sns_weixin_mod']),
    // 'is_bind_open' => boolval($opt['is_mp_bind_open']),
    'callback'       => esc_url(home_url('/oauth/mpweixin/callback')),
);




if (is_weixin_visit()) {

    $OAuth = new \Yurun\OAuthLogin\Weixin\OAuth2($Config['app_id'], $Config['app_secret']);
    $OAuth->openidMode = Yurun\OAuthLogin\Weixin\OpenidMode::OPEN_ID;
    
    $accessToken = $OAuth->getAccessToken('state', null, 'state');
    $openid = $OAuth->openid;

    $snsInfo = $OAuth->getUserInfo();

    $sns_data = [
        'openid' => $openid,
        'name' => $snsInfo['nickname'],
        'avatar'  => $snsInfo['headimgurl'],
        'method' => 'mpweixin'
    ];
    

    $result = zb_oauth_callback_event($sns_data);

    if ($result['status'] == 1) {
        wp_safe_redirect(get_uc_menu_link());exit;
    } else {
        zb_wp_die($result['msg']);
    }

    exit;
}


//实例化公众号登录类
$mp_weixin = new Mp_WeChat_Login($Config['app_id'], $Config['app_secret'], $Config['token']);

$mp_weixin->handle_wechat_auth();


exit;
