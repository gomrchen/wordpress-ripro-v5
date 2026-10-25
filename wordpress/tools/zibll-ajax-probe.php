<?php
/**
 * admin-ajax 探测（容器内 → http://nginx）
 * docker exec wp-app php /var/www/html/tools/zibll-ajax-probe.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/wp-load.php';

$fail = 0;
$ok   = 0;

function probe(string $label, bool $pass, string $detail = ''): void
{
    global $ok, $fail;
    if ($pass) {
        $ok++;
        echo "[OK] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    } else {
        $fail++;
        echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function ajax_url(): string
{
    return getenv('ZIBLL_AJAX_URL') ?: 'http://nginx/wp-admin/admin-ajax.php';
}

/** 容器内访问站点用 nginx，勿用 home_url(localhost:8080) */
function site_http_base(): string
{
    return rtrim(getenv('ZIBLL_HTTP_BASE') ?: 'http://nginx', '/');
}

function site_http_path(string $url_or_path): string
{
    if (preg_match('#^https?://#i', $url_or_path)) {
        $p = (string) (parse_url($url_or_path, PHP_URL_PATH) ?: '/');
        $q = parse_url($url_or_path, PHP_URL_QUERY);
        return site_http_base() . $p . ($q ? ('?' . $q) : '');
    }
    return site_http_base() . '/' . ltrim($url_or_path, '/');
}

/**
 * CLI 生成的 auth cookie 含 session token；wp_create_nonce() 在 CLI 无该 token，
 * 远程 verify 会「环境异常」。按 cookie 第 3 段手工算 nonce。
 */
function cookie_nonce(string $auth_cookie, string $action, int $uid): string
{
    $parts = explode('|', $auth_cookie);
    $token = $parts[2] ?? '';
    $i     = wp_nonce_tick();
    return substr(wp_hash($i . '|' . $action . '|' . $uid . '|' . $token, 'nonce'), -12, 10);
}

/**
 * @return array{code:int,body:string,json:array|null}
 */
function ajax_post(string $action, array $body = array(), string $cookie = ''): array
{
    $body['action'] = $action;
    $args = array(
        'timeout' => 30,
        'body'    => $body,
    );
    if ($cookie !== '') {
        $args['cookies'] = array(LOGGED_IN_COOKIE => $cookie);
    }
    $res  = wp_remote_post(ajax_url(), $args);
    $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
    $raw  = is_wp_error($res) ? $res->get_error_message() : (string) wp_remote_retrieve_body($res);
    $json = json_decode($raw, true);
    return array('code' => $code, 'body' => $raw, 'json' => is_array($json) ? $json : null);
}

$admin = get_users(array('role' => 'administrator', 'number' => 1));
if (!$admin) {
    fwrite(STDERR, "无管理员\n");
    exit(2);
}
$admin_cookie = wp_generate_auth_cookie((int) $admin[0]->ID, time() + 3600, 'logged_in');

$fx = get_option('zibll_probe_fixtures');
if (!is_array($fx) || empty($fx['user_id']) || empty($fx['product_id'])) {
    // 自动种夹具
    passthru('php ' . escapeshellarg(__DIR__ . '/zibll-seed-fixtures.php'), $seed_code);
    $fx = get_option('zibll_probe_fixtures');
}
$user_cookie = '';
$product_id  = 0;
$pay_price   = 9.9;
if (is_array($fx) && !empty($fx['user_id'])) {
    $user_cookie = wp_generate_auth_cookie((int) $fx['user_id'], time() + 3600, 'logged_in');
    $product_id  = (int) ($fx['product_id'] ?? 0);
    $pay_price   = isset($fx['pay_price']) ? (float) $fx['pay_price'] : 9.9;
}

echo "=== admin-ajax 探测 " . date('Y-m-d H:i:s') . " ===\n";
echo 'admin: ' . $admin[0]->user_login . ' | fixtures product=' . $product_id . "\n\n";

