<?php
/*
 * CPT: eventosapp_ticket
 * Registro y gestión de tickets asociados a eventosapp_event.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$upload = wp_upload_dir();
$qr_cache_dir = trailingslashit($upload['basedir']) . 'eventosapp-qr-cache/';
$qr_log_dir   = trailingslashit($upload['basedir']) . 'eventosapp-qr-logs/';

if (!file_exists($qr_cache_dir)) wp_mkdir_p($qr_cache_dir);
if (!file_exists($qr_log_dir))   wp_mkdir_p($qr_log_dir);

if (!defined('QR_CACHEABLE')) define('QR_CACHEABLE', true);
if (!defined('QR_CACHE_DIR')) define('QR_CACHE_DIR', $qr_cache_dir);
if (!defined('QR_LOG_DIR'))   define('QR_LOG_DIR', $qr_log_dir);
// Incluimos la librería QR solo aquí (no rompe en el frontend)
require_once plugin_dir_path(__FILE__) . 'includes/qrlib/qrlib.php';

require_once plugin_dir_path(__FILE__) . 'includes/functions/google-wallet-android.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions/eventosapp-ticket-helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions/eventosapp-ticket-ics.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions/eventosapp-ticket-email.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions/apple-wallet-ios.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions/apple-wallet-webservice.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions/apple-wallet-hooks.php';


/**
 * 1. Registrar el Custom Post Type "eventosapp_ticket"
 */
add_action('init', function() {
    register_post_type('eventosapp_ticket', [
        'labels' => [
            'name'               => 'Tickets',
            'singular_name'      => 'Ticket',
            'add_new'            => 'Agregar Nuevo',
            'add_new_item'       => 'Agregar Nuevo Ticket',
            'edit_item'          => 'Editar Ticket',
            'new_item'           => 'Nuevo Ticket',
            'view_item'          => 'Ver Ticket',
            'search_items'       => 'Buscar Tickets',
            'not_found'          => 'No se encontraron tickets',
            'not_found_in_trash' => 'No se encontraron tickets en la papelera',
        ],
        'public'              => false,
        'show_ui'             => true,
        'menu_icon'           => 'dashicons-ticket',
        'supports'            => ['title'],
        'has_archive'         => false,
        'rewrite'             => false,
        'show_in_rest'        => false,
        'show_in_menu'        => false,
    ]);
});

/**
 * 1.1. Ocultar el campo "title" en el admin y hacerlo no editable.
 */
add_action('admin_head', function() {
    $screen = get_current_screen();
    if ($screen && $screen->post_type === 'eventosapp_ticket') {
        echo '<style>
        #titlediv, .inline-edit-col .title { display:none !important; }
        </style>';
    }
});
add_filter('quick_edit_show_taxonomy', function($show, $taxonomy, $post_type) {
    if ($post_type === 'eventosapp_ticket') return false;
    return $show;
}, 10, 3);

/**
 * 2. Metabox principal para datos del ticket
 */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_ticket_main',
        'Gestión de Ticket',
        'eventosapp_ticket_metabox_render',
        'eventosapp_ticket',
        'normal',
        'high'
    );

	/**
 * 2.1. Metabox: Estado (Checked In / Not Checked In) + Log
 */

	    add_meta_box(
        'eventosapp_ticket_status',
        'Estado de Check-In',
        'eventosapp_ticket_status_metabox',
        'eventosapp_ticket',
        'side',
        'high'
    );
	
	    add_meta_box(
        'eventosapp_ticket_sesiones',
        'Acceso a Sesiones/Salones Internos',
        'eventosapp_ticket_sesiones_metabox',
        'eventosapp_ticket',
        'side',
        'default'
    );
	
    add_meta_box(
        'eventosapp_ticket_files',
        'Archivos y Enlaces del Ticket',
        'eventosapp_ticket_files_metabox',
        'eventosapp_ticket',
        'side',
        'low'
    );

	    add_meta_box(
        'eventosapp_ticket_email',
        'Enviar Ticket por Correo',
        'eventosapp_ticket_email_metabox_render',
        'eventosapp_ticket',
        'side',
        'high'
    );
	
	// === Metabox: Networking – Enviar resumen (manual/QA) ===
   add_meta_box(
        'eventosapp_ticket_net_digest',
        'Networking – Enviar resumen',
        'eventosapp_ticket_networking_digest_metabox_render',
        'eventosapp_ticket',
        'side',
        'high'
    );
	
	
});


// Render
function eventosapp_ticket_networking_digest_metabox_render($post){
    $nonce_url = wp_nonce_url(
        add_query_arg([
            'action'  => 'eventosapp_net2_resend_digest',
            'post_id' => $post->ID,
        ], admin_url('admin-post.php')),
        'eventosapp_net2_resend_digest'
    );

    $already = get_post_meta($post->ID, '_eventosapp_net_digest_sent', true);
    ?>
    <div style="font-size:13px;line-height:1.5">
        <p>Envía el correo de <b>resumen de networking</b> para este ticket, tal como se enviaría 24 h después del evento.</p>
        <p><a class="button button-primary" href="<?php echo esc_url($nonce_url); ?>">Reenviar resumen</a></p>
        <?php if ($already): ?>
            <p style="margin-top:8px;color:#475569">
                Este ticket ya tenía marcado <code>_eventosapp_net_digest_sent=1</code>.
                <br><b>Nota:</b> El reenvío manual <u>no</u> modifica esa marca ni reemplaza el envío automático.
            </p>
        <?php else: ?>
            <p style="margin-top:8px;color:#475569">
                Aún no está marcado como enviado automáticamente. Este reenvío manual <u>no</u> afectará el envío programado.
            </p>
        <?php endif; ?>
    </div>
    <?php
}



/**
 * Metabox: Enviar Ticket por Correo
 * Ahora muestra el historial de envíos: status, primera fecha, último envío y los 3 últimos
 */
function eventosapp_ticket_email_metabox_render($post) {
    $asistente_email = get_post_meta($post->ID, '_eventosapp_asistente_email', true);
    $is_admin = current_user_can('manage_options');

    // URL base con nonce (usaremos GET para evitar forms anidados)
    $base_url = wp_nonce_url(
        add_query_arg([
            'action'  => 'eventosapp_send_ticket_email',
            'post_id' => $post->ID,
        ], admin_url('admin-post.php')),
        'eventosapp_send_ticket_email' // action del nonce
    );
    
    // === NUEVO: Obtener información de historial de envíos ===
    $email_status = get_post_meta($post->ID, '_eventosapp_ticket_email_sent_status', true);
    $first_sent = get_post_meta($post->ID, '_eventosapp_ticket_email_first_sent', true);
    $last_email_at = get_post_meta($post->ID, '_eventosapp_ticket_last_email_at', true);
    $last_email_to = get_post_meta($post->ID, '_eventosapp_ticket_last_email_to', true);
    $email_history = get_post_meta($post->ID, '_eventosapp_ticket_email_history', true);
    
    if (!is_array($email_history)) {
        $email_history = [];
    }
    
    $status_enviado = ($email_status === 'enviado');
    
    ?>
    <div style="font-size:13px;line-height:1.4">
        
        <?php if ($status_enviado): ?>
            <!-- Estado: Enviado -->
            <div style="background:#d1fae5;border:1px solid #10b981;padding:10px;border-radius:4px;margin-bottom:12px;">
                <strong style="color:#047857;display:block;margin-bottom:4px;">
                    ✓ Estado: Correo Enviado
                </strong>
                
                <?php if ($first_sent): ?>
                    <span style="color:#065f46;font-size:12px;">
                        <strong>Primer envío:</strong> <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($first_sent))); ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Estado: No Enviado -->
            <div style="background:#fee2e2;border:1px solid #ef4444;padding:10px;border-radius:4px;margin-bottom:12px;">
                <strong style="color:#dc2626;">
                    ✗ Estado: Correo No Enviado
                </strong>
            </div>
        <?php endif; ?>
        
        <!-- Historial de los 3 últimos envíos -->
        <?php if (!empty($email_history) && count($email_history) > 0): ?>
            <div style="background:#f1f5f9;border:1px solid #cbd5e1;padding:10px;border-radius:4px;margin-bottom:12px;">
                <strong style="display:block;margin-bottom:6px;color:#334155;">Últimos envíos:</strong>
                <div style="font-size:12px;color:#475569;">
                    <?php foreach ($email_history as $idx => $envio): ?>
                        <div style="padding:4px 0;<?php echo $idx > 0 ? 'border-top:1px solid #e2e8f0;' : ''; ?>">
                            <strong><?php echo ($idx + 1); ?>.</strong>
                            <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($envio['fecha']))); ?>
                            <br>
                            <span style="padding-left:12px;color:#64748b;">
                                → <?php echo esc_html($envio['destinatario']); ?>
                                <?php if (!empty($envio['source'])): ?>
                                    <em style="color:#94a3b8;">(<?php echo esc_html($envio['source']); ?>)</em>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <hr style="margin:12px 0;border:0;border-top:1px solid #e5e7eb;">
        
        <p><b>Email del asistente:</b><br>
            <input type="email" class="widefat" value="<?php echo esc_attr($asistente_email); ?>" disabled>
            <small>Este es el correo guardado en el ticket.</small>
        </p>

        <?php if ($is_admin): ?>
            <p><b>Correo alterno (solo admin):</b><br>
                <input type="email" class="widefat" id="eventosapp_email_alt" placeholder="ej: pruebas@tu-dominio.com">
                <small>Si lo llenas, enviaremos aquí en vez del del asistente.</small>
            </p>
        <?php endif; ?>

        <p>
            <a href="<?php echo esc_url($base_url); ?>" class="button button-primary" id="eventosapp_send_ticket_btn">
                <?php echo $status_enviado ? 'Reenviar ticket por correo' : 'Enviar ticket por correo'; ?>
            </a>
        </p>
        <small>El correo incluirá QR, PDF, archivo ICS y botones para Wallet.</small>
    </div>

    <script>
    (function($){
        $('#eventosapp_send_ticket_btn').on('click', function(e){
            <?php if ($is_admin): ?>
            // Si es admin y escribió correo alterno, lo mandamos por GET
            var alt = $('#eventosapp_email_alt').val();
            if (alt && alt.length > 3) {
                e.preventDefault();
                var url = new URL(this.href);
                url.searchParams.set('eventosapp_email_alt_from_metabox','1');
                url.searchParams.set('eventosapp_email_alt', alt);
                window.location = url.toString();
            }
            <?php endif; ?>
        });
    })(jQuery);
    </script>
    <?php
}


