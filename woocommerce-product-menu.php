<?php
/*
Plugin Name: WooCommerce Delivery Diet Menu
Description: Πρόσθετο για διαδικτυακή παράδοση διατροφικών μενού
Version: 2.1
Author: Vasilis Papageorgiou
*/

// Αποτροπή άμεσης πρόσβασης
if ( ! defined( 'ABSPATH' ) ) {
    exit; 
}

// Ορισμός σταθερών του plugin
define( 'WOOPM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOOPM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Φόρτωση κλάσεων του plugin
require_once WOOPM_PLUGIN_DIR . 'frontend/includes/class-blue-menu-shortcode.php';
require_once WOOPM_PLUGIN_DIR . 'frontend/includes/class-ajax-handler.php';

// Load menu assignment functions
if ( file_exists( WOOPM_PLUGIN_DIR . 'includes/functions-menu-assignments.php' ) ) {
    require_once WOOPM_PLUGIN_DIR . 'includes/functions-menu-assignments.php';
}

// Αρχικοποίηση plugin
function woopm_init_plugin() {
    new WOOPMMenu_System();
    new WOOPMBlueMenu_Manager();
    new WOOPMAjax_Handler();
    
    // Initialize admin features
    if ( is_admin() ) {
        require_once WOOPM_PLUGIN_DIR . 'admin/includes/class-menu-import-admin.php';
        new WOOPM_Menu_Import_Admin();
    }
}
add_action( 'plugins_loaded', 'woopm_init_plugin' );

// Clear ACF cache on plugin activation/deactivation
function woopm_clear_acf_cache() {
    // Clear ACF field cache
    delete_transient( 'acf_field_groups' );
    delete_option( '_transient_acf_field_groups' );
    delete_option( '_transient_timeout_acf_field_groups' );
    
    // Clear ACF local store
    if ( function_exists( 'acf_reset_local' ) ) {
        acf_reset_local();
    }
    
    // Force ACF to rebuild field cache
    if ( function_exists( 'acf_get_field_groups' ) ) {
        acf_get_field_groups();
    }
}

register_activation_hook( __FILE__, 'woopm_clear_acf_cache' );
register_deactivation_hook( __FILE__, 'woopm_clear_acf_cache' );

/**
 * Main Menu System Class
 */
class WOOPMMenu_System {
    
    public function __construct() {
        // Register taxonomies
        add_action( 'init', [ $this, 'register_taxonomies' ] );
        
        // Register ACF fields with high priority to ensure ACF is fully loaded
        add_action( 'acf/init', [ $this, 'register_acf_fields' ], 20 );
        
        // Sync ACF to taxonomies - priority 5 to run before ACF saves
        add_action( 'acf/save_post', [ $this, 'sync_acf_to_taxonomies' ], 5 );
        
        // Modify queries
        add_action( 'pre_get_posts', [ $this, 'modify_taxonomy_queries' ] );
        
        // Remove metaboxes
        add_action( 'add_meta_boxes', [ $this, 'remove_taxonomy_metaboxes' ], 99 );
        
        // Fix week field taxonomy query
        add_filter( 'acf/fields/taxonomy/query/name=week', [ $this, 'fix_week_field_query' ], 10, 3 );
    }
    
    /**
     * Register all taxonomies
     */
    public function register_taxonomies() {
        // Unregister legacy taxonomies if they exist
        foreach ( [ 'weekly_menu', 'monthly_menu', 'monthly_menu_days' ] as $legacy ) {
            if ( taxonomy_exists( $legacy ) ) {
                unregister_taxonomy( $legacy );
            }
        }
        
        // Unified Program Menu (hierarchical)
        register_taxonomy( 'program_menu', 'product', [
            'label'             => 'Program Menus',
            'description'       => 'All diet programmes (weekly, monthly, etc.)',
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'rewrite'           => [ 'slug' => 'program-menu' ],
        ] );
        
        // Helper selectors
        register_taxonomy( 'week_no', 'product', [ 
            'label' => 'Weeks (1-4)', 
            'hierarchical' => false, 
            'show_ui' => true,
            'show_admin_column' => true,
            'rewrite' => ['slug' => 'week'] 
        ] );
        
        register_taxonomy( 'weekday', 'product', [ 
            'label' => 'Week Days', 
            'hierarchical' => false, 
            'show_ui' => true,
            'show_admin_column' => true,
            'rewrite' => ['slug' => 'weekday'] 
        ] );
        
        register_taxonomy( 'mealtime', 'product', [ 
            'label' => 'Meal Times', 
            'hierarchical' => false, 
            'show_ui' => true,
            'show_admin_column' => true,
            'rewrite' => ['slug' => 'mealtime'] 
        ] );
    }
    
    /**
     * Register ACF field group
     */
    public function register_acf_fields() {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) return;
        
        acf_add_local_field_group( [
            'key'      => 'group_menu_assignments_v2',
            'title'    => 'Menu Assignments',
            'fields'   => [
                [
                    'key'     => 'field_ma_repeater',
                    'type'    => 'repeater',
                    'name'    => 'menu_assignments',
                    'label'   => 'Menu Assignments',
                    'instructions' => 'Each row represents one specific day/meal assignment. Add multiple rows for the same product to appear on different days/meals.',
                    'button_label' => 'Add Day/Meal Assignment',
                    'layout'  => 'table',
                    'sub_fields' => [
                        [ // Menu Type (Weekly or Monthly)
                            'key'        => 'field_ma_menu_type',
                            'label'      => 'Type',
                            'name'       => 'menu_type',
                            'type'       => 'select',
                            'choices'    => [
                                'weekly'  => 'Weekly',
                                'monthly' => 'Monthly',
                            ],
                            'default_value' => 'weekly',
                            'ui'         => 1,
                            'wrapper'    => ['width' => '15'],
                        ],
                        [ // Program Menu select
                            'key'        => 'field_ma_program_menu',
                            'label'      => 'Menu',
                            'name'       => 'program_menu',
                            'type'       => 'taxonomy',
                            'taxonomy'   => 'program_menu',
                            'field_type' => 'select',
                            'return_format'=>'id',
                            'ui'         => 1,
                            'wrapper'    => ['width' => '25'],
                        ],
                        [ // Week (single select - optional for all menus)
                            'key'        => 'field_ma_week_number',
                            'label'      => 'Week',
                            'name'       => 'week',
                            'type'       => 'taxonomy',
                            'taxonomy'   => 'week_no',
                            'field_type' => 'select',
                            'allow_null' => 1,
                            'multiple'   => 0,
                            'required'   => 0,
                            'ui'         => 1,
                            'ajax'       => 0,
                            'return_format' => 'id',
                            'wrapper'    => ['width' => '15'],
                        ],
                        [ // Day (single select)
                            'key'        => 'field_ma_day',
                            'label'      => 'Day',
                            'name'       => 'day',
                            'type'       => 'taxonomy',
                            'taxonomy'   => 'weekday',
                            'field_type' => 'select',
                            'ui'         => 1,
                            'return_format'=>'id',
                            'wrapper'    => ['width' => '25'],
                        ],
                        [ // Meal (single select - optional)
                            'key'        => 'field_ma_meal',
                            'label'      => 'Meal (Optional)',
                            'name'       => 'meal',
                            'type'       => 'taxonomy',
                            'taxonomy'   => 'mealtime',
                            'field_type' => 'select',
                            'allow_null' => 1,
                            'ui'         => 1,
                            'return_format'=>'id',
                            'wrapper'    => ['width' => '20'],
                            'instructions' => 'Leave empty to show in ALL meals',
                        ],
                    ],
                ],
            ],
            'location' => [ [ [ 'param'=>'post_type','operator'=>'==','value'=>'product' ] ] ],
        ] );
    }
    
    /**
     * Sync ACF fields to taxonomies
     */
    public function sync_acf_to_taxonomies( $post_id ) {
        if ( get_post_type( $post_id ) !== 'product' || wp_is_post_revision( $post_id ) ) return;
        
        // Reset all taxonomies
        foreach ( [ 'program_menu','week_no','weekday','mealtime' ] as $tax ) {
            wp_set_object_terms( $post_id, [], $tax, false );
        }
        
        $program_ids = $week_ids = $day_ids = $meal_ids = [];
        $menu_assignments_data = []; // Store detailed assignment data
        
        if ( have_rows( 'menu_assignments', $post_id ) ) {
            while ( have_rows( 'menu_assignments', $post_id ) ) {
                the_row();
                $menu_type = get_sub_field( 'menu_type' );
                $program = get_sub_field( 'program_menu' );
                $week    = get_sub_field( 'week' );
                $day     = get_sub_field( 'day' );
                $meal    = get_sub_field( 'meal' );
                
                // Collect unique term IDs
                if ( $program ) {
                    $program_ids[] = (int) $program;
                    // Store menu type in term meta
                    if ( $menu_type ) {
                        update_term_meta( (int) $program, 'menu_type', $menu_type );
                    }
                }
                if ( $week ) $week_ids[] = (int) $week;
                if ( $day )  $day_ids[]  = (int) $day;
                if ( $meal ) $meal_ids[] = (int) $meal;
                
                // Store the complete assignment for future use
                $menu_assignments_data[] = [
                    'menu_type' => $menu_type,
                    'program'   => $program,
                    'week'      => $week,
                    'day'       => $day,
                    'meal'      => $meal,
                ];
            }
        }
        
        // Save unique taxonomy terms
        if ( $program_ids ) wp_set_object_terms( $post_id, array_unique( $program_ids ), 'program_menu', false );
        if ( $week_ids )    wp_set_object_terms( $post_id, array_unique( $week_ids ),    'week_no',      false );
        if ( $day_ids )     wp_set_object_terms( $post_id, array_unique( $day_ids ),     'weekday',      false );
        if ( $meal_ids )    wp_set_object_terms( $post_id, array_unique( $meal_ids ),    'mealtime',     false );
        
        // Store the complete assignments data as post meta for precise querying
        if ( ! empty( $menu_assignments_data ) ) {
            update_post_meta( $post_id, '_menu_assignments_data', $menu_assignments_data );
        } else {
            delete_post_meta( $post_id, '_menu_assignments_data' );
        }
    }
    
    /**
     * Modify taxonomy archive queries
     */
    public function modify_taxonomy_queries( $q ) {
        if ( is_admin() || ! $q->is_main_query() ) return;
        if ( is_tax( [ 'program_menu','week_no','weekday','mealtime' ] ) ) {
            $q->set( 'post_type', 'product' );
            $q->set( 'posts_per_page', 12 );
        }
    }
    
    /**
     * Remove taxonomy metaboxes
     */
    public function remove_taxonomy_metaboxes() {
        // Remove old taxonomy meta boxes
        remove_meta_box( 'monthly_menudiv', 'product', 'side' );
        remove_meta_box( 'monthly_menu_daysdiv', 'product', 'side' );
        remove_meta_box( 'weekly_menudiv', 'product', 'side' );
        
        // Remove new taxonomy meta boxes (handled by ACF)
        remove_meta_box( 'program_menudiv', 'product', 'side' );
        remove_meta_box( 'week_nodiv', 'product', 'side' );
        remove_meta_box( 'weekdaydiv', 'product', 'side' );
        remove_meta_box( 'mealtimediv', 'product', 'side' );
    }
    
    /**
     * Fix week field to ensure it uses week_no taxonomy
     */
    public function fix_week_field_query( $args, $field, $post_id ) {
        // Force the taxonomy to be week_no
        $args['taxonomy'] = 'week_no';
        return $args;
    }
}

