<?php

/**
 * PayPal 用户取消支付后的同步回跳
 */

header('Content-type:text/html; Charset=utf-8');

wp_redirect(home_url('/'));
exit;
