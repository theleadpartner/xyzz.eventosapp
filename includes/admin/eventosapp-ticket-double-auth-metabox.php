<?php
/**
 * Metabox de Doble Autenticación en Tickets Individuales
 * 
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ========================================
// METABOX DE EVENTO: CANALES DE ENTREGA
// ========================================

if ( ! function_exists( 'eventosapp_double_auth_template_is_compatible' ) ) {
    function eventosapp_double_auth_template_is_compatible( $template, $sender_id = '' ) {
        if ( ! is_array( $template ) ) return false;

        $approved = function_exists( 'eventosapp_whatsapp_is_template_approved' )
            ? eventosapp_whatsapp_is_template_approved( $template )
            : in_array( strtoupper( (string) ( $template['meta_status'] ?? '' ) ), [ 'APPROVED', 'ACTIVE' ], true );
        if ( ! $approved ) return false;

        if ( $sender_id && function_exists( 'eventosapp_whatsapp_template_matches_sender' ) && ! eventosapp_whatsapp_template_matches_sender( $template, $sender_id, true ) ) {
            return false;
        }

        $header_format = strtoupper( sanitize_key( (string) ( $template['header_format'] ?? 'NONE' ) ) );
        if ( in_array( $header_format, [ 'IMAGE', 'VIDEO', 'DOCUMENT' ], true ) ) return false;

        if ( function_exists( 'eventosapp_whatsapp_get_runtime_body_variable_numbers' ) ) {
            $variables = eventosapp_whatsapp_get_runtime_body_variable_numbers( $template );
        } else {
            preg_match_all( '/\{\{\s*(\d+)\s*\}\}/', (string) ( $template['body_text'] ?? '' ), $matches );
            $variables = ! empty( $matches[1] ) ? array_map( 'absint', $matches[1] ) : [];
        }

        $variables = array_values( array_unique( array_map( 'absint', (array) $variables ) ) );
        if ( ! in_array( 1, $variables, true ) || array_diff( $variables, [ 1, 2, 3, 4, 5, 6 ] ) ) return false;

        $header_text = (string) ( $template['header_text_meta'] ?? $template['header_text'] ?? '' );
        if ( preg_match( '/\{\{\s*\d+\s*\}\}/', $header_text ) ) return false;

        foreach ( range( 1, 10 ) as $button_number ) {
            if ( strpos( (string) ( $template[ 'button_' . $button_number . '_url' ] ?? '' ), '{{' ) !== false ) return false;
        }

        return true;
    }
}

if ( ! function_exists( 'eventosapp_double_auth_whatsapp_template_defaults' ) ) {
    function eventosapp_double_auth_whatsapp_template_defaults() {
        $now = current_time( 'mysql' );
        return [
            'id'                        => 'double_auth_code',
            'double_auth_code'          => '1',
            'base_key'                  => 'double_auth_code',
            'is_default'                => '1',
            'name'                      => 'eventosapp_codigo_doble_auth_v1',
            'language'                  => 'es',
            'category'                  => 'UTILITY',
            'modality'                  => 'custom',
            'title'                     => 'Código de acceso · Doble Autenticación',
            'header_format'             => 'NONE',
            'header_text'               => '',
            'body_text'                 => "Tu código de acceso es *{{1}}*.\n\nHola {{2}}, úsalo para validar tu ingreso a *{{3}}*.\n📅 Válido para: {{4}}\n🎟️ Ticket: {{5}}\n\nNo compartas este código con otras personas.\n\n{{6}}",
            'body_examples'             => "48271\nMaría Pérez\nEvento Demo\n20/08/2026\nTK-DEMO-001\nOrganizador del evento",
            'body_text_meta'            => '',
            'body_variable_map'         => [ 1, 2, 3, 4, 5, 6 ],
            'body_variable_signature'   => '',
            'footer_text'               => 'EventosApp',
            'button_mode'               => 'none',
            'button_count'              => '0',
            'button_1_text'             => '',
            'button_1_url'              => '',
            'button_1_example'          => '',
            'button_2_text'             => '',
            'button_2_url'              => '',
            'button_2_example'          => '',
            'sender_phone_number_id'    => '',
            'sender_phone_label'        => 'Número por defecto',
            'waba_id'                   => '',
            'meta_template_id'          => '',
            'meta_status'               => 'LOCAL',
            'meta_category'             => '',
            'meta_rejected_reason'      => '',
            'last_api_message'          => '',
            'last_api_response'         => [],
            'last_submitted_at'         => '',
            'last_checked_at'           => '',
            'created_at'                => $now,
            'updated_at'                => $now,
        ];
    }
}

/**
 * Crea una plantilla base local dentro del administrador general de plantillas.
 * No la envía automáticamente a Meta: el administrador conserva el control de
 * revisión/aprobación y del número emisor.
 */
