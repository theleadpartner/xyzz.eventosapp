<?php
/**
 * EventosApp - WhatsApp Flows
 *
 * Capa de control del envío masivo. El módulo original se conserva sin cambios
 * en eventosapp-whatsapp-flows-core.php y se carga al final de este archivo.
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! defined('EVENTOSAPP_WHATSAPP_FLOW_TEMPLATE_BINDING_VERSION') ) {
    define('EVENTOSAPP_WHATSAPP_FLOW_TEMPLATE_BINDING_VERSION', '2026.08.21.1');
}

function eventosapp_wa_flow_bulk_control_config() {
    $config = apply_filters('eventosapp_wa_flow_bulk_control_config', [
        'batch_size'          => 5,
        'min_delay_ms'        => 350,
        'target_cycle_ms'     => 3500,
        'max_delay_ms'        => 1500,
        'busy_retry_ms'       => 1200,
        'lock_timeout_seconds'=> 120,
    ]);
    $config = is_array($config) ? $config : [];

    $batch = min(10, max(1, absint($config['batch_size'] ?? 5)));
    $min   = min(5000, max(250, absint($config['min_delay_ms'] ?? 350)));
    $max   = min(10000, max($min, absint($config['max_delay_ms'] ?? 1500)));

    return [
        'batch_size'           => $batch,
        'min_delay_ms'         => $min,
        'target_cycle_ms'      => min(15000, max($min, absint($config['target_cycle_ms'] ?? 3500))),
        'max_delay_ms'         => $max,
        'busy_retry_ms'        => min(10000, max(500, absint($config['busy_retry_ms'] ?? 1200))),
        'lock_timeout_seconds' => min(300, max(30, absint($config['lock_timeout_seconds'] ?? 120))),
    ];
}

function eventosapp_wa_flow_bulk_segment_key($segment_id) {
    return 'evapp_whatsapp_flow_segment_' . sanitize_key((string) $segment_id);
}

function eventosapp_wa_flow_bulk_stop_key($segment_id) {
    return 'evapp_whatsapp_flow_stop_' . sanitize_key((string) $segment_id);
}

function eventosapp_wa_flow_bulk_lock_key($segment_id) {
    return 'evapp_whatsapp_flow_lock_' . sanitize_key((string) $segment_id);
}

function eventosapp_wa_flow_bulk_is_stopped($segment_id) {
    return (string) get_option(eventosapp_wa_flow_bulk_stop_key($segment_id), '') === '1';
}

function eventosapp_wa_flow_bulk_acquire_lock($segment_id, $timeout) {
    $key = eventosapp_wa_flow_bulk_lock_key($segment_id);
    $token = wp_generate_uuid4();
    $value = ['token' => $token, 'created_at' => time(), 'user_id' => get_current_user_id()];

    if ( add_option($key, $value, '', 'no') ) {
        return $token;
    }

    $current = get_option($key, []);
    $created = is_array($current) ? absint($current['created_at'] ?? 0) : 0;
    if ( ! $created || (time() - $created) > $timeout ) {
        delete_option($key);
        if ( add_option($key, $value, '', 'no') ) {
            return $token;
        }
    }

    return '';
}

function eventosapp_wa_flow_bulk_release_lock($segment_id, $token) {
    $key = eventosapp_wa_flow_bulk_lock_key($segment_id);
    $current = get_option($key, []);
    if ( is_array($current) && hash_equals((string)($current['token'] ?? ''), (string) $token) ) {
        delete_option($key);
    }
}

/**
 * Se ejecuta antes del callback histórico del módulo. Si todo está correcto,
 * deja que el procesador original continúe; si hay detención o concurrencia,
 * responde antes de que se produzcan nuevos envíos.
 */