// Enqueue scripts
function woopm_enqueue_admin_scripts( $hook ) {
    // Only load on product edit pages
    if ( ! in_array( $hook, ['post.php', 'post-new.php'] ) ) {
        return;
    }
    
    global $post;
    if ( ! $post || $post->post_type !== 'product' ) {
        return;
    }
    
    wp_enqueue_style('woopm-admin-style', WOOPM_PLUGIN_URL . 'admin/css/admin-style.css', array(), time());
    wp_enqueue_script( 'woopm-admin-script', WOOPM_PLUGIN_URL . 'admin/js/admin-script.js', array( 'jquery', 'acf-input' ), time(), true );
    
    wp_enqueue_script('sweetalert', 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js', array(), null, true);
    wp_enqueue_style('sweetalert-css', 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css', array(), null);
    wp_localize_script( 'woopm-admin-script', 'woopm_ajax_object', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'woopm_nonce' => wp_create_nonce( 'woopm_nonce' ),
    ));
}
add_action( 'admin_enqueue_scripts', 'woopm_enqueue_admin_scripts' );

function woopm_enqueue_public_scripts() {
    wp_enqueue_style( 'woopm-public-style', WOOPM_PLUGIN_URL . 'frontend/css/public-style.css', array(), time() );
    wp_enqueue_script( 'woopm-public-script', WOOPM_PLUGIN_URL . 'frontend/js/public-ajax.js', array( 'jquery' ), time(), true );
    wp_enqueue_style('woopm-font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', array(), null);
    wp_enqueue_script('sweetalert', 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js', array(), null, true);
    wp_enqueue_style('sweetalert-css', 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css', array(), null);
    wp_localize_script( 'woopm-public-script', 'woopm_ajax_object', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'woopm_nonce' => wp_create_nonce( 'woopm_nonce' ),
    ));
}
add_action( 'wp_enqueue_scripts', 'woopm_enqueue_public_scripts' );

// Βοηθητικές συναρτήσεις

/**
 * Υπολογισμός συνολικής τιμής προϊόντων ενός μενού
 * 
 * @param string $taxonomy_type Τύπος taxonomy (συνήθως 'program_menu')
 * @param int $taxonomy_slug ID του μενού
 * @param string $menu_type Τύπος μενού ('ready' ή 'own')
 * @return array Συνολική τιμή και πλήθος προϊόντων
 */
function get_category_products_total_price_by_taxonomy($taxonomy_type, $taxonomy_slug, $menu_type, $program_type = null) {
    $total_price = 0;
    $product_count = 0;
    error_log("Getting price for menu: type=$taxonomy_type, slug=$taxonomy_slug, menu_type=$menu_type, program_type=$program_type");
    
    $term = get_term($taxonomy_slug, $taxonomy_type);
    
    if (!is_wp_error($term) && !empty($term)) {
        // Check if this is a monthly menu
        $menu_type_meta = get_term_meta($taxonomy_slug, 'menu_type', true);
        $is_monthly = ($menu_type_meta === 'monthly');
        
        error_log("Menu type from meta: $menu_type_meta, is_monthly: " . ($is_monthly ? 'yes' : 'no'));
        
        if($menu_type == 'ready'){
            // For ready menus, first try to find a product with the same name as the menu
            global $wpdb;
            $menu_product = $wpdb->get_row($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} 
                 WHERE post_type = 'product' 
                 AND post_status = 'publish' 
                 AND post_title = %s 
                 LIMIT 1",
                $term->name
            ));
            
            if ($menu_product) {
                // Found a product with the same name as the menu
                $product = wc_get_product($menu_product->ID);
                if ($product) {
                    $menu_price = get_field('menu_price', $menu_product->ID);
                    $total_price = !empty($menu_price) ? floatval($menu_price) : $product->get_price();
                    $product_count = 1;
                    error_log("Found menu product '{$term->name}' with price: $total_price");
                }
            } else {
                // Fallback to old logic
                // First try to get products from term meta (legacy method)
                $productids = get_term_meta($taxonomy_slug, 'product', true);
                
                // If we have products in term meta, count each occurrence
                if(!empty($productids)){
                    foreach($productids as $product_id){
                        $product = wc_get_product($product_id);
                        if($product) {
                            $price = $product->get_price();
                            // Include products even if they have no price (price could be 0 or empty)
                            $total_price += floatval($price);
                            $product_count++;
                        }
                    }
                } else {
                // Use the new function to get ALL occurrences
                error_log("No products in term meta, getting all occurrences from menu assignments");
                
                // For monthly menus, include all weeks in the total
                $include_all_weeks = $is_monthly;
                $occurrences = get_all_product_occurrences_by_menu($taxonomy_slug, null, null, null, $include_all_weeks);
                
                error_log("Found " . count($occurrences) . " product occurrences (include_all_weeks: " . ($include_all_weeks ? 'yes' : 'no') . ")");
                
                foreach($occurrences as $occurrence){
                    $product = wc_get_product($occurrence['product_id']);
                    if($product) {
                        $price = $product->get_price();
                        // Include products even if they have no price (price could be 0 or empty)
                        $total_price += floatval($price);
                        $product_count++;
                        
                        // Log week info for monthly menus
                        if ($is_monthly && !empty($occurrence['assignment']['week'])) {
                            $week_term = get_term($occurrence['assignment']['week'], 'week_no');
                            $week_name = $week_term ? $week_term->name : 'Unknown';
                            error_log("Product {$occurrence['product_id']} in $week_name, price: $price, running total: $total_price");
                        } else {
                            error_log("Product {$occurrence['product_id']} price: $price, running total: $total_price");
                        }
                    }
                }
            }
            }
        } elseif($menu_type == 'own' && $program_type) {
            // For "build your own" menus, calculate total using custom prices
            error_log("Calculating total for 'own' menu with program type: $program_type");
            
            // Get all product occurrences for this menu
            $include_all_weeks = $is_monthly;
            $occurrences = get_all_product_occurrences_by_menu($taxonomy_slug, null, null, null, $include_all_weeks);
            
            error_log("Found " . count($occurrences) . " product occurrences for own menu");
            
            foreach($occurrences as $occurrence){
                $product_id = $occurrence['product_id'];
                $product = wc_get_product($product_id);
                
                if($product) {
                    // Get menu price for "own" menu type
                    $custom_price = get_field('menu_price', $product_id);
                    $price = !empty($custom_price) ? floatval($custom_price) : $product->get_price();
                    
                    // Include products even if they have no price (price could be 0 or empty)
                    $total_price += floatval($price);
                    $product_count++;
                    
                    error_log("Product {$product_id} custom price: $price, running total: $total_price");
                }
            }
        }
    }
    
    $total_price_formatted = wc_price($total_price);
    error_log("Final total price for $product_count products: $total_price_formatted");
    
    return [
        'total_price' => $total_price_formatted,
        'total_price_plain' => $total_price,
        'product_count' => $product_count
    ];
}

