<?php
/**
 * Metabox de Doble Autenticación en Tickets Individuales
 * 
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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
                            ******
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
                            📧 Enviar
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
                    $display.text('******');
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
                        $btn.prop('disabled', false).text('📧 Enviar');
                        
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
                        $btn.prop('disabled', false).text('📧 Enviar');
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
                    <strong>ℹ️ Modo Primer Día:</strong> Este código es válido para todos los días del evento.
                </div>
            <?php endif; ?>
            
            <?php if ($code): ?>
                <div class="evapp-auth-code-display" id="evapp-code-display">
                    ******
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
                📧 Enviar Código por Email
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
                    $display.text('******');
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
                        $btn.prop('disabled', false).text('📧 Enviar Código por Email');
                        
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
                        $btn.prop('disabled', false).text('📧 Enviar Código por Email');
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
        <h4 style="margin-bottom:8px;">📋 Log de Envíos (Últimos 5)</h4>
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
