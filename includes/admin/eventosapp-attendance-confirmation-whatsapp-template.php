<?php
/**
 * EventosApp - Plantillas WhatsApp para confirmación de asistencia.
 *
 * Administra un inventario ilimitado de plantillas con botones QUICK_REPLY
 * “Sí” y “No”, compatible con la sección general de Plantillas WhatsApp,
 * Meta Cloud API y los envíos masivos/programados de confirmación.
 *
 * Ruta: includes/admin/eventosapp-attendance-confirmation-whatsapp-template.php
 *
 * @package EventosApp
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Capacidad requerida para administrar el inventario.
 */
function eventosapp_attendance_confirmation_whatsapp_template_can_manage() {
    if ( function_exists('eventosapp_attendance_confirmation_admin_can_manage') ) {
        return eventosapp_attendance_confirmation_admin_can_manage();
    }
    return current_user_can('manage_options');
}

/**
 * Registro base de la plantilla predeterminada.
 */
function eventosapp_attendance_confirmation_whatsapp_template_defaults() {
    $now = current_time('mysql');
    return [
        'id'                       => 'attendance_confirmation',
        'attendance_confirmation'  => '1',
        'is_default'               => '1',
        'base_key'                 => 'attendance_confirmation',
        'name'                     => 'eventosapp_confirmacion_asistencia_v1',
        'language'                 => 'es',
        'category'                 => 'UTILITY',
        'modality'                 => 'custom',
        'title'                    => 'Confirmación de asistencia — Sí / No',
        'header_format'            => 'IMAGE',
        'header_text'              => '',
        'header_sample_handle'     => '',
        'header_sample_file_name'  => '',
        'header_sample_file_type'  => '',
        'header_sample_file_size'  => 0,
        'header_sample_uploaded_at'=> '',
        'body_text'                => "Hola {{1}}, queremos confirmar tu asistencia a *{{2}}*.\n\n📅 *Fecha:* {{3}}\n🕒 *Hora:* {{4}}\n📍 *Lugar:* {{5}}\n\nPor favor responde usando uno de los botones.",
        'body_examples'            => "María Pérez\nEvento Demo\n20 de mayo de 2026\n8:00 a. m.\nCentro de Convenciones",
        'body_text_meta'           => '',
        'body_variable_map'        => [1, 2, 3, 4, 5],
        'body_variable_signature'  => '',
        'footer_text'              => 'EventosApp',
        'button_mode'              => 'quick_reply',
        'button_count'             => '2',
        'button_1_text'            => 'Sí, asistiré',
        'button_1_url'             => '',
        'button_1_example'         => '',
        'button_2_text'            => 'No podré asistir',
        'button_2_url'             => '',
        'button_2_example'         => '',
        'sender_phone_number_id'   => '',
        'sender_phone_label'       => 'Número por defecto',
        'waba_id'                  => '',
        'meta_template_id'         => '',
        'meta_status'              => 'LOCAL',
        'meta_category'            => '',
        'meta_rejected_reason'     => '',
        'last_api_message'         => '',
        'last_api_response'        => [],
        'last_submitted_at'        => '',
        'last_checked_at'          => '',
        'created_at'               => $now,
        'updated_at'               => $now,
    ];
}

/**
 * Normaliza las variables del BODY para Meta y para el envío real.
 */
function eventosapp_attendance_confirmation_whatsapp_template_prepare_body($template) {
    $template = is_array($template) ? $template : [];

    if ( function_exists('eventosapp_whatsapp_templates_prepare_body_for_meta') ) {
        $prepared = eventosapp_whatsapp_templates_prepare_body_for_meta(
            $template['body_text'] ?? '',
            $template['body_examples'] ?? ''
        );
        $template['body_text_meta'] = sanitize_textarea_field((string)($prepared['text'] ?? $template['body_text'] ?? ''));
        $template['body_variable_map'] = is_array($prepared['variable_numbers'] ?? null)
            ? array_values(array_unique(array_map('absint', $prepared['variable_numbers'])))
            : [1, 2, 3, 4, 5];
        $template['body_variable_signature'] = sanitize_text_field((string)($prepared['signature'] ?? md5((string)($template['body_text'] ?? ''))));
    } else {
        $template['body_text_meta'] = sanitize_textarea_field((string)($template['body_text'] ?? ''));
        preg_match_all('/\{\{(\d+)\}\}/', (string)($template['body_text'] ?? ''), $matches);
        $template['body_variable_map'] = ! empty($matches[1])
            ? array_values(array_unique(array_map('absint', $matches[1])))
            : [];
        $template['body_variable_signature'] = md5((string)($template['body_text'] ?? ''));
    }

    return $template;
}

/**
 * Garantiza la plantilla predeterminada y normaliza todos los registros de
 * confirmación existentes sin tocar sus estados remotos.
 */
function eventosapp_attendance_confirmation_whatsapp_template_ensure() {
    if ( ! function_exists('eventosapp_whatsapp_templates_get_settings') || ! function_exists('eventosapp_whatsapp_templates_update_settings') ) {
        return;
    }

    $settings = eventosapp_whatsapp_templates_get_settings();
    $defaults = eventosapp_attendance_confirmation_whatsapp_template_defaults();
    $templates = is_array($settings['templates'] ?? null) ? $settings['templates'] : [];
    $changed = false;

    if ( empty($templates['attendance_confirmation']) || ! is_array($templates['attendance_confirmation']) ) {
        $templates['attendance_confirmation'] = $defaults;
        $changed = true;
    }

    foreach ( $templates as $template_id => $template ) {
        if ( ! is_array($template) ) {
            continue;
        }

        $is_default = sanitize_key((string)$template_id) === 'attendance_confirmation';
        if ( ! $is_default && empty($template['attendance_confirmation']) ) {
            continue;
        }

        $original = $template;
        $template = wp_parse_args($template, $defaults);
        $template['id'] = sanitize_key((string)$template_id);
        $template['attendance_confirmation'] = '1';
        $template['base_key'] = 'attendance_confirmation';
        $template['is_default'] = $is_default ? '1' : '0';
        $template['button_mode'] = 'quick_reply';
        $template['button_count'] = '2';
        $template['button_1_url'] = '';
        $template['button_1_example'] = '';
        $template['button_2_url'] = '';
        $template['button_2_example'] = '';

        if ( trim((string)($template['created_at'] ?? '')) === '' ) {
            $template['created_at'] = current_time('mysql');
        }
        if ( trim((string)($template['updated_at'] ?? '')) === '' ) {
            $template['updated_at'] = $template['created_at'];
        }

        $template = eventosapp_attendance_confirmation_whatsapp_template_prepare_body($template);
        if ( maybe_serialize($original) !== maybe_serialize($template) ) {
            $templates[$template_id] = $template;
            $changed = true;
        }
    }

    if ( $changed ) {
        $settings['templates'] = $templates;
        eventosapp_whatsapp_templates_update_settings($settings);
    }
}
add_action('admin_init', 'eventosapp_attendance_confirmation_whatsapp_template_ensure', 8);

/**
 * Devuelve todas las plantillas especializadas.
 */
function eventosapp_attendance_confirmation_whatsapp_template_get_all($only_approved = false) {
    eventosapp_attendance_confirmation_whatsapp_template_ensure();

    if ( function_exists('eventosapp_attendance_confirmation_get_whatsapp_templates') ) {
        $templates = eventosapp_attendance_confirmation_get_whatsapp_templates($only_approved);
    } elseif ( function_exists('eventosapp_whatsapp_templates_get_settings') ) {
        $settings = eventosapp_whatsapp_templates_get_settings();
        $templates = [];
        foreach ( (array)($settings['templates'] ?? []) as $template_id => $template ) {
            if ( ! is_array($template) || empty($template['attendance_confirmation']) ) {
                continue;
            }
            if ( $only_approved && ! eventosapp_attendance_confirmation_whatsapp_template_is_approved($template) ) {
                continue;
            }
            $templates[sanitize_key((string)$template_id)] = $template;
        }
    } else {
        $templates = ['attendance_confirmation' => eventosapp_attendance_confirmation_whatsapp_template_defaults()];
    }

    uasort($templates, static function($a, $b) {
        $a_time = strtotime((string)($a['updated_at'] ?? $a['created_at'] ?? '')) ?: 0;
        $b_time = strtotime((string)($b['updated_at'] ?? $b['created_at'] ?? '')) ?: 0;
        return $b_time <=> $a_time;
    });

    return $templates;
}

/**
 * Obtiene una plantilla por ID local. Sin argumento conserva compatibilidad con
 * el registro predeterminado histórico.
 */
function eventosapp_attendance_confirmation_whatsapp_template_get($template_id = '') {
    $template_id = sanitize_key((string)$template_id);
    if ( $template_id === '' ) {
        $template_id = 'attendance_confirmation';
    }

    $templates = eventosapp_attendance_confirmation_whatsapp_template_get_all(false);
    if ( isset($templates[$template_id]) && is_array($templates[$template_id]) ) {
        return $templates[$template_id];
    }

    return $template_id === 'attendance_confirmation'
        ? eventosapp_attendance_confirmation_whatsapp_template_defaults()
        : null;
}

/**
 * Guarda una plantilla específica. El segundo argumento es opcional para
 * mantener compatibilidad con el comportamiento anterior de plantilla única.
 */
function eventosapp_attendance_confirmation_whatsapp_template_update($template, $template_id = '') {
    if ( ! function_exists('eventosapp_whatsapp_templates_get_settings') || ! function_exists('eventosapp_whatsapp_templates_update_settings') ) {
        return false;
    }

    $template = is_array($template) ? $template : [];
    $template_id = sanitize_key((string)($template_id ?: ($template['id'] ?? 'attendance_confirmation')));
    if ( $template_id === '' ) {
        return false;
    }

    $settings = eventosapp_whatsapp_templates_get_settings();
    $existing = isset($settings['templates'][$template_id]) && is_array($settings['templates'][$template_id])
        ? $settings['templates'][$template_id]
        : [];

    $template = wp_parse_args($template, eventosapp_attendance_confirmation_whatsapp_template_defaults());
    $template['id'] = $template_id;
    $template['attendance_confirmation'] = '1';
    $template['base_key'] = 'attendance_confirmation';
    $template['is_default'] = $template_id === 'attendance_confirmation' ? '1' : '0';
    $template['button_mode'] = 'quick_reply';
    $template['button_count'] = '2';
    $template['button_1_url'] = '';
    $template['button_1_example'] = '';
    $template['button_2_url'] = '';
    $template['button_2_example'] = '';
    $template['created_at'] = sanitize_text_field((string)($existing['created_at'] ?? $template['created_at'] ?? current_time('mysql')));
    $template['updated_at'] = current_time('mysql');
    $template = eventosapp_attendance_confirmation_whatsapp_template_prepare_body($template);

    $settings['templates'][$template_id] = $template;
    eventosapp_whatsapp_templates_update_settings($settings);
    return $template_id;
}

