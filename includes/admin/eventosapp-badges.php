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
 * Opciones de rotación del driver que se exportan para EventosApp Printer Setup.
 * Auto conserva la orientación normal; las opciones 180 se usan solo si el driver
 * las expone o si se complementan con un perfil privado del fabricante.
 */
function eventosapp_badge_driver_rotation_options() {
    return [
        'auto'         => __('Automático según orientación', 'eventosapp'),
        'portrait'     => __('Portrait', 'eventosapp'),
        'landscape'    => __('Landscape', 'eventosapp'),
        'portrait180'  => __('Portrait 180', 'eventosapp'),
        'landscape180' => __('Landscape 180', 'eventosapp'),
    ];
}

/**
 * Normaliza la rotación del driver sin amarrarla a medidas o nombres específicos.
 */
function eventosapp_badge_sanitize_driver_rotation($value) {
    $value = strtolower(trim((string) $value));
    $value = str_replace([' ', '_', '-', '°'], '', $value);

    if (in_array($value, ['portrait180', 'reverseportrait', 'portraitreverse', 'vertical180', 'p180'], true)) {
        return 'portrait180';
    }
    if (in_array($value, ['landscape180', 'reverselandscape', 'landscapereverse', 'horizontal180', 'l180'], true)) {
        return 'landscape180';
    }
    if (in_array($value, ['portrait', 'vertical', 'p'], true)) {
        return 'portrait';
    }
    if (in_array($value, ['landscape', 'horizontal', 'l'], true)) {
        return 'landscape';
    }

    return 'auto';
}

/**
 * Etiqueta legible para mostrar la rotación del driver en la biblioteca.
 */
function eventosapp_badge_driver_rotation_label($value) {
    $value   = eventosapp_badge_sanitize_driver_rotation($value);
    $options = eventosapp_badge_driver_rotation_options();
    return $options[$value] ?? $options['auto'];
}

/**
 * Calcula el lienzo final y la rotación del contenido que se enviarán al navegador y a la impresora.
 *
 * La biblioteca guarda el tamaño base del formato de papel, pero la orientación
 * de impresión debe convertir ese formato en un lienzo final tipo portrait o
 * landscape. Cuando el lienzo se intercambia, también se rota el bloque interno
 * de contenido para que el preview, @page y la impresión usen exactamente la
 * misma dirección visual.
 *
 * Ejemplo: una plantilla base 101 × 59 mm en orientación Vertical envía:
 * - @page / lienzo: 59 × 101 mm.
 * - contenido: 101 × 59 mm, rotado -90° dentro del lienzo.
 */
function eventosapp_badge_get_oriented_paper_dimensions($width_mm, $height_mm, $orientation = '') {
    $base_width_mm  = eventosapp_badge_sanitize_mm($width_mm, 99, 10, 1000);
    $base_height_mm = eventosapp_badge_sanitize_mm($height_mm, 55, 10, 1000);
    $orientation    = sanitize_key($orientation);

    $base_page_orientation = ($base_height_mm >= $base_width_mm) ? 'portrait' : 'landscape';

    if (!in_array($orientation, ['portrait', 'landscape'], true)) {
        $orientation = $base_page_orientation;
    }

    $short_side = min($base_width_mm, $base_height_mm);
    $long_side  = max($base_width_mm, $base_height_mm);

    if ($orientation === 'portrait') {
        $width_mm  = $short_side;
        $height_mm = $long_side;
    } else {
        $width_mm  = $long_side;
        $height_mm = $short_side;
    }

    $canvas_rotated = (abs($width_mm - $base_width_mm) > 0.01) || (abs($height_mm - $base_height_mm) > 0.01);
    $content_rotation = 0;

    if ($canvas_rotated) {
        /*
         * Si el formato base era horizontal y se pide vertical, el lienzo se vuelve
         * corto × largo y el contenido debe girar -90° para acompañar ese lienzo.
         * Si el formato base era vertical y se pide horizontal, el giro opuesto lo
         * mantiene consistente con el nuevo @page.
         */
        $content_rotation = ($orientation === 'portrait') ? -90 : 90;
    }

    return [
        'base_width_mm'         => $base_width_mm,
        'base_height_mm'        => $base_height_mm,
        'width_mm'              => $width_mm,
        'height_mm'             => $height_mm,
        'orientation'           => $orientation,
        'page_orientation'      => $orientation,
        'base_page_orientation' => $base_page_orientation,
        'content_rotation'      => $content_rotation,
        'rotated'               => $canvas_rotated,
        'canvas_rotated'        => $canvas_rotated,
    ];
}

/**
 * Versión helper para plantillas completas.
 */
function eventosapp_badge_get_oriented_template_dimensions($template) {
    if (!is_array($template)) {
        $template = [];
    }

    return eventosapp_badge_get_oriented_paper_dimensions(
        $template['width_mm'] ?? 99,
        $template['height_mm'] ?? 55,
        $template['orientation'] ?? ''
    );
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
            'orientation'     => 'landscape',
            'driver_rotation' => 'auto',
            'locked'          => true,
        ],
        'badge_100x140' => [
            'key'         => 'badge_100x140',
            'name'        => __('Escarapela vertical 100 × 140 mm', 'eventosapp'),
            'width_mm'    => 100,
            'height_mm'   => 140,
            'margin_mm'   => 3,
            'orientation'     => 'portrait',
            'driver_rotation' => 'auto',
            'locked'          => true,
        ],
        'badge_90x130' => [
            'key'         => 'badge_90x130',
            'name'        => __('Escarapela vertical 90 × 130 mm', 'eventosapp'),
            'width_mm'    => 90,
            'height_mm'   => 130,
            'margin_mm'   => 3,
            'orientation'     => 'portrait',
            'driver_rotation' => 'auto',
            'locked'          => true,
        ],
        'card_86x54' => [
            'key'         => 'card_86x54',
            'name'        => __('Tarjeta PVC 86 × 54 mm', 'eventosapp'),
            'width_mm'    => 86,
            'height_mm'   => 54,
            'margin_mm'   => 2,
            'orientation'     => 'landscape',
            'driver_rotation' => 'auto',
            'locked'          => true,
        ],
        'wristband_250x25' => [
            'key'         => 'wristband_250x25',
            'name'        => __('Manilla 250 × 25 mm', 'eventosapp'),
            'width_mm'    => 250,
            'height_mm'   => 25,
            'margin_mm'   => 2,
            'orientation'     => 'landscape',
            'driver_rotation' => 'auto',
            'locked'          => true,
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

    $driver_rotation = eventosapp_badge_sanitize_driver_rotation(
        $template['driver_rotation']
        ?? $template['driverRotation']
        ?? $template['rotate']
        ?? $template['rotation']
        ?? 'auto'
    );

    return [
        'key'             => $key,
        'name'            => $name,
        'width_mm'        => $width,
        'height_mm'       => $height,
        'margin_mm'       => $margin,
        'orientation'     => $orientation,
        'driver_rotation' => $driver_rotation,
        'locked'          => !empty($template['locked']),
    ];
}

/**
 * Devuelve plantillas de papel base + plantillas guardadas por el administrador.
 */
