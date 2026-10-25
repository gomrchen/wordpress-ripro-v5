<?php
/**
 * RiPro V5 主题授权（与后台 theme_ajax_activ / riprov2_new_check 契约一致）
 */
defined('ABSPATH') || exit;

class ZB_Lic {

    private const API_URL           = 'https://ritheme.com/wp-content/plugins/ripro-v2-auth/api/v1.php';
    private const LICENSE_DATA_SLUG = 'riprov2_license_data';
    private const PRODUCT_NAME      = 'ripro-v5';
    private const AES_METHOD        = 'AES-256-CBC';
    private const AES_IV            = '0123456789abcdef';

    private const MARKUP_CONTENT = '<p> <strong>主题授权提示：</strong>请在后台主题设置-主题授权-激活主题的正版授权，授权购买：<a class="" href="https://ritheme.com/" target="_blank">RiTheme官网</a></p>';

    private const MARKUP_ADMIN_PREFIX = '<a href="';
    private const MARKUP_ADMIN_MID    = '">立即激活</a> | 授权购买：<a target="_blank" href="https://ritheme.com/">RiTheme官网</a>';
    private const MARKUP_ADMIN_WRAP = '<div class="notice notice-error theme-activate-notice"><p><strong>主题授权提示：</strong>请在后台主题设置填写授权信息激活主题：';

    /** @var string */
    private $id = '';
    /** @var string */
    private $key = '';
    /** @var string */
    private $token = '';
    /** @var bool */
    private $is_activ = false;
    /** @var string */
    private $site_url = '';

    public function __construct() {
        $this->init();
        $this->register_hooks();
    }

    private function register_hooks() {
        $check_action = 'wp_ajax_riprov2_new_check';
        if (!has_action($check_action)) {
            add_action($check_action, array($this, 'ajax_new_check'));
            add_action('wp_ajax_nopriv_riprov2_new_check', array($this, 'ajax_new_check'));
        }

        add_action('csf_' . _OPTIONS_PRE . '_save', array($this, 'save_action'));

        add_action('wp_ajax_theme_ajax_activ', array($this, 'theme_ajax_activ'));

        if (!$this->is_activ) {
            add_filter('the_content', array($this, 'license_after_content'), 9039);
            add_action('admin_notices', array($this, 'license_after_admin'));
            add_filter('document_title_parts', array($this, 'license_document_parts'), 10, 1);
        }
    }