/**
 * Determina si el estado remoto permite envío.
 */
function eventosapp_attendance_confirmation_whatsapp_template_is_approved($template) {
    if ( function_exists('eventosapp_whatsapp_is_template_approved') ) {
        return eventosapp_whatsapp_is_template_approved($template);
    }
    return in_array(strtoupper((string)($template['meta_status'] ?? '')), ['APPROVED', 'ACTIVE'], true);
}

/**
 * Estado normalizado para badges y filtros.
 */
function eventosapp_attendance_confirmation_whatsapp_template_status($template) {
    $status = is_array($template) ? ($template['meta_status'] ?? 'LOCAL') : 'LOCAL';
    if ( function_exists('eventosapp_whatsapp_templates_normalize_meta_status') ) {
        return eventosapp_whatsapp_templates_normalize_meta_status($status, 'LOCAL');
    }
    $status = strtoupper(sanitize_key((string)$status));
    return $status !== '' ? $status : 'LOCAL';
}

function eventosapp_attendance_confirmation_whatsapp_template_status_label($status) {
    if ( function_exists('eventosapp_whatsapp_templates_meta_status_label') ) {
        return eventosapp_whatsapp_templates_meta_status_label($status);
    }
    $status = strtoupper(sanitize_text_field((string)$status));
    $labels = [
        'LOCAL'       => 'Local, sin enviar',
        'APPROVED'    => 'Aprobada',
        'ACTIVE'      => 'Activa',
        'PENDING'     => 'En revisión',
        'IN_APPEAL'   => 'En apelación',
        'REJECTED'    => 'Rechazada',
        'PAUSED'      => 'Pausada',
        'DISABLED'    => 'Deshabilitada',
        'UNKNOWN'     => 'Desconocida',
        'DRY_RUN'     => 'Prueba interna',
    ];
    return $labels[$status] ?? ($status !== '' ? $status : 'Local');
}

function eventosapp_attendance_confirmation_whatsapp_template_status_class($status) {
    $status = strtoupper(sanitize_key((string)$status));
    if ( in_array($status, ['APPROVED', 'ACTIVE'], true) ) return 'is-approved';
    if ( in_array($status, ['PENDING', 'IN_APPEAL'], true) ) return 'is-pending';
    if ( in_array($status, ['REJECTED', 'PAUSED', 'DISABLED'], true) ) return 'is-error';
    return 'is-local';
}

/**
 * Cuentas emisoras disponibles.
 */
function eventosapp_attendance_confirmation_whatsapp_template_sender_accounts() {
    return function_exists('eventosapp_whatsapp_get_phone_accounts')
        ? eventosapp_whatsapp_get_phone_accounts()
        : [];
}

/**
 * WABA efectivo del registro, respetando la resolución multi-número del módulo
 * general cuando está disponible.
 */
function eventosapp_attendance_confirmation_whatsapp_template_effective_waba_id($template, $settings = null) {
    $template = is_array($template) ? $template : [];
    $settings = is_array($settings)
        ? $settings
        : (function_exists('eventosapp_whatsapp_templates_get_settings') ? eventosapp_whatsapp_templates_get_settings() : []);

    if ( function_exists('eventosapp_whatsapp_templates_get_template_waba_id') ) {
        return preg_replace('/\D+/', '', (string)eventosapp_whatsapp_templates_get_template_waba_id($template, $settings));
    }

    $waba_id = preg_replace('/\D+/', '', (string)($template['waba_id'] ?? ''));
    if ( $waba_id === '' ) {
        $waba_id = preg_replace('/\D+/', '', (string)($settings['waba_id'] ?? ''));
    }
    return $waba_id;
}

/**
 * Crea un ID local único y estable.
 */
function eventosapp_attendance_confirmation_whatsapp_template_unique_id($seed, $exclude_id = '') {
    $seed = sanitize_key((string)$seed);
    $exclude_id = sanitize_key((string)$exclude_id);
    if ( $seed === '' ) {
        $seed = 'attendance_confirmation_' . strtolower(wp_generate_password(8, false, false));
    }
    if ( strpos($seed, 'attendance_confirmation') !== 0 ) {
        $seed = 'attendance_confirmation_' . $seed;
    }

    $settings = function_exists('eventosapp_whatsapp_templates_get_settings')
        ? eventosapp_whatsapp_templates_get_settings()
        : ['templates' => []];
    $templates = is_array($settings['templates'] ?? null) ? $settings['templates'] : [];

    $candidate = $seed;
    $counter = 2;
    while ( isset($templates[$candidate]) && $candidate !== $exclude_id ) {
        $candidate = $seed . '_' . $counter;
        $counter++;
    }
    return $candidate;
}

/**
 * Nombre técnico único dentro del inventario local para el mismo idioma.
 */
function eventosapp_attendance_confirmation_whatsapp_template_has_duplicate_name($name, $language, $exclude_id = '', $waba_id = '') {
    $name = sanitize_key((string)$name);
    $language = sanitize_text_field((string)$language);
    $exclude_id = sanitize_key((string)$exclude_id);
    $waba_id = preg_replace('/\D+/', '', (string)$waba_id);

    if ( $name === '' ) return false;

    if ( function_exists('eventosapp_whatsapp_templates_find_local_duplicate') ) {
        return eventosapp_whatsapp_templates_find_local_duplicate($name, $language, $waba_id, $exclude_id) !== '';
    }

    $settings = function_exists('eventosapp_whatsapp_templates_get_settings')
        ? eventosapp_whatsapp_templates_get_settings()
        : ['templates' => []];

    foreach ( (array)($settings['templates'] ?? []) as $template_id => $template ) {
        if ( ! is_array($template) || sanitize_key((string)$template_id) === $exclude_id ) continue;
        if ( sanitize_key((string)($template['name'] ?? '')) !== $name ) continue;
        if ( sanitize_text_field((string)($template['language'] ?? '')) !== $language ) continue;
        if ( $waba_id !== '' ) {
            $candidate_waba = eventosapp_attendance_confirmation_whatsapp_template_effective_waba_id($template, $settings);
            if ( $candidate_waba !== $waba_id ) continue;
        }
        return true;
    }
    return false;
}

/**
 * Registro temporal para el formulario de creación.
 */
function eventosapp_attendance_confirmation_whatsapp_template_new_record() {
    $template = eventosapp_attendance_confirmation_whatsapp_template_defaults();
    $template['id'] = '';
    $template['is_default'] = '0';
    $template['title'] = 'Nueva confirmación de asistencia';
    $template['name'] = 'eventosapp_confirmacion_asistencia_' . wp_date('Ymd_His');
    $template['meta_template_id'] = '';
    $template['meta_status'] = 'LOCAL';
    $template['meta_category'] = '';
    $template['meta_rejected_reason'] = '';
    $template['last_api_message'] = '';
    $template['last_api_response'] = [];
    $template['last_meta_result'] = [];
    $template['meta_history'] = [];
    $template['meta_link_source'] = '';
    $template['meta_identity_reset_at'] = '';
    $template['meta_identity_reset_reason'] = '';
    $template['last_submitted_at'] = '';
    $template['last_checked_at'] = '';
    $template['created_at'] = current_time('mysql');
    $template['updated_at'] = current_time('mysql');
    return $template;
}

/**
 * Duplica una plantilla como registro local nuevo y elimina toda relación remota.
 */
function eventosapp_attendance_confirmation_whatsapp_template_duplicate($template_id) {
    $template_id = sanitize_key((string)$template_id);
    $source = eventosapp_attendance_confirmation_whatsapp_template_get($template_id);
    if ( ! is_array($source) ) {
        return new WP_Error('template_not_found', 'La plantilla que intentas duplicar no existe.');
    }

    $copy = $source;
    $copy['is_default'] = '0';
    $copy['title'] = sanitize_text_field((string)($source['title'] ?? 'Plantilla')) . ' — Copia';

    $base_name = sanitize_key((string)($source['name'] ?? 'eventosapp_confirmacion_asistencia')) . '_copia';
    $name = $base_name;
    $counter = 2;
    while ( eventosapp_attendance_confirmation_whatsapp_template_has_duplicate_name($name, $source['language'] ?? 'es', '', eventosapp_attendance_confirmation_whatsapp_template_effective_waba_id($source)) ) {
        $name = $base_name . '_' . $counter;
        $counter++;
    }
    $copy['name'] = $name;

    $new_id = eventosapp_attendance_confirmation_whatsapp_template_unique_id($name);
    $copy['id'] = $new_id;
    $copy['meta_template_id'] = '';
    $copy['meta_status'] = 'LOCAL';
    $copy['meta_category'] = '';
    $copy['meta_rejected_reason'] = '';
    $copy['last_api_message'] = '';
    $copy['last_api_response'] = [];
    $copy['last_meta_result'] = [];
    $copy['meta_history'] = [];
    $copy['meta_link_source'] = '';
    $copy['meta_identity_reset_at'] = '';
    $copy['meta_identity_reset_reason'] = '';
    $copy['duplicated_from_local_id'] = $template_id;
    $copy['last_submitted_at'] = '';
    $copy['last_checked_at'] = '';
    $copy['header_sample_handle'] = '';
    $copy['header_sample_file_name'] = '';
    $copy['header_sample_file_type'] = '';
    $copy['header_sample_file_size'] = 0;
    $copy['header_sample_uploaded_at'] = '';
    $copy['created_at'] = current_time('mysql');
    $copy['updated_at'] = current_time('mysql');

    eventosapp_attendance_confirmation_whatsapp_template_update($copy, $new_id);
    return $new_id;
}

/**
 * Mapa de eventos que conservan una plantilla en su programación.
 */
