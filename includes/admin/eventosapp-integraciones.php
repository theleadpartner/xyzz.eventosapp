<?php
if (!defined('ABSPATH')) exit;

/**
 * Página de Integraciones para EventosApp (Google Wallet + Apple Wallet)
 * - Guarda por admin-post.php para evitar bloqueos de WAF/ModSecurity
 * - Campos Apple (icon/logo/strip) con llaves POST neutras
 * - **Instrumentación de consola**: Volcado detallado en Chrome DevTools (Console)
 */

add_action('admin_menu', function() {
    add_submenu_page(
        'eventosapp_dashboard',
        'Integraciones',
        'Integraciones',
        'manage_options',
        'eventosapp_integraciones',
        'eventosapp_render_integraciones_page'
    );
}, 15);

/** ===== Helpers ===== */

function eventosapp_valid_hex($hex, $default = '#3782C4'){
    $hex = trim((string)$hex);
    if ($hex === '') return $default;
    if ($hex[0] !== '#') $hex = '#'.$hex;
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) ? strtoupper($hex) : $default;
}

/** Acepta URLs absolutas http/https o rutas relativas dentro del WP. */
function eventosapp_normalize_url_flexible($raw){
    $raw = trim((string) wp_unslash($raw));
    if ($raw === '') return '';

    // Absoluta http/https
    if (filter_var($raw, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $raw)) {
        return esc_url_raw($raw, ['http','https']);
    }
    // Protocol-relative //host/...
    if (strpos($raw, '//') === 0) {
        $scheme = parse_url(home_url('/'), PHP_URL_SCHEME) ?: 'https';
        return esc_url_raw($scheme . ':' . $raw, ['http','https']);
    }
    // Desde raíz /wp-content/...
    if ($raw[0] === '/') {
        return esc_url_raw(home_url($raw), ['http','https']);
    }
    // Prefijos comunes sin barra
    if (preg_match('#^(wp-content/|content/uploads/|uploads/|includes/)#i', $raw)) {
        return esc_url_raw(home_url('/' . ltrim($raw, '/')), ['http','https']);
    }
    return '';
}

/**
 * Guarda una opción de URL sólo si la clave llega en $_POST (para no “blanquear”
 * cuando el WAF haya cortado el campo).
 */
function eventosapp_update_url_option_if_present($option_name, $post_key, $label=''){
    if (!array_key_exists($post_key, $_POST)) {
        // No tocar valor previo si el WAF quitó el campo
        error_log("[EventosApp] Integraciones: '{$option_name}' no modificado (POST key ausente: {$post_key})");
        return;
    }
    $raw = wp_unslash($_POST[$post_key]);
    $label = $label ?: $option_name;

    if (trim($raw) === '') {
        update_option($option_name, '');
        return;
    }
    $normalized = eventosapp_normalize_url_flexible($raw);
    if ($normalized !== '') {
        update_option($option_name, $normalized);
    } else {
        error_log("[EventosApp] Integraciones: URL inválida para {$option_name} (raw='{$raw}')");
    }
}

/** ===== Guardado por admin-post ===== */

