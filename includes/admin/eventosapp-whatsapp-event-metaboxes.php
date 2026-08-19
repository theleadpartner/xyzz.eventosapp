<?php
/**
 * EventosApp - Configuración WhatsApp por evento
 *
 * Reorganiza la configuración de WhatsApp del CPT de eventos en tres metaboxes:
 * - Flows: mapeo + programación.
 * - Confirmación de asistencia: programación + plantilla + header.
 * - Tickets y recordatorios: plantillas, headers, reglas y recordatorios.
 *
 * Las claves históricas se reutilizan para no migrar ni perder configuraciones.
 * Las programaciones nuevas de Flow se ejecutan mediante la Cola y Tareas.
 *
 * Ruta: includes/admin/eventosapp-whatsapp-event-metaboxes.php
 *
 * @package EventosApp
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! defined('EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META') ) {
    define('EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META', '_eventosapp_whatsapp_flow_schedule');
}

/**
 * CPTs de evento soportados durante la transición histórica del plugin.
 */
function eventosapp_whatsapp_event_hub_post_types() {
    if ( function_exists('eventosapp_whatsapp_event_post_types') ) {
        return eventosapp_whatsapp_event_post_types();
    }
    return ['eventosapp_event', 'eventosapp_events'];
}

function eventosapp_whatsapp_event_hub_is_event($post_id) {
    $post_type = get_post_type(absint($post_id));
    return $post_type && in_array($post_type, eventosapp_whatsapp_event_hub_post_types(), true);
}

/**
 * Retira únicamente los metaboxes que quedan absorbidos por la nueva experiencia.
 * Sus funciones y save handlers se conservan para mantener compatibilidad.
 */
add_action('add_meta_boxes', function() {
    foreach ( eventosapp_whatsapp_event_hub_post_types() as $screen ) {
        if ( ! post_type_exists($screen) ) {
            continue;
        }

        remove_meta_box('eventosapp_event_whatsapp_visuals', $screen, 'normal');
        remove_meta_box('eventosapp_event_whatsapp_visuals', $screen, 'side');
        remove_meta_box('eventosapp_whatsapp_rules', $screen, 'normal');
        remove_meta_box('eventosapp_whatsapp_rules', $screen, 'side');
        remove_meta_box('eventosapp_ticket_reminders', $screen, 'normal');
        remove_meta_box('eventosapp_ticket_reminders', $screen, 'side');
        remove_meta_box('eventosapp_attendance_confirmation_schedule', $screen, 'normal');
        remove_meta_box('eventosapp_attendance_confirmation_schedule', $screen, 'side');

        add_meta_box(
            'eventosapp_whatsapp_event_flows',
            'WhatsApp Flows — Configuración y Programación',
            'eventosapp_whatsapp_event_hub_render_flows',
            $screen,
            'normal',
            'high'
        );

        add_meta_box(
            'eventosapp_whatsapp_event_attendance',
            'WhatsApp — Confirmación de Asistencia',
            'eventosapp_whatsapp_event_hub_render_attendance',
            $screen,
            'normal',
            'high'
        );

        add_meta_box(
            'eventosapp_whatsapp_event_tickets_reminders',
            'WhatsApp — Tickets y Recordatorios',
            'eventosapp_whatsapp_event_hub_render_tickets_reminders',
            $screen,
            'normal',
            'default'
        );
    }
}, 999);

/**
 * La librería multimedia se carga solo al editar/crear eventos.
 */
