<?php
/**
 * EventosApp - Plantillas WhatsApp para Meta
 *
 * Administra plantillas de WhatsApp Business Platform desde EventosApp.
 * Este archivo no reemplaza el envío actual de WhatsApp Tickets: agrega un
 * módulo administrativo independiente para crear, editar, enviar a Meta,
 * consultar estado y reutilizar plantillas prediseñadas por modalidad.
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! defined('EVENTOSAPP_WHATSAPP_TEMPLATES_OPTION') ) {
    define('EVENTOSAPP_WHATSAPP_TEMPLATES_OPTION', 'eventosapp_whatsapp_templates_settings');
}

/**
 * Registra el submenú debajo de WhatsApp Tickets.
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'eventosapp_dashboard',
        'Plantillas WhatsApp',
        'Plantillas WhatsApp',
        'manage_options',
        'eventosapp_whatsapp_templates',
        'eventosapp_whatsapp_templates_render_page'
    );
}, 21);

/**
 * URL pública frontal para acciones de ticket usadas desde WhatsApp.
 * No usa /wp-admin/admin-post.php para evitar que el enlace dependa de una sesión iniciada.
 */
function eventosapp_whatsapp_templates_public_action_url($action, $ticket_public = '{{1}}') {
    if ( function_exists('eventosapp_whatsapp_public_action_url') ) {
        return eventosapp_whatsapp_public_action_url($action, $ticket_public);
    }

    $action = sanitize_key((string) $action);
    if ( ! in_array($action, ['ticket_landing', 'ticket_ics', 'virtual_access'], true) ) {
        $action = 'ticket_landing';
    }

    $ticket_public = (string) $ticket_public;
    $slug = sanitize_title((string) apply_filters('eventosapp_whatsapp_public_ticket_page_slug', 'ticket'));
    $slug = $slug !== '' ? $slug : 'ticket';
    $base_url = trailingslashit(home_url('/' . $slug));

    $args = [
        'ticket' => $ticket_public,
    ];

    if ( $action !== 'ticket_landing' ) {
        $args = [
            'eventosapp_whatsapp_public_action' => $action,
            'ticket' => $ticket_public,
        ];
    }

    $url = add_query_arg($args, $base_url);

    return str_replace(['%7B%7B1%7D%7D', '%7b%7b1%7d%7d', rawurlencode('{{1}}')], '{{1}}', $url);
}

/**
 * URL pública específica para la landing de ticket.
 */
function eventosapp_whatsapp_templates_public_ticket_landing_url($ticket_public = '{{1}}') {
    return eventosapp_whatsapp_templates_public_action_url('ticket_landing', $ticket_public);
}

/**
 * URL base segura para botones de plantilla.
 */
function eventosapp_whatsapp_templates_button_url($action) {
    return eventosapp_whatsapp_templates_public_action_url($action, '{{1}}');
}

/**
 * Convierte URLs antiguas basadas en /wp-admin/admin-post.php a la ruta pública frontal.
 */
function eventosapp_whatsapp_templates_normalize_public_button_url($url) {
    $url = (string) $url;
    if ( $url === '' || strpos($url, 'admin-post.php') === false || strpos($url, 'eventosapp_whatsapp_') === false ) {
        return $url;
    }

    $legacy_map = [
        'eventosapp_whatsapp_ticket_landing' => 'ticket_landing',
        'eventosapp_whatsapp_ticket_ics'     => 'ticket_ics',
        'eventosapp_whatsapp_virtual_access' => 'virtual_access',
    ];

    foreach ( $legacy_map as $legacy_action => $public_action ) {
        if ( strpos($url, 'action=' . $legacy_action) !== false || strpos($url, 'action=' . rawurlencode($legacy_action)) !== false ) {
            $ticket_placeholder = strpos($url, 'ticket_demo_123') !== false ? 'ticket_demo_123' : '{{1}}';
            return eventosapp_whatsapp_templates_public_action_url($public_action, $ticket_placeholder);
        }
    }

    return $url;
}

/**
 * Ejemplo completo de URL para mostrar en ayudas internas o usar como valor del BODY.
 */
function eventosapp_whatsapp_templates_button_example_url($action) {
    return str_replace('{{1}}', eventosapp_whatsapp_templates_default_button_variable_example(), eventosapp_whatsapp_templates_button_url($action));
}

/**
 * Valor de ejemplo seguro para la variable dinámica de botones URL.
 *
 * Meta no espera la URL completa en components[].buttons[].example. Para botones
 * con URL dinámica, el ejemplo debe ser únicamente el valor que reemplaza {{1}}.
 */
function eventosapp_whatsapp_templates_default_button_variable_example() {
    return 'ticket_demo_123';
}


/**
 * Categorías soportadas por este módulo para plantillas de tickets.
 *
 * Utility y Marketing conservan el builder general. Authentication se expone
 * únicamente para el flujo especializado de Doble Autenticación/OTP, cuya
 * estructura es distinta y se normaliza antes de enviarla a Meta.
 */
function eventosapp_whatsapp_templates_supported_categories() {
    return [
        'UTILITY'        => 'Utility',
        'MARKETING'      => 'Marketing',
        'AUTHENTICATION' => 'Authentication',
    ];
}

/**
 * Normaliza la categoría solicitada localmente.
 */
function eventosapp_whatsapp_templates_sanitize_category($category, $fallback = 'UTILITY') {
    $category = strtoupper(sanitize_key((string) $category));
    $fallback = strtoupper(sanitize_key((string) $fallback));
    $supported = eventosapp_whatsapp_templates_supported_categories();

    if ( $category === '' ) {
        $category = $fallback;
    }

    if ( ! isset($supported[$category]) ) {
        $category = isset($supported[$fallback]) ? $fallback : 'UTILITY';
    }

    return $category;
}

/**
 * Normaliza la categoría devuelta por Meta sin forzarla a una lista cerrada.
 */
function eventosapp_whatsapp_templates_normalize_meta_category($category) {
    $category = strtoupper(sanitize_key((string) $category));
    return $category !== '' ? $category : '';
}

/**
 * Etiqueta legible de categoría.
 */
function eventosapp_whatsapp_templates_category_label($category) {
    $category = eventosapp_whatsapp_templates_normalize_meta_category($category);
    $labels = eventosapp_whatsapp_templates_supported_categories();
    return $labels[$category] ?? ($category !== '' ? $category : 'Sin categoría');
}

/**
 * Detecta si Meta tiene una categoría diferente a la solicitada localmente.
 */
function eventosapp_whatsapp_templates_category_mismatch($template) {
    $template = is_array($template) ? $template : [];
    $requested = eventosapp_whatsapp_templates_sanitize_category($template['category'] ?? 'UTILITY');
    $remote = eventosapp_whatsapp_templates_normalize_meta_category($template['meta_category'] ?? '');

    return $remote !== '' && $requested !== '' && $remote !== $requested;
}

/**
 * Mensaje administrativo para diferencias de categoría entre EventosApp y Meta.
 */
function eventosapp_whatsapp_templates_category_status_message($requested_category, $remote_category = '') {
    $requested_category = eventosapp_whatsapp_templates_sanitize_category($requested_category ?: 'UTILITY');
    $remote_category = eventosapp_whatsapp_templates_normalize_meta_category($remote_category);

    if ( $remote_category === '' ) {
        return 'Categoría solicitada a Meta: ' . eventosapp_whatsapp_templates_category_label($requested_category) . '.';
    }

    if ( $remote_category === $requested_category ) {
        return 'Categoría confirmada por Meta: ' . eventosapp_whatsapp_templates_category_label($remote_category) . '.';
    }

    if ( $requested_category === 'AUTHENTICATION' || $remote_category === 'AUTHENTICATION' ) {
        return 'Meta reporta categoría ' . eventosapp_whatsapp_templates_category_label($remote_category) . ' aunque EventosApp solicitó ' . eventosapp_whatsapp_templates_category_label($requested_category) . '. Para códigos OTP usa exclusivamente Authentication con BODY administrado por Meta y botón OTP.';
    }

    return 'Meta reporta categoría ' . eventosapp_whatsapp_templates_category_label($remote_category) . ' aunque EventosApp la tenía marcada como ' . eventosapp_whatsapp_templates_category_label($requested_category) . '. Revisa el contenido; si incluye promociones, premios, sorteos, ofertas o llamados comerciales, déjala como Marketing antes de reenviarla.';
}

/**
 * Busca señales de contenido promocional para avisar antes de enviar como Utility.
 * No bloquea el envío porque la decisión final la toma Meta.
 */
function eventosapp_whatsapp_templates_detect_marketing_signals($template) {
    $template = is_array($template) ? $template : [];
    $text = implode(' ', [
        (string)($template['title'] ?? ''),
        (string)($template['body_text'] ?? ''),
        (string)($template['footer_text'] ?? ''),
        (string)($template['button_1_text'] ?? ''),
        (string)($template['button_2_text'] ?? ''),
    ]);

    if ( function_exists('remove_accents') ) {
        $text = remove_accents($text);
    }
    $text = strtolower($text);

    $checks = [
        '/\bpremios?\b|\bsorteos?\b|\brifas?\b|\bgan(a|ar|ate|as|a\s+tu)\b/' => 'premios, sorteos o incentivos',
        '/\bofertas?\b|\bdescuentos?\b|\bpromocion(es)?\b|\bcupon(es)?\b|\bgratis\b/' => 'ofertas, descuentos o promociones',
        '/\bcompr(a|ar)\b|\bventa(s)?\b|\bproducto(s)?\b|\bservicio(s)?\b/' => 'contenido comercial o de venta',
        '/\bparticipa\b|\bparticipar\b|\bregistrate\b|\binscribete\b|\baun estas a tiempo\b|\bte esperamos\b/' => 'llamados de participación o asistencia',
        '/\bespectacular(es)?\b|\bimperdible\b|\bexclusiv(a|o|as|os)\b|\blimitad(a|o|as|os)\b/' => 'lenguaje promocional',
    ];

    $signals = [];
    foreach ( $checks as $pattern => $label ) {
        if ( preg_match($pattern, $text) ) {
            $signals[] = $label;
        }
    }

    return array_values(array_unique($signals));
}

/**
 * Aviso de categoría según contenido y categoría solicitada.
 */
function eventosapp_whatsapp_templates_category_advice($template) {
    $template = is_array($template) ? $template : [];
    $category = eventosapp_whatsapp_templates_sanitize_category($template['category'] ?? 'UTILITY');
    $signals = eventosapp_whatsapp_templates_detect_marketing_signals($template);

    if ( $category === 'AUTHENTICATION' ) {
        return 'Plantilla Authentication: úsala exclusivamente para códigos de verificación/OTP. Meta controla el texto del BODY y requiere un botón OTP; EventosApp usa COPY_CODE para Doble Autenticación.';
    }

    if ( $category === 'UTILITY' && ! empty($signals) ) {
        return 'Aviso de categoría: el texto tiene señales de Marketing (' . implode(', ', $signals) . '). Meta podría reclasificarla como Marketing aunque la envíes como Utility.';
    }

    if ( $category === 'MARKETING' ) {
        return 'Plantilla marcada como Marketing: úsala para promociones, invitaciones, recordatorios con premios/sorteos, ofertas o llamados comerciales. Debe enviarse solo a usuarios con opt-in válido.';
    }

    return 'Plantilla marcada como Utility: úsala para confirmaciones, accesos, recordatorios operativos o actualizaciones directamente relacionadas con la inscripción del asistente.';
}

/**
 * Extrae un resumen simple del quality_score devuelto por Meta.
 */
