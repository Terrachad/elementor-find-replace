<?php
/**
 * Plugin Name: Elementor Find & Replace
 * Description: Simple plugin to find and replace text across all Elementor pages
 * Version: 1.0
 * Author: TerraChad / Vladyslav Olshevskyi
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ElementorFindReplace {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_elementor_find_replace_preview', array($this, 'ajax_preview'));
        add_action('wp_ajax_elementor_find_replace_execute', array($this, 'ajax_execute'));
    }
    
    public function add_admin_menu() {
        add_management_page(
            'Elementor Find & Replace',
            'Elementor Find & Replace',
            'manage_options',
            'elementor-find-replace',
            array($this, 'admin_page')
        );
    }
    
    public function enqueue_scripts($hook) {
        if ($hook !== 'tools_page_elementor-find-replace') {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'elementor_fr_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('elementor_find_replace_nonce')
        ));
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Elementor Find & Replace</h1>
            <div class="notice notice-warning">
                <p><strong>Important:</strong> Always backup your database before running find & replace operations!</p>
            </div>
            
            <form id="elementor-find-replace-form">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="find_text">Find Text</label>
                        </th>
                        <td>
                            <input type="text" id="find_text" name="find_text" class="regular-text" required />
                            <p class="description">Enter the text you want to find (case-sensitive)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="replace_text">Replace With</label>
                        </th>
                        <td>
                            <input type="text" id="replace_text" name="replace_text" class="regular-text" />
                            <p class="description">Enter the replacement text (leave empty to remove the text)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="case_sensitive">Options</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="case_sensitive" name="case_sensitive" checked />
                                Case sensitive search
                            </label>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="button" id="preview-btn" class="button">Preview Changes</button>
                    <button type="button" id="execute-btn" class="button button-primary" disabled>Execute Replace</button>
                </p>
            </form>
            
            <div id="results" style="margin-top: 20px;"></div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#preview-btn').click(function() {
                var findText = $('#find_text').val();
                var replaceText = $('#replace_text').val();
                var caseSensitive = $('#case_sensitive').is(':checked');
                
                if (!findText) {
                    alert('Please enter text to find');
                    return;
                }
                
                $('#results').html('<p>Searching...</p>');
                $('#execute-btn').prop('disabled', true);
                
                $.ajax({
                    url: elementor_fr_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'elementor_find_replace_preview',
                        find_text: findText,
                        replace_text: replaceText,
                        case_sensitive: caseSensitive,
                        nonce: elementor_fr_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#results').html(response.data.html);
                            if (response.data.count > 0) {
                                $('#execute-btn').prop('disabled', false);
                            }
                        } else {
                            $('#results').html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $('#results').html('<div class="notice notice-error"><p>Error occurred during preview</p></div>');
                    }
                });
            });
            
            $('#execute-btn').click(function() {
                if (!confirm('Are you sure you want to execute this find & replace? This cannot be undone!')) {
                    return;
                }
                
                var findText = $('#find_text').val();
                var replaceText = $('#replace_text').val();
                var caseSensitive = $('#case_sensitive').is(':checked');
                
                $('#results').html('<p>Executing replace operation...</p>');
                $(this).prop('disabled', true);
                
                $.ajax({
                    url: elementor_fr_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'elementor_find_replace_execute',
                        find_text: findText,
                        replace_text: replaceText,
                        case_sensitive: caseSensitive,
                        nonce: elementor_fr_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#results').html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                        } else {
                            $('#results').html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $('#results').html('<div class="notice notice-error"><p>Error occurred during execution</p></div>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function ajax_preview() {
        check_ajax_referer('elementor_find_replace_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $find_text = sanitize_text_field($_POST['find_text']);
        $replace_text = sanitize_text_field($_POST['replace_text']);
        $case_sensitive = isset($_POST['case_sensitive']) && $_POST['case_sensitive'] === 'true';
        
        $results = $this->find_in_elementor_pages($find_text, $case_sensitive);
        
        if (empty($results)) {
            wp_send_json_success(array(
                'html' => '<div class="notice notice-info"><p>No occurrences found.</p></div>',
                'count' => 0
            ));
        }
        
        $html = '<div class="notice notice-info"><p>Found ' . count($results) . ' page(s) with occurrences:</p></div>';
        $html .= '<table class="wp-list-table widefat fixed striped">';
        $html .= '<thead><tr><th>Page Title</th><th>Page ID</th><th>Occurrences</th></tr></thead><tbody>';
        
        foreach ($results as $result) {
            $html .= '<tr>';
            $html .= '<td><a href="' . get_edit_post_link($result['post_id']) . '">' . esc_html($result['post_title']) . '</a></td>';
            $html .= '<td>' . $result['post_id'] . '</td>';
            $html .= '<td>' . $result['occurrences'] . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        
        wp_send_json_success(array(
            'html' => $html,
            'count' => count($results)
        ));
    }
    
    public function ajax_execute() {
        check_ajax_referer('elementor_find_replace_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $find_text = sanitize_text_field($_POST['find_text']);
        $replace_text = sanitize_text_field($_POST['replace_text']);
        $case_sensitive = isset($_POST['case_sensitive']) && $_POST['case_sensitive'] === 'true';
        
        $count = $this->replace_in_elementor_pages($find_text, $replace_text, $case_sensitive);
        
        // Clear Elementor cache
        if (class_exists('\Elementor\Plugin')) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }
        
        wp_send_json_success("Successfully replaced text in {$count} page(s). Elementor cache has been cleared.");
    }
    
    private function find_in_elementor_pages($find_text, $case_sensitive = true) {
        global $wpdb;
        
        // Get all posts with Elementor data
        $posts = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, pm.meta_value 
            FROM {$wpdb->posts} p 
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
            WHERE pm.meta_key = '_elementor_data' 
            AND p.post_status = 'publish'
            AND pm.meta_value != ''
        "));
        
        $results = array();
        
        foreach ($posts as $post) {
            $elementor_data = $post->meta_value;
            
            if ($case_sensitive) {
                $occurrences = substr_count($elementor_data, $find_text);
            } else {
                $occurrences = substr_count(strtolower($elementor_data), strtolower($find_text));
            }
            
            if ($occurrences > 0) {
                $results[] = array(
                    'post_id' => $post->ID,
                    'post_title' => $post->post_title,
                    'occurrences' => $occurrences
                );
            }
        }
        
        return $results;
    }
    
    private function replace_in_elementor_pages($find_text, $replace_text, $case_sensitive = true) {
        global $wpdb;
        
        // Get all posts with Elementor data
        $posts = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, pm.meta_value 
            FROM {$wpdb->posts} p 
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
            WHERE pm.meta_key = '_elementor_data' 
            AND p.post_status = 'publish'
            AND pm.meta_value != ''
        "));
        
        $updated_count = 0;
        
        foreach ($posts as $post) {
            $elementor_data = $post->meta_value;
            $original_data = $elementor_data;
            
            if ($case_sensitive) {
                $new_data = str_replace($find_text, $replace_text, $elementor_data);
            } else {
                $new_data = str_ireplace($find_text, $replace_text, $elementor_data);
            }
            
            // Only update if data changed
            if ($new_data !== $original_data) {
                $wpdb->update(
                    $wpdb->postmeta,
                    array('meta_value' => $new_data),
                    array(
                        'post_id' => $post->ID,
                        'meta_key' => '_elementor_data'
                    )
                );
                
                // Update the post modified time
                wp_update_post(array(
                    'ID' => $post->ID,
                    'post_modified' => current_time('mysql'),
                    'post_modified_gmt' => current_time('mysql', 1)
                ));
                
                $updated_count++;
            }
        }
        
        return $updated_count;
    }
}

// Initialize the plugin
new ElementorFindReplace();
?>