<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Metabox: Configuración de Escarapela (por evento)
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'eventosapp_badge_settings',
        __('Configuración de Escarapela', 'eventosapp'),
        'eventosapp_render_badge_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
});

/**
 * Obtiene todos los campos disponibles para un evento (básicos + extras).
 */
function eventosapp_get_all_available_fields($evento_id = 0) {
    // Campos básicos del ticket.
    $basic_fields = [
        'none'           => __('Ninguno', 'eventosapp'),
        'full_name'      => __('Nombres + Apellidos', 'eventosapp'),
        'nombre'         => __('Nombre', 'eventosapp'),
        'apellido'       => __('Apellido', 'eventosapp'),
        'company'        => __('Nombre de la Empresa', 'eventosapp'),
        'designation'    => __('Cargo', 'eventosapp'),
        'cc_id'          => __('CC_ID', 'eventosapp'),
        'email'          => __('Email', 'eventosapp'),
        'telefono'       => __('Teléfono', 'eventosapp'),
        'nit'            => __('NIT', 'eventosapp'),
        'ciudad'         => __('Ciudad', 'eventosapp'),
        'pais'           => __('País', 'eventosapp'),
        'localidad'      => __('Localidad', 'eventosapp'),
        'qr'             => __('QR', 'eventosapp'),
        'qr_networking'  => __('QR Networking', 'eventosapp'),
    ];

    // Si hay un evento, agregar sus campos adicionales.
    if ($evento_id && function_exists('eventosapp_get_event_extra_fields')) {
        $extra_fields = eventosapp_get_event_extra_fields($evento_id);
        if (!empty($extra_fields)) {
            foreach ($extra_fields as $field) {
                if (empty($field['key']) || empty($field['label'])) {
                    continue;
                }
                $key = 'extra_' . sanitize_key($field['key']);
                $basic_fields[$key] = $field['label'] . ' (campo adicional)';
            }
        }
    }

    return $basic_fields;
}

/**
 * Detecta los campos del mapeo que deben renderizarse como imagen QR.
 */
function eventosapp_badge_is_qr_field($field) {
    return in_array((string) $field, ['qr', 'qr_networking'], true);
}


/**
 * Convierte píxeles CSS heredados a milímetros físicos para mantener compatibilidad.
 * 96 CSS px = 25.4 mm según la referencia CSS usada por navegadores modernos.
 */
function eventosapp_badge_px_to_mm($px) {
    $px = (float) $px;
    if ($px <= 0) {
        return 0.0;
    }
    return round(($px * 25.4) / 96, 2);
}

/**
 * Sanitiza números decimales de milímetros sin depender de locale.
 */
function eventosapp_badge_sanitize_mm($value, $default = 0, $min = 0, $max = 1000) {
    if (is_string($value)) {
        $value = str_replace(',', '.', $value);
    }

    $number = is_numeric($value) ? (float) $value : (float) $default;
    if ($number < $min) {
        $number = (float) $min;
    }
    if ($number > $max) {
        $number = (float) $max;
    }

    return round($number, 2);
}

/**
 * Formatea milímetros para CSS evitando comas o decimales innecesarios.
 */
function eventosapp_badge_format_mm($value) {
    $value = eventosapp_badge_sanitize_mm($value, 0, 0, 1000);
    $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    return $formatted === '' ? '0' : $formatted;
}

/**
 * Plantillas base disponibles aunque aún no existan plantillas guardadas.
 */
function eventosapp_badge_default_paper_templates() {
    return [
        'legacy_standard' => [
            'key'         => 'legacy_standard',
            'name'        => __('Estándar heredado 99 × 55 mm', 'eventosapp'),
            'width_mm'    => 99,
            'height_mm'   => 55,
            'margin_mm'   => 2,
            'orientation' => 'landscape',
            'locked'      => true,
        ],
        'badge_100x140' => [
            'key'         => 'badge_100x140',
            'name'        => __('Escarapela vertical 100 × 140 mm', 'eventosapp'),
            'width_mm'    => 100,
            'height_mm'   => 140,
            'margin_mm'   => 3,
            'orientation' => 'portrait',
            'locked'      => true,
        ],
        'badge_90x130' => [
            'key'         => 'badge_90x130',
            'name'        => __('Escarapela vertical 90 × 130 mm', 'eventosapp'),
            'width_mm'    => 90,
            'height_mm'   => 130,
            'margin_mm'   => 3,
            'orientation' => 'portrait',
            'locked'      => true,
        ],
        'card_86x54' => [
            'key'         => 'card_86x54',
            'name'        => __('Tarjeta PVC 86 × 54 mm', 'eventosapp'),
            'width_mm'    => 86,
            'height_mm'   => 54,
            'margin_mm'   => 2,
            'orientation' => 'landscape',
            'locked'      => true,
        ],
        'wristband_250x25' => [
            'key'         => 'wristband_250x25',
            'name'        => __('Manilla 250 × 25 mm', 'eventosapp'),
            'width_mm'    => 250,
            'height_mm'   => 25,
            'margin_mm'   => 2,
            'orientation' => 'landscape',
            'locked'      => true,
        ],
    ];
}

/**
 * Normaliza una plantilla para evitar medidas inválidas o datos incompletos.
 */
function eventosapp_badge_normalize_paper_template($template, $fallback_key = '') {
    if (!is_array($template)) {
        $template = [];
    }

    $key = !empty($template['key']) ? sanitize_key($template['key']) : sanitize_key($fallback_key);
    if ($key === '') {
        $key = 'template_' . wp_generate_password(8, false, false);
    }

    $name = !empty($template['name']) ? sanitize_text_field($template['name']) : __('Formato sin nombre', 'eventosapp');
    $width = eventosapp_badge_sanitize_mm($template['width_mm'] ?? 99, 99, 10, 1000);
    $height = eventosapp_badge_sanitize_mm($template['height_mm'] ?? 55, 55, 10, 1000);
    $max_margin = max(0, min($width, $height) / 2 - 0.5);
    $margin = eventosapp_badge_sanitize_mm($template['margin_mm'] ?? 0, 0, 0, $max_margin);
    $orientation = sanitize_key($template['orientation'] ?? (($height >= $width) ? 'portrait' : 'landscape'));
    if (!in_array($orientation, ['portrait', 'landscape'], true)) {
        $orientation = ($height >= $width) ? 'portrait' : 'landscape';
    }

    return [
        'key'         => $key,
        'name'        => $name,
        'width_mm'    => $width,
        'height_mm'   => $height,
        'margin_mm'   => $margin,
        'orientation' => $orientation,
        'locked'      => !empty($template['locked']),
    ];
}

/**
 * Devuelve plantillas de papel base + plantillas guardadas por el administrador.
 */
function eventosapp_badge_get_paper_templates() {
    $templates = [];

    foreach (eventosapp_badge_default_paper_templates() as $key => $template) {
        $templates[$key] = eventosapp_badge_normalize_paper_template($template, $key);
        $templates[$key]['locked'] = true;
    }

    $saved = get_option('eventosapp_badge_paper_templates', []);
    if (is_array($saved)) {
        foreach ($saved as $key => $template) {
            $normalized = eventosapp_badge_normalize_paper_template($template, $key);
            $templates[$normalized['key']] = $normalized;
        }
    }

    return $templates;
}

/**
 * Obtiene una plantilla heredada desde las medidas viejas del evento cuando existían.
 */
function eventosapp_badge_get_legacy_event_template($evento_id) {
    $evento_id = absint($evento_id);
    $width_px  = $evento_id ? get_post_meta($evento_id, 'eventosapp_badge_width', true) : '';
    $height_px = $evento_id ? get_post_meta($evento_id, 'eventosapp_badge_height', true) : '';

    $width_px  = ($width_px === '' || $width_px === null) ? 374 : absint($width_px);
    $height_px = ($height_px === '' || $height_px === null) ? 208 : absint($height_px);

    $width_mm  = eventosapp_badge_px_to_mm($width_px ?: 374);
    $height_mm = eventosapp_badge_px_to_mm($height_px ?: 208);

    return eventosapp_badge_normalize_paper_template([
        'key'         => 'legacy_event',
        'name'        => sprintf(__('Medida heredada de este evento %s × %s mm', 'eventosapp'), eventosapp_badge_format_mm($width_mm), eventosapp_badge_format_mm($height_mm)),
        'width_mm'    => $width_mm,
        'height_mm'   => $height_mm,
        'margin_mm'   => 2,
        'orientation' => ($height_mm >= $width_mm) ? 'portrait' : 'landscape',
        'locked'      => true,
    ], 'legacy_event');
}