function eventosapp_whatsapp_templates_quality_summary($quality_score) {
    if ( empty($quality_score) ) {
        return '';
    }

    if ( is_array($quality_score) ) {
        foreach ( ['score', 'rating', 'quality_rating', 'status'] as $key ) {
            if ( ! empty($quality_score[$key]) && is_scalar($quality_score[$key]) ) {
                return sanitize_text_field((string) $quality_score[$key]);
            }
        }
        return sanitize_text_field(wp_json_encode($quality_score, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    return sanitize_text_field((string) $quality_score);
}


/**
 * Normaliza los estados de plantilla devueltos por Meta.
 */
function eventosapp_whatsapp_templates_normalize_meta_status($status, $fallback = 'LOCAL') {
    $status = strtoupper(sanitize_key((string) $status));
    $aliases = [
        'IN_REVIEW'      => 'PENDING',
        'PENDING_REVIEW' => 'PENDING',
        'UNDER_REVIEW'   => 'PENDING',
        'EN_REVISION'    => 'PENDING',
    ];

    if ( isset($aliases[$status]) ) {
        $status = $aliases[$status];
    }

    if ( $status === '' ) {
        $status = strtoupper(sanitize_key((string) $fallback));
        if ( $status === '' ) {
            return '';
        }
    }

    return $status;
}

/**
 * Etiqueta administrativa consistente para cualquier estado conocido.
 */
function eventosapp_whatsapp_templates_meta_status_label($status) {
    $status = eventosapp_whatsapp_templates_normalize_meta_status($status);
    $labels = [
        'LOCAL'      => 'Local, sin enviar',
        'PENDING'    => 'En revisión',
        'APPROVED'   => 'Aprobada',
        'ACTIVE'     => 'Activa',
        'REJECTED'   => 'Rechazada',
        'IN_APPEAL'  => 'En apelación',
        'PAUSED'     => 'Pausada',
        'DISABLED'   => 'Deshabilitada',
        'DELETED'    => 'Eliminada en Meta',
        'UNKNOWN'    => 'Estado desconocido',
        'DRY_RUN'    => 'Prueba interna',
    ];

    return $labels[$status] ?? $status;
}

/**
 * Meta suele devolver rejected_reason="NONE" incluso cuando no existe rechazo.
 * Este helper evita mostrarlo como una alerta falsa.
 */
function eventosapp_whatsapp_templates_normalize_rejected_reason($reason) {
    if ( is_array($reason) ) {
        foreach ( ['message', 'reason', 'code', 'title'] as $key ) {
            if ( isset($reason[$key]) && is_scalar($reason[$key]) ) {
                $reason = $reason[$key];
                break;
            }
        }
    }

    if ( ! is_scalar($reason) ) {
        return '';
    }

    $reason = trim(sanitize_text_field((string) $reason));
    $normalized = strtoupper(str_replace([' ', '-'], '_', $reason));
    if ( in_array($normalized, ['', 'NONE', 'NULL', 'N_A', 'NA', 'NOT_APPLICABLE', 'NO_REJECTION', 'NO_REASON', 'UNSPECIFIED'], true) ) {
        return '';
    }

    return $reason;
}

/**
 * Extrae todos los campos útiles de error que Meta puede devolver.
 */
function eventosapp_whatsapp_templates_error_context($response) {
    $response = is_array($response) ? $response : [];
    $error = isset($response['error']) && is_array($response['error']) ? $response['error'] : [];
    $error_data = isset($error['error_data']) && is_array($error['error_data']) ? $error['error_data'] : [];

    return [
        'message'            => sanitize_text_field((string)($error['message'] ?? '')),
        'type'               => sanitize_text_field((string)($error['type'] ?? '')),
        'code'               => absint($error['code'] ?? 0),
        'subcode'            => absint($error['error_subcode'] ?? 0),
        'error_user_title'   => sanitize_text_field((string)($error['error_user_title'] ?? '')),
        'error_user_message' => sanitize_text_field((string)($error['error_user_msg'] ?? '')),
        'details'            => sanitize_text_field((string)($error_data['details'] ?? '')),
        'messaging_product'  => sanitize_text_field((string)($error_data['messaging_product'] ?? '')),
        'fbtrace_id'         => sanitize_text_field((string)($error['fbtrace_id'] ?? '')),
    ];
}

/**
 * Infiere el tipo de operación a partir del método y la ruta Graph.
 */
function eventosapp_whatsapp_templates_detect_api_operation($method, $path) {
    $method = strtoupper((string) $method);
    $path = ltrim((string) $path, '/');
    $path_without_query = strtok($path, '?');

    if ( $method === 'POST' && preg_match('#^[^/]+/message_templates$#', (string) $path_without_query) ) {
        return 'create';
    }
    if ( $method === 'POST' ) {
        return 'update';
    }
    if ( $method === 'GET' && strpos($path_without_query, '/message_templates') !== false ) {
        return 'list';
    }
    if ( $method === 'GET' ) {
        return 'status';
    }
    if ( $method === 'DELETE' ) {
        return 'delete';
    }

    return 'request';
}

/**
 * Clasifica respuestas de error para que todas las pantallas muestren la misma
 * explicación, sin depender del texto variable que Meta entregue.
 */
function eventosapp_whatsapp_templates_classify_api_error($result) {
    $result = is_array($result) ? $result : [];
    $response = is_array($result['response'] ?? null) ? $result['response'] : [];
    $error = eventosapp_whatsapp_templates_error_context($response);
    $haystack = strtolower(implode(' ', array_filter([
        $error['message'],
        $error['error_user_title'],
        $error['error_user_message'],
        $error['details'],
        is_scalar($result['technical_message'] ?? null) ? (string)$result['technical_message'] : '',
    ])));

    if ( $error['subcode'] === 2388299
        || strpos($haystack, 'parameters at the beginning or at the end') !== false
        || strpos($haystack, 'variables cannot be at the beginning or end') !== false
        || strpos($haystack, 'parámetros al principio ni al final') !== false
        || strpos($haystack, 'variables no pueden estar al principio ni al final') !== false ) {
        return 'body_variable_boundary';
    }
    if ( $error['subcode'] === 2388003 || strpos($haystack, 'only be edited if rejected') !== false || strpos($haystack, 'can only be edited if rejected') !== false || strpos($haystack, 'solo se pueden editar si se rechazaron') !== false ) {
        return 'template_edit_locked';
    }
    if ( strpos($haystack, 'already exists') !== false || strpos($haystack, 'duplicate') !== false || strpos($haystack, 'same name') !== false || strpos($haystack, 'ya existe') !== false || strpos($haystack, 'nombre de plantilla') !== false && strpos($haystack, 'existe') !== false ) {
        return 'template_duplicate';
    }
    if ( $error['code'] === 190 || strpos($haystack, 'access token') !== false || strpos($haystack, 'oauth') !== false ) {
        return 'authentication';
    }
    if ( in_array($error['code'], [10, 200, 294], true) || strpos($haystack, 'permission') !== false || strpos($haystack, 'permiso') !== false ) {
        return 'permission';
    }
    if ( in_array($error['code'], [4, 17, 32, 613, 80004], true) || strpos($haystack, 'rate limit') !== false || strpos($haystack, 'too many') !== false ) {
        return 'rate_limit';
    }
    if ( strpos($haystack, 'header') !== false && (strpos($haystack, 'handle') !== false || strpos($haystack, 'media') !== false || strpos($haystack, 'image') !== false) ) {
        return 'header_sample';
    }
    if ( (int)($result['http_code'] ?? 0) === 404 || $error['code'] === 803 || strpos($haystack, 'unsupported get request') !== false || strpos($haystack, 'does not exist') !== false ) {
        return 'not_found';
    }
    if ( $error['code'] === 100 ) {
        return 'invalid_parameter';
    }
    if ( (int)($result['http_code'] ?? 0) >= 500 ) {
        return 'meta_unavailable';
    }

    return 'meta_api';
}

/**
 * Mensaje humano único para los distintos errores de plantillas.
 */
function eventosapp_whatsapp_templates_error_message($error_type, $error, $http_code = 0) {
    $messages = [
        'template_duplicate'  => 'Meta no creó la plantilla porque ya existe otra con el mismo nombre técnico e idioma dentro de ese WABA. Usa un nombre técnico diferente para crear una nueva versión o sincroniza el registro existente.',
        'template_edit_locked'=> 'Meta rechazó la actualización porque la plantilla remota no está en estado Rechazada. Las plantillas aprobadas, activas o en revisión deben conservarse; crea una nueva versión con otro nombre técnico.',
        'body_variable_boundary'=> 'Meta rechazó el BODY porque una variable quedó como primer o último contenido significativo. EventosApp protege las plantillas de Doble Autenticación corrigiendo automáticamente ese límite; para otras plantillas agrega texto fijo antes de la primera variable y después de la última.',
        'authentication'      => 'Meta rechazó la solicitud por un problema con el Access Token. Revisa que el token esté vigente y pertenezca al WABA seleccionado.',
        'permission'          => 'Meta rechazó la solicitud por permisos insuficientes. Revisa los permisos de administración de WhatsApp y de plantillas para el WABA seleccionado.',
        'rate_limit'          => 'Meta aplicó un límite temporal de solicitudes. Espera unos minutos antes de volver a intentar.',
        'header_sample'       => 'Meta rechazó la muestra del encabezado. Genera un Header Sample Handle nuevo con una imagen JPG o PNG y vuelve a enviar.',
        'not_found'           => 'La plantilla o el recurso remoto ya no existe, no pertenece al WABA consultado o el token no puede verlo.',
        'invalid_parameter'   => 'Meta rechazó uno o más parámetros de la plantilla. Revisa nombre técnico, idioma, categoría, variables, encabezado y botones.',
        'meta_unavailable'    => 'Meta presentó un error temporal al procesar la solicitud. Intenta nuevamente más tarde.',
        'meta_api'            => 'Meta rechazó la solicitud de la plantilla.',
    ];

    $message = $messages[$error_type] ?? $messages['meta_api'];
    $technical = array_values(array_unique(array_filter([
        $error['error_user_title'] ?? '',
        $error['error_user_message'] ?? '',
        $error['message'] ?? '',
        $error['details'] ?? '',
    ])));

    if ( ! empty($technical) ) {
        $message .= ' Detalle de Meta: ' . implode(' | ', $technical) . '.';
    }

    $codes = [];
    if ( $http_code ) $codes[] = 'HTTP ' . absint($http_code);
    if ( ! empty($error['code']) ) $codes[] = 'código ' . absint($error['code']);
    if ( ! empty($error['subcode']) ) $codes[] = 'subcódigo ' . absint($error['subcode']);
    if ( ! empty($codes) ) {
        $message .= ' (' . implode(' · ', $codes) . ')';
    }

    return sanitize_text_field($message);
}

/**
 * Enriquece cualquier resultado del cliente Graph con una estructura estable.
 */
function eventosapp_whatsapp_templates_enrich_api_result($result, $method, $path, $body = null) {
    $result = is_array($result) ? $result : [];
    $result['ok'] = ! empty($result['ok']);
    $result['http_code'] = absint($result['http_code'] ?? 0);
    $result['operation'] = eventosapp_whatsapp_templates_detect_api_operation($method, $path);
    $result['request_path'] = sanitize_text_field((string) $path);
    $response = $result['response'] ?? null;
    if ( is_string($response) ) {
        $decoded = json_decode($response, true);
        if ( is_array($decoded) ) $response = $decoded;
    }
    $result['response'] = $response;
    $result['technical_message'] = sanitize_text_field((string)($result['message'] ?? ''));

    if ( $result['ok'] ) {
        $response_array = is_array($response) ? $response : [];
        $meta_id = sanitize_text_field((string)($response_array['id'] ?? ''));
        $meta_status = eventosapp_whatsapp_templates_normalize_meta_status($response_array['status'] ?? '', '');
        $meta_category = eventosapp_whatsapp_templates_normalize_meta_category($response_array['category'] ?? '');
        $count = isset($response_array['data']) && is_array($response_array['data']) ? count($response_array['data']) : null;
        $parts = [];

        if ( $result['operation'] === 'create' ) {
            $parts[] = 'Meta recibió la nueva plantilla para revisión';
        } elseif ( $result['operation'] === 'update' ) {
            $parts[] = 'Meta recibió la actualización de la plantilla existente';
        } elseif ( $result['operation'] === 'status' ) {
            $parts[] = 'Estado consultado correctamente en Meta';
        } elseif ( $result['operation'] === 'list' ) {
            $parts[] = 'Consulta de plantillas completada en Meta';
        } else {
            $parts[] = 'Solicitud procesada correctamente por Meta';
        }

        if ( $meta_id !== '' ) $parts[] = 'ID ' . $meta_id;
        if ( $meta_status !== '' && $meta_status !== 'LOCAL' ) $parts[] = 'estado ' . eventosapp_whatsapp_templates_meta_status_label($meta_status);
        if ( $meta_category !== '' ) $parts[] = 'categoría ' . eventosapp_whatsapp_templates_category_label($meta_category);
        if ( $count !== null ) $parts[] = $count . ' registro(s) recibido(s)';

        $result['meta_id'] = $meta_id;
        $result['meta_status'] = $meta_status;
        $result['meta_category'] = $meta_category;
        $result['error_type'] = '';
        $result['error_code'] = 0;
        $result['error_subcode'] = 0;
        $result['error_user_title'] = '';
        $result['error_user_message'] = '';
        $result['fbtrace_id'] = '';
        $result['notice_level'] = 'success';
        $result['message'] = sanitize_text_field(implode('. ', $parts) . '.');
        return $result;
    }

    $response_array = is_array($response) ? $response : [];
    $error = eventosapp_whatsapp_templates_error_context($response_array);
    $error_type = eventosapp_whatsapp_templates_classify_api_error(array_merge($result, ['response' => $response_array]));
    $result['error_type'] = $error_type;
    $result['error_code'] = $error['code'];
    $result['error_subcode'] = $error['subcode'];
    $result['error_user_title'] = $error['error_user_title'];
    $result['error_user_message'] = $error['error_user_message'];
    $result['fbtrace_id'] = $error['fbtrace_id'];
    $result['notice_level'] = in_array($error_type, ['template_duplicate', 'template_edit_locked', 'not_found'], true) ? 'warning' : 'error';
    $result['message'] = eventosapp_whatsapp_templates_error_message($error_type, $error, $result['http_code']);

    return $result;
}

/**
 * Resumen técnico persistible para la interfaz administrativa.
 */
function eventosapp_whatsapp_templates_result_summary($result) {
    $result = is_array($result) ? $result : [];
    return [
        'operation'          => sanitize_key((string)($result['operation'] ?? '')),
        'ok'                 => ! empty($result['ok']) ? 1 : 0,
        'notice_level'       => sanitize_key((string)($result['notice_level'] ?? (! empty($result['ok']) ? 'success' : 'error'))),
        'http_code'          => absint($result['http_code'] ?? 0),
        'message'            => sanitize_text_field((string)($result['message'] ?? '')),
        'technical_message'  => sanitize_text_field((string)($result['technical_message'] ?? '')),
        'error_type'         => sanitize_key((string)($result['error_type'] ?? '')),
        'error_code'         => absint($result['error_code'] ?? 0),
        'error_subcode'      => absint($result['error_subcode'] ?? 0),
        'error_user_title'   => sanitize_text_field((string)($result['error_user_title'] ?? '')),
        'error_user_message' => sanitize_text_field((string)($result['error_user_message'] ?? '')),
        'fbtrace_id'         => sanitize_text_field((string)($result['fbtrace_id'] ?? '')),
        'meta_id'            => sanitize_text_field((string)($result['meta_id'] ?? '')),
        'meta_status'        => eventosapp_whatsapp_templates_normalize_meta_status($result['meta_status'] ?? '', ''),
        'meta_category'      => eventosapp_whatsapp_templates_normalize_meta_category($result['meta_category'] ?? ''),
    ];
}

/**
 * Añade un historial corto de cambios y respuestas para que los cambios de Meta
 * sean visibles sin almacenar payloads extensos indefinidamente.
 */
function eventosapp_whatsapp_templates_append_meta_history($template, $operation, $result = [], $before = []) {
    $template = is_array($template) ? $template : [];
    $before = is_array($before) ? $before : [];
    $summary = eventosapp_whatsapp_templates_result_summary($result);
    $entry = [
        'at'                => current_time('mysql'),
        'operation'         => sanitize_key((string) $operation),
        'ok'                => $summary['ok'],
        'notice_level'      => $summary['notice_level'],
        'http_code'         => $summary['http_code'],
        'message'           => $summary['message'],
        'error_type'        => $summary['error_type'],
        'error_code'        => $summary['error_code'],
        'error_subcode'     => $summary['error_subcode'],
        'previous_status'   => eventosapp_whatsapp_templates_normalize_meta_status($before['meta_status'] ?? '', ''),
        'status'            => eventosapp_whatsapp_templates_normalize_meta_status($template['meta_status'] ?? '', ''),
        'previous_category' => eventosapp_whatsapp_templates_normalize_meta_category($before['meta_category'] ?? ''),
        'category'          => eventosapp_whatsapp_templates_normalize_meta_category($template['meta_category'] ?? ''),
        'previous_meta_id'  => sanitize_text_field((string)($before['meta_template_id'] ?? '')),
        'meta_id'           => sanitize_text_field((string)($template['meta_template_id'] ?? '')),
        'rejected_reason'   => eventosapp_whatsapp_templates_normalize_rejected_reason($template['meta_rejected_reason'] ?? ''),
    ];

    $history = isset($template['meta_history']) && is_array($template['meta_history']) ? $template['meta_history'] : [];
    $signature_data = $entry;
    unset($signature_data['at']);
    $signature = md5(wp_json_encode($signature_data));
    $last = ! empty($history) && is_array(end($history)) ? end($history) : [];
    $last_signature_data = $last;
    unset($last_signature_data['at']);
    $last_signature = $last ? md5(wp_json_encode($last_signature_data)) : '';

    if ( $signature !== $last_signature ) {
        $history[] = $entry;
    }

    $template['meta_history'] = array_slice($history, -20);
    $template['last_meta_result'] = $summary;
    return $template;
}

/**
 * Aplica una fotografía remota y registra cambios de estado/categoría.
 */
function eventosapp_whatsapp_templates_apply_remote_snapshot($template, $remote, $operation = 'sync', $result = []) {
    $template = is_array($template) ? $template : [];
    $remote = is_array($remote) ? $remote : [];
    $before = $template;

    if ( ! empty($remote['id']) ) {
        $template['meta_template_id'] = sanitize_text_field((string) $remote['id']);
    }
    if ( isset($remote['status']) && $remote['status'] !== '' ) {
        $template['meta_status'] = eventosapp_whatsapp_templates_normalize_meta_status($remote['status'], 'UNKNOWN');
    }
    if ( isset($remote['category']) ) {
        $template['meta_category'] = eventosapp_whatsapp_templates_normalize_meta_category($remote['category']);
    }
    $template['meta_rejected_reason'] = eventosapp_whatsapp_templates_normalize_rejected_reason($remote['rejected_reason'] ?? '');
    $template['meta_remote_name'] = sanitize_key((string)($remote['name'] ?? ($template['meta_remote_name'] ?? '')));
    $template['meta_remote_language'] = sanitize_text_field((string)($remote['language'] ?? ($template['meta_remote_language'] ?? '')));
    $template['meta_quality_score'] = eventosapp_whatsapp_templates_quality_summary($remote['quality_score'] ?? '');
    $template['last_checked_at'] = current_time('mysql');

    $requested_category = eventosapp_whatsapp_templates_sanitize_category($template['category'] ?? 'UTILITY');
    $remote_category = eventosapp_whatsapp_templates_normalize_meta_category($template['meta_category'] ?? '');
    $mismatch = $remote_category !== '' && $remote_category !== $requested_category;
    $previous_mismatch = ! empty($before['meta_category_mismatch']);
    $template['meta_category_mismatch'] = $mismatch ? '1' : '0';
    if ( $mismatch && ( ! $previous_mismatch || $remote_category !== eventosapp_whatsapp_templates_normalize_meta_category($before['meta_category'] ?? '') ) ) {
        $template['meta_category_changed_at'] = current_time('mysql');
        $template['meta_category_previous'] = eventosapp_whatsapp_templates_normalize_meta_category($before['meta_category'] ?? $requested_category);
    }

    if ( ! empty($result) ) {
        $template['last_api_message'] = sanitize_text_field((string)($result['message'] ?? ''));
        $template['last_api_response'] = function_exists('eventosapp_whatsapp_sanitize_log_context')
            ? eventosapp_whatsapp_sanitize_log_context($result['response'] ?? $remote)
            : ($result['response'] ?? $remote);
    }

    $template = eventosapp_whatsapp_templates_append_meta_history($template, $operation, $result, $before);
    return $template;
}

/**
 * Consulta todas las páginas de plantillas de un WABA.
 */
function eventosapp_whatsapp_templates_fetch_remote_templates($waba_id, $fields = '') {
    $waba_id = eventosapp_whatsapp_templates_sanitize_waba_id($waba_id);
    if ( $waba_id === '' ) {
        return [
            'ok' => false,
            'message' => 'No se puede consultar Meta porque falta el WABA ID.',
            'data' => [],
            'pages' => 0,
            'notice_level' => 'error',
        ];
    }

    $fields = sanitize_text_field((string) $fields);
    if ( $fields === '' ) {
        $fields = 'id,name,status,category,language,rejected_reason,quality_score';
    }

    $all = [];
    $after = '';
    $pages = 0;
    $last_result = [];
    $partial_error = null;

    do {
        $query = [
            'limit'  => 100,
            'fields' => $fields,
        ];
        if ( $after !== '' ) $query['after'] = $after;
        $path = rawurlencode($waba_id) . '/message_templates?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $last_result = eventosapp_whatsapp_templates_api_request('GET', $path);
        $pages++;

        if ( empty($last_result['ok']) || ! is_array($last_result['response'] ?? null) ) {
            $partial_error = $last_result;
            break;
        }

        $page_data = isset($last_result['response']['data']) && is_array($last_result['response']['data'])
            ? $last_result['response']['data']
            : [];
        foreach ( $page_data as $remote ) {
            if ( is_array($remote) ) $all[] = $remote;
        }

        $after = sanitize_text_field((string)($last_result['response']['paging']['cursors']['after'] ?? ''));
        $has_next = ! empty($last_result['response']['paging']['next']) && $after !== '';
    } while ( $has_next && $pages < 50 );

    if ( $partial_error && empty($all) ) {
        $partial_error['data'] = [];
        $partial_error['pages'] = $pages;
        return $partial_error;
    }

    $message = 'Meta devolvió ' . count($all) . ' plantilla(s) del WABA en ' . $pages . ' página(s).';
    if ( $partial_error ) {
        $message .= ' La consulta fue parcial: ' . sanitize_text_field((string)($partial_error['message'] ?? 'una página presentó error'));
    }

    return [
        'ok' => true,
        'partial' => (bool) $partial_error,
        'message' => $message,
        'data' => $all,
        'pages' => $pages,
        'response' => $last_result['response'] ?? [],
        'notice_level' => $partial_error ? 'warning' : 'success',
    ];
}

/**
 * Busca coincidencia remota exacta por nombre técnico e idioma dentro del WABA.
 */
function eventosapp_whatsapp_templates_find_remote_template($waba_id, $name, $language) {
    $name = eventosapp_whatsapp_templates_sanitize_template_name($name);
    $language = sanitize_text_field((string) $language);
    $list = eventosapp_whatsapp_templates_fetch_remote_templates($waba_id);
    if ( empty($list['ok']) ) {
        $list['found'] = false;
        $list['matches'] = [];
        return $list;
    }

    $matches = [];
    foreach ( $list['data'] as $remote ) {
        if ( ! is_array($remote) ) continue;
        if ( eventosapp_whatsapp_templates_sanitize_template_name($remote['name'] ?? '') === $name && sanitize_text_field((string)($remote['language'] ?? '')) === $language ) {
            $matches[] = $remote;
        }
    }

    return array_merge($list, [
        'found' => ! empty($matches),
        'ambiguous' => count($matches) > 1,
        'matches' => $matches,
        'remote' => $matches[0] ?? null,
    ]);
}

/**
 * Consulta una plantilla remota por ID.
 */
function eventosapp_whatsapp_templates_get_remote_template_by_id($meta_template_id) {
    $meta_template_id = sanitize_text_field((string) $meta_template_id);
    if ( $meta_template_id === '' ) {
        return [
            'ok' => false,
            'message' => 'No se recibió un ID de plantilla de Meta.',
            'error_type' => 'not_found',
            'notice_level' => 'warning',
        ];
    }

    $fields = 'id,name,status,category,language,rejected_reason,quality_score';
    return eventosapp_whatsapp_templates_api_request('GET', rawurlencode($meta_template_id) . '?fields=' . rawurlencode($fields));
}

/**
 * Busca duplicados locales dentro del mismo WABA, nombre e idioma.
 */
function eventosapp_whatsapp_templates_find_local_duplicate($name, $language, $waba_id, $exclude_id = '') {
    $name = eventosapp_whatsapp_templates_sanitize_template_name($name);
    $language = sanitize_text_field((string) $language);
    $waba_id = eventosapp_whatsapp_templates_sanitize_waba_id($waba_id);
    $exclude_id = sanitize_key((string) $exclude_id);
    $settings = eventosapp_whatsapp_templates_get_settings();

    foreach ( (array)($settings['templates'] ?? []) as $template_id => $template ) {
        if ( ! is_array($template) || sanitize_key((string)$template_id) === $exclude_id ) continue;
        if ( eventosapp_whatsapp_templates_sanitize_template_name($template['name'] ?? '') !== $name ) continue;
        if ( sanitize_text_field((string)($template['language'] ?? '')) !== $language ) continue;
        $candidate_waba = eventosapp_whatsapp_templates_get_template_waba_id($template, $settings);
        if ( $candidate_waba === $waba_id ) {
            return sanitize_key((string) $template_id);
        }
    }

    return '';
}

/**
 * Imprime el último resultado técnico y el historial reciente.
 */
function eventosapp_whatsapp_templates_render_meta_diagnostics($template, $compact = false) {
    $template = is_array($template) ? $template : [];
    $result = isset($template['last_meta_result']) && is_array($template['last_meta_result']) ? $template['last_meta_result'] : [];
    $history = isset($template['meta_history']) && is_array($template['meta_history']) ? array_reverse($template['meta_history']) : [];
    $reason = eventosapp_whatsapp_templates_normalize_rejected_reason($template['meta_rejected_reason'] ?? '');

    if ( empty($result) && empty($history) && $reason === '' ) {
        return;
    }

    echo '<details class="evapp-wa-meta-details"' . ($compact ? '' : ' open') . '>';
    echo '<summary>Detalle de Meta y cambios recientes</summary>';
    echo '<div class="evapp-wa-meta-details-body">';

    if ( ! empty($result) ) {
        echo '<p><strong>Última operación:</strong> ' . esc_html($result['operation'] ?: 'consulta') . '<br>';
        echo '<strong>Resultado:</strong> ' . esc_html(! empty($result['ok']) ? 'Procesado' : 'Con novedad') . '<br>';
        if ( ! empty($result['http_code']) ) echo '<strong>HTTP:</strong> ' . esc_html($result['http_code']) . '<br>';
        if ( ! empty($result['error_type']) ) echo '<strong>Tipo:</strong> ' . esc_html($result['error_type']) . '<br>';
        if ( ! empty($result['error_code']) ) echo '<strong>Código:</strong> ' . esc_html($result['error_code']) . '<br>';
        if ( ! empty($result['error_subcode']) ) echo '<strong>Subcódigo:</strong> ' . esc_html($result['error_subcode']) . '<br>';
        if ( ! empty($result['fbtrace_id']) ) echo '<strong>Trace ID:</strong> ' . esc_html($result['fbtrace_id']) . '<br>';
        echo '</p>';
        if ( ! empty($result['message']) ) echo '<p>' . esc_html($result['message']) . '</p>';
    }

    if ( $reason !== '' ) {
        echo '<p><strong>Motivo de rechazo:</strong> ' . esc_html($reason) . '</p>';
    }

    if ( ! empty($history) ) {
        echo '<ol class="evapp-wa-meta-history">';
        foreach ( array_slice($history, 0, 8) as $entry ) {
            if ( ! is_array($entry) ) continue;
            $label = sanitize_text_field((string)($entry['message'] ?? ''));
            if ( $label === '' ) {
                $label = eventosapp_whatsapp_templates_meta_status_label($entry['status'] ?? 'UNKNOWN');
            }
            echo '<li><strong>' . esc_html($entry['at'] ?? '') . '</strong> · ' . esc_html($entry['operation'] ?? 'sync') . '<br><span>' . esc_html($label) . '</span></li>';
        }
        echo '</ol>';
    }

    echo '</div></details>';
}

/**
 * Detecta cuántos botones URL activos tiene una plantilla.
 *
 * Se mantiene limitado a 1 o 2 porque este módulo trabaja exclusivamente con
 * botones URL de plantillas de ticket. Si no hay botones diligenciados, se
 * retorna 1 para que la UI permita construir una plantilla mínima sin forzar
 * dos botones por defecto.
 */
function eventosapp_whatsapp_templates_supported_button_types() {
    return [
        'URL'          => 'URL',
        'QUICK_REPLY'  => 'Respuesta rápida',
        'PHONE_NUMBER' => 'Llamar por teléfono',
    ];
}

function eventosapp_whatsapp_templates_get_button_type($template, $button_number) {
    $template = is_array($template) ? $template : [];
    $button_number = max(1, min(2, absint($button_number)));
    $stored = strtoupper(sanitize_key((string)($template['button_' . $button_number . '_type'] ?? '')));
    if ( isset(eventosapp_whatsapp_templates_supported_button_types()[$stored]) ) return $stored;
    $mode = sanitize_key((string)($template['button_mode'] ?? 'url'));
    if ( $mode === 'quick_reply' ) return 'QUICK_REPLY';
    if ( $mode === 'phone_number' ) return 'PHONE_NUMBER';
    return 'URL';
}

function eventosapp_whatsapp_templates_detect_button_count($template) {
    $template = is_array($template) ? $template : [];
    $button_mode = sanitize_key((string)($template['button_mode'] ?? 'url'));
    if ( $button_mode === 'none' ) {
        return 0;
    }

    $highest_active_slot = 0;
    foreach ( [1, 2] as $button_number ) {
        $text = trim((string)($template['button_' . $button_number . '_text'] ?? ''));
        $url  = trim((string)($template['button_' . $button_number . '_url'] ?? ''));
        if ( $text !== '' || $url !== '' ) {
            $highest_active_slot = max($highest_active_slot, $button_number);
        }
    }

    return $highest_active_slot >= 2 ? 2 : ($highest_active_slot === 1 ? 1 : 0);
}

/**
 * Normaliza la cantidad de botones configurada. Se admite 0 para plantillas
 * sin botones genéricos (por ejemplo Authentication usa su botón OTP especial).
 */
function eventosapp_whatsapp_templates_normalize_button_count($value, $fallback_template = []) {
    $count = is_numeric($value) ? (int)$value : -1;
    if ( ! in_array($count, [0, 1, 2], true) ) {
        $count = eventosapp_whatsapp_templates_detect_button_count($fallback_template);
    }
    return max(0, min(2, $count));
}

/**
 * Devuelve los slots activos según la cantidad configurada.
 */
function eventosapp_whatsapp_templates_get_enabled_button_numbers($template) {
    $template = is_array($template) ? $template : [];
    $count = eventosapp_whatsapp_templates_normalize_button_count($template['button_count'] ?? '', $template);
    if ( $count <= 0 ) return [];
    return $count >= 2 ? [1, 2] : [1];
}

/**
 * Limpia botones deshabilitados sin modificar los botones activos.
 */
function eventosapp_whatsapp_templates_prune_disabled_buttons($template) {
    $template = is_array($template) ? $template : [];
    $button_count = eventosapp_whatsapp_templates_normalize_button_count($template['button_count'] ?? '', $template);
    $template['button_count'] = (string)$button_count;

    if ( sanitize_key((string)($template['button_mode'] ?? 'url')) === 'none' ) {
        $button_count = 0;
        $template['button_count'] = '0';
    }

    foreach ( [1, 2] as $button_number ) {
        if ( $button_number <= $button_count ) continue;
        $template['button_' . $button_number . '_text'] = '';
        $template['button_' . $button_number . '_url'] = '';
        $template['button_' . $button_number . '_example'] = '';
        $template['button_' . $button_number . '_phone_number'] = '';
    }
    return $template;
}

/**
 * Sanitiza el campo de ejemplo de un botón URL.
 *
 * Se permite conservar ejemplos antiguos guardados como URL completa para poder
 * extraer de ellos el sufijo correcto al construir el payload hacia Meta.
 */
function eventosapp_whatsapp_templates_sanitize_button_example($example) {
    $example = trim((string) $example);
    if ( $example === '' ) {
        return '';
    }

    $example = wp_strip_all_tags($example);
    $example = preg_replace('/[\r\n\t]+/', '', $example);
    $example = trim($example);

    return sanitize_text_field($example);
}

/**
 * Extrae el valor que Meta debe recibir en el example de un botón URL dinámico.
 *
 * El campo puede venir de versiones anteriores como URL completa. En ese caso,
 * esta función toma solo la parte que reemplaza {{1}}. Ejemplo:
 * URL plantilla: https://dominio.com/ticket/?ticket={{1}}
 * Ejemplo guardado: https://dominio.com/ticket/?ticket=ticket_demo_123
 * Valor Meta: ticket_demo_123
 */
function eventosapp_whatsapp_templates_button_example_for_meta($url_template, $stored_example = '') {
    $url_template = (string) $url_template;

    if ( strpos($url_template, '{{1}}') === false ) {
        return '';
    }

    $stored_example = eventosapp_whatsapp_templates_sanitize_button_example($stored_example);
    $fallback = eventosapp_whatsapp_templates_default_button_variable_example();
    if ( $stored_example === '' ) {
        $stored_example = $fallback;
    }

    [$prefix, $suffix] = array_pad(explode('{{1}}', $url_template, 2), 2, '');

    if ( $prefix !== '' && strpos($stored_example, $prefix) === 0 ) {
        $candidate = substr($stored_example, strlen($prefix));
        if ( $suffix !== '' && substr($candidate, -strlen($suffix)) === $suffix ) {
            $candidate = substr($candidate, 0, -strlen($suffix));
        }
        $candidate = eventosapp_whatsapp_templates_sanitize_button_example($candidate);
        if ( $candidate !== '' && ! preg_match('/^https?:\/\//i', $candidate) ) {
            return $candidate;
        }
    }

    if ( preg_match('/^https?:\/\//i', $stored_example) ) {
        return $fallback;
    }

    return $stored_example;
}

/**
 * Plantillas base recomendadas para EventosApp.
 */
function eventosapp_whatsapp_templates_default_records() {
    $now = current_time('mysql');

    return [
        'default_presencial' => [
            'id'                    => 'default_presencial',
            'is_default'            => '1',
            'base_key'              => 'presencial',
            'name'                  => 'eventosapp_ticket_presencial_v1',
            'language'              => 'es',
            'category'              => 'UTILITY',
            'modality'              => 'presencial',
            'title'                 => 'Ticket presencial con QR',
            'header_format'         => 'IMAGE',
            'header_text'           => '',
            'header_sample_handle'  => '',
            'header_sample_file_name' => '',
            'header_sample_file_type' => '',
            'header_sample_file_size' => '',
            'header_sample_uploaded_at' => '',
            'body_text'             => "🎟️ Hola {{1}}, tu inscripción a *{{2}}* está confirmada.\n\n✅ Presenta este QR en el ingreso al evento.\n\n📌 *Detalles de tu inscripción:*\n\n🎫 *Evento:* {{2}}\n📅 *Fecha:* {{3}}\n🕒 *Hora:* {{4}}\n📍 *Lugar:* {{5}}\n👥 *Modalidad:* {{8}}\n🏢 *Organizador:* {{7}}\n\n🔗 Ingresa a tu 'Ticket' para ver:\n\n🍎 Ticket para *Apple Wallet*\n💳 Ticket para *Google Wallet*\n📄 Ticket descargable en *PDF*\n📆 Recordatorio para agregar a tu agenda\n\n✨ Te esperamos.",
            'body_examples'         => "María Pérez\nEvento Demo\n20 de mayo de 2026\n8:00 a. m.\nCentro de Convenciones\nhttps://demo.eventosapp.com/ticket_demo_123\nEventosApp\nPresencial",
            'footer_text'           => 'EventosApp',
            'button_count'          => '2',
            'button_1_text'         => 'Ver mi ticket',
            'button_1_url'          => eventosapp_whatsapp_templates_button_url('ticket_landing'),
            'button_1_example'      => eventosapp_whatsapp_templates_default_button_variable_example(),
            'button_2_text'         => 'Agregar a agenda',
            'button_2_url'          => eventosapp_whatsapp_templates_button_url('ticket_ics'),
            'button_2_example'      => eventosapp_whatsapp_templates_default_button_variable_example(),
            'sender_phone_number_id' => '',
            'sender_phone_label'    => 'Número por defecto',
            'waba_id'               => '',
            'meta_template_id'      => '',
            'meta_status'           => 'LOCAL',
            'meta_category'         => '',
            'meta_rejected_reason'  => '',
            'last_api_message'      => '',
            'last_api_response'     => [],
            'last_submitted_at'     => '',
            'last_checked_at'       => '',
            'created_at'            => $now,
            'updated_at'            => $now,
        ],
        'default_virtual' => [
            'id'                    => 'default_virtual',
            'is_default'            => '1',
            'base_key'              => 'virtual',
            'name'                  => 'eventosapp_ticket_virtual_v1',
            'language'              => 'es',
            'category'              => 'UTILITY',
            'modality'              => 'virtual',
            'title'                 => 'Ticket virtual con acceso',
            'header_format'         => 'IMAGE',
            'header_text'           => '',
            'header_sample_handle'  => '',
            'header_sample_file_name' => '',
            'header_sample_file_type' => '',
            'header_sample_file_size' => '',
            'header_sample_uploaded_at' => '',
            'body_text'             => "🎟️ Hola {{1}}, tu inscripción a *{{2}}* está confirmada.\n\n✅ Conserva este mensaje para consultar tu acceso al evento.\n\n📌 *Detalles de tu inscripción:*\n\n🎫 *Evento:* {{2}}\n📅 *Fecha:* {{3}}\n🕒 *Hora:* {{4}}\n💻 *Plataforma:* {{5}}\n👥 *Modalidad:* {{8}}\n🏢 *Organizador:* {{7}}\n\n🔗 Ingresa a tu 'Ticket' para ver:\n\n🍎 Ticket para *Apple Wallet*\n💳 Ticket para *Google Wallet*\n📄 Ticket descargable en *PDF*\n📆 Recordatorio para agregar a tu agenda\n\n✨ Te esperamos.",
            'body_examples'         => "María Pérez\nEvento Demo Virtual\n20 de mayo de 2026\n8:00 a. m.\nZoom\nhttps://demo.eventosapp.com/acceso_virtual\nEventosApp\nVirtual",
            'footer_text'           => 'EventosApp',
            'button_count'          => '2',
            'button_1_text'         => 'Ingresar al evento',
            'button_1_url'          => eventosapp_whatsapp_templates_button_url('virtual_access'),
            'button_1_example'      => eventosapp_whatsapp_templates_default_button_variable_example(),
            'button_2_text'         => 'Agregar a agenda',
            'button_2_url'          => eventosapp_whatsapp_templates_button_url('ticket_ics'),
            'button_2_example'      => eventosapp_whatsapp_templates_default_button_variable_example(),
            'sender_phone_number_id' => '',
            'sender_phone_label'    => 'Número por defecto',
            'waba_id'               => '',
            'meta_template_id'      => '',
            'meta_status'           => 'LOCAL',
            'meta_category'         => '',
            'meta_rejected_reason'  => '',
            'last_api_message'      => '',
            'last_api_response'     => [],
            'last_submitted_at'     => '',
            'last_checked_at'       => '',
            'created_at'            => $now,
            'updated_at'            => $now,
        ],
    ];
}

/**
 * Configuración base del módulo de plantillas.
 */
function eventosapp_whatsapp_templates_default_settings() {
    return [
        'waba_id'      => '',
        'app_id'       => '',
        'default_qr_header_image' => '',
        'default_virtual_message_image' => '',
        'templates'    => eventosapp_whatsapp_templates_default_records(),
        'last_sync_at' => '',
        'last_message' => '',
    ];
}

/**
 * Obtiene configuración del módulo y garantiza plantillas por defecto.
 */
function eventosapp_whatsapp_templates_get_settings() {
    $saved = get_option(EVENTOSAPP_WHATSAPP_TEMPLATES_OPTION, []);
    if ( ! is_array($saved) ) {
        $saved = [];
    }

    $defaults = eventosapp_whatsapp_templates_default_settings();
    $settings = wp_parse_args($saved, $defaults);

    if ( empty($settings['templates']) || ! is_array($settings['templates']) ) {
        $settings['templates'] = [];
    }

    $changed = false;
    foreach ( eventosapp_whatsapp_templates_default_records() as $default_id => $default_template ) {
        if ( empty($settings['templates'][$default_id]) || ! is_array($settings['templates'][$default_id]) ) {
            $settings['templates'][$default_id] = $default_template;
            $changed = true;
        } else {
            $settings['templates'][$default_id] = wp_parse_args($settings['templates'][$default_id], $default_template);

            // Migración segura: solo actualiza la estructura visual/textual de las plantillas base
            // cuando siguen en estado local. No toca plantillas ya aprobadas/en revisión para no
            // desincronizar lo que Meta tiene aprobado con los parámetros que se envían en runtime.
            $current_status = strtoupper((string)($settings['templates'][$default_id]['meta_status'] ?? 'LOCAL'));
            if ( ! empty($settings['templates'][$default_id]['is_default']) && $settings['templates'][$default_id]['is_default'] === '1' && in_array($current_status, ['', 'LOCAL'], true) ) {
                foreach ( ['body_text', 'body_examples', 'header_format', 'footer_text', 'button_count', 'button_1_text', 'button_1_url', 'button_1_example', 'button_2_text', 'button_2_url', 'button_2_example'] as $migrated_field ) {
                    $settings['templates'][$default_id][$migrated_field] = $default_template[$migrated_field];
                }
                $changed = true;
            }
        }
    }

    foreach ( $settings['templates'] as $template_id => $template ) {
        if ( ! is_array($template) ) {
            continue;
        }

        // Migra automáticamente SOLO plantillas de Doble Autenticación que aún
        // no están aprobadas/activas. Las Utility históricas ya aprobadas se
        // conservan intactas para no interrumpir eventos existentes.
        if ( eventosapp_whatsapp_templates_is_double_auth_template($template) ) {
            $current_double_auth_status = eventosapp_whatsapp_templates_normalize_meta_status($template['meta_status'] ?? 'LOCAL');
            if ( ! in_array($current_double_auth_status, ['APPROVED', 'ACTIVE'], true) ) {
                $previous_double_auth_category = eventosapp_whatsapp_templates_normalize_meta_category($template['category'] ?? '');
                $previous_double_auth_meta_category = eventosapp_whatsapp_templates_normalize_meta_category($template['meta_category'] ?? '');
                $authentication_template = eventosapp_whatsapp_templates_normalize_authentication_template($template);

                // Cambiar de Utility a Authentication no es una simple corrección
                // de BODY: es otra tipología de Meta. Se desvincula la plantilla
                // remota rechazada y se usa un nombre nuevo para evitar que el
                // preflight vuelva a enlazar el registro Utility anterior.
                $is_authentication_transition = $previous_double_auth_category !== 'AUTHENTICATION'
                    || ($previous_double_auth_meta_category !== '' && $previous_double_auth_meta_category !== 'AUTHENTICATION');
                if ( $is_authentication_transition ) {
                    $old_remote_id = sanitize_text_field((string)($template['meta_template_id'] ?? ''));
                    $old_name = eventosapp_whatsapp_templates_sanitize_template_name($template['name'] ?? '');
                    $authentication_template['name'] = 'eventosapp_codigo_doble_auth_auth_' . wp_date('Ymd_His') . '_' . substr(md5((string)$template_id . '|' . $old_name), 0, 6);
                    $authentication_template['meta_template_id'] = '';
                    $authentication_template['meta_status'] = 'LOCAL';
                    $authentication_template['meta_category'] = '';
                    $authentication_template['meta_rejected_reason'] = '';
                    $authentication_template['meta_remote_name'] = '';
                    $authentication_template['meta_remote_language'] = '';
                    $authentication_template['meta_quality_score'] = '';
                    $authentication_template['meta_link_source'] = '';
                    $authentication_template['meta_category_mismatch'] = '0';
                    $authentication_template['last_submitted_at'] = '';
                    $authentication_template['last_checked_at'] = '';
                    $authentication_template['last_api_response'] = [];
                    $authentication_template['last_meta_result'] = [];
                    $authentication_template['meta_identity_reset_at'] = current_time('mysql');
                    $authentication_template['meta_identity_reset_reason'] = 'Migración automática de Doble Autenticación desde ' . ($previous_double_auth_category ?: 'Utility') . ' al formato oficial Authentication OTP.';
                    $authentication_template['last_api_message'] = 'La versión anterior' . ($old_name !== '' ? ' “' . $old_name . '”' : '') . ($old_remote_id !== '' ? ' (Meta ID ' . $old_remote_id . ')' : '') . ' quedó desvinculada. El próximo envío creará una plantilla Authentication OTP nueva.';
                }

                if ( maybe_serialize($authentication_template) !== maybe_serialize($template) ) {
                    $settings['templates'][$template_id] = $authentication_template;
                    $template = $authentication_template;
                    $changed = true;
                }
            }
        }

        $normalized_category = eventosapp_whatsapp_templates_sanitize_category($template['category'] ?? 'UTILITY');
        if ( (string)($template['category'] ?? '') !== $normalized_category ) {
            $settings['templates'][$template_id]['category'] = $normalized_category;
            $changed = true;
        }

        $normalized_meta_category = eventosapp_whatsapp_templates_normalize_meta_category($template['meta_category'] ?? '');
        if ( (string)($template['meta_category'] ?? '') !== $normalized_meta_category ) {
            $settings['templates'][$template_id]['meta_category'] = $normalized_meta_category;
            $changed = true;
        }

        $normalized_meta_status = eventosapp_whatsapp_templates_normalize_meta_status($template['meta_status'] ?? 'LOCAL');
        if ( (string)($template['meta_status'] ?? '') !== $normalized_meta_status ) {
            $settings['templates'][$template_id]['meta_status'] = $normalized_meta_status;
            $changed = true;
        }

        $normalized_rejected_reason = eventosapp_whatsapp_templates_normalize_rejected_reason($template['meta_rejected_reason'] ?? '');
        if ( (string)($template['meta_rejected_reason'] ?? '') !== $normalized_rejected_reason ) {
            $settings['templates'][$template_id]['meta_rejected_reason'] = $normalized_rejected_reason;
            $changed = true;
        }

        if ( ! isset($settings['templates'][$template_id]['meta_history']) || ! is_array($settings['templates'][$template_id]['meta_history']) ) {
            $settings['templates'][$template_id]['meta_history'] = [];
            $changed = true;
        } elseif ( count($settings['templates'][$template_id]['meta_history']) > 20 ) {
            $settings['templates'][$template_id]['meta_history'] = array_slice($settings['templates'][$template_id]['meta_history'], -20);
            $changed = true;
        }

        if ( ! isset($settings['templates'][$template_id]['last_meta_result']) || ! is_array($settings['templates'][$template_id]['last_meta_result']) ) {
            $settings['templates'][$template_id]['last_meta_result'] = [];
            $changed = true;
        }

        $normalized_button_count = eventosapp_whatsapp_templates_normalize_button_count($template['button_count'] ?? '', $template);
        if ( (string)($template['button_count'] ?? '') !== (string)$normalized_button_count ) {
            $settings['templates'][$template_id]['button_count'] = (string)$normalized_button_count;
            $changed = true;
        }

        foreach ( [1, 2] as $button_number ) {
            $url_key = 'button_' . $button_number . '_url';
            $example_key = 'button_' . $button_number . '_example';

            $current_url = (string)($template[$url_key] ?? '');
            $normalized_url = eventosapp_whatsapp_templates_normalize_public_button_url($current_url);
            if ( $normalized_url !== $current_url ) {
                $settings['templates'][$template_id][$url_key] = $normalized_url;
                $changed = true;
            }

            $current_example = (string)($template[$example_key] ?? '');
            $normalized_example = eventosapp_whatsapp_templates_normalize_public_button_url($current_example);
            if ( $normalized_example !== $current_example ) {
                $settings['templates'][$template_id][$example_key] = $normalized_example;
                $changed = true;
            }
        }

        $pruned_template = eventosapp_whatsapp_templates_prune_disabled_buttons($settings['templates'][$template_id]);
        foreach ( ['button_count', 'button_2_text', 'button_2_url', 'button_2_example'] as $button_field ) {
            if ( (string)($settings['templates'][$template_id][$button_field] ?? '') !== (string)($pruned_template[$button_field] ?? '') ) {
                $settings['templates'][$template_id][$button_field] = $pruned_template[$button_field] ?? '';
                $changed = true;
            }
        }
    }

    if ( $changed ) {
        update_option(EVENTOSAPP_WHATSAPP_TEMPLATES_OPTION, $settings, false);
    }

    return $settings;
}

/**
 * Guarda configuración completa del módulo.
 */
function eventosapp_whatsapp_templates_update_settings($settings) {
    if ( ! is_array($settings) ) {
        $settings = eventosapp_whatsapp_templates_default_settings();
    }
    update_option(EVENTOSAPP_WHATSAPP_TEMPLATES_OPTION, $settings, false);
}

/**
 * Sanitiza IDs numéricos de WhatsApp Business Account.
 */
function eventosapp_whatsapp_templates_sanitize_waba_id($value) {
    if ( function_exists('eventosapp_whatsapp_sanitize_waba_id') ) {
        return eventosapp_whatsapp_sanitize_waba_id($value);
    }
    return preg_replace('/\D+/', '', (string) $value);
}

/**
 * Sanitiza Phone Number ID usando el helper principal cuando está disponible.
 */
function eventosapp_whatsapp_templates_sanitize_phone_number_id($value) {
    if ( function_exists('eventosapp_whatsapp_sanitize_phone_number_id') ) {
        return eventosapp_whatsapp_sanitize_phone_number_id($value);
    }
    return preg_replace('/\D+/', '', (string) $value);
}

/**
 * Obtiene las cuentas/números configurados en WhatsApp Tickets.
 */
function eventosapp_whatsapp_templates_get_phone_accounts() {
    if ( function_exists('eventosapp_whatsapp_get_settings') && function_exists('eventosapp_whatsapp_get_phone_accounts') ) {
        return eventosapp_whatsapp_get_phone_accounts(eventosapp_whatsapp_get_settings());
    }
    return [];
}

/**
 * Devuelve el Phone Number ID por defecto de WhatsApp Tickets.
 */
function eventosapp_whatsapp_templates_get_default_phone_number_id() {
    if ( function_exists('eventosapp_whatsapp_get_settings') ) {
        $wa_settings = eventosapp_whatsapp_get_settings();
        return eventosapp_whatsapp_templates_sanitize_phone_number_id($wa_settings['phone_number_id'] ?? '');
    }
    return '';
}

/**
 * Devuelve datos completos del número/cuenta seleccionada para una plantilla.
 * Si la plantilla no tiene número explícito, se interpreta como número por defecto
 * para mantener compatibilidad con las plantillas aprobadas antes de agregar multi-número.
 */
function eventosapp_whatsapp_templates_resolve_sender_account($phone_number_id = '', $template_settings = null) {
    $template_settings = is_array($template_settings) ? $template_settings : eventosapp_whatsapp_templates_get_settings();
    $accounts = eventosapp_whatsapp_templates_get_phone_accounts();
    $phone_number_id = eventosapp_whatsapp_templates_sanitize_phone_number_id($phone_number_id);
    $default_phone_number_id = eventosapp_whatsapp_templates_get_default_phone_number_id();

    if ( $phone_number_id === '' ) {
        $phone_number_id = $default_phone_number_id;
    }

    $is_default_sender = ($phone_number_id === '' || $phone_number_id === $default_phone_number_id);

    if ( $phone_number_id !== '' && isset($accounts[$phone_number_id]) && is_array($accounts[$phone_number_id]) ) {
        $account = $accounts[$phone_number_id];
        $is_default_sender = $is_default_sender || ! empty($account['is_default']);
        $account_waba_id = eventosapp_whatsapp_templates_sanitize_waba_id($account['waba_id'] ?? '');

        return [
            'phone_number_id' => $phone_number_id,
            'alias'           => sanitize_text_field((string)($account['alias'] ?? 'Número WhatsApp')),
            'label'           => sanitize_text_field((string)($account['label'] ?? (($account['alias'] ?? 'Número WhatsApp') . ' — ' . $phone_number_id))),
            'waba_id'         => $account_waba_id !== ''
                ? $account_waba_id
                : ($is_default_sender ? eventosapp_whatsapp_templates_sanitize_waba_id($template_settings['waba_id'] ?? '') : ''),
            'is_default'      => $is_default_sender,
            'operator_managed'=> ! empty($account['operator_managed']),
            'client_post_id'  => absint($account['client_post_id'] ?? 0),
        ];
    }

    return [
        'phone_number_id' => $phone_number_id,
        'alias'           => $phone_number_id !== '' ? 'Número no disponible' : 'Número por defecto',
        'label'           => $phone_number_id !== '' ? 'Número no disponible — ' . $phone_number_id : 'Número por defecto',
        'waba_id'         => $is_default_sender ? eventosapp_whatsapp_templates_sanitize_waba_id($template_settings['waba_id'] ?? '') : '',
        'is_default'      => $is_default_sender,
        'operator_managed'=> false,
        'client_post_id'  => 0,
    ];
}

/**
 * WABA efectivo de una plantilla según el número marcado.
 */
function eventosapp_whatsapp_templates_get_template_waba_id($template, $template_settings = null) {
    $template = is_array($template) ? $template : [];
    $template_settings = is_array($template_settings) ? $template_settings : eventosapp_whatsapp_templates_get_settings();
    $sender_phone = eventosapp_whatsapp_templates_sanitize_phone_number_id($template['sender_phone_number_id'] ?? '');
    $account = eventosapp_whatsapp_templates_resolve_sender_account($sender_phone, $template_settings);

    if ( ! empty($account['is_default']) ) {
        $default_waba_id = eventosapp_whatsapp_templates_sanitize_waba_id($template_settings['waba_id'] ?? '');
        return $default_waba_id !== '' ? $default_waba_id : eventosapp_whatsapp_templates_sanitize_waba_id($template['waba_id'] ?? '');
    }

    // Para números distintos al principal, el WABA pertenece a la plantilla.
    // Esto evita que la aprobación se envíe por error al WABA global del número por defecto.
    return eventosapp_whatsapp_templates_sanitize_waba_id($template['waba_id'] ?? '');
}

/**
 * Etiqueta administrativa del número al que pertenece una plantilla.
 */
function eventosapp_whatsapp_templates_get_template_sender_label($template, $template_settings = null) {
    $template = is_array($template) ? $template : [];
    $template_settings = is_array($template_settings) ? $template_settings : eventosapp_whatsapp_templates_get_settings();
    $sender_phone = eventosapp_whatsapp_templates_sanitize_phone_number_id($template['sender_phone_number_id'] ?? '');
    $account = eventosapp_whatsapp_templates_resolve_sender_account($sender_phone, $template_settings);
    return sanitize_text_field((string)($account['label'] ?? 'Número por defecto'));
}

/**
 * Obtiene una plantilla por ID local.
 */
function eventosapp_whatsapp_templates_get_template($template_id) {
    $template_id = sanitize_key((string) $template_id);
    $settings = eventosapp_whatsapp_templates_get_settings();
    return isset($settings['templates'][$template_id]) && is_array($settings['templates'][$template_id]) ? $settings['templates'][$template_id] : null;
}

/**
 * Sanitiza nombres de plantilla aceptados por Meta.
 */
function eventosapp_whatsapp_templates_sanitize_template_name($name) {
    $name = strtolower((string) $name);
    $name = preg_replace('/[^a-z0-9_]+/', '_', $name);
    $name = trim($name, '_');
    return $name;
}

/**
 * Sanitiza URL de botón conservando {{1}} para URL dinámica.
 */
function eventosapp_whatsapp_templates_sanitize_url_template($url) {
    $url = trim((string) $url);
    if ( $url === '' ) {
        return '';
    }

    $placeholder = '__EVENTOSAPP_WA_VAR_1__';
    $url = str_replace('{{1}}', $placeholder, $url);
    $url = esc_url_raw($url);
    $url = str_replace($placeholder, '{{1}}', $url);

    return $url;
}

/**
 * Sanitiza el Header Sample Handle generado por Meta.
 *
 * Este valor no es una URL pública. Meta lo devuelve después de subir
 * una imagen de muestra con Resumable Upload API y suele venir como una
 * cadena larga con prefijos internos, por ejemplo "4::...".
 */
function eventosapp_whatsapp_templates_sanitize_header_handle($handle) {
    $handle = trim((string) $handle);
    if ( $handle === '' ) {
        return '';
    }

    $handle = wp_strip_all_tags($handle);
    $handle = preg_replace('/[\r\n\t]+/', '', $handle);
    $handle = trim($handle);

    return $handle;
}

/**
 * Extrae las variables numéricas del cuerpo en el orden real en que aparecen.
 * Si una variable se repite, conserva una sola entrada para que el valor se
 * reutilice correctamente en Meta y en el envío runtime.
 */
function eventosapp_whatsapp_templates_extract_body_variable_numbers($body_text) {
    $numbers = [];

    if ( preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $body_text, $matches) ) {
        foreach ( (array) $matches[1] as $number ) {
            $number = absint($number);
            if ( $number < 1 ) {
                continue;
            }
            if ( ! in_array($number, $numbers, true) ) {
                $numbers[] = $number;
            }
        }
    }

    return $numbers;
}

/**
 * Convierte el textarea de ejemplos en un arreglo indexado por número de variable.
 * La línea 1 corresponde a {{1}}, la línea 2 a {{2}}, etc. Esto permite que el
 * usuario elimine {{6}} del cuerpo y siga usando {{7}} / {{8}} sin romper el
 * ejemplo que Meta exige para el componente BODY.
 */
function eventosapp_whatsapp_templates_parse_body_examples_by_number($examples_text) {
    $lines = preg_split('/\r\n|\r|\n/', (string) $examples_text);
    $examples = [];
    $index = 1;

    foreach ( (array) $lines as $line ) {
        $line = sanitize_text_field($line);
        if ( $line !== '' ) {
            $examples[$index] = $line;
        }
        $index++;
    }

    return $examples;
}

/**
 * Valor de muestra seguro para cada variable estándar de EventosApp.
 */
function eventosapp_whatsapp_templates_body_example_fallback($variable_number) {
    $fallback = [
        1 => 'María Pérez',
        2 => 'Evento Demo',
        3 => '20 de mayo de 2026',
        4 => '8:00 a. m.',
        5 => 'Centro de Convenciones',
        6 => eventosapp_whatsapp_templates_button_example_url('ticket_landing'),
        7 => 'EventosApp',
        8 => 'Presencial',
    ];

    $variable_number = absint($variable_number);
    return $fallback[$variable_number] ?? ('Ejemplo ' . $variable_number);
}

/**
 * Prepara el cuerpo para Meta.
 *
 * Meta es muy sensible al ejemplo del BODY cuando existen variables. Para evitar
 * rechazos cuando el usuario edita el texto, elimina {{6}} o reordena variables,
 * EventosApp envía a Meta una versión normalizada con variables consecutivas
 * {{1}}, {{2}}, {{3}}..., pero conserva un mapa entre esas posiciones y las
 * variables reales de EventosApp.
 */
function eventosapp_whatsapp_templates_prepare_body_for_meta($body_text, $examples_text = '') {
    $body_text = (string) $body_text;
    $variable_numbers = eventosapp_whatsapp_templates_extract_body_variable_numbers($body_text);
    $examples_by_number = eventosapp_whatsapp_templates_parse_body_examples_by_number($examples_text);

    if ( empty($variable_numbers) ) {
        return [
            'text' => $body_text,
            'variable_numbers' => [],
            'example_values' => [],
            'signature' => md5($body_text),
        ];
    }

    $local_to_meta = [];
    foreach ( $variable_numbers as $index => $local_number ) {
        $local_to_meta[$local_number] = $index + 1;
    }

    $normalized_text = preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', function($match) use ($local_to_meta) {
        $local_number = absint($match[1] ?? 0);
        if ( ! isset($local_to_meta[$local_number]) ) {
            return $match[0];
        }
        return '{{' . $local_to_meta[$local_number] . '}}';
    }, $body_text);

    $example_values = [];
    foreach ( $variable_numbers as $local_number ) {
        $example = $examples_by_number[$local_number] ?? '';
        if ( $example === '' ) {
            $example = eventosapp_whatsapp_templates_body_example_fallback($local_number);
        }
        $example_values[] = sanitize_text_field($example);
    }

    return [
        'text' => (string) $normalized_text,
        'variable_numbers' => array_values(array_map('absint', $variable_numbers)),
        'example_values' => $example_values,
        'signature' => md5($body_text),
    ];
}

/**
 * Sanitiza y normaliza un mapa de variables guardado en la plantilla.
 */
function eventosapp_whatsapp_templates_sanitize_body_variable_map($map) {
    $normalized = [];

    if ( is_string($map) ) {
        $decoded = json_decode($map, true);
        if ( is_array($decoded) ) {
            $map = $decoded;
        }
    }

    if ( is_array($map) ) {
        foreach ( $map as $number ) {
            $number = absint($number);
            if ( $number > 0 && ! in_array($number, $normalized, true) ) {
                $normalized[] = $number;
            }
        }
    }

    return $normalized;
}

/**
 * Normaliza ejemplos del body a una fila compatible con Meta.
 */
function eventosapp_whatsapp_templates_body_examples_to_array($body_text, $examples_text) {
    $prepared = eventosapp_whatsapp_templates_prepare_body_for_meta($body_text, $examples_text);
    return $prepared['example_values'] ?? [];
}

/**
 * Detecta de forma estable si una plantilla pertenece al flujo funcional de
 * Doble Autenticación. Se revisan las marcas persistentes usadas por las
 * versiones actuales y anteriores para que una plantilla ya creada también
 * quede protegida aunque todavía no se haya vuelto a guardar desde el builder.
 */
function eventosapp_whatsapp_templates_is_double_auth_template($template) {
    $template = is_array($template) ? $template : [];

    $builder_type = sanitize_key((string)($template['builder_type'] ?? ''));
    $base_key = sanitize_key((string)($template['base_key'] ?? ''));
    $id = sanitize_key((string)($template['id'] ?? ''));
    $name = eventosapp_whatsapp_templates_sanitize_template_name($template['name'] ?? '');

    return $builder_type === 'double_auth_code'
        || $base_key === 'double_auth_code'
        || $id === 'double_auth_code'
        || ! empty($template['double_auth_code'])
        || strpos($name, 'eventosapp_codigo_doble_auth') === 0;
}

/**
 * Normaliza la plantilla funcional de Doble Autenticación al formato especial
 * AUTHENTICATION de Meta. Estas plantillas NO usan un BODY libre como Utility:
 * Meta genera el texto de autenticación y EventosApp únicamente entrega el OTP
 * en {{1}} durante el envío. El botón OTP COPY_CODE también recibe ese mismo OTP.
 */
function eventosapp_whatsapp_templates_normalize_authentication_template($template) {
    $template = is_array($template) ? $template : [];
    if ( ! eventosapp_whatsapp_templates_is_double_auth_template($template) ) {
        return $template;
    }

    $template['double_auth_code'] = '1';
    $template['builder_type'] = 'double_auth_code';
    $template['base_key'] = 'double_auth_code';
    $template['category'] = 'AUTHENTICATION';
    $template['header_format'] = 'NONE';
    $template['header_text'] = '';
    $template['header_sample_handle'] = '';
    $template['header_sample_file_name'] = '';
    $template['header_sample_file_type'] = '';
    $template['header_sample_file_size'] = 0;
    $template['header_sample_uploaded_at'] = '';
    $template['advanced_components_json'] = '';

    // Representación local mínima para que el runtime conserve el mapa del OTP.
    // Este texto NO se envía como text del componente BODY al crear la plantilla.
    $template['body_text'] = '{{1}}';
    $template['body_examples'] = '48271';
    $template['body_text_meta'] = '{{1}}';
    $template['body_variable_map'] = [1];
    $template['body_variable_signature'] = md5('{{1}}');

    // Authentication usa componentes especiales; no se mezclan footer ni botones
    // genéricos del builder estándar.
    $template['footer_text'] = '';
    $template['button_mode'] = 'none';
    $template['button_count'] = '0';
    foreach ( [1, 2] as $button_number ) {
        $template['button_' . $button_number . '_text'] = '';
        $template['button_' . $button_number . '_url'] = '';
        $template['button_' . $button_number . '_example'] = '';
        $template['button_' . $button_number . '_phone_number'] = '';
        $template['button_' . $button_number . '_type'] = 'URL';
    }

    $template['authentication_otp_type'] = 'COPY_CODE';
    $template['authentication_button_text'] = sanitize_text_field((string)($template['authentication_button_text'] ?? 'Copiar código'));
    if ( $template['authentication_button_text'] === '' ) {
        $template['authentication_button_text'] = 'Copiar código';
    }
    $template['authentication_add_security_recommendation'] = '1';

    // Se deja sin aviso de expiración porque los códigos de EventosApp pueden
    // emitirse con antelación al evento. Si en el futuro el backend aplica TTL,
    // este campo admite 1..90 minutos y build_meta_components lo agregará.
    $expiration = absint($template['authentication_code_expiration_minutes'] ?? 0);
    $template['authentication_code_expiration_minutes'] = ($expiration >= 1 && $expiration <= 90) ? (string)$expiration : '';

    return $template;
}

