<?php
/*
Plugin Name: EventosApp
Description: Gestión de eventos y tickets para asistentes.
Version: 1.1
Author: The Lead Partner
*/

if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path(__FILE__) . 'eventosapp-tickets.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-integraciones.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions/google-wallet-android.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-badges.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-wallet-hooks.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-extras-ticket.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-email-header.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-sesiones.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-configuracion.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-co-gestion.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-frontend-helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-frontend-dashboard.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-frontend-search.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-frontend-register.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-public-register.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-qr-checkin.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-generador-masivo-qr.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-frontend-edit.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-frontend-metrics.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-campos-adicionales.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-privacidad.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-api-autocomplete.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions/eventosapp-ticket-email.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions/eventosapp-email-masivo.php';
require_once plugin_dir_path(__FILE__) . 'includes/api/eventosapp-intake-ac.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/metabox-curl.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-herramientas.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-embed-form.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-edicion-masiva.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-networking-auth.php';
require_once plugin_dir_path(__FILE__) . 'includes/public/eventosapp-pkpass-endpoint.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-notificaciones-evento.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-frontend-auto-register.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-auto-networking.php';  // <-- NUEVO: Auto-creación páginas networking
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-event-checklist.php'; // <-- NUEVO
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-batch-refresh.php';   // <-- NUEVO: batch refresh tickets por evento
require_once plugin_dir_path(__FILE__) . 'includes/functions/eventosapp-webhook-conditionals.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions/eventosapp-networking-search.php';




// === Debug de correo (solo si EVENTOSAPP_DEBUG_MAIL está habilitado) ===
if (defined('EVENTOSAPP_DEBUG_MAIL') && EVENTOSAPP_DEBUG_MAIL) {

    // 1) Log detallado cuando falla wp_mail (mensajes + DATA completa)
    add_action('wp_mail_failed', function($wp_error){
        if (is_wp_error($wp_error)) {
            error_log('[EventosApp] wp_mail_failed: ' . implode(' | ', $wp_error->get_error_messages()));
            $data = $wp_error->get_error_data();
            if ($data) {
                // data incluye: to, subject, message, headers, attachments, phpmailer_exception_code
                error_log('[EventosApp] wp_mail_failed DATA: ' . print_r($data, true));
            }
        } else {
            error_log('[EventosApp] wp_mail_failed desconocido');
        }
    }, 10, 1);

    // 2) Log antes de enviar (para ver exactamente qué se intenta mandar)
    add_filter('pre_wp_mail', function($null, $atts){
        // $atts = ['to'=>..., 'subject'=>..., 'message'=>..., 'headers'=>..., 'attachments'=>...]
        error_log('[EventosApp] pre_wp_mail ATTS: ' . print_r($atts, true));
        return $null; // no cortamos el envío
    }, 10, 2);

    // 3) Debug de PHPMailer a error_log (mientras depuras)
    add_action('phpmailer_init', function($phpmailer){
        // Si usas SMTP (WP Mail SMTP, Post SMTP, etc.) verás el diálogo SMTP aquí
        $phpmailer->SMTPDebug  = 2; // 0 = off, 1/2 = info detallada
        $phpmailer->Debugoutput = function($str, $level) {
            error_log("[PHPMailer:$level] $str");
        };
    });

}


// === Activación: preparar .htaccess PKPASS y flushear en el próximo init (sin do_action('init')) ===
register_activation_hook(__FILE__, function () {
    // Escribe/asegura el bloque de .htaccess para .pkpass
    if ( ! function_exists('insert_with_markers') && file_exists( ABSPATH . 'wp-admin/includes/misc.php' ) ) {
        require_once ABSPATH . 'wp-admin/includes/misc.php';
    }
    if ( function_exists('eventosapp_pkpass_activation') ) {
        eventosapp_pkpass_activation();
    }

    // Marca para flushear cuando corra el init real de WP
    update_option('eventosapp_needs_flush', 1);
});

// En el primer init real, registra reglas (tu plugin ya las agrega con add_action('init', ...)) y flushea
add_action('init', function () {
    if ( get_option('eventosapp_needs_flush') ) {
        flush_rewrite_rules();
        delete_option('eventosapp_needs_flush');
    }
}, 99);

