<?php
// includes/admin/eventosapp-extras-ticket.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Metabox: Funciones Extra del Ticket
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'eventosapp_extras_ticket',
        'Funciones Extra del Ticket',
        'eventosapp_render_metabox_extras_ticket',
        'eventosapp_event',
        'side',
        'default'
    );
});

/**
 * ÚNICA definición (unificada) del render del metabox
 */
/**
 * ÚNICA definición (unificada) del render del metabox
 */
function eventosapp_render_metabox_extras_ticket($post) {
    // Recupera valores (por defecto OFF)
    $pdf         = get_post_meta($post->ID, '_eventosapp_ticket_pdf', true);
    $ics         = get_post_meta($post->ID, '_eventosapp_ticket_ics', true);
    $walleta     = get_post_meta($post->ID, '_eventosapp_ticket_wallet_android', true);
    $walleti     = get_post_meta($post->ID, '_eventosapp_ticket_wallet_apple', true);
    $verify      = get_post_meta($post->ID, '_eventosapp_ticket_verify_email', true);

    // Envío auto para registro público
    $auto_public = get_post_meta($post->ID, '_eventosapp_ticket_auto_email_public', true);

    // Envío auto para registro manual
    $auto_manual = get_post_meta($post->ID, '_eventosapp_ticket_auto_email_manual', true);

    // Flag "usar QR preimpreso"
    $use_preprinted = get_post_meta($post->ID, '_eventosapp_ticket_use_preprinted_qr', true);
    // Flag "usar QR preimpreso SOLO para Networking"
    $use_preprinted_net = get_post_meta($post->ID, '_eventosapp_ticket_use_preprinted_qr_networking', true);

    // Flag "Activar Doble Autenticación"
    $double_auth = get_post_meta($post->ID, '_eventosapp_ticket_double_auth_enabled', true);

    // NUEVO: Flag "Vincular ticket con CPT Asistente"
    $vincular_asistente = get_post_meta($post->ID, '_eventosapp_ticket_vincular_asistente', true);

    wp_nonce_field('eventosapp_extras_ticket_guardar', 'eventosapp_extras_ticket_nonce');
    ?>
    <label>
        <input type="checkbox" name="eventosapp_ticket_pdf" value="1" <?php checked($pdf, '1'); ?>>
        Ticket en PDF Adjunto
    </label><br>
    <label>
        <input type="checkbox" name="eventosapp_ticket_ics" value="1" <?php checked($ics, '1'); ?>>
        Archivo ICS para Calendarios
    </label><br>
    <label>
        <input type="checkbox" name="eventosapp_ticket_wallet_android" value="1" <?php checked($walleta, '1'); ?>>
        Ticket en Wallet Android
    </label><br>
    <label>
        <input type="checkbox" name="eventosapp_ticket_wallet_apple" value="1" <?php checked($walleti, '1'); ?>>
        Ticket en Wallet Apple/iPhone
    </label><br>
    <label>
        <input type="checkbox" name="eventosapp_ticket_verify_email" value="1" <?php checked($verify, '1'); ?>>
        Verificar Correo Electrónico de Asistente
    </label>

    <hr>
    <label>
        <input type="checkbox" name="eventosapp_ticket_auto_email_public" value="1" <?php checked($auto_public, '1'); ?>>
        <strong>Enviar ticket automáticamente en el registro público</strong>
    </label>
    <br>
    <small style="color:#666">Aplica cuando los asistentes se registran ellos mismos desde el formulario público.</small>

    <hr>
    <label>
        <input type="checkbox" name="eventosapp_ticket_auto_email_manual" value="1" <?php checked($auto_manual, '1'); ?>>
        <strong>Enviar ticket automáticamente en el registro manual</strong>
    </label>
    <br>
    <small style="color:#666">Aplica cuando el staff/organizador registra asistentes manualmente desde el panel frontal.</small>

    <hr>
    <label>
        <input type="checkbox" name="eventosapp_ticket_use_preprinted_qr" value="1" <?php checked($use_preprinted, '1'); ?>>
        <strong>Usar QR preimpreso para Check In</strong>
    </label>
    <br>
    <small style="color:#666">Si está activo, el lector QR buscará por el campo numérico para realizar Checkin <code>eventosapp_ticket_preprintedID</code> del ticket.</small>

    <hr>
    <label>
        <input type="checkbox" name="eventosapp_ticket_use_preprinted_qr_networking" value="1" <?php checked($use_preprinted_net, '1'); ?>>
        <strong>Usar QR Preimpreso en Networking</strong>
    </label>
    <br>
    <small style="color:#666">
        Aplica únicamente al lector de <code>[eventosapp_qr_contacto]</code>. Al activarlo, buscará por el campo numérico
        <code>eventosapp_ticket_preprintedID</code> en lugar de <code>eventosapp_ticketID</code>.
    </small>

    <hr>
    <label>
        <input type="checkbox" name="eventosapp_ticket_double_auth_enabled" value="1" <?php checked($double_auth, '1'); ?>>
        <strong>🔐 Activar Doble Autenticación para Check-In</strong>
    </label>
    <br>
    <small style="color:#666">
        Requiere que los asistentes presenten un código de 5 dígitos además del QR para hacer check-in.
        Agrega una capa extra de seguridad contra tickets robados o compartidos.
    </small>

    <hr>
    <label>
        <input type="checkbox" name="eventosapp_ticket_control_pago" value="1" <?php checked(get_post_meta($post->ID, '_eventosapp_ticket_control_pago', true), '1'); ?>>
        <strong>💳 Activar Control de Pago</strong>
    </label>
    <br>
    <small style="color:#666">
        Requiere que los tickets estén en estado "Pagado" para poder realizar check-in.
        Los tickets en estado "No Pagado" mostrarán un mensaje de error al intentar hacer check-in.
    </small>

    <hr>
    <label>
        <input type="checkbox" name="eventosapp_ticket_vincular_asistente" value="1" <?php checked($vincular_asistente, '1'); ?>>
        <strong>👤 Vincular Tickets con CPT Asistentes</strong>
    </label>
    <br>
    <small style="color:#666">
        Al crear un ticket, se asociará automáticamente al perfil del asistente según la cédula.
        Si el asistente no existe, se creará. Los datos del perfil siempre se actualizarán con los del último ticket creado.
    </small>

    <?php
}

