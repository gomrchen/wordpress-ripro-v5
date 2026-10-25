<?php
/**
 * 订单表 cao_order_tbl 读写（与 template-ajax / template-shop 契约一致）
 */
defined('ABSPATH') || exit;

class ZB_Shop {

    public static function get_pay_status($status) {
        return zb_get_order_tbl_status($status);
    }

    public static function get_pay_type($type_id) {
        $opt = zb_get_pay_optons($type_id);
        if (is_array($opt) && isset($opt['name'])) {
            return $opt['name'];
        }
        return __('无', 'ripro');
    }

    public static function get_order_type($type) {
        return zb_get_order_tbl_type($type);
    }

    public static function get_order($order_trade_no) {
        global $wpdb;
        if (empty($order_trade_no)) {
            return null;
        }
        $sql = "SELECT * FROM {$wpdb->cao_order_tbl} WHERE order_trade_no = %s";
        return $wpdb->get_row($wpdb->prepare($sql, $order_trade_no), OBJECT);
    }

    /**
     * @param array $param 与 template-ajax 下单字段一致
     */
    public static function add_order($param = array()) {
        global $wpdb;

        $defaults = array(
            'post_id'         => 0,
            'user_id'         => 0,
            'order_type'      => 0,
            'order_trade_no'  => wp_date('ymdhis') . mt_rand(100, 999) . mt_rand(100, 999) . mt_rand(100, 999),
            'order_price'     => 0,
            'create_time'     => time(),
            'pay_type'        => 0,
            'pay_time'        => 0,
            'pay_price'       => 0,
            'pay_trade_no'    => '',
            'order_info'      => '',
            'pay_status'      => 0,
        );

        $row = array();
        foreach ($defaults as $key => $default) {
            $row[$key] = array_key_exists($key, $param) ? $param[$key] : $default;
        }

        $formats = array('%d', '%d', '%d', '%s', '%f', '%d', '%d', '%d', '%f', '%s', '%s', '%d');
        return $wpdb->insert($wpdb->cao_order_tbl, $row, $formats);
    }

    public static function update_order($data = array(), $where = array(), $pre_data = array(), $pre_where = array()) {
        global $wpdb;
        return $wpdb->update($wpdb->cao_order_tbl, $data, $where, $pre_data, $pre_where);
    }

    public static function delete_order($where = array()) {
        global $wpdb;
        return $wpdb->delete($wpdb->cao_order_tbl, $where);
    }

    /**
     * 文章是否已购：0 未购 / 1 已购（含 cookie 免登录与有效期）
     */
    public static function get_pay_post_status($user_id, $post_id) {
        global $wpdb;

        if (empty($post_id)) {
            return 0;
        }

        $order = null;

        if (empty($user_id)) {
            $cookie_key = 'post_payed_' . $post_id;
            $enc        = ZB_Cookie::get($cookie_key);
            if ($enc) {
                $trade_no = ZB_Code::destr($enc);
                if ($trade_no) {
                    $sql = "SELECT * FROM {$wpdb->cao_order_tbl} WHERE order_trade_no = %s AND post_id = %d AND pay_status = 1 ORDER BY create_time DESC";
                    $order = $wpdb->get_row($wpdb->prepare($sql, $trade_no, $post_id), OBJECT);
                }
            }
        } else {
            $sql = "SELECT * FROM {$wpdb->cao_order_tbl} WHERE user_id = %d AND post_id = %d AND pay_status = 1 ORDER BY create_time DESC";
            $order = $wpdb->get_row($wpdb->prepare($sql, $user_id, $post_id), OBJECT);
        }

        if (empty($order) || (int) $order->order_type !== 1 || (int) $order->pay_status !== 1) {
            return 0;
        }

        $expire_days = (int) _cao('site_post_pay_expire_day', 0);
        if ($expire_days > 0) {
            $deadline = strtotime('+' . $expire_days . ' days', (int) $order->create_time);
            if (time() > $deadline) {
                return 0;
            }
        }

        return 1;
    }

    /** 支付回调统一入口（各 shop 渠道 notify 脚本调用） */
    public static function pay_notfiy_callback($order_trade_no, $pay_trade_no) {
        global $wpdb;

        if (empty($order_trade_no) || empty($pay_trade_no) || empty($wpdb->cao_order_tbl)) {
            return false;
        }

        $order = self::get_order($order_trade_no);
        if (!$order || (int) $order->pay_status !== 0) {
            return false;
        }

        $updated = self::update_order(
            array(
                'pay_status'   => 1,
                'pay_trade_no' => $pay_trade_no,
                'pay_time'     => time(),
            ),
            array('order_trade_no' => $order_trade_no),
            array('%d', '%s', '%d'),
            array('%s')
        );

        if (!$updated) {
            return false;
        }

        $order = self::get_order($order_trade_no);
        do_action('site_pay_order_success', $order);

        return true;
    }

    /** 支付完成同步回跳地址（与各渠道 return 脚本一致） */
    public static function order_success_redirect_url($order) {
        if (empty($order)) {
            return home_url('/');
        }
        $type = (int) $order->order_type;
        if ($type === 1) {
            return get_permalink($order->post_id);
        }
        if ($type === 2) {
            return get_uc_menu_link('coin');
        }
        if ($type === 3) {
            return get_uc_menu_link('vip');
        }
        return home_url('/');
    }
}