    private function init() {
        global $wpdb;

        $this->site_url = untrailingslashit((string) get_option('siteurl'));
        if (defined('WP_SITEURL')) {
            $this->site_url = untrailingslashit(WP_SITEURL);
        } elseif (defined('WP_HOME')) {
            $this->site_url = untrailingslashit(WP_HOME);
        } else {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                'siteurl'
            ));
            if (is_object($row) && $row->option_value) {
                $this->site_url = untrailingslashit($row->option_value);
            }
        }

        $local = $this->get_local_license_data();
        $this->id  = isset($local['id']) ? (string) $local['id'] : '';
        $this->key = isset($local['key']) ? (string) $local['key'] : '';

        if (defined('_THEME_TOKEN')) {
            $this->token = _THEME_TOKEN;
        }

        $this->is_activ = $this->is_local_activ();
        if (!$this->is_activ && defined('RIPRO_LOCAL_DOCKER') && RIPRO_LOCAL_DOCKER) {
            $this->is_activ = true;
        }

        $GLOBALS['ripro_is_activ'] = $this->is_activ;
    }

    private function is_local_activ() {
        if (empty($this->id) || empty($this->key) || empty($this->token)) {
return false;
        }
        $hash = md5($this->id . self::PRODUCT_NAME . $this->key);
        return wp_check_password($hash, md5($this->token));
    }

    private function option_key() {
        return md5(self::LICENSE_DATA_SLUG . $this->site_url);
    }

    private function get_local_license_data() {
        $raw = get_option($this->option_key());
        if (empty($raw)) {
            return array('id' => '', 'key' => '');
        }
        $json = $this->destr($raw);
        if ($json === false || $json === '') {
            return array('id' => '', 'key' => '');
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return array('id' => '', 'key' => '');
        }
        return wp_parse_args($data, array('id' => '', 'key' => ''));
    }

    private function update_local_license_data($data = array()) {
        $payload = array_merge(
            array('id' => '', 'key' => ''),
            $data
        );
        $encrypted = $this->enstr(wp_json_encode($payload));
        return update_option($this->option_key(), $encrypted);
    }

    private function license_crypto_key() {
        return md5(pack('H*', md5($this->site_url)));
    }

    private function enstr($data) {
        $key       = $this->license_crypto_key();
        $encrypted = openssl_encrypt($data, self::AES_METHOD, $key, OPENSSL_RAW_DATA, self::AES_IV);
        return base64_encode($encrypted);
    }

    private function destr($encrypted) {
        $key = $this->license_crypto_key();
        $bin = base64_decode($encrypted, true);
        if ($bin === false) {
            return false;
        }
        return openssl_decrypt($bin, self::AES_METHOD, $key, OPENSSL_RAW_DATA, self::AES_IV);
    }

    private function verify_license_sign($id, $key) {
        $hash = md5($id . wp_date('Y-m') . $key);
        return wp_check_password($hash, md5($this->token));
    }

    private function json_status($status, $msg) {
        return array('status' => $status, 'msg' => $msg);
    }

    private function http_request($url, $data) {
        $response = wp_remote_post($url, array(
            'timeout'     => 15,
            'sslverify'   => false,
            'body'        => $data,
        ));
        if (is_wp_error($response)) {
            return false;
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        return wp_remote_retrieve_body($response);
    }

    private function asyn_check_license($id = '', $key = '', $action = 'activ') {
        if (!$this->verify_license_sign($id, $key)) {
            return $this->json_status(0, __('授权错误', 'ripro'));
        }

        $activ_option = 'theme_activatenum';
        $activ_count  = (int) get_option($activ_option, 0);
        if ($action === 'activ' && $activ_count >= 10) {
            return $this->json_status(0, __('激活失败', 'ripro'));
        }

        $body = array(
            'site'         => get_bloginfo('name'),
            'name'         => self::PRODUCT_NAME,
            'version'      => defined('_THEME_VERSION') ? _THEME_VERSION : '0.0',
            'domain'       => $this->site_url,
            'email'        => get_bloginfo('admin_email'),
            'admin_email'  => get_bloginfo('admin_email'),
            'user_id'      => get_current_user_id(),
            'user_token'   => $this->token,
            'id'           => $id,
            'key'          => $key,
        );

        $raw = $this->http_request(self::API_URL, $body);
        if ($raw === false) {
            return $this->json_status(0, __('网络连接超时', 'ripro'));
        }

        $result = json_decode($raw, true);
        if (empty($result) || empty($result['status'])) {
            $msg = !empty($result['msg']) ? $result['msg'] : __('授权错误', 'ripro');
            return $this->json_status(0, $msg);
        }

        if ((int) $result['status'] === 1) {
            if ($action === 'activ') {
                update_option($activ_option, $activ_count + 1);
            }
            update_option(md5('theme_is_asyn_check_' . wp_date('Y-m')), time());
            return $this->json_status(1, !empty($result['msg']) ? $result['msg'] : __('授权成功', 'ripro'));
        }

        $msg = !empty($result['msg']) ? $result['msg'] : __('激活失败', 'ripro');
        return $this->json_status(0, $msg);
    }

    public function save_action() {
        $flag_key = md5('theme_is_asyn_check_' . wp_date('Y-m'));
        if (get_option($flag_key)) {
            return;
        }
        $local = $this->get_local_license_data();
        $res   = $this->asyn_check_license($local['id'], $local['key'], 'check');
        if (!empty($res['status']) && (int) $res['status'] === 1) {
            $this->update_local_license_data(array(
                'id'  => $local['id'],
                'key' => $local['key'],
            ));
        } else {
            $this->update_local_license_data();
        }
    }

    public function theme_ajax_activ() {
        if (!current_user_can('administrator')) {
            wp_send_json($this->json_status(0, __('请登录管理员账号激活', 'ripro')));
        }

        $id  = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
        $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';

        if ($id === '' || $key === '' || strpos($id, '*') !== false || strpos($key, '*') !== false) {
            wp_send_json($this->json_status(0, __('请填写正确的授权的信息', 'ripro')));
        }

        if (mb_strlen($id) < 6 || mb_strlen($key) < 6) {
            wp_send_json($this->json_status(0, __('请填写正确的授权的信息', 'ripro')));
        }

        $local = $this->get_local_license_data();
        if ($local['id'] === $id && $local['key'] === $key) {
            wp_send_json($this->json_status(0, __('请填写正确的授权的信息', 'ripro')));
        }

        $res = $this->asyn_check_license($id, $key, 'activ');
        if (!empty($res['status']) && (int) $res['status'] === 1) {
            $this->update_local_license_data(array('id' => $id, 'key' => $key));
            $this->id         = $id;
            $this->key        = $key;
            $this->is_activ   = true;
            $GLOBALS['ripro_is_activ'] = true;
        }

        wp_send_json($res);
    }

    public function ajax_new_check() {
        $type = isset($_REQUEST['type']) ? sanitize_text_field(wp_unslash($_REQUEST['type'])) : '';
        $key  = isset($_REQUEST['key']) ? sanitize_text_field(wp_unslash($_REQUEST['key'])) : '';
        $val  = isset($_REQUEST['value']) ? sanitize_text_field(wp_unslash($_REQUEST['value'])) : '';

        if ($type === 'get' && $key === 'request' && $val === 'check') {
            $res = $this->asyn_check_license($this->id, $this->key, 'check');
            if (!empty($res['status']) && (int) $res['status'] === 1) {
                $this->is_activ = true;
                $GLOBALS['ripro_is_activ'] = true;
            }
            wp_send_json($res);
        }

        if ($type === 'update' && $key === 'status') {
            wp_send_json($this->json_status(1, __('授权成功', 'ripro')));
        }

        wp_send_json($this->json_status(0, __('授权错误', 'ripro')));
    }

    public function license_document_parts($parts) {
        if ($this->is_activ || !is_array($parts)) {
            return $parts;
        }
        if (isset($parts['title'])) {
            $parts['title'] .= __('(未授权)', 'ripro');
        }
        return $parts;
    }

    public function license_after_content($content) {
        if ($this->is_activ || is_feed() || is_home() || !is_singular() || !is_main_query()) {
            return $content;
        }
        return $content . self::MARKUP_CONTENT;
    }

    public function license_after_admin() {
        if ($this->is_activ) {
            return;
        }
        $url = admin_url('admin.php?page=ripro#tab=' . rawurlencode('主题授权'));
        echo self::MARKUP_ADMIN_WRAP . self::MARKUP_ADMIN_PREFIX . esc_url($url) . self::MARKUP_ADMIN_MID . '</p></div>';
    }
}
