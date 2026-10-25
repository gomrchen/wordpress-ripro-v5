RiPro V5 inc/core 开源还原说明
================================

已手写可读：
- class-zb-shop.php     订单表
- class-zb-lic.php      主题授权（不再依赖 bootstrap）
- template-core.tail.php

- class-zb-pay.php      支付网关（Yurun SDK + 虎皮椒/讯虎/易支付/PayPal）

template-core.bootstrap.php 仅在被还原前的旧版 Pay/Lic 使用；Shop/Lic/Pay 均已不依赖。

备份：
- template-core.php.obfuscated.bak
- class-zb-lic.deobfuscated.bak

联调（类 + 订单回调 + 14 条支付路由脚本 + HTTP 探活）：
docker compose exec -T wordpress php -r "define('WP_USE_THEMES', false); require '/var/www/html/wp-load.php'; require '/var/www/html/wp-content/themes/ripro-v5/inc/core/smoke-core.php';"

Docker 本地无正版授权时：wp-config 通过 RIPRO_LOCAL_DOCKER 允许支付回调联调（见 docker-compose.yml）。