/**
 * Plantilla base oficial de Doble Autenticación.
 *
 * Se declara aquí, antes de cargar el metabox histórico, para que cualquier
 * instalación nueva cree desde el principio una plantilla AUTHENTICATION y no
 * vuelva a generar la versión Utility de seis variables que Meta puede rechazar.
 */
if ( ! function_exists('eventosapp_double_auth_whatsapp_template_defaults') ) {
    function eventosapp_double_auth_whatsapp_template_defaults() {
        $now = current_time('mysql');
        return [
            'id'                                    => 'double_auth_code',
            'double_auth_code'                      => '1',
            'builder_type'                          => 'double_auth_code',
            'base_key'                              => 'double_auth_code',
            'is_default'                            => '1',
            'name'                                  => 'eventosapp_codigo_doble_auth_v2',
            'language'                              => 'es',
            'category'                              => 'AUTHENTICATION',
            'modality'                              => 'custom',
            'title'                                 => 'Código de acceso · Doble Autenticación',
            'header_format'                         => 'NONE',
            'header_text'                           => '',
            'header_sample_handle'                  => '',
            'body_text'                             => '{{1}}',
            'body_examples'                         => '48271',
            'body_text_meta'                        => '{{1}}',
            'body_variable_map'                     => [1],
            'body_variable_signature'               => md5('{{1}}'),
            'footer_text'                           => '',
            'button_mode'                           => 'none',
            'button_count'                          => '0',
            'button_1_text'                         => '',
            'button_1_url'                          => '',
            'button_1_example'                      => '',
            'button_2_text'                         => '',
            'button_2_url'                          => '',
            'button_2_example'                      => '',
            'authentication_otp_type'               => 'COPY_CODE',
            'authentication_button_text'            => 'Copiar código',
            'authentication_add_security_recommendation' => '1',
            'authentication_code_expiration_minutes'=> '',
            'sender_phone_number_id'                => '',
            'sender_phone_label'                    => 'Número por defecto',
            'waba_id'                               => '',
            'meta_template_id'                      => '',
            'meta_status'                           => 'LOCAL',
            'meta_category'                         => '',
            'meta_rejected_reason'                  => '',
            'last_api_message'                      => '',
            'last_api_response'                     => [],
            'last_submitted_at'                     => '',
            'last_checked_at'                       => '',
            'created_at'                            => $now,
            'updated_at'                            => $now,
        ];
    }
}


/**
 * Detecta si la primera o la última variable del BODY queda realmente en un
 * límite del mensaje para los criterios de Meta.
 *
 * No basta con comprobar espacios: una variable seguida únicamente por punto,
 * asteriscos, emojis u otros signos sigue sin tener texto fijo después. Por eso
 * se exige al menos una letra o un número antes de la primera variable y después
 * de la última. Así se bloquea también el caso {{6}}., que el validador anterior
 * podía dejar pasar.
 *
 * @return string[] Valores posibles: start, end.
 */
function eventosapp_whatsapp_templates_body_boundary_variable_issues($body_text) {
    $body_text = (string) $body_text;
    if ( trim($body_text) === '' ) {
        return [];
    }

    preg_match_all('/\{\{\s*\d+\s*\}\}/u', $body_text, $matches, PREG_OFFSET_CAPTURE);
    $variables = isset($matches[0]) && is_array($matches[0]) ? $matches[0] : [];
    if ( empty($variables) ) {
        return [];
    }

    $issues = [];
    $first = reset($variables);
    $last = end($variables);

    $first_token = is_array($first) ? (string)($first[0] ?? '') : '';
    $first_offset = is_array($first) ? (int)($first[1] ?? 0) : 0;
    $prefix = substr($body_text, 0, max(0, $first_offset));
    if ( ! preg_match('/[\p{L}\p{N}]/u', (string) $prefix) ) {
        $issues[] = 'start';
    }

    $last_token = is_array($last) ? (string)($last[0] ?? '') : '';
    $last_offset = is_array($last) ? (int)($last[1] ?? 0) : 0;
    $suffix_offset = max(0, $last_offset + strlen($last_token));
    $suffix = substr($body_text, $suffix_offset);
    if ( ! preg_match('/[\p{L}\p{N}]/u', (string) $suffix) ) {
        $issues[] = 'end';
    }

    return array_values(array_unique($issues));
}

/**
 * Corrige exclusivamente el BODY de las plantillas de Doble Autenticación
 * antes de guardarlas o enviarlas a Meta.
 *
 * El motor de envío de códigos consume {{1}}..{{6}} y este ajuste NO cambia,
 * elimina ni reordena ninguna variable. Solo agrega texto fijo cuando la primera
 * o la última variable queda en el límite del BODY, evitando el error de Meta
 * código 100 / subcódigo 2388299 sin romper la compatibilidad del envío actual.
 */
function eventosapp_whatsapp_templates_normalize_double_auth_body_for_meta($template) {
    $template = is_array($template) ? $template : [];
    if ( ! eventosapp_whatsapp_templates_is_double_auth_template($template) ) {
        return $template;
    }

    // Compatibilidad con llamadas antiguas: el nombre del helper se conserva,
    // pero desde esta versión la normalización correcta es la estructura oficial
    // AUTHENTICATION de Meta, no agregar texto alrededor de {{1}}..{{6}}.
    return eventosapp_whatsapp_templates_normalize_authentication_template($template);
}

/**
 * Normaliza plantilla desde POST o array.
 */
function eventosapp_whatsapp_templates_normalize_template($raw, $existing = []) {
    $raw = is_array($raw) ? $raw : [];
    $existing = is_array($existing) ? $existing : [];

    $id = ! empty($existing['id']) ? sanitize_key($existing['id']) : (! empty($raw['id']) ? sanitize_key($raw['id']) : 'tpl_' . wp_generate_uuid4());
    if ( $id === '' ) {
        $id = 'tpl_' . wp_generate_uuid4();
    }

    $category = eventosapp_whatsapp_templates_sanitize_category(
        $raw['category'] ?? ($existing['category'] ?? 'UTILITY'),
        $existing['category'] ?? 'UTILITY'
    );

    $language = ! empty($raw['language']) ? sanitize_text_field($raw['language']) : ($existing['language'] ?? 'es');
    $language = preg_replace('/[^a-zA-Z_\-]+/', '', $language);
    if ( $language === '' ) {
        $language = 'es';
    }

    $name = eventosapp_whatsapp_templates_sanitize_template_name($raw['name'] ?? ($existing['name'] ?? ''));
    if ( $name === '' ) {
        $name = 'eventosapp_template_' . substr(md5($id), 0, 8);
    }

    $header_format = ! empty($raw['header_format']) ? strtoupper(sanitize_key($raw['header_format'])) : ($existing['header_format'] ?? 'NONE');
    if ( ! in_array($header_format, ['NONE', 'TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'], true) ) {
        $header_format = 'NONE';
    }

    $modality = ! empty($raw['modality']) ? sanitize_key($raw['modality']) : ($existing['modality'] ?? 'custom');
    if ( ! in_array($modality, ['presencial', 'virtual', 'custom'], true) ) {
        $modality = 'custom';
    }

    $button_mode = sanitize_key((string)($raw['button_mode'] ?? ($existing['button_mode'] ?? 'url')));
    if ( ! in_array($button_mode, ['none', 'url', 'quick_reply', 'phone_number', 'mixed'], true) ) {
        $button_mode = 'url';
    }

    $template_settings = eventosapp_whatsapp_templates_get_settings();
    $requested_sender_phone = array_key_exists('sender_phone_number_id', $raw)
        ? eventosapp_whatsapp_templates_sanitize_phone_number_id($raw['sender_phone_number_id'])
        : eventosapp_whatsapp_templates_sanitize_phone_number_id($existing['sender_phone_number_id'] ?? '');
    $sender_account = eventosapp_whatsapp_templates_resolve_sender_account($requested_sender_phone, $template_settings);
    $effective_sender_phone = eventosapp_whatsapp_templates_sanitize_phone_number_id($sender_account['phone_number_id'] ?? $requested_sender_phone);
    $existing_effective_sender_phone = eventosapp_whatsapp_templates_sanitize_phone_number_id($existing['sender_phone_number_id'] ?? '');
    if ( $existing_effective_sender_phone === '' ) {
        $existing_effective_sender_phone = eventosapp_whatsapp_templates_get_default_phone_number_id();
    }

    $sender_changed = ! empty($existing)
        && $effective_sender_phone !== ''
        && $existing_effective_sender_phone !== ''
        && $effective_sender_phone !== $existing_effective_sender_phone;

    $posted_waba_id = array_key_exists('waba_id', $raw)
        ? eventosapp_whatsapp_templates_sanitize_waba_id($raw['waba_id'])
        : eventosapp_whatsapp_templates_sanitize_waba_id($existing['waba_id'] ?? '');

    $account_waba_id = eventosapp_whatsapp_templates_sanitize_waba_id($sender_account['waba_id'] ?? '');
    if ( $account_waba_id !== '' ) {
        $effective_waba_id = $account_waba_id;
    } elseif ( ! empty($sender_account['is_default']) ) {
        $effective_waba_id = eventosapp_whatsapp_templates_sanitize_waba_id($template_settings['waba_id'] ?? '');
    } else {
        $effective_waba_id = $posted_waba_id;
    }

    $existing_waba_id = ! empty($existing)
        ? eventosapp_whatsapp_templates_get_template_waba_id($existing, $template_settings)
        : '';
    $remote_waba_changed = ! empty($existing)
        && $effective_waba_id !== ''
        && $existing_waba_id !== ''
        && $effective_waba_id !== $existing_waba_id
        && ( ! empty($existing['meta_template_id']) || eventosapp_whatsapp_templates_normalize_meta_status($existing['meta_status'] ?? 'LOCAL') !== 'LOCAL' );

    $name_changed = ! empty($existing)
        && eventosapp_whatsapp_templates_sanitize_template_name($existing['name'] ?? '') !== $name;
    $language_changed = ! empty($existing)
        && sanitize_text_field((string)($existing['language'] ?? '')) !== $language;
    $identity_changed = $name_changed || $language_changed;
    $has_remote_identity = ! empty($existing['meta_template_id'])
        || eventosapp_whatsapp_templates_normalize_meta_status($existing['meta_status'] ?? 'LOCAL') !== 'LOCAL';
    $remote_context_changed = $has_remote_identity && ($sender_changed || $remote_waba_changed || $identity_changed);
    $header_sample_context_changed = $remote_context_changed || $sender_changed || $remote_waba_changed;

    $reset_reasons = [];
    if ( $name_changed ) $reset_reasons[] = 'cambió el nombre técnico';
    if ( $language_changed ) $reset_reasons[] = 'cambió el idioma';
    if ( $sender_changed ) $reset_reasons[] = 'cambió el número emisor';
    if ( $remote_waba_changed ) $reset_reasons[] = 'cambió el WABA';
    $reset_message = $remote_context_changed
        ? 'Se desvinculó la identidad remota porque ' . implode(', ', $reset_reasons) . '. El próximo envío se procesará como una plantilla nueva y no intentará editar la anterior.'
        : '';

    $button_count_source = array_key_exists('button_count', $raw) ? $raw['button_count'] : ($existing['button_count'] ?? '');
    $button_count_fallback = ! empty($raw) ? $raw : $existing;
    $button_count = eventosapp_whatsapp_templates_normalize_button_count($button_count_source, $button_count_fallback);

    $template = [
        'id'                   => $id,
        'attendance_confirmation' => ! empty($existing['attendance_confirmation']) ? '1' : (! empty($raw['attendance_confirmation']) ? '1' : ''),
        'double_auth_code'       => ! empty($existing['double_auth_code']) ? '1' : (! empty($raw['double_auth_code']) ? '1' : ''),
        'authentication_otp_type' => 'COPY_CODE',
        'authentication_button_text' => sanitize_text_field((string)($raw['authentication_button_text'] ?? ($existing['authentication_button_text'] ?? 'Copiar código'))),
        'authentication_add_security_recommendation' => '1',
        'authentication_code_expiration_minutes' => sanitize_text_field((string)($raw['authentication_code_expiration_minutes'] ?? ($existing['authentication_code_expiration_minutes'] ?? ''))),
        'builder_type'           => sanitize_key((string)($raw['builder_type'] ?? ($existing['builder_type'] ?? ''))),
        'advanced_components_json' => sanitize_textarea_field((string)($raw['advanced_components_json'] ?? ($existing['advanced_components_json'] ?? ''))),
        'archived'               => ! empty($raw['archived']) || ! empty($existing['archived']) ? '1' : '0',
        'archived_at'            => sanitize_text_field((string)($existing['archived_at'] ?? '')),
        'is_default'           => ! empty($existing['is_default']) && $existing['is_default'] === '1' ? '1' : '0',
        'base_key'             => ! empty($existing['base_key']) ? sanitize_key($existing['base_key']) : (! empty($raw['base_key']) ? sanitize_key($raw['base_key']) : $modality),
        'name'                 => $name,
        'language'             => $language,
        'category'             => $category,
        'modality'             => $modality,
        'title'                => sanitize_text_field($raw['title'] ?? ($existing['title'] ?? '')),
        'header_format'        => $header_format,
        'header_text'          => sanitize_text_field($raw['header_text'] ?? ($existing['header_text'] ?? '')),
        'header_sample_handle' => $header_sample_context_changed ? '' : eventosapp_whatsapp_templates_sanitize_header_handle($raw['header_sample_handle'] ?? ($existing['header_sample_handle'] ?? '')),
        'header_sample_file_name' => $header_sample_context_changed ? '' : sanitize_file_name($existing['header_sample_file_name'] ?? ''),
        'header_sample_file_type' => $header_sample_context_changed ? '' : sanitize_mime_type($existing['header_sample_file_type'] ?? ''),
        'header_sample_file_size' => $header_sample_context_changed ? 0 : absint($existing['header_sample_file_size'] ?? 0),
        'header_sample_uploaded_at' => $header_sample_context_changed ? '' : sanitize_text_field($existing['header_sample_uploaded_at'] ?? ''),
        'body_text'            => sanitize_textarea_field($raw['body_text'] ?? ($existing['body_text'] ?? '')),
        'body_examples'        => sanitize_textarea_field($raw['body_examples'] ?? ($existing['body_examples'] ?? '')),
        'body_text_meta'       => sanitize_textarea_field($existing['body_text_meta'] ?? ''),
        'body_variable_map'    => eventosapp_whatsapp_templates_sanitize_body_variable_map($existing['body_variable_map'] ?? []),
        'body_variable_signature' => sanitize_text_field($existing['body_variable_signature'] ?? ''),
        'footer_text'          => sanitize_text_field($raw['footer_text'] ?? ($existing['footer_text'] ?? '')),
        'button_mode'          => $button_mode,
        'button_count'         => (string) $button_count,
        'button_1_text'        => sanitize_text_field($raw['button_1_text'] ?? ($existing['button_1_text'] ?? '')),
        'button_1_url'         => $button_mode === 'quick_reply' ? '' : eventosapp_whatsapp_templates_sanitize_url_template($raw['button_1_url'] ?? ($existing['button_1_url'] ?? '')),
        'button_1_example'     => $button_mode === 'quick_reply' ? '' : eventosapp_whatsapp_templates_sanitize_button_example($raw['button_1_example'] ?? ($existing['button_1_example'] ?? '')),
        'button_1_type'        => strtoupper(sanitize_key((string)($raw['button_1_type'] ?? ($existing['button_1_type'] ?? '')))),
        'button_1_phone_number'=> sanitize_text_field((string)($raw['button_1_phone_number'] ?? ($existing['button_1_phone_number'] ?? ''))),
        'button_2_text'        => sanitize_text_field($raw['button_2_text'] ?? ($existing['button_2_text'] ?? '')),
        'button_2_url'         => $button_mode === 'quick_reply' ? '' : eventosapp_whatsapp_templates_sanitize_url_template($raw['button_2_url'] ?? ($existing['button_2_url'] ?? '')),
        'button_2_example'     => $button_mode === 'quick_reply' ? '' : eventosapp_whatsapp_templates_sanitize_button_example($raw['button_2_example'] ?? ($existing['button_2_example'] ?? '')),
        'button_2_type'        => strtoupper(sanitize_key((string)($raw['button_2_type'] ?? ($existing['button_2_type'] ?? '')))),
        'button_2_phone_number'=> sanitize_text_field((string)($raw['button_2_phone_number'] ?? ($existing['button_2_phone_number'] ?? ''))),
        'sender_phone_number_id' => $effective_sender_phone,
        'sender_phone_label'   => sanitize_text_field((string)($sender_account['alias'] ?? ($sender_account['label'] ?? 'Número WhatsApp'))),
        'waba_id'              => $effective_waba_id,
        'meta_template_id'     => $remote_context_changed ? '' : sanitize_text_field($existing['meta_template_id'] ?? ''),
        'meta_status'          => $remote_context_changed ? 'LOCAL' : eventosapp_whatsapp_templates_normalize_meta_status($existing['meta_status'] ?? 'LOCAL'),
        'meta_category'        => $remote_context_changed ? '' : eventosapp_whatsapp_templates_normalize_meta_category($existing['meta_category'] ?? ''),
        'meta_rejected_reason' => $remote_context_changed ? '' : eventosapp_whatsapp_templates_normalize_rejected_reason($existing['meta_rejected_reason'] ?? ''),
        'meta_remote_name'     => $remote_context_changed ? '' : sanitize_key((string)($existing['meta_remote_name'] ?? '')),
        'meta_remote_language' => $remote_context_changed ? '' : sanitize_text_field((string)($existing['meta_remote_language'] ?? '')),
        'meta_quality_score'   => $remote_context_changed ? '' : sanitize_text_field((string)($existing['meta_quality_score'] ?? '')),
        'meta_category_mismatch' => $remote_context_changed ? '0' : (! empty($existing['meta_category_mismatch']) ? '1' : '0'),
        'meta_category_changed_at' => $remote_context_changed ? '' : sanitize_text_field((string)($existing['meta_category_changed_at'] ?? '')),
        'meta_category_previous' => $remote_context_changed ? '' : eventosapp_whatsapp_templates_normalize_meta_category($existing['meta_category_previous'] ?? ''),
        'meta_link_source'     => $remote_context_changed ? '' : sanitize_key((string)($existing['meta_link_source'] ?? '')),
        'meta_identity_reset_at' => $remote_context_changed ? current_time('mysql') : sanitize_text_field((string)($existing['meta_identity_reset_at'] ?? '')),
        'meta_identity_reset_reason' => $remote_context_changed ? $reset_message : sanitize_text_field((string)($existing['meta_identity_reset_reason'] ?? '')),
        'last_api_message'     => $remote_context_changed ? $reset_message : sanitize_text_field($existing['last_api_message'] ?? ''),
        'last_api_response'    => $remote_context_changed ? [] : (isset($existing['last_api_response']) && is_array($existing['last_api_response']) ? $existing['last_api_response'] : []),
        'last_meta_result'     => $remote_context_changed ? [] : (isset($existing['last_meta_result']) && is_array($existing['last_meta_result']) ? $existing['last_meta_result'] : []),
        'meta_history'         => isset($existing['meta_history']) && is_array($existing['meta_history']) ? $existing['meta_history'] : [],
        'last_submitted_at'    => $remote_context_changed ? '' : sanitize_text_field($existing['last_submitted_at'] ?? ''),
        'last_checked_at'      => $remote_context_changed ? '' : sanitize_text_field($existing['last_checked_at'] ?? ''),
        'created_at'           => sanitize_text_field($existing['created_at'] ?? current_time('mysql')),
        'updated_at'           => current_time('mysql'),
    ];

    if ( $template['title'] === '' ) {
        $template['title'] = $template['name'];
    }

    $template = eventosapp_whatsapp_templates_prune_disabled_buttons($template);
    foreach ( [1,2] as $button_number ) {
        $type_key = 'button_' . $button_number . '_type';
        $type = strtoupper(sanitize_key((string)($template[$type_key] ?? '')));
        if ( ! isset(eventosapp_whatsapp_templates_supported_button_types()[$type]) ) {
            $template[$type_key] = $button_mode === 'quick_reply' ? 'QUICK_REPLY' : ($button_mode === 'phone_number' ? 'PHONE_NUMBER' : 'URL');
        }
        $phone_key = 'button_' . $button_number . '_phone_number';
        $template[$phone_key] = preg_replace('/[^0-9+]/', '', (string)($template[$phone_key] ?? ''));
    }

    if ( $button_mode !== 'quick_reply' ) {
        if ( strpos($template['button_1_url'], '{{1}}') !== false && $template['button_1_example'] === '' ) {
            $template['button_1_example'] = eventosapp_whatsapp_templates_default_button_variable_example();
        }
        if ( strpos($template['button_2_url'], '{{1}}') !== false && $template['button_2_example'] === '' ) {
            $template['button_2_example'] = eventosapp_whatsapp_templates_default_button_variable_example();
        }
    }

    // Doble Autenticación usa la tipología AUTHENTICATION oficial de Meta.
    // El helper conserva compatibilidad con registros históricos y fuerza el
    // mapa local a un único OTP {{1}} sin alterar otros tipos de plantilla.
    $template = eventosapp_whatsapp_templates_normalize_double_auth_body_for_meta($template);

    $prepared_body = eventosapp_whatsapp_templates_prepare_body_for_meta($template['body_text'], $template['body_examples']);
    $template['body_text_meta'] = sanitize_textarea_field($prepared_body['text'] ?? $template['body_text']);
    $template['body_variable_map'] = eventosapp_whatsapp_templates_sanitize_body_variable_map($prepared_body['variable_numbers'] ?? []);
    $template['body_variable_signature'] = sanitize_text_field($prepared_body['signature'] ?? md5((string)$template['body_text']));

    if ( $remote_context_changed ) {
        $reset_result = [
            'ok' => true,
            'operation' => 'identity_reset',
            'notice_level' => 'warning',
            'message' => $reset_message,
            'http_code' => 0,
        ];
        $template = eventosapp_whatsapp_templates_append_meta_history($template, 'identity_reset', $reset_result, $existing);
    }

    return $template;
}

/**
 * Valida plantilla antes de enviarla a Meta.
 */
function eventosapp_whatsapp_templates_validate_for_meta($template) {
    $template = is_array($template) ? $template : [];
    $template = eventosapp_whatsapp_templates_normalize_double_auth_body_for_meta($template);
    $errors = [];

    $category = eventosapp_whatsapp_templates_sanitize_category($template['category'] ?? 'UTILITY');
    if ( ! isset(eventosapp_whatsapp_templates_supported_categories()[$category]) ) {
        $errors[] = 'La categoría de la plantilla no es compatible con este módulo. Usa Utility, Marketing o Authentication.';
    }

    $name = eventosapp_whatsapp_templates_sanitize_template_name($template['name'] ?? '');
    if ( $name === '' ) {
        $errors[] = 'Falta el nombre técnico de la plantilla.';
    } elseif ( $name !== (string)($template['name'] ?? '') ) {
        $errors[] = 'El nombre técnico solo puede contener minúsculas, números y guion bajo.';
    }

    if ( empty($template['language']) ) {
        $errors[] = 'Falta el idioma de la plantilla.';
    }

    $is_double_auth = eventosapp_whatsapp_templates_is_double_auth_template($template);
    if ( $category === 'AUTHENTICATION' && ! $is_double_auth ) {
        $errors[] = 'La categoría Authentication se administra desde el preset Doble Autenticación para garantizar la estructura OTP exigida por Meta.';
        return array_values(array_unique($errors));
    }

    if ( $is_double_auth ) {
        if ( $category !== 'AUTHENTICATION' ) {
            $errors[] = 'La plantilla de Doble Autenticación debe enviarse a Meta con categoría AUTHENTICATION.';
        }
        if ( strtoupper((string)($template['header_format'] ?? 'NONE')) !== 'NONE' ) {
            $errors[] = 'Las plantillas Authentication de Doble Autenticación no deben usar encabezado multimedia o de texto.';
        }
        if ( strtoupper(sanitize_key((string)($template['authentication_otp_type'] ?? 'COPY_CODE'))) !== 'COPY_CODE' ) {
            $errors[] = 'Doble Autenticación debe usar el botón OTP COPY_CODE.';
        }
        $button_text = trim((string)($template['authentication_button_text'] ?? 'Copiar código'));
        $button_length = function_exists('mb_strlen') ? mb_strlen($button_text, 'UTF-8') : strlen($button_text);
        if ( $button_text === '' || $button_length > 25 ) {
            $errors[] = 'El texto del botón OTP debe tener entre 1 y 25 caracteres.';
        }
        $expiration = absint($template['authentication_code_expiration_minutes'] ?? 0);
        if ( $expiration !== 0 && ($expiration < 1 || $expiration > 90) ) {
            $errors[] = 'La expiración visible del código Authentication debe estar entre 1 y 90 minutos, o quedar vacía.';
        }
        return array_values(array_unique($errors));
    }

    $advanced_components_json = trim((string)($template['advanced_components_json'] ?? ''));
    $using_advanced_components = false;
    if ( $advanced_components_json !== '' ) {
        $advanced_components = json_decode($advanced_components_json, true);
        if ( ! is_array($advanced_components) || array_values($advanced_components) !== $advanced_components ) {
            $errors[] = 'Los Componentes Meta avanzados deben ser un JSON válido cuyo nivel superior sea un arreglo de componentes.';
        } elseif ( empty($advanced_components) ) {
            $errors[] = 'Los Componentes Meta avanzados no pueden ser un arreglo vacío.';
        } else {
            $using_advanced_components = true;
            foreach ( $advanced_components as $component_index => $component ) {
                if ( ! is_array($component) || empty($component['type']) ) {
                    $errors[] = 'Cada componente avanzado debe ser un objeto e incluir la propiedad type. Revisa el componente ' . ((int)$component_index + 1) . '.';
                    $using_advanced_components = false;
                    break;
                }
            }
        }
    }

    if ( ! $using_advanced_components ) {
        if ( empty($template['body_text']) ) {
            $errors[] = 'Falta el cuerpo de la plantilla.';
        } else {
            $body_variables = eventosapp_whatsapp_templates_extract_body_variable_numbers($template['body_text']);
            if ( count($body_variables) > 20 ) {
                $errors[] = 'El cuerpo de la plantilla tiene demasiadas variables. Usa máximo 20 variables para mantener compatibilidad con Meta.';
            }

            $boundary_issues = eventosapp_whatsapp_templates_body_boundary_variable_issues($template['body_text']);
            if ( in_array('start', $boundary_issues, true) ) {
                $errors[] = 'El cuerpo no puede comenzar con una variable aunque esté rodeada únicamente por signos, formato o emojis. Agrega texto fijo antes del primer parámetro.';
            }
            if ( in_array('end', $boundary_issues, true) ) {
                $errors[] = 'El cuerpo no puede terminar con una variable aunque tenga punto, formato, espacios o emojis después. Agrega texto fijo después del último parámetro.';
            }
        }

        $header_format = strtoupper((string)($template['header_format'] ?? 'NONE'));
        if ( in_array($header_format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) ) {
            if ( empty($template['header_sample_handle']) ) {
                $errors[] = 'La plantilla usa encabezado multimedia. Para enviarla a Meta debes subir un archivo de muestra para generar el Header Sample Handle, o pegar un handle válido generado por Meta.';
            } elseif ( preg_match('/^https?:\/\//i', (string) $template['header_sample_handle']) ) {
                $errors[] = 'El Header Sample Handle no puede ser una URL pública. Debe ser el handle que Meta devuelve después de subir el archivo de muestra con Resumable Upload API.';
            }
        }

        $button_mode = sanitize_key((string)($template['button_mode'] ?? 'url'));
        if ( ! in_array($button_mode, ['none', 'url', 'quick_reply', 'phone_number', 'mixed'], true) ) {
            $button_mode = 'url';
        }

        $buttons = 0;
        foreach ( $button_mode === 'none' ? [] : eventosapp_whatsapp_templates_get_enabled_button_numbers($template) as $i ) {
            $text = trim((string)($template['button_' . $i . '_text'] ?? ''));
            $url  = trim((string)($template['button_' . $i . '_url'] ?? ''));
            $button_type = $button_mode === 'mixed'
                ? eventosapp_whatsapp_templates_get_button_type($template, $i)
                : ($button_mode === 'quick_reply' ? 'QUICK_REPLY' : ($button_mode === 'phone_number' ? 'PHONE_NUMBER' : 'URL'));

            $text_length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
            if ( $text !== '' && $text_length > 25 ) {
                $errors[] = 'El texto de cada botón puede tener máximo 25 caracteres.';
            }

            if ( $button_type === 'QUICK_REPLY' ) {
                if ( $text === '' ) {
                    $errors[] = 'Cada botón de respuesta rápida debe tener texto.';
                    continue;
                }
                $buttons++;
                continue;
            }

            if ( $button_type === 'PHONE_NUMBER' ) {
                $phone = preg_replace('/[^0-9+]/', '', (string)($template['button_' . $i . '_phone_number'] ?? ''));
                if ( $text === '' || $phone === '' ) {
                    $errors[] = 'Cada botón de llamada debe tener texto y número telefónico.';
                }
                $buttons++;
                continue;
            }

            if ( $text !== '' || $url !== '' ) {
                if ( $text === '' || $url === '' ) {
                    $errors[] = 'Cada botón URL debe tener texto y URL.';
                }
                if ( substr_count($url, '{{1}}') > 1 ) {
                    $errors[] = 'Cada botón URL solo puede usar una variable dinámica {{1}}.';
                }
                if ( strpos($url, '{{1}}') !== false ) {
                    $button_example_for_meta = eventosapp_whatsapp_templates_button_example_for_meta($url, $template['button_' . $i . '_example'] ?? '');
                    if ( $button_example_for_meta === '' ) {
                        $errors[] = 'Cada botón con URL dinámica debe tener un valor de ejemplo para reemplazar {{1}}.';
                    }
                }
                $buttons++;
            }
        }

        if ( $button_mode === 'quick_reply' && $buttons < 1 ) {
            $errors[] = 'La plantilla debe tener al menos un botón de respuesta rápida.';
        }
        if ( $buttons > 2 ) {
            $errors[] = 'El builder visual admite máximo 2 botones. Para estructuras especiales usa Componentes Meta JSON en el modo avanzado.';
        }
    }

    return array_values(array_unique($errors));
}

/**
 * Construye componentes para crear/actualizar plantilla en Meta.
 */
function eventosapp_whatsapp_templates_build_meta_components($template) {
    $template = is_array($template) ? $template : [];
    $template = eventosapp_whatsapp_templates_normalize_double_auth_body_for_meta($template);
    $components = [];

    // Meta trata los códigos OTP como una tipología especial. Authentication no
    // acepta el BODY libre usado por Utility: se declara BODY sin text y Meta
    // genera el contenido localizado. COPY_CODE crea el botón OTP nativo.
    if ( eventosapp_whatsapp_templates_is_double_auth_template($template) ) {
        $body = [
            'type' => 'BODY',
            'add_security_recommendation' => ! empty($template['authentication_add_security_recommendation']),
        ];
        $components[] = $body;

        $expiration = absint($template['authentication_code_expiration_minutes'] ?? 0);
        if ( $expiration >= 1 && $expiration <= 90 ) {
            $components[] = [
                'type' => 'FOOTER',
                'code_expiration_minutes' => $expiration,
            ];
        }

        $button_text = sanitize_text_field((string)($template['authentication_button_text'] ?? 'Copiar código'));
        if ( $button_text === '' ) $button_text = 'Copiar código';
        $components[] = [
            'type' => 'BUTTONS',
            'buttons' => [[
                'type' => 'OTP',
                'otp_type' => 'COPY_CODE',
                'text' => $button_text,
            ]],
        ];

        return $components;
    }

    /*
     * Modo avanzado para estructuras oficiales de Meta que no necesitan un
     * control visual específico en EventosApp (por ejemplo catálogo, ubicación
     * u otros componentes futuros). El JSON solo se utiliza si su nivel superior
     * es un arreglo; la validación específica final continúa a cargo de Meta.
     */
    $advanced_components_json = trim((string)($template['advanced_components_json'] ?? ''));
    if ( $advanced_components_json !== '' ) {
        $advanced_components = json_decode($advanced_components_json, true);
        if ( is_array($advanced_components) && array_values($advanced_components) === $advanced_components ) {
            return $advanced_components;
        }
    }

    $header_format = strtoupper((string)($template['header_format'] ?? 'NONE'));
    if ( in_array($header_format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) ) {
        $components[] = [
            'type'    => 'HEADER',
            'format'  => $header_format,
            'example' => [
                'header_handle' => [ $template['header_sample_handle'] ],
            ],
        ];
    } elseif ( $header_format === 'TEXT' && ! empty($template['header_text']) ) {
        $components[] = [
            'type'   => 'HEADER',
            'format' => 'TEXT',
            'text'   => $template['header_text'],
        ];
    }

    $prepared_body = eventosapp_whatsapp_templates_prepare_body_for_meta($template['body_text'] ?? '', $template['body_examples'] ?? '');
    $body_component = [
        'type' => 'BODY',
        'text' => $prepared_body['text'] ?? ($template['body_text'] ?? ''),
    ];

    $body_examples = $prepared_body['example_values'] ?? [];
    if ( ! empty($body_examples) ) {
        $body_component['example'] = [
            'body_text' => [ $body_examples ],
        ];
    }
    $components[] = $body_component;

    if ( ! empty($template['footer_text']) ) {
        $components[] = [
            'type' => 'FOOTER',
            'text' => $template['footer_text'],
        ];
    }

    $button_mode = sanitize_key((string)($template['button_mode'] ?? 'url'));
    if ( ! in_array($button_mode, ['none', 'url', 'quick_reply', 'phone_number', 'mixed'], true) ) $button_mode = 'url';
    $buttons = [];
    foreach ( $button_mode === 'none' ? [] : eventosapp_whatsapp_templates_get_enabled_button_numbers($template) as $i ) {
        $text = trim((string)($template['button_' . $i . '_text'] ?? ''));
        if ( $text === '' ) continue;

        $button_type = $button_mode === 'mixed' ? eventosapp_whatsapp_templates_get_button_type($template, $i) : ($button_mode === 'quick_reply' ? 'QUICK_REPLY' : ($button_mode === 'phone_number' ? 'PHONE_NUMBER' : 'URL'));
        if ( $button_type === 'QUICK_REPLY' ) {
            $buttons[] = ['type'=>'QUICK_REPLY','text'=>$text];
            continue;
        }
        if ( $button_type === 'PHONE_NUMBER' ) {
            $phone = preg_replace('/[^0-9+]/', '', (string)($template['button_' . $i . '_phone_number'] ?? ''));
            if ( $phone !== '' ) $buttons[] = ['type'=>'PHONE_NUMBER','text'=>$text,'phone_number'=>$phone];
            continue;
        }
        $url = trim((string)($template['button_' . $i . '_url'] ?? ''));
        if ( $url === '' ) continue;
        $button = ['type'=>'URL','text'=>$text,'url'=>$url];
        if ( strpos($url, '{{1}}') !== false ) {
            $button_example_for_meta = eventosapp_whatsapp_templates_button_example_for_meta($url, $template['button_' . $i . '_example'] ?? '');
            if ( $button_example_for_meta !== '' ) $button['example'] = [$button_example_for_meta];
        }
        $buttons[] = $button;
    }

    if ( ! empty($buttons) ) {
        $components[] = [
            'type'    => 'BUTTONS',
            'buttons' => array_slice($buttons, 0, 2),
        ];
    }

    return $components;
}

/**
 * Localiza una plantilla de Doble Autenticación Authentication por nombre e idioma.
 *
 * El envío histórico vive en eventosapp-doble-auth.php y fue diseñado cuando el
 * código se modelaba como Utility. Esta capa permite que ese motor estable siga
 * funcionando sin reescribirlo: solo identifica plantillas OTP administradas por
 * este módulo y deja intactas todas las demás plantillas y mensajes.
 */
function eventosapp_whatsapp_templates_find_runtime_authentication_template($name, $language = '') {
    $name = eventosapp_whatsapp_templates_sanitize_template_name($name);
    $language = sanitize_text_field((string)$language);
    if ( $name === '' ) return null;

    $settings = eventosapp_whatsapp_templates_get_settings();
    foreach ( (array)($settings['templates'] ?? []) as $template ) {
        if ( ! is_array($template) || ! eventosapp_whatsapp_templates_is_double_auth_template($template) ) continue;
        if ( eventosapp_whatsapp_templates_sanitize_template_name($template['name'] ?? '') !== $name ) continue;
        if ( $language !== '' && sanitize_text_field((string)($template['language'] ?? '')) !== $language ) continue;

        $remote_category = eventosapp_whatsapp_templates_normalize_meta_category($template['meta_category'] ?? '');
        $local_category = eventosapp_whatsapp_templates_sanitize_category($template['category'] ?? 'UTILITY');
        $effective_category = $remote_category !== '' ? $remote_category : $local_category;
        if ( $effective_category !== 'AUTHENTICATION' ) continue;

        $status = eventosapp_whatsapp_templates_normalize_meta_status($template['meta_status'] ?? 'LOCAL');
        if ( ! in_array($status, ['APPROVED', 'ACTIVE'], true) ) continue;

        return $template;
    }

    return null;
}