add_action('admin_post_eventosapp_guardar_integraciones', 'eventosapp_guardar_integraciones');
function eventosapp_guardar_integraciones(){
    if (!current_user_can('manage_options')) wp_die('Permisos insuficientes.');
    check_admin_referer('eventosapp_integraciones_guardar','eventosapp_integraciones_nonce');

    // --- GOOGLE WALLET (global) ---
    update_option('eventosapp_wallet_issuer_id', sanitize_text_field($_POST['eventosapp_wallet_issuer_id'] ?? ''));
    update_option('eventosapp_wallet_class_id',  sanitize_text_field($_POST['eventosapp_wallet_class_id']  ?? ''));
    eventosapp_update_url_option_if_present('eventosapp_wallet_logo_url',     'gw_logo_url',     'Google Wallet - Logo URL');
    eventosapp_update_url_option_if_present('eventosapp_wallet_hero_img_url', 'gw_hero_url',     'Google Wallet - Hero URL');
    update_option('eventosapp_wallet_branding_text', sanitize_text_field($_POST['eventosapp_wallet_branding_text'] ?? ''));
    update_option('eventosapp_wallet_hex_color', eventosapp_valid_hex($_POST['eventosapp_wallet_hex_color'] ?? '#3782C4', '#3782C4'));

    // Subida JSON credenciales
    if (!empty($_FILES['eventosapp_wallet_json']['tmp_name'])) {
        $uploaded = $_FILES['eventosapp_wallet_json'];
        $dir = wp_upload_dir();
        $target_dir = trailingslashit($dir['basedir']) . 'eventosapp-integraciones/';
        if (!file_exists($target_dir)) wp_mkdir_p($target_dir);
        $target_file = $target_dir . 'google-service-account.json';
        if (move_uploaded_file($uploaded['tmp_name'], $target_file)) {
            update_option('eventosapp_wallet_json_path', $target_file);
        } else {
            error_log('[EventosApp] Integraciones: Error subiendo google-service-account.json');
        }
    }

    // --- APPLE WALLET (global) ---
    update_option('eventosapp_apple_team_id',      sanitize_text_field($_POST['eventosapp_apple_team_id'] ?? ''));
    update_option('eventosapp_apple_pass_type_id', sanitize_text_field($_POST['eventosapp_apple_pass_type_id'] ?? ''));
    update_option('eventosapp_apple_org_name',     sanitize_text_field($_POST['eventosapp_apple_org_name'] ?? 'EventosApp'));
    update_option('eventosapp_apple_p12_pass',     sanitize_text_field($_POST['eventosapp_apple_p12_pass'] ?? ''));

    $env = $_POST['eventosapp_apple_env'] ?? 'sandbox';
    $env = in_array($env, ['sandbox','production'], true) ? $env : 'sandbox';
    update_option('eventosapp_apple_env', $env);

    // Auth base (si no existe, generar)
    $auth = get_option('eventosapp_apple_auth_base', '');
    if (!$auth) { $auth = wp_generate_password(32, false, false); }
    update_option('eventosapp_apple_auth_base', $auth);

    // Subidas de certificados Apple
    $dir = wp_upload_dir();
    $apple_dir = trailingslashit($dir['basedir']) . 'eventosapp-integraciones/apple/';
    if (!file_exists($apple_dir)) wp_mkdir_p($apple_dir);

    if (!empty($_FILES['eventosapp_apple_p12']['tmp_name'])) {
        $p12file = $apple_dir . 'pass-certificates.p12';
        if (move_uploaded_file($_FILES['eventosapp_apple_p12']['tmp_name'], $p12file)) {
            update_option('eventosapp_apple_p12_path', $p12file);
        } else {
            error_log('[EventosApp] Integraciones: Error subiendo .p12');
        }
    }
    if (!empty($_FILES['eventosapp_apple_wwdr']['tmp_name'])) {
        $wwdrfile = $apple_dir . 'AppleWWDRCAG4.pem';
        if (move_uploaded_file($_FILES['eventosapp_apple_wwdr']['tmp_name'], $wwdrfile)) {
            update_option('eventosapp_apple_wwdr_pem', $wwdrfile);
        } else {
            error_log('[EventosApp] Integraciones: Error subiendo WWDR .pem');
        }
    }

    // Branding Apple (global por defecto) — llaves POST neutras
    eventosapp_update_url_option_if_present('eventosapp_apple_icon_default_url',  'ab_i', 'Apple - Icon URL');
    eventosapp_update_url_option_if_present('eventosapp_apple_logo_default_url',  'ab_l', 'Apple - Logo URL');
    eventosapp_update_url_option_if_present('eventosapp_apple_strip_default_url', 'ab_s', 'Apple - Strip URL');

    update_option('eventosapp_apple_bg_hex',    eventosapp_valid_hex($_POST['eventosapp_apple_bg_hex']    ?? '', '#3782C4'));
    update_option('eventosapp_apple_fg_hex',    eventosapp_valid_hex($_POST['eventosapp_apple_fg_hex']    ?? '', '#FFFFFF'));
    update_option('eventosapp_apple_label_hex', eventosapp_valid_hex($_POST['eventosapp_apple_label_hex'] ?? '', '#FFFFFF'));

    // Redirigir de vuelta a la página con bandera de éxito
    $redirect = add_query_arg(['page'=>'eventosapp_integraciones','saved'=>'1'], admin_url('admin.php'));
    wp_safe_redirect($redirect);
    exit;
}

/** ============================================================
 * ====== Botón manual: reparar/crear reglas .htaccess PKPASS ==
 * ============================================================ */

/** Detección simple de reglas PKPASS en un texto dado (.htaccess) */
function eventosapp_pkpass_rules_present_in_text($text){
    if (!is_string($text) || $text==='') return false;

    // Debe existir el MIME
    $has_mime = (bool) preg_match('~AddType\s+application/vnd\.apple\.pkpass\s+\.pkpass~i', $text);

    // Deben existir ambos headers base
    $has_ct  = (bool) preg_match('~Header\s+set\s+Content-Type\s+"application/vnd\.apple\.pkpass"~i', $text);
    $has_cd  = (bool) preg_match('~Header\s+set\s+Content-Disposition\s+"inline"~i', $text);

    // Aceptamos cualquiera de las dos formas de desactivar gzip
    $has_nogzip = (bool) (
        preg_match('~SetEnv\s+no-gzip\s+1~i', $text) ||
        preg_match('~SetEnvIfNoCase\s+Request_URI\s+"\\\\?\.pkpass\$"\s+no-gzip~i', $text)
    );

    // Anti-caché completo
    $has_cache = (bool) (
        preg_match('~Header\s+set\s+Cache-Control\s+"no-cache,\s*no-store,\s*must-revalidate"~i', $text) &&
        preg_match('~Header\s+set\s+Pragma\s+"no-cache"~i', $text) &&
        preg_match('~Header\s+set\s+Expires\s+"0"~i', $text)
    );

    return ($has_mime && $has_ct && $has_cd && $has_nogzip && $has_cache);
}


