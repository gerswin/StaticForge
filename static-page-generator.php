<?php
/**
 * Plugin Name: StaticForge
 * Description: Convierte WP en HTML sólido
 * Version: 1.3.1
 * Author: Gerswin Pineda
 * Update URI: https://github.com/gerswin/StaticForge
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.6
 */

if (!defined('ABSPATH')) {
    exit;
}

// Cargar AWS SDK
require_once __DIR__ . '/vendor/autoload.php';

// Cargar auto-updater
require_once __DIR__ . '/includes/class-staticforge-updater.php';

// Verificar que AWS SDK se cargó correctamente
if (class_exists('Aws\CloudFront\CloudFrontClient')) {
    error_log('StaticForge: AWS SDK cargado correctamente');
} else {
    error_log('StaticForge: ERROR - AWS SDK no se pudo cargar');
}

// Inicializar auto-updater
if (is_admin()) {
    new StaticForge_Updater(__FILE__);
}

class StaticPageGenerator {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_generate_static_page', array($this, 'generate_static_page'));
        add_action('wp_ajax_toggle_autoupdate', array($this, 'toggle_autoupdate'));
        add_action('wp_ajax_test_s3_connection', array($this, 'test_s3_connection'));
        add_action('wp_ajax_test_cloudfront_behavior', array($this, 'test_cloudfront_behavior'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('post_updated', array($this, 'handle_page_update'), 10, 3);
        
        register_activation_hook(__FILE__, array($this, 'create_tracking_table'));
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
        
        add_submenu_page(
            'static-page-generator',
            'CloudFront',
            'CloudFront',
            'manage_options',
            'static-page-cloudfront',
            array($this, 'cloudfront_page')
        );
        
        add_submenu_page(
            'static-page-generator',
            'Debug',
            'Debug',
            'manage_options',
            'static-page-debug',
            array($this, 'debug_page')
        );
    }
    
    public function enqueue_scripts($hook) {
        // Allow scripts on all StaticForge pages
        $allowed_hooks = array(
            'toplevel_page_static-page-generator',
            'staticforge_page_static-page-config',
            'staticforge_page_static-page-cloudfront',
            'staticforge_page_static-page-debug'
        );
        
        if (!in_array($hook, $allowed_hooks)) {
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
        // Obtener tipos de contenido habilitados para StaticForge
        $enabled_post_types = $this->get_enabled_post_types();

        // Filtros: estado y paginación
        $status_filter = isset($_GET['spg_status']) ? sanitize_key($_GET['spg_status']) : 'publish';
        $allowed_status = array('all', 'publish', 'draft', 'pending', 'private');
        if (!in_array($status_filter, $allowed_status, true)) {
            $status_filter = 'publish';
        }
        $statuses = ($status_filter === 'all') ? array('publish', 'draft', 'pending', 'private') : array($status_filter);

        $per_page = isset($_GET['spg_per_page']) ? intval($_GET['spg_per_page']) : 20;
        if ($per_page < 1) { $per_page = 20; }
        if ($per_page > 200) { $per_page = 200; }
        $paged = isset($_GET['spg_paged']) ? max(1, intval($_GET['spg_paged'])) : 1;

        // Query con conteo para paginar
        $query_args = array(
            'post_type'           => $enabled_post_types,
            'post_status'         => $statuses,
            'posts_per_page'      => $per_page,
            'paged'               => $paged,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => false,
        );
        $q = new WP_Query($query_args);
        $pages = $q->posts;
        $found_posts = intval($q->found_posts);
        $max_pages = max(1, intval($q->max_num_pages));
        
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
        <style>
        .autoupdate-checkbox {
            margin-left: 10px;
        }
        .spinner {
            margin-left: 10px;
            display: none;
        }
        .spinner.is-active {
            display: inline-block;
        }
        .generated-link {
            text-decoration: none;
            padding: 2px 6px;
            border-radius: 3px;
            background: #f0f0f0;
            display: inline-block;
            margin: 2px 0;
            font-size: 12px;
        }
        .generated-link:hover {
            background: #e0e0e0;
        }
        .cf-status-active {
            color: #46b450;
            font-weight: bold;
        }
        .cf-status-error {
            color: #dc3232;
        }
        .cf-not-configured {
            color: #999;
            font-style: italic;
        }
        </style>
        <div class="wrap">
            <h1>StaticForge - Convierte WP en HTML sólido</h1>
            <form method="get" action="" style="margin: 12px 0;">
                <input type="hidden" name="page" value="static-page-generator" />
                <label style="margin-right:8px;">Estado:
                    <select name="spg_status">
                        <option value="publish" <?php selected($status_filter, 'publish'); ?>>Publicados</option>
                        <option value="draft" <?php selected($status_filter, 'draft'); ?>>Borradores</option>
                        <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pendientes</option>
                        <option value="private" <?php selected($status_filter, 'private'); ?>>Privados</option>
                        <option value="all" <?php selected($status_filter, 'all'); ?>>Todos</option>
                    </select>
                </label>
                <label style="margin-right:8px;">Por página:
                    <select name="spg_per_page">
                        <?php foreach (array(10,20,50,100,200) as $opt): ?>
                            <option value="<?php echo esc_attr($opt); ?>" <?php selected($per_page, $opt); ?>><?php echo esc_html($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="button">Filtrar</button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=static-page-generator')); ?>" class="button-link" style="margin-left:8px;">Limpiar filtros</a>
            </form>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>URL</th>
                        <th>Estado</th>
                        <th>Auto-update</th>
                        <th>Última Generación</th>
                        <th>URL Generada</th>
                        <th>CloudFront</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pages as $page): 
                        $autoupdate_enabled = get_post_meta($page->ID, 'spg_autoupdate', true);
                        $page_url = ($page->ID == 0) ? home_url('/') : get_permalink($page->ID);
                        $generation_data = $this->get_generation_data($page->ID);
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
                            <?php if ($generation_data): ?>
                                <span class="generation-date"><?php echo esc_html($generation_data['generated_at']); ?></span>
                            <?php else: ?>
                                <span class="no-generation">No generado</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($generation_data && $generation_data['generated_url']): ?>
                                <?php 
                                // Mostrar URL de S3
                                ?>
                                <a href="<?php echo esc_url($generation_data['generated_url']); ?>" target="_blank" class="generated-link" title="Ver en S3">
                                    📦 S3
                                </a>
                                <?php 
                                // Si CloudFront está configurado, mostrar también esa URL
                                if ($this->cloudfront_configured()) {
                                    $cloudfront_manager = new CloudFrontManager();
                                    $page_slug = ($page->ID == 0) ? 'home' : $page->post_name;
                                    $cf_url = $cloudfront_manager->get_cloudfront_url('/' . $page_slug);
                                    if ($cf_url): ?>
                                        <br>
                                        <a href="<?php echo esc_url($cf_url); ?>" target="_blank" class="generated-link" title="Ver en CloudFront">
                                            ☁️ CloudFront
                                        </a>
                                    <?php endif;
                                }
                                ?>
                            <?php else: ?>
                                <span class="no-url">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            $cloudfront_status = $this->get_page_cloudfront_status($page->ID);
                            if ($cloudfront_status): ?>
                                <span class="cf-status-<?php echo esc_attr($cloudfront_status['status']); ?>">
                                    <?php echo $cloudfront_status['icon']; ?> <?php echo esc_html($cloudfront_status['text']); ?>
                                </span>
                            <?php else: ?>
                                <span class="cf-not-configured">☁️ No configurado</span>
                            <?php endif; ?>
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
            <?php 
            // Paginación simple
            $base_url = admin_url('admin.php?page=static-page-generator');
            $base_url = add_query_arg(array(
                'spg_status'   => $status_filter,
                'spg_per_page' => $per_page,
            ), $base_url);

            $prev_url = $paged > 1 ? add_query_arg('spg_paged', $paged - 1, $base_url) : '';
            $next_url = $paged < $max_pages ? add_query_arg('spg_paged', $paged + 1, $base_url) : '';

            $range_start = ($paged - 1) * $per_page + 1;
            $current_count = count($q->posts);
            $range_end = min($range_start + $current_count - 1, $found_posts);
            ?>
            <div class="tablenav bottom" style="margin-top:10px;">
                <div class="tablenav-pages">
                    <span class="displaying-num">Mostrando <?php echo esc_html($found_posts ? $range_start : 0); ?>–<?php echo esc_html($found_posts ? $range_end : 0); ?> de <?php echo esc_html($found_posts); ?></span>
                    <span class="pagination-links" style="margin-left:10px;">
                        <?php if ($prev_url): ?>
                            <a class="prev-page button" href="<?php echo esc_url($prev_url); ?>">« Anterior</a>
                        <?php else: ?>
                            <span class="tablenav-pages-navspan button disabled">« Anterior</span>
                        <?php endif; ?>
                        <span class="paging-input" style="margin:0 8px;">
                            Página <?php echo esc_html($paged); ?> de <span class="total-pages"><?php echo esc_html($max_pages); ?></span>
                        </span>
                        <?php if ($next_url): ?>
                            <a class="next-page button" href="<?php echo esc_url($next_url); ?>">Siguiente »</a>
                        <?php else: ?>
                            <span class="tablenav-pages-navspan button disabled">Siguiente »</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.generate-static').on('click', function() {
                var button = $(this);
                var spinner = button.siblings('.spinner');
                var message = button.siblings('.result-message');
                var pageId = button.data('page-id');
                var pageTitle = button.data('page-title');
                
                console.log('StaticForge: Iniciando AJAX para página:', pageId, pageTitle);
                console.log('StaticForge: URL AJAX:', spg_ajax.ajax_url);
                console.log('StaticForge: Nonce:', spg_ajax.nonce);
                
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
                        console.log('StaticForge: AJAX Success:', response);
                        if (response.success) {
                            message.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                        } else {
                            message.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('StaticForge: AJAX Error:', {xhr, status, error});
                        console.log('StaticForge: Response Status:', xhr.status);
                        console.log('StaticForge: Response Text:', xhr.responseText);
                        message.html('<span style="color: red;">✗ Error en la comunicación (' + xhr.status + ')</span>');
                    },
                    complete: function() {
                        console.log('StaticForge: AJAX Complete');
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
        
        // CloudFront configuration
        if (isset($_POST['cloudfront_distribution_id'])) {
            update_option('spg_cloudfront_distribution_id', sanitize_text_field($_POST['cloudfront_distribution_id']));
        }
        if (isset($_POST['cloudfront_prefix'])) {
            update_option('spg_cloudfront_prefix', sanitize_text_field($_POST['cloudfront_prefix']));
        }
        // Tipos de contenido habilitados
        $received_types = isset($_POST['spg_enabled_post_types']) ? (array) $_POST['spg_enabled_post_types'] : array();
        $sanitized = array();
        foreach ($received_types as $ptype) {
            $ptype = sanitize_key($ptype);
            if (post_type_exists($ptype)) {
                $sanitized[] = $ptype;
            }
        }
        update_option('spg_enabled_post_types', $sanitized);
            echo '<div class="notice notice-success"><p>Configuración guardada correctamente.</p></div>';
        }
        
        $aws_access_key = get_option('spg_aws_access_key', '');
        $aws_secret_key = get_option('spg_aws_secret_key', '');
        $s3_bucket = get_option('spg_s3_bucket', '');
        $s3_region = get_option('spg_s3_region', 'us-east-1');
        $cloudfront_distribution_id = get_option('spg_cloudfront_distribution_id', '');
        $cloudfront_prefix = get_option('spg_cloudfront_prefix', '');
        $enabled_post_types = get_option('spg_enabled_post_types', array('page'));
        if (!is_array($enabled_post_types)) { $enabled_post_types = array('page'); }
        // Obtener post types públicos y con UI para listarlos como checkboxes
        $public_types = get_post_types(array('public' => true, 'show_ui' => true), 'objects');
        // Excluir adjuntos u objetos no útiles
        unset($public_types['attachment']);
        $offline_mode = !$this->s3_credentials_configured();
        ?>
        <div class="wrap">
            <h1>Configuración de S3</h1>
            <?php if ($offline_mode): ?>
                <div class="notice notice-warning"><p>⚠️ Modo offline activo: Sin credenciales de S3, los HTML se guardarán localmente en <code>uploads/static-pages/</code>. Completa las credenciales para subir a S3 e integrar con CloudFront.</p></div>
            <?php endif; ?>
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row">AWS Access Key</th>
                        <td>
                            <input type="text" name="aws_access_key" value="<?php echo esc_attr($aws_access_key); ?>" class="regular-text" />
                            <p class="description">Opcional para pruebas offline. Déjalo vacío para generar localmente.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">AWS Secret Key</th>
                        <td>
                            <input type="password" name="aws_secret_key" value="<?php echo esc_attr($aws_secret_key); ?>" class="regular-text" />
                            <p class="description">Opcional para pruebas offline. Cuando falten credenciales, no se sube a S3.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Bucket de S3</th>
                        <td>
                            <input type="text" name="s3_bucket" value="<?php echo esc_attr($s3_bucket); ?>" class="regular-text" />
                            <p class="description">Opcional para pruebas offline. Requerido solo si vas a usar S3.</p>
                        </td>
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
                
                <h2>Configuración CloudFront (Opcional)</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Distribution ID</th>
                        <td>
                            <input type="text" name="cloudfront_distribution_id" 
                                   value="<?php echo esc_attr($cloudfront_distribution_id); ?>" 
                                   class="regular-text" 
                                   placeholder="E1234567890123" />
                            <p class="description">ID de la distribución CloudFront para invalidación de cache</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Prefijo S3 (Opcional)</th>
                        <td>
                            <input type="text" name="cloudfront_prefix" 
                                   value="<?php echo esc_attr($cloudfront_prefix); ?>" 
                                   class="regular-text" 
                                   placeholder="prod/" />
                            <p class="description">Prefijo para organizar archivos en S3 por entorno</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Tipos de contenido</th>
                        <td>
                            <?php if (!empty($public_types)): ?>
                                <?php foreach ($public_types as $ptype => $obj): ?>
                                    <label style="display:inline-block; margin: 4px 12px 4px 0;">
                                        <input type="checkbox" name="spg_enabled_post_types[]" value="<?php echo esc_attr($ptype); ?>" <?php checked(in_array($ptype, $enabled_post_types, true)); ?> />
                                        <?php echo esc_html($obj->labels->name); ?> <code style="color:#666;">(<?php echo esc_html($ptype); ?>)</code>
                                    </label>
                                <?php endforeach; ?>
                                <p class="description">Selecciona los post types que podrán forjarse a estático y aparecerán en la lista.</p>
                            <?php else: ?>
                                <em>No se encontraron tipos de contenido públicos.</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    public function generate_static_page() {
        error_log('StaticForge: ===== INICIO AJAX generate_static_page =====');
        error_log('StaticForge: POST data: ' . print_r($_POST, true));
        
        if (!wp_verify_nonce($_POST['nonce'], 'spg_nonce')) {
            error_log('StaticForge: Error - nonce inválido');
            wp_die('Error de seguridad');
        }
        
        error_log('StaticForge: Nonce verificado correctamente');
        
        if (!current_user_can('manage_options')) {
            error_log('StaticForge: Error - permisos insuficientes');
            wp_die('Permisos insuficientes');
        }
        
        error_log('StaticForge: Permisos verificados correctamente');
        
        $page_id = intval($_POST['page_id']);
        $page_title = sanitize_text_field($_POST['page_title']);
        
        error_log('StaticForge: Procesando página ID: ' . $page_id . ', título: ' . $page_title);
        
        $html_content = $this->get_page_html($page_id);
        error_log('StaticForge: HTML obtenido, longitud: ' . strlen($html_content));
        
        if (!$html_content) {
            error_log('StaticForge: ERROR - No se pudo obtener HTML de la página');
            wp_send_json_error(array('message' => 'Error al obtener el contenido de la página'));
            return;
        }
        
        error_log('StaticForge: HTML obtenido exitosamente');
        
        $success_message = '';
        $has_cloudfront = $this->cloudfront_configured();
        error_log('StaticForge: CloudFront configurado: ' . ($has_cloudfront ? 'SÍ' : 'NO'));
        
        if ($this->s3_credentials_configured()) {
            $s3_result = $this->upload_to_s3($html_content, $page_title, $page_id);
            
            if ($s3_result && isset($s3_result['success']) && $s3_result['success']) {
                $this->save_generation_data($page_id, $s3_result['url'], 's3');
                $success_message = '🔥 HTML forjado y subido a S3 correctamente';
                
                // Crear/actualizar behavior en CloudFront si está configurado
                if ($has_cloudfront) {
                    $cloudfront_result = $this->create_cloudfront_behavior_for_page($page_id);
                    if ($cloudfront_result) {
                        $success_message .= ' + ☁️ CloudFront actualizado';
                    } else {
                        $success_message .= ' (⚠️ CloudFront: error)';
                    }
                }
                
                wp_send_json_success(array('message' => $success_message));
            } else {
                $error_details = $s3_result && isset($s3_result['error']) ? $s3_result['error'] : 'Error desconocido';
                $debug_info = '';
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $debug_info = '<br><small>Debug: ' . esc_html($error_details) . '</small>';
                }
                
                wp_send_json_error(array('message' => '❌ Error al forjar la página en S3' . $debug_info));
            }
        } else {
            $temp_url = $this->save_to_temp_folder($html_content, $page_title, $page_id);
            
            if ($temp_url) {
                $this->save_generation_data($page_id, $temp_url, 'local');
                $success_message = '🔥 HTML sólido forjado: <a href="' . esc_url($temp_url) . '" target="_blank">Ver archivo</a>';
                
                if ($has_cloudfront) {
                    $success_message .= ' (⚠️ CloudFront requiere S3)';
                }
                
                wp_send_json_success(array('message' => $success_message));
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
        
        // Firma de StaticForge para identificar la procedencia del HTML generado
        $version = 'unknown';
        if (function_exists('get_file_data')) {
            $plugin_data = get_file_data(__FILE__, array('Version' => 'Version'));
            if (!empty($plugin_data['Version'])) {
                $version = $plugin_data['Version'];
            }
        }
        $generated_at = current_time('mysql');
        $signature = "\n<!-- Generated by StaticForge v{$version} | {$generated_at} | Source: {$base_url} -->\n";

        if (stripos($html, '</head>') !== false) {
            $html = preg_replace('/<\/head>/i', $signature . '</head>', $html, 1);
        } elseif (stripos($html, '</body>') !== false) {
            $html = preg_replace('/<\/body>/i', $signature . '</body>', $html, 1);
        } else {
            $html = $signature . $html;
        }

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
        
        $configured = !empty($aws_access_key) && !empty($aws_secret_key) && !empty($s3_bucket);
        
        // Log para debugging
        if (!$configured && (defined('WP_DEBUG') && WP_DEBUG)) {
            error_log("StaticForge S3 Config Check - Access Key: " . (!empty($aws_access_key) ? 'SET' : 'EMPTY') . 
                     ", Secret Key: " . (!empty($aws_secret_key) ? 'SET' : 'EMPTY') . 
                     ", Bucket: " . (!empty($s3_bucket) ? 'SET' : 'EMPTY'));
        }
        
        return $configured;
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
        // Solo AWS SDK; si no está, error claro
        if (!class_exists('Aws\S3\S3Client')) {
            error_log('StaticForge: AWS SDK no disponible para S3');
            return array('success' => false, 'error' => 'AWS SDK no disponible');
        }
        return $this->upload_to_s3_with_sdk($content, $page_title, $page_id);
    }
    
    private function upload_to_s3_with_sdk($content, $page_title, $page_id) {
        try {
            require_once(__DIR__ . '/vendor/autoload.php');
            
            $aws_access_key = get_option('spg_aws_access_key');
            $aws_secret_key = get_option('spg_aws_secret_key');
            $s3_bucket = get_option('spg_s3_bucket');
            $s3_region = get_option('spg_s3_region', 'us-east-1');
            
            $s3 = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => $s3_region,
                'credentials' => [
                    'key' => $aws_access_key,
                    'secret' => $aws_secret_key,
                ],
            ]);
            
            // Obtener slug de la página de forma tolerante
            if ($page_id === 0) {
                $page_slug = 'home';
            } else {
                $page = is_numeric($page_id) ? get_post(intval($page_id)) : null;
                if ($page && isset($page->post_name) && $page->post_name) {
                    $page_slug = $page->post_name;
                } else {
                    // Fallback: derivar slug del título
                    $page_slug = sanitize_title($page_title ?: 'page');
                }
            }
            
            $key_index = $page_slug . '/index.html';
            
            // Subir SOLO index.html (CloudFront Function maneja las redirecciones)
            $s3->putObject([
                'Bucket' => $s3_bucket,
                'Key' => $key_index,
                'Body' => $content,
                'ContentType' => 'text/html',
                'CacheControl' => 'max-age=3600'
                // ACL removido - el bucket usa políticas en lugar de ACLs
            ]);
            
            error_log('StaticForge: Contenido HTML subido a S3: ' . $key_index);
            
            $public_host = ($s3_region === 'us-east-1')
                ? "{$s3_bucket}.s3.amazonaws.com"
                : "{$s3_bucket}.s3.{$s3_region}.amazonaws.com";
            return array(
                'success' => true,
                'url' => "https://{$public_host}/{$key_index}"
            );
            
        } catch (Exception $e) {
            error_log("StaticForge AWS SDK Error: " . $e->getMessage());
            return array(
                'success' => false,
                'error' => 'AWS SDK Error: ' . $e->getMessage()
            );
        }
    }
    
    // Eliminado: upload_to_s3_manual. El flujo usa únicamente AWS SDK.
    
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
        // Solo actuar para los post types habilitados
        $enabled = $this->get_enabled_post_types();
        if (!in_array($post_after->post_type, $enabled, true)) {
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
            $s3_result = $this->upload_to_s3($html_content, $post_after->post_title, $post_id);
            if ($s3_result && isset($s3_result['success']) && $s3_result['success']) {
                $this->save_generation_data($post_id, $s3_result['url'], 's3');
                // Actualizar CloudFront si está configurado
                if ($this->cloudfront_configured()) {
                    $this->create_cloudfront_behavior_for_page($post_id);
                }
            }
        } else {
            $temp_url = $this->save_to_temp_folder($html_content, $post_after->post_title, $post_id);
            if ($temp_url) {
                $this->save_generation_data($post_id, $temp_url, 'local');
            }
        }
    }

    private function get_enabled_post_types() {
        $enabled = get_option('spg_enabled_post_types', array('page'));
        if (!is_array($enabled) || empty($enabled)) {
            $enabled = array('page');
        }
        // Validar que aún existan
        $enabled = array_values(array_filter($enabled, function ($pt) { return post_type_exists($pt); }));
        // Si nada sobrevivió, fallback a page
        return !empty($enabled) ? $enabled : array('page');
    }
    
    public function create_tracking_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'staticforge_generated';
        $cf_table_name = $wpdb->prefix . 'staticforge_cloudfront';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tabla de páginas generadas
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            page_id int(11) NOT NULL,
            generated_url varchar(500) NOT NULL,
            storage_type varchar(20) NOT NULL,
            generated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY page_id (page_id)
        ) $charset_collate;";
        