function get_total_kcal_by_taxonomy($taxonomy_type, $taxonomy_slug, $taxonomy_child_type, $taxonomy_child_slug) {
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'tax_query' => array(
            array(
                'taxonomy' => $taxonomy_type,
                'field' => 'ID',
                'terms' => $taxonomy_slug,
                'operator' => 'IN',
            ),
            array(
                'taxonomy' => $taxonomy_child_type,
                'field' => 'slug',
                'terms' => $taxonomy_child_slug,
                'operator' => 'IN',
            ),
        ),
    );
    
    $query = new WP_Query($args);
    
    $total_kcal = 0;
    
    while ($query->have_posts()) {
        $query->the_post();
        
        $product = wc_get_product(get_the_ID());
        $kcal_val = (int) get_post_meta(get_the_ID(), 'kcal', true);
        
        $total_kcal += $kcal_val;
    }
    
    wp_reset_postdata();
    
    return ['total_kcal' => $total_kcal];
}

/**
 * Get products for specific day/meal combination
 * This function ensures products only appear in their assigned day/meal slots
 */
/**
 * Ανάκτηση προϊόντων βάσει ανάθεσης μενού
 * 
 * @param int $program_menu_id ID του μενού προγράμματος
 * @param int $week_id ID εβδομάδας (null για εβδομαδιαία μενού)
 * @param int $day_id ID ημέρας
 * @param int $meal_id ID γεύματος
 * @return array Array με IDs προϊόντων (unique)
 */