function eventosapp_wa_flow_bulk_preflight() {
    if ( ! current_user_can('manage_options') ) {
        return;
    }

    check_ajax_referer('eventosapp_whatsapp_flow_process');

    $segment_id = isset($_POST['segment_id']) ? sanitize_key((string) wp_unslash($_POST['segment_id'])) : '';
    $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
    if ( $segment_id === '' ) {
        return;
    }

    $segment_key = eventosapp_wa_flow_bulk_segment_key($segment_id);
    $segment = get_option($segment_key);
    if ( ! is_array($segment) ) {
        return;
    }

    if ( eventosapp_wa_flow_bulk_is_stopped($segment_id) || sanitize_key((string)($segment['campaign_status'] ?? '')) === 'stopped' ) {
        wp_send_json_success([
            'processed' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => 0,
            'next_offset' => $offset, 'stopped' => true, 'busy' => false,
            'logs' => [['message' => 'El envío fue detenido. No se procesarán más tickets.', 'type' => 'warning']],
        ]);
    }

    $config = eventosapp_wa_flow_bulk_control_config();
    $token = eventosapp_wa_flow_bulk_acquire_lock($segment_id, $config['lock_timeout_seconds']);
    if ( $token === '' ) {
        wp_send_json_success([
            'processed' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => 0,
            'next_offset' => $offset, 'stopped' => false, 'busy' => true,
            'retry_after_ms' => $config['busy_retry_ms'],
            'logs' => [['message' => 'Otro lote sigue en ejecución. Se reintentará automáticamente.', 'type' => 'info']],
        ]);
    }

    register_shutdown_function(static function() use ($segment_id, $token) {
        eventosapp_wa_flow_bulk_release_lock($segment_id, $token);
    });

    $_POST['batch_size'] = $config['batch_size'];
    $segment['campaign_status'] = 'running';
    $segment['campaign_last_offset'] = $offset;
    $segment['updated_at'] = current_time('mysql');
    update_option($segment_key, $segment, false);
}
add_action('wp_ajax_eventosapp_whatsapp_flow_process_batch', 'eventosapp_wa_flow_bulk_preflight', 1);

function eventosapp_wa_flow_bulk_stop() {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error('No autorizado');
    }
    check_ajax_referer('eventosapp_whatsapp_flow_process');

    $segment_id = isset($_POST['segment_id']) ? sanitize_key((string) wp_unslash($_POST['segment_id'])) : '';
    $segment_key = eventosapp_wa_flow_bulk_segment_key($segment_id);
    $segment = $segment_id !== '' ? get_option($segment_key) : [];

    if ( ! is_array($segment) ) {
        wp_send_json_error('Segmento no encontrado');
    }

    update_option(eventosapp_wa_flow_bulk_stop_key($segment_id), '1', false);
    $segment['campaign_status'] = 'stopped';
    $segment['campaign_stopped_at'] = current_time('mysql');
    $segment['campaign_stopped_by'] = get_current_user_id();
    $segment['updated_at'] = current_time('mysql');
    update_option($segment_key, $segment, false);

    if ( function_exists('eventosapp_whatsapp_flows_add_activity') ) {
        eventosapp_whatsapp_flows_add_activity('flow_masivo_detenido', [
            'segment_id' => $segment_id,
            'event_id' => absint($segment['event_id'] ?? 0),
            'flow_post_id' => absint($segment['flow_post_id'] ?? 0),
            'offset' => absint($segment['campaign_last_offset'] ?? 0),
            'total' => absint($segment['total'] ?? 0),
            'user_id' => get_current_user_id(),
        ]);
    }

    wp_send_json_success([
        'stopped' => true,
        'message' => 'Envío detenido. El lote que ya estaba en proceso puede terminar, pero no se iniciarán nuevos lotes.',
    ]);
}
add_action('wp_ajax_eventosapp_whatsapp_flow_stop_campaign', 'eventosapp_wa_flow_bulk_stop', 1);

/**
 * Conserva todas las funciones, acciones y pantallas existentes.
 */
require_once __DIR__ . '/eventosapp-whatsapp-flows-core.php';

/**
 * Configuración WhatsApp unificada por evento (metaboxes + programación Flow).
 */
require_once __DIR__ . '/eventosapp-whatsapp-event-metaboxes.php';
require_once __DIR__ . '/eventosapp-whatsapp-event-metaboxes-runtime.php';

/**
 * Obtiene el Meta Flow ID canónico del Flow local seleccionado.
 * La relación vive en el CPT interno del módulo de Flows y no debe depender
 * de un valor escrito manualmente en el builder de plantillas.
 */
function eventosapp_wa_flow_template_resolve_meta_flow_id($flow_post_id) {
    $flow_post_id = absint($flow_post_id);
    if ( ! $flow_post_id ) {
        return '';
    }

    if ( function_exists('eventosapp_whatsapp_flows_get_flow_config') ) {
        $config = eventosapp_whatsapp_flows_get_flow_config($flow_post_id);
        if ( ! empty($config) && is_array($config) ) {
            return preg_replace('/\D+/', '', (string)($config['meta_flow_id'] ?? ''));
        }
    }

    if ( defined('EVENTOSAPP_WHATSAPP_FLOWS_POST_TYPE') && get_post_type($flow_post_id) !== EVENTOSAPP_WHATSAPP_FLOWS_POST_TYPE ) {
        return '';
    }

    return preg_replace('/\D+/', '', (string) get_post_meta($flow_post_id, '_eventosapp_wa_flow_meta_id', true));
}