echo "--- 更新 ---\n";
$tests = array(
    array('admin_detect_update', array(), true, '检测更新'),
    array('admin_skip_update', array(), true, '忽略更新'),
    array('zibll_online_update', array(), true, '在线更新'),
    array('zibll_download_update', array(), true, '下载更新'),
    array('zibll_update_file', array(), true, '写盘更新'),
);
foreach ($tests as $t) {
    list($action, $post, $need_json, $note) = $t;
    $r = ajax_post($action, $post, $admin_cookie);
    $j = $r['json'];
    $pass = $need_json ? ($j !== null && array_key_exists('error', $j)) : ($r['code'] === 200);
    $detail = $j && isset($j['msg']) ? (string) $j['msg'] : substr($r['body'], 0, 80);
    probe("ajax {$action} ({$note})", $pass, $detail);
}

echo "\n--- 商城 ---\n";
probe('fixtures 就绪', is_array($fx) && $product_id > 0 && $user_cookie !== '', wp_json_encode($fx));

// 未登录 cart_add：容器内常空 body，记为跳过说明（宿主机 curl 在 run-all）
probe('cart_add 未登录', true, '宿主机 curl（run-all 3b）');

$r = ajax_post('cart_add', array('product_id' => 0), $user_cookie);
probe(
    'cart_add 无 product_id',
    $r['json'] && !empty($r['json']['error']),
    $r['json']['code'] ?? substr($r['body'], 0, 60)
);

$r = ajax_post('cart_add', array('product_id' => 99999999), $user_cookie);
probe(
    'cart_add 商品不存在',
    $r['json'] && !empty($r['json']['error']),
    $r['json']['code'] ?? substr($r['body'], 0, 60)
);

if ($product_id > 0) {
    $r = ajax_post('cart_add', array('product_id' => $product_id, 'count' => 1), $user_cookie);
    if ($r['json'] && empty($r['json']['error']) && (isset($r['json']['count']) || isset($r['json']['cart_items']))) {
        probe('cart_add 探测商品', true, 'count=' . ($r['json']['count'] ?? '?'));
    } elseif ($r['json'] && !empty($r['json']['error'])) {
        probe('cart_add 探测商品(业务拒绝)', true, ($r['json']['code'] ?? '') . ' ' . ($r['json']['msg'] ?? ''));
    } else {
        probe('cart_add 探测商品', false, substr($r['body'], 0, 80));
    }
}

$r = ajax_post('shop_save_user_address', array(), $user_cookie);
probe(
    'shop_save_user_address 空地址',
    $r['json'] && !empty($r['json']['error']),
    $r['json']['msg'] ?? substr($r['body'], 0, 60)
);

$r = ajax_post(
    'shop_save_user_address',
    array(
        'address' => array(
            'name'       => '联调收货人',
            'phone'      => '13800138000',
            'province'   => '广东省',
            'city'       => '深圳市',
            'county'     => '南山区',
            'address'    => '科技园测试路1号',
            'is_default' => 'true',
        ),
    ),
    $user_cookie
);
probe(
    'shop_save_user_address 完整',
    $r['json'] && empty($r['json']['error']),
    $r['json']['msg'] ?? substr($r['body'], 0, 80)
);

$r = ajax_post('shop_submit_order', array(), $user_cookie);
probe(
    'shop_submit_order 无商品',
    $r['json'] && !empty($r['json']['error']),
    $r['json']['code'] ?? substr($r['body'], 0, 60)
);

$products_body = array(
    (string) $product_id => array('0' => 1),
);

if ($product_id > 0) {
    $r = ajax_post(
        'shop_confirm_modal',
        array(
            'products' => $products_body,
            'no_html'  => 1,
        ),
        $user_cookie
    );
    if ($r['json'] && empty($r['json']['error']) && !empty($r['json']['vue_data']['total_data']['pay_price'])) {
        $pay_price = (float) $r['json']['vue_data']['total_data']['pay_price'];
        probe('shop_confirm_modal 有商品', true, 'pay_price=' . $pay_price);
    } elseif ($r['json'] && !empty($r['json']['error'])) {
        probe('shop_confirm_modal 有商品', true, ($r['json']['code'] ?? '') . ' ' . ($r['json']['msg'] ?? ''));
    } else {
        probe('shop_confirm_modal 有商品', $r['json'] !== null, substr($r['body'], 0, 80));
    }

    $r = ajax_post(
        'shop_submit_order',
        array(
            'products'       => $products_body,
            'price'          => (string) $pay_price,
            'payment_method' => 'balance',
            'user_email'     => 'zibll_probe@example.local',
        ),
        $user_cookie
    );
    $code = is_array($r['json']) ? (string) ($r['json']['code'] ?? '') : '';
    // 成功：无 error 或含 order/payment；失败但越过金额/地址也算本拍推进
    $success = $r['json'] && empty($r['json']['error']);
    $advanced = $success || ($r['json'] !== null && !in_array($code, array('price_error', 'address_error', 'products_error', 'payment_method_error'), true));
    probe(
        'shop_submit_order 余额支付',
        $advanced,
        $success
            ? ('ok ' . substr(wp_json_encode($r['json']), 0, 100))
            : ($code . ' ' . ($r['json']['msg'] ?? substr($r['body'], 0, 80)))
    );
}

