<?php
/**
 * includes/public/eventosapp-pkpass-endpoint.php
 *
 * Endpoints públicos para servir archivos .pkpass
 * - URL bonita:   /eventosapp-pkpass/{serial}.pkpass
 * - Querystring:  /?evapp_pkpass={serial}
 * - REST:         /wp-json/eventosapp/v1/pkpass/{serial}
 *
 * Pensado para cargarse SIEMPRE (frontend y admin). No depender de is_admin().
 */

if (!defined('ABSPATH')) exit;

/* ============================================================
 * =============== REWRITE TAG + REGLA "BONITA" ===============
 * ============================================================ */

/**
 * Registramos el tag y la regla para /eventosapp-pkpass/{serial}.pkpass
 * (Permitimos @ en el serial para compatibilidad con pases externos)
 */
add_action('init', function () {
    // Permite capturar la variable desde WP_Query
    add_rewrite_tag('%evapp_pkpass%', '([A-Za-z0-9._@-]+)');
    // Regla bonita
    add_rewrite_rule(
        '^eventosapp-pkpass/([A-Za-z0-9._@-]+)\.pkpass$',
        'index.php?evapp_pkpass=$matches[1]',
        'top'
    );
}, 5);

/** Acepta la query var también por ?evapp_pkpass=SERIAL */
add_filter('query_vars', function ($vars) {
    if (!in_array('evapp_pkpass', $vars, true)) $vars[] = 'evapp_pkpass';
    return $vars;
});

/* ============================================================
 * ================== SERVIDOR DEL ARCHIVO ====================
 * ============================================================ */

/**
 * Intercepta la request y entrega el .pkpass con cabeceras correctas.
 * Funciona tanto con la URL bonita como con el querystring.
 */
add_action('template_redirect', function () {
    // 1) Primero intenta con la query var parseada por WP (URL bonita)
    $serial = get_query_var('evapp_pkpass');
    // 2) Fallback: querystring directo
    if (!$serial && isset($_GET['evapp_pkpass'])) {
        $serial = (string) wp_unslash($_GET['evapp_pkpass']);
    }
    if (!$serial) return; // no es una petición de pkpass

    // Sanitizar conservando '@' y los caracteres válidos. Evitar sanitize_file_name.
    $serial = preg_replace('/[^A-Za-z0-9._@-]/', '', (string)$serial);
    if ($serial === '') {
        status_header(400);
        wp_die('Serial inválido', '400', ['response' => 400]);
    }

    $uploads = wp_upload_dir();
    $path    = trailingslashit($uploads['basedir']) . "eventosapp-pkpass/{$serial}.pkpass";

    if (!file_exists($path)) {
        status_header(404);
        wp_die('Pass no encontrado', '404', ['response' => 404]);
    }

    // Info del archivo
    $size = (int) filesize($path);
    $mtime = filemtime($path);
    $last_modified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

    // Respuesta 304 si el cliente envía If-Modified-Since válido
    if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
        $ims = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
        if ($ims && $ims >= $mtime) {
            header('Last-Modified: ' . $last_modified);
            header('Content-Type: application/vnd.apple.pkpass');
            header('Content-Disposition: inline; filename="' . $serial . '.pkpass"');
            header('Cache-Control: public, max-age=2592000, no-transform');
            header('X-Content-Type-Options: nosniff');
            status_header(304);
            exit;
        }
    }

    // Desactivar compresión en tiempo de ejecución por si el server/proxy la fuerza
    if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
    @ini_set('zlib.output_compression', 'Off');

    // Cabeceras para Apple Wallet
    header('Content-Type: application/vnd.apple.pkpass');
    header('Content-Disposition: inline; filename="' . $serial . '.pkpass"');
    header('Content-Length: ' . $size);
    header('Last-Modified: ' . $last_modified);
    // Blindajes / caching: permitir cache público y evitar transformaciones (optimizers/CDNs)
    header('Cache-Control: public, max-age=2592000, no-transform');
    header('X-Content-Type-Options: nosniff');

    // Soporte HEAD: sólo cabeceras
    if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'HEAD') {
        exit;
    }

    // Entrega del archivo
    readfile($path);
    exit;
}, 5);

