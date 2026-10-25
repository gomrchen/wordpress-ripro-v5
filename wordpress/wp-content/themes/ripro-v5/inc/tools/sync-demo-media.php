<?php
/**
 * 从官方演示 XML 恢复文章正文里的外链图片（导入时被替换成 thumb.jpg 的帖子）
 *
 *   docker compose exec -T wordpress php wp-content/themes/ripro-v5/inc/tools/sync-demo-media.php
 */
if (php_sapi_name() !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 5);
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

@set_time_limit(0);
@ini_set('memory_limit', '512M');

const RIPRO_DEMO_XML_URL = 'https://ripro.rizhuti.com/ripro-demo-file/ripro-v5-content.xml';

/**
 * @return string|WP_Error
 */
function ripro_v5_demo_xml_path() {
    $upload = wp_upload_dir();
    $dir    = $upload['basedir'] . '/ripro-demo-cache';
    if (!wp_mkdir_p($dir)) {
        return new WP_Error('mkdir', 'cannot create cache dir');
    }
    $path = $dir . '/ripro-v5-content.xml';
    if (is_file($path) && filesize($path) > 100000) {
        return $path;
    }
    $raw = wp_remote_get(RIPRO_DEMO_XML_URL, array('timeout' => 120));
    if (is_wp_error($raw)) {
        return $raw;
    }
    $body = wp_remote_retrieve_body($raw);
    if ($body === '') {
        return new WP_Error('xml', 'empty demo xml');
    }
    file_put_contents($path, $body);
    return $path;
}

/**
 * @return array{by_slug: array<string, string>, by_title: array<string, string>}
 */
function ripro_v5_parse_demo_post_contents($xml_path) {
    $xml = file_get_contents($xml_path);
    if ($xml === false) {
        return array('by_slug' => array(), 'by_title' => array());
    }
    $by_slug  = array();
    $by_title = array();
    if (!preg_match_all('/<item>(.*?)<\/item>/s', $xml, $blocks)) {
        return array('by_slug' => $by_slug, 'by_title' => $by_title);
    }
    foreach ($blocks[1] as $block) {
        if (strpos($block, '<wp:post_type><![CDATA[post]]></wp:post_type>') === false) {
            continue;
        }
        if (!preg_match('/<wp:post_name><!\[CDATA\[(.*?)\]\]><\/wp:post_name>/', $block, $name_m)) {
            continue;
        }
        if (!preg_match('/<title><!\[CDATA\[(.*?)\]\]><\/title>/', $block, $title_m)) {
            continue;
        }
        if (!preg_match('/<content:encoded><!\[CDATA\[(.*)\]\]><\/content:encoded>/s', $block, $content_m)) {
            continue;
        }
        $slug    = $name_m[1];
        $content = $content_m[1];
        $by_slug[$slug] = $content;
        $by_slug[rawurldecode($slug)] = $content;
        $title = trim($title_m[1]);
        if ($title !== '') {
            $by_title[$title] = $content;
        }
    }
    return array('by_slug' => $by_slug, 'by_title' => $by_title);
}

function ripro_v5_sync_demo_post_media() {
    $xml_path = ripro_v5_demo_xml_path();
    if (is_wp_error($xml_path)) {
        return $xml_path;
    }
    $parsed = ripro_v5_parse_demo_post_contents($xml_path);
    $by_slug  = $parsed['by_slug'];
    $by_title = $parsed['by_title'];
    if (empty($by_slug) && empty($by_title)) {
        return new WP_Error('parse', 'no post contents parsed from xml');
    }

    global $wpdb;
    $posts = $wpdb->get_results(
        "SELECT ID, post_name, post_title, post_content FROM {$wpdb->posts}
         WHERE post_type = 'post' AND post_status = 'publish'"
    );

    $updated   = 0;
    $skipped   = 0;
    $no_demo   = 0;
    $thumb_tag = 'assets/img/thumb.jpg';

    foreach ($posts as $post) {
        $new_content = null;
        $slug        = $post->post_name;
        if (isset($by_slug[$slug])) {
            $new_content = $by_slug[$slug];
        } elseif (isset($by_slug[rawurldecode($slug)])) {
            $new_content = $by_slug[rawurldecode($slug)];
        } elseif (isset($by_title[$post->post_title])) {
            $new_content = $by_title[$post->post_title];
        } else {
            $no_demo++;
            continue;
        }
        if ($new_content === '' || $new_content === $post->post_content) {
            $skipped++;
            continue;
        }
        $needs_restore = (strpos($post->post_content, $thumb_tag) !== false)
            || (strpos($post->post_content, '<img') === false && strpos($new_content, '<img') !== false);
        if (!$needs_restore) {
            $skipped++;
            continue;
        }
        $wpdb->update(
            $wpdb->posts,
            array('post_content' => $new_content),
            array('ID' => (int) $post->ID),
            array('%s'),
            array('%d')
        );
        $updated++;
    }

    return array(
        'demo_posts_in_xml' => count(array_unique($by_slug)),
        'local_posts'       => count($posts),
        'updated'           => $updated,
        'skipped'           => $skipped,
        'no_demo_match'     => $no_demo,
    );
}

/**
 * 从官网首页分类盒抓取 bg-image（外链 CDN，与演示站一致）
 */
function ripro_v5_sync_category_bg_from_official() {
    $raw = wp_remote_get('https://ripro.rizhuti.com/', array('timeout' => 60));
    if (is_wp_error($raw)) {
        return $raw;
    }
    $html = wp_remote_retrieve_body($raw);
    if ($html === '') {
        return new WP_Error('html', 'empty homepage');
    }
    if (!preg_match_all(
        '/<div class="widget-catbox-item[^"]*"[^>]*data-bg="([^"]+)"[^>]*>.*?<h3 class="catbox-title">([^<]+)/s',
        $html,
        $matches,
        PREG_SET_ORDER
    )) {
        return new WP_Error('parse', 'no catbox on official homepage');
    }
    $updated = 0;
    foreach ($matches as $m) {
        $bg    = $m[1];
        $name  = trim($m[2]);
        $term  = get_term_by('name', $name, 'category');
        if (!$term) {
            continue;
        }
        update_term_meta((int) $term->term_id, 'bg-image', esc_url_raw($bg));
        $updated++;
    }
    return array('category_bg_updated' => $updated);
}

$post_result = ripro_v5_sync_demo_post_media();
if (is_wp_error($post_result)) {
    fwrite(STDERR, $post_result->get_error_message() . "\n");
    exit(1);
}
$cat_result = ripro_v5_sync_category_bg_from_official();
if (is_wp_error($cat_result)) {
    fwrite(STDERR, $cat_result->get_error_message() . "\n");
    exit(1);
}
echo "DEMO_MEDIA_SYNC_OK\n";
echo json_encode(array_merge($post_result, $cat_result), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
