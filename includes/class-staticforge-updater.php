<?php
/**
 * StaticForge Auto-Updater
 * 
 * Permite actualizaciones automáticas desde GitHub
 */

if (!defined('ABSPATH')) {
    exit;
}

class StaticForge_Updater {
    
    private $plugin_slug;
    private $version;
    private $github_username = 'gerswin';
    private $github_repo = 'StaticForge';
    private $github_response;
    
    public function __construct($plugin_file) {
        $this->plugin_slug = plugin_basename($plugin_file);
        
        // Obtener versión actual del plugin
        $plugin_data = get_file_data($plugin_file, array('Version' => 'Version'));
        $this->version = $plugin_data['Version'];
        
        // Hooks para verificar actualizaciones
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_filter('upgrader_source_selection', array($this, 'rename_github_folder'), 10, 3);
        
        // Agregar botón de verificación manual
        add_action('admin_notices', array($this, 'show_update_notice'));
        
        // Limpiar cache de actualizaciones
        add_action('admin_init', array($this, 'maybe_clear_update_cache'));
    }
    
    /**
     * Verificar si hay una nueva versión disponible
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        $github_data = $this->get_github_release();
        
        if (!$github_data) {
            return $transient;
        }
        
        // Comparar versiones
        $github_version = ltrim($github_data->tag_name, 'v');
        
        if (version_compare($this->version, $github_version, '<')) {
            $plugin_data = array(
                'slug' => dirname($this->plugin_slug),
                'plugin' => $this->plugin_slug,
                'new_version' => $github_version,
                'url' => $github_data->html_url,
                'package' => $github_data->zipball_url,
                'icons' => array(
                    '2x' => 'https://raw.githubusercontent.com/' . $this->github_username . '/' . $this->github_repo . '/main/assets/icon-256x256.png',
                    '1x' => 'https://raw.githubusercontent.com/' . $this->github_username . '/' . $this->github_repo . '/main/assets/icon-128x128.png',
                ),
                'tested' => '6.4', // Versión de WP testeada
                'requires_php' => '7.4',
                'compatibility' => new stdClass(),
            );
            
            $transient->response[$this->plugin_slug] = (object) $plugin_data;
        }
        
        return $transient;
    }
    
    /**
     * Obtener información del release más reciente desde GitHub
     */
    private function get_github_release() {
        if ($this->github_response !== null) {
            return $this->github_response;
        }
        
        // Verificar cache
        $cache_key = 'staticforge_github_release';
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            $this->github_response = $cached;
            return $cached;
        }
        