/* ============================================================
 * ======================= RUTA REST ==========================
 * ============================================================ */

/**
 * Ruta REST útil detrás de proxies/CDNs:
 *   GET /wp-json/eventosapp/v1/pkpass/{serial}
 */
add_action('rest_api_init', function () {
    register_rest_route('eventosapp/v1', '/pkpass/(?P<serial>[A-Za-z0-9._@-]+)', [
        'methods'             => ['GET', 'HEAD'],
        'permission_callback' => '__return_true',
        'callback'            => function ($req) {
            $serial_raw = (string) ($req['serial'] ?? '');
            $serial = preg_replace('/[^A-Za-z0-9._@-]/', '', $serial_raw);
            if ($serial === '') {
                return new WP_Error('bad_request', 'Serial inválido', ['status' => 400]);
            }

            $uploads = wp_upload_dir();
            $path    = trailingslashit($uploads['basedir']) . "eventosapp-pkpass/{$serial}.pkpass";

            if (!file_exists($path)) {
                return new WP_Error('not_found', 'Pass no encontrado', ['status' => 404]);
            }

            $size = (int) filesize($path);
            $mtime = filemtime($path);
            $last_modified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

            // 304 si aplica
            $ims = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : false;
            if ($ims && $ims >= $mtime) {
                header('Last-Modified: ' . $last_modified);
                header('Content-Type: application/vnd.apple.pkpass');
                header('Content-Disposition: inline; filename="' . $serial . '.pkpass"');
                header('Cache-Control: public, max-age=2592000, no-transform');
                header('X-Content-Type-Options: nosniff');
                status_header(304);
                exit;
            }

            // Desactivar compresión (runtime)
            if (function_exists('apache_setenv')) { @apache_setenv('no-gzip','1'); }
            @ini_set('zlib.output_compression','Off');

            header('Content-Type: application/vnd.apple.pkpass');
            header('Content-Disposition: inline; filename="' . $serial . '.pkpass"');
            header('Content-Length: ' . $size);
            header('Last-Modified: ' . $last_modified);
            header('Cache-Control: public, max-age=2592000, no-transform');
            header('X-Content-Type-Options: nosniff');

            // HEAD => solo cabeceras
            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
                exit;
            }

            readfile($path);
            exit;
        },
    ]);
});

/* ============================================================
 * =========== FLUSH DE REWRITE RULES (UNA SOLA VEZ) ==========
 * ============================================================ */

/**
 * Si no puedes registrar un activation hook en el archivo principal,
 * hacemos un flush una sola vez en admin para asegurar las reglas.
 */
add_action('admin_init', function () {
    $flag = (int) get_option('eventosapp_pkpass_rules_flushed_once', 0);
    if (!$flag) {
        flush_rewrite_rules(false);
        update_option('eventosapp_pkpass_rules_flushed_once', time(), false);
    }
}, 20);

/**
 * Si tu archivo principal del plugin define la constante con su ruta,
 * puedes usar este hook para hacer flush al activar (preferible).
 *
 * En el archivo principal:
 *   if (!defined('EVENTOSAPP_PLUGIN_FILE')) define('EVENTOSAPP_PLUGIN_FILE', __FILE__);
 *   require_once __DIR__ . '/includes/public/eventosapp-pkpass-endpoint.php';
 */
if (defined('EVENTOSAPP_PLUGIN_FILE') && function_exists('register_activation_hook')) {
    register_activation_hook(EVENTOSAPP_PLUGIN_FILE, function () {
        flush_rewrite_rules(false);
        update_option('eventosapp_pkpass_rules_flushed_once', time(), false);
    });
}