add_action('admin_enqueue_scripts', function($hook_suffix) {
    if ( ! in_array($hook_suffix, ['post.php', 'post-new.php'], true) ) {
        return;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( ! $screen || ! in_array((string)$screen->post_type, eventosapp_whatsapp_event_hub_post_types(), true) ) {
        return;
    }
    wp_enqueue_media();
}, 40);

function eventosapp_whatsapp_event_hub_styles() {
    static $printed = false;
    if ( $printed ) {
        return;
    }
    $printed = true;
    ?>
    <style>
        .evapp-wa-event-hub{--evapp-border:#dcdcde;--evapp-muted:#646970;--evapp-soft:#f6f7f7;--evapp-blue:#2271b1}
        .evapp-wa-event-hub .evapp-wa-hub-intro{margin:0 0 16px;padding:12px 14px;background:#f0f6fc;border-left:4px solid #72aee6;border-radius:0 8px 8px 0;line-height:1.5}
        .evapp-wa-event-hub .evapp-wa-hub-grid{display:grid;grid-template-columns:minmax(220px,280px) minmax(320px,1fr);gap:13px 18px;align-items:start}
        .evapp-wa-event-hub .evapp-wa-hub-grid>label{font-weight:700;padding-top:7px}
        .evapp-wa-event-hub .evapp-wa-hub-field input[type=text],
        .evapp-wa-event-hub .evapp-wa-hub-field input[type=url],
        .evapp-wa-event-hub .evapp-wa-hub-field input[type=date],
        .evapp-wa-event-hub .evapp-wa-hub-field input[type=time],
        .evapp-wa-event-hub .evapp-wa-hub-field select{width:100%;max-width:760px}
        .evapp-wa-event-hub .evapp-wa-hub-section{margin:18px 0 10px;padding-top:16px;border-top:1px solid #e2e4e7}
        .evapp-wa-event-hub .evapp-wa-hub-section:first-of-type{margin-top:0;padding-top:0;border-top:0}
        .evapp-wa-event-hub .evapp-wa-hub-section h4{margin:0 0 5px;font-size:14px}
        .evapp-wa-event-hub .evapp-wa-hub-section p{margin:0;color:var(--evapp-muted)}
        .evapp-wa-event-hub .evapp-wa-media-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
        .evapp-wa-event-hub .evapp-wa-media-preview{margin-top:10px}
        .evapp-wa-event-hub .evapp-wa-media-preview img{display:block;max-width:320px;max-height:170px;width:auto;height:auto;border:1px solid var(--evapp-border);border-radius:8px;background:#fff;padding:4px}
        .evapp-wa-event-hub .evapp-wa-hub-help{font-size:12px;color:var(--evapp-muted);line-height:1.45;margin:5px 0 0}
        .evapp-wa-event-hub .evapp-wa-schedule-box{background:var(--evapp-soft);border:1px solid var(--evapp-border);border-radius:10px;padding:14px;margin-top:10px}
        .evapp-wa-event-hub .evapp-wa-schedule-inline{display:grid;grid-template-columns:minmax(180px,260px) minmax(140px,200px);gap:12px;max-width:500px;margin-top:12px}
        .evapp-wa-event-hub .evapp-wa-status{margin-top:12px;padding:10px 12px;background:#fff;border:1px solid var(--evapp-border);border-radius:8px;line-height:1.5}
        .evapp-wa-event-hub .evapp-wa-status.is-error{background:#fcf0f1;border-color:#d63638}
        .evapp-wa-event-hub .evapp-wa-status.is-ok{background:#edfaef;border-color:#68de7c}
        .evapp-wa-event-hub .evapp-wa-embedded-block{margin-top:18px;padding-top:18px;border-top:1px solid #dcdcde}
        .evapp-wa-event-hub .evapp-wa-embedded-block>h4{margin:0 0 10px}
        @media(max-width:782px){
            .evapp-wa-event-hub .evapp-wa-hub-grid{grid-template-columns:1fr}
            .evapp-wa-event-hub .evapp-wa-hub-grid>label{padding-top:0}
            .evapp-wa-event-hub .evapp-wa-schedule-inline{grid-template-columns:1fr}
        }
    </style>
    <?php
}

/**
 * Campo URL de imagen con selector de la Media Library.
 */
function eventosapp_whatsapp_event_hub_media_field($args) {
    $args = wp_parse_args(is_array($args) ? $args : [], [
        'id'          => '',
        'name'        => '',
        'value'       => '',
        'label'       => '',
        'help'        => '',
        'button'      => 'Seleccionar imagen',
        'placeholder' => 'Selecciona una imagen desde la biblioteca multimedia',
    ]);

    $id = sanitize_html_class((string)$args['id']);
    if ( $id === '' || $args['name'] === '' ) {
        return;
    }
    $value = esc_url_raw((string)$args['value']);
    ?>
    <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($args['label']); ?></label>
    <div class="evapp-wa-hub-field evapp-wa-media-field" data-evapp-media-field>
        <input type="url" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($args['name']); ?>" value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr($args['placeholder']); ?>" data-evapp-media-input>
        <div class="evapp-wa-media-actions">
            <button type="button" class="button" data-evapp-media-select><?php echo esc_html($args['button']); ?></button>
            <button type="button" class="button-link-delete" data-evapp-media-clear>Quitar</button>
        </div>
        <?php if ( $args['help'] !== '' ) : ?><p class="evapp-wa-hub-help"><?php echo esc_html($args['help']); ?></p><?php endif; ?>
        <div class="evapp-wa-media-preview" data-evapp-media-preview><?php if ( $value !== '' ) : ?><img src="<?php echo esc_url($value); ?>" alt="Vista previa"><?php endif; ?></div>
    </div>
    <?php
}

function eventosapp_whatsapp_event_hub_media_script() {
    static $printed = false;
    if ( $printed ) {
        return;
    }
    $printed = true;
    ?>
    <script>
    jQuery(function($){
        $(document).off('click.evappWaHubSelect','[data-evapp-media-select]').on('click.evappWaHubSelect','[data-evapp-media-select]',function(e){
            e.preventDefault();
            const $field=$(this).closest('[data-evapp-media-field]');
            const $input=$field.find('[data-evapp-media-input]').first();
            const $preview=$field.find('[data-evapp-media-preview]').first();
            if(!window.wp||!wp.media){return;}
            const frame=wp.media({title:'Seleccionar imagen',button:{text:'Usar esta imagen'},library:{type:'image'},multiple:false});
            frame.on('select',function(){
                const attachment=frame.state().get('selection').first();
                const data=attachment?attachment.toJSON():{};
                if(!data.url){return;}
                $input.val(data.url).trigger('change');
                $preview.html($('<img>',{src:data.url,alt:'Vista previa'}));
            });
            frame.open();
        });
        $(document).off('click.evappWaHubClear','[data-evapp-media-clear]').on('click.evappWaHubClear','[data-evapp-media-clear]',function(e){
            e.preventDefault();
            const $field=$(this).closest('[data-evapp-media-field]');
            $field.find('[data-evapp-media-input]').first().val('').trigger('change');
            $field.find('[data-evapp-media-preview]').first().empty();
        });
    });
    </script>
    <?php
}

function eventosapp_whatsapp_event_hub_timezone_name($event_id) {
    if ( function_exists('eventosapp_task_queue_event_timezone_name') ) {
        return eventosapp_task_queue_event_timezone_name($event_id);
    }
    if ( function_exists('eventosapp_get_event_timezone_object') ) {
        try {
            $timezone = eventosapp_get_event_timezone_object($event_id);
            if ( $timezone instanceof DateTimeZone ) {
                return $timezone->getName();
            }
        } catch (Throwable $e) {
            // Fallback debajo.
        }
    }
    $timezone = sanitize_text_field((string)get_post_meta($event_id, '_eventosapp_zona_horaria', true));
    return $timezone !== '' ? $timezone : wp_timezone_string();
}

function eventosapp_whatsapp_event_hub_timezone_object($event_id) {
    $name = eventosapp_whatsapp_event_hub_timezone_name($event_id);
    try {
        return new DateTimeZone($name ?: 'UTC');
    } catch (Throwable $e) {
        return wp_timezone();
    }
}

/**
 * Parsea una fecha/hora como hora local del evento y devuelve timestamp UTC.
 */
function eventosapp_whatsapp_event_hub_parse_local_datetime($event_id, $date, $time) {
    $date = sanitize_text_field((string)$date);
    $time = sanitize_text_field((string)$time);
    if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^\d{2}:\d{2}$/', $time) ) {
        return new WP_Error('invalid_event_datetime', 'La fecha u hora programada no tiene un formato válido.');
    }

    $timezone = eventosapp_whatsapp_event_hub_timezone_object($event_id);
    $datetime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if ( ! $datetime || (is_array($errors) && (! empty($errors['warning_count']) || ! empty($errors['error_count']))) ) {
        return new WP_Error('invalid_event_datetime', 'La fecha u hora programada no es válida para la zona horaria del evento.');
    }
    if ( $datetime->format('Y-m-d H:i') !== $date . ' ' . $time ) {
        return new WP_Error('invalid_event_datetime', 'La fecha u hora programada fue normalizada por la zona horaria. Selecciona una hora válida.');
    }

    return $datetime->getTimestamp();
}

function eventosapp_whatsapp_event_hub_render_flows($post) {
    $event_id = absint($post->ID);
    $schedule = get_post_meta($event_id, EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META, true);
    $schedule = is_array($schedule) ? $schedule : [];
    $timezone_name = eventosapp_whatsapp_event_hub_timezone_name($event_id);
    $queue_task_id = absint($schedule['queue_task_id'] ?? 0);
    $queue_task = ($queue_task_id && function_exists('eventosapp_task_queue_get')) ? eventosapp_task_queue_get($queue_task_id) : null;
    $last_error = sanitize_text_field((string)($schedule['last_error'] ?? ''));

    eventosapp_whatsapp_event_hub_styles();
    wp_nonce_field('eventosapp_whatsapp_event_hub_save', 'eventosapp_whatsapp_event_hub_nonce');
    // Activa los save handlers históricos que conservan los metadatos de mapeo.
    wp_nonce_field('eventosapp_whatsapp_event_visuals_save', 'eventosapp_whatsapp_event_visuals_nonce');
    ?>
    <div class="evapp-wa-event-hub">
        <div class="evapp-wa-hub-intro"><strong>Configuración unificada del Flow.</strong> La plantilla, el Flow, el número emisor, el cabezote y su programación quedan asociados al evento desde este bloque. La programación se registra en <strong>Cola y Tareas</strong> y usa la zona horaria del evento.</div>

        <div class="evapp-wa-hub-section"><h4>1. Mapeo de plantilla y Flow</h4><p>Selecciona la plantilla aprobada y el Flow que se abrirá desde WhatsApp.</p></div>
        <div class="evapp-wa-hub-grid">
            <div style="grid-column:1/-1">
                <?php if ( function_exists('eventosapp_whatsapp_render_event_satisfaction_flow_sender_phone_select') ) eventosapp_whatsapp_render_event_satisfaction_flow_sender_phone_select($event_id); ?>
            </div>
            <div style="grid-column:1/-1">
                <?php if ( function_exists('eventosapp_whatsapp_render_event_satisfaction_flow_template_select') ) eventosapp_whatsapp_render_event_satisfaction_flow_template_select($event_id); ?>
            </div>
            <div style="grid-column:1/-1">
                <?php if ( function_exists('eventosapp_whatsapp_render_event_satisfaction_flow_select') ) eventosapp_whatsapp_render_event_satisfaction_flow_select($event_id); ?>
            </div>
            <?php eventosapp_whatsapp_event_hub_media_field([
                'id'=>'evapp_eventosapp_whatsapp_satisfaction_flow_header_img',
                'name'=>'eventosapp_whatsapp_satisfaction_flow_header_img',
                'value'=>get_post_meta($event_id, '_eventosapp_whatsapp_satisfaction_flow_header_img', true),
                'label'=>'Cabezote de imagen del Flow',
                'help'=>'Imagen dinámica enviada en el header de la plantilla Flow. Si queda vacía, se conserva el fallback de la plantilla y del evento.',
                'button'=>'Seleccionar cabezote del Flow',
            ]); ?>
        </div>

        <div class="evapp-wa-hub-section"><h4>2. Programación del envío</h4><p>Programa un envío del Flow para los tickets del evento. Los filtros y reglas de WhatsApp se validan al procesar cada ticket.</p></div>
        <div class="evapp-wa-schedule-box">
            <label><input type="checkbox" name="evapp_whatsapp_flow_schedule[enabled]" value="1" <?php checked(! empty($schedule['enabled'])); ?>> Activar programación de WhatsApp Flow</label>
            <div class="evapp-wa-schedule-inline">
                <label>Fecha local del evento<br><input type="date" name="evapp_whatsapp_flow_schedule[date]" value="<?php echo esc_attr($schedule['date'] ?? ''); ?>"></label>
                <label>Hora local del evento<br><input type="time" name="evapp_whatsapp_flow_schedule[time]" value="<?php echo esc_attr($schedule['time'] ?? ''); ?>"></label>
            </div>
            <p class="evapp-wa-hub-help">Zona horaria aplicada: <strong><?php echo esc_html($timezone_name); ?></strong>. EventosApp convierte esta fecha/hora a UTC únicamente para la cola, sin interpretarla con la zona horaria del servidor.</p>

            <?php if ( $last_error !== '' ) : ?>
                <div class="evapp-wa-status is-error"><strong>No se pudo programar:</strong> <?php echo esc_html($last_error); ?></div>
            <?php elseif ( $queue_task_id ) : ?>
                <div class="evapp-wa-status is-ok">
                    <strong>Tarea de cola #<?php echo esc_html((string)$queue_task_id); ?></strong>
                    <?php if ( is_array($queue_task) ) : ?> · Estado: <?php echo esc_html((string)($queue_task['status'] ?? '')); ?><?php endif; ?>
                    <?php if ( function_exists('eventosapp_task_queue_task_url') ) : ?> · <a href="<?php echo esc_url(eventosapp_task_queue_task_url($queue_task_id)); ?>">Abrir en Cola y Tareas</a><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    eventosapp_whatsapp_event_hub_media_script();
}

function eventosapp_whatsapp_event_hub_render_attendance($post) {
    $event_id = absint($post->ID);
    eventosapp_whatsapp_event_hub_styles();
    ?>
    <div class="evapp-wa-event-hub">
        <div class="evapp-wa-hub-intro"><strong>Confirmación de asistencia en un solo lugar.</strong> Conserva el motor actual de confirmación, pero reúne aquí la programación, la plantilla WhatsApp y la imagen que utilizará el header.</div>

        <?php if ( function_exists('eventosapp_attendance_confirmation_render_event_metabox') ) : ?>
            <?php eventosapp_attendance_confirmation_render_event_metabox($post); ?>
        <?php else : ?>
            <p>No está disponible el módulo de programación de confirmación de asistencia.</p>
        <?php endif; ?>

        <div class="evapp-wa-embedded-block">
            <h4>Header de WhatsApp para confirmación</h4>
            <div class="evapp-wa-hub-grid">
                <?php eventosapp_whatsapp_event_hub_media_field([
                    'id'=>'evapp-attendance-wa-image-url',
                    'name'=>'eventosapp_attendance_confirmation_whatsapp_image',
                    'value'=>get_post_meta($event_id, '_eventosapp_attendance_confirmation_whatsapp_image', true),
                    'label'=>'Imagen para confirmación de asistencia',
                    'help'=>'Se usa como encabezado dinámico de la plantilla WhatsApp de confirmación con botones de respuesta.',
                    'button'=>'Seleccionar header de confirmación',
                ]); ?>
            </div>
        </div>
    </div>
    <?php
    eventosapp_whatsapp_event_hub_media_script();
}

function eventosapp_whatsapp_event_hub_render_tickets_reminders($post) {
    $event_id = absint($post->ID);
    $modalities = function_exists('eventosapp_whatsapp_event_template_modalities') ? eventosapp_whatsapp_event_template_modalities($event_id) : ['presencial','virtual'];

    eventosapp_whatsapp_event_hub_styles();
    // El handler histórico de visuales seguirá guardando exactamente las mismas claves.
    wp_nonce_field('eventosapp_whatsapp_event_visuals_save', 'eventosapp_whatsapp_event_visuals_nonce');
    ?>
    <div class="evapp-wa-event-hub">
        <div class="evapp-wa-hub-intro"><strong>Tickets y recordatorios unificados.</strong> Configura plantillas, cabezotes, reglas de envío y recordatorios sin repartir la configuración en varios metaboxes.</div>

        <div class="evapp-wa-hub-section"><h4>1. Plantillas y número emisor</h4><p>Mapeo de plantillas utilizado por los envíos de ticket del evento.</p></div>
        <div class="evapp-wa-hub-grid">
            <div style="grid-column:1/-1">
                <?php if ( function_exists('eventosapp_whatsapp_render_event_sender_phone_select') ) eventosapp_whatsapp_render_event_sender_phone_select($event_id); ?>
            </div>
            <?php foreach ( (array)$modalities as $modality ) : ?>
                <div style="grid-column:1/-1">
                    <?php if ( function_exists('eventosapp_whatsapp_render_event_template_select') ) eventosapp_whatsapp_render_event_template_select($event_id, $modality); ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="evapp-wa-hub-section"><h4>2. Cabezotes e imágenes</h4><p>Todos los selectores abren directamente la biblioteca multimedia de WordPress.</p></div>
        <div class="evapp-wa-hub-grid">
            <?php eventosapp_whatsapp_event_hub_media_field([
                'id'=>'evapp_eventosapp_whatsapp_qr_header_img',
                'name'=>'eventosapp_whatsapp_qr_header_img',
                'value'=>get_post_meta($event_id, '_eventosapp_whatsapp_qr_header_img', true),
                'label'=>'Cabezote para ticket WhatsApp presencial / QR',
                'help'=>'Imagen usada por el mensaje de ticket/QR cuando la plantilla requiere header dinámico.',
                'button'=>'Seleccionar cabezote del ticket',
            ]); ?>
            <?php eventosapp_whatsapp_event_hub_media_field([
                'id'=>'evapp_eventosapp_whatsapp_virtual_message_img',
                'name'=>'eventosapp_whatsapp_virtual_message_img',
                'value'=>get_post_meta($event_id, '_eventosapp_whatsapp_virtual_message_img', true),
                'label'=>'Cabezote para ticket WhatsApp virtual',
                'help'=>'Imagen usada por la variante virtual del mensaje de ticket.',
                'button'=>'Seleccionar cabezote virtual',
            ]); ?>
            <?php eventosapp_whatsapp_event_hub_media_field([
                'id'=>'evapp_eventosapp_whatsapp_landing_header_img',
                'name'=>'eventosapp_whatsapp_landing_header_img',
                'value'=>get_post_meta($event_id, '_eventosapp_whatsapp_landing_header_img', true),
                'label'=>'Cabezote de la landing del ticket WhatsApp',
                'help'=>'Imagen mostrada en la landing vinculada al ticket enviado por WhatsApp.',
                'button'=>'Seleccionar cabezote de landing',
            ]); ?>
        </div>

        <div class="evapp-wa-embedded-block">
            <h4>3. Reglas de envío de tickets</h4>
            <?php if ( function_exists('eventosapp_whatsapp_render_event_rules_metabox') ) : ?>
                <?php eventosapp_whatsapp_render_event_rules_metabox($post); ?>
            <?php else : ?>
                <p>El motor de reglas de WhatsApp no está disponible.</p>
            <?php endif; ?>
        </div>

        <div class="evapp-wa-embedded-block">
            <h4>4. Recordatorios programados por WhatsApp</h4>
            <?php if ( function_exists('eventosapp_ticket_reminders_render_metabox') ) : ?>
                <?php eventosapp_ticket_reminders_render_metabox($post); ?>
            <?php else : ?>
                <p>El módulo de recordatorios programados no está disponible.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    eventosapp_whatsapp_event_hub_media_script();
}

/**
 * Devuelve un listado de tickets del evento justo cuando la tarea comienza.
 * Así una programación creada con anticipación también incluye registros que
 * se hayan creado después de guardar el evento.
 */
function eventosapp_whatsapp_event_flow_schedule_ticket_ids($event_id) {
    $event_id = absint($event_id);
    if ( ! $event_id ) {
        return [];
    }
    $ids = get_posts([
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => ['publish','pending','draft','private'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'meta_query'     => [[
            'key'     => '_eventosapp_ticket_evento_id',
            'value'   => $event_id,
            'compare' => '=',
        ]],
    ]);
    return function_exists('eventosapp_task_queue_ticket_ids') ? eventosapp_task_queue_ticket_ids($ids) : array_values(array_filter(array_map('absint', $ids)));
}

/**
 * Adaptador de Flow programado. Reutiliza el procesador probado de Flow masivo,
 * pero prepara la audiencia al momento de ejecutar y no al momento de guardar.
 */
function eventosapp_whatsapp_event_flow_schedule_process($task, $runtime) {
    if ( ! function_exists('eventosapp_task_queue_process_flow_bulk') ) {
        return new WP_Error('flow_queue_adapter_missing', 'No está disponible el procesador de WhatsApp Flow de la cola central.');
    }

    $task = is_array($task) ? $task : [];
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    $event_id = absint($task['event_id'] ?? ($payload['event_id'] ?? 0));
    $ticket_ids = function_exists('eventosapp_task_queue_ticket_ids') ? eventosapp_task_queue_ticket_ids($payload['ticket_ids'] ?? []) : [];

    if ( empty($payload['prepared']) ) {
        $ticket_ids = eventosapp_whatsapp_event_flow_schedule_ticket_ids($event_id);
        $payload['ticket_ids'] = $ticket_ids;
        $payload['prepared'] = 1;
        $payload['prepared_at_utc'] = current_time('mysql', true);

        if ( function_exists('eventosapp_task_queue_update') && ! empty($task['id']) ) {
            eventosapp_task_queue_update(absint($task['id']), [
                'payload'     => $payload,
                'total_items' => count($ticket_ids),
            ]);
        }
        $task['payload'] = $payload;
        $task['total_items'] = count($ticket_ids);
    }

    $segment = [
        'event_id'                   => $event_id,
        'flow_post_id'               => absint($payload['flow_post_id'] ?? 0),
        'flow_template_id'           => sanitize_key((string)($payload['flow_template_id'] ?? '')),
        'sender_phone_number_id'     => sanitize_text_field((string)($payload['sender_phone_number_id'] ?? '')),
        'flow_template_header_image' => esc_url_raw((string)($payload['header_image_url'] ?? '')),
    ];

    $task['payload']['segment_id'] = sanitize_key('scheduled_flow_' . absint($task['id'] ?? 0));
    $task['payload']['segment'] = $segment;
    $task['payload']['ticket_ids'] = $ticket_ids;

    return eventosapp_task_queue_process_flow_bulk($task, $runtime);
}

add_action('init', function() {
    if ( ! function_exists('eventosapp_task_queue_register_adapter') ) {
        return;
    }
    if ( function_exists('eventosapp_task_queue_get_adapter') && eventosapp_task_queue_get_adapter('whatsapp_flow_scheduled') ) {
        return;
    }
    eventosapp_task_queue_register_adapter('whatsapp_flow_scheduled', [
        'label'          => 'WhatsApp Flow programado por evento',
        'group'          => 'scheduled',
        'channel'        => 'flow',
        'batch_size'     => 5,
        'min_batch_size' => 1,
        'max_batch_size' => 10,
        'process_batch'  => 'eventosapp_whatsapp_event_flow_schedule_process',
    ]);
}, 45);

function eventosapp_whatsapp_event_flow_schedule_signature($event_id, $schedule) {
    $schedule = is_array($schedule) ? $schedule : [];
    $parts = [
        absint($event_id),
        ! empty($schedule['enabled']) ? '1' : '0',
        sanitize_text_field((string)($schedule['date'] ?? '')),
        sanitize_text_field((string)($schedule['time'] ?? '')),
        sanitize_key((string)($schedule['flow_template_id'] ?? '')),
        absint($schedule['flow_post_id'] ?? 0),
        sanitize_text_field((string)($schedule['sender_phone_number_id'] ?? '')),
        esc_url_raw((string)($schedule['header_image_url'] ?? '')),
    ];
    return hash('sha256', implode('|', array_map('strval', $parts)));
}

function eventosapp_whatsapp_event_flow_schedule_cancel_task($task_id, $reason) {
    $task_id = absint($task_id);
    if ( ! $task_id || ! function_exists('eventosapp_task_queue_get') || ! function_exists('eventosapp_task_queue_cancel') ) {
        return;
    }
    $task = eventosapp_task_queue_get($task_id);
    if ( ! is_array($task) ) {
        return;
    }
    $terminal = function_exists('eventosapp_task_queue_terminal_statuses') ? eventosapp_task_queue_terminal_statuses() : ['cancelled','completed','expired','failed','archived'];
    if ( ! in_array((string)($task['status'] ?? ''), $terminal, true) ) {
        eventosapp_task_queue_cancel($task_id, $reason);
    }
}

/**
 * Guarda/sincroniza la programación de Flow después de que el handler histórico
 * haya persistido el mapeo de plantilla, Flow, emisor e imagen.
 */
function eventosapp_whatsapp_event_hub_save_flow_schedule($post_id, $post) {
    $post_id = absint($post_id);
    if ( ! $post_id || ! eventosapp_whatsapp_event_hub_is_event($post_id) ) {
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

    $raw = isset($_POST['evapp_whatsapp_flow_schedule']) && is_array($_POST['evapp_whatsapp_flow_schedule'])
        ? wp_unslash($_POST['evapp_whatsapp_flow_schedule'])
        : [];

    $existing = get_post_meta($post_id, EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META, true);
    $existing = is_array($existing) ? $existing : [];
    $enabled = ! empty($raw['enabled']);
    $date = sanitize_text_field((string)($raw['date'] ?? ''));
    $time = sanitize_text_field((string)($raw['time'] ?? ''));

    $flow_template_id = function_exists('eventosapp_whatsapp_get_event_selected_satisfaction_flow_template_id')
        ? eventosapp_whatsapp_get_event_selected_satisfaction_flow_template_id($post_id)
        : sanitize_key((string)get_post_meta($post_id, '_eventosapp_whatsapp_satisfaction_flow_template_id', true));
    $flow_post_id = function_exists('eventosapp_whatsapp_get_event_selected_satisfaction_flow_post_id')
        ? eventosapp_whatsapp_get_event_selected_satisfaction_flow_post_id($post_id)
        : absint(get_post_meta($post_id, '_eventosapp_whatsapp_satisfaction_flow_post_id', true));
    $sender_phone_number_id = function_exists('eventosapp_whatsapp_get_event_satisfaction_flow_sender_phone_number_id')
        ? eventosapp_whatsapp_get_event_satisfaction_flow_sender_phone_number_id($post_id)
        : sanitize_text_field((string)get_post_meta($post_id, '_eventosapp_whatsapp_satisfaction_flow_sender_phone_number_id', true));
    $header_image_url = function_exists('eventosapp_whatsapp_get_event_satisfaction_flow_header_image')
        ? eventosapp_whatsapp_get_event_satisfaction_flow_header_image($post_id)
        : esc_url_raw((string)get_post_meta($post_id, '_eventosapp_whatsapp_satisfaction_flow_header_img', true));

    $schedule = [
        'enabled'                => $enabled ? '1' : '0',
        'date'                   => $date,
        'time'                   => $time,
        'event_timezone'         => eventosapp_whatsapp_event_hub_timezone_name($post_id),
        'flow_template_id'       => $flow_template_id,
        'flow_post_id'           => $flow_post_id,
        'sender_phone_number_id' => $sender_phone_number_id,
        'header_image_url'       => $header_image_url,
        'queue_task_id'          => absint($existing['queue_task_id'] ?? 0),
        'last_error'             => '',
        'updated_at'             => current_time('mysql'),
    ];
    $schedule['signature'] = eventosapp_whatsapp_event_flow_schedule_signature($post_id, $schedule);

    if ( ! $enabled ) {
        eventosapp_whatsapp_event_flow_schedule_cancel_task($schedule['queue_task_id'], 'Programación de WhatsApp Flow desactivada desde el evento.');
        $schedule['queue_task_id'] = 0;
        $schedule['timestamp'] = 0;
        $schedule['scheduled_at_utc'] = '';
        update_post_meta($post_id, EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META, $schedule);
        return;
    }

    if ( $flow_template_id === '' || ! $flow_post_id ) {
        eventosapp_whatsapp_event_flow_schedule_cancel_task($schedule['queue_task_id'], 'Programación reemplazada por una configuración incompleta de Flow.');
        $schedule['queue_task_id'] = 0;
        $schedule['last_error'] = 'Selecciona una plantilla Flow y un Flow válido antes de activar la programación.';
        update_post_meta($post_id, EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META, $schedule);
        return;
    }

    $timestamp = eventosapp_whatsapp_event_hub_parse_local_datetime($post_id, $date, $time);
    if ( is_wp_error($timestamp) ) {
        eventosapp_whatsapp_event_flow_schedule_cancel_task($schedule['queue_task_id'], 'Programación reemplazada por una fecha/hora inválida.');
        $schedule['queue_task_id'] = 0;
        $schedule['last_error'] = $timestamp->get_error_message();
        update_post_meta($post_id, EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META, $schedule);
        return;
    }
    if ( $timestamp <= time() ) {
        eventosapp_whatsapp_event_flow_schedule_cancel_task($schedule['queue_task_id'], 'Programación reemplazada por una fecha/hora vencida.');
        $schedule['queue_task_id'] = 0;
        $schedule['timestamp'] = $timestamp;
        $schedule['scheduled_at_utc'] = gmdate('Y-m-d H:i:s', $timestamp);
        $schedule['last_error'] = 'La fecha y hora del Flow deben estar en el futuro según la zona horaria del evento.';
        update_post_meta($post_id, EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META, $schedule);
        return;
    }

    $schedule['timestamp'] = $timestamp;
    $schedule['scheduled_at_utc'] = gmdate('Y-m-d H:i:s', $timestamp);

    $existing_task_id = absint($existing['queue_task_id'] ?? 0);
    $same_signature = ! empty($existing['signature']) && hash_equals((string)$existing['signature'], (string)$schedule['signature']);
    if ( $same_signature && $existing_task_id && function_exists('eventosapp_task_queue_get') ) {
        $existing_task = eventosapp_task_queue_get($existing_task_id);
        $terminal = function_exists('eventosapp_task_queue_terminal_statuses') ? eventosapp_task_queue_terminal_statuses() : ['cancelled','completed','expired','failed','archived'];
        if ( is_array($existing_task) && ! in_array((string)($existing_task['status'] ?? ''), $terminal, true) ) {
            $schedule['queue_task_id'] = $existing_task_id;
            update_post_meta($post_id, EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META, $schedule);
            return;
        }
    }

    eventosapp_whatsapp_event_flow_schedule_cancel_task($existing_task_id, 'Programación de WhatsApp Flow reemplazada desde el evento.');

    if ( ! function_exists('eventosapp_task_queue_create') ) {
        $schedule['queue_task_id'] = 0;
        $schedule['last_error'] = 'La Cola y Tareas no está disponible; la programación no se guardó como tarea ejecutable.';
        update_post_meta($post_id, EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META, $schedule);
        return;
    }

    $payload = [
        'event_id'               => $post_id,
        'flow_template_id'       => $flow_template_id,
        'flow_post_id'           => $flow_post_id,
        'sender_phone_number_id' => $sender_phone_number_id,
        'header_image_url'       => $header_image_url,
        'ticket_ids'             => [],
        'prepared'               => 0,
        'planned_timestamp'      => $timestamp,
        'event_timezone'         => $schedule['event_timezone'],
        'scheduled_local'        => $date . ' ' . $time . ':00',
        'scheduled_utc'          => $schedule['scheduled_at_utc'],
        'schedule_signature'     => $schedule['signature'],
    ];

    $notification_email = function_exists('eventosapp_task_queue_current_notification_email')
        ? eventosapp_task_queue_current_notification_email()
        : sanitize_email(get_option('admin_email'));

    $task_id = eventosapp_task_queue_create([
        'task_type'          => 'whatsapp_flow_scheduled',
        'task_group'         => 'scheduled',
        'channel'            => 'flow',
        'title'              => 'WhatsApp Flow programado · ' . get_the_title($post_id),
        'status'             => 'scheduled',
        'event_id'           => $post_id,
        'scheduled_at'       => $schedule['scheduled_at_utc'],
        'next_run_at'        => $schedule['scheduled_at_utc'],
        'total_items'        => 0,
        'payload'            => $payload,
        'notification_email' => $notification_email,
        'created_by'         => get_current_user_id(),
    ]);

    if ( is_wp_error($task_id) ) {
        $schedule['queue_task_id'] = 0;
        $schedule['last_error'] = $task_id->get_error_message();
    } else {
        $schedule['queue_task_id'] = absint($task_id);
        if ( function_exists('eventosapp_task_queue_add_log') ) {
            eventosapp_task_queue_add_log(absint($task_id), 'info', 'Programación creada desde el metabox WhatsApp Flows del evento.', [
                'event_id'       => $post_id,
                'event_timezone' => $schedule['event_timezone'],
                'scheduled_local'=> $payload['scheduled_local'],
                'scheduled_utc'  => $payload['scheduled_utc'],
                'flow_post_id'   => $flow_post_id,
                'template_id'    => $flow_template_id,
            ]);
        }
    }

    update_post_meta($post_id, EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META, $schedule);
}

add_action('save_post_eventosapp_event', 'eventosapp_whatsapp_event_hub_save_flow_schedule', 180, 2);
add_action('save_post_eventosapp_events', 'eventosapp_whatsapp_event_hub_save_flow_schedule', 180, 2);

/**
 * Asegura que los schedulers ya existentes también sincronicen el alias plural
 * del CPT cuando una instalación lo utilice.
 */
add_action('save_post_eventosapp_events', function($post_id) {
    if ( wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) ) {
        return;
    }
    if ( function_exists('eventosapp_task_queue_sync_ticket_reminders') ) {
        eventosapp_task_queue_sync_ticket_reminders($post_id);
    }
    if ( function_exists('eventosapp_task_queue_sync_attendance_schedule') ) {
        eventosapp_task_queue_sync_attendance_schedule($post_id);
    }
}, 285, 1);
