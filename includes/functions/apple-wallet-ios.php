<?php
// includes/functions/apple-wallet-ios.php
if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . '../libs/pkpass-loader.php';
eventosapp_include_pkpass();

/* ============================================================
 * ====================== HELPERS DEBUG =======================
 * ============================================================ */

/**
 * Guarda entradas de depuración asociadas a un ticket en meta `_evapp_pk_console`
 * y también envía a error_log(). Se reutiliza en otros módulos.
 *
 * Versión “silenciosa”: no emite logs ni imprime en consola.
 */
if (!function_exists('evapp_pk_debug_append')) {
    function evapp_pk_debug_append($ticket_id, $title, $data){
        // No-op para producción (se evita escribir meta y error_log).
        return;
    }
}

/**
 * Inyecta en el admin un dump a console.log de `_evapp_pk_console`
 * para el post actual (tickets/eventos). Deshabilitado en producción.
 */
if (!function_exists('evapp_pk_console_admin_bootstrap')) {
    function evapp_pk_console_admin_bootstrap(){
        // No-op: se deshabilita cualquier salida a la consola del navegador.
        return;
    }
    add_action('admin_print_footer_scripts', 'evapp_pk_console_admin_bootstrap', 99);
}

/* ============================================================
 * ======================== COLOR HEX =========================
 * ============================================================ */

/** Convierte HEX a "rgb(r,g,b)" con fallback */
function evapp_hex_to_rgb_str($hex, $fallback='rgb(55,130,196)', $ticket_id=0, $label='HEX'){
    $raw = (string)$hex;
    $x = ltrim(trim($raw), '#');
    $ok = (bool) preg_match('/^[0-9A-Fa-f]{6}$/', $x);
    if (!$ok){
        return $fallback;
    }
    $r = hexdec(substr($x,0,2));
    $g = hexdec(substr($x,2,2));
    $b = hexdec(substr($x,4,2));
    return "rgb($r,$g,$b)";
}

/* ============================================================
 * ==================== IMÁGENES DESCARGA =====================
 * ============================================================ */

/**
 * Descarga imagen por URL y la normaliza a PNG si es posible (ruta temporal).
 * $asset_tag: 'icon' | 'logo' | 'strip' | 'img' (solo para referencia interna).
 */
function evapp_dl_png_or_null($url, $asset_tag='img', $ticket_id=0){
    $tag = $asset_tag ?: 'img';
    if (!$url){
        return null;
    }

    $res = wp_remote_get($url, ['timeout'=>15]);
    if (is_wp_error($res)) {
        return null;
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    if ($code < 200 || $code >= 300 || !$body) return null;

    // Intentar convertir a PNG si es una imagen válida
    $img = @imagecreatefromstring($body);
    if ($img) {
        $tmp = wp_tempnam('evapp_png');
        @unlink($tmp);
        $png = $tmp . '.png';
        imagepng($img, $png);
        imagedestroy($img);
        return $png;
    }

    // Último recurso: guarda tal cual (puede no ser PNG)
    $tmp = wp_tempnam('evapp_any');
    file_put_contents($tmp, $body);
    return $tmp;
}

/* ============================================================
 * ===================== PNG SÓLIDO AUX =======================
 * ============================================================ */

/** Genera un PNG sólido (p.ej. placeholder para icono) y devuelve ruta */
function evapp_make_solid_png($w, $h, $rgb='rgb(55,130,196)', $ticket_id=0, $label='solid'){
    $ok = preg_match('/^rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)$/', (string)$rgb);
    if (!$ok) $rgb = 'rgb(55,130,196)';
    [$r,$g,$b] = array_map('intval', explode(',', trim($rgb,'rgb() ')));
    $im = imagecreatetruecolor((int)$w, (int)$h);
    imagesavealpha($im, true);
    $bg = imagecolorallocatealpha($im, $r, $g, $b, 0);
    imagefilledrectangle($im, 0, 0, (int)$w, (int)$h, $bg);
    $file = wp_tempnam('evapp_solid');
    @unlink($file);
    $file .= '.png';
    imagepng($im, $file);
    imagedestroy($im);
    return $file;
}

/* ============================================================
 * =================== ADD FILE (CUALQUIER LIB) ===============
 * ============================================================ */

/**
 * Añade un archivo BINARIO al PK con un nombre destino exacto.
 * Intenta: addFileFromString → addData → addFile.
 */
function evapp_pk_add_named_binary($pk, $destName, $binary, $ticket_id=0){
    if ($binary === '' || $binary === null) {
        return false;
    }

    // 1) Preferir agregar por string directo
    if (method_exists($pk, 'addFileFromString')) {
        try {
            $pk->addFileFromString($destName, $binary);
            return true;
        } catch (\Throwable $e) {
            // continuar con fallback
        }
    }

    // 2) Fallback addData(name, bytes)
    if (method_exists($pk, 'addData')) {
        try {
            $pk->addData($destName, $binary);
            return true;
        } catch (\Throwable $e) {
            // continuar con fallback
        }
    }

    // 3) Último recurso: escribir a disco con ese NOMBRE y usar addFile()
    $tmp = wp_tempnam(basename($destName));
    @unlink($tmp);
    $tmp = dirname($tmp) . '/' . basename($destName); // asegura nombre correcto
    file_put_contents($tmp, $binary);

    if (method_exists($pk, 'addFile')) {
        try {
            $pk->addFile($tmp);
            return true;
        } catch (\Throwable $e) {
            // sin más opciones
        }
    }

    return false;
}

/* ============================================================
 * ================== CONFIGURACIÓN (APPLE) ===================
 * ============================================================ */

/** Helpers configuración Apple (sin logging sensible) */
function evapp_apple_cfg($ticket_id=0) {
    return [
        'team_id'      => get_option('eventosapp_apple_team_id',''),
        'pass_type_id' => get_option('eventosapp_apple_pass_type_id',''),
        'org_name'     => get_option('eventosapp_apple_org_name','EventosApp'),
        'p12_path'     => get_option('eventosapp_apple_p12_path',''),
        'p12_pass'     => get_option('eventosapp_apple_p12_pass',''),
        'wwdr_pem'     => get_option('eventosapp_apple_wwdr_pem',''),
        'env'          => get_option('eventosapp_apple_env','sandbox'),
        'auth_base'    => get_option('eventosapp_apple_auth_base',''),
        'ws_url'       => home_url('/eventosapp-passkit/v1'),
    ];
}

/* ============================================================
 * ============== WRAPPER / COMPATIBILIDAD (PKPASS) ===========
 * ============================================================ */

/**
 * Generador Apple (WRAPPER):
 * Delegar SIEMPRE al generador canonizado `eventosapp_generar_enlace_wallet_apple()`.
 * Mantiene compatibilidad con código que aún invoca `eventosapp_apple_generate_pass()`.
 */
if (!function_exists('eventosapp_apple_generate_pass')) {
    function eventosapp_apple_generate_pass($ticket_id, $debug=false){
        if (function_exists('eventosapp_generar_enlace_wallet_apple')) {
            return eventosapp_generar_enlace_wallet_apple($ticket_id);
        }
        return false;
    }
}

/** Borra el enlace .pkpass del ticket si se desactiva Apple */
function eventosapp_apple_delete_pass($ticket_id){
    delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple');
}