/**
 * Resuelve la plantilla seleccionada del evento con fallback compatible.
 */
function eventosapp_badge_get_selected_paper_template($evento_id) {
    $evento_id = absint($evento_id);
    $selected_key = $evento_id ? sanitize_key(get_post_meta($evento_id, 'eventosapp_badge_paper_template', true)) : '';

    if ($selected_key === 'legacy_event') {
        return eventosapp_badge_get_legacy_event_template($evento_id);
    }

    $templates = eventosapp_badge_get_paper_templates();
    if ($selected_key !== '' && isset($templates[$selected_key])) {
        return $templates[$selected_key];
    }

    $legacy_width  = $evento_id ? get_post_meta($evento_id, 'eventosapp_badge_width', true) : '';
    $legacy_height = $evento_id ? get_post_meta($evento_id, 'eventosapp_badge_height', true) : '';
    if (($legacy_width !== '' && $legacy_width !== null) || ($legacy_height !== '' && $legacy_height !== null)) {
        return eventosapp_badge_get_legacy_event_template($evento_id);
    }

    return $templates['legacy_standard'];
}

/**
 * Guarda la biblioteca de plantillas desde el metabox del evento.
 */
function eventosapp_badge_save_paper_templates_from_post() {
    $existing_templates = eventosapp_badge_get_paper_templates();
    $submitted          = isset($_POST['eventosapp_badge_paper_templates']) && is_array($_POST['eventosapp_badge_paper_templates'])
        ? wp_unslash($_POST['eventosapp_badge_paper_templates'])
        : [];
    $delete             = isset($_POST['eventosapp_badge_delete_template']) && is_array($_POST['eventosapp_badge_delete_template'])
        ? array_map('sanitize_key', array_keys(wp_unslash($_POST['eventosapp_badge_delete_template'])))
        : [];

    $templates_to_save = [];
    foreach ($submitted as $key => $template) {
        $key = sanitize_key($key);
        if ($key === '' || $key === 'legacy_event') {
            continue;
        }

        $was_locked = !empty($existing_templates[$key]['locked']);
        if ($was_locked && in_array($key, $delete, true)) {
            // Las plantillas base no se eliminan para evitar que eventos existentes queden sin formato.
            $delete = array_diff($delete, [$key]);
        }

        if (!$was_locked && in_array($key, $delete, true)) {
            continue;
        }

        $normalized = eventosapp_badge_normalize_paper_template($template, $key);
        $normalized['locked'] = $was_locked;
        $templates_to_save[$normalized['key']] = $normalized;
    }

    if (!empty($_POST['eventosapp_badge_new_template_name'])) {
        $new_name = sanitize_text_field(wp_unslash($_POST['eventosapp_badge_new_template_name']));
        $base_key = sanitize_key(sanitize_title($new_name));
        if ($base_key === '') {
            $base_key = 'plantilla';
        }

        $new_key = $base_key;
        $suffix = 2;
        while (isset($templates_to_save[$new_key]) || isset($existing_templates[$new_key])) {
            $new_key = $base_key . '_' . $suffix;
            $suffix++;
        }

        $new_template = eventosapp_badge_normalize_paper_template([
            'key'         => $new_key,
            'name'        => $new_name,
            'width_mm'    => $_POST['eventosapp_badge_new_template_width_mm'] ?? 99,
            'height_mm'   => $_POST['eventosapp_badge_new_template_height_mm'] ?? 55,
            'margin_mm'   => $_POST['eventosapp_badge_new_template_margin_mm'] ?? 2,
            'orientation' => $_POST['eventosapp_badge_new_template_orientation'] ?? 'landscape',
            'locked'      => false,
        ], $new_key);
        $new_template['locked'] = false;
        $templates_to_save[$new_template['key']] = $new_template;
    }

    /*
     * Guardamos incluso las plantillas base editadas para permitir ajustar márgenes
     * y medidas desde la biblioteca sin tocar los campos del evento.
     */
    update_option('eventosapp_badge_paper_templates', $templates_to_save, false);
}

/**
 * Obtiene la instancia actual del QR Manager sin depender de un singleton.
 */
function eventosapp_badge_get_qr_manager_instance() {
    global $eventosapp_qr_manager_instance;

    if (isset($eventosapp_qr_manager_instance) && $eventosapp_qr_manager_instance instanceof EventosApp_QR_Manager) {
        return $eventosapp_qr_manager_instance;
    }

    if (class_exists('EventosApp_QR_Manager')) {
        $eventosapp_qr_manager_instance = new EventosApp_QR_Manager();
        return $eventosapp_qr_manager_instance;
    }

    return null;
}

/**
 * Lee la URL pública de la imagen PNG de un QR guardado en _eventosapp_qr_codes.
 */
function eventosapp_badge_read_stored_qr_image_url($ticket_id, $type = 'badge') {
    $ticket_id = absint($ticket_id);
    $type      = sanitize_key($type);

    if (!$ticket_id || $type === '') {
        return '';
    }

    $all_qr_codes = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
    if (!is_array($all_qr_codes) || empty($all_qr_codes[$type]) || !is_array($all_qr_codes[$type])) {
        return '';
    }

    $qr_data = $all_qr_codes[$type];
    if (empty($qr_data['url'])) {
        return '';
    }

    $qr_url  = (string) $qr_data['url'];
    $qr_path = !empty($qr_data['path']) ? (string) $qr_data['path'] : '';

    if ($qr_path !== '' && !file_exists($qr_path)) {
        return '';
    }

    if ($qr_path !== '' && file_exists($qr_path)) {
        $separator = (strpos($qr_url, '?') === false) ? '?' : '&';
        return $qr_url . $separator . 'v=' . filemtime($qr_path);
    }

    return $qr_url;
}

/**
 * Obtiene el QR de networking/escarapela generado por el QR Manager.
 *
 * Este QR corresponde al tipo interno "badge" del QR Manager y codifica una URL
 * hacia /networking/global con event + ticketid + código de seguridad.
 */
function eventosapp_get_ticket_networking_qr_url($ticket_id) {
    $ticket_id = absint($ticket_id);
    if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
        return '';
    }

    $existing_url = eventosapp_badge_read_stored_qr_image_url($ticket_id, 'badge');
    if ($existing_url !== '') {
        return $existing_url;
    }

    $manager = eventosapp_badge_get_qr_manager_instance();
    if ($manager && method_exists($manager, 'generate_qr_code')) {
        $generated = $manager->generate_qr_code($ticket_id, 'badge');
        if (is_array($generated) && !empty($generated['url'])) {
            $qr_url  = (string) $generated['url'];
            $qr_path = !empty($generated['path']) ? (string) $generated['path'] : '';
            if ($qr_path !== '' && file_exists($qr_path)) {
                $separator = (strpos($qr_url, '?') === false) ? '?' : '&';
                return $qr_url . $separator . 'v=' . filemtime($qr_path);
            }
            return $qr_url;
        }
    }

    return eventosapp_badge_read_stored_qr_image_url($ticket_id, 'badge');
}

/**
 * Busca un ticket por su ID público eventosapp_ticketID.
 */
function eventosapp_badge_find_ticket_by_public_key($ticket_key) {
    $ticket_key = trim((string) $ticket_key);
    if ($ticket_key === '') {
        return 0;
    }

    $found = get_posts([
        'post_type'   => 'eventosapp_ticket',
        'post_status' => 'any',
        'numberposts' => 1,
        'fields'      => 'ids',
        'meta_key'    => 'eventosapp_ticketID',
        'meta_value'  => $ticket_key,
    ]);

    return !empty($found) ? absint($found[0]) : 0;
}