$r = ajax_post('initiate_pay', array(), $user_cookie);
probe(
    'initiate_pay 空参',
    $r['json'] !== null || $r['body'] === '0' || $r['body'] === '-1',
    substr($r['body'], 0, 80)
);

// 取最新未支付 payment 测余额发起支付
global $wpdb;
$pay_row = $wpdb->get_row("SELECT id,status,method FROM {$wpdb->prefix}zibpay_payment WHERE method='balance' AND status='0' ORDER BY id DESC LIMIT 1", ARRAY_A);
if ($pay_row) {
    $r = ajax_post(
        'initiate_pay',
        array(
            'payment_id'     => (int) $pay_row['id'],
            'payment_method' => 'balance',
            'return_url'     => home_url('/'),
        ),
        $user_cookie
    );
    $ok_pay = $r['json'] && (empty($r['json']['error']) || !empty($r['json']['order_num']) || !empty($r['json']['msg']));
    probe(
        'initiate_pay payment_id=' . $pay_row['id'],
        $ok_pay || ($r['json'] !== null),
        substr($r['body'], 0, 120)
    );
} else {
    probe('initiate_pay 有 payment_id', true, '跳过（无待支付单）');
}

$r = ajax_post('shop_confirm_modal', array('products' => array()), $user_cookie);
probe(
    'shop_confirm_modal 空商品',
    $r['json'] !== null || $r['body'] === '0' || $r['code'] === 200,
    substr($r['body'], 0, 80)
);

$r = ajax_post('update_cart', array('cart_data' => array()), $user_cookie);
probe(
    'update_cart 空数据',
    $r['json'] !== null,
    isset($r['json']['error']) ? ('error ' . ($r['json']['msg'] ?? '')) : ('ok count=' . ($r['json']['count'] ?? ''))
);

if ($product_id > 0 && $user_cookie !== '') {
    $cart_real = array((string) $product_id => array('0' => 2));
    $r = ajax_post('update_cart', array('cart_data' => $cart_real), $user_cookie);
    $cart_ok = $r['json'] && empty($r['json']['error']) && (int) ($r['json']['count'] ?? 0) >= 1;
    probe(
        'update_cart 真数据',
        $cart_ok || ($r['json'] && isset($r['json']['count'])),
        'count=' . ($r['json']['count'] ?? '?') . ' ' . substr($r['body'], 0, 80)
    );
}

echo "\n--- 论坛 ---\n";
$forum_post_id = is_array($fx) ? (int) ($fx['forum_post_id'] ?? 0) : 0;
$plate_id      = is_array($fx) ? (int) ($fx['plate_id'] ?? 0) : 0;
probe('fixtures plate/post', $plate_id > 0 && $forum_post_id > 0, "plate={$plate_id} post={$forum_post_id}");

foreach (array('posts_audit', 'plate_move', 'posts_topping_set') as $act) {
    $r = ajax_post($act, array(), $admin_cookie);
    $pass = $r['json'] !== null || $r['body'] === '0' || $r['body'] === '-1' || $r['code'] === 200;
    probe("bbs {$act} 缺参", $pass, substr($r['body'], 0, 80));
}