/**
 * Compatibilidad runtime para Authentication OTP COPY_CODE.
 *
 * Meta crea el botón como type=OTP/otp_type=COPY_CODE, pero al ENVIAR una
 * plantilla el valor dinámico del botón viaja como componente type=button,
 * sub_type=url, index=0. El motor histórico de Doble Autenticación ya entrega
 * el OTP al BODY; aquí se duplica exclusivamente ese mismo OTP hacia el botón.
 *
 * Se usa el filtro HTTP nativo de WordPress para no tocar el motor estable de
 * tickets/doble autenticación. El alcance está restringido a POST /messages,
 * type=template y a una plantilla local de Doble Autenticación Authentication
 * aprobada. Utility, Marketing, Flow y cualquier otra petición quedan intactas.
 */
function eventosapp_whatsapp_templates_authentication_runtime_http_args($args, $url) {
    $args = is_array($args) ? $args : [];
    $url = (string)$url;

    if ( stripos($url, 'graph.facebook.com/') === false || ! preg_match('#/messages(?:\?.*)?$#i', $url) ) {
        return $args;
    }
    if ( strtoupper((string)($args['method'] ?? 'GET')) !== 'POST' || ! isset($args['body']) ) {
        return $args;
    }

    $body_was_array = is_array($args['body']);
    $payload = $body_was_array ? $args['body'] : json_decode((string)$args['body'], true);
    if ( ! is_array($payload) || sanitize_key((string)($payload['type'] ?? '')) !== 'template' ) {
        return $args;
    }

    $template_payload = isset($payload['template']) && is_array($payload['template']) ? $payload['template'] : [];
    $template_name = eventosapp_whatsapp_templates_sanitize_template_name($template_payload['name'] ?? '');
    $language = sanitize_text_field((string)($template_payload['language']['code'] ?? ''));
    if ( $template_name === '' ) return $args;

    $authentication_template = eventosapp_whatsapp_templates_find_runtime_authentication_template($template_name, $language);
    if ( ! is_array($authentication_template) ) return $args;

    $components = isset($template_payload['components']) && is_array($template_payload['components'])
        ? array_values($template_payload['components'])
        : [];

    $otp = '';
    $has_copy_code_runtime_button = false;
    foreach ( $components as $component ) {
        if ( ! is_array($component) ) continue;
        $type = strtolower(sanitize_key((string)($component['type'] ?? '')));
        if ( $type === 'body' && $otp === '' ) {
            $parameters = isset($component['parameters']) && is_array($component['parameters']) ? $component['parameters'] : [];
            if ( isset($parameters[0]['text']) && is_scalar($parameters[0]['text']) ) {
                $candidate = trim((string)$parameters[0]['text']);
                if ( preg_match('/^\d{5}$/', $candidate) ) $otp = $candidate;
            }
        }
        if ( $type === 'button'
            && strtolower(sanitize_key((string)($component['sub_type'] ?? ''))) === 'url'
            && (string)($component['index'] ?? '') === '0' ) {
            $has_copy_code_runtime_button = true;
        }
    }

    if ( $otp === '' || $has_copy_code_runtime_button ) return $args;

    $components[] = [
        'type' => 'button',
        'sub_type' => 'url',
        'index' => '0',
        'parameters' => [[
            'type' => 'text',
            'text' => $otp,
        ]],
    ];

    $payload['template']['components'] = $components;
    $args['body'] = $body_was_array
        ? $payload
        : wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ( function_exists('eventosapp_whatsapp_log') ) {
        eventosapp_whatsapp_log('Authentication OTP: agregado parámetro runtime para COPY_CODE', [
            'template' => $template_name,
            'language' => $language,
            'has_otp' => true,
        ]);
    }

    return $args;
}
add_filter('http_request_args', 'eventosapp_whatsapp_templates_authentication_runtime_http_args', 20, 2);

/**
 * Petición común a Meta Graph API para plantillas.
 */
function eventosapp_whatsapp_templates_api_request($method, $path, $body = null) {
    $method = strtoupper((string) $method);
    $path = ltrim((string) $path, '/');
    $wa_settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];

    if ( ! empty($wa_settings['dry_run']) && $wa_settings['dry_run'] === '1' ) {
        $result = [
            'ok' => true,
            'http_code' => 0,
            'message' => 'Modo prueba interno: solicitud de plantilla simulada, no se llamó a Meta.',
            'response' => [
                'dry_run' => true,
                'id' => 'dry_run_template_id',
                'status' => 'DRY_RUN',
                'category' => is_array($body) && ! empty($body['category']) ? eventosapp_whatsapp_templates_sanitize_category($body['category']) : 'UTILITY',
            ],
        ];
        return eventosapp_whatsapp_templates_enrich_api_result($result, $method, $path, $body);
    }

    // El cliente Graph central es la fuente única cuando está cargado. Además de
    // mantener compatibilidad con el número principal, puede resolver tokens por
    // WABA cuando el operador de WhatsApp administra cuentas adicionales.
    if ( function_exists('eventosapp_whatsapp_graph_api_request') ) {
        $result = eventosapp_whatsapp_graph_api_request($method, $path, $body, $wa_settings);
        $result = eventosapp_whatsapp_templates_enrich_api_result($result, $method, $path, $body);

        if ( function_exists('eventosapp_whatsapp_log') ) {
            eventosapp_whatsapp_log(! empty($result['ok']) ? 'Plantilla WhatsApp API OK' : 'Plantilla WhatsApp API error', [
                'method' => $method,
                'path' => $path,
                'operation' => $result['operation'] ?? '',
                'http_code' => $result['http_code'] ?? 0,
                'error_type' => $result['error_type'] ?? '',
                'error_code' => $result['error_code'] ?? 0,
                'error_subcode' => $result['error_subcode'] ?? 0,
                'request_body' => is_array($body) ? $body : null,
                'response' => $result['response'] ?? null,
            ]);
        }

        return $result;
    }

    $access_token = trim((string)($wa_settings['access_token'] ?? ''));
    $api_version  = preg_replace('/[^a-zA-Z0-9\.\-_]/', '', (string)($wa_settings['api_version'] ?? 'v23.0'));
    $timeout      = min(60, max(5, absint($wa_settings['request_timeout'] ?? 20)));

    if ( $api_version === '' ) $api_version = 'v23.0';
    if ( $access_token === '' ) {
        return eventosapp_whatsapp_templates_enrich_api_result([
            'ok' => false,
            'http_code' => 0,
            'message' => 'Falta Access Token en WhatsApp Tickets o en la cuenta administrada por el operador.',
            'response' => [
                'error' => [
                    'message' => 'Falta Access Token para ejecutar la solicitud Graph API.',
                    'code' => 190,
                ],
            ],
        ], $method, $path, $body);
    }

    $endpoint = sprintf('https://graph.facebook.com/%s/%s', rawurlencode($api_version), $path);
    $args = [
        'timeout' => $timeout,
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json',
        ],
        'method' => $method,
    ];
    if ( $body !== null ) {
        $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $response = wp_remote_request($endpoint, $args);
    if ( is_wp_error($response) ) {
        return eventosapp_whatsapp_templates_enrich_api_result([
            'ok' => false,
            'http_code' => 0,
            'message' => $response->get_error_message(),
            'response' => [
                'error' => [
                    'message' => $response->get_error_message(),
                    'type' => 'wordpress_http_error',
                ],
            ],
        ], $method, $path, $body);
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw_body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($raw_body, true);
    $result = [
        'ok' => $code >= 200 && $code < 300,
        'http_code' => $code,
        'message' => $code >= 200 && $code < 300 ? 'Solicitud aceptada por Meta.' : eventosapp_whatsapp_templates_extract_api_error($decoded, $raw_body, $code),
        'response' => is_array($decoded) ? $decoded : $raw_body,
        'endpoint' => $endpoint,
    ];
    $result = eventosapp_whatsapp_templates_enrich_api_result($result, $method, $path, $body);

    if ( function_exists('eventosapp_whatsapp_log') ) {
        eventosapp_whatsapp_log(! empty($result['ok']) ? 'Plantilla WhatsApp API OK' : 'Plantilla WhatsApp API error', [
            'method' => $method,
            'path' => $path,
            'operation' => $result['operation'] ?? '',
            'http_code' => $result['http_code'] ?? 0,
            'error_type' => $result['error_type'] ?? '',
            'error_code' => $result['error_code'] ?? 0,
            'error_subcode' => $result['error_subcode'] ?? 0,
            'request_body' => is_array($body) ? $body : null,
            'response' => $result['response'] ?? null,
        ]);
    }

    return $result;
}

/**
 * Extrae errores de Meta.
 */
function eventosapp_whatsapp_templates_extract_api_error($decoded, $raw_body, $code) {
    $response = is_array($decoded) ? $decoded : [];
    $result = [
        'ok' => false,
        'http_code' => absint($code),
        'response' => $response,
        'technical_message' => is_string($raw_body) ? $raw_body : '',
    ];
    $error = eventosapp_whatsapp_templates_error_context($response);
    $error_type = eventosapp_whatsapp_templates_classify_api_error($result);

    if ( empty($response) && function_exists('eventosapp_whatsapp_extract_api_error') ) {
        return eventosapp_whatsapp_extract_api_error($decoded, $raw_body, $code);
    }

    return eventosapp_whatsapp_templates_error_message($error_type, $error, $code);
}

/**
 * Indica si el formulario recibió un archivo de muestra para encabezado.
 */
function eventosapp_whatsapp_templates_has_header_sample_upload() {
    return ! empty($_FILES['header_sample_file'])
        && is_array($_FILES['header_sample_file'])
        && isset($_FILES['header_sample_file']['error'])
        && (int) $_FILES['header_sample_file']['error'] !== UPLOAD_ERR_NO_FILE;
}

/**
 * Valida el archivo local antes de subirlo a Meta como muestra de encabezado.
 */
function eventosapp_whatsapp_templates_validate_header_sample_file($file, $header_format = 'IMAGE') {
    if ( empty($file) || ! is_array($file) ) return new WP_Error('evapp_wa_header_file_missing', 'No se recibió el archivo de muestra.');
    $error = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ( $error === UPLOAD_ERR_NO_FILE ) return new WP_Error('evapp_wa_header_file_missing', 'No se seleccionó ningún archivo de muestra.');
    if ( $error !== UPLOAD_ERR_OK ) return new WP_Error('evapp_wa_header_file_upload_error', 'WordPress no pudo recibir el archivo de muestra. Código de carga: ' . $error . '.');
    $tmp_name = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    if ( $tmp_name === '' || ! is_uploaded_file($tmp_name) || ! file_exists($tmp_name) || ! is_readable($tmp_name) ) return new WP_Error('evapp_wa_header_file_invalid_tmp', 'El archivo temporal de la muestra no está disponible o no se puede leer.');

    $header_format = strtoupper(sanitize_key((string)$header_format));
    if ( ! in_array($header_format, ['IMAGE','VIDEO','DOCUMENT'], true) ) $header_format = 'IMAGE';
    $size = isset($file['size']) ? absint($file['size']) : filesize($tmp_name);
    if ( $size <= 0 ) return new WP_Error('evapp_wa_header_file_empty', 'El archivo de muestra está vacío.');
    $max_size = $header_format === 'IMAGE' ? 5 * 1024 * 1024 : 16 * 1024 * 1024;
    if ( $size > $max_size ) return new WP_Error('evapp_wa_header_file_too_large', 'El archivo de muestra supera el máximo permitido por EventosApp para este tipo de encabezado.');

    $original_name = isset($file['name']) ? sanitize_file_name((string)$file['name']) : 'eventosapp-header-sample';
    $allowed_by_format = [
        'IMAGE' => ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'],
        'VIDEO' => ['mp4'=>'video/mp4'],
        'DOCUMENT' => ['pdf'=>'application/pdf'],
    ];
    $allowed_mimes = $allowed_by_format[$header_format];
    $check = wp_check_filetype_and_ext($tmp_name, $original_name, $allowed_mimes);
    $mime = ! empty($check['type']) ? $check['type'] : '';
    if ( $mime === '' && function_exists('finfo_open') ) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ( $finfo ) { $mime = (string)finfo_file($finfo, $tmp_name); finfo_close($finfo); }
    }
    if ( ! in_array($mime, array_values($allowed_mimes), true) ) {
        $label = $header_format === 'IMAGE' ? 'JPG/JPEG o PNG' : ($header_format === 'VIDEO' ? 'MP4' : 'PDF');
        return new WP_Error('evapp_wa_header_file_type', 'La muestra para este encabezado debe ser ' . $label . '.');
    }
    if ( $original_name === '' ) $original_name = 'eventosapp-header-sample.' . (string)key($allowed_mimes);
    return ['tmp_name'=>$tmp_name,'name'=>$original_name,'type'=>$mime,'size'=>$size];
}

/**
 * Sube una imagen de muestra a Meta y devuelve el Header Sample Handle.
 */
function eventosapp_whatsapp_templates_upload_header_sample_to_meta($file, $template = []) {
    $settings = eventosapp_whatsapp_templates_get_settings();
    $wa_settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];
    $template = is_array($template) ? $template : [];

    $sender_phone_number_id = eventosapp_whatsapp_templates_sanitize_phone_number_id($template['sender_phone_number_id'] ?? '');
    if ( $sender_phone_number_id !== '' && function_exists('eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id') ) {
        $wa_settings = eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id($sender_phone_number_id, $wa_settings);
    }

    $operator_settings = function_exists('eventosapp_wa_operator_get_settings') ? eventosapp_wa_operator_get_settings() : [];
    $app_id = preg_replace('/\D+/', '', (string)($settings['app_id'] ?? ''));
    if ( $app_id === '' && ! empty($operator_settings['app_id']) ) {
        $app_id = preg_replace('/\D+/', '', (string)$operator_settings['app_id']);
    }

    $access_token = trim((string)($wa_settings['access_token'] ?? ''));
    if ( $access_token === '' && function_exists('eventosapp_wa_operator_get_any_access_token') ) {
        $access_token = eventosapp_wa_operator_get_any_access_token();
    }

    $api_version  = preg_replace('/[^a-zA-Z0-9\.\-_]/', '', (string)($wa_settings['api_version'] ?? ($operator_settings['api_version'] ?? 'v23.0')));
    $timeout      = min(60, max(5, absint($wa_settings['request_timeout'] ?? 20)));

    if ( $api_version === '' ) {
        $api_version = 'v23.0';
    }

    if ( $app_id === '' ) {
        return [
            'ok' => false,
            'message' => 'Falta configurar el Meta App ID en la conexión de plantillas. Este ID se necesita para crear la sesión de Resumable Upload y generar el Header Sample Handle.',
        ];
    }

    if ( $access_token === '' ) {
        return [
            'ok' => false,
            'message' => 'Falta Access Token en WhatsApp Tickets. El mismo token se usa para subir la muestra y crear la plantilla.',
        ];
    }

    $validated = eventosapp_whatsapp_templates_validate_header_sample_file($file, $template['header_format'] ?? 'IMAGE');
    if ( is_wp_error($validated) ) {
        return [
            'ok' => false,
            'message' => $validated->get_error_message(),
        ];
    }

    if ( ! empty($wa_settings['dry_run']) && $wa_settings['dry_run'] === '1' ) {
        return [
            'ok' => true,
            'message' => 'Modo prueba interno: muestra simulada. No se subió archivo a Meta.',
            'handle' => 'dry_run_header_handle_' . substr(md5($validated['name'] . $validated['size']), 0, 16),
            'file' => $validated,
            'response' => [
                'dry_run' => true,
            ],
        ];
    }

    $session_endpoint = sprintf('https://graph.facebook.com/%s/%s/uploads', rawurlencode($api_version), rawurlencode($app_id));
    $session_endpoint = add_query_arg([
        'file_name'   => $validated['name'],
        'file_length' => $validated['size'],
        'file_type'   => $validated['type'],
        'access_token' => $access_token,
    ], $session_endpoint);

    $session_response = wp_remote_post($session_endpoint, [
        'timeout' => $timeout,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
    ]);

    if ( is_wp_error($session_response) ) {
        return [
            'ok' => false,
            'message' => 'No se pudo crear la sesión de carga en Meta: ' . $session_response->get_error_message(),
        ];
    }

    $session_code = (int) wp_remote_retrieve_response_code($session_response);
    $session_body = (string) wp_remote_retrieve_body($session_response);
    $session_decoded = json_decode($session_body, true);
    $session_ok = $session_code >= 200 && $session_code < 300 && is_array($session_decoded) && ! empty($session_decoded['id']);

    if ( function_exists('eventosapp_whatsapp_log') ) {
        eventosapp_whatsapp_log($session_ok ? 'Header Sample: sesión de carga creada' : 'Header Sample: error creando sesión', [
            'http_code' => $session_code,
            'file_name' => $validated['name'],
            'file_type' => $validated['type'],
            'file_size' => $validated['size'],
            'response' => $session_decoded ?: $session_body,
        ]);
    }

    if ( ! $session_ok ) {
        return [
            'ok' => false,
            'message' => eventosapp_whatsapp_templates_extract_api_error($session_decoded, $session_body, $session_code),
            'response' => $session_decoded ?: $session_body,
        ];
    }

    $upload_session_id = (string) $session_decoded['id'];
    $binary = file_get_contents($validated['tmp_name']);
    if ( $binary === false || $binary === '' ) {
        return [
            'ok' => false,
            'message' => 'No se pudo leer el archivo de muestra para enviarlo a Meta.',
        ];
    }

    $upload_endpoint = sprintf('https://graph.facebook.com/%s/%s', rawurlencode($api_version), $upload_session_id);
    $upload_response = wp_remote_request($upload_endpoint, [
        'method' => 'POST',
        'timeout' => $timeout,
        'headers' => [
            'Authorization' => 'OAuth ' . $access_token,
            'file_offset'   => '0',
            'Content-Type'  => $validated['type'],
            'Content-Length' => (string) strlen($binary),
        ],
        'body' => $binary,
    ]);

    if ( is_wp_error($upload_response) ) {
        return [
            'ok' => false,
            'message' => 'No se pudo subir la muestra a Meta: ' . $upload_response->get_error_message(),
        ];
    }

    $upload_code = (int) wp_remote_retrieve_response_code($upload_response);
    $upload_body = (string) wp_remote_retrieve_body($upload_response);
    $upload_decoded = json_decode($upload_body, true);
    $handle = '';

    if ( is_array($upload_decoded) ) {
        if ( ! empty($upload_decoded['h']) ) {
            $handle = eventosapp_whatsapp_templates_sanitize_header_handle($upload_decoded['h']);
        } elseif ( ! empty($upload_decoded['handle']) ) {
            $handle = eventosapp_whatsapp_templates_sanitize_header_handle($upload_decoded['handle']);
        }
    }

    $upload_ok = $upload_code >= 200 && $upload_code < 300 && $handle !== '';

    if ( function_exists('eventosapp_whatsapp_log') ) {
        eventosapp_whatsapp_log($upload_ok ? 'Header Sample: archivo subido a Meta' : 'Header Sample: error subiendo archivo', [
            'http_code' => $upload_code,
            'file_name' => $validated['name'],
            'file_type' => $validated['type'],
            'file_size' => $validated['size'],
            'has_handle' => $handle !== '',
            'response' => $upload_decoded ?: $upload_body,
        ]);
    }

    if ( ! $upload_ok ) {
        return [
            'ok' => false,
            'message' => $handle === '' ? 'Meta respondió la carga, pero no devolvió un Header Sample Handle utilizable.' : eventosapp_whatsapp_templates_extract_api_error($upload_decoded, $upload_body, $upload_code),
            'response' => $upload_decoded ?: $upload_body,
        ];
    }

    return [
        'ok' => true,
        'message' => 'Imagen de muestra subida a Meta. Header Sample Handle generado correctamente.',
        'handle' => $handle,
        'file' => $validated,
        'response' => $upload_decoded,
    ];
}

/**
 * Envia o reenvía una plantilla a Meta.
 */
function eventosapp_whatsapp_templates_submit_to_meta($template_id) {
    $template_id = sanitize_key((string) $template_id);
    $settings = eventosapp_whatsapp_templates_get_settings();

    if ( empty($settings['templates'][$template_id]) || ! is_array($settings['templates'][$template_id]) ) {
        return [
            'ok' => false,
            'message' => 'Plantilla local no encontrada.',
            'error_type' => 'not_found',
            'notice_level' => 'error',
        ];
    }

    $template = $settings['templates'][$template_id];
    $before = $template;

    // Autocorrección de compatibilidad para registros de Doble Autenticación
    // creados antes de esta versión. Esto permite reenviarlos sin obligar al
    // administrador a abrir y guardar primero el editor.
    $template = eventosapp_whatsapp_templates_normalize_double_auth_body_for_meta($template);

    $waba_id = eventosapp_whatsapp_templates_get_template_waba_id($template, $settings);
    if ( $waba_id === '' ) {
        return [
            'ok' => false,
            'message' => 'Configura el WhatsApp Business Account ID del número emisor al que pertenece esta plantilla.',
            'error_type' => 'invalid_parameter',
            'notice_level' => 'error',
        ];
    }

    $sender_account = eventosapp_whatsapp_templates_resolve_sender_account($template['sender_phone_number_id'] ?? '', $settings);
    $prepared_body = eventosapp_whatsapp_templates_prepare_body_for_meta($template['body_text'] ?? '', $template['body_examples'] ?? '');
    $requested_category = eventosapp_whatsapp_templates_sanitize_category($template['category'] ?? 'UTILITY');
    $template['category'] = $requested_category;
    $template['body_text_meta'] = sanitize_textarea_field($prepared_body['text'] ?? ($template['body_text'] ?? ''));
    $template['body_variable_map'] = eventosapp_whatsapp_templates_sanitize_body_variable_map($prepared_body['variable_numbers'] ?? []);
    $template['body_variable_signature'] = sanitize_text_field($prepared_body['signature'] ?? md5((string)($template['body_text'] ?? '')));
    $template['sender_phone_number_id'] = eventosapp_whatsapp_templates_sanitize_phone_number_id($sender_account['phone_number_id'] ?? ($template['sender_phone_number_id'] ?? ''));
    $template['sender_phone_label'] = sanitize_text_field((string)($sender_account['alias'] ?? ($sender_account['label'] ?? 'Número WhatsApp')));
    $template['waba_id'] = $waba_id;
    $template['meta_rejected_reason'] = eventosapp_whatsapp_templates_normalize_rejected_reason($template['meta_rejected_reason'] ?? '');
    $settings['templates'][$template_id] = $template;
    eventosapp_whatsapp_templates_update_settings($settings);

    $local_duplicate_id = eventosapp_whatsapp_templates_find_local_duplicate(
        $template['name'] ?? '',
        $template['language'] ?? '',
        $waba_id,
        $template_id
    );
    if ( $local_duplicate_id !== '' ) {
        $result = [
            'ok' => false,
            'operation' => empty($template['meta_template_id']) ? 'create' : 'update',
            'notice_level' => 'warning',
            'error_type' => 'template_duplicate',
            'message' => 'No se envió la plantilla porque el inventario local ya contiene otra plantilla con el mismo nombre técnico, idioma y WABA. Registro en conflicto: ' . $local_duplicate_id . '.',
            'http_code' => 0,
        ];
        $template = eventosapp_whatsapp_templates_append_meta_history($template, 'local_duplicate_blocked', $result, $before);
        $template['last_api_message'] = $result['message'];
        $settings['templates'][$template_id] = $template;
        eventosapp_whatsapp_templates_update_settings($settings);
        return $result;
    }

    $errors = eventosapp_whatsapp_templates_validate_for_meta($template);
    if ( ! empty($errors) ) {
        $result = [
            'ok' => false,
            'operation' => empty($template['meta_template_id']) ? 'create' : 'update',
            'notice_level' => 'error',
            'error_type' => 'invalid_parameter',
            'message' => implode(' ', $errors),
            'http_code' => 0,
        ];
        $template = eventosapp_whatsapp_templates_append_meta_history($template, 'validation_failed', $result, $before);
        $template['last_api_message'] = $result['message'];
        $settings['templates'][$template_id] = $template;
        eventosapp_whatsapp_templates_update_settings($settings);
        return $result;
    }

    $meta_template_id = sanitize_text_field((string)($template['meta_template_id'] ?? ''));
    if ( $meta_template_id !== '' ) {
        $remote_result = eventosapp_whatsapp_templates_get_remote_template_by_id($meta_template_id);
        if ( ! empty($remote_result['ok']) && is_array($remote_result['response'] ?? null) ) {
            $remote = $remote_result['response'];
            $remote_name = eventosapp_whatsapp_templates_sanitize_template_name($remote['name'] ?? '');
            $remote_language = sanitize_text_field((string)($remote['language'] ?? ''));
            $local_name = eventosapp_whatsapp_templates_sanitize_template_name($template['name'] ?? '');
            $local_language = sanitize_text_field((string)($template['language'] ?? ''));

            if ( ($remote_name !== '' && $remote_name !== $local_name) || ($remote_language !== '' && $remote_language !== $local_language) ) {
                $template['meta_template_id'] = '';
                $template['meta_status'] = 'LOCAL';
                $template['meta_category'] = '';
                $template['meta_rejected_reason'] = '';
                $template['meta_link_source'] = '';
                $template['last_api_message'] = 'El ID remoto guardado pertenecía a otro nombre o idioma. EventosApp lo desvinculó y procesará esta configuración como una plantilla nueva.';
                $template = eventosapp_whatsapp_templates_append_meta_history($template, 'remote_identity_detached', [
                    'ok' => true,
                    'operation' => 'identity_reset',
                    'notice_level' => 'warning',
                    'message' => $template['last_api_message'],
                    'http_code' => $remote_result['http_code'] ?? 0,
                ], $before);
                $meta_template_id = '';
            } else {
                $template = eventosapp_whatsapp_templates_apply_remote_snapshot($template, $remote, 'preflight_status', $remote_result);
                $remote_status = eventosapp_whatsapp_templates_normalize_meta_status($template['meta_status'] ?? 'UNKNOWN');
                if ( $remote_status !== 'REJECTED' ) {
                    $result = [
                        'ok' => false,
                        'operation' => 'update',
                        'notice_level' => 'warning',
                        'error_type' => 'template_edit_locked',
                        'http_code' => 0,
                        'meta_id' => $template['meta_template_id'] ?? '',
                        'meta_status' => $remote_status,
                        'meta_category' => $template['meta_category'] ?? '',
                        'message' => 'No se enviaron cambios a Meta. La plantilla remota está ' . eventosapp_whatsapp_templates_meta_status_label($remote_status) . ' y Meta solo permite editar plantillas rechazadas. Duplica la plantilla o cambia el nombre técnico para crear una nueva versión.',
                    ];
                    $template['last_api_message'] = $result['message'];
                    $template = eventosapp_whatsapp_templates_append_meta_history($template, 'update_blocked', $result, $before);
                    $settings['templates'][$template_id] = $template;
                    eventosapp_whatsapp_templates_update_settings($settings);
                    return $result;
                }
            }
        } elseif ( ($remote_result['error_type'] ?? '') === 'not_found' ) {
            $template['meta_template_id'] = '';
            $template['meta_status'] = 'LOCAL';
            $template['meta_category'] = '';
            $template['meta_rejected_reason'] = '';
            $template['meta_link_source'] = '';
            $template['last_api_message'] = 'El ID remoto ya no pudo encontrarse. EventosApp eliminó el vínculo obsoleto y verificará el nombre antes de crear una plantilla nueva.';
            $template = eventosapp_whatsapp_templates_append_meta_history($template, 'stale_remote_id_cleared', [
                'ok' => true,
                'operation' => 'identity_reset',
                'notice_level' => 'warning',
                'message' => $template['last_api_message'],
                'http_code' => $remote_result['http_code'] ?? 0,
            ], $before);
            $meta_template_id = '';
        } else {
            $template['last_api_message'] = sanitize_text_field((string)($remote_result['message'] ?? 'No se pudo verificar la plantilla remota antes de editarla.'));
            $template['last_api_response'] = is_array($remote_result['response'] ?? null) ? $remote_result['response'] : [];
            $template = eventosapp_whatsapp_templates_append_meta_history($template, 'preflight_failed', $remote_result, $before);
            $settings['templates'][$template_id] = $template;
            eventosapp_whatsapp_templates_update_settings($settings);
            return $remote_result;
        }
    }

    if ( $meta_template_id === '' ) {
        $remote_match = eventosapp_whatsapp_templates_find_remote_template($waba_id, $template['name'] ?? '', $template['language'] ?? '');
        if ( empty($remote_match['ok']) ) {
            $template['last_api_message'] = sanitize_text_field((string)($remote_match['message'] ?? 'No se pudo comprobar si el nombre ya existe en Meta.'));
            $template = eventosapp_whatsapp_templates_append_meta_history($template, 'duplicate_preflight_failed', $remote_match, $before);
            $settings['templates'][$template_id] = $template;
            eventosapp_whatsapp_templates_update_settings($settings);
            return $remote_match;
        }

        if ( ! empty($remote_match['found']) && is_array($remote_match['remote'] ?? null) ) {
            $remote = $remote_match['remote'];
            $duplicate_result = [
                'ok' => false,
                'operation' => 'create',
                'notice_level' => 'warning',
                'error_type' => 'template_duplicate',
                'http_code' => 0,
                'meta_id' => sanitize_text_field((string)($remote['id'] ?? '')),
                'meta_status' => eventosapp_whatsapp_templates_normalize_meta_status($remote['status'] ?? 'UNKNOWN'),
                'meta_category' => eventosapp_whatsapp_templates_normalize_meta_category($remote['category'] ?? ''),
                'message' => 'No se creó una plantilla duplicada. Meta ya tiene el nombre técnico “' . sanitize_text_field((string)($template['name'] ?? '')) . '” para el idioma ' . sanitize_text_field((string)($template['language'] ?? '')) . ' en este WABA. EventosApp vinculó el registro local a la plantilla remota encontrada; cambia el nombre técnico para crear una versión nueva.',
                'response' => $remote,
            ];
            $template = eventosapp_whatsapp_templates_apply_remote_snapshot($template, $remote, 'remote_duplicate_detected', $duplicate_result);
            $template['meta_link_source'] = 'name_match';
            $template['meta_duplicate_detected_at'] = current_time('mysql');
            $template['last_api_message'] = $duplicate_result['message'];
            $settings['templates'][$template_id] = $template;
            eventosapp_whatsapp_templates_update_settings($settings);
            return $duplicate_result;
        }
    }

    $payload = [
        'name'       => $template['name'],
        'language'   => $template['language'],
        'category'   => $requested_category,
        'components' => eventosapp_whatsapp_templates_build_meta_components($template),
    ];

    if ( function_exists('eventosapp_whatsapp_log') ) {
        eventosapp_whatsapp_log('Plantilla WhatsApp enviada a Meta con identidad verificada', [
            'template_id' => $template_id,
            'template_name' => $template['name'] ?? '',
            'operation' => $meta_template_id !== '' ? 'update_rejected' : 'create',
            'requested_category' => $requested_category,
            'sender_phone_number_id' => $template['sender_phone_number_id'] ?? '',
            'sender_phone_label' => $template['sender_phone_label'] ?? '',
            'waba_id' => $waba_id,
            'meta_template_id' => $meta_template_id,
            'button_mode' => $template['button_mode'] ?? 'url',
            'button_count' => $template['button_count'] ?? '',
            'header_format' => $template['header_format'] ?? '',
            'body_variable_map' => $template['body_variable_map'] ?? [],
        ]);
    }

    if ( $meta_template_id !== '' ) {
        $api_result = eventosapp_whatsapp_templates_api_request('POST', rawurlencode($meta_template_id), [
            'category'   => $requested_category,
            'components' => $payload['components'],
        ]);
        $operation = 'update';
    } else {
        $api_result = eventosapp_whatsapp_templates_api_request('POST', rawurlencode($waba_id) . '/message_templates', $payload);
        $operation = 'create';
    }

    $response = is_array($api_result['response'] ?? null) ? $api_result['response'] : [];
    $template['last_submitted_at'] = current_time('mysql');
    $template['last_api_message'] = sanitize_text_field((string)($api_result['message'] ?? ''));
    $template['last_api_response'] = function_exists('eventosapp_whatsapp_sanitize_log_context')
        ? eventosapp_whatsapp_sanitize_log_context($response)
        : $response;

    if ( ! empty($api_result['ok']) ) {
        if ( empty($response['id']) && $meta_template_id !== '' ) $response['id'] = $meta_template_id;
        if ( empty($response['status']) ) $response['status'] = 'PENDING';
        if ( empty($response['category']) ) $response['category'] = $requested_category;
        if ( empty($response['name']) ) $response['name'] = $template['name'];
        if ( empty($response['language']) ) $response['language'] = $template['language'];
        $template = eventosapp_whatsapp_templates_apply_remote_snapshot($template, $response, $operation, $api_result);
        $template['meta_link_source'] = $operation === 'create' ? 'created_by_eventosapp' : ($template['meta_link_source'] ?? 'updated_by_eventosapp');
        $api_result['message'] = trim((string)$api_result['message'] . ' ' . eventosapp_whatsapp_templates_category_status_message($requested_category, $template['meta_category'] ?? ''));
        $template['last_api_message'] = sanitize_text_field($api_result['message']);
    } else {
        if ( ($api_result['error_type'] ?? '') === 'template_duplicate' ) {
            $race_match = eventosapp_whatsapp_templates_find_remote_template($waba_id, $template['name'] ?? '', $template['language'] ?? '');
            if ( ! empty($race_match['found']) && is_array($race_match['remote'] ?? null) ) {
                $template = eventosapp_whatsapp_templates_apply_remote_snapshot($template, $race_match['remote'], 'remote_duplicate_detected', $api_result);
                $template['meta_link_source'] = 'name_match_after_create';
            }
        }
        $template = eventosapp_whatsapp_templates_append_meta_history($template, $operation . '_failed', $api_result, $before);
    }

    $template['updated_at'] = current_time('mysql');
    $settings['templates'][$template_id] = $template;
    eventosapp_whatsapp_templates_update_settings($settings);

    return $api_result;
}

/**
 * Consulta estado de una plantilla en Meta.
 */
function eventosapp_whatsapp_templates_check_status($template_id) {
    $template_id = sanitize_key((string) $template_id);
    $settings = eventosapp_whatsapp_templates_get_settings();

    if ( empty($settings['templates'][$template_id]) || ! is_array($settings['templates'][$template_id]) ) {
        return [
            'ok' => false,
            'message' => 'Plantilla local no encontrada.',
            'error_type' => 'not_found',
            'notice_level' => 'error',
        ];
    }

    $template = $settings['templates'][$template_id];
    $before = $template;
    if ( empty($template['meta_template_id']) ) {
        return eventosapp_whatsapp_templates_sync_template_by_name($template_id);
    }

    $api_result = eventosapp_whatsapp_templates_get_remote_template_by_id($template['meta_template_id']);
    if ( ! empty($api_result['ok']) && is_array($api_result['response'] ?? null) ) {
        $template = eventosapp_whatsapp_templates_apply_remote_snapshot($template, $api_result['response'], 'status_check', $api_result);
        $template['waba_id'] = eventosapp_whatsapp_templates_get_template_waba_id($template, $settings);
        $template['last_api_message'] = sanitize_text_field(trim((string)($api_result['message'] ?? '') . ' ' . eventosapp_whatsapp_templates_category_status_message($template['category'] ?? 'UTILITY', $template['meta_category'] ?? '')));
        $settings['templates'][$template_id] = $template;
        eventosapp_whatsapp_templates_update_settings($settings);
        $api_result['message'] = $template['last_api_message'];
        return $api_result;
    }

    if ( ($api_result['error_type'] ?? '') === 'not_found' ) {
        $old_id = sanitize_text_field((string)($template['meta_template_id'] ?? ''));
        $template['meta_template_id'] = '';
        $template['meta_status'] = 'LOCAL';
        $template['meta_category'] = '';
        $template['meta_rejected_reason'] = '';
        $template['meta_link_source'] = '';
        $api_result['notice_level'] = 'warning';
        $api_result['message'] = 'El ID remoto ' . $old_id . ' ya no pudo encontrarse. Se eliminó el vínculo obsoleto del registro local. Puedes volver a enviarlo con el mismo nombre después de verificar que no exista en Meta.';
    }

    $template['last_checked_at'] = current_time('mysql');
    $template['last_api_message'] = sanitize_text_field((string)($api_result['message'] ?? 'No se pudo consultar el estado.'));
    $template['last_api_response'] = is_array($api_result['response'] ?? null) ? $api_result['response'] : [];
    $template = eventosapp_whatsapp_templates_append_meta_history($template, 'status_check_failed', $api_result, $before);
    $settings['templates'][$template_id] = $template;
    eventosapp_whatsapp_templates_update_settings($settings);

    return $api_result;
}

/**
 * Busca una plantilla por nombre/idioma si no hay ID remoto guardado.
 */
