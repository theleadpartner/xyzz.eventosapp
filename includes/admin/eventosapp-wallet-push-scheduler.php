<?php
/**
 * EventosApp – Programador de Notificaciones Push para Wallets
 * Archivo: includes/admin/eventosapp-wallet-push-scheduler.php
 *
 * Metabox en CPT eventosapp_event que permite programar notificaciones push
 * hacia Apple Wallet (APNs) y/o Google Wallet (addMessage TEXT_AND_NOTIFY).
 *
 * Dependencias (ya cargadas por el plugin principal):
 *  - includes/functions/apple-wallet-webservice.php  → evapp_pkws_push_ticket_update()
 *  - includes/functions/apple-wallet-ios.php         → evapp_apple_cfg()
 *  - includes/functions/google-wallet-android.php    → eventosapp_google_wallet_get_access_token()
 */

if ( ! defined('ABSPATH') ) exit;

/* ============================================================
 * =================== NOMBRE DE LA TABLA =====================
 * ============================================================ */

function evapp_wps_table() {
    global $wpdb;
    return $wpdb->prefix . 'evapp_wallet_pushes';
}

/* ============================================================
 * ============== CREACIÓN DE TABLA (dbDelta) =================
 * ============================================================ */

function evapp_wps_create_table() {
    global $wpdb;
    $table   = evapp_wps_table();
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        evento_id    BIGINT(20) UNSIGNED NOT NULL,
        mensaje      TEXT NOT NULL,
        wallet_type  VARCHAR(10) NOT NULL DEFAULT 'both',
        scheduled_at DATETIME NOT NULL,
        sent_at      DATETIME NULL DEFAULT NULL,
        status       VARCHAR(10) NOT NULL DEFAULT 'pending',
        error_msg    VARCHAR(255) NULL DEFAULT NULL,
        created_by   BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY evento_status (evento_id, status),
        KEY status_sched  (status, scheduled_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

// Crear tabla en activación (también en plugins_loaded como fallback si no existe)
add_action( 'plugins_loaded', function() {
    if ( get_option('evapp_wps_db_version') !== '1.0' ) {
        evapp_wps_create_table();
        update_option( 'evapp_wps_db_version', '1.0' );
    }
}, 15 );

/* ============================================================
 * ==================== REGISTRO DEL CRON ====================
 * ============================================================ */

add_filter( 'cron_schedules', function( $schedules ) {
    if ( ! isset( $schedules['evapp_wps_every_minute'] ) ) {
        $schedules['evapp_wps_every_minute'] = [
            'interval' => 60,
            'display'  => __( 'Cada minuto (EventosApp Wallet Push)' ),
        ];
    }
    return $schedules;
} );

add_action( 'init', function() {
    if ( ! wp_next_scheduled('evapp_wps_cron_check') ) {
        wp_schedule_event( time(), 'evapp_wps_every_minute', 'evapp_wps_cron_check' );
    }
} );

/* ============================================================
 * =================== WORKER DEL CRON =======================
 * ============================================================ */

add_action( 'evapp_wps_cron_check', 'evapp_wps_process_pending' );

function evapp_wps_process_pending() {
    global $wpdb;
    $table = evapp_wps_table();
    $now   = current_time( 'mysql' );

    // Lock para evitar ejecuciones concurrentes
    if ( get_transient('evapp_wps_processing') ) return;
    set_transient( 'evapp_wps_processing', 1, 90 );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'pending' AND scheduled_at <= %s ORDER BY scheduled_at ASC LIMIT 10",
            $now
        )
    );

    foreach ( $rows as $row ) {
        evapp_wps_dispatch_push( (int) $row->id );
    }

    delete_transient('evapp_wps_processing');
}

/* ============================================================
 * =================== DISPATCHER PRINCIPAL ==================
 * ============================================================ */

/**
 * Ejecuta el envío de una notificación por su ID en la tabla.
 */