/**
 * Antes del save handler histórico, fuerza que meta_flow_id corresponda al
 * Flow local recibido. Esto protege el vínculo aunque JavaScript esté
 * deshabilitado, el navegador conserve un valor antiguo o el POST sea alterado.
 */
function eventosapp_wa_flow_template_bind_selected_meta_flow_id() {
    if ( ! current_user_can('manage_options') || ! isset($_POST['flow_post_id']) ) {
        return;
    }

    $flow_post_id = absint(wp_unslash($_POST['flow_post_id']));
    $_POST['meta_flow_id'] = eventosapp_wa_flow_template_resolve_meta_flow_id($flow_post_id);
}
add_action('admin_post_eventosapp_whatsapp_flow_template_save', 'eventosapp_wa_flow_template_bind_selected_meta_flow_id', 1);

/**
 * Mantiene sincronizado visualmente el Meta Flow ID del builder unificado.
 * El campo queda de solo lectura porque su fuente de verdad es Flow local.
 */
function eventosapp_wa_flow_template_render_meta_id_binding() {
    if ( ! current_user_can('manage_options') ) {
        return;
    }

    $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
    $engine = isset($_GET['engine']) ? sanitize_key((string) wp_unslash($_GET['engine'])) : '';
    $builder_type = isset($_GET['builder_type']) ? sanitize_key((string) wp_unslash($_GET['builder_type'])) : '';

    if ( $page !== 'eventosapp_whatsapp_templates' || ($engine !== 'flow' && $builder_type !== 'flow') ) {
        return;
    }

    $flow_meta_ids = [];
    if ( function_exists('eventosapp_whatsapp_flows_get_all_for_select') ) {
        foreach ( eventosapp_whatsapp_flows_get_all_for_select() as $flow_id => $flow ) {
            $flow_id = absint($flow_id);
            if ( ! $flow_id ) {
                continue;
            }
            $flow_meta_ids[(string) $flow_id] = preg_replace('/\D+/', '', (string)($flow['meta_flow_id'] ?? ''));
        }
    }
    ?>
    <script>
    (function(){
        const flowMetaIds = <?php echo wp_json_encode($flow_meta_ids, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?> || {};

        function initFlowTemplateBinding(){
            const actionInput = document.querySelector('input[name="action"][value="eventosapp_whatsapp_flow_template_save"]');
            const form = actionInput ? actionInput.closest('form') : null;
            if (!form) return;

            const flowSelect = form.querySelector('select[name="flow_post_id"]');
            const metaFlowInput = form.querySelector('input[name="meta_flow_id"]');
            if (!flowSelect || !metaFlowInput) return;

            metaFlowInput.readOnly = true;
            metaFlowInput.setAttribute('aria-readonly', 'true');
            metaFlowInput.setAttribute('autocomplete', 'off');
            metaFlowInput.title = 'Se completa automáticamente con el Meta Flow ID del Flow local seleccionado.';

            function syncMetaFlowId(){
                const flowId = String(flowSelect.value || '0');
                const nextMetaFlowId = flowMetaIds[flowId] || '';
                if (metaFlowInput.value !== nextMetaFlowId) {
                    metaFlowInput.value = nextMetaFlowId;
                    metaFlowInput.dispatchEvent(new Event('input', {bubbles:true}));
                }
            }

            flowSelect.addEventListener('change', syncMetaFlowId);
            syncMetaFlowId();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initFlowTemplateBinding, {once:true});
        } else {
            initFlowTemplateBinding();
        }
    })();
    </script>
    <?php
}
add_action('admin_footer', 'eventosapp_wa_flow_template_render_meta_id_binding', 98);

