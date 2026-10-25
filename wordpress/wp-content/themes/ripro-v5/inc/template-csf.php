<?php
/**
 * RiPro-V5 Theme Options Framework
 * 
 * @package RiPro-V5
 * @since 1.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!defined('_OPTIONS_PRE')) {
    // Replace the version number of the theme on each release.
    define('_OPTIONS_PRE', '_ripro_new_options');
}

/**
 * Get theme option value
 *
 * @param string $option Option name
 * @param mixed  $default Default value
 * @return mixed
 */
if (!function_exists('_cao')) {
    function _cao(string $option = '', $default = null) {
        static $options = null;
        
        if ($options === null) {
            $options = get_option(_OPTIONS_PRE, []);
        }
        
        return $options[$option] ?? $default;
    }
}

/**
 * Get legacy theme option value
 *
 * @param string $option Option name
 * @param mixed  $default Default value
 * @return mixed
 */
if (!function_exists('_cao_old')) {
    function _cao_old(string $option = '', $default = null) {
        static $options = null;
        
        if ($options === null) {
            $options = get_option('_riprov2_options', []);
        }
        
        if (strpos($option, ':') !== false) {
            $keys = explode(':', $option);
            $value = $options;
            
            foreach ($keys as $key) {
                if (!isset($value[$key])) {
                    return $default;
                }
                $value = $value[$key];
            }
            
            return $value;
        }
        
        return $options[$option] ?? $default;
    }
}

//加载配置文件
if (!class_exists('CSF')) {
    $config_files = [
        '/plugins/codestar-framework/codestar-framework.php',  // Core
        '/options/admin-options.php',                         // Admin
        '/options/metabox-options.php',                       // Metabox
        '/options/profile-options.php',                       // Profile
        '/options/nav-menu-options.php',                      // Navigation
        '/options/shortcode-options.php',                     // Shortcode
        '/options/widget-options.php',                        // Widget
        '/options/taxonomy-options.php',                      // Taxonomy
    ];

    foreach ($config_files as $file) {
        $file_path = get_template_directory() . '/inc' . $file;
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
}

/**
 * Initialize theme options
 *
 * @param array $params Framework parameters
 * @return array
 */
function zbzhuti_option_init(array $params): array {
    return array_merge($params, [
        'framework_title' => sprintf('RiPro-V5主题设置 <small>正式版 V%s</small>', _THEME_VERSION),
        'menu_title'     => '主题设置',
        'theme'          => 'light',
        'show_bar_menu'  => false,
        'enqueue_webfont'=> false,
        'enqueue'        => false,
        'show_search'    => false,
        'ajax_save'      => false,
        'footer_credit'  => '',
        'footer_text'    => sprintf(
            '感谢您使用RiPro-V5主题进行创作运营，本主题相关文档教程地址 <a href="%s" target="_blank">www.ritheme.com</a>',
            esc_url('https://ritheme.com/document/category/ripro-v5')
        ),
    ]);
}
add_filter('csf_' . _OPTIONS_PRE . '_args', 'zbzhuti_option_init');
