<?php

/**
 * 内置cookie方法 2023 12-10 已修复包括js能够获取
 */
class ZB_Cookie {

    public static $name = '_zb_'; //cooke前缀

    public static function get($key, $default = null) {
        $name = self::$name . $key;

        if (isset($_COOKIE[$name])) {
            return $_COOKIE[$name];
        }

        return $default;
    }

    public static function set($key, $var, $expire = null) {
        $name           = self::$name . $key;
        $_COOKIE[$name] = $var; //当前全局赋值

        if (empty($expire)) {
            $expire = time() + 3600 * 24 * 30; //30天效期
        } else {
            $expire = time() + $expire; //一年有效期
        }

        $path = '/';
        $domain = parse_url(get_home_url(), PHP_URL_HOST);

        return setcookie($name, $var, $expire, $path, $domain ,false);
    }

}



/**
 * ZB_Code 加密解密字符串
 */
class ZB_Code {

    /**
     * helper function to encrypt author name
     * 修复：替换为AES-128-CBC，增加异常处理，规范密钥/IV逻辑
     */
    public static function encid($id) {
        // 1. 输入校验：确保ID是整数
        $intId = intval($id);
        if ($intId <= 0) {
            throw new \InvalidArgumentException("加密ID必须是正整数");
        }

        // 2. 生成规范密钥（AES-128固定16字节）
        $keySource = md5(md5(home_url()));
        $aesKey = substr($keySource, 0, 16); // AES-128要求16字节密钥

        // 3. 生成随机IV（CBC模式必需，AES-128-CBC固定16字节）
        $iv = openssl_random_pseudo_bytes(16);
        if ($iv === false) {
            throw new \RuntimeException("生成加密向量IV失败");
        }

        // 4. 转换ID为36进制字符串（保留原逻辑）
        $plainText = base_convert($intId, 10, 36);

        // 5. AES-128-CBC加密（OPENSSL_RAW_DATA输出二进制）
        $encrypted = openssl_encrypt(
            $plainText,
            'AES-128-CBC',
            $aesKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        // 6. 异常捕获：加密失败时抛出明确错误
        if ($encrypted === false) {
            $error = openssl_error_string() ?: '未知错误';
            throw new \RuntimeException("ID加密失败: " . $error);
        }

        // 7. 合并IV和加密数据（IV需随加密结果传输，解密时分离）
        $combined = $iv . $encrypted;

        // 8. 转16进制字符串（保留原bin2hex输出格式）
        return bin2hex($combined);
    }

    /**
     * helper function to decrypt author name
     * 修复：配套AES-128-CBC解密，增加异常处理，兼容原输出格式
     */
    public static function decid($encid) {
        // 1. 输入校验：确保加密字符串是16进制格式
        if (!ctype_xdigit($encid)) {
            throw new \InvalidArgumentException("解密字符串必须是16进制格式");
        }

        // 2. 生成和加密一致的密钥
        $keySource = md5(md5(home_url()));
        $aesKey = substr($keySource, 0, 16);

        // 3. 16进制转回二进制数据
        $combined = pack('H*', $encid);
        if ($combined === false || strlen($combined) < 16) {
            throw new \RuntimeException("解密数据格式错误，长度不足");
        }

        // 4. 分离IV（前16字节）和加密数据（剩余部分）
        $iv = substr($combined, 0, 16);
        $encryptedStr = substr($combined, 16);

        // 5. AES-128-CBC解密
        $decrypted = openssl_decrypt(
            $encryptedStr,
            'AES-128-CBC',
            $aesKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        // 6. 异常捕获：解密失败时抛出明确错误
        if ($decrypted === false) {
            $error = openssl_error_string() ?: '未知错误';
            throw new \RuntimeException("ID解密失败: " . $error);
        }

        // 7. 36进制转回10进制整数（保留原逻辑）
        $decId = base_convert($decrypted, 36, 10);
        return intval($decId); // 确保返回整数类型
    }

    /**
     * 加密数据（AES-128-CBC，兼容PHP7.4+/OpenSSL3.0+）
     */
    public static function enstr($data) {
        // 获取密钥（AES-128需要16字节密钥）
        $key = _cao('site_security_key', md5(home_url()));
        $aesKey = substr(md5($key), 0, 16); // AES-128固定16字节密钥
        
        // 生成随机IV（CBC模式必须，AES-128-CBC的IV固定16字节）
        $iv = openssl_random_pseudo_bytes(16);
        
        // AES-128-CBC加密（OPENSSL_RAW_DATA输出二进制）
        $encrypted = openssl_encrypt(
            $data,
            'AES-128-CBC',
            $aesKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            $error = openssl_error_string() ?: '未知错误';
            throw new \RuntimeException("加密失败: " . $error);
        }

        // 合并IV和加密数据（IV需要随加密结果一起传输，解密时要用）
        $result = $iv . $encrypted;
        // 转URL安全的base64
        return self::base64ToUrl($result);
    }

    /**
     * 解密数据（配套AES-128-CBC）
     */
    public static function destr($encrypted) {
        $key = _cao('site_security_key', md5(home_url()));
        $aesKey = substr(md5($key), 0, 16);
        


        // 还原URL安全base64为二进制数据
        $data = self::urlToBase64($encrypted);
        
        // 分离IV（前16字节）和加密数据（剩余部分）
        $iv = substr($data, 0, 16);
        $encryptedStr = substr($data, 16);
        
        // AES-128-CBC解密
        $decrypted = openssl_decrypt(
            $encryptedStr,
            'AES-128-CBC',
            $aesKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            return false;
        }

        return $decrypted;
    }

    /**
     * base64 转url
     */
    public static function base64ToUrl($string) {
        // 先对原始数据做标准base64编码，再替换特殊字符
        $data = base64_encode($string);
        $data = str_replace(array('+', '/', '='), array('-', '_', ''), $data);
        return $data;
    }

    /**
     * url 转base64
     */
    public static function urlToBase64($string) {
        // 还原URL安全字符为标准base64字符
        $data = str_replace(array('-', '_'), array('+', '/'), $string);
        // 补全base64缺失的等号（URL转base64时被删除）
        $padding = strlen($data) % 4;
        if ($padding > 0) {
            $data .= str_repeat('=', 4 - $padding);
        }
        // 解码为原始二进制数据
        return base64_decode($data);
    }


}



//推广类 cao_aff_tbl
class ZB_Aff {

    //获取状态
    public static function get_aff_status($status) {
        return zb_get_aff_tbl_status($status);
    }

    public static function get_user_aff_info($user_id) {
        global $wpdb;
        $aff_table   = $wpdb->cao_aff_tbl; //AFF表 a
        $order_table = $wpdb->cao_order_tbl; //order b
        $user_id = absint($user_id);
        $info = [
            'ref_uids'   => array(),
            'leiji'      => 0,
            'ketixian'   => 0,
            'tixianzhon' => 0,
            'yitixian'   => 0,
        ];

        // 累计推广人头
        $sql = $wpdb->get_results($wpdb->prepare("SELECT user_id FROM $wpdb->usermeta WHERE meta_key=%s AND meta_value=%s", 'cao_ref_from', $user_id));
        if (!empty($sql)) {
            $info['ref_uids'] = wp_list_pluck($sql, 'user_id');
        }

        //累计佣金 累计已提现 累计提现中 累计可提现 分组查询
        $q = "SELECT SUM(CONVERT(`a`.aff_rate * `b`.pay_price, DECIMAL(10,2))) AS sum, `a`.status
              FROM {$aff_table} AS `a`
              LEFT JOIN {$order_table} AS `b`
              ON `a`.order_id = `b`.id
              WHERE `a`.aff_uid = $user_id AND `b`.pay_status = 1
              GROUP BY `a`.status";

        // 执行查询并返回结果
        $data = $wpdb->get_results($q, ARRAY_A);

        if (!empty($data)) {
            $status = [0 => 'ketixian', 1 => 'tixianzhon', 2 => 'yitixian'];

            foreach ($data as $item) {
                $key        = $status[$item['status']];
                $info[$key] = sprintf("%.2f", $item['sum']);
            }
        }
        $zong = $info['ketixian'] + $info['tixianzhon'] + $info['yitixian'];
        $info['leiji'] = sprintf("%.2f", $zong);
        
        return $info;

    }

    //添加
    public static function add_aff_log($param = array()) {
        global $wpdb;
        $fields = [
            'order_id'    => 0, //关联订单表id
            'aff_uid'     => 0, //推荐人ID
            'aff_rate'    => 0, //佣金比例
            'create_time' => time(),
            'apply_time'  => 0,
            'comple_time' => 0,
            'note'        => '',
            'status'      => 0, //状态 -1失效 | 0未提现 | 1提现中  2已提现
        ];

        $pre = ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d'];
        foreach ($fields as $key => $value) {
            if (isset($param[$key])) {
                $fields[$key] = $param[$key];
            } else {
                $fields[$key] = $value;
            }
        }
        return $wpdb->insert($wpdb->cao_aff_tbl, $fields, $pre);
    }

    //更新
    public static function update_aff_log($data = array(), $where = array(), $pre_data = array(), $pre_where = array()) {
        global $wpdb;
        return $wpdb->update($wpdb->cao_aff_tbl, $data, $where, $pre_data, $pre_where);
    }

    //删除
    public static function delete_aff_log($where = array()) {
        global $wpdb;
        return $wpdb->delete($wpdb->cao_aff_tbl, $where);
    }

}


//下载类 cao_down_tbl
class ZB_Down {

    //添加
    public static function add_down_log($param = array()) {
        global $wpdb;
        $fields = [
            'user_id'     => 0,
            'post_id'     => 0,
            'create_time' => time(),
            'ip' => '',
            'note' => '',
        ];

        $pre = ['%d', '%d', '%s', '%s', '%s'];

        foreach ($fields as $key => $value) {
            if (isset($param[$key])) {
                $fields[$key] = $param[$key];
            } else {
                $fields[$key] = $value;
            }
        }
        return $wpdb->insert($wpdb->cao_down_tbl, $fields, $pre);
    }

    //更新
    public static function update_down_log($data = array(), $where = array(), $pre_data = array(), $pre_where = array()) {
        global $wpdb;
        return $wpdb->update($wpdb->cao_down_tbl, $data, $where, $pre_data, $pre_where);
    }

    //删除
    public static function delete_down_log($where = array()) {
        global $wpdb;
        return $wpdb->delete($wpdb->cao_down_tbl, $where);
    }

    //获取用户今日下载次数 排除已经支付过的订单不统计
    public static function get_user_today_down_num($user_id) {
        global $wpdb;
        $today_time = get_today_time_range();

        $cache_key = 'user_today_down_num_' . $user_id;
        // wp_cache_set($cache_key, 0);
        $result = wp_cache_get($cache_key);

        if (false === $result) {
            $select = "SELECT count(a.post_id) as count FROM {$wpdb->cao_down_tbl} AS a 
                      LEFT JOIN {$wpdb->cao_order_tbl} AS b 
                      ON (a.user_id = b.user_id AND a.post_id = b.post_id) 
                      WHERE a.user_id = %d AND a.create_time BETWEEN %s AND %s AND (b.pay_status IS NULL OR b.pay_status = 0) 
                      GROUP BY a.post_id";
            $result = $wpdb->query($wpdb->prepare($select, $user_id, $today_time['start'], $today_time['end']));
            wp_cache_set($cache_key, $result);
        }

        return (int) $result;
    }

    //今天是否下载过文章
    public static function get_today_post_downnum($user_id, $post_id) {
        global $wpdb;
        $today_time = get_today_time_range();
        $result      = $wpdb->get_var(
            $wpdb->prepare("SELECT count(post_id) FROM {$wpdb->cao_down_tbl} WHERE user_id = %d AND post_id = %d AND create_time BETWEEN %s AND %s ", $user_id, $post_id, $today_time['start'], $today_time['end'])
        );
        return (int) $result;
    }

    //删除用户下载次数缓存
    public static function delete_user_today_down_num_cache($user_id) {
        $cache_key = 'user_today_down_num_' . $user_id;
        wp_cache_delete($cache_key);
    }

    //下载文件输出到浏览器 loac
    public static function local_download_file($file) {

        if (!is_file($file)) {
            return false;
        }

        //Required for IE, otherwise Content-Disposition may be ignored.
        if (ini_get('zlib.output_compression')) {
            @ini_set('zlib.output_compression', 'Off');
        }

        //文件名模式 真实文件名 加密文件名 安全

        if (!empty(_cao('is_site_down_basename', false))) {
            $file_name = rawurlencode(basename($file));
        } else {
            $extension = substr(strrchr($file, '.'), 1);
            $file_name = substr(md5(basename($file)), 8, 16);
            $file_name = rawurlencode($file_name . '.' . $extension);
        }

        //下载文件需要用到的头
        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($file));

        // Read the file in chunks to avoid memory issues
        $handle = fopen($file, 'rb');
        while (!feof($handle)) {
            echo fread($handle, 8192);
            ob_flush();
            flush();
        }
        fclose($handle);exit;
    }

}



//CDK卡密类 cao_cdk_tbl
class ZB_Cdk {