function eventosapp_badge_get_paper_templates() {
    $templates = [];
    $deleted_defaults = get_option('eventosapp_badge_deleted_default_templates', []);
    if (!is_array($deleted_defaults)) {
        $deleted_defaults = [];
    }
    $deleted_defaults = array_map('sanitize_key', $deleted_defaults);

    foreach (eventosapp_badge_default_paper_templates() as $key => $template) {
        $key = sanitize_key($key);
        if (in_array($key, $deleted_defaults, true)) {
            continue;
        }

        $templates[$key] = eventosapp_badge_normalize_paper_template($template, $key);
        $templates[$key]['locked'] = false;
        $templates[$key]['is_default'] = true;
    }

    $saved = get_option('eventosapp_badge_paper_templates', []);
    if (is_array($saved)) {
        foreach ($saved as $key => $template) {
            $normalized = eventosapp_badge_normalize_paper_template($template, $key);
            if (in_array($normalized['key'], $deleted_defaults, true)) {
                continue;
            }
            $normalized['locked'] = false;
            $normalized['is_default'] = isset(eventosapp_badge_default_paper_templates()[$normalized['key']]);
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

    if (!empty($templates)) {
        return reset($templates);
    }

    return eventosapp_badge_runtime_fallback_template();
}

/**
 * Renderiza una vista previa visual del papel físico y su área útil.
 * La orientación se muestra como orientación del contenido, no como intercambio
 * del tamaño físico del papel.
 */
function eventosapp_badge_render_paper_preview_box($template, $label = '', $class = '') {
    $template = eventosapp_badge_normalize_paper_template($template, $template['key'] ?? 'preview');
    $layout   = eventosapp_badge_get_oriented_template_dimensions($template);
    $ratio    = max(0.1, (float) $layout['width_mm'] / max(1, (float) $layout['height_mm']));
    $left_pct = min(45, max(0, ((float) $template['margin_mm'] / max(1, (float) $layout['width_mm'])) * 100));
    $top_pct  = min(45, max(0, ((float) $template['margin_mm'] / max(1, (float) $layout['height_mm'])) * 100));
    $label    = $label !== '' ? $label : __('Área útil', 'eventosapp');
    $canvas_label = !empty($layout['canvas_rotated'])
        ? sprintf(__('Lienzo %s × %s mm · contenido %s°', 'eventosapp'), eventosapp_badge_format_mm($layout['width_mm']), eventosapp_badge_format_mm($layout['height_mm']), (int) $layout['content_rotation'])
        : '';

    ?>
    <div class="evapp-paper-preview <?php echo esc_attr($class); ?>" style="--paper-ratio:<?php echo esc_attr($ratio); ?>;" data-effective-width-mm="<?php echo esc_attr(eventosapp_badge_format_mm($layout['width_mm'])); ?>" data-effective-height-mm="<?php echo esc_attr(eventosapp_badge_format_mm($layout['height_mm'])); ?>" data-paper-orientation="<?php echo esc_attr($layout['orientation']); ?>" data-content-rotation="<?php echo esc_attr((int) $layout['content_rotation']); ?>">
      <div class="evapp-paper-safe" style="left:<?php echo esc_attr($left_pct); ?>%;right:<?php echo esc_attr($left_pct); ?>%;top:<?php echo esc_attr($top_pct); ?>%;bottom:<?php echo esc_attr($top_pct); ?>%;">
        <span class="evapp-paper-safe-label"><?php echo esc_html($label); ?></span>
        <?php if ($canvas_label !== ''): ?>
          <span class="evapp-paper-rotation-label"><?php echo esc_html($canvas_label); ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

/**
 * Guarda la biblioteca centralizada de plantillas de papel.
 */
function eventosapp_badge_save_paper_templates_from_request($request) {
    if (!is_array($request)) {
        return false;
    }

    $existing_templates = eventosapp_badge_get_paper_templates();
    $default_templates  = eventosapp_badge_default_paper_templates();
    $submitted          = isset($request['eventosapp_badge_paper_templates']) && is_array($request['eventosapp_badge_paper_templates'])
        ? wp_unslash($request['eventosapp_badge_paper_templates'])
        : [];
    $delete             = isset($request['eventosapp_badge_delete_template']) && is_array($request['eventosapp_badge_delete_template'])
        ? array_map('sanitize_key', array_keys(wp_unslash($request['eventosapp_badge_delete_template'])))
        : [];

    $templates_to_save = [];
    $deleted_defaults  = get_option('eventosapp_badge_deleted_default_templates', []);
    if (!is_array($deleted_defaults)) {
        $deleted_defaults = [];
    }
    $deleted_defaults = array_values(array_unique(array_map('sanitize_key', $deleted_defaults)));

    foreach ($submitted as $key => $template) {
        $key = sanitize_key($key);
        if ($key === '' || $key === 'legacy_event') {
            continue;
        }

        if (in_array($key, $delete, true)) {
            if (isset($default_templates[$key])) {
                $deleted_defaults[] = $key;
            }
            continue;
        }

        $normalized = eventosapp_badge_normalize_paper_template($template, $key);
        $normalized['locked'] = false;
        $normalized['is_default'] = isset($default_templates[$key]);
        $templates_to_save[$normalized['key']] = $normalized;

        if (isset($default_templates[$normalized['key']])) {
            $deleted_defaults = array_diff($deleted_defaults, [$normalized['key']]);
        }
    }

    if (!empty($request['eventosapp_badge_new_template_name'])) {
        $new_name = sanitize_text_field(wp_unslash($request['eventosapp_badge_new_template_name']));
        $base_key = sanitize_key(sanitize_title($new_name));
        if ($base_key === '') {
            $base_key = 'plantilla';
        }

        $new_key = $base_key;
        $suffix  = 2;
        while (isset($templates_to_save[$new_key]) || isset($existing_templates[$new_key]) || isset($default_templates[$new_key])) {
            $new_key = $base_key . '_' . $suffix;
            $suffix++;
        }

        $new_template = eventosapp_badge_normalize_paper_template([
            'key'         => $new_key,
            'name'        => $new_name,
            'width_mm'    => $request['eventosapp_badge_new_template_width_mm'] ?? 99,
            'height_mm'   => $request['eventosapp_badge_new_template_height_mm'] ?? 55,
            'margin_mm'        => $request['eventosapp_badge_new_template_margin_mm'] ?? 2,
            'orientation'      => $request['eventosapp_badge_new_template_orientation'] ?? 'landscape',
            'driver_rotation' => $request['eventosapp_badge_new_template_driver_rotation'] ?? 'auto',
            'locked'           => false,
        ], $new_key);
        $new_template['locked'] = false;
        $new_template['is_default'] = false;
        $templates_to_save[$new_template['key']] = $new_template;
    }

    $deleted_defaults = array_values(array_unique(array_filter($deleted_defaults)));
    update_option('eventosapp_badge_deleted_default_templates', $deleted_defaults, false);
    update_option('eventosapp_badge_paper_templates', $templates_to_save, false);
    return true;
}

/**
 * Wrapper heredado por compatibilidad con instalaciones que llamen esta función.
 */
function eventosapp_badge_save_paper_templates_from_post() {
    return eventosapp_badge_save_paper_templates_from_request($_POST);
}


/**
 * Devuelve una plantilla temporal segura cuando el administrador elimina todas las plantillas.
 * No se muestra en la biblioteca: solo evita errores en eventos que aún no tengan un formato activo.
 */
function eventosapp_badge_runtime_fallback_template() {
    return eventosapp_badge_normalize_paper_template([
        'key'         => 'runtime_fallback',
        'name'        => __('Formato temporal 99 × 55 mm', 'eventosapp'),
        'width_mm'    => 99,
        'height_mm'   => 55,
        'margin_mm'   => 2,
        'orientation' => 'landscape',
        'locked'      => false,
    ], 'runtime_fallback');
}

/**
 * Permite subir archivos ZIP desde la Biblioteca de Escarapelas.
 */
function eventosapp_badge_allow_zip_upload_mimes($mimes) {
    $mimes['zip'] = 'application/zip';
    return $mimes;
}

/**
 * Normaliza los recursos descargables de soporte de impresión.
 */
function eventosapp_badge_get_support_files() {
    $files = get_option('eventosapp_badge_support_files', []);
    if (!is_array($files)) {
        return [];
    }

    $clean = [];
    foreach ($files as $key => $file) {
        if (!is_array($file)) {
            continue;
        }
        $key = sanitize_key($key);
        $attachment_id = absint($file['attachment_id'] ?? 0);
        $url = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
        if (!$attachment_id || !$url) {
            continue;
        }
        $name = sanitize_text_field($file['name'] ?? '');
        if ($name === '') {
            $name = basename((string) get_attached_file($attachment_id));
        }
        $clean[$key ?: ('file_' . $attachment_id)] = [
            'key'           => $key ?: ('file_' . $attachment_id),
            'name'          => $name,
            'attachment_id' => $attachment_id,
            'url'           => esc_url_raw($url),
            'filename'      => sanitize_file_name($file['filename'] ?? basename((string) get_attached_file($attachment_id))),
            'uploaded_at'   => sanitize_text_field($file['uploaded_at'] ?? ''),
        ];
    }

    return $clean;
}

/**
 * Guarda nombres, elimina recursos viejos y sube nuevos ZIP de soporte de impresión.
 */
function eventosapp_badge_save_support_files_from_request($request, $files) {
    if (!is_array($request)) {
        return false;
    }

    $support_files = eventosapp_badge_get_support_files();

    if (!empty($request['eventosapp_badge_support_existing']) && is_array($request['eventosapp_badge_support_existing'])) {
        $submitted = wp_unslash($request['eventosapp_badge_support_existing']);
        foreach ($submitted as $key => $data) {
            $key = sanitize_key($key);
            if (!isset($support_files[$key]) || !is_array($data)) {
                continue;
            }
            if (!empty($data['name'])) {
                $support_files[$key]['name'] = sanitize_text_field($data['name']);
            }
        }
    }

    if (!empty($request['eventosapp_badge_delete_support_file']) && is_array($request['eventosapp_badge_delete_support_file'])) {
        $delete_keys = array_map('sanitize_key', array_keys(wp_unslash($request['eventosapp_badge_delete_support_file'])));
        foreach ($delete_keys as $key) {
            if (!isset($support_files[$key])) {
                continue;
            }
            $attachment_id = absint($support_files[$key]['attachment_id']);
            if ($attachment_id) {
                wp_delete_attachment($attachment_id, true);
            }
            unset($support_files[$key]);
        }
    }

    if (is_array($files)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        for ($i = 1; $i <= 2; $i++) {
            $input_name = 'eventosapp_badge_support_file_' . $i;
            if (empty($files[$input_name]) || !is_array($files[$input_name]) || empty($files[$input_name]['name'])) {
                continue;
            }
            if (!empty($files[$input_name]['error']) && (int) $files[$input_name]['error'] !== UPLOAD_ERR_OK) {
                continue;
            }

            $extension = strtolower(pathinfo((string) $files[$input_name]['name'], PATHINFO_EXTENSION));
            if ($extension !== 'zip') {
                continue;
            }

            add_filter('upload_mimes', 'eventosapp_badge_allow_zip_upload_mimes');
            $attachment_id = media_handle_upload($input_name, 0);
            remove_filter('upload_mimes', 'eventosapp_badge_allow_zip_upload_mimes');

            if (is_wp_error($attachment_id)) {
                continue;
            }

            $name = '';
            $name_key = 'eventosapp_badge_support_name_' . $i;
            if (!empty($request[$name_key])) {
                $name = sanitize_text_field(wp_unslash($request[$name_key]));
            }
            if ($name === '') {
                $name = sanitize_file_name($files[$input_name]['name']);
            }

            $file_key = 'support_' . $attachment_id;
            $support_files[$file_key] = [
                'key'           => $file_key,
                'name'          => $name,
                'attachment_id' => absint($attachment_id),
                'url'           => esc_url_raw(wp_get_attachment_url($attachment_id)),
                'filename'      => sanitize_file_name($files[$input_name]['name']),
                'uploaded_at'   => current_time('mysql'),
            ];
        }
    }

    update_option('eventosapp_badge_support_files', $support_files, false);
    return true;
}

/**
 * Exporta las plantillas actuales en JSON.
 */
function eventosapp_badge_export_templates_json() {
    if (!current_user_can('manage_options')) {
        wp_die(__('No tienes permisos suficientes para exportar estas plantillas.', 'eventosapp'), '', 403);
    }

    check_admin_referer('eventosapp_export_badge_templates');

    $templates = array_values(eventosapp_badge_get_paper_templates());
    foreach ($templates as &$template) {
        unset($template['locked']);
        $template['driver_rotation'] = eventosapp_badge_sanitize_driver_rotation($template['driver_rotation'] ?? 'auto');
        $template['driverRotation']  = $template['driver_rotation'];
    }
    unset($template);

    $payload = [
        'type'        => 'eventosapp_badge_templates',
        'version'     => 1,
        'exported_at' => current_time('mysql'),
        'site'        => home_url('/'),
        'templates'   => $templates,
    ];

    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=eventosapp-biblioteca-escarapelas-' . gmdate('Ymd-His') . '.json');
    echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Importa plantillas desde un JSON exportado por EventosApp.
 */
function eventosapp_badge_import_templates_from_upload($files) {
    if (empty($files['eventosapp_badge_templates_import']) || !is_array($files['eventosapp_badge_templates_import']) || empty($files['eventosapp_badge_templates_import']['tmp_name'])) {
        return false;
    }

    $file = $files['eventosapp_badge_templates_import'];
    if (!empty($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    $contents = file_get_contents($file['tmp_name']);
    if (!$contents) {
        return false;
    }

    $payload = json_decode($contents, true);
    if (!is_array($payload)) {
        return false;
    }

    $incoming = [];
    if (!empty($payload['templates']) && is_array($payload['templates'])) {
        $incoming = $payload['templates'];
    } elseif (array_keys($payload) !== range(0, count($payload) - 1)) {
        $incoming = array_values($payload);
    } else {
        $incoming = $payload;
    }

    if (empty($incoming)) {
        return false;
    }

    $templates = eventosapp_badge_get_paper_templates();
    $default_templates = eventosapp_badge_default_paper_templates();
    $deleted_defaults = get_option('eventosapp_badge_deleted_default_templates', []);
    if (!is_array($deleted_defaults)) {
        $deleted_defaults = [];
    }

    foreach ($incoming as $template) {
        if (!is_array($template)) {
            continue;
        }

        $normalized = eventosapp_badge_normalize_paper_template($template, $template['key'] ?? '');
        $base_key = sanitize_key($normalized['key']);
        if ($base_key === '' || $base_key === 'legacy_event' || $base_key === 'runtime_fallback') {
            $base_key = sanitize_key(sanitize_title($normalized['name']));
            if ($base_key === '') {
                $base_key = 'plantilla_importada';
            }
        }

        $new_key = $base_key;
        $suffix = 2;
        while (isset($templates[$new_key]) || isset($default_templates[$new_key])) {
            $new_key = $base_key . '_' . $suffix;
            $suffix++;
        }

        $normalized['key'] = $new_key;
        $normalized['locked'] = false;
        $normalized['is_default'] = false;
        $templates[$new_key] = $normalized;
    }

    $deleted_defaults = array_values(array_unique(array_map('sanitize_key', $deleted_defaults)));
    update_option('eventosapp_badge_deleted_default_templates', $deleted_defaults, false);
    update_option('eventosapp_badge_paper_templates', $templates, false);

    return true;
}

/**
 * Página centralizada: Biblioteca de Escarapelas.
 */
add_action('admin_menu', function () {
    add_submenu_page(
        'eventosapp_dashboard',
        __('Biblioteca de Escarapelas', 'eventosapp'),
        __('Biblioteca de Escarapelas', 'eventosapp'),
        'manage_options',
        'eventosapp_badge_library',
        'eventosapp_render_badge_library_page'
    );
}, 12);

/**
 * Renderiza la página administrativa de plantillas reutilizables.
 */
function eventosapp_render_badge_library_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('No tienes permisos suficientes para acceder a esta página.', 'eventosapp'));
    }

    if (!empty($_GET['eventosapp_badge_export'])) {
        eventosapp_badge_export_templates_json();
    }

    $saved         = false;
    $imported      = false;
    $support_saved = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eventosapp_badge_library_action'])) {
        check_admin_referer('eventosapp_save_badge_library', 'eventosapp_badge_library_nonce');
        $saved         = eventosapp_badge_save_paper_templates_from_request($_POST);
        $imported      = eventosapp_badge_import_templates_from_upload($_FILES);
        $support_saved = eventosapp_badge_save_support_files_from_request($_POST, $_FILES);
    }

    $templates     = eventosapp_badge_get_paper_templates();
    $support_files = eventosapp_badge_get_support_files();
    $export_url    = wp_nonce_url(admin_url('admin.php?page=eventosapp_badge_library&eventosapp_badge_export=1'), 'eventosapp_export_badge_templates');
    ?>
    <div class="wrap evapp-badge-library-wrap">
      <h1><?php _e('Biblioteca de Escarapelas', 'eventosapp'); ?></h1>
      <p class="description">
        <?php _e('Crea, edita, elimina, exporta e importa formatos de papel reutilizables para todos los eventos. La plantilla seleccionada se usa en @page, en el contenedor HTML y en la vista previa.', 'eventosapp'); ?>
      </p>

      <?php if ($saved): ?>
        <div class="notice notice-success is-dismissible"><p><?php _e('Biblioteca de escarapelas guardada correctamente.', 'eventosapp'); ?></p></div>
      <?php endif; ?>
      <?php if ($imported): ?>
        <div class="notice notice-success is-dismissible"><p><?php _e('Plantillas importadas correctamente.', 'eventosapp'); ?></p></div>
      <?php endif; ?>
      <?php if ($support_saved): ?>
        <div class="notice notice-success is-dismissible"><p><?php _e('Archivos de soporte de impresión actualizados correctamente.', 'eventosapp'); ?></p></div>
      <?php endif; ?>

      <style>
        .evapp-badge-library-wrap{max-width:1380px}
        .evapp-badge-library-wrap *{box-sizing:border-box}
        .evapp-library-panel{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px;margin-top:18px;box-shadow:0 1px 2px rgba(0,0,0,.04);overflow:hidden}
        .evapp-library-toolbar{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:14px}
        .evapp-library-toolbar p{margin:4px 0;color:#50575e;max-width:920px}.evapp-library-toolbar-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
        .evapp-template-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(255px,1fr));gap:16px;margin-top:16px;align-items:stretch}
        .evapp-template-card{border:1px solid #dcdcde;border-radius:12px;background:#fbfbfc;padding:14px;display:flex;flex-direction:column;gap:12px;align-items:stretch;min-width:0;overflow:hidden}
        .evapp-template-card.is-base{background:#f8fafc}.evapp-template-card-main{width:100%;min-width:0}.evapp-template-card h2{font-size:14px;margin:0 0 10px;line-height:1.3;word-break:break-word}
        .evapp-template-card label,.evapp-library-form-row label{font-weight:600;font-size:12px;display:block;margin:0 0 10px;min-width:0}
        .evapp-template-card input,.evapp-template-card select,.evapp-library-form-row input,.evapp-library-form-row select{width:100%;max-width:100%;margin-top:4px;min-height:34px}
        .evapp-template-fields{display:grid;grid-template-columns:1fr;gap:8px;margin:2px 0 4px}.evapp-template-meta{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;color:#646970;font-size:12px}
        .evapp-template-delete{color:#b32d2e;margin-top:10px!important;padding-top:8px;border-top:1px solid #dcdcde}
        .evapp-paper-preview{width:min(100%,185px);flex:0 0 auto;max-width:100%;background:#fff;border:2px solid #1d2327;border-radius:7px;position:relative;box-shadow:0 8px 18px rgba(0,0,0,.08);overflow:hidden;margin:0 auto 2px}
        .evapp-paper-preview::before{content:"";display:block;aspect-ratio:var(--paper-ratio,1.6)}.evapp-paper-safe{position:absolute;border:1px dashed #2271b1;background:rgba(34,113,177,.07);border-radius:5px;display:flex;flex-direction:column;gap:2px;align-items:center;justify-content:center;font-size:10px;color:#2271b1;text-align:center;padding:3px;min-width:0;overflow:hidden}.evapp-paper-safe-label{display:block;line-height:1.1}.evapp-paper-rotation-label{display:block;font-size:9px;line-height:1.05;font-weight:700;color:#135e96}
        .evapp-new-template{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end;padding:14px;border:1px dashed #8c8f94;border-radius:12px;background:#f6f7f7;margin-top:20px}
        .evapp-library-submit{margin-top:18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}.evapp-library-two-cols{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:18px;align-items:start}.evapp-library-box{border:1px solid #dcdcde;border-radius:12px;background:#fff;padding:16px;min-width:0}.evapp-library-box h2{margin-top:0}.evapp-library-form-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end}.evapp-support-list{display:grid;grid-template-columns:1fr;gap:12px;margin:12px 0}.evapp-support-item{border:1px solid #dcdcde;border-radius:10px;background:#f6f7f7;padding:12px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:end}.evapp-support-item .button{white-space:nowrap}.evapp-support-delete{color:#b32d2e}.evapp-library-muted{color:#646970;font-size:12px;margin-top:5px;display:block}
        @media (max-width:1100px){.evapp-library-two-cols{grid-template-columns:1fr}.evapp-library-toolbar{display:block}.evapp-library-toolbar-actions{justify-content:flex-start;margin-top:12px}}
        @media (max-width:782px){.evapp-badge-library-wrap{margin-right:10px}.evapp-library-panel,.evapp-library-box{padding:14px}.evapp-template-grid{grid-template-columns:1fr}.evapp-new-template,.evapp-library-form-row,.evapp-support-item{grid-template-columns:1fr}.evapp-library-toolbar-actions .button{width:100%;text-align:center}}
      </style>

      <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('eventosapp_save_badge_library', 'eventosapp_badge_library_nonce'); ?>
        <input type="hidden" name="eventosapp_badge_library_action" value="save">

        <div class="evapp-library-panel">
          <div class="evapp-library-toolbar">
            <div>
              <h2 style="margin-top:0"><?php _e('Formatos disponibles', 'eventosapp'); ?></h2>
              <p><?php _e('El ancho, alto y margen se guardan en milímetros. Todas las plantillas se pueden editar o eliminar, incluidas las plantillas base.', 'eventosapp'); ?></p>
            </div>
            <div class="evapp-library-toolbar-actions">
              <a class="button" href="<?php echo esc_url($export_url); ?>"><?php _e('Descargar plantillas', 'eventosapp'); ?></a>
              <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=eventosapp_event')); ?>"><?php _e('Ir a eventos', 'eventosapp'); ?></a>
            </div>
          </div>

          <?php if (empty($templates)): ?>
            <div class="notice notice-warning inline"><p><?php _e('No hay plantillas guardadas. Crea una nueva plantilla o importa una biblioteca para poder seleccionarla en los eventos.', 'eventosapp'); ?></p></div>
          <?php endif; ?>

          <div class="evapp-template-grid">
            <?php foreach ($templates as $key => $template): ?>
              <div class="evapp-template-card <?php echo !empty($template['is_default']) ? 'is-base' : ''; ?>">
                <?php eventosapp_badge_render_paper_preview_box($template, __('Área útil', 'eventosapp')); ?>
                <div class="evapp-template-card-main">
                  <h2><?php echo esc_html($template['name']); ?></h2>
                  <input type="hidden" name="eventosapp_badge_paper_templates[<?php echo esc_attr($key); ?>][key]" value="<?php echo esc_attr($key); ?>">

                  <label><?php _e('Nombre de la plantilla', 'eventosapp'); ?>
                    <input type="text" name="eventosapp_badge_paper_templates[<?php echo esc_attr($key); ?>][name]" value="<?php echo esc_attr($template['name']); ?>" required>
                  </label>

                  <div class="evapp-template-fields">
                    <label><?php _e('Ancho mm', 'eventosapp'); ?>
                      <input type="number" step="0.1" min="10" name="eventosapp_badge_paper_templates[<?php echo esc_attr($key); ?>][width_mm]" value="<?php echo esc_attr(eventosapp_badge_format_mm($template['width_mm'])); ?>" required>
                    </label>
                    <label><?php _e('Alto mm', 'eventosapp'); ?>
                      <input type="number" step="0.1" min="10" name="eventosapp_badge_paper_templates[<?php echo esc_attr($key); ?>][height_mm]" value="<?php echo esc_attr(eventosapp_badge_format_mm($template['height_mm'])); ?>" required>
                    </label>
                    <label><?php _e('Margen mm', 'eventosapp'); ?>
                      <input type="number" step="0.1" min="0" name="eventosapp_badge_paper_templates[<?php echo esc_attr($key); ?>][margin_mm]" value="<?php echo esc_attr(eventosapp_badge_format_mm($template['margin_mm'])); ?>" required>
                    </label>
                  </div>

                  <label><?php _e('Orientación de impresión', 'eventosapp'); ?>
                    <select name="eventosapp_badge_paper_templates[<?php echo esc_attr($key); ?>][orientation]">
                      <option value="landscape" <?php selected($template['orientation'], 'landscape'); ?>><?php _e('Horizontal', 'eventosapp'); ?></option>
                      <option value="portrait" <?php selected($template['orientation'], 'portrait'); ?>><?php _e('Vertical', 'eventosapp'); ?></option>
                    </select>
                  </label>

                  <label><?php _e('Rotación del driver', 'eventosapp'); ?>
                    <select name="eventosapp_badge_paper_templates[<?php echo esc_attr($key); ?>][driver_rotation]">
                      <?php foreach (eventosapp_badge_driver_rotation_options() as $rotation_key => $rotation_label): ?>
                        <option value="<?php echo esc_attr($rotation_key); ?>" <?php selected(eventosapp_badge_sanitize_driver_rotation($template['driver_rotation'] ?? 'auto'), $rotation_key); ?>><?php echo esc_html($rotation_label); ?></option>
                      <?php endforeach; ?>
                    </select>
                    <span class="description"><?php _e('Úsalo solo cuando el driver físico necesite Portrait 180 o Landscape 180. Si no aplica, deja Automático.', 'eventosapp'); ?></span>
                  </label>

                  <?php
                    $template_oriented = eventosapp_badge_get_oriented_template_dimensions($template);
                    $template_orientation_label = $template_oriented['orientation'] === 'portrait' ? __('vertical', 'eventosapp') : __('horizontal', 'eventosapp');
                  ?>
                  <div class="evapp-template-meta">
                    <span><?php printf(esc_html__('Tamaño base: %s × %s mm', 'eventosapp'), esc_html(eventosapp_badge_format_mm($template['width_mm'])), esc_html(eventosapp_badge_format_mm($template['height_mm']))); ?></span>
                    <span>· <?php printf(esc_html__('Lienzo de impresión: %s × %s mm', 'eventosapp'), esc_html(eventosapp_badge_format_mm($template_oriented['width_mm'])), esc_html(eventosapp_badge_format_mm($template_oriented['height_mm']))); ?></span>
                    <span>· <?php printf(esc_html__('Orientación %s', 'eventosapp'), esc_html($template_orientation_label)); ?></span>
                    <span>· <?php printf(esc_html__('Rotate driver: %s', 'eventosapp'), esc_html(eventosapp_badge_driver_rotation_label($template['driver_rotation'] ?? 'auto'))); ?></span>
                    <?php if (!empty($template['is_default'])): ?><span>· <?php _e('Plantilla base editable', 'eventosapp'); ?></span><?php endif; ?>
                  </div>

                  <label class="evapp-template-delete">
                    <input type="checkbox" name="eventosapp_badge_delete_template[<?php echo esc_attr($key); ?>]" value="1">
                    <?php _e('Eliminar esta plantilla', 'eventosapp'); ?>
                  </label>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="evapp-new-template">
            <label><?php _e('Nueva plantilla', 'eventosapp'); ?>
              <input type="text" name="eventosapp_badge_new_template_name" placeholder="<?php esc_attr_e('Ej. Escarapela sponsor', 'eventosapp'); ?>">
            </label>
            <label><?php _e('Ancho mm', 'eventosapp'); ?>
              <input type="number" step="0.1" min="10" name="eventosapp_badge_new_template_width_mm" value="100">
            </label>
            <label><?php _e('Alto mm', 'eventosapp'); ?>
              <input type="number" step="0.1" min="10" name="eventosapp_badge_new_template_height_mm" value="140">
            </label>
            <label><?php _e('Margen mm', 'eventosapp'); ?>
              <input type="number" step="0.1" min="0" name="eventosapp_badge_new_template_margin_mm" value="3">
            </label>
            <label><?php _e('Orientación de impresión', 'eventosapp'); ?>
              <select name="eventosapp_badge_new_template_orientation">
                <option value="portrait"><?php _e('Vertical', 'eventosapp'); ?></option>
                <option value="landscape"><?php _e('Horizontal', 'eventosapp'); ?></option>
              </select>
            </label>
            <label><?php _e('Rotación del driver', 'eventosapp'); ?>
              <select name="eventosapp_badge_new_template_driver_rotation">
                <?php foreach (eventosapp_badge_driver_rotation_options() as $rotation_key => $rotation_label): ?>
                  <option value="<?php echo esc_attr($rotation_key); ?>"><?php echo esc_html($rotation_label); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
        </div>

        <div class="evapp-library-two-cols">
          <section class="evapp-library-box">
            <h2><?php _e('Importar biblioteca', 'eventosapp'); ?></h2>
            <p><?php _e('Sube un archivo JSON exportado desde esta misma sección. Las plantillas importadas se agregan sin borrar las existentes.', 'eventosapp'); ?></p>
            <label><?php _e('Archivo JSON de plantillas', 'eventosapp'); ?>
              <input type="file" name="eventosapp_badge_templates_import" accept="application/json,.json">
            </label>
          </section>

          <section class="evapp-library-box">
            <h2><?php _e('Archivos de soporte para impresión', 'eventosapp'); ?></h2>
            <p><?php _e('Sube ZIP con el driver, instalador o herramienta recomendada. Cada archivo puede tener un nombre visible, botón de descarga y opción de eliminación.', 'eventosapp'); ?></p>

            <?php if (!empty($support_files)): ?>
              <div class="evapp-support-list">
                <?php foreach ($support_files as $file_key => $file): ?>
                  <div class="evapp-support-item">
                    <div>
                      <label><?php _e('Nombre visible', 'eventosapp'); ?>
                        <input type="text" name="eventosapp_badge_support_existing[<?php echo esc_attr($file_key); ?>][name]" value="<?php echo esc_attr($file['name']); ?>">
                      </label>
                      <span class="evapp-library-muted"><?php echo esc_html($file['filename']); ?><?php echo !empty($file['uploaded_at']) ? ' · ' . esc_html($file['uploaded_at']) : ''; ?></span>
                      <label class="evapp-support-delete"><input type="checkbox" name="eventosapp_badge_delete_support_file[<?php echo esc_attr($file_key); ?>]" value="1"> <?php _e('Eliminar este ZIP', 'eventosapp'); ?></label>
                    </div>
                    <a class="button" href="<?php echo esc_url($file['url']); ?>" target="_blank" rel="noopener"><?php _e('Descargar', 'eventosapp'); ?></a>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="description"><?php _e('Todavía no hay archivos de soporte cargados.', 'eventosapp'); ?></p>
            <?php endif; ?>

            <div class="evapp-library-form-row">
              <label><?php _e('Nombre archivo 1', 'eventosapp'); ?>
                <input type="text" name="eventosapp_badge_support_name_1" placeholder="<?php esc_attr_e('Ej. Driver impresora recomendada', 'eventosapp'); ?>">
              </label>
              <label><?php _e('ZIP archivo 1', 'eventosapp'); ?>
                <input type="file" name="eventosapp_badge_support_file_1" accept=".zip,application/zip">
              </label>
              <label><?php _e('Nombre archivo 2', 'eventosapp'); ?>
                <input type="text" name="eventosapp_badge_support_name_2" placeholder="<?php esc_attr_e('Ej. Instalador formatos de papel', 'eventosapp'); ?>">
              </label>
              <label><?php _e('ZIP archivo 2', 'eventosapp'); ?>
                <input type="file" name="eventosapp_badge_support_file_2" accept=".zip,application/zip">
              </label>
            </div>
          </section>
        </div>

        <div class="evapp-library-submit">
          <button type="submit" class="button button-primary button-large"><?php _e('Guardar biblioteca', 'eventosapp'); ?></button>
          <span class="description"><?php _e('Después de guardar, estas plantillas aparecerán en el metabox Configuración de Escarapela de cada evento.', 'eventosapp'); ?></span>
        </div>
      </form>
      <script>
      jQuery(function($){
        function evappBadgeLibraryNumber($input, fallback){
          var value = parseFloat($input.val());
          return isNaN(value) ? fallback : value;
        }
        function evappBadgeLibraryApplyCard($card){
          var baseWidth = evappBadgeLibraryNumber($card.find('input[name$="[width_mm]"]'), 99);
          var baseHeight = evappBadgeLibraryNumber($card.find('input[name$="[height_mm]"]'), 55);
          var margin = evappBadgeLibraryNumber($card.find('input[name$="[margin_mm]"]'), 0);
          var orientation = ($card.find('select[name$="[orientation]"]').val() || '').toString();
          if(orientation !== 'portrait' && orientation !== 'landscape'){
            orientation = baseHeight >= baseWidth ? 'portrait' : 'landscape';
          }
          var shortSide = Math.min(baseWidth, baseHeight);
          var longSide = Math.max(baseWidth, baseHeight);
          var width = orientation === 'portrait' ? shortSide : longSide;
          var height = orientation === 'portrait' ? longSide : shortSide;
          var canvasChanged = Math.abs(width - baseWidth) > 0.01 || Math.abs(height - baseHeight) > 0.01;
          var contentRotation = canvasChanged ? (orientation === 'portrait' ? -90 : 90) : 0;
          var ratio = width / Math.max(height, 1);
          var leftPct = Math.min(45, Math.max(0, (margin / Math.max(width, 1)) * 100));
          var topPct = Math.min(45, Math.max(0, (margin / Math.max(height, 1)) * 100));
          var $preview = $card.find('.evapp-paper-preview');
          var $safe = $card.find('.evapp-paper-safe');
          $preview.css('--paper-ratio', ratio).attr('data-content-rotation', contentRotation).attr('data-effective-width-mm', width).attr('data-effective-height-mm', height);
          $safe.css({left:leftPct+'%', right:leftPct+'%', top:topPct+'%', bottom:topPct+'%'});
          var $rotationLabel = $safe.find('.evapp-paper-rotation-label');
          if(canvasChanged){
            if(!$rotationLabel.length){
              $rotationLabel = $('<span class="evapp-paper-rotation-label"></span>').appendTo($safe);
            }
            $rotationLabel.text('Lienzo ' + width + ' × ' + height + ' mm · contenido ' + contentRotation + '°');
          } else {
            $rotationLabel.remove();
          }
        }
        $('.evapp-template-card').each(function(){ evappBadgeLibraryApplyCard($(this)); });
        $('.evapp-template-card').on('input change', 'input, select', function(){
          evappBadgeLibraryApplyCard($(this).closest('.evapp-template-card'));
        });
      });
      </script>
    </div>
    <?php
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
function eventosapp_badge_render_field_slot($field, $labels, $font_size, $font_weight, $margin, $qr_size, $text_align = 'center') {
    $field       = (string) $field;
    $font_size   = absint($font_size);
    $font_weight = absint($font_weight);
    $margin      = absint($margin);
    $qr_size     = absint($qr_size);
    $text_align  = sanitize_key($text_align);
    if (!in_array($text_align, ['left', 'center', 'right'], true)) {
        $text_align = 'center';
    }

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
    echo "<div class='slot' style='margin:" . $margin . "px; font-size:" . $font_size . "px; font-weight:" . $font_weight . "; text-align:" . esc_attr($text_align) . ";'>" . esc_html($label_text) . "</div>";
}

/**
 * Render del metabox.
 */
function eventosapp_render_badge_metabox($post) {
    wp_nonce_field('eventosapp_save_badge_settings', 'eventosapp_badge_nonce');

    $design = get_post_meta($post->ID, 'eventosapp_badge_design', true) ?: 'manillas';

    $order_fields = [];
    for ($i = 1; $i <= 5; $i++) {
        $order_fields[$i] = get_post_meta($post->ID, "eventosapp_field_order_{$i}", true) ?: 'none';
    }

    $paper_templates   = eventosapp_badge_get_paper_templates();
    $legacy_template   = eventosapp_badge_get_legacy_event_template($post->ID);
    $selected_key      = sanitize_key(get_post_meta($post->ID, 'eventosapp_badge_paper_template', true));
    $selected_template = eventosapp_badge_get_selected_paper_template($post->ID);
    $selected_oriented = eventosapp_badge_get_oriented_template_dimensions($selected_template);

    if ($selected_key === '' && $selected_template['key'] === 'legacy_event') {
        $selected_key = 'legacy_event';
    } elseif ($selected_key === '') {
        $selected_key = $selected_template['key'];
    }

    if ($selected_template['key'] === 'legacy_event') {
        $paper_templates = array_merge(['legacy_event' => $legacy_template], $paper_templates);
    } elseif (empty($paper_templates)) {
        $paper_templates = [$selected_template['key'] => $selected_template];
    }

    $allowed_text_align = ['left', 'center', 'right'];
    $text_align = sanitize_key(get_post_meta($post->ID, 'eventosapp_badge_text_align', true) ?: 'center');
    if (!in_array($text_align, $allowed_text_align, true)) {
        $text_align = 'center';
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
    if ($border_width === '' || $border_width === null) {
        $border_width = 0;
    }
    $border_width = (int) $border_width;

    $preview_ticket_key = get_post_meta($post->ID, 'eventosapp_badge_ticket_key', true) ?: '';
    $options            = eventosapp_get_all_available_fields($post->ID);
    $weights            = [100, 200, 300, 400, 500, 600, 700, 800];
    $library_url        = admin_url('admin.php?page=eventosapp_badge_library');

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
      .evapp-badge-metabox{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1d2327;max-width:100%;box-sizing:border-box;overflow:hidden}
      .evapp-badge-metabox *{box-sizing:border-box}
      .evapp-badge-intro{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;background:#f0f6fc;border:1px solid #c5d9ed;border-left:4px solid #2271b1;border-radius:10px;padding:13px 15px;margin:12px 0 16px}
      .evapp-badge-intro strong{display:block;margin-bottom:4px}.evapp-badge-intro p{margin:0;color:#50575e}.evapp-badge-intro .button{white-space:normal;text-align:center;min-height:36px;display:inline-flex;align-items:center;justify-content:center}
      .evapp-badge-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:16px;margin:0 0 16px;align-items:start}
      .evapp-badge-section{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.03);margin-bottom:16px;min-width:0;overflow:hidden}
      .evapp-badge-section h3{margin:0 0 12px;font-size:15px;line-height:1.3}.evapp-badge-section p{margin:6px 0;color:#50575e}
      .evapp-badge-field{margin:12px 0;min-width:0}.evapp-badge-field label{font-weight:600;display:block;margin-bottom:5px}
      .evapp-badge-field input[type=text],.evapp-badge-field input[type=number],.evapp-badge-field select{width:100%;max-width:100%;min-height:34px}.evapp-badge-help{display:block;margin-top:5px;color:#646970;font-size:12px}
      .evapp-paper-row{display:grid;grid-template-columns:minmax(130px,160px) minmax(0,1fr);gap:14px;align-items:center;margin-top:10px;min-width:0}
      .evapp-paper-preview{width:150px;max-width:100%;background:#fff;border:2px solid #1d2327;border-radius:7px;position:relative;box-shadow:0 8px 18px rgba(0,0,0,.08);overflow:hidden}
      .evapp-paper-preview::before{content:"";display:block;aspect-ratio:var(--paper-ratio,1.6)}
      .evapp-paper-safe{position:absolute;border:1px dashed #2271b1;background:rgba(34,113,177,.06);border-radius:5px;display:flex;flex-direction:column;gap:2px;align-items:center;justify-content:center;font-size:10px;color:#2271b1;text-align:center;padding:3px;box-sizing:border-box;overflow:hidden}.evapp-paper-safe-label{display:block;line-height:1.1}.evapp-paper-rotation-label{display:block;font-size:9px;line-height:1.05;font-weight:700;color:#135e96}
      .evapp-paper-meta{min-width:0}.evapp-paper-meta strong{display:block;font-size:14px;margin-bottom:4px;word-break:break-word}.evapp-paper-meta span{color:#50575e}
      .evapp-design-options{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px}.evapp-design-card{border:1px solid #dcdcde;border-radius:10px;padding:10px;background:#f6f7f7;cursor:pointer;display:block;min-width:0}.evapp-design-card input{margin-right:7px}.evapp-design-card:has(input:checked){border-color:#2271b1;background:#f0f6fc;box-shadow:0 0 0 1px #2271b1 inset}
      .evapp-field-map{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}.evapp-field-map label{font-weight:600;font-size:12px;display:block;margin-bottom:4px}.evapp-field-map select{width:100%;max-width:100%;min-height:34px}
      .evapp-style-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}.evapp-style-grid label{font-weight:600;font-size:12px;display:block;margin-bottom:4px}.evapp-style-grid input,.evapp-style-grid select{max-width:100%;min-height:34px}.evapp-style-grid input[type=number]{width:96px}.evapp-style-grid .evapp-style-combo{display:grid;grid-template-columns:minmax(0,96px) minmax(0,82px);gap:6px;align-items:center}.evapp-style-grid .evapp-style-combo label{grid-column:1/-1}.evapp-style-grid .evapp-style-combo input{width:96px}.evapp-style-grid .evapp-style-combo select{width:82px}
      .evapp-print-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px}.evapp-print-actions .button-primary{min-height:36px;white-space:normal}
      .evapp-live-paper{width:100%;max-width:560px;margin:0 auto;background:#fff;border:2px solid #1d2327;border-radius:9px;position:relative;box-shadow:0 14px 30px rgba(0,0,0,.13);overflow:hidden}.evapp-live-paper::before{content:"";display:block;aspect-ratio:var(--paper-ratio,1.6)}
      .evapp-live-safe{position:absolute;border:1px dashed #2271b1;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;box-sizing:border-box}.evapp-live-badge{width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#fff;overflow:hidden;text-align:var(--evapp-badge-text-align,center);flex:0 0 auto;transform-origin:center center;transition:transform .18s ease,width .18s ease,height .18s ease}.evapp-live-badge.design-escarapelas{flex-direction:column}.evapp-live-badge.design-manillas{flex-direction:row}.evapp-live-left,.evapp-live-right{display:flex;flex-direction:column;justify-content:center;align-items:center;min-width:0;max-height:100%}.evapp-live-left{flex:0 1 auto}.evapp-live-right{flex:0 0 auto}.evapp-live-slot{text-align:var(--evapp-badge-text-align,center);line-height:1.12;word-break:break-word;overflow-wrap:anywhere;max-width:100%;padding:1px 2px}.evapp-live-slot.is-qr{display:flex;align-items:center;justify-content:center}.evapp-live-slot.is-qr span{display:flex;align-items:center;justify-content:center;border:2px solid #1d2327;background:repeating-linear-gradient(45deg,#fff,#fff 3px,#e5e7eb 3px,#e5e7eb 6px);font-size:10px;font-weight:700;color:#1d2327}
      .evapp-live-caption{margin-top:12px;text-align:center;color:#50575e;font-size:12px}.evapp-live-caption strong{display:block;color:#1d2327;font-size:13px;margin-bottom:2px}
      @media (min-width:1500px){.evapp-badge-grid{grid-template-columns:minmax(0,1.05fr) minmax(420px,.95fr)}}
      @media (max-width:782px){.evapp-badge-intro{display:block}.evapp-badge-intro .button{margin-top:10px;width:100%}.evapp-design-options,.evapp-field-map,.evapp-style-grid{grid-template-columns:1fr}.evapp-paper-row{grid-template-columns:1fr}.evapp-paper-preview{margin:0 auto}.evapp-badge-section{padding:12px}.evapp-style-grid .evapp-style-combo{grid-template-columns:96px 82px}}
    </style>

    <div class="evapp-badge-metabox">
      <div class="evapp-badge-intro">
        <div>
          <strong><?php _e('Configuración por evento usando plantillas centralizadas.', 'eventosapp'); ?></strong>
          <p><?php _e('El evento solo escoge la plantilla, define orientación, mapea campos y ajusta estilos. Las medidas físicas se administran desde Biblioteca de Escarapelas.', 'eventosapp'); ?></p>
        </div>
        <a class="button" href="<?php echo esc_url($library_url); ?>"><?php _e('Abrir Biblioteca de Escarapelas', 'eventosapp'); ?></a>
      </div>

      <div class="evapp-badge-grid">
        <div>
          <section class="evapp-badge-section">
            <h3><?php _e('1. Plantilla de impresión', 'eventosapp'); ?></h3>
            <div class="evapp-badge-field">
              <label for="eventosapp_badge_paper_template"><?php _e('Plantilla activa', 'eventosapp'); ?></label>
              <select name="eventosapp_badge_paper_template" id="eventosapp_badge_paper_template">
                <?php foreach ($paper_templates as $key => $template): ?>
                  <?php
                    $option_layout = eventosapp_badge_get_oriented_template_dimensions($template);
                    $option_orientation_label = $option_layout['orientation'] === 'portrait' ? __('Vertical', 'eventosapp') : __('Horizontal', 'eventosapp');
                  ?>
                  <option value="<?php echo esc_attr($key); ?>" <?php selected($selected_key, $key); ?>
                          data-name="<?php echo esc_attr($template['name']); ?>"
                          data-width="<?php echo esc_attr(eventosapp_badge_format_mm($option_layout['width_mm'])); ?>"
                          data-height="<?php echo esc_attr(eventosapp_badge_format_mm($option_layout['height_mm'])); ?>"
                          data-base-width="<?php echo esc_attr(eventosapp_badge_format_mm($template['width_mm'])); ?>"
                          data-base-height="<?php echo esc_attr(eventosapp_badge_format_mm($template['height_mm'])); ?>"
                          data-margin="<?php echo esc_attr(eventosapp_badge_format_mm($template['margin_mm'])); ?>"
                          data-orientation="<?php echo esc_attr($option_layout['orientation']); ?>"
                          data-content-rotation="<?php echo esc_attr((int) $option_layout['content_rotation']); ?>">
                    <?php echo esc_html($template['name']); ?> — <?php echo esc_html(eventosapp_badge_format_mm($option_layout['width_mm'])); ?> × <?php echo esc_html(eventosapp_badge_format_mm($option_layout['height_mm'])); ?> mm · <?php printf(esc_html__('Lienzo %s', 'eventosapp'), esc_html($option_orientation_label)); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <span class="evapp-badge-help"><?php _e('Las medidas y márgenes se crean o editan en la nueva página Biblioteca de Escarapelas.', 'eventosapp'); ?></span>
            </div>

            <div class="evapp-paper-row">
              <div class="evapp-paper-preview" id="evapp_selected_paper_preview">
                <div class="evapp-paper-safe" id="evapp_selected_paper_safe"><?php _e('Área útil', 'eventosapp'); ?></div>
              </div>
              <div class="evapp-paper-meta">
                <strong id="evapp_selected_paper_name"><?php echo esc_html($selected_template['name']); ?></strong>
                <span id="evapp_selected_paper_size"><?php echo esc_html(eventosapp_badge_format_mm($selected_oriented['width_mm'])); ?> × <?php echo esc_html(eventosapp_badge_format_mm($selected_oriented['height_mm'])); ?> mm</span><br>
                <span id="evapp_selected_paper_margin"><?php printf(esc_html__('Margen: %s mm', 'eventosapp'), esc_html(eventosapp_badge_format_mm($selected_template['margin_mm']))); ?></span><br>
                <span id="evapp_selected_paper_orientation"><?php echo esc_html($selected_oriented['orientation'] === 'portrait' ? __('Vertical', 'eventosapp') : __('Horizontal', 'eventosapp')); ?></span>
              </div>
            </div>
          </section>

          <section class="evapp-badge-section">
            <h3><?php _e('2. Orientación de elementos', 'eventosapp'); ?></h3>
            <div class="evapp-design-options">
              <?php foreach ($design_labels as $key => $data): ?>
                <label class="evapp-design-card">
                  <input type="radio" name="eventosapp_badge_design" value="<?php echo esc_attr($key); ?>" <?php checked($design, $key); ?>>
                  <strong><?php echo esc_html($data['title']); ?></strong>
                  <span class="evapp-badge-help"><?php echo esc_html($data['desc']); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="evapp-badge-section">
            <h3><?php _e('3. Mapeo de contenido', 'eventosapp'); ?></h3>
            <div class="evapp-field-map">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <div>
                  <label for="eventosapp_field_order_<?php echo (int) $i; ?>"><?php printf(esc_html__('Campo %d', 'eventosapp'), (int) $i); ?></label>
                  <select name="eventosapp_field_order_<?php echo (int) $i; ?>" id="eventosapp_field_order_<?php echo (int) $i; ?>" class="evapp-field-order">
                    <?php foreach ($options as $val => $lab): ?>
                      <option value="<?php echo esc_attr($val); ?>" <?php selected($order_fields[$i], $val); ?>><?php echo esc_html($lab); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php endfor; ?>
            </div>
          </section>

          <section class="evapp-badge-section">
            <h3><?php _e('4. Estilo de textos, QR y borde', 'eventosapp'); ?></h3>
            <div class="evapp-style-grid">
              <div class="evapp-style-combo">
                <label><?php _e('Texto grande', 'eventosapp'); ?></label>
                <input type="number" name="eventosapp_badge_size_large" id="eventosapp_badge_size_large" value="<?php echo esc_attr($size_large); ?>" min="1"> px
                <select name="eventosapp_badge_weight_large" id="eventosapp_badge_weight_large">
                  <?php foreach ($weights as $w) echo '<option value="'.$w.'"'.selected($weight_large, $w, false).'>'.$w.'</option>'; ?>
                </select>
              </div>
              <div class="evapp-style-combo">
                <label><?php _e('Texto mediano', 'eventosapp'); ?></label>
                <input type="number" name="eventosapp_badge_size_medium" id="eventosapp_badge_size_medium" value="<?php echo esc_attr($size_medium); ?>" min="1"> px
                <select name="eventosapp_badge_weight_medium" id="eventosapp_badge_weight_medium">
                  <?php foreach ($weights as $w) echo '<option value="'.$w.'"'.selected($weight_medium, $w, false).'>'.$w.'</option>'; ?>
                </select>
              </div>
              <div class="evapp-style-combo">
                <label><?php _e('Texto pequeño', 'eventosapp'); ?></label>
                <input type="number" name="eventosapp_badge_size_small" id="eventosapp_badge_size_small" value="<?php echo esc_attr($size_small); ?>" min="1"> px
                <select name="eventosapp_badge_weight_small" id="eventosapp_badge_weight_small">
                  <?php foreach ($weights as $w) echo '<option value="'.$w.'"'.selected($weight_small, $w, false).'>'.$w.'</option>'; ?>
                </select>
              </div>
              <div>
                <label><?php _e('Separación vertical', 'eventosapp'); ?></label>
                <input type="number" name="eventosapp_badge_sep_vertical" id="eventosapp_badge_sep_vertical" value="<?php echo esc_attr($sep_vertical); ?>" min="0"> px
              </div>
              <div>
                <label><?php _e('Separación horizontal', 'eventosapp'); ?></label>
                <input type="number" name="eventosapp_badge_sep_horizontal" id="eventosapp_badge_sep_horizontal" value="<?php echo esc_attr($sep_horizontal); ?>" min="0"> px
              </div>
              <div>
                <label><?php _e('Tamaño del QR', 'eventosapp'); ?></label>
                <input type="number" name="eventosapp_badge_qr_size" id="eventosapp_badge_qr_size" value="<?php echo esc_attr($qr_size); ?>" min="1"> px
              </div>
              <div>
                <label><?php _e('Grosor del borde', 'eventosapp'); ?></label>
                <input type="number" name="eventosapp_badge_border_width" id="eventosapp_badge_border_width" value="<?php echo esc_attr($border_width); ?>" min="0"> px
              </div>
              <div>
                <label for="eventosapp_badge_text_align"><?php _e('Alineación del texto', 'eventosapp'); ?></label>
                <select name="eventosapp_badge_text_align" id="eventosapp_badge_text_align">
                  <option value="left" <?php selected($text_align, 'left'); ?>><?php _e('Izquierda', 'eventosapp'); ?></option>
                  <option value="center" <?php selected($text_align, 'center'); ?>><?php _e('Centro', 'eventosapp'); ?></option>
                  <option value="right" <?php selected($text_align, 'right'); ?>><?php _e('Derecha', 'eventosapp'); ?></option>
                </select>
              </div>
            </div>
          </section>
        </div>

        <aside>
          <section class="evapp-badge-section">
            <h3><?php _e('Vista previa del formato', 'eventosapp'); ?></h3>
            <div class="evapp-live-paper" id="evapp_live_paper">
              <div class="evapp-live-safe" id="evapp_live_safe">
                <div class="evapp-live-badge" id="evapp_live_badge"></div>
              </div>
            </div>
            <div class="evapp-live-caption">
              <strong id="evapp_live_template_name"><?php echo esc_html($selected_template['name']); ?></strong>
              <span id="evapp_live_template_detail"><?php echo esc_html(eventosapp_badge_format_mm($selected_oriented['width_mm'])); ?> × <?php echo esc_html(eventosapp_badge_format_mm($selected_oriented['height_mm'])); ?> mm</span>
            </div>
          </section>

          <section class="evapp-badge-section">
            <h3><?php _e('Vista previa / prueba de impresión', 'eventosapp'); ?></h3>
            <div class="evapp-badge-field">
              <label for="eventosapp_badge_ticket_key"><?php _e('Número de ticket (ID público)', 'eventosapp'); ?></label>
              <input type="text" name="eventosapp_badge_ticket_key" id="eventosapp_badge_ticket_key" value="<?php echo esc_attr($preview_ticket_key); ?>" placeholder="p. ej. tkcdG7ejZvDjWAD">
              <span class="evapp-badge-help"><?php _e('Opcional. Si lo dejas vacío se abre una vista con textos de muestra.', 'eventosapp'); ?></span>
            </div>
            <div class="evapp-print-actions">
              <button type="button" class="button button-primary" id="eventosapp_download_badge"><?php _e('Vista previa / Imprimir escarapela', 'eventosapp'); ?></button>
            </div>
            <p class="evapp-badge-help"><?php _e('Para impresión precisa, usa escala 100% y desactiva “ajustar a página” en el cuadro de impresión cuando el navegador lo muestre.', 'eventosapp'); ?></p>
          </section>
        </aside>
      </div>
    </div>

    <script>
    jQuery(function($){
      function evappEscapeHtml(value){
        return $('<div/>').text(value || '').html();
      }

      function evappNumber(selector, fallback){
        var value = parseFloat($(selector).val());
        return isNaN(value) ? fallback : value;
      }

      function evappSelectedPaper(){
        var $selected = $('#eventosapp_badge_paper_template option:selected');
        return {
          name: ($selected.data('name') || $selected.text() || '').toString(),
          width: parseFloat($selected.data('width')) || 99,
          height: parseFloat($selected.data('height')) || 55,
          baseWidth: parseFloat($selected.data('base-width')) || parseFloat($selected.data('width')) || 99,
          baseHeight: parseFloat($selected.data('base-height')) || parseFloat($selected.data('height')) || 55,
          margin: parseFloat($selected.data('margin')) || 0,
          orientation: ($selected.data('orientation') || '').toString(),
          contentRotation: parseInt($selected.data('content-rotation'), 10) || 0
        };
      }

      function evappApplyPaperPreview(){
        var paper = evappSelectedPaper();
        var ratio = paper.width / Math.max(paper.height, 1);
        var leftPct = Math.min(45, Math.max(0, (paper.margin / Math.max(paper.width, 1)) * 100));
        var topPct = Math.min(45, Math.max(0, (paper.margin / Math.max(paper.height, 1)) * 100));
        var orientation = (paper.orientation === 'portrait' || paper.orientation === 'landscape')
          ? paper.orientation
          : (paper.height >= paper.width ? 'portrait' : 'landscape');
        var orientationText = orientation === 'portrait' ? '<?php echo esc_js(__('Vertical', 'eventosapp')); ?>' : '<?php echo esc_js(__('Horizontal', 'eventosapp')); ?>';
        var baseText = paper.baseWidth + ' × ' + paper.baseHeight + ' mm';
        var canvasText = paper.width + ' × ' + paper.height + ' mm';
        var safeWidth = Math.max(0.1, paper.width - (paper.margin * 2));
        var safeHeight = Math.max(0.1, paper.height - (paper.margin * 2));
        var contentRotation = paper.contentRotation === -90 || paper.contentRotation === 90 ? paper.contentRotation : 0;
        var contentWidth = contentRotation !== 0 ? safeHeight : safeWidth;
        var contentHeight = contentRotation !== 0 ? safeWidth : safeHeight;
        var contentWidthPct = Math.max(1, (contentWidth / Math.max(safeWidth, 0.1)) * 100);
        var contentHeightPct = Math.max(1, (contentHeight / Math.max(safeHeight, 0.1)) * 100);

        $('#evapp_selected_paper_preview, #evapp_live_paper').css('--paper-ratio', ratio).attr('data-content-rotation', contentRotation);
        $('#evapp_selected_paper_safe, #evapp_live_safe').css({left:leftPct+'%', right:leftPct+'%', top:topPct+'%', bottom:topPct+'%'});
        $('#evapp_live_badge').css({
          transform: contentRotation !== 0 ? 'rotate(' + contentRotation + 'deg)' : 'none',
          width: contentWidthPct + '%',
          height: contentHeightPct + '%',
          'transform-origin':'center center'
        });
        $('#evapp_selected_paper_safe').html('<span class="evapp-paper-safe-label"><?php echo esc_js(__('Área útil', 'eventosapp')); ?></span>' + (contentRotation !== 0 ? '<span class="evapp-paper-rotation-label"><?php echo esc_js(__('Contenido', 'eventosapp')); ?> ' + contentRotation + '°</span>' : ''));
        $('#evapp_selected_paper_name, #evapp_live_template_name').text(paper.name);
        $('#evapp_selected_paper_size').text(canvasText);
        $('#evapp_selected_paper_margin').text('<?php echo esc_js(__('Margen:', 'eventosapp')); ?> ' + paper.margin + ' mm');
        $('#evapp_selected_paper_orientation').text(orientationText + ' · <?php echo esc_js(__('lienzo', 'eventosapp')); ?> ' + canvasText + (contentRotation !== 0 ? ' · <?php echo esc_js(__('contenido', 'eventosapp')); ?> ' + contentRotation + '°' : ''));
        $('#evapp_live_template_detail').text('<?php echo esc_js(__('Lienzo de impresión', 'eventosapp')); ?> ' + canvasText + ' · <?php echo esc_js(__('Base', 'eventosapp')); ?> ' + baseText + ' · <?php echo esc_js(__('Margen', 'eventosapp')); ?> ' + paper.margin + ' mm' + (contentRotation !== 0 ? ' · <?php echo esc_js(__('Contenido', 'eventosapp')); ?> ' + contentRotation + '°' : ''));
      }

      function evappSlotHtml(field, label, level){
        var isQr = field === 'qr' || field === 'qr_networking';
        var margin = Math.max(1, Math.round((level === 'horizontal' ? evappNumber('#eventosapp_badge_sep_horizontal', 4) : evappNumber('#eventosapp_badge_sep_vertical', 4)) * 0.35));
        if(isQr){
          var qr = Math.max(24, Math.min(82, Math.round(evappNumber('#eventosapp_badge_qr_size', 72) * 0.52)));
          return '<div class="evapp-live-slot is-qr" style="margin:'+margin+'px"><span style="width:'+qr+'px;height:'+qr+'px">QR</span></div>';
        }

        var size = evappNumber('#eventosapp_badge_size_medium', 18);
        var weight = evappNumber('#eventosapp_badge_weight_medium', 500);
        if(level === 'large'){
          size = evappNumber('#eventosapp_badge_size_large', 24);
          weight = evappNumber('#eventosapp_badge_weight_large', 600);
        } else if(level === 'small'){
          size = evappNumber('#eventosapp_badge_size_small', 14);
          weight = evappNumber('#eventosapp_badge_weight_small', 400);
        }

        var previewSize = Math.max(8, Math.min(24, Math.round(size * 0.52)));
        var align = ($('#eventosapp_badge_text_align').val() || 'center').toString();
        return '<div class="evapp-live-slot" style="margin:'+margin+'px;font-size:'+previewSize+'px;font-weight:'+weight+';text-align:'+align+'">'+evappEscapeHtml(label)+'</div>';
      }

      function evappBuildLivePreview(){
        evappApplyPaperPreview();

        var active = [];
        $('.evapp-field-order').each(function(){
          var field = $(this).val();
          if(field && field !== 'none'){
            active.push({field: field, label: $(this).find('option:selected').text()});
          }
        });
        if(!active.length){
          active = [
            {field:'full_name', label:'Nombres + Apellidos'},
            {field:'company', label:'Nombre de la Empresa'},
            {field:'qr', label:'QR'}
          ];
        }

        var design = $('input[name="eventosapp_badge_design"]:checked').val() || 'manillas';
        var border = Math.max(0, Math.round(evappNumber('#eventosapp_badge_border_width', 0)));
        var textAlign = ($('#eventosapp_badge_text_align').val() || 'center').toString();
        var html = '';
        var cls = 'design-' + design;

        if(design === 'escarapelas_split'){
          var left3 = active.slice(0, 3);
          html += '<div class="evapp-live-left">';
          $.each(left3, function(index, item){
            html += evappSlotHtml(item.field, item.label, index === 0 ? 'large' : (index === 1 ? 'medium' : 'small'));
          });
          html += '</div><div class="evapp-live-right" style="margin-left:'+Math.max(1, Math.round(evappNumber('#eventosapp_badge_sep_horizontal', 4) * 0.45))+'px">';
          if(active[3]) html += evappSlotHtml(active[3].field, active[3].label, 'medium');
          html += '</div>';
        } else if(design === 'escarapelas_split_4'){
          var left4 = active.slice(0, 4);
          html += '<div class="evapp-live-left">';
          $.each(left4, function(index, item){
            html += evappSlotHtml(item.field, item.label, index === 0 ? 'large' : (index <= 2 ? 'medium' : 'small'));
          });
          html += '</div><div class="evapp-live-right" style="margin-left:'+Math.max(1, Math.round(evappNumber('#eventosapp_badge_sep_horizontal', 4) * 0.45))+'px">';
          if(active[4]) html += evappSlotHtml(active[4].field, active[4].label, 'medium');
          html += '</div>';
        } else {
          $.each(active, function(index, item){
            var level = 'horizontal';
            if(design === 'escarapelas'){
              level = index === 0 ? 'large' : (index <= 2 ? 'medium' : 'small');
            }
            html += evappSlotHtml(item.field, item.label, level);
          });
        }

        $('#evapp_live_badge')
          .removeClass('design-manillas design-escarapelas design-escarapelas_split design-escarapelas_split_4')
          .addClass(cls)
          .css({
            'border': border > 0 ? border + 'px solid #000' : 'none',
            '--evapp-badge-text-align': textAlign,
            'text-align': textAlign
          })
          .html(html);
      }

      $('#eventosapp_badge_paper_template, .evapp-field-order, input[name="eventosapp_badge_design"], #eventosapp_badge_size_large, #eventosapp_badge_size_medium, #eventosapp_badge_size_small, #eventosapp_badge_weight_large, #eventosapp_badge_weight_medium, #eventosapp_badge_weight_small, #eventosapp_badge_sep_vertical, #eventosapp_badge_sep_horizontal, #eventosapp_badge_qr_size, #eventosapp_badge_border_width, #eventosapp_badge_text_align').on('change keyup input', evappBuildLivePreview);
      evappBuildLivePreview();

      $('#eventosapp_download_badge').on('click', function(e){
        e.preventDefault();
        var ticketKey = $('input[name="eventosapp_badge_ticket_key"]').val() || '';
        var url = '<?php echo admin_url('admin-ajax.php'); ?>' +
                  '?action=eventosapp_download_badge' +
                  '&post_id=<?php echo (int) $post->ID; ?>' +
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

    for ($i = 1; $i <= 5; $i++) {
        $k = "eventosapp_field_order_{$i}";
        update_post_meta($post_id, $k, sanitize_key($_POST[$k] ?? 'none'));
    }

    /*
     * Ancho/alto directos ya no se guardan desde el metabox. Se conservan metas
     * heredadas existentes únicamente como fallback para eventos antiguos.
     */
    foreach (['large','medium','small'] as $s) {
        if (isset($_POST["eventosapp_badge_size_{$s}"])) {
            update_post_meta($post_id, "eventosapp_badge_size_{$s}", absint($_POST["eventosapp_badge_size_{$s}"]));
        }
        if (isset($_POST["eventosapp_badge_weight_{$s}"])) {
            update_post_meta($post_id, "eventosapp_badge_weight_{$s}", absint($_POST["eventosapp_badge_weight_{$s}"]));
        }
    }

    foreach (['vertical','horizontal'] as $dir) {
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
    if (isset($_POST['eventosapp_badge_text_align'])) {
        $text_align = sanitize_key($_POST['eventosapp_badge_text_align']);
        if (in_array($text_align, ['left', 'center', 'right'], true)) {
            update_post_meta($post_id, 'eventosapp_badge_text_align', $text_align);
        }
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

    $text_align = sanitize_key($get('eventosapp_badge_text_align', 'center'));
    if (!in_array($text_align, ['left', 'center', 'right'], true)) {
        $text_align = 'center';
    }

    $paper_template = eventosapp_badge_get_selected_paper_template($evento_id);
    $paper_base_width_mm  = eventosapp_badge_sanitize_mm($paper_template['width_mm'] ?? 99, 99, 10, 1000);
    $paper_base_height_mm = eventosapp_badge_sanitize_mm($paper_template['height_mm'] ?? 55, 55, 10, 1000);
    $paper_orientation_info = eventosapp_badge_get_oriented_paper_dimensions(
        $paper_base_width_mm,
        $paper_base_height_mm,
        $paper_template['orientation'] ?? ''
    );
    $paper_width_mm  = eventosapp_badge_sanitize_mm($paper_orientation_info['width_mm'], 99, 10, 1000);
    $paper_height_mm = eventosapp_badge_sanitize_mm($paper_orientation_info['height_mm'], 55, 10, 1000);
    $paper_margin_mm = eventosapp_badge_sanitize_mm($paper_template['margin_mm'] ?? 0, 0, 0, max(0, min($paper_width_mm, $paper_height_mm) / 2 - 0.5));
    $paper_orientation = sanitize_key($paper_orientation_info['orientation'] ?? (($paper_height_mm >= $paper_width_mm) ? 'portrait' : 'landscape'));
    $paper_page_orientation = sanitize_key($paper_orientation_info['page_orientation'] ?? (($paper_height_mm >= $paper_width_mm) ? 'portrait' : 'landscape'));
    $paper_content_rotation = (int) ($paper_orientation_info['content_rotation'] ?? 0);
    if (!in_array($paper_orientation, ['portrait', 'landscape'], true)) {
        $paper_orientation = $paper_page_orientation;
    }
    if (!in_array($paper_page_orientation, ['portrait', 'landscape'], true)) {
        $paper_page_orientation = ($paper_height_mm >= $paper_width_mm) ? 'portrait' : 'landscape';
    }
    if (!in_array($paper_content_rotation, [-90, 0, 90], true)) {
        $paper_content_rotation = 0;
    }

    $paper_inner_width_mm  = max(0.1, $paper_width_mm - ($paper_margin_mm * 2));
    $paper_inner_height_mm = max(0.1, $paper_height_mm - ($paper_margin_mm * 2));
    $paper_content_width_mm  = $paper_content_rotation !== 0 ? $paper_inner_height_mm : $paper_inner_width_mm;
    $paper_content_height_mm = $paper_content_rotation !== 0 ? $paper_inner_width_mm : $paper_inner_height_mm;

    /*
     * width/height se conservan como equivalencia CSS para funciones heredadas.
     * La impresión real usa el tamaño final del lienzo en @page y en el contenedor HTML.
     */
    $width_px  = max(1, (int) round(($paper_width_mm * 96) / 25.4));
    $height_px = max(1, (int) round(($paper_height_mm * 96) / 25.4));

    return [
        'design'              => $design,
        'order'               => $order,
        'paper_template_key'  => sanitize_key($paper_template['key'] ?? 'legacy_standard'),
        'paper_template_name' => sanitize_text_field($paper_template['name'] ?? __('Formato de papel', 'eventosapp')),
        'paper_base_width_mm' => $paper_base_width_mm,
        'paper_base_height_mm'=> $paper_base_height_mm,
        'paper_width_mm'      => $paper_width_mm,
        'paper_height_mm'     => $paper_height_mm,
        'paper_margin_mm'     => $paper_margin_mm,
        'paper_orientation'   => $paper_orientation,
        'paper_page_orientation' => $paper_page_orientation,
        'paper_content_rotation' => $paper_content_rotation,
        'paper_inner_width_mm'   => $paper_inner_width_mm,
        'paper_inner_height_mm'  => $paper_inner_height_mm,
        'paper_content_width_mm' => $paper_content_width_mm,
        'paper_content_height_mm'=> $paper_content_height_mm,
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
        'text_align'          => $text_align,
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
    $paper_inner_width_mm = eventosapp_badge_format_mm($cfg['paper_inner_width_mm'] ?? max(0.1, (float) $cfg['paper_width_mm'] - ((float) $cfg['paper_margin_mm'] * 2)));
    $paper_inner_height_mm = eventosapp_badge_format_mm($cfg['paper_inner_height_mm'] ?? max(0.1, (float) $cfg['paper_height_mm'] - ((float) $cfg['paper_margin_mm'] * 2)));
    $paper_content_width_mm = eventosapp_badge_format_mm($cfg['paper_content_width_mm'] ?? $cfg['paper_inner_width_mm'] ?? $cfg['paper_width_mm']);
    $paper_content_height_mm = eventosapp_badge_format_mm($cfg['paper_content_height_mm'] ?? $cfg['paper_inner_height_mm'] ?? $cfg['paper_height_mm']);
    $paper_orientation = sanitize_key($cfg['paper_orientation'] ?? (($cfg['paper_height_mm'] >= $cfg['paper_width_mm']) ? 'portrait' : 'landscape'));
    if (!in_array($paper_orientation, ['portrait', 'landscape'], true)) {
        $paper_orientation = ($cfg['paper_height_mm'] >= $cfg['paper_width_mm']) ? 'portrait' : 'landscape';
    }
    $paper_page_orientation = sanitize_key($cfg['paper_page_orientation'] ?? (($cfg['paper_height_mm'] >= $cfg['paper_width_mm']) ? 'portrait' : 'landscape'));
    if (!in_array($paper_page_orientation, ['portrait', 'landscape'], true)) {
        $paper_page_orientation = ($cfg['paper_height_mm'] >= $cfg['paper_width_mm']) ? 'portrait' : 'landscape';
    }
    $paper_content_rotation = (int) ($cfg['paper_content_rotation'] ?? 0);
    if (!in_array($paper_content_rotation, [-90, 0, 90], true)) {
        $paper_content_rotation = 0;
    }
    $paper_content_transform = $paper_content_rotation !== 0 ? 'rotate(' . $paper_content_rotation . 'deg)' : 'none';
    $border_width    = max(0, (int) $cfg['border_width']);
    $text_align      = sanitize_key($cfg['text_align'] ?? 'center');
    if (!in_array($text_align, ['left', 'center', 'right'], true)) {
        $text_align = 'center';
    }

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
    padding:0;
    position:relative;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#fff;
    overflow:hidden;
    box-sizing:border-box;
    print-color-adjust:exact;
    -webkit-print-color-adjust:exact;
  }
  .badge-print-safe{
    position:absolute;
    left:<?php echo esc_html($paper_margin_mm); ?>mm;
    top:<?php echo esc_html($paper_margin_mm); ?>mm;
    width:<?php echo esc_html($paper_inner_width_mm); ?>mm;
    height:<?php echo esc_html($paper_inner_height_mm); ?>mm;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    background:#fff;
  }
  .badge{
    <?php echo ($border_width > 0 ? "border:" . $border_width . "px solid #000;" : "border:none;"); ?>
    display:flex;
    flex-direction:<?php echo esc_attr($flex_dir); ?>;
    align-items:center;
    justify-content:center;
    width:<?php echo esc_html($paper_content_width_mm); ?>mm;
    height:<?php echo esc_html($paper_content_height_mm); ?>mm;
    flex:0 0 auto;
    padding:0;
    box-sizing:border-box;
    overflow:hidden;
    background:#fff;
    text-align:<?php echo esc_attr($text_align); ?>;
    transform:<?php echo esc_attr($paper_content_transform); ?>;
    transform-origin:center center;
  }
  .left,.right{display:flex;flex-direction:column;justify-content:center;align-items:center;min-width:0;max-height:100%;}
  .left{flex:0 1 auto;}
  .right{flex:0 0 auto;}
  .slot{line-height:1.15;text-align:<?php echo esc_attr($text_align); ?>;word-break:break-word;overflow-wrap:anywhere;max-width:100%;}
  .slot-qr{display:flex;align-items:center;justify-content:center;}
  .slot-qr img{display:block;max-width:100%;height:auto;}
  @media screen {
    body{min-height:100vh;padding:24px;}
    .badge-print-page{box-shadow:0 12px 30px rgba(0,0,0,.18);}
  }
  @media print {
    html,body{width:<?php echo esc_html($paper_width_mm); ?>mm;height:<?php echo esc_html($paper_height_mm); ?>mm;min-height:0;background:#fff;display:block;}
    body{padding:0;}
    .badge-print-page{width:<?php echo esc_html($paper_width_mm); ?>mm;height:<?php echo esc_html($paper_height_mm); ?>mm;margin:0;padding:0;box-shadow:none;page-break-after:avoid;page-break-before:avoid;}
  }
</style>
</head>
<body>
<div class="badge-print-page"
     data-paper-template="<?php echo esc_attr($cfg['paper_template_key']); ?>"
     data-paper-width-mm="<?php echo esc_attr($paper_width_mm); ?>"
     data-paper-height-mm="<?php echo esc_attr($paper_height_mm); ?>"
     data-paper-margin-mm="<?php echo esc_attr($paper_margin_mm); ?>"
     data-paper-orientation="<?php echo esc_attr($paper_orientation); ?>"
     data-paper-page-orientation="<?php echo esc_attr($paper_page_orientation); ?>"
     data-content-rotation="<?php echo esc_attr($paper_content_rotation); ?>">
  <div class="badge-print-safe">
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
            eventosapp_badge_render_field_slot($field, $labels, $fs, $fw, $cfg['sep_vertical'], $cfg['qr_size'], $cfg['text_align']);
        }
        echo "</div>";

        $mh = absint($cfg['sep_horizontal']);
        echo "<div class='right' style='margin-left:{$mh}px'>";
        if ($right) {
            eventosapp_badge_render_field_slot($right, $labels, $cfg['size_medium'], $cfg['weight_medium'], $cfg['sep_vertical'], $cfg['qr_size'], $cfg['text_align']);
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
            eventosapp_badge_render_field_slot($field, $labels, $fs, $fw, $cfg['sep_vertical'], $cfg['qr_size'], $cfg['text_align']);
        }
        echo "</div>";

        $mh = absint($cfg['sep_horizontal']);
        echo "<div class='right' style='margin-left:{$mh}px'>";
        if ($right) {
            eventosapp_badge_render_field_slot($right, $labels, $cfg['size_medium'], $cfg['weight_medium'], $cfg['sep_vertical'], $cfg['qr_size'], $cfg['text_align']);
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
            eventosapp_badge_render_field_slot($field, $labels, $fs, $fw, $margin, $cfg['qr_size'], $cfg['text_align']);
        }
    }
?>
    </div>
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