function eventosapp_whatsapp_templates_sync_template_by_name($template_id) {
    $template_id = sanitize_key((string) $template_id);
    $settings = eventosapp_whatsapp_templates_get_settings();

    if ( empty($settings['templates'][$template_id]) || ! is_array($settings['templates'][$template_id]) ) {
        return [
            'ok' => false,
            'message' => 'Plantilla local no encontrada.',
            'error_type' => 'not_found',
            'notice_level' => 'error',
        ];
    }

    $template = $settings['templates'][$template_id];
    $before = $template;
    $waba_id = eventosapp_whatsapp_templates_get_template_waba_id($template, $settings);
    if ( $waba_id === '' ) {
        return [
            'ok' => false,
            'message' => 'Configura el WhatsApp Business Account ID del número emisor de esta plantilla.',
            'error_type' => 'invalid_parameter',
            'notice_level' => 'error',
        ];
    }

    $match = eventosapp_whatsapp_templates_find_remote_template($waba_id, $template['name'] ?? '', $template['language'] ?? '');
    if ( empty($match['ok']) ) {
        $template['last_checked_at'] = current_time('mysql');
        $template['last_api_message'] = sanitize_text_field((string)($match['message'] ?? 'No se pudo consultar Meta por nombre.'));
        $template = eventosapp_whatsapp_templates_append_meta_history($template, 'name_sync_failed', $match, $before);
        $settings['templates'][$template_id] = $template;
        eventosapp_whatsapp_templates_update_settings($settings);
        return $match;
    }

    if ( empty($match['found']) || ! is_array($match['remote'] ?? null) ) {
        $result = [
            'ok' => false,
            'operation' => 'status',
            'notice_level' => 'warning',
            'error_type' => 'not_found',
            'http_code' => 0,
            'message' => 'No se encontró en Meta una plantilla con este nombre técnico, idioma y WABA. El registro local continúa sin vínculo remoto.',
            'response' => [],
        ];
        $template['last_checked_at'] = current_time('mysql');
        $template['last_api_message'] = $result['message'];
        $template = eventosapp_whatsapp_templates_append_meta_history($template, 'name_sync_not_found', $result, $before);
        $settings['templates'][$template_id] = $template;
        eventosapp_whatsapp_templates_update_settings($settings);
        return $result;
    }

    if ( ! empty($match['ambiguous']) ) {
        $result = [
            'ok' => false,
            'operation' => 'status',
            'notice_level' => 'warning',
            'error_type' => 'ambiguous_remote_match',
            'http_code' => 0,
            'message' => 'Meta devolvió más de una coincidencia con el mismo nombre e idioma. No se vinculó automáticamente para evitar asociar el registro incorrecto.',
            'response' => $match['matches'],
        ];
        $template['last_api_message'] = $result['message'];
        $template = eventosapp_whatsapp_templates_append_meta_history($template, 'name_sync_ambiguous', $result, $before);
        $settings['templates'][$template_id] = $template;
        eventosapp_whatsapp_templates_update_settings($settings);
        return $result;
    }

    $remote = $match['remote'];
    $result = [
        'ok' => true,
        'operation' => 'status',
        'notice_level' => 'success',
        'http_code' => 200,
        'message' => 'Plantilla vinculada y sincronizada desde Meta por nombre técnico, idioma y WABA.',
        'meta_id' => sanitize_text_field((string)($remote['id'] ?? '')),
        'meta_status' => eventosapp_whatsapp_templates_normalize_meta_status($remote['status'] ?? 'UNKNOWN'),
        'meta_category' => eventosapp_whatsapp_templates_normalize_meta_category($remote['category'] ?? ''),
        'response' => $remote,
    ];
    $template = eventosapp_whatsapp_templates_apply_remote_snapshot($template, $remote, 'name_sync', $result);
    $template['meta_link_source'] = 'name_sync';
    $template['waba_id'] = $waba_id;
    $template['last_api_message'] = sanitize_text_field($result['message'] . ' ' . eventosapp_whatsapp_templates_category_status_message($template['category'] ?? 'UTILITY', $template['meta_category'] ?? ''));
    $settings['templates'][$template_id] = $template;
    eventosapp_whatsapp_templates_update_settings($settings);
    $result['message'] = $template['last_api_message'];

    return $result;
}

/**
 * Sincroniza estados de plantillas locales con Meta.
 */
function eventosapp_whatsapp_templates_sync_all() {
    $settings = eventosapp_whatsapp_templates_get_settings();
    $templates = isset($settings['templates']) && is_array($settings['templates']) ? $settings['templates'] : [];
    $waba_ids = [];

    $default_waba_id = eventosapp_whatsapp_templates_sanitize_waba_id($settings['waba_id'] ?? '');
    if ( $default_waba_id !== '' ) $waba_ids[$default_waba_id] = $default_waba_id;
    foreach ( $templates as $template ) {
        if ( ! is_array($template) ) continue;
        $template_waba_id = eventosapp_whatsapp_templates_get_template_waba_id($template, $settings);
        if ( $template_waba_id !== '' ) $waba_ids[$template_waba_id] = $template_waba_id;
    }

    if ( empty($waba_ids) ) {
        return [
            'ok' => false,
            'message' => 'Configura al menos un WhatsApp Business Account ID para sincronizar plantillas.',
            'notice_level' => 'error',
        ];
    }

    $remote_by_waba = [];
    $query_errors = [];
    foreach ( $waba_ids as $waba_id ) {
        $list = eventosapp_whatsapp_templates_fetch_remote_templates($waba_id);
        if ( empty($list['ok']) ) {
            $query_errors[$waba_id] = $list;
            continue;
        }
        $by_id = [];
        $by_name_language = [];
        foreach ( $list['data'] as $remote ) {
            if ( ! is_array($remote) ) continue;
            $remote_id = sanitize_text_field((string)($remote['id'] ?? ''));
            if ( $remote_id !== '' ) $by_id[$remote_id] = $remote;
            $key = eventosapp_whatsapp_templates_sanitize_template_name($remote['name'] ?? '') . '|' . sanitize_text_field((string)($remote['language'] ?? ''));
            if ( $key !== '|' ) {
                if ( ! isset($by_name_language[$key]) ) $by_name_language[$key] = [];
                $by_name_language[$key][] = $remote;
            }
        }
        $remote_by_waba[$waba_id] = [
            'by_id' => $by_id,
            'by_name_language' => $by_name_language,
            'count' => count($list['data']),
        ];
    }

    if ( empty($remote_by_waba) ) {
        $first_error = reset($query_errors);
        return [
            'ok' => false,
            'message' => is_array($first_error) ? ($first_error['message'] ?? 'No se pudieron consultar plantillas en los WABA configurados.') : 'No se pudieron consultar plantillas en los WABA configurados.',
            'response' => $query_errors,
            'notice_level' => 'error',
        ];
    }

    $counts = [
        'updated' => 0,
        'unchanged' => 0,
        'missing' => 0,
        'ambiguous' => 0,
        'recategorized' => 0,
        'waba_errors' => count($query_errors),
    ];

    foreach ( $templates as $local_id => $template ) {
        if ( ! is_array($template) ) continue;
        $template_waba_id = eventosapp_whatsapp_templates_get_template_waba_id($template, $settings);
        if ( $template_waba_id === '' || empty($remote_by_waba[$template_waba_id]) ) {
            $counts['missing']++;
            continue;
        }

        $before = $template;
        $remote = null;
        $meta_id = sanitize_text_field((string)($template['meta_template_id'] ?? ''));
        if ( $meta_id !== '' && isset($remote_by_waba[$template_waba_id]['by_id'][$meta_id]) ) {
            $remote = $remote_by_waba[$template_waba_id]['by_id'][$meta_id];
        } else {
            $key = eventosapp_whatsapp_templates_sanitize_template_name($template['name'] ?? '') . '|' . sanitize_text_field((string)($template['language'] ?? ''));
            $matches = $remote_by_waba[$template_waba_id]['by_name_language'][$key] ?? [];
            if ( count($matches) === 1 ) {
                $remote = $matches[0];
            } elseif ( count($matches) > 1 ) {
                $counts['ambiguous']++;
                $result = [
                    'ok' => false,
                    'operation' => 'status',
                    'notice_level' => 'warning',
                    'error_type' => 'ambiguous_remote_match',
                    'message' => 'La sincronización encontró varias coincidencias remotas con el mismo nombre e idioma. No se cambió el vínculo local.',
                    'http_code' => 0,
                ];
                $template['last_api_message'] = $result['message'];
                $template = eventosapp_whatsapp_templates_append_meta_history($template, 'sync_ambiguous', $result, $before);
                $settings['templates'][$local_id] = $template;
                continue;
            }
        }

        if ( ! is_array($remote) ) {
            $counts['missing']++;
            $result = [
                'ok' => false,
                'operation' => 'status',
                'notice_level' => 'warning',
                'error_type' => 'not_found',
                'message' => 'La sincronización no encontró coincidencia en Meta por ID ni por nombre e idioma.',
                'http_code' => 0,
            ];
            $template['last_api_message'] = $result['message'];
            $template['last_checked_at'] = current_time('mysql');
            $template = eventosapp_whatsapp_templates_append_meta_history($template, 'sync_not_found', $result, $before);
            $settings['templates'][$local_id] = $template;
            continue;
        }

        $result = [
            'ok' => true,
            'operation' => 'status',
            'notice_level' => 'success',
            'http_code' => 200,
            'message' => 'Estado sincronizado desde Meta.',
            'meta_id' => sanitize_text_field((string)($remote['id'] ?? '')),
            'meta_status' => eventosapp_whatsapp_templates_normalize_meta_status($remote['status'] ?? 'UNKNOWN'),
            'meta_category' => eventosapp_whatsapp_templates_normalize_meta_category($remote['category'] ?? ''),
            'response' => $remote,
        ];
        $template = eventosapp_whatsapp_templates_apply_remote_snapshot($template, $remote, 'sync_all', $result);
        $template['meta_link_source'] = $meta_id !== '' ? ($template['meta_link_source'] ?? 'id_sync') : 'name_sync';
        $template['waba_id'] = $template_waba_id;
        $template['last_api_message'] = sanitize_text_field('Estado sincronizado desde Meta. ' . eventosapp_whatsapp_templates_category_status_message($template['category'] ?? 'UTILITY', $template['meta_category'] ?? ''));

        if ( eventosapp_whatsapp_templates_category_mismatch($template) ) $counts['recategorized']++;
        $before_signature = maybe_serialize([
            $before['meta_template_id'] ?? '',
            $before['meta_status'] ?? '',
            $before['meta_category'] ?? '',
            eventosapp_whatsapp_templates_normalize_rejected_reason($before['meta_rejected_reason'] ?? ''),
        ]);
        $after_signature = maybe_serialize([
            $template['meta_template_id'] ?? '',
            $template['meta_status'] ?? '',
            $template['meta_category'] ?? '',
            $template['meta_rejected_reason'] ?? '',
        ]);
        if ( $before_signature === $after_signature ) $counts['unchanged']++;
        else $counts['updated']++;
        $settings['templates'][$local_id] = $template;
    }

    $settings['last_sync_at'] = current_time('mysql');
    $settings['last_message'] = sprintf(
        'Actualizadas: %d. Sin cambios: %d. Sin coincidencia: %d. Ambiguas: %d. Recategorizadas por Meta: %d. WABA con error: %d.',
        $counts['updated'],
        $counts['unchanged'],
        $counts['missing'],
        $counts['ambiguous'],
        $counts['recategorized'],
        $counts['waba_errors']
    );
    eventosapp_whatsapp_templates_update_settings($settings);

    $has_success = ($counts['updated'] + $counts['unchanged']) > 0;
    $has_warnings = $counts['missing'] > 0 || $counts['ambiguous'] > 0 || $counts['waba_errors'] > 0;
    return [
        'ok' => $has_success,
        'partial' => $has_warnings,
        'notice_level' => $has_warnings ? 'warning' : 'success',
        'message' => 'Sincronización terminada. ' . $settings['last_message'],
        'counts' => $counts,
        'response' => $query_errors,
    ];
}
/**
 * Render principal del módulo.
 */
/**
 * ============================================================
 * CENTRO UNIFICADO DE PLANTILLAS WHATSAPP
 * ============================================================
 *
 * La UI vive únicamente en eventosapp_whatsapp_templates. Los módulos de
 * confirmación, doble autenticación y Flow se conservan cargados como capas
 * internas para mantener compatibilidad con envíos, campañas y registros ya
 * existentes.
 */

add_action('admin_menu', function() {
    remove_submenu_page('eventosapp_dashboard', 'eventosapp_whatsapp_flow_templates');
    remove_submenu_page('eventosapp_dashboard', 'eventosapp_attendance_confirmation_template');
}, 999);

add_action('admin_init', function() {
    if ( ! is_admin() || ! current_user_can('manage_options') ) return;
    $page = isset($_GET['page']) ? sanitize_key((string)wp_unslash($_GET['page'])) : '';
    if ( ! in_array($page, ['eventosapp_whatsapp_flow_templates', 'eventosapp_attendance_confirmation_template'], true) ) return;

    $args = ['page' => 'eventosapp_whatsapp_templates'];
    if ( $page === 'eventosapp_whatsapp_flow_templates' ) {
        $args['view'] = 'edit';
        $args['engine'] = 'flow';
        $template_id = isset($_GET['template_id']) ? sanitize_key((string)wp_unslash($_GET['template_id'])) : '';
        if ( $template_id !== '' ) $args['template_id'] = $template_id;
        else $args['builder_type'] = 'flow';
    } else {
        $args['view'] = 'edit';
        $args['engine'] = 'standard';
        $args['template_id'] = isset($_GET['template_id']) ? sanitize_key((string)wp_unslash($_GET['template_id'])) : 'attendance_confirmation';
    }

    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}, 4);

/**
 * Algunas versiones anteriores del módulo especializado de Confirmación
 * imprimen un aviso y reescriben enlaces hacia su editor antiguo mediante un
 * callback anónimo. Como ese archivo se mantiene cargado por compatibilidad,
 * esta corrección tardía conserva una única experiencia visible sin tocar su
 * motor de envío ni su almacenamiento.
 */
/**
 * Compatibilidad preventiva con Doble Autenticación.
 *
 * El módulo histórico acepta cualquier plantilla Utility compatible con sus
 * variables. Con el inventario unificado aparecen nuevas familias de plantillas
 * que también podrían pasar esa comprobación por casualidad. Definir el helper
 * aquí (el archivo de Doble Autenticación lo declara solo si no existe) conserva
 * la lógica anterior y excluye únicamente las nuevas familias especializadas.
 */
if ( ! function_exists('eventosapp_double_auth_template_is_compatible') ) {
    function eventosapp_double_auth_template_is_compatible($template, $sender_id = '') {
        if ( ! is_array($template) || ! empty($template['archived']) ) return false;

        $builder_type = sanitize_key((string)($template['builder_type'] ?? ''));
        $base_key = sanitize_key((string)($template['base_key'] ?? ''));
        $local_category = eventosapp_whatsapp_templates_sanitize_category($template['category'] ?? 'UTILITY');
        $remote_category = eventosapp_whatsapp_templates_normalize_meta_category($template['meta_category'] ?? '');
        $effective_category = $remote_category !== '' ? $remote_category : $local_category;

        if ( ! empty($template['attendance_confirmation']) ) return false;
        if ( in_array($builder_type, ['marketing', 'attendance_confirmation', 'utility_custom', 'flow'], true) ) return false;
        if ( in_array($base_key, ['marketing', 'attendance_confirmation', 'utility_custom', 'flow'], true) ) return false;

        $approved = function_exists('eventosapp_whatsapp_is_template_approved')
            ? eventosapp_whatsapp_is_template_approved($template)
            : in_array(strtoupper((string)($template['meta_status'] ?? '')), ['APPROVED', 'ACTIVE'], true);
        if ( ! $approved ) return false;

        if ( $sender_id && function_exists('eventosapp_whatsapp_template_matches_sender') && ! eventosapp_whatsapp_template_matches_sender($template, $sender_id, true) ) {
            return false;
        }

        $header_format = strtoupper(sanitize_key((string)($template['header_format'] ?? 'NONE')));
        if ( in_array($header_format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) ) return false;

        // Nuevo formato oficial: Authentication + OTP. No depende del BODY libre
        // ni de {{2}}..{{6}} porque Meta genera el texto y solo recibe el código.
        if ( $effective_category === 'AUTHENTICATION' ) {
            return eventosapp_whatsapp_templates_is_double_auth_template($template)
                || $builder_type === 'double_auth_code'
                || $base_key === 'double_auth_code';
        }

        // Compatibilidad hacia atrás: una Utility histórica ya aprobada puede
        // seguir utilizándose para no interrumpir eventos que ya estaban activos.
        if ( $effective_category !== 'UTILITY' ) return false;

        if ( function_exists('eventosapp_whatsapp_get_runtime_body_variable_numbers') ) {
            $variables = eventosapp_whatsapp_get_runtime_body_variable_numbers($template);
        } else {
            preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string)($template['body_text'] ?? ''), $matches);
            $variables = ! empty($matches[1]) ? array_map('absint', $matches[1]) : [];
        }

        $variables = array_values(array_unique(array_map('absint', (array)$variables)));
        if ( ! in_array(1, $variables, true) || array_diff($variables, [1, 2, 3, 4, 5, 6]) ) return false;

        $header_text = (string)($template['header_text_meta'] ?? $template['header_text'] ?? '');
        if ( preg_match('/\{\{\s*\d+\s*\}\}/', $header_text) ) return false;

        foreach ( range(1, 10) as $button_number ) {
            if ( strpos((string)($template['button_' . $button_number . '_url'] ?? ''), '{{') !== false ) return false;
        }

        return true;
    }
}

/**
 * Actualiza la ayuda visual del metabox histórico de Doble Autenticación.
 *
 * El archivo del metabox se conserva sin cambios para minimizar superficie de
 * riesgo. Su texto original describe la antigua Utility {{1}}..{{6}}; cuando
 * se edita un evento, esta mejora aclara que el formato recomendado actual es
 * Authentication OTP y que la Utility anterior solo queda como compatibilidad.
 */
add_action('admin_footer', function() {
    if ( ! is_admin() ) return;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'eventosapp_event' || $screen->base !== 'post' ) return;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        var varsBox = document.querySelector('.evapp-da-delivery .evapp-da-vars');
        if ( varsBox ) {
            varsBox.innerHTML = '<strong>Authentication OTP (recomendado por Meta):</strong><br>' +
                '<code>{{1}}</code> Código OTP · botón nativo “Copiar código”.<br>' +
                '<span style="display:block;margin-top:5px;color:#646970">Meta genera el BODY de Authentication. Las plantillas Utility antiguas ya aprobadas con {{1}}…{{6}} siguen siendo compatibles únicamente para no interrumpir eventos existentes.</span>';
        }
        var select = document.getElementById('evapp-da-whatsapp-template');
        if ( select ) {
            var help = select.nextElementSibling;
            if ( help && help.classList.contains('evapp-da-help') ) {
                help.textContent = 'Se priorizan plantillas Authentication OTP aprobadas y compatibles con el número emisor. Las Utility antiguas aprobadas se mantienen como compatibilidad.';
            }
        }
    });
    </script>
    <?php
}, 998);

add_action('admin_footer', function() {
    $page = isset($_GET['page']) ? sanitize_key((string)wp_unslash($_GET['page'])) : '';
    if ( $page !== 'eventosapp_whatsapp_templates' ) return;

    $attendance_url = add_query_arg([
        'page' => 'eventosapp_whatsapp_templates',
        'view' => 'edit',
        'engine' => 'standard',
        'template_id' => 'attendance_confirmation',
    ], admin_url('admin.php'));
    ?>
    <script>
    jQuery(function($){
        const unifiedAttendanceUrl = <?php echo wp_json_encode($attendance_url); ?>;
        $('.notice').filter(function(){
            const $notice=$(this);
            return $notice.text().indexOf('Confirmación de asistencia:')!==-1
                && $notice.find('a[href*="eventosapp_attendance_confirmation_template"]').length>0;
        }).remove();
        $('a[href*="eventosapp_attendance_confirmation_template"],a[href*="template_id=attendance_confirmation"]').attr('href',unifiedAttendanceUrl);
    });
    </script>
    <?php
}, 999);

function eventosapp_whatsapp_templates_builder_presets() {
    return [
        'ticket_presencial' => [
            'label' => 'Enviar Ticket Presencial',
            'short' => 'Ticket presencial',
            'engine' => 'standard',
            'category' => 'UTILITY',
            'description' => 'Ticket físico con QR, landing del ticket y agenda.',
            'icon' => '🎟️',
        ],
        'ticket_virtual' => [
            'label' => 'Enviar Ticket Virtual',
            'short' => 'Ticket virtual',
            'engine' => 'standard',
            'category' => 'UTILITY',
            'description' => 'Acceso virtual, información de plataforma y agenda.',
            'icon' => '💻',
        ],
        'attendance_confirmation' => [
            'label' => 'Enviar Confirmación de Asistencia',
            'short' => 'Confirmación',
            'engine' => 'standard',
            'category' => 'UTILITY',
            'description' => 'Confirmación Sí / No con QUICK_REPLY y trazabilidad de respuesta.',
            'icon' => '✅',
        ],
        'double_auth_code' => [
            'label' => 'Enviar Código de Doble Autenticación',
            'short' => 'Doble autenticación',
            'engine' => 'standard',
            'category' => 'AUTHENTICATION',
            'description' => 'Código OTP con tipología Authentication y botón nativo para copiar el código.',
            'icon' => '🔐',
        ],
        'flow' => [
            'label' => 'Plantilla para WhatsApp Flow',
            'short' => 'WhatsApp Flow',
            'engine' => 'flow',
            'category' => 'UTILITY',
            'description' => 'Abre un Flow publicado desde un botón nativo de WhatsApp.',
            'icon' => '🧩',
        ],
        'marketing' => [
            'label' => 'Plantilla de Marketing',
            'short' => 'Marketing',
            'engine' => 'standard',
            'category' => 'MARKETING',
            'description' => 'Builder flexible para campañas, invitaciones, novedades y contenido promocional.',
            'icon' => '📣',
        ],
        'utility_custom' => [
            'label' => 'Utility Personalizada',
            'short' => 'Utility personalizada',
            'engine' => 'standard',
            'category' => 'UTILITY',
            'description' => 'Mensaje operativo Utility sin depender de un flujo predeterminado.',
            'icon' => '⚙️',
        ],
    ];
}

function eventosapp_whatsapp_templates_builder_type_label($type) {
    $type = sanitize_key((string)$type);
    $presets = eventosapp_whatsapp_templates_builder_presets();
    return $presets[$type]['short'] ?? 'Personalizada';
}

function eventosapp_whatsapp_templates_builder_type_for_template($template, $engine = 'standard') {
    $template = is_array($template) ? $template : [];
    if ( $engine === 'flow' ) return 'flow';

    $saved = sanitize_key((string)($template['builder_type'] ?? ''));
    if ( isset(eventosapp_whatsapp_templates_builder_presets()[$saved]) && $saved !== 'flow' ) return $saved;
    if ( ! empty($template['attendance_confirmation']) || sanitize_key((string)($template['base_key'] ?? '')) === 'attendance_confirmation' ) return 'attendance_confirmation';
    if ( ! empty($template['double_auth_code']) || sanitize_key((string)($template['base_key'] ?? '')) === 'double_auth_code' ) return 'double_auth_code';
    if ( sanitize_key((string)($template['modality'] ?? '')) === 'presencial' ) return 'ticket_presencial';
    if ( sanitize_key((string)($template['modality'] ?? '')) === 'virtual' ) return 'ticket_virtual';
    if ( eventosapp_whatsapp_templates_sanitize_category($template['category'] ?? 'UTILITY') === 'MARKETING' ) return 'marketing';
    return 'utility_custom';
}

function eventosapp_whatsapp_templates_builder_new_standard_template($type) {
    $type = sanitize_key((string)$type);
    $defaults = eventosapp_whatsapp_templates_default_records();
    $now = current_time('mysql');

    if ( $type === 'ticket_virtual' ) {
        $template = $defaults['default_virtual'];
    } elseif ( $type === 'attendance_confirmation' && function_exists('eventosapp_attendance_confirmation_whatsapp_template_defaults') ) {
        $template = eventosapp_attendance_confirmation_whatsapp_template_defaults();
    } elseif ( $type === 'double_auth_code' && function_exists('eventosapp_double_auth_whatsapp_template_defaults') ) {
        $template = eventosapp_double_auth_whatsapp_template_defaults();
    } elseif ( $type === 'marketing' ) {
        $template = [
            'id' => '', 'is_default' => '0', 'base_key' => 'marketing',
            'name' => 'eventosapp_marketing_' . wp_date('Ymd_His'), 'language' => 'es',
            'category' => 'MARKETING', 'modality' => 'custom', 'title' => 'Nueva plantilla de Marketing',
            'header_format' => 'NONE', 'header_text' => '', 'header_sample_handle' => '',
            'body_text' => "Hola {{1}},\n\nEscribe aquí el contenido de tu campaña.",
            'body_examples' => "María Pérez", 'footer_text' => 'EventosApp',
            'button_mode' => 'none', 'button_count' => '0',
            'button_1_text' => '', 'button_1_url' => '', 'button_1_example' => '',
            'button_2_text' => '', 'button_2_url' => '', 'button_2_example' => '',
        ];
    } elseif ( $type === 'utility_custom' ) {
        $template = [
            'id' => '', 'is_default' => '0', 'base_key' => 'utility_custom',
            'name' => 'eventosapp_utility_' . wp_date('Ymd_His'), 'language' => 'es',
            'category' => 'UTILITY', 'modality' => 'custom', 'title' => 'Nueva plantilla Utility',
            'header_format' => 'NONE', 'header_text' => '', 'header_sample_handle' => '',
            'body_text' => "Hola {{1}},\n\nEscribe aquí la actualización operativa relacionada con tu registro o evento.",
            'body_examples' => "María Pérez", 'footer_text' => 'EventosApp',
            'button_mode' => 'none', 'button_count' => '0',
            'button_1_text' => '', 'button_1_url' => '', 'button_1_example' => '',
            'button_2_text' => '', 'button_2_url' => '', 'button_2_example' => '',
        ];
    } else {
        $type = 'ticket_presencial';
        $template = $defaults['default_presencial'];
    }

    $template = wp_parse_args($template, [
        'sender_phone_number_id' => '', 'sender_phone_label' => 'Número por defecto', 'waba_id' => '',
        'meta_template_id' => '', 'meta_status' => 'LOCAL', 'meta_category' => '', 'meta_rejected_reason' => '',
        'last_api_message' => '', 'last_api_response' => [], 'last_meta_result' => [], 'meta_history' => [],
        'last_submitted_at' => '', 'last_checked_at' => '', 'created_at' => $now, 'updated_at' => $now,
    ]);

    $template['id'] = 'tpl_' . wp_generate_uuid4();
    $template['is_default'] = '0';
    $template['builder_type'] = $type;
    $template['archived'] = '0';
    $template['archived_at'] = '';
    $template['meta_template_id'] = '';
    $template['meta_status'] = 'LOCAL';
    $template['meta_category'] = '';
    $template['meta_rejected_reason'] = '';
    $template['last_api_message'] = '';
    $template['last_api_response'] = [];
    $template['last_meta_result'] = [];
    $template['meta_history'] = [];
    $template['last_submitted_at'] = '';
    $template['last_checked_at'] = '';
    $template['created_at'] = $now;
    $template['updated_at'] = $now;

    if ( $type === 'ticket_presencial' ) {
        $template['base_key'] = 'presencial';
        $template['name'] = 'eventosapp_ticket_presencial_' . wp_date('Ymd_His');
        $template['title'] = 'Nuevo Ticket Presencial';
    } elseif ( $type === 'ticket_virtual' ) {
        $template['base_key'] = 'virtual';
        $template['name'] = 'eventosapp_ticket_virtual_' . wp_date('Ymd_His');
        $template['title'] = 'Nuevo Ticket Virtual';
    } elseif ( $type === 'attendance_confirmation' ) {
        $template['attendance_confirmation'] = '1';
        $template['base_key'] = 'attendance_confirmation';
        $template['name'] = 'eventosapp_confirmacion_asistencia_' . wp_date('Ymd_His');
        $template['title'] = 'Nueva Confirmación de Asistencia';
        $template['button_mode'] = 'quick_reply';
        $template['button_count'] = '2';
    } elseif ( $type === 'double_auth_code' ) {
        $template['double_auth_code'] = '1';
        $template['base_key'] = 'double_auth_code';
        $template['name'] = 'eventosapp_codigo_doble_auth_' . wp_date('Ymd_His');
        $template['title'] = 'Nuevo Código de Doble Autenticación';
        $template['button_mode'] = 'none';
        $template['button_count'] = '0';
        $template = eventosapp_whatsapp_templates_normalize_double_auth_body_for_meta($template);
    }

    return $template;
}

function eventosapp_whatsapp_templates_builder_variable_help($type) {
    $type = sanitize_key((string)$type);
    if ( $type === 'attendance_confirmation' ) {
        return [1=>'Nombre', 2=>'Evento', 3=>'Fecha', 4=>'Hora', 5=>'Lugar'];
    }
    if ( $type === 'double_auth_code' ) {
        return [1=>'Código OTP'];
    }
    if ( in_array($type, ['ticket_presencial', 'ticket_virtual'], true) ) {
        return [1=>'Nombre', 2=>'Evento', 3=>'Fecha', 4=>'Hora', 5=>'Lugar / plataforma', 6=>'Enlace del ticket', 7=>'Organizador', 8=>'Modalidad'];
    }
    return [1=>'Variable 1', 2=>'Variable 2', 3=>'Variable 3', 4=>'Variable 4', 5=>'Variable 5', 6=>'Variable 6', 7=>'Variable 7', 8=>'Variable 8'];
}

function eventosapp_whatsapp_templates_unified_flow_status($status) {
    $status = strtoupper(str_replace('-', '_', sanitize_key((string)$status)));
    $aliases = ['LOCAL_DRAFT'=>'LOCAL','SUBMITTED'=>'PENDING','META_ERROR'=>'REJECTED','IN_REVIEW'=>'PENDING'];
    return $aliases[$status] ?? ($status !== '' ? $status : 'LOCAL');
}

function eventosapp_whatsapp_templates_unified_inventory() {
    $settings = eventosapp_whatsapp_templates_get_settings();
    $rows = [];
    foreach ( (array)($settings['templates'] ?? []) as $id => $template ) {
        if ( ! is_array($template) ) continue;
        $id = sanitize_key((string)$id);
        $rows[] = [
            'engine' => 'standard',
            'id' => $id,
            'title' => sanitize_text_field((string)($template['title'] ?? $template['name'] ?? $id)),
            'name' => sanitize_key((string)($template['name'] ?? $id)),
            'builder_type' => eventosapp_whatsapp_templates_builder_type_for_template($template, 'standard'),
            'category' => eventosapp_whatsapp_templates_sanitize_category($template['category'] ?? 'UTILITY'),
            'language' => sanitize_text_field((string)($template['language'] ?? 'es')),
            'status' => eventosapp_whatsapp_templates_normalize_meta_status($template['meta_status'] ?? 'LOCAL'),
            'meta_category' => eventosapp_whatsapp_templates_normalize_meta_category($template['meta_category'] ?? ''),
            'archived' => ! empty($template['archived']),
            'updated_at' => sanitize_text_field((string)($template['updated_at'] ?? $template['created_at'] ?? '')),
            'sender_label' => eventosapp_whatsapp_templates_get_template_sender_label($template, $settings),
            'is_default' => ! empty($template['is_default']),
            'raw' => $template,
        ];
    }

    if ( function_exists('eventosapp_whatsapp_flow_templates_get_all') ) {
        foreach ( (array)eventosapp_whatsapp_flow_templates_get_all() as $id => $template ) {
            if ( ! is_array($template) ) continue;
            $id = sanitize_key((string)$id);
            $sender = function_exists('eventosapp_whatsapp_flow_templates_resolve_sender_account')
                ? eventosapp_whatsapp_flow_templates_resolve_sender_account($template['sender_phone_number_id'] ?? '')
                : [];
            $rows[] = [
                'engine' => 'flow',
                'id' => $id,
                'title' => sanitize_text_field((string)($template['display_name'] ?? $template['name'] ?? $id)),
                'name' => sanitize_key((string)($template['name'] ?? $id)),
                'builder_type' => 'flow',
                'category' => strtoupper(sanitize_key((string)($template['category'] ?? 'UTILITY'))),
                'language' => sanitize_text_field((string)($template['language'] ?? 'es_CO')),
                'status' => eventosapp_whatsapp_templates_unified_flow_status($template['meta_status'] ?? 'local_draft'),
                'meta_category' => strtoupper(sanitize_key((string)($template['meta_category'] ?? ''))),
                'archived' => ! empty($template['archived']),
                'updated_at' => sanitize_text_field((string)($template['updated_at'] ?? $template['created_at'] ?? '')),
                'sender_label' => sanitize_text_field((string)($sender['label'] ?? 'Número por defecto')),
                'is_default' => false,
                'raw' => $template,
            ];
        }
    }

    usort($rows, static function($a, $b) {
        $a_time = strtotime((string)($a['updated_at'] ?? '')) ?: 0;
        $b_time = strtotime((string)($b['updated_at'] ?? '')) ?: 0;
        return $b_time <=> $a_time;
    });
    return $rows;
}

function eventosapp_whatsapp_templates_unified_admin_action_form($action, $nonce_action, $row, $label, $class = 'button') {
    $row = is_array($row) ? $row : [];
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="evapp-utpl-inline-form">';
    wp_nonce_field($nonce_action);
    echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
    echo '<input type="hidden" name="template_id" value="' . esc_attr($row['id'] ?? '') . '">';
    echo '<input type="hidden" name="engine" value="' . esc_attr($row['engine'] ?? 'standard') . '">';
    echo '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
}