/**
 * Guardar metadatos del metabox de extras
 */
add_action('save_post_eventosapp_event', function($post_id){
    // Evitar autosaves y revisiones
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;

    // Verifica capacidades mínimas
    if (!current_user_can('edit_post', $post_id)) return;

    // Nonce del metabox
    if (!isset($_POST['eventosapp_extras_ticket_nonce']) || !wp_verify_nonce($_POST['eventosapp_extras_ticket_nonce'], 'eventosapp_extras_ticket_guardar')) {
        return;
    }

    // Guardar flags existentes
    update_post_meta($post_id, '_eventosapp_ticket_pdf',                isset($_POST['eventosapp_ticket_pdf']) ? '1' : '0');
    update_post_meta($post_id, '_eventosapp_ticket_ics',                isset($_POST['eventosapp_ticket_ics']) ? '1' : '0');
    update_post_meta($post_id, '_eventosapp_ticket_wallet_android',     isset($_POST['eventosapp_ticket_wallet_android']) ? '1' : '0');
    update_post_meta($post_id, '_eventosapp_ticket_wallet_apple',       isset($_POST['eventosapp_ticket_wallet_apple']) ? '1' : '0');
    update_post_meta($post_id, '_eventosapp_ticket_verify_email',       isset($_POST['eventosapp_ticket_verify_email']) ? '1' : '0');

    // Solo para registro público
    update_post_meta($post_id, '_eventosapp_ticket_auto_email_public',  isset($_POST['eventosapp_ticket_auto_email_public']) ? '1' : '0');

    // Solo para registro manual (staff/organizador)
    update_post_meta($post_id, '_eventosapp_ticket_auto_email_manual',  isset($_POST['eventosapp_ticket_auto_email_manual']) ? '1' : '0');

    // Usar QR preimpreso (por evento)
    update_post_meta($post_id, '_eventosapp_ticket_use_preprinted_qr', isset($_POST['eventosapp_ticket_use_preprinted_qr']) ? '1' : '0');

    // Usar QR preimpreso SOLO para networking
    update_post_meta(
        $post_id,
        '_eventosapp_ticket_use_preprinted_qr_networking',
        isset($_POST['eventosapp_ticket_use_preprinted_qr_networking']) ? '1' : '0'
    );

    // Activar Doble Autenticación
    update_post_meta(
        $post_id,
        '_eventosapp_ticket_double_auth_enabled',
        isset($_POST['eventosapp_ticket_double_auth_enabled']) ? '1' : '0'
    );

    // Activar Control de Pago
    update_post_meta(
        $post_id,
        '_eventosapp_ticket_control_pago',
        isset($_POST['eventosapp_ticket_control_pago']) ? '1' : '0'
    );

    // NUEVO: Vincular Tickets con CPT Asistentes
    update_post_meta(
        $post_id,
        '_eventosapp_ticket_vincular_asistente',
        isset($_POST['eventosapp_ticket_vincular_asistente']) ? '1' : '0'
    );

}, 25); // prioridad > 20 para correr después del guardado base


