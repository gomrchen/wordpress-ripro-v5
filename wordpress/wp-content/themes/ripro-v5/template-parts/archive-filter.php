<?php

// 自定义筛选
if (!is_category() || !is_site_term_filter()) {
    return;
}

$taxonomy_name = 'category';
$curr_term = get_queried_object();
if (!$curr_term || !isset($curr_term->term_id)) {
    return;
}

$curr_term_id = (int) $curr_term->term_id; // 获取当前分类ID
$top_term_id  = zb_get_term_top_id($curr_term_id, $taxonomy_name); // 获取当前分类顶级分类ID
$filter_config = get_cat_filter_config($top_term_id); // 获取当前顶级分类筛选配置

if (empty($filter_config)) {
    return;
}

$parent_ids = array($curr_term_id); // 子分类ID集
if (!empty($filter_config['is_child_cat'])) {
    $parent_id = isset($curr_term->parent) ? (int) $curr_term->parent : 0; // 是否有父ID

    while ($parent_id) {
        $parent_term = get_term($parent_id, $taxonomy_name);
        if (!$parent_term || is_wp_error($parent_term)) {
            break;
        }

        $parent_ids[] = (int) $parent_id;
        $parent_id = (int) $parent_term->parent;
    }
}

$parent_ids = array_values(array_unique($parent_ids));
$parent_ids_lookup = array_fill_keys($parent_ids, true);
?>

<div class="filter-warp">
<div class="container">

<?php
// 一级分类
$top_cats = !empty($filter_config['top_cats']) ? array_map('intval', (array) $filter_config['top_cats']) : array();
$top_terms = array();
if (!empty($top_cats)) {
    $top_terms = get_terms(array(
        'taxonomy'   => $taxonomy_name,
        'hide_empty' => false,
        'include'    => $top_cats,
        'orderby'    => 'include',
    ));
}

if (!empty($top_terms) && !is_wp_error($top_terms)) : ?>
<ul class="filter-item">
    <li class="filter-name"><i class="fas fa-layer-group me-1"></i><?php _e('分类', 'ripro'); ?></li>
    <?php foreach ($top_terms as $item) : ?>
        <li class="filter-link <?php echo isset($parent_ids_lookup[(int) $item->term_id]) ? 'active' : ''; ?>">
            <a href="<?php echo esc_url(get_term_link($item->term_id, $taxonomy_name)); ?>"><?php echo esc_html($item->name); ?></a>
        </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php
// 子分类筛选 自动显示
$children_filter = array_reverse($parent_ids);

foreach ($children_filter as $parent_id) {
    $term = get_term($parent_id, $taxonomy_name);
    if (!$term || is_wp_error($term)) {
        continue;
    }

    $children_terms = get_terms(array(
        'taxonomy'   => $taxonomy_name,
        'hide_empty' => false,
        'parent'     => $parent_id,
        'orderby'    => !empty($filter_config['child_cat_orderby']) ? $filter_config['child_cat_orderby'] : 'name',
        'order'      => !empty($filter_config['child_cat_order']) ? $filter_config['child_cat_order'] : 'ASC',
    ));

    if (empty($children_terms) || is_wp_error($children_terms)) {
        continue;
    }
    ?>
    <ul class="filter-item">
        <li class="filter-name"><i class="fas fa-layer-group me-1"></i><?php echo esc_html($term->name); ?></li>
        <li class="filter-link <?php echo $curr_term_id === (int) $term->term_id ? 'active' : ''; ?>">
            <a href="<?php echo esc_url(get_term_link($term->term_id, $taxonomy_name)); ?>"><?php echo esc_html__('全部', 'ripro'); ?></a>
        </li>
        <?php foreach ($children_terms as $item) : ?>
            <li class="filter-link <?php echo isset($parent_ids_lookup[(int) $item->term_id]) ? 'active' : ''; ?>">
                <a href="<?php echo esc_url(get_term_link($item->term_id, $taxonomy_name)); ?>"><?php echo esc_html($item->name); ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
}