    //获取状态
    public static function get_cdk_status($status) {
        return zb_get_cdk_tbl_status($status);
    }

    //获取卡密类型
    public static function get_cdk_type($type) {
        return zb_get_cdk_tbl_type($type);
    }

    //获取
    public static function get_cdk($code = '') {
        global $wpdb;
        if (empty($code)) {
            return false;
        }

        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->cao_cdk_tbl} WHERE code = %s", $code
            ), OBJECT);

        return $result;

    }

    //添加卡密
    public static function add_cdk($param = array()) {
        global $wpdb;

        $fields = [
            'order_id'    => 0,
            'amount'      => 0,
            'type'        => 0,
            'create_time' => time(),
            'expiry_time' => strtotime('+1 day'),
            'code'        => substr(md5(uniqid()), 5, 10),
            'info'        => '', //卡密信息
            'status'      => 0,
        ];

        $pre = ['%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d'];

        foreach ($fields as $key => $value) {
            if (isset($param[$key])) {
                $fields[$key] = $param[$key];
            } else {
                $fields[$key] = $value;
            }
        }

        //格式化邀请码
        if ($fields['type'] != 1) {
            $fields['amount'] = 0;
        }

        return $wpdb->insert($wpdb->cao_cdk_tbl, $fields, $pre);

    }

    //更新
    public static function update_cdk($data = array(), $where = array(), $pre_data = array(), $pre_where = array()) {
        global $wpdb;
        return $wpdb->update($wpdb->cao_cdk_tbl, $data, $where, $pre_data, $pre_where);
    }

    //删除
    public static function delete_cdk($where = array()) {
        global $wpdb;
        return $wpdb->delete($wpdb->cao_cdk_tbl, $where);
    }
}