// REEMPLAZA COMPLETO
function eventosapp_ticket_status_metabox($post) {
    $evento_id = (int) get_post_meta($post->ID, '_eventosapp_ticket_evento_id', true);
    $days = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days($evento_id) : [];
    if (!$days) {
        echo '<span style="color:red">No hay días definidos para el evento.</span>';
        return;
    }

    // Estado por día
    $status_arr = get_post_meta($post->ID, '_eventosapp_checkin_status', true);
    if (is_string($status_arr)) $status_arr = @unserialize($status_arr);
    if (!is_array($status_arr)) $status_arr = [];

    // Log
    $log = get_post_meta($post->ID, '_eventosapp_checkin_log', true);
    if (is_string($log)) $log = @unserialize($log);
    if (!is_array($log)) $log = [];

    echo '<b>Check-in por día (editable):</b><br>';
    foreach ($days as $day) {
        $status = $status_arr[$day] ?? 'not_checked_in';
        $field_name = "eventosapp_checkin_status[".$day."]";
        echo "<div style='margin-bottom:8px'>";
        echo "<b>" . esc_html(date_i18n("D, d M Y", strtotime($day))) . "</b><br>";
        echo "<select name='{$field_name}'>
                <option value='not_checked_in' ".selected($status,'not_checked_in',false).">Not Checked In</option>
                <option value='checked_in' ".selected($status,'checked_in',false).">Checked In</option>
              </select>";
        echo "</div>";
    }
    echo '<small style="color:#666;display:block;margin:6px 0 10px">Puedes cambiar el estado manualmente para corregir lecturas accidentales. Se registrará en el log.</small>';

    // LOG
    echo '<hr><b>Log de Check-ins:</b>';
    echo "<div style='font-size:12px; max-height:140px; overflow-y:auto; background:#fafbfc; border:1px solid #e5e5e5; padding:6px; border-radius:7px;'>";
    if (!empty($log)) {
        foreach(array_reverse($log) as $row) {
            echo '<div style="margin-bottom:6px">';
            echo '<span style="color:#225;">' . esc_html($row['fecha'] ?? '') . ' ' . esc_html($row['hora'] ?? '') . "</span><br>";
            echo '<b>Dia:</b> ' . esc_html($row['dia'] ?? '-') . ' ';
            echo '<b>Status:</b> ' . esc_html($row['status'] ?? '-') . '<br>';
            if (!empty($row['previo'])) {
                echo '<b>Previo:</b> ' . esc_html($row['previo']) . '<br>';
            }
            if (!empty($row['origen'])) {
                echo '<b>Origen:</b> ' . esc_html($row['origen']) . '<br>';
            }
            echo '<b>Por:</b> ' . esc_html($row['usuario'] ?? 'Sistema');
            echo '<hr style="margin:6px 0">';
            echo '</div>';
        }
    } else {
        echo '<span style="color:#888;">Sin registros aún.</span>';
    }
    echo '</div>';
}


function eventosapp_ticket_files_metabox($post) {
    $ticketID = get_post_meta($post->ID, 'eventosapp_ticketID', true);

    // URL QR
    $qr_url = $ticketID ? eventosapp_get_ticket_qr_url($ticketID) : '';
    // URL PDF
    $pdf_url = get_post_meta($post->ID, '_eventosapp_ticket_pdf_url', true);
    // ICS
    $ics_url = get_post_meta($post->ID, '_eventosapp_ticket_ics_url', true);
	// Wallet Android (Google)
	$wallet_android_url = get_post_meta($post->ID, '_eventosapp_ticket_wallet_android_url', true);
    // Wallet Apple
    $wallet_apple_url = get_post_meta($post->ID, '_eventosapp_ticket_wallet_apple', true);

    ?>
    <style>
        .eventosapp-file-links label { display:block; font-weight:600; margin-bottom:2px; margin-top:12px;}
        .eventosapp-file-links input[type="text"] { width:100%; font-size:13px; }
        .eventosapp-file-links a { font-size:13px; }
    </style>
    <div class="eventosapp-file-links">
        <label>QR:</label>
        <?php if ($qr_url): ?>
            <a href="<?php echo esc_url($qr_url); ?>" target="_blank"><?php echo esc_html($qr_url); ?></a>
        <?php else: ?>
            <span style="color:#888;">No generado aún.</span>
        <?php endif; ?>

        <label>PDF:</label>
        <?php if ($pdf_url): ?>
            <a href="<?php echo esc_url($pdf_url); ?>" target="_blank"><?php echo esc_html($pdf_url); ?></a>
        <?php else: ?>
            <span style="color:#888;">No generado aún.</span>
        <?php endif; ?>

        <label>ICS (enlace o archivo):</label>
        <?php if ($ics_url): ?>
            <a href="<?php echo esc_url($ics_url); ?>" target="_blank"><?php echo esc_html($ics_url); ?></a>
        <?php else: ?>
            <span style="color:#888;">No generado aún.</span>
        <?php endif; ?>

		<label>Wallet Android:</label>
		<?php if ($wallet_android_url): ?>
			<a href="<?php echo esc_url($wallet_android_url); ?>" target="_blank"><?php echo esc_html($wallet_android_url); ?></a>
		<?php else: ?>
			<span style="color:#888;">No generado aún.</span>
		<?php endif; ?>

        <label>Wallet Apple:</label>
        <?php
        // Intentamos varias metaclaves posibles
        $wallet_apple_url = get_post_meta($post->ID, '_eventosapp_ticket_wallet_apple', true);
        if (!$wallet_apple_url) $wallet_apple_url = get_post_meta($post->ID, '_eventosapp_ticket_wallet_apple_url', true);
        if (!$wallet_apple_url) $wallet_apple_url = get_post_meta($post->ID, '_eventosapp_ticket_pkpass_url', true);

        if ($wallet_apple_url) {
            echo '<a href="'.esc_url($wallet_apple_url).'" target="_blank">'.esc_html($wallet_apple_url).'</a>';
        } else {
            echo '<span style="color:#888;">No generado aún.</span>';
        }
        ?>
    </div>
    <?php
}



function eventosapp_get_ticket_qr_url($ticketID) {
    $upload_dir = wp_upload_dir();
    $qr_dir = $upload_dir['basedir'] . '/eventosapp-qr/';
    $qr_url = $upload_dir['baseurl'] . '/eventosapp-qr/';
    if (!file_exists($qr_dir)) {
        wp_mkdir_p($qr_dir);
    }
    $qr_file = $qr_dir . $ticketID . '.png';
    $qr_src = $qr_url . $ticketID . '.png';

    if (!file_exists($qr_file)) {
        QRcode::png($ticketID, $qr_file, 'L', 6, 2);
    }
    if (file_exists($qr_file)) {
        return $qr_src . '?v=' . filemtime($qr_file);
    }
    return '';
}