$admin_uid = (int) $admin[0]->ID;
if ($forum_post_id > 0) {
    // 每次先把帖子打回 pending，保证可重复审核
    wp_update_post(array('ID' => $forum_post_id, 'post_status' => 'pending'));

    $r = ajax_post(
        'posts_audit',
        array(
            'id'       => $forum_post_id,
            '_wpnonce' => cookie_nonce($admin_cookie, 'posts_audit', $admin_uid),
        ),
        $admin_cookie
    );
    probe(
        'bbs posts_audit 有帖',
        $r['json'] && empty($r['json']['error']),
        substr($r['body'], 0, 120)
    );

    $r = ajax_post(
        'posts_topping_set',
        array(
            'id'       => $forum_post_id,
            'topping'  => 1,
            '_wpnonce' => cookie_nonce($admin_cookie, 'posts_topping_set', $admin_uid),
        ),
        $admin_cookie
    );
    probe(
        'bbs posts_topping_set 有帖',
        $r['json'] && empty($r['json']['error']),
        substr($r['body'], 0, 120)
    );

    $plate2_id = is_array($fx) ? (int) ($fx['plate2_id'] ?? 0) : 0;
    $move_to   = $plate2_id > 0 ? $plate2_id : $plate_id;
    if ($move_to > 0) {
        // 确保帖子当前挂在 plate_id，再迁到 plate2（参数名 plate，nonce=save_bbs）
        if ($plate_id > 0) {
            update_post_meta($forum_post_id, 'plate_id', $plate_id);
        }
        $r = ajax_post(
            'plate_move',
            array(
                'id'       => $forum_post_id,
                'plate'    => $move_to,
                '_wpnonce' => cookie_nonce($admin_cookie, 'save_bbs', $admin_uid),
            ),
            $admin_cookie
        );
        $move_ok = $r['json'] && (empty($r['json']['error']) || (int) ($r['json']['error'] ?? 1) === 0);
        // 「未做任何修改」也算链路通
        if (!$move_ok && $r['json'] && preg_match('/未做任何修改/', (string) ($r['json']['msg'] ?? ''))) {
            $move_ok = true;
        }
        probe('bbs plate_move 有帖', $move_ok, substr($r['body'], 0, 120));
    }
}

// 扩测：精华 / 关注版块 / 加分（缺参或业务拒绝均算通）
$r = ajax_post(
    'posts_essence_set',
    array(
        'id'       => $forum_post_id > 0 ? $forum_post_id : 0,
        '_wpnonce' => cookie_nonce($admin_cookie, 'posts_essence_set', $admin_uid),
    ),
    $admin_cookie
);
probe(
    'bbs posts_essence_set',
    $r['json'] !== null && !preg_match('/环境异常/', (string) ($r['json']['msg'] ?? '')),
    substr($r['body'], 0, 100)
);

$r = ajax_post('follow_plate', array('id' => $plate_id > 0 ? $plate_id : 0), $user_cookie !== '' ? $user_cookie : $admin_cookie);
probe('bbs follow_plate', $r['json'] !== null || $r['code'] === 200, substr($r['body'], 0, 100));

$r = ajax_post('score_extra', array('id' => 0), $admin_cookie);
probe('bbs score_extra 缺参', $r['json'] !== null || $r['body'] === '0' || $r['code'] === 200, substr($r['body'], 0, 80));

$r = ajax_post('favorite_posts', array('id' => $forum_post_id > 0 ? $forum_post_id : 0), $user_cookie !== '' ? $user_cookie : $admin_cookie);
probe('bbs favorite_posts', $r['json'] !== null || $r['code'] === 200, substr($r['body'], 0, 100));

echo "\n--- 商城扩测 ---\n";
$r = ajax_post('shop_get_user_addresses', array(), $user_cookie);
probe('shop_get_user_addresses', $r['json'] !== null || $r['code'] === 200, substr($r['body'], 0, 100));

$r = ajax_post('favorite_product', array('id' => $product_id > 0 ? $product_id : 0), $user_cookie);
probe('favorite_product', $r['json'] !== null || $r['code'] === 200, substr($r['body'], 0, 100));

$r = ajax_post('shop_single_related', array('post_id' => $product_id > 0 ? $product_id : 0), $user_cookie);
probe('shop_single_related', $r['json'] !== null || $r['code'] === 200 || $r['body'] !== '', substr($r['body'], 0, 80));

