<?php

//导航菜单
class ZB_Walker_Nav_Menu extends Walker_Nav_Menu {

    public $db_fields = array('parent' => 'menu_item_parent', 'id' => 'db_id');
    protected $mega;

    public function __construct($mega = false) {
        $this->mega = $mega;
    }

    public function start_lvl(&$output, $depth = 0, $args = array()) {
        $indent = str_repeat("\t", $depth);
        $output .= "\n$indent<ul class=\"sub-menu\">\n";
    }

    public function end_lvl(&$output, $depth = 0, $args = array()) {
        $indent = str_repeat("\t", $depth);
        $output .= "$indent</ul>\n";
    }

    public function start_el(&$output, $item, $depth = 0, $args = array(), $id = 0) {
        $indent = ($depth) ? str_repeat("\t", $depth) : '';

        $classes = empty($item->classes) ? array() : (array) $item->classes;

        $keep_classes = ['menu-item','menu-item-has-children'];
        $classes = array_intersect( $classes, $keep_classes );

        $class_names = join(' ', apply_filters('nav_menu_css_class', array_filter($classes), $item, $args, $depth));
        $class_names = $class_names ? ' class="' . esc_attr($class_names) . '"' : '';

        $id = '';

        $output .= $indent . '<li' . $id . $class_names . '>';

        $atts           = array();
        $atts['title']  = !empty($item->attr_title) ? $item->attr_title : '';
        $atts['target'] = !empty($item->target) ? $item->target : '';
        $atts['rel']    = !empty($item->xfn) ? $item->xfn : '';
        $atts['href']   = !empty($item->url) ? $item->url : '';

        $atts = apply_filters('nav_menu_link_attributes', $atts, $item, $args, $depth);

        $attributes = '';
        foreach ($atts as $attr => $value) {
            if (!empty($value)) {
                $value = ('href' === $attr) ? esc_url($value) : esc_attr($value);
                $attributes .= ' ' . $attr . '="' . $value . '"';
            }
        }

        $nav_icon = get_post_meta($item->ID, 'menu_icon', true);
        if (!empty($nav_icon)) {
            $item->title = '<i class="' . $nav_icon . ' me-1"></i>' . $item->title;
        }

        $item_output = $args->before;
        $item_output .= '<a' . $attributes . '>';
        $item_output .= $args->link_before . apply_filters('the_title', $item->title, $item->ID) . $args->link_after;
        $item_output .= '</a>';
        $item_output .= $args->after;

        $output .= apply_filters('walker_nav_menu_start_el', $item_output, $item, $depth, $args);
    }

    public function end_el(&$output, $item, $depth = 0, $args = array()) {
        $output .= "</li>\n";
    }


    public static function fallback($args) {
        extract($args);

        $fb_output = null;

        if ($container) {
            $fb_output = '<' . $container;

            if ($container_id) {
                $fb_output .= ' id="' . $container_id . '"';
            }

            if ($container_class) {
                $fb_output .= ' class="' . $container_class . '"';
            }

            $fb_output .= '>';
        }

        $fb_output .= '<ul';

        if ($menu_id) {
            $fb_output .= ' id="' . $menu_id . '"';
        }

        if ($menu_class) {
            $fb_output .= ' class="' . $menu_class . '"';
        }

        $fb_output .= '>';
        $fb_output .= '<li class="menu-item"><a href="' . esc_url(admin_url('nav-menus.php')) . '">Add Menus</a></li>';
        $fb_output .= '</ul>';

        if ($container) {
            $fb_output .= '</' . $container . '>';
        }

        echo wp_kses($fb_output, array(
            'ul'   => array('id' => array(), 'class' => array()),
            'li'   => array('class' => array()),
            'a'    => array('href' => array()),
            'span' => array(),
        ));
    }

}

//评论
class ZB_Walker_Comment extends Walker_Comment {

