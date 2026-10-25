<?php

$post_id = get_the_ID();
$user_id = get_current_user_id();

$content = $args['content'];
$atts = shortcode_atts(array(
   'title' => '自定义标题',
), $args['atts']);


?>

<div class="ri-accordions-shortcode accordion" id="accordionPanelsStayOpenExample">
	<div class="accordion-item">
	    <h5 class="accordion-header">
	      <button class="accordion-button collapsed" type="button"><?php echo esc_html( $atts['title'] );?></button>
	    </h5>
	    <div class="accordion-collapse collapse" >
	      <div class="accordion-body">
	        <?php echo wp_kses_post( $content );?>
	      </div>
	    </div>
	</div>
</div>