function get_products_by_menu_assignment( $program_menu_id, $week_id = null, $day_id = null, $meal_id = null ) {
    global $wpdb;
    
    // Generate cache key
    $cache_key = 'woopm_products_' . md5(serialize([$program_menu_id, $week_id, $day_id, $meal_id]));
    
    // Try to get from cache first
    $cached_result = get_transient($cache_key);
    if ($cached_result !== false) {
        error_log("=== Returning cached result for menu assignment query ===");
        return $cached_result;
    }
    
    // Check if lookup table exists and use it for better performance
    $table_name = $wpdb->prefix . 'woopm_menu_assignments';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    
    if ($table_exists) {
        // Use optimized lookup table
        error_log("=== Using optimized lookup table ===");
        
        $where_conditions = ["program_menu_id = %d"];
        $where_values = [$program_menu_id];
        
        if ($week_id !== null) {
            $where_conditions[] = "week_id = %d";
            $where_values[] = $week_id;
        } else {
            $where_conditions[] = "(week_id IS NULL OR week_id = '')";
        }
        
        if ($day_id !== null) {
            $where_conditions[] = "day_id = %d";
            $where_values[] = $day_id;
        }
        
        if ($meal_id !== null) {
            $where_conditions[] = "meal_id = %d";
            $where_values[] = $meal_id;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $query = "SELECT DISTINCT product_id FROM $table_name WHERE $where_clause";
        $prepared_query = $wpdb->prepare($query, $where_values);
        
        $product_ids = $wpdb->get_col($prepared_query);
        
        // Cache the result
        $cache_duration = get_option('woopm_cache_duration', 3600);
        set_transient($cache_key, $product_ids, $cache_duration);
        
        error_log("Found " . count($product_ids) . " products using lookup table");
        return $product_ids;
    }
    
    // Fallback to original logic if table doesn't exist
    error_log("=== get_products_by_menu_assignment called (fallback mode) ===");
    error_log("Program Menu ID: $program_menu_id");
    error_log("Week ID: " . ($week_id ?: 'null'));
    error_log("Day ID: " . ($day_id ?: 'null'));
    error_log("Meal ID: " . ($meal_id ?: 'null'));
    
    // Get ALL products with menu assignments since taxonomy assignments may be incomplete
    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => '_menu_assignments_data',
                'compare' => 'EXISTS',
            ],
        ],
    ];
    
    $product_ids = get_posts( $args );
    
    error_log("Meta query found " . count($product_ids) . " products with _menu_assignments_data");
    
    if ( empty( $product_ids ) ) {
        error_log("No products found with meta query");
        return [];
    }
    
    // Now filter by the specific assignment combination
    $filtered_products = [];
    
    foreach ( $product_ids as $product_id ) {
        $assignments = get_post_meta( $product_id, '_menu_assignments_data', true );
        
        if ( ! is_array( $assignments ) ) {
            // Try to get from ACF field
            $acf_assignments = get_field('menu_assignments', $product_id);
            if (is_array($acf_assignments)) {
                error_log("Product $product_id has ACF assignments but no _menu_assignments_data meta");
            }
            continue;
        }
        
        // Log first product's assignments structure
        static $logged_first = false;
        if (!$logged_first) {
            error_log("Sample assignment structure for product $product_id: " . print_r($assignments, true));
            $logged_first = true;
        }
        
        // Check if this product has an assignment matching our criteria
        foreach ( $assignments as $idx => $assignment ) {
            $match = true;
            
            error_log("  Checking assignment for product $product_id:");
            error_log("    Program required: $program_menu_id, Assignment has: " . ($assignment['program'] ?? 'null'));
            error_log("    Day required: " . ($day_id ?: 'null') . ", Assignment has: " . ($assignment['day'] ?? 'null'));
            error_log("    Meal required: " . ($meal_id ?: 'null') . ", Assignment has: " . ($assignment['meal'] ?? 'null'));
            error_log("    Week required: " . ($week_id ?: 'null') . ", Assignment has: " . ($assignment['week'] ?? 'null'));
            
            if ( $program_menu_id && $assignment['program'] != $program_menu_id ) {
                error_log("    ❌ Program mismatch");
                $match = false;
            }
            
            if ( $week_id !== null ) {
                // If week_id is provided, assignment must match exactly
                if ( $assignment['week'] != $week_id ) {
                    error_log("    ❌ Week mismatch");
                    $match = false;
                }
            } else {
                // If week_id is null, we want products with empty week assignment
                if ( !empty($assignment['week']) ) {
                    error_log("    ❌ Week should be empty but has: " . $assignment['week']);
                    $match = false;
                }
            }
            
            if ( $match && $day_id && $assignment['day'] != $day_id ) {
                error_log("    ❌ Day mismatch");
                $match = false;
            }
            
            if ( $match && $meal_id ) {
                // If assignment has no meal specified, it matches ALL meals
                if ( !empty($assignment['meal']) && $assignment['meal'] != $meal_id ) {
                    error_log("    ❌ Meal mismatch");
                    $match = false;
                }
            }
            
            if ( $match ) {
                error_log("    ✅ MATCH! Adding product $product_id");
                $filtered_products[] = $product_id;
                break; // Found a matching assignment, no need to check others
            } else {
                error_log("    ❌ No match for this assignment");
            }
        }
    }
    
    $result = array_unique( $filtered_products );
    
    error_log("Final filtered products: " . count($result));
    if (!empty($result)) {
        error_log("Product IDs: " . implode(', ', $result));
    }
    
    // Cache the result
    $cache_duration = get_option('woopm_cache_duration', 3600);
    set_transient($cache_key, $result, $cache_duration);
    
    return $result;
}

