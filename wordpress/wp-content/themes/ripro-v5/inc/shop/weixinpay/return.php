<?php
/**
 * 微信官方同步通知
 */
header('Content-type:text/html; Charset=utf-8');

$out_trade_no = isset($_GET['out_trade_no']) ? wp_unslash($_GET['out_trade_no']) : '';
$order        = ($out_trade_no !== '') ? ZB_Shop::get_order($out_trade_no) : null;

wp_redirect(ZB_Shop::order_success_redirect_url($order));
exit;
