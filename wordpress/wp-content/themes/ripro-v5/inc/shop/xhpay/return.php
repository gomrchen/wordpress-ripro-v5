<?php

/**
 * 讯虎支付同步通知 同步查询模式
 */

header('Content-type:text/html; Charset=utf-8');

if (empty($_GET['pay_type']) || empty($_GET['out_trade_no'])) {
    wp_redirect(home_url());exit;
}

$pay_type = isset($_GET['pay_type']) ? $_GET['pay_type'] : '';
if ($pay_type === 'alipay') {
    $_Config = _cao('xunhupay_alipay');
} elseif ($pay_type === 'wechat') {
    $_Config = _cao('xunhupay_weixin');
} else {
    wp_redirect(home_url());
    exit;
}

if (empty($_Config['mchid']) || empty($_Config['private_key'])) {
    exit('error');
}

require_once get_template_directory() . '/inc/plugins/xunhupay/xhpay.class.php';

$xhpay = new Xhpay($_Config);

$data   = $xhpay->sign(array('out_trade_no' => $_GET['out_trade_no']));
$result = array();

try {
    $result = $xhpay->query($data);
} catch (Exception $e) {
    // 查询失败仍按订单号回跳
}

if (isset($result['return_code']) && isset($result['status']) && isset($result['sign'])) {

    if ($xhpay->checkSign($result) === true && $result['return_code'] == 'SUCCESS' && $result['status'] == 'complete') {

        //商户本地订单号
        $out_trade_no = $result['out_trade_no'];
        //讯虎平台交易号
        $trade_no = $result['order_id'];
        //发送支付成功回调
        ZB_Shop::pay_notfiy_callback($out_trade_no, $trade_no);

    }

}

$out_trade_no = '';
if (!empty($result['out_trade_no'])) {
    $out_trade_no = $result['out_trade_no'];
} elseif (!empty($_GET['out_trade_no'])) {
    $out_trade_no = wp_unslash($_GET['out_trade_no']);
}

$order = ($out_trade_no !== '') ? ZB_Shop::get_order($out_trade_no) : null;

wp_redirect(ZB_Shop::order_success_redirect_url($order));
exit;