function evapp_wps_dispatch_push( $push_id ) {
    global $wpdb;
    $table = evapp_wps_table();

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $push_id ) );
    if ( ! $row ) return false;
    if ( $row->status === 'sent' ) return true; // ya enviado

    $evento_id   = (int)    $row->evento_id;
    $mensaje     = (string) $row->mensaje;
    $wallet_type = (string) $row->wallet_type;

    $errors = [];

    if ( in_array( $wallet_type, ['google', 'both'], true ) ) {
        $gres = evapp_wps_dispatch_google( $evento_id, $mensaje );
        if ( ! $gres['ok'] ) {
            $errors[] = 'Google: ' . ( $gres['error'] ?? 'error desconocido' );
        }
    }

    if ( in_array( $wallet_type, ['apple', 'both'], true ) ) {
        $ares = evapp_wps_dispatch_apple( $evento_id );
        if ( ! $ares['ok'] ) {
            $errors[] = 'Apple: ' . ( $ares['error'] ?? 'error desconocido' );
        }
    }

    if ( empty( $errors ) ) {
        $wpdb->update(
            $table,
            [ 'status' => 'sent', 'sent_at' => current_time('mysql'), 'error_msg' => null ],
            [ 'id'     => $push_id ],
            [ '%s',    '%s',                                            '%s' ],
            [ '%d' ]
        );
        return true;
    } else {
        $wpdb->update(
            $table,
            [ 'status' => 'failed', 'error_msg' => implode(' | ', $errors) ],
            [ 'id'     => $push_id ],
            [ '%s',    '%s' ],
            [ '%d' ]
        );
        return false;
    }
}

/* ============================================================
 * ================ DISPATCHER GOOGLE WALLET =================
 * ============================================================ */

/**
 * Envía addMessage (TEXT_AND_NOTIFY) a la clase del evento en Google Wallet
 * Y también a cada objeto individual para garantizar la notificación push en el SO Android.
 *
 * - Clase: el mensaje aparece en la sección de mensajes dentro de la app Wallet (todos los portadores).
 * - Objeto: dispara la notificación push visible en el centro de notificaciones del SO vía FCM.
 *
 * Retorna ['ok' => bool, 'error' => string|null].
 */
function evapp_wps_dispatch_google( $evento_id, $mensaje ) {
    if ( ! function_exists('eventosapp_google_wallet_get_access_token') ) {
        return [ 'ok' => false, 'error' => 'Función de token no disponible' ];
    }

    $issuer_id = get_option('eventosapp_wallet_issuer_id', '');
    if ( ! $issuer_id ) {
        return [ 'ok' => false, 'error' => 'issuer_id no configurado' ];
    }

    // Resolver class_id del evento (misma lógica que google-wallet-android.php)
    $wallet_custom    = get_post_meta( $evento_id, '_eventosapp_wallet_custom_enable', true ) === '1';
    $class_id_event   = $wallet_custom ? trim( (string) get_post_meta( $evento_id, '_eventosapp_wallet_class_id', true ) ) : '';
    $class_id_default = trim( (string) get_option('eventosapp_wallet_class_id', '') );

    if ( $class_id_event !== '' ) {
        $class_id = $class_id_event;
    } elseif ( $class_id_default !== '' ) {
        $class_id = $class_id_default;
    } else {
        $class_id = 'event_' . $evento_id;
    }

    // Asegurar prefijo issuer_id
    if ( strpos( $class_id, '.' ) === false ) {
        $class_id = $issuer_id . '.' . ltrim( $class_id, '.' );
    }

    // Obtener token de acceso
    $token_res = eventosapp_google_wallet_get_access_token();
    $token     = $token_res['token'] ?? null;
    if ( ! $token ) {
        return [ 'ok' => false, 'error' => 'No se pudo obtener access token de Google' ];
    }

    $common_headers = [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type'  => 'application/json',
    ];

    // ID base del mensaje (único por envío)
    $msg_id = 'evapp-push-' . time() . '-' . $evento_id;

    // ── 1) addMessage a nivel de CLASE ──────────────────────────────────────────
    // Propaga el mensaje in-app a todos los portadores de objetos de esta clase.
    $class_payload = [
        'message' => [
            'header'      => get_the_title( $evento_id ),
            'body'        => sanitize_text_field( $mensaje ),
            'id'          => $msg_id,
            'messageType' => 'TEXT_AND_NOTIFY',
        ],
    ];

    $class_endpoint = 'https://walletobjects.googleapis.com/walletobjects/v1/eventTicketClass/'
                    . rawurlencode( $class_id ) . '/addMessage';

    $class_response = wp_remote_post( $class_endpoint, [
        'timeout' => 20,
        'headers' => $common_headers,
        'body'    => wp_json_encode( $class_payload ),
    ] );

    if ( is_wp_error( $class_response ) ) {
        $err = $class_response->get_error_message();
        error_log( "EVENTOSAPP GW PUSH clase:$class_id wp_error:$err" );
        return [ 'ok' => false, 'error' => $err ];
    }

    $class_code = wp_remote_retrieve_response_code( $class_response );
    if ( $class_code < 200 || $class_code >= 300 ) {
        $class_body = wp_remote_retrieve_body( $class_response );
        $err        = "HTTP $class_code: " . wp_strip_all_tags( $class_body );
        error_log( "EVENTOSAPP GW PUSH clase:$class_id error:$err" );
        return [ 'ok' => false, 'error' => $err ];
    }

    error_log( "EVENTOSAPP GW PUSH clase:$class_id OK HTTP:$class_code" );

    // ── 2) addMessage a nivel de OBJETO (por ticket) ─────────────────────────────
    // La notificación a nivel de clase entrega el mensaje in-app pero no siempre
    // dispara la notificación push del SO Android. El nivel de objeto envía vía FCM
    // al dispositivo específico que tiene ese pase, activando la notificación visible
    // en el centro de notificaciones.
    // Límite: 150 tickets por ejecución para evitar timeouts de PHP.
    $tickets = get_posts( [
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'posts_per_page' => 150,
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'   => '_eventosapp_ticket_evento_id',
                'value' => (int) $evento_id,
            ],
            [
                'key'     => '_eventosapp_ticket_wallet_android_url',
                'compare' => 'EXISTS',
            ],
            [
                'key'     => 'eventosapp_ticketID',
                'compare' => 'EXISTS',
            ],
        ],
        'no_found_rows' => true,
    ] );

    $obj_endpoint_base = 'https://walletobjects.googleapis.com/walletobjects/v1/eventTicketObject/';
    $obj_ok            = 0;
    $obj_err           = 0;

    foreach ( $tickets as $ticket_id ) {
        $unique_id = get_post_meta( (int) $ticket_id, 'eventosapp_ticketID', true );
        if ( ! $unique_id ) continue;

        $object_id   = $issuer_id . '.' . $unique_id;
        $obj_payload = [
            'message' => [
                'header'      => get_the_title( $evento_id ),
                'body'        => sanitize_text_field( $mensaje ),
                'id'          => $msg_id . '-' . $unique_id,
                'messageType' => 'TEXT_AND_NOTIFY',
            ],
        ];

        $obj_res  = wp_remote_post( $obj_endpoint_base . rawurlencode( $object_id ) . '/addMessage', [
            'timeout' => 8,
            'headers' => $common_headers,
            'body'    => wp_json_encode( $obj_payload ),
        ] );

        if ( is_wp_error( $obj_res ) ) {
            $obj_err++;
            error_log( "EVENTOSAPP GW PUSH obj:$object_id wp_error:" . $obj_res->get_error_message() );
        } else {
            $ocode = wp_remote_retrieve_response_code( $obj_res );
            if ( $ocode >= 200 && $ocode < 300 ) {
                $obj_ok++;
            } else {
                $obj_err++;
                error_log( "EVENTOSAPP GW PUSH obj:$object_id HTTP:$ocode body:" . substr( wp_remote_retrieve_body( $obj_res ), 0, 200 ) );
            }
        }
    }

    error_log( "EVENTOSAPP GW PUSH evento:$evento_id clase:OK obj_ok:$obj_ok obj_err:$obj_err total_tickets:" . count( $tickets ) );

    return [ 'ok' => true, 'error' => null ];
}

