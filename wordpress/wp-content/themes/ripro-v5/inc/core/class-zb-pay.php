<?php
/**
 * 在线支付网关（与 template-shop 契约一致）
 */
defined('ABSPATH') || exit;

class ZB_Pay {

    protected $AliPayConfig   = array();
    protected $WeixinPayConfig = array();
    protected $XhWxPayConfig   = array();
    protected $XhAliPayConfig  = array();
    protected $shop_notify_uri = '';
    protected $home_url        = '';

    public function __construct() {
        $this->AliPayConfig    = (array) _cao('alipay');
        $this->WeixinPayConfig = (array) _cao('weixinpay');
        $this->XhWxPayConfig   = (array) _cao('xunhupay_weixin');
        $this->XhAliPayConfig  = (array) _cao('xunhupay_alipay');
        $this->shop_notify_uri = esc_url(home_url('/pay/callback'));
        $this->home_url        = esc_url(home_url());
    }

    protected function alipay_sdk() {
        $c      = $this->AliPayConfig;
        $public = new \Yurun\PaySDK\AlipayApp\Params\PublicParams();
        $public->appID          = isset($c['appid']) ? $c['appid'] : '';
        $public->appPrivateKey  = isset($c['privateKey']) ? $c['privateKey'] : '';
        $public->appPublicKey   = isset($c['publicKey']) ? $c['publicKey'] : '';
        return new \Yurun\PaySDK\AlipayApp\SDK($public);
    }

    protected function weixin_sdk() {
        $c      = $this->WeixinPayConfig;
        $public = new \Yurun\PaySDK\Weixin\Params\PublicParams();
        $public->appID  = isset($c['appid']) ? $c['appid'] : '';
        $public->mch_id = isset($c['mch_id']) ? $c['mch_id'] : '';
        $public->key    = isset($c['key']) ? $c['key'] : '';
        return new \Yurun\PaySDK\Weixin\SDK($public);
    }

    protected function echo_pay_error($prefix, $exception) {
        if ($exception instanceof Exception) {
            echo $prefix . $exception->getCode() . ',' . $exception->getMessage();
            exit();
        }
        throw $exception;
    }

    public function alipay_app_web_pay($order_info) {
        $sdk     = $this->alipay_sdk();
        $request = new \Yurun\PaySDK\AlipayApp\Page\Params\Pay\Request();
        $request->notify_url = $this->shop_notify_uri . '/alipay/notify';
        $request->return_url = $this->shop_notify_uri . '/alipay/return';
        $request->businessParams->out_trade_no = $order_info['order_trade_no'];
        $request->businessParams->total_amount = $order_info['pay_price'];
        $request->businessParams->subject      = $order_info['order_name'];
        $request->businessParams->qr_pay_mode  = '4';
        $request->businessParams->goods_type   = '0';
        $pay_form = '';
        $sdk->prepareExecute($request, $pay_form);
        return $pay_form;
    }

    public function alipay_app_wap_pay($order_info) {
        $sdk     = $this->alipay_sdk();
        $request = new \Yurun\PaySDK\AlipayApp\Wap\Params\Pay\Request();
        $request->notify_url = $this->shop_notify_uri . '/alipay/notify';
        $request->return_url = $this->shop_notify_uri . '/alipay/return';
        $request->businessParams->out_trade_no = $order_info['order_trade_no'];
        $request->businessParams->total_amount = $order_info['pay_price'];
        $request->businessParams->subject      = $order_info['order_name'];
        $pay_form = '';
        $sdk->prepareExecute($request, $pay_form);
        return $pay_form;
    }

    public function alipay_app_qr_pay($order_info) {
        $sdk     = $this->alipay_sdk();
        $request = new \Yurun\PaySDK\AlipayApp\FTF\Params\QR\Request();
        $request->notify_url = $this->shop_notify_uri . '/alipay/notify';
        $request->businessParams->out_trade_no = $order_info['order_trade_no'];
        $request->businessParams->total_amount = $order_info['pay_price'];
        $request->businessParams->subject      = $order_info['order_name'];
        try {
            $result = $sdk->execute($request);
            if (!isset($result['alipay_trade_precreate_response']['code'])
                || $result['alipay_trade_precreate_response']['code'] != '10000') {
                throw new Exception(
                    isset($result['alipay_trade_precreate_response']['sub_msg'])
                        ? $result['alipay_trade_precreate_response']['sub_msg']
                        : 'Alipay QR error',
                    0
                );
            }
            return $result['alipay_trade_precreate_response']['qr_code'];
        } catch (Exception $e) {
            $this->echo_pay_error('errorcode:', $e);
        }
    }

