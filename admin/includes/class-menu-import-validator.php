<?php
/**
 * Menu Import Validator Class
 * 
 * @package WooCommerce_Product_Menu
 */

if (!defined('ABSPATH')) {
    exit;
}

class WOOPM_Menu_Import_Validator {
    
    private $file_path;
    private $data = [];
    private $results = [
        'total_rows' => 0,
        'valid_rows' => 0,
        'products_found' => 0,
        'products_missing' => 0,
        'missing_products' => [],
        'missing_terms' => [],
        'data_issues' => [],
        'ready_to_import' => false
    ];
    
    public function __construct($file_path) {
        $this->file_path = $file_path;
    }
    
    /**
     * Validate the import file
     */
    public function validate() {
        // Parse Excel file
        $this->parse_excel();
        
        if (empty($this->data)) {
            $this->results['data_issues'][] = 'No data found in Excel file';
            return $this->results;
        }
        
        $this->results['total_rows'] = count($this->data);
        
        // Validate each row
        $products_to_check = [];
        $terms_to_check = [
            'program_menu' => [],
            'week_no' => [],
            'weekday' => [],
            'mealtime' => []
        ];
        
        foreach ($this->data as $index => $row) {
            $row_num = $index + 2; // Excel row number
            
            // Extract data - handle both Monthly and Weekly formats
            $menu_title = isset($row['Τίτλος Μενού']) ? trim($row['Τίτλος Μενού']) : '';
            $type = isset($row['Type']) ? trim($row['Type']) : '';
            
            // For Weekly type, the columns are shifted
            if (strtolower($type) === 'weekly') {
                // In Weekly format: Meal contains product name
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
            
            if (empty($product_name) && empty($menu_title)) {
                continue; // Skip empty rows
            }
            
            if (!empty($product_name)) {
                $products_to_check[$product_name] = true;
                
                if (!empty($menu_title)) {
                    $this->results['valid_rows']++;
                    $terms_to_check['program_menu'][$menu_title] = true;
                    
                    // Check week (only for monthly)
                    if (!empty($week)) {
                        $terms_to_check['week_no'][$week] = true;
                    }
                    
                    // Check day
                    if (!empty($day)) {
                        $terms_to_check['weekday'][$day] = true;
                    }
                    
                    // Check meal
                    if (!empty($meal)) {
                        $terms_to_check['mealtime'][$meal] = true;
                    }
                } else {
                    $this->results['data_issues'][] = "Row $row_num: Product '$product_name' has no menu assignment";
                }
            } else {
                $this->results['data_issues'][] = "Row $row_num: Menu assignment without product name";
            }
        }
        
        // Check products exist
        foreach (array_keys($products_to_check) as $product_name) {
            if ($this->product_exists($product_name)) {
                $this->results['products_found']++;
            } else {
                $this->results['products_missing']++;
                $this->results['missing_products'][] = $product_name;
            }
        }
        
        // Check terms exist
        foreach ($terms_to_check as $taxonomy => $terms) {
            foreach (array_keys($terms) as $term_name) {
                if (!$this->term_exists($term_name, $taxonomy)) {
                    if (!isset($this->results['missing_terms'][$taxonomy])) {
                        $this->results['missing_terms'][$taxonomy] = [];
                    }
                    $this->results['missing_terms'][$taxonomy][] = $term_name;
                }
            }
        }
        
        // Determine if ready to import
        $this->results['ready_to_import'] = (
            $this->results['valid_rows'] > 0 &&
            empty($this->results['missing_products']) &&
            empty($this->results['missing_terms'])
        );
        
        return $this->results;
    }
    
    /**
     * Parse Excel file
     */
    private function parse_excel() {
        try {
            $zip = new ZipArchive();
            if ($zip->open($this->file_path) !== TRUE) {
                throw new Exception('Cannot open Excel file');
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
            $this->results['data_issues'][] = 'Error parsing Excel: ' . $e->getMessage();
        }
    }
    
    /**
     * Check if product exists
     */
    private function product_exists($name) {
        global $wpdb;
        
        $clean_name = $this->clean_product_name($name);
        
        // Check exact match
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_type = 'product' 
             AND post_status = 'publish' 
             AND post_title = %s 
             LIMIT 1",
            $clean_name
        ));
        
        if ($exists) return true;
        
        // Check LIKE match
        $like_name = '%' . $wpdb->esc_like($clean_name) . '%';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_type = 'product' 
             AND post_status = 'publish' 
             AND post_title LIKE %s 
             LIMIT 1",
            $like_name
        ));
        
        return (bool)$exists;
    }
    
    /**
     * Check if term exists
     */
    private function term_exists($name, $taxonomy) {
        $term = get_term_by('name', $name, $taxonomy);
        return (bool)$term;
    }
    
    /**
     * Clean product name
     */
    private function clean_product_name($name) {
        // Remove extra spaces
        $name = preg_replace('/\s+/', ' ', trim($name));
        
        // Don't replace & character - keep it as is for proper matching
        // Just handle HTML entities
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return $name;
    }
}