<?php
/**
 * EventosApp - Runtime de configuración WhatsApp por evento.
 *
 * Complementa el hub de metaboxes con dos garantías de experiencia/ejecución:
 * 1) resincroniza una tarea Flow si cambia la zona horaria del evento aunque
 *    la fecha/hora local visible no cambie;
 * 2) mantiene el mapeo visual Plantilla Flow -> Flow/header al editar el evento.
 *
 * Ruta: includes/admin/eventosapp-whatsapp-event-metaboxes-runtime.php
 *
 * @package EventosApp
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! defined('EVENTOSAPP_WHATSAPP_EVENT_METABOXES_VERSION') ) {
    define('EVENTOSAPP_WHATSAPP_EVENT_METABOXES_VERSION', '2026.08.19.2');
}

/**
 * Si la zona horaria cambia, fuerza una única recreación de la tarea pendiente.
 *
 * La programación conserva fecha/hora como valores locales del evento. Una
 * tarea ya creada guarda también la zona usada en su payload. Si ambas zonas
 * dejan de coincidir, marcamos la firma histórica como obsoleta y reutilizamos
 * el save handler central para cancelar/recrear la tarea con el UTC correcto.
 */
function eventosapp_whatsapp_event_hub_resync_flow_timezone($post_id, $post) {
    $post_id = absint($post_id);
    if ( ! $post_id || ! function_exists('eventosapp_whatsapp_event_hub_is_event') || ! eventosapp_whatsapp_event_hub_is_event($post_id) ) {
        return;
    }
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
        return;
    }
    if ( wp_is_post_revision($post_id) ) {
        return;
    }
    if ( ! isset($_POST['eventosapp_whatsapp_event_hub_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventosapp_whatsapp_event_hub_nonce'])), 'eventosapp_whatsapp_event_hub_save') ) {
        return;
    }
    if ( ! current_user_can('edit_post', $post_id) ) {
        return;
    }

    $schedule = get_post_meta($post_id, EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META, true);
    $schedule = is_array($schedule) ? $schedule : [];
    if ( empty($schedule['enabled']) || empty($schedule['queue_task_id']) || ! function_exists('eventosapp_task_queue_get') ) {
        return;
    }

    $task_id = absint($schedule['queue_task_id']);
    $task = eventosapp_task_queue_get($task_id);
    if ( ! is_array($task) ) {
        return;
    }

    $terminal = function_exists('eventosapp_task_queue_terminal_statuses')
        ? eventosapp_task_queue_terminal_statuses()
        : ['cancelled','completed','expired','failed','archived'];
    if ( in_array((string)($task['status'] ?? ''), $terminal, true) ) {
        return;
    }

    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    $task_timezone = sanitize_text_field((string)($payload['event_timezone'] ?? ''));
    $event_timezone = function_exists('eventosapp_whatsapp_event_hub_timezone_name')
        ? eventosapp_whatsapp_event_hub_timezone_name($post_id)
        : '';

    if ( $event_timezone === '' || $task_timezone === '' || $event_timezone === $task_timezone ) {
        return;
    }

    // Evita que el save handler considere equivalente la tarea creada con la
    // zona anterior. El propio handler cancela y crea la nueva tarea de forma
    // trazable en Cola y Tareas.
    $schedule['signature'] = 'timezone_changed_' . wp_generate_uuid4();
    $schedule['last_error'] = '';
    update_post_meta($post_id, EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META, $schedule);

    if ( function_exists('eventosapp_whatsapp_event_hub_save_flow_schedule') ) {
        eventosapp_whatsapp_event_hub_save_flow_schedule($post_id, $post);
    }
}
add_action('save_post_eventosapp_event', 'eventosapp_whatsapp_event_hub_resync_flow_timezone', 190, 2);
add_action('save_post_eventosapp_events', 'eventosapp_whatsapp_event_hub_resync_flow_timezone', 190, 2);

/**
 * Mantiene la ayuda de mapeo del metabox antiguo: al escoger una plantilla
 * Flow, selecciona su Flow local asociado y propone su header si el evento aún
 * no tiene uno personalizado. El servidor sigue siendo la autoridad final.
 */
function eventosapp_whatsapp_event_hub_runtime_footer() {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( ! $screen || ! in_array((string)$screen->post_type, function_exists('eventosapp_whatsapp_event_hub_post_types') ? eventosapp_whatsapp_event_hub_post_types() : ['eventosapp_event','eventosapp_events'], true) ) {
        return;
    }
    ?>
    <script>
    jQuery(function($){
        const $template=$('#evapp_eventosapp_whatsapp_satisfaction_flow_template_id');
        const $flow=$('#evapp_eventosapp_whatsapp_satisfaction_flow_post_id');
        const $header=$('#evapp_eventosapp_whatsapp_satisfaction_flow_header_img');
        if(!$template.length){return;}
        $template.off('change.evappWaEventHubMap').on('change.evappWaEventHubMap',function(){
            const $option=$(this).find('option:selected');
            const flowPostId=String($option.data('flow-post-id')||'');
            const headerUrl=String($option.data('header-image-url')||'');
            if(flowPostId&&$flow.find('option[value="'+flowPostId+'"]:not(:disabled)').length){
                $flow.val(flowPostId).trigger('change');
            }
            if(headerUrl&&$header.length&&!String($header.val()||'').trim()){
                $header.val(headerUrl).trigger('change');
                const $preview=$header.closest('[data-evapp-media-field]').find('[data-evapp-media-preview]').first();
                if($preview.length){$preview.html($('<img>',{src:headerUrl,alt:'Vista previa'}));}
            }
        });
    });
    </script>
    <?php
}
add_action('admin_footer-post.php', 'eventosapp_whatsapp_event_hub_runtime_footer', 80);
add_action('admin_footer-post-new.php', 'eventosapp_whatsapp_event_hub_runtime_footer', 80);

/**
 * rc.32: filtros de audiencia y log de la programación de Flow.
 */
require_once __DIR__ . '/eventosapp-whatsapp-event-flow-schedule-controls.php';