        // Tabla de behaviors CloudFront
        $cf_sql = "CREATE TABLE $cf_table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            page_id int(11) NOT NULL,
            path_pattern varchar(200) NOT NULL,
            status varchar(50) NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY page_id (page_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($cf_sql);
    }
    
    private function save_generation_data($page_id, $generated_url, $storage_type) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'staticforge_generated';
        
        $wpdb->replace(
            $table_name,
            array(
                'page_id' => $page_id,
                'generated_url' => $generated_url,
                'storage_type' => $storage_type,
                'generated_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s')
        );
    }
    
    private function get_generation_data($page_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'staticforge_generated';
        
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE page_id = %d",
                $page_id
            ),
            ARRAY_A
        );
        
        if ($result) {
            // Formatear fecha de manera legible
            $date = new DateTime($result['generated_at']);
            $result['generated_at'] = $date->format('d/m/Y H:i');
        }
        
        return $result;
    }
    
    private function cloudfront_configured() {
        $distribution_id = get_option('spg_cloudfront_distribution_id');
        return !empty($distribution_id) && $this->s3_credentials_configured();
    }
    
    private function create_cloudfront_behavior_for_page($page_id) {
        if (!$this->cloudfront_configured()) {
            error_log('StaticForge: CloudFront no configurado');
            return false;
        }
        
        // Obtener slug de la página
        $page_slug = $this->get_page_slug($page_id);
        if (!$page_slug) {
            error_log('StaticForge: No se pudo obtener slug para página ' . $page_id);
            return false;
        }
        
        error_log('StaticForge: Iniciando creación de behavior para página ' . $page_id . ', slug: ' . $page_slug);
        
        $cloudfront_manager = new CloudFrontManager();
        
        // Crear path pattern basado en el slug
        $path_pattern = '/' . $page_slug;
        
        // Crear behavior SIN sobrescribir el contenido existente
        // El tercer parámetro indica que NO debe crear placeholder
        $result = $cloudfront_manager->create_behavior_without_placeholder($path_pattern);
        
        // Guardar estado en la base de datos
        if ($result) {
            error_log('StaticForge: Behavior creado exitosamente para ' . $path_pattern);
            $this->save_cloudfront_behavior_status($page_id, 'active', $path_pattern);
            return true;
        } else {
            error_log('StaticForge: Error creando behavior para ' . $path_pattern);
            $this->save_cloudfront_behavior_status($page_id, 'error', $path_pattern);
            return false;
        }
        
        return $result;
    }
    
    private function get_page_slug($page_id) {
        if ($page_id == 0) {
            return 'home';
        }
        
        $page = get_post($page_id);
        return $page ? $page->post_name : false;
    }
    
    private function save_cloudfront_behavior_status($page_id, $status, $path_pattern) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'staticforge_cloudfront';
        
        $wpdb->replace(
            $table_name,
            array(
                'page_id' => $page_id,
                'path_pattern' => $path_pattern,
                'status' => $status,
                'updated_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s')
        );
    }
    
    private function get_page_cloudfront_status($page_id) {
        if (!$this->cloudfront_configured()) {
            return false;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'staticforge_cloudfront';
        
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE page_id = %d",
                $page_id
            ),
            ARRAY_A
        );
        
        if (!$result) {
            return array(
                'status' => 'not-created',
                'icon' => '⏳',
                'text' => 'Sin behavior'
            );
        }
        
        switch ($result['status']) {
            case 'active':
                return array(
                    'status' => 'active',
                    'icon' => '✅',
                    'text' => 'Activo: ' . $result['path_pattern']
                );
            case 'error':
                return array(
                    'status' => 'error',
                    'icon' => '❌',
                    'text' => 'Error en: ' . $result['path_pattern']
                );
            default:
                return array(
                    'status' => 'unknown',
                    'icon' => '❓',
                    'text' => 'Estado desconocido'
                );
        }
    }
    
    public function cloudfront_page() {
        if (isset($_POST['submit_path'])) {
            $this->handle_cloudfront_path_creation();
        }
        
        if (isset($_POST['invalidate_path'])) {
            $this->handle_cloudfront_invalidation();
        }
        
        if (isset($_POST['delete_behavior'])) {
            $this->handle_cloudfront_behavior_deletion();
        }
        
        ?>
        <div class="wrap">
            <h1>CloudFront - Gestión de Behaviors</h1>
            
            <h2>Behaviors Actuales</h2>
            <?php $this->display_current_behaviors(); ?>
            
            <hr>
            
            <h2>Crear Nuevo Behavior</h2>
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row">Path Pattern</th>
                        <td>
                            <input type="text" name="path_pattern" class="regular-text" 
                                   placeholder="/promos" required />
                            <p class="description">
                                Ejemplo: "/promos" creará behaviors para "/promos" y "/promos/*"
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">S3 Prefix (Opcional)</th>
                        <td>
                            <input type="text" name="s3_prefix" class="regular-text" 
                                   placeholder="campaigns/" />
                            <p class="description">
                                Carpeta en S3 donde se almacenarán los archivos
                            </p>
                        </td>
                    </tr>
                </table>
                <?php wp_nonce_field('spg_cloudfront_nonce', 'cloudfront_nonce'); ?>
                <?php submit_button('🚀 Crear Behavior', 'primary', 'submit_path'); ?>
            </form>
            
            <hr>
            
            <h2>Invalidar Cache</h2>
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row">Paths a Invalidar</th>
                        <td>
                            <textarea name="invalidation_paths" class="large-text" rows="3" 
                                      placeholder="/promos&#10;/promos/*&#10;/campaigns/*" required></textarea>
                            <p class="description">
                                Un path por línea. Ejemplo: /promos, /promos/*, etc.
                            </p>
                        </td>
                    </tr>
                </table>
                <?php wp_nonce_field('spg_cloudfront_nonce', 'cloudfront_nonce'); ?>
                <?php submit_button('🗑️ Invalidar Cache', 'secondary', 'invalidate_path'); ?>
            </form>
            
            <hr>
            
            <h2>Estado de la Distribución</h2>
            <div id="distribution-status">
                <?php $this->display_distribution_status(); ?>
            </div>
        </div>
        <?php
    }
    
    private function handle_cloudfront_path_creation() {
        if (!wp_verify_nonce($_POST['cloudfront_nonce'], 'spg_cloudfront_nonce')) {
            wp_die('Error de seguridad');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        $path_pattern = sanitize_text_field($_POST['path_pattern']);
        $s3_prefix = sanitize_text_field($_POST['s3_prefix']);
        
        $cloudfront_manager = new CloudFrontManager();
        $result = $cloudfront_manager->create_behavior($path_pattern, $s3_prefix);
        
        if ($result) {
            echo '<div class="notice notice-success"><p>🚀 Behavior creado exitosamente</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ Error al crear behavior</p></div>';
        }
    }
    
    private function handle_cloudfront_invalidation() {
        if (!wp_verify_nonce($_POST['cloudfront_nonce'], 'spg_cloudfront_nonce')) {
            wp_die('Error de seguridad');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        $paths_raw = $_POST['invalidation_paths'];
        $paths = array_filter(array_map('trim', explode("\n", $paths_raw)));
        
        $cloudfront_manager = new CloudFrontManager();
        $result = $cloudfront_manager->create_invalidation($paths);
        
        if ($result) {
            echo '<div class="notice notice-success"><p>🗑️ Invalidación creada exitosamente</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ Error al crear invalidación</p></div>';
        }
    }
    
    private function display_distribution_status() {
        $distribution_id = get_option('spg_cloudfront_distribution_id');
        
        if (empty($distribution_id)) {
            echo '<p>⚠️ No hay Distribution ID configurado</p>';
            return;
        }
        
        $cloudfront_manager = new CloudFrontManager();
        $status = $cloudfront_manager->get_distribution_status();
        
        if ($status) {
            echo '<p>📊 Estado: <strong>' . esc_html($status['Status']) . '</strong></p>';
            echo '<p>🌐 Domain: <strong>' . esc_html($status['DomainName']) . '</strong></p>';
            echo '<p>📅 Última modificación: <strong>' . esc_html($status['LastModifiedTime']) . '</strong></p>';
        } else {
            echo '<p>❌ No se pudo obtener el estado de la distribución</p>';
        }
    }
    
    private function display_current_behaviors() {
        $distribution_id = get_option('spg_cloudfront_distribution_id');
        
        if (empty($distribution_id)) {
            echo '<p>⚠️ No hay Distribution ID configurado</p>';
            return;
        }
        
        $cloudfront_manager = new CloudFrontManager();
        $behaviors = $cloudfront_manager->get_all_behaviors();
        
        if (!$behaviors || empty($behaviors)) {
            echo '<p>📭 No hay behaviors configurados</p>';
            return;
        }
        
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Path Pattern</th>
                    <th>Target Origin</th>
                    <th>Cache Policy</th>
                    <th>Function</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($behaviors as $behavior): ?>
                <tr>
                    <td><strong><?php echo esc_html($behavior['PathPattern']); ?></strong></td>
                    <td><?php echo esc_html($behavior['TargetOriginId']); ?></td>
                    <td><?php echo isset($behavior['CachePolicyId']) ? '✅ Configurado' : '❌ Sin cache policy'; ?></td>
                    <td>
                        <?php 
                        if (isset($behavior['FunctionAssociations']) && $behavior['FunctionAssociations']['Quantity'] > 0) {
                            echo '⚡ CloudFront Function activa';
                        } else {
                            echo '➖ Sin función';
                        }
                        ?>
                    </td>
                    <td>
                        <form method="post" action="" style="display:inline;">
                            <?php wp_nonce_field('spg_cloudfront_delete_nonce', 'delete_nonce'); ?>
                            <input type="hidden" name="path_pattern" value="<?php echo esc_attr($behavior['PathPattern']); ?>">
                            <button type="submit" name="delete_behavior" class="button button-small" 
                                    onclick="return confirm('¿Estás seguro de eliminar el behavior <?php echo esc_js($behavior['PathPattern']); ?>?');">
                                🗑️ Eliminar
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    private function handle_cloudfront_behavior_deletion() {
        if (!wp_verify_nonce($_POST['delete_nonce'], 'spg_cloudfront_delete_nonce')) {
            wp_die('Error de seguridad');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        $path_pattern = sanitize_text_field($_POST['path_pattern']);
        
        $cloudfront_manager = new CloudFrontManager();
        $result = $cloudfront_manager->delete_behavior($path_pattern);
        
        if ($result) {
            echo '<div class="notice notice-success"><p>✅ Behavior ' . esc_html($path_pattern) . ' eliminado exitosamente</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ Error al eliminar behavior ' . esc_html($path_pattern) . '</p></div>';
        }
    }
    
    public function debug_page() {
        ?>
        <div class="wrap">
            <h1>StaticForge - Debug</h1>
            
            <h2>Configuración Actual</h2>
            <table class="wp-list-table widefat">
                <tr>
                    <td><strong>AWS Access Key</strong></td>
                    <td><?php echo !empty(get_option('spg_aws_access_key')) ? '✅ Configurado' : '❌ No configurado'; ?></td>
                </tr>
                <tr>
                    <td><strong>AWS Secret Key</strong></td>
                    <td><?php echo !empty(get_option('spg_aws_secret_key')) ? '✅ Configurado' : '❌ No configurado'; ?></td>
                </tr>
                <tr>
                    <td><strong>S3 Bucket</strong></td>
                    <td><?php echo !empty(get_option('spg_s3_bucket')) ? '✅ ' . esc_html(get_option('spg_s3_bucket')) : '❌ No configurado'; ?></td>
                </tr>
                <tr>
                    <td><strong>S3 Region</strong></td>
                    <td><?php echo esc_html(get_option('spg_s3_region', 'us-east-1')); ?></td>
                </tr>
                <tr>
                    <td><strong>CloudFront Distribution ID</strong></td>
                    <td><?php echo !empty(get_option('spg_cloudfront_distribution_id')) ? '✅ ' . esc_html(get_option('spg_cloudfront_distribution_id')) : '❌ No configurado'; ?></td>
                </tr>
                <tr>
                    <td><strong>WP_DEBUG</strong></td>
                    <td><?php echo defined('WP_DEBUG') && WP_DEBUG ? '✅ Activado' : '❌ Desactivado'; ?></td>
                </tr>
                <tr>
                    <td><strong>AWS SDK</strong></td>
                    <td><?php echo class_exists('Aws\S3\S3Client') ? '✅ Disponible' : '❌ No disponible (usando implementación manual)'; ?></td>
                </tr>
                <tr>
                    <td><strong>CloudFront SDK</strong></td>
                    <td><?php echo class_exists('Aws\CloudFront\CloudFrontClient') ? '✅ Disponible' : '❌ No disponible'; ?></td>
                </tr>
                <tr>
                    <td><strong>Método de upload</strong></td>
                    <td><?php echo class_exists('Aws\S3\S3Client') ? 'AWS SDK (recomendado)' : 'Implementación manual mejorada'; ?></td>
                </tr>
            </table>
            
            <?php if (!class_exists('Aws\S3\S3Client')): ?>
            <div class="notice notice-warning">
                <h3>⚡ Mejora el rendimiento con AWS SDK</h3>
                <p>Para mayor confiabilidad, instala el AWS SDK for PHP:</p>
                <ol>
                    <li>Navega al directorio del plugin: <code>/wp-content/plugins/staticforge/</code></li>
                    <li>Ejecuta: <code>composer install</code></li>
                    <li>El SDK se instalará automáticamente y mejorará la conectividad S3</li>
                </ol>
            </div>
            <?php endif; ?>
            
            <div class="notice notice-info">
                <h3>📋 Configuración requerida del S3 Bucket</h3>
                <p>Para que StaticForge funcione correctamente, tu bucket S3 debe estar configurado así:</p>
                <ol>
                    <li><strong>Bucket Policy</strong>: Permite acceso público de lectura</li>
                    <li><strong>Block Public Access</strong>: Deshabilitado o configurado para permitir políticas públicas</li>
                    <li><strong>Static Website Hosting</strong>: Opcional pero recomendado</li>
                </ol>
                <p><strong>Política de bucket recomendada:</strong></p>
                <pre style="background: #f9f9f9; padding: 10px; font-size: 12px;">{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "PublicReadGetObject",
      "Effect": "Allow",
      "Principal": "*",
      "Action": "s3:GetObject",
      "Resource": "arn:aws:s3:::TU-BUCKET-NAME/*"
    }
  ]
}</pre>
            </div>
            
            <h2>Test de Conectividad</h2>
            <p>
                <button type="button" class="button button-primary" onclick="testS3Connection()">🧪 Test S3</button>
                <span id="s3-test-result"></span>
            </p>
            
            <?php if (!empty(get_option('spg_cloudfront_distribution_id'))): ?>
            <p>
                <button type="button" class="button button-primary" onclick="testCloudFrontBehavior()">☁️ Test CloudFront Behavior</button>
                <span id="cf-test-result"></span>
            </p>
            <?php endif; ?>
            
            <h2>Actualizaciones</h2>
            <p>
                <a href="<?php echo add_query_arg('force-check', '1'); ?>" class="button">🔄 Verificar Actualizaciones</a>
                <?php $plugin_data = get_file_data(__FILE__, array('Version' => 'Version')); ?>
                <span style="margin-left: 10px; color: #666;">Versión actual: <strong><?php echo esc_html($plugin_data['Version']); ?></strong></span>
            </p>
            
            <h2>Logs Recientes</h2>
            <div style="background: #f1f1f1; padding: 10px; font-family: monospace; height: 300px; overflow-y: auto;">
                <?php
                // Mostrar logs recientes si WP_DEBUG está activo
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $log_file = WP_CONTENT_DIR . '/debug.log';
                    if (file_exists($log_file)) {
                        $logs = file_get_contents($log_file);
                        $recent_logs = array_slice(explode("\n", $logs), -50);
                        foreach ($recent_logs as $log_line) {
                            if (strpos($log_line, 'StaticForge') !== false) {
                                echo esc_html($log_line) . "<br>";
                            }
                        }
                    } else {
                        echo "No se encontró archivo debug.log";
                    }
                } else {
                    echo "Activa WP_DEBUG para ver logs";
                }
                ?>
            </div>
        </div>
        
        <script>
        function testS3Connection() {
            document.getElementById('s3-test-result').innerHTML = '⏳ Probando...';
            
            jQuery.ajax({
                url: spg_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'test_s3_connection',
                    nonce: spg_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        document.getElementById('s3-test-result').innerHTML = '✅ ' + response.data.message;
                    } else {
                        document.getElementById('s3-test-result').innerHTML = '❌ ' + response.data.message;
                    }
                },
                error: function() {
                    document.getElementById('s3-test-result').innerHTML = '❌ Error en la comunicación';
                }
            });
        }
        
        function testCloudFrontBehavior() {
            document.getElementById('cf-test-result').innerHTML = '⏳ Creando behavior de prueba...';
            
            jQuery.ajax({
                url: spg_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'test_cloudfront_behavior',
                    nonce: spg_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        document.getElementById('cf-test-result').innerHTML = '✅ ' + response.data.message;
                    } else {
                        document.getElementById('cf-test-result').innerHTML = '❌ ' + response.data.message;
                    }
                },
                error: function() {
                    document.getElementById('cf-test-result').innerHTML = '❌ Error en la comunicación';
                }
            });
        }
        </script>
        <?php
    }
    
    public function test_s3_connection() {
        if (!wp_verify_nonce($_POST['nonce'], 'spg_nonce')) {
            wp_die('Error de seguridad');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        if (!$this->s3_credentials_configured()) {
            wp_send_json_error(array('message' => 'Credenciales S3 no configuradas'));
            return;
        }
        
        // Test básico: subir un archivo pequeño de prueba
        $test_content = '<!DOCTYPE html><html><head><title>StaticForge Test</title></head><body><h1>Test Connection</h1><p>Generated at: ' . date('Y-m-d H:i:s') . '</p></body></html>';
        
        // Usar un ID/slug seguro para prueba, evitando depender de un post real
        $result = $this->upload_to_s3($test_content, 'test-connection', -1);
        
        if ($result && isset($result['success']) && $result['success']) {
            wp_send_json_success(array('message' => 'Conexión S3 exitosa! URL: ' . $result['url']));
        } else {
            $error_details = $result && isset($result['error']) ? $result['error'] : 'Error desconocido';
            wp_send_json_error(array('message' => 'Fallo en S3: ' . $error_details));
        }
    }
    
    public function test_cloudfront_behavior() {
        if (!wp_verify_nonce($_POST['nonce'], 'spg_nonce')) {
            wp_die('Error de seguridad');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        if (!$this->cloudfront_configured()) {
            wp_send_json_error(array('message' => 'CloudFront no está configurado'));
            return;
        }
        
        error_log('StaticForge: === INICIANDO TEST DE CLOUDFRONT BEHAVIOR ===');
        
        // Crear un behavior de prueba
        $cloudfront_manager = new CloudFrontManager();
        $test_path = '/staticforge-test-' . time();
        
        error_log('StaticForge: Creando behavior de prueba para path: ' . $test_path);
        
        $result = $cloudfront_manager->create_behavior($test_path);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => 'Behavior creado exitosamente! Path: ' . $test_path . 
                            ' - Revisa los logs para ver detalles de la respuesta de AWS.'
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'Error creando behavior. Revisa los logs de debug para más información.'
            ));
        }
    }
}

class CloudFrontManager {
    
    private $distribution_id;
    private $cloudfront_client;
    private $distribution_domain;
    
    public function __construct() {
        $this->distribution_id = get_option('spg_cloudfront_distribution_id');
        
        // Inicializar cliente CloudFront usando SDK
        try {
            $this->cloudfront_client = new \Aws\CloudFront\CloudFrontClient([
                'version' => 'latest',
                'region' => 'us-east-1', // CloudFront siempre usa us-east-1
                'credentials' => [
                    'key' => get_option('spg_aws_access_key'),
                    'secret' => get_option('spg_aws_secret_key'),
                ]
            ]);
            
            error_log('StaticForge CloudFront: Cliente SDK inicializado correctamente');
            error_log('StaticForge CloudFront: Distribution ID: ' . $this->distribution_id);
            
            // Obtener dominio de CloudFront
            $this->get_distribution_domain();
            
        } catch (\Exception $e) {
            error_log('StaticForge CloudFront: Error inicializando cliente SDK: ' . $e->getMessage());
            $this->cloudfront_client = null;
        }
    }
    
    public function get_cloudfront_url($path) {
        if (!$this->distribution_domain) {
            return false;
        }
        return 'https://' . $this->distribution_domain . $path;
    }
    
    private function get_distribution_domain() {
        if (!$this->cloudfront_client || !$this->distribution_id) {
            return false;
        }
        
        try {
            $result = $this->cloudfront_client->getDistribution([
                'Id' => $this->distribution_id
            ]);
            
            if (isset($result['Distribution']['DomainName'])) {
                $this->distribution_domain = $result['Distribution']['DomainName'];
                return $this->distribution_domain;
            }
        } catch (\Exception $e) {
            error_log('StaticForge CloudFront: Error obteniendo dominio: ' . $e->getMessage());
        }
        
        return false;
    }
    
    public function validate_and_normalize_path($path) {
        // Limpiar y normalizar el path
        $path = trim($path);
        
        // Asegurar que comience con /
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        
        // Remover / al final
        $path = rtrim($path, '/');
        
        // Validar que no sea demasiado genérico
        if ($path === '' || $path === '/') {
            return false;
        }
        
        return $path;
    }
    
    public function create_behavior_without_placeholder($path_pattern) {
        // Versión que NO crea placeholder en S3
        return $this->create_behavior_internal($path_pattern, '', false);
    }
    
    public function create_behavior($path_pattern, $s3_prefix = '') {
        // Versión que SÍ crea placeholder en S3 (para paths manuales)
        return $this->create_behavior_internal($path_pattern, $s3_prefix, true);
    }
    
    private function create_behavior_internal($path_pattern, $s3_prefix = '', $create_placeholder = true) {
        error_log('StaticForge CloudFront: Iniciando create_behavior para path: ' . $path_pattern);
        
        $normalized_path = $this->validate_and_normalize_path($path_pattern);
        if (!$normalized_path) {
            error_log('StaticForge CloudFront: Path inválido: ' . $path_pattern);
            return false;
        }
        
        error_log('StaticForge CloudFront: Path normalizado: ' . $normalized_path);
        
        // 1. Obtener configuración actual
        $distribution_config = $this->get_distribution_config();
        if (!$distribution_config) {
            error_log('StaticForge CloudFront: No se pudo obtener configuración de distribución');
            return false;
        }
        
        error_log('StaticForge CloudFront: Configuración obtenida exitosamente');
        
        // 2. Preparar S3 SOLO si se solicita explícitamente
        if ($create_placeholder) {
            $s3_result = $this->prepare_s3_structure($normalized_path, $s3_prefix);
            error_log('StaticForge CloudFront: S3 preparado, resultado: ' . ($s3_result ? 'éxito' : 'error (continuando)'));
        } else {
            error_log('StaticForge CloudFront: Omitiendo creación de placeholder (contenido ya existe)');
        }
        
        // 3. Añadir behaviors (sin CloudFront Functions)
        $behaviors_added = $this->add_behaviors_to_config(
            $distribution_config, 
            $normalized_path
        );
        
        if (!$behaviors_added) {
            error_log('StaticForge CloudFront: No se pudieron añadir behaviors');
            return false;
        }
        
        error_log('StaticForge CloudFront: Behaviors añadidos exitosamente');
        
        // Debug: Verificar que los cambios persistan
        if (isset($distribution_config['config']['CacheBehaviors'])) {
            error_log('StaticForge CloudFront: Después de add_behaviors - Total: ' . 
                     $distribution_config['config']['CacheBehaviors']['Quantity']);
            error_log('StaticForge CloudFront: Después de add_behaviors - Count real: ' . 
                     count($distribution_config['config']['CacheBehaviors']['Items']));
        }
        
        // 5. Actualizar distribución
        $update_result = $this->update_distribution($distribution_config);
        
        if ($update_result) {
            // 6. Invalidar cache
            $this->create_invalidation([$normalized_path, $normalized_path . '/*']);
        }
        
        return $update_result;
    }
    
    private function get_distribution_config() {
        if (!$this->cloudfront_client) {
            error_log('StaticForge CloudFront: Cliente SDK no disponible');
            return false;
        }
        
        error_log('StaticForge CloudFront: Obteniendo configuración usando SDK para: ' . $this->distribution_id);
        
        try {
            // Esperar si la distribución está en proceso de actualización
            $max_retries = 3;
            $retry_count = 0;
            
            while ($retry_count < $max_retries) {
                $result = $this->cloudfront_client->getDistributionConfig([
                    'Id' => $this->distribution_id
                ]);
                
                // Verificar estado de la distribución
                $dist_result = $this->cloudfront_client->getDistribution([
                    'Id' => $this->distribution_id
                ]);
                
                $status = $dist_result['Distribution']['Status'] ?? 'Unknown';
                error_log('StaticForge CloudFront: Estado de distribución: ' . $status);
                
                if ($status === 'InProgress') {
                    error_log('StaticForge CloudFront: Distribución en progreso, esperando 5 segundos...');
                    sleep(5);
                    $retry_count++;
                    continue;
                }
                
                break;
            }
            
            error_log('StaticForge CloudFront: Configuración obtenida exitosamente con SDK');
            
            // El SDK devuelve datos estructurados
            $config = $result['DistributionConfig'];
            $etag = $result['ETag'];
            
            error_log('StaticForge CloudFront: ETag obtenido: ' . $etag);
            error_log('StaticForge CloudFront: Origins encontrados: ' . count($config['Origins']['Items']));
            
            // Debug: Log existing behaviors
            if (isset($config['CacheBehaviors']) && isset($config['CacheBehaviors']['Items'])) {
                error_log('StaticForge CloudFront: Behaviors existentes: ' . count($config['CacheBehaviors']['Items']));
                foreach ($config['CacheBehaviors']['Items'] as $idx => $behavior) {
                    error_log('StaticForge CloudFront: - Behavior[' . $idx . ']: ' . $behavior['PathPattern'] . ' -> ' . $behavior['TargetOriginId']);
                }
            } else {
                error_log('StaticForge CloudFront: No hay behaviors configurados actualmente');
            }
            
            // Debug: Log all origin IDs
            foreach ($config['Origins']['Items'] as $origin) {
                error_log('StaticForge CloudFront: Origin disponible: ID=' . $origin['Id'] . ', Domain=' . $origin['DomainName']);
            }
            
            return array(
                'config' => $config,
                'etag' => $etag,
                'raw_result' => $result
            );
            
        } catch (\Aws\Exception\AwsException $e) {
            error_log('StaticForge CloudFront: Error SDK obteniendo configuración: ' . $e->getMessage());
            error_log('StaticForge CloudFront: Error Code: ' . $e->getAwsErrorCode());
            error_log('StaticForge CloudFront: Request ID: ' . $e->getAwsRequestId());
            return false;
        }
    }
    
    private function prepare_s3_structure($path, $s3_prefix = '') {
        error_log('StaticForge CloudFront: Preparando S3 para path: ' . $path . ', prefix: ' . $s3_prefix);
        
        $s3_bucket = get_option('spg_s3_bucket');
        $s3_region = get_option('spg_s3_region', 'us-east-1');
        $cloudfront_prefix = get_option('spg_cloudfront_prefix', '');
        
        error_log('StaticForge CloudFront: S3 bucket: ' . $s3_bucket . ', CF prefix: ' . $cloudfront_prefix);
        
        // Construir path completo en S3
        $full_prefix = trim($cloudfront_prefix . '/' . $s3_prefix . '/' . ltrim($path, '/'), '/');
        $s3_key = $full_prefix . '/index.html';
        
        error_log('StaticForge CloudFront: S3 path calculado: ' . $s3_key);
        
        try {
            // Verificar si el archivo ya existe en S3
            $s3 = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => $s3_region,
                'credentials' => [
                    'key' => get_option('spg_aws_access_key'),
                    'secret' => get_option('spg_aws_secret_key'),
                ],
            ]);
            
            // Intentar obtener el objeto para ver si existe
            try {
                $result = $s3->headObject([
                    'Bucket' => $s3_bucket,
                    'Key' => $s3_key
                ]);
                error_log('StaticForge CloudFront: Archivo ya existe en S3, no se sobrescribirá');
                return true;
            } catch (\Aws\S3\Exception\S3Exception $e) {
                // Si el objeto no existe, crear un placeholder temporal
                if ($e->getStatusCode() === 404) {
                    error_log('StaticForge CloudFront: Archivo no existe, creando placeholder temporal');
                    
                    $placeholder_content = '<!DOCTYPE html>
<html>
<head>
    <title>StaticForge - Path Ready</title>
</head>
<body>
    <h1>Path Configurado</h1>
    <p>El path <code>' . esc_html($path) . '</code> está listo para recibir contenido.</p>
    <p>Generado por StaticForge</p>
</body>
</html>';
                    
                    // Solo crear el placeholder si no existe
                    $s3->putObject([
                        'Bucket' => $s3_bucket,
                        'Key' => $s3_key,
                        'Body' => $placeholder_content,
                        'ContentType' => 'text/html',
                    ]);
                    
                    error_log('StaticForge CloudFront: S3 placeholder temporal creado');
                    return true;
                }
                throw $e;
            }
        } catch (\Exception $e) {
            error_log('StaticForge CloudFront: Error en prepare_s3_structure: ' . $e->getMessage());
            return false;
        }
    }
    
        // Eliminado: Soporte para CloudFront Functions. No se requiere para funcionamiento básico.

    private function add_behaviors_to_config(&$distribution_config, $path) {
        error_log('StaticForge CloudFront: Implementando add_behaviors_to_config REAL usando SDK para path: ' . $path);
        
        if (!$distribution_config || !isset($distribution_config['config'])) {
            error_log('StaticForge CloudFront: No hay configuración de distribución');
            return false;
        }
        
        $config = &$distribution_config['config']; // Referencia para modificar
        
        // Inicializar CacheBehaviors si no existe
        if (!isset($config['CacheBehaviors'])) {
            error_log('StaticForge CloudFront: Inicializando CacheBehaviors (no existía)');
            $config['CacheBehaviors'] = [
                'Quantity' => 0,
                'Items' => []
            ];
        }
        
        // Debug: Log initial state
        error_log('StaticForge CloudFront: Estado inicial - Behaviors existentes: ' . 
                 (isset($config['CacheBehaviors']['Items']) ? count($config['CacheBehaviors']['Items']) : 0));
        
        $base = ltrim($path, '/');
        $patterns = ['/' . $base, '/' . $base . '/*'];
        
        foreach ($patterns as $pp) {
            error_log('StaticForge CloudFront: Asegurando behavior para pattern: ' . $pp);
            $desired = $this->create_cache_behavior_array($pp, $config);
            if ($desired === false) {
                error_log('StaticForge CloudFront: No se puede asegurar behavior; origin S3 no encontrado.');
                return false;
            }
            
            // Debug: Log behavior details
            error_log('StaticForge CloudFront: Behavior creado con TargetOriginId: ' . $desired['TargetOriginId']);
            error_log('StaticForge CloudFront: Behavior CachePolicyId: ' . $desired['CachePolicyId']);
            
            $found = false;
            if (isset($config['CacheBehaviors']['Items'])) {
                foreach ($config['CacheBehaviors']['Items'] as &$behavior) {
                    if (isset($behavior['PathPattern']) && $behavior['PathPattern'] === $pp) {
                        // Actualizar campos clave
                        error_log('StaticForge CloudFront: Actualizando behavior existente para: ' . $pp);
                        $behavior['TargetOriginId'] = $desired['TargetOriginId'];
                        $behavior['TrustedSigners'] = $desired['TrustedSigners'];
                        $behavior['TrustedKeyGroups'] = $desired['TrustedKeyGroups'];
                        $behavior['ViewerProtocolPolicy'] = $desired['ViewerProtocolPolicy'];
                        $behavior['AllowedMethods'] = $desired['AllowedMethods'];
                        $behavior['SmoothStreaming'] = $desired['SmoothStreaming'];
                        $behavior['Compress'] = $desired['Compress'];
                        $behavior['LambdaFunctionAssociations'] = $desired['LambdaFunctionAssociations'];
                        $behavior['FunctionAssociations'] = $desired['FunctionAssociations'];
                        $behavior['FieldLevelEncryptionId'] = $desired['FieldLevelEncryptionId'];
                        $behavior['CachePolicyId'] = $desired['CachePolicyId'];
                        $behavior['OriginRequestPolicyId'] = $desired['OriginRequestPolicyId'];
                        
                        // Preserve ResponseHeadersPolicyId if it exists
                        if (!isset($desired['ResponseHeadersPolicyId']) && isset($behavior['ResponseHeadersPolicyId'])) {
                            $desired['ResponseHeadersPolicyId'] = $behavior['ResponseHeadersPolicyId'];
                        }
                        
                        // GrpcConfig might not exist in older behaviors
                        if (!isset($behavior['GrpcConfig'])) {
                            $behavior['GrpcConfig'] = $desired['GrpcConfig'];
                        }
                        
                        $found = true;
                        error_log('StaticForge CloudFront: Behavior existente actualizado: ' . $pp);
                        break;
                    }
                }
                unset($behavior);
            }
            
            if (!$found) {
                $config['CacheBehaviors']['Items'][] = $desired;
                error_log('StaticForge CloudFront: Nuevo behavior añadido: ' . $pp);
                error_log('StaticForge CloudFront: Behavior completo: ' . json_encode($desired));
            }
        }
        
        // Actualizar cantidad
        $config['CacheBehaviors']['Quantity'] = isset($config['CacheBehaviors']['Items']) ? count($config['CacheBehaviors']['Items']) : 0;
        error_log('StaticForge CloudFront: Behaviors asegurados. Total final: ' . $config['CacheBehaviors']['Quantity']);
        
        // Debug: Log all behaviors after modification
        if (isset($config['CacheBehaviors']['Items'])) {
            foreach ($config['CacheBehaviors']['Items'] as $idx => $behavior) {
                error_log('StaticForge CloudFront: Final Behavior[' . $idx . ']: ' . 
                         $behavior['PathPattern'] . ' -> ' . $behavior['TargetOriginId']);
            }
        }
        
        // IMPORTANTE: Re-indexar el array para asegurar que no hay gaps
        if (isset($config['CacheBehaviors']['Items'])) {
            $config['CacheBehaviors']['Items'] = array_values($config['CacheBehaviors']['Items']);
        }
        
        return true;
    }
    
    private function create_cache_behavior_array($path_pattern, $distribution_config) {
        error_log('StaticForge CloudFront: Creando behavior array para pattern: ' . $path_pattern);
        
        // Obtener el primer origen S3 de la distribución actual
        $target_origin_id = $this->get_s3_origin_id_from_config_array($distribution_config);
        
        // Requerir un origin válido
        if (!$target_origin_id) {
            error_log('StaticForge CloudFront: No hay Origin S3 válido en la distribución (se esperaba StaticS3Origin).');
            return false;
        }
        
        error_log('StaticForge CloudFront: Usando TargetOriginId: ' . $target_origin_id);
        
        // AWS Managed Cache Policies (verificados como válidos)
        // CachingOptimized: 658327ea-f89d-4fab-a63d-7e88639e58f6
        // CachingDisabled: 4135ea2d-6df8-44a3-9df3-4b5a84be39ad
        // CachingOptimizedForUncompressedObjects: b2884449-e4de-46a7-ac36-70bc7f1ddd6d
        
        // AWS Managed Origin Request Policies
        // CORS-S3Origin: 88a5eaf4-2fd4-4709-b370-b4c650ea3fcf
        // CORS-CustomOrigin: 59781a5b-3903-41f3-afcb-af62929ccde1
        // AllViewer: 216adef6-5c7f-47e4-b989-5492eafa07d3
        
        $behavior_array = [
            'PathPattern' => $path_pattern,
            'TargetOriginId' => $target_origin_id,
            'TrustedSigners' => [
                'Enabled' => false,
                'Quantity' => 0
            ],
            'TrustedKeyGroups' => [
                'Enabled' => false,
                'Quantity' => 0
            ],
            'ViewerProtocolPolicy' => 'redirect-to-https',
            'AllowedMethods' => [
                'Quantity' => 3,
                'Items' => ['HEAD', 'GET', 'OPTIONS'],
                'CachedMethods' => [
                    'Quantity' => 2,
                    'Items' => ['HEAD', 'GET']
                ]
            ],
            'SmoothStreaming' => false,
            'Compress' => true,
            'LambdaFunctionAssociations' => [
                'Quantity' => 0
            ],
            'FieldLevelEncryptionId' => '',
            // Use CachingOptimized policy for static content
            'CachePolicyId' => '658327ea-f89d-4fab-a63d-7e88639e58f6',
            // Use CORS-S3Origin for S3 origins
            'OriginRequestPolicyId' => '88a5eaf4-2fd4-4709-b370-b4c650ea3fcf'
        ];
        
        // Check for existing S3 behaviors to copy their CloudFront Function and ResponseHeadersPolicyId
        $found_s3_behavior = false;
        if (isset($distribution_config['CacheBehaviors']) && 
            isset($distribution_config['CacheBehaviors']['Items'])) {
            foreach ($distribution_config['CacheBehaviors']['Items'] as $existing_behavior) {
                if (isset($existing_behavior['TargetOriginId']) && 
                    $existing_behavior['TargetOriginId'] === 'StaticS3Origin') {
                    // Copy FunctionAssociations if present
                    if (isset($existing_behavior['FunctionAssociations'])) {
                        $behavior_array['FunctionAssociations'] = $existing_behavior['FunctionAssociations'];
                        error_log('StaticForge CloudFront: Copiando FunctionAssociations de behavior existente');
                    }
                    // Copy ResponseHeadersPolicyId if present
                    if (isset($existing_behavior['ResponseHeadersPolicyId'])) {
                        $behavior_array['ResponseHeadersPolicyId'] = $existing_behavior['ResponseHeadersPolicyId'];
                        error_log('StaticForge CloudFront: Copiando ResponseHeadersPolicyId: ' . $existing_behavior['ResponseHeadersPolicyId']);
                    }
                    $found_s3_behavior = true;
                    break;
                }
            }
        }
        
        // Default FunctionAssociations if not found from existing behaviors
        if (!$found_s3_behavior) {
            $behavior_array['FunctionAssociations'] = [
                'Quantity' => 0
            ];
        }
        
        // Only add GrpcConfig if CloudFront version supports it
        if (isset($distribution_config['CacheBehaviors']) && 
            isset($distribution_config['CacheBehaviors']['Items']) && 
            !empty($distribution_config['CacheBehaviors']['Items'])) {
            $first_behavior = $distribution_config['CacheBehaviors']['Items'][0];
            if (isset($first_behavior['GrpcConfig'])) {
                $behavior_array['GrpcConfig'] = ['Enabled' => false];
            }
        } else {
            // Default: include it for new distributions
            $behavior_array['GrpcConfig'] = ['Enabled' => false];
        }
        
        error_log('StaticForge CloudFront: Behavior configurado con policies: Cache=' . 
                 $behavior_array['CachePolicyId'] . ', OriginRequest=' . $behavior_array['OriginRequestPolicyId']);
        
        return $behavior_array;
    }
    
    private function get_s3_origin_id_from_config_array($distribution_config) {
        error_log('StaticForge CloudFront: Obteniendo Origin ID desde configuración SDK');
        
        try {
            if (!isset($distribution_config['Origins']) || !isset($distribution_config['Origins']['Items'])) {
                error_log('StaticForge CloudFront: No se encontraron Origins en configuración SDK');
                return false;
            }
            
            $origins = $distribution_config['Origins']['Items'];
            $s3_bucket = get_option('spg_s3_bucket');
            $s3_region = get_option('spg_s3_region', 'us-east-1');
            
            error_log('StaticForge CloudFront: Evaluando ' . count($origins) . ' origins');
            error_log('StaticForge CloudFront: Bucket S3 configurado: ' . $s3_bucket);
            
            // 1) Preferir origin con Id explícito "StaticS3Origin"
            foreach ($origins as $origin) {
                if (isset($origin['Id']) && $origin['Id'] === 'StaticS3Origin') {
                    error_log('StaticForge CloudFront: Origin preferido encontrado: StaticS3Origin');
                    return $origin['Id'];
                }
            }
            
            // 2) Buscar origin cuyo dominio contenga nuestro bucket S3
            foreach ($origins as $origin) {
                $domain_name = $origin['DomainName'];
                $origin_id = $origin['Id'];
                error_log('StaticForge CloudFront: Evaluando origin: ' . $origin_id . ' -> ' . $domain_name);
                
                // Check if it's an S3 origin (multiple possible formats)
                if (strpos($domain_name, $s3_bucket) !== false) {
                    error_log('StaticForge CloudFront: Origin S3 encontrado por nombre de bucket: ' . $origin_id);
                    return $origin_id;
                }
                
                // Check for S3 website endpoint
                if (strpos($domain_name, '.s3-website') !== false) {
                    error_log('StaticForge CloudFront: Origin S3 website encontrado: ' . $origin_id);
                    return $origin_id;
                }
                
                // Check for standard S3 domain
                if (strpos($domain_name, '.s3.amazonaws.com') !== false || 
                    strpos($domain_name, ".s3.{$s3_region}.amazonaws.com") !== false ||
                    strpos($domain_name, ".s3-{$s3_region}.amazonaws.com") !== false) {
                    error_log('StaticForge CloudFront: Origin S3 encontrado por dominio S3: ' . $origin_id);
                    return $origin_id;
                }
            }
            
            // 3) Si no encontramos match específico, usar el primer origin S3 que encontremos
            foreach ($origins as $origin) {
                if (isset($origin['S3OriginConfig'])) {
                    error_log('StaticForge CloudFront: Usando primer S3 origin encontrado: ' . $origin['Id']);
                    return $origin['Id'];
                }
            }
            
            // 4) Fallback: usar el primer origin disponible
            if (!empty($origins)) {
                $first_origin = $origins[0];
                error_log('StaticForge CloudFront: WARNING - Usando primer origin como fallback: ' . $first_origin['Id']);
                return $first_origin['Id'];
            }
            
            // 5) No se encontró ningún origin
            error_log('StaticForge CloudFront: ERROR - No se encontró ningún origin válido en la distribución.');
            return false;
            
        } catch (Exception $e) {
            error_log('StaticForge CloudFront: Error obteniendo Origin ID: ' . $e->getMessage());
            return false;
        }
    }
    
    private function create_cache_behavior_xml($path_pattern, $distribution_config) {
        error_log('StaticForge CloudFront: Creando XML para behavior pattern: ' . $path_pattern);
        
        // Obtener el primer origen S3 de la distribución actual
        $target_origin_id = $this->get_s3_origin_id_from_config($distribution_config);
        
        // Fallback si no se puede obtener origin ID
        if (!$target_origin_id) {
            $s3_bucket = get_option('spg_s3_bucket');
            $target_origin_id = $s3_bucket . '-origin';
            error_log('StaticForge CloudFront: Usando origin ID fallback: ' . $target_origin_id);
        }
        
        $behavior_xml = array(
            'PathPattern' => $path_pattern,
            'TargetOriginId' => $target_origin_id,
            'ViewerProtocolPolicy' => 'redirect-to-https',
            'CachePolicyId' => '4135ea2d-6df8-44a3-9df3-4b5a84be39ad',  // Managed-CachingDisabled
            'OriginRequestPolicyId' => '88a5eaf4-2fd4-4709-b370-b4c650ea3fcf',  // Managed-CORS-S3Origin
            'Compress' => 'true',
            'TrustedSigners' => array(
                'Enabled' => 'false',
                'Quantity' => '0'
            ),
            'ForwardedValues' => array(
                'QueryString' => 'false',
                'Cookies' => array('Forward' => 'none')
            ),
            'MinTTL' => '0',
            'DefaultTTL' => '86400',
            'MaxTTL' => '31536000'
        );
        
        // Sin CloudFront Function
        $behavior_xml['FunctionAssociations'] = array(
            'Quantity' => '0'
        );
        
        return $behavior_xml;
    }
    
    private function add_behavior_to_xml($cache_behaviors_element, $behavior_data) {
        error_log('StaticForge CloudFront: Añadiendo behavior a XML');
        
        try {
            // Crear nuevo elemento member para el behavior
            $member = $cache_behaviors_element->addChild('member');
            
            // Añadir cada elemento del behavior
            foreach ($behavior_data as $key => $value) {
                if (is_array($value)) {
                    $this->add_xml_array_element($member, $key, $value);
                } else {
                    $member->addChild($key, htmlspecialchars($value));
                }
            }
            
            // Actualizar la cantidad de behaviors
            $quantity = (int)($cache_behaviors_element->Quantity ?? 0) + 1;
            if (isset($cache_behaviors_element->Quantity)) {
                $cache_behaviors_element->Quantity = $quantity;
            } else {
                $cache_behaviors_element->addChild('Quantity', $quantity);
            }
            
            error_log('StaticForge CloudFront: Behavior XML añadido, nueva cantidad: ' . $quantity);
            return true;
            
        } catch (Exception $e) {
            error_log('StaticForge CloudFront: Error añadiendo behavior a XML: ' . $e->getMessage());
            return false;
        }
    }
    
    private function add_xml_array_element($parent, $key, $array) {
        $element = $parent->addChild($key);
        
        foreach ($array as $sub_key => $sub_value) {
            if (is_array($sub_value)) {
                $this->add_xml_array_element($element, $sub_key, $sub_value);
            } else {
                $element->addChild($sub_key, htmlspecialchars($sub_value));
            }
        }
    }
    
    private function update_distribution($distribution_config) {
        if (!$this->cloudfront_client) {
            error_log('StaticForge CloudFront: Cliente SDK no disponible para update');
            return false;
        }
        
        error_log('StaticForge CloudFront: Implementando update_distribution REAL usando SDK');
        
        if (!$distribution_config || !isset($distribution_config['config']) || !isset($distribution_config['etag'])) {
            error_log('StaticForge CloudFront: Configuración o ETag faltante');
            return false;
        }
        
        $config = $distribution_config['config'];
        $etag = $distribution_config['etag'];
        error_log('StaticForge CloudFront: Usando ETag: ' . $etag);
        
        // Debug: Log configuration before sending
        error_log('StaticForge CloudFront: === CONFIGURACIÓN A ENVIAR ===');
        error_log('StaticForge CloudFront: Total Behaviors a enviar: ' . $config['CacheBehaviors']['Quantity']);
        error_log('StaticForge CloudFront: Count real de Items: ' . count($config['CacheBehaviors']['Items']));
        
        // Verificar sincronización
        if ($config['CacheBehaviors']['Quantity'] != count($config['CacheBehaviors']['Items'])) {
            error_log('StaticForge CloudFront: ⚠️ WARNING: Quantity y Items count no coinciden!');
            // Forzar sincronización
            $config['CacheBehaviors']['Quantity'] = count($config['CacheBehaviors']['Items']);
            error_log('StaticForge CloudFront: Corregido Quantity a: ' . $config['CacheBehaviors']['Quantity']);
        }
        
        if (isset($config['CacheBehaviors']['Items'])) {
            foreach ($config['CacheBehaviors']['Items'] as $idx => $behavior) {
                error_log('StaticForge CloudFront: Behavior[' . $idx . ']: PathPattern=' . $behavior['PathPattern'] . 
                         ', TargetOriginId=' . $behavior['TargetOriginId'] . 
                         ', CachePolicyId=' . (isset($behavior['CachePolicyId']) ? $behavior['CachePolicyId'] : 'none'));
            }
        }
        
        // Debug: Verificar estructura completa
        error_log('StaticForge CloudFront: JSON de CacheBehaviors: ' . json_encode($config['CacheBehaviors']));
        
        try {
            $result = $this->cloudfront_client->updateDistribution([
                'Id' => $this->distribution_id,
                'DistributionConfig' => $config,
                'IfMatch' => $etag
            ]);
            
            error_log('StaticForge CloudFront: Distribución actualizada exitosamente usando SDK');
            error_log('StaticForge CloudFront: Nuevo ETag: ' . $result['ETag']);
            error_log('StaticForge CloudFront: Estado distribución: ' . $result['Distribution']['Status']);
            
            // Debug: Log response details
            if (isset($result['Distribution']['DistributionConfig']['CacheBehaviors'])) {
                $response_behaviors = $result['Distribution']['DistributionConfig']['CacheBehaviors'];
                error_log('StaticForge CloudFront: === RESPUESTA DE AWS ===');
                error_log('StaticForge CloudFront: Behaviors en respuesta: ' . $response_behaviors['Quantity']);
                if (isset($response_behaviors['Items'])) {
                    foreach ($response_behaviors['Items'] as $idx => $behavior) {
                        error_log('StaticForge CloudFront: Respuesta Behavior[' . $idx . ']: ' . $behavior['PathPattern']);
                    }
                }
            }
            
            return true;
            
        } catch (\Aws\CloudFront\Exception\CloudFrontException $e) {
            error_log('StaticForge CloudFront: Error CloudFront SDK: ' . $e->getMessage());
            error_log('StaticForge CloudFront: Error Code: ' . $e->getAwsErrorCode());
            error_log('StaticForge CloudFront: Request ID: ' . $e->getAwsRequestId());
            
            // Debug: Log detailed error response
            $error_response = $e->getResponse();
            if ($error_response) {
                $body = $error_response->getBody();
                error_log('StaticForge CloudFront: Error Response Body: ' . $body);
            }
            
            if ($e->getStatusCode() === 409) {
                error_log('StaticForge CloudFront: Conflicto 409 - ETag desactualizado o distribución siendo modificada');
            } elseif ($e->getStatusCode() === 400) {
                error_log('StaticForge CloudFront: Error 400 - Configuración inválida');
                // Log validation errors if available
                if (strpos($e->getMessage(), 'InvalidArgument') !== false) {
                    error_log('StaticForge CloudFront: Argumento inválido en la configuración');
                }
            } elseif ($e->getStatusCode() === 403) {
                error_log('StaticForge CloudFront: Error 403 - Permisos insuficientes');
            }
            
            return false;
            
        } catch (\Exception $e) {
            error_log('StaticForge CloudFront: Error general SDK: ' . $e->getMessage());
            error_log('StaticForge CloudFront: Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }
    
    private function serialize_distribution_config($config_xml) {
        error_log('StaticForge CloudFront: Iniciando serialización de configuración');
        
        try {
            // La configuración debe enviarse solo con el nodo DistributionConfig
            if (!isset($config_xml->DistributionConfig)) {
                error_log('StaticForge CloudFront: No se encontró DistributionConfig en XML');
                return false;
            }
            
            // Crear un nuevo documento XML para el payload
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->formatOutput = false;  // CloudFront es sensible al formato
            $dom->preserveWhiteSpace = false;
            
            // Importar el nodo DistributionConfig
            $distribution_config = $config_xml->DistributionConfig;
            
            // Convertir SimpleXML a DOMDocument
            $dom_config = dom_import_simplexml($distribution_config);
            $new_config = $dom->importNode($dom_config, true);
            $dom->appendChild($new_config);
            
            // Asegurar que el namespace esté correcto
            $root = $dom->documentElement;
            $root->setAttribute('xmlns', 'http://cloudfront.amazonaws.com/doc/2020-05-31/');
            
            $xml_string = $dom->saveXML();
            
            // Limpieza final del XML
            $xml_string = $this->clean_cloudfront_xml($xml_string);
            
            error_log('StaticForge CloudFront: XML serializado exitosamente, longitud: ' . strlen($xml_string));
            error_log('StaticForge CloudFront: XML preview: ' . substr($xml_string, 0, 200) . '...');
            
            return $xml_string;
            
        } catch (Exception $e) {
            error_log('StaticForge CloudFront: Error serializando XML: ' . $e->getMessage());
            return false;
        }
    }
    
    private function clean_cloudfront_xml($xml_string) {
        // Remover declaración XML duplicada si existe
        $xml_string = preg_replace('/^<\?xml[^>]*>\s*<\?xml[^>]*>/', '<?xml version="1.0" encoding="UTF-8"?>', $xml_string);
        
        // Asegurar que no haya espacios en blanco extra
        $xml_string = preg_replace('/>\s+</', '><', $xml_string);
        
        // Normalizar line endings
        $xml_string = str_replace("\r\n", "\n", $xml_string);
        $xml_string = str_replace("\r", "\n", $xml_string);
        
        return $xml_string;
    }
    
    private function get_s3_origin_id_from_config($distribution_config) {
        error_log('StaticForge CloudFront: Obteniendo Origin ID desde configuración');
        
        try {
            // Debug: imprimir estructura XML para entender el formato
            error_log('StaticForge CloudFront: Estructura XML: ' . substr($distribution_config->asXML(), 0, 1000));
            
            $origins = $distribution_config->DistributionConfig->Origins ?? null;
            if (!$origins) {
                error_log('StaticForge CloudFront: No se encontraron Origins en configuración');
                // Intentar diferentes estructuras posibles
                $origins = $distribution_config->Origins ?? null;
                if (!$origins) {
                    error_log('StaticForge CloudFront: Tampoco se encontraron Origins en nivel raíz');
                    return false;
                }
            }
            
            $s3_bucket = get_option('spg_s3_bucket');
            $s3_region = get_option('spg_s3_region', 'us-east-1');
            
            // Buscar origin que coincida con nuestro bucket S3
            foreach ($origins->member as $origin) {
                $domain_name = (string)$origin->DomainName;
                $origin_id = (string)$origin->Id;
                
                error_log('StaticForge CloudFront: Evaluando origin: ' . $origin_id . ' -> ' . $domain_name);
                
                // Verificar si es nuestro bucket S3
                if (strpos($domain_name, $s3_bucket) !== false || 
                    strpos($domain_name, "s3.{$s3_region}.amazonaws.com") !== false) {
                    error_log('StaticForge CloudFront: Origin S3 encontrado: ' . $origin_id);
                    return $origin_id;
                }
            }
            
            // Si no encontramos el bucket específico, usar el primer origin
            $first_origin_id = (string)$origins->member[0]->Id;
            error_log('StaticForge CloudFront: Usando primer origin como fallback: ' . $first_origin_id);
            return $first_origin_id;
            
        } catch (Exception $e) {
            error_log('StaticForge CloudFront: Error obteniendo Origin ID: ' . $e->getMessage());
            return false;
        }
    }
    
    public function create_invalidation($paths) {
        if (empty($this->distribution_id)) {
            return false;
        }
        if (!$this->cloudfront_client) {
            error_log('StaticForge CloudFront: Cliente SDK no disponible para invalidación');
            return false;
        }
        try {
            $this->cloudfront_client->createInvalidation([
                'DistributionId' => $this->distribution_id,
                'InvalidationBatch' => [
                    'Paths' => [
                        'Quantity' => count($paths),
                        'Items' => $paths,
                    ],
                    'CallerReference' => 'staticforge-' . time(),
                ],
            ]);
            return true;
        } catch (\Aws\CloudFront\Exception\CloudFrontException $e) {
            error_log('StaticForge CloudFront: Error creando invalidación con SDK: ' . $e->getMessage());
            return false;
        }
    }
    
    public function get_distribution_status() {
        if (empty($this->distribution_id)) {
            return false;
        }
        if (!$this->cloudfront_client) {
            return false;
        }
        try {
            $res = $this->cloudfront_client->getDistribution([
                'Id' => $this->distribution_id,
            ]);
            if (!isset($res['Distribution'])) {
                return false;
            }
            $dist = $res['Distribution'];
            return [
                'Status' => (string)($dist['Status'] ?? ''),
                'DomainName' => (string)($dist['DomainName'] ?? ''),
                'LastModifiedTime' => isset($dist['LastModifiedTime']) ? (string)$dist['LastModifiedTime'] : '',
            ];
        } catch (\Aws\CloudFront\Exception\CloudFrontException $e) {
            error_log('StaticForge CloudFront: Error obteniendo estatus de distribución con SDK: ' . $e->getMessage());
            return false;
        }
    }
    
    public function get_all_behaviors() {
        if (!$this->cloudfront_client || !$this->distribution_id) {
            return false;
        }
        
        try {
            $result = $this->cloudfront_client->getDistributionConfig([
                'Id' => $this->distribution_id
            ]);
            
            if (isset($result['DistributionConfig']['CacheBehaviors']['Items'])) {
                return $result['DistributionConfig']['CacheBehaviors']['Items'];
            }
            
            return [];
            
        } catch (\Exception $e) {
            error_log('StaticForge CloudFront: Error obteniendo behaviors: ' . $e->getMessage());
            return false;
        }
    }
    
    public function delete_behavior($path_pattern) {
        if (!$this->cloudfront_client || !$this->distribution_id) {
            error_log('StaticForge CloudFront: Cliente o Distribution ID no disponible');
            return false;
        }
        
        try {
            // 1. Obtener configuración actual
            $result = $this->cloudfront_client->getDistributionConfig([
                'Id' => $this->distribution_id
            ]);
            
            $config = $result['DistributionConfig'];
            $etag = $result['ETag'];
            
            // 2. Buscar y eliminar el behavior
            if (!isset($config['CacheBehaviors']['Items'])) {
                error_log('StaticForge CloudFront: No hay behaviors para eliminar');
                return false;
            }
            
            $new_behaviors = [];
            $found = false;
            
            foreach ($config['CacheBehaviors']['Items'] as $behavior) {
                if ($behavior['PathPattern'] !== $path_pattern) {
                    $new_behaviors[] = $behavior;
                } else {
                    $found = true;
                    error_log('StaticForge CloudFront: Behavior encontrado y marcado para eliminación: ' . $path_pattern);
                }
            }
            
            if (!$found) {
                error_log('StaticForge CloudFront: Behavior no encontrado: ' . $path_pattern);
                return false;
            }
            
            // 3. Actualizar la configuración
            $config['CacheBehaviors']['Items'] = $new_behaviors;
            $config['CacheBehaviors']['Quantity'] = count($new_behaviors);
            
            // 4. Enviar actualización a CloudFront
            $update_result = $this->cloudfront_client->updateDistribution([
                'Id' => $this->distribution_id,
                'DistributionConfig' => $config,
                'IfMatch' => $etag
            ]);
            
            error_log('StaticForge CloudFront: Behavior eliminado exitosamente: ' . $path_pattern);
            
            // 5. Invalidar cache para ese path
            $this->create_invalidation([$path_pattern, $path_pattern . '/*']);
            
            return true;
            
        } catch (\Exception $e) {
            error_log('StaticForge CloudFront: Error eliminando behavior: ' . $e->getMessage());
            return false;
        }
    }
    
    // Firma manual eliminada: CloudFront se maneja vía SDK
}

new StaticPageGenerator();