/* ============================================================
 * ================ DISPATCHER APPLE WALLET ==================
 * ============================================================ */

/**
 * Envía push APNs a todos los dispositivos registrados de los tickets del evento.
 * iOS recibirá la notificación silenciosa y re-descargará el .pkpass.
 * La notificación visible al usuario depende del campo changeMessage del pase.
 *
 * Retorna ['ok' => bool, 'error' => string|null, 'pushed' => int].
 */
function evapp_wps_dispatch_apple( $evento_id ) {
    if ( ! function_exists('evapp_pkws_push_ticket_update') ) {
        return [ 'ok' => false, 'error' => 'evapp_pkws_push_ticket_update no disponible', 'pushed' => 0 ];
    }

    // Obtener todos los tickets del evento que tengan dispositivos registrados
    $tickets = get_posts( [
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'posts_per_page' => -1,
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'   => '_eventosapp_ticket_evento_id',
                'value' => $evento_id,
            ],
            [
                'key'     => '_evapp_pk_devices',
                'compare' => 'EXISTS',
            ],
        ],
        'no_found_rows'  => true,
    ] );

    if ( empty( $tickets ) ) {
        return [ 'ok' => true, 'error' => null, 'pushed' => 0 ]; // ok pero sin destinatarios
    }

    $pushed = 0;
    foreach ( $tickets as $ticket_id ) {
        $devs = get_post_meta( $ticket_id, '_evapp_pk_devices', true );
        if ( ! is_array( $devs ) || empty( $devs ) ) continue;
        evapp_pkws_push_ticket_update( (int) $ticket_id );
        $pushed++;
    }

    return [ 'ok' => true, 'error' => null, 'pushed' => $pushed ];
}

