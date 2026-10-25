<?php
defined('ABSPATH') || exit;
global $wpdb;
$trade = 'PROBE' . time();
$wpdb->insert(
    $wpdb->cao_order_tbl,
    array(
        'post_id'         => 1,
        'user_id'         => 1,
        'order_type'      => 2,
        'order_trade_no'  => $trade,
        'order_price'     => 9.99,
        'create_time'     => time(),
        'pay_type'        => 1,
        'pay_status'      => 0,
    )
);
add_filter(
    'query',
    static function ($q) {
        if (stripos($q, 'cao_order') !== false) {
            fwrite(STDERR, "Q: {$q}\n");
        }
        return $q;
    }
);
$ok = ZB_Shop::pay_notfiy_callback($trade, 'PAY-' . time());
fwrite(STDERR, 'CALLBACK: ' . var_export($ok, true) . "\n");
$row = ZB_Shop::get_order($trade);
fwrite(STDERR, 'AFTER: pay_status=' . $row->pay_status . ' pay_trade_no=' . $row->pay_trade_no . "\n");
