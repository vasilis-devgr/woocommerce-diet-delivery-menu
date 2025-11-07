<?php
/**
 * Menu Assignments Viewer Class
 * 
 * @package WooCommerce_Product_Menu
 */

if (!defined('ABSPATH')) {
    exit;
}

class WOOPM_Menu_Assignments_Viewer {
    
    public function render() {
        $action = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'view';
        
        ?>
        <div class="wrap woopm-assignments-wrap">
            <h1><?php _e('Menu Assignments', 'woopm'); ?></h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=woopm-menu-assignments&tab=view" class="nav-tab <?php echo $action === 'view' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('View Assignments', 'woopm'); ?>
                </a>
                <a href="?page=woopm-menu-assignments&tab=stats" class="nav-tab <?php echo $action === 'stats' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Statistics', 'woopm'); ?>
                </a>
                <a href="?page=woopm-menu-assignments&tab=export" class="nav-tab <?php echo $action === 'export' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Export', 'woopm'); ?>
                </a>
                <a href="?page=woopm-menu-assignments&tab=tools" class="nav-tab <?php echo $action === 'tools' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Tools', 'woopm'); ?>
                </a>
            </nav>
            
            <div class="tab-content">
                <?php
                switch ($action) {
                    case 'stats':
                        $this->render_statistics();
                        break;
                    case 'export':
                        $this->render_export();
                        break;
                    case 'tools':
                        $this->render_tools();
                        break;
                    default:
                        $this->render_assignments_list();
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render assignments list
     */
    private function render_assignments_list() {
        // Get filter parameters
        $filter_menu = isset($_GET['menu']) ? intval($_GET['menu']) : 0;
        $filter_week = isset($_GET['week']) ? intval($_GET['week']) : 0;
        $filter_day = isset($_GET['day']) ? intval($_GET['day']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Get sorting parameters
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'title';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'asc';
        
        ?>
        <form method="get" class="filter-form">
            <input type="hidden" name="page" value="woopm-menu-assignments">
            <input type="hidden" name="tab" value="view">
            
            <input type="search" name="s" placeholder="<?php esc_attr_e('Search products...', 'woopm'); ?>" value="<?php echo esc_attr($search); ?>">
            
            <select name="menu">
                <option value=""><?php _e('All Menus', 'woopm'); ?></option>
                <?php
                $menus = get_terms(['taxonomy' => 'program_menu', 'hide_empty' => false]);
                foreach ($menus as $menu) {
                    echo '<option value="' . esc_attr($menu->term_id) . '" ' . selected($filter_menu, $menu->term_id, false) . '>';
                    echo esc_html($menu->name);
                    echo '</option>';
                }
                ?>
            </select>
            
            <select name="week">
                <option value=""><?php _e('All Weeks', 'woopm'); ?></option>
                <?php
                $weeks = get_terms(['taxonomy' => 'week_no', 'hide_empty' => false]);
                foreach ($weeks as $week) {
                    echo '<option value="' . esc_attr($week->term_id) . '" ' . selected($filter_week, $week->term_id, false) . '>';
                    echo esc_html($week->name);
                    echo '</option>';
                }
                ?>
            </select>
            
            <select name="day">
                <option value=""><?php _e('All Days', 'woopm'); ?></option>
                <?php
                $days = get_terms(['taxonomy' => 'weekday', 'hide_empty' => false]);
                foreach ($days as $day) {
                    echo '<option value="' . esc_attr($day->term_id) . '" ' . selected($filter_day, $day->term_id, false) . '>';
                    echo esc_html($day->name);
                    echo '</option>';
                }
                ?>
            </select>
            
            <button type="submit" class="button"><?php _e('Filter', 'woopm'); ?></button>
            <a href="?page=woopm-menu-assignments&tab=view" class="button"><?php _e('Clear', 'woopm'); ?></a>
        </form>
        
        <?php
        // Query products - show all without pagination
        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1, // Show all products
            'meta_query' => [
                [
                    'key' => 'menu_assignments',
                    'compare' => 'EXISTS'
                ]
            ]
        ];
        
        if ($search) {
            $args['s'] = $search;
        }
        
        $products = new WP_Query($args);
        
        if ($products->have_posts()) {
            // Collect all products data first for sorting
            $products_data = [];
            while ($products->have_posts()) {
                $products->the_post();
                $product_id = get_the_ID();
                $product = wc_get_product($product_id);
                $assignments = get_field('menu_assignments', $product_id);
                
                // Apply filters
                if ($filter_menu || $filter_week || $filter_day) {
                    $filtered = $this->filter_assignments($assignments, $filter_menu, $filter_week, $filter_day);
                    if (empty($filtered)) continue;
                    $assignments = $filtered;
                }
                
                // Extract unique days from assignments
                $days = [];
                if (is_array($assignments)) {
                    foreach ($assignments as $assignment) {
                        if (!empty($assignment['day'])) {
                            $day_term = get_term($assignment['day'], 'weekday');
                            if ($day_term && !is_wp_error($day_term)) {
                                $days[$day_term->term_id] = $day_term->name;
                            }
                        }
                    }
                }
                
                $products_data[] = [
                    'id' => $product_id,
                    'title' => get_the_title(),
                    'sku' => $product->get_sku(),
                    'assignments' => $assignments,
                    'assignments_count' => is_array($assignments) ? count($assignments) : 0,
                    'days' => $days,
                    'days_display' => implode(', ', $days),
                    'menu_price' => get_field('menu_price', $product_id),
                    'edit_link' => get_edit_post_link($product_id)
                ];
            }
            wp_reset_postdata();
            
            // Sort products data
            $this->sort_products_data($products_data, $orderby, $order);
            
            ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php echo $this->get_sortable_column_header('Product', 'title', $orderby, $order); ?></th>
                        <th><?php echo $this->get_sortable_column_header('SKU', 'sku', $orderby, $order); ?></th>
                        <th><?php echo $this->get_sortable_column_header('Days', 'days', $orderby, $order); ?></th>
                        <th><?php echo $this->get_sortable_column_header('Assignments', 'assignments', $orderby, $order); ?></th>
                        <th><?php echo $this->get_sortable_column_header('Menu Price', 'menu_price', $orderby, $order); ?></th>
                        <th><?php _e('Actions', 'woopm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ($products_data as $product_data) {
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($product_data['title']); ?></strong>
                            </td>
                            <td><?php echo esc_html($product_data['sku']); ?></td>
                            <td><?php echo esc_html($product_data['days_display']); ?></td>
                            <td><?php echo $this->format_assignments($product_data['assignments']); ?></td>
                            <td><?php echo esc_html($product_data['menu_price']); ?></td>
                            <td>
                                <a href="<?php echo $product_data['edit_link']; ?>" class="button button-small">
                                    <?php _e('Edit', 'woopm'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php
                    }
                    ?>
                </tbody>
            </table>
            
            <?php
            // Show total count
            echo '<p>' . sprintf(__('Total products shown: %d', 'woopm'), count($products_data)) . '</p>';
        } else {
            echo '<p>' . __('No products with menu assignments found.', 'woopm') . '</p>';
        }
        
        wp_reset_postdata();
    }
    
    /**
     * Render statistics
     */
    private function render_statistics() {
        global $wpdb;
        
        // Total products with assignments
        $total_products = $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) 
             FROM {$wpdb->postmeta} 
             WHERE meta_key LIKE 'menu_assignments_%'"
        );
        
        ?>
        <div class="woopm-stats-grid">
            <div class="woopm-stat-box">
                <h3><?php _e('Total Products', 'woopm'); ?></h3>
                <div class="woopm-stat-number"><?php echo number_format_i18n($total_products); ?></div>
            </div>
        </div>
        
        <h3><?php _e('Assignments by Menu', 'woopm'); ?></h3>
        <table class="wp-list-table widefat">
            <thead>
                <tr>
                    <th><?php _e('Menu', 'woopm'); ?></th>
                    <th><?php _e('Type', 'woopm'); ?></th>
                    <th><?php _e('Products', 'woopm'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $menus = get_terms(['taxonomy' => 'program_menu', 'hide_empty' => false]);
                foreach ($menus as $menu) {
                    $menu_type = get_term_meta($menu->term_id, 'menu_type', true);
                    $count = $this->count_products_by_menu($menu->term_id);
                    ?>
                    <tr>
                        <td><?php echo esc_html($menu->name); ?></td>
                        <td><?php echo esc_html($menu_type ?: 'Not set'); ?></td>
                        <td><?php echo number_format_i18n($count); ?></td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Render export
     */
    private function render_export() {
        if (isset($_POST['export_csv'])) {
            $this->export_csv();
            return;
        }
        
        ?>
        <h2><?php _e('Export Menu Assignments', 'woopm'); ?></h2>
        <p><?php _e('Export all product menu assignments to CSV format.', 'woopm'); ?></p>
        
        <form method="post">
            <?php wp_nonce_field('woopm_export_csv', 'export_nonce'); ?>
            <button type="submit" name="export_csv" class="button button-primary">
                <?php _e('Export to CSV', 'woopm'); ?>
            </button>
        </form>
        <?php
    }
    
    /**
     * Render tools
     */
    private function render_tools() {
        if (isset($_POST['cleanup_duplicates'])) {
            $this->cleanup_duplicates();
        }
        
        if (isset($_POST['remove_all_assignments'])) {
            $this->remove_all_assignments();
        }
        
        ?>
        <h2><?php _e('Maintenance Tools', 'woopm'); ?></h2>
        
        <form method="post">
            <?php wp_nonce_field('woopm_cleanup', 'cleanup_nonce'); ?>
            
            <h3><?php _e('Remove Duplicate Assignments', 'woopm'); ?></h3>
            <p><?php _e('Remove duplicate menu assignments from products.', 'woopm'); ?></p>
            <button type="submit" name="cleanup_duplicates" class="button">
                <?php _e('Remove Duplicates', 'woopm'); ?>
            </button>
            
            <hr style="margin: 30px 0;">
            
            <h3><?php _e('Remove All Assignments', 'woopm'); ?></h3>
            <p style="color: #d63638;"><strong><?php _e('Warning:', 'woopm'); ?></strong> <?php _e('This will permanently remove ALL menu assignments from ALL products. This action cannot be undone!', 'woopm'); ?></p>
            <button type="submit" name="remove_all_assignments" class="button button-danger" onclick="return confirm('<?php _e('Are you sure you want to remove ALL menu assignments from ALL products? This action cannot be undone!', 'woopm'); ?>');" style="background-color: #dc3232; color: white; border-color: #dc3232;">
                <?php _e('Remove All Assignments', 'woopm'); ?>
            </button>
        </form>
        <?php
    }
    
    /**
     * Filter assignments
     */
    private function filter_assignments($assignments, $menu, $week, $day) {
        if (!is_array($assignments)) return [];
        
        $filtered = [];
        foreach ($assignments as $assignment) {
            $match = true;
            
            if ($menu && $assignment['program_menu'] != $menu) $match = false;
            if ($week && (!isset($assignment['week']) || $assignment['week'] != $week)) $match = false;
            if ($day && $assignment['day'] != $day) $match = false;
            
            if ($match) $filtered[] = $assignment;
        }
        
        return $filtered;
    }
    
    /**
     * Format assignments for display
     */
    private function format_assignments($assignments) {
        if (!is_array($assignments) || empty($assignments)) {
            return '<em>' . __('No assignments', 'woopm') . '</em>';
        }
        
        $output = '<ul class="assignment-list">';
        foreach ($assignments as $assignment) {
            $menu = get_term($assignment['program_menu'], 'program_menu');
            $day = get_term($assignment['day'], 'weekday');
            
            $line = $assignment['menu_type'] . ': ';
            $line .= $menu ? $menu->name : 'Unknown';
            
            if ($assignment['menu_type'] === 'monthly' && !empty($assignment['week'])) {
                $week = get_term($assignment['week'], 'week_no');
                $line .= ' - ' . ($week ? $week->name : 'Unknown');
            }
            
            $line .= ' - ' . ($day ? $day->name : 'Unknown');
            
            if (!empty($assignment['meal'])) {
                $meal = get_term($assignment['meal'], 'mealtime');
                $line .= ' - ' . ($meal ? $meal->name : 'Unknown');
            }
            
            $output .= '<li>' . esc_html($line) . '</li>';
        }
        $output .= '</ul>';
        
        return $output;
    }
    
    /**
     * Count products by menu
     */
    private function count_products_by_menu($menu_id) {
        global $wpdb;
        
        // This is a simplified count - for accurate results, would need to parse serialized data
        $like = '%"program_menu";s:%:"' . $menu_id . '"%';
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) 
             FROM {$wpdb->postmeta} 
             WHERE meta_key = '_menu_assignments_data' 
             AND meta_value LIKE %s",
            $like
        ));
    }
    
    /**
     * Export to CSV
     */
    private function export_csv() {
        if (!wp_verify_nonce($_POST['export_nonce'], 'woopm_export_csv')) {
            return;
        }
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="menu-assignments-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Headers
        fputcsv($output, [
            'Product ID',
            'Product Name',
            'SKU',
            'Menu Type',
            'Menu Name',
            'Week',
            'Day',
            'Meal',
            'Menu Price'
        ]);
        
        // Get all products
        $products = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'menu_assignments',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);
        
        foreach ($products as $product_post) {
            $product = wc_get_product($product_post->ID);
            $assignments = get_field('menu_assignments', $product_post->ID);
            
            if (is_array($assignments)) {
                foreach ($assignments as $assignment) {
                    $menu = get_term($assignment['program_menu'], 'program_menu');
                    $week = !empty($assignment['week']) ? get_term($assignment['week'], 'week_no') : null;
                    $day = get_term($assignment['day'], 'weekday');
                    $meal = !empty($assignment['meal']) ? get_term($assignment['meal'], 'mealtime') : null;
                    
                    fputcsv($output, [
                        $product_post->ID,
                        $product_post->post_title,
                        $product->get_sku(),
                        $assignment['menu_type'],
                        $menu ? $menu->name : '',
                        $week ? $week->name : '',
                        $day ? $day->name : '',
                        $meal ? $meal->name : '',
                        get_field('menu_price', $product_post->ID)
                    ]);
                }
            }
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Cleanup duplicate assignments
     */
    private function cleanup_duplicates() {
        if (!wp_verify_nonce($_POST['cleanup_nonce'], 'woopm_cleanup')) {
            return;
        }
        
        $cleaned = 0;
        
        $products = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'menu_assignments',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);
        
        foreach ($products as $product) {
            $assignments = get_field('menu_assignments', $product->ID);
            if (!is_array($assignments)) continue;
            
            $unique = [];
            $duplicates = 0;
            
            foreach ($assignments as $assignment) {
                $key = md5(serialize($assignment));
                if (!isset($unique[$key])) {
                    $unique[$key] = $assignment;
                } else {
                    $duplicates++;
                }
            }
            
            if ($duplicates > 0) {
                update_field('menu_assignments', array_values($unique), $product->ID);
                $cleaned += $duplicates;
            }
        }
        
        echo '<div class="notice notice-success"><p>';
        printf(__('Removed %d duplicate assignments.', 'woopm'), $cleaned);
        echo '</p></div>';
    }
    
    /**
     * Remove all assignments from all products
     */
    private function remove_all_assignments() {
        if (!wp_verify_nonce($_POST['cleanup_nonce'], 'woopm_cleanup')) {
            return;
        }
        
        global $wpdb;
        
        // Get all products with menu assignments
        $products = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'menu_assignments',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);
        
        $removed = 0;
        $total_assignments = 0;
        
        foreach ($products as $product) {
            $assignments = get_field('menu_assignments', $product->ID);
            if (!empty($assignments) && is_array($assignments)) {
                $total_assignments += count($assignments);
                
                // Delete the ACF field
                delete_field('menu_assignments', $product->ID);
                
                // Delete all related meta fields
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->postmeta} 
                     WHERE post_id = %d 
                     AND meta_key LIKE %s",
                    $product->ID,
                    'menu_assignments_%'
                ));
                