// ========================================
// NUEVO METABOX: Configuración de Doble Autenticación
// ========================================

add_action('add_meta_boxes', function () {
    add_meta_box(
        'eventosapp_double_auth_config',
        '🔐 Configuración de Doble Autenticación',
        'eventosapp_render_metabox_double_auth_config',
        'eventosapp_event',
        'normal',
        'default'
    );
});

/**
 * Render del metabox de configuración de doble autenticación
 */
function eventosapp_render_metabox_double_auth_config($post) {
    $double_auth_enabled = get_post_meta($post->ID, '_eventosapp_ticket_double_auth_enabled', true);
    
    // Solo mostrar si está activada la doble autenticación
    if ($double_auth_enabled !== '1') {
        echo '<p style="color:#666;">⚠️ Para activar este sistema, marca la casilla <strong>"Activar Doble Autenticación para Check-In"</strong> en el panel lateral "Funciones Extra del Ticket".</p>';
        return;
    }
    
    // Recuperar datos guardados
    $scheduled_datetime = get_post_meta($post->ID, '_eventosapp_double_auth_scheduled_datetime', true);
    $scheduled_timezone = get_post_meta($post->ID, '_eventosapp_double_auth_scheduled_timezone', true);
    $auth_mode = get_post_meta($post->ID, '_eventosapp_ticket_double_auth_mode', true);
    $mass_log = eventosapp_get_event_mass_log($post->ID);
    
    // NUEVO: Configuración para días siguientes (eventos multi-día)
    $followup_amount = get_post_meta($post->ID, '_eventosapp_double_auth_followup_amount', true);
    $followup_unit = get_post_meta($post->ID, '_eventosapp_double_auth_followup_unit', true);
    $followup_time = get_post_meta($post->ID, '_eventosapp_double_auth_followup_time', true);
    
    // Obtener tipo de fecha del evento
    $tipo_fecha = get_post_meta($post->ID, '_eventosapp_tipo_fecha', true) ?: 'unica';
    
    // Valores por defecto
    if (!$auth_mode) {
        $auth_mode = 'first_day';
    }
    
    if (!$followup_amount) {
        $followup_amount = 1;
    }
    
    if (!$followup_unit) {
        $followup_unit = 'days';
    }
    
    if (!$followup_time) {
        $followup_time = '06:00';
    }
    
    // Timezone por defecto
    if (!$scheduled_timezone) {
        $scheduled_timezone = wp_timezone_string();
    }
    
    wp_nonce_field('eventosapp_double_auth_config_save', 'eventosapp_double_auth_config_nonce');
    
    ?>
    <style>
    .evapp-double-auth-section {
        background: #f9f9f9;
        padding: 15px;
        margin: 15px 0;
        border-radius: 6px;
        border-left: 4px solid #2F73B5;
    }
    .evapp-double-auth-section h4 {
        margin-top: 0;
        color: #2F73B5;
    }
    .evapp-form-row {
        margin: 15px 0;
    }
    .evapp-form-row label {
        display: block;
        font-weight: bold;
        margin-bottom: 5px;
    }
    .evapp-form-row input,
    .evapp-form-row select {
        width: 100%;
        max-width: 400px;
    }
    .evapp-form-row input[type="radio"] {
        width: auto;
        margin-right: 8px;
        vertical-align: middle;
    }
    .evapp-radio-option {
        margin: 12px 0;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 6px;
        background: #fff;
        transition: all 0.2s ease;
    }
    .evapp-radio-option:hover {
        background: #f5f5f5;
        border-color: #2F73B5;
    }
    .evapp-radio-option label {
        display: flex;
        align-items: flex-start;
        font-weight: normal;
        margin: 0;
        cursor: pointer;
    }
    .evapp-radio-option input[type="radio"] {
        margin-top: 2px;
        flex-shrink: 0;
    }
    .evapp-radio-label-content {
        flex: 1;
    }
    .evapp-radio-label-content strong {
        display: block;
        font-size: 14px;
        color: #1d2327;
        margin-bottom: 4px;
    }
    .evapp-radio-description {
        display: block;
        color: #666;
        font-size: 13px;
        line-height: 1.4;
        margin-top: 4px;
    }
    .evapp-followup-config {
        background: #e7f3ff;
        border: 2px solid #0073aa;
        border-radius: 6px;
        padding: 15px;
        margin-top: 15px;
        display: none;
    }
    .evapp-followup-config.active {
        display: block;
    }
    .evapp-followup-config h5 {
        margin: 0 0 10px 0;
        color: #0073aa;
        font-size: 14px;
    }
    .evapp-inline-fields {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
        margin: 10px 0;
    }
    .evapp-inline-field {
        display: flex;
        flex-direction: column;
    }
    .evapp-inline-field label {
        font-size: 12px;
        color: #666;
        margin-bottom: 4px;
    }
    .evapp-inline-field input[type="number"],
    .evapp-inline-field input[type="time"],
    .evapp-inline-field select {
        padding: 6px 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
    }
    .evapp-inline-field input[type="number"] {
        width: 80px;
    }
    .evapp-inline-field input[type="time"] {
        width: 120px;
    }
    .evapp-inline-field select {
        width: 150px;
    }
    .evapp-btn-test {
        background: #0073aa;
        color: white;
        border: none;
        padding: 8px 15px;
        border-radius: 4px;
        cursor: pointer;
    }
    .evapp-btn-test:hover {
        background: #005177;
    }
    .evapp-btn-mass {
        background: #d9534f;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 4px;
        cursor: pointer;
        font-weight: bold;
    }
    .evapp-btn-mass:hover {
        background: #c9302c;
    }
    .evapp-btn-regenerate {
        background: #ff6b6b;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 4px;
        cursor: pointer;
        font-weight: bold;
    }
    .evapp-btn-regenerate:hover {
        background: #ee5a5a;
    }
    .evapp-btn-regenerate:disabled,
    .evapp-btn-mass:disabled {
        background: #ccc;
        cursor: not-allowed;
    }
    .evapp-log-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }
    .evapp-log-table th,
    .evapp-log-table td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: left;
    }
    .evapp-log-table th {
        background: #2F73B5;
        color: white;
    }
    .evapp-ajax-message {
        padding: 10px;
        margin: 10px 0;
        border-radius: 4px;
        display: none;
    }
    .evapp-ajax-message.success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    .evapp-ajax-message.error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    </style>
    
    <div class="evapp-double-auth-section">
        <h4>⏰ Envío Programado Automático</h4>
        <p>Programa la fecha y hora exacta en la que se enviarán los códigos de verificación a todos los tickets emitidos.</p>
        
        <div class="evapp-form-row">
            <label for="evapp-scheduled-datetime">Fecha y Hora de Envío:</label>
            <input 
                type="datetime-local" 
                id="evapp-scheduled-datetime" 
                name="eventosapp_double_auth_scheduled_datetime"
                value="<?php 
                if ($scheduled_datetime) {
                    // Convertir el timestamp a la zona horaria correcta
                    try {
                        $dt = new DateTime('@' . $scheduled_datetime);
                        $dt->setTimezone(new DateTimeZone($scheduled_timezone));
                        echo $dt->format('Y-m-d\TH:i');
                    } catch (Exception $e) {
                        echo '';
                    }
                } else {
                    echo '';
                }
                ?>"
            />
        </div>
        
        <div class="evapp-form-row">
            <label for="evapp-scheduled-timezone">Zona Horaria:</label>
            <select id="evapp-scheduled-timezone" name="eventosapp_double_auth_scheduled_timezone">
                <?php
                $timezones = timezone_identifiers_list();
                foreach ($timezones as $tz) {
                    printf(
                        '<option value="%s"%s>%s</option>',
                        esc_attr($tz),
                        selected($scheduled_timezone, $tz, false),
                        esc_html($tz)
                    );
                }
                ?>
            </select>
        </div>
        
        <p style="color:#666;font-size:13px;">
            <strong>Nota:</strong> El envío programado se ejecutará automáticamente en la fecha/hora especificada.
            Guarda los cambios del evento para activar la programación.
        </p>
    </div>
    
    <?php if ($tipo_fecha !== 'unica'): ?>
    <div class="evapp-double-auth-section">
        <h4>📅 Configuración Multi-Día</h4>
        <p style="margin-bottom:15px;">Este evento tiene múltiples fechas. Configura cómo funcionará la doble autenticación:</p>
        
        <div class="evapp-radio-option">
            <label>
                <input type="radio" name="eventosapp_ticket_double_auth_mode" value="first_day" <?php checked($auth_mode, 'first_day'); ?> class="evapp-auth-mode-radio">
                <div class="evapp-radio-label-content">
                    <strong>Solo Primer Día</strong>
                    <span class="evapp-radio-description">
                        Se genera y envía un único código que sirve para hacer check-in en el primer día del evento.
                        Los días siguientes no requieren código de verificación.
                    </span>
                </div>
            </label>
        </div>
        
        <div class="evapp-radio-option">
            <label>
                <input type="radio" name="eventosapp_ticket_double_auth_mode" value="all_days" <?php checked($auth_mode, 'all_days'); ?> class="evapp-auth-mode-radio">
                <div class="evapp-radio-label-content">
                    <strong>Todos los Días</strong>
                    <span class="evapp-radio-description">
                        Se genera y envía un código diferente para cada día del evento. 
                        El primer código se envía en la fecha programada arriba.
                        Los códigos de días siguientes se programan automáticamente según la configuración abajo.
                    </span>
                </div>
            </label>
        </div>
        
        <!-- Configuración para días siguientes -->
        <div class="evapp-followup-config <?php echo ($auth_mode === 'all_days') ? 'active' : ''; ?>" id="evapp-followup-config">
            <h5>⏰ Programación de Códigos para Días Siguientes (desde día 2 en adelante)</h5>
            <p style="font-size:13px;color:#004c73;margin:8px 0;">
                Configura cuándo se deben enviar los códigos para los días siguientes del evento:
            </p>
            
            <div class="evapp-inline-fields">
                <div class="evapp-inline-field">
                    <label for="evapp-followup-amount">Enviar</label>
                    <input 
                        type="number" 
                        id="evapp-followup-amount" 
                        name="eventosapp_double_auth_followup_amount" 
                        value="<?php echo esc_attr($followup_amount); ?>" 
                        min="1" 
                        max="999"
                    />
                </div>
                
                <div class="evapp-inline-field">
                    <label for="evapp-followup-unit">Unidad</label>
                    <select id="evapp-followup-unit" name="eventosapp_double_auth_followup_unit">
                        <option value="hours" <?php selected($followup_unit, 'hours'); ?>>Horas antes</option>
                        <option value="days" <?php selected($followup_unit, 'days'); ?>>Días antes</option>
                        <option value="weeks" <?php selected($followup_unit, 'weeks'); ?>>Semanas antes</option>
                    </select>
                </div>
                
                <div class="evapp-inline-field">
                    <label for="evapp-followup-time">A las</label>
                    <input 
                        type="time" 
                        id="evapp-followup-time" 
                        name="eventosapp_double_auth_followup_time" 
                        value="<?php echo esc_attr($followup_time); ?>"
                    />
                </div>
            </div>
            
            <p style="font-size:12px;color:#666;margin:10px 0 0 0;line-height:1.5;">
                <strong>Ejemplo:</strong> Si configuras "1 día antes a las 06:00", los asistentes recibirán 
                el código del día 2 un día antes del día 2 a las 6:00 AM, el código del día 3 un día antes 
                del día 3 a las 6:00 AM, y así sucesivamente.
            </p>
        </div>
        
        <p style="color:#d9534f;font-size:13px;margin-top:15px;background:#fff3cd;padding:10px;border-radius:4px;border-left:3px solid #d9534f;">
            <strong>⚠️ Importante:</strong> Si cambias esta configuración después de haber enviado códigos, 
            deberás usar "Regenerar y Enviar Códigos" para actualizar todos los tickets.
        </p>
    </div>
    <?php endif; ?>
    
    <div class="evapp-double-auth-section">
        <h4>🧪 Prueba Manual</h4>
        <p>Envía un código de verificación a un ticket específico para probar el sistema.</p>
        
        <div class="evapp-form-row">
            <label for="evapp-test-ticket-id">ID del Ticket (ej: tkA9fL2...):</label>
            <input 
                type="text" 
                id="evapp-test-ticket-id" 
                placeholder="tkXXXXXXXXXXXX"
                style="max-width:300px;"
            />
            <button type="button" id="evapp-test-send-btn" class="evapp-btn-test">
                Enviar Código de Prueba
            </button>
        </div>
        
        <div id="evapp-test-message" class="evapp-ajax-message"></div>
    </div>
    
    <div class="evapp-double-auth-section">
        <h4>📤 Envío Masivo</h4>
        <p>Envía códigos de verificación a <strong>todos los tickets emitidos</strong> de este evento.</p>
        
        <button type="button" id="evapp-mass-send-btn" class="evapp-btn-mass">
            Enviar Códigos a Todos los Tickets Ahora
        </button>
        
        <div id="evapp-mass-message" class="evapp-ajax-message"></div>
        
        <hr style="margin: 20px 0;">
        
        <h4 style="color: #d9534f;">🔄 Regenerar y Enviar Nuevos Códigos</h4>
        <p style="color:#d9534f;"><strong>⚠️ ATENCIÓN:</strong> Esta acción <strong>BORRARÁ</strong> todos los códigos actuales y generará nuevos códigos para todos los tickets.</p>
        <p>Usa esta opción cuando necesites invalidar todos los códigos existentes por seguridad.</p>
        
        <button type="button" id="evapp-regenerate-send-btn" class="evapp-btn-regenerate">
            Regenerar y Enviar Códigos a Todos los Tickets
        </button>
        
        <div id="evapp-regenerate-message" class="evapp-ajax-message"></div>
    </div>
    
    <div class="evapp-double-auth-section">
        <h4>📊 Log de Envíos Masivos (Últimos 3)</h4>
        <?php if (empty($mass_log)): ?>
            <p style="color:#666;">No hay envíos masivos registrados aún.</p>
        <?php else: ?>
            <table class="evapp-log-table">
                <thead>
                    <tr>
                        <th>Fecha/Hora</th>
                        <th>Total Tickets</th>
                        <th>Exitosos</th>
                        <th>Fallidos</th>
                        <th>Usuario</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($mass_log) as $entry): ?>
                        <tr>
                            <td><?php echo date_i18n('d/m/Y H:i', $entry['timestamp']); ?></td>
                            <td><?php echo absint($entry['total']); ?></td>
                            <td style="color:#28a745;"><?php echo absint($entry['success']); ?></td>
                            <td style="color:#d9534f;"><?php echo absint($entry['failed']); ?></td>
                            <td><?php echo esc_html($entry['user_name']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
<?php endif; ?>
    </div>
    
    <!-- NUEVA SECCIÓN: Reprogramar Envío Completo -->
    <div class="evapp-double-auth-section">
        <h4 style="color:#d9534f;">🔄 Reprogramar Envío Completo</h4>
        <p style="color:#666;font-size:12px;margin-bottom:10px;line-height:1.5;">
            Si necesitas reprogramar el envío de códigos (por ejemplo, si cambiaste las fechas del evento), 
            puedes limpiar el registro de días enviados. Esto permitirá que se vuelvan a enviar los códigos 
            según la programación configurada.
        </p>
        
        <button type="button" class="button" id="evapp-clear-sent-days" style="background:#dc3545;color:#fff;border-color:#dc3545;">
            🗑️ Limpiar Registro de Días Enviados
        </button>
        
        <div id="evapp-clear-message" class="evapp-ajax-message"></div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Control de visibilidad de configuración de días siguientes
        $('.evapp-auth-mode-radio').on('change', function() {
            const selectedMode = $('input[name="eventosapp_ticket_double_auth_mode"]:checked').val();
            
            if (selectedMode === 'all_days') {
                $('#evapp-followup-config').addClass('active');
            } else {
                $('#evapp-followup-config').removeClass('active');
            }
        });
        
        // Envío de prueba
        $('#evapp-test-send-btn').on('click', function() {
            const ticketId = $('#evapp-test-ticket-id').val().trim();
            const $btn = $(this);
            const $msg = $('#evapp-test-message');
            
            if (!ticketId) {
                $msg.removeClass('success').addClass('error').text('Por favor ingresa un ID de ticket').show();
                return;
            }
            
            $btn.prop('disabled', true).text('Enviando...');
            $msg.hide();
            
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'eventosapp_test_send_auth_code',
                    ticket_id: ticketId,
                    nonce: '<?php echo wp_create_nonce("eventosapp_double_auth_test"); ?>'
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('Enviar Código de Prueba');
                    
                    if (response.success) {
                        $msg.removeClass('error').addClass('success').text('✅ ' + response.data.message).show();
                        $('#evapp-test-ticket-id').val('');
                    } else {
                        $msg.removeClass('success').addClass('error').text('❌ ' + (response.data || 'Error desconocido')).show();
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Enviar Código de Prueba');
                    $msg.removeClass('success').addClass('error').text('❌ Error de conexión').show();
                }
            });
        });
        
        // Envío masivo
        $('#evapp-mass-send-btn').on('click', function() {
            if (!confirm('¿Estás seguro de que deseas enviar códigos a TODOS los tickets de este evento?')) {
                return;
            }
            
            const $btn = $(this);
            const $msg = $('#evapp-mass-message');
            
            $btn.prop('disabled', true).text('Enviando...');
            $msg.hide();
            
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'eventosapp_mass_send_auth_codes',
                    event_id: <?php echo absint($post->ID); ?>,
                    nonce: '<?php echo wp_create_nonce("eventosapp_double_auth_mass"); ?>'
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('Enviar Códigos a Todos los Tickets Ahora');
                    
                    if (response.success) {
                        $msg.removeClass('error').addClass('success').text('✅ ' + response.data.message).show();
                        // Recargar página después de 2 segundos para actualizar el log
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $msg.removeClass('success').addClass('error').text('❌ ' + (response.data || 'Error desconocido')).show();
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Enviar Códigos a Todos los Tickets Ahora');
                    $msg.removeClass('success').addClass('error').text('❌ Error de conexión').show();
                }
            });
        });
        
        // Regenerar y enviar códigos
        $('#evapp-regenerate-send-btn').on('click', function() {
            if (!confirm('⚠️ ADVERTENCIA: Esta acción BORRARÁ todos los códigos actuales y generará nuevos códigos para TODOS los tickets.\n\n¿Estás completamente seguro de que deseas continuar?')) {
                return;
            }
            
            // Confirmación doble
            if (!confirm('Esta es una acción irreversible. Los códigos antiguos dejarán de funcionar.\n\n¿Confirmas que deseas regenerar TODOS los códigos?')) {
                return;
            }
            
            const $btn = $(this);
            const $msg = $('#evapp-regenerate-message');
            
            $btn.prop('disabled', true).text('Regenerando y enviando...');
            $msg.hide();
            
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'eventosapp_regenerate_and_send_auth_codes',
                    event_id: <?php echo absint($post->ID); ?>,
                    nonce: '<?php echo wp_create_nonce("eventosapp_double_auth_regenerate"); ?>'
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('Regenerar y Enviar Códigos a Todos los Tickets');
                    
                    if (response.success) {
                        $msg.removeClass('error').addClass('success').text('✅ ' + response.data.message).show();
                        // Recargar página después de 2 segundos para actualizar el log
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $msg.removeClass('success').addClass('error').text('❌ ' + (response.data || 'Error desconocido')).show();
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Regenerar y Enviar Códigos a Todos los Tickets');
                    $msg.removeClass('success').addClass('error').text('❌ Error de conexión').show();
                }
            });
        });
        
        // NUEVO: Limpiar registro de días enviados
        $('#evapp-clear-sent-days').on('click', function() {
            if (!confirm('⚠️ ADVERTENCIA: Esta acción limpiará el registro de días enviados.\n\nEsto significa que el sistema volverá a enviar los códigos según la programación configurada, incluso para días que ya se enviaron anteriormente.\n\n¿Estás completamente seguro?')) {
                return;
            }
            
            const $btn = $(this);
            const $msg = $('#evapp-clear-message');
            
            $btn.prop('disabled', true).text('Limpiando...');
            $msg.hide();
            
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'eventosapp_clear_sent_days',
                    event_id: <?php echo absint($post->ID); ?>,
                    nonce: '<?php echo wp_create_nonce("eventosapp_clear_sent_days"); ?>'
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('🗑️ Limpiar Registro de Días Enviados');
                    
                    if (response.success) {
                        $msg.removeClass('error').addClass('success').text('✅ ' + response.data.message).show();
                        
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $msg.removeClass('error').addClass('success').text('❌ ' + (response.data || 'Error desconocido')).show();
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('🗑️ Limpiar Registro de Días Enviados');
                    $msg.removeClass('error').addClass('success').text('❌ Error de conexión').show();
                }
            });
        });
    });
    </script>
    <?php
}

