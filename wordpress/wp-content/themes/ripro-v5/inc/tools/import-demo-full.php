<?php
/**
 * 官方演示全量导入（内容 + 小工具 + 主题选项），对齐 ripro.rizhuti.com
 *
 *   docker compose exec -T wordpress php wp-content/themes/ripro-v5/inc/tools/import-demo-full.php
 */
if (php_sapi_name() !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 5);
if (!is_file($root . '/wp-load.php')) {
    fwrite(STDERR, "wp-load.php not found\n");
    exit(1);
}

define('WP_ADMIN', true);
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

$ocdi_plugin = 'one-click-demo-import/one-click-demo-import.php';
if (!is_plugin_active($ocdi_plugin)) {
    $activated = activate_plugin($ocdi_plugin);
    if (is_wp_error($activated)) {
        fwrite(STDERR, 'activate OCDI: ' . $activated->get_error_message() . "\n");
        exit(1);
    }
}
if (!defined('OCDI_PATH')) {
    require_once WP_PLUGIN_DIR . '/one-click-demo-import/one-click-demo-import.php';
}

if (!class_exists('OCDI\\OneClickDemoImport')) {
    fwrite(STDERR, "OCDI classes not loaded\n");
    exit(1);
}

@set_time_limit(0);
@ini_set('memory_limit', '512M');

add_filter('ocdi/time_for_one_ajax_call', static function () {
    return 3600;
});
add_filter('ocdi/importer_options', static function ($options) {
    $options['fetch_attachments']     = true;
    $options['aggressive_url_search'] = true;
    return $options;
});

$ocdi = OCDI\OneClickDemoImport::get_instance();
$ocdi->import_files = OCDI\Helpers::validate_import_file_info(
    OCDI\Helpers::apply_filters('ocdi/import_files', array())
);
if (empty($ocdi->import_files[0])) {
    fwrite(STDERR, "No ocdi/import_files from theme\n");
    exit(1);
}

$import_actions = new OCDI\ImportActions();
$import_actions->register_hooks();

$logger            = new OCDI\Logger();
$logger->min_level = 'warning';
$ocdi->importer    = new OCDI\Importer(
    array(
        'fetch_attachments'     => true,
        'aggressive_url_search' => true,
    ),
    $logger
);
$ocdi->log_file_path = OCDI\Helpers::get_log_path();
OCDI\Helpers::set_demo_import_start_time();

echo "Downloading demo files...\n";
$import_files = OCDI\Helpers::download_import_files($ocdi->import_files[0]);
if (empty($import_files)) {
    fwrite(STDERR, "download_import_files failed\n");
    exit(1);
}

$index = 0;
OCDI\Helpers::do_action('ocdi/before_content_import_execution', $import_files, $ocdi->import_files, $index);

if (!empty($import_files['content'])) {
    echo "Importing content (attachments may take several minutes)...\n";
    $errors = $ocdi->importer->import_content($import_files['content']);
    if (!empty($errors)) {
        echo "Content import messages:\n{$errors}\n";
    }
}

if (!empty($import_files['widgets'])) {
    echo "Importing widgets...\n";
    OCDI\Helpers::do_action('ocdi/before_widgets_import', $import_files);
    OCDI\WidgetImporter::import($import_files['widgets']);
}

OCDI\Helpers::do_action('ocdi/after_all_import_execution', $import_files, $ocdi->import_files, $index);

$main_menu = get_term_by('name', 'main', 'nav_menu');
echo 'main menu: ' . ($main_menu ? $main_menu->term_id : 'missing') . "\n";
echo 'home_url: ' . home_url('/') . "\n";
echo "DEMO_FULL_IMPORT_OK\n";