/**
 * Resuelve el evento desde post_id/event_id/evento_id/event o desde el ticket.
 */
function eventosapp_badge_resolve_event_id($ticket_id = 0) {
    $event_candidates = ['post_id', 'event_id', 'evento_id', 'event'];

    foreach ($event_candidates as $key) {
        if (!isset($_GET[$key])) {
            continue;
        }

        $raw = sanitize_text_field(wp_unslash($_GET[$key]));
        if ($raw === '') {
            continue;
        }

        if (preg_match('/^(\d+)/', $raw, $matches)) {
            $event_id = absint($matches[1]);
            if ($event_id) {
                return $event_id;
            }
        }
    }

    $ticket_id = absint($ticket_id);
    if ($ticket_id) {
        $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        if ($event_id) {
            return $event_id;
        }
    }

    return 0;
}

/**
 * Resuelve el ticket recibido por ID numérico o por ID público tk...
 */
function eventosapp_badge_resolve_ticket_id() {
    $ticket_id_raw = isset($_GET['ticket_id']) ? sanitize_text_field(wp_unslash($_GET['ticket_id'])) : '';
    $ticket_id     = absint($ticket_id_raw);

    if ($ticket_id && get_post_type($ticket_id) === 'eventosapp_ticket') {
        return $ticket_id;
    }

    if ($ticket_id_raw !== '') {
        $found_by_public = eventosapp_badge_find_ticket_by_public_key($ticket_id_raw);
        if ($found_by_public) {
            return $found_by_public;
        }
    }

    $ticket_key = isset($_GET['ticket_key']) ? sanitize_text_field(wp_unslash($_GET['ticket_key'])) : '';
    if ($ticket_key !== '') {
        return eventosapp_badge_find_ticket_by_public_key($ticket_key);
    }

    return 0;
}

/**
 * Arma los valores que se pueden imprimir en la escarapela.
 */
function eventosapp_badge_build_labels($evento_id, $ticket_id = 0) {
    $evento_id = absint($evento_id);
    $ticket_id = absint($ticket_id);

    $labels = [
        'full_name'      => 'Nombres + Apellidos',
        'nombre'         => 'Nombre',
        'apellido'       => 'Apellido',
        'company'        => 'Nombre de la Empresa',
        'designation'    => 'Cargo',
        'cc_id'          => 'CC_ID',
        'email'          => 'Email',
        'telefono'       => 'Teléfono',
        'nit'            => 'NIT',
        'ciudad'         => 'Ciudad',
        'pais'           => 'País',
        'localidad'      => 'Localidad',
        'qr'             => '',
        'qr_networking'  => '',
    ];

    if ($ticket_id && get_post_type($ticket_id) === 'eventosapp_ticket') {
        $nombre    = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
        $apell     = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
        $empresa   = get_post_meta($ticket_id, '_eventosapp_asistente_empresa', true);
        $cargo     = get_post_meta($ticket_id, '_eventosapp_asistente_cargo', true);
        $cc        = get_post_meta($ticket_id, '_eventosapp_asistente_cc', true);
        $email     = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
        $tel       = get_post_meta($ticket_id, '_eventosapp_asistente_tel', true);
        $nit       = get_post_meta($ticket_id, '_eventosapp_asistente_nit', true);
        $ciudad    = get_post_meta($ticket_id, '_eventosapp_asistente_ciudad', true);
        $pais      = get_post_meta($ticket_id, '_eventosapp_asistente_pais', true);
        $localidad = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);
        $code      = get_post_meta($ticket_id, 'eventosapp_ticketID', true);

        $labels['full_name']   = trim($nombre . ' ' . $apell) ?: $labels['full_name'];
        $labels['nombre']      = $nombre    ?: $labels['nombre'];
        $labels['apellido']    = $apell     ?: $labels['apellido'];
        $labels['company']     = $empresa   ?: $labels['company'];
        $labels['designation'] = $cargo     ?: $labels['designation'];
        $labels['cc_id']       = $cc        ?: $labels['cc_id'];
        $labels['email']       = $email     ?: $labels['email'];
        $labels['telefono']    = $tel       ?: $labels['telefono'];
        $labels['nit']         = $nit       ?: $labels['nit'];
        $labels['ciudad']      = $ciudad    ?: $labels['ciudad'];
        $labels['pais']        = $pais      ?: $labels['pais'];
        $labels['localidad']   = $localidad ?: $labels['localidad'];

        // QR existente / legacy del ticket. Se conserva sin cambios.
        if ($code && function_exists('eventosapp_get_ticket_qr_url')) {
            $labels['qr'] = eventosapp_get_ticket_qr_url($code);
        }

        // QR Networking: imagen PNG del QR tipo badge que apunta a /networking/global.
        $labels['qr_networking'] = eventosapp_get_ticket_networking_qr_url($ticket_id);
    }

    if ($evento_id && function_exists('eventosapp_get_event_extra_fields')) {
        $extra_fields = eventosapp_get_event_extra_fields($evento_id);
        if (!empty($extra_fields)) {
            foreach ($extra_fields as $field) {
                if (empty($field['key']) || empty($field['label'])) {
                    continue;
                }
                $key = 'extra_' . sanitize_key($field['key']);
                $value = ($ticket_id && get_post_type($ticket_id) === 'eventosapp_ticket')
                    ? get_post_meta($ticket_id, '_eventosapp_extra_' . sanitize_key($field['key']), true)
                    : '';
                $labels[$key] = $value ?: $field['label'];
            }
        }
    }

    return $labels;
}

/**
 * Renderiza un campo de la escarapela como texto o como imagen QR según corresponda.
 */
function eventosapp_badge_render_field_slot($field, $labels, $font_size, $font_weight, $margin, $qr_size) {
    $field       = (string) $field;
    $font_size   = absint($font_size);
    $font_weight = absint($font_weight);
    $margin      = absint($margin);
    $qr_size     = absint($qr_size);

    if (eventosapp_badge_is_qr_field($field)) {
        $qr_src = isset($labels[$field]) ? (string) $labels[$field] : '';
        if ($qr_src !== '') {
            $alt = ($field === 'qr_networking') ? __('QR Networking', 'eventosapp') : __('QR', 'eventosapp');
            echo "<div class='slot slot-qr slot-" . esc_attr($field) . "' style='margin:" . $margin . "px'><img src='" . esc_url($qr_src) . "' width='" . $qr_size . "' height='" . $qr_size . "' alt='" . esc_attr($alt) . "'></div>";
            return;
        }

        echo "<div class='slot slot-qr-empty slot-" . esc_attr($field) . "' style='margin:" . $margin . "px'></div>";
        return;
    }

    $label_text = isset($labels[$field]) ? $labels[$field] : '';
    echo "<div class='slot' style='margin:" . $margin . "px; font-size:" . $font_size . "px; font-weight:" . $font_weight . ";'>" . esc_html($label_text) . "</div>";
}

/**
 * Render del metabox.
 */