/* ============================================================
 * ====================== METABOX UI =========================
 * ============================================================ */

add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'evapp_wallet_push_scheduler',
        '📲 Notificaciones Push – Wallet',
        'evapp_wps_render_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
} );

function evapp_wps_render_metabox( WP_Post $post ) {
    global $wpdb;
    $evento_id = $post->ID;
    $table     = evapp_wps_table();

    wp_nonce_field( 'evapp_wps_nonce_' . $evento_id, 'evapp_wps_nonce' );

    // Leer notificaciones del evento
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE evento_id = %d ORDER BY scheduled_at DESC LIMIT 50",
            $evento_id
        )
    );

    $type_labels = [
        'google' => '🤖 Google Wallet',
        'apple'  => '🍎 Apple Wallet',
        'both'   => '📲 Ambos Wallets',
    ];
    $status_labels = [
        'pending' => '<span style="color:#b45309;font-weight:600;">⏳ Pendiente</span>',
        'sent'    => '<span style="color:#16a34a;font-weight:600;">✅ Enviada</span>',
        'failed'  => '<span style="color:#dc2626;font-weight:600;">❌ Fallida</span>',
    ];

    // Inyectar styles
    echo '<style>
        #evapp_wallet_push_scheduler .evapp-wps-table-wrap { overflow-x:auto; margin-bottom:18px; }
        #evapp_wallet_push_scheduler table.evapp-wps-list { width:100%; border-collapse:collapse; font-size:13px; }
        #evapp_wallet_push_scheduler table.evapp-wps-list th,
        #evapp_wallet_push_scheduler table.evapp-wps-list td { padding:8px 10px; border-bottom:1px solid #e5e7eb; vertical-align:middle; }
        #evapp_wallet_push_scheduler table.evapp-wps-list th { background:#f9fafb; font-weight:600; text-align:left; }
        #evapp_wallet_push_scheduler .evapp-wps-form-wrap { background:#f0f9ff; border:1px solid #bae6fd; border-radius:6px; padding:16px 18px; }
        #evapp_wallet_push_scheduler .evapp-wps-form-wrap h4 { margin:0 0 12px; font-size:14px; color:#0c4a6e; }
        #evapp_wallet_push_scheduler .evapp-wps-field { margin-bottom:12px; }
        #evapp_wallet_push_scheduler .evapp-wps-field label { display:block; font-weight:600; margin-bottom:4px; font-size:13px; }
        #evapp_wallet_push_scheduler .evapp-wps-field textarea { width:100%; min-height:70px; }
        #evapp_wallet_push_scheduler .evapp-wps-field input[type=datetime-local] { width:100%; max-width:280px; }
        #evapp_wallet_push_scheduler .evapp-wps-radios label { font-weight:400; margin-right:14px; }
        #evapp_wallet_push_scheduler .evapp-wps-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        #evapp_wallet_push_scheduler .evapp-wps-notice { padding:8px 12px; border-radius:4px; font-size:13px; margin-top:10px; display:none; }
        #evapp_wallet_push_scheduler .evapp-wps-notice.ok  { background:#dcfce7; border:1px solid #86efac; color:#166534; }
        #evapp_wallet_push_scheduler .evapp-wps-notice.err { background:#fee2e2; border:1px solid #fca5a5; color:#991b1b; }
        #evapp_wallet_push_scheduler .evapp-wps-spin { display:none; }
        #evapp_wallet_push_scheduler .evapp-wps-empty { color:#6b7280; font-style:italic; font-size:13px; padding:10px 0; }
        #evapp_wallet_push_scheduler .evapp-wps-note { background:#fffbeb; border:1px solid #fcd34d; border-radius:4px; padding:8px 12px; font-size:12px; color:#78350f; margin-bottom:14px; }
    </style>';

    // ── Nota informativa ─────────────────────────────────────────────────────
    echo '<div class="evapp-wps-note">
        <strong>Google Wallet:</strong> El mensaje llega como notificación push visible en Android (requiere que el asistente tenga el pase guardado y notificaciones habilitadas, límite 3 push/24h por pase).<br>
        <strong>Apple Wallet:</strong> El push despierta el Wallet del dispositivo para que re-descargue el pase actualizado. La notificación visible depende de los campos <code>changeMessage</code> configurados en el pase.
    </div>';

    // ── Lista de notificaciones ───────────────────────────────────────────────
    echo '<div class="evapp-wps-table-wrap">';
    if ( empty( $rows ) ) {
        echo '<p class="evapp-wps-empty">No hay notificaciones programadas para este evento.</p>';
    } else {
        echo '<table class="evapp-wps-list">
            <thead><tr>
                <th>#</th>
                <th>Mensaje</th>
                <th>Wallet</th>
                <th>Fecha / Hora</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $status_html = $status_labels[ $row->status ] ?? esc_html( $row->status );
            $type_label  = $type_labels[ $row->wallet_type ] ?? esc_html( $row->wallet_type );
            $sched_local = get_date_from_gmt( $row->scheduled_at, 'd/m/Y H:i' );
            $sent_local  = $row->sent_at ? get_date_from_gmt( $row->sent_at, 'd/m/Y H:i' ) : '—';
            $error_tip   = $row->error_msg ? ' title="' . esc_attr( $row->error_msg ) . '"' : '';

            echo '<tr id="evapp-wps-row-' . (int) $row->id . '">
                <td style="color:#9ca3af;">' . (int) $row->id . '</td>
                <td style="max-width:300px;word-break:break-word;">' . esc_html( $row->mensaje ) . '</td>
                <td>' . $type_label . '</td>
                <td>' . esc_html( $sched_local ) . '</td>
                <td' . $error_tip . '>' . $status_html . '</td>
                <td class="evapp-wps-actions">';

            if ( $row->status !== 'sent' ) {
                echo '<button type="button"
                        class="button button-small evapp-wps-btn-sendnow"
                        data-id="' . (int) $row->id . '"
                        title="Enviar inmediatamente">▶ Enviar Ya</button>';
            }

            echo '<button type="button"
                    class="button button-small evapp-wps-btn-delete"
                    data-id="' . (int) $row->id . '"
                    title="Eliminar notificación"
                    style="color:#dc2626;border-color:#dc2626;">✕ Eliminar</button>';

            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }
    echo '</div>'; // .evapp-wps-table-wrap

    // ── Formulario nueva notificación ─────────────────────────────────────────
    // Datetime mínima = ahora (hora local WP) en formato ISO para datetime-local
    $min_dt = date_i18n( 'Y-m-d\TH:i', current_time('timestamp') + 60 );

    echo '<div class="evapp-wps-form-wrap">
        <h4>➕ Programar nueva notificación</h4>

        <div class="evapp-wps-field">
            <label for="evapp-wps-mensaje">Mensaje <span style="font-weight:400;color:#6b7280;">(texto plano)</span></label>
            <textarea id="evapp-wps-mensaje" name="evapp_wps_mensaje" placeholder="Ej: La sala de registro ya está abierta. ¡Te esperamos!"></textarea>
        </div>

        <div class="evapp-wps-field">
            <label>Plataforma destino</label>
            <div class="evapp-wps-radios">
                <label><input type="radio" name="evapp_wps_wallet_type" value="both" checked> 📲 Ambos Wallets</label>
                <label><input type="radio" name="evapp_wps_wallet_type" value="google"> 🤖 Solo Google</label>
                <label><input type="radio" name="evapp_wps_wallet_type" value="apple"> 🍎 Solo Apple</label>
            </div>
        </div>

        <div class="evapp-wps-field">
            <label for="evapp-wps-fecha">Fecha y hora de envío <span style="font-weight:400;color:#6b7280;">(zona horaria del servidor)</span></label>
            <input type="datetime-local" id="evapp-wps-fecha" min="' . esc_attr( $min_dt ) . '">
        </div>

        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button type="button" id="evapp-wps-btn-add" class="button button-primary">📅 Programar notificación</button>
            <button type="button" id="evapp-wps-btn-add-now" class="button">⚡ Enviar ahora</button>
            <span class="evapp-wps-spin" id="evapp-wps-form-spin">⏳</span>
        </div>

        <div class="evapp-wps-notice" id="evapp-wps-form-notice"></div>
    </div>'; // .evapp-wps-form-wrap

    // ── Inline JS ─────────────────────────────────────────────────────────────
    $ajax_url = admin_url('admin-ajax.php');
    $nonce    = wp_create_nonce( 'evapp_wps_ajax_' . $evento_id );

    echo '<script>