/**
 * Get all product occurrences for a menu (including duplicates)
 * This is used for calculating total menu price
 */
function get_all_product_occurrences_by_menu( $program_menu_id, $week_id = null, $day_id = null, $meal_id = null, $include_all_weeks = false ) {
    // First get products with the base taxonomy query
    $tax_query = [
        'relation' => 'AND',
        [
            'taxonomy' => 'program_menu',
            'field'    => 'term_id',
            'terms'    => $program_menu_id,
        ],
    ];
    
    // Add week filter if provided and not including all weeks
    if ( $week_id !== null && !$include_all_weeks ) {
        $tax_query[] = [
            'taxonomy' => 'week_no',
            'field'    => 'term_id',
            'terms'    => $week_id,
        ];
    }
    
    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'tax_query'      => $tax_query,
        'fields'         => 'ids',
    ];
    
    $product_ids = get_posts( $args );
    
    if ( empty( $product_ids ) ) {
        return [];
    }
    
    // Now get ALL matching assignments (don't break on first match)
    $all_occurrences = [];
    
    foreach ( $product_ids as $product_id ) {
        $assignments = get_post_meta( $product_id, '_menu_assignments_data', true );
        
        if ( ! is_array( $assignments ) ) {
            continue;
        }
        
        // Check ALL assignments for this product
        foreach ( $assignments as $assignment ) {
            $match = true;
            
            if ( $program_menu_id && $assignment['program'] != $program_menu_id ) {
                $match = false;
            }
            
            // For monthly menus when calculating total price, include all weeks
            if ( $include_all_weeks ) {
                // Don't filter by week - include all weeks
            } elseif ( $week_id !== null ) {
                if ( $assignment['week'] != $week_id ) {
                    $match = false;
                }
            } else {
                if ( !empty($assignment['week']) ) {
                    $match = false;
                }
            }
            
            if ( $match && $day_id && $assignment['day'] != $day_id ) {
                $match = false;
            }
            
            if ( $match && $meal_id ) {
                if ( !empty($assignment['meal']) && $assignment['meal'] != $meal_id ) {
                    $match = false;
                }
            }
            
            if ( $match ) {
                // Add each occurrence separately
                $all_occurrences[] = [
                    'product_id' => $product_id,
                    'assignment' => $assignment
                ];
            }
        }
    }
    
    return $all_occurrences;
}