function eventosapp_ticket_metabox_render($post) {
    // Ticket ID único y visible (solo si existe)
    $ticketID = get_post_meta($post->ID, 'eventosapp_ticketID', true);

    // Cargar eventos disponibles
    $eventos = get_posts([
        'post_type'   => 'eventosapp_event',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
    ]);

    // Valores guardados (para editar)
    $evento_id   = get_post_meta($post->ID, '_eventosapp_ticket_evento_id', true) ?: '';
    $preprinted  = get_post_meta($post->ID, 'eventosapp_ticket_preprintedID', true) ?: '';
    $user_id            = get_post_meta($post->ID, '_eventosapp_ticket_user_id', true) ?: '';
    $asistente_nombre   = get_post_meta($post->ID, '_eventosapp_asistente_nombre', true) ?: '';
    $asistente_apellido = get_post_meta($post->ID, '_eventosapp_asistente_apellido', true) ?: '';
    $asistente_cc       = get_post_meta($post->ID, '_eventosapp_asistente_cc', true) ?: '';
    $asistente_email    = get_post_meta($post->ID, '_eventosapp_asistente_email', true) ?: '';
    $asistente_tel      = get_post_meta($post->ID, '_eventosapp_asistente_tel', true) ?: '';
    $asistente_empresa  = get_post_meta($post->ID, '_eventosapp_asistente_empresa', true) ?: '';
	$asistente_nit      = get_post_meta($post->ID, '_eventosapp_asistente_nit', true) ?: '';
	$asistente_cargo    = get_post_meta($post->ID, '_eventosapp_asistente_cargo', true) ?: '';
	// NUEVO
	$asistente_ciudad   = get_post_meta($post->ID, '_eventosapp_asistente_ciudad', true) ?: '';
	$asistente_pais     = get_post_meta($post->ID, '_eventosapp_asistente_pais', true) ?: 'Colombia';

	$asistente_localidad= get_post_meta($post->ID, '_eventosapp_asistente_localidad', true) ?: '';

    // ¿el evento pide QR preimpreso?
    $use_preprinted = $evento_id ? (get_post_meta($evento_id, '_eventosapp_ticket_use_preprinted_qr', true) === '1') : false;
    wp_nonce_field('eventosapp_ticket_guardar', 'eventosapp_ticket_nonce');
    ?>
    <style>
        .eventosapp-ticket-metabox { display: flex; gap: 30px; }
        .eventosapp-ticket-form   { flex: 2; }
        .eventosapp-ticket-summary{ flex: 1; background: #fafbfc; border:1px solid #eee; padding:18px 12px; border-radius:9px; font-size:15px;}
        .eventosapp-ticket-form label { font-weight:600; margin-top:9px; display:block; }
        .eventosapp-ticket-section { margin-bottom: 20px; }
        .eventosapp-input-wide { width: 99%; }
        .eventosapp-ticketid-block {font-size:17px; margin-bottom:15px; color:#216db2;}
        .eventosapp-ticket-qr-block {margin-bottom:18px;}
    </style>
    <div class="eventosapp-ticket-metabox">
        <div class="eventosapp-ticket-form">
            <?php if ($ticketID): ?>
                <div class="eventosapp-ticketid-block">
                    <b>ID Único de Ticket:</b> <?php echo esc_html($ticketID); ?>
                </div>
                <div class="eventosapp-ticket-qr-block">
                    <b>QR del Ticket:</b><br>
                    <?php
                    $qr_src = eventosapp_get_ticket_qr_url($ticketID);
                    if ($qr_src) {
                        echo '<img src="' . esc_url($qr_src) . '" alt="QR Ticket" style="max-width:140px;">';
                    } else {
                        echo '<span style="color:#b33;">No se pudo generar el QR</span>';
                    }
                    ?>
                </div>
            <?php endif; ?>
            <!-- resto del formulario igual -->
            <!-- SECCIÓN EVENTO -->
            <div class="eventosapp-ticket-section">
                <h3>Detalles del Evento</h3>
                <label for="eventosapp_ticket_evento_id">Seleccionar Evento:</label>
                <select name="eventosapp_ticket_evento_id" id="eventosapp_ticket_evento_id" required>
                    <option value="">Selecciona un evento...</option>
                    <?php foreach ($eventos as $ev): ?>
                        <option value="<?php echo esc_attr($ev->ID); ?>" <?php selected($evento_id, $ev->ID); ?>>
                            <?php echo esc_html($ev->post_title) . " [{$ev->ID}]"; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- SECCIÓN GENERADOR DEL TICKET -->
            <div class="eventosapp-ticket-section">
                <h3>Generador del Ticket</h3>
                <label for="eventosapp_ticket_user_id">Escoger usuario (WordPress):</label>
                <select id="eventosapp_ticket_user_id" name="eventosapp_ticket_user_id" class="eventosapp-input-wide select2-ajax" required>
                    <?php if ($user_id): ?>
                        <?php $user = get_userdata($user_id); ?>
                        <option value="<?php echo esc_attr($user_id); ?>" selected>
                            <?php echo esc_html($user ? $user->display_name . " ({$user->user_email})" : "ID {$user_id}"); ?>
                        </option>
                    <?php else: ?>
                        <option value="">Buscar usuario...</option>
                    <?php endif; ?>
                </select>
                <div id="eventosapp_user_info">
                    <!-- Aquí se cargan datos del usuario por JS -->
                </div>
            </div>
			    <!-- Por ejemplo, al final de la sección “Generador del Ticket” -->
    <div class="eventosapp-ticket-section">
        <h3>QR Preimpreso (opcional)</h3>
        <label for="eventosapp_ticket_preprintedID">ID de QR preimpreso (numérico):</label>
        <input type="number" min="0" step="1" class="eventosapp-input-wide"
               id="eventosapp_ticket_preprintedID"
               name="eventosapp_ticket_preprintedID"
               value="<?php echo esc_attr($preprinted); ?>"
               placeholder="Ej: 8, 123, 4501">
        <?php if ($use_preprinted): ?>
            <small style="color:#d63638;display:block;margin-top:4px;">
                Este evento usa QR preimpreso: el lector buscará este ID en lugar del <code>eventosapp_ticketID</code>.
            </small>
        <?php endif; ?>
    </div>
			
            <!-- SECCIÓN DATOS DEL ASISTENTE ... (igual que antes) -->
            <div class="eventosapp-ticket-section">
                <h3>Datos del Asistente</h3>
                <label>Primer Nombre:</label>
                <input type="text" class="eventosapp-input-wide" name="eventosapp_asistente_nombre" value="<?php echo esc_attr($asistente_nombre); ?>" required>
                <label>Apellido:</label>
                <input type="text" class="eventosapp-input-wide" name="eventosapp_asistente_apellido" value="<?php echo esc_attr($asistente_apellido); ?>" required>
                <label>Cédula de Ciudadanía:</label>
                <input type="text" class="eventosapp-input-wide" name="eventosapp_asistente_cc" value="<?php echo esc_attr($asistente_cc); ?>">
                <label>Email:</label>
                <input type="email" class="eventosapp-input-wide" name="eventosapp_asistente_email" value="<?php echo esc_attr($asistente_email); ?>" required>
                <label>Número de Contacto:</label>
                <input type="text" class="eventosapp-input-wide" name="eventosapp_asistente_tel" value="<?php echo esc_attr($asistente_tel); ?>">
                <label>Nombre de Empresa:</label>
                <input type="text" class="eventosapp-input-wide" name="eventosapp_asistente_empresa" value="<?php echo esc_attr($asistente_empresa); ?>">
                <label>NIT:</label>
                <input type="text" class="eventosapp-input-wide" name="eventosapp_asistente_nit" value="<?php echo esc_attr($asistente_nit); ?>">
<label>Cargo:</label>
<input type="text" class="eventosapp-input-wide" name="eventosapp_asistente_cargo" value="<?php echo esc_attr($asistente_cargo); ?>">

<!-- NUEVO: Ciudad -->
<label>Ciudad:</label>
<input type="text" class="eventosapp-input-wide" name="eventosapp_asistente_ciudad" value="<?php echo esc_attr($asistente_ciudad); ?>">

<!-- NUEVO: País -->
<label>País:</label>
<select name="eventosapp_asistente_pais" class="eventosapp-input-wide" id="eventosapp_asistente_pais">
    <?php
    $countries = function_exists('eventosapp_get_countries') ? eventosapp_get_countries() : array('Colombia');
    $current_country = $asistente_pais ?: 'Colombia';
    foreach ($countries as $c) {
        echo '<option value="'.esc_attr($c).'" '.selected($current_country, $c, false).'>'.esc_html($c).'</option>';
    }
    ?>
</select>

<label>Localidad:</label>
<select name="eventosapp_asistente_localidad" class="eventosapp-input-wide" id="eventosapp_asistente_localidad">
    <option value="">Seleccione...</option>
    <?php
    $localidades = [];
    if ($evento_id) {
        $localidades = get_post_meta($evento_id, '_eventosapp_localidades', true);
        if (!is_array($localidades) || empty($localidades)) $localidades = ['General', 'VIP', 'Platino'];
    } else {
        $localidades = ['General', 'VIP', 'Platino'];
    }
    foreach ($localidades as $loc) {
        echo '<option value="' . esc_attr($loc) . '" ' . selected($asistente_localidad, $loc, false) . '>' . esc_html($loc) . '</option>';
    }
    ?>
</select>
				<?php
// === Campos adicionales definidos por el evento ===
if (function_exists('eventosapp_get_event_extra_fields') && $evento_id) {
    $extras = eventosapp_get_event_extra_fields($evento_id);
    if ($extras) {
        echo '<div class="eventosapp-ticket-section"><h3>Campos adicionales del evento</h3>';
        foreach ($extras as $f) {
            $key   = $f['key'];
            $label = $f['label'];
            $req   = !empty($f['required']);
            $val   = get_post_meta($post->ID, '_eventosapp_extra_'.$key, true);

            echo '<label>'.esc_html($label).($req?' <span style="color:#b33">*</span>':'').'</label>';

            $name = 'eventosapp_extra['.$key.']';
            if ($f['type']==='number') {
                echo '<input type="number" class="eventosapp-input-wide" name="'.$name.'" value="'.esc_attr($val).'">';
            } elseif ($f['type']==='select') {
                echo '<select class="eventosapp-input-wide" name="'.$name.'">';
                echo '<option value="">Seleccione…</option>';
                foreach ($f['options'] as $opt){
                    echo '<option value="'.esc_attr($opt).'" '.selected($val,$opt,false).'>'.esc_html($opt).'</option>';
                }
                echo '</select>';
            } else {
                echo '<input type="text" class="eventosapp-input-wide" name="'.$name.'" value="'.esc_attr($val).'">';
            }
        }
        echo '</div>';
    }


}
?>

				

            </div>
        </div>
        <!-- SECCIÓN RESUMEN DEL EVENTO -->
        <div class="eventosapp-ticket-summary" id="eventosapp_ticket_event_summary">
            <em>Selecciona un evento para ver el resumen aquí...</em>
        </div>
    </div>
    <script>
(function($){
    // Inicializa el resumen del evento y localidades
    $('#eventosapp_ticket_evento_id').on('change', function(){
        var evento_id = $(this).val();

        // Resumen del evento (ya lo tienes)
        $('#eventosapp_ticket_event_summary').html('<em>Cargando...</em>');
        if(evento_id){
            $.post(ajaxurl, {
                action: 'eventosapp_ticket_get_event_summary',
                evento_id: evento_id
            }, function(resp){
                $('#eventosapp_ticket_event_summary').html(resp.data ? resp.data : '<em>No hay datos</em>');
            });

            // NUEVO: cargar localidades dinámicas
            $.post(ajaxurl, {
                action: 'eventosapp_ticket_get_localidades',
                evento_id: evento_id
            }, function(resp){
                if (resp.success && resp.data) {
                    var $sel = $('#eventosapp_asistente_localidad');
                    var currentVal = $sel.val();
                    $sel.empty().append('<option value="">Seleccione...</option>');
                    $.each(resp.data, function(i, loc){
                        var selected = (loc === currentVal) ? 'selected' : '';
                        $sel.append('<option value="'+loc+'" '+selected+'>'+loc+'</option>');
                    });
                }
            });

        } else {
            $('#eventosapp_ticket_event_summary').html('<em>Selecciona un evento para ver el resumen aquí...</em>');
            // Restaurar localidades default si no hay evento
            var $sel = $('#eventosapp_asistente_localidad');
            $sel.empty()
                .append('<option value="">Seleccione...</option>')
                .append('<option value="General">General</option>')
                .append('<option value="VIP">VIP</option>')
                .append('<option value="Platino">Platino</option>');
        }
    }).trigger('change');

    // Inicializa Select2 solo cuando está cargado
    function initSelect2User() {
        if (typeof $.fn.select2 !== 'undefined') {
            $('#eventosapp_ticket_user_id').select2({
                ajax: {
                    url: ajaxurl,
                    dataType: 'json',
                    delay: 300,
                    data: function (params) {
                        return {
                            q: params.term,
                            action: 'eventosapp_ticket_user_search'
                        };
                    },
                    processResults: function (data) {
                        return { results: data.data };
                    },
                    cache: true
                },
                minimumInputLength: 2,
                width: '100%',
                placeholder: 'Buscar usuario...',
                allowClear: true
            }).on('select2:select', function(e){
                var user_id = $(this).val();
                // Autopopular datos del usuario
                $.post(ajaxurl, {
                    action: 'eventosapp_ticket_user_info',
                    user_id: user_id
                }, function(resp){
                    if(resp.success && resp.data){
                        $('#eventosapp_user_info').html(
                            '<div><b>Nombre:</b> ' + resp.data.first_name + '</div>' +
                            '<div><b>Apellido:</b> ' + resp.data.last_name + '</div>' +
                            '<div><b>Email:</b> ' + resp.data.email + '</div>' +
                            '<div><b>Display Name:</b> ' + resp.data.display_name + '</div>'
                        );
                    }
                });
            });
        } else {
            setTimeout(initSelect2User, 400); // Intenta de nuevo en 400ms
        }
    }
    $(document).ready(function(){
        initSelect2User();
    });
})(jQuery);
    </script>
    <?php
}


function eventosapp_ticket_sesiones_metabox($post) {
    $evento_id = get_post_meta($post->ID, '_eventosapp_ticket_evento_id', true);
    $sesiones = $evento_id ? get_post_meta($evento_id, '_eventosapp_sesiones_internas', true) : [];
    if (!is_array($sesiones)) $sesiones = [];
    // Extrae nombre
    $nombres_sesiones = [];
    foreach ($sesiones as $s) {
        if (is_array($s) && isset($s['nombre'])) $nombres_sesiones[] = $s['nombre'];
        elseif (is_string($s)) $nombres_sesiones[] = $s;
    }
    $accesos = get_post_meta($post->ID, '_eventosapp_ticket_sesiones_acceso', true);
    if (!is_array($accesos)) $accesos = [];

    // Nuevo: status de check-in por sesión
    $checkin_sesiones = get_post_meta($post->ID, '_eventosapp_ticket_checkin_sesiones', true);
    if (!is_array($checkin_sesiones)) $checkin_sesiones = [];

    if (!$nombres_sesiones) {
        echo '<em>No hay sesiones internas creadas para este evento.</em>';
        return;
    }

    wp_nonce_field('eventosapp_ticket_sesiones_guardar', 'eventosapp_ticket_sesiones_nonce');
    echo '<strong>Selecciona a qué espacios internos tiene acceso este ticket:</strong><br><br>';
    foreach ($nombres_sesiones as $s) {
        $checked = in_array($s, $accesos) ? 'checked' : '';
        $status = isset($checkin_sesiones[$s]) ? $checkin_sesiones[$s] : 'not_checked_in';
        echo '<div style="margin-bottom:7px; padding:7px 8px 4px 8px; background:#fafbfc; border:1px solid #eee; border-radius:8px;">';
        echo '<label style="display:block; margin-bottom:3px;">
                <input type="checkbox" name="eventosapp_ticket_sesiones_acceso[]" value="'.esc_attr($s).'" '.$checked.'> '.esc_html($s).'
              </label>';
        echo '<label style="font-size:13px; color:#555;">Check-in: </label>
              <select name="eventosapp_ticket_checkin_sesiones['.esc_attr($s).']" style="margin-left:6px;">
                <option value="not_checked_in" '.selected($status, 'not_checked_in', false).'>No</option>
                <option value="checked_in" '.selected($status, 'checked_in', false).'>Sí</option>
              </select>';
        echo '</div>';
    }
}


function eventosapp_ticket_tiene_acceso($ticket_id, $nombre_sesion) {
    $accesos = get_post_meta($ticket_id, '_eventosapp_ticket_sesiones_acceso', true);
    if (!is_array($accesos)) $accesos = [];
    return in_array($nombre_sesion, $accesos);
}

// Genera el PDF del ticket (si aplica) usando dompdf y template HTML
function eventosapp_ticket_generar_pdf($ticket_id) {
    // 1. Verificar evento y si la opción está activa
    $evento_id = get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    if (!$evento_id) return;

    $pdf_on = get_post_meta($evento_id, '_eventosapp_ticket_pdf', true);
    if ($pdf_on !== '1') return; // Solo si la opción está activa

    // 2. Cargar datos del evento y asistente
    $evento_nombre = get_the_title($evento_id);
    $organizador = get_post_meta($evento_id, '_eventosapp_organizador', true);
    $lugar_evento = get_post_meta($evento_id, '_eventosapp_direccion', true);
    // --- Fecha legible ---
    $tipo_fecha = get_post_meta($evento_id, '_eventosapp_tipo_fecha', true);
    if ($tipo_fecha === 'unica') {
        $fecha_evento = get_post_meta($evento_id, '_eventosapp_fecha_unica', true);
        $fecha_legible = $fecha_evento ? date_i18n('F d, Y', strtotime($fecha_evento)) : '';
    } elseif ($tipo_fecha === 'consecutiva') {
        $inicio = get_post_meta($evento_id, '_eventosapp_fecha_inicio', true);
        $fecha_legible = $inicio ? date_i18n('F d, Y', strtotime($inicio)) : '';
    } else {
        $fechas = get_post_meta($evento_id, '_eventosapp_fechas_noco', true);
        if (is_array($fechas) && $fechas) {
            $fecha_legible = implode(', ', array_map(function($f){ return date_i18n('F d, Y', strtotime($f)); }, $fechas));
        } else {
            $fecha_legible = '';
        }
    }

    $asistente_nombre = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true) . ' ' . get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
    $asistente_email  = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
    $localidad        = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);
    $ticket_code      = get_post_meta($ticket_id, 'eventosapp_ticketID', true);

    // QR como imagen base64
    $qr_path = eventosapp_get_ticket_qr_url($ticket_code);
    $qr_img = '';
    if ($qr_path) {
        $qr_file = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], strtok($qr_path, '?'));
        if (file_exists($qr_file)) {
            $img_data = file_get_contents($qr_file);
            $qr_img = 'data:image/png;base64,' . base64_encode($img_data);
        }
    }

    // 3. Cargar template HTML
    $tpl_path = plugin_dir_path(__FILE__) . 'includes/templates/pdf_template/pdf-template-ticket.html';
    if (!file_exists($tpl_path)) return;

    $html = file_get_contents($tpl_path);
    $html = str_replace([
        '{{evento_nombre}}',
        '{{organizador}}',
        '{{fecha_evento}}',
        '{{lugar_evento}}',
        '{{asistente_nombre}}',
        '{{asistente_email}}',
        '{{localidad}}',
        '{{ticket_id}}',
        '{{qr_img}}'
    ], [
        esc_html($evento_nombre),
        esc_html($organizador),
        esc_html($fecha_legible),
        esc_html($lugar_evento),
        esc_html($asistente_nombre),
        esc_html($asistente_email),
        esc_html($localidad),
        esc_html($ticket_code),
        $qr_img
    ], $html);

    // 4. Generar PDF con dompdf
    require_once plugin_dir_path(__FILE__) . 'includes/dompdf/autoload.inc.php';
    $dompdf = new Dompdf\Dompdf();
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // 5. Guardar el PDF en la carpeta de uploads/eventosapp-pdf/
    $upload_dir = wp_upload_dir();
    $pdf_dir = $upload_dir['basedir'] . '/eventosapp-pdf/';
    if (!file_exists($pdf_dir)) wp_mkdir_p($pdf_dir);

    $pdf_file = $pdf_dir . $ticket_code . '.pdf';
    file_put_contents($pdf_file, $dompdf->output());

    // Opcional: guardar la ruta relativa/url del PDF como meta del ticket
    update_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', $upload_dir['baseurl'] . '/eventosapp-pdf/' . $ticket_code . '.pdf');
}