        // Llamar a la API de GitHub
        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_username,
            $this->github_repo
        );
        
        $headers = array(
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'StaticForge-Updater/' . $this->version,
        );

        // Permitir token para evitar rate limit o repos privados
        $token = defined('STATICFORGE_GITHUB_TOKEN') ? constant('STATICFORGE_GITHUB_TOKEN') : get_option('spg_github_token');
        if (!empty($token)) {
            $headers['Authorization'] = 'token ' . $token;
        }

        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => 10,
        ));
        
        if (is_wp_error($response)) {
            error_log('StaticForge Updater: Error obteniendo release de GitHub: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);
        
        if (empty($data->tag_name)) {
            return false;
        }
        
        // Guardar en cache por 6 horas
        set_transient($cache_key, $data, 6 * HOUR_IN_SECONDS);
        
        $this->github_response = $data;
        return $data;
    }
    
    /**
     * Proporcionar información del plugin para el modal de actualización
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        
        if ($args->slug !== dirname($this->plugin_slug)) {
            return $result;
        }
        
        $github_data = $this->get_github_release();
        
        if (!$github_data) {
            return $result;
        }
        
        $plugin_info = array(
            'name' => 'StaticForge',
            'slug' => dirname($this->plugin_slug),
            'version' => ltrim($github_data->tag_name, 'v'),
            'author' => '<a href="https://github.com/' . $this->github_username . '">Gerswin Pineda</a>',
            'author_profile' => 'https://github.com/' . $this->github_username,
            'download_link' => $github_data->zipball_url,
            'trunk' => $github_data->zipball_url,
            'last_updated' => $github_data->published_at,
            'homepage' => $github_data->html_url,
            'sections' => array(
                'description' => 'Convierte WordPress en HTML estático con soporte para S3 y CloudFront.',
                'changelog' => $this->parse_changelog($github_data->body),
            ),
            'banners' => array(
                'low' => 'https://raw.githubusercontent.com/' . $this->github_username . '/' . $this->github_repo . '/main/assets/banner-772x250.png',
                'high' => 'https://raw.githubusercontent.com/' . $this->github_username . '/' . $this->github_repo . '/main/assets/banner-1544x500.png',
            ),
        );
        
        return (object) $plugin_info;
    }
    
    /**
     * Parsear changelog desde el body del release
     */
    private function parse_changelog($body) {
        // Convertir Markdown a HTML básico
        $changelog = $body;
        
        // Convertir headers
        $changelog = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $changelog);
        $changelog = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $changelog);
        
        // Convertir listas
        $changelog = preg_replace('/^\* (.+)$/m', '<li>$1</li>', $changelog);
        $changelog = preg_replace('/^\- (.+)$/m', '<li>$1</li>', $changelog);
        
        // Envolver listas en <ul>
        $changelog = preg_replace('/((<li>.+<\/li>\n?)+)/', '<ul>$1</ul>', $changelog);
        
        // Convertir saltos de línea
        $changelog = nl2br($changelog);
        
        return $changelog;
    }
    
    /**
     * Renombrar la carpeta descargada de GitHub
     */
    public function rename_github_folder($source, $remote_source, $upgrader) {
        global $wp_filesystem;

        // Asegurar que solo afectamos a este plugin
        if (!isset($upgrader->skin) || empty($upgrader->skin->plugin) || $upgrader->skin->plugin !== $this->plugin_slug) {
            return $source;
        }

        $expected_dir = dirname($this->plugin_slug); // nombre de carpeta del plugin
        $source_basename = basename(untrailingslashit($source));

        // Si ya coincide, no hacer nada
        if ($source_basename === $expected_dir) {
            return $source;
        }

        // Directorio destino con el nombre esperado junto al source actual
        $destination = trailingslashit(dirname($source)) . $expected_dir . '/';

        // Si existe, intentar eliminar para evitar conflictos
        if ($wp_filesystem->is_dir($destination)) {
            $wp_filesystem->delete($destination, true);
        }

        // Renombrar/mover la carpeta descomprimida al nombre correcto
        if ($wp_filesystem->move($source, $destination, true)) {
            return $destination;
        }

        // En caso de fallo, devolver el original
        return $source;
    }
    
    /**
     * Mostrar notificación de actualización disponible
     */
    public function show_update_notice() {
        if (!current_user_can('update_plugins')) {
            return;
        }
        
        $github_data = $this->get_github_release();
        
        if (!$github_data) {
            return;
        }
        
        $github_version = ltrim($github_data->tag_name, 'v');
        
        if (version_compare($this->version, $github_version, '<')) {
            $update_url = wp_nonce_url(
                self_admin_url('update.php?action=upgrade-plugin&plugin=' . $this->plugin_slug),
                'upgrade-plugin_' . $this->plugin_slug
            );
            
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <strong>StaticForge:</strong> 
                    Nueva versión <?php echo esc_html($github_version); ?> disponible 
                    (actual: <?php echo esc_html($this->version); ?>).
                    <a href="<?php echo esc_url($update_url); ?>" class="button button-primary" style="margin-left: 10px;">
                        Actualizar ahora
                    </a>
                    <a href="<?php echo esc_url($github_data->html_url); ?>" target="_blank" style="margin-left: 10px;">
                        Ver cambios
                    </a>
                </p>
            </div>
            <?php
        }
    }
    
    /**
     * Limpiar cache de actualizaciones si se solicita
     */
    public function maybe_clear_update_cache() {
        if (isset($_GET['force-check']) && $_GET['force-check'] === '1') {
            delete_transient('staticforge_github_release');
            delete_site_transient('update_plugins');
        }
    }
    
    /**
     * Método para verificar actualizaciones manualmente
     */
    public static function check_now() {
        delete_transient('staticforge_github_release');
        delete_site_transient('update_plugins');
        wp_update_plugins();
        
        return true;
    }
}
