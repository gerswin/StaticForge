<?php
/**
 * Bootstrap for StaticForge Unit Tests
 */

// Define WordPress constants for testing
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../../');
}

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}

if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}

// Mock WordPress functions for testing
if (!function_exists('add_action')) {
    function add_action($hook, $function, $priority = 10, $accepted_args = 1) {
        // Mock implementation
    }
}

if (!function_exists('add_menu_page')) {
    function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $function = '', $icon_url = '', $position = null) {
        // Mock implementation
    }
}

if (!function_exists('add_submenu_page')) {
    function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function = '') {
        // Mock implementation
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false) {
        // Mock implementation
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $object_name, $l10n) {
        // Mock implementation
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '', $scheme = 'admin') {
        return 'http://example.com/wp-admin/' . $path;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) {
        return 'test_nonce_' . $action;
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) {
        return true; // Always pass in tests
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return true; // Always pass in tests
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = array()) {
        throw new Exception($message);
    }
}

if (!function_exists('get_pages')) {
    function get_pages($args = array()) {
        $page = new stdClass();
        $page->ID = 1;
        $page->post_title = 'Test Page';
        $page->post_status = 'publish';
        $page->post_name = 'test-page';
        return array($page);
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        $options = array(
            'page_on_front' => 0,
            'spg_aws_access_key' => '',
            'spg_aws_secret_key' => '',
            'spg_s3_bucket' => '',
            'spg_s3_region' => 'us-east-1'
        );
        return isset($options[$option]) ? $options[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        return true;
    }
}

if (!function_exists('get_post')) {
    function get_post($post_id = null) {
        if ($post_id == 0) {
            return null;
        }
        $post = new stdClass();
        $post->ID = $post_id;
        $post->post_title = 'Test Post';
        $post->post_name = 'test-post';
        $post->post_type = 'page';
        $post->post_status = 'publish';
        return $post;
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink($post_id = 0) {
        return 'http://example.com/test-page/';
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '', $scheme = null) {
        return 'http://example.com/' . $path;
    }
}

if (!function_exists('get_site_url')) {
    function get_site_url() {
        return 'http://example.com';
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($time = null) {
        return array(
            'basedir' => '/tmp/wp-uploads',
            'baseurl' => 'http://example.com/wp-content/uploads'
        );
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($target) {
        return mkdir($target, 0755, true);
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = array()) {
        return array(
            'body' => '<html><head><title>Test</title></head><body><h1>Test Page</h1></body></html>',
            'response' => array('code' => 200)
        );
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = array()) {
        return array(
            'response' => array('code' => 200)
        );
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        return isset($response['body']) ? $response['body'] : '';
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return isset($response['response']['code']) ? $response['response']['code'] : 200;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return false; // Never error in tests
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null) {
        echo json_encode(array('success' => true, 'data' => $data));
        exit;
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null) {
        echo json_encode(array('success' => false, 'data' => $data));
        exit;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        return $single ? '1' : array('1');
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '') {
        return true;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}

if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($filename) {
        return preg_replace('/[^a-zA-Z0-9-_.]/', '-', $filename);
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('checked')) {
    function checked($checked, $current = true, $echo = true) {
        return $checked == $current ? 'checked="checked"' : '';
    }
}

if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true) {
        return $selected == $current ? 'selected="selected"' : '';
    }
}

if (!function_exists('submit_button')) {
    function submit_button($text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null) {
        echo '<input type="submit" name="submit" value="Guardar cambios" class="button button-primary">';
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $function) {
        // Mock implementation for testing
    }
}

// Mock global $wpdb for testing
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public $prefix = 'wp_';
        
        public function get_charset_collate() {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }
        
        public function replace($table, $data, $format) {
            return true;
        }
        
        public function get_row($query, $output = OBJECT, $y = 0) {
            return array(
                'page_id' => 1,
                'generated_url' => 'http://example.com/static/test-page.html',
                'storage_type' => 'local',
                'generated_at' => date('Y-m-d H:i:s')
            );
        }
        
        public function prepare($query, ...$args) {
            return $query;
        }
    };
}

if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) {
        return date('Y-m-d H:i:s');
    }
}

// Load the plugin file
require_once dirname(__FILE__) . '/../static-page-generator.php';