/**
 * 3. Guardar: Generar ID NO predecible, usarlo como title, guardar campos y controlar status+log
 */
// Reemplaza el hook actual por este:
add_action('save_post_eventosapp_ticket', 'eventosapp_save_ticket', 20, 3);

function eventosapp_save_ticket($post_id, $post, $update) {
    // 1) Protecciones básicas
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if ($post->post_type !== 'eventosapp_ticket') return;
    if (!isset($_POST['eventosapp_ticket_nonce']) || !wp_verify_nonce($_POST['eventosapp_ticket_nonce'], 'eventosapp_ticket_guardar')) return;

    // 2) Evento asociado (obligatorio)
    $evento_id = intval($_POST['eventosapp_ticket_evento_id'] ?? 0);
    if (!$evento_id) return;
    update_post_meta($post_id, '_eventosapp_ticket_evento_id', $evento_id);

    // 3) Usuario generador
    $user_id = intval($_POST['eventosapp_ticket_user_id'] ?? 0);
    update_post_meta($post_id, '_eventosapp_ticket_user_id', $user_id);

    // 4) Datos asistente
    update_post_meta($post_id, '_eventosapp_asistente_nombre',   sanitize_text_field($_POST['eventosapp_asistente_nombre']   ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_apellido', sanitize_text_field($_POST['eventosapp_asistente_apellido'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_cc',       sanitize_text_field($_POST['eventosapp_asistente_cc']       ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_email',    sanitize_email($_POST['eventosapp_asistente_email']         ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_tel',      sanitize_text_field($_POST['eventosapp_asistente_tel']      ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_empresa',  sanitize_text_field($_POST['eventosapp_asistente_empresa']  ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_nit',      sanitize_text_field($_POST['eventosapp_asistente_nit']      ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_cargo',    sanitize_text_field($_POST['eventosapp_asistente_cargo']    ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_ciudad',   sanitize_text_field($_POST['eventosapp_asistente_ciudad']   ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_pais',     sanitize_text_field($_POST['eventosapp_asistente_pais']     ?? 'Colombia'));
    update_post_meta($post_id, '_eventosapp_asistente_localidad',sanitize_text_field($_POST['eventosapp_asistente_localidad'] ?? ''));

    // 5) Guardar CAMPOS EXTRA por evento (estaba en lugar incorrecto)
    if (function_exists('eventosapp_get_event_extra_fields')) {
        $schema = eventosapp_get_event_extra_fields($evento_id);
        $in = isset($_POST['eventosapp_extra']) && is_array($_POST['eventosapp_extra']) ? $_POST['eventosapp_extra'] : [];
        foreach ((array)$schema as $fld){
            if (empty($fld['key'])) continue;
            $key = $fld['key'];
            $raw = isset($in[$key]) ? wp_unslash($in[$key]) : '';
            $val = function_exists('eventosapp_normalize_extra_value') ? eventosapp_normalize_extra_value($fld, $raw) : sanitize_text_field($raw);
            update_post_meta($post_id, '_eventosapp_extra_'.$key, $val);
        }
    }

    // 6) ID público NO predecible + secuencia interna por evento + título = ID
    $ticketID = get_post_meta($post_id, 'eventosapp_ticketID', true);
    if (!$ticketID) {
        $new_id = function_exists('eventosapp_generate_unique_ticket_id') ? eventosapp_generate_unique_ticket_id() : wp_generate_uuid4();
        update_post_meta($post_id, 'eventosapp_ticketID', $new_id);

        $seq = function_exists('eventosapp_next_event_sequence') ? eventosapp_next_event_sequence($evento_id) : 0;
        update_post_meta($post_id, '_eventosapp_ticket_seq', (int)$seq);

        // Evitar recursión al actualizar el título
        remove_action('save_post_eventosapp_ticket', 'eventosapp_save_ticket', 20);
        wp_update_post([
            'ID'         => $post_id,
            'post_title' => $new_id,
        ]);
        add_action('save_post_eventosapp_ticket', 'eventosapp_save_ticket', 20, 3);

        // 7) Auto-asignación de accesos a sesiones por localidad
        $localidad_ticket = get_post_meta($post_id, '_eventosapp_asistente_localidad', true);
        $sesiones = get_post_meta($evento_id, '_eventosapp_sesiones_internas', true);
        if (!is_array($sesiones)) $sesiones = [];
        $accesos_auto = [];
        foreach ($sesiones as $ses) {
            if (isset($ses['nombre'], $ses['localidades']) && is_array($ses['localidades'])) {
                if ($localidad_ticket && in_array($localidad_ticket, $ses['localidades'], true)) {
                    $accesos_auto[] = $ses['nombre'];
                }
            }
        }
        if ($accesos_auto) {
            update_post_meta($post_id, '_eventosapp_ticket_sesiones_acceso', $accesos_auto);
        }
        
        // 7.1) Inicializar estado de envío de correo para tickets nuevos
        if (function_exists('eventosapp_ticket_init_email_status')) {
            eventosapp_ticket_init_email_status($post_id);
        }
    }

    // 8) Wallet Android on/off por evento
    $wallet_android_on = get_post_meta($evento_id, '_eventosapp_ticket_wallet_android', true);
    if ($wallet_android_on === '1' || $wallet_android_on === 1 || $wallet_android_on === true) {
        if (function_exists('eventosapp_generar_enlace_wallet_android')) eventosapp_generar_enlace_wallet_android($post_id, false);
    } else {
        if (function_exists('eventosapp_eliminar_enlace_wallet_android')) eventosapp_eliminar_enlace_wallet_android($post_id);
    }
	
    // 8.1) Wallet Apple on/off por evento (usa SIEMPRE el generador canonizado / wrapper)
    $wallet_ios_on = get_post_meta($evento_id, '_eventosapp_ticket_wallet_apple', true);
    if ($wallet_ios_on === '1' || $wallet_ios_on === 1 || $wallet_ios_on === true) {
        // Generador unificado para asegurar el mismo .pkpass que el batch por evento.
        if (function_exists('eventosapp_apple_generate_pass')) {
            eventosapp_apple_generate_pass($post_id);
        } elseif (function_exists('eventosapp_generar_enlace_wallet_apple')) {
            // Fallback seguro al generador interno si el wrapper no existe en esta instalación.
            eventosapp_generar_enlace_wallet_apple($post_id);
        }
        // (Los generadores escriben las tres metaclaves: _eventosapp_ticket_wallet_apple, _eventosapp_ticket_wallet_apple_url, _eventosapp_ticket_pkpass_url)
    } else {
        // Limpieza cuando Apple está desactivado para el evento
        delete_post_meta($post_id, '_eventosapp_ticket_wallet_apple');
        delete_post_meta($post_id, '_eventosapp_ticket_wallet_apple_url');
        delete_post_meta($post_id, '_eventosapp_ticket_pkpass_url');
    }

    // 9) Check-in multi-día + log (igual que tu lógica actual)
    $days = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days($evento_id) : [];

    $status_arr = get_post_meta($post_id, '_eventosapp_checkin_status', true);
    if (is_string($status_arr)) $status_arr = @unserialize($status_arr);
    if (!is_array($status_arr)) $status_arr = [];

    $log = get_post_meta($post_id, '_eventosapp_checkin_log', true);
    if (is_string($log)) $log = @unserialize($log);
    if (!is_array($log)) $log = [];

    try { $now = new DateTime('now', wp_timezone()); } catch(Exception $e) { $now = new DateTime('now'); }

    if (isset($_POST['eventosapp_checkin_status']) && is_array($_POST['eventosapp_checkin_status'])) {
        foreach ($days as $day) {
            if (!array_key_exists($day, $_POST['eventosapp_checkin_status'])) continue;
            $prev  = $status_arr[$day] ?? 'not_checked_in';
            $nuevo = sanitize_text_field($_POST['eventosapp_checkin_status'][$day]);
            if (!in_array($nuevo, ['checked_in','not_checked_in'], true)) $nuevo = $prev;

            if ($nuevo !== $prev) {
                $status_arr[$day] = $nuevo;
                $u = wp_get_current_user();
                $usuario = ($u && $u->exists()) ? ($u->display_name.' ('.$u->user_email.')') : 'Sistema';
                $log[] = [
                    'fecha'   => $now->format('Y-m-d'),
                    'hora'    => $now->format('H:i:s'),
                    'dia'     => $day,
                    'status'  => $nuevo,
                    'usuario' => $usuario,
                    'origen'  => 'manual',
                    'previo'  => $prev,
                ];
            }
        }
        update_post_meta($post_id, '_eventosapp_checkin_status', $status_arr);
        update_post_meta($post_id, '_eventosapp_checkin_log', $log);
    } else {
        if (empty($status_arr) && !empty($days)) {
            foreach ($days as $d) $status_arr[$d] = 'not_checked_in';
            update_post_meta($post_id, '_eventosapp_checkin_status', $status_arr);
        }
    }

    // 10) Guardar accesos a sesiones internas (manual)
    if (isset($_POST['eventosapp_ticket_sesiones_nonce']) && wp_verify_nonce($_POST['eventosapp_ticket_sesiones_nonce'], 'eventosapp_ticket_sesiones_guardar')) {
        $accesos = [];
        if (!empty($_POST['eventosapp_ticket_sesiones_acceso']) && is_array($_POST['eventosapp_ticket_sesiones_acceso'])) {
            foreach ($_POST['eventosapp_ticket_sesiones_acceso'] as $a) $accesos[] = sanitize_text_field($a);
        }
        update_post_meta($post_id, '_eventosapp_ticket_sesiones_acceso', $accesos);
    }

    // 11) Status por sesión interna
    if (isset($_POST['eventosapp_ticket_checkin_sesiones']) && is_array($_POST['eventosapp_ticket_checkin_sesiones'])) {
        $statuses = [];
        foreach ($_POST['eventosapp_ticket_checkin_sesiones'] as $s => $v) {
            $statuses[$s] = in_array($v, ['checked_in', 'not_checked_in'], true) ? $v : 'not_checked_in';
        }
        update_post_meta($post_id, '_eventosapp_ticket_checkin_sesiones', $statuses);
    }

    // 12) Guardar manuales de archivos (si existiera UI para ello)
    if (isset($_POST['eventosapp_ticket_ics_url'])) {
        update_post_meta($post_id, '_eventosapp_ticket_ics_url', sanitize_text_field($_POST['eventosapp_ticket_ics_url']));
    }
    if (isset($_POST['eventosapp_ticket_wallet_android'])) {
        update_post_meta($post_id, '_eventosapp_ticket_wallet_android', sanitize_text_field($_POST['eventosapp_ticket_wallet_android']));
    }
    if (isset($_POST['eventosapp_ticket_wallet_apple'])) {
        update_post_meta($post_id, '_eventosapp_ticket_wallet_apple', sanitize_text_field($_POST['eventosapp_ticket_wallet_apple']));
    }

    // 13) Guardar ID preimpreso (numérico)
    if (isset($_POST['eventosapp_ticket_preprintedID'])) {
        $raw = wp_unslash($_POST['eventosapp_ticket_preprintedID']);
        $num = preg_replace('/\D+/', '', (string)$raw);
        update_post_meta($post_id, 'eventosapp_ticket_preprintedID', $num);
    }

    // 14) Regenerar PDF / ICS si corresponde
    if (function_exists('eventosapp_ticket_generar_pdf')) eventosapp_ticket_generar_pdf($post_id);
    if (function_exists('eventosapp_ticket_generar_ics')) eventosapp_ticket_generar_ics($post_id);

    // Canal de creación por defecto si no existe aún: manual (editor del admin)
    if (!get_post_meta($post_id, '_eventosapp_creation_channel', true)) {
        update_post_meta($post_id, '_eventosapp_creation_channel', 'manual');
    }
}

/**
 * 3.1. Prevenir edición del título en Quick Edit y programáticamente
 */
add_filter('wp_insert_post_data', function($data, $postarr) {
    if ($data['post_type'] === 'eventosapp_ticket') {
        // Si ya existe el ticket y tiene meta, no dejar cambiar el título nunca
        if (!empty($postarr['ID'])) {
            $ticketID = get_post_meta($postarr['ID'], 'eventosapp_ticketID', true);
            if ($ticketID && $data['post_title'] !== $ticketID) {
                $data['post_title'] = $ticketID;
            }
        }
    }
    return $data;
}, 99, 2);

/**
 * 4. AJAX: Resumen del evento en la metabox del ticket
 */
add_action('wp_ajax_eventosapp_ticket_get_event_summary', function() {
    if ( ! current_user_can('edit_posts') ) wp_send_json_error('Unauthorized', 403);

    $evento_id = intval($_POST['evento_id'] ?? 0);
    if(!$evento_id) wp_send_json_success('');
    $evento = get_post($evento_id);
    if(!$evento || $evento->post_type !== 'eventosapp_event') wp_send_json_success('');

    $tipo_fecha = get_post_meta($evento_id, '_eventosapp_tipo_fecha', true);
    $date_label = '-';

    if ($tipo_fecha === 'unica') {
        $fecha = get_post_meta($evento_id, '_eventosapp_fecha_unica', true);
        if ($fecha) $date_label = esc_html( date_i18n("D, d M Y", strtotime($fecha)) );
    } elseif ($tipo_fecha === 'consecutiva') {
        $inicio = get_post_meta($evento_id, '_eventosapp_fecha_inicio', true);
        $fin    = get_post_meta($evento_id, '_eventosapp_fecha_fin', true);
        if ($inicio && $fin) {
            $date_label = esc_html( date_i18n("D, d M Y", strtotime($inicio)) )
                        . ' &rarr; '
                        . esc_html( date_i18n("D, d M Y", strtotime($fin)) );
        }
    } elseif ($tipo_fecha === 'noconsecutiva') {
        $fechas = get_post_meta($evento_id, '_eventosapp_fechas_noco', true);
        if (is_string($fechas)) $fechas = @unserialize($fechas);
        if (!is_array($fechas)) $fechas = [];
        if ($fechas) {
            $label_arr = [];
            foreach ($fechas as $f) {
                if ($f) $label_arr[] = esc_html( date_i18n("D, d M Y", strtotime($f)) );
            }
            $date_label = implode('<br>', $label_arr);
        }
    }

    $hora_inicio = get_post_meta($evento_id, '_eventosapp_hora_inicio', true);
    $hora_fin    = get_post_meta($evento_id, '_eventosapp_hora_cierre', true);
    $lugar       = get_post_meta($evento_id, '_eventosapp_direccion', true);
    $gps         = get_post_meta($evento_id, '_eventosapp_coordenadas', true);
    $telefono    = get_post_meta($evento_id, '_eventosapp_organizador_tel', true);
    $email       = get_post_meta($evento_id, '_eventosapp_organizador_email', true);

    $out  = '<b>'.esc_html($evento->post_title).'</b><br>';
    $out .= '<b>Date(s):</b> '.($date_label !== '' ? $date_label : '-').'<br>';
    $out .= '<b>Start time:</b> ' . esc_html($hora_inicio ?: '-') . '<br>';
    $out .= '<b>End time:</b> '   . esc_html($hora_fin ?: '-')    . '<br>';
    $out .= '<b>Venue:</b> '      . esc_html($lugar ?: '-')       . '<br>';
    $out .= '<b>GPS Coordinates:</b> ' . esc_html($gps ?: '-')    . '<br>';
    $out .= '<b>Phone:</b> '      . esc_html($telefono ?: '-')    . '<br>';
    $out .= '<b>Email:</b> '      . esc_html($email ?: '-')       . '<br>';

    wp_send_json_success($out);

});


/**
 * 5. AJAX: Búsqueda de usuario WordPress por nombre/correo
 */
add_action('wp_ajax_eventosapp_ticket_user_search', function() {
    $results = [];
    $q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
    if(strlen($q) < 3) wp_send_json_success($results);

    $allowed_roles = ['administrator', 'organizador', 'staff', 'logistico'];

    $args = [
        'search'         => '*' . esc_attr($q) . '*',
        'search_columns' => ['user_login','user_nicename','user_email','display_name'],
        'number'         => 30
    ];

    $users = get_users($args);

    foreach($users as $u){
        // Debug temporal:
        // error_log('Usuario: '.$u->user_login.' -- Roles: '.json_encode($u->roles));
        if (array_intersect($u->roles, $allowed_roles)) {
            $results[] = [
                'id'   => $u->ID,
                'text' => $u->display_name . " ({$u->user_email})"
            ];
        }
    }
    wp_send_json_success($results);
});

/**
 * 6. AJAX: Info detallada usuario (para autocompletar)
 */
add_action('wp_ajax_eventosapp_ticket_user_info', function() {
    $user_id = intval($_POST['user_id'] ?? 0);
    if(!$user_id) wp_send_json_success([]);
    $u = get_userdata($user_id);
    if(!$u) wp_send_json_success([]);
    $first_name = get_user_meta($user_id, 'first_name', true);
    $last_name  = get_user_meta($user_id, 'last_name', true);
    wp_send_json_success([
        'first_name'   => $first_name,
        'last_name'    => $last_name,
        'email'        => $u->user_email,
        'display_name' => $u->display_name
    ]);
});

/**
 * 7. Enqueue Select2 para admin (solo en tickets)
 */
add_action('admin_enqueue_scripts', function($hook){
    // Solo cargar para el CPT eventosapp_ticket
    $screen = get_current_screen();
    if ($screen && $screen->post_type === 'eventosapp_ticket') {
        // Cargar jQuery explícitamente (muchos themes lo rompen)
        wp_enqueue_script('jquery');
        // Cargar Select2
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true);
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0');
    }
});

add_action('wp_ajax_eventosapp_ticket_get_localidades', function() {
    if ( ! current_user_can('edit_posts') ) wp_send_json_error('Unauthorized', 403);

    $evento_id = intval($_POST['evento_id'] ?? 0);
    $localidades = [];
    if ($evento_id) {
        $localidades = get_post_meta($evento_id, '_eventosapp_localidades', true);
        if (!is_array($localidades) || empty($localidades)) $localidades = ['General', 'VIP', 'Platino'];
    } else {
        $localidades = ['General', 'VIP', 'Platino'];
    }
    wp_send_json_success($localidades);
});


function eventosapp_url_to_path($url) {
    $upload = wp_upload_dir();
    if (strpos($url, $upload['baseurl']) === 0) {
        $rel = substr($url, strlen($upload['baseurl']));
        return $upload['basedir'] . $rel;
    }
    return false;
}

add_action('admin_notices', function() {
    if (!is_admin() || !isset($_GET['post'], $_GET['evapp_mail'])) return;
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'eventosapp_ticket') === false) return;

    $success = ($_GET['evapp_mail'] === '1');
    $msg = isset($_GET['evapp_msg']) ? sanitize_text_field(wp_unslash($_GET['evapp_msg'])) : ($success ? 'Correo enviado.' : 'Error enviando correo.');
    echo '<div class="' . ($success ? 'notice notice-success' : 'notice notice-error') . ' is-dismissible"><p><b>EventosApp:</b> ' . esc_html($msg) . '</p></div>';
});

/** Canal de creación: helpers (manual, webhook, import, public) */
if (!function_exists('eventosapp_creation_channel_labels')) {
    function eventosapp_creation_channel_labels($key = null) {
        $map = [
            'manual'  => 'Manual',
            'webhook' => 'Integración',
            'import'  => 'Importación',
            'public'  => 'Inscripción Usuario',
        ];
        return is_null($key) ? $map : ($map[$key] ?? $key);
    }
}

// Muestra un aviso en el editor del ticket después del envío manual
add_action('admin_notices', function(){
    if (!is_admin() || !isset($_GET['post'], $_GET['evapp_netdigest'])) return;
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'eventosapp_ticket') return;

    $ok  = ($_GET['evapp_netdigest'] === '1');
    $msg = isset($_GET['evapp_netdigest_msg']) ? sanitize_text_field(wp_unslash($_GET['evapp_netdigest_msg'])) : ($ok ? 'Resumen enviado.' : 'No se pudo enviar el resumen.');
    echo '<div class="'.($ok?'notice notice-success':'notice notice-error').' is-dismissible"><p><b>EventosApp:</b> '.esc_html($msg).'</p></div>';
});






// === NUEVO: normalizador simple (acentos, minúsculas, espacios) ===
if ( ! function_exists('eventosapp_normalize_text') ) {
    function eventosapp_normalize_text($s) {
        $s = wp_strip_all_tags( (string) $s );
        $s = remove_accents($s);
        if (function_exists('mb_strtolower')) {
            $s = mb_strtolower($s, 'UTF-8');
        } else {
            $s = strtolower($s);
        }
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }
}

// === NUEVO: lista de países (ordenados alfabéticamente, Colombia por defecto en selects) ===
if ( ! function_exists('eventosapp_get_countries') ) {
    function eventosapp_get_countries() {
        // Base ISO + algunos ampliamente usados; sin acentos para evitar problemas de ordenación
        $countries = explode('|', 
        'Afghanistan|Albania|Algeria|Andorra|Angola|Antigua and Barbuda|Argentina|Armenia|Australia|Austria|Azerbaijan|Bahamas|Bahrain|Bangladesh|Barbados|Belarus|Belgium|Belize|Benin|Bhutan|Bolivia|Bosnia and Herzegovina|Botswana|Brazil|Brunei|Bulgaria|Burkina Faso|Burundi|Cabo Verde|Cambodia|Cameroon|Canada|Central African Republic|Chad|Chile|China|Colombia|Comoros|Congo (Congo-Brazzaville)|Costa Rica|Cote d\'Ivoire|Croatia|Cuba|Cyprus|Czechia|Democratic Republic of the Congo|Denmark|Djibouti|Dominica|Dominican Republic|Ecuador|Egypt|El Salvador|Equatorial Guinea|Eritrea|Estonia|Eswatini|Ethiopia|Fiji|Finland|France|Gabon|Gambia|Georgia|Germany|Ghana|Greece|Grenada|Guatemala|Guinea|Guinea-Bissau|Guyana|Haiti|Honduras|Hungary|Iceland|India|Indonesia|Iran|Iraq|Ireland|Israel|Italy|Jamaica|Japan|Jordan|Kazakhstan|Kenya|Kiribati|Kuwait|Kyrgyzstan|Laos|Latvia|Lebanon|Lesotho|Liberia|Libya|Liechtenstein|Lithuania|Luxembourg|Madagascar|Malawi|Malaysia|Maldives|Mali|Malta|Marshall Islands|Mauritania|Mauritius|Mexico|Micronesia|Moldova|Monaco|Mongolia|Montenegro|Morocco|Mozambique|Myanmar|Namibia|Nauru|Nepal|Netherlands|New Zealand|Nicaragua|Niger|Nigeria|North Korea|North Macedonia|Norway|Oman|Pakistan|Palau|Panama|Papua New Guinea|Paraguay|Peru|Philippines|Poland|Portugal|Qatar|Romania|Russia|Rwanda|Saint Kitts and Nevis|Saint Lucia|Saint Vincent and the Grenadines|Samoa|San Marino|Sao Tome and Principe|Saudi Arabia|Senegal|Serbia|Seychelles|Sierra Leone|Singapore|Slovakia|Slovenia|Solomon Islands|Somalia|South Africa|South Korea|South Sudan|Spain|Sri Lanka|Sudan|Suriname|Sweden|Switzerland|Syria|Taiwan|Tajikistan|Tanzania|Thailand|Timor-Leste|Togo|Tonga|Trinidad and Tobago|Tunisia|Turkey|Turkmenistan|Tuvalu|Uganda|Ukraine|United Arab Emirates|United Kingdom|United States|Uruguay|Uzbekistan|Vanuatu|Venezuela|Vietnam|Yemen|Zambia|Zimbabwe|Kosovo|Palestine');
        natcasesort($countries);
        return array_values($countries);
    }
}


/**
 * Mantiene actualizado el índice _evapp_search_blob al guardar un ticket.
 * (Se ejecuta después de guardar todos los metadatos)
 */
add_action('save_post_eventosapp_ticket', function($post_id){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (get_post_type($post_id) !== 'eventosapp_ticket') return;
    eventosapp_ticket_build_search_blob($post_id);
}, 999);

/**
 * Reindexa TODOS los tickets existentes (solo admin). Úsalo una vez.
 * URL: /wp-admin/admin-ajax.php?action=eventosapp_reindex_tickets_all&_wpnonce=XXXX
 */
// AJAX: reindexar UN ticket
add_action('wp_ajax_eventosapp_reindex_ticket', function(){
    if ( ! current_user_can('manage_options') ) wp_die('No autorizado', '', 403);

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $nonce   = isset($_POST['_wpnonce']) ? sanitize_text_field($_POST['_wpnonce']) : '';

    if ( ! $post_id || ! wp_verify_nonce( $nonce, 'eventosapp_reindex_ticket_'.$post_id ) ) {
        wp_die('Nonce inválido', '', 403);
    }
    if ( get_post_type($post_id) !== 'eventosapp_ticket' ) wp_die('Tipo inválido', '', 400);

    eventosapp_ticket_build_search_blob($post_id);

    wp_send_json_success(['msg' => 'Reindex OK para el ticket #'.$post_id]);
});

// Acción en fila: "Reindexar índice"
add_filter('post_row_actions', function($actions, $post){
    if ( $post->post_type === 'eventosapp_ticket' && current_user_can('manage_options') ) {
        $nonce = wp_create_nonce('eventosapp_reindex_ticket_'.$post->ID);
        $actions['evapp_reindex_one'] =
            '<a href="#" class="evapp-reindex-one" data-id="'.esc_attr($post->ID).'" data-nonce="'.esc_attr($nonce).'">Reindexar índice</a>';
    }
    return $actions;
}, 10, 2);

// JS para el click en la lista de tickets
add_action('admin_footer-edit.php', function(){
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'eventosapp_ticket' ) return;
    if ( ! current_user_can('manage_options') ) return; ?>
    <script>
    (function($){
        $(document).on('click', '.evapp-reindex-one', function(e){
            e.preventDefault();
            var $a = $(this);
            var id = $a.data('id');
            var nonce = $a.data('nonce');
            $a.text('Reindexando…');

            $.post(ajaxurl, {
                action: 'eventosapp_reindex_ticket',
                post_id: id,
                _wpnonce: nonce
            }).done(function(resp){
                alert(resp && resp.data ? resp.data.msg : 'Listo');
            }).fail(function(xhr){
                alert('Error: ' + (xhr.responseText || 'desconocido'));
            }).always(function(){
                $a.text('Reindexar índice');
            });
        });
    })(jQuery);
    </script>
<?php });

// Barra de herramientas en la lista de Tickets: filtro por Evento + botón "Reindexar todos"
add_action('restrict_manage_posts', function($post_type){
    if ($post_type !== 'eventosapp_ticket') return;

    // --- Filtro por Evento ---
    $selected = isset($_GET['ev_evento']) ? absint($_GET['ev_evento']) : 0;

    // Cargamos eventos (publicados y borrador); si tienes MUCHOS, limita posts_per_page
    $event_ids = get_posts([
        'post_type'      => 'eventosapp_event',
        'post_status'    => ['publish', 'draft'],
        'fields'         => 'ids',
        'orderby'        => 'title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
        'posts_per_page' => 500, // ajusta si tienes miles
    ]);

    echo '<label for="filter-ev-evento" class="screen-reader-text">Filtrar por evento</label>';
    echo '<select name="ev_evento" id="filter-ev-evento" class="postform" style="max-width:380px">';
    echo '<option value="">Todos los eventos</option>';
    foreach ($event_ids as $eid) {
        $title = get_the_title($eid);
        echo '<option value="'.esc_attr($eid).'" '.selected($selected, $eid, false).'>'.esc_html($title.' ['.$eid.']').'</option>';
    }
    echo '</select>';
	
	// --- Filtro por Canal ---
$canal_sel = isset($_GET['ev_canal']) ? sanitize_text_field($_GET['ev_canal']) : '';
$canales   = function_exists('eventosapp_creation_channel_labels')
    ? eventosapp_creation_channel_labels()
    : ['manual'=>'Manual','webhook'=>'Integración','import'=>'Importación','public'=>'Inscripción Usuario'];

echo ' <select name="ev_canal" id="filter-ev-canal" class="postform">';
echo '<option value="">Todos los canales</option>';
foreach ($canales as $slug => $label) {
    echo '<option value="'.esc_attr($slug).'" '.selected($canal_sel, $slug, false).'>'.esc_html($label).'</option>';
}
echo '</select>';


    // --- Botón "Reindexar todos" (solo admins), conservamos tu comportamiento anterior ---
    if ( current_user_can('manage_options') ) {
        $nonce = wp_create_nonce('eventosapp_reindex_tickets_all');
        echo ' <button type="button" class="button button-secondary" id="evapp-reindex-all" data-nonce="'.esc_attr($nonce).'">Reindexar todos los tickets</button>';
        echo ' <span id="evapp-reindex-status" style="margin-left:8px;color:#666;"></span>';
    }
});


// JS para lanzar el AJAX masivo desde la lista
add_action('admin_footer-edit.php', function(){
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'eventosapp_ticket' ) return;
    if ( ! current_user_can('manage_options') ) return; ?>
    <script>
    (function($){
        $(document).on('click', '#evapp-reindex-all', function(){
            if(!confirm('¿Reindexar el índice de búsqueda de TODOS los tickets?')) return;

            var $btn = $(this);
            var $st  = $('#evapp-reindex-status');
            var nonce = $btn.data('nonce');

            $btn.prop('disabled', true).text('Reindexando…');
            $st.text('Esto puede tardar según la cantidad de tickets.');

            $.get(ajaxurl, {
                action: 'eventosapp_reindex_tickets_all',
                _wpnonce: nonce
            }).done(function(resp){
                alert(resp);
                $st.text('Listo.');
            }).fail(function(xhr){
                alert('Error al reindexar: ' + (xhr.responseText || 'desconocido'));
                $st.text('Error.');
            }).always(function(){
                $btn.prop('disabled', false).text('Reindexar todos los tickets');
            });
        });
    })(jQuery);
    </script>
<?php });