// 自定义字段分类法筛选 custom_taxonomy
if (!empty($filter_config['custom_taxonomy']) && is_array($filter_config['custom_taxonomy'])) {
    $custom_taxonomy = get_site_custom_taxonomy(); // 获取自定义字段配置
    foreach ($filter_config['custom_taxonomy'] as $taxonomy_name) {
        if (empty($custom_taxonomy[$taxonomy_name]['name'])) {
            continue;
        }

        $term_name = $custom_taxonomy[$taxonomy_name]['name'];
        $terms = get_terms(array(
            'taxonomy'   => $taxonomy_name,
            'hide_empty' => false,
            'orderby'    => 'none',
        ));

        if (empty($terms) || is_wp_error($terms)) {
            continue;
        }
        ?>
        <ul class="filter-item">
            <li class="filter-name"><i class="fas fa-filter me-1"></i><?php echo esc_html($term_name); ?></li>
            <li class="filter-link <?php echo null === get_param($taxonomy_name, null, 'get') ? 'active' : ''; ?>">
                <a href="<?php echo esc_url(remove_query_arg($taxonomy_name)); ?>"><?php echo esc_html__('全部', 'ripro'); ?></a>
            </li>
            <?php foreach ($terms as $item) : ?>
                <li class="filter-link <?php echo urldecode((string) get_query_var($taxonomy_name)) === urldecode($item->slug) ? 'active' : ''; ?>">
                    <a href="<?php echo esc_url(add_query_arg($taxonomy_name, $item->slug)); ?>"><?php echo esc_html($item->name); ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }
}

// 价格权限
if (is_site_shop() && !empty($filter_config['is_price'])) {
    $site_vip_options = get_site_vip_options();
    $priceOptions = array(
        'all'          => __('全部', 'ripro'),
        'free'         => __('免费', 'ripro'),
        'vip_free'     => sprintf(__('%s免费', 'ripro'), $site_vip_options['vip']['name']),
        'vip_rate'     => sprintf(__('%s折扣', 'ripro'), $site_vip_options['vip']['name']),
        'vip_only'     => sprintf(__('%s专属', 'ripro'), $site_vip_options['vip']['name']),
        'boosvip_free' => sprintf(__('%s免费', 'ripro'), $site_vip_options['boosvip']['name']),
    );

    $priceKey = 'price';
    ?>
    <ul class="filter-item">
        <li class="filter-name"><i class="<?php echo esc_attr(get_site_coin_icon()); ?> me-1"></i><?php echo esc_html__('价格', 'ripro'); ?></li>
        <?php foreach ($priceOptions as $key => $name) : ?>
            <li class="filter-link <?php echo urldecode((string) get_param($priceKey, 'all', 'get')) === urldecode($key) ? 'active' : ''; ?>">
                <a href="<?php echo esc_url(add_query_arg($priceKey, $key)); ?>"><?php echo esc_html($name); ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
}

// 排序
$orderOptions = array(
    'date'       => __('最新', 'ripro'),
    'views'      => __('热度', 'ripro'),
    'likes'      => __('点赞', 'ripro'),
    'follow_num'  => __('收藏', 'ripro'),
    'modified'   => __('更新', 'ripro'),
    'rand'       => __('随机', 'ripro'),
);
$queryKey = 'orderby';
?>
<ul class="filter-item">
    <li class="filter-name"><i class="fas fa-sort-amount-down-alt me-1"></i><?php echo esc_html__('排序', 'ripro'); ?></li>
    <?php foreach ($orderOptions as $key => $name) : ?>
        <li class="filter-link <?php echo urldecode((string) get_param($queryKey, 'date', 'get')) === urldecode($key) ? 'active' : ''; ?>">
            <a href="<?php echo esc_url(add_query_arg($queryKey, $key)); ?>"><?php echo esc_html($name); ?></a>
        </li>
    <?php endforeach; ?>
</ul>

</div>
</div>
