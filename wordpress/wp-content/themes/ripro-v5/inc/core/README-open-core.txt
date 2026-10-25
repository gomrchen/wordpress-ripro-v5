RiPro V5 inc/core 开源说明
================================

已手写可读（无主题授权 / 无 ritheme 远程校验）：
- class-zb-shop.php   订单表与支付回调
- class-zb-pay.php    支付网关（Yurun SDK + 虎皮椒/讯虎/易支付/PayPal）
- template-core.php   仅加载 Shop + Pay

联调冒烟（类 + 订单回调 + 14 条异步/notify 脚本 + 5 条同步 return HTTP 302 + PayPal cancel）：
docker compose exec -T wordpress php -r "define('WP_USE_THEMES', false); require '/var/www/html/wp-load.php'; require '/var/www/html/wp-content/themes/ripro-v5/inc/core/smoke-core.php';"