/**
 * Columnas en el listado de Tickets:
 * Evento, Asistente, Email, Estado, Creado
 */
add_filter('manage_edit-eventosapp_ticket_columns', function($cols){
    $new = [];
    $new['cb']         = $cols['cb'] ?? '<input type="checkbox" />';
    $new['title']      = __('ID de Ticket', 'eventosapp');
    $new['ev_evento']  = __('Evento', 'eventosapp');
    $new['ev_asistente']= __('Asistente', 'eventosapp');
    $new['ev_estado']  = __('Estado', 'eventosapp');
    $new['ev_creado']  = __('Creado', 'eventosapp');
    $new['ev_canal']   = __('Canal', 'eventosapp');
    return $new;
});

/**
 * Render de cada columna
 */
add_action('manage_eventosapp_ticket_posts_custom_column', function($col, $post_id){
    switch ($col) {
        case 'ev_evento':
            $evento_id = (int) get_post_meta($post_id, '_eventosapp_ticket_evento_id', true);
            if ($evento_id) {
                $t = get_the_title($evento_id);
                $link = get_edit_post_link($evento_id);
                echo $link ? '<a href="'.esc_url($link).'">'.esc_html($t).'</a>' : esc_html($t);
                echo ' <span style="color:#888">['.esc_html($evento_id).']</span>';
            } else {
                echo '<span style="color:#888">—</span>';
            }
        break;

        case 'ev_asistente':
            $nombre   = get_post_meta($post_id, '_eventosapp_asistente_nombre', true);
            $apellido = get_post_meta($post_id, '_eventosapp_asistente_apellido', true);
            $full = trim($nombre.' '.$apellido);
            echo $full ? esc_html($full) : '<span style="color:#888">—</span>';
        break;

        case 'ev_estado':
            $status_arr = get_post_meta($post_id, '_eventosapp_checkin_status', true);
            if (is_string($status_arr)) $status_arr = @unserialize($status_arr);
            if (!is_array($status_arr)) $status_arr = [];
            $any_checked = in_array('checked_in', $status_arr, true);

            $label = $any_checked ? 'Checked In' : 'Not Checked In';
            $bg    = $any_checked ? '#17a22b' : '#b33';
            echo '<span style="display:inline-block;padding:2px 7px;border-radius:999px;color:#fff;font-size:12px;background:'.$bg.'">'.$label.'</span>';

            if ($status_arr) {
                $checked = 0; $total = 0;
                foreach($status_arr as $d=>$st){ $total++; if($st==='checked_in') $checked++; }
                echo ' <span title="Días con check-in: '.$checked.' / '.$total.'" style="color:#888;cursor:help">('.$checked.'/'.$total.')</span>';
            }
        break;

        case 'ev_creado':
            $t = get_post_time('U', true, $post_id);
            echo esc_html( date_i18n( get_option('date_format').' '.get_option('time_format'), $t ) );
        break;

        case 'ev_canal':
            $ch = get_post_meta($post_id, '_eventosapp_creation_channel', true);
            if (!$ch && get_post_meta($post_id, '_eventosapp_import_fingerprint', true)) { $ch = 'import'; }
            if ($ch) {
                $label = eventosapp_creation_channel_labels($ch);
                echo '<span class="evapp-chip evapp-chip-'.esc_attr($ch).'">'.esc_html($label).'</span>';
            } else {
                echo '<span style="color:#888">—</span>';
            }
        break;
    }
}, 10, 2);