/**
 * Debug function to check product assignments
 */
function debug_product_menu_assignments( $product_id ) {
    echo "<pre>";
    echo "Product ID: $product_id\n";
    
    // Check taxonomies
    $taxonomies = ['program_menu', 'week_no', 'weekday', 'mealtime'];
    foreach ($taxonomies as $tax) {
        $terms = wp_get_post_terms($product_id, $tax);
        echo "\n$tax terms:\n";
        foreach ($terms as $term) {
            echo "  - {$term->name} (ID: {$term->term_id})\n";
        }
    }
    
    // Check meta data
    $assignments = get_post_meta($product_id, '_menu_assignments_data', true);
    echo "\n_menu_assignments_data:\n";
    print_r($assignments);
    
    // Check ACF data
    if (function_exists('get_field')) {
        $acf_data = get_field('menu_assignments', $product_id);
        echo "\nACF menu_assignments:\n";
        print_r($acf_data);
    }
    
    echo "</pre>";
}

// Add debug shortcode
add_shortcode('debug_product_menu', function($atts) {
    $atts = shortcode_atts(['id' => 0], $atts);
    if ($atts['id']) {
        ob_start();
        debug_product_menu_assignments($atts['id']);
        return ob_get_clean();
    }
    return 'Please provide a product ID: [debug_product_menu id="123"]';
});

// Εμφάνιση πληροφοριών μενού στο καλάθι
add_filter( 'woocommerce_get_item_data', 'woopm_display_menu_info_in_cart', 10, 2 );

/**
 * Εμφάνιση μεταδεδομένων μενού στο καλάθι αγορών
 * 
 * @param array $item_data Υπάρχοντα δεδομένα αντικειμένου
 * @param array $cart_item Στοιχείο καλαθιού
 * @return array Ενημερωμένα δεδομένα με πληροφορίες μενού
 */
