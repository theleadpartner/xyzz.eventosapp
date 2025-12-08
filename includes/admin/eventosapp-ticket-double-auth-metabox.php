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
    // Obtener el evento del ticket (CORREGIDO: usar _eventosapp_ticket_evento_id)
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
    $current_day = get_post_meta($post->ID, '_eventosapp_double_auth_current_day', true);
    
    // Obtener datos del código
    $code = eventosapp_get_ticket_auth_code($post->ID);
    $code_date = get_post_meta($post->ID, '_eventosapp_double_auth_code_date', true);
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
    .evapp-auth-code-display {
        font-size: 28px;
        font-weight: bold;
        text-align: center;
        letter-spacing: 8px;
        font-family: monospace;
        color: #2F73B5;
        margin: 10px 0;
    }
    .evapp-auth-btn {
        width: 100%;
        padding: 8px;
        margin: 5px 0;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 13px;
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
        font-size: 12px;
        border-collapse: collapse;
        margin-top: 10px;
    }
    .evapp-auth-log-table td {
        border: 1px solid #ddd;
        padding: 5px;
    }
    .evapp-auth-log-table td:first-child {
        background: #f0f0f0;
        font-weight: bold;
        width: 40%;
    }
    .evapp-auth-message {
        padding: 8px;
        border-radius: 4px;
        margin: 10px 0;
        font-size: 13px;
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
    </style>
    
    <div class="evapp-auth-code-box">
        <h4 style="margin-top:0;">Código de Verificación</h4>
        
        <?php if ($auth_mode === 'all_days' && $tipo_fecha !== 'unica' && $current_day): ?>
            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:8px;margin:8px 0;font-size:12px;color:#856404;">
                <strong>📅 Código válido para:</strong> <?php echo date_i18n('d/m/Y', strtotime($current_day)); ?>
            </div>
        <?php elseif ($auth_mode === 'all_days' && $tipo_fecha !== 'unica'): ?>
            <div style="background:#e7f3ff;border:1px solid #0073aa;border-radius:4px;padding:8px;margin:8px 0;font-size:12px;color:#004c73;">
                <strong>ℹ️ Evento Multi-Día:</strong> Se genera un código diferente para cada día.
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
    
    <div style="margin-top:15px;">
        <h4 style="margin-bottom:8px;">📋 Log de Envíos (Últimos 3)</h4>
        <?php if (empty($log)): ?>
            <p style="color:#666;font-size:12px;">No hay envíos registrados.</p>
        <?php else: ?>
            <table class="evapp-auth-log-table">
                <?php foreach (array_reverse($log) as $entry): ?>
                    <tr>
                        <td>Fecha/Hora:</td>
                        <td><?php echo date_i18n('d/m/Y H:i', $entry['timestamp']); ?></td>
                    </tr>
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