/**
 * Hacer columnas ordenables
 */
add_filter('manage_edit-eventosapp_ticket_sortable_columns', function($cols){
    $cols['ev_evento']    = 'ev_evento';
    $cols['ev_asistente'] = 'ev_asistente';
    $cols['ev_creado']    = 'date';
    return $cols;
});


/**
 * Ajustar query del listado de Tickets:
 * - Orden por columnas personalizadas (como ya tenías)
 * - Filtro por evento (dropdown ev_evento)
 */
add_action('pre_get_posts', function($q){
    if (!is_admin() || !$q->is_main_query()) return;
    if ($q->get('post_type') !== 'eventosapp_ticket') return;

    $orderby = $q->get('orderby');

    // Orden por columnas personalizadas
    if ($orderby === 'ev_evento') {
        $q->set('meta_key', '_eventosapp_ticket_evento_id');
        $q->set('orderby', 'meta_value_num');
    } elseif ($orderby === 'ev_asistente') {
        $q->set('meta_key', '_eventosapp_asistente_nombre');
        $q->set('orderby', 'meta_value');
    }
    // (sin ev_email)

    // Filtro por canal
    $canal = isset($_GET['ev_canal']) ? sanitize_text_field($_GET['ev_canal']) : '';
    if ($canal !== '') {
        $mq = (array) $q->get('meta_query');
        $mq[] = [
            'key'     => '_eventosapp_creation_channel',
            'value'   => $canal,
            'compare' => '=',
        ];
        $q->set('meta_query', $mq);
    }

    // Filtro por evento
    $ev = isset($_GET['ev_evento']) ? absint($_GET['ev_evento']) : 0;
    if ($ev) {
        $mq = (array) $q->get('meta_query');
        $mq[] = [
            'key'     => '_eventosapp_ticket_evento_id',
            'value'   => $ev,
            'compare' => '=',
            'type'    => 'NUMERIC',
        ];
        $q->set('meta_query', $mq);
    }
});


