<?php
$site_footer_rollbar = _cao('site_footer_rollbar', array());
$site_footer_rollbar = apply_filters('ripro_footer_rollbar_items', $site_footer_rollbar);
if (!empty($site_footer_rollbar) && is_array($site_footer_rollbar)) : ?>
	<div class="rollbar notranslate" translate="no">
		<ul class="actions">
			<?php foreach ($site_footer_rollbar as $item) :
				if (!is_array($item) || empty($item['href'])) {
					continue;
				}
				$target = empty($item['is_blank']) ? '' : '_blank';
				$icon   = !empty($item['icon']) ? $item['icon'] : 'fas fa-link';
				$title  = isset($item['title']) ? $item['title'] : '';
				printf(
					'<li><a target="%s" href="%s" rel="nofollow noopener noreferrer"><i class="%s" aria-hidden="true"></i><span>%s</span></a></li>',
					esc_attr($target),
					esc_url($item['href']),
					esc_attr($icon),
					esc_html($title)
				);
			endforeach; ?>
		</ul>
	</div>
<?php endif;