// (Opcional) Desactivación limpia: remueve el bloque y flushea
register_deactivation_hook(__FILE__, function () {
    if ( ! function_exists('insert_with_markers') && file_exists( ABSPATH . 'wp-admin/includes/misc.php' ) ) {
        require_once ABSPATH . 'wp-admin/includes/misc.php';
    }
    if ( function_exists('insert_with_markers') ) {
        $root_ht = ABSPATH . '.htaccess';
        if ( file_exists($root_ht) && is_writable($root_ht) ) {
            insert_with_markers($root_ht, 'EventosApp PKPASS', []); // elimina el bloque
        }
    }
    flush_rewrite_rules();
});


register_deactivation_hook(__FILE__, function () {
    // (Opcional) Limpia el bloque en raíz si quieres dejar todo como estaba
    if (!function_exists('insert_with_markers') && file_exists(ABSPATH.'wp-admin/includes/misc.php')) {
        require_once ABSPATH.'wp-admin/includes/misc.php';
    }
    if (function_exists('insert_with_markers')) {
        $root_ht = ABSPATH . '.htaccess';
        if (file_exists($root_ht) && is_writable($root_ht)) {
            insert_with_markers($root_ht, 'EventosApp PKPASS', []); // elimina el bloque
        }
    }

    flush_rewrite_rules();
});



// === Color Picker para el CPT de eventos ===
add_action('admin_enqueue_scripts', function(){
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'eventosapp_event' ) {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        // wp_enqueue_media(); // <-- necesario para el frame de la biblioteca si vas a usar Media Library
    }
});


/**
 * Menú principal y submenús (estructura customizada)
 */
add_action('admin_menu', function() {
    add_menu_page(
        'EventosApp',                // Page title
        'EventosApp',                // Menu title
        'manage_options',            // Capability
        'eventosapp_dashboard',      // Menu slug (no página real)
        '__return_null',             // Callback
        'dashicons-calendar-alt',    // Icon
        25                           // Position
    );

    // Submenú "Eventos"
    add_submenu_page(
        'eventosapp_dashboard',
        'Eventos',
        'Eventos',
        'manage_options',
        'edit.php?post_type=eventosapp_event'
    );

    // Submenú "Tickets"
    add_submenu_page(
        'eventosapp_dashboard',
        'Tickets',
        'Tickets',
        'manage_options',
        'edit.php?post_type=eventosapp_ticket'
    );
}, 9);


/**
 * Cambiar el "menu_parent" de los CPT a nuestro menú principal
 */
add_filter('parent_file', function($parent_file) {
    global $current_screen;
    if (isset($current_screen->post_type) && in_array($current_screen->post_type, ['eventosapp_event', 'eventosapp_ticket'])) {
        return 'eventosapp_dashboard';
    }
    return $parent_file;
});


/**
 * 1. Registrar el Custom Post Type "eventosapp_event"
 */
add_action('init', function() {
    register_post_type('eventosapp_event', [
        'labels' => [
            'name'               => 'Eventos',
            'singular_name'      => 'Evento',
            'add_new'            => 'Agregar Nuevo',
            'add_new_item'       => 'Agregar Nuevo Evento',
            'edit_item'          => 'Editar Evento',
            'new_item'           => 'Nuevo Evento',
            'view_item'          => 'Ver Evento',
            'search_items'       => 'Buscar Eventos',
            'not_found'          => 'No se encontraron eventos',
            'not_found_in_trash' => 'No se encontraron eventos en la papelera',
        ],
        'public'              => true,
        'menu_icon'           => 'dashicons-calendar-alt',
        'supports'            => ['title', 'thumbnail', 'custom-fields'],
        'has_archive'         => true,
        'rewrite'             => ['slug' => 'eventos'],
        'show_in_rest'        => true,
        // NO poner menu_position, ni menu_parent aquí
        'show_ui'             => true,
        'show_in_menu'        => false, // <-- solo en menú custom
    ]);
});


/**
 * 2. Metabox para detalles del evento + otras metabox
 */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_detalles_evento',
        'Detalles del Evento',
        'eventosapp_render_metabox_evento',
        'eventosapp_event',
        'normal',
        'default'
    );

    add_meta_box(
        'eventosapp_localidades_evento',
        'Localidades del Evento',
        'eventosapp_render_metabox_localidades',
        'eventosapp_event',
        'normal',
        'default'
    );
});