    public function html5_comment( $comment, $depth, $args ) {
        $GLOBALS['comment'] = $comment;

        if ( 'div' == $args['style'] ) {
            $tag = 'div';
            $add_below = 'comment';
        } else {
            $tag = 'li';
            $add_below = 'div-comment';
        }
        $author = get_comment_author();
        
        if( $comment->user_id){
            
            $author = $author.' '.zb_get_user_badge($comment->user_id,'span');
        }else if( $comment->comment_author_url ){
            $author = '<a href="'.esc_url($comment->comment_author_url).'" target="_blank" rel="nofollow">'.$author.'</a>';
        }
        
        ?>
        <<?php echo $tag ?> <?php comment_class( empty( $args['has_children'] ) ? '' : 'parent' ) ?> id="comment-<?php comment_ID() ?>">
        
        <div id="div-comment-<?php comment_ID() ?>" class="comment-inner">
            <div class="comment-author">
                <?php if ( $args['avatar_size'] != 0 ) echo get_avatar( $comment, $args['avatar_size'] ); ?>
            </div>
            <div class="comment-body">
                <div class="nickname"><?php echo $author;?></div>
                <?php if ( $comment->comment_approved == '0' ) : ?>
                    <div class="comment-awaiting-moderation"><?php _e('您的评论正在等待审核。', 'ripro'); ?></div>
                <?php endif; ?>
                <div class="comment-content"><?php comment_text(); ?></div>

                <div class="comment-meta">

                    <span class="comment-time"><?php echo sprintf(__('%s前', 'ripro'),human_time_diff(get_comment_time('U'), current_time('timestamp'))); ?></span>
                    <?php comment_reply_link( array_merge( $args, array( 'add_below' => $add_below, 'depth' => $depth, 'max_depth' => $args['max_depth'] ) ) ); ?>
                </div>

            </div>

            
        </div>
        <?php
    }
}


//微信公众号扫码登录
class Mp_WeChat_Login {
    private $appid;
    private $secret;
    private $token;
    private $cache_time = 300;

    public function __construct($appid, $secret, $token) {
        $this->appid = $appid;
        $this->secret = $secret;
        $this->token = $token;
    }


    // Generate and display the WeChat login QR code
    public function login_qrcode() {
        $ajax_url = esc_url(set_url_scheme(admin_url('admin-ajax.php')));
        $scene_str = md5(uniqid() . mt_rand());
        set_transient("wx_scene_{$scene_str}", 'waiting', $this->cache_time);
    
        // Get the QR code ticket on the server side
        $qrcode_ticket = $this->get_qrcode_ticket($scene_str);
    
        ob_start(); ?>
        <!-- 用bootstrapv5的c演示居中美化登录界面 -->
        <div class="container d-flex justify-content-center align-items-center vh-100">
            <div class="wx-login-box" data-scene="<?= esc_attr($scene_str) ?>" data-ticket="<?= esc_attr($qrcode_ticket) ?>">
                <div class="qrcode-container mb-3"></div>
                <div class="wx-status text-center">使用微信扫码登录</div>
            </div>
        </div>
    
        <script>
        (function($) {
            $(document).ready(function() {
                const scene = $('.wx-login-box').data('scene');
                const ticket = $('.wx-login-box').data('ticket');
                initQrcode(scene, ticket);
            });
    
            function initQrcode(scene, ticket) {
                const qrcodeUrl = `https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket=${ticket}`;
                console.log(scene);
                $('.qrcode-container').html(`<img src="${qrcodeUrl}" style="max-width:240px">`);
                checkLogin(scene);
            }
    
            function checkLogin(scene) {
                $.post('<?= $ajax_url ?>', {
                    action: 'zb_mpweixin_ajax_check_login',
                    scene_str: scene,
                    _ajax_nonce: '<?= wp_create_nonce('check_wxlogin_nonce') ?>'
                }, function(res) {
                    
                    if (res.status == 1) {
                        $('.wx-status').html(res.msg);
                        //返回上一页面并刷新
                        window.location.href = '<?=get_uc_menu_link()?>';
                    } else {
                        setTimeout(() => checkLogin(scene), 3000);
                    }
                    
                });
            }
        })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }

