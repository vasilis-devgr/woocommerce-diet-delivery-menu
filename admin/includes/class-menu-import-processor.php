<?php
/**
 * Menu Import Processor Class
 * 
 * @package WooCommerce_Product_Menu
 */

if (!defined('ABSPATH')) {
    exit;
}

class WOOPM_Menu_Import_Processor {
    
    private $file_path;
    private $data = [];
    private $options = [
        'skip_existing' => true,
        'clear_existing' => false,
        'update_prices' => false,
        'batch_size' => 25
    ];
    
    private $stats = [
        'products_found' => 0,
        'products_not_found' => 0,
        'assignments_created' => 0,
        'assignments_updated' => 0,
        'errors' => 0
    ];
    
    private $term_cache = [];
    private $log = [];
    private $cleared_products = []; // Track assignments for products that have been cleared
    
    public function __construct($file_path, $options = []) {
        $this->file_path = $file_path;
        // Merge provided options with defaults
        $this->options = array_merge($this->options, $options);
        $this->parse_excel();
        $this->cache_terms();
    }
    
    /**
     * Set import options
     */
    public function set_options($options) {
        $this->options = array_merge($this->options, $options);
    }
    
    /**
     * Process a batch of rows
     */
    public function process_batch($batch_number) {
        $start = $batch_number * $this->options['batch_size'];
        $end = $start + $this->options['batch_size'];
        $total = count($this->data);
        
        // Reset batch stats
        $batch_stats = [
            'products_found' => 0,
            'products_not_found' => 0,
            'assignments_created' => 0,
            'assignments_updated' => 0,
            'errors' => 0
        ];
        
        // Process rows in this batch
        for ($i = $start; $i < $end && $i < $total; $i++) {
            $result = $this->process_row($this->data[$i], $i + 2);
            
            // Update batch stats
            if ($result['success']) {
                if ($result['product_found']) {
                    $batch_stats['products_found']++;
                    if ($result['assignment_created']) {
                        $batch_stats['assignments_created']++;
                    } else {
                        $batch_stats['assignments_updated']++;
                    }
                } else {
                    $batch_stats['products_not_found']++;
                }
            } else {
                $batch_stats['errors']++;
            }
        }
        
        // Update total stats
        foreach ($batch_stats as $key => $value) {
            $this->stats[$key] += $value;
        }
        
        // Store complete log in transient for debugging
        set_transient('woopm_import_log_' . get_current_user_id(), $this->log, HOUR_IN_SECONDS);
        
        // Get logs just from this batch
        $batch_log_start = max(0, count($this->log) - 50); // Show last 50 entries max per batch
        $batch_logs = array_slice($this->log, $batch_log_start);
        
        return [
            'processed' => min($end, $total),
            'total' => $total,
            'has_more' => $end < $total,
            'stats' => $batch_stats,
            'total_stats' => $this->stats,
            'log' => $batch_logs // Return more logs for live viewer
        ];
    }
    