if ( ! function_exists( 'eventosapp_double_auth_whatsapp_template_ensure' ) ) {
    function eventosapp_double_auth_whatsapp_template_ensure() {
        if ( ! function_exists( 'eventosapp_whatsapp_templates_get_settings' ) || ! function_exists( 'eventosapp_whatsapp_templates_update_settings' ) ) return;

        $settings = eventosapp_whatsapp_templates_get_settings();
        $settings = is_array( $settings ) ? $settings : [];
        $templates = is_array( $settings['templates'] ?? null ) ? $settings['templates'] : [];
        $defaults = eventosapp_double_auth_whatsapp_template_defaults();
        $key = 'double_auth_code';

        if ( isset( $templates[$key] ) && is_array( $templates[$key] ) ) return;

        $template = $defaults;
        if ( function_exists( 'eventosapp_whatsapp_templates_prepare_body_for_meta' ) ) {
            $prepared = eventosapp_whatsapp_templates_prepare_body_for_meta( $template['body_text'], $template['body_examples'] );
            $template['body_text_meta'] = sanitize_textarea_field( (string) ( $prepared['text'] ?? $template['body_text'] ) );
            $template['body_variable_map'] = is_array( $prepared['variable_numbers'] ?? null )
                ? array_values( array_unique( array_map( 'absint', $prepared['variable_numbers'] ) ) )
                : [ 1, 2, 3, 4, 5, 6 ];
            $template['body_variable_signature'] = sanitize_text_field( (string) ( $prepared['signature'] ?? md5( $template['body_text'] ) ) );
        }

        $templates[$key] = $template;
        $settings['templates'] = $templates;
        eventosapp_whatsapp_templates_update_settings( $settings );
    }
}
add_action( 'admin_init', 'eventosapp_double_auth_whatsapp_template_ensure', 9 );

add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_double_auth_delivery',
        '📨 Canales de Doble Autenticación',
        'eventosapp_render_double_auth_delivery_metabox',
        'eventosapp_event',
        'side',
        'default'
    );
} );

