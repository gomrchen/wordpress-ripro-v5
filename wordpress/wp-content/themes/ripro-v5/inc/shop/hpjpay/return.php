<?php

/**
 * 讯虎支付同步通知 同步查询模式
 */

header('Content-type:text/html; Charset=utf-8');


if (empty($_GET)) {
    wp_redirect(home_url());exit;
}

$back_url = home_url();

//商户本地订单号
$out_trade_no = isset($_GET['out_trade_no']) ? wp_unslash($_GET['out_trade_no']) : '';

if ($out_trade_no !== '') {
    $order = ZB_Shop::get_order($out_trade_no);
} else {
    $order = null;
}

if ($order) {

    if ($order->order_type == 1) {
        $back_url = get_permalink($order->post_id);
    } elseif ($order->order_type == 2) {
        $back_url = get_uc_menu_link('coin');
    } elseif ($order->order_type == 3) {
        $back_url = get_uc_menu_link('vip');
    } else {
        $back_url = home_url();
    }

}

wp_redirect($back_url);exit;