    /**
     * Process a single row
     */
    private function process_row($row, $row_number) {
        $result = [
            'success' => true,
            'product_found' => false,
            'assignment_created' => false,
            'message' => ''
        ];
        
        // Extract data - handle both Monthly and Weekly formats
        $menu_title = isset($row['Τίτλος Μενού']) ? trim($row['Τίτλος Μενού']) : '';
        $type = isset($row['Type']) ? trim($row['Type']) : '';
        
        // For Weekly type, the columns are shifted
        if (strtolower($type) === 'weekly') {
            // In Weekly format: 
            // Εβδομάδα contains day (e.g., "Δευτέρα")
            // Ημέρα contains meal time (e.g., "Πρωινό")
            // Meal contains product name
            // Γεύμα(doc αρχείο) is empty
            $product_name = isset($row['Meal']) ? trim($row['Meal']) : '';
            $week = ''; // Weekly menus don't have week numbers
            $day = isset($row['Εβδομάδα']) ? trim($row['Εβδομάδα']) : '';
            $meal = isset($row['Ημέρα']) ? trim($row['Ημέρα']) : '';
        } else {
            // Monthly format uses original mapping
            $product_name = isset($row['Γεύμα(doc αρχείο)']) ? trim($row['Γεύμα(doc αρχείο)']) : '';
            $week = isset($row['Εβδομάδα']) ? trim($row['Εβδομάδα']) : '';
            $day = isset($row['Ημέρα']) ? trim($row['Ημέρα']) : '';
            $meal = isset($row['Meal']) ? trim($row['Meal']) : '';
        }
        
        // Always log row processing for debugging
        $this->log("=== Row $row_number ===");
        $this->log("Raw data: " . json_encode($row));
        $this->log("Type: '$type' | Menu: '$menu_title'");
        $this->log("Extracted - Product: '$product_name' | Week: '$week' | Day: '$day' | Meal: '$meal'");
        
        // Skip empty rows
        if (empty($product_name) || empty($menu_title)) {
            $this->log("Row $row_number: Skipping - empty product name or menu title");
            return $result;
        }
        
        // Find product
        $product = $this->find_product_by_name($product_name);
        if (!$product) {
            $result['success'] = false;
            $result['product_found'] = false;
            $result['message'] = "Product not found: '$product_name'";
            $this->log("ERROR at Row $row_number: " . $result['message']);
            
            // Additional debug info for first few failures
            if ($this->stats['products_not_found'] < 5) {
                $this->log("  - Original name: '$product_name'");
                $clean_name = preg_replace('/\s+/', ' ', trim($product_name));
                $clean_name = html_entity_decode($clean_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $this->log("  - Cleaned name: '$clean_name'");
                
                // Try to find similar products
                global $wpdb;
                $similar = $wpdb->get_results($wpdb->prepare(
                    "SELECT ID, post_title FROM {$wpdb->posts} 
                     WHERE post_type = 'product' 
                     AND post_status = 'publish' 
                     AND post_title LIKE %s 
                     LIMIT 3",
                    '%' . $wpdb->esc_like(substr($clean_name, 0, 20)) . '%'
                ));
                
                if ($similar) {
                    $this->log("  - Similar products found:");
                    foreach ($similar as $sim) {
                        $this->log("    - ID: {$sim->ID}, Title: '{$sim->post_title}'");
                    }
                } else {
                    $this->log("  - No similar products found");
                }
            }
            
            return $result;
        }
        
        $result['product_found'] = true;
        
        // Prepare assignment
        $assignment = $this->prepare_assignment($type, $menu_title, $week, $day, $meal);
        if (!$assignment) {
            $result['success'] = false;
            $result['message'] = "Invalid assignment data - could not find matching terms";
            $this->log("Row $row_number: " . $result['message']);
            $this->log("  - Type: '$type', Menu: '$menu_title', Week: '$week', Day: '$day', Meal: '$meal'");
            
            // Debug which terms are missing
            if (!$this->find_term($menu_title, 'program_menu')) {
                $this->log("  - Missing program_menu: '$menu_title'");
            }
            if (!empty($week) && !$this->find_term($week, 'week_no')) {
                $this->log("  - Missing week_no: '$week'");
            }
            if (!empty($day) && !$this->find_term($day, 'weekday')) {
                $this->log("  - Missing weekday: '$day'");
            }
            if (!empty($meal) && !$this->find_term($meal, 'mealtime')) {
                $this->log("  - Missing mealtime: '$meal'");
            }
            
            return $result;
        }
        
        // Update product assignments
        $update_result = $this->update_product_assignments($product->ID, $assignment, $product_name);
        
        if ($update_result['created']) {
            $result['assignment_created'] = true;
            $this->log("Row $row_number: Created assignment for '$product_name'");
        } elseif ($update_result['exists']) {
            $this->log("Row $row_number: Assignment already exists for '$product_name'");
        } else {
            $result['success'] = false;
            $result['message'] = "Failed to update assignment";
            $this->log("Row $row_number: " . $result['message']);
        }
        
        // Update prices if option is set
        if ($this->options['update_prices'] && isset($row['Weekly Price']) && isset($row['Monthly Price'])) {
            $this->update_product_prices($product->ID, $row['Weekly Price'], $row['Monthly Price']);
        }
        
        return $result;
    }
    
    /**
     * Parse Excel file
     */
    private function parse_excel() {
        try {
            $zip = new ZipArchive();
            if ($zip->open($this->file_path) !== TRUE) {
                return;
            }
            
            // Read shared strings
            $shared_strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $xml = simplexml_load_string($zip->getFromName('xl/sharedStrings.xml'));
                if ($xml) {
                    foreach ($xml->si as $si) {
                        $shared_strings[] = (string)$si->t;
                    }
                }
            }
            
            // Read worksheet
            if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
                $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
                if ($xml) {
                    $rows = [];
                    foreach ($xml->sheetData->row as $row) {
                        $row_data = [];
                        foreach ($row->c as $cell) {
                            $value = '';
                            if (isset($cell->v)) {
                                if ((string)$cell['t'] == 's') {
                                    $value = $shared_strings[(int)$cell->v];
                                } else {
                                    $value = (string)$cell->v;
                                }
                            }
                            $row_data[] = $value;
                        }
                        if (!empty(array_filter($row_data))) {
                            $rows[] = $row_data;
                        }
                    }
                    
                    // Convert to associative array
                    if (count($rows) > 1) {
                        $headers = $rows[0];
                        for ($i = 1; $i < count($rows); $i++) {
                            $row_assoc = [];
                            for ($j = 0; $j < count($headers); $j++) {
                                $row_assoc[$headers[$j]] = isset($rows[$i][$j]) ? trim($rows[$i][$j]) : '';
                            }
                            $this->data[] = $row_assoc;
                        }
                    }
                }
            }
            
            $zip->close();
            
        } catch (Exception $e) {
            $this->log('Error parsing Excel: ' . $e->getMessage());
        }
    }
    
    /**
     * Cache taxonomy terms
     */
    private function cache_terms() {
        $taxonomies = ['program_menu', 'week_no', 'weekday', 'mealtime'];
        
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false
            ]);
            
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $this->term_cache[$taxonomy][strtolower(trim($term->name))] = $term;
                }
            }
        }
    }
    
    /**
     * Find product by name
     */
    private function find_product_by_name($name) {
        global $wpdb;
        
        // Clean name but preserve & character
        $clean_name = preg_replace('/\s+/', ' ', trim($name));
        $clean_name = html_entity_decode($clean_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Always log product searches for debugging
        $this->log("Looking for product: Original='$name', Cleaned='$clean_name'");
        
        // Try exact match first
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_type = 'product' 
             AND post_status = 'publish' 
             AND post_title = %s 
             LIMIT 1",
            $clean_name
        ));
        
        if ($post_id) {
            $this->log("  → FOUND via exact match: ID=$post_id");
            return get_post($post_id);
        }
        
        // Try LIKE match
        $like_name = '%' . $wpdb->esc_like($clean_name) . '%';
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_type = 'product' 
             AND post_status = 'publish' 
             AND post_title LIKE %s 
             LIMIT 1",
            $like_name
        ));
        
        if ($post_id) {
            $this->log("  → FOUND via LIKE match: ID=$post_id");
            return get_post($post_id);
        }
        
        // Product not found - try to find similar products for debugging
        $this->log("  → NOT FOUND - Searching for similar products...");
        
        // Try first 20 characters
        $partial = substr($clean_name, 0, 20);
        $similar = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} 
             WHERE post_type = 'product' 
             AND post_status = 'publish' 
             AND post_title LIKE %s 
             LIMIT 3",
            '%' . $wpdb->esc_like($partial) . '%'
        ));
        
        if ($similar) {
            $this->log("  → Similar products found:");
            foreach ($similar as $sim) {
                $this->log("    - ID: {$sim->ID}, Title: '{$sim->post_title}'");
            }
        } else {
            $this->log("  → No similar products found");
        }
        
        return null;
    }
    
    /**
     * Find term by name
     */
    private function find_term($name, $taxonomy) {
        $clean_name = strtolower(trim($name));
        
        // Log meal term searches for debugging
        if ($taxonomy === 'mealtime') {
            $this->log("Searching for meal term: '$name' (cleaned: '$clean_name')");
            $this->log("Available terms: " . implode(', ', array_keys($this->term_cache[$taxonomy] ?? [])));
        }
        
        // Direct match
        if (isset($this->term_cache[$taxonomy][$clean_name])) {
            return $this->term_cache[$taxonomy][$clean_name];
        }
        
        // Try without accents
        $no_accents = remove_accents($clean_name);
        if (isset($this->term_cache[$taxonomy][$no_accents])) {
            return $this->term_cache[$taxonomy][$no_accents];
        }
        
        // Try partial matches
        foreach ($this->term_cache[$taxonomy] as $term_name => $term) {
            // Check if one contains the other
            if (strpos($term_name, $clean_name) !== false || strpos($clean_name, $term_name) !== false) {
                return $term;
            }
            
            // Also try without accents
            $term_no_accents = remove_accents($term_name);
            if (strpos($term_no_accents, $no_accents) !== false || strpos($no_accents, $term_no_accents) !== false) {
                return $term;
            }
        }
        
        return null;
    }
    
    /**
     * Prepare assignment data
     */
    private function prepare_assignment($type, $menu_title, $week, $day, $meal) {
        // Handle English type values from Excel
        $assignment = [
            'menu_type' => strtolower($type) === 'monthly' ? 'monthly' : 'weekly'
        ];
        
        // Find menu
        $menu_term = $this->find_term($menu_title, 'program_menu');
        if (!$menu_term) return null;
        $assignment['program_menu'] = $menu_term->term_id;
        
        // Find week (for monthly)
        if ($assignment['menu_type'] === 'monthly' && !empty($week)) {
            $week_term = $this->find_term($week, 'week_no');
            if ($week_term) {
                $assignment['week'] = $week_term->term_id;
            }
        }
        
        // Find day
        if (!empty($day)) {
            $day_term = $this->find_term($day, 'weekday');
            if (!$day_term) return null;
            $assignment['day'] = $day_term->term_id;
        }
        
        // Find meal (optional)
        if (!empty($meal)) {
            // Map Greek meal names to the actual taxonomy terms
            // Note: The database uses 'Bραδινό' with Latin 'B' instead of Greek 'Β'
            $meal_mapping = [
                'Πρωινό' => 'Πρωινό',
                'Μεσημεριανό' => 'Μεσημεριανό',
                'Βραδινό' => 'Bραδινό',      // Greek B -> map to Latin B
                'Bραδινό' => 'Bραδινό',      // Latin B (from Excel and DB)
                'Δείπνο' => 'Δείπνο',
                'Σνακ' => 'Σνακ'
            ];
            
            $mapped_meal = isset($meal_mapping[$meal]) ? $meal_mapping[$meal] : $meal;
            $meal_term = $this->find_term($mapped_meal, 'mealtime');
            if ($meal_term) {
                $assignment['meal'] = $meal_term->term_id;
                $this->log("Found meal term: '$mapped_meal' (ID: {$meal_term->term_id})");
            } else {
                $this->log("WARNING: Meal term not found: '$meal' (mapped to: '$mapped_meal')");
            }
        }
        
        return $assignment;
    }
    
    /**
     * Update product assignments
     */
    private function update_product_assignments($product_id, $new_assignment, $product_name) {
        $result = ['created' => false, 'exists' => false, 'cleared' => false];
        
        // Check if we've already processed this product with clear_existing
        if ($this->options['clear_existing'] && isset($this->cleared_products[$product_id])) {
            // Get the current assignments from our tracking array instead of database
            $existing = $this->cleared_products[$product_id];
        } else {
            // Get existing assignments from database
            $existing = get_field('menu_assignments', $product_id);
            if (!is_array($existing)) {
                $existing = [];
            }
            
            // Clear existing assignments if option is set and this is first time processing this product
            if ($this->options['clear_existing'] && !empty($existing)) {
                $existing = [];
                $result['cleared'] = true;
                $this->log("Cleared existing assignments for product ID $product_id: '$product_name'");
            }
        }
        
        // Check if already exists (only if not clearing)
        if (!$this->options['clear_existing'] && $this->options['skip_existing']) {
            foreach ($existing as $assignment) {
                if ($this->assignments_match($assignment, $new_assignment)) {
                    $result['exists'] = true;
                    return $result;
                }
            }
        }
        
        // Add new assignment
        $existing[] = $new_assignment;
        
        // Update field
        if (update_field('menu_assignments', $existing, $product_id)) {
            $result['created'] = true;
            
            // If clear_existing is enabled, track the current state
            if ($this->options['clear_existing']) {
                $this->cleared_products[$product_id] = $existing;
            }
            
            // Sync to meta
            $this->sync_assignments_to_meta($product_id);
        } else {
            // Debug why update_field failed
            $this->log("ERROR: update_field failed for product ID $product_id");
            $this->log("  - Assignment data: " . json_encode($new_assignment));
            $this->log("  - Total assignments after adding: " . count($existing));
            $this->log("  - Existing assignments: " . json_encode($existing));
        }
        
        return $result;
    }
    
    /**
     * Check if assignments match
     */
    private function assignments_match($a1, $a2) {
        $fields = ['menu_type', 'program_menu', 'week', 'day', 'meal'];
        
        foreach ($fields as $field) {
            $v1 = isset($a1[$field]) ? $a1[$field] : '';
            $v2 = isset($a2[$field]) ? $a2[$field] : '';
            
            if ($v1 != $v2) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Sync assignments to meta
     */
    private function sync_assignments_to_meta($product_id) {
        $assignments = get_field('menu_assignments', $product_id);
        
        if (!empty($assignments)) {
            $simplified = [];
            foreach ($assignments as $assignment) {
                $simplified[] = [
                    'menu_type' => $assignment['menu_type'],
                    'program' => $assignment['program_menu'],
                    'week' => isset($assignment['week']) ? $assignment['week'] : '',
                    'day' => $assignment['day'],
                    'meal' => isset($assignment['meal']) ? $assignment['meal'] : ''
                ];
            }
            update_post_meta($product_id, '_menu_assignments_data', $simplified);
        }
    }
    
    /**
     * Update product prices
     */
    private function update_product_prices($product_id, $weekly_price, $monthly_price) {
        if (!empty($weekly_price)) {
            update_field('εβδομαδιαίο', $weekly_price, $product_id);
        }
        
        if (!empty($monthly_price)) {
            update_field('μηνιαίο', $monthly_price, $product_id);
        }
    }
    
    /**
     * Add log entry
     */
    private function log($message) {
        $this->log[] = [
            'time' => current_time('mysql'),
            'message' => $message
        ];
    }
}