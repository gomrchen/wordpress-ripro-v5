<?php 

$site_footer_link = _cao('site_footer_links', array());
$site_footer_link = apply_filters('ripro_footer_friend_links', $site_footer_link);

if (is_home() && !empty($site_footer_link)) : ?>
  <div class="footer-links small d-none d-lg-block mt-2">
    <span><?php _e('友情链接：', 'ripro');?></span>
    <ul>
      <?php foreach ($site_footer_link as $item) :?>
        <?php printf('<li><a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a></li>', esc_url($item['href']), esc_attr($item['title']), esc_html($item['title']));?>
      <?php endforeach;?>
    </ul>
  </div>
<?php endif;?>