function eventosapp_whatsapp_templates_render_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes para acceder a esta página.');
    }
    if ( function_exists('wp_enqueue_media') ) wp_enqueue_media();

    $settings = eventosapp_whatsapp_templates_get_settings();
    $view = isset($_GET['view']) ? sanitize_key((string)wp_unslash($_GET['view'])) : 'list';
    $engine = isset($_GET['engine']) ? sanitize_key((string)wp_unslash($_GET['engine'])) : 'standard';
    $template_id = isset($_GET['template_id']) ? sanitize_key((string)wp_unslash($_GET['template_id'])) : '';
    $builder_type = isset($_GET['builder_type']) ? sanitize_key((string)wp_unslash($_GET['builder_type'])) : '';

    $notice = isset($_GET['evapp_wa_tpl_msg']) ? sanitize_text_field((string)wp_unslash($_GET['evapp_wa_tpl_msg'])) : '';
    $notice_ok = isset($_GET['evapp_wa_tpl_ok']) && (string)$_GET['evapp_wa_tpl_ok'] === '1';
    $notice_level = isset($_GET['evapp_wa_tpl_level']) ? sanitize_key((string)wp_unslash($_GET['evapp_wa_tpl_level'])) : ($notice_ok ? 'success' : 'error');
    if ( ! in_array($notice_level, ['success','warning','error','info'], true) ) $notice_level = $notice_ok ? 'success' : 'error';

    $presets = eventosapp_whatsapp_templates_builder_presets();
    ?>
    <div class="wrap eventosapp-wa-templates evapp-utpl-app">
        <style>
            .evapp-utpl-app{--evp:#3279bd;--evp-dark:#245d93;--evp-soft:#eaf4ff;--ev-bg:#f5f8fc;--ev-card:#fff;--ev-border:#dfe7f1;--ev-text:#182230;--ev-muted:#667085;--ev-success:#16855b;--ev-warn:#a16207;--ev-danger:#b42318;margin:0 0 0 -20px;padding:0 24px 42px;background:var(--ev-bg);min-height:calc(100vh - 32px);color:var(--ev-text);box-sizing:border-box}
            .evapp-utpl-app *{box-sizing:border-box}
            .evapp-utpl-hero{margin:0 -24px 22px;padding:28px 28px 24px;background:linear-gradient(135deg,#fff 0%,#eef6ff 100%);border-bottom:1px solid var(--ev-border)}
            .evapp-utpl-hero-top{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;max-width:1500px;margin:auto}
            .evapp-utpl-kicker{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--evp-dark);font-weight:800;margin-bottom:6px}
            .evapp-utpl-hero h1{margin:0;font-size:30px;line-height:1.15;color:var(--ev-text)}
            .evapp-utpl-hero p{margin:8px 0 0;max-width:860px;color:var(--ev-muted);font-size:14px;line-height:1.55}
            .evapp-utpl-hero-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
            .evapp-utpl-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:38px;padding:8px 13px;border:1px solid #b9c9da;border-radius:9px;background:#fff;color:#24415e;text-decoration:none;font-weight:700;cursor:pointer}
            .evapp-utpl-btn:hover{border-color:var(--evp);color:var(--evp-dark)}
            .evapp-utpl-btn.primary{background:var(--evp);border-color:var(--evp);color:#fff}.evapp-utpl-btn.primary:hover{background:var(--evp-dark);color:#fff}
            .evapp-utpl-shell{max-width:1500px;margin:auto}.evapp-utpl-notice{padding:12px 14px;border-radius:10px;margin:0 0 16px;background:#fff;border:1px solid var(--ev-border);border-left:4px solid var(--evp)}
            .evapp-utpl-notice.success{border-left-color:var(--ev-success)}.evapp-utpl-notice.warning{border-left-color:var(--ev-warn)}.evapp-utpl-notice.error{border-left-color:var(--ev-danger)}
            .evapp-utpl-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}
            .evapp-utpl-stat{background:#fff;border:1px solid var(--ev-border);border-radius:12px;padding:15px 16px;box-shadow:0 1px 2px rgba(16,24,40,.03)}.evapp-utpl-stat b{display:block;font-size:24px;line-height:1.1}.evapp-utpl-stat span{color:var(--ev-muted);font-size:12px}
            .evapp-utpl-card{background:#fff;border:1px solid var(--ev-border);border-radius:14px;box-shadow:0 1px 3px rgba(16,24,40,.04);overflow:hidden;margin-bottom:16px}.evapp-utpl-card-head{padding:16px 18px;border-bottom:1px solid #edf1f5;display:flex;justify-content:space-between;gap:12px;align-items:center}.evapp-utpl-card-head h2{margin:0;font-size:17px}.evapp-utpl-card-body{padding:18px}
            .evapp-utpl-presets{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.evapp-utpl-preset{display:block;text-decoration:none;color:inherit;border:1px solid var(--ev-border);border-radius:12px;padding:14px;background:#fff;transition:.16s ease}.evapp-utpl-preset:hover{border-color:#9fc2e3;box-shadow:0 4px 14px rgba(50,121,189,.10);transform:translateY(-1px)}.evapp-utpl-preset-icon{font-size:23px}.evapp-utpl-preset b{display:block;margin:8px 0 3px}.evapp-utpl-preset small{color:var(--ev-muted);line-height:1.35}.evapp-utpl-tag{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;font-size:11px;font-weight:800;background:#eef2f6;color:#475467}.evapp-utpl-tag.utility{background:#eaf4ff;color:#245d93}.evapp-utpl-tag.marketing{background:#fff4e5;color:#8a4b08}
            .evapp-utpl-toolbar{display:grid;grid-template-columns:minmax(260px,2fr) repeat(4,minmax(140px,1fr)) auto;gap:8px;align-items:center}.evapp-utpl-toolbar input,.evapp-utpl-toolbar select{width:100%;min-height:38px;border:1px solid #cfd9e5;border-radius:8px;padding:6px 9px;background:#fff}
            .evapp-utpl-table-wrap{overflow:auto}.evapp-utpl-table{width:100%;border-collapse:separate;border-spacing:0;min-width:1080px}.evapp-utpl-table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#667085;background:#f8fafc;padding:10px 12px;text-align:left;border-bottom:1px solid var(--ev-border);position:sticky;top:0;z-index:1}.evapp-utpl-table td{padding:12px;border-bottom:1px solid #edf1f5;vertical-align:top}.evapp-utpl-table tr:last-child td{border-bottom:0}.evapp-utpl-name{font-weight:800;color:#1d344b}.evapp-utpl-sub{font-size:12px;color:var(--ev-muted);margin-top:3px;line-height:1.4}.evapp-utpl-status{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:800;background:#eef2f6;color:#475467}.evapp-utpl-status.APPROVED,.evapp-utpl-status.ACTIVE{background:#e7f8ef;color:#11633f}.evapp-utpl-status.PENDING,.evapp-utpl-status.IN_APPEAL{background:#fff7dd;color:#8a5b00}.evapp-utpl-status.REJECTED,.evapp-utpl-status.PAUSED,.evapp-utpl-status.DISABLED{background:#feeceb;color:#9b1c1c}.evapp-utpl-actions{display:flex;gap:6px;flex-wrap:wrap;min-width:270px}.evapp-utpl-inline-form{display:inline;margin:0}.evapp-utpl-actions .button{margin:0}.evapp-utpl-archived{opacity:.66}.evapp-utpl-empty{padding:30px;text-align:center;color:var(--ev-muted)}
            .evapp-utpl-pager{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px 16px;border-top:1px solid var(--ev-border);background:#fbfcfd}.evapp-utpl-pager-links{display:flex;gap:6px}.evapp-utpl-pager a,.evapp-utpl-pager span.current{display:inline-flex;min-width:34px;height:34px;align-items:center;justify-content:center;border:1px solid #ccd8e4;border-radius:8px;text-decoration:none;background:#fff}.evapp-utpl-pager span.current{background:var(--evp);color:#fff;border-color:var(--evp)}
            .evapp-utpl-details summary{cursor:pointer;font-weight:800}.evapp-utpl-settings-grid{display:grid;grid-template-columns:220px minmax(0,720px);gap:12px 18px;margin-top:14px}.evapp-utpl-settings-grid label{font-weight:700;padding-top:7px}.evapp-utpl-settings-grid input{width:100%}.evapp-utpl-help{font-size:12px;color:var(--ev-muted);margin:5px 0 0;line-height:1.45}
            @media(max-width:1200px){.evapp-utpl-presets{grid-template-columns:repeat(3,minmax(0,1fr))}.evapp-utpl-toolbar{grid-template-columns:repeat(3,minmax(0,1fr))}.evapp-utpl-toolbar .evapp-utpl-search{grid-column:span 2}.evapp-utpl-stats{grid-template-columns:repeat(2,1fr)}}
            @media(max-width:782px){.evapp-utpl-app{margin-left:-10px;padding:0 12px 30px}.evapp-utpl-hero{margin:0 -12px 16px;padding:20px 14px}.evapp-utpl-hero-top{display:block}.evapp-utpl-hero-actions{justify-content:flex-start;margin-top:14px}.evapp-utpl-hero h1{font-size:25px}.evapp-utpl-presets{grid-template-columns:1fr}.evapp-utpl-toolbar{grid-template-columns:1fr}.evapp-utpl-toolbar .evapp-utpl-search{grid-column:auto}.evapp-utpl-stats{grid-template-columns:1fr 1fr}.evapp-utpl-settings-grid{grid-template-columns:1fr}.evapp-utpl-settings-grid label{padding-top:0}.evapp-utpl-card-body{padding:14px}}
        </style>

        <div class="evapp-utpl-hero">
            <div class="evapp-utpl-hero-top">
                <div>
                    <div class="evapp-utpl-kicker">EventosApp · WhatsApp Business Platform</div>
                    <h1>Plantillas WhatsApp</h1>
                    <p>Un único centro para construir, aprobar y administrar plantillas Utility, Marketing y plantillas que abren WhatsApp Flows, conservando los motores existentes de tickets, confirmación, doble autenticación y campañas.</p>
                </div>
                <div class="evapp-utpl-hero-actions">
                    <?php if ( $view === 'edit' ) : ?>
                        <a class="evapp-utpl-btn" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_templates')); ?>">← Volver al inventario</a>
                    <?php else : ?>
                        <a class="evapp-utpl-btn primary" href="#evapp-utpl-new">＋ Crear plantilla</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="evapp-utpl-shell">
            <?php if ( $notice !== '' ) : ?><div class="evapp-utpl-notice <?php echo esc_attr($notice_level); ?>"><strong>EventosApp:</strong> <?php echo esc_html($notice); ?></div><?php endif; ?>

            <?php if ( $view === 'edit' ) : ?>
                <?php eventosapp_whatsapp_templates_render_edit_form($template_id); ?>
            <?php else :
                $inventory = eventosapp_whatsapp_templates_unified_inventory();
                $total = count($inventory);
                $approved = count(array_filter($inventory, static fn($r) => in_array($r['status'], ['APPROVED','ACTIVE'], true) && empty($r['archived'])));
                $pending = count(array_filter($inventory, static fn($r) => in_array($r['status'], ['PENDING','IN_APPEAL'], true) && empty($r['archived'])));
                $archived_count = count(array_filter($inventory, static fn($r) => ! empty($r['archived'])));

                $q = isset($_GET['q']) ? sanitize_text_field((string)wp_unslash($_GET['q'])) : '';
                $filter_type = isset($_GET['type']) ? sanitize_key((string)wp_unslash($_GET['type'])) : '';
                $filter_category = isset($_GET['category']) ? strtoupper(sanitize_key((string)wp_unslash($_GET['category']))) : '';
                $filter_status = isset($_GET['status']) ? strtoupper(sanitize_key((string)wp_unslash($_GET['status']))) : '';
                $filter_archive = isset($_GET['archive']) ? sanitize_key((string)wp_unslash($_GET['archive'])) : 'active';

                $filtered = array_values(array_filter($inventory, static function($row) use ($q,$filter_type,$filter_category,$filter_status,$filter_archive) {
                    if ( $filter_archive === 'active' && ! empty($row['archived']) ) return false;
                    if ( $filter_archive === 'archived' && empty($row['archived']) ) return false;
                    if ( $filter_type !== '' && $row['builder_type'] !== $filter_type ) return false;
                    if ( $filter_category !== '' && strtoupper($row['category']) !== $filter_category ) return false;
                    if ( $filter_status !== '' && strtoupper($row['status']) !== $filter_status ) return false;
                    if ( $q !== '' ) {
                        $haystack = strtolower(remove_accents(implode(' ', [$row['title'],$row['name'],$row['language'],$row['sender_label'],$row['builder_type']])));
                        $needle = strtolower(remove_accents($q));
                        if ( strpos($haystack, $needle) === false ) return false;
                    }
                    return true;
                }));

                $per_page = 30;
                $page_num = max(1, absint($_GET['paged_tpl'] ?? 1));
                $pages = max(1, (int)ceil(count($filtered) / $per_page));
                if ( $page_num > $pages ) $page_num = $pages;
                $page_rows = array_slice($filtered, ($page_num - 1) * $per_page, $per_page);
            ?>
                <div class="evapp-utpl-stats">
                    <div class="evapp-utpl-stat"><b><?php echo esc_html($total); ?></b><span>Total de plantillas</span></div>
                    <div class="evapp-utpl-stat"><b><?php echo esc_html($approved); ?></b><span>Aprobadas / activas</span></div>
                    <div class="evapp-utpl-stat"><b><?php echo esc_html($pending); ?></b><span>En revisión</span></div>
                    <div class="evapp-utpl-stat"><b><?php echo esc_html($archived_count); ?></b><span>Archivadas localmente</span></div>
                </div>

                <div class="evapp-utpl-card" id="evapp-utpl-new">
                    <div class="evapp-utpl-card-head"><div><h2>Crear nueva plantilla</h2><div class="evapp-utpl-sub">Escoge el objetivo. El builder precarga estructura, variables y botones recomendados; luego puedes editarlos antes de guardar.</div></div></div>
                    <div class="evapp-utpl-card-body"><div class="evapp-utpl-presets">
                        <?php foreach ( $presets as $type => $preset ) :
                            $url = add_query_arg(['page'=>'eventosapp_whatsapp_templates','view'=>'edit','engine'=>$preset['engine'],'builder_type'=>$type], admin_url('admin.php'));
                        ?>
                            <a class="evapp-utpl-preset" href="<?php echo esc_url($url); ?>">
                                <span class="evapp-utpl-preset-icon"><?php echo esc_html($preset['icon']); ?></span>
                                <b><?php echo esc_html($preset['label']); ?></b>
                                <span class="evapp-utpl-tag <?php echo strtolower($preset['category']) === 'marketing' ? 'marketing' : 'utility'; ?>"><?php echo esc_html($preset['category']); ?></span>
                                <small><?php echo esc_html($preset['description']); ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div></div>
                </div>

                <div class="evapp-utpl-card">
                    <div class="evapp-utpl-card-head"><div><h2>Inventario</h2><div class="evapp-utpl-sub"><?php echo esc_html(count($filtered)); ?> resultado(s) con los filtros actuales.</div></div></div>
                    <div class="evapp-utpl-card-body">
                        <form method="get" class="evapp-utpl-toolbar">
                            <input type="hidden" name="page" value="eventosapp_whatsapp_templates">
                            <input class="evapp-utpl-search" type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Buscar por nombre, técnico, idioma o número…">
                            <select name="type"><option value="">Todos los tipos</option><?php foreach($presets as $type=>$preset): ?><option value="<?php echo esc_attr($type); ?>" <?php selected($filter_type,$type); ?>><?php echo esc_html($preset['short']); ?></option><?php endforeach; ?></select>
                            <select name="category"><option value="">Todas las categorías</option><option value="UTILITY" <?php selected($filter_category,'UTILITY'); ?>>Utility</option><option value="MARKETING" <?php selected($filter_category,'MARKETING'); ?>>Marketing</option><option value="AUTHENTICATION" <?php selected($filter_category,'AUTHENTICATION'); ?>>Authentication</option></select>
                            <select name="status"><option value="">Todos los estados</option><?php foreach(['LOCAL'=>'Local','PENDING'=>'En revisión','APPROVED'=>'Aprobada','ACTIVE'=>'Activa','REJECTED'=>'Rechazada','PAUSED'=>'Pausada','DISABLED'=>'Deshabilitada'] as $v=>$l): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($filter_status,$v); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select>
                            <select name="archive"><option value="active" <?php selected($filter_archive,'active'); ?>>Activas</option><option value="archived" <?php selected($filter_archive,'archived'); ?>>Archivadas</option><option value="all" <?php selected($filter_archive,'all'); ?>>Todas</option></select>
                            <button class="evapp-utpl-btn" type="submit">Filtrar</button>
                        </form>
                    </div>
                    <div class="evapp-utpl-table-wrap">
                        <?php if ( empty($page_rows) ) : ?><div class="evapp-utpl-empty">No hay plantillas que coincidan con los filtros.</div><?php else : ?>
                        <table class="evapp-utpl-table"><thead><tr><th>Plantilla</th><th>Tipo</th><th>Categoría</th><th>Cuenta</th><th>Estado Meta</th><th>Actualizada</th><th>Acciones</th></tr></thead><tbody>
                        <?php foreach($page_rows as $row):
                            $edit_url = add_query_arg(['page'=>'eventosapp_whatsapp_templates','view'=>'edit','engine'=>$row['engine'],'template_id'=>$row['id'],'builder_type'=>$row['builder_type']], admin_url('admin.php'));
                        ?>
                            <tr class="<?php echo ! empty($row['archived']) ? 'evapp-utpl-archived' : ''; ?>">
                                <td><div class="evapp-utpl-name"><?php echo esc_html($row['title']); ?></div><div class="evapp-utpl-sub"><?php echo esc_html($row['name']); ?> · <?php echo esc_html($row['language']); ?><?php if($row['engine']==='flow'): ?> · Flow<?php endif; ?></div></td>
                                <td><?php echo esc_html(eventosapp_whatsapp_templates_builder_type_label($row['builder_type'])); ?><?php if(!empty($row['archived'])): ?><div class="evapp-utpl-sub">Archivada</div><?php endif; ?></td>
                                <td><span class="evapp-utpl-tag <?php echo strtolower($row['category'])==='marketing'?'marketing':'utility'; ?>"><?php echo esc_html($row['category']); ?></span><?php if($row['meta_category'] && $row['meta_category']!==$row['category']): ?><div class="evapp-utpl-sub">Meta: <?php echo esc_html($row['meta_category']); ?></div><?php endif; ?></td>
                                <td><div class="evapp-utpl-sub"><?php echo esc_html($row['sender_label']); ?></div></td>
                                <td><span class="evapp-utpl-status <?php echo esc_attr($row['status']); ?>"><?php echo esc_html($row['status']); ?></span></td>
                                <td><div class="evapp-utpl-sub"><?php echo esc_html($row['updated_at'] ?: '—'); ?></div></td>
                                <td><div class="evapp-utpl-actions">
                                    <a class="button" href="<?php echo esc_url($edit_url); ?>">Abrir</a>
                                    <?php if ( $row['engine'] === 'flow' ) : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-utpl-inline-form"><?php wp_nonce_field('eventosapp_whatsapp_flow_template_submit_meta'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_flow_template_submit_meta"><input type="hidden" name="template_id" value="<?php echo esc_attr($row['id']); ?>"><button class="button button-primary">Enviar a Meta</button></form>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-utpl-inline-form"><?php wp_nonce_field('eventosapp_whatsapp_flow_template_sync_status'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_flow_template_sync_status"><input type="hidden" name="template_id" value="<?php echo esc_attr($row['id']); ?>"><button class="button">Consultar</button></form>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-utpl-inline-form"><?php wp_nonce_field('eventosapp_whatsapp_flow_template_duplicate'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_flow_template_duplicate"><input type="hidden" name="template_id" value="<?php echo esc_attr($row['id']); ?>"><button class="button">Duplicar</button></form>
                                    <?php else : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-utpl-inline-form"><?php wp_nonce_field('eventosapp_whatsapp_templates_submit_' . $row['id'], 'eventosapp_whatsapp_templates_submit_nonce'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_templates_submit"><input type="hidden" name="template_id" value="<?php echo esc_attr($row['id']); ?>"><button class="button button-primary">Enviar a Meta</button></form>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-utpl-inline-form"><?php wp_nonce_field('eventosapp_whatsapp_templates_check_' . $row['id'], 'eventosapp_whatsapp_templates_check_nonce'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_templates_check"><input type="hidden" name="template_id" value="<?php echo esc_attr($row['id']); ?>"><button class="button">Consultar</button></form>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-utpl-inline-form"><?php wp_nonce_field('eventosapp_whatsapp_templates_duplicate_' . $row['id'], 'eventosapp_whatsapp_templates_duplicate_nonce'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_templates_duplicate"><input type="hidden" name="template_id" value="<?php echo esc_attr($row['id']); ?>"><button class="button">Duplicar</button></form>
                                    <?php endif; ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-utpl-inline-form"><?php wp_nonce_field('eventosapp_whatsapp_templates_unified_export'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_templates_unified_export"><input type="hidden" name="engine" value="<?php echo esc_attr($row['engine']); ?>"><input type="hidden" name="template_id" value="<?php echo esc_attr($row['id']); ?>"><button class="button">Exportar</button></form>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-utpl-inline-form"><?php wp_nonce_field('eventosapp_whatsapp_templates_archive'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_templates_archive"><input type="hidden" name="engine" value="<?php echo esc_attr($row['engine']); ?>"><input type="hidden" name="template_id" value="<?php echo esc_attr($row['id']); ?>"><input type="hidden" name="archive" value="<?php echo !empty($row['archived'])?'0':'1'; ?>"><button class="button"><?php echo !empty($row['archived'])?'Restaurar':'Archivar'; ?></button></form>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody></table><?php endif; ?>
                    </div>
                    <?php if($pages>1): ?><div class="evapp-utpl-pager"><span><?php echo esc_html($page_num); ?> de <?php echo esc_html($pages); ?></span><div class="evapp-utpl-pager-links"><?php for($i=1;$i<=$pages;$i++): $url=add_query_arg(array_merge($_GET,['paged_tpl'=>$i]),admin_url('admin.php')); ?><a class="<?php echo $i===$page_num?'current':''; ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($i); ?></a><?php endfor; ?></div></div><?php endif; ?>
                </div>

                <div class="evapp-utpl-card"><div class="evapp-utpl-card-head"><h2>Importar plantilla</h2></div><div class="evapp-utpl-card-body">
                    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                        <?php wp_nonce_field('eventosapp_whatsapp_templates_unified_import'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_templates_unified_import"><input type="file" name="template_import_file" accept="application/json,.json" required><button class="evapp-utpl-btn" type="submit">Importar JSON</button><span class="evapp-utpl-help">Acepta exportaciones del centro unificado y exportaciones anteriores de plantillas Flow.</span>
                    </form>
                </div></div>

                <div class="evapp-utpl-card"><div class="evapp-utpl-card-body"><details class="evapp-utpl-details"><summary>Configuración técnica de Meta y medios por defecto</summary>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('eventosapp_whatsapp_templates_save_settings','eventosapp_whatsapp_templates_settings_nonce'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_templates_save_settings">
                        <div class="evapp-utpl-settings-grid">
                            <label>WhatsApp Business Account ID</label><div><input type="text" name="waba_id" value="<?php echo esc_attr($settings['waba_id'] ?? ''); ?>"><p class="evapp-utpl-help">Fallback global para plantillas del número principal.</p></div>
                            <label>Meta App ID</label><div><input type="text" name="app_id" value="<?php echo esc_attr($settings['app_id'] ?? ''); ?>"><p class="evapp-utpl-help">Se usa para Resumable Upload de muestras de encabezado.</p></div>
                            <label>Imagen por defecto QR</label><div><input type="text" name="default_qr_header_image" value="<?php echo esc_attr($settings['default_qr_header_image'] ?? ''); ?>"></div>
                            <label>Imagen por defecto virtual</label><div><input type="text" name="default_virtual_message_image" value="<?php echo esc_attr($settings['default_virtual_message_image'] ?? ''); ?>"></div>
                        </div><p><button class="evapp-utpl-btn primary" type="submit">Guardar configuración</button></p>
                    </form>
                </details></div></div>
            <?php endif; ?>
        </div>
        <script>
        jQuery(function($){
            $('.notice').filter(function(){return $(this).find('a[href*="eventosapp_attendance_confirmation_template"]').length>0;}).remove();
        });
        </script>
    </div>
    <?php
}


/**
 * Compatibilidad: conserva el renderer histórico para integraciones que lo llamen directamente.
 * La pantalla principal ya no lo usa; el inventario visible se renderiza desde el centro unificado.
 */
function eventosapp_whatsapp_templates_render_list($settings) {
    $templates = isset($settings['templates']) && is_array($settings['templates']) ? $settings['templates'] : [];
    ?>
    <div class="evapp-wa-tpl-card">
        <h2>Plantillas disponibles</h2>
        <p>
            Las dos plantillas por defecto ya quedan creadas localmente como base: una para modalidad presencial y otra para modalidad virtual. Puedes editarlas, duplicarlas o enviar su estructura a Meta para aprobación marcándolas para el número emisor correspondiente.
        </p>

        <p class="evapp-wa-tpl-actions">
            <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['page' => 'eventosapp_whatsapp_templates', 'view' => 'edit', 'base' => 'presencial'], admin_url('admin.php'))); ?>">Crear nueva desde presencial</a>
            <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['page' => 'eventosapp_whatsapp_templates', 'view' => 'edit', 'base' => 'virtual'], admin_url('admin.php'))); ?>">Crear nueva desde virtual</a>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <?php wp_nonce_field('eventosapp_whatsapp_templates_sync_all', 'eventosapp_whatsapp_templates_sync_nonce'); ?>
                <input type="hidden" name="action" value="eventosapp_whatsapp_templates_sync_all">
                <?php submit_button('Sincronizar estados desde Meta', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('Esto restaurará las dos plantillas base de EventosApp. No elimina las plantillas personalizadas.');">
                <?php wp_nonce_field('eventosapp_whatsapp_templates_reset_defaults', 'eventosapp_whatsapp_templates_reset_nonce'); ?>
                <input type="hidden" name="action" value="eventosapp_whatsapp_templates_reset_defaults">
                <?php submit_button('Restaurar plantillas base', 'secondary', 'submit', false); ?>
            </form>
        </p>

        <?php if ( ! empty($settings['last_sync_at']) ) : ?>
            <p class="evapp-wa-tpl-help">Última sincronización: <?php echo esc_html($settings['last_sync_at']); ?>. <?php echo esc_html($settings['last_message'] ?? ''); ?></p>
        <?php endif; ?>

        <table class="evapp-wa-tpl-table">
            <thead>
                <tr>
                    <th>Plantilla</th>
                    <th>Modalidad</th>
                    <th>Número / WABA</th>
                    <th>Idioma / Categoría</th>
                    <th>Estado Meta</th>
                    <th>Botones</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $templates as $template_id => $template ) : ?>
                    <?php if ( ! is_array($template) ) continue; ?>
                    <?php $status = eventosapp_whatsapp_templates_normalize_meta_status($template['meta_status'] ?? 'LOCAL'); ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($template['title'] ?? $template['name'] ?? $template_id); ?></strong><br>
                            <span class="evapp-wa-tpl-code"><?php echo esc_html($template['name'] ?? ''); ?></span>
                            <?php if ( ! empty($template['is_default']) && $template['is_default'] === '1' ) : ?><br><small>Plantilla base de EventosApp</small><?php endif; ?>
                            <?php if ( ! empty($template['meta_template_id']) ) : ?><br><small>ID Meta: <?php echo esc_html($template['meta_template_id']); ?></small><?php endif; ?>
                        </td>
                        <td><?php echo esc_html(eventosapp_whatsapp_templates_modality_label($template['modality'] ?? 'custom')); ?></td>
                        <td>
                            <?php
                            $sender_label = eventosapp_whatsapp_templates_get_template_sender_label($template, $settings);
                            $sender_phone = eventosapp_whatsapp_templates_sanitize_phone_number_id($template['sender_phone_number_id'] ?? '') ?: eventosapp_whatsapp_templates_get_default_phone_number_id();
                            $template_waba_id = eventosapp_whatsapp_templates_get_template_waba_id($template, $settings);
                            ?>
                            <strong><?php echo esc_html($sender_label); ?></strong>
                            <?php if ( $sender_phone !== '' ) : ?><br><small>Phone ID: <?php echo esc_html($sender_phone); ?></small><?php endif; ?>
                            <?php if ( $template_waba_id !== '' ) : ?><br><small>WABA: <?php echo esc_html($template_waba_id); ?></small><?php else : ?><br><small style="color:#b91c1c;">Sin WABA ID</small><?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $requested_category = eventosapp_whatsapp_templates_sanitize_category($template['category'] ?? 'UTILITY');
                            $remote_category = eventosapp_whatsapp_templates_normalize_meta_category($template['meta_category'] ?? '');
                            $category_mismatch = eventosapp_whatsapp_templates_category_mismatch($template);
                            $quality_summary = sanitize_text_field((string)($template['meta_quality_score'] ?? ''));
                            if ( $quality_summary === '' ) $quality_summary = eventosapp_whatsapp_templates_quality_summary($template['last_api_response']['quality_score'] ?? '');
                            ?>
                            <?php echo esc_html($template['language'] ?? 'es'); ?><br>
                            <small>Solicitada:</small> <span class="evapp-wa-category <?php echo esc_attr($requested_category); ?>"><?php echo esc_html(eventosapp_whatsapp_templates_category_label($requested_category)); ?></span>
                            <?php if ( $remote_category !== '' ) : ?>
                                <br><small>Meta:</small> <span class="evapp-wa-category <?php echo esc_attr($remote_category); ?>"><?php echo esc_html(eventosapp_whatsapp_templates_category_label($remote_category)); ?></span>
                            <?php endif; ?>
                            <?php if ( $category_mismatch ) : ?>
                                <small class="evapp-wa-cat-mismatch">Recategorizada por Meta<?php if ( ! empty($template['meta_category_changed_at']) ) : ?> · <?php echo esc_html($template['meta_category_changed_at']); ?><?php endif; ?></small>
                            <?php endif; ?>
                            <?php if ( $quality_summary !== '' ) : ?>
                                <br><small>Calidad: <?php echo esc_html($quality_summary); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="evapp-wa-status <?php echo esc_attr($status); ?>"><?php echo esc_html(eventosapp_whatsapp_templates_meta_status_label($status)); ?></span>
                            <?php $normalized_reason = eventosapp_whatsapp_templates_normalize_rejected_reason($template['meta_rejected_reason'] ?? ''); ?>
                            <?php if ( $normalized_reason !== '' ) : ?><br><small style="color:#b32d2e;"><?php echo esc_html($normalized_reason); ?></small><?php endif; ?>
                            <?php if ( ! empty($template['last_checked_at']) ) : ?><br><small>Consulta: <?php echo esc_html($template['last_checked_at']); ?></small><?php endif; ?>
                            <?php if ( ! empty($template['last_api_message']) ) : ?><br><small><?php echo esc_html($template['last_api_message']); ?></small><?php endif; ?>
                            <?php eventosapp_whatsapp_templates_render_meta_diagnostics($template, true); ?>
                        </td>
                        <td>
                            <?php
                            $enabled_buttons = eventosapp_whatsapp_templates_get_enabled_button_numbers($template);
                            $listed_buttons = 0;
                            foreach ( $enabled_buttons as $button_number ) :
                                $button_text = trim((string)($template['button_' . $button_number . '_text'] ?? ''));
                                if ( $button_text === '' ) {
                                    continue;
                                }
                                $listed_buttons++;
                            ?>
                                <?php echo esc_html($button_number); ?>. <?php echo esc_html($button_text); ?><br>
                            <?php endforeach; ?>
                            <?php if ( $listed_buttons === 0 ) : ?>
                                <small>Sin botones activos</small><br>
                            <?php endif; ?>
                            <small><?php echo esc_html(count($enabled_buttons)); ?> botón<?php echo count($enabled_buttons) === 1 ? '' : 'es'; ?> configurado<?php echo count($enabled_buttons) === 1 ? '' : 's'; ?></small>
                        </td>
                        <td>
                            <div class="evapp-wa-tpl-actions">
                                <a class="button button-secondary" href="<?php echo esc_url(add_query_arg(['page' => 'eventosapp_whatsapp_templates', 'view' => 'edit', 'template_id' => $template_id], admin_url('admin.php'))); ?>">Editar</a>

                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                    <?php wp_nonce_field('eventosapp_whatsapp_templates_submit_' . $template_id, 'eventosapp_whatsapp_templates_submit_nonce'); ?>
                                    <input type="hidden" name="action" value="eventosapp_whatsapp_templates_submit">
                                    <input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>">
                                    <?php submit_button('Enviar / reenviar a Meta', 'primary small', 'submit', false); ?>
                                </form>

                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                    <?php wp_nonce_field('eventosapp_whatsapp_templates_check_' . $template_id, 'eventosapp_whatsapp_templates_check_nonce'); ?>
                                    <input type="hidden" name="action" value="eventosapp_whatsapp_templates_check">
                                    <input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>">
                                    <?php submit_button('Consultar estado', 'secondary small', 'submit', false); ?>
                                </form>

                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                    <?php wp_nonce_field('eventosapp_whatsapp_templates_duplicate_' . $template_id, 'eventosapp_whatsapp_templates_duplicate_nonce'); ?>
                                    <input type="hidden" name="action" value="eventosapp_whatsapp_templates_duplicate">
                                    <input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>">
                                    <?php submit_button('Duplicar', 'secondary small', 'submit', false); ?>
                                </form>

                                <?php if ( empty($template['is_default']) || $template['is_default'] !== '1' ) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('¿Eliminar esta plantilla local? No elimina la plantilla en Meta.');">
                                        <?php wp_nonce_field('eventosapp_whatsapp_templates_delete_' . $template_id, 'eventosapp_whatsapp_templates_delete_nonce'); ?>
                                        <input type="hidden" name="action" value="eventosapp_whatsapp_templates_delete">
                                        <input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>">
                                        <?php submit_button('Eliminar local', 'delete small', 'submit', false); ?>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Etiqueta legible de modalidad.
 */
function eventosapp_whatsapp_templates_modality_label($modality) {
    $labels = [
        'presencial' => 'Presencial',
        'virtual'    => 'Virtual',
        'custom'     => 'Personalizada',
    ];
    return $labels[$modality] ?? 'Personalizada';
}

/**
 * Render formulario de edición/creación.
 */
function eventosapp_whatsapp_templates_render_edit_form($template_id = '') {
    $engine = isset($_GET['engine']) ? sanitize_key((string)wp_unslash($_GET['engine'])) : 'standard';
    $builder_type = isset($_GET['builder_type']) ? sanitize_key((string)wp_unslash($_GET['builder_type'])) : '';
    if ( $engine === 'flow' || $builder_type === 'flow' ) {
        eventosapp_whatsapp_templates_render_unified_flow_builder($template_id);
        return;
    }

    $settings = eventosapp_whatsapp_templates_get_settings();
    $is_new = true;
    if ( $template_id !== '' && ! empty($settings['templates'][$template_id]) && is_array($settings['templates'][$template_id]) ) {
        $template = $settings['templates'][$template_id];
        $is_new = false;
        $builder_type = eventosapp_whatsapp_templates_builder_type_for_template($template, 'standard');
    } else {
        if ( ! isset(eventosapp_whatsapp_templates_builder_presets()[$builder_type]) || $builder_type === 'flow' ) $builder_type = 'ticket_presencial';
        $template = eventosapp_whatsapp_templates_builder_new_standard_template($builder_type);
    }

    $template = wp_parse_args($template, [
        'id'=>'','title'=>'','name'=>'','language'=>'es','category'=>'UTILITY','modality'=>'custom','base_key'=>'custom','advanced_components_json'=>'',
        'header_format'=>'NONE','header_text'=>'','header_sample_handle'=>'','header_sample_file_name'=>'','header_sample_file_type'=>'','header_sample_file_size'=>0,'header_sample_uploaded_at'=>'',
        'body_text'=>'','body_examples'=>'','footer_text'=>'','button_mode'=>'url','button_count'=>'0',
        'button_1_text'=>'','button_1_type'=>'URL','button_1_url'=>'','button_1_example'=>'','button_1_phone_number'=>'','button_2_text'=>'','button_2_type'=>'URL','button_2_url'=>'','button_2_example'=>'','button_2_phone_number'=>'',
        'sender_phone_number_id'=>'','waba_id'=>'','meta_status'=>'LOCAL','meta_category'=>'','meta_template_id'=>'','archived'=>'0',
    ]);
    $template['builder_type'] = $builder_type;
    if ( $builder_type === 'double_auth_code' ) {
        $template = eventosapp_whatsapp_templates_normalize_double_auth_body_for_meta($template);
    }
    $template['category'] = eventosapp_whatsapp_templates_sanitize_category($template['category'] ?? 'UTILITY');
    $template['button_count'] = (string)eventosapp_whatsapp_templates_normalize_button_count($template['button_count'] ?? '', $template);
    $template['button_mode'] = sanitize_key((string)($template['button_mode'] ?? 'none'));
    if ( ! in_array($template['button_mode'], ['none','url','quick_reply','phone_number','mixed'], true) ) $template['button_mode'] = 'url';

    $phone_accounts = eventosapp_whatsapp_templates_get_phone_accounts();
    $default_sender_phone = eventosapp_whatsapp_templates_get_default_phone_number_id();
    $sender_phone = eventosapp_whatsapp_templates_sanitize_phone_number_id($template['sender_phone_number_id'] ?? '') ?: $default_sender_phone;
    $template_waba_id = eventosapp_whatsapp_templates_get_template_waba_id($template, $settings);
    $variables = eventosapp_whatsapp_templates_builder_variable_help($builder_type);
    $fixed_category = $builder_type === 'marketing' ? 'MARKETING' : ($builder_type === 'double_auth_code' ? 'AUTHENTICATION' : (in_array($builder_type, ['ticket_presencial','ticket_virtual','attendance_confirmation','utility_custom'], true) ? 'UTILITY' : ''));
    if ( $fixed_category !== '' ) $template['category'] = $fixed_category;
    $functional_button_lock = $builder_type === 'attendance_confirmation' ? 'attendance' : ($builder_type === 'double_auth_code' ? 'double_auth' : '');
    if ( $functional_button_lock === 'attendance' ) { $template['button_mode']='quick_reply'; $template['button_count']='2'; }
    if ( $functional_button_lock === 'double_auth' ) { $template['button_mode']='none'; $template['button_count']='0'; }
    $status = eventosapp_whatsapp_templates_normalize_meta_status($template['meta_status'] ?? 'LOCAL');
    $payload = ['waba_id'=>$template_waba_id,'name'=>$template['name'],'language'=>$template['language'],'category'=>$template['category'],'components'=>eventosapp_whatsapp_templates_build_meta_components($template)];
    ?>
    <style>
        .evapp-utpl-builder{display:grid;grid-template-columns:minmax(0,1fr) 390px;gap:18px;align-items:start}.evapp-utpl-builder-main{display:flex;flex-direction:column;gap:14px}.evapp-utpl-builder .evapp-utpl-card{margin:0}.evapp-utpl-builder-section{padding:18px}.evapp-utpl-section-title{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}.evapp-utpl-section-title h2{margin:0;font-size:17px}.evapp-utpl-section-title p{margin:4px 0 0;color:var(--ev-muted);font-size:12px}.evapp-utpl-form-grid{display:grid;grid-template-columns:180px minmax(0,1fr);gap:14px 18px;align-items:start}.evapp-utpl-form-grid>label{font-weight:800;padding-top:9px}.evapp-utpl-form-grid>div{min-width:0}.evapp-utpl-form-grid input[type=text],.evapp-utpl-form-grid input[type=url],.evapp-utpl-form-grid input[type=number],.evapp-utpl-form-grid input[type=file],.evapp-utpl-form-grid textarea,.evapp-utpl-form-grid select{display:block;width:100%;max-width:none;min-width:0;box-sizing:border-box;border:1px solid #cfd9e5;border-radius:8px;padding:8px 10px;background:#fff}.evapp-utpl-form-grid input[type=file]{padding:7px 8px}.evapp-utpl-form-grid textarea{min-height:150px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;line-height:1.45;resize:vertical}.evapp-utpl-inline-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;width:100%;min-width:0}.evapp-utpl-inline-grid>*{min-width:0}.evapp-utpl-flow-field-stack{display:flex;flex-direction:column;gap:10px;width:100%;min-width:0}.evapp-utpl-flow-field{display:flex;flex-direction:column;gap:5px;width:100%;min-width:0}.evapp-utpl-flow-field>label{font-size:12px;font-weight:800;color:var(--ev-text)}.evapp-utpl-flow-help{padding:9px 11px;border:1px solid #e1e8f0;border-radius:9px;background:#f8fafc;color:var(--ev-muted);font-size:12px;line-height:1.45}.evapp-utpl-vars{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.evapp-utpl-var{border:1px solid #c8d8e8;background:#f5faff;color:#245d93;border-radius:999px;padding:5px 8px;font-size:11px;font-weight:800;cursor:pointer}.evapp-utpl-media-note{padding:10px 12px;background:#f5f8fc;border:1px solid var(--ev-border);border-radius:9px;font-size:12px;color:var(--ev-muted);margin-top:8px}.evapp-utpl-button-card{border:1px solid var(--ev-border);border-radius:10px;padding:11px;margin-top:8px;background:#fafcfe}.evapp-utpl-preview-pane{position:sticky;top:42px}.evapp-utpl-phone{background:#e9edef;border-radius:24px;padding:14px;border:7px solid #263238;box-shadow:0 12px 30px rgba(16,24,40,.14)}.evapp-utpl-phone-top{text-align:center;font-size:11px;color:#667085;margin:0 0 10px}.evapp-utpl-bubble{background:#fff;border-radius:10px;padding:11px;box-shadow:0 1px 2px rgba(16,24,40,.08)}.evapp-utpl-bubble-header{font-weight:800;margin-bottom:7px}.evapp-utpl-bubble-body{white-space:pre-wrap;line-height:1.45;font-size:13px;min-height:90px}.evapp-utpl-bubble-footer{font-size:11px;color:#8a94a1;margin-top:8px}.evapp-utpl-preview-button{text-align:center;color:#1476d4;border-top:1px solid #edf0f2;padding:8px 4px;margin:8px -11px -8px;font-weight:700;font-size:12px}.evapp-utpl-meta-box{margin-top:12px;background:#fff;border:1px solid var(--ev-border);border-radius:11px;padding:12px}.evapp-utpl-meta-box pre{white-space:pre-wrap;max-height:300px;overflow:auto;background:#f8fafc;padding:9px;border-radius:7px;font-size:11px}.evapp-utpl-sticky-actions{position:sticky;bottom:0;z-index:10;display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:rgba(255,255,255,.95);backdrop-filter:blur(8px);border:1px solid var(--ev-border);border-radius:12px;padding:11px 12px;box-shadow:0 -5px 22px rgba(16,24,40,.08)}
        @media(max-width:1150px){.evapp-utpl-builder{grid-template-columns:1fr}.evapp-utpl-preview-pane{position:static}.evapp-utpl-phone{max-width:430px}}
        @media(max-width:782px){.evapp-utpl-form-grid{grid-template-columns:1fr}.evapp-utpl-form-grid>label{padding-top:0}.evapp-utpl-inline-grid{grid-template-columns:1fr}.evapp-utpl-builder-section{padding:14px}.evapp-utpl-sticky-actions{position:static}}
    </style>

    <?php if ( $is_new ) : ?>
        <div class="evapp-utpl-card"><div class="evapp-utpl-card-body">
            <label for="evapp-builder-type"><strong>Tipo de plantilla</strong></label>
            <select id="evapp-builder-type" style="min-width:320px;margin-left:8px">
                <?php foreach(eventosapp_whatsapp_templates_builder_presets() as $type=>$preset): if($preset['engine']==='flow') continue; ?><option value="<?php echo esc_attr($type); ?>" <?php selected($builder_type,$type); ?>><?php echo esc_html($preset['label']); ?></option><?php endforeach; ?>
                <option value="flow">Plantilla para WhatsApp Flow</option>
            </select>
            <span class="evapp-utpl-help" style="margin-left:8px">Al cambiar el tipo se carga su configuración recomendada; después puedes modificarla libremente.</span>
        </div></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" id="evapp-utpl-builder-form">
        <?php wp_nonce_field('eventosapp_whatsapp_templates_save_template','eventosapp_whatsapp_templates_save_nonce'); ?>
        <input type="hidden" name="action" value="eventosapp_whatsapp_templates_save_template">
        <input type="hidden" name="template[id]" value="<?php echo esc_attr($template['id']); ?>">
        <input type="hidden" name="existing_template_id" value="<?php echo esc_attr($is_new ? '' : $template['id']); ?>">
        <input type="hidden" name="template[builder_type]" value="<?php echo esc_attr($builder_type); ?>">
        <input type="hidden" name="template[base_key]" value="<?php echo esc_attr($template['base_key']); ?>">
        <?php if($builder_type==='attendance_confirmation'): ?><input type="hidden" name="template[attendance_confirmation]" value="1"><?php endif; ?>
        <?php if($builder_type==='double_auth_code'): ?><input type="hidden" name="template[double_auth_code]" value="1"><?php endif; ?>
        <?php if($builder_type==='double_auth_code'): ?><input type="hidden" name="template[authentication_otp_type]" value="COPY_CODE"><input type="hidden" name="template[authentication_button_text]" value="Copiar código"><input type="hidden" name="template[authentication_add_security_recommendation]" value="1"><?php endif; ?>

        <div class="evapp-utpl-builder">
            <div class="evapp-utpl-builder-main">
                <div class="evapp-utpl-card"><div class="evapp-utpl-builder-section">
                    <div class="evapp-utpl-section-title"><div><h2>1. Identidad y cuenta</h2><p>Define cómo se identifica la plantilla localmente y en Meta.</p></div><span class="evapp-utpl-status <?php echo esc_attr($status); ?>"><?php echo esc_html($status); ?></span></div>
                    <div class="evapp-utpl-form-grid">
                        <label>Título interno</label><div><input type="text" name="template[title]" value="<?php echo esc_attr($template['title']); ?>" required><p class="evapp-utpl-help">Nombre amigable para encontrarla en EventosApp.</p></div>
                        <label>Nombre técnico Meta</label><div><input type="text" name="template[name]" value="<?php echo esc_attr($template['name']); ?>" required pattern="[a-z0-9_]+"><p class="evapp-utpl-help">Minúsculas, números y guion bajo. Cambiarlo en una plantilla vinculada crea una identidad remota nueva.</p></div>
                        <label>Número emisor</label><div><select name="template[sender_phone_number_id]" id="evapp-utpl-sender"><option value="">Número por defecto</option><?php foreach($phone_accounts as $phone_id=>$account): ?><option value="<?php echo esc_attr($phone_id); ?>" <?php selected($sender_phone,$phone_id); ?>><?php echo esc_html($account['label'] ?? $phone_id); ?></option><?php endforeach; ?></select></div>
                        <label>WABA ID</label><div><input type="text" name="template[waba_id]" value="<?php echo esc_attr($template['waba_id'] ?? ''); ?>" placeholder="Se resuelve automáticamente cuando el número lo conoce"><p class="evapp-utpl-help">Déjalo vacío si el número emisor ya está vinculado a su WABA.</p></div>
                        <label>Idioma / categoría</label><div class="evapp-utpl-inline-grid"><select name="template[language]"><option value="es" <?php selected($template['language'],'es'); ?>>Español · es</option><option value="es_CO" <?php selected($template['language'],'es_CO'); ?>>Español Colombia · es_CO</option><option value="en_US" <?php selected($template['language'],'en_US'); ?>>English US · en_US</option><option value="pt_BR" <?php selected($template['language'],'pt_BR'); ?>>Português Brasil · pt_BR</option></select><?php if($fixed_category!==''): ?><input type="hidden" name="template[category]" value="<?php echo esc_attr($fixed_category); ?>"><input type="text" value="<?php echo esc_attr($fixed_category); ?>" readonly><?php else: ?><select name="template[category]"><option value="UTILITY" <?php selected($template['category'],'UTILITY'); ?>>Utility</option><option value="MARKETING" <?php selected($template['category'],'MARKETING'); ?>>Marketing</option><option value="AUTHENTICATION" <?php selected($template['category'],'AUTHENTICATION'); ?>>Authentication</option></select><?php endif; ?></div></div>
                    </div>
                </div></div>

                <div class="evapp-utpl-card"><div class="evapp-utpl-builder-section">
                    <div class="evapp-utpl-section-title"><div><h2>2. Contenido</h2><p>Encabezado, cuerpo, variables y footer.</p></div></div>
                    <div class="evapp-utpl-form-grid">
                        <label>Encabezado</label><div><select name="template[header_format]" id="evapp-utpl-header-format"><option value="NONE" <?php selected($template['header_format'],'NONE'); ?>>Sin encabezado</option><option value="TEXT" <?php selected($template['header_format'],'TEXT'); ?>>Texto</option><option value="IMAGE" <?php selected($template['header_format'],'IMAGE'); ?>>Imagen</option><option value="VIDEO" <?php selected($template['header_format'],'VIDEO'); ?>>Video MP4</option><option value="DOCUMENT" <?php selected($template['header_format'],'DOCUMENT'); ?>>Documento PDF</option></select></div>
                        <label class="evapp-header-text-row">Texto del encabezado</label><div class="evapp-header-text-row"><input type="text" name="template[header_text]" value="<?php echo esc_attr($template['header_text']); ?>"></div>
                        <label class="evapp-header-image-row">Muestra para Meta</label><div class="evapp-header-image-row"><input type="file" name="header_sample_file" accept="image/jpeg,image/png,video/mp4,application/pdf"><input type="text" name="template[header_sample_handle]" value="<?php echo esc_attr($template['header_sample_handle']); ?>" placeholder="Header Sample Handle" style="margin-top:8px"><div class="evapp-utpl-media-note">Para encabezado multimedia, sube JPG/PNG, MP4 o PDF según el tipo seleccionado. EventosApp conserva el Resumable Upload existente y guarda el handle devuelto por Meta.<?php if($template['header_sample_file_name']): ?><br><strong>Actual:</strong> <?php echo esc_html($template['header_sample_file_name']); ?><?php endif; ?></div></div>
                        <label>Cuerpo del mensaje</label><div><textarea name="template[body_text]" id="evapp-utpl-body" required <?php echo $builder_type==='double_auth_code'?'readonly':''; ?>><?php echo esc_textarea($template['body_text']); ?></textarea><?php if($builder_type!=='double_auth_code'): ?><div class="evapp-utpl-vars"><?php foreach($variables as $num=>$label): ?><button type="button" class="evapp-utpl-var" data-var="{{<?php echo esc_attr($num); ?>}}">{{<?php echo esc_html($num); ?>}} · <?php echo esc_html($label); ?></button><?php endforeach; ?></div><?php endif; ?><p class="evapp-utpl-help"><?php if($builder_type==='double_auth_code'): ?>Authentication no permite un BODY libre. Meta genera el texto localizado y EventosApp solo envía <strong>{{1}}</strong> como código OTP. El contenido de nombre, evento, fecha, ticket y organizador ya no forma parte de esta plantilla.<?php else: ?>Los presets funcionales ya traen el mapa esperado por su módulo. En Marketing/Utility personalizada puedes usar solo las variables que necesites.<?php endif; ?></p></div>
                        <label>Ejemplos de variables</label><div><textarea name="template[body_examples]" id="evapp-utpl-examples" required <?php echo $builder_type==='double_auth_code'?'readonly':''; ?>><?php echo esc_textarea($template['body_examples']); ?></textarea><p class="evapp-utpl-help"><?php echo $builder_type==='double_auth_code'?'Ejemplo local del único OTP. Meta no recibe body_text de ejemplo en la creación Authentication.':'Un ejemplo por línea. El motor normaliza la numeración antes de construir el payload para Meta.'; ?></p></div>
                        <label>Footer</label><div><input type="text" name="template[footer_text]" id="evapp-utpl-footer" value="<?php echo esc_attr($template['footer_text']); ?>" <?php echo $builder_type==='double_auth_code'?'readonly':''; ?>><p class="evapp-utpl-help"><?php if($builder_type==='double_auth_code'): ?>Sin footer de expiración: EventosApp no anuncia un TTL que el backend actual no aplique.<?php endif; ?></p></div>
                    </div>
                </div></div>

                <div class="evapp-utpl-card"><div class="evapp-utpl-builder-section">
                    <div class="evapp-utpl-section-title"><div><h2>3. Botones</h2><p>El preset propone una estructura, pero puedes dejarla sin botones, usar enlaces o respuestas rápidas.</p></div></div>
                    <div class="evapp-utpl-form-grid">
                        <label>Tipo de botones</label><div><?php if($functional_button_lock==='attendance'): ?><input type="hidden" name="template[button_mode]" value="quick_reply"><select id="evapp-utpl-button-mode" disabled><option>Respuestas rápidas · requerido por Confirmación</option></select><?php elseif($functional_button_lock==='double_auth'): ?><input type="hidden" name="template[button_mode]" value="none"><select id="evapp-utpl-button-mode" disabled><option>OTP · Copiar código · administrado por Meta</option></select><?php else: ?><select name="template[button_mode]" id="evapp-utpl-button-mode"><option value="none" <?php selected($template['button_mode'],'none'); ?>>Sin botones</option><option value="url" <?php selected($template['button_mode'],'url'); ?>>Botones URL</option><option value="quick_reply" <?php selected($template['button_mode'],'quick_reply'); ?>>Respuestas rápidas</option><option value="phone_number" <?php selected($template['button_mode'],'phone_number'); ?>>Llamar por teléfono</option><option value="mixed" <?php selected($template['button_mode'],'mixed'); ?>>Mixtos (por botón)</option></select><?php endif; ?></div>
                        <label>Cantidad</label><div><?php if($functional_button_lock==='attendance'): ?><input type="hidden" name="template[button_count]" value="2"><select id="evapp-utpl-button-count" disabled><option>2 botones</option></select><?php elseif($functional_button_lock==='double_auth'): ?><input type="hidden" name="template[button_count]" value="0"><select id="evapp-utpl-button-count" disabled><option>1 botón OTP especial</option></select><?php else: ?><select name="template[button_count]" id="evapp-utpl-button-count"><option value="0" <?php selected((int)$template['button_count'],0); ?>>0</option><option value="1" <?php selected((int)$template['button_count'],1); ?>>1</option><option value="2" <?php selected((int)$template['button_count'],2); ?>>2</option></select><?php endif; ?></div>
                        <label>Configuración</label><div>
                            <?php for($i=1;$i<=2;$i++): ?>
                            <div class="evapp-utpl-button-card" data-button-card="<?php echo $i; ?>"><strong>Botón <?php echo $i; ?></strong><select class="evapp-button-type" name="template[button_<?php echo $i; ?>_type]" style="margin-bottom:7px"><option value="URL" <?php selected(eventosapp_whatsapp_templates_get_button_type($template,$i),'URL'); ?>>URL</option><option value="QUICK_REPLY" <?php selected(eventosapp_whatsapp_templates_get_button_type($template,$i),'QUICK_REPLY'); ?>>Respuesta rápida</option><option value="PHONE_NUMBER" <?php selected(eventosapp_whatsapp_templates_get_button_type($template,$i),'PHONE_NUMBER'); ?>>Llamar</option></select><input type="text" name="template[button_<?php echo $i; ?>_text]" value="<?php echo esc_attr($template['button_'.$i.'_text']); ?>" placeholder="Texto del botón"><div class="evapp-button-url-fields" style="margin-top:7px"><input type="text" name="template[button_<?php echo $i; ?>_url]" value="<?php echo esc_attr($template['button_'.$i.'_url']); ?>" placeholder="https://.../{{1}}"><input type="text" name="template[button_<?php echo $i; ?>_example]" value="<?php echo esc_attr($template['button_'.$i.'_example']); ?>" placeholder="Valor de ejemplo para {{1}}" style="margin-top:7px"></div><div class="evapp-button-phone-fields" style="margin-top:7px"><input type="text" name="template[button_<?php echo $i; ?>_phone_number]" value="<?php echo esc_attr($template['button_'.$i.'_phone_number'] ?? ''); ?>" placeholder="+573001234567"></div></div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div></div>

                <?php if ( in_array($builder_type, ['marketing','utility_custom'], true) ) : ?>
                <div class="evapp-utpl-card"><div class="evapp-utpl-builder-section">
                    <details <?php echo trim((string)($template['advanced_components_json'] ?? '')) !== '' ? 'open' : ''; ?>>
                        <summary style="cursor:pointer;font-weight:800">Modo avanzado · Componentes Meta JSON</summary>
                        <p class="evapp-utpl-help">Opcional. Si lo completas, este arreglo JSON reemplaza los componentes generados por los controles visuales al enviar a Meta. Úsalo para estructuras especiales o nuevas de Meta. Déjalo vacío para usar el builder visual.</p>
                        <textarea name="template[advanced_components_json]" style="width:100%;min-height:220px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace" placeholder='[{"type":"BODY","text":"Hola {{1}}","example":{"body_text":[["María"]]}}]'><?php echo esc_textarea($template['advanced_components_json'] ?? ''); ?></textarea>
                        <div class="evapp-utpl-media-note">EventosApp valida que el nivel superior sea un arreglo JSON y que cada componente incluya <code>type</code>. La validación específica de componentes especiales sigue a cargo de Meta.</div>
                    </details>
                </div></div>
                <?php endif; ?>

                <div class="evapp-utpl-sticky-actions">
                    <button class="evapp-utpl-btn primary" type="submit">Guardar plantilla</button>
                    <a class="evapp-utpl-btn" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_templates')); ?>">Cancelar</a>
                    <?php if(!$is_new): ?><span class="evapp-utpl-help">Guardar no envía automáticamente a Meta.</span><?php endif; ?>
                </div>
            </div>

            <aside class="evapp-utpl-preview-pane">
                <div class="evapp-utpl-card"><div class="evapp-utpl-card-body">
                    <div class="evapp-utpl-section-title"><div><h2>Vista previa</h2><p>Aproximación visual del mensaje.</p></div></div>
                    <div class="evapp-utpl-phone"><div class="evapp-utpl-phone-top">WhatsApp</div><div class="evapp-utpl-bubble"><div class="evapp-utpl-bubble-header" id="evapp-preview-header"></div><div class="evapp-utpl-bubble-body" id="evapp-preview-body"></div><div class="evapp-utpl-bubble-footer" id="evapp-preview-footer"></div><div id="evapp-preview-buttons"></div></div></div>
                    <div class="evapp-utpl-meta-box"><strong>Payload técnico</strong><pre><?php echo esc_html(wp_json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?></pre></div>
                    <?php if(!$is_new): eventosapp_whatsapp_templates_render_meta_diagnostics($template,true); endif; ?>
                </div></div>
            </aside>
        </div>
    </form>

    <?php if(!$is_new): ?>
    <div class="evapp-utpl-card" style="margin-top:16px"><div class="evapp-utpl-card-body"><div class="evapp-utpl-actions">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-utpl-inline-form"><?php wp_nonce_field('eventosapp_whatsapp_templates_submit_' . $template['id'],'eventosapp_whatsapp_templates_submit_nonce'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_templates_submit"><input type="hidden" name="template_id" value="<?php echo esc_attr($template['id']); ?>"><button class="evapp-utpl-btn primary">Enviar / reenviar a Meta</button></form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-utpl-inline-form"><?php wp_nonce_field('eventosapp_whatsapp_templates_check_' . $template['id'],'eventosapp_whatsapp_templates_check_nonce'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_templates_check"><input type="hidden" name="template_id" value="<?php echo esc_attr($template['id']); ?>"><button class="evapp-utpl-btn">Consultar estado</button></form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-utpl-inline-form"><?php wp_nonce_field('eventosapp_whatsapp_templates_duplicate_' . $template['id'],'eventosapp_whatsapp_templates_duplicate_nonce'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_templates_duplicate"><input type="hidden" name="template_id" value="<?php echo esc_attr($template['id']); ?>"><button class="evapp-utpl-btn">Duplicar</button></form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-utpl-inline-form"><?php wp_nonce_field('eventosapp_whatsapp_templates_unified_export'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_templates_unified_export"><input type="hidden" name="engine" value="standard"><input type="hidden" name="template_id" value="<?php echo esc_attr($template['id']); ?>"><button class="evapp-utpl-btn">Exportar JSON</button></form>
    </div></div></div>
    <?php endif; ?>

    <script>
    jQuery(function($){
        const isNew=<?php echo $is_new?'true':'false'; ?>; const buttonLock=<?php echo wp_json_encode($functional_button_lock); ?>;
        $('#evapp-builder-type').on('change',function(){
            if(!isNew)return;
            const type=$(this).val();
            const engine=type==='flow'?'flow':'standard';
            window.location=<?php echo wp_json_encode(admin_url('admin.php?page=eventosapp_whatsapp_templates&view=edit')); ?>+'&engine='+encodeURIComponent(engine)+'&builder_type='+encodeURIComponent(type);
        });
        $('.evapp-utpl-var').on('click',function(){
            const $body=$('#evapp-utpl-body'),el=$body.get(0),token=$(this).data('var')||'';
            const start=el.selectionStart||0,end=el.selectionEnd||0,val=$body.val();
            $body.val(val.slice(0,start)+token+val.slice(end)); el.focus(); el.selectionStart=el.selectionEnd=start+token.length; updatePreview();
        });
        function evappToggleTemplateWabaField(){ return true; }
        function evappToggleTemplateButtonBoxes(){ toggleButtons(); }
        function toggleHeader(){const type=$('#evapp-utpl-header-format').val();$('.evapp-header-text-row').toggle(type==='TEXT');$('.evapp-header-image-row').toggle(['IMAGE','VIDEO','DOCUMENT'].includes(type));}
        function toggleButtons(){let mode=buttonLock==='attendance'?'quick_reply':(buttonLock==='double_auth'?'none':$('#evapp-utpl-button-mode').val()),count=buttonLock==='attendance'?2:(buttonLock==='double_auth'?0:parseInt($('#evapp-utpl-button-count').val()||0,10));if(mode==='none'){count=0;if(!buttonLock)$('#evapp-utpl-button-count').val('0');}if(!buttonLock)$('#evapp-utpl-button-count').prop('disabled',mode==='none');$('[data-button-card]').each(function(){const n=parseInt($(this).data('button-card'),10);$(this).toggle(n<=count&&mode!=='none');});$('.evapp-button-type').toggle(mode==='mixed');$('[data-button-card]').each(function(){const $c=$(this),type=mode==='mixed'?String($c.find('.evapp-button-type').val()||'URL'):(mode==='quick_reply'?'QUICK_REPLY':(mode==='phone_number'?'PHONE_NUMBER':'URL'));$c.find('.evapp-button-url-fields').toggle(type==='URL');$c.find('.evapp-button-phone-fields').toggle(type==='PHONE_NUMBER');});updatePreview();}
        function updatePreview(){
            const hType=$('#evapp-utpl-header-format').val(),h=$('input[name="template[header_text]"]').val()||'';
            $('#evapp-preview-header').text(hType==='TEXT'?h:(['IMAGE','VIDEO','DOCUMENT'].includes(hType)?'[ '+hType+' de encabezado ]':''));
            if(buttonLock==='double_auth'){
                $('#evapp-preview-body').text('{{1}} es tu código de verificación.\n\nPor tu seguridad, no compartas este código.');
                $('#evapp-preview-footer').text('');
                $('#evapp-preview-buttons').html('<div class="evapp-utpl-preview-button">Copiar código</div>');
                return;
            }
            $('#evapp-preview-body').text($('#evapp-utpl-body').val()||'');$('#evapp-preview-footer').text($('#evapp-utpl-footer').val()||'');
            const mode=buttonLock==='attendance'?'quick_reply':$('#evapp-utpl-button-mode').val(),count=buttonLock==='attendance'?2:parseInt($('#evapp-utpl-button-count').val()||0,10);let html='';
            if(mode!=='none'){for(let i=1;i<=count;i++){const txt=$('input[name="template[button_'+i+'_text]"]').val()||('Botón '+i);html+='<div class="evapp-utpl-preview-button">'+$('<div>').text(txt).html()+'</div>';}}$('#evapp-preview-buttons').html(html);
        }
        $('#evapp-utpl-header-format').on('change',function(){toggleHeader();updatePreview();});$('#evapp-utpl-button-mode,#evapp-utpl-button-count,.evapp-button-type').on('change',toggleButtons);$('#evapp-utpl-builder-form').on('input','input,textarea,select',updatePreview);toggleHeader();toggleButtons();updatePreview();
    });
    </script>
    <?php
}

/**
 * Builder unificado para las plantillas que abren WhatsApp Flows.
 * Reutiliza exactamente el almacenamiento y los handlers del módulo Flow.
 */
function eventosapp_whatsapp_templates_render_unified_flow_builder_styles() {
    ?>
    <style>
        .evapp-utpl-flow-builder{display:grid;grid-template-columns:minmax(0,1fr) 390px;gap:18px;align-items:start}
        .evapp-utpl-flow-builder .evapp-utpl-builder-main{display:flex;flex-direction:column;gap:14px;min-width:0}
        .evapp-utpl-flow-builder .evapp-utpl-card{margin:0}
        .evapp-utpl-flow-builder .evapp-utpl-builder-section{padding:18px}
        .evapp-utpl-flow-builder .evapp-utpl-section-title{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}
        .evapp-utpl-flow-builder .evapp-utpl-section-title h2{margin:0;font-size:17px}
        .evapp-utpl-flow-builder .evapp-utpl-section-title p{margin:4px 0 0;color:var(--ev-muted);font-size:12px}
        .evapp-utpl-flow-builder .evapp-utpl-form-grid{display:grid;grid-template-columns:180px minmax(0,1fr);gap:14px 18px;align-items:start}
        .evapp-utpl-flow-builder .evapp-utpl-form-grid>label{font-weight:800;padding-top:9px}
        .evapp-utpl-flow-builder .evapp-utpl-form-grid>div{min-width:0}
        .evapp-utpl-flow-builder .evapp-utpl-form-grid input[type=text],
        .evapp-utpl-flow-builder .evapp-utpl-form-grid input[type=url],
        .evapp-utpl-flow-builder .evapp-utpl-form-grid input[type=number],
        .evapp-utpl-flow-builder .evapp-utpl-form-grid input[type=file],
        .evapp-utpl-flow-builder .evapp-utpl-form-grid textarea,
        .evapp-utpl-flow-builder .evapp-utpl-form-grid select{display:block;width:100%;max-width:none;min-width:0;min-height:40px;box-sizing:border-box;border:1px solid #cfd9e5;border-radius:8px;padding:8px 10px;background:#fff;box-shadow:none}
        .evapp-utpl-flow-builder .evapp-utpl-form-grid input[type=file]{padding:7px 8px}
        .evapp-utpl-flow-builder .evapp-utpl-form-grid textarea{min-height:150px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;line-height:1.45;resize:vertical}
        .evapp-utpl-flow-builder .evapp-utpl-inline-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;width:100%;min-width:0}
        .evapp-utpl-flow-builder .evapp-utpl-inline-grid>*{min-width:0}
        .evapp-utpl-flow-builder .evapp-utpl-flow-field-stack{display:flex;flex-direction:column;gap:10px;width:100%;min-width:0}
        .evapp-utpl-flow-builder .evapp-utpl-flow-field{display:flex;flex-direction:column;gap:5px;width:100%;min-width:0}
        .evapp-utpl-flow-builder .evapp-utpl-flow-field>label{font-size:12px;font-weight:800;color:var(--ev-text)}
        .evapp-utpl-flow-builder .evapp-utpl-flow-help{padding:9px 11px;border:1px solid #e1e8f0;border-radius:9px;background:#f8fafc;color:var(--ev-muted);font-size:12px;line-height:1.45}
        .evapp-utpl-flow-builder .evapp-utpl-preview-pane{position:sticky;top:42px;min-width:0}
        .evapp-utpl-flow-builder .evapp-utpl-meta-box{margin-top:12px;background:#fff;border:1px solid var(--ev-border);border-radius:11px;padding:12px;overflow:hidden}
        .evapp-utpl-flow-builder .evapp-utpl-meta-box pre{white-space:pre-wrap;word-break:break-word;max-height:480px;overflow:auto;background:#f8fafc;padding:9px;border-radius:7px;font-size:11px;line-height:1.45;margin:0}
        .evapp-utpl-flow-builder .evapp-utpl-sticky-actions{position:sticky;bottom:0;z-index:10;display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:rgba(255,255,255,.95);backdrop-filter:blur(8px);border:1px solid var(--ev-border);border-radius:12px;padding:11px 12px;box-shadow:0 -5px 22px rgba(16,24,40,.08)}
        @media(max-width:1150px){
            .evapp-utpl-flow-builder{grid-template-columns:1fr}
            .evapp-utpl-flow-builder .evapp-utpl-preview-pane{position:static}
        }
        @media(max-width:782px){
            .evapp-utpl-flow-builder .evapp-utpl-form-grid{grid-template-columns:1fr}
            .evapp-utpl-flow-builder .evapp-utpl-form-grid>label{padding-top:0}
            .evapp-utpl-flow-builder .evapp-utpl-inline-grid{grid-template-columns:1fr}
            .evapp-utpl-flow-builder .evapp-utpl-builder-section{padding:14px}
            .evapp-utpl-flow-builder .evapp-utpl-sticky-actions{position:static}
        }
    </style>
    <?php
}

function eventosapp_whatsapp_templates_render_unified_flow_builder($template_id = '') {
    if ( ! function_exists('eventosapp_whatsapp_flow_templates_default_item') ) {
        echo '<div class="evapp-utpl-notice error">El módulo de plantillas Flow no está disponible.</div>';
        return;
    }

    eventosapp_whatsapp_templates_render_unified_flow_builder_styles();

    $items = eventosapp_whatsapp_flow_templates_get_all();
    $is_new = $template_id === '' || empty($items[$template_id]);
    $settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];
    $default_waba = eventosapp_whatsapp_flow_templates_get_default_waba_id($settings);
    $default_phone = eventosapp_whatsapp_flow_templates_get_default_phone_number_id($settings);
    $template = $is_new ? wp_parse_args(['waba_id'=>$default_waba,'sender_phone_number_id'=>$default_phone],eventosapp_whatsapp_flow_templates_default_item()) : eventosapp_whatsapp_flow_templates_get($template_id);
    $template = eventosapp_whatsapp_flow_templates_prepare_template_for_meta($template);
    $phone_accounts = eventosapp_whatsapp_flow_templates_get_phone_accounts($settings);
    $flows = function_exists('eventosapp_whatsapp_flows_get_all_for_select') ? eventosapp_whatsapp_flows_get_all_for_select() : [];
    $status = eventosapp_whatsapp_templates_unified_flow_status($template['meta_status'] ?? 'local_draft');
    ?>
    <?php if($is_new): ?><div class="evapp-utpl-card"><div class="evapp-utpl-card-body"><label><strong>Tipo de plantilla</strong></label><select id="evapp-flow-type-switch" style="min-width:320px;margin-left:8px"><option value="flow">Plantilla para WhatsApp Flow</option><?php foreach(eventosapp_whatsapp_templates_builder_presets() as $type=>$preset): if($type==='flow')continue; ?><option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($preset['label']); ?></option><?php endforeach; ?></select></div></div><?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
        <?php wp_nonce_field('eventosapp_whatsapp_flow_template_save'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_flow_template_save"><input type="hidden" name="template_id" value="<?php echo esc_attr($is_new?'':$template_id); ?>"><input type="hidden" name="save_mode" value="save">
        <div class="evapp-utpl-builder evapp-utpl-flow-builder">
            <div class="evapp-utpl-builder-main">
                <div class="evapp-utpl-card"><div class="evapp-utpl-builder-section"><div class="evapp-utpl-section-title"><div><h2>1. Identidad y cuenta</h2><p>Plantilla aprobable por Meta que abre un Flow.</p></div><span class="evapp-utpl-status <?php echo esc_attr($status); ?>"><?php echo esc_html($status); ?></span></div><div class="evapp-utpl-form-grid">
                    <label>Título interno</label><div><input type="text" name="display_name" value="<?php echo esc_attr($template['display_name']); ?>" required></div>
                    <label>Nombre técnico Meta</label><div><input type="text" name="template_name" value="<?php echo esc_attr($template['name']); ?>" required></div>
                    <label>Número emisor</label><div><select name="sender_phone_number_id"><option value="">Número por defecto</option><?php foreach($phone_accounts as $phone_id=>$account): ?><option value="<?php echo esc_attr($phone_id); ?>" <?php selected($template['sender_phone_number_id'],$phone_id); ?>><?php echo esc_html($account['label'] ?? $phone_id); ?></option><?php endforeach; ?></select></div>
                    <label>WABA ID</label><div><input type="text" name="waba_id" value="<?php echo esc_attr($template['waba_id']); ?>"></div>
                    <label>Idioma / categoría</label><div class="evapp-utpl-inline-grid"><input type="text" name="language" value="<?php echo esc_attr($template['language']); ?>"><select name="category"><?php foreach(eventosapp_whatsapp_flow_templates_supported_categories() as $value=>$label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($template['category'],$value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div></div>
                </div></div>
                <div class="evapp-utpl-card"><div class="evapp-utpl-builder-section"><div class="evapp-utpl-section-title"><div><h2>2. Contenido</h2><p>Mensaje que invita a abrir el Flow.</p></div></div><div class="evapp-utpl-form-grid">
                    <label>Encabezado</label><div>
                        <div class="evapp-utpl-flow-field-stack">
                            <div class="evapp-utpl-flow-field">
                                <label for="evapp-unified-flow-header-format">Tipo de encabezado</label>
                                <select id="evapp-unified-flow-header-format" name="header_format"><option value="NONE" <?php selected($template['header_format'],'NONE'); ?>>Sin encabezado</option><option value="TEXT" <?php selected($template['header_format'],'TEXT'); ?>>Texto</option><option value="IMAGE" <?php selected($template['header_format'],'IMAGE'); ?>>Imagen</option></select>
                            </div>
                            <div class="evapp-utpl-flow-field" data-unified-flow-header-text>
                                <label for="evapp-unified-flow-header-text">Texto del encabezado</label>
                                <input id="evapp-unified-flow-header-text" type="text" name="header_text" value="<?php echo esc_attr($template['header_text']); ?>" placeholder="Texto del encabezado" maxlength="60">
                            </div>
                            <div class="evapp-utpl-flow-field" data-unified-flow-header-image>
                                <label for="evapp-unified-flow-header-file">Imagen de muestra para Meta</label>
                                <input id="evapp-unified-flow-header-file" type="file" name="flow_header_sample_file" accept="image/jpeg,image/png">
                                <span class="evapp-utpl-flow-help">La imagen se usa para generar el Header Sample Handle de aprobación en Meta.</span>
                            </div>
                            <div class="evapp-utpl-flow-field" data-unified-flow-header-image>
                                <label for="evapp-unified-flow-header-handle">Header Sample Handle</label>
                                <input id="evapp-unified-flow-header-handle" type="text" name="header_sample_handle" value="<?php echo esc_attr($template['header_sample_handle']); ?>" placeholder="Handle generado por Meta">
                            </div>
                            <input type="hidden" name="header_image_url" value="<?php echo esc_attr($template['header_image_url']); ?>">
                        </div>
                    </div>
                    <label>Cuerpo</label><div><textarea name="body" required><?php echo esc_textarea($template['body']); ?></textarea></div>
                    <label>Ejemplos</label><div><div class="evapp-utpl-inline-grid"><div class="evapp-utpl-flow-field"><label for="evapp-unified-flow-sample-1">Ejemplo {{1}}</label><input id="evapp-unified-flow-sample-1" type="text" name="sample_1" value="<?php echo esc_attr($template['sample_1']); ?>" placeholder="Nombre de prueba"></div><div class="evapp-utpl-flow-field"><label for="evapp-unified-flow-sample-2">Ejemplo {{2}}</label><input id="evapp-unified-flow-sample-2" type="text" name="sample_2" value="<?php echo esc_attr($template['sample_2']); ?>" placeholder="Evento de prueba"></div></div></div>
                    <label>Footer</label><div><div class="evapp-utpl-flow-field"><input type="text" name="footer_text" value="<?php echo esc_attr($template['footer_text']); ?>" maxlength="60" placeholder="Texto opcional del pie"></div></div>
                    <label>Texto del botón</label><div><div class="evapp-utpl-flow-field"><input type="text" name="button_text" value="<?php echo esc_attr($template['button_text']); ?>" maxlength="30" required></div></div>
                </div></div>
                <div class="evapp-utpl-card"><div class="evapp-utpl-builder-section"><div class="evapp-utpl-section-title"><div><h2>3. Flow de destino</h2><p>Vincula el botón con el Flow y su pantalla inicial.</p></div></div><div class="evapp-utpl-form-grid">
                    <label>Flow local</label><div><select name="flow_post_id"><option value="0">Seleccionar Flow…</option><?php foreach($flows as $flow_id=>$flow_label): if(is_array($flow_label)){$label=$flow_label['label']??$flow_label['title']??('Flow #'.$flow_id);}else{$label=$flow_label;} ?><option value="<?php echo esc_attr($flow_id); ?>" <?php selected((int)$template['flow_post_id'],(int)$flow_id); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div>
                    <label>Meta Flow ID</label><div><input type="text" name="meta_flow_id" value="<?php echo esc_attr($template['meta_flow_id']); ?>"></div>
                    <label>Pantalla inicial</label><div><input type="text" name="navigate_screen" value="<?php echo esc_attr($template['navigate_screen']); ?>" placeholder="SURVEY"></div>
                </div></div>
                <div class="evapp-utpl-sticky-actions"><button class="evapp-utpl-btn primary" type="submit">Guardar plantilla Flow</button><a class="evapp-utpl-btn" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_templates')); ?>">Cancelar</a><span class="evapp-utpl-help">Guardar no publica ni envía a aprobación.</span></div>
            </div>
            <aside class="evapp-utpl-preview-pane"><div class="evapp-utpl-card"><div class="evapp-utpl-card-body"><h2 style="margin-top:0">Resumen</h2><p><strong><?php echo esc_html($template['display_name'] ?: 'Nueva plantilla Flow'); ?></strong></p><p class="evapp-utpl-help">Flow: <?php echo esc_html($template['meta_flow_id'] ?: 'sin Meta Flow ID'); ?><br>Pantalla: <?php echo esc_html($template['navigate_screen'] ?: 'SURVEY'); ?><br>WABA efectivo: <?php echo esc_html(eventosapp_whatsapp_flow_templates_get_template_waba_id($template,$settings) ?: 'sin resolver'); ?></p><div class="evapp-utpl-meta-box"><pre><?php echo esc_html(wp_json_encode(eventosapp_whatsapp_flow_templates_build_meta_payload($template),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?></pre></div></div></div></aside>
        </div>
    </form>
    <?php if(!$is_new): ?><div class="evapp-utpl-card" style="margin-top:16px"><div class="evapp-utpl-card-body"><div class="evapp-utpl-actions">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-utpl-inline-form"><?php wp_nonce_field('eventosapp_whatsapp_flow_template_submit_meta'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_flow_template_submit_meta"><input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>"><button class="evapp-utpl-btn primary">Enviar a Meta</button></form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-utpl-inline-form"><?php wp_nonce_field('eventosapp_whatsapp_flow_template_sync_status'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_flow_template_sync_status"><input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>"><button class="evapp-utpl-btn">Consultar estado</button></form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-utpl-inline-form"><?php wp_nonce_field('eventosapp_whatsapp_flow_template_duplicate'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_flow_template_duplicate"><input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>"><button class="evapp-utpl-btn">Duplicar</button></form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-utpl-inline-form"><?php wp_nonce_field('eventosapp_whatsapp_templates_unified_export'); ?><input type="hidden" name="action" value="eventosapp_whatsapp_templates_unified_export"><input type="hidden" name="engine" value="flow"><input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>"><button class="evapp-utpl-btn">Exportar JSON</button></form>
    </div></div></div><?php endif; ?>
    <script>
    jQuery(function($){
        $('#evapp-flow-type-switch').on('change',function(){const t=$(this).val();if(t==='flow')return;window.location=<?php echo wp_json_encode(admin_url('admin.php?page=eventosapp_whatsapp_templates&view=edit&engine=standard&builder_type=')); ?>+encodeURIComponent(t);});
        const $headerFormat=$('#evapp-unified-flow-header-format');
        function toggleUnifiedFlowHeader(){
            const type=String($headerFormat.val()||'NONE');
            $('[data-unified-flow-header-text]').toggle(type==='TEXT');
            $('[data-unified-flow-header-image]').toggle(type==='IMAGE');
        }
        $headerFormat.on('change',toggleUnifiedFlowHeader);
        toggleUnifiedFlowHeader();
    });
    </script>
    <?php
}


/**
 * Redirección con mensaje al módulo.
 */
function eventosapp_whatsapp_templates_redirect($ok, $message, $extra_args = [], $level = '') {
    $level = sanitize_key((string)$level);
    if ( ! in_array($level, ['success', 'warning', 'error', 'info'], true) ) {
        $level = $ok ? 'success' : 'error';
    }

    $args = array_merge([
        'page' => 'eventosapp_whatsapp_templates',
        'evapp_wa_tpl_ok' => $ok ? '1' : '0',
        'evapp_wa_tpl_level' => $level,
        'evapp_wa_tpl_msg' => rawurlencode((string) $message),
    ], $extra_args);

    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}

/**
 * Archiva/restaura una plantilla sin borrar su configuración ni su identidad Meta.
 */
add_action('admin_post_eventosapp_whatsapp_templates_archive', function() {
    if ( ! current_user_can('manage_options') ) wp_die('Permisos insuficientes.');
    check_admin_referer('eventosapp_whatsapp_templates_archive');
    $engine = sanitize_key((string)($_POST['engine'] ?? 'standard'));
    $template_id = sanitize_key((string)($_POST['template_id'] ?? ''));
    $archive = ! empty($_POST['archive']);
    if ( $template_id === '' ) eventosapp_whatsapp_templates_redirect(false, 'No se recibió una plantilla válida.');

    if ( $engine === 'flow' ) {
        if ( ! function_exists('eventosapp_whatsapp_flow_templates_get_all') ) eventosapp_whatsapp_templates_redirect(false, 'El motor de plantillas Flow no está disponible.');
        $items = eventosapp_whatsapp_flow_templates_get_all();
        if ( empty($items[$template_id]) || ! is_array($items[$template_id]) ) eventosapp_whatsapp_templates_redirect(false, 'No se encontró la plantilla Flow.');
        $items[$template_id]['archived'] = $archive ? '1' : '0';
        $items[$template_id]['archived_at'] = $archive ? current_time('mysql') : '';
        $items[$template_id]['updated_at'] = current_time('mysql');
        eventosapp_whatsapp_flow_templates_save_all($items);
    } else {
        $settings = eventosapp_whatsapp_templates_get_settings();
        if ( empty($settings['templates'][$template_id]) || ! is_array($settings['templates'][$template_id]) ) eventosapp_whatsapp_templates_redirect(false, 'No se encontró la plantilla.');
        $settings['templates'][$template_id]['archived'] = $archive ? '1' : '0';
        $settings['templates'][$template_id]['archived_at'] = $archive ? current_time('mysql') : '';
        $settings['templates'][$template_id]['updated_at'] = current_time('mysql');
        eventosapp_whatsapp_templates_update_settings($settings);
    }

    eventosapp_whatsapp_templates_redirect(true, $archive ? 'Plantilla archivada. Conserva su configuración y relación con Meta.' : 'Plantilla restaurada al inventario activo.', ['archive'=>$archive?'archived':'active']);
});

function eventosapp_whatsapp_templates_unified_export_standard_payload($template) {
    $template = is_array($template) ? $template : [];
    $keys = [
        'builder_type','base_key','attendance_confirmation','double_auth_code','authentication_otp_type','authentication_button_text','authentication_add_security_recommendation','authentication_code_expiration_minutes','advanced_components_json','title','name','language','category','modality',
        'header_format','header_text','header_sample_handle','header_sample_file_name','header_sample_file_type','header_sample_file_size','header_sample_uploaded_at',
        'body_text','body_examples','footer_text','button_mode','button_count','button_1_text','button_1_type','button_1_url','button_1_example','button_1_phone_number','button_2_text','button_2_type','button_2_url','button_2_example','button_2_phone_number',
    ];
    $config = [];
    foreach($keys as $key) if(array_key_exists($key,$template)) $config[$key] = $template[$key];
    return [
        'schema'=>'eventosapp_whatsapp_template_unified','version'=>2,'engine'=>'standard','exported_at'=>current_time('mysql'),
        'template'=>$config,
        'excluded_system_fields'=>['id','is_default','sender_phone_number_id','sender_phone_label','waba_id','meta_template_id','meta_status','meta_category','meta_rejected_reason','last_api_response','last_meta_result','meta_history','created_at','updated_at','archived','archived_at'],
    ];
}

add_action('admin_post_eventosapp_whatsapp_templates_unified_export', function() {
    if ( ! current_user_can('manage_options') ) wp_die('Permisos insuficientes.');
    check_admin_referer('eventosapp_whatsapp_templates_unified_export');
    $engine = sanitize_key((string)($_POST['engine'] ?? 'standard'));
    $template_id = sanitize_key((string)($_POST['template_id'] ?? ''));
    $payload = null;
    $name = 'plantilla-whatsapp';

    if ( $engine === 'flow' ) {
        $template = function_exists('eventosapp_whatsapp_flow_templates_get') ? eventosapp_whatsapp_flow_templates_get($template_id) : [];
        if ( empty($template) ) eventosapp_whatsapp_templates_redirect(false, 'No se encontró la plantilla Flow para exportar.');
        $flow_payload = eventosapp_whatsapp_flow_templates_build_export_payload($template);
        $payload = ['schema'=>'eventosapp_whatsapp_template_unified','version'=>2,'engine'=>'flow','exported_at'=>current_time('mysql'),'template'=>$flow_payload['template'] ?? [],'source_schema'=>$flow_payload['schema'] ?? 'eventosapp_whatsapp_flow_template'];
        $name = sanitize_key((string)($template['name'] ?? 'plantilla-flow'));
    } else {
        $settings = eventosapp_whatsapp_templates_get_settings();
        $template = $settings['templates'][$template_id] ?? null;
        if ( ! is_array($template) ) eventosapp_whatsapp_templates_redirect(false, 'No se encontró la plantilla para exportar.');
        $payload = eventosapp_whatsapp_templates_unified_export_standard_payload($template);
        $name = sanitize_key((string)($template['name'] ?? 'plantilla-whatsapp'));
    }

    $json = wp_json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ( ! is_string($json) || $json === '' ) eventosapp_whatsapp_templates_redirect(false, 'No se pudo construir el JSON de exportación.');
    while(ob_get_level()) ob_end_clean();
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . sanitize_file_name(($name ?: 'plantilla-whatsapp') . '-' . gmdate('Ymd-His') . '.json') . '"');
    header('X-Content-Type-Options: nosniff');
    echo $json;
    exit;
});

add_action('admin_post_eventosapp_whatsapp_templates_unified_import', function() {
    if ( ! current_user_can('manage_options') ) wp_die('Permisos insuficientes.');
    check_admin_referer('eventosapp_whatsapp_templates_unified_import');
    $file = $_FILES['template_import_file'] ?? null;
    if ( ! is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ) eventosapp_whatsapp_templates_redirect(false, 'Selecciona un archivo JSON válido para importar.');
    $size = absint($file['size'] ?? 0);
    $tmp_name = (string)($file['tmp_name'] ?? '');
    if ( $size <= 0 || $size > 2 * 1024 * 1024 ) eventosapp_whatsapp_templates_redirect(false, 'El JSON debe pesar entre 1 byte y 2 MB.');
    if ( $tmp_name === '' || ! is_uploaded_file($tmp_name) || ! is_readable($tmp_name) ) eventosapp_whatsapp_templates_redirect(false, 'No se pudo validar el archivo temporal de importación.');
    $raw = file_get_contents($tmp_name);
    $payload = json_decode((string)$raw, true);
    if ( ! is_array($payload) ) eventosapp_whatsapp_templates_redirect(false, 'El archivo no contiene JSON válido.');

    $schema = sanitize_key((string)($payload['schema'] ?? ''));
    $engine = sanitize_key((string)($payload['engine'] ?? ''));
    if ( $schema === 'eventosapp_whatsapp_flow_template' ) $engine = 'flow';
    if ( $engine === '' && isset($payload['template']['meta_flow_id']) ) $engine = 'flow';

    if ( $engine === 'flow' ) {
        if ( ! function_exists('eventosapp_whatsapp_flow_templates_parse_import_payload') ) eventosapp_whatsapp_templates_redirect(false, 'El importador Flow no está disponible.');
        $flow_source = $schema === 'eventosapp_whatsapp_template_unified' ? ($payload['template'] ?? []) : $payload;
        $parsed = eventosapp_whatsapp_flow_templates_parse_import_payload($flow_source);
        if ( is_wp_error($parsed) ) eventosapp_whatsapp_templates_redirect(false, $parsed->get_error_message());
        $new_id = sanitize_key('flow_tpl_' . wp_generate_password(12,false,false));
        $item = wp_parse_args($parsed, eventosapp_whatsapp_flow_templates_default_item());
        $item['id'] = $new_id;
        $item['display_name'] = sanitize_text_field((string)($item['display_name'] ?: $item['name'])) . ' · Importada';
        $item['meta_status'] = 'local_draft'; $item['meta_template_id']=''; $item['meta_category']=''; $item['last_meta_response']=[];
        $item['waba_id']=''; $item['sender_phone_number_id']=''; $item['flow_post_id']=0; $item['archived']='0'; $item['archived_at']='';
        $item['created_at']=current_time('mysql'); $item['updated_at']=current_time('mysql');
        $items = eventosapp_whatsapp_flow_templates_get_all(); $items[$new_id]=$item; eventosapp_whatsapp_flow_templates_save_all($items);
        eventosapp_whatsapp_templates_redirect(true,'Plantilla Flow importada. Revisa Flow, WABA y número emisor antes de enviarla a Meta.',['view'=>'edit','engine'=>'flow','template_id'=>$new_id,'builder_type'=>'flow']);
    }

    $raw_template = is_array($payload['template'] ?? null) ? $payload['template'] : $payload;
    $type = sanitize_key((string)($raw_template['builder_type'] ?? ''));
    if ( ! isset(eventosapp_whatsapp_templates_builder_presets()[$type]) || $type === 'flow' ) {
        $type = eventosapp_whatsapp_templates_builder_type_for_template($raw_template,'standard');
    }
    $raw_template['id'] = 'tpl_' . wp_generate_uuid4();
    $raw_template['builder_type'] = $type;
    $raw_template['is_default'] = '0';
    $raw_template['archived'] = '0';
    $raw_template['archived_at'] = '';
    if($type==='attendance_confirmation'){$raw_template['attendance_confirmation']='1';$raw_template['base_key']='attendance_confirmation';}
    if($type==='double_auth_code'){$raw_template['double_auth_code']='1';$raw_template['base_key']='double_auth_code';}
    $template = eventosapp_whatsapp_templates_normalize_template($raw_template, []);
    $template['meta_template_id']=''; $template['meta_status']='LOCAL'; $template['meta_category']=''; $template['meta_rejected_reason']='';
    $template['last_api_response']=[]; $template['last_meta_result']=[]; $template['meta_history']=[]; $template['last_submitted_at']=''; $template['last_checked_at']='';
    $template['title'] = sanitize_text_field((string)($template['title'] ?: $template['name'])) . ' · Importada';
    $settings = eventosapp_whatsapp_templates_get_settings(); $settings['templates'][$template['id']]=$template; eventosapp_whatsapp_templates_update_settings($settings);
    eventosapp_whatsapp_templates_redirect(true,'Plantilla importada como copia local. Revisa número emisor y WABA antes de enviarla a Meta.',['view'=>'edit','engine'=>'standard','template_id'=>$template['id'],'builder_type'=>$type]);
});


/**
 * Guarda conexión WABA ID.
 */
add_action('admin_post_eventosapp_whatsapp_templates_save_settings', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }
    if ( ! isset($_POST['eventosapp_whatsapp_templates_settings_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_settings_nonce'], 'eventosapp_whatsapp_templates_save_settings') ) {
        wp_die('Nonce inválido.');
    }

    $settings = eventosapp_whatsapp_templates_get_settings();
    $settings['waba_id'] = isset($_POST['waba_id']) ? preg_replace('/\D+/', '', (string) wp_unslash($_POST['waba_id'])) : '';
    $settings['app_id'] = isset($_POST['app_id']) ? preg_replace('/\D+/', '', (string) wp_unslash($_POST['app_id'])) : '';
    $settings['default_qr_header_image'] = isset($_POST['default_qr_header_image']) ? esc_url_raw(trim((string) wp_unslash($_POST['default_qr_header_image']))) : '';
    $settings['default_virtual_message_image'] = isset($_POST['default_virtual_message_image']) ? esc_url_raw(trim((string) wp_unslash($_POST['default_virtual_message_image']))) : '';
    eventosapp_whatsapp_templates_update_settings($settings);

    eventosapp_whatsapp_templates_redirect(true, 'Conexión de plantillas guardada.');
});

/**
 * Guarda plantilla local.
 */
add_action('admin_post_eventosapp_whatsapp_templates_save_template', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }
    if ( ! isset($_POST['eventosapp_whatsapp_templates_save_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_save_nonce'], 'eventosapp_whatsapp_templates_save_template') ) {
        wp_die('Nonce inválido.');
    }

    $settings = eventosapp_whatsapp_templates_get_settings();
    $existing_id = isset($_POST['existing_template_id']) ? sanitize_key(wp_unslash($_POST['existing_template_id'])) : '';
    $raw_template = isset($_POST['template']) && is_array($_POST['template']) ? wp_unslash($_POST['template']) : [];
    $existing = $existing_id && ! empty($settings['templates'][$existing_id]) ? $settings['templates'][$existing_id] : [];
    $template = eventosapp_whatsapp_templates_normalize_template($raw_template, $existing);
    $template_waba_id = eventosapp_whatsapp_templates_get_template_waba_id($template, $settings);
    $duplicate_local_id = eventosapp_whatsapp_templates_find_local_duplicate(
        $template['name'] ?? '',
        $template['language'] ?? '',
        $template_waba_id,
        $template['id'] ?? $existing_id
    );
    if ( $duplicate_local_id !== '' ) {
        eventosapp_whatsapp_templates_redirect(false, 'Ya existe otra plantilla local con el mismo nombre técnico, idioma y WABA. Registro en conflicto: ' . $duplicate_local_id . '.');
    }
    $identity_notice = sanitize_text_field((string)($template['meta_identity_reset_reason'] ?? ''));

    if ( $existing_id && $existing_id !== $template['id'] && isset($settings['templates'][$existing_id]) ) {
        unset($settings['templates'][$existing_id]);
    }

    $upload_message = '';
    $upload_ok = true;

    if ( eventosapp_whatsapp_templates_has_header_sample_upload() ) {
        if ( ! in_array(strtoupper((string)($template['header_format'] ?? 'NONE')), ['IMAGE','VIDEO','DOCUMENT'], true) ) {
            $upload_ok = false;
            $upload_message = 'Seleccionaste un archivo de muestra, pero el encabezado de la plantilla no está configurado como multimedia.';
        } else {
            $upload_result = eventosapp_whatsapp_templates_upload_header_sample_to_meta($_FILES['header_sample_file'], $template);
            if ( ! empty($upload_result['ok']) ) {
                $file_meta = is_array($upload_result['file'] ?? null) ? $upload_result['file'] : [];
                $template['header_sample_handle'] = eventosapp_whatsapp_templates_sanitize_header_handle($upload_result['handle'] ?? '');
                $template['header_sample_file_name'] = sanitize_file_name($file_meta['name'] ?? '');
                $template['header_sample_file_type'] = sanitize_mime_type($file_meta['type'] ?? '');
                $template['header_sample_file_size'] = absint($file_meta['size'] ?? 0);
                $template['header_sample_uploaded_at'] = current_time('mysql');
                $template['last_api_message'] = sanitize_text_field((string)($upload_result['message'] ?? 'Archivo de muestra subido a Meta.'));
                $template['last_api_response'] = is_array($upload_result['response'] ?? null) ? $upload_result['response'] : [];
                $upload_message = $upload_result['message'] ?? 'Archivo de muestra subido a Meta y handle guardado.';
            } else {
                $upload_ok = false;
                $template['last_api_message'] = sanitize_text_field((string)($upload_result['message'] ?? 'No se pudo subir el archivo de muestra a Meta.'));
                $template['last_api_response'] = is_array($upload_result['response'] ?? null) ? $upload_result['response'] : [];
                $upload_message = $template['last_api_message'];
            }
        }
    }

    $settings['templates'][$template['id']] = $template;
    eventosapp_whatsapp_templates_update_settings($settings);

    if ( $upload_message !== '' ) {
        $base_message = $upload_ok ? 'Plantilla local guardada. ' : 'Plantilla local guardada, pero ';
        if ( $identity_notice !== '' ) $base_message .= $identity_notice . ' ';
        eventosapp_whatsapp_templates_redirect($upload_ok, $base_message . $upload_message, [
            'view' => 'edit',
            'template_id' => $template['id'],
        ], $upload_ok ? 'success' : 'warning');
    }

    $saved_message = 'Plantilla local guardada.';
    if ( $identity_notice !== '' ) $saved_message .= ' ' . $identity_notice;
    eventosapp_whatsapp_templates_redirect(true, $saved_message, [
        'view' => 'edit',
        'template_id' => $template['id'],
    ]);
});

/**
 * Duplica una plantilla local.
 */
add_action('admin_post_eventosapp_whatsapp_templates_duplicate', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }

    $template_id = isset($_POST['template_id']) ? sanitize_key(wp_unslash($_POST['template_id'])) : '';
    if ( ! isset($_POST['eventosapp_whatsapp_templates_duplicate_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_duplicate_nonce'], 'eventosapp_whatsapp_templates_duplicate_' . $template_id) ) {
        wp_die('Nonce inválido.');
    }

    $settings = eventosapp_whatsapp_templates_get_settings();
    if ( empty($settings['templates'][$template_id]) ) {
        eventosapp_whatsapp_templates_redirect(false, 'No se encontró la plantilla para duplicar.');
    }

    $copy = $settings['templates'][$template_id];
    $copy['id'] = 'tpl_' . wp_generate_uuid4();
    $copy['is_default'] = '0';
    $copy['archived'] = '0';
    $copy['archived_at'] = '';
    $copy['title'] = 'Copia de ' . ($copy['title'] ?? $copy['name']);
    $copy['name'] = eventosapp_whatsapp_templates_sanitize_template_name(($copy['name'] ?? 'eventosapp_template') . '_copy_' . substr(md5($copy['id']), 0, 4));
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
    $copy['created_at'] = current_time('mysql');
    $copy['updated_at'] = current_time('mysql');

    $settings['templates'][$copy['id']] = $copy;
    eventosapp_whatsapp_templates_update_settings($settings);

    eventosapp_whatsapp_templates_redirect(true, 'Plantilla duplicada. Puedes editarla antes de enviarla a Meta.', [
        'view' => 'edit',
        'template_id' => $copy['id'],
    ]);
});

/**
 * Elimina plantilla local no predeterminada.
 */
add_action('admin_post_eventosapp_whatsapp_templates_delete', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }

    $template_id = isset($_POST['template_id']) ? sanitize_key(wp_unslash($_POST['template_id'])) : '';
    if ( ! isset($_POST['eventosapp_whatsapp_templates_delete_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_delete_nonce'], 'eventosapp_whatsapp_templates_delete_' . $template_id) ) {
        wp_die('Nonce inválido.');
    }

    $settings = eventosapp_whatsapp_templates_get_settings();
    if ( ! empty($settings['templates'][$template_id]['is_default']) && $settings['templates'][$template_id]['is_default'] === '1' ) {
        eventosapp_whatsapp_templates_redirect(false, 'No puedes eliminar una plantilla base. Usa restaurar si necesitas volver al diseño original.');
    }

    unset($settings['templates'][$template_id]);
    eventosapp_whatsapp_templates_update_settings($settings);

    eventosapp_whatsapp_templates_redirect(true, 'Plantilla local eliminada.');
});

/**
 * Enviar / reenviar plantilla a Meta.
 */
add_action('admin_post_eventosapp_whatsapp_templates_submit', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }

    $template_id = isset($_POST['template_id']) ? sanitize_key(wp_unslash($_POST['template_id'])) : '';
    if ( ! isset($_POST['eventosapp_whatsapp_templates_submit_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_submit_nonce'], 'eventosapp_whatsapp_templates_submit_' . $template_id) ) {
        wp_die('Nonce inválido.');
    }

    $result = eventosapp_whatsapp_templates_submit_to_meta($template_id);
    eventosapp_whatsapp_templates_redirect(! empty($result['ok']), $result['message'] ?? 'Solicitud procesada.', [], $result['notice_level'] ?? '');
});

/**
 * Consultar estado de plantilla.
 */
add_action('admin_post_eventosapp_whatsapp_templates_check', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }

    $template_id = isset($_POST['template_id']) ? sanitize_key(wp_unslash($_POST['template_id'])) : '';
    if ( ! isset($_POST['eventosapp_whatsapp_templates_check_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_check_nonce'], 'eventosapp_whatsapp_templates_check_' . $template_id) ) {
        wp_die('Nonce inválido.');
    }

    $result = eventosapp_whatsapp_templates_check_status($template_id);
    eventosapp_whatsapp_templates_redirect(! empty($result['ok']), $result['message'] ?? 'Consulta procesada.', [], $result['notice_level'] ?? '');
});

/**
 * Sincroniza todas las plantillas locales con Meta.
 */
add_action('admin_post_eventosapp_whatsapp_templates_sync_all', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }
    if ( ! isset($_POST['eventosapp_whatsapp_templates_sync_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_sync_nonce'], 'eventosapp_whatsapp_templates_sync_all') ) {
        wp_die('Nonce inválido.');
    }

    $result = eventosapp_whatsapp_templates_sync_all();
    eventosapp_whatsapp_templates_redirect(! empty($result['ok']), $result['message'] ?? 'Sincronización procesada.', [], $result['notice_level'] ?? '');
});

/**
 * Restaura plantillas base sin eliminar personalizadas.
 */
add_action('admin_post_eventosapp_whatsapp_templates_reset_defaults', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }
    if ( ! isset($_POST['eventosapp_whatsapp_templates_reset_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_reset_nonce'], 'eventosapp_whatsapp_templates_reset_defaults') ) {
        wp_die('Nonce inválido.');
    }

    $settings = eventosapp_whatsapp_templates_get_settings();
    foreach ( eventosapp_whatsapp_templates_default_records() as $id => $template ) {
        $settings['templates'][$id] = $template;
    }
    eventosapp_whatsapp_templates_update_settings($settings);

    eventosapp_whatsapp_templates_redirect(true, 'Plantillas base restauradas.');
});