/**
 * Metabox: Detalles del Evento (INCLUYE Wallet por evento)
 */
function eventosapp_render_metabox_evento($post) {
    // Recuperar valores guardados
    $tipo_fecha   = get_post_meta($post->ID, '_eventosapp_tipo_fecha', true) ?: 'unica';
    $fecha_unica  = get_post_meta($post->ID, '_eventosapp_fecha_unica', true) ?: '';
    $fecha_inicio = get_post_meta($post->ID, '_eventosapp_fecha_inicio', true) ?: '';
    $fecha_fin    = get_post_meta($post->ID, '_eventosapp_fecha_fin', true) ?: '';
    $fechas_noco  = get_post_meta($post->ID, '_eventosapp_fechas_noco', true) ?: [];
    $hora_inicio  = get_post_meta($post->ID, '_eventosapp_hora_inicio', true) ?: '';
    $hora_cierre  = get_post_meta($post->ID, '_eventosapp_hora_cierre', true) ?: '';
    $zona_horaria = get_post_meta($post->ID, '_eventosapp_zona_horaria', true) ?: '';

    // Campos del evento
    $direccion         = get_post_meta($post->ID, '_eventosapp_direccion', true) ?: '';
    $coordenadas       = get_post_meta($post->ID, '_eventosapp_coordenadas', true) ?: '';
    $organizador       = get_post_meta($post->ID, '_eventosapp_organizador', true) ?: '';
    $organizador_email = get_post_meta($post->ID, '_eventosapp_organizador_email', true) ?: '';
    $organizador_tel   = get_post_meta($post->ID, '_eventosapp_organizador_tel', true) ?: '';

    if (!is_array($fechas_noco)) {
        $fechas_noco = is_string($fechas_noco) && $fechas_noco ? explode(',', $fechas_noco) : [];
    }

    // === PERSONALIZACIÓN WALLET (por evento) ===
    $wallet_custom_enable = get_post_meta($post->ID, '_eventosapp_wallet_custom_enable', true) === '1' ? '1' : '0';
    $wallet_class_id      = get_post_meta($post->ID, '_eventosapp_wallet_class_id', true) ?: '';
    $wallet_logo_url      = get_post_meta($post->ID, '_eventosapp_wallet_logo_url', true) ?: '';
    $wallet_hero_url      = get_post_meta($post->ID, '_eventosapp_wallet_hero_img_url', true) ?: '';
    $wallet_hex_color     = get_post_meta($post->ID, '_eventosapp_wallet_hex_color', true) ?: '#3782C4';

    // Apple overrides (opcionales por evento)
    $apple_icon_url  = get_post_meta($post->ID, '_eventosapp_apple_icon_url',  true) ?: '';
    $apple_logo_url  = get_post_meta($post->ID, '_eventosapp_apple_logo_url',  true) ?: '';
    $apple_strip_url = get_post_meta($post->ID, '_eventosapp_apple_strip_url', true) ?: '';
    $apple_hex_bg    = get_post_meta($post->ID, '_eventosapp_apple_hex_bg',    true) ?: '';
    $apple_hex_fg    = get_post_meta($post->ID, '_eventosapp_apple_hex_fg',    true) ?: '';
    $apple_hex_label = get_post_meta($post->ID, '_eventosapp_apple_hex_label', true) ?: '';

    $readonly = ($wallet_custom_enable === '1' && $wallet_class_id) ? 'readonly' : '';
    $note = ($readonly
        ? '<span class="muted">Este ID fue generado automáticamente y no es editable.</span>'
        : '<span class="muted">Si no incluye “.”, se antepone el Issuer ID.</span>'
    );

    wp_nonce_field('eventosapp_detalles_evento', 'eventosapp_nonce');
    ?>
    <style>
    .eventosapp_fecha_row { margin-bottom: 10px; }
    .eventosapp-input-wide { width: 99%; }
    .eventosapp-wallet-toggle .desc { font-size:12px; color:#666; display:block; margin-top:2px; }
    .eventosapp-wallet-grid { border:1px solid #e5e5e5; padding:10px; margin-top:6px; background:#fafafa; }
    .muted { color:#666; font-size:12px; }
    </style>

    <!-- Tipo de fecha -->
    <div class="eventosapp_fecha_row">
        <label><strong>Tipo de fecha del evento:</strong></label><br>
        <select id="eventosapp_tipo_fecha" name="eventosapp_tipo_fecha">
            <option value="unica"        <?php selected($tipo_fecha, 'unica'); ?>>Una sola fecha</option>
            <option value="consecutiva"  <?php selected($tipo_fecha, 'consecutiva'); ?>>Varios días consecutivos</option>
            <option value="noconsecutiva"<?php selected($tipo_fecha, 'noconsecutiva'); ?>>Varios días NO consecutivos</option>
        </select>
    </div>

    <div id="eventosapp_fechas_wrap">
        <div id="fecha_unica_wrap" style="display:<?php echo ($tipo_fecha === 'unica' ? 'block' : 'none'); ?>;">
            <label>Fecha del evento:</label><br>
            <input type="date" name="eventosapp_fecha_unica" value="<?php echo esc_attr($fecha_unica); ?>">
        </div>

        <div id="fecha_consecutiva_wrap" style="display:<?php echo ($tipo_fecha === 'consecutiva' ? 'block' : 'none'); ?>;">
            <label>Fecha de inicio:</label><br>
            <input type="date" name="eventosapp_fecha_inicio" value="<?php echo esc_attr($fecha_inicio); ?>"><br>
            <label>Fecha de cierre:</label><br>
            <input type="date" name="eventosapp_fecha_fin" value="<?php echo esc_attr($fecha_fin); ?>">
        </div>

        <div id="fecha_noco_wrap" style="display:<?php echo ($tipo_fecha === 'noconsecutiva' ? 'block' : 'none'); ?>;">
            <label>Fechas específicas (puede agregar varias):</label><br>
            <div id="eventosapp_fechas_noco_list">
                <?php
                if ($fechas_noco && is_array($fechas_noco)) {
                    foreach ($fechas_noco as $fecha) {
                        echo '<div><input type="date" name="eventosapp_fechas_noco[]" value="'.esc_attr($fecha).'" style="margin-bottom:2px;"> <button type="button" class="remove_fecha_noco button">-</button></div>';
                    }
                }
                ?>
            </div>
            <button type="button" id="add_fecha_noco" class="button">Agregar Fecha</button>
        </div>
    </div>

    <br>
    <label><strong>Hora de Inicio:</strong></label><br>
    <input type="time" name="eventosapp_hora_inicio" value="<?php echo esc_attr($hora_inicio); ?>"><br><br>

    <label><strong>Hora de Cierre:</strong></label><br>
    <input type="time" name="eventosapp_hora_cierre" value="<?php echo esc_attr($hora_cierre); ?>"><br><br>

    <label><strong>Zona Horaria:</strong></label><br>
    <select name="eventosapp_zona_horaria">
        <?php
        $zonas = timezone_identifiers_list();
        foreach ($zonas as $zona) {
            echo '<option value="' . esc_attr($zona) . '" '.selected($zona_horaria, $zona, false). '>' . esc_html($zona) . '</option>';
        }
        ?>
    </select>
    <br><br>

    <!-- CAMPOS DEL EVENTO -->
    <label><strong>Dirección del Evento:</strong></label><br>
    <input type="text" class="eventosapp-input-wide" name="eventosapp_direccion" value="<?php echo esc_attr($direccion); ?>" placeholder="Ej: Calle 123 #45-67, Ciudad"><br><br>

    <label><strong>Coordenadas Google Maps (lat,lng):</strong></label><br>
    <input type="text" class="eventosapp-input-wide" name="eventosapp_coordenadas" value="<?php echo esc_attr($coordenadas); ?>" placeholder="Ej: 11.0041,-74.8067"><br>
    <span class="muted">Puedes obtenerlas desde <a href="https://www.google.com/maps" target="_blank" rel="noopener">Google Maps</a></span><br><br>

    <label><strong>Nombre del Organizador:</strong></label><br>
    <input type="text" class="eventosapp-input-wide" name="eventosapp_organizador" value="<?php echo esc_attr($organizador); ?>"><br><br>

    <label><strong>Correo Electrónico del Organizador:</strong></label><br>
    <input type="email" class="eventosapp-input-wide" name="eventosapp_organizador_email" value="<?php echo esc_attr($organizador_email); ?>"><br><br>

    <label><strong>Número de Contacto del Organizador:</strong></label><br>
    <input type="text" class="eventosapp-input-wide" name="eventosapp_organizador_tel" value="<?php echo esc_attr($organizador_tel); ?>"><br><br>

    <!-- === PERSONALIZACIÓN GOOGLE/APPLE POR EVENTO === -->
    <div class="eventosapp-wallet-toggle">
        <label>
            <input type="checkbox" id="eventosapp_wallet_custom_enable" name="eventosapp_wallet_custom_enable" value="1" <?php checked($wallet_custom_enable, '1'); ?>>
            <strong>Personalizar Wallet para este evento</strong>
        </label>
        <span class="desc">Si está desmarcado, se usarán los valores por defecto de <em>Integraciones</em>.</span>
    </div>

    <div id="eventosapp_wallet_custom_fields" class="eventosapp-wallet-grid" style="display:<?php echo ($wallet_custom_enable === '1' ? 'block' : 'none'); ?>;">
        <h3 style="margin:6px 0 10px">Google Wallet</h3>
        <table class="form-table" style="margin:0;">
            <tr>
                <th style="width:180px;"><label for="eventosapp_wallet_class_id">Class ID:</label></th>
                <td>
                    <input type="text" class="regular-text" id="eventosapp_wallet_class_id" name="eventosapp_wallet_class_id"
                           value="<?php echo esc_attr($wallet_class_id); ?>" <?php echo $readonly; ?>
                           placeholder="ej: issuerId.event_123">
                    <?php echo $note; ?>
                </td>
            </tr>
            <tr>
                <th><label for="eventosapp_wallet_logo_url">URL Logo:</label></th>
                <td><input type="url" class="regular-text" id="eventosapp_wallet_logo_url" name="eventosapp_wallet_logo_url" value="<?php echo esc_attr($wallet_logo_url); ?>" placeholder="https://.../logo.png"></td>
            </tr>
            <tr>
                <th><label for="eventosapp_wallet_hex_color">Color HEX (clase):</label></th>
                <td>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <input type="text" class="regular-text eventosapp-color-field" id="eventosapp_wallet_hex_color" name="eventosapp_wallet_hex_color" value="<?php echo esc_attr($wallet_hex_color ?: '#3782C4'); ?>" data-default-color="#3782C4" />
                        <span id="eventosapp_wallet_hex_color_preview" style="width:34px;height:22px;border-radius:4px;border:1px solid #ccd0d4;display:inline-block;background:<?php echo esc_attr($wallet_hex_color ?: '#3782C4'); ?>"></span>
                    </div>
                    <span class="muted">Color de la clase (ej. #3782C4). Por defecto #3782C4.</span>
                </td>
            </tr>
            <tr>
                <th><label for="eventosapp_wallet_hero_img_url">URL Imagen Hero:</label></th>
                <td><input type="url" class="regular-text" id="eventosapp_wallet_hero_img_url" name="eventosapp_wallet_hero_img_url" value="<?php echo esc_attr($wallet_hero_url); ?>" placeholder="https://.../hero.jpg"></td>
            </tr>
        </table>

        <hr>
        <h3 style="margin:10px 0 10px">Apple Wallet (opcional / overrides por evento)</h3>
        <table class="form-table" style="margin:0;">
            <tr>
                <th><label for="eventosapp_apple_icon_url">Apple Icon URL (png)</label></th>
                <td><input type="url" class="regular-text" id="eventosapp_apple_icon_url" name="eventosapp_apple_icon_url" value="<?php echo esc_attr($apple_icon_url); ?>" placeholder="https://.../icon.png"></td>
            </tr>
            <tr>
                <th><label for="eventosapp_apple_logo_url">Apple Logo URL (png)</label></th>
                <td><input type="url" class="regular-text" id="eventosapp_apple_logo_url" name="eventosapp_apple_logo_url" value="<?php echo esc_attr($apple_logo_url); ?>" placeholder="https://.../logo.png"></td>
            </tr>
            <tr>
                <th><label for="eventosapp_apple_strip_url">Apple Strip URL (png)</label></th>
                <td><input type="url" class="regular-text" id="eventosapp_apple_strip_url" name="eventosapp_apple_strip_url" value="<?php echo esc_attr($apple_strip_url); ?>" placeholder="https://.../strip.png"></td>
            </tr>
            <tr>
                <th><label for="eventosapp_apple_hex_bg">Apple BG HEX</label></th>
                <td><input type="text" class="regular-text" id="eventosapp_apple_hex_bg" name="eventosapp_apple_hex_bg" value="<?php echo esc_attr($apple_hex_bg); ?>" placeholder="#3782C4"></td>
            </tr>
            <tr>
                <th><label for="eventosapp_apple_hex_fg">Apple FG HEX</label></th>
                <td><input type="text" class="regular-text" id="eventosapp_apple_hex_fg" name="eventosapp_apple_hex_fg" value="<?php echo esc_attr($apple_hex_fg); ?>" placeholder="#FFFFFF"></td>
            </tr>
            <tr>
                <th><label for="eventosapp_apple_hex_label">Apple Label HEX</label></th>
                <td><input type="text" class="regular-text" id="eventosapp_apple_hex_label" name="eventosapp_apple_hex_label" value="<?php echo esc_attr($apple_hex_label); ?>" placeholder="#FFFFFF"></td>
            </tr>
        </table>
    </div>

    <script>
    (function($){
      $('#eventosapp_tipo_fecha').on('change', function() {
          var tipo = $(this).val();
          $('#fecha_unica_wrap, #fecha_consecutiva_wrap, #fecha_noco_wrap').hide();
          if(tipo == 'unica')        $('#fecha_unica_wrap').show();
          if(tipo == 'consecutiva')  $('#fecha_consecutiva_wrap').show();
          if(tipo == 'noconsecutiva')$('#fecha_noco_wrap').show();
      });
      $('#add_fecha_noco').on('click', function(){
          $('#eventosapp_fechas_noco_list').append('<div><input type="date" name="eventosapp_fechas_noco[]" style="margin-bottom:2px;"> <button type="button" class="remove_fecha_noco button">-</button></div>');
      });
      $(document).on('click', '.remove_fecha_noco', function(){
          $(this).parent().remove();
      });
      $('#eventosapp_wallet_custom_enable').on('change', function(){
          if ($(this).is(':checked')) {
              $('#eventosapp_wallet_custom_fields').slideDown(120);
          } else {
              $('#eventosapp_wallet_custom_fields').slideUp(120);
          }
      });
    })(jQuery);

    // Color Picker + preview
    jQuery(function($){
      var $inputColor = $('#eventosapp_wallet_hex_color');
      var $preview    = $('#eventosapp_wallet_hex_color_preview');

      if ($inputColor.length && $inputColor.wpColorPicker) {
        $inputColor.wpColorPicker({
          change: function(event, ui){
            if (ui && ui.color) $preview.css('background-color', ui.color.toString());
          },
          clear: function(){
            var def = $inputColor.data('default-color') || '#3782C4';
            $preview.css('background-color', def);
          }
        });
        $inputColor.on('input', function(){
          var val = $(this).val();
          if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(val)) $preview.css('background-color', val);
        });
      }
    });
    </script>
    <?php
}


/**
 * Metabox: Localidades
 */
function eventosapp_render_metabox_localidades($post) {
    $localidades = get_post_meta($post->ID, '_eventosapp_localidades', true);
    if (!is_array($localidades) || empty($localidades)) {
        $localidades = ['General', 'VIP', 'Platino'];
    }
    wp_nonce_field('eventosapp_localidades_guardar', 'eventosapp_localidades_nonce');
    ?>
    <p>
        <strong>Define las localidades disponibles para este evento:</strong><br>
        <span style="font-size:12px;color:#666;">Puedes agregar, quitar o editar localidades (ej: General, VIP, Platino, etc).</span>
    </p>
    <div id="eventosapp_localidades_list">
        <?php foreach ($localidades as $i => $loc): ?>
            <div style="margin-bottom:4px;">
                <input type="text" name="eventosapp_localidades[]" value="<?php echo esc_attr($loc); ?>" placeholder="Nombre localidad" style="width:220px;">
                <button type="button" class="remove_localidad button">Eliminar</button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" id="add_localidad" class="button">Agregar Localidad</button>
    <script>
    (function($){
        $('#add_localidad').on('click', function(){
            $('#eventosapp_localidades_list').append(
                '<div style="margin-bottom:4px;">' +
                '<input type="text" name="eventosapp_localidades[]" value="" placeholder="Nombre localidad" style="width:220px;">' +
                '<button type="button" class="remove_localidad button">Eliminar</button>' +
                '</div>'
            );
        });
        $(document).on('click', '.remove_localidad', function(){
            $(this).parent().remove();
        });
    })(jQuery);
    </script>
    <?php
}


/**
 * 3. Guardar los valores de la metabox (incluye Wallet por evento)
 */
add_action('save_post_eventosapp_event', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['eventosapp_nonce']) || !wp_verify_nonce($_POST['eventosapp_nonce'], 'eventosapp_detalles_evento')) return;

    $tipo = isset($_POST['eventosapp_tipo_fecha']) ? sanitize_text_field($_POST['eventosapp_tipo_fecha']) : 'unica';
    update_post_meta($post_id, '_eventosapp_tipo_fecha', $tipo);

    if ($tipo === 'unica') {
        update_post_meta($post_id, '_eventosapp_fecha_unica', sanitize_text_field($_POST['eventosapp_fecha_unica'] ?? ''));
        update_post_meta($post_id, '_eventosapp_fecha_inicio', '');
        update_post_meta($post_id, '_eventosapp_fecha_fin', '');
        update_post_meta($post_id, '_eventosapp_fechas_noco', []);
    } elseif ($tipo === 'consecutiva') {
        update_post_meta($post_id, '_eventosapp_fecha_inicio', sanitize_text_field($_POST['eventosapp_fecha_inicio'] ?? ''));
        update_post_meta($post_id, '_eventosapp_fecha_fin', sanitize_text_field($_POST['eventosapp_fecha_fin'] ?? ''));
        update_post_meta($post_id, '_eventosapp_fecha_unica', '');
        update_post_meta($post_id, '_eventosapp_fechas_noco', []);
    } elseif ($tipo === 'noconsecutiva') {
        $fechas_noco = [];
        if (!empty($_POST['eventosapp_fechas_noco']) && is_array($_POST['eventosapp_fechas_noco'])) {
            foreach ($_POST['eventosapp_fechas_noco'] as $f) {
                $f = trim($f);
                if ($f !== '') $fechas_noco[] = sanitize_text_field($f);
            }
        }
        update_post_meta($post_id, '_eventosapp_fechas_noco', $fechas_noco);
        update_post_meta($post_id, '_eventosapp_fecha_unica', '');
        update_post_meta($post_id, '_eventosapp_fecha_inicio', '');
        update_post_meta($post_id, '_eventosapp_fecha_fin', '');
    }

    update_post_meta($post_id, '_eventosapp_hora_inicio', sanitize_text_field($_POST['eventosapp_hora_inicio'] ?? ''));
    update_post_meta($post_id, '_eventosapp_hora_cierre', sanitize_text_field($_POST['eventosapp_hora_cierre'] ?? ''));
    update_post_meta($post_id, '_eventosapp_zona_horaria', sanitize_text_field($_POST['eventosapp_zona_horaria'] ?? ''));

    update_post_meta($post_id, '_eventosapp_direccion', sanitize_text_field($_POST['eventosapp_direccion'] ?? ''));
    update_post_meta($post_id, '_eventosapp_coordenadas', sanitize_text_field($_POST['eventosapp_coordenadas'] ?? ''));
    update_post_meta($post_id, '_eventosapp_organizador', sanitize_text_field($_POST['eventosapp_organizador'] ?? ''));
    update_post_meta($post_id, '_eventosapp_organizador_email', sanitize_email($_POST['eventosapp_organizador_email'] ?? ''));
    update_post_meta($post_id, '_eventosapp_organizador_tel', sanitize_text_field($_POST['eventosapp_organizador_tel'] ?? ''));

    if (isset($_POST['eventosapp_localidades_nonce']) && wp_verify_nonce($_POST['eventosapp_localidades_nonce'], 'eventosapp_localidades_guardar')) {
        $localidades = [];
        if (!empty($_POST['eventosapp_localidades']) && is_array($_POST['eventosapp_localidades'])) {
            foreach ($_POST['eventosapp_localidades'] as $l) {
                $l = trim($l);
                if ($l !== '') $localidades[] = sanitize_text_field($l);
            }
        }
        update_post_meta($post_id, '_eventosapp_localidades', $localidades);
    }

    // === WALLET por evento ===
    $wc_enable = isset($_POST['eventosapp_wallet_custom_enable']) ? '1' : '0';
    update_post_meta($post_id, '_eventosapp_wallet_custom_enable', $wc_enable);

    if ($wc_enable === '1') {
        $existing_class = get_post_meta($post_id, '_eventosapp_wallet_class_id', true);
        if (!$existing_class) {
            $class_id = sanitize_text_field($_POST['eventosapp_wallet_class_id'] ?? '');
            update_post_meta($post_id, '_eventosapp_wallet_class_id', $class_id);
        }
        update_post_meta($post_id, '_eventosapp_wallet_logo_url', esc_url_raw($_POST['eventosapp_wallet_logo_url'] ?? ''));
        update_post_meta($post_id, '_eventosapp_wallet_hero_img_url', esc_url_raw($_POST['eventosapp_wallet_hero_img_url'] ?? ''));
        $hex = isset($_POST['eventosapp_wallet_hex_color']) ? wp_unslash($_POST['eventosapp_wallet_hex_color']) : '';
        $hex = sanitize_hex_color( $hex );
        if ( ! $hex ) { $hex = '#3782C4'; }
        update_post_meta($post_id, '_eventosapp_wallet_hex_color', $hex);

        // Apple overrides (opcionales)
        update_post_meta($post_id, '_eventosapp_apple_icon_url',  esc_url_raw($_POST['eventosapp_apple_icon_url']  ?? ''));
        update_post_meta($post_id, '_eventosapp_apple_logo_url',  esc_url_raw($_POST['eventosapp_apple_logo_url']  ?? ''));
        update_post_meta($post_id, '_eventosapp_apple_strip_url', esc_url_raw($_POST['eventosapp_apple_strip_url'] ?? ''));
        foreach (['bg'=>'_eventosapp_apple_hex_bg', 'fg'=>'_eventosapp_apple_hex_fg', 'label'=>'_eventosapp_apple_hex_label'] as $k=>$meta){
            $v = $_POST['eventosapp_apple_hex_'.$k] ?? '';
            if ($v && preg_match('/^#?[0-9A-Fa-f]{6}$/', $v)) { if ($v[0] !== '#') $v = '#'.$v; }
            else $v = '';
            update_post_meta($post_id, $meta, $v);
        }

    } else {
        // Limpiar cuando se desactiva
        delete_post_meta($post_id, '_eventosapp_wallet_class_id');
        delete_post_meta($post_id, '_eventosapp_wallet_logo_url');
        delete_post_meta($post_id, '_eventosapp_wallet_hero_img_url');
        delete_post_meta($post_id, '_eventosapp_wallet_hex_color');

        delete_post_meta($post_id, '_eventosapp_apple_icon_url');
        delete_post_meta($post_id, '_eventosapp_apple_logo_url');
        delete_post_meta($post_id, '_eventosapp_apple_strip_url');
        delete_post_meta($post_id, '_eventosapp_apple_hex_bg');
        delete_post_meta($post_id, '_eventosapp_apple_hex_fg');
        delete_post_meta($post_id, '_eventosapp_apple_hex_label');
    }
}, 20);

// ===== Asegurar roles base de EventosApp =====
add_action('init', function(){
    eventosapp_ensure_core_roles();
}, 5);

function eventosapp_ensure_core_roles(){
    $roles = [
        'staff'       => ['name' => 'Staff',       'caps' => ['read'=>true]],
        'logistico'   => ['name' => 'Logístico',   'caps' => ['read'=>true]],
        'organizador' => ['name' => 'Organizador', 'caps' => ['read'=>true]],
        'coordinador' => ['name' => 'Coordinador', 'caps' => ['read'=>true]],
    ];
    foreach ($roles as $slug => $data){
        if ( ! get_role($slug) ){
            add_role($slug, $data['name'], $data['caps']);
        }
    }
}

