jQuery(document).ready(function($) {
    // Show week field for all menu types - user can leave it empty for weekly menus
    function showAllWeekFields() {
        $('.acf-row:not(.acf-clone)').each(function() {
            var $row = $(this);
            var $weekField = $row.find('[data-name="week"]').closest('.acf-field');
            
            // Always show the week field
            $weekField.show();
            
            // Remove required attribute so it can be left empty
            $weekField.find('select').attr('required', false);
        });
    }
    
    // Run on page load
    if (typeof acf !== 'undefined') {
        acf.addAction('ready', function() {
            setTimeout(showAllWeekFields, 250);
        });
        
        // Run when repeater rows are added
        acf.addAction('append', function($el) {
            setTimeout(showAllWeekFields, 100);
        });
    }
    
    // Initial show with delay
    setTimeout(showAllWeekFields, 500);
});