/**
 * Resuelve ticket por identificador público recibido desde botones WhatsApp.
 */
function eventosapp_whatsapp_templates_resolve_ticket_from_request() {
    $public = '';
    foreach ( ['ticket', 'ticket_pub', 'public_id', 'ticketID'] as $key ) {
        if ( isset($_GET[$key]) && $_GET[$key] !== '' ) {
            $public = sanitize_text_field(wp_unslash($_GET[$key]));
            break;
        }
    }

    if ( $public === '' ) {
        return 0;
    }

    if ( function_exists('eventosapp_find_ticket_by_public_id') ) {
        $ticket_id = eventosapp_find_ticket_by_public_id($public);
        if ( $ticket_id && get_post_type($ticket_id) === 'eventosapp_ticket' ) {
            return $ticket_id;
        }
    }

    if ( ctype_digit($public) && current_user_can('edit_post', absint($public)) && get_post_type(absint($public)) === 'eventosapp_ticket' ) {
        return absint($public);
    }

    return 0;
}

/**
 * Obtiene URLs útiles del ticket para landing de WhatsApp.
 */
function eventosapp_whatsapp_templates_get_ticket_assets($ticket_id) {
    $ticket_id = absint($ticket_id);
    $event_id = $ticket_id ? absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true)) : 0;

    $qr_url = function_exists('eventosapp_whatsapp_ensure_qr_url') ? eventosapp_whatsapp_ensure_qr_url($ticket_id) : '';

    $ics_url = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);
    if ( ! $ics_url && function_exists('eventosapp_ticket_generar_ics') ) {
        eventosapp_ticket_generar_ics($ticket_id);
        $ics_url = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);
    }

    $pdf_url = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true);
    if ( ! $pdf_url && function_exists('eventosapp_ticket_generar_pdf') && ! (function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id)) ) {
        eventosapp_ticket_generar_pdf($ticket_id);
        $pdf_url = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true);
    }

    $google_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url', true);
    if ( ! $google_wallet ) {
        $google_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android', true);
    }

    $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url', true);
    if ( ! $apple_wallet ) {
        $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple', true);
    }
    if ( ! $apple_wallet ) {
        $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url', true);
    }

    $virtual_landing = '';
    if ( function_exists('eventosapp_get_virtual_landing_url') ) {
        $virtual_landing = eventosapp_get_virtual_landing_url($ticket_id);
    }

    $platform_url = function_exists('eventosapp_get_ticket_virtual_platform_url') ? eventosapp_get_ticket_virtual_platform_url($ticket_id) : ($event_id ? get_post_meta($event_id, '_eventosapp_virtual_url', true) : '');
    if ( function_exists('eventosapp_get_ticket_virtual_access_url') ) {
        $virtual_access_url = eventosapp_get_ticket_virtual_access_url($ticket_id);
    } else {
        $virtual_access_url = $virtual_landing ?: $platform_url;
    }

    $landing_header = function_exists('eventosapp_whatsapp_get_landing_header_image') ? eventosapp_whatsapp_get_landing_header_image($ticket_id, $event_id) : '';

    // En la landing pública solo se debe mostrar el QR limpio. La composición
    // cabezote + QR se reserva exclusivamente para el encabezado multimedia del
    // mensaje de WhatsApp presencial.
    $message_image  = $qr_url;

    return [
        'qr' => esc_url_raw($qr_url),
        'message_image' => esc_url_raw($message_image),
        'landing_header' => esc_url_raw($landing_header),
        'ics' => esc_url_raw($ics_url),
        'pdf' => esc_url_raw($pdf_url),
        'google_wallet' => esc_url_raw($google_wallet),
        'apple_wallet' => esc_url_raw($apple_wallet),
        'virtual_landing' => esc_url_raw($virtual_landing),
        'virtual_access_url' => esc_url_raw($virtual_access_url),
        'platform_url' => esc_url_raw($platform_url),
    ];
}