/**
 * Un poco de estilo para que se vea bonito
 */
add_action('admin_head', function(){
    $screen = get_current_screen();
    if ($screen && $screen->post_type === 'eventosapp_ticket') {
        echo '<style>
            /* Anchos pensados para que todo quepa sin colapsar */
            .wp-list-table .column-title{width:16%}
            .wp-list-table .column-ev_evento{width:26%}
            .wp-list-table .column-ev_asistente{width:22%}
            .wp-list-table .column-ev_estado{width:14%}
            .wp-list-table .column-ev_creado{width:12%}
            .wp-list-table .column-ev_canal{width:10%}

            /* Chips de canal */
            .evapp-chip{display:inline-block;padding:2px 7px;border-radius:999px;font-size:12px;color:#fff}
            .evapp-chip-manual{background:#6b7280}
            .evapp-chip-webhook{background:#2563eb}
            .evapp-chip-import{background:#059669}
            .evapp-chip-public{background:#d97706}

            /* Evitar que los títulos y nombres se “verticalicen” por cortes raros */
            .wp-list-table .column-title a,
            .wp-list-table .column-ev_evento a,
            .wp-list-table .column-ev_asistente{
                white-space: normal;
                word-break: normal;
                overflow-wrap: anywhere;
            }
        </style>';
    }
});


add_action('wp_ajax_eventosapp_reindex_tickets_all', function(){
    if ( ! current_user_can('manage_options') ) {
        wp_die('No autorizado', '', 403);
    }
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
    if ( ! wp_verify_nonce($nonce, 'eventosapp_reindex_tickets_all') ) {
        wp_die('Nonce inválido', '', 403);
    }

    if ( ! function_exists('eventosapp_ticket_build_search_blob') ) {
        wp_die('Función de reindex no disponible');
    }

    $q = new WP_Query([
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'posts_per_page' => -1,
        'no_found_rows'  => true,
    ]);

    $count = 0;
    if ($q->have_posts()){
        foreach ($q->posts as $tid){
            eventosapp_ticket_build_search_blob($tid);
            $count++;
        }
    }
    wp_die('Reindex OK. Tickets procesados: '.$count);
	
});
