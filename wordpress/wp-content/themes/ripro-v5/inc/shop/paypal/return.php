<?php
/**
 * PayPal successfull payment return
 */

header('Content-type:text/html; Charset=utf-8');

if (!_cao('is_paypal', false)) {
    exit('PayPal NOT');
}

require_once get_template_directory() . '/inc/plugins/paypal/paypal.php';
require_once get_template_directory() . '/inc/plugins/paypal/httprequest.php';

$opt    = _cao('paypal');
$config = array_merge(array(
    'username'  => 'aaa',
    'password'  => 'bbb',
    'signature' => 'ccc',
    'return'    => '',
    'cancel'    => '',
    'debug'     => false,
), $opt);

$r = new PayPal($config);

$final = $r->doPayment();

$content = var_export($final, true) . PHP_EOL . 'Details:' . var_export($r->getCheckoutDetails($final['TOKEN']), true);
file_put_contents(__DIR__ . '/notify_result.txt', $content);

//
if ($final['ACK'] == 'Success' && isset($final['TOKEN'])) {

    $pp_order = $r->getCheckoutDetails($final['TOKEN']);

    //商户本地订单号
    $out_trade_no = $pp_order['INVNUM'];
    //交易号
    $trade_no = $final['TRANSACTIONID'];

    //发送支付成功回调
    ZB_Shop::pay_notfiy_callback($out_trade_no, $trade_no);

    $order = ZB_Shop::get_order(wp_unslash($pp_order['INVNUM']));
    wp_redirect(ZB_Shop::order_success_redirect_url($order));
    exit;

}

wp_redirect(home_url());
exit;
