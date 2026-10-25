<div id="omnisearch" class="omnisearch">
    <div class="container">

    	<?php get_search_form();?>
    	
        <div class="omnisearch-suggestions">
            <div class="search-keywords">
                <?php 

                $args = array( 'taxonomy' => array('post_tag','category'),'number'=> 18,'orderby'=>'name','smallest'=>14,'largest'=>14,'unit'=>'px',); 

                wp_tag_cloud($args);
                
                ?>
            </div>
            <?php do_action('ripro_v2_ads', 'ad_omnisearch_bottum'); ?>
        </div>
    </div>
</div>
