<?php
/**
 * Functions for handling menu assignments
 * 
 * This file contains improved functions for querying products
 * based on their ACF menu_assignments field
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get products by menu assignment using ACF field
 * 
 * This function queries products based on their menu_assignments ACF repeater field
 * instead of using taxonomies. It properly filters by menu type (weekly/monthly).
 * 
 * @param int $program_menu_id The program menu term ID
 * @param int|null $week_id The week term ID (for monthly menus)
 * @param int|null $day_id The weekday term ID
 * @param int|null $meal_id The mealtime term ID
 * @return array Array of product IDs
 */
function get_products_by_menu_assignment_acf($program_menu_id, $week_id = null, $day_id = null, $meal_id = null) {
    global $wpdb;
    
    // Always log for debugging
    error_log("=== get_products_by_menu_assignment_acf ===");
    error_log("Program Menu: $program_menu_id");
    error_log("Week: " . ($week_id ?: 'null'));
    error_log("Day: " . ($day_id ?: 'null'));
    error_log("Meal: " . ($meal_id ?: 'null'));
    
    // Get the menu type from the program menu term
    $menu_type = get_term_meta($program_menu_id, 'menu_type', true);
    if (!$menu_type) {
        // Try to determine from the menu name
        $menu_term = get_term($program_menu_id, 'program_menu');
        if ($menu_term && !is_wp_error($menu_term)) {
            if (stripos($menu_term->name, 'weekly') !== false || stripos($menu_term->name, 'εβδομαδιαίο') !== false) {
                $menu_type = 'weekly';
            } elseif (stripos($menu_term->name, 'monthly') !== false || stripos($menu_term->name, 'μηνιαίο') !== false) {
                $menu_type = 'monthly';
            }
        }
    }
    
    // Get all products with menu assignments
    $args = [
        'post_type' => 'product',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => [
            [
                'key' => 'menu_assignments',
                'compare' => 'EXISTS'
            ]
        ]
    ];
    
    $products = get_posts($args);
    $matching_products = [];
    
    error_log("Checking " . count($products) . " products with menu_assignments");
    
    foreach ($products as $product_id) {
        $assignments = get_field('menu_assignments', $product_id);
        
        if (!is_array($assignments)) {
            error_log("Product $product_id has no valid assignments");
            continue;
        }
        
        error_log("Product $product_id has " . count($assignments) . " assignments");
        
        foreach ($assignments as $idx => $assignment) {
            // Log assignment details
            if ($idx == 0) { // Log first assignment for each product
                error_log("  Assignment sample: menu_type=" . ($assignment['menu_type'] ?? 'null') . 
                         ", program_menu=" . ($assignment['program_menu'] ?? 'null') .
                         ", week=" . ($assignment['week'] ?? 'null') .
                         ", day=" . ($assignment['day'] ?? 'null') .
                         ", meal=" . ($assignment['meal'] ?? 'null'));
            }
            
            // Check if assignment matches our criteria
            $matches = true;
            
            // Check menu type
            if ($menu_type && isset($assignment['menu_type']) && $assignment['menu_type'] !== $menu_type) {
                continue;
            }
            
            // Check program menu
            if ($program_menu_id && $assignment['program_menu'] != $program_menu_id) {
                continue;
            }
            
            // Check week (for monthly menus)
            if ($week_id !== null) {
                if (empty($assignment['week']) || $assignment['week'] != $week_id) {
                    continue;
                }
            }
            
            // Check day
            if ($day_id !== null) {
                if (empty($assignment['day']) || $assignment['day'] != $day_id) {
                    continue;
                }
            }
            
            // Check meal
            if ($meal_id !== null) {
                if (empty($assignment['meal']) || $assignment['meal'] != $meal_id) {
                    continue;
                }
            }
            
            // If we got here, this assignment matches
            $matching_products[] = $product_id;
            break; // No need to check other assignments for this product
        }
    }
    
    // Remove duplicates
    $matching_products = array_unique($matching_products);
    
    error_log("Final result: Found " . count($matching_products) . " matching products");
    if (!empty($matching_products)) {
        error_log("Product IDs: " . implode(', ', $matching_products));
    }
    
    return $matching_products;
}

/**
 * Override the default function
 * Comment this out to use the original taxonomy-based function
 */
/*
if (!function_exists('get_products_by_menu_assignment')) {
    function get_products_by_menu_assignment($program_menu_id, $week_id = null, $day_id = null, $meal_id = null) {
        return get_products_by_menu_assignment_acf($program_menu_id, $week_id, $day_id, $meal_id);
    }
} else {
    // If the function already exists, we'll hook in and replace it
    add_action('init', function() {
        if (function_exists('override_pluggable_function')) {
            override_pluggable_function('get_products_by_menu_assignment', 'get_products_by_menu_assignment_acf');
        }
    }, 99);
}
*/

/**
 * Get menu type for a program menu
 * 
 * @param int $program_menu_id
 * @return string 'weekly' or 'monthly'
 */
function get_program_menu_type($program_menu_id) {
    $menu_type = get_term_meta($program_menu_id, 'menu_type', true);
    
    if (!$menu_type) {
        // Try to determine from the menu name
        $menu_term = get_term($program_menu_id, 'program_menu');
        if ($menu_term && !is_wp_error($menu_term)) {
            if (stripos($menu_term->name, 'weekly') !== false || stripos($menu_term->name, 'εβδομαδιαίο') !== false) {
                $menu_type = 'weekly';
            } elseif (stripos($menu_term->name, 'monthly') !== false || stripos($menu_term->name, 'μηνιαίο') !== false) {
                $menu_type = 'monthly';
            }
        }
    }
    
    return $menu_type ?: 'weekly'; // Default to weekly if unknown
}

/**
 * Check if a product is assigned to a specific menu
 * 
 * @param int $product_id
 * @param int $program_menu_id
 * @param string $menu_type Optional menu type filter
 * @return bool
 */
function product_has_menu_assignment($product_id, $program_menu_id, $menu_type = null) {
    $assignments = get_field('menu_assignments', $product_id);
    
    if (!is_array($assignments)) {
        return false;
    }
    
    foreach ($assignments as $assignment) {
        if ($assignment['program_menu'] == $program_menu_id) {
            if ($menu_type && isset($assignment['menu_type']) && $assignment['menu_type'] !== $menu_type) {
                continue;
            }
            return true;
        }
    }
    
    return false;
}