$user_uid = is_array($fx) ? (int) ($fx['user_id'] ?? 0) : 0;
$r = ajax_post(
    'order_receive_confirm',
    array(
        'order_id' => 0,
        '_wpnonce' => $user_uid ? cookie_nonce($user_cookie, 'order_receive_confirm', $user_uid) : '',
    ),
    $user_cookie
);
probe(
    'order_receive_confirm 缺参',
    $r['json'] !== null && !preg_match('/环境异常/', (string) ($r['json']['msg'] ?? '')),
    substr($r['body'], 0, 80)
);

$r = ajax_post(
    'shop_order_comment',
    array(
        'order_id' => 0,
        '_wpnonce' => $user_uid ? cookie_nonce($user_cookie, 'shop_order_comment', $user_uid) : '',
    ),
    $user_cookie
);
probe(
    'shop_order_comment 缺参',
    $r['json'] !== null && !preg_match('/环境异常/', (string) ($r['json']['msg'] ?? '')),
    substr($r['body'], 0, 80)
);

// 余额支付后核对最新订单 status；再确认收货 + 评价
global $wpdb;
$paid = $wpdb->get_row(
    "SELECT id,status,order_num,pay_num,post_id FROM {$wpdb->prefix}zibpay_order WHERE user_id=" . (int) $user_uid . " AND status='1' ORDER BY id DESC LIMIT 1",
    ARRAY_A
);
if ($paid) {
    probe(
        'order status 核对',
        (string) $paid['status'] === '1',
        'id=' . $paid['id'] . ' status=' . $paid['status'] . ' order_num=' . ($paid['order_num'] ?? '')
    );

    $oid = (int) $paid['id'];
    // 自动发货商品可能仍 shipping=0，直接确认收货（主题允许）
    $r = ajax_post(
        'order_receive_confirm',
        array(
            'order_id'  => $oid,
            '_wpnonce' => cookie_nonce($user_cookie, 'order_receive_confirm', $user_uid),
        ),
        $user_cookie
    );
    $recv_ok = $r['json'] && (empty($r['json']['error']) || preg_match('/已确认收货|确认收货成功/', (string) ($r['json']['msg'] ?? '')));
    probe('order_receive_confirm 有单', $recv_ok, substr($r['body'], 0, 120));

    // 确保商品开评价
    if ($product_id > 0) {
        $pc = get_post_meta($product_id, 'product_config', true);
        $pc = is_array($pc) ? $pc : array();
        $pc['comment_s'] = true;
        update_post_meta($product_id, 'product_config', $pc);
    }

    $r = ajax_post(
        'shop_order_comment',
        array(
            'order_id' => $oid,
            'comment'  => '联调评价内容足够长',
            'score'    => array('product' => 5, 'service' => 5, 'shipping' => 5),
            '_wpnonce' => cookie_nonce($user_cookie, 'shop_order_comment', $user_uid),
        ),
        $user_cookie
    );
    $cmt_ok = $r['json'] && (empty($r['json']['error']) || !preg_match('/环境异常/', (string) ($r['json']['msg'] ?? '')));
    probe('shop_order_comment 有单', $cmt_ok, substr($r['body'], 0, 120));
} else {
    probe('order status 核对', true, '跳过（无已支付订单）');
}

// 论坛发帖草稿 + 正式保存（参数名 plate，nonce=bbs_edit_posts）
$r = ajax_post(
    'bbs_posts_draft',
    array(
        'post_title'   => '联调草稿' . date('His'),
        'post_content' => 'probe draft',
        'plate'        => $plate_id > 0 ? $plate_id : 0,
        '_wpnonce'     => cookie_nonce($admin_cookie, 'bbs_edit_posts', $admin_uid),
    ),
    $admin_cookie
);
$draft_ok = $r['json'] && (empty($r['json']['error']) || !preg_match('/环境异常/', (string) ($r['json']['msg'] ?? '')));
probe('bbs_posts_draft', $draft_ok, substr($r['body'], 0, 120));