    // Get QR code ticket from WeChat API
    public function get_qrcode_ticket($scene) {
        $access_token = $this->get_access_token();
        $url = "https://api.weixin.qq.com/cgi-bin/qrcode/create?access_token={$access_token}";

        $response = wp_remote_post($url, [
            'body' => json_encode([
                'expire_seconds' => $this->cache_time,
                'action_name' => 'QR_STR_SCENE',
                'action_info' => ['scene' => ['scene_str' => $scene]]
            ])
        ]);

        return json_decode(wp_remote_retrieve_body($response))->ticket ?? '';
    }

    public function create_menu($data){
        $access_token = $this->get_access_token();
        $url = "https://api.weixin.qq.com/cgi-bin/menu/create?access_token=".$access_token;
        
        $response = wp_remote_post($url, [
            'body' => $data
        ]);
        return json_decode(wp_remote_retrieve_body($response));

    }

    // Get access token from WeChat API
    

    private function get_access_token() {
        $cache_key = 'wx_access_token';
        if ($token = get_transient($cache_key)) return $token;
    
        $response = wp_remote_get("https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$this->appid}&secret={$this->secret}");
        $data = json_decode(wp_remote_retrieve_body($response));
    
        // Check if the response contains the expected properties
        if (isset($data->access_token) && isset($data->expires_in)) {
            set_transient($cache_key, $data->access_token, $data->expires_in - 300);
            return $data->access_token;
        } else {
            // Log the error for debugging
            return null; // Return null or handle the error as needed
        }
    }

    // Handle WeChat authentication
    public function handle_wechat_auth() {

        if (isset($_GET['echostr'])) {
            echo $this->verify_signature() ? $_GET['echostr'] : 'fail';
            exit;
        }

        $xml = file_get_contents("php://input");

        if (empty($xml)) {
            die('');
        }

        $data = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        
        // file_put_contents(__DIR__ . '/notify_result.txt', var_export($data, true));
        
        //如果是扫码事件，则将用户信息写入数据库
        if (in_array($data->Event, ['SCAN', 'subscribe'])) {
            $scene = str_replace('qrscene_', '', $data->EventKey);
            set_transient("wx_scene_{$scene}", (string)$data->FromUserName, $this->cache_time);

            $this->reply_text($data->FromUserName, $data->ToUserName, __('扫码登录成功','ripro'));
        }

        //如果是文字消息则根据文字搜索回复文章
        if ($data->MsgType == 'text') {
            $content = $data->Content;
            //根据消息文字搜索wp文章返回
            $args = array(  
                's' => trim($content),
                'posts_per_page' => 5,
                'post_type' => 'post',
                'post_status' => 'publish'
            );
            $PostsData = new WP_Query($args);
            if ($PostsData->have_posts()) {
                $posts = $PostsData->posts;
                $this_reply = '';
                $i = 1;
                foreach ($posts as $post) {
                    // 将标题格式化为可以发送的消息 给前面加上编号
                    $this_reply .= $i.'. <a href="'.get_the_permalink($post->ID).'">'.get_the_title($post->ID).'</a>' . "\n";
                    $i++;
                }

                //查询到文章但是无法成功发送出

                $this->reply_text($data->FromUserName, $data->ToUserName, $this_reply); 
            }else{
                $this->reply_text($data->FromUserName, $data->ToUserName, __('没有找到相关文章','ripro'));
            }

        }

        exit;
    }

    // Verify WeChat signature
    private function verify_signature() {
        $signature = $_GET['signature'] ?? '';
        $timestamp = $_GET['timestamp'] ?? '';
        $nonce = $_GET['nonce'] ?? '';

        $tmpArr = [$this->token, $timestamp, $nonce];
        sort($tmpArr, SORT_STRING);
        return sha1(implode($tmpArr)) === $signature;
    }

    // Reply with a text message to WeChat
    private function reply_text($to, $from, $content) {
        echo "<xml>
        <ToUserName><![CDATA[{$to}]]></ToUserName>
        <FromUserName><![CDATA[{$from}]]></FromUserName>
        <CreateTime>".time()."</CreateTime>
        <MsgType><![CDATA[text]]></MsgType>
        <Content><![CDATA[{$content}]]></Content>
        </xml>";
    }

    // 
}