/** Estado actual de reglas en raíz y en uploads/eventosapp-pkpass/.htaccess */
function eventosapp_pkpass_current_state(){
    $root_ok = false;  $uploads_ok = false;

    // Raíz
    $root_file = ABSPATH . '.htaccess';
    if (file_exists($root_file) && is_readable($root_file)) {
        $c = @file_get_contents($root_file);
        if ($c && eventosapp_pkpass_rules_present_in_text($c)) $root_ok = true;
    }
    // Uploads
    $uploads = wp_upload_dir();
    $up_file = trailingslashit($uploads['basedir']).'eventosapp-pkpass/.htaccess';
    if (file_exists($up_file) && is_readable($up_file)) {
        $c2 = @file_get_contents($up_file);
        if ($c2 && eventosapp_pkpass_rules_present_in_text($c2)) $uploads_ok = true;
    }
    return ['root_ok'=>$root_ok, 'uploads_ok'=>$uploads_ok, 'root_file'=>$root_file, 'uploads_file'=>$up_file];
}

/** Handler del botón: intenta escribir reglas en raíz y si no, en uploads */
add_action('admin_post_eventosapp_fix_pkpass_htaccess', 'eventosapp_fix_pkpass_htaccess');
function eventosapp_fix_pkpass_htaccess(){
    if (!current_user_can('manage_options')) wp_die('Permisos insuficientes.');
    check_admin_referer('eventosapp_fix_pkpass_htaccess');

    // Si YA existen en raíz o uploads, no hacemos nada y avisamos.
    $state = eventosapp_pkpass_current_state();
    if ($state['root_ok'] || $state['uploads_ok']) {
        $dest = add_query_arg([
            'page'      => 'eventosapp_integraciones',
            'pkpassht'  => 'already',
            'root'      => (int)$state['root_ok'],
            'uploads'   => (int)$state['uploads_ok'],
        ], admin_url('admin.php'));
        wp_safe_redirect($dest);
        exit;
    }

    // Forzar intento (quitamos el throttle diario de la auto-sanitización)
    delete_option('eventosapp_pkpass_htaccess_checked');

    $wrote = 'fail';
    $reason = '';

    // 1) Intento en raíz
    if (function_exists('eventosapp_write_root_htaccess_rules')) {
        $ok = eventosapp_write_root_htaccess_rules();
        if ($ok) { $wrote = 'root'; }
    }

    // 2) Fallback a uploads si raíz falló
    if ($wrote !== 'root' && function_exists('eventosapp_write_uploads_pkpass_htaccess')) {
        $ok2 = eventosapp_write_uploads_pkpass_htaccess();
        if ($ok2) { $wrote = 'uploads'; }
    }

    // Razón simple si todo falló (permisos típicos)
    if ($wrote==='fail') {
        $root_file = ABSPATH.'.htaccess';
        if (!file_exists($root_file) && !is_writable(ABSPATH)) {
            $reason = 'La carpeta raíz no es escribible para crear .htaccess';
        } elseif (file_exists($root_file) && !is_writable($root_file)) {
            $reason = 'El archivo .htaccess en la raíz no es escribible';
        } else {
            $uploads = wp_upload_dir();
            $up_dir = trailingslashit($uploads['basedir']).'eventosapp-pkpass/';
            if (!file_exists($up_dir) && !wp_mkdir_p($up_dir)) {
                $reason = 'No se pudo crear la carpeta uploads/eventosapp-pkpass';
            } else {
                $up_ht = $up_dir.'.htaccess';
                if (file_exists($up_ht) && !is_writable($up_ht)) {
                    $reason = 'El .htaccess en uploads/eventosapp-pkpass no es escribible';
                }
            }
        }
    }

    $dest = add_query_arg([
        'page'     => 'eventosapp_integraciones',
        'pkpassht' => $wrote,
        'why'      => rawurlencode($reason),
    ], admin_url('admin.php'));
    wp_safe_redirect($dest);
    exit;
}

/** ===== Render ===== */

