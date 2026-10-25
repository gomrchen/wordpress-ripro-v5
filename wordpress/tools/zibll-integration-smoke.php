<?php
/**
 * 开源版冒烟：无授权类；校验 ZibDB / 更新 / 商城符号
 * docker exec wp-app php /var/www/html/tools/zibll-integration-smoke.php
 */
declare(strict_types=1);

$wp_load = dirname(__DIR__) . '/wp-load.php';
if (!is_file($wp_load)) {
    fwrite(STDERR, "找不到 wp-load.php\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $wp_load;

$fail = 0;
$ok   = 0;

function smoke(string $label, bool $pass, string $detail = ''): void
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

echo "=== 开源冒烟 " . date('Y-m-d H:i:s') . " ===\n";
echo 'home: ' . home_url('/') . "\n\n";

smoke('无 class ZibAut', !class_exists('ZibAut', false));
smoke('无 class ZibCodeAut', !class_exists('ZibCodeAut', false));
smoke('无 ZIBLL_AUT_OFFLINE_OK', !defined('ZIBLL_AUT_OFFLINE_OK'));
smoke('class ZibDB', class_exists('ZibDB'));
smoke('class ZibCodeUpdeta', class_exists('ZibCodeUpdeta'));
smoke('class ZibPaySDK', class_exists('ZibPaySDK'));
smoke('function zib_json_exit', function_exists('zib_json_exit'));
smoke('function zib_shop_get_url_chick_data', function_exists('zib_shop_get_url_chick_data'));

$chick = zib_shop_get_url_chick_data();
smoke(
    'chick_data 键',
    is_array($chick) && isset($chick['domain'], $chick['home'], $chick['version']),
    is_array($chick) ? implode(',', array_keys($chick)) : ''
);

$exists = ZibDB::name('posts')->where('ID', 1)->is_exists();
smoke('ZibDB::is_exists', is_bool($exists));

foreach (array(
    'admin_skip_update', 'admin_detect_update',
    'zibll_online_update', 'zibll_download_update', 'zibll_update_file',
    'cart_add', 'shop_submit_order', 'initiate_pay',
) as $act) {
    smoke('registered wp_ajax_' . $act, has_action('wp_ajax_' . $act) !== false);
}

smoke('未注册 admin_curl_aut', has_action('wp_ajax_admin_curl_aut') === false);
smoke('未注册 admin_delete_aut', has_action('wp_ajax_admin_delete_aut') === false);

$upd = ZibCodeUpdeta::online_update();
smoke('online_update shape', is_array($upd) && !empty($upd['error']));

echo "\n合计 OK={$ok} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