function eventosapp_render_double_auth_delivery_metabox( $post ) {
    $enabled = get_post_meta( $post->ID, '_eventosapp_ticket_double_auth_enabled', true ) === '1';
    if ( ! $enabled ) {
        echo '<p style="color:#646970;margin:0;">Activa primero <strong>Doble Autenticación para Check-In</strong> en “Funciones Extra del Ticket”.</p>';
        return;
    }

    $email_meta = get_post_meta( $post->ID, '_eventosapp_double_auth_send_email', true );
    $email_enabled = $email_meta === '' ? true : $email_meta === '1';
    $whatsapp_enabled = get_post_meta( $post->ID, '_eventosapp_double_auth_send_whatsapp', true ) === '1';
    $template_id = sanitize_key( (string) get_post_meta( $post->ID, '_eventosapp_double_auth_whatsapp_template_id', true ) );
    $event_whatsapp_enabled = get_post_meta( $post->ID, '_eventosapp_ticket_whatsapp_enabled', true ) === '1';

    $settings = function_exists( 'eventosapp_whatsapp_get_settings' ) ? eventosapp_whatsapp_get_settings() : [];
    if ( function_exists( 'eventosapp_whatsapp_resolve_sender_settings' ) ) {
        $settings = eventosapp_whatsapp_resolve_sender_settings( $post->ID, $settings );
    }
    $sender_id = sanitize_text_field( (string) ( $settings['sender_phone_number_id'] ?? $settings['phone_number_id'] ?? '' ) );

    $templates = function_exists( 'eventosapp_whatsapp_masivo_get_templates' )
        ? eventosapp_whatsapp_masivo_get_templates( true )
        : [];
    $templates = array_filter( $templates, static function( $template ) use ( $sender_id ) {
        return eventosapp_double_auth_template_is_compatible( $template, $sender_id );
    } );

    $queue_map = get_post_meta( $post->ID, '_eventosapp_double_auth_queue_tasks', true );
    $queue_map = is_array( $queue_map ) ? $queue_map : [];

    wp_nonce_field( 'eventosapp_double_auth_delivery_save', 'eventosapp_double_auth_delivery_nonce' );
    ?>
    <style>
    .evapp-da-delivery{font-size:12px;line-height:1.45}
    .evapp-da-delivery .evapp-da-channel{display:block;padding:9px 10px;margin:0 0 8px;border:1px solid #dcdcde;border-radius:7px;background:#fff}
    .evapp-da-delivery .evapp-da-channel strong{font-size:13px}
    .evapp-da-delivery select{width:100%;margin-top:5px}
    .evapp-da-delivery .evapp-da-help{display:block;margin:5px 0 10px;color:#646970}
    .evapp-da-delivery .evapp-da-warning{padding:8px 9px;margin:8px 0;border-left:3px solid #dba617;background:#fff8e5;color:#6b5200}
    .evapp-da-delivery .evapp-da-vars{padding:8px 9px;margin-top:8px;border-radius:6px;background:#f0f6fc;color:#1d2327}
    .evapp-da-delivery .evapp-da-vars code{font-size:11px}
    .evapp-da-delivery .evapp-da-queue{margin-top:12px;padding-top:10px;border-top:1px solid #dcdcde}
    .evapp-da-delivery .evapp-da-task{display:block;margin-top:6px;padding:6px 8px;border-radius:6px;background:#f6f7f7;text-decoration:none}
    </style>

    <div class="evapp-da-delivery">
        <label class="evapp-da-channel">
            <input type="checkbox" name="eventosapp_double_auth_send_email" value="1" <?php checked( $email_enabled ); ?>>
            <strong>Correo electrónico</strong>
            <span class="evapp-da-help">Mantiene la plantilla de correo de Doble Autenticación que ya utiliza EventosApp.</span>
        </label>

        <label class="evapp-da-channel">
            <input type="checkbox" name="eventosapp_double_auth_send_whatsapp" value="1" <?php checked( $whatsapp_enabled ); ?>>
            <strong>WhatsApp</strong>
            <span class="evapp-da-help">Envía el mismo código mediante una plantilla aprobada por Meta.</span>
        </label>

        <?php if ( ! $event_whatsapp_enabled ) : ?>
            <div class="evapp-da-warning">Para utilizar WhatsApp también debe estar activa <strong>Mensajería de WhatsApp para tickets</strong> en este evento.</div>
        <?php endif; ?>

        <label for="evapp-da-whatsapp-template"><strong>Plantilla WhatsApp del código</strong></label>
        <select id="evapp-da-whatsapp-template" name="eventosapp_double_auth_whatsapp_template_id">
            <option value="">Seleccionar plantilla aprobada…</option>
            <?php foreach ( $templates as $id => $template ) :
                $id = sanitize_key( (string) ( $template['id'] ?? $id ) );
                $name = sanitize_text_field( (string) ( $template['title'] ?? $template['display_name'] ?? $template['name'] ?? $id ) );
                $language = sanitize_text_field( (string) ( $template['language'] ?? '' ) );
                ?>
                <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $template_id, $id ); ?>>
                    <?php echo esc_html( $name . ( $language ? ' · ' . $language : '' ) ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span class="evapp-da-help">Solo aparecen plantillas aprobadas, compatibles con el número emisor y con las variables admitidas por este módulo.</span>
        <?php if ( empty( $templates ) ) : ?>
            <div class="evapp-da-warning">EventosApp ya creó la plantilla base <strong>“Código de acceso · Doble Autenticación”</strong>. Debes enviarla a revisión y esperar que Meta la apruebe antes de activar WhatsApp. <a href="<?php echo esc_url( admin_url( 'admin.php?page=eventosapp_whatsapp_templates' ) ); ?>">Abrir Plantillas WhatsApp</a>.</div>
        <?php endif; ?>

        <div class="evapp-da-vars">
            <strong>Variables BODY:</strong><br>
            <code>{{1}}</code> Código · <code>{{2}}</code> Nombre · <code>{{3}}</code> Evento<br>
            <code>{{4}}</code> Fecha de validez · <code>{{5}}</code> Ticket · <code>{{6}}</code> Organizador
            <br><strong>{{1}} es obligatoria.</strong>
        </div>

        <div class="evapp-da-queue">
            <strong>Programación en Cola y Tareas</strong>
            <?php if ( empty( $queue_map ) ) : ?>
                <span class="evapp-da-help">Al guardar una programación válida, el envío inicial y los días posteriores aparecerán en Cola y Tareas.</span>
            <?php else : ?>
                <?php foreach ( $queue_map as $row ) :
                    if ( ! is_array( $row ) || empty( $row['task_id'] ) ) continue;
                    $task_id = absint( $row['task_id'] );
                    $day = sanitize_text_field( (string) ( $row['day'] ?? '' ) );
                    $label = $day ? date_i18n( 'd/m/Y', strtotime( $day ) ) : 'Envío inicial';
                    $url = function_exists( 'eventosapp_task_queue_task_url' )
                        ? eventosapp_task_queue_task_url( $task_id )
                        : admin_url( 'admin.php?page=eventosapp_task_queue&task_id=' . $task_id );
                    ?>
                    <a class="evapp-da-task" href="<?php echo esc_url( $url ); ?>"><strong><?php echo esc_html( $label ); ?></strong> · Tarea #<?php echo esc_html( $task_id ); ?></a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

add_action( 'save_post_eventosapp_event', function( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) return;
    if ( ! isset( $_POST['eventosapp_double_auth_delivery_nonce'] ) ) return;

    $nonce = sanitize_text_field( wp_unslash( $_POST['eventosapp_double_auth_delivery_nonce'] ) );
    if ( ! wp_verify_nonce( $nonce, 'eventosapp_double_auth_delivery_save' ) ) return;

    $email = isset( $_POST['eventosapp_double_auth_send_email'] ) ? '1' : '0';
    $whatsapp = isset( $_POST['eventosapp_double_auth_send_whatsapp'] ) ? '1' : '0';
    $template_id = isset( $_POST['eventosapp_double_auth_whatsapp_template_id'] )
        ? sanitize_key( wp_unslash( $_POST['eventosapp_double_auth_whatsapp_template_id'] ) )
        : '';

    // Valida la plantilla nuevamente al guardar para impedir configuraciones
    // que luego fallen en el worker.
    if ( $whatsapp === '1' ) {
        $event_wa = get_post_meta( $post_id, '_eventosapp_ticket_whatsapp_enabled', true ) === '1';
        $template = $template_id && function_exists( 'eventosapp_whatsapp_masivo_get_template' )
            ? eventosapp_whatsapp_masivo_get_template( $template_id )
            : null;

        $settings = function_exists( 'eventosapp_whatsapp_get_settings' ) ? eventosapp_whatsapp_get_settings() : [];
        if ( function_exists( 'eventosapp_whatsapp_resolve_sender_settings' ) ) {
            $settings = eventosapp_whatsapp_resolve_sender_settings( $post_id, $settings );
        }
        $sender_id = sanitize_text_field( (string) ( $settings['sender_phone_number_id'] ?? $settings['phone_number_id'] ?? '' ) );

        if ( ! $event_wa || ! eventosapp_double_auth_template_is_compatible( $template, $sender_id ) ) {
            $whatsapp = '0';
            set_transient(
                'eventosapp_double_auth_delivery_notice_' . get_current_user_id(),
                'WhatsApp para Doble Autenticación no se activó porque falta habilitar WhatsApp en el evento o seleccionar una plantilla aprobada compatible.',
                60
            );
        }
    }

    if ( $email !== '1' && $whatsapp !== '1' ) $email = '1';

    update_post_meta( $post_id, '_eventosapp_double_auth_send_email', $email );
    update_post_meta( $post_id, '_eventosapp_double_auth_send_whatsapp', $whatsapp );
    update_post_meta( $post_id, '_eventosapp_double_auth_whatsapp_template_id', $template_id );
}, 40, 1 );

add_action( 'admin_notices', function() {
    $key = 'eventosapp_double_auth_delivery_notice_' . get_current_user_id();
    $message = get_transient( $key );
    if ( ! $message ) return;
    delete_transient( $key );
    echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
} );

// ========================================
// METABOX: Información de Doble Autenticación
// ========================================

add_action('add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_ticket_double_auth_info',
        '🔐 Información de Doble Autenticación',
        'eventosapp_render_ticket_double_auth_metabox',
        'eventosapp_ticket',
        'side',
        'default'
    );
});

/**
 * Render del metabox de doble autenticación en tickets
 */
function eventosapp_render_ticket_double_auth_metabox($post) {
    // Obtener el evento del ticket
    $event_id = get_post_meta($post->ID, '_eventosapp_ticket_evento_id', true);
    
    if (!$event_id) {
        echo '<p style="color:#666;">Este ticket no está asociado a ningún evento.</p>';
        return;
    }
    
    // Verificar si el evento tiene doble autenticación activada
    $double_auth_enabled = get_post_meta($event_id, '_eventosapp_ticket_double_auth_enabled', true);
    
    if ($double_auth_enabled !== '1') {
        echo '<p style="color:#666;">⚠️ Este evento no tiene activada la Doble Autenticación.</p>';
        echo '<p style="font-size:12px;">Para activarla, edita el evento y marca la casilla correspondiente en "Funciones Extra del Ticket".</p>';
        return;
    }
    
    // Obtener configuración del evento
    $auth_mode = get_post_meta($event_id, '_eventosapp_ticket_double_auth_mode', true);
    $tipo_fecha = get_post_meta($event_id, '_eventosapp_tipo_fecha', true);
    
    // Obtener días del evento
    $event_days = function_exists('eventosapp_get_event_days') 
        ? eventosapp_get_event_days($event_id) 
        : [];
    
    $log = eventosapp_get_ticket_auth_log($post->ID);
    
    wp_nonce_field('eventosapp_ticket_double_auth_actions', 'eventosapp_ticket_double_auth_nonce');
    
    ?>
    <style>
    .evapp-auth-code-box {
        background: #f9f9f9;
        padding: 12px;
        border-radius: 6px;
        margin: 10px 0;
        border: 2px solid #2F73B5;
    }
    .evapp-auth-day-container {
        background: #ffffff;
        border: 1px solid #ddd;
        border-radius: 6px;
        padding: 10px;
        margin: 8px 0;
    }
    .evapp-auth-day-container:hover {
        border-color: #2F73B5;
        box-shadow: 0 2px 4px rgba(47,115,181,0.1);
    }
    .evapp-auth-day-header {
        font-weight: bold;
        color: #2F73B5;
        margin-bottom: 8px;
        font-size: 13px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .evapp-auth-code-display {
        font-size: 24px;
        font-weight: bold;
        text-align: center;
        letter-spacing: 6px;
        font-family: monospace;
        color: #2F73B5;
        margin: 8px 0;
        padding: 8px;
        background: #f0f4f8;
        border-radius: 4px;
    }
    .evapp-auth-btn {
        width: 100%;
        padding: 6px 8px;
        margin: 4px 0;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
    }
    .evapp-auth-btn-small {
        padding: 4px 8px;
        font-size: 11px;
        width: 48%;
        display: inline-block;
    }
    .evapp-auth-btn-reveal {
        background: #0073aa;
        color: white;
    }
    .evapp-auth-btn-reveal:hover {
        background: #005177;
    }
    .evapp-auth-btn-send {
        background: #28a745;
        color: white;
    }
    .evapp-auth-btn-send:hover {
        background: #218838;
    }
    .evapp-auth-log-table {
        width: 100%;
        font-size: 11px;
        border-collapse: collapse;
        margin-top: 10px;
    }
    .evapp-auth-log-table td {
        border: 1px solid #ddd;
        padding: 4px;
    }
    .evapp-auth-log-table td:first-child {
        background: #f0f0f0;
        font-weight: bold;
        width: 35%;
    }
    .evapp-auth-message {
        padding: 6px;
        border-radius: 4px;
        margin: 8px 0;
        font-size: 11px;
        display: none;
    }
    .evapp-auth-message.success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    .evapp-auth-message.error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    .evapp-single-day-info {
        background: #e7f3ff;
        border: 1px solid #0073aa;
        border-radius: 4px;
        padding: 8px;
        margin: 8px 0;
        font-size: 12px;
        color: #004c73;
    }
    .evapp-btn-row {
        display: flex;
        gap: 4px;
        margin-top: 6px;
    }
    </style>
    
    <?php
    // Determinar modo de visualización
    if ($auth_mode === 'all_days' && count($event_days) > 1) {
        // MODO MULTI-DÍA: Mostrar acordeón con cada día
        ?>
        <div class="evapp-auth-code-box">
            <h4 style="margin-top:0;">Códigos por Día del Evento</h4>
            <div class="evapp-single-day-info">
                <strong>ℹ️ Evento Multi-Día:</strong> Este evento tiene <?php echo count($event_days); ?> días. 
                Cada día tiene su propio código de verificación.
            </div>
            
            <?php
            // Obtener todos los códigos
            $all_codes = function_exists('eventosapp_get_all_ticket_day_codes')
                ? eventosapp_get_all_ticket_day_codes($post->ID, $event_id)
                : [];
            
            foreach ($event_days as $day) {
                $day_data = isset($all_codes[$day]) ? $all_codes[$day] : ['code' => null, 'timestamp' => null];
                $code = $day_data['code'];
                $code_date = $day_data['timestamp'];
                $day_formatted = date_i18n('D, d M Y', strtotime($day));
                ?>
                <div class="evapp-auth-day-container" data-day="<?php echo esc_attr($day); ?>">
                    <div class="evapp-auth-day-header">
                        <span>📅 <?php echo esc_html($day_formatted); ?></span>
                        <span style="font-size:10px;color:#666;font-weight:normal;">
                            <?php echo $day; ?>
                        </span>
                    </div>
                    
                    <?php if ($code): ?>
                        <div class="evapp-auth-code-display evapp-code-display-<?php echo esc_attr($day); ?>">
                            *****
                        </div>
                        <?php if ($code_date): ?>
                            <p style="font-size:10px;color:#666;text-align:center;margin:4px 0;">
                                Generado: <?php echo date_i18n('d/m/Y H:i', $code_date); ?>
                            </p>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="evapp-auth-code-display evapp-code-display-<?php echo esc_attr($day); ?>" style="font-size:14px;color:#999;">
                            Sin código
                        </div>
                    <?php endif; ?>
                    
                    <div class="evapp-btn-row">
                        <button type="button" class="evapp-auth-btn evapp-auth-btn-small evapp-auth-btn-reveal evapp-reveal-code-day" 
                                data-ticket-id="<?php echo absint($post->ID); ?>"
                                data-day="<?php echo esc_attr($day); ?>">
                            👁️ Ver
                        </button>
                        <button type="button" class="evapp-auth-btn evapp-auth-btn-small evapp-auth-btn-send evapp-send-code-day" 
                                data-ticket-id="<?php echo absint($post->ID); ?>"
                                data-day="<?php echo esc_attr($day); ?>">
                            📨 Enviar
                        </button>
                    </div>
                    
                    <div class="evapp-auth-message evapp-day-message-<?php echo esc_attr($day); ?>"></div>
                </div>
                <?php
            }
            ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var revealedCodes = {};
            
            // Revelar código de un día específico
            $('.evapp-reveal-code-day').on('click', function() {
                const $btn = $(this);
                const ticketId = $btn.data('ticket-id');
                const day = $btn.data('day');
                const $display = $('.evapp-code-display-' + day);
                const $msg = $('.evapp-day-message-' + day);
                
                if (revealedCodes[day]) {
                    $display.text('*****');
                    $btn.text('👁️ Ver');
                    revealedCodes[day] = false;
                    return;
                }
                
                $btn.prop('disabled', true).text('...');
                $msg.hide();
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'eventosapp_reveal_auth_code_for_day',
                        ticket_id: ticketId,
                        date: day,
                        nonce: '<?php echo wp_create_nonce("eventosapp_double_auth_reveal"); ?>'
                    },
                    success: function(response) {
                        $btn.prop('disabled', false);
                        
                        if (response.success && response.data.code) {
                            $display.text(response.data.code);
                            $btn.text('🙈 Ocultar');
                            revealedCodes[day] = true;
                        } else {
                            $msg.removeClass('success').addClass('error').text('❌ ' + (response.data || 'Error')).show();
                            $btn.text('👁️ Ver');
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('👁️ Ver');
                        $msg.removeClass('success').addClass('error').text('❌ Error de conexión').show();
                    }
                });
            });
            
            // Enviar código de un día específico
            $('.evapp-send-code-day').on('click', function() {
                const $btn = $(this);
                const ticketId = $btn.data('ticket-id');
                const day = $btn.data('day');
                const dayFormatted = new Date(day).toLocaleDateString('es-ES', { 
                    day: '2-digit', 
                    month: '2-digit', 
                    year: 'numeric' 
                });
                const $msg = $('.evapp-day-message-' + day);
                const $display = $('.evapp-code-display-' + day);
                
                if (!confirm('¿Enviar código del ' + dayFormatted + '? Se generará un nuevo código y se invalidará el anterior para este día.')) {
                    return;
                }
                
                $btn.prop('disabled', true).text('Enviando...');
                $msg.hide();
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'eventosapp_send_auth_code_for_day',
                        ticket_id: ticketId,
                        date: day,
                        nonce: '<?php echo wp_create_nonce("eventosapp_double_auth_single"); ?>'
                    },
                    success: function(response) {
                        $btn.prop('disabled', false).text('📨 Enviar');
                        
                        if (response.success) {
                            $msg.removeClass('error').addClass('success').text('✅ ' + response.data.message).show();
                            
                            // Si el código estaba revelado, actualizar display
                            if (revealedCodes[day] && response.data.code) {
                                $display.text(response.data.code);
                            }
                            
                            // Recargar después de 2 segundos
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $msg.removeClass('success').addClass('error').text('❌ ' + (response.data || 'Error')).show();
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('📨 Enviar');
                        $msg.removeClass('success').addClass('error').text('❌ Error de conexión').show();
                    }
                });
            });
        });
        </script>
        <?php
    } else {
        // MODO DÍA ÚNICO O PRIMER DÍA: Mostrar código único
        $code = eventosapp_get_ticket_auth_code($post->ID);
        $code_date = get_post_meta($post->ID, '_eventosapp_double_auth_code_date', true);
        ?>
        <div class="evapp-auth-code-box">
            <h4 style="margin-top:0;">Código de Verificación</h4>
            
            <?php if ($auth_mode === 'first_day' && count($event_days) > 1): ?>
                <div class="evapp-single-day-info">
                    <strong>ℹ️ Modo Primer Día:</strong> Este código se solicita únicamente durante el primer día del evento. Los días siguientes no requieren segundo factor.
                </div>
            <?php endif; ?>
            
            <?php if ($code): ?>
                <div class="evapp-auth-code-display" id="evapp-code-display">
                    *****
                </div>
                <button type="button" class="evapp-auth-btn evapp-auth-btn-reveal" id="evapp-reveal-code">
                    👁️ Mostrar Código
                </button>
                
                <?php if ($code_date): ?>
                    <p style="font-size:12px;color:#666;text-align:center;margin:5px 0;">
                        Generado: <?php echo date_i18n('d/m/Y H:i', $code_date); ?>
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <p style="text-align:center;color:#666;">No hay código asignado aún.</p>
            <?php endif; ?>
            
            <button type="button" class="evapp-auth-btn evapp-auth-btn-send" id="evapp-send-code">
                📨 Enviar Código por Email
            </button>
            
            <div id="evapp-auth-message" class="evapp-auth-message"></div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var codeRevealed = false;
            
            // Revelar código
            $('#evapp-reveal-code').on('click', function() {
                const $btn = $(this);
                const $display = $('#evapp-code-display');
                const $msg = $('#evapp-auth-message');
                
                if (codeRevealed) {
                    $display.text('*****');
                    $btn.text('👁️ Mostrar Código');
                    codeRevealed = false;
                    return;
                }
                
                $btn.prop('disabled', true).text('Cargando...');
                $msg.hide();
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'eventosapp_reveal_auth_code',
                        ticket_id: <?php echo absint($post->ID); ?>,
                        nonce: '<?php echo wp_create_nonce("eventosapp_double_auth_reveal"); ?>'
                    },
                    success: function(response) {
                        $btn.prop('disabled', false);
                        
                        if (response.success && response.data.code) {
                            $display.text(response.data.code);
                            $btn.text('🙈 Ocultar Código');
                            codeRevealed = true;
                        } else {
                            $msg.removeClass('success').addClass('error').text('❌ ' + (response.data || 'Error al revelar código')).show();
                            $btn.text('👁️ Mostrar Código');
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('👁️ Mostrar Código');
                        $msg.removeClass('success').addClass('error').text('❌ Error de conexión').show();
                    }
                });
            });
            
            // Enviar código
            $('#evapp-send-code').on('click', function() {
                if (!confirm('¿Estás seguro de que deseas enviar el código de verificación al asistente? Se generará un nuevo código y se invalidará el anterior.')) {
                    return;
                }
                
                const $btn = $(this);
                const $msg = $('#evapp-auth-message');
                const $display = $('#evapp-code-display');
                
                $btn.prop('disabled', true).text('Enviando...');
                $msg.hide();
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'eventosapp_send_single_auth_code',
                        ticket_id: <?php echo absint($post->ID); ?>,
                        nonce: '<?php echo wp_create_nonce("eventosapp_double_auth_single"); ?>'
                    },
                    success: function(response) {
                        $btn.prop('disabled', false).text('📨 Enviar Código por Email');
                        
                        if (response.success) {
                            $msg.removeClass('error').addClass('success').text('✅ ' + response.data.message).show();
                            
                            // Si el código estaba revelado, actualizar display
                            if (codeRevealed && response.data.code) {
                                $display.text(response.data.code);
                            }
                            
                            // Recargar página después de 2 segundos para actualizar el log
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $msg.removeClass('success').addClass('error').text('❌ ' + (response.data || 'Error al enviar código')).show();
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('📨 Enviar Código por Email');
                        $msg.removeClass('success').addClass('error').text('❌ Error de conexión').show();
                    }
                });
            });
        });
        </script>
        <?php
    }
    ?>
    
    <div style="margin-top:15px;">
        <h4 style="margin-bottom:8px;">📋 Log de Envíos (Últimos 10)</h4>
        <?php if (empty($log)): ?>
            <p style="color:#666;font-size:12px;">No hay envíos registrados.</p>
        <?php else: ?>
            <table class="evapp-auth-log-table">
                <?php foreach (array_reverse($log) as $entry): ?>
                    <tr>
                        <td>Fecha/Hora:</td>
                        <td><?php echo date_i18n('d/m/Y H:i', $entry['timestamp']); ?></td>
                    </tr>
                    <?php if (isset($entry['day'])): ?>
                    <tr>
                        <td>Día:</td>
                        <td><?php echo date_i18n('d/m/Y', strtotime($entry['day'])); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td>Método:</td>
                        <td>
                            <?php 
                            $method_labels = [
                                'manual' => '🖐️ Manual',
                                'masivo' => '📤 Masivo',
                                'automatico' => '⏰ Automático'
                            ];
                            echo isset($method_labels[$entry['method']]) ? $method_labels[$entry['method']] : $entry['method'];
                            ?>
                        </td>
                    </tr>
                    <?php if ( ! empty($entry['channel']) ): ?>
                    <tr>
                        <td>Canal:</td>
                        <td><?php
                            $channel_labels = [
                                'email' => '✉️ Correo',
                                'whatsapp' => '💬 WhatsApp',
                            ];
                            $channel = sanitize_key((string)$entry['channel']);
                            echo esc_html($channel_labels[$channel] ?? ucfirst($channel));
                        ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td>Enviado por:</td>
                        <td><?php echo esc_html($entry['user_name']); ?></td>
                    </tr>
                    <tr><td colspan="2" style="height:5px;background:#fff;border:none;"></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
