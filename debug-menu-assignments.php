<?php
/**
 * Debug Menu Assignments
 * 
 * Usage: /wp-content/plugins/woocommerce-product-menu/debug-menu-assignments.php?product_id=123
 */

// Load WordPress
$wp_load_paths = [
    __DIR__ . '/../../../wp-load.php',
    __DIR__ . '/../../../../wp-load.php',
    __DIR__ . '/../../../../../wp-load.php'
];

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded) {
    die('Error: Could not load WordPress.');
}

// Check if user is logged in and has appropriate permissions
if (!current_user_can('manage_options')) {
    die('Error: You must be logged in as an administrator to use this debug tool.');
}

// Get product ID from URL parameter
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

// HTML header
?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug Menu Assignments</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        h1, h2, h3 {
            color: #333;
        }
        .section {
            margin-bottom: 30px;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 3px;
        }
        pre {
            background-color: #f4f4f4;
            padding: 10px;
            border-radius: 3px;
            overflow-x: auto;
        }
        .error {
            color: #d9534f;
            background-color: #f2dede;
            padding: 10px;
            border-radius: 3px;
            margin-bottom: 20px;
        }
        .success {
            color: #3c763d;
            background-color: #dff0d8;
            padding: 10px;
            border-radius: 3px;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f4f4f4;
            font-weight: bold;
        }
        .form-section {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 3px;
        }
        input[type="number"] {
            padding: 5px;
            margin-right: 10px;
        }
        button {
            padding: 5px 15px;
            background-color: #0073aa;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        button:hover {
            background-color: #005a87;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Debug Menu Assignments</h1>
        
        <div class="form-section">
            <form method="get">
                <label for="product_id">Product ID:</label>
                <input type="number" id="product_id" name="product_id" value="<?php echo esc_attr($product_id); ?>" required>
                <button type="submit">Debug Product</button>
            </form>
        </div>

        <?php if ($product_id > 0): ?>
            <?php
            // Get product
            $product = wc_get_product($product_id);
            
            if (!$product) {
                echo '<div class="error">Error: Product with ID ' . $product_id . ' not found.</div>';
            } else {
                ?>
                <div class="success">
                    <strong>Product Found:</strong> <?php echo esc_html($product->get_name()); ?> (ID: <?php echo $product_id; ?>)
                </div>

                <!-- Menu Assignments Data -->
                <div class="section">
                    <h2>Menu Assignments Data (_menu_assignments_data)</h2>
                    <?php
                    $menu_assignments_data = get_post_meta($product_id, '_menu_assignments_data', true);
                    
                    if (empty($menu_assignments_data)) {
                        echo '<p>No menu assignments data found for this product.</p>';
                    } else {
                        echo '<h3>Raw Data:</h3>';
                        echo '<pre>' . esc_html(print_r($menu_assignments_data, true)) . '</pre>';
                        
                        echo '<h3>Formatted Data:</h3>';
                        if (is_array($menu_assignments_data)) {
                            echo '<table>';
                            echo '<tr><th>Key</th><th>Value</th><th>Type</th></tr>';
                            foreach ($menu_assignments_data as $key => $value) {
                                echo '<tr>';
                                echo '<td>' . esc_html($key) . '</td>';
                                echo '<td>';
                                if (is_array($value)) {
                                    echo '<pre>' . esc_html(print_r($value, true)) . '</pre>';
                                } else {
                                    echo esc_html($value);
                                }
                                echo '</td>';
                                echo '<td>' . gettype($value) . '</td>';
                                echo '</tr>';
                            }
                            echo '</table>';
                        }
                    }
                    ?>
                </div>

                <!-- Taxonomy Terms -->
                <div class="section">
                    <h2>Assigned Taxonomy Terms</h2>
                    <?php
                    $taxonomies = ['program_menu', 'week_no', 'weekday', 'mealtime'];
                    
                    foreach ($taxonomies as $taxonomy) {
                        echo '<h3>' . ucfirst(str_replace('_', ' ', $taxonomy)) . ' (' . $taxonomy . ')</h3>';
                        
                        // Check if taxonomy exists
                        if (!taxonomy_exists($taxonomy)) {
                            echo '<p class="error">Taxonomy "' . $taxonomy . '" does not exist!</p>';
                            continue;
                        }
                        
                        // Get terms for this product
                        $terms = wp_get_object_terms($product_id, $taxonomy, array('fields' => 'all'));
                        
                        if (is_wp_error($terms)) {
                            echo '<p class="error">Error retrieving terms: ' . $terms->get_error_message() . '</p>';
                        } elseif (empty($terms)) {
                            echo '<p>No terms assigned.</p>';
                        } else {
                            echo '<table>';
                            echo '<tr><th>Term ID</th><th>Name</th><th>Slug</th><th>Description</th></tr>';
                            foreach ($terms as $term) {
                                echo '<tr>';
                                echo '<td>' . $term->term_id . '</td>';
                                echo '<td>' . esc_html($term->name) . '</td>';
                                echo '<td>' . esc_html($term->slug) . '</td>';
                                echo '<td>' . esc_html($term->description) . '</td>';
                                echo '</tr>';
                            }
                            echo '</table>';
                        }
                        
                        // Also show term IDs array
                        $term_ids = wp_get_object_terms($product_id, $taxonomy, array('fields' => 'ids'));
                        if (!empty($term_ids)) {
                            echo '<p><strong>Term IDs:</strong> ' . implode(', ', $term_ids) . '</p>';
                        }
                    }
                    ?>
                </div>

                <!-- All Post Meta -->
                <div class="section">
                    <h2>All Product Meta Data</h2>
                    <p>Showing all meta keys that contain "menu" in their name:</p>
                    <?php
                    $all_meta = get_post_meta($product_id);
                    $menu_related_meta = array();
                    
                    foreach ($all_meta as $meta_key => $meta_values) {
                        if (stripos($meta_key, 'menu') !== false) {
                            $menu_related_meta[$meta_key] = $meta_values;
                        }
                    }
                    
                    if (empty($menu_related_meta)) {
                        echo '<p>No menu-related meta data found.</p>';
                    } else {
                        echo '<table>';
                        echo '<tr><th>Meta Key</th><th>Meta Value(s)</th></tr>';
                        foreach ($menu_related_meta as $meta_key => $meta_values) {
                            echo '<tr>';
                            echo '<td>' . esc_html($meta_key) . '</td>';
                            echo '<td>';
                            foreach ($meta_values as $value) {
                                if (is_serialized($value)) {
                                    $unserialized = maybe_unserialize($value);
                                    echo '<pre>' . esc_html(print_r($unserialized, true)) . '</pre>';
                                } else {
                                    echo esc_html($value) . '<br>';
                                }
                            }
                            echo '</td>';
                            echo '</tr>';
                        }
                        echo '</table>';
                    }
                    ?>
                </div>

                <!-- Debug Information -->
                <div class="section">
                    <h2>Debug Information</h2>
                    <ul>
                        <li><strong>Product Type:</strong> <?php echo $product->get_type(); ?></li>
                        <li><strong>Product Status:</strong> <?php echo $product->get_status(); ?></li>
                        <li><strong>Product SKU:</strong> <?php echo $product->get_sku() ?: 'Not set'; ?></li>
                        <li><strong>WordPress Version:</strong> <?php echo get_bloginfo('version'); ?></li>
                        <li><strong>WooCommerce Version:</strong> <?php echo defined('WC_VERSION') ? WC_VERSION : 'Not detected'; ?></li>
                        <li><strong>Current User:</strong> <?php $current_user = wp_get_current_user(); echo $current_user->user_login; ?></li>
                    </ul>
                </div>
            <?php } ?>
        <?php else: ?>
            <p>Please enter a product ID to debug.</p>
        <?php endif; ?>
    </div>
</body>
</html>