    public function weixin_qr_pay($order_info) {
        $sdk     = $this->weixin_sdk();
        $request = new \Yurun\PaySDK\Weixin\Native\Params\Pay\Request();
        $request->body         = $order_info['order_name'];
        $request->out_trade_no = $order_info['order_trade_no'];
        $request->total_fee    = (int) round($order_info['pay_price'] * 100);
        $request->spbill_create_ip = $order_info['ip'];
        $request->notify_url   = $this->shop_notify_uri . '/weixinpay/notify';
        try {
            $result = $sdk->execute($request);
            if (!isset($result['return_code']) || $result['return_code'] !== 'SUCCESS') {
                throw new Exception(isset($result['return_msg']) ? $result['return_msg'] : 'WeChat pay error', 0);
            }
            return $result['code_url'];
        } catch (Exception $e) {
            $this->echo_pay_error('errorcode:', $e);
        }
    }

    public function weixin_h5_pay($order_info) {
        $sdk     = $this->weixin_sdk();
        $request = new \Yurun\PaySDK\Weixin\H5\Params\Pay\Request();
        $request->body         = $order_info['order_name'];
        $request->out_trade_no = $order_info['order_trade_no'];
        $request->total_fee    = (int) round($order_info['pay_price'] * 100);
        $request->spbill_create_ip = $order_info['ip'];
        $request->notify_url   = $this->shop_notify_uri . '/weixinpay/notify';
        $scene                 = new \Yurun\PaySDK\Weixin\H5\Params\SceneInfo();
        $scene->type           = 'Wap';
        $scene->wap_url        = $this->home_url;
        $scene->wap_name       = get_bloginfo('name');
        $request->scene_info   = $scene;
        try {
            $result = $sdk->execute($request);
            if (!isset($result['return_code']) || $result['return_code'] !== 'SUCCESS') {
                throw new Exception(isset($result['return_msg']) ? $result['return_msg'] : 'WeChat H5 error', 0);
            }
            return $result['mweb_url'];
        } catch (Exception $e) {
            $this->echo_pay_error('errorcode:', $e);
        }
    }

    public function weixin_jsapi_pay($order_info) {
        $sdk     = $this->weixin_sdk();
        $request = new \Yurun\PaySDK\Weixin\JSAPI\Params\Pay\Request();
        $request->body         = $order_info['order_name'];
        $request->out_trade_no = $order_info['order_trade_no'];
        $request->total_fee    = (int) round($order_info['pay_price'] * 100);
        $request->spbill_create_ip = $order_info['ip'];
        $request->notify_url   = $this->shop_notify_uri . '/weixinpay/notify';
        $request->openid       = isset($order_info['openid']) ? $order_info['openid'] : '';
        try {
            $result = $sdk->execute($request);
            if (!isset($result['return_code']) || $result['return_code'] !== 'SUCCESS') {
                throw new Exception(isset($result['return_msg']) ? $result['return_msg'] : 'WeChat JSAPI error', 0);
            }
            return $result;
        } catch (Exception $e) {
            $this->echo_pay_error('errorcode:', $e);
        }
    }

    public function hupijiao_pay($order_info, $pay_type) {
        require_once get_template_directory() . '/inc/plugins/hupijiao/hupijiao.class.php';

        $config_key = ($pay_type === 'wechat') ? 'hupijiao_weixin' : 'hupijiao_alipay';
        $config     = (array) _cao($config_key);
        $defaults   = array(
            'app_id'     => '',
            'app_secret' => '',
            'api_url'    => 'https://api.dpweixin.com/payment/do.html',
        );
        $pay_config = array_merge($defaults, $config);

        $notify_suffix = ($pay_type === 'wechat') ? '/hpjpay/notify_wx' : '/hpjpay/notify_ali';
        $return_url    = $this->shop_notify_uri . '/hpjpay/return';
        $notify_url    = add_query_arg(
            array(
                'pay_type'     => $pay_type,
                'out_trade_no' => $order_info['order_trade_no'],
            ),
            $this->shop_notify_uri . $notify_suffix
        );

        $data = array(
            'version'         => '1.1',
            'trade_order_id'  => $order_info['order_trade_no'],
            'payment'         => $order_info['pay_price'],
            'total_fee'       => $order_info['pay_price'],
            'title'           => $order_info['order_name'],
            'time'            => time(),
            'notify_url'      => $notify_url,
            'return_url'      => $return_url,
            'callback_url'    => $return_url,
            'nonce_str'       => md5(site_url() . $order_info['order_trade_no']),
            'plugins'         => 'ripro-v5-site',
        );

        $client = new Hupijiao($pay_config);
        try {
            $response = $client->request($data);
            if (!is_array($response) || !isset($response['errcode']) || (int) $response['errcode'] !== 0) {
                $code = isset($response['errcode']) ? $response['errcode'] : 0;
                $msg  = isset($response['errmsg']) ? $response['errmsg'] : 'Hupijiao error';
                throw new Exception($msg, (int) $code);
            }
            return array(
                'url'        => isset($response['url']) ? $response['url'] : '',
                'url_qrcode' => isset($response['url_qrcode']) ? $response['url_qrcode'] : '',
            );
        } catch (Exception $e) {
            echo 'errcode:' . $e->getCode() . ',errmsg:' . $e->getMessage();
            exit();
        }
    }

