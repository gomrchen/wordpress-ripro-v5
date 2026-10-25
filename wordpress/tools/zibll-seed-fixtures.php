<?php
/**
 * 联调夹具：subscriber + shop_product + bbs plate/post（可重复执行）
 * docker exec wp-app php /var/www/html/tools/zibll-seed-fixtures.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/wp-load.php';

$opts = get_option('zibll_options');
$opts = is_array($opts) ? $opts : array();
$opts['shop_s']        = true;
$opts['pay_balance_s'] = true;
$opts['bbs_s']         = true;
update_option('zibll_options', $opts);
if (function_exists('_spz')) {
    _spz('shop_s', true);
    _spz('pay_balance_s', true);
    _spz('bbs_s', true);
}
echo "[OK] shop_s + pay_balance_s + bbs_s = true\n";

$login = 'zibll_probe_user';
$pass  = 'ProbeUser!2026';
$email = 'zibll_probe@example.local';

$user = get_user_by('login', $login);
if (!$user) {
    $uid = wp_create_user($login, $pass, $email);
    if (is_wp_error($uid)) {
        fwrite(STDERR, '创建用户失败: ' . $uid->get_error_message() . "\n");
        exit(1);
    }
    $user = new WP_User($uid);
    $user->set_role('subscriber');
    echo "[OK] 创建用户 #{$uid} {$login}\n";
} else {
    wp_set_password($pass, $user->ID);
    echo "[OK] 用户已存在 #{$user->ID} {$login}\n";
}

update_user_meta($user->ID, 'balance', 100);
echo "[OK] 用户余额 balance=100\n";

$product_config = array(
    'guest_buy'              => false,
    'pay_modo'               => '0',
    'start_price'            => 9.9,
    'shipping_type'          => 'auto',
    'shipping_delivery_desc' => '联调自动发货',
    'stock_type'             => 'all',
    'stock_all'              => 99,
    'email_fill'             => 'off',
    'desc'                   => '联调探测商品',
    'product_options'        => array(),
    'params'                 => array(),
    'comment_s'              => true,
    'auto_delivery'          => array(
        'type' => 'fixed',
        'val'  => 'PROBE-AUTO-CODE',
    ),
);

$marker = 'zibll_probe_product';
$existing = get_posts(array(
    'post_type'      => 'shop_product',
    'post_status'    => 'any',
    'posts_per_page' => 1,
    'meta_key'       => '_zibll_probe',
    'meta_value'     => $marker,
));

if ($existing) {
    $pid = (int) $existing[0]->ID;
    wp_update_post(array(
        'ID'          => $pid,
        'post_status' => 'publish',
        'post_title'  => '联调探测商品',
    ));
    echo "[OK] 商品已存在 #{$pid}，更新 product_config\n";
} else {
    $pid = wp_insert_post(array(
        'post_type'    => 'shop_product',
        'post_status'  => 'publish',
        'post_title'   => '联调探测商品',
        'post_content' => 'zibll integration probe product',
        'post_author'  => 1,
    ), true);
    if (is_wp_error($pid)) {
        fwrite(STDERR, '创建商品失败: ' . $pid->get_error_message() . "\n");
        exit(1);
    }
    update_post_meta($pid, '_zibll_probe', $marker);
    echo "[OK] 创建商品 #{$pid}\n";
}

update_post_meta($pid, 'product_config', $product_config);
update_post_meta($pid, 'zibpay_modo', '0');
update_post_meta($pid, 'zibpay_price', 9.9);

$confirm = function_exists('zib_shop_get_confirm_data')
    ? zib_shop_get_confirm_data(array($pid => array('0' => 1)))
    : array();
$pay_price = 9.9;
if (is_array($confirm) && !empty($confirm['total_data']['pay_price'])) {
    $pay_price = (float) $confirm['total_data']['pay_price'];
}

// --- 论坛夹具 ---
$plate_id = 0;
$post_id  = 0;
$plates = get_posts(array(
    'post_type'      => 'plate',
    'post_status'    => 'any',
    'posts_per_page' => 1,
    'meta_key'       => '_zibll_probe',
    'meta_value'     => 'plate',
));
if ($plates) {
    $plate_id = (int) $plates[0]->ID;
    wp_update_post(array('ID' => $plate_id, 'post_status' => 'publish', 'post_title' => '联调版块'));
    echo "[OK] 版块已存在 #{$plate_id}\n";
} else {
    $plate_id = wp_insert_post(array(
        'post_type'   => 'plate',
        'post_status' => 'publish',
        'post_title'  => '联调版块',
        'post_author' => 1,
        'post_excerpt'=> 'probe plate',
    ), true);
    if (!is_wp_error($plate_id)) {
        update_post_meta($plate_id, '_zibll_probe', 'plate');
        echo "[OK] 创建版块 #{$plate_id}\n";
    } else {
        echo "[WARN] 创建版块失败: " . $plate_id->get_error_message() . "\n";
        $plate_id = 0;
    }
}

// 第二版块：供 plate_move 切换目标
$plate2_id = 0;
$plates2 = get_posts(array(
    'post_type'      => 'plate',
    'post_status'    => 'any',
    'posts_per_page' => 1,
    'meta_key'       => '_zibll_probe',
    'meta_value'     => 'plate2',
));
if ($plates2) {
    $plate2_id = (int) $plates2[0]->ID;
    wp_update_post(array('ID' => $plate2_id, 'post_status' => 'publish', 'post_title' => '联调版块B'));
    echo "[OK] 版块B已存在 #{$plate2_id}\n";
} else {
    $plate2_id = wp_insert_post(array(
        'post_type'    => 'plate',
        'post_status'  => 'publish',
        'post_title'   => '联调版块B',
        'post_author'  => 1,
        'post_excerpt' => 'probe plate B',
    ), true);
    if (!is_wp_error($plate2_id)) {
        update_post_meta($plate2_id, '_zibll_probe', 'plate2');
        echo "[OK] 创建版块B #{$plate2_id}\n";
    } else {
        echo "[WARN] 创建版块B失败: " . $plate2_id->get_error_message() . "\n";
        $plate2_id = 0;
    }
}

$fposts = get_posts(array(
    'post_type'      => 'forum_post',
    'post_status'    => 'any',
    'posts_per_page' => 1,
    'meta_key'       => '_zibll_probe',
    'meta_value'     => 'forum_post',
));
if ($fposts) {
    $post_id = (int) $fposts[0]->ID;
    wp_update_post(array(
        'ID'          => $post_id,
        'post_status' => 'pending',
        'post_title'  => '联调待审帖',
    ));
    if ($plate_id) {
        update_post_meta($post_id, 'plate_id', $plate_id);
    }
    echo "[OK] 帖子已存在 #{$post_id} → pending\n";
} else {
    $post_id = wp_insert_post(array(
        'post_type'    => 'forum_post',
        'post_status'  => 'pending',
        'post_title'   => '联调待审帖',
        'post_content' => 'probe forum post',
        'post_author'  => (int) $user->ID,
    ), true);
    if (!is_wp_error($post_id)) {
        update_post_meta($post_id, '_zibll_probe', 'forum_post');
        if ($plate_id) {
            update_post_meta($post_id, 'plate_id', $plate_id);
        }
        echo "[OK] 创建帖子 #{$post_id} pending\n";
    } else {
        echo "[WARN] 创建帖子失败: " . $post_id->get_error_message() . "\n";
        $post_id = 0;
    }
}

update_option('zibll_probe_fixtures', array(
    'user_login' => $login,
    'user_pass'  => $pass,
    'user_id'    => (int) $user->ID,
    'product_id' => (int) $pid,
    'pay_price'  => $pay_price,
    'pay_modo'   => is_array($confirm) ? ($confirm['pay_modo'] ?? '0') : '0',
    'plate_id'   => (int) $plate_id,
    'plate2_id'  => (int) $plate2_id,
    'forum_post_id' => (int) $post_id,
));

echo wp_json_encode(get_option('zibll_probe_fixtures'), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