//工单类 cao_ticket_tbl
class ZB_Ticket {

    //工单状态
    public static function get_status($status) {
        return zb_get_ticket_tbl_status($status);
    }

    //工单类型
    public static function get_type($type) {
        return zb_get_ticket_tbl_type($type);
    }

    //获取单条
    public static function get($id = '') {
        global $wpdb;
        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->cao_ticket_tbl} WHERE id = %d", intval($id)
            ), OBJECT);

        return $result;

    }

    //添加卡密
    public static function add($param = array()) {
        global $wpdb;
        $fields = [
            'type'          => 0,
            'title'         => '',
            'content'       => '',
            'reply_content' => '',
            'file' => '',
            'reply_file' => '',
            'creator_id'    => 0,
            'assignee_id'   => 0,
            'create_time'   => time(),
            'updated_time'  => '',
            'reply_time'    => '',
            'status'        => 0,
        ];

        $pre = ['%d', '%s', '%s', '%s','%s','%s','%d', '%d', '%s', '%s', '%s', '%d'];

        foreach ($fields as $key => $value) {

            if (isset($param[$key])) {
                $fields[$key] = $param[$key];
            } else {
                $fields[$key] = $value;
            }
        }

        return $wpdb->insert($wpdb->cao_ticket_tbl, $fields, $pre);

    }

    //更新
    public static function update($data = array(), $where = array(), $pre_data = array(), $pre_where = array()) {
        global $wpdb;
        return $wpdb->update($wpdb->cao_ticket_tbl, $data, $where, $pre_data, $pre_where);
    }

    //删除传入id
    public static function delete($id='') {
        global $wpdb;
        // 获取要删除的数据
        $data = self::get($id);

        if (empty($data)) {
            return false;
        }

        //删除附件
        foreach ($data as $key => $value) {
            if (($key == 'file' || $key == 'reply_file') && !empty($value)) {
                // 获取默认上传目录路径
                $upload_dir = wp_upload_dir();
                $ticket_dir = $upload_dir['basedir'] . '/ticket-file'; // 新建ticket目录
                $ticket_uri = $upload_dir['baseurl'] . '/ticket-file'; // ticket目录的URL地址
                $file_path = str_replace($ticket_uri,$ticket_dir, $value);
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
        }

        return $wpdb->delete($wpdb->cao_ticket_tbl, ['id' => $id]);
    }


}
