<?php
/**
 * Admin interface for Menu Import functionality
 * 
 * @package WooCommerce_Product_Menu
 * @subpackage Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class WOOPM_Menu_Import_Admin {
    
    private $upload_dir;
    private $current_file;
    
    public function __construct() {
        // Set upload directory
        $upload = wp_upload_dir();
        $this->upload_dir = $upload['basedir'] . '/menu-imports';
        
        // Create directory if it doesn't exist
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
        }
        
        // Add admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Register AJAX handlers
        add_action('wp_ajax_woopm_validate_import', [$this, 'ajax_validate_import']);
        add_action('wp_ajax_woopm_run_import', [$this, 'ajax_run_import']);
        add_action('wp_ajax_woopm_create_missing_terms', [$this, 'ajax_create_missing_terms']);
        
        // Handle file uploads
        add_action('admin_init', [$this, 'handle_file_upload']);
        
        // Handle clear file action
        add_action('admin_init', [$this, 'handle_clear_file']);
        
        // Enqueue scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Menu Import',
            'Menu Import',
            'manage_options',
            'woopm-menu-import',
            [$this, 'render_import_page']
        );
        
        add_submenu_page(
            'woocommerce',
            'Menu Assignments',
            'Menu Assignments',
            'manage_options',
            'woopm-menu-assignments',
            [$this, 'render_assignments_page']
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook) {
        if (!in_array($hook, ['woocommerce_page_woopm-menu-import', 'woocommerce_page_woopm-menu-assignments'])) {
            return;
        }
        
        wp_enqueue_script(
            'woopm-import-admin',
            WOOPM_PLUGIN_URL . 'admin/js/import-admin.js',
            ['jquery'],
            '1.0.0',
            true
        );
        
        wp_localize_script('woopm-import-admin', 'woopm_import', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'admin_url' => admin_url(),
            'nonce' => wp_create_nonce('woopm_import_nonce'),
            'strings' => [
                'validating' => __('Validating file...', 'woopm'),
                'importing' => __('Importing data...', 'woopm'),
                'complete' => __('Import complete!', 'woopm'),
                'error' => __('An error occurred', 'woopm')
            ]
        ]);
        
        wp_enqueue_style(
            'woopm-import-admin',
            WOOPM_PLUGIN_URL . 'admin/css/import-admin.css',
            [],
            '1.0.0'
        );
    }
    
    /**
     * Handle file upload
     */
    public function handle_file_upload() {
        if (!isset($_POST['woopm_upload_nonce']) || !wp_verify_nonce($_POST['woopm_upload_nonce'], 'woopm_upload_file')) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (empty($_FILES['import_file']['name'])) {
            add_settings_error('woopm_import', 'no_file', __('Please select a file to upload.', 'woopm'));
            return;
        }
        
        $file = $_FILES['import_file'];
        $allowed_types = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'application/octet-stream', // Sometimes Excel files are detected as this
            'application/zip' // Excel files are essentially ZIP files
        ];
        
        // Also check file extension as MIME type detection can be unreliable
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['xlsx', 'xls'];
        
        if (!in_array($file['type'], $allowed_types) && !in_array($file_ext, $allowed_extensions)) {
            add_settings_error('woopm_import', 'invalid_type', 
                sprintf(__('Please upload a valid Excel file. Detected type: %s, Extension: %s', 'woopm'), 
                    $file['type'], $file_ext));
            return;
        }
        
        // Check if upload directory exists and is writable
        if (!file_exists($this->upload_dir)) {
            if (!wp_mkdir_p($this->upload_dir)) {
                add_settings_error('woopm_import', 'dir_failed', 
                    sprintf(__('Failed to create upload directory: %s', 'woopm'), $this->upload_dir));
                return;
            }
        }
        
        if (!is_writable($this->upload_dir)) {
            add_settings_error('woopm_import', 'dir_not_writable', 
                sprintf(__('Upload directory is not writable: %s', 'woopm'), $this->upload_dir));
            return;
        }
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form',
                UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
            ];
            
            $error_message = isset($upload_errors[$file['error']]) 
                ? $upload_errors[$file['error']] 
                : 'Unknown upload error';
                
            add_settings_error('woopm_import', 'upload_error', 
                sprintf(__('Upload error: %s', 'woopm'), $error_message));
            return;
        }
        
        // Generate unique filename
        $filename = 'import-' . date('Y-m-d-His') . '-' . sanitize_file_name($file['name']);
        $filepath = $this->upload_dir . '/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Store current file in option
            update_option('woopm_current_import_file', $filepath);
            add_settings_error('woopm_import', 'upload_success', __('File uploaded successfully. You can now validate the import.', 'woopm'), 'success');
        } else {
            add_settings_error('woopm_import', 'upload_failed', 
                sprintf(__('Failed to move uploaded file. Check permissions on: %s', 'woopm'), $this->upload_dir));
        }
    }
    
    /**
     * Handle clear file action
     */
    public function handle_clear_file() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'clear_file') {
            return;
        }
        
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'clear_file')) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Delete current file option
        delete_option('woopm_current_import_file');
        
        // Redirect back to upload page
        wp_redirect(admin_url('admin.php?page=woopm-menu-import'));
        exit;
    }
    
    /**
     * Render import page
     */
    public function render_import_page() {
        $current_file = get_option('woopm_current_import_file');
        $step = isset($_GET['step']) ? sanitize_text_field($_GET['step']) : 'upload';
        
        ?>
        <div class="wrap woopm-import-wrap">
            <h1><?php _e('Menu Import', 'woopm'); ?></h1>
            
            <?php settings_errors('woopm_import'); ?>
            
            <div class="woopm-import-steps">
                <div class="step <?php echo $step === 'upload' ? 'active' : ''; ?>">
                    <span class="step-number">1</span>
                    <span class="step-title"><?php _e('Upload File', 'woopm'); ?></span>
                </div>
                <div class="step <?php echo $step === 'validate' ? 'active' : ''; ?>">
                    <span class="step-number">2</span>
                    <span class="step-title"><?php _e('Validate Data', 'woopm'); ?></span>
                </div>
                <div class="step <?php echo $step === 'import' ? 'active' : ''; ?>">
                    <span class="step-number">3</span>
                    <span class="step-title"><?php _e('Import', 'woopm'); ?></span>
                </div>
                <div class="step <?php echo $step === 'complete' ? 'active' : ''; ?>">
                    <span class="step-number">4</span>
                    <span class="step-title"><?php _e('Complete', 'woopm'); ?></span>
                </div>
            </div>
            
            <div class="woopm-import-content">
                <?php
                switch ($step) {
                    case 'validate':
                        $this->render_validate_step($current_file);
                        break;
                    case 'import':
                        $this->render_import_step($current_file);
                        break;
                    case 'complete':
                        $this->render_complete_step();
                        break;
                    default:
                        $this->render_upload_step($current_file);
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render upload step
     */
    private function render_upload_step($current_file) {
        ?>
        <div class="woopm-upload-section">
            <h2><?php _e('Step 1: Upload Excel File', 'woopm'); ?></h2>
            
            <?php if ($current_file && file_exists($current_file)): ?>
                <div class="notice notice-info">
                    <p><?php printf(__('Current file: %s', 'woopm'), basename($current_file)); ?></p>
                    <p>
                        <a href="<?php echo admin_url('admin.php?page=woopm-menu-import&step=validate'); ?>" class="button button-primary">
                            <?php _e('Proceed to Validation', 'woopm'); ?>
                        </a>
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=woopm-menu-import&action=clear_file'), 'clear_file'); ?>" class="button">
                            <?php _e('Upload New File', 'woopm'); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>
            
            <form method="post" enctype="multipart/form-data" class="woopm-upload-form">
                <?php wp_nonce_field('woopm_upload_file', 'woopm_upload_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="import_file"><?php _e('Select Excel File', 'woopm'); ?></label>
                        </th>
                        <td>
                            <input type="file" name="import_file" id="import_file" accept=".xlsx,.xls" required>
                            <p class="description"><?php _e('Upload an Excel file containing menu assignments.', 'woopm'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary"><?php _e('Upload File', 'woopm'); ?></button>
                </p>
            </form>
            
            <div class="woopm-help-section">
                <h3><?php _e('File Format', 'woopm'); ?></h3>
                <p><?php _e('Your Excel file should contain the following columns:', 'woopm'); ?></p>
                <ul>
                    <li><strong>Type</strong> - Μηνιαίο or Εβδομαδιαίο</li>
                    <li><strong>Τίτλος Μενού</strong> - Menu name</li>
                    <li><strong>Εβδομάδα</strong> - Week (for monthly menus)</li>
                    <li><strong>Ημέρα</strong> - Day of the week</li>
                    <li><strong>Meal</strong> - Meal type (optional)</li>
                    <li><strong>Γεύμα(doc αρχείο)</strong> - Product name</li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render validate step
     */
    private function render_validate_step($current_file) {
        ?>
        <div class="woopm-validate-section">
            <h2><?php _e('Step 2: Validate Data', 'woopm'); ?></h2>
            
            <div id="validation-progress" class="notice notice-info">
                <p><?php _e('Validating file...', 'woopm'); ?></p>
                <div class="woopm-progress-bar">
                    <div class="woopm-progress-bar-fill"></div>
                </div>
            </div>
            
            <div id="validation-results" style="display: none;">
                <!-- Results will be loaded via AJAX -->
            </div>
            
            <div class="woopm-actions" style="display: none;">
                <a href="<?php echo admin_url('admin.php?page=woopm-menu-import&step=import'); ?>" class="button button-primary proceed-import" style="display: none;">
                    <?php _e('Proceed to Import', 'woopm'); ?>
                </a>
                <button type="button" class="button create-missing-terms" style="display: none;">
                    <?php _e('Create Missing Terms', 'woopm'); ?>
                </button>
                <a href="<?php echo admin_url('admin.php?page=woopm-menu-import'); ?>" class="button">
                    <?php _e('Back to Upload', 'woopm'); ?>
                </a>
            </div>
        </div>
        
        <script>
            jQuery(document).ready(function($) {
                // Auto-start validation
                woopmImport.validateFile();
            });
        </script>
        <?php
    }
    
    /**
     * Render import step
     */
    private function render_import_step($current_file) {
        ?>
        <div class="woopm-import-section">
            <h2><?php _e('Step 3: Import Data', 'woopm'); ?></h2>
            
            <div class="notice notice-warning">
                <p><?php _e('Warning: This will update product menu assignments. Make sure you have a backup.', 'woopm'); ?></p>
            </div>
            
            <div id="import-settings">
                <h3><?php _e('Import Options', 'woopm'); ?></h3>
                <label>
                    <input type="checkbox" id="update-prices">
                    <?php _e('Update custom prices if provided', 'woopm'); ?>
                </label>
                <p class="description">
                    <?php _e('Note: To remove all existing assignments before importing new ones, use the "Remove All Assignments" button in the Tools tab.', 'woopm'); ?>
                </p>
            </div>
            
            <div id="import-progress" style="display: none;">
                <p><?php _e('Importing data...', 'woopm'); ?></p>
                <div class="woopm-progress-bar">
                    <div class="woopm-progress-bar-fill"></div>
                </div>
                <div class="woopm-progress-details"></div>
                
                <!-- Live Import Log -->
                <div id="import-live-log" style="margin-top: 20px;">
                    <h3><?php _e('Import Log', 'woopm'); ?></h3>
                    <div class="log-controls">
                        <label>
                            <input type="checkbox" id="auto-scroll" checked>
                            <?php _e('Auto-scroll', 'woopm'); ?>
                        </label>
                        <button type="button" class="button button-small" onclick="clearImportLog()">
                            <?php _e('Clear Log', 'woopm'); ?>
                        </button>
                    </div>
                    <div class="woopm-live-log-container">
                        <div id="live-log-content"></div>
                    </div>
                </div>
            </div>
            
            <div id="import-results" style="display: none;">
                <!-- Results will be loaded via AJAX -->
            </div>
            
            <div class="woopm-actions">
                <button type="button" class="button button-primary" id="start-import">
                    <?php _e('Start Import', 'woopm'); ?>
                </button>
                <a href="<?php echo admin_url('admin.php?page=woopm-menu-import&step=validate'); ?>" class="button">
                    <?php _e('Back to Validation', 'woopm'); ?>
                </a>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render complete step
     */
    private function render_complete_step() {
        // Clear the current file
        delete_option('woopm_current_import_file');
        
        // Get import log
        $import_log = get_transient('woopm_import_log_' . get_current_user_id());
        
        ?>
        <div class="woopm-complete-section">
            <h2><?php _e('Import Complete!', 'woopm'); ?></h2>
            
            <div class="notice notice-success">
                <p><?php _e('Your menu assignments have been imported successfully.', 'woopm'); ?></p>
            </div>
            
            <?php if ($import_log && is_array($import_log)): ?>
            <div class="woopm-import-log-viewer">
                <h3><?php _e('Import Log', 'woopm'); ?></h3>
                <p><?php _e('Detailed log of the import process:', 'woopm'); ?></p>
                
                <div class="log-filters">
                    <button class="button" onclick="filterLog('all')"><?php _e('Show All', 'woopm'); ?></button>
                    <button class="button" onclick="filterLog('error')"><?php _e('Errors Only', 'woopm'); ?></button>
                    <button class="button" onclick="filterLog('product')"><?php _e('Product Searches', 'woopm'); ?></button>
                    <button class="button" onclick="filterLog('row')"><?php _e('Row Data', 'woopm'); ?></button>
                </div>
                
                <div class="woopm-log-container">
                    <pre><?php
                    foreach ($import_log as $entry) {
                        $class = '';
                        if (strpos($entry['message'], 'NOT FOUND') !== false || strpos($entry['message'], 'Error') !== false) {
                            $class = 'log-error';
                        } elseif (strpos($entry['message'], '=== Row') !== false) {
                            $class = 'log-row';
                        } elseif (strpos($entry['message'], 'Looking for product') !== false) {
                            $class = 'log-product';
                        }
                        
                        echo '<span class="log-entry ' . $class . '" data-type="' . $class . '">';
                        echo '[' . date('H:i:s', $entry['time']) . '] ' . esc_html($entry['message']);
                        echo "</span>\n";
                    }
                    ?></pre>
                </div>
            </div>
            
            <script>
            function filterLog(type) {
                const entries = document.querySelectorAll('.log-entry');
                entries.forEach(entry => {
                    if (type === 'all') {
                        entry.style.display = 'block';
                    } else if (type === 'error' && entry.classList.contains('log-error')) {
                        entry.style.display = 'block';
                    } else if (type === 'product' && entry.classList.contains('log-product')) {
                        entry.style.display = 'block';
                    } else if (type === 'row' && entry.classList.contains('log-row')) {
                        entry.style.display = 'block';
                    } else {
                        entry.style.display = 'none';
                    }
                });
            }
            </script>
            <?php endif; ?>
            
            <div class="woopm-actions">
                <a href="<?php echo admin_url('admin.php?page=woopm-menu-assignments'); ?>" class="button button-primary">
                    <?php _e('View Menu Assignments', 'woopm'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=woopm-menu-import'); ?>" class="button">
                    <?php _e('Import Another File', 'woopm'); ?>
                </a>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render assignments page
     */
    public function render_assignments_page() {
        require_once WOOPM_PLUGIN_DIR . 'admin/includes/class-menu-assignments-viewer.php';
        $viewer = new WOOPM_Menu_Assignments_Viewer();
        $viewer->render();
    }
    
    /**
     * AJAX handler for validation
     */
    public function ajax_validate_import() {
        check_ajax_referer('woopm_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        $current_file = get_option('woopm_current_import_file');
        if (!$current_file || !file_exists($current_file)) {
            wp_send_json_error(['message' => __('No file found.', 'woopm')]);
        }
        
        require_once WOOPM_PLUGIN_DIR . 'admin/includes/class-menu-import-validator.php';
        $validator = new WOOPM_Menu_Import_Validator($current_file);
        $results = $validator->validate();
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX handler for import
     */
    public function ajax_run_import() {
        check_ajax_referer('woopm_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        $current_file = get_option('woopm_current_import_file');
        if (!$current_file || !file_exists($current_file)) {
            wp_send_json_error(['message' => __('No file found.', 'woopm')]);
            return;
        }
        
        $skip_existing = isset($_POST['skip_existing']) && $_POST['skip_existing'] === 'true';
        $update_prices = isset($_POST['update_prices']) && $_POST['update_prices'] === 'true';
        $clear_existing = isset($_POST['clear_existing']) && $_POST['clear_existing'] === 'true';
        
        try {
            require_once WOOPM_PLUGIN_DIR . 'admin/includes/class-menu-import-processor.php';
            $processor = new WOOPM_Menu_Import_Processor($current_file, [
                'skip_existing' => $skip_existing,
                'update_prices' => $update_prices,
                'clear_existing' => $clear_existing
            ]);
            
            $batch = isset($_POST['batch']) ? intval($_POST['batch']) : 0;
            $results = $processor->process_batch($batch);
            
            wp_send_json_success($results);
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => sprintf(__('Import error: %s', 'woopm'), $e->getMessage()),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
    
    /**
     * AJAX handler for creating missing terms
     */
    public function ajax_create_missing_terms() {
        check_ajax_referer('woopm_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        $terms_data = isset($_POST['terms']) ? json_decode(stripslashes($_POST['terms']), true) : [];
        $created = [];
        
        foreach ($terms_data as $taxonomy => $terms) {
            foreach ($terms as $term_name) {
                $result = wp_insert_term($term_name, $taxonomy);
                if (!is_wp_error($result)) {
                    $created[] = [
                        'taxonomy' => $taxonomy,
                        'term' => $term_name,
                        'id' => $result['term_id']
                    ];
                }
            }
        }
        
        wp_send_json_success(['created' => $created]);
    }
}