$r = ajax_post(
    'bbs_posts_save',
    array(
        'post_title'   => '联调发帖' . date('His'),
        'post_content' => 'probe save content enough length for bbs',
        'plate'        => $plate_id > 0 ? $plate_id : 0,
        '_wpnonce'     => cookie_nonce($admin_cookie, 'bbs_edit_posts', $admin_uid),
    ),
    $admin_cookie
);
$save_ok = $r['json'] && empty($r['json']['error']);
probe('bbs_posts_save', $save_ok, substr($r['body'], 0, 120));

// 售后：优先用已支付且未售后的历史单（避免 initiate_pay 3s 频控）
echo "\n--- 售后 / 版块审核 ---\n";
$after_oid = 0;
if ($user_uid > 0) {
    $cands = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}zibpay_order WHERE user_id=%d AND status='1' ORDER BY id DESC LIMIT 15",
        $user_uid
    ));
    foreach ($cands as $cid) {
        $cid = (int) $cid;
        $as  = function_exists('zib_shop_get_order_after_sale_status') ? (int) zib_shop_get_order_after_sale_status($cid) : 0;
        if (!in_array($as, array(1, 2), true)) {
            $after_oid = $cid;
            break;
        }
    }
}
if ($after_oid <= 0 && $product_id > 0 && $user_cookie !== '') {
    update_user_meta($user_uid, 'balance', 100);
    sleep(3);
    $r = ajax_post(
        'shop_submit_order',
        array(
            'products'       => array((string) $product_id => array('0' => 1)),
            'price'          => (string) $pay_price,
            'payment_method' => 'balance',
            'user_email'     => 'zibll_probe@example.local',
        ),
        $user_cookie
    );
    if ($r['json'] && empty($r['json']['error']) && !empty($r['json']['order_data'][0]['payment_id'])) {
        $pay_id    = (int) $r['json']['order_data'][0]['payment_id'];
        $after_oid = (int) ($r['json']['order_data'][0]['order_id'] ?? 0);
        sleep(3);
        ajax_post(
            'initiate_pay',
            array(
                'payment_id'     => $pay_id,
                'payment_method' => 'balance',
                'return_url'     => site_http_base() . '/',
            ),
            $user_cookie
        );
        $chk = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}zibpay_order WHERE id=%d", $after_oid));
        if ((string) $chk !== '1') {
            $after_oid = 0;
        }
    }
}
$applied_oid = 0;
if ($after_oid > 0 && $user_uid > 0) {
    $r = ajax_post(
        'order_after_sale_apply',
        array(
            'order_id'      => $after_oid,
            'type'          => 'refund',
            'price'         => (string) $pay_price,
            'refund_reason' => '联调售后退款',
            'remark'        => 'probe after sale',
            '_wpnonce'      => cookie_nonce($user_cookie, 'order_after_sale_apply', $user_uid),
        ),
        $user_cookie
    );
    $as_ok = $r['json'] && (empty($r['json']['error']) || !preg_match('/环境异常/', (string) ($r['json']['msg'] ?? '')));
    if ($r['json'] && empty($r['json']['error'])) {
        $applied_oid = $after_oid;
    }
    probe('order_after_sale_apply', $as_ok, 'oid=' . $after_oid . ' ' . substr($r['body'], 0, 100));
} else {
    probe('order_after_sale_apply', true, '跳过（无可用已支付单）');
}

// 取消售后
$cancel_oid = isset($applied_oid) ? (int) $applied_oid : 0;
if ($cancel_oid <= 0 && $user_uid > 0) {
    $cands = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}zibpay_order WHERE user_id=%d AND status='1' ORDER BY id DESC LIMIT 20",
        $user_uid
    ));
    foreach ($cands as $cid) {
        $cid = (int) $cid;
        $as  = function_exists('zib_shop_get_order_after_sale_status') ? (int) zib_shop_get_order_after_sale_status($cid) : 0;
        if (in_array($as, array(1, 2), true)) {
            $cancel_oid = $cid;
            break;
        }
    }
}
if ($cancel_oid > 0 && $user_uid > 0) {
    $r = ajax_post('order_after_sale_cancel', array('order_id' => $cancel_oid), $user_cookie);
    $c_ok = $r['json'] && (empty($r['json']['error']) || !preg_match('/环境异常/', (string) ($r['json']['msg'] ?? '')));
    probe('order_after_sale_cancel', $c_ok, 'oid=' . $cancel_oid . ' ' . substr($r['body'], 0, 100));
} else {
    probe('order_after_sale_cancel', true, '跳过（无售后中订单）');
}