(function($){
    var ajaxurl = ' . json_encode( $ajax_url ) . ';
    var nonce   = ' . json_encode( $nonce ) . ';
    var eventoId = ' . (int) $evento_id . ';

    function showNotice(msg, type) {
        var $n = $("#evapp-wps-form-notice");
        $n.removeClass("ok err").addClass(type === "ok" ? "ok" : "err").html(msg).show();
    }

    function reloadMetabox() {
        // Recarga solo el metabox con AJAX para refrescar la lista sin perder el post editor
        $.post(ajaxurl, {
            action: "evapp_wps_load_rows",
            evento_id: eventoId,
            _wpnonce: nonce
        }, function(res) {
            if (res.success && res.data.html) {
                $(".evapp-wps-table-wrap").html(res.data.html);
            }
        });
    }

    // ── Programar / Enviar Ahora (formulario) ────────────────────────────
    function formAction(sendNow) {
        var mensaje     = $.trim($("#evapp-wps-mensaje").val());
        var walletType  = $("input[name=evapp_wps_wallet_type]:checked").val();
        var scheduledAt = $("#evapp-wps-fecha").val();

        if (!mensaje) { showNotice("⚠ Escribe un mensaje.", "err"); return; }
        if (!sendNow && !scheduledAt) { showNotice("⚠ Selecciona fecha y hora de envío.", "err"); return; }

        $("#evapp-wps-form-spin").show();
        $("#evapp-wps-btn-add, #evapp-wps-btn-add-now").prop("disabled", true);

        $.post(ajaxurl, {
            action:       "evapp_wps_add",
            evento_id:    eventoId,
            mensaje:      mensaje,
            wallet_type:  walletType,
            scheduled_at: sendNow ? "" : scheduledAt,
            send_now:     sendNow ? "1" : "0",
            _wpnonce:     nonce
        }, function(res) {
            $("#evapp-wps-form-spin").hide();
            $("#evapp-wps-btn-add, #evapp-wps-btn-add-now").prop("disabled", false);

            if (res.success) {
                showNotice(res.data.message || "✅ Listo.", "ok");
                $("#evapp-wps-mensaje").val("");
                $("#evapp-wps-fecha").val("");
                reloadMetabox();
            } else {
                showNotice("❌ " + (res.data || "Error desconocido."), "err");
            }
        }).fail(function() {
            $("#evapp-wps-form-spin").hide();
            $("#evapp-wps-btn-add, #evapp-wps-btn-add-now").prop("disabled", false);
            showNotice("❌ Error de red. Intenta de nuevo.", "err");
        });
    }

    $("#evapp-wps-btn-add").on("click", function() { formAction(false); });
    $("#evapp-wps-btn-add-now").on("click", function() { formAction(true); });

    // ── Enviar Ya (fila existente) ───────────────────────────────────────
    $(document).on("click", ".evapp-wps-btn-sendnow", function() {
        var btn = $(this);
        var id  = btn.data("id");
        if (!confirm("¿Enviar esta notificación ahora?")) return;
        btn.prop("disabled", true).text("⏳");
        $.post(ajaxurl, {
            action:   "evapp_wps_send_now",
            push_id:  id,
            evento_id: eventoId,
            _wpnonce: nonce
        }, function(res) {
            if (res.success) {
                reloadMetabox();
            } else {
                alert("❌ " + (res.data || "Error."));
                btn.prop("disabled", false).text("▶ Enviar Ya");
            }
        });
    });

    // ── Eliminar (fila existente) ────────────────────────────────────────
    $(document).on("click", ".evapp-wps-btn-delete", function() {
        var btn = $(this);
        var id  = btn.data("id");
        if (!confirm("¿Eliminar esta notificación?")) return;
        btn.prop("disabled", true).text("⏳");
        $.post(ajaxurl, {
            action:   "evapp_wps_delete",
            push_id:  id,
            evento_id: eventoId,
            _wpnonce: nonce
        }, function(res) {
            if (res.success) {
                $("#evapp-wps-row-" + id).fadeOut(300, function(){ $(this).remove(); });
            } else {
                alert("❌ " + (res.data || "Error."));
                btn.prop("disabled", false).text("✕ Eliminar");
            }
        });
    });

})(jQuery);
</script>';
}

