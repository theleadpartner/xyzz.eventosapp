<?php
/**
 * Shortcode de Check-In con QR y Doble Autenticación
 * [qr_checkin_doble_auth]
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'qr_checkin_doble_auth', 'eventosapp_render_qr_double_auth_shortcode' );

function eventosapp_render_qr_double_auth_shortcode() {
    // Requerir feature
    if ( function_exists( 'eventosapp_require_feature' ) ) {
        eventosapp_require_feature( 'qr_double_auth' );
    }
    
    // Verificar evento activo
    if ( function_exists( 'eventosapp_require_active_event' ) ) {
        eventosapp_require_active_event();
    }
    
    $event_id = function_exists( 'eventosapp_get_active_event' ) ? eventosapp_get_active_event() : 0;
    
    if ( ! $event_id ) {
        return '<p>No hay evento activo.</p>';
    }
    
    // Verificar si el evento tiene doble autenticación activada
    $double_auth_enabled = get_post_meta( $event_id, '_eventosapp_ticket_double_auth_enabled', true );
    
    if ( $double_auth_enabled !== '1' ) {
        return '<div class="notice notice-warning" style="padding:12px;margin:10px 0;">
            <strong>Advertencia:</strong> Este evento no tiene activada la Doble Autenticación. 
            Por favor actívala en la configuración del evento o usa el Check-In QR estándar.
        </div>';
    }
    
    ob_start();
    
    // Barra de evento activo
    if ( function_exists( 'eventosapp_active_event_bar' ) ) {
        eventosapp_active_event_bar();
    }
    
    ?>
    <style>
    .evapp-qr-double-auth-container {
        max-width: 800px;
        margin: 20px auto;
        padding: 20px;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .evapp-qr-double-auth-container h2 {
        margin-top: 0;
        color: #2F73B5;
        border-bottom: 2px solid #2F73B5;
        padding-bottom: 10px;
    }
    #evapp-qr-reader {
        width: 100%;
        max-width: 500px;
        margin: 20px auto;
        border: 3px solid #2F73B5;
        border-radius: 8px;
    }
    .evapp-ticket-info {
        background: #f9f9f9;
        padding: 15px;
        border-radius: 6px;
        margin: 15px 0;
        border-left: 4px solid #2F73B5;
    }
    .evapp-ticket-info h3 {
        margin-top: 0;
        color: #333;
    }
    .evapp-auth-code-input {
        margin: 20px 0;
        padding: 20px;
        background: #fffbea;
        border: 2px dashed #f0ad4e;
        border-radius: 6px;
    }
    .evapp-auth-code-input label {
        display: block;
        font-weight: bold;
        margin-bottom: 10px;
        color: #333;
    }
    .evapp-auth-code-input input[type="text"] {
        width: 100%;
        max-width: 200px;
        font-size: 24px;
        letter-spacing: 8px;
        text-align: center;
        padding: 12px;
        border: 2px solid #ddd;
        border-radius: 4px;
        font-family: monospace;
    }
    .evapp-auth-code-input input[type="text"]:focus {
        outline: none;
        border-color: #2F73B5;
        box-shadow: 0 0 5px rgba(47,115,181,0.3);
    }
    .evapp-verify-btn {
        background: #28a745;
        color: white;
        border: none;
        padding: 12px 30px;
        font-size: 16px;
        border-radius: 4px;
        cursor: pointer;
        margin-top: 15px;
        transition: background 0.3s;
    }
    .evapp-verify-btn:hover {
        background: #218838;
    }
    .evapp-verify-btn:disabled {
        background: #ccc;
        cursor: not-allowed;
    }
    .evapp-cancel-btn {
        background: #6c757d;
        color: white;
        border: none;
        padding: 10px 20px;
        font-size: 14px;
        border-radius: 4px;
        cursor: pointer;
        margin-left: 10px;
    }
    .evapp-cancel-btn:hover {
        background: #5a6268;
    }
    .evapp-message {
        padding: 15px;
        border-radius: 6px;
        margin: 15px 0;
        font-weight: bold;
    }
    .evapp-message.success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    .evapp-message.error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    .evapp-message.info {
        background: #d1ecf1;
        color: #0c5460;
        border: 1px solid #bee5eb;
    }
    .evapp-hidden {
        display: none;
    }
    .evapp-loading {
        text-align: center;
        padding: 20px;
        color: #666;
    }
    .evapp-spinner {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(0,0,0,.1);
        border-radius: 50%;
        border-top-color: #2F73B5;
        animation: evapp-spin 1s ease-in-out infinite;
    }
    @keyframes evapp-spin {
        to { transform: rotate(360deg); }
    }
    </style>
    
    <div class="evapp-qr-double-auth-container">
        <h2>🔐 Check-In con QR y Doble Autenticación</h2>
        
        <div id="evapp-message-container"></div>
        
        <!-- Lector de QR -->
        <div id="evapp-scanner-section">
            <p><strong>Instrucciones:</strong></p>
            <ol>
                <li>Escanea el código QR del ticket del asistente</li>
                <li>El sistema te pedirá el código de verificación de 5 dígitos</li>
                <li>El asistente debe proporcionarte su código personal</li>
                <li>Ingresa el código y presiona "Verificar y Aprobar Check-In"</li>
            </ol>
            <div id="evapp-qr-reader"></div>
            <p style="text-align:center;color:#666;margin-top:10px;">
                <small>El lector se activará automáticamente al cargar esta página</small>
            </p>
        </div>
        
        <!-- Información del ticket + input de código -->
        <div id="evapp-verification-section" class="evapp-hidden">
            <div class="evapp-ticket-info">
                <h3>📋 Información del Ticket</h3>
                <div id="evapp-ticket-details"></div>
            </div>
            
            <div class="evapp-auth-code-input">
                <label for="evapp-auth-code">
                    🔑 Solicita al asistente su código de verificación:
                </label>
                <input 
                    type="text" 
                    id="evapp-auth-code" 
                    maxlength="5" 
                    placeholder="00000"
                    autocomplete="off"
                    pattern="[0-9]*"
                    inputmode="numeric"
                />
                <div style="margin-top:10px;">
                    <button type="button" id="evapp-verify-btn" class="evapp-verify-btn">
                        Verificar y Aprobar Check-In
                    </button>
                    <button type="button" id="evapp-cancel-btn" class="evapp-cancel-btn">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
        
        <div id="evapp-loading" class="evapp-loading evapp-hidden">
            <div class="evapp-spinner"></div>
            <p>Procesando...</p>
        </div>
    </div>
    
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
    (function($) {
        let html5QrCode;
        let currentTicketId = null;
        let currentTicketData = null;
        
        const $messageContainer = $('#evapp-message-container');
        const $scannerSection = $('#evapp-scanner-section');
        const $verificationSection = $('#evapp-verification-section');
        const $ticketDetails = $('#evapp-ticket-details');
        const $authCodeInput = $('#evapp-auth-code');
        const $verifyBtn = $('#evapp-verify-btn');
        const $cancelBtn = $('#evapp-cancel-btn');
        const $loading = $('#evapp-loading');
        
        // Inicializar lector QR
        function initQrScanner() {
            html5QrCode = new Html5Qrcode("evapp-qr-reader");
            
            html5QrCode.start(
                { facingMode: "environment" },
                { fps: 10, qrbox: { width: 250, height: 250 } },
                onScanSuccess,
                onScanFailure
            ).catch(err => {
                showMessage('Error al iniciar la cámara: ' + err, 'error');
            });
        }
        
        // Cuando se escanea un QR exitosamente
        function onScanSuccess(decodedText, decodedResult) {
            // Detener el scanner
            html5QrCode.stop();
            
            // Buscar el ticket
            searchTicket(decodedText);
        }
        
        function onScanFailure(error) {
            // Ignorar errores de no detección
        }
        
        // Buscar ticket por QR
        function searchTicket(qrCode) {
            showLoading(true);
            
            $.ajax({
                url: ajaxurl || '<?php echo admin_url("admin-ajax.php"); ?>',
                method: 'POST',
                data: {
                    action: 'eventosapp_search_ticket_by_qr',
                    qr_code: qrCode,
                    event_id: <?php echo absint($event_id); ?>,
                    nonce: '<?php echo wp_create_nonce("eventosapp_qr_search"); ?>'
                },
                success: function(response) {
                    showLoading(false);
                    
                    if (response.success && response.data.ticket) {
                        currentTicketId = response.data.ticket.id;
                        currentTicketData = response.data.ticket;
                        showVerificationSection(response.data.ticket);
                    } else {
                        showMessage(response.data || 'Ticket no encontrado', 'error');
                        setTimeout(restartScanner, 3000);
                    }
                },
                error: function() {
                    showLoading(false);
                    showMessage('Error de conexión', 'error');
                    setTimeout(restartScanner, 3000);
                }
            });
        }
        
        // Mostrar sección de verificación
        function showVerificationSection(ticket) {
            $scannerSection.addClass('evapp-hidden');
            
            // Construir HTML de detalles
            let html = '<p><strong>Nombre:</strong> ' + ticket.nombre + '</p>';
            html += '<p><strong>Email:</strong> ' + ticket.email + '</p>';
            html += '<p><strong>Ticket ID:</strong> ' + ticket.ticket_id + '</p>';
            
            if (ticket.localidad) {
                html += '<p><strong>Localidad:</strong> ' + ticket.localidad + '</p>';
            }
            
            if (ticket.checked_in) {
                html += '<p style="color:#d9534f;"><strong>⚠️ ADVERTENCIA:</strong> Este ticket ya hizo check-in el ' + ticket.checkin_date + '</p>';
            }
            
            $ticketDetails.html(html);
            $verificationSection.removeClass('evapp-hidden');
            $authCodeInput.val('').focus();
        }
        
        // Verificar código y hacer check-in
        $verifyBtn.on('click', function() {
            const code = $authCodeInput.val().trim();
            
            if (code.length !== 5) {
                showMessage('El código debe tener 5 dígitos', 'error');
                return;
            }
            
            if (!currentTicketId) {
                showMessage('Error: No hay ticket seleccionado', 'error');
                return;
            }
            
            showLoading(true);
            
            $.ajax({
                url: ajaxurl || '<?php echo admin_url("admin-ajax.php"); ?>',
                method: 'POST',
                data: {
                    action: 'eventosapp_verify_and_checkin',
                    ticket_id: currentTicketId,
                    auth_code: code,
                    event_id: <?php echo absint($event_id); ?>,
                    nonce: '<?php echo wp_create_nonce("eventosapp_verify_checkin"); ?>'
                },
                success: function(response) {
                    showLoading(false);
                    
                    if (response.success) {
                        showMessage('✅ ' + response.data.message, 'success');
                        setTimeout(function() {
                            resetAndRestart();
                        }, 3000);
                    } else {
                        showMessage('❌ ' + (response.data || 'Código incorrecto'), 'error');
                        $authCodeInput.val('').focus();
                    }
                },
                error: function() {
                    showLoading(false);
                    showMessage('Error de conexión', 'error');
                }
            });
        });
        
        // Cancelar y volver a escanear
        $cancelBtn.on('click', function() {
            resetAndRestart();
        });
        
        // Permitir Enter en el input
        $authCodeInput.on('keypress', function(e) {
            if (e.which === 13) {
                $verifyBtn.click();
            }
        });
        
        // Solo permitir números
        $authCodeInput.on('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
        
        // Funciones auxiliares
        function showMessage(text, type) {
            const msgClass = 'evapp-message ' + type;
            $messageContainer.html('<div class="' + msgClass + '">' + text + '</div>');
            
            setTimeout(function() {
                $messageContainer.empty();
            }, 5000);
        }
        
        function showLoading(show) {
            if (show) {
                $loading.removeClass('evapp-hidden');
                $verifyBtn.prop('disabled', true);
            } else {
                $loading.addClass('evapp-hidden');
                $verifyBtn.prop('disabled', false);
            }
        }
        
        function restartScanner() {
            initQrScanner();
        }
        
        function resetAndRestart() {
            currentTicketId = null;
            currentTicketData = null;
            $verificationSection.addClass('evapp-hidden');
            $messageContainer.empty();
            $scannerSection.removeClass('evapp-hidden');
            restartScanner();
        }
        
        // Inicializar al cargar
        $(document).ready(function() {
            initQrScanner();
        });
        
    })(jQuery);
    </script>
    <?php
    
    return ob_get_clean();
}

// ========================================
// AJAX: Buscar ticket por QR
// ========================================

add_action( 'wp_ajax_eventosapp_search_ticket_by_qr', function() {
    check_ajax_referer( 'eventosapp_qr_search', 'nonce' );
    
    $qr_code  = isset( $_POST['qr_code'] ) ? sanitize_text_field( $_POST['qr_code'] ) : '';
    $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
    
    if ( ! $qr_code || ! $event_id ) {
        wp_send_json_error( 'Datos incompletos' );
    }
    
    // Verificar si el evento usa QR preimpreso
    $use_preprinted = get_post_meta( $event_id, '_eventosapp_ticket_use_preprinted_qr', true ) === '1';
    $meta_key = $use_preprinted ? 'eventosapp_ticket_preprintedID' : 'eventosapp_ticketID';
    
    // Buscar ticket
    $tickets = get_posts([
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'   => $meta_key,
                'value' => $qr_code,
            ],
            [
                'key'   => '_eventosapp_event_id',
                'value' => $event_id,
            ],
        ],
    ]);
    
    if ( empty( $tickets ) ) {
        wp_send_json_error( 'Ticket no encontrado o no pertenece a este evento' );
    }
    
    $ticket = $tickets[0];
    $ticket_id = $ticket->ID;
    
    // Obtener datos
    $nombre       = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true );
    $apellido     = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );
    $email        = get_post_meta( $ticket_id, '_eventosapp_asistente_email', true );
    $localidad    = get_post_meta( $ticket_id, '_eventosapp_asistente_localidad', true );
    $ticket_public_id = get_post_meta( $ticket_id, 'eventosapp_ticketID', true );
    $checked_in   = get_post_meta( $ticket_id, '_eventosapp_checkin', true ) === '1';
    $checkin_date = get_post_meta( $ticket_id, '_eventosapp_checkin_date', true );
    
    if ( $checkin_date ) {
        $checkin_date = date_i18n( 'd/m/Y H:i', strtotime( $checkin_date ) );
    }
    
    wp_send_json_success([
        'ticket' => [
            'id'          => $ticket_id,
            'nombre'      => trim( $nombre . ' ' . $apellido ),
            'email'       => $email,
            'ticket_id'   => $ticket_public_id,
            'localidad'   => $localidad,
            'checked_in'  => $checked_in,
            'checkin_date' => $checkin_date,
        ],
    ]);
});

// ========================================
// AJAX: Verificar código y hacer check-in
// ========================================

add_action( 'wp_ajax_eventosapp_verify_and_checkin', function() {
    check_ajax_referer( 'eventosapp_verify_checkin', 'nonce' );
    
    $ticket_id = isset( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;
    $auth_code = isset( $_POST['auth_code'] ) ? sanitize_text_field( $_POST['auth_code'] ) : '';
    $event_id  = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
    
    if ( ! $ticket_id || ! $auth_code || ! $event_id ) {
        wp_send_json_error( 'Datos incompletos' );
    }
    
    // Verificar que el ticket pertenece al evento
    $ticket_event = get_post_meta( $ticket_id, '_eventosapp_event_id', true );
    if ( absint( $ticket_event ) !== $event_id ) {
        wp_send_json_error( 'El ticket no pertenece a este evento' );
    }
    
    // Validar código
    if ( ! function_exists( 'eventosapp_validate_auth_code' ) ) {
        wp_send_json_error( 'Sistema de autenticación no disponible' );
    }
    
    $is_valid = eventosapp_validate_auth_code( $ticket_id, $auth_code );
    
    if ( ! $is_valid ) {
        wp_send_json_error( 'Código de verificación incorrecto. Por favor verifica e intenta nuevamente.' );
    }
    
    // Código válido, proceder con check-in
    $already_checked = get_post_meta( $ticket_id, '_eventosapp_checkin', true ) === '1';
    
    update_post_meta( $ticket_id, '_eventosapp_checkin', '1' );
    update_post_meta( $ticket_id, '_eventosapp_checkin_date', current_time( 'mysql' ) );
    update_post_meta( $ticket_id, '_eventosapp_checkin_user', get_current_user_id() );
    
    $nombre   = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true );
    $apellido = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );
    
    if ( $already_checked ) {
        $message = sprintf( 'Check-in confirmado para %s %s (ya había ingresado anteriormente)', $nombre, $apellido );
    } else {
        $message = sprintf( 'Check-in exitoso para %s %s', $nombre, $apellido );
    }
    
    wp_send_json_success([
        'message' => $message,
        'already_checked' => $already_checked,
    ]);
});