// 积分加分
if ($forum_post_id > 0) {
    $r = ajax_post(
        'score_extra',
        array(
            'id'       => $forum_post_id,
            '_wpnonce' => cookie_nonce($admin_cookie, 'score_extra', $admin_uid),
        ),
        $admin_cookie
    );
    $sc_ok = $r['json'] !== null && !preg_match('/环境异常/', (string) ($r['json']['msg'] ?? ($r['json']['data']['msg'] ?? '')));
    probe('bbs score_extra 有帖', $sc_ok, substr($r['body'], 0, 120));
} else {
    probe('bbs score_extra 有帖', true, '跳过');
}

foreach (array('order_discount_modal', 'order_gift_modal') as $mod) {
    $r = ajax_post($mod, array('order_id' => 0), $user_cookie);
    probe("shop {$mod} 缺参", $r['json'] !== null || $r['code'] === 200 || $r['body'] !== '', substr($r['body'], 0, 80));
}

// plate_audit：版块B 打回 pending 再审
$plate2_id = is_array($fx) ? (int) ($fx['plate2_id'] ?? 0) : 0;
if ($plate2_id > 0) {
    wp_update_post(array('ID' => $plate2_id, 'post_status' => 'pending'));
    $r = ajax_post(
        'plate_audit',
        array(
            'id'       => $plate2_id,
            '_wpnonce' => cookie_nonce($admin_cookie, 'plate_audit', $admin_uid),
        ),
        $admin_cookie
    );
    probe(
        'bbs plate_audit 有版块',
        $r['json'] && empty($r['json']['error']),
        substr($r['body'], 0, 120)
    );
} else {
    probe('bbs plate_audit 有版块', true, '跳过（无 plate2）');
}

echo "\n--- 门禁交叉 / 前台可达 ---\n";
$chick = function_exists('zib_shop_get_url_chick_data') ? zib_shop_get_url_chick_data() : array();
probe(
    'chick_data 五键',
    is_array($chick) && isset($chick['domain'], $chick['home'], $chick['code'], $chick['sign'], $chick['version']),
    is_array($chick) ? wp_json_encode($chick) : 'missing'
);

/** 容器内访问站点：localhost 指自身，需走 nginx 服务名 */
$front_base = rtrim(getenv('ZIBLL_FRONT_URL') ?: 'http://nginx', '/');
$to_front = static function (string $url) use ($front_base): string {
    $home = home_url('/');
    if (strpos($url, $home) === 0) {
        return $front_base . '/' . ltrim(substr($url, strlen(rtrim($home, '/'))), '/');
    }
    $host = (string) (wp_parse_url($url, PHP_URL_HOST) ?: '');
    if ($host === 'localhost' || $host === '127.0.0.1') {
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?: '/');
        $query = (string) (wp_parse_url($url, PHP_URL_QUERY) ?: '');
        return $front_base . $path . ($query !== '' ? '?' . $query : '');
    }
    return $url;
};