/**
 * Guardar configuración de doble autenticación
 */
add_action('save_post_eventosapp_event', function($post_id){
    // Evitar autosaves y revisiones
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    
    // Verifica capacidades mínimas
    if (!current_user_can('edit_post', $post_id)) return;
    
    // Nonce del metabox de doble auth
    if (!isset($_POST['eventosapp_double_auth_config_nonce']) || !wp_verify_nonce($_POST['eventosapp_double_auth_config_nonce'], 'eventosapp_double_auth_config_save')) {
        return;
    }
    
    // Guardar modo de autenticación (first_day o all_days)
    $auth_mode = isset($_POST['eventosapp_ticket_double_auth_mode']) ? sanitize_text_field($_POST['eventosapp_ticket_double_auth_mode']) : 'first_day';
    if (in_array($auth_mode, ['first_day', 'all_days'])) {
        update_post_meta($post_id, '_eventosapp_ticket_double_auth_mode', $auth_mode);
    }
    
    // NUEVO: Guardar configuración de días siguientes (solo para modo all_days)
    if ($auth_mode === 'all_days') {
        $followup_amount = isset($_POST['eventosapp_double_auth_followup_amount']) ? absint($_POST['eventosapp_double_auth_followup_amount']) : 1;
        $followup_unit = isset($_POST['eventosapp_double_auth_followup_unit']) ? sanitize_text_field($_POST['eventosapp_double_auth_followup_unit']) : 'days';
        $followup_time = isset($_POST['eventosapp_double_auth_followup_time']) ? sanitize_text_field($_POST['eventosapp_double_auth_followup_time']) : '06:00';
        
        // Validar unidad
        if (!in_array($followup_unit, ['hours', 'days', 'weeks'])) {
            $followup_unit = 'days';
        }
        
        // Validar cantidad
        if ($followup_amount < 1) {
            $followup_amount = 1;
        }
        
        // Validar formato de hora (HH:MM)
        if (!preg_match('/^\d{2}:\d{2}$/', $followup_time)) {
            $followup_time = '06:00';
        }
        
        update_post_meta($post_id, '_eventosapp_double_auth_followup_amount', $followup_amount);
        update_post_meta($post_id, '_eventosapp_double_auth_followup_unit', $followup_unit);
        update_post_meta($post_id, '_eventosapp_double_auth_followup_time', $followup_time);
    } else {
        // Si no es modo all_days, eliminar estos campos
        delete_post_meta($post_id, '_eventosapp_double_auth_followup_amount');
        delete_post_meta($post_id, '_eventosapp_double_auth_followup_unit');
        delete_post_meta($post_id, '_eventosapp_double_auth_followup_time');
    }
    
    // Guardar fecha/hora programada
    if (isset($_POST['eventosapp_double_auth_scheduled_datetime']) && $_POST['eventosapp_double_auth_scheduled_datetime']) {
        $datetime_local = sanitize_text_field($_POST['eventosapp_double_auth_scheduled_datetime']);
        $timezone = isset($_POST['eventosapp_double_auth_scheduled_timezone']) ? sanitize_text_field($_POST['eventosapp_double_auth_scheduled_timezone']) : wp_timezone_string();
        
        // Convertir a timestamp
        try {
            $dt = new DateTime($datetime_local, new DateTimeZone($timezone));
            $timestamp = $dt->getTimestamp();
            
            update_post_meta($post_id, '_eventosapp_double_auth_scheduled_datetime', $timestamp);
            update_post_meta($post_id, '_eventosapp_double_auth_scheduled_timezone', $timezone);
            
            // Programar el envío
            if (function_exists('eventosapp_schedule_auth_codes_send')) {
                eventosapp_schedule_auth_codes_send($post_id);
            }
        } catch (Exception $e) {
            // Error en la fecha, no guardar
        }
    } else {
        // Si no hay fecha, cancelar cualquier programación
        delete_post_meta($post_id, '_eventosapp_double_auth_scheduled_datetime');
        delete_post_meta($post_id, '_eventosapp_double_auth_scheduled_timezone');
        wp_clear_scheduled_hook('eventosapp_auto_send_auth_codes', [$post_id]);
    }
    
}, 30); // prioridad > 25 para correr después del guardado de extras