                $removed++;
            }
        }
        
        echo '<div class="notice notice-success"><p>';
        printf(
            __('Successfully removed %d assignments from %d products.', 'woopm'), 
            $total_assignments, 
            $removed
        );
        echo '</p></div>';
    }
    
    /**
     * Get sortable column header
     */
    private function get_sortable_column_header($label, $column, $current_orderby, $current_order) {
        $base_url = admin_url('admin.php?page=woopm-menu-assignments&tab=view');
        
        // Preserve filter parameters
        foreach (['s', 'menu', 'week', 'day'] as $param) {
            if (isset($_GET[$param]) && $_GET[$param] !== '') {
                $base_url = add_query_arg($param, $_GET[$param], $base_url);
            }
        }
        
        // Determine new order
        $new_order = 'asc';
        $arrow = '';
        
        if ($current_orderby === $column) {
            $new_order = ($current_order === 'asc') ? 'desc' : 'asc';
            $arrow = ($current_order === 'asc') ? ' ▲' : ' ▼';
        }
        
        $url = add_query_arg([
            'orderby' => $column,
            'order' => $new_order
        ], $base_url);
        
        return sprintf(
            '<a href="%s">%s%s</a>',
            esc_url($url),
            esc_html__($label, 'woopm'),
            $arrow
        );
    }
    
    /**
     * Sort products data
     */
    private function sort_products_data(&$products_data, $orderby, $order) {
        usort($products_data, function($a, $b) use ($orderby, $order) {
            $result = 0;
            
            switch ($orderby) {
                case 'title':
                    $result = strcasecmp($a['title'], $b['title']);
                    break;
                case 'sku':
                    $result = strcasecmp($a['sku'], $b['sku']);
                    break;
                case 'days':
                    $result = strcasecmp($a['days_display'], $b['days_display']);
                    break;
                case 'assignments':
                    $result = $a['assignments_count'] - $b['assignments_count'];
                    break;
                case 'menu_price':
                    $result = floatval($a['menu_price']) - floatval($b['menu_price']);
                    break;
            }
            
            return ($order === 'desc') ? -$result : $result;
        });
    }
}