function eventosapp_render_badge_metabox($post) {
    wp_nonce_field('eventosapp_save_badge_settings', 'eventosapp_badge_nonce');

    // Valores guardados (con defaults).
    $design   = get_post_meta($post->ID, 'eventosapp_badge_design', true) ?: 'manillas';

    $order_fields = [];
    for ($i=1; $i<=5; $i++){
        $order_fields[$i] = get_post_meta($post->ID, "eventosapp_field_order_{$i}", true) ?: 'none';
    }

    $paper_templates  = eventosapp_badge_get_paper_templates();
    $legacy_template  = eventosapp_badge_get_legacy_event_template($post->ID);
    $selected_key     = sanitize_key(get_post_meta($post->ID, 'eventosapp_badge_paper_template', true));
    $selected_template = eventosapp_badge_get_selected_paper_template($post->ID);

    if ($selected_key === '' && $selected_template['key'] === 'legacy_event') {
        $selected_key = 'legacy_event';
    } elseif ($selected_key === '') {
        $selected_key = $selected_template['key'];
    }

    if ($selected_template['key'] === 'legacy_event') {
        $paper_templates = array_merge(['legacy_event' => $legacy_template], $paper_templates);
    }

    $size_large     = (int) (get_post_meta($post->ID, 'eventosapp_badge_size_large', true) ?: 24);
    $size_medium    = (int) (get_post_meta($post->ID, 'eventosapp_badge_size_medium', true) ?: 18);
    $size_small     = (int) (get_post_meta($post->ID, 'eventosapp_badge_size_small', true) ?: 14);
    $weight_large   = (int) (get_post_meta($post->ID, 'eventosapp_badge_weight_large', true) ?: 600);
    $weight_medium  = (int) (get_post_meta($post->ID, 'eventosapp_badge_weight_medium', true) ?: 500);
    $weight_small   = (int) (get_post_meta($post->ID, 'eventosapp_badge_weight_small', true) ?: 400);
    $sep_vertical   = (int) (get_post_meta($post->ID, 'eventosapp_badge_sep_vertical', true) ?: 4);
    $sep_horizontal = (int) (get_post_meta($post->ID, 'eventosapp_badge_sep_horizontal', true) ?: 4);
    $qr_size        = (int) (get_post_meta($post->ID, 'eventosapp_badge_qr_size', true) ?: 72);
    $border_width   = get_post_meta($post->ID, 'eventosapp_badge_border_width', true);
    if ($border_width === '' || $border_width === null) $border_width = 0;
    $border_width   = (int) $border_width;

    // Campo para vista previa con el ID público del ticket (tk...).
    $preview_ticket_key = get_post_meta($post->ID, 'eventosapp_badge_ticket_key', true) ?: '';

    // Obtener todos los campos disponibles (básicos + extras del evento).
    $options = eventosapp_get_all_available_fields($post->ID);

    $weights = [100,200,300,400,500,600,700,800];
    $design_labels = [
        'manillas' => [
            'title' => __('Manillas / Horizontal', 'eventosapp'),
            'desc'  => __('Campos en línea. Ideal para formatos horizontales.', 'eventosapp'),
        ],
        'escarapelas' => [
            'title' => __('Escarapela vertical', 'eventosapp'),
            'desc'  => __('Campo 1 grande y campos secundarios debajo.', 'eventosapp'),
        ],
        'escarapelas_split' => [
            'title' => __('Split 3 + 1', 'eventosapp'),
            'desc'  => __('Tres campos a la izquierda y uno destacado a la derecha.', 'eventosapp'),
        ],
        'escarapelas_split_4' => [
            'title' => __('Split 4 + 1', 'eventosapp'),
            'desc'  => __('Cuatro campos a la izquierda y uno destacado a la derecha.', 'eventosapp'),
        ],
    ];
    ?>
    <style>
      .evapp-badge-metabox{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1d2327}
      .evapp-badge-intro{background:#f0f6fc;border:1px solid #c5d9ed;border-left:4px solid #2271b1;border-radius:8px;padding:12px 14px;margin:12px 0 16px}
      .evapp-badge-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:0 0 16px}
      .evapp-badge-section{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
      .evapp-badge-section h3{margin:0 0 10px;font-size:15px;line-height:1.3}
      .evapp-badge-section p{margin:6px 0;color:#50575e}
      .evapp-badge-field{margin:12px 0}
      .evapp-badge-field label{font-weight:600;display:block;margin-bottom:5px}
      .evapp-badge-field input[type=text],.evapp-badge-field input[type=number],.evapp-badge-field select{width:100%;max-width:360px}
      .evapp-badge-help{display:block;margin-top:5px;color:#646970;font-size:12px}
      .evapp-design-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
      .evapp-design-card{border:1px solid #dcdcde;border-radius:10px;padding:10px;background:#f6f7f7;cursor:pointer;display:block}
      .evapp-design-card input{margin-right:7px}
      .evapp-design-card:has(input:checked){border-color:#2271b1;background:#f0f6fc;box-shadow:0 0 0 1px #2271b1 inset}
      .evapp-field-map{display:grid;grid-template-columns:repeat(5,minmax(140px,1fr));gap:10px}
      .evapp-field-map label{font-weight:600;font-size:12px;display:block;margin-bottom:4px}
      .evapp-paper-selected{display:grid;grid-template-columns:160px 1fr;gap:14px;align-items:center;margin-top:10px}
      .evapp-paper-preview{width:140px;max-width:100%;background:#fff;border:2px solid #1d2327;border-radius:6px;position:relative;box-shadow:0 8px 18px rgba(0,0,0,.08);overflow:hidden}
      .evapp-paper-preview::before{content:"";display:block;aspect-ratio:var(--paper-ratio,1.6)}
      .evapp-paper-safe{position:absolute;border:1px dashed #2271b1;background:rgba(34,113,177,.06);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:10px;color:#2271b1;text-align:center;padding:3px;box-sizing:border-box}
      .evapp-paper-meta strong{display:block;font-size:14px;margin-bottom:4px}
      .evapp-template-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-top:12px}
      .evapp-template-card{border:1px solid #dcdcde;border-radius:10px;padding:10px;background:#fbfbfc}
      .evapp-template-card .evapp-paper-preview{width:100px;margin-bottom:8px}
      .evapp-template-card input[type=text]{width:100%}
      .evapp-template-card .evapp-template-mini-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:8px}
      .evapp-template-card .evapp-template-mini-grid input{width:100%}
      .evapp-template-card small{display:block;color:#646970;margin-top:6px}
      .evapp-new-template{display:grid;grid-template-columns:2fr repeat(4,1fr);gap:8px;align-items:end;margin-top:12px;padding:12px;border:1px dashed #8c8f94;border-radius:10px;background:#f6f7f7}
      .evapp-new-template label{font-size:12px;font-weight:600;display:block;margin-bottom:4px}
      .evapp-new-template input,.evapp-new-template select{width:100%}
      .evapp-badge-actions{display:flex;gap:10px;align-items:center;margin-top:14px}
      @media (max-width:1100px){.evapp-badge-grid{grid-template-columns:1fr}.evapp-field-map{grid-template-columns:repeat(2,minmax(140px,1fr))}.evapp-new-template{grid-template-columns:1fr 1fr}.evapp-paper-selected{grid-template-columns:1fr}}
      @media (max-width:782px){.evapp-design-options,.evapp-field-map,.evapp-new-template{grid-template-columns:1fr}.evapp-badge-section{padding:12px}}
    </style>

    <div class="evapp-badge-metabox" data-selected-template="<?php echo esc_attr($selected_key); ?>">
      <div class="evapp-badge-intro">
        <strong><?php _e('Formato de papel centralizado por plantillas.', 'eventosapp'); ?></strong>
        <p><?php _e('Los campos directos de ancho y alto ya no se editan por evento. Ahora el tamaño físico de impresión sale del formato de papel seleccionado para evitar diferencias entre el HTML y la impresora.', 'eventosapp'); ?></p>
      </div>

      <div class="evapp-badge-grid">
        <section class="evapp-badge-section">
          <h3><?php _e('1. Formato de papel para impresión', 'eventosapp'); ?></h3>
          <div class="evapp-badge-field">
            <label for="eventosapp_badge_paper_template"><?php _e('Plantilla activa', 'eventosapp'); ?></label>
            <select name="eventosapp_badge_paper_template" id="eventosapp_badge_paper_template">
              <?php foreach ($paper_templates as $key => $template): ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($selected_key, $key); ?>
                        data-name="<?php echo esc_attr($template['name']); ?>"
                        data-width="<?php echo esc_attr(eventosapp_badge_format_mm($template['width_mm'])); ?>"
                        data-height="<?php echo esc_attr(eventosapp_badge_format_mm($template['height_mm'])); ?>"
                        data-margin="<?php echo esc_attr(eventosapp_badge_format_mm($template['margin_mm'])); ?>"
                        data-orientation="<?php echo esc_attr($template['orientation']); ?>">
                  <?php echo esc_html($template['name']); ?> — <?php echo esc_html(eventosapp_badge_format_mm($template['width_mm'])); ?> × <?php echo esc_html(eventosapp_badge_format_mm($template['height_mm'])); ?> mm
                </option>
              <?php endforeach; ?>
            </select>
            <span class="evapp-badge-help"><?php _e('La plantilla define ancho, alto y margen de impresión. La orientación de los elementos se mantiene en “Tipo de diseño”.', 'eventosapp'); ?></span>
          </div>

          <?php
          $ratio = max(0.1, (float) $selected_template['width_mm'] / max(1, (float) $selected_template['height_mm']));
          $left_pct = min(45, max(0, ((float) $selected_template['margin_mm'] / max(1, (float) $selected_template['width_mm'])) * 100));
          $top_pct  = min(45, max(0, ((float) $selected_template['margin_mm'] / max(1, (float) $selected_template['height_mm'])) * 100));
          ?>
          <div class="evapp-paper-selected">
            <div class="evapp-paper-preview" id="evapp_selected_paper_preview" style="--paper-ratio:<?php echo esc_attr($ratio); ?>;">
              <div class="evapp-paper-safe" id="evapp_selected_paper_safe" style="left:<?php echo esc_attr($left_pct); ?>%;right:<?php echo esc_attr($left_pct); ?>%;top:<?php echo esc_attr($top_pct); ?>%;bottom:<?php echo esc_attr($top_pct); ?>%;">
                <?php _e('Área útil', 'eventosapp'); ?>
              </div>
            </div>
            <div class="evapp-paper-meta">
              <strong id="evapp_selected_paper_name"><?php echo esc_html($selected_template['name']); ?></strong>
              <span id="evapp_selected_paper_size"><?php echo esc_html(eventosapp_badge_format_mm($selected_template['width_mm'])); ?> × <?php echo esc_html(eventosapp_badge_format_mm($selected_template['height_mm'])); ?> mm</span><br>
              <span id="evapp_selected_paper_margin"><?php printf(esc_html__('Margen: %s mm', 'eventosapp'), esc_html(eventosapp_badge_format_mm($selected_template['margin_mm']))); ?></span><br>
              <span id="evapp_selected_paper_orientation"><?php echo esc_html($selected_template['orientation'] === 'portrait' ? __('Vertical', 'eventosapp') : __('Horizontal', 'eventosapp')); ?></span>
            </div>
          </div>
        </section>

        <section class="evapp-badge-section">
          <h3><?php _e('2. Vista previa de impresión', 'eventosapp'); ?></h3>
          <div class="evapp-badge-field">
            <label for="eventosapp_badge_ticket_key"><?php _e('Número de ticket (ID público)', 'eventosapp'); ?></label>
            <input type="text"
                   id="eventosapp_badge_ticket_key"
                   name="eventosapp_badge_ticket_key"
                   value="<?php echo esc_attr($preview_ticket_key); ?>"
                   placeholder="p. ej. tkcdG7ejZvDjWAD"
                   pattern="^[A-Za-z0-9_-]{6,}$"
                   title="<?php esc_attr_e('Pegue aquí el ID público del ticket (tk...)', 'eventosapp'); ?>">
            <span class="evapp-badge-help"><?php _e('Opcional. Si lo dejas vacío se abre una vista con textos de muestra.', 'eventosapp'); ?></span>
          </div>
          <div class="evapp-badge-actions">
            <button type="button" class="button button-primary" id="eventosapp_download_badge"><?php _e('Vista previa / Imprimir escarapela', 'eventosapp'); ?></button>
          </div>
          <p><?php _e('Para impresión precisa, usa escala 100% y desactiva “ajustar a página” en el cuadro de impresión cuando el navegador lo muestre.', 'eventosapp'); ?></p>
        </section>
      </div>

      <div class="evapp-badge-grid">
        <section class="evapp-badge-section">
          <h3><?php _e('3. Orientación de elementos', 'eventosapp'); ?></h3>
          <div class="evapp-design-options">
            <?php foreach ($design_labels as $design_key => $design_info): ?>
              <label class="evapp-design-card">
                <input type="radio" name="eventosapp_badge_design" value="<?php echo esc_attr($design_key); ?>" <?php checked($design, $design_key); ?>>
                <strong><?php echo esc_html($design_info['title']); ?></strong>
                <small class="evapp-badge-help"><?php echo esc_html($design_info['desc']); ?></small>
              </label>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="evapp-badge-section">
          <h3><?php _e('4. Mapeo de contenido', 'eventosapp'); ?></h3>
          <div class="evapp-field-map">
            <?php for ($i=1; $i<=5; $i++): ?>
              <div>
                <label for="eventosapp_field_order_<?php echo $i; ?>"><?php printf(__('Campo %d', 'eventosapp'), $i); ?></label>
                <select name="eventosapp_field_order_<?php echo $i; ?>" id="eventosapp_field_order_<?php echo $i; ?>">
                  <?php foreach($options as $val=>$lab): ?>
                    <option value="<?php echo esc_attr($val); ?>" <?php selected($order_fields[$i], $val); ?>><?php echo esc_html($lab); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endfor; ?>
          </div>
        </section>
      </div>

      <section class="evapp-badge-section">
        <h3><?php _e('5. Estilo de elementos internos', 'eventosapp'); ?></h3>
        <div class="evapp-field-map">
          <div>
            <label><?php _e('Tamaño grande', 'eventosapp'); ?></label>
            <input type="number" name="eventosapp_badge_size_large" value="<?php echo esc_attr($size_large); ?>" min="1"> px
            <select name="eventosapp_badge_weight_large">
              <?php foreach($weights as $w) echo '<option value="'.$w.'"'.selected($weight_large,$w,false).'>'.$w.'</option>'; ?>
            </select>
          </div>
          <div>
            <label><?php _e('Tamaño mediano', 'eventosapp'); ?></label>
            <input type="number" name="eventosapp_badge_size_medium" value="<?php echo esc_attr($size_medium); ?>" min="1"> px
            <select name="eventosapp_badge_weight_medium">
              <?php foreach($weights as $w) echo '<option value="'.$w.'"'.selected($weight_medium,$w,false).'>'.$w.'</option>'; ?>
            </select>
          </div>
          <div>
            <label><?php _e('Tamaño pequeño', 'eventosapp'); ?></label>
            <input type="number" name="eventosapp_badge_size_small" value="<?php echo esc_attr($size_small); ?>" min="1"> px
            <select name="eventosapp_badge_weight_small">
              <?php foreach($weights as $w) echo '<option value="'.$w.'"'.selected($weight_small,$w,false).'>'.$w.'</option>'; ?>
            </select>
          </div>
          <div>
            <label><?php _e('Separación vertical', 'eventosapp'); ?></label>
            <input type="number" name="eventosapp_badge_sep_vertical" value="<?php echo esc_attr($sep_vertical); ?>" min="0"> px
          </div>
          <div>
            <label><?php _e('Separación horizontal', 'eventosapp'); ?></label>
            <input type="number" name="eventosapp_badge_sep_horizontal" value="<?php echo esc_attr($sep_horizontal); ?>" min="0"> px
          </div>
          <div>
            <label><?php _e('Tamaño del QR', 'eventosapp'); ?></label>
            <input type="number" name="eventosapp_badge_qr_size" value="<?php echo esc_attr($qr_size); ?>" min="1"> px
          </div>
          <div>
            <label><?php _e('Grosor del borde', 'eventosapp'); ?></label>
            <input type="number" name="eventosapp_badge_border_width" value="<?php echo esc_attr($border_width); ?>" min="0"> px
          </div>
        </div>
      </section>

      <section class="evapp-badge-section" style="margin-top:16px;">
        <h3><?php _e('6. Biblioteca de plantillas de papel', 'eventosapp'); ?></h3>
        <p><?php _e('Crea formatos reutilizables para otros eventos. Estas medidas se usan en @page y en el contenedor HTML para que la vista previa coincida mejor con la impresión física.', 'eventosapp'); ?></p>

        <div class="evapp-template-grid">
          <?php foreach ($paper_templates as $key => $template):
              if ($key === 'legacy_event') {
                  continue;
              }
              $ratio = max(0.1, (float) $template['width_mm'] / max(1, (float) $template['height_mm']));
              $left_pct = min(45, max(0, ((float) $template['margin_mm'] / max(1, (float) $template['width_mm'])) * 100));
              $top_pct  = min(45, max(0, ((float) $template['margin_mm'] / max(1, (float) $template['height_mm'])) * 100));
          ?>
            <div class="evapp-template-card">
              <div class="evapp-paper-preview" style="--paper-ratio:<?php echo esc_attr($ratio); ?>;">
                <div class="evapp-paper-safe" style="left:<?php echo esc_attr($left_pct); ?>%;right:<?php echo esc_attr($left_pct); ?>%;top:<?php echo esc_attr($top_pct); ?>%;bottom:<?php echo esc_attr($top_pct); ?>%;">mm</div>
              </div>
              <input type="hidden" name="eventosapp_badge_paper_templates[<?php echo esc_attr($key); ?>][key]" value="<?php echo esc_attr($key); ?>">
              <label>
                <span class="screen-reader-text"><?php _e('Nombre de plantilla', 'eventosapp'); ?></span>
                <input type="text" name="eventosapp_badge_paper_templates[<?php echo esc_attr($key); ?>][name]" value="<?php echo esc_attr($template['name']); ?>">
              </label>
              <div class="evapp-template-mini-grid">
                <label><?php _e('Ancho', 'eventosapp'); ?><input type="number" step="0.1" min="10" name="eventosapp_badge_paper_templates[<?php echo esc_attr($key); ?>][width_mm]" value="<?php echo esc_attr(eventosapp_badge_format_mm($template['width_mm'])); ?>"></label>
                <label><?php _e('Alto', 'eventosapp'); ?><input type="number" step="0.1" min="10" name="eventosapp_badge_paper_templates[<?php echo esc_attr($key); ?>][height_mm]" value="<?php echo esc_attr(eventosapp_badge_format_mm($template['height_mm'])); ?>"></label>
                <label><?php _e('Margen', 'eventosapp'); ?><input type="number" step="0.1" min="0" name="eventosapp_badge_paper_templates[<?php echo esc_attr($key); ?>][margin_mm]" value="<?php echo esc_attr(eventosapp_badge_format_mm($template['margin_mm'])); ?>"></label>
              </div>
              <input type="hidden" name="eventosapp_badge_paper_templates[<?php echo esc_attr($key); ?>][orientation]" value="<?php echo esc_attr($template['orientation']); ?>">
              <small><?php echo esc_html($template['orientation'] === 'portrait' ? __('Vertical', 'eventosapp') : __('Horizontal', 'eventosapp')); ?><?php echo !empty($template['locked']) ? ' · ' . esc_html__('base', 'eventosapp') : ''; ?></small>
              <?php if (empty($template['locked'])): ?>
                <label style="margin-top:8px;display:block;color:#b32d2e;"><input type="checkbox" name="eventosapp_badge_delete_template[<?php echo esc_attr($key); ?>]" value="1"> <?php _e('Eliminar plantilla', 'eventosapp'); ?></label>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="evapp-new-template">
          <label><?php _e('Nueva plantilla', 'eventosapp'); ?><input type="text" name="eventosapp_badge_new_template_name" placeholder="<?php esc_attr_e('Ej. Escarapela sponsor', 'eventosapp'); ?>"></label>
          <label><?php _e('Ancho mm', 'eventosapp'); ?><input type="number" step="0.1" min="10" name="eventosapp_badge_new_template_width_mm" value="100"></label>
          <label><?php _e('Alto mm', 'eventosapp'); ?><input type="number" step="0.1" min="10" name="eventosapp_badge_new_template_height_mm" value="140"></label>
          <label><?php _e('Margen mm', 'eventosapp'); ?><input type="number" step="0.1" min="0" name="eventosapp_badge_new_template_margin_mm" value="3"></label>
          <label><?php _e('Orientación', 'eventosapp'); ?>
            <select name="eventosapp_badge_new_template_orientation">
              <option value="portrait"><?php _e('Vertical', 'eventosapp'); ?></option>
              <option value="landscape"><?php _e('Horizontal', 'eventosapp'); ?></option>
            </select>
          </label>
        </div>
      </section>
    </div>

    <script>
    jQuery(function($){
      function evappUpdatePaperPreview(){
        var $selected = $('#eventosapp_badge_paper_template option:selected');
        var name = $selected.data('name') || $selected.text();
        var width = parseFloat($selected.data('width')) || 99;
        var height = parseFloat($selected.data('height')) || 55;
        var margin = parseFloat($selected.data('margin')) || 0;
        var orientation = ($selected.data('orientation') || '').toString();
        var ratio = width / Math.max(height, 1);
        var leftPct = Math.min(45, Math.max(0, (margin / Math.max(width, 1)) * 100));
        var topPct = Math.min(45, Math.max(0, (margin / Math.max(height, 1)) * 100));

        $('#evapp_selected_paper_preview').css('--paper-ratio', ratio);
        $('#evapp_selected_paper_safe').css({left:leftPct+'%', right:leftPct+'%', top:topPct+'%', bottom:topPct+'%'});
        $('#evapp_selected_paper_name').text(name);
        $('#evapp_selected_paper_size').text(width + ' × ' + height + ' mm');
        $('#evapp_selected_paper_margin').text('<?php echo esc_js(__('Margen:', 'eventosapp')); ?> ' + margin + ' mm');
        $('#evapp_selected_paper_orientation').text(orientation === 'portrait' ? '<?php echo esc_js(__('Vertical', 'eventosapp')); ?>' : '<?php echo esc_js(__('Horizontal', 'eventosapp')); ?>');
      }

      $('#eventosapp_badge_paper_template').on('change', evappUpdatePaperPreview);
      evappUpdatePaperPreview();

      $('#eventosapp_download_badge').on('click', function(e){
        e.preventDefault();
        var ticketKey = $('input[name="eventosapp_badge_ticket_key"]').val() || '';
        var url = '<?php echo admin_url('admin-ajax.php'); ?>' +
                  '?action=eventosapp_download_badge' +
                  '&post_id=<?php echo (int)$post->ID; ?>' +
                  '&ticket_key=' + encodeURIComponent(ticketKey) +
                  '&_wpnonce=<?php echo wp_create_nonce('eventosapp_download_badge'); ?>';
        window.open(url, '_blank');
      });
    });
    </script>
    <?php
}

/**
 * Guardado del metabox.
 */
add_action('save_post_eventosapp_event', function($post_id){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;

    if (empty($_POST['eventosapp_badge_nonce']) || !wp_verify_nonce($_POST['eventosapp_badge_nonce'], 'eventosapp_save_badge_settings')) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) return;

    eventosapp_badge_save_paper_templates_from_post();

    $templates = eventosapp_badge_get_paper_templates();
    $paper_template = isset($_POST['eventosapp_badge_paper_template']) ? sanitize_key($_POST['eventosapp_badge_paper_template']) : '';
    if ($paper_template === 'legacy_event' || isset($templates[$paper_template])) {
        update_post_meta($post_id, 'eventosapp_badge_paper_template', $paper_template);
    }

    if (isset($_POST['eventosapp_badge_design'])) {
        $d = sanitize_key($_POST['eventosapp_badge_design']);
        if (in_array($d, ['manillas','escarapelas','escarapelas_split','escarapelas_split_4'], true)) {
            update_post_meta($post_id, 'eventosapp_badge_design', $d);
        }
    }
    for ($i=1; $i<=5; $i++){
        $k = "eventosapp_field_order_{$i}";
        update_post_meta($post_id, $k, sanitize_key($_POST[$k] ?? 'none'));
    }

    /*
     * Ancho/alto directos ya no se guardan desde el metabox. Se conservan metas
     * heredadas existentes únicamente como fallback para eventos antiguos.
     */
    foreach (['large','medium','small'] as $s){
        if (isset($_POST["eventosapp_badge_size_{$s}"])) {
            update_post_meta($post_id, "eventosapp_badge_size_{$s}", absint($_POST["eventosapp_badge_size_{$s}"]));
        }
        if (isset($_POST["eventosapp_badge_weight_{$s}"])) {
            update_post_meta($post_id, "eventosapp_badge_weight_{$s}", absint($_POST["eventosapp_badge_weight_{$s}"]));
        }
    }
    foreach (['vertical','horizontal'] as $dir){
        if (isset($_POST["eventosapp_badge_sep_{$dir}"])) {
            update_post_meta($post_id, "eventosapp_badge_sep_{$dir}", absint($_POST["eventosapp_badge_sep_{$dir}"]));
        }
    }
    if (isset($_POST['eventosapp_badge_qr_size'])) {
        update_post_meta($post_id, 'eventosapp_badge_qr_size', absint($_POST['eventosapp_badge_qr_size']));
    }
    if (isset($_POST['eventosapp_badge_border_width'])) {
        update_post_meta($post_id, 'eventosapp_badge_border_width', max(0, absint($_POST['eventosapp_badge_border_width'])));
    }
    if (isset($_POST['eventosapp_badge_ticket_key'])) {
        update_post_meta(
            $post_id,
            'eventosapp_badge_ticket_key',
            sanitize_text_field($_POST['eventosapp_badge_ticket_key'])
        );
    }
}, 22);

/**
 * Helper público para jalar la configuración desde cualquier parte.
 */
function eventosapp_get_badge_settings($evento_id) {
    $evento_id = absint($evento_id);

    $get = function($k, $def = null) use ($evento_id) {
        if (!$evento_id) {
            return $def;
        }

        $v = get_post_meta($evento_id, $k, true);
        return ($v === '' || $v === null) ? $def : $v;
    };

    $allowed_designs = ['manillas', 'escarapelas', 'escarapelas_split', 'escarapelas_split_4'];
    $design = sanitize_key($get('eventosapp_badge_design', 'manillas'));
    if (!in_array($design, $allowed_designs, true)) {
        $design = 'manillas';
    }

    $order = [];
    for ($i = 1; $i <= 5; $i++) {
        $field = sanitize_key($get("eventosapp_field_order_{$i}", 'none'));
        $order[$i] = $field !== '' ? $field : 'none';
    }

    $paper_template = eventosapp_badge_get_selected_paper_template($evento_id);
    $paper_width_mm = eventosapp_badge_sanitize_mm($paper_template['width_mm'] ?? 99, 99, 10, 1000);
    $paper_height_mm = eventosapp_badge_sanitize_mm($paper_template['height_mm'] ?? 55, 55, 10, 1000);
    $paper_margin_mm = eventosapp_badge_sanitize_mm($paper_template['margin_mm'] ?? 0, 0, 0, max(0, min($paper_width_mm, $paper_height_mm) / 2 - 0.5));

    /*
     * width/height se conservan como equivalencia CSS para funciones heredadas.
     * La impresión real usa paper_width_mm/paper_height_mm y @page en milímetros.
     */
    $width_px  = max(1, (int) round(($paper_width_mm * 96) / 25.4));
    $height_px = max(1, (int) round(($paper_height_mm * 96) / 25.4));

    return [
        'design'              => $design,
        'order'               => $order,
        'paper_template_key'  => sanitize_key($paper_template['key'] ?? 'legacy_standard'),
        'paper_template_name' => sanitize_text_field($paper_template['name'] ?? __('Formato de papel', 'eventosapp')),
        'paper_width_mm'      => $paper_width_mm,
        'paper_height_mm'     => $paper_height_mm,
        'paper_margin_mm'     => $paper_margin_mm,
        'paper_orientation'   => sanitize_key($paper_template['orientation'] ?? (($paper_height_mm >= $paper_width_mm) ? 'portrait' : 'landscape')),
        'width'               => $width_px,
        'height'              => $height_px,
        'size_large'          => max(1, (int) $get('eventosapp_badge_size_large', 24)),
        'size_medium'         => max(1, (int) $get('eventosapp_badge_size_medium', 18)),
        'size_small'          => max(1, (int) $get('eventosapp_badge_size_small', 14)),
        'weight_large'        => max(100, (int) $get('eventosapp_badge_weight_large', 600)),
        'weight_medium'       => max(100, (int) $get('eventosapp_badge_weight_medium', 500)),
        'weight_small'        => max(100, (int) $get('eventosapp_badge_weight_small', 400)),
        'sep_vertical'        => max(0, (int) $get('eventosapp_badge_sep_vertical', 4)),
        'sep_horizontal'      => max(0, (int) $get('eventosapp_badge_sep_horizontal', 4)),
        'qr_size'             => max(1, (int) $get('eventosapp_badge_qr_size', 72)),
        'border_width'        => max(0, (int) $get('eventosapp_badge_border_width', 0)),
    ];
}

/**
 * Helper para generar URL firmada de impresión desde admin.
 */
function eventosapp_badge_print_url($evento_id, $ticket = '') {
    $args = [
        'action'  => 'eventosapp_download_badge',
        'post_id' => (int)$evento_id,
    ];
    if (is_numeric($ticket)) {
        $args['ticket_id'] = (int)$ticket;
    } elseif (is_string($ticket) && $ticket !== '') {
        $args['ticket_key'] = $ticket;
    }
    $url = add_query_arg($args, admin_url('admin-ajax.php'));
    return wp_nonce_url($url, 'eventosapp_download_badge');
}

/**
 * Imprime el HTML final de la escarapela usando la configuración guardada.
 *
 * Se protege con function_exists porque versiones anteriores del módulo de
 * búsqueda/frontend también declaraban esta función. La fuente preferida es
 * este helper central de escarapelas, pero esta protección evita fatales si
 * otro archivo heredado ya fue cargado por el sitio.
 */
if ( ! function_exists('eventosapp_get_badge_html_from_event') ) {
function eventosapp_get_badge_html_from_event($evento_id, $ticket_id = 0, $auto_print = true) {
    $evento_id  = absint($evento_id);
    $ticket_id  = absint($ticket_id);
    $auto_print = (bool) $auto_print;

    if (!$evento_id || get_post_type($evento_id) !== 'eventosapp_event') {
        return '';
    }

    if ($ticket_id && get_post_type($ticket_id) !== 'eventosapp_ticket') {
        $ticket_id = 0;
    }

    if ($ticket_id) {
        $ticket_event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        if ($ticket_event_id && $ticket_event_id !== $evento_id) {
            $evento_id = $ticket_event_id;
        }
    }

    $cfg      = eventosapp_get_badge_settings($evento_id);
    $labels   = eventosapp_badge_build_labels($evento_id, $ticket_id);
    $flex_dir = ($cfg['design'] === 'escarapelas') ? 'column' : 'row';

    $paper_width_mm  = eventosapp_badge_format_mm($cfg['paper_width_mm']);
    $paper_height_mm = eventosapp_badge_format_mm($cfg['paper_height_mm']);
    $paper_margin_mm = eventosapp_badge_format_mm($cfg['paper_margin_mm']);
    $border_width    = max(0, (int) $cfg['border_width']);

    ob_start();
    ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Escarapela</title>
<style>
  @page {
    size: <?php echo esc_html($paper_width_mm); ?>mm <?php echo esc_html($paper_height_mm); ?>mm;
    margin: 0;
  }
  *{box-sizing:border-box;}
  html,body{margin:0;padding:0;width:100%;min-height:100%;background:#f3f4f5;}
  body{font-family:Arial,Helvetica,sans-serif;display:flex;align-items:center;justify-content:center;}
  .badge-print-page{
    width:<?php echo esc_html($paper_width_mm); ?>mm;
    height:<?php echo esc_html($paper_height_mm); ?>mm;
    margin:0 auto;
    padding:<?php echo esc_html($paper_margin_mm); ?>mm;
    background:#fff;
    overflow:hidden;
    box-sizing:border-box;
    print-color-adjust:exact;
    -webkit-print-color-adjust:exact;
  }
  .badge{
    <?php echo ($border_width > 0 ? "border:" . $border_width . "px solid #000;" : "border:none;"); ?>
    display:flex;
    flex-direction:<?php echo esc_attr($flex_dir); ?>;
    align-items:stretch;
    justify-content:center;
    width:100%;
    height:100%;
    padding:0;
    box-sizing:border-box;
    overflow:hidden;
    background:#fff;
  }
  .left,.right{display:flex;flex-direction:column;justify-content:center;min-width:0;height:100%;}
  .left{flex:1 1 auto;}
  .right{align-items:center;flex:0 0 auto;}
  .slot{line-height:1.15;text-align:center;word-break:break-word;overflow-wrap:anywhere;max-width:100%;}
  .slot-qr{display:flex;align-items:center;justify-content:center;}
  .slot-qr img{display:block;max-width:100%;height:auto;}
  @media screen {
    body{min-height:100vh;padding:24px;}
    .badge-print-page{box-shadow:0 12px 30px rgba(0,0,0,.18);}
  }
  @media print {
    html,body{width:<?php echo esc_html($paper_width_mm); ?>mm;height:<?php echo esc_html($paper_height_mm); ?>mm;min-height:0;background:#fff;display:block;}
    body{padding:0;}
    .badge-print-page{width:<?php echo esc_html($paper_width_mm); ?>mm;height:<?php echo esc_html($paper_height_mm); ?>mm;margin:0;padding:<?php echo esc_html($paper_margin_mm); ?>mm;box-shadow:none;page-break-after:avoid;page-break-before:avoid;}
  }
</style>
</head>
<body>
<div class="badge-print-page"
     data-paper-template="<?php echo esc_attr($cfg['paper_template_key']); ?>"
     data-paper-width-mm="<?php echo esc_attr($paper_width_mm); ?>"
     data-paper-height-mm="<?php echo esc_attr($paper_height_mm); ?>"
     data-paper-margin-mm="<?php echo esc_attr($paper_margin_mm); ?>">
  <div class="badge" data-event-id="<?php echo esc_attr($evento_id); ?>" data-ticket-id="<?php echo esc_attr($ticket_id); ?>">
<?php
    $active = [];
    for ($i = 1; $i <= 5; $i++) {
        $f = $cfg['order'][$i] ?? 'none';
        if ($f !== 'none') {
            $active[] = $f;
        }
    }

    if (!$active) {
        $active = ['full_name', 'company', 'qr'];
    }

    if ($cfg['design'] === 'escarapelas_split') {
        // Diseño split 3 izq / 1 der.
        $left  = array_slice($active, 0, 3);
        $right = $active[3] ?? null;

        echo "<div class='left'>";
        foreach ($left as $idx => $field) {
            $fs = ($idx === 0) ? $cfg['size_large'] : (($idx === 1) ? $cfg['size_medium'] : $cfg['size_small']);
            $fw = ($idx === 0) ? $cfg['weight_large'] : (($idx === 1) ? $cfg['weight_medium'] : $cfg['weight_small']);
            eventosapp_badge_render_field_slot($field, $labels, $fs, $fw, $cfg['sep_vertical'], $cfg['qr_size']);
        }
        echo "</div>";

        $mh = absint($cfg['sep_horizontal']);
        echo "<div class='right' style='margin-left:{$mh}px'>";
        if ($right) {
            eventosapp_badge_render_field_slot($right, $labels, $cfg['size_medium'], $cfg['weight_medium'], $cfg['sep_vertical'], $cfg['qr_size']);
        }
        echo "</div>";

    } elseif ($cfg['design'] === 'escarapelas_split_4') {
        // Diseño split 4 izq / 1 der.
        $left  = array_slice($active, 0, 4);
        $right = $active[4] ?? null;

        echo "<div class='left'>";
        foreach ($left as $idx => $field) {
            if ($idx === 0) {
                $fs = $cfg['size_large'];
                $fw = $cfg['weight_large'];
            } elseif ($idx === 1 || $idx === 2) {
                $fs = $cfg['size_medium'];
                $fw = $cfg['weight_medium'];
            } else {
                $fs = $cfg['size_small'];
                $fw = $cfg['weight_small'];
            }
            eventosapp_badge_render_field_slot($field, $labels, $fs, $fw, $cfg['sep_vertical'], $cfg['qr_size']);
        }
        echo "</div>";

        $mh = absint($cfg['sep_horizontal']);
        echo "<div class='right' style='margin-left:{$mh}px'>";
        if ($right) {
            eventosapp_badge_render_field_slot($right, $labels, $cfg['size_medium'], $cfg['weight_medium'], $cfg['sep_vertical'], $cfg['qr_size']);
        }
        echo "</div>";

    } else {
        // Diseños normales (manillas o escarapelas vertical).
        foreach (array_values($active) as $idx => $field) {
            $margin = ($cfg['design'] === 'escarapelas') ? $cfg['sep_vertical'] : $cfg['sep_horizontal'];
            if ($cfg['design'] === 'escarapelas') {
                $fs = ($idx === 0) ? $cfg['size_large'] : (($idx <= 2) ? $cfg['size_medium'] : $cfg['size_small']);
                $fw = ($idx === 0) ? $cfg['weight_large'] : (($idx <= 2) ? $cfg['weight_medium'] : $cfg['weight_small']);
            } else {
                $fs = $cfg['size_medium'];
                $fw = $cfg['weight_medium'];
            }
            eventosapp_badge_render_field_slot($field, $labels, $fs, $fw, $margin, $cfg['qr_size']);
        }
    }
?>
  </div>
</div>
<?php if ($auto_print): ?>
<script id="eventosapp-badge-autoprint">
(function(){
  var evappBadgePrinted = false;

  function evappBadgeSendPrint(){
    if(evappBadgePrinted) return;
    evappBadgePrinted = true;
    setTimeout(function(){
      try { window.focus(); } catch(e) {}
      try { window.print(); } catch(e) {}
    }, 200);
  }

  function evappBadgeWaitImages(){
    var imgs = [];
    try { imgs = Array.prototype.slice.call(document.images || []); } catch(e) { imgs = []; }
    if(!imgs.length){ evappBadgeSendPrint(); return; }

    var pending = imgs.length;
    var done = function(){
      pending--;
      if(pending <= 0){ evappBadgeSendPrint(); }
    };

    imgs.forEach(function(img){
      if(img.complete){
        done();
      } else {
        img.addEventListener('load', done, {once:true});
        img.addEventListener('error', done, {once:true});
      }
    });

    setTimeout(evappBadgeSendPrint, 2500);
  }

  function evappBadgeReady(){
    if(document.fonts && document.fonts.ready){
      document.fonts.ready.then(evappBadgeWaitImages).catch(evappBadgeWaitImages);
    } else {
      evappBadgeWaitImages();
    }
  }

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', evappBadgeReady, {once:true});
  } else {
    evappBadgeReady();
  }
})();
</script>
<?php endif; ?>
</body>
</html>
<?php
    return ob_get_clean();
}
}

/**
 * Imprime el HTML final de la escarapela usando la configuración guardada.
 */
function eventosapp_badge_output_html($evento_id, $ticket_id = 0, $auto_print = true) {
    $evento_id = absint($evento_id);
    $ticket_id = absint($ticket_id);

    if (!$evento_id) {
        wp_die('Evento inválido', '', 400);
    }

    $html = eventosapp_get_badge_html_from_event($evento_id, $ticket_id, $auto_print);
    if ($html === '') {
        wp_die('No fue posible generar la escarapela para este evento.', '', 500);
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }

    echo $html;
    exit;
}

/**
 * AJAX admin: imprimir/descargar escarapela desde el metabox del evento.
 */
add_action('wp_ajax_eventosapp_download_badge', 'eventosapp_download_badge_handler');
add_action('wp_ajax_nopriv_eventosapp_download_badge', 'eventosapp_download_badge_handler');

function eventosapp_download_badge_handler() {
    if ( empty($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'eventosapp_download_badge') ) {
        wp_die('Nonce inválido', '', 403);
    }

    $ticket_id = eventosapp_badge_resolve_ticket_id();
    $evento_id = eventosapp_badge_resolve_event_id($ticket_id);

    if (!$evento_id) wp_die('Evento inválido', '', 400);

    // El botón del metabox es administrativo y mantiene validación por capacidad.
    if (!current_user_can('edit_post', $evento_id)) wp_die('Sin permisos.', '', 403);

    eventosapp_badge_output_html($evento_id, $ticket_id, true);
}