function woopm_display_menu_info_in_cart( $item_data, $cart_item ) {
    if ( isset( $cart_item['menu_selection'] ) ) {
        $menu_info = $cart_item['menu_selection'];
        
        // Check if it's a ready menu - if so, don't display these meta fields
        $menu_category = isset($menu_info['menu_category']) ? $menu_info['menu_category'] : '';
        $is_menu_product = isset($menu_info['is_menu_product']) ? $menu_info['is_menu_product'] : false;
        $is_custom_menu = isset($menu_info['is_custom_menu']) ? $menu_info['is_custom_menu'] : false;
        
        // For ready menu products, only show allergens
        if ($menu_category === 'Έτοιμα μενού' && $is_menu_product) {
            // Only add allergens for ready menus
            if ( !empty( $menu_info['allergens'] ) && $menu_info['allergens'] !== 'Καμία' ) {
                $item_data[] = array(
                    'name' => __( 'Αλλεργιογόνα & ιδιαίτερες προτιμήσεις', 'woocommerce' ),
                    'value' => $menu_info['allergens']
                );
            }
            return $item_data; // Return without adding other menu meta
        }
        
        // For individual products from "make your own menu", add all meta except menu name
        if ($menu_category === 'Φτιάξε το δικό σου μενού' && $is_custom_menu) {
            // Skip menu name but add other meta
            
            // Add program type
            $item_data[] = array(
                'name' => __( 'Πρόγραμμα', 'woocommerce' ),
                'value' => $menu_info['program_type']
            );
            
            // Add zipcode
            if ( !empty( $menu_info['zipcode'] ) ) {
                $item_data[] = array(
                    'name' => __( 'Ταχυδρομικός κώδικας', 'woocommerce' ),
                    'value' => $menu_info['zipcode']
                );
            }
            
            // Add allergens if any
            if ( !empty( $menu_info['allergens'] ) && $menu_info['allergens'] !== '' ) {
                $item_data[] = array(
                    'name' => __( 'Αλλεργιογόνα', 'woocommerce' ),
                    'value' => $menu_info['allergens']
                );
            }
            
            // Add schedule information
            if ( isset( $menu_info['schedule'] ) ) {
                $schedule = $menu_info['schedule'];
                $schedule_text = '';
                
                if ( !empty( $schedule['week_name'] ) ) {
                    $schedule_text .= $schedule['week_name'] . ' - ';
                }
                if ( !empty( $schedule['day_name'] ) ) {
                    $schedule_text .= $schedule['day_name'];
                }
                if ( !empty( $schedule['meal_name'] ) ) {
                    $schedule_text .= ' - ' . $schedule['meal_name'];
                }
                
                if ( !empty( $schedule_text ) ) {
                    $item_data[] = array(
                        'name' => __( 'Πρόγραμμα', 'woocommerce' ),
                        'value' => $schedule_text
                    );
                }
            }
            
            return $item_data;
        }
        
        // Add menu name
        $item_data[] = array(
            'name' => __( 'Μενού', 'woocommerce' ),
            'value' => $menu_info['menu_name']
        );
        
        // Add program type
        $item_data[] = array(
            'name' => __( 'Πρόγραμμα', 'woocommerce' ),
            'value' => $menu_info['program_type']
        );
        
        // Add total menu price (only if greater than 0)
        if ( !empty( $menu_info['total_menu_price'] ) && $menu_info['total_menu_price'] > 0 ) {
            $item_data[] = array(
                'name' => __( 'Συνολική τιμή μενού', 'woocommerce' ),
                'value' => wc_price( $menu_info['total_menu_price'] )
            );
        }
        
        // Add zipcode
        if ( !empty( $menu_info['zipcode'] ) ) {
            $item_data[] = array(
                'name' => __( 'Ταχυδρομικός κώδικας', 'woocommerce' ),
                'value' => $menu_info['zipcode']
            );
        }
        
        // Add allergens if any
        if ( !empty( $menu_info['allergens'] ) && $menu_info['allergens'] !== '' ) {
            $item_data[] = array(
                'name' => __( 'Αλλεργιογόνα', 'woocommerce' ),
                'value' => $menu_info['allergens']
            );
        }
        
        // Add schedule information for custom menus
        if ( isset( $menu_info['is_custom_menu'] ) && $menu_info['is_custom_menu'] && isset( $menu_info['schedule'] ) ) {
            $schedule = $menu_info['schedule'];
            $schedule_text = '';
            
            if ( !empty( $schedule['week_name'] ) ) {
                $schedule_text .= $schedule['week_name'] . ' - ';
            }
            if ( !empty( $schedule['day_name'] ) ) {
                $schedule_text .= $schedule['day_name'];
            }
            if ( !empty( $schedule['meal_name'] ) ) {
                $schedule_text .= ' - ' . $schedule['meal_name'];
            }
            
            if ( !empty( $schedule_text ) ) {
                $item_data[] = array(
                    'name' => __( 'Πρόγραμμα', 'woocommerce' ),
                    'value' => $schedule_text
                );
            }
        }
    }
    
    return $item_data;
}

// Save menu information to order items
add_action( 'woocommerce_checkout_create_order_line_item', 'woopm_save_menu_info_to_order_items', 10, 4 );
function woopm_save_menu_info_to_order_items( $item, $cart_item_key, $values, $order ) {
    if ( isset( $values['menu_selection'] ) ) {
        foreach ( $values['menu_selection'] as $key => $value ) {
            $item->add_meta_data( '_' . $key, $value );
        }
    }
}

// Display menu information in order details (admin and customer)
add_action( 'woocommerce_order_item_meta_start', 'woopm_display_menu_info_in_order', 10, 3 );
function woopm_display_menu_info_in_order( $item_id, $item, $order ) {
    if ( $menu_name = $item->get_meta( '_menu_name' ) ) {
        echo '<p><strong>' . __( 'Μενού:', 'woocommerce' ) . '</strong> ' . esc_html( $menu_name ) . '</p>';
    }
    
    if ( $program_type = $item->get_meta( '_program_type' ) ) {
        echo '<p><strong>' . __( 'Πρόγραμμα:', 'woocommerce' ) . '</strong> ' . esc_html( $program_type ) . '</p>';
    }
    
    if ( $total_price = $item->get_meta( '_total_menu_price' ) ) {
        echo '<p><strong>' . __( 'Συνολική τιμή μενού:', 'woocommerce' ) . '</strong> ' . wc_price( $total_price ) . '</p>';
    }
}