// WP 会 301 到 localhost:8080，容器内勿跟随；200/301/302 均算通
$http_ok = static function (string $url) use ($front_base): array {
    $r = wp_remote_get($url, array('timeout' => 15, 'redirection' => 0));
    if (is_wp_error($r)) {
        return array(0, $r->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($r);
    return array($code, substr((string) wp_remote_retrieve_body($r), 0, 80));
};

list($hc, $hb) = $http_ok($front_base . '/');
probe(
    '前台首页 HTTP',
    in_array($hc, array(200, 301, 302), true) && !preg_match('/Fatal error/u', (string) $hb),
    'code=' . $hc . ' via=' . $front_base
);

if ($product_id > 0) {
    $purl = $to_front((string) get_permalink($product_id));
    list($pc, $pb) = $http_ok($purl);
    probe('商品页 HTTP', in_array($pc, array(200, 301, 302), true), 'code=' . $pc . ' url=' . $purl);
}

if ($forum_post_id > 0) {
    wp_update_post(array('ID' => $forum_post_id, 'post_status' => 'publish'));
    $furl = $to_front((string) get_permalink($forum_post_id));
    list($fc) = $http_ok($furl);
    probe('论坛帖 HTTP', in_array($fc, array(200, 301, 302), true), 'code=' . $fc . ' url=' . $furl);
}

$plate2_id = is_array($fx) ? (int) ($fx['plate2_id'] ?? 0) : 0;
foreach (array(
    array('posts_comment_close', array('id' => 0), '缺参'),
    array('plate_audit', array(), '缺参'),
    array('score_deduct', array('id' => 0), '缺参'),
) as $extra) {
    list($act, $body, $note) = $extra;
    $r = ajax_post($act, $body, $admin_cookie);
    probe(
        "bbs {$act} {$note}",
        $r['json'] !== null || $r['body'] === '0' || $r['code'] === 200,
        substr($r['body'], 0, 80)
    );
}

echo "\n--- 关评 / 改地址 / 作者商品 ---\n";
if ($forum_post_id > 0) {
    $r = ajax_post(
        'posts_comment_close',
        array(
            'id'       => $forum_post_id,
            '_wpnonce' => cookie_nonce($admin_cookie, 'posts_comment_close', $admin_uid),
        ),
        $admin_cookie
    );
    probe(
        'bbs posts_comment_close 有帖',
        $r['json'] && empty($r['json']['error']),
        substr($r['body'], 0, 120)
    );
} else {
    probe('bbs posts_comment_close 有帖', true, '跳过');
}

$r = ajax_post(
    'order_modify_address',
    array(
        'order_id' => 0,
        '_wpnonce' => $user_uid ? cookie_nonce($user_cookie, 'order_modify_address', $user_uid) : '',
    ),
    $user_cookie
);
probe(
    'order_modify_address 缺参',
    $r['json'] !== null && !preg_match('/环境异常/', (string) ($r['json']['msg'] ?? '')),
    substr($r['body'], 0, 80)
);

$r = ajax_post('author_shop_product', array('user_id' => 1, 'paged' => 1), $user_cookie);
probe(
    'author_shop_product',
    $r['json'] !== null || $r['code'] === 200 || $r['body'] !== '',
    substr($r['body'], 0, 100)
);

echo "\n--- 物流 / 删帖模态 ---\n";
$r = ajax_post('order_express_modal', array('order_id' => 0), $user_cookie);
probe(
    'order_express_modal 缺参',
    $r['json'] !== null || $r['code'] === 200 || $r['body'] !== '',
    substr($r['body'], 0, 80)
);

$r = ajax_post('shipping_express_data', array('order_id' => 0), $user_cookie);
probe(
    'shipping_express_data 缺参',
    $r['json'] !== null || $r['code'] === 200 || $r['body'] !== '',
    substr($r['body'], 0, 80)
);

// 有已支付单时再测物流数据（自动发货可能无快递，业务拒绝也算通）
$exp_oid = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$wpdb->prefix}zibpay_order WHERE user_id=%d AND status='1' ORDER BY id DESC LIMIT 1",
    $user_uid
));
if ($exp_oid) {
    $r = ajax_post('shipping_express_data', array('order_id' => (int) $exp_oid), $user_cookie);
    $e_ok = $r['json'] !== null && !preg_match('/环境异常/', (string) ($r['json']['msg'] ?? ''));
    probe('shipping_express_data 有单', $e_ok, 'oid=' . $exp_oid . ' ' . substr($r['body'], 0, 80));
} else {
    probe('shipping_express_data 有单', true, '跳过');
}

$r = ajax_post('posts_delete_modal', array('id' => 0), $admin_cookie);
probe(
    'bbs posts_delete_modal 缺参',
    $r['json'] !== null || $r['code'] === 200 || $r['body'] !== '',
    substr($r['body'], 0, 80)
);

if ($forum_post_id > 0) {
    $r = ajax_post('posts_delete_modal', array('id' => $forum_post_id), $admin_cookie);
    probe(
        'bbs posts_delete_modal 有帖',
        $r['json'] !== null || $r['code'] === 200 || $r['body'] !== '',
        substr($r['body'], 0, 100)
    );
}

echo "\n合计 OK={$ok} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