function eventosapp_render_integraciones_page() {

    // Recuperar valores para mostrar
    $issuer_id  = get_option('eventosapp_wallet_issuer_id', '');
    $class_id   = get_option('eventosapp_wallet_class_id',  '');
    $logo_url   = get_option('eventosapp_wallet_logo_url',  '');
    $hero_url   = get_option('eventosapp_wallet_hero_img_url', '');
    $brand_txt  = get_option('eventosapp_wallet_branding_text', '');
    $json_path  = get_option('eventosapp_wallet_json_path', '');
    $hex_color  = get_option('eventosapp_wallet_hex_color', '#3782C4');

    $apple_team     = get_option('eventosapp_apple_team_id','');
    $apple_pass     = get_option('eventosapp_apple_pass_type_id','');
    $apple_org      = get_option('eventosapp_apple_org_name','EventosApp');
    $apple_p12      = get_option('eventosapp_apple_p12_path','');
    $apple_p12_pass = get_option('eventosapp_apple_p12_pass','');
    $apple_wwdr     = get_option('eventosapp_apple_wwdr_pem','');
    $apple_env      = get_option('eventosapp_apple_env','sandbox');
    $apple_auth     = get_option('eventosapp_apple_auth_base','');

    $apple_icon_def  = get_option('eventosapp_apple_icon_default_url','');
    $apple_logo_def  = get_option('eventosapp_apple_logo_default_url','');
    $apple_strip_def = get_option('eventosapp_apple_strip_default_url','');
    $apple_bg_hex    = get_option('eventosapp_apple_bg_hex','#3782C4');
    $apple_fg_hex    = get_option('eventosapp_apple_fg_hex','#FFFFFF');
    $apple_label_hex = get_option('eventosapp_apple_label_hex','#FFFFFF');

    // Fallbacks del plugin
    $plugin_base_dir = plugin_dir_path(__FILE__);
    $plugin_base_url = plugin_dir_url(__FILE__);
    $fallbacks = [
        'icon'  => ['path' => $plugin_base_dir.'includes/assets/apple/icon.png',  'url' => $plugin_base_url.'includes/assets/apple/icon.png'],
        'logo'  => ['path' => $plugin_base_dir.'includes/assets/apple/logo.png',  'url' => $plugin_base_url.'includes/assets/apple/logo.png'],
        'strip' => ['path' => $plugin_base_dir.'includes/assets/apple/strip.png', 'url' => $plugin_base_url.'includes/assets/apple/strip.png'],
    ];

    // Para mapear URL -> PATH como hará el generador
    $uploads = wp_upload_dir();
    $uploads_baseurl = $uploads['baseurl'];
    $uploads_basedir = $uploads['basedir'];

    ?>
    <div class="wrap">
        <h1>Integraciones de EventosApp</h1>

        <?php if (!empty($_GET['saved'])): ?>
            <div class="updated notice is-dismissible"><p>Configuración guardada.</p></div>
        <?php endif; ?>

        <?php
        // Avisos del botón de reparación .htaccess
        if (!empty($_GET['pkpassht'])) {
            $m = sanitize_text_field($_GET['pkpassht']);
            $why = isset($_GET['why']) ? esc_html($_GET['why']) : '';
            if ($m === 'already') {
                $rootHad    = !empty($_GET['root']);
                $uploadsHad = !empty($_GET['uploads']);
                echo '<div class="updated notice is-dismissible"><p><b>PKPASS .htaccess:</b> Ya existían las reglas'
                   . ($rootHad ? ' (raíz)' : '') . ($uploadsHad ? ' (uploads)' : '')
                   . '. <b>No fue necesario</b> escribir nada.</p></div>';
            } elseif ($m === 'root') {
                echo '<div class="updated notice is-dismissible"><p><b>PKPASS .htaccess:</b> Reglas escritas en <b>raíz</b> correctamente.</p></div>';
            } elseif ($m === 'uploads') {
                echo '<div class="updated notice is-dismissible"><p><b>PKPASS .htaccess:</b> No se pudo escribir en raíz; creado <b>uploads/eventosapp-pkpass/.htaccess</b> como fallback.</p></div>';
            } elseif ($m === 'fail') {
                echo '<div class="error notice is-dismissible"><p><b>PKPASS .htaccess:</b> No se pudo escribir en raíz ni en uploads. '.$why.'</p></div>';
            }
        }
        ?>

        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" novalidate autocomplete="off">
            <input type="hidden" name="action" value="eventosapp_guardar_integraciones">
            <?php wp_nonce_field('eventosapp_integraciones_guardar', 'eventosapp_integraciones_nonce'); ?>

            <h2>Google Wallet (Android)</h2>
            <table class="form-table">
                <tr>
                    <th><label>Archivo google-service-account.json:</label></th>
                    <td>
                        <input type="file" name="eventosapp_wallet_json" accept=".json"><br>
                        <?php
                        if ($json_path && file_exists($json_path)) {
                            echo '<span style="color:green">Archivo cargado: <b>' . esc_html(basename($json_path)) . '</b></span>';
                        } else {
                            echo '<span style="color:#888;">No cargado</span>';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th><label>Issuer ID:</label></th>
                    <td><input type="text" name="eventosapp_wallet_issuer_id" value="<?php echo esc_attr($issuer_id); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label>Class ID (global):</label></th>
                    <td><input type="text" name="eventosapp_wallet_class_id" value="<?php echo esc_attr($class_id); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label>URL Logo (global):</label></th>
                    <td><input type="text" name="gw_logo_url" value="<?php echo esc_attr($logo_url); ?>" class="regular-text" placeholder="https://.../logo.png o /wp-content/..."></td>
                </tr>
                <tr>
                    <th><label>URL Imagen Hero (global):</label></th>
                    <td><input type="text" name="gw_hero_url" value="<?php echo esc_attr($hero_url); ?>" class="regular-text" placeholder="https://.../hero.png o /wp-content/..."></td>
                </tr>
                <tr>
                    <th><label>Color HEX (clase):</label></th>
                    <td><input type="text" name="eventosapp_wallet_hex_color" value="<?php echo esc_attr($hex_color); ?>" class="regular-text" placeholder="#3782C4"></td>
                </tr>
                <tr>
                    <th><label>Texto Branding:</label></th>
                    <td><input type="text" name="eventosapp_wallet_branding_text" value="<?php echo esc_attr($brand_txt); ?>" class="regular-text"></td>
                </tr>
            </table>

            <hr>
            <h2>Apple Wallet (PassKit)</h2>
            <table class="form-table">
                <tr>
                    <th><label>Team ID</label></th>
                    <td><input name="eventosapp_apple_team_id" class="regular-text" value="<?php echo esc_attr($apple_team); ?>"></td>
                </tr>
                <tr>
                    <th><label>Pass Type ID</label></th>
                    <td><input name="eventosapp_apple_pass_type_id" class="regular-text" value="<?php echo esc_attr($apple_pass); ?>"></td>
                </tr>
                <tr>
                    <th><label>Organization Name</label></th>
                    <td><input name="eventosapp_apple_org_name" class="regular-text" value="<?php echo esc_attr($apple_org); ?>"></td>
                </tr>
                <tr>
                    <th><label>Certificado .p12</label></th>
                    <td>
                        <input type="file" name="eventosapp_apple_p12" accept=".p12">
                        <?php echo ($apple_p12 && file_exists($apple_p12)) ? '<span style="color:green;margin-left:8px">Subido</span>' : '<span style="color:#888;margin-left:8px">No subido</span>'; ?>
                    </td>
                </tr>
                <tr>
                    <th><label>Password del .p12</label></th>
                    <td><input type="password" name="eventosapp_apple_p12_pass" class="regular-text" value="<?php echo esc_attr($apple_p12_pass); ?>"></td>
                </tr>
                <tr>
                    <th><label>WWDR .pem</label></th>
                    <td>
                        <input type="file" name="eventosapp_apple_wwdr" accept=".pem">
                        <?php echo ($apple_wwdr && file_exists($apple_wwdr)) ? '<span style="color:green;margin-left:8px">Subido</span>' : '<span style="color:#888;margin-left:8px">No subido</span>'; ?>
                    </td>
                </tr>
                <tr>
                    <th><label>Entorno APNs</label></th>
                    <td>
                        <label><input type="radio" name="eventosapp_apple_env" value="sandbox" <?php checked($apple_env,'sandbox'); ?>> Sandbox</label>
                        &nbsp;&nbsp;
                        <label><input type="radio" name="eventosapp_apple_env" value="production" <?php checked($apple_env,'production'); ?>> Production</label>
                    </td>
                </tr>
                <tr>
                    <th><label>Web Service URL</label></th>
                    <td><input readonly class="regular-text" value="<?php echo esc_url( home_url('/eventosapp-passkit/v1') ); ?>"></td>
                </tr>
                <tr>
                    <th><label>Auth base</label></th>
                    <td><code><?php echo esc_html($apple_auth); ?></code></td>
                </tr>

                <!-- Branding Apple por defecto -->
                <tr><th colspan="2"><h3>Branding Apple (por defecto, si el evento no sobreescribe)</h3></th></tr>
                <tr>
                    <th><label>Icon URL (png)</label></th>
                    <td>
                        <input type="text" name="ab_i" value="<?php echo esc_attr($apple_icon_def); ?>" class="regular-text" placeholder="https://.../icon.png o /wp-content/.../icon.png" autocomplete="off">
                        <?php
                        echo file_exists($fallbacks['icon']['path'])
                            ? '<span style="margin-left:8px;color:#2271b1">Fallback detectado: <a href="'.esc_url($fallbacks['icon']['url']).'" target="_blank">icon.png</a></span>'
                            : '<span style="margin-left:8px;color:#888">Sin fallback en plugin</span>';
                        ?>
                    </td>
                </tr>
                <tr>
                    <th><label>Logo URL (png)</label></th>
                    <td>
                        <input type="text" name="ab_l" value="<?php echo esc_attr($apple_logo_def); ?>" class="regular-text" placeholder="https://.../logo.png" autocomplete="off">
                        <?php
                        echo file_exists($fallbacks['logo']['path'])
                            ? '<span style="margin-left:8px;color:#2271b1">Fallback detectado</span>'
                            : '<span style="margin-left:8px;color:#888">Sin fallback en plugin</span>';
                        ?>
                    </td>
                </tr>
                <tr>
                    <th><label>Strip URL (png)</label></th>
                    <td>
                        <input type="text" name="ab_s" value="<?php echo esc_attr($apple_strip_def); ?>" class="regular-text" placeholder="https://.../strip.png" autocomplete="off">
                        <?php
                        echo file_exists($fallbacks['strip']['path'])
                            ? '<span style="margin-left:8px;color:#2271b1">Fallback detectado</span>'
                            : '<span style="margin-left:8px;color:#888">Sin fallback en plugin</span>';
                        ?>
                    </td>
                </tr>
                <tr>
                    <th><label>BG HEX</label></th>
                    <td><input type="text" name="eventosapp_apple_bg_hex" value="<?php echo esc_attr($apple_bg_hex); ?>" class="regular-text" placeholder="#3782C4"></td>
                </tr>
                <tr>
                    <th><label>FG HEX</label></th>
                    <td><input type="text" name="eventosapp_apple_fg_hex" value="<?php echo esc_attr($apple_fg_hex); ?>" class="regular-text" placeholder="#FFFFFF"></td>
                </tr>
                <tr>
                    <th><label>Label HEX</label></th>
                    <td><input type="text" name="eventosapp_apple_label_hex" value="<?php echo esc_attr($apple_label_hex); ?>" class="regular-text" placeholder="#FFFFFF"></td>
                </tr>
            </table>

            <br>
            <button type="submit" class="button button-primary">Guardar Cambios</button>
        </form>

        <?php
        // === Tarjeta de estado + botón "Reparar .htaccess PKPASS" (fuera del form principal) ===
        $st = eventosapp_pkpass_current_state();
        $root_mark  = $st['root_ok'] ? '✅' : '❌';
        $upl_mark   = $st['uploads_ok'] ? '✅' : '❌';
        $root_time  = (int) get_option('eventosapp_pkpass_htaccess_root_ok', 0);
        $upl_time   = (int) get_option('eventosapp_pkpass_htaccess_uploads_ok', 0);
        ?>
        <hr>
        <h2>.htaccess para PKPASS (servir pase con cabeceras correctas)</h2>
        <div style="border:1px solid #ccd0d4;padding:12px;border-radius:6px;background:#fff;">
            <p style="margin:0 0 10px">
                <b>Estado actual</b><br>
                Raíz (<code><?php echo esc_html($st['root_file']); ?></code>): <?php echo $root_mark; ?>
                <?php if ($root_time) echo ' <span style="color:#666">(escrito auto: '.esc_html( date_i18n('Y-m-d H:i', $root_time) ).')</span>'; ?><br>
                Uploads (<code><?php echo esc_html($st['uploads_file']); ?></code>): <?php echo $upl_mark; ?>
                <?php if ($upl_time) echo ' <span style="color:#666">(escrito auto: '.esc_html( date_i18n('Y-m-d H:i', $upl_time) ).')</span>'; ?>
            </p>

            <?php if ($st['root_ok'] || $st['uploads_ok']): ?>
                <p style="margin:6px 0 10px; color:#0a0;"><b>Listo:</b> Ya hay reglas válidas. <u>No es necesario</u> volver a escribir.</p>
                <p style="margin:6px 0 0; color:#666;">Si cambiaste de Nginx/Apache o ves problemas de cabeceras, puedes forzar de nuevo, pero normalmente no hace falta.</p>
            <?php else: ?>
                <p style="margin:6px 0 10px; color:#d63638;"><b>Faltan reglas:</b> No se detectan en raíz ni en uploads.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin-top:10px;">
                <input type="hidden" name="action" value="eventosapp_fix_pkpass_htaccess">
                <?php wp_nonce_field('eventosapp_fix_pkpass_htaccess'); ?>
                <button class="button button-secondary" <?php disabled(($st['root_ok'] || $st['uploads_ok'])); ?>>
                    Reparar .htaccess PKPASS ahora
                </button>
                <span style="margin-left:8px;color:#666;">Intenta raíz; si falla, crea el de <code>uploads/eventosapp-pkpass</code>.</span>
            </form>
        </div>
    </div>
    <?php
    // =========================
    // === LOGS EN CONSOLA ====
    // =========================

    // Datos que imprimiremos en consola (incluye secretos, A PROPÓSITO para depuración)
    $data = [
        'team_id'      => (string)$apple_team,
        'pass_type_id' => (string)$apple_pass,
        'org_name'     => (string)$apple_org,
        'p12_path'     => (string)$apple_p12,
        'p12_pass'     => (string)$apple_p12_pass, // se imprimirá tal cual
        'wwdr_pem'     => (string)$apple_wwdr,
        'env'          => (string)$apple_env,
        'ws_url'       => (string)home_url('/eventosapp-passkit/v1'),
        'auth_base'    => (string)$apple_auth,

        // Branding por defecto (si el evento no sobreescribe)
        'icon_url'     => (string)$apple_icon_def,
        'logo_url'     => (string)$apple_logo_def,
        'strip_url'    => (string)$apple_strip_def,

        // Colores
        'bg_hex'       => (string)$apple_bg_hex,
        'fg_hex'       => (string)$apple_fg_hex,
        'label_hex'    => (string)$apple_label_hex,

        // Uploads mapping para resolver rutas locales
        'uploads_baseurl' => (string)$uploads_baseurl,
        'uploads_basedir' => (string)$uploads_basedir,

        // Fallbacks del plugin
        'fallbacks' => $fallbacks,
    ];

    // Función PHP para simular la resolución URL -> PATH como hace el generador:
    $resolve = function($maybe) use ($uploads_baseurl, $uploads_basedir) {
        $maybe = (string) $maybe;
        if ($maybe === '') return ['input'=>'', 'action'=>'empty', 'resolved'=>null, 'exists'=>false];

        // Si es absoluta http/https
        if (preg_match('~^https?://~i', $maybe)) {
            if (strpos($maybe, $uploads_baseurl) === 0) {
                $rel = substr($maybe, strlen($uploads_baseurl));
                $path = $uploads_basedir . $rel;
                return ['input'=>$maybe, 'action'=>'map_uploads_url_to_path', 'resolved'=>$path, 'exists'=>file_exists($path)];
            }
            // No es del propio uploads → en el generador se descarga a tmp.
            return ['input'=>$maybe, 'action'=>'download_to_tmp (simulado)', 'resolved'=>null, 'exists'=>false];
        }

        // Si ya vino como PATH local
        if (file_exists($maybe)) {
            return ['input'=>$maybe, 'action'=>'path_as_is', 'resolved'=>$maybe, 'exists'=>true];
        }

        // Si es relativo /wp-content/...
        if ($maybe && $maybe[0] === '/') {
            $abs = home_url($maybe);
            if (strpos($abs, $uploads_baseurl) === 0) {
                $rel = substr($abs, strlen($uploads_baseurl));
                $path = $uploads_basedir . $rel;
                return ['input'=>$maybe, 'action'=>'root_to_home_to_path', 'resolved'=>$path, 'exists'=>file_exists($path)];
            }
            return ['input'=>$maybe, 'action'=>'root_to_home (fuera de uploads)', 'resolved'=>null, 'exists'=>false];
        }

        return ['input'=>$maybe, 'action'=>'unhandled', 'resolved'=>null, 'exists'=>false];
    };

    $icon_res  = $resolve($apple_icon_def);
    $logo_res  = $resolve($apple_logo_def);
    $strip_res = $resolve($apple_strip_def);

    // Incrustamos todo como JSON seguro y lo volcamos con console.log
    $js_data = [
        'config' => $data,
        'assets_resolve' => [
            'icon'  => $icon_res,
            'logo'  => $logo_res,
            'strip' => $strip_res,
        ],
        // Simulación de cómo "quedarían" los nombres de destino para addFile()
        'would_addFile' => [
            ['source' => $icon_res['resolved'],  'destName' => 'icon.png',    'exists' => $icon_res['exists']],
            ['source' => $icon_res['resolved'],  'destName' => 'icon@2x.png', 'exists' => $icon_res['exists']],
            ['source' => $logo_res['resolved'],  'destName' => 'logo.png',    'exists' => $logo_res['exists']],
            ['source' => $strip_res['resolved'], 'destName' => 'strip.png',   'exists' => $strip_res['exists']],
        ],
        'file_presence' => [
            'p12_exists'  => ($apple_p12 && file_exists($apple_p12)),
            'wwdr_exists' => ($apple_wwdr && file_exists($apple_wwdr)),
        ],
    ];

    ?>
    <script>
    (function(){
        try {
            const D = <?php echo wp_json_encode($js_data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?>;

            // ==============================
            // CONFIG (con secretos visibles)
            // ==============================
            console.groupCollapsed('%cEVENTOSAPP → Apple Wallet: CONFIG ACTUAL','color:#0b5; font-weight:bold;');
            console.log('Team ID:', D.config.team_id);
            console.log('Pass Type ID:', D.config.pass_type_id);
            console.log('Organization Name:', D.config.org_name);
            console.log('Certificado .p12 (path):', D.config.p12_path);
            console.log('%cPassword .p12 (VISIBLE INTENCIONALMENTE): %s','color:#b00; font-weight:bold;', D.config.p12_pass);
            console.log('WWDR .pem (path):', D.config.wwdr_pem);
            console.log('APNs env:', D.config.env);
            console.log('Web Service URL:', D.config.ws_url);
            console.log('Auth base:', D.config.auth_base);
            console.groupEnd();

            // ==============================
            // BRANDING / ASSETS (URL crudas)
            // ==============================
            console.groupCollapsed('%cEVENTOSAPP → Apple Wallet: ASSETS (URL crudas)','color:#06c; font-weight:bold;');
            console.log('Icon URL (png):', D.config.icon_url || '(vacío)');
            console.log('Logo URL (png):', D.config.logo_url || '(vacío)');
            console.log('Strip URL (png):', D.config.strip_url || '(vacío)');
            console.groupEnd();

            // ==============================
            // COLORES
            // ==============================
            console.groupCollapsed('%cEVENTOSAPP → Apple Wallet: COLORES','color:#a60; font-weight:bold;');
            console.log('BG HEX:', D.config.bg_hex);
            console.log('FG HEX:', D.config.fg_hex);
            console.log('Label HEX:', D.config.label_hex);
            console.groupEnd();

            // ==============================
            // RESOLUCIÓN URL → PATH LOCAL
            // ==============================
            console.groupCollapsed('%cEVENTOSAPP → Apple Wallet: RESOLUCIÓN DE RUTAS','color:#444; font-weight:bold;');
            console.log('uploads.baseurl:', D.config.uploads_baseurl);
            console.log('uploads.basedir:', D.config.uploads_basedir);

            const printRes = (name, r) => {
                console.group(name);
                console.log('Entrada:', r.input);
                console.log('Acción:', r.action);
                console.log('Path resuelto:', r.resolved || '(nulo)');
                console.log('¿Existe en disco?:', r.exists);
                console.groupEnd();
            };
            printRes('icon',  D.assets_resolve.icon);
            printRes('logo',  D.assets_resolve.logo);
            printRes('strip', D.assets_resolve.strip);
            console.groupEnd();

            // ==============================
            // SIMULACIÓN addFile()
            // ==============================
            console.groupCollapsed('%cEVENTOSAPP → Apple Wallet: SIMULACIÓN addFile()','color:#b0b; font-weight:bold;');
            D.would_addFile.forEach((f) => {
                console.log('addFile(source= %o , destName= "%s") → source existe: %s',
                    f.source || '(null)',
                    f.destName,
                    f.exists ? 'sí' : 'NO'
                );
            });
            console.groupEnd();

            // ==============================
            // CERTS: EXISTENCIA FÍSICA
            // ==============================
            console.groupCollapsed('%cEVENTOSAPP → Apple Wallet: CERTIFICADOS','color:#b00; font-weight:bold;');
            console.log('.p12 existe?:', D.file_presence.p12_exists);
            console.log('WWDR .pem existe?:', D.file_presence.wwdr_exists);
            if (!D.file_presence.p12_exists) console.warn('ATENCIÓN: El .p12 NO existe en el path indicado.');
            if (!D.file_presence.wwdr_exists) console.warn('ATENCIÓN: El WWDR .pem NO existe en el path indicado.');
            console.groupEnd();

            // ==============================
            // FALLBACKS del plugin
            // ==============================
            console.groupCollapsed('%cEVENTOSAPP → Apple Wallet: FALLBACKS del plugin','color:#2271b1; font-weight:bold;');
            console.log('icon fallback path:', D.config.fallbacks.icon.path, ' | exists=', <?php echo file_exists($fallbacks['icon']['path']) ? 'true':'false'; ?>);
            console.log('logo fallback path:', D.config.fallbacks.logo.path, ' | exists=', <?php echo file_exists($fallbacks['logo']['path']) ? 'true':'false'; ?>);
            console.log('strip fallback path:', D.config.fallbacks.strip.path, ' | exists=', <?php echo file_exists($fallbacks['strip']['path']) ? 'true':'false'; ?>);
            console.groupEnd();

            // ==============================
            // NOTA DE SEGURIDAD
            // ==============================
            console.info('%cNOTA: Estos logs muestran credenciales (password .p12) y rutas internas. Elimínalos en producción.','color:#b00; font-weight:bold;');

        } catch (e) {
            console.error('EVENTOSAPP Console Debug error:', e);
        }
    })();
    </script>
    <?php
}