/* ============================================================
 * =================== HANDLERS AJAX =========================
 * ============================================================ */

/* ── ADD (programar o enviar ahora) ── */
add_action( 'wp_ajax_evapp_wps_add', function() {
    $evento_id   = (int) ( $_POST['evento_id']   ?? 0 );
    $nonce_key   = 'evapp_wps_ajax_' . $evento_id;

    if ( ! check_ajax_referer( $nonce_key, '_wpnonce', false ) ) {
        wp_send_json_error( 'Nonce inválido.' );
    }
    if ( ! current_user_can('edit_post', $evento_id) ) {
        wp_send_json_error( 'Permisos insuficientes.' );
    }
    if ( get_post_type( $evento_id ) !== 'eventosapp_event' ) {
        wp_send_json_error( 'Evento no válido.' );
    }

    $mensaje     = sanitize_textarea_field( wp_unslash( $_POST['mensaje']     ?? '' ) );
    $wallet_type = sanitize_text_field(     wp_unslash( $_POST['wallet_type'] ?? 'both' ) );
    $scheduled_str = sanitize_text_field(  wp_unslash( $_POST['scheduled_at'] ?? '' ) );
    $send_now    = ( ( $_POST['send_now'] ?? '0' ) === '1' );

    if ( ! $mensaje ) {
        wp_send_json_error( 'El mensaje no puede estar vacío.' );
    }
    if ( ! in_array( $wallet_type, ['google', 'apple', 'both'], true ) ) {
        wp_send_json_error( 'Tipo de wallet no válido.' );
    }

    // Convertir datetime-local (hora local WP) a datetime MySQL UTC
    if ( $send_now || ! $scheduled_str ) {
        $scheduled_gmt = current_time( 'mysql', 1 ); // UTC inmediato
    } else {
        // datetime-local viene como "2025-12-01T14:30" → timestamp local WP
        $ts_local = strtotime( str_replace( 'T', ' ', $scheduled_str ) );
        if ( ! $ts_local ) {
            wp_send_json_error( 'Fecha/hora inválida.' );
        }
        // Convertir hora local a GMT: resta el offset
        $offset_sec  = (int) get_option('gmt_offset') * 3600;
        $ts_gmt      = $ts_local - $offset_sec;
        $scheduled_gmt = gmdate( 'Y-m-d H:i:s', $ts_gmt );

        if ( ! $send_now && $ts_gmt < ( time() + 30 ) ) {
            wp_send_json_error( 'La fecha/hora de envío debe ser futura.' );
        }
    }

    global $wpdb;
    $table = evapp_wps_table();

    $inserted = $wpdb->insert(
        $table,
        [
            'evento_id'    => $evento_id,
            'mensaje'      => $mensaje,
            'wallet_type'  => $wallet_type,
            'scheduled_at' => $scheduled_gmt,
            'status'       => 'pending',
            'created_by'   => get_current_user_id(),
            'created_at'   => current_time('mysql'),
        ],
        [ '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
    );

    if ( ! $inserted ) {
        wp_send_json_error( 'Error al guardar en la base de datos.' );
    }

    $push_id = (int) $wpdb->insert_id;

    // Si enviar ahora → despachar inmediatamente
    if ( $send_now ) {
        $ok = evapp_wps_dispatch_push( $push_id );
        if ( $ok ) {
            wp_send_json_success( [ 'message' => '✅ Notificación enviada correctamente.' ] );
        } else {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT error_msg FROM {$table} WHERE id=%d", $push_id ) );
            wp_send_json_success( [
                'message' => '⚠ Enviado con errores: ' . ( $row->error_msg ?? 'sin detalle' ),
            ] );
        }
    }

    // Programar cron de respaldo para la hora exacta (wp_schedule_single_event usa timestamps Unix UTC)
    wp_schedule_single_event(
        strtotime( $scheduled_gmt ),
        'evapp_wps_dispatch_single',
        [ $push_id ]
    );

    wp_send_json_success( [ 'message' => '📅 Notificación programada para ' . get_date_from_gmt( $scheduled_gmt, 'd/m/Y H:i' ) . '.' ] );
} );

// Acción cron de respaldo para dispatch individual
add_action( 'evapp_wps_dispatch_single', 'evapp_wps_dispatch_push' );

/* ── SEND NOW (fila existente) ── */
add_action( 'wp_ajax_evapp_wps_send_now', function() {
    $push_id   = (int) ( $_POST['push_id']   ?? 0 );
    $evento_id = (int) ( $_POST['evento_id'] ?? 0 );

    if ( ! check_ajax_referer( 'evapp_wps_ajax_' . $evento_id, '_wpnonce', false ) ) {
        wp_send_json_error( 'Nonce inválido.' );
    }
    if ( ! current_user_can('edit_post', $evento_id) ) {
        wp_send_json_error( 'Permisos insuficientes.' );
    }

    $ok = evapp_wps_dispatch_push( $push_id );
    if ( $ok ) {
        wp_send_json_success( [ 'message' => 'Enviado.' ] );
    } else {
        global $wpdb;
        $tbl = evapp_wps_table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT error_msg FROM {$tbl} WHERE id=%d", $push_id ) );
        wp_send_json_error( 'Error: ' . ( $row->error_msg ?? 'sin detalle' ) );
    }
} );

/* ── DELETE ── */
add_action( 'wp_ajax_evapp_wps_delete', function() {
    $push_id   = (int) ( $_POST['push_id']   ?? 0 );
    $evento_id = (int) ( $_POST['evento_id'] ?? 0 );

    if ( ! check_ajax_referer( 'evapp_wps_ajax_' . $evento_id, '_wpnonce', false ) ) {
        wp_send_json_error( 'Nonce inválido.' );
    }
    if ( ! current_user_can('edit_post', $evento_id) ) {
        wp_send_json_error( 'Permisos insuficientes.' );
    }

    global $wpdb;
    $deleted = $wpdb->delete( evapp_wps_table(), [ 'id' => $push_id, 'evento_id' => $evento_id ], [ '%d', '%d' ] );
    if ( $deleted ) {
        // Cancelar cron de respaldo si existe
        wp_unschedule_event( wp_next_scheduled('evapp_wps_dispatch_single', [ $push_id ]) ?: 0, 'evapp_wps_dispatch_single', [ $push_id ] );
        wp_send_json_success();
    } else {
        wp_send_json_error( 'No se pudo eliminar.' );
    }
} );

/* ── LOAD ROWS (actualizar tabla sin recargar página) ── */
add_action( 'wp_ajax_evapp_wps_load_rows', function() {
    $evento_id = (int) ( $_POST['evento_id'] ?? 0 );

    if ( ! check_ajax_referer( 'evapp_wps_ajax_' . $evento_id, '_wpnonce', false ) ) {
        wp_send_json_error( 'Nonce inválido.' );
    }
    if ( ! current_user_can('edit_post', $evento_id) ) {
        wp_send_json_error( 'Permisos insuficientes.' );
    }

    global $wpdb;
    $table = evapp_wps_table();
    $rows  = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE evento_id = %d ORDER BY scheduled_at DESC LIMIT 50",
            $evento_id
        )
    );

    $type_labels = [
        'google' => '🤖 Google Wallet',
        'apple'  => '🍎 Apple Wallet',
        'both'   => '📲 Ambos Wallets',
    ];
    $status_labels = [
        'pending' => '<span style="color:#b45309;font-weight:600;">⏳ Pendiente</span>',
        'sent'    => '<span style="color:#16a34a;font-weight:600;">✅ Enviada</span>',
        'failed'  => '<span style="color:#dc2626;font-weight:600;">❌ Fallida</span>',
    ];

    ob_start();
    if ( empty( $rows ) ) {
        echo '<p class="evapp-wps-empty">No hay notificaciones programadas para este evento.</p>';
    } else {
        echo '<table class="evapp-wps-list">
            <thead><tr>
                <th>#</th><th>Mensaje</th><th>Wallet</th><th>Fecha / Hora</th><th>Estado</th><th>Acciones</th>
            </tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $status_html = $status_labels[ $row->status ] ?? esc_html( $row->status );
            $type_label  = $type_labels[ $row->wallet_type ] ?? esc_html( $row->wallet_type );
            $sched_local = get_date_from_gmt( $row->scheduled_at, 'd/m/Y H:i' );
            $error_tip   = $row->error_msg ? ' title="' . esc_attr( $row->error_msg ) . '"' : '';

            echo '<tr id="evapp-wps-row-' . (int) $row->id . '">
                <td style="color:#9ca3af;">' . (int) $row->id . '</td>
                <td style="max-width:300px;word-break:break-word;">' . esc_html( $row->mensaje ) . '</td>
                <td>' . $type_label . '</td>
                <td>' . esc_html( $sched_local ) . '</td>
                <td' . $error_tip . '>' . $status_html . '</td>
                <td class="evapp-wps-actions">';

            if ( $row->status !== 'sent' ) {
                echo '<button type="button" class="button button-small evapp-wps-btn-sendnow" data-id="' . (int)$row->id . '">▶ Enviar Ya</button>';
            }
            echo '<button type="button" class="button button-small evapp-wps-btn-delete" data-id="' . (int)$row->id . '" style="color:#dc2626;border-color:#dc2626;">✕ Eliminar</button>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    $html = ob_get_clean();

    wp_send_json_success( [ 'html' => $html ] );
} );

/* ============================================================
 * ========== LIMPIEZA AL ELIMINAR EVENTO (CASCADE) ===========
 * ============================================================ */

add_action( 'before_delete_post', function( $post_id ) {
    if ( get_post_type( $post_id ) !== 'eventosapp_event' ) return;

    global $wpdb;
    $wpdb->delete( evapp_wps_table(), [ 'evento_id' => $post_id ], [ '%d' ] );
} );