// Ensure cart item data persists
add_filter( 'woocommerce_add_cart_item_data', 'woopm_add_cart_item_data', 10, 3 );
function woopm_add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
    // This ensures our menu_selection data is properly saved
    return $cart_item_data;
}

// Get cart item from session
add_filter( 'woocommerce_get_cart_item_from_session', 'woopm_get_cart_item_from_session', 10, 3 );
function woopm_get_cart_item_from_session( $cart_item, $values, $key ) {
    if ( isset( $values['menu_selection'] ) ) {
        $cart_item['menu_selection'] = $values['menu_selection'];
    }
    return $cart_item;
}

/**
 * Sync menu assignments to lookup table when product is saved
 */
add_action('save_post_product', function($post_id) {
    global $wpdb;
    
    // Check if lookup table exists
    $table_name = $wpdb->prefix . 'woopm_menu_assignments';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        return;
    }
    
    // Get menu assignments
    $assignments = get_post_meta($post_id, '_menu_assignments_data', true);
    
    // Delete existing assignments for this product
    $wpdb->delete($table_name, ['product_id' => $post_id]);
    
    // Insert new assignments
    if (is_array($assignments)) {
        foreach ($assignments as $index => $assignment) {
            if (!empty($assignment['program'])) {
                $wpdb->insert($table_name, [
                    'product_id' => $post_id,
                    'program_menu_id' => $assignment['program'],
                    'week_id' => !empty($assignment['week']) ? $assignment['week'] : null,
                    'day_id' => !empty($assignment['day']) ? $assignment['day'] : null,
                    'meal_id' => !empty($assignment['meal']) ? $assignment['meal'] : null,
                    'assignment_index' => $index,
                ]);
            }
        }
    }
    
    // Clear related caches
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_woopm_products_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_woopm_products_%'");
});

/**
 * Clear cache when accessing blue menu page
 */
add_action('init', function() {
    if (isset($_GET['clear_menu_cache'])) {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_woopm_products_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_woopm_products_%'");
        wp_redirect(remove_query_arg('clear_menu_cache'));
        exit;
    }
});

/**
 * Register ACF field for shop visibility
 */
add_action('acf/init', function() {
    if (function_exists('acf_add_local_field_group')) {
        acf_add_local_field_group(array(
            'key' => 'group_shop_visibility',
            'title' => 'Shop Visibility',
            'fields' => array(
                array(
                    'key' => 'field_show_in_shop',
                    'label' => 'Show in Shop',
                    'name' => 'show_in_shop',
                    'type' => 'true_false',
                    'instructions' => 'Check this box to show this product on the /shop/ page',
                    'required' => 0,
                    'default_value' => 1,
                    'ui' => 1,
                    'ui_on_text' => 'Show',
                    'ui_off_text' => 'Hide',
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'product',
                    ),
                ),
            ),
            'menu_order' => 0,
            'position' => 'side',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'active' => true,
            'description' => '',
        ));
    }
});

/**
 * Hide products based on shop visibility checkbox
 */
add_action('woocommerce_product_query', 'woopm_control_shop_visibility', 10, 2);
function woopm_control_shop_visibility($q, $wc_query) {
    // Only apply on shop page
    if (!is_shop()) {
        return;
    }
    
    // Get existing meta query
    $meta_query = $q->get('meta_query') ?: array();
    
    // Add condition to only show products with show_in_shop = true
    // Products without the field set will be shown by default
    $meta_query[] = array(
        'relation' => 'OR',
        array(
            'key' => 'show_in_shop',
            'value' => '1',
            'compare' => '=',
        ),
        array(
            'key' => 'show_in_shop',
            'compare' => 'NOT EXISTS',
        )
    );
    
    $q->set('meta_query', $meta_query);
}

 function clear_menu_blue_cache() {
      // Clear WordPress transients
      delete_transient('menu_blue_cache');

      // Clear any custom transients used by the plugin
      global $wpdb;
      $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_woopm_%'");

      // Clear object cache
      wp_cache_flush();

      // Clear ACF cache
      delete_transient('acf_field_groups');

      return true;
  }

  // Add admin bar menu item for easy cache clearing
  add_action('admin_bar_menu', function($wp_admin_bar) {
      $wp_admin_bar->add_node([
          'id' => 'clear-menu-cache',
          'title' => 'Clear Menu Cache',
          'href' => add_query_arg('clear_menu_cache', '1', admin_url()),
      ]);
  }, 100);

  // Handle cache clear request
  add_action('admin_init', function() {
      if (isset($_GET['clear_menu_cache']) && current_user_can('manage_options')) {
          clear_menu_blue_cache();
          wp_redirect(remove_query_arg('clear_menu_cache'));
          exit;
      }
  });