    public function new_xunhu_pay($order_info, $pay_type) {
        require_once get_template_directory() . '/inc/plugins/xunhupay/xhpay.class.php';

        $pay_config = ($pay_type === 'alipay') ? $this->XhAliPayConfig : $this->XhWxPayConfig;
        $xhpay      = new Xhpay($pay_config);
        $notify_path = ($pay_type === 'alipay') ? '/xhpay/notify_ali' : '/xhpay/notify_wx';

        $data = array(
            'body'         => $order_info['order_name'],
            'total_fee'    => $order_info['pay_price'] * 100,
            'out_trade_no' => $order_info['order_trade_no'],
            'type'         => $pay_type,
            'notify_url'   => $this->shop_notify_uri . $notify_path,
        );

        $res = array('h5' => '', 'qrcode' => '');

        if ($pay_type === 'wechat' && wp_is_mobile() && !is_weixin_visit()) {
            $data['wap_url']  = home_url();
            $data['wap_name'] = get_bloginfo('name');
            try {
                $result = $xhpay->h5($data);
                if (empty($result['return_code']) || $result['return_code'] !== 'SUCCESS') {
                    $err_msg  = isset($result['err_msg']) ? $result['err_msg'] : 'Network Error';
                    $err_code = isset($result['err_code']) ? (int) $result['err_code'] : 0;
                    throw new Exception($err_msg, $err_code);
                }
                $res['h5'] = $result['mweb_url'];
                return $res;
            } catch (Exception $e) {
                echo $e->getMessage();
                exit();
            }
        }

        if (wp_is_mobile()) {
            $return_url = add_query_arg(
                array(
                    'pay_type'     => $pay_type,
                    'out_trade_no' => $order_info['order_trade_no'],
                ),
                $this->shop_notify_uri . '/xhpay/return'
            );
            $data['redirect_url'] = $return_url;
            $res['h5']            = $xhpay->cashier($data);
            return $res;
        }

        try {
            $result = $xhpay->native($data);
            if (empty($result) || (isset($result['return_code']) && $result['return_code'] !== 'SUCCESS')) {
                $err_msg  = isset($result['err_msg']) ? $result['err_msg'] : 'Network Error';
                $err_code = isset($result['err_code']) ? (int) $result['err_code'] : 0;
                throw new Exception($err_msg, $err_code);
            }
            $res['qrcode'] = $result['code_url'];
            return $res;
        } catch (Exception $e) {
            echo $e->getMessage();
            exit();
        }
    }

    public function epay_pay($order_info, $pay_type) {
        require_once get_template_directory() . '/inc/plugins/epay/EpayCore.class.php';

        $config_key = ($pay_type === 'wxpay') ? 'epay_weixin' : 'epay_alipay';
        $config     = (array) _cao($config_key);
        $pay_config = array_merge(
            array('pid' => '', 'key' => '', 'apiurl' => ''),
            $config
        );

        $device = wp_is_mobile() ? 'mobile' : 'pc';

        $data = array(
            'pid'          => $pay_config['pid'],
            'type'         => $pay_type,
            'out_trade_no' => $order_info['order_trade_no'],
            'name'         => $order_info['order_name'],
            'money'        => $order_info['pay_price'],
            'clientip'     => get_ip_address(),
            'device'       => $device,
            'notify_url'   => $this->shop_notify_uri . '/epay/notify',
            'return_url'   => $this->shop_notify_uri . '/epay/return',
        );

        $epay = new EpayCore($pay_config);
        return $epay->getPayLink($data);
    }

    public function paypal_pay($order_info) {
        require_once get_template_directory() . '/inc/plugins/paypal/paypal.php';
        require_once get_template_directory() . '/inc/plugins/paypal/httprequest.php';

        $pay_config = (array) _cao('paypal');
        $rates      = !empty($pay_config['rates']) ? (float) $pay_config['rates'] : 1.0;
        $currency   = !empty($pay_config['currency']) ? $pay_config['currency'] : 'USD';

        $config = array_merge(
            $pay_config,
            array(
                'return' => $this->shop_notify_uri . '/paypal/return',
                'cancel' => $this->shop_notify_uri . '/paypal/cancel',
            )
        );

        $amount  = $order_info['pay_price'] * $rates;
        $desc    = $order_info['order_name'];
        $invoice = $order_info['order_trade_no'];

        $paypal = new PayPal($config);
        $result = $paypal->doExpressCheckout($amount, $desc, $invoice, $currency);

        if (is_string($result)) {
            return $result;
        }

        if (is_array($result)) {
            $ack = isset($result['ACK']) ? $result['ACK'] : '';
            $short = isset($result['L_SHORTMESSAGE0']) ? $result['L_SHORTMESSAGE0'] : '';
            $long  = isset($result['L_LONGMESSAGE0']) ? $result['L_LONGMESSAGE0'] : '';
            $code  = isset($result['L_ERRORCODE0']) ? $result['L_ERRORCODE0'] : '';
            printf('Error：%s，%s：%s', $ack, $short, $long);
            exit();
        }

        return $result;
    }
}
