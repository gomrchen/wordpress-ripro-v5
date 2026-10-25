RiPro V5 开源版 CLI 工具（非运行时核心，勿 require 进前台）

apply-demo-reference.php  拉官方 JSON，对齐首页小工具与部分主题选项
align-official-site.php   菜单 + 站点信息 + 关广告 + 官网演示 UI
import-demo-full.php      OCDI 全量导入（需 one-click-demo-import 插件）
sync-demo-media.php       从演示 XML/官网补分类图与正文外链图

示例：
docker compose exec -T wordpress php wp-content/themes/ripro-v5/inc/tools/align-official-site.php
