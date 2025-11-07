/**
 * Admin JavaScript for Menu Import functionality
 */

var woopmImport = (function($) {
    'use strict';
    
    var currentBatch = 0;
    var totalBatches = 0;
    var importStats = {
        products_found: 0,
        products_not_found: 0,
        assignments_created: 0,
        assignments_updated: 0,
        errors: 0
    };
    
    /**
     * Validate import file
     */
    function validateFile() {
        var $progress = $('#validation-progress');
        var $results = $('#validation-results');
        var $actions = $('.woopm-actions');
        
        $.ajax({
            url: woopm_import.ajax_url,
            type: 'POST',
            data: {
                action: 'woopm_validate_import',
                nonce: woopm_import.nonce
            },
            success: function(response) {
                $progress.hide();
                
                if (response.success) {
                    displayValidationResults(response.data);
                    $results.show();
                    $actions.show();
                    
                    // Show appropriate buttons
                    if (response.data.ready_to_import) {
                        $('.proceed-import').show();
                    }
                    if (response.data.missing_terms && Object.keys(response.data.missing_terms).length > 0) {
                        $('.create-missing-terms').show();
                    }
                } else {
                    $results.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>').show();
                    $actions.show();
                }
            },
            error: function() {
                $progress.hide();
                $results.html('<div class="notice notice-error"><p>' + woopm_import.strings.error + '</p></div>').show();
                $actions.show();
            }
        });
    }
    
    /**
     * Display validation results
     */
    function displayValidationResults(data) {
        var html = '';
        
        // Summary
        html += '<div class="woopm-validation-summary">';
        html += '<h3>Validation Summary</h3>';
        html += '<ul>';
        html += '<li>Total rows: ' + data.total_rows + '</li>';
        html += '<li>Valid rows: ' + data.valid_rows + '</li>';
        html += '<li>Products found: ' + data.products_found + '</li>';
        html += '<li>Products missing: ' + data.products_missing + '</li>';
        html += '</ul>';
        html += '</div>';
        
        // Missing products
        if (data.missing_products && data.missing_products.length > 0) {
            html += '<div class="notice notice-warning">';
            html += '<h3>Missing Products (' + data.missing_products.length + ')</h3>';
            html += '<div class="woopm-scrollable">';
            html += '<ul>';
            data.missing_products.forEach(function(product) {
                html += '<li>' + escapeHtml(product) + '</li>';
            });
            html += '</ul>';
            html += '</div>';
            html += '</div>';
        }
        
        // Missing terms
        if (data.missing_terms && Object.keys(data.missing_terms).length > 0) {
            html += '<div class="notice notice-warning">';
            html += '<h3>Missing Taxonomy Terms</h3>';
            for (var taxonomy in data.missing_terms) {
                if (data.missing_terms[taxonomy].length > 0) {
                    html += '<h4>' + taxonomy + '</h4>';
                    html += '<ul>';
                    data.missing_terms[taxonomy].forEach(function(term) {
                        html += '<li>' + escapeHtml(term) + '</li>';
                    });
                    html += '</ul>';
                }
            }
            html += '</div>';
        }
        
        // Data issues
        if (data.data_issues && data.data_issues.length > 0) {
            html += '<div class="notice notice-info">';
            html += '<h3>Data Quality Issues</h3>';
            html += '<ul>';
            data.data_issues.forEach(function(issue) {
                html += '<li>' + escapeHtml(issue) + '</li>';
            });
            html += '</ul>';
            html += '</div>';
        }
        
        // Success message
        if (data.ready_to_import) {
            html += '<div class="notice notice-success">';
            html += '<p><strong>✓ Ready to import!</strong> All validation checks passed.</p>';
            html += '</div>';
        }
        
        $('#validation-results').html(html);
    }
    
    /**
     * Create missing terms
     */
    function createMissingTerms() {
        var $button = $('.create-missing-terms');
        $button.prop('disabled', true).text('Creating terms...');
        
        // Get missing terms from validation results
        var missingTerms = {};
        // This would be populated from the validation results
        
        $.ajax({
            url: woopm_import.ajax_url,
            type: 'POST',
            data: {
                action: 'woopm_create_missing_terms',
                nonce: woopm_import.nonce,
                terms: JSON.stringify(missingTerms)
            },
            success: function(response) {
                if (response.success) {
                    $button.hide();
                    // Re-validate to update results
                    validateFile();
                } else {
                    alert('Error creating terms');
                    $button.prop('disabled', false).text('Create Missing Terms');
                }
            },
            error: function() {
                alert('Error creating terms');
                $button.prop('disabled', false).text('Create Missing Terms');
            }
        });
    }
    
    /**
     * Start import process
     */
    function startImport() {
        var $button = $('#start-import');
        var $progress = $('#import-progress');
        var $results = $('#import-results');
        var $settings = $('#import-settings');
        
        // Get settings
        var skipExisting = true; // Always skip existing by default
        var updatePrices = $('#update-prices').is(':checked');
        var clearExisting = false; // Never clear existing
        
        $button.prop('disabled', true);
        $settings.hide();
        $progress.show();
        
        // Reset stats
        currentBatch = 0;
        importStats = {
            products_found: 0,
            products_not_found: 0,
            assignments_created: 0,
            assignments_updated: 0,
            errors: 0
        };
        
        // Start processing
        processBatch(skipExisting, updatePrices, clearExisting);
    }
    
    /**
     * Process import batch
     */
    function processBatch(skipExisting, updatePrices, clearExisting) {
        var $progressBar = $('.woopm-progress-bar-fill');
        var $progressDetails = $('.woopm-progress-details');
        
        $.ajax({
            url: woopm_import.ajax_url,
            type: 'POST',
            data: {
                action: 'woopm_run_import',
                nonce: woopm_import.nonce,
                batch: currentBatch,
                skip_existing: skipExisting,
                update_prices: updatePrices,
                clear_existing: clearExisting
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    
                    // Update stats
                    importStats.products_found += data.stats.products_found;
                    importStats.products_not_found += data.stats.products_not_found;
                    importStats.assignments_created += data.stats.assignments_created;
                    importStats.assignments_updated += data.stats.assignments_updated;
                    importStats.errors += data.stats.errors;
                    
                    // Update progress
                    var progress = (data.processed / data.total) * 100;
                    $progressBar.css('width', progress + '%');
                    $progressDetails.html(
                        'Processed ' + data.processed + ' of ' + data.total + ' rows<br>' +
                        'Products found: ' + importStats.products_found + '<br>' +
                        'Assignments created: ' + importStats.assignments_created
                    );
                    
                    // Log entries to console for debugging
                    if (data.log && data.log.length > 0) {
                        console.log('Import Log - Batch ' + currentBatch + ':');
                        data.log.forEach(function(entry) {
                            console.log(entry.message);
                            
                            // Also add to live log viewer
                            addToLiveLog(entry.message);
                        });
                    }
                    
                    if (data.has_more) {
                        currentBatch++;
                        setTimeout(function() {
                            processBatch(skipExisting, updatePrices, clearExisting);
                        }, 100);
                    } else {
                        // Import complete
                        importComplete();
                    }
                } else {
                    importError(response.data.message);
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = woopm_import.strings.error;
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                } else if (error) {
                    errorMessage += ': ' + error;
                }
                importError(errorMessage);
            }
        });
    }
    
    /**
     * Import complete
     */
    function importComplete() {
        var $progress = $('#import-progress');
        var $results = $('#import-results');
        var $progressBar = $('.woopm-progress-bar-fill');
        var $progressDetails = $('.woopm-progress-details');
        
        // Update progress to show completion
        $progressBar.css('width', '100%');
        $progress.find('> p').text('Import Complete!');
        
        // Add completion message to log
        addToLiveLog('');
        addToLiveLog('=== IMPORT COMPLETE ===');
        addToLiveLog('Total processed: ' + (importStats.products_found + importStats.products_not_found) + ' rows');
        addToLiveLog('Products found: ' + importStats.products_found);
        addToLiveLog('Products not found: ' + importStats.products_not_found);
        addToLiveLog('Assignments created: ' + importStats.assignments_created);
        addToLiveLog('Assignments updated: ' + importStats.assignments_updated);
        addToLiveLog('Errors: ' + importStats.errors);
        
        var html = '<div class="notice notice-success">';
        html += '<h3>Import Complete!</h3>';
        html += '<ul>';
        html += '<li>Products found: ' + importStats.products_found + '</li>';
        html += '<li>Products not found: ' + importStats.products_not_found + '</li>';
        html += '<li>Assignments created: ' + importStats.assignments_created + '</li>';
        html += '<li>Assignments updated: ' + importStats.assignments_updated + '</li>';
        if (importStats.errors > 0) {
            html += '<li class="error">Errors: ' + importStats.errors + '</li>';
        }
        html += '</ul>';
        html += '</div>';
        
        html += '<div class="woopm-actions">';
        html += '<a href="' + woopm_import.admin_url + 'admin.php?page=woopm-menu-import&step=complete" class="button button-primary">Continue</a>';
        html += '</div>';
        
        $results.html(html).show();
        
        // Keep the log visible but stop auto-scroll
        $('#auto-scroll').prop('checked', false);
    }
    
    /**
     * Import error
     */
    function importError(message) {
        var $progress = $('#import-progress');
        var $results = $('#import-results');
        var $button = $('#start-import');
        
        $progress.hide();
        $button.prop('disabled', false);
        
        var html = '<div class="notice notice-error">';
        html += '<p><strong>Import Error:</strong> ' + escapeHtml(message) + '</p>';
        html += '</div>';
        
        $results.html(html).show();
    }
    
    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
    /**
     * Add message to live log
     */
    function addToLiveLog(message) {
        var $logContent = $('#live-log-content');
        var timestamp = new Date().toLocaleTimeString();
        
        // Determine message type for styling
        var messageClass = 'log-entry';
        if (message.indexOf('NOT FOUND') !== -1 || message.indexOf('Error') !== -1) {
            messageClass += ' log-error';
        } else if (message.indexOf('=== Row') !== -1) {
            messageClass += ' log-row';
        } else if (message.indexOf('Looking for product') !== -1) {
            messageClass += ' log-product';
        } else if (message.indexOf('FOUND') !== -1) {
            messageClass += ' log-success';
        }
        
        var logEntry = '<div class="' + messageClass + '">' +
                      '<span class="log-time">[' + timestamp + ']</span> ' +
                      '<span class="log-message">' + escapeHtml(message) + '</span>' +
                      '</div>';
        
        $logContent.append(logEntry);
        
        // Auto-scroll if enabled
        if ($('#auto-scroll').is(':checked')) {
            var container = $('.woopm-live-log-container');
            container.scrollTop(container[0].scrollHeight);
        }
    }
    
    /**
     * Clear import log
     */
    window.clearImportLog = function() {
        $('#live-log-content').empty();
    };
    
    /**
     * Initialize
     */
    function init() {
        // Bind events
        $(document).on('click', '.create-missing-terms', createMissingTerms);
        $(document).on('click', '#start-import', startImport);
        
        // No checkbox interactions needed anymore
    }
    
    // Public methods
    return {
        init: init,
        validateFile: validateFile
    };
    
})(jQuery);

// Initialize when DOM is ready
jQuery(document).ready(function() {
    woopmImport.init();
});