/**
 * Render público de landing de ticket para el botón Ver mi ticket.
 */
add_action('admin_post_nopriv_eventosapp_whatsapp_ticket_landing', 'eventosapp_whatsapp_templates_render_public_ticket_landing');
add_action('admin_post_eventosapp_whatsapp_ticket_landing', 'eventosapp_whatsapp_templates_render_public_ticket_landing');
add_action('template_redirect', 'eventosapp_whatsapp_templates_public_action_router', 0);

/**
 * Router público frontal para los enlaces de WhatsApp.
 * Permite abrir la landing, el ICS y el acceso virtual sin pasar por /wp-admin/.
 */
function eventosapp_whatsapp_templates_public_action_router() {
    if ( is_admin() ) {
        return;
    }

    $action = isset($_GET['eventosapp_whatsapp_public_action']) ? sanitize_key(wp_unslash($_GET['eventosapp_whatsapp_public_action'])) : '';
    if ( $action === '' ) {
        return;
    }

    if ( $action === 'ticket_landing' ) {
        eventosapp_whatsapp_templates_render_public_ticket_landing();
    }

    if ( $action === 'ticket_ics' ) {
        eventosapp_whatsapp_templates_redirect_ticket_ics();
    }

    if ( $action === 'virtual_access' ) {
        eventosapp_whatsapp_templates_redirect_virtual_access();
    }

    status_header(404);
    wp_die('Acción pública de WhatsApp no encontrada.');
}

if ( ! function_exists('eventosapp_whatsapp_redirect_to_virtual_target') ) {
    /**
     * Ejecuta la redirección final del acceso virtual de WhatsApp.
     *
     * No usa wp_safe_redirect() porque el destino puede ser una plataforma externa
     * configurada en el evento (Zoom, Meet, Teams, etc.). wp_safe_redirect() bloquearía
     * esos dominios y enviaría al fallback de WordPress, haciendo que los botones de
     * plantillas WhatsApp no respeten la configuración de landing/plataforma directa.
     */
    function eventosapp_whatsapp_redirect_to_virtual_target($target) {
        $target = esc_url_raw((string) $target);

        if ( $target === '' ) {
            status_header(404);
            wp_die('No se encontró enlace virtual para este ticket.');
        }

        nocache_headers();
        wp_redirect($target, 302, 'EventosApp WhatsApp');
        exit;
    }
}

function eventosapp_whatsapp_templates_render_public_ticket_landing() {
    $ticket_id = eventosapp_whatsapp_templates_resolve_ticket_from_request();
    if ( ! $ticket_id ) {
        status_header(404);
        wp_die('Ticket no encontrado.');
    }

    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    $assets = eventosapp_whatsapp_templates_get_ticket_assets($ticket_id);
    $nombre = trim(get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true) . ' ' . get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true));
    $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
    $modalidad = function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label($ticket_id) : get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);
    $is_virtual = function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id);

    // Para tickets virtuales, cualquier botón genérico "Ver mi ticket" debe respetar
    // la configuración del evento: landing de EventosApp activa o plataforma directa.
    if ( $is_virtual && ! empty($assets['virtual_access_url']) ) {
        eventosapp_whatsapp_redirect_to_virtual_target($assets['virtual_access_url']);
    }

    $fecha = function_exists('eventosapp_whatsapp_get_event_date_label') ? eventosapp_whatsapp_get_event_date_label($event_id) : '';
    $hora_inicio = $event_id ? get_post_meta($event_id, '_eventosapp_hora_inicio', true) : '';
    $hora_cierre = $event_id ? get_post_meta($event_id, '_eventosapp_hora_cierre', true) : '';
    $direccion = $event_id ? get_post_meta($event_id, '_eventosapp_direccion', true) : '';
    $organizador = $event_id ? (function_exists('eventosapp_get_nombre_organizador') ? eventosapp_get_nombre_organizador($event_id) : get_post_meta($event_id, '_eventosapp_organizador', true)) : '';
    $platform = $event_id ? get_post_meta($event_id, '_eventosapp_virtual_platform', true) : '';
    $event_title = $event_id ? get_the_title($event_id) : 'Ticket';

    $wallet_google_img = function_exists('eventosapp_asset_url_with_version') ? eventosapp_asset_url_with_version('assets/graphics/wallet_icons/google_wallet_btn.png') : '';
    $wallet_apple_img  = function_exists('eventosapp_asset_url_with_version') ? eventosapp_asset_url_with_version('assets/graphics/wallet_icons/apple_wallet_btn.png') : '';

    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow', true);
    header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
    ?>
    <!doctype html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex,nofollow">
        <title><?php echo esc_html($event_title); ?> - Ticket</title>
        <style>
            body{margin:0;background:#eef2f7;color:#111827;font-family:Arial,Helvetica,sans-serif;}
            .evapp-ticket-wrap{max-width:760px;margin:0 auto;padding:28px 16px;box-sizing:border-box;}
            .evapp-ticket-card{background:#fff;border-radius:20px;box-shadow:0 14px 42px rgba(15,23,42,.11);overflow:hidden;border:1px solid #e5e7eb;}
            .evapp-ticket-header{background:#0f172a;}
            .evapp-ticket-header img{display:block;width:100%;height:auto;max-height:190px;object-fit:cover;}
            .evapp-ticket-body{padding:26px 28px 8px;}
            .evapp-ticket-title{margin:0 0 8px;font-size:28px;line-height:1.18;color:#111827;}
            .evapp-ticket-subtitle{margin:0 0 18px;color:#64748b;font-size:15px;line-height:1.45;}
            .evapp-ticket-media{text-align:center;margin:18px 0 22px;}
            .evapp-ticket-media img{max-width:330px;width:100%;height:auto;border:1px solid #e5e7eb;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.08);}
            .evapp-ticket-kvs{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:14px 16px;margin:16px 0;}
            .evapp-ticket-kv{display:flex;gap:10px;padding:7px 0;border-bottom:1px solid rgba(226,232,240,.9);line-height:1.45;}
            .evapp-ticket-kv:last-child{border-bottom:0;}
            .evapp-ticket-kv b{min-width:120px;color:#0f172a;}
            .evapp-ticket-actions{padding:20px 28px 28px;background:#f8fafc;border-top:1px solid #e5e7eb;text-align:center;}
            .evapp-ticket-wallets{display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;margin:0 0 14px;}
            .evapp-ticket-wallets img{display:block;width:200px;max-width:100%;height:auto;}
            .evapp-ticket-buttons{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;}
            .evapp-ticket-button{display:inline-block;text-align:center;text-decoration:none;background:#111827;color:#fff!important;padding:13px 18px;border-radius:12px;font-weight:700;min-width:170px;box-sizing:border-box;}
            .evapp-ticket-button.secondary{background:#2563eb;}
            .evapp-ticket-button.neutral{background:#475569;}
            .evapp-ticket-button.success{background:#16a34a;}
            .evapp-ticket-small{font-size:12px;color:#64748b;margin:16px 0 0;text-align:center;line-height:1.4;}
            @media(max-width:520px){.evapp-ticket-body{padding:22px 18px 8px}.evapp-ticket-actions{padding:18px}.evapp-ticket-title{font-size:24px}.evapp-ticket-kv{display:block}.evapp-ticket-kv b{display:block;margin-bottom:2px}.evapp-ticket-button{width:100%;}.evapp-ticket-wallets a{width:100%;display:flex;justify-content:center;}}
        </style>
    </head>
    <body>
        <main class="evapp-ticket-wrap">
            <section class="evapp-ticket-card">
                <?php if ( ! empty($assets['landing_header']) ) : ?>
                    <div class="evapp-ticket-header"><img src="<?php echo esc_url($assets['landing_header']); ?>" alt="<?php echo esc_attr($event_title); ?>"></div>
                <?php endif; ?>

                <div class="evapp-ticket-body">
                    <h1 class="evapp-ticket-title"><?php echo esc_html($event_title); ?></h1>
                    <p class="evapp-ticket-subtitle">Tu inscripción está confirmada. Conserva esta página para consultar los enlaces principales del ticket.</p>

                    <?php if ( ! empty($assets['qr']) && ! $is_virtual ) : ?>
                        <div class="evapp-ticket-media">
                            <img src="<?php echo esc_url($assets['qr']); ?>" alt="QR de ingreso">
                        </div>
                    <?php endif; ?>

                    <div class="evapp-ticket-kvs">
                        <?php if ( $nombre ) : ?><div class="evapp-ticket-kv"><b>Asistente:</b><span><?php echo esc_html($nombre); ?></span></div><?php endif; ?>
                        <?php if ( $ticket_code ) : ?><div class="evapp-ticket-kv"><b>Ticket:</b><span><?php echo esc_html($ticket_code); ?></span></div><?php endif; ?>
                        <?php if ( $organizador ) : ?><div class="evapp-ticket-kv"><b>Organizador:</b><span><?php echo esc_html($organizador); ?></span></div><?php endif; ?>
                        <?php if ( $modalidad ) : ?><div class="evapp-ticket-kv"><b>Modalidad:</b><span><?php echo esc_html($modalidad); ?></span></div><?php endif; ?>
                        <?php if ( $fecha ) : ?><div class="evapp-ticket-kv"><b>Fecha:</b><span><?php echo esc_html($fecha); ?></span></div><?php endif; ?>
                        <?php if ( $hora_inicio ) : ?><div class="evapp-ticket-kv"><b>Hora:</b><span><?php echo esc_html($hora_inicio . ($hora_cierre ? ' - ' . $hora_cierre : '')); ?></span></div><?php endif; ?>
                        <?php if ( $is_virtual && $platform ) : ?><div class="evapp-ticket-kv"><b>Plataforma:</b><span><?php echo esc_html($platform); ?></span></div><?php endif; ?>
                        <?php if ( ! $is_virtual && $direccion ) : ?><div class="evapp-ticket-kv"><b>Lugar:</b><span><?php echo esc_html($direccion); ?></span></div><?php endif; ?>
                    </div>
                </div>

                <div class="evapp-ticket-actions">
                    <?php if ( ! $is_virtual && ( ! empty($assets['google_wallet']) || ! empty($assets['apple_wallet']) ) ) : ?>
                        <div class="evapp-ticket-wallets">
                            <?php if ( ! empty($assets['google_wallet']) ) : ?>
                                <a href="<?php echo esc_url($assets['google_wallet']); ?>" target="_blank" rel="noopener noreferrer" aria-label="Agregar a Google Wallet">
                                    <?php if ( $wallet_google_img ) : ?><img src="<?php echo esc_url($wallet_google_img); ?>" alt="Agregar a Google Wallet"><?php else : ?>Agregar a Google Wallet<?php endif; ?>
                                </a>
                            <?php endif; ?>
                            <?php if ( ! empty($assets['apple_wallet']) ) : ?>
                                <a href="<?php echo esc_url($assets['apple_wallet']); ?>" target="_blank" rel="noopener noreferrer" aria-label="Agregar a Apple Wallet">
                                    <?php if ( $wallet_apple_img ) : ?><img src="<?php echo esc_url($wallet_apple_img); ?>" alt="Agregar a Apple Wallet"><?php else : ?>Agregar a Apple Wallet<?php endif; ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="evapp-ticket-buttons">
                        <?php if ( ! empty($assets['virtual_access_url']) && $is_virtual ) : ?><a class="evapp-ticket-button success" href="<?php echo esc_url($assets['virtual_access_url']); ?>" target="_blank" rel="noopener noreferrer">Ingresar al evento virtual</a><?php endif; ?>
                        <?php if ( ! empty($assets['ics']) ) : ?><a class="evapp-ticket-button secondary" href="<?php echo esc_url($assets['ics']); ?>" target="_blank" rel="noopener noreferrer">Agregar a agenda</a><?php endif; ?>
                        <?php if ( ! empty($assets['pdf']) ) : ?><a class="evapp-ticket-button neutral" href="<?php echo esc_url($assets['pdf']); ?>" target="_blank" rel="noopener noreferrer">Descargar PDF</a><?php endif; ?>
                    </div>

                    <p class="evapp-ticket-small">Este enlace pertenece a EventosApp. No compartas tu ticket con terceros.</p>
                </div>
            </section>
        </main>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Redirige al ICS desde botón WhatsApp.
 */
add_action('admin_post_nopriv_eventosapp_whatsapp_ticket_ics', 'eventosapp_whatsapp_templates_redirect_ticket_ics');
add_action('admin_post_eventosapp_whatsapp_ticket_ics', 'eventosapp_whatsapp_templates_redirect_ticket_ics');

function eventosapp_whatsapp_templates_redirect_ticket_ics() {
    $ticket_id = eventosapp_whatsapp_templates_resolve_ticket_from_request();
    if ( ! $ticket_id ) {
        status_header(404);
        wp_die('Ticket no encontrado.');
    }

    $assets = eventosapp_whatsapp_templates_get_ticket_assets($ticket_id);
    if ( empty($assets['ics']) ) {
        status_header(404);
        wp_die('No se encontró archivo ICS para este ticket.');
    }

    wp_safe_redirect($assets['ics']);
    exit;
}

/**
 * Redirige a acceso virtual desde botón WhatsApp.
 */
add_action('admin_post_nopriv_eventosapp_whatsapp_virtual_access', 'eventosapp_whatsapp_templates_redirect_virtual_access');
add_action('admin_post_eventosapp_whatsapp_virtual_access', 'eventosapp_whatsapp_templates_redirect_virtual_access');

function eventosapp_whatsapp_templates_redirect_virtual_access() {
    $ticket_id = eventosapp_whatsapp_templates_resolve_ticket_from_request();
    if ( ! $ticket_id ) {
        status_header(404);
        wp_die('Ticket no encontrado.');
    }

    $assets = eventosapp_whatsapp_templates_get_ticket_assets($ticket_id);
    $target = ! empty($assets['virtual_access_url']) ? $assets['virtual_access_url'] : $assets['platform_url'];

    eventosapp_whatsapp_redirect_to_virtual_target($target);
}