function eventosapp_attendance_confirmation_whatsapp_template_usage_map() {
    static $usage_map = null;
    if ( is_array($usage_map) ) return $usage_map;

    $usage_map = [];
    $event_ids = get_posts([
        'post_type'      => 'eventosapp_event',
        'post_status'    => ['publish', 'draft', 'private', 'pending', 'future'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    foreach ( $event_ids as $event_id ) {
        $config = get_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule', true);
        if ( ! is_array($config) ) continue;
        $template_id = sanitize_key((string)($config['whatsapp_template_id'] ?? ''));
        if ( $template_id === '' ) continue;
        if ( ! isset($usage_map[$template_id]) ) $usage_map[$template_id] = [];
        $usage_map[$template_id][] = absint($event_id);
    }

    return $usage_map;
}

function eventosapp_attendance_confirmation_whatsapp_template_usage($template_id) {
    $template_id = sanitize_key((string)$template_id);
    $usage_map = eventosapp_attendance_confirmation_whatsapp_template_usage_map();
    return isset($usage_map[$template_id]) && is_array($usage_map[$template_id])
        ? $usage_map[$template_id]
        : [];
}

/**
 * Elimina únicamente el registro local. La plantilla predeterminada no puede
 * eliminarse porque es el fallback histórico de los envíos existentes.
 */
function eventosapp_attendance_confirmation_whatsapp_template_delete($template_id) {
    $template_id = sanitize_key((string)$template_id);
    if ( $template_id === '' || $template_id === 'attendance_confirmation' ) {
        return new WP_Error('protected_template', 'La plantilla predeterminada no se puede eliminar. Puedes duplicarla y editar la copia.');
    }
    $usage = eventosapp_attendance_confirmation_whatsapp_template_usage($template_id);
    if ( ! empty($usage) ) {
        return new WP_Error('template_in_use', 'La plantilla está seleccionada en la programación de ' . count($usage) . ' evento(s). Cambia esas programaciones antes de eliminarla.');
    }
    if ( ! function_exists('eventosapp_whatsapp_templates_get_settings') || ! function_exists('eventosapp_whatsapp_templates_update_settings') ) {
        return new WP_Error('settings_unavailable', 'No está disponible el almacenamiento de Plantillas WhatsApp.');
    }

    $settings = eventosapp_whatsapp_templates_get_settings();
    if ( empty($settings['templates'][$template_id]) || ! is_array($settings['templates'][$template_id]) ) {
        return new WP_Error('template_not_found', 'La plantilla ya no existe en el inventario local.');
    }

    unset($settings['templates'][$template_id]);
    eventosapp_whatsapp_templates_update_settings($settings);
    return true;
}

/**
 * Componentes especializados QUICK_REPLY para Meta.
 */
function eventosapp_attendance_confirmation_whatsapp_template_build_meta_components($template) {
    $template = eventosapp_attendance_confirmation_whatsapp_template_prepare_body($template);
    $components = [];
    $header = strtoupper(sanitize_key((string)($template['header_format'] ?? 'NONE')));

    if ( $header === 'IMAGE' ) {
        $component = ['type' => 'HEADER', 'format' => 'IMAGE'];
        if ( ! empty($template['header_sample_handle']) ) {
            $component['example'] = ['header_handle' => [sanitize_text_field((string)$template['header_sample_handle'])]];
        }
        $components[] = $component;
    } elseif ( $header === 'TEXT' && ! empty($template['header_text']) ) {
        $components[] = [
            'type'   => 'HEADER',
            'format' => 'TEXT',
            'text'   => sanitize_text_field((string)$template['header_text']),
        ];
    }

    $body = [
        'type' => 'BODY',
        'text' => sanitize_textarea_field((string)(($template['body_text_meta'] ?? '') ?: ($template['body_text'] ?? ''))),
    ];
    $examples = preg_split('/\R+/', trim((string)($template['body_examples'] ?? '')));
    $examples = array_values(array_filter(array_map('sanitize_text_field', (array)$examples), 'strlen'));
    if ( ! empty($template['body_variable_map']) ) {
        $expected = count((array)$template['body_variable_map']);
        while ( count($examples) < $expected ) {
            $examples[] = 'Ejemplo ' . (count($examples) + 1);
        }
        $body['example'] = ['body_text' => [array_slice($examples, 0, $expected)]];
    }
    $components[] = $body;

    if ( ! empty($template['footer_text']) ) {
        $components[] = [
            'type' => 'FOOTER',
            'text' => sanitize_text_field((string)$template['footer_text']),
        ];
    }

    $components[] = [
        'type' => 'BUTTONS',
        'buttons' => [
            ['type' => 'QUICK_REPLY', 'text' => sanitize_text_field((string)($template['button_1_text'] ?? 'Sí, asistiré'))],
            ['type' => 'QUICK_REPLY', 'text' => sanitize_text_field((string)($template['button_2_text'] ?? 'No podré asistir'))],
        ],
    ];

    return $components;
}

/**
 * Validaciones específicas antes de enviar a Meta.
 */
function eventosapp_attendance_confirmation_whatsapp_template_validate($template) {
    $template = is_array($template) ? $template : [];
    $errors = [];

    if ( trim((string)($template['title'] ?? '')) === '' ) {
        $errors[] = 'Falta el título interno de la plantilla.';
    }
    if ( trim((string)($template['name'] ?? '')) === '' ) {
        $errors[] = 'Falta el nombre técnico de la plantilla.';
    } elseif ( ! preg_match('/^[a-z0-9_]+$/', (string)$template['name']) ) {
        $errors[] = 'El nombre técnico solo puede contener minúsculas, números y guion bajo.';
    }
    if ( trim((string)($template['language'] ?? '')) === '' ) {
        $errors[] = 'Falta el idioma.';
    }
    if ( trim((string)($template['body_text'] ?? '')) === '' ) {
        $errors[] = 'Falta el cuerpo del mensaje.';
    }
    if ( trim((string)($template['button_1_text'] ?? '')) === '' || trim((string)($template['button_2_text'] ?? '')) === '' ) {
        $errors[] = 'Los dos botones deben tener texto.';
    }

    foreach ( [1, 2] as $button_number ) {
        $button_text = (string)($template['button_' . $button_number . '_text'] ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($button_text, 'UTF-8') : strlen($button_text);
        if ( $length > 25 ) {
            $errors[] = 'El texto del botón ' . $button_number . ' no puede superar 25 caracteres.';
        }
    }

    if ( strtoupper((string)($template['header_format'] ?? 'NONE')) === 'IMAGE' ) {
        if ( trim((string)($template['header_sample_handle'] ?? '')) === '' ) {
            $errors[] = 'Debes subir una imagen de muestra a Meta para obtener el Header Sample Handle.';
        } elseif ( preg_match('/^https?:\/\//i', (string)$template['header_sample_handle']) ) {
            $errors[] = 'El Header Sample Handle no puede ser una URL pública.';
        }
    }

    if ( eventosapp_attendance_confirmation_whatsapp_template_effective_waba_id($template) === '' ) {
        $errors[] = 'Falta el WABA ID de la plantilla o de su número emisor.';
    }

    return $errors;
}

/**
 * Envía o actualiza una plantilla específica en Meta.
 */
function eventosapp_attendance_confirmation_whatsapp_template_submit($template_id = '') {
    $template_id = sanitize_key((string)($template_id ?: 'attendance_confirmation'));

    // El módulo general ya distingue creación, actualización permitida,
    // duplicado remoto, plantilla bloqueada, identidad obsoleta, permisos,
    // token, límites y cambios de categoría. Usarlo aquí evita dos motores con
    // respuestas diferentes para la misma API.
    if ( function_exists('eventosapp_whatsapp_templates_submit_to_meta') ) {
        return eventosapp_whatsapp_templates_submit_to_meta($template_id);
    }

    return [
        'ok' => false,
        'message' => 'No está disponible el motor unificado de Plantillas WhatsApp.',
        'error_type' => 'module_unavailable',
        'notice_level' => 'error',
    ];
}

/**
 * Convierte el formulario en un registro limpio.
 */
function eventosapp_attendance_confirmation_whatsapp_template_save_from_post($existing, $template_id = '') {
    $raw = isset($_POST['template']) && is_array($_POST['template'])
        ? wp_unslash($_POST['template'])
        : [];

    $is_new = ! is_array($existing);
    $existing = is_array($existing) ? $existing : [];
    $template = wp_parse_args($existing, eventosapp_attendance_confirmation_whatsapp_template_defaults());
    $template_id = sanitize_key((string)($template_id ?: ($template['id'] ?? '')));

    $title = sanitize_text_field((string)($raw['title'] ?? $template['title']));
    $name = sanitize_key((string)($raw['name'] ?? $template['name']));
    if ( $name === '' && $title !== '' ) {
        $name = sanitize_key('eventosapp_' . sanitize_title($title));
    }

    if ( $template_id === '' ) {
        $seed = preg_replace('/^eventosapp_/', '', $name);
        $template_id = eventosapp_attendance_confirmation_whatsapp_template_unique_id($seed ?: 'nueva');
    }

    $old_name = sanitize_key((string)($existing['name'] ?? ''));
    $old_language = sanitize_text_field((string)($existing['language'] ?? ''));
    $old_sender = preg_replace('/\D+/', '', (string)($existing['sender_phone_number_id'] ?? ''));
    $old_waba = ! empty($existing) ? eventosapp_attendance_confirmation_whatsapp_template_effective_waba_id($existing) : '';

    $template['id'] = $template_id;
    $template['attendance_confirmation'] = '1';
    $template['base_key'] = 'attendance_confirmation';
    $template['is_default'] = $template_id === 'attendance_confirmation' ? '1' : '0';
    $template['button_mode'] = 'quick_reply';
    $template['button_count'] = '2';
    $template['title'] = $title;
    $template['name'] = $name;
    $template['language'] = preg_replace('/[^a-zA-Z_\-]/', '', (string)($raw['language'] ?? $template['language']));

    $category_raw = strtoupper(sanitize_key((string)($raw['category'] ?? 'UTILITY')));
    $template['category'] = in_array($category_raw, ['UTILITY', 'MARKETING'], true) ? $category_raw : 'UTILITY';

    $header_format_raw = strtoupper(sanitize_key((string)($raw['header_format'] ?? 'IMAGE')));
    $template['header_format'] = in_array($header_format_raw, ['NONE', 'TEXT', 'IMAGE'], true) ? $header_format_raw : 'IMAGE';
    $template['header_text'] = sanitize_text_field((string)($raw['header_text'] ?? ''));
    $template['header_sample_handle'] = sanitize_text_field((string)($raw['header_sample_handle'] ?? $template['header_sample_handle']));
    $template['body_text'] = sanitize_textarea_field((string)($raw['body_text'] ?? $template['body_text']));
    $template['body_examples'] = sanitize_textarea_field((string)($raw['body_examples'] ?? $template['body_examples']));
    $template['footer_text'] = sanitize_text_field((string)($raw['footer_text'] ?? $template['footer_text']));
    $template['button_1_text'] = sanitize_text_field((string)($raw['button_1_text'] ?? 'Sí, asistiré'));
    $template['button_2_text'] = sanitize_text_field((string)($raw['button_2_text'] ?? 'No podré asistir'));
    $template['button_1_url'] = '';
    $template['button_1_example'] = '';
    $template['button_2_url'] = '';
    $template['button_2_example'] = '';
    $template['sender_phone_number_id'] = preg_replace('/\D+/', '', (string)($raw['sender_phone_number_id'] ?? ''));
    $template['waba_id'] = preg_replace('/\D+/', '', (string)($raw['waba_id'] ?? ''));

    $accounts = eventosapp_attendance_confirmation_whatsapp_template_sender_accounts();
    if ( $template['sender_phone_number_id'] !== '' && isset($accounts[$template['sender_phone_number_id']]) ) {
        $template['sender_phone_label'] = sanitize_text_field((string)($accounts[$template['sender_phone_number_id']]['alias'] ?? $accounts[$template['sender_phone_number_id']]['label'] ?? 'Número WhatsApp'));
    } elseif ( $template['sender_phone_number_id'] === '' ) {
        $template['sender_phone_label'] = 'Número por defecto';
    }

    $new_waba = eventosapp_attendance_confirmation_whatsapp_template_effective_waba_id($template);
    $identity_changes = [];
    if ( ! $is_new && $old_name !== $template['name'] ) $identity_changes[] = 'nombre técnico';
    if ( ! $is_new && $old_language !== $template['language'] ) $identity_changes[] = 'idioma';
    if ( ! $is_new && $old_sender !== $template['sender_phone_number_id'] ) $identity_changes[] = 'número emisor';
    if ( ! $is_new && $old_waba !== '' && $new_waba !== '' && $old_waba !== $new_waba ) $identity_changes[] = 'WABA';
    $has_remote_identity = ! empty($existing['meta_template_id']) || eventosapp_attendance_confirmation_whatsapp_template_status($existing) !== 'LOCAL';
    $detach_remote = ! empty($identity_changes) && $has_remote_identity;

    if ( $is_new || $detach_remote ) {
        $template['meta_template_id'] = '';
        $template['meta_status'] = 'LOCAL';
        $template['meta_category'] = '';
        $template['meta_rejected_reason'] = '';
        $template['meta_remote_name'] = '';
        $template['meta_remote_language'] = '';
        $template['meta_quality_score'] = '';
        $template['meta_category_mismatch'] = '0';
        $template['meta_category_changed_at'] = '';
        $template['meta_category_previous'] = '';
        $template['meta_link_source'] = '';
        $template['last_api_response'] = [];
        $template['last_meta_result'] = [];
        $template['last_submitted_at'] = '';
        $template['last_checked_at'] = '';
        $template['header_sample_handle'] = '';
        $template['header_sample_file_name'] = '';
        $template['header_sample_file_type'] = '';
        $template['header_sample_file_size'] = 0;
        $template['header_sample_uploaded_at'] = '';

        if ( $detach_remote ) {
            $template['meta_identity_reset_at'] = current_time('mysql');
            $template['meta_identity_reset_reason'] = 'Se cambió ' . implode(', ', $identity_changes) . '. EventosApp desvinculó la plantilla remota para que el próximo envío cree una plantilla nueva y no intente editar la anterior.';
            $template['last_api_message'] = $template['meta_identity_reset_reason'];
            if ( function_exists('eventosapp_whatsapp_templates_append_meta_history') ) {
                $template = eventosapp_whatsapp_templates_append_meta_history($template, 'identity_reset', [
                    'ok' => true,
                    'operation' => 'identity_reset',
                    'notice_level' => 'warning',
                    'message' => $template['meta_identity_reset_reason'],
                    'http_code' => 0,
                ], $existing);
            }
        } else {
            $template['last_api_message'] = '';
            $template['meta_history'] = [];
            $template['created_at'] = current_time('mysql');
        }
    }

    $template['meta_rejected_reason'] = function_exists('eventosapp_whatsapp_templates_normalize_rejected_reason')
        ? eventosapp_whatsapp_templates_normalize_rejected_reason($template['meta_rejected_reason'] ?? '')
        : sanitize_text_field((string)($template['meta_rejected_reason'] ?? ''));
    $template['updated_at'] = current_time('mysql');
    return eventosapp_attendance_confirmation_whatsapp_template_prepare_body($template);
}

/**
 * URL base del inventario.
 */
function eventosapp_attendance_confirmation_whatsapp_template_admin_url($args = []) {
    return add_query_arg(
        array_merge(['page' => 'eventosapp_attendance_confirmation_template'], is_array($args) ? $args : []),
        admin_url('admin.php')
    );
}

/**
 * Redirección con aviso administrativo.
 */
function eventosapp_attendance_confirmation_whatsapp_template_redirect($message, $ok = true, $args = [], $level = '') {
    $level = sanitize_key((string)$level);
    if ( ! in_array($level, ['success', 'warning', 'error', 'info'], true) ) {
        $level = $ok ? 'success' : 'error';
    }
    $url = eventosapp_attendance_confirmation_whatsapp_template_admin_url(array_merge([
        'evapp_attendance_ok'    => $ok ? 1 : 0,
        'evapp_attendance_level' => $level,
        'evapp_attendance_msg'   => rawurlencode((string)$message),
    ], is_array($args) ? $args : []));
    wp_safe_redirect($url);
    exit;
}

/**
 * Menú especializado.
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'eventosapp_dashboard',
        'Plantillas de Confirmación WhatsApp',
        'Plantillas Confirmación',
        'manage_options',
        'eventosapp_attendance_confirmation_template',
        'eventosapp_attendance_confirmation_whatsapp_template_render_page',
        22
    );
}, 22);

/**
 * Guardar, enviar o consultar desde el editor.
 */
add_action('admin_post_eventosapp_attendance_confirmation_template_action', function() {
    if ( ! eventosapp_attendance_confirmation_whatsapp_template_can_manage() ) {
        wp_die('Permisos insuficientes.');
    }
    check_admin_referer('eventosapp_attendance_confirmation_template_action', 'evapp_attendance_template_nonce');

    $action = sanitize_key((string)($_POST['template_action'] ?? 'save'));
    $template_id = sanitize_key((string)($_POST['template_id'] ?? ''));
    $existing = $template_id !== '' ? eventosapp_attendance_confirmation_whatsapp_template_get($template_id) : null;
    $template = eventosapp_attendance_confirmation_whatsapp_template_save_from_post($existing, $template_id);
    $template_id = sanitize_key((string)$template['id']);

    $template_waba_id = eventosapp_attendance_confirmation_whatsapp_template_effective_waba_id($template);
    if ( eventosapp_attendance_confirmation_whatsapp_template_has_duplicate_name($template['name'], $template['language'], $template_id, $template_waba_id) ) {
        $redirect_args = is_array($existing)
            ? ['view' => 'edit', 'template_id' => $template_id]
            : ['view' => 'new'];
        eventosapp_attendance_confirmation_whatsapp_template_redirect(
            'Ya existe una plantilla local con el mismo nombre técnico e idioma. Usa un nombre técnico diferente.',
            false,
            $redirect_args
        );
    }

    $ok = true;
    $message = 'Plantilla guardada localmente.';

    if ( ! empty($_FILES['header_sample_file']) && is_array($_FILES['header_sample_file']) && (int)($_FILES['header_sample_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ) {
        if ( function_exists('eventosapp_whatsapp_templates_upload_header_sample_to_meta') ) {
            $upload = eventosapp_whatsapp_templates_upload_header_sample_to_meta($_FILES['header_sample_file'], $template);
            if ( ! empty($upload['ok']) ) {
                $template['header_sample_handle'] = sanitize_text_field((string)$upload['handle']);
                $template['header_sample_file_name'] = sanitize_file_name((string)($upload['file']['name'] ?? ''));
                $template['header_sample_file_type'] = sanitize_mime_type((string)($upload['file']['type'] ?? ''));
                $template['header_sample_file_size'] = absint($upload['file']['size'] ?? 0);
                $template['header_sample_uploaded_at'] = current_time('mysql');
                $message = sanitize_text_field((string)($upload['message'] ?? 'Muestra subida a Meta.'));
            } else {
                $ok = false;
                $message = sanitize_text_field((string)($upload['message'] ?? 'No se pudo subir la imagen de muestra.'));
            }
        } else {
            $ok = false;
            $message = 'No está disponible el cargador de muestras del módulo Plantillas WhatsApp.';
        }
    }

    eventosapp_attendance_confirmation_whatsapp_template_update($template, $template_id);

    if ( $action === 'submit' && $ok ) {
        $result = eventosapp_attendance_confirmation_whatsapp_template_submit($template_id);
        $ok = ! empty($result['ok']);
        $message = sanitize_text_field((string)($result['message'] ?? ($ok ? 'Plantilla enviada a Meta.' : 'No se pudo enviar la plantilla a Meta.')));
    } elseif ( $action === 'check' ) {
        if ( function_exists('eventosapp_whatsapp_templates_check_status') ) {
            $result = eventosapp_whatsapp_templates_check_status($template_id);
            $ok = ! empty($result['ok']);
            $message = sanitize_text_field((string)($result['message'] ?? 'Estado consultado.'));
        } else {
            $ok = false;
            $message = 'No está disponible la consulta de estado del módulo Plantillas WhatsApp.';
        }
    }

    $notice_level = isset($result['notice_level']) ? sanitize_key((string)$result['notice_level']) : ($ok ? 'success' : 'error');
    eventosapp_attendance_confirmation_whatsapp_template_redirect(
        $message,
        $ok,
        ['view' => 'edit', 'template_id' => $template_id],
        $notice_level
    );
});

/**
 * Duplica una plantilla desde el inventario.
 */
add_action('admin_post_eventosapp_attendance_confirmation_template_duplicate', function() {
    if ( ! eventosapp_attendance_confirmation_whatsapp_template_can_manage() ) wp_die('Permisos insuficientes.');
    $template_id = sanitize_key((string)($_POST['template_id'] ?? ''));
    check_admin_referer('eventosapp_attendance_confirmation_template_duplicate_' . $template_id, 'evapp_attendance_duplicate_nonce');

    $new_id = eventosapp_attendance_confirmation_whatsapp_template_duplicate($template_id);
    if ( is_wp_error($new_id) ) {
        eventosapp_attendance_confirmation_whatsapp_template_redirect($new_id->get_error_message(), false);
    }

    eventosapp_attendance_confirmation_whatsapp_template_redirect(
        'Plantilla duplicada. Revisa el nombre técnico, carga una nueva imagen de muestra y envíala a Meta cuando esté lista.',
        true,
        ['view' => 'edit', 'template_id' => $new_id]
    );
});

/**
 * Elimina una plantilla local personalizada.
 */
add_action('admin_post_eventosapp_attendance_confirmation_template_delete', function() {
    if ( ! eventosapp_attendance_confirmation_whatsapp_template_can_manage() ) wp_die('Permisos insuficientes.');
    $template_id = sanitize_key((string)($_POST['template_id'] ?? ''));
    check_admin_referer('eventosapp_attendance_confirmation_template_delete_' . $template_id, 'evapp_attendance_delete_nonce');

    $result = eventosapp_attendance_confirmation_whatsapp_template_delete($template_id);
    if ( is_wp_error($result) ) {
        eventosapp_attendance_confirmation_whatsapp_template_redirect($result->get_error_message(), false);
    }

    eventosapp_attendance_confirmation_whatsapp_template_redirect(
        'Plantilla eliminada del inventario local. Esta acción no elimina una plantilla que ya exista en Meta.',
        true
    );
});

/**
 * Consulta una plantilla sin abrir el editor.
 */
add_action('admin_post_eventosapp_attendance_confirmation_template_check', function() {
    if ( ! eventosapp_attendance_confirmation_whatsapp_template_can_manage() ) wp_die('Permisos insuficientes.');
    $template_id = sanitize_key((string)($_POST['template_id'] ?? ''));
    check_admin_referer('eventosapp_attendance_confirmation_template_check_' . $template_id, 'evapp_attendance_check_nonce');

    if ( ! function_exists('eventosapp_whatsapp_templates_check_status') ) {
        eventosapp_attendance_confirmation_whatsapp_template_redirect('No está disponible la consulta de estado del módulo Plantillas WhatsApp.', false);
    }

    $result = eventosapp_whatsapp_templates_check_status($template_id);
    eventosapp_attendance_confirmation_whatsapp_template_redirect(
        sanitize_text_field((string)($result['message'] ?? 'Estado consultado.')),
        ! empty($result['ok']),
        [],
        sanitize_key((string)($result['notice_level'] ?? (! empty($result['ok']) ? 'success' : 'error')))
    );
});

/**
 * Sincroniza únicamente el inventario de confirmación.
 */
add_action('admin_post_eventosapp_attendance_confirmation_templates_sync_all', function() {
    if ( ! eventosapp_attendance_confirmation_whatsapp_template_can_manage() ) wp_die('Permisos insuficientes.');
    check_admin_referer('eventosapp_attendance_confirmation_templates_sync_all', 'evapp_attendance_sync_nonce');

    if ( ! function_exists('eventosapp_whatsapp_templates_check_status') ) {
        eventosapp_attendance_confirmation_whatsapp_template_redirect('No está disponible la consulta de estado del módulo Plantillas WhatsApp.', false);
    }

    $counts = [
        'updated' => 0,
        'warnings' => 0,
        'errors' => 0,
        'missing' => 0,
        'recategorized' => 0,
    ];
    foreach ( eventosapp_attendance_confirmation_whatsapp_template_get_all(false) as $template_id => $template ) {
        $result = eventosapp_whatsapp_templates_check_status($template_id);
        $level = sanitize_key((string)($result['notice_level'] ?? (! empty($result['ok']) ? 'success' : 'error')));
        if ( ! empty($result['ok']) ) $counts['updated']++;
        elseif ( ($result['error_type'] ?? '') === 'not_found' ) $counts['missing']++;
        elseif ( $level === 'warning' ) $counts['warnings']++;
        else $counts['errors']++;

        $current = eventosapp_attendance_confirmation_whatsapp_template_get($template_id);
        if ( is_array($current) && function_exists('eventosapp_whatsapp_templates_category_mismatch') && eventosapp_whatsapp_templates_category_mismatch($current) ) {
            $counts['recategorized']++;
        }
    }

    $message = sprintf(
        'Sincronización terminada. Consultadas: %d. Sin coincidencia remota: %d. Advertencias: %d. Errores: %d. Recategorizadas por Meta: %d.',
        $counts['updated'],
        $counts['missing'],
        $counts['warnings'],
        $counts['errors'],
        $counts['recategorized']
    );
    $has_success = $counts['updated'] > 0;
    $level = ($counts['warnings'] + $counts['missing'] + $counts['errors']) > 0 ? 'warning' : 'success';
    eventosapp_attendance_confirmation_whatsapp_template_redirect($message, $has_success, [], $level);
});

/**
 * CSS compartido del inventario y editor.
 */
function eventosapp_attendance_confirmation_whatsapp_template_render_styles() {
    ?>
    <style>
        .evapp-rsvp-wrap{max-width:1440px;margin-top:18px}
        .evapp-rsvp-head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin:18px 0 20px}
        .evapp-rsvp-head h1{margin:0 0 6px;font-size:28px;line-height:1.2}
        .evapp-rsvp-head p{margin:0;color:#646970;max-width:820px;font-size:14px;line-height:1.5}
        .evapp-rsvp-head-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
        .evapp-rsvp-kicker{display:block;color:#3858e9;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}
        .evapp-rsvp-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:18px 0}
        .evapp-rsvp-stat{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
        .evapp-rsvp-stat strong{display:block;font-size:26px;line-height:1;margin-bottom:6px;color:#1d2327}
        .evapp-rsvp-stat span{color:#646970;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
        .evapp-rsvp-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px;margin:18px 0;box-shadow:0 1px 3px rgba(0,0,0,.04)}
        .evapp-rsvp-card h2,.evapp-rsvp-card h3{margin-top:0}
        .evapp-rsvp-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}
        .evapp-rsvp-filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .evapp-rsvp-filters input[type=search]{min-width:280px}
        .evapp-rsvp-table-wrap{overflow:auto;border:1px solid #e2e4e7;border-radius:10px}
        .evapp-rsvp-table{width:100%;min-width:1080px;border-collapse:collapse;background:#fff}
        .evapp-rsvp-table th{background:#f6f7f7;color:#50575e;font-size:12px;text-transform:uppercase;letter-spacing:.03em;text-align:left;padding:12px;border-bottom:1px solid #dcdcde}
        .evapp-rsvp-table td{padding:14px 12px;border-bottom:1px solid #edf0f2;vertical-align:top}
        .evapp-rsvp-table tr:last-child td{border-bottom:0}
        .evapp-rsvp-title{font-weight:700;font-size:14px;color:#1d2327;margin-bottom:4px}
        .evapp-rsvp-code{font-family:Menlo,Consolas,monospace;color:#646970;font-size:11px;word-break:break-word}
        .evapp-rsvp-status{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#f0f0f1;color:#50575e}
        .evapp-rsvp-status:before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor;opacity:.75}
        .evapp-rsvp-status.is-approved{background:#edfaef;color:#16753b}
        .evapp-rsvp-status.is-pending{background:#fff8e5;color:#8a5a00}
        .evapp-rsvp-status.is-error{background:#fcf0f1;color:#b32d2e}
        .evapp-rsvp-status.is-local{background:#eef3ff;color:#2745a6}
        .evapp-rsvp-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
        .evapp-rsvp-actions form{margin:0}
        .evapp-rsvp-meta{color:#646970;font-size:12px;line-height:1.45}
        .evapp-rsvp-category{display:inline-block;padding:2px 7px;border-radius:999px;font-size:11px;font-weight:700;background:#eef3ff;color:#2745a6;margin:2px 0}
        .evapp-rsvp-category.marketing{background:#fff3cd;color:#7c4a03}
        .evapp-rsvp-category.utility{background:#e7f1ff;color:#0a4b78}
        .evapp-rsvp-cat-change{color:#b32d2e;font-weight:700;font-size:11px;margin:5px 0}
        .evapp-rsvp-last-message{margin-top:8px;padding:8px;background:#f6f7f7;border-radius:7px;font-size:11px;line-height:1.45;color:#50575e}
        .evapp-wa-meta-details{margin-top:8px;border:1px solid #dcdcde;border-radius:7px;background:#fff}
        .evapp-wa-meta-details summary{cursor:pointer;padding:7px 9px;font-weight:600;color:#2271b1;font-size:11px}
        .evapp-wa-meta-details-body{padding:0 9px 9px;font-size:11px;line-height:1.45;color:#50575e}
        .evapp-wa-meta-details-body p{margin:7px 0}
        .evapp-wa-meta-history{margin:8px 0 0 18px;max-height:180px;overflow:auto}
        .evapp-wa-meta-history li{margin-bottom:7px;padding-bottom:7px;border-bottom:1px solid #edf0f2}
        .evapp-rsvp-editor-grid{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:20px;align-items:start}
        .evapp-rsvp-sidebar{position:sticky;top:46px}
        .evapp-rsvp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
        .evapp-rsvp-field{margin-bottom:16px}
        .evapp-rsvp-field:last-child{margin-bottom:0}
        .evapp-rsvp-field label{display:block;font-weight:600;margin-bottom:6px;color:#1d2327}
        .evapp-rsvp-field input[type=text],.evapp-rsvp-field input[type=file],.evapp-rsvp-field select,.evapp-rsvp-field textarea{width:100%;max-width:none}
        .evapp-rsvp-field textarea{font-family:Menlo,Consolas,monospace;line-height:1.5}
        .evapp-rsvp-help{font-size:12px;color:#646970;line-height:1.45;margin:5px 0 0}
        .evapp-rsvp-warning{background:#fff8e5;border-left:4px solid #dba617;padding:11px 13px;margin:12px 0;line-height:1.5}
        .evapp-rsvp-info{background:#f0f6fc;border-left:4px solid #72aee6;padding:11px 13px;margin:12px 0;line-height:1.5}
        .evapp-rsvp-preview{background:#f6f7f7;border:1px solid #dcdcde;border-radius:10px;padding:14px}
        .evapp-rsvp-preview-body{white-space:pre-wrap;line-height:1.5;color:#1d2327;min-height:120px}
        .evapp-rsvp-preview-footer{color:#646970;font-size:12px;margin-top:12px;padding-top:10px;border-top:1px solid #dcdcde}
        .evapp-rsvp-preview-buttons{display:grid;gap:7px;margin-top:12px}
        .evapp-rsvp-preview-button{display:block;text-align:center;background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:9px;color:#2271b1;font-weight:600}
        .evapp-rsvp-file-meta{background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:10px;margin-top:8px;font-size:12px;color:#50575e}
        .evapp-rsvp-actionbar{position:sticky;bottom:0;z-index:20;display:flex;justify-content:space-between;align-items:center;gap:12px;background:rgba(255,255,255,.96);backdrop-filter:blur(8px);border:1px solid #dcdcde;border-radius:12px;padding:12px 14px;margin-top:18px;box-shadow:0 -4px 18px rgba(0,0,0,.06)}
        .evapp-rsvp-actionbar-buttons{display:flex;gap:8px;flex-wrap:wrap}
        @media(max-width:1100px){.evapp-rsvp-editor-grid{grid-template-columns:1fr}.evapp-rsvp-sidebar{position:static}.evapp-rsvp-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:782px){.evapp-rsvp-head{display:block}.evapp-rsvp-head-actions{justify-content:flex-start;margin-top:14px}.evapp-rsvp-grid,.evapp-rsvp-stats{grid-template-columns:1fr}.evapp-rsvp-filters input[type=search]{min-width:0;width:100%}.evapp-rsvp-actionbar{position:static;display:block}.evapp-rsvp-actionbar-buttons{margin-top:10px}}
    </style>
    <?php
}

/**
 * Aviso basado en query string.
 */
function eventosapp_attendance_confirmation_whatsapp_template_render_notice() {
    if ( empty($_GET['evapp_attendance_msg']) ) return;
    $ok = ! empty($_GET['evapp_attendance_ok']);
    $level = sanitize_key((string)($_GET['evapp_attendance_level'] ?? ($ok ? 'success' : 'error')));
    $classes = [
        'success' => 'notice-success',
        'warning' => 'notice-warning',
        'info'    => 'notice-info',
        'error'   => 'notice-error',
    ];
    $message = sanitize_text_field(wp_unslash($_GET['evapp_attendance_msg']));
    echo '<div class="notice ' . esc_attr($classes[$level] ?? ($ok ? 'notice-success' : 'notice-error')) . ' is-dismissible"><p><strong>EventosApp:</strong> ' . esc_html($message) . '</p></div>';
}

/**
 * Página principal: inventario o editor.
 */
function eventosapp_attendance_confirmation_whatsapp_template_render_page() {
    if ( ! eventosapp_attendance_confirmation_whatsapp_template_can_manage() ) {
        wp_die('Permisos insuficientes.');
    }

    $view = sanitize_key((string)($_GET['view'] ?? 'list'));
    $template_id = sanitize_key((string)($_GET['template_id'] ?? ''));

    echo '<div class="wrap evapp-rsvp-wrap">';
    eventosapp_attendance_confirmation_whatsapp_template_render_styles();
    eventosapp_attendance_confirmation_whatsapp_template_render_notice();

    if ( $view === 'edit' || $view === 'new' ) {
        eventosapp_attendance_confirmation_whatsapp_template_render_editor($template_id, $view === 'new');
    } else {
        eventosapp_attendance_confirmation_whatsapp_template_render_inventory();
    }

    echo '</div>';
}

/**
 * Inventario de plantillas de confirmación.
 */
function eventosapp_attendance_confirmation_whatsapp_template_render_inventory() {
    $templates = eventosapp_attendance_confirmation_whatsapp_template_get_all(false);
    $search = sanitize_text_field((string)($_GET['s'] ?? ''));
    $status_filter = strtoupper(sanitize_text_field((string)($_GET['status'] ?? '')));

    $stats = ['total' => 0, 'approved' => 0, 'pending' => 0, 'alerts' => 0];
    foreach ( $templates as $template ) {
        $stats['total']++;
        $status = eventosapp_attendance_confirmation_whatsapp_template_status($template);
        if ( in_array($status, ['APPROVED', 'ACTIVE'], true) ) $stats['approved']++;
        elseif ( in_array($status, ['PENDING', 'IN_APPEAL'], true) ) $stats['pending']++;

        $reason = function_exists('eventosapp_whatsapp_templates_normalize_rejected_reason')
            ? eventosapp_whatsapp_templates_normalize_rejected_reason($template['meta_rejected_reason'] ?? '')
            : sanitize_text_field((string)($template['meta_rejected_reason'] ?? ''));
        $category_changed = function_exists('eventosapp_whatsapp_templates_category_mismatch')
            ? eventosapp_whatsapp_templates_category_mismatch($template)
            : false;
        $last_level = sanitize_key((string)($template['last_meta_result']['notice_level'] ?? ''));
        if ( $reason !== '' || $category_changed || in_array($last_level, ['warning', 'error'], true) || in_array($status, ['REJECTED','PAUSED','DISABLED','UNKNOWN'], true) ) {
            $stats['alerts']++;
        }
    }

    $filtered = [];
    foreach ( $templates as $template_id => $template ) {
        $status = eventosapp_attendance_confirmation_whatsapp_template_status($template);
        if ( $status_filter !== '' && $status !== $status_filter ) continue;
        if ( $search !== '' ) {
            $haystack = strtolower(implode(' ', [
                (string)$template_id,
                (string)($template['title'] ?? ''),
                (string)($template['name'] ?? ''),
                (string)($template['language'] ?? ''),
                (string)($template['sender_phone_label'] ?? ''),
            ]));
            if ( strpos($haystack, strtolower($search)) === false ) continue;
        }
        $filtered[$template_id] = $template;
    }

    ?>
    <div class="evapp-rsvp-head">
        <div>
            <span class="evapp-rsvp-kicker">WhatsApp · Confirmación de asistencia</span>
            <h1>Inventario de plantillas de confirmación</h1>
            <p>Crea y administra todas las variantes que necesites. Cada plantilla conserva su propio nombre técnico, idioma, categoría, número emisor, WABA, contenido y estado en Meta.</p>
        </div>
        <div class="evapp-rsvp-head-actions">
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_templates')); ?>">Plantillas WhatsApp generales</a>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="eventosapp_attendance_confirmation_templates_sync_all">
                <?php wp_nonce_field('eventosapp_attendance_confirmation_templates_sync_all', 'evapp_attendance_sync_nonce'); ?>
                <button type="submit" class="button">Sincronizar estados</button>
            </form>
            <a class="button button-primary" href="<?php echo esc_url(eventosapp_attendance_confirmation_whatsapp_template_admin_url(['view' => 'new'])); ?>">Crear nueva plantilla</a>
        </div>
    </div>

    <div class="evapp-rsvp-stats">
        <div class="evapp-rsvp-stat"><strong><?php echo esc_html($stats['total']); ?></strong><span>Total</span></div>
        <div class="evapp-rsvp-stat"><strong><?php echo esc_html($stats['approved']); ?></strong><span>Aprobadas / activas</span></div>
        <div class="evapp-rsvp-stat"><strong><?php echo esc_html($stats['pending']); ?></strong><span>Pendientes</span></div>
        <div class="evapp-rsvp-stat"><strong><?php echo esc_html($stats['alerts']); ?></strong><span>Cambios / alertas</span></div>
    </div>

    <div class="evapp-rsvp-card">
        <div class="evapp-rsvp-toolbar">
            <div>
                <h2 style="margin-bottom:4px">Plantillas disponibles</h2>
                <p class="evapp-rsvp-help">Las aprobadas o activas aparecen como opciones de envío. Las demás pueden mantenerse en preparación o revisión sin afectar los envíos existentes.</p>
            </div>
            <form class="evapp-rsvp-filters" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="eventosapp_attendance_confirmation_template">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Buscar por título, nombre técnico o emisor">
                <select name="status">
                    <option value="">Todos los estados</option>
                    <?php foreach ( ['APPROVED','ACTIVE','PENDING','IN_APPEAL','REJECTED','PAUSED','DISABLED','LOCAL','UNKNOWN'] as $status_option ) : ?>
                        <option value="<?php echo esc_attr($status_option); ?>" <?php selected($status_filter, $status_option); ?>><?php echo esc_html(eventosapp_attendance_confirmation_whatsapp_template_status_label($status_option)); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button">Filtrar</button>
                <?php if ( $search !== '' || $status_filter !== '' ) : ?><a class="button" href="<?php echo esc_url(eventosapp_attendance_confirmation_whatsapp_template_admin_url()); ?>">Limpiar</a><?php endif; ?>
            </form>
        </div>

        <div class="evapp-rsvp-table-wrap">
            <table class="evapp-rsvp-table">
                <thead>
                    <tr>
                        <th>Plantilla</th>
                        <th>Estado Meta</th>
                        <th>Configuración</th>
                        <th>Número emisor / WABA</th>
                        <th>Actualización</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty($filtered) ) : ?>
                    <tr><td colspan="6">No hay plantillas que coincidan con los filtros.</td></tr>
                <?php else : ?>
                    <?php foreach ( $filtered as $template_id => $template ) :
                        $status = eventosapp_attendance_confirmation_whatsapp_template_status($template);
                        $waba = eventosapp_attendance_confirmation_whatsapp_template_effective_waba_id($template);
                        $is_default = $template_id === 'attendance_confirmation';
                        $requested_category = function_exists('eventosapp_whatsapp_templates_sanitize_category') ? eventosapp_whatsapp_templates_sanitize_category($template['category'] ?? 'UTILITY') : strtoupper((string)($template['category'] ?? 'UTILITY'));
                        $remote_category = function_exists('eventosapp_whatsapp_templates_normalize_meta_category') ? eventosapp_whatsapp_templates_normalize_meta_category($template['meta_category'] ?? '') : strtoupper((string)($template['meta_category'] ?? ''));
                        $category_mismatch = $remote_category !== '' && $remote_category !== $requested_category;
                        $rejected_reason = function_exists('eventosapp_whatsapp_templates_normalize_rejected_reason') ? eventosapp_whatsapp_templates_normalize_rejected_reason($template['meta_rejected_reason'] ?? '') : sanitize_text_field((string)($template['meta_rejected_reason'] ?? ''));
                        ?>
                        <tr data-template-id="<?php echo esc_attr($template_id); ?>">
                            <td>
                                <div class="evapp-rsvp-title"><?php echo esc_html($template['title'] ?? $template['name'] ?? 'Plantilla'); ?><?php if ( $is_default ) : ?> <span class="evapp-rsvp-status is-local" style="padding:2px 7px">Predeterminada</span><?php endif; ?></div>
                                <div class="evapp-rsvp-code"><?php echo esc_html($template['name'] ?? ''); ?></div>
                                <div class="evapp-rsvp-meta">ID local: <?php echo esc_html($template_id); ?></div>
                            </td>
                            <td>
                                <span class="evapp-rsvp-status <?php echo esc_attr(eventosapp_attendance_confirmation_whatsapp_template_status_class($status)); ?>"><?php echo esc_html(eventosapp_attendance_confirmation_whatsapp_template_status_label($status)); ?></span>
                                <?php if ( ! empty($template['meta_template_id']) ) : ?><div class="evapp-rsvp-meta" style="margin-top:7px">ID Meta: <?php echo esc_html($template['meta_template_id']); ?></div><?php endif; ?>
                                <?php if ( $rejected_reason !== '' ) : ?><div class="evapp-rsvp-meta" style="color:#b32d2e;margin-top:5px"><?php echo esc_html($rejected_reason); ?></div><?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html(strtoupper((string)($template['language'] ?? 'es'))); ?></strong><br>
                                <span class="evapp-rsvp-meta">Solicitada:</span> <span class="evapp-rsvp-category <?php echo esc_attr(strtolower($requested_category)); ?>"><?php echo esc_html(ucfirst(strtolower($requested_category))); ?></span>
                                <?php if ( $remote_category !== '' ) : ?><br><span class="evapp-rsvp-meta">Meta:</span> <span class="evapp-rsvp-category <?php echo esc_attr(strtolower($remote_category)); ?>"><?php echo esc_html(ucfirst(strtolower($remote_category))); ?></span><?php endif; ?>
                                <?php if ( $category_mismatch ) : ?><div class="evapp-rsvp-cat-change">Meta cambió la categoría<?php if ( ! empty($template['meta_category_changed_at']) ) : ?> · <?php echo esc_html($template['meta_category_changed_at']); ?><?php endif; ?></div><?php endif; ?>
                                <span class="evapp-rsvp-meta"><?php echo strtoupper((string)($template['header_format'] ?? 'NONE')) === 'IMAGE' ? 'Encabezado con imagen dinámica' : (strtoupper((string)($template['header_format'] ?? 'NONE')) === 'TEXT' ? 'Encabezado de texto' : 'Sin encabezado'); ?><br>Programaciones guardadas: <?php echo esc_html(count(eventosapp_attendance_confirmation_whatsapp_template_usage($template_id))); ?></span>
                            </td>
                            <td>
                                <strong><?php echo esc_html($template['sender_phone_label'] ?? 'Número por defecto'); ?></strong><br>
                                <?php if ( ! empty($template['sender_phone_number_id']) ) : ?><span class="evapp-rsvp-code"><?php echo esc_html($template['sender_phone_number_id']); ?></span><br><?php endif; ?>
                                <span class="evapp-rsvp-meta">WABA: <?php echo esc_html($waba ?: 'Sin configurar'); ?></span>
                            </td>
                            <td>
                                <span class="evapp-rsvp-meta"><strong>Local:</strong> <?php echo esc_html($template['updated_at'] ?? '—'); ?><br><strong>Meta:</strong> <?php echo esc_html($template['last_checked_at'] ?? $template['last_submitted_at'] ?? '—'); ?></span>
                                <?php if ( ! empty($template['last_api_message']) ) : ?><div class="evapp-rsvp-last-message"><?php echo esc_html($template['last_api_message']); ?></div><?php endif; ?>
                                <?php if ( function_exists('eventosapp_whatsapp_templates_render_meta_diagnostics') ) eventosapp_whatsapp_templates_render_meta_diagnostics($template, true); ?>
                            </td>
                            <td>
                                <div class="evapp-rsvp-actions">
                                    <a class="button button-primary" href="<?php echo esc_url(eventosapp_attendance_confirmation_whatsapp_template_admin_url(['view' => 'edit', 'template_id' => $template_id])); ?>">Editar</a>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="eventosapp_attendance_confirmation_template_check">
                                        <input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>">
                                        <?php wp_nonce_field('eventosapp_attendance_confirmation_template_check_' . $template_id, 'evapp_attendance_check_nonce'); ?>
                                        <button type="submit" class="button">Consultar estado</button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="eventosapp_attendance_confirmation_template_duplicate">
                                        <input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>">
                                        <?php wp_nonce_field('eventosapp_attendance_confirmation_template_duplicate_' . $template_id, 'evapp_attendance_duplicate_nonce'); ?>
                                        <button type="submit" class="button">Duplicar</button>
                                    </form>
                                    <?php if ( ! $is_default ) : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Esta acción elimina solo el registro local. Si la plantilla existe en Meta, seguirá existiendo allí. ¿Deseas continuar?');">
                                            <input type="hidden" name="action" value="eventosapp_attendance_confirmation_template_delete">
                                            <input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>">
                                            <?php wp_nonce_field('eventosapp_attendance_confirmation_template_delete_' . $template_id, 'evapp_attendance_delete_nonce'); ?>
                                            <button type="submit" class="button-link-delete">Eliminar local</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

/**
 * Editor especializado de una plantilla.
 */
function eventosapp_attendance_confirmation_whatsapp_template_render_editor($template_id = '', $is_new = false) {
    $template_id = sanitize_key((string)$template_id);
    $template = $is_new ? eventosapp_attendance_confirmation_whatsapp_template_new_record() : eventosapp_attendance_confirmation_whatsapp_template_get($template_id);

    if ( ! is_array($template) ) {
        echo '<div class="notice notice-error"><p>La plantilla solicitada no existe.</p></div>';
        echo '<p><a class="button" href="' . esc_url(eventosapp_attendance_confirmation_whatsapp_template_admin_url()) . '">Volver al inventario</a></p>';
        return;
    }

    $settings = function_exists('eventosapp_whatsapp_templates_get_settings') ? eventosapp_whatsapp_templates_get_settings() : [];
    $accounts = eventosapp_attendance_confirmation_whatsapp_template_sender_accounts();
    $status = eventosapp_attendance_confirmation_whatsapp_template_status($template);
    $effective_waba = eventosapp_attendance_confirmation_whatsapp_template_effective_waba_id($template, $settings);
    $is_remote_locked = ! empty($template['meta_template_id']) && in_array($status, ['APPROVED', 'ACTIVE', 'PENDING', 'IN_APPEAL'], true);
    $editor_title = $is_new ? 'Crear plantilla de confirmación' : 'Editar plantilla de confirmación';
    ?>
    <div class="evapp-rsvp-head">
        <div>
            <span class="evapp-rsvp-kicker">WhatsApp · Confirmación de asistencia</span>
            <h1><?php echo esc_html($editor_title); ?></h1>
            <p>Configura una plantilla con dos respuestas rápidas. El sistema agregará los payloads firmados durante el envío para asociar correctamente cada respuesta con su ticket.</p>
        </div>
        <div class="evapp-rsvp-head-actions">
            <a class="button" href="<?php echo esc_url(eventosapp_attendance_confirmation_whatsapp_template_admin_url()); ?>">Volver al inventario</a>
            <?php if ( ! $is_new ) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="eventosapp_attendance_confirmation_template_duplicate">
                    <input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>">
                    <?php wp_nonce_field('eventosapp_attendance_confirmation_template_duplicate_' . $template_id, 'evapp_attendance_duplicate_nonce'); ?>
                    <button type="submit" class="button">Duplicar plantilla</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( $is_remote_locked ) : ?>
        <div class="evapp-rsvp-warning"><strong>Importante:</strong> Meta normalmente no permite modificar una plantilla aprobada o en revisión. Puedes guardar cambios locales, pero para publicar una variante distinta es más seguro duplicarla, cambiar el nombre técnico y enviarla como plantilla nueva.</div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="eventosapp_attendance_confirmation_template_action">
        <input type="hidden" name="template_id" value="<?php echo esc_attr($is_new ? '' : $template_id); ?>">
        <?php wp_nonce_field('eventosapp_attendance_confirmation_template_action', 'evapp_attendance_template_nonce'); ?>

        <div class="evapp-rsvp-editor-grid">
            <div>
                <div class="evapp-rsvp-card">
                    <h2>Identificación y conexión</h2>
                    <div class="evapp-rsvp-grid">
                        <div class="evapp-rsvp-field">
                            <label for="evapp-rsvp-title">Título interno</label>
                            <input type="text" id="evapp-rsvp-title" name="template[title]" value="<?php echo esc_attr($template['title'] ?? ''); ?>" required>
                            <p class="evapp-rsvp-help">Solo se usa dentro de EventosApp para identificar la plantilla.</p>
                        </div>
                        <div class="evapp-rsvp-field">
                            <label for="evapp-rsvp-name">Nombre técnico en Meta</label>
                            <input type="text" id="evapp-rsvp-name" name="template[name]" value="<?php echo esc_attr($template['name'] ?? ''); ?>" pattern="[a-z0-9_]+" required>
                            <p class="evapp-rsvp-help">Solo minúsculas, números y guion bajo. Debe ser único para el mismo idioma dentro del WABA.</p>
                        </div>
                        <div class="evapp-rsvp-field">
                            <label for="evapp-rsvp-language">Idioma</label>
                            <input type="text" id="evapp-rsvp-language" name="template[language]" value="<?php echo esc_attr($template['language'] ?? 'es'); ?>" placeholder="es" required>
                        </div>
                        <div class="evapp-rsvp-field">
                            <label for="evapp-rsvp-category">Categoría solicitada</label>
                            <select id="evapp-rsvp-category" name="template[category]">
                                <option value="UTILITY" <?php selected($template['category'] ?? 'UTILITY', 'UTILITY'); ?>>Utility</option>
                                <option value="MARKETING" <?php selected($template['category'] ?? 'UTILITY', 'MARKETING'); ?>>Marketing</option>
                            </select>
                            <p class="evapp-rsvp-help">Meta puede reclasificar el contenido según el texto final.</p>
                        </div>
                        <div class="evapp-rsvp-field">
                            <label for="evapp-rsvp-sender">Número emisor</label>
                            <select id="evapp-rsvp-sender" name="template[sender_phone_number_id]">
                                <option value="">Número por defecto</option>
                                <?php foreach ( $accounts as $id => $account ) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($template['sender_phone_number_id'] ?? '', $id); ?>><?php echo esc_html($account['label'] ?? $id); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="evapp-rsvp-field">
                            <label for="evapp-rsvp-waba">WABA ID</label>
                            <input type="text" id="evapp-rsvp-waba" name="template[waba_id]" value="<?php echo esc_attr($template['waba_id'] ?? ''); ?>" placeholder="<?php echo esc_attr($effective_waba ?: ($settings['waba_id'] ?? '')); ?>">
                            <p class="evapp-rsvp-help">Puede quedar vacío cuando el número emisor o la configuración general ya resuelven el WABA. WABA efectivo actual: <strong><?php echo esc_html($effective_waba ?: 'sin configurar'); ?></strong>.</p>
                        </div>
                    </div>
                </div>

                <div class="evapp-rsvp-card">
                    <h2>Contenido</h2>
                    <div class="evapp-rsvp-grid">
                        <div class="evapp-rsvp-field">
                            <label for="evapp-rsvp-header-format">Formato de encabezado</label>
                            <select id="evapp-rsvp-header-format" name="template[header_format]">
                                <option value="IMAGE" <?php selected($template['header_format'] ?? 'IMAGE', 'IMAGE'); ?>>Imagen dinámica</option>
                                <option value="NONE" <?php selected($template['header_format'] ?? 'IMAGE', 'NONE'); ?>>Sin encabezado</option>
                                <option value="TEXT" <?php selected($template['header_format'] ?? 'IMAGE', 'TEXT'); ?>>Texto</option>
                            </select>
                        </div>
                        <div class="evapp-rsvp-field" id="evapp-rsvp-header-text-field">
                            <label for="evapp-rsvp-header-text">Texto del encabezado</label>
                            <input type="text" id="evapp-rsvp-header-text" name="template[header_text]" value="<?php echo esc_attr($template['header_text'] ?? ''); ?>">
                        </div>
                    </div>

                    <div id="evapp-rsvp-image-fields">
                        <div class="evapp-rsvp-field">
                            <label for="evapp-rsvp-header-file">Imagen JPG/PNG de muestra para Meta</label>
                            <input type="file" id="evapp-rsvp-header-file" name="header_sample_file" accept="image/jpeg,image/png">
                            <p class="evapp-rsvp-help">La imagen real de cada evento se selecciona en el metabox de WhatsApp del evento. Este archivo solo genera el Header Sample Handle requerido para aprobación.</p>
                            <?php if ( ! empty($template['header_sample_file_name']) ) : ?>
                                <div class="evapp-rsvp-file-meta">
                                    <strong>Última muestra:</strong> <?php echo esc_html($template['header_sample_file_name']); ?><br>
                                    <?php if ( ! empty($template['header_sample_file_type']) ) : ?>Tipo: <?php echo esc_html($template['header_sample_file_type']); ?> · <?php endif; ?>
                                    <?php if ( ! empty($template['header_sample_file_size']) ) : ?>Tamaño: <?php echo esc_html(size_format(absint($template['header_sample_file_size']))); ?> · <?php endif; ?>
                                    <?php echo esc_html($template['header_sample_uploaded_at'] ?? ''); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="evapp-rsvp-field">
                            <label for="evapp-rsvp-header-handle">Header Sample Handle</label>
                            <input type="text" id="evapp-rsvp-header-handle" name="template[header_sample_handle]" value="<?php echo esc_attr($template['header_sample_handle'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="evapp-rsvp-field">
                        <label for="evapp-rsvp-body">Cuerpo</label>
                        <textarea id="evapp-rsvp-body" name="template[body_text]" rows="11" required><?php echo esc_textarea($template['body_text'] ?? ''); ?></textarea>
                        <p class="evapp-rsvp-help">Variables disponibles: {{1}} nombre, {{2}} evento, {{3}} fecha, {{4}} hora, {{5}} lugar, {{6}} modalidad.</p>
                    </div>
                    <div class="evapp-rsvp-field">
                        <label for="evapp-rsvp-examples">Ejemplos de variables</label>
                        <textarea id="evapp-rsvp-examples" name="template[body_examples]" rows="7"><?php echo esc_textarea($template['body_examples'] ?? ''); ?></textarea>
                        <p class="evapp-rsvp-help">Un ejemplo por línea y en el mismo orden de las variables utilizadas en el cuerpo.</p>
                    </div>
                    <div class="evapp-rsvp-field">
                        <label for="evapp-rsvp-footer">Pie</label>
                        <input type="text" id="evapp-rsvp-footer" name="template[footer_text]" value="<?php echo esc_attr($template['footer_text'] ?? ''); ?>">
                    </div>
                </div>

                <div class="evapp-rsvp-card">
                    <h2>Botones de respuesta rápida</h2>
                    <div class="evapp-rsvp-grid">
                        <div class="evapp-rsvp-field">
                            <label for="evapp-rsvp-button-yes">Botón afirmativo</label>
                            <input type="text" id="evapp-rsvp-button-yes" name="template[button_1_text]" value="<?php echo esc_attr($template['button_1_text'] ?? 'Sí, asistiré'); ?>" maxlength="25" required>
                            <p class="evapp-rsvp-help">Máximo 25 caracteres.</p>
                        </div>
                        <div class="evapp-rsvp-field">
                            <label for="evapp-rsvp-button-no">Botón negativo</label>
                            <input type="text" id="evapp-rsvp-button-no" name="template[button_2_text]" value="<?php echo esc_attr($template['button_2_text'] ?? 'No podré asistir'); ?>" maxlength="25" required>
                            <p class="evapp-rsvp-help">Máximo 25 caracteres.</p>
                        </div>
                    </div>
                    <div class="evapp-rsvp-info"><strong>No debes configurar payloads manualmente.</strong> EventosApp los crea de forma firmada durante cada envío para identificar el ticket y registrar la respuesta recibida por webhook.</div>
                </div>
            </div>

            <aside class="evapp-rsvp-sidebar">
                <div class="evapp-rsvp-card">
                    <?php
                    $editor_requested_category = function_exists('eventosapp_whatsapp_templates_sanitize_category') ? eventosapp_whatsapp_templates_sanitize_category($template['category'] ?? 'UTILITY') : strtoupper((string)($template['category'] ?? 'UTILITY'));
                    $editor_remote_category = function_exists('eventosapp_whatsapp_templates_normalize_meta_category') ? eventosapp_whatsapp_templates_normalize_meta_category($template['meta_category'] ?? '') : strtoupper((string)($template['meta_category'] ?? ''));
                    $editor_category_mismatch = $editor_remote_category !== '' && $editor_remote_category !== $editor_requested_category;
                    $editor_rejected_reason = function_exists('eventosapp_whatsapp_templates_normalize_rejected_reason') ? eventosapp_whatsapp_templates_normalize_rejected_reason($template['meta_rejected_reason'] ?? '') : sanitize_text_field((string)($template['meta_rejected_reason'] ?? ''));
                    ?>
                    <h3>Estado e identidad en Meta</h3>
                    <p><span class="evapp-rsvp-status <?php echo esc_attr(eventosapp_attendance_confirmation_whatsapp_template_status_class($status)); ?>"><?php echo esc_html(eventosapp_attendance_confirmation_whatsapp_template_status_label($status)); ?></span></p>
                    <div class="evapp-rsvp-meta">
                        <strong>ID local:</strong> <?php echo esc_html($is_new ? 'se generará al guardar' : $template_id); ?><br>
                        <strong>ID Meta:</strong> <?php echo esc_html($template['meta_template_id'] ?? '—'); ?><br>
                        <strong>Nombre remoto:</strong> <?php echo esc_html($template['meta_remote_name'] ?? '—'); ?><br>
                        <strong>Idioma remoto:</strong> <?php echo esc_html($template['meta_remote_language'] ?? '—'); ?><br>
                        <strong>Último envío:</strong> <?php echo esc_html($template['last_submitted_at'] ?? '—'); ?><br>
                        <strong>Última consulta:</strong> <?php echo esc_html($template['last_checked_at'] ?? '—'); ?>
                    </div>
                    <p style="margin-bottom:6px"><span class="evapp-rsvp-meta">Solicitada:</span> <span class="evapp-rsvp-category <?php echo esc_attr(strtolower($editor_requested_category)); ?>"><?php echo esc_html(ucfirst(strtolower($editor_requested_category))); ?></span></p>
                    <?php if ( $editor_remote_category !== '' ) : ?><p style="margin-top:0"><span class="evapp-rsvp-meta">Meta:</span> <span class="evapp-rsvp-category <?php echo esc_attr(strtolower($editor_remote_category)); ?>"><?php echo esc_html(ucfirst(strtolower($editor_remote_category))); ?></span></p><?php endif; ?>
                    <?php if ( $editor_category_mismatch ) : ?><div class="evapp-rsvp-warning"><strong>Recategorización detectada:</strong><br>Meta cambió esta plantilla de <?php echo esc_html($editor_requested_category); ?> a <?php echo esc_html($editor_remote_category); ?><?php if ( ! empty($template['meta_category_changed_at']) ) : ?> el <?php echo esc_html($template['meta_category_changed_at']); ?><?php endif; ?>.</div><?php endif; ?>
                    <?php if ( ! empty($template['meta_identity_reset_reason']) ) : ?><div class="evapp-rsvp-warning"><strong>Identidad remota reiniciada:</strong><br><?php echo esc_html($template['meta_identity_reset_reason']); ?></div><?php endif; ?>
                    <?php if ( ! empty($template['last_api_message']) ) : ?><div class="evapp-rsvp-info" style="margin-bottom:0"><?php echo esc_html($template['last_api_message']); ?></div><?php endif; ?>
                    <?php if ( $editor_rejected_reason !== '' ) : ?><div class="evapp-rsvp-warning" style="margin-bottom:0"><strong>Motivo reportado:</strong><br><?php echo esc_html($editor_rejected_reason); ?></div><?php endif; ?>
                    <?php if ( function_exists('eventosapp_whatsapp_templates_render_meta_diagnostics') ) eventosapp_whatsapp_templates_render_meta_diagnostics($template, false); ?>
                </div>

                <div class="evapp-rsvp-card">
                    <h3>Vista previa</h3>
                    <div class="evapp-rsvp-preview">
                        <div class="evapp-rsvp-preview-body" id="evapp-rsvp-preview-body"></div>
                        <div class="evapp-rsvp-preview-footer" id="evapp-rsvp-preview-footer"></div>
                        <div class="evapp-rsvp-preview-buttons">
                            <span class="evapp-rsvp-preview-button" id="evapp-rsvp-preview-yes"></span>
                            <span class="evapp-rsvp-preview-button" id="evapp-rsvp-preview-no"></span>
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        <div class="evapp-rsvp-actionbar">
            <span class="evapp-rsvp-help">Guardar localmente no publica cambios en Meta.</span>
            <div class="evapp-rsvp-actionbar-buttons">
                <button class="button button-primary" name="template_action" value="save">Guardar localmente</button>
                <button class="button" name="template_action" value="submit">Guardar y enviar a Meta</button>
                <button class="button" name="template_action" value="check">Guardar y consultar estado</button>
            </div>
        </div>
    </form>

    <script>
    jQuery(function($){
        const $format = $('#evapp-rsvp-header-format');
        const updateHeaderFields = function(){
            const value = String($format.val() || '').toUpperCase();
            $('#evapp-rsvp-header-text-field').toggle(value === 'TEXT');
            $('#evapp-rsvp-image-fields').toggle(value === 'IMAGE');
        };
        const updatePreview = function(){
            $('#evapp-rsvp-preview-body').text($('#evapp-rsvp-body').val() || 'Escribe el cuerpo de la plantilla para ver la vista previa.');
            $('#evapp-rsvp-preview-footer').text($('#evapp-rsvp-footer').val() || 'Sin pie configurado');
            $('#evapp-rsvp-preview-yes').text($('#evapp-rsvp-button-yes').val() || 'Sí');
            $('#evapp-rsvp-preview-no').text($('#evapp-rsvp-button-no').val() || 'No');
        };
        $format.on('change', updateHeaderFields);
        $('#evapp-rsvp-body,#evapp-rsvp-footer,#evapp-rsvp-button-yes,#evapp-rsvp-button-no').on('input change', updatePreview);
        updateHeaderFields();
        updatePreview();
    });
    </script>
    <?php
}

/**
 * Enlace contextual desde la página general y bloqueo visual de su editor URL,
 * que no conoce la estructura QUICK_REPLY especializada.
 */
add_action('admin_notices', function() {
    if ( ! is_admin() || sanitize_key((string)($_GET['page'] ?? '')) !== 'eventosapp_whatsapp_templates' ) return;
    $count = count(eventosapp_attendance_confirmation_whatsapp_template_get_all(false));
    echo '<div class="notice notice-info"><p><strong>Confirmación de asistencia:</strong> hay ' . esc_html($count) . ' plantilla' . ($count === 1 ? '' : 's') . ' con botones Sí / No. Se administran en un inventario especializado para conservar correctamente los botones QUICK_REPLY. <a class="button button-small" href="' . esc_url(eventosapp_attendance_confirmation_whatsapp_template_admin_url()) . '">Gestionar inventario</a></p></div>';
});

add_action('admin_footer', function() {
    if ( sanitize_key((string)($_GET['page'] ?? '')) !== 'eventosapp_whatsapp_templates' ) return;

    $links = [];
    foreach ( eventosapp_attendance_confirmation_whatsapp_template_get_all(false) as $template_id => $template ) {
        $links[$template_id] = eventosapp_attendance_confirmation_whatsapp_template_admin_url([
            'view' => 'edit',
            'template_id' => $template_id,
        ]);
    }
    ?>
    <script>
    jQuery(function($){
        const specializedLinks = <?php echo wp_json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        $('.evapp-wa-tpl-table tbody tr').each(function(){
            const $row = $(this);
            const $candidate = $row.find('a[href*="template_id="]').first();
            if(!$candidate.length) return;
            const match = String($candidate.attr('href') || '').match(/[?&]template_id=([^&]+)/);
            if(!match) return;
            const templateId = decodeURIComponent(match[1]);
            if(!specializedLinks[templateId]) return;
            const $actions = $row.find('.evapp-wa-tpl-actions').last();
            if($actions.length){
                $actions.empty().append($('<a>',{
                    class:'button button-primary',
                    href:specializedLinks[templateId],
                    text:'Gestionar confirmación'
                }));
            }
            $row.find('a[href*="template_id='+templateId+'"]').attr('href', specializedLinks[templateId]);
        });
    });
    </script>
    <?php
});