function eventosapp_wa_flow_bulk_render_controls() {
    $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
    $step = isset($_GET['step']) ? absint($_GET['step']) : 1;
    $segment_id = isset($_GET['segment_id']) ? sanitize_key((string) wp_unslash($_GET['segment_id'])) : '';

    if ( $page !== 'eventosapp_whatsapp_flows_campaign' || $step !== 3 || $segment_id === '' ) {
        return;
    }

    $segment = get_option(eventosapp_wa_flow_bulk_segment_key($segment_id), []);
    if ( ! is_array($segment) ) {
        return;
    }

    $ticket_ids = isset($segment['ticket_ids']) && is_array($segment['ticket_ids']) ? $segment['ticket_ids'] : [];
    $total = count($ticket_ids);
    $config = eventosapp_wa_flow_bulk_control_config();
    $stopped = eventosapp_wa_flow_bulk_is_stopped($segment_id)
        || sanitize_key((string)($segment['campaign_status'] ?? '')) === 'stopped';
    ?>
    <style>
        .eventosapp-wa-flows #stopProcess{border-color:#b32d2e;color:#b32d2e;margin-left:6px}
        .eventosapp-wa-flows .evapp-flow-runtime-note{margin:12px 0;padding:10px 12px;border-left:4px solid #3454f4;background:#eef2ff}
    </style>
    <script>
    jQuery(function($){
        const segmentId=<?php echo wp_json_encode($segment_id); ?>;
        const total=<?php echo (int) $total; ?>;
        const batchSize=<?php echo (int) $config['batch_size']; ?>;
        const minDelay=<?php echo (int) $config['min_delay_ms']; ?>;
        const targetCycle=<?php echo (int) $config['target_cycle_ms']; ?>;
        const maxDelay=<?php echo (int) $config['max_delay_ms']; ?>;
        const busyDelay=<?php echo (int) $config['busy_retry_ms']; ?>;
        const nonce='<?php echo esc_js(wp_create_nonce('eventosapp_whatsapp_flow_process')); ?>';
        let offset=0,processed=0,sent=0,skipped=0,errors=0;
        let running=false,pauseRequested=false,stopping=false,stopped=<?php echo $stopped ? 'true' : 'false'; ?>;
        let timer=null,currentRequest=null;

        const $start=$('#startProcess'),$pause=$('#pauseProcess'),$status=$('#processStatus'),$final=$('#finalActions');
        if(!$('#stopProcess').length){$pause.after('<button type="button" class="button" id="stopProcess" style="display:none">Detener envío</button>')}
        const $stop=$('#stopProcess');
        $start.off('click');$pause.off('click');$stop.off('click');

        if(!$('.evapp-flow-runtime-note').length){
            $('.evapp-flow-progress-box>p').last().after(
                '<div class="evapp-flow-runtime-note"><strong>Protección activa:</strong> lotes secuenciales de hasta '+batchSize+
                ' tickets, pausa adaptativa de '+(minDelay/1000).toFixed(2)+' a '+(maxDelay/1000).toFixed(2)+
                ' segundos y bloqueo contra solicitudes simultáneas.</div>'
            );
        }

        function log(message,type='info'){
            const $log=$('#logContainer'),time=new Date().toLocaleTimeString(),safe=$('<div>').text(message||'').html();
            $log.append('<div class="evapp-wa-log-entry evapp-wa-log-'+type+'">['+time+'] '+safe+'</div>');
            $log.scrollTop($log[0].scrollHeight);
        }
        function clearTimer(){if(timer){clearTimeout(timer);timer=null}}
        function update(){
            const pct=total?Math.min(100,Math.round(processed/total*100)):0;
            $('#progressBar').css('width',pct+'%');$('#progressText').text(pct+'%');
            $('#processedCount').text(processed);$('#sentCount').text(sent);
            $('#skippedCount').text(skipped);$('#errorCount').text(errors);
            if(!stopped&&processed>=total){complete()}
        }
        function complete(){
            running=false;clearTimer();$start.hide();$pause.hide();$stop.hide();$final.show();
            $status.html('<div class="evapp-success"><strong>✅ Envío completado</strong><p>Se procesaron todos los tickets.</p></div>');
        }
        function paused(){
            running=false;pauseRequested=false;clearTimer();$pause.hide();
            $start.show().prop('disabled',false).text('Continuar desde el ticket '+(offset+1));
            $stop.show().prop('disabled',false);
            $status.html('<div class="evapp-warning"><strong>Proceso pausado.</strong> Puedes continuar o detener definitivamente.</div>');
        }
        function stoppedUI(message){
            running=false;pauseRequested=false;stopping=false;stopped=true;clearTimer();
            $start.hide();$pause.hide();$stop.hide();$final.show();
            $status.html('<div class="evapp-warning"><strong>⛔ Envío detenido.</strong><p>'+$('<div>').text(message).html()+'</p></div>');
        }
        function schedule(started,requested=0){
            if(!running||stopped||processed>=total){update();return}
            let delay=Math.max(minDelay,Math.min(maxDelay,targetCycle-(Date.now()-started)));
            delay=Math.max(delay,requested);
            $status.html('<div class="evapp-info"><strong>Procesando...</strong> Siguiente lote en '+(delay/1000).toFixed(1)+' segundos.</div>');
            timer=setTimeout(processBatch,delay);
        }
        function processBatch(){
            clearTimer();
            if(!running||stopped||processed>=total){update();return}
            const started=Date.now();
            currentRequest=$.ajax({
                url:ajaxurl,type:'POST',timeout:120000,
                data:{action:'eventosapp_whatsapp_flow_process_batch',segment_id:segmentId,offset:offset,batch_size:batchSize,_wpnonce:nonce},
                success(response){
                    currentRequest=null;
                    if(!response.success){
                        running=false;$pause.hide();$stop.hide();$start.show().prop('disabled',false);
                        const msg=response.data||'Error desconocido';$status.html('<div class="notice notice-error"><p>'+$('<div>').text(msg).html()+'</p></div>');log(msg,'error');return;
                    }
                    const data=response.data||{};
                    (data.logs||[]).forEach(item=>log(item.message,item.type));
                    if(data.busy){schedule(started,parseInt(data.retry_after_ms||busyDelay,10));return}
                    processed+=parseInt(data.processed||0,10);sent+=parseInt(data.sent||0,10);
                    skipped+=parseInt(data.skipped||0,10);errors+=parseInt(data.errors||0,10);
                    offset=parseInt(data.next_offset||(offset+batchSize),10);update();
                    if(data.stopped||stopped||stopping){stoppedUI('No se iniciarán nuevos lotes.');return}
                    if(pauseRequested&&processed<total){paused();log('Pausa confirmada al finalizar el lote.','warning');return}
                    if(running&&processed<total){schedule(started,parseInt(data.errors||0,10)>0?1000:0)}
                },
                error(xhr,status,error){
                    currentRequest=null;if(stopped||stopping){return}
                    running=false;$pause.hide();$stop.hide();$start.show().prop('disabled',false);
                    const msg=error||status||'Error de conexión';$status.html('<div class="notice notice-error"><p>'+$('<div>').text(msg).html()+'</p></div>');log(msg,'error');
                }
            });
        }

        $start.on('click.evappFlowControl',function(){
            if(!total||stopped){return}
            running=true;pauseRequested=false;stopping=false;$start.hide();$pause.show();$stop.show().prop('disabled',false);
            $status.html('<div class="evapp-info"><strong>Procesando...</strong> No cierres esta ventana.</div>');
            processBatch();
        });
        $pause.on('click.evappFlowControl',function(){
            running=false;pauseRequested=true;clearTimer();$pause.hide();$stop.show();
            if(currentRequest){$start.show().prop('disabled',true).text('Esperando cierre del lote actual...')}
            else{paused()}
        });
        $stop.on('click.evappFlowControl',function(){
            if(stopped||stopping||!confirm('¿Detener definitivamente? El lote actual puede terminar, pero no se iniciarán nuevos lotes.')){return}
            stopping=true;running=false;pauseRequested=false;clearTimer();$stop.prop('disabled',true);$pause.prop('disabled',true);
            $.post(ajaxurl,{action:'eventosapp_whatsapp_flow_stop_campaign',segment_id:segmentId,_wpnonce:nonce})
            .done(response=>{
                if(response&&response.success){stoppedUI((response.data||{}).message||'Envío detenido.');log('Envío detenido.','warning')}
                else{stopping=false;$stop.prop('disabled',false);$start.show().prop('disabled',false);log('No se pudo detener el envío.','error')}
            }).fail(()=>{stopping=false;$stop.prop('disabled',false);$start.show().prop('disabled',false);log('Error al detener el envío.','error')});
        });

        $(window).off('beforeunload.evappFlowControl').on('beforeunload.evappFlowControl',function(){
            if(running||stopping){return 'Hay un envío en ejecución.'}
        });

        if(stopped){stoppedUI('Este segmento fue detenido y no puede reanudarse.')}
    });
    </script>
    <?php
}
add_action('admin_footer', 'eventosapp_wa_flow_bulk_render_controls', 99);