/**
 * AJAX: Limpiar registro de días enviados
 */
add_action('wp_ajax_eventosapp_clear_sent_days', function() {
    check_ajax_referer('eventosapp_clear_sent_days', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Permisos insuficientes');
    }
    
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    
    if (!$event_id) {
        wp_send_json_error('ID de evento no proporcionado');
    }
    
    // Limpiar el registro
    if (function_exists('eventosapp_clear_sent_days')) {
        eventosapp_clear_sent_days($event_id);
        
        // También cancelar todos los crons programados para reprogramar desde cero
        wp_clear_scheduled_hook('eventosapp_send_auth_codes_scheduled', [$event_id]);
        
        // Cancelar crons de días específicos
        if (function_exists('eventosapp_get_event_days')) {
            $days = eventosapp_get_event_days($event_id);
            foreach ($days as $day) {
                $timestamp = wp_next_scheduled('eventosapp_send_auth_codes_for_specific_day', [$event_id, $day]);
                if ($timestamp) {
                    wp_unschedule_event($timestamp, 'eventosapp_send_auth_codes_for_specific_day', [$event_id, $day]);
                }
            }
        }
        
        // Reprogramar desde cero
        if (function_exists('eventosapp_schedule_auth_codes_send')) {
            eventosapp_schedule_auth_codes_send($event_id);
        }
        
        wp_send_json_success([
            'message' => 'Registro limpiado exitosamente. Los envíos se han reprogramado desde cero.'
        ]);
    } else {
        wp_send_json_error('Función no disponible');
    }
});
