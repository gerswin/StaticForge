<?php
/**
 * Plugin Name: StaticForge
 * Description: Convierte WP en HTML sólido
 * Version: 1.0
 * Author: Gerswin Pineda
 */

if (!defined('ABSPATH')) {
    exit;
}

class StaticPageGenerator {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_generate_static_page', array($this, 'generate_static_page'));
        add_action('wp_ajax_toggle_autoupdate', array($this, 'toggle_autoupdate'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('post_updated', array($this, 'handle_page_update'), 10, 3);
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'StaticForge',
            'StaticForge',
            'manage_options',
            'static-page-generator',
            array($this, 'admin_page'),
            'dashicons-hammer',
            30
        );
        
        add_submenu_page(
            'static-page-generator',
            'Configuración S3',
            'Configuración S3',
            'manage_options',
            'static-page-config',
            array($this, 'config_page')
        );
    }
    
    public function enqueue_scripts($hook) {
        if ($hook != 'toplevel_page_static-page-generator') {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'spg_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('spg_nonce'),
            'autoupdate_nonce' => wp_create_nonce('spg_autoupdate_nonce')
        ));
    }
    
    public function admin_page() {
        $pages = get_pages();
        
        // Agregar página home si está configurada
        $home_page_id = get_option('page_on_front');
        if ($home_page_id) {
            $home_page = get_post($home_page_id);
            if ($home_page) {
                // Verificar si ya está en la lista
                $found = false;
                foreach ($pages as $page) {
                    if ($page->ID == $home_page_id) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    array_unshift($pages, $home_page);
                }
            }
        } else {
            // Si no hay página estática configurada, crear entrada para home
            $home_page = new stdClass();
            $home_page->ID = 0;
            $home_page->post_title = 'Página de Inicio (Home)';
            $home_page->post_status = 'publish';
            $home_page->post_name = 'home';
            array_unshift($pages, $home_page);
        }
        ?>
        <div class="wrap">
            <h1>StaticForge - Convierte WP en HTML sólido</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>URL</th>
                        <th>Estado</th>
                        <th>Auto-update</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pages as $page): 
                        $autoupdate_enabled = get_post_meta($page->ID, 'spg_autoupdate', true);
                        $page_url = ($page->ID == 0) ? home_url('/') : get_permalink($page->ID);
                    ?>
                    <tr>
                        <td><?php echo esc_html($page->post_title); ?></td>
                        <td><?php echo $page_url; ?></td>
                        <td><?php echo ucfirst($page->post_status); ?></td>
                        <td>
                            <input type="checkbox" 
                                   class="autoupdate-checkbox" 
                                   data-page-id="<?php echo $page->ID; ?>"
                                   <?php checked($autoupdate_enabled, '1'); ?> />
                        </td>
                        <td>
                            <button class="button button-primary generate-static" 
                                    data-page-id="<?php echo $page->ID; ?>"
                                    data-page-title="<?php echo esc_attr($page->post_title); ?>">
                                🔨 Forjar HTML
                            </button>
                            <span class="spinner" style="float: none; margin: 0 5px;"></span>
                            <span class="result-message"></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.generate-static').on('click', function() {
                var button = $(this);
                var spinner = button.siblings('.spinner');
                var message = button.siblings('.result-message');
                var pageId = button.data('page-id');
                var pageTitle = button.data('page-title');
                
                button.prop('disabled', true);
                spinner.addClass('is-active');
                message.text('');
                
                $.ajax({
                    url: spg_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'generate_static_page',
                        page_id: pageId,
                        page_title: pageTitle,
                        nonce: spg_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            message.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                        } else {
                            message.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                        }
                    },
                    error: function() {
                        message.html('<span style="color: red;">✗ Error en la comunicación</span>');
                    },
                    complete: function() {
                        button.prop('disabled', false);
                        spinner.removeClass('is-active');
                    }
                });
            });
            
            $('.autoupdate-checkbox').on('change', function() {
                var checkbox = $(this);
                var pageId = checkbox.data('page-id');
                var enabled = checkbox.is(':checked') ? 1 : 0;
                
                $.ajax({
                    url: spg_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'toggle_autoupdate',
                        page_id: pageId,
                        enabled: enabled,
                        nonce: spg_ajax.autoupdate_nonce
                    },
                    error: function() {
                        checkbox.prop('checked', !checkbox.is(':checked'));
                        alert('Error al actualizar la configuración de auto-update');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function config_page() {
        if (isset($_POST['submit'])) {
            update_option('spg_aws_access_key', sanitize_text_field($_POST['aws_access_key']));
            update_option('spg_aws_secret_key', sanitize_text_field($_POST['aws_secret_key']));
            update_option('spg_s3_bucket', sanitize_text_field($_POST['s3_bucket']));
            update_option('spg_s3_region', sanitize_text_field($_POST['s3_region']));
            echo '<div class="notice notice-success"><p>Configuración guardada correctamente.</p></div>';
        }
        
        $aws_access_key = get_option('spg_aws_access_key', '');
        $aws_secret_key = get_option('spg_aws_secret_key', '');
        $s3_bucket = get_option('spg_s3_bucket', '');
        $s3_region = get_option('spg_s3_region', 'us-east-1');
        ?>
        <div class="wrap">
            <h1>Configuración de S3</h1>
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row">AWS Access Key</th>
                        <td><input type="text" name="aws_access_key" value="<?php echo esc_attr($aws_access_key); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row">AWS Secret Key</th>
                        <td><input type="password" name="aws_secret_key" value="<?php echo esc_attr($aws_secret_key); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row">Bucket de S3</th>
                        <td><input type="text" name="s3_bucket" value="<?php echo esc_attr($s3_bucket); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row">Región de S3</th>
                        <td>
                            <select name="s3_region">
                                <option value="us-east-1" <?php selected($s3_region, 'us-east-1'); ?>>US East (N. Virginia)</option>
                                <option value="us-west-1" <?php selected($s3_region, 'us-west-1'); ?>>US West (N. California)</option>
                                <option value="us-west-2" <?php selected($s3_region, 'us-west-2'); ?>>US West (Oregon)</option>
                                <option value="eu-west-1" <?php selected($s3_region, 'eu-west-1'); ?>>Europe (Ireland)</option>
                                <option value="ap-southeast-1" <?php selected($s3_region, 'ap-southeast-1'); ?>>Asia Pacific (Singapore)</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    public function generate_static_page() {
        if (!wp_verify_nonce($_POST['nonce'], 'spg_nonce')) {
            wp_die('Error de seguridad');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        $page_id = intval($_POST['page_id']);
        $page_title = sanitize_text_field($_POST['page_title']);
        
        $html_content = $this->get_page_html($page_id);
        
        if (!$html_content) {
            wp_send_json_error(array('message' => 'Error al obtener el contenido de la página'));
            return;
        }
        
        if ($this->s3_credentials_configured()) {
            $result = $this->upload_to_s3($html_content, $page_title, $page_id);
            
            if ($result) {
                wp_send_json_success(array('message' => '🔥 HTML forjado y subido a S3 correctamente'));
            } else {
                wp_send_json_error(array('message' => '❌ Error al forjar la página en S3'));
            }
        } else {
            $temp_url = $this->save_to_temp_folder($html_content, $page_title, $page_id);
            
            if ($temp_url) {
                wp_send_json_success(array('message' => '🔥 HTML sólido forjado: <a href="' . esc_url($temp_url) . '" target="_blank">Ver archivo</a>'));
            } else {
                wp_send_json_error(array('message' => '❌ Error al forjar HTML sólido'));
            }
        }
    }
    
    private function get_page_html($page_id) {
        // Si es ID 0, es la página home
        if ($page_id == 0) {
            $permalink = home_url('/');
        } else {
            $permalink = get_permalink($page_id);
        }
        
        $response = wp_remote_get($permalink, array(
            'timeout' => 30,
            'user-agent' => 'StaticForge/1.0'
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $html = wp_remote_retrieve_body($response);
        
        $html = $this->process_html_for_static($html, $permalink);
        
        return $html;
    }
    
    private function process_html_for_static($html, $base_url) {
        $site_url = get_site_url();
        
        $html = str_replace('href="/', 'href="' . $site_url . '/', $html);
        $html = str_replace('src="/', 'src="' . $site_url . '/', $html);
        
        $html = preg_replace('/href="([^"]*)\?[^"]*"/', 'href="$1"', $html);
        
        return $html;
    }
    
    private function s3_credentials_configured() {
        $aws_access_key = get_option('spg_aws_access_key');
        $aws_secret_key = get_option('spg_aws_secret_key');
        $s3_bucket = get_option('spg_s3_bucket');
        
        return !empty($aws_access_key) && !empty($aws_secret_key) && !empty($s3_bucket);
    }
    
    private function save_to_temp_folder($content, $page_title, $page_id) {
        $upload_dir = wp_upload_dir();
        $base_folder = $upload_dir['basedir'] . '/static-pages/';
        
        // Si es ID 0, es la página home
        if ($page_id == 0) {
            $page_slug = 'home';
        } else {
            $page = get_post($page_id);
            $page_slug = $page->post_name;
        }
        
        $page_folder = $base_folder . $page_slug . '/';
        
        if (!file_exists($page_folder)) {
            wp_mkdir_p($page_folder);
        }
        
        $file_path = $page_folder . 'index.html';
        
        $result = file_put_contents($file_path, $content);
        
        if ($result === false) {
            return false;
        }
        
        return $upload_dir['baseurl'] . '/static-pages/' . $page_slug . '/index.html';
    }
    
    private function upload_to_s3($content, $page_title, $page_id) {
        $aws_access_key = get_option('spg_aws_access_key');
        $aws_secret_key = get_option('spg_aws_secret_key');
        $s3_bucket = get_option('spg_s3_bucket');
        $s3_region = get_option('spg_s3_region', 'us-east-1');
        
        if (empty($aws_access_key) || empty($aws_secret_key) || empty($s3_bucket)) {
            return false;
        }
        
        // Si es ID 0, es la página home
        if ($page_id == 0) {
            $page_slug = 'home';
        } else {
            $page = get_post($page_id);
            $page_slug = $page->post_name;
        }
        
        $filename = $page_slug . '/index.html';
        
        $endpoint = "https://s3.{$s3_region}.amazonaws.com/{$s3_bucket}/{$filename}";
        
        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        
        $canonical_request = "PUT\n/{$filename}\n\ncontent-type:text/html\nhost:s3.{$s3_region}.amazonaws.com\nx-amz-content-sha256:" . hash('sha256', $content) . "\nx-amz-date:{$timestamp}\n\ncontent-type;host;x-amz-content-sha256;x-amz-date\n" . hash('sha256', $content);
        
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = "{$date}/{$s3_region}/s3/aws4_request";
        $string_to_sign = "{$algorithm}\n{$timestamp}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
        
        $date_key = hash_hmac('sha256', $date, 'AWS4' . $aws_secret_key, true);
        $date_region_key = hash_hmac('sha256', $s3_region, $date_key, true);
        $date_region_service_key = hash_hmac('sha256', 's3', $date_region_key, true);
        $signing_key = hash_hmac('sha256', 'aws4_request', $date_region_service_key, true);
        
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
        
        $authorization = "{$algorithm} Credential={$aws_access_key}/{$credential_scope}, SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date, Signature={$signature}";
        
        $headers = array(
            'Content-Type' => 'text/html',
            'Authorization' => $authorization,
            'x-amz-content-sha256' => hash('sha256', $content),
            'x-amz-date' => $timestamp
        );
        
        $response = wp_remote_request($endpoint, array(
            'method' => 'PUT',
            'headers' => $headers,
            'body' => $content,
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        return ($response_code >= 200 && $response_code < 300);
    }
    
    public function toggle_autoupdate() {
        if (!wp_verify_nonce($_POST['nonce'], 'spg_autoupdate_nonce')) {
            wp_die('Error de seguridad');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        $page_id = intval($_POST['page_id']);
        $enabled = intval($_POST['enabled']);
        
        update_post_meta($page_id, 'spg_autoupdate', $enabled);
        
        wp_send_json_success();
    }
    
    public function handle_page_update($post_id, $post_after, $post_before) {
        if ($post_after->post_type !== 'page') {
            return;
        }
        
        if ($post_after->post_status !== 'publish') {
            return;
        }
        
        $autoupdate_enabled = get_post_meta($post_id, 'spg_autoupdate', true);
        if (!$autoupdate_enabled) {
            return;
        }
        
        $html_content = $this->get_page_html($post_id);
        
        if (!$html_content) {
            return;
        }
        
        if ($this->s3_credentials_configured()) {
            $this->upload_to_s3($html_content, $post_after->post_title, $post_id);
        } else {
            $this->save_to_temp_folder($html_content, $post_after->post_title, $post_id);
        }
    }
}

new StaticPageGenerator();