<?php
/**
 * Sistema de condicionales para Webhook → EventosApp
 * Permite configurar reglas que determinan si se envía correo y con qué plantilla
 *
 * Ahora soporta múltiples criterios por regla:
 * - match = all  => deben cumplirse TODOS los criterios
 * - match = any  => basta con que se cumpla AL MENOS UNO
 *
 * Compatibilidad:
 * - Sigue leyendo reglas antiguas con estructura simple:
 *   field + operator + value + action + template
 *
 * @package EventosApp
 */

if (!defined('ABSPATH')) exit;

/**
 * Convierte un valor de condición a texto seguro para comparar.
 * Permite que los campos tipo lista/array también puedan evaluarse.
 */
function eventosapp_condition_value_to_string($value) {
    if (is_array($value)) {
        $parts = [];
        array_walk_recursive($value, function($item) use (&$parts) {
            if (is_scalar($item) || $item === null) {
                $parts[] = (string) $item;
            }
        });
        return implode(', ', $parts);
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if ($value === null) {
        return '';
    }

    return is_scalar($value) ? (string) $value : '';
}

/**
 * Normaliza texto para condicionales:
 * - quita HTML
 * - decodifica entidades
 * - elimina tildes cuando WordPress lo permite
 * - convierte a minúsculas
 * - compacta espacios
 */
function eventosapp_condition_normalize_text($value) {
    $value = eventosapp_condition_value_to_string($value);
    $value = html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
    $value = trim($value);

    if (function_exists('remove_accents')) {
        $value = remove_accents($value);
    }

    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }

    $value = preg_replace('/\s+/u', ' ', $value);
    return trim((string) $value);
}

/**
 * Divide el valor configurado cuando se quieren aceptar varios valores.
 * Uso recomendado en el metabox: Virtual|Presencial|Híbrido.
 */
function eventosapp_condition_compare_values($compare_value) {
    $raw = eventosapp_condition_value_to_string($compare_value);
    if ($raw === '') {
        return [''];
    }

    $parts = preg_split('/\s*\|\s*/', $raw);
    $parts = array_map('trim', is_array($parts) ? $parts : [$raw]);
    $parts = array_values(array_filter($parts, function($item) {
        return $item !== '';
    }));

    return $parts ?: [''];
}

/**
 * Devuelve una etiqueta legible para logs y diagnóstico.
 */
function eventosapp_condition_operator_label($operator) {
    $labels = [
        'equals'       => 'Es igual a',
        'not_equals'   => 'No es igual a',
        'contains'     => 'Contiene',
        'not_contains' => 'No contiene',
        'starts_with'  => 'Comienza con',
        'ends_with'    => 'Termina con',
        'is_empty'     => 'Está vacío',
        'is_not_empty' => 'No está vacío',
    ];

    return $labels[$operator] ?? $operator;
}

/**
 * Obtiene las plantillas de correo disponibles
 */
function eventosapp_get_available_email_templates() {
    // Directorio real de plantillas del plugin: includes/templates/email_tickets/.
    // También se conserva compatibilidad con instalaciones antiguas que hayan usado /templates/email_tickets/.
    $templates = [];
    $plugin_root = plugin_dir_path(dirname(dirname(__FILE__)));
    $dirs = [
        $plugin_root . 'includes/templates/email_tickets/',
        $plugin_root . 'templates/email_tickets/',
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        $files = glob(trailingslashit($dir) . '*.html');
        foreach ((array) $files as $file) {
            if (!is_readable($file)) continue;
            $basename = basename($file);
            if (isset($templates[$basename])) continue;
            $label = str_replace(['-', '_', '.html'], [' ', ' ', ''], $basename);
            $label = trim(ucwords($label));
            $templates[$basename] = $label ?: $basename;
        }
    }

    if (empty($templates)) {
        $templates['email-ticket.html'] = 'Email Ticket (por defecto)';
    } elseif (isset($templates['email-ticket.html'])) {
        $templates = ['email-ticket.html' => 'Email Ticket (por defecto)'] + array_diff_key($templates, ['email-ticket.html' => true]);
    }

    return apply_filters('eventosapp_available_email_templates', $templates, $dirs);
}

/**
 * Obtiene los campos disponibles para las condicionales (base + extras del evento)
 */
function eventosapp_get_conditional_fields($event_id) {
    $fields = [
        // Campos base del ticket
        'email'     => 'Email',
        'nombre'    => 'Nombre',
        'apellido'  => 'Apellido',
        'cc'        => 'Cédula/CC',
        'nit'       => 'NIT',
        'empresa'   => 'Empresa',
        'cargo'     => 'Cargo',
        'tel'       => 'Teléfono',
        'ciudad'    => 'Ciudad',
        'pais'      => 'País',
        'localidad' => 'Localidad',
    ];

    // Alias aceptados por el webhook para evitar que una regla vieja quede apuntando a un campo vacío.
    $fields['first_name']       = 'Nombre (alias webhook first_name)';
    $fields['last_name']        = 'Apellido (alias webhook last_name)';
    $fields['phone']            = 'Teléfono (alias webhook phone)';
    $fields['company']          = 'Empresa (alias webhook company)';
    $fields['city']             = 'Ciudad (alias webhook city)';
    $fields['country']          = 'País (alias webhook country)';
    $fields['ticket_localidad'] = 'Localidad (alias webhook ticket_localidad)';
    $fields['external_id']      = 'External ID / ID externo';

    // Agregar campos extras del evento si existen.
    if (function_exists('eventosapp_get_event_extra_fields') && $event_id) {
        $extras = eventosapp_get_event_extra_fields($event_id);
        if (is_array($extras)) {
            foreach ($extras as $extra) {
                if (empty($extra['key'])) {
                    continue;
                }

                $key   = sanitize_key((string) $extra['key']);
                $label = !empty($extra['label']) ? (string) $extra['label'] : $key;

                if ($key === '') {
                    continue;
                }

                $fields['extra_' . $key] = $label . ' (extra)';
            }
        }
    }

    /**
     * Permite que otros módulos agreguen campos a las condicionales.
     */
    $fields = apply_filters('eventosapp_webhook_conditional_fields', $fields, $event_id);

    return is_array($fields) ? $fields : [];
}

/**
 * Obtiene el valor de un campo del ticket.
 *
 * Importante:
 * - Las condicionales se evalúan después de crear/actualizar el ticket.
 * - Por eso aquí se lee desde post meta, no desde el payload crudo.
 * - Se agregan alias para los nombres usados por el webhook: first_name, company, city, etc.
 * - Para extras se soporta tanto extra_modalidad como modalidad.
 */
function eventosapp_get_ticket_field_value($ticket_id, $field_key) {
    $field_key = sanitize_text_field((string) $field_key);

    // Alias de payload webhook -> campos internos del ticket.
    $aliases = [
        'first_name'       => 'nombre',
        'firstname'        => 'nombre',
        'last_name'        => 'apellido',
        'lastname'         => 'apellido',
        'phone'            => 'tel',
        'telefono'         => 'tel',
        'celular'          => 'tel',
        'company'          => 'empresa',
        'city'             => 'ciudad',
        'country'          => 'pais',
        'ticket_localidad' => 'localidad',
        'submission_id'    => 'external_id',
        'ac_submission_id' => 'external_id',
        'payload_id'       => 'external_id',
    ];

    if (isset($aliases[$field_key])) {
        $field_key = $aliases[$field_key];
    }

    // Mapeo de campos base a sus meta_keys.
    $meta_map = [
        'email'       => '_eventosapp_asistente_email',
        'correo'      => '_eventosapp_asistente_email',
        'nombre'      => '_eventosapp_asistente_nombre',
        'apellido'    => '_eventosapp_asistente_apellido',
        'cc'          => '_eventosapp_asistente_cc',
        'cedula'      => '_eventosapp_asistente_cc',
        'nit'         => '_eventosapp_asistente_nit',
        'empresa'     => '_eventosapp_asistente_empresa',
        'cargo'       => '_eventosapp_asistente_cargo',
        'tel'         => '_eventosapp_asistente_tel',
        'ciudad'      => '_eventosapp_asistente_ciudad',
        'pais'        => '_eventosapp_asistente_pais',
        'localidad'   => '_eventosapp_asistente_localidad',
        'external_id' => '_eventosapp_external_id',
    ];

    // Si es un campo base.
    if (isset($meta_map[$field_key])) {
        return eventosapp_condition_value_to_string(get_post_meta($ticket_id, $meta_map[$field_key], true));
    }

    // Si es un campo extra (formato: extra_nombredelcampo).
    $extra_key = $field_key;
    if (strpos($extra_key, 'extra_') === 0) {
        $extra_key = substr($extra_key, 6);
    }

    $extra_key = sanitize_key((string) $extra_key);
    if ($extra_key !== '') {
        // Formato principal usado por eventosapp-intake-ac.php: meta individual por campo.
        $value = get_post_meta($ticket_id, '_eventosapp_extra_' . $extra_key, true);
        if ($value !== '' && $value !== false && $value !== null) {
            return eventosapp_condition_value_to_string($value);
        }

        // Fallback: formato array para extras capturados desde webhook.
        $extras = get_post_meta($ticket_id, '_eventosapp_ticket_extras', true);
        if (is_array($extras)) {
            if (array_key_exists($extra_key, $extras)) {
                return eventosapp_condition_value_to_string($extras[$extra_key]);
            }

            // Fallback defensivo: búsqueda sin distinguir mayúsculas/minúsculas.
            foreach ($extras as $key => $item_value) {
                if (sanitize_key((string) $key) === $extra_key) {
                    return eventosapp_condition_value_to_string($item_value);
                }
            }
        }
    }

    return '';
}

/**
 * Evalúa un operador de comparación.
 *
 * La comparación ahora es tolerante a:
 * - mayúsculas/minúsculas
 * - tildes: "sí" y "si" se consideran equivalentes
 * - espacios duplicados o invisibles
 * - valores tipo array/lista
 */
function eventosapp_evaluate_condition_operator($field_value, $operator, $compare_value) {
    $raw_field   = trim(eventosapp_condition_value_to_string($field_value));
    $raw_compare = trim(eventosapp_condition_value_to_string($compare_value));

    $field_norm = eventosapp_condition_normalize_text($raw_field);
    $compare_values = eventosapp_condition_compare_values($raw_compare);
    $compare_norms = array_map('eventosapp_condition_normalize_text', $compare_values);

    switch ($operator) {
        case 'equals':
            foreach ($compare_norms as $compare_norm) {
                if ($field_norm === $compare_norm) {
                    return true;
                }
            }
            return false;

        case 'not_equals':
            foreach ($compare_norms as $compare_norm) {
                if ($field_norm === $compare_norm) {
                    return false;
                }
            }
            return true;

        case 'contains':
            foreach ($compare_norms as $compare_norm) {
                if ($compare_norm !== '' && strpos($field_norm, $compare_norm) !== false) {
                    return true;
                }
            }
            return false;

        case 'not_contains':
            foreach ($compare_norms as $compare_norm) {
                if ($compare_norm !== '' && strpos($field_norm, $compare_norm) !== false) {
                    return false;
                }
            }
            return true;

        case 'starts_with':
            foreach ($compare_norms as $compare_norm) {
                if ($compare_norm !== '' && strpos($field_norm, $compare_norm) === 0) {
                    return true;
                }
            }
            return false;

        case 'ends_with':
            foreach ($compare_norms as $compare_norm) {
                if ($compare_norm === '') {
                    continue;
                }
                $len = strlen($compare_norm);
                if (substr($field_norm, -$len) === $compare_norm) {
                    return true;
                }
            }
            return false;

        case 'is_empty':
            return $raw_field === '';

        case 'is_not_empty':
            return $raw_field !== '';

        default:
            return false;
    }
}

/**
 * Normaliza una regla a formato moderno con múltiples condiciones.
 * Compatibilidad con estructura vieja:
 * - field
 * - operator
 * - value
 */
function eventosapp_normalize_webhook_rule($rule) {
    if (!is_array($rule)) {
        return null;
    }

    $normalized = [
        'match'      => 'all',
        'conditions' => [],
        'action'     => '',
        'template'   => '',
    ];

    // Acción
    if (!empty($rule['action'])) {
        $normalized['action'] = sanitize_text_field($rule['action']);
    }

    // Plantilla
    if (!empty($rule['template'])) {
        $normalized['template'] = sanitize_file_name($rule['template']);
    }

    // Modo de coincidencia
    if (!empty($rule['match']) && in_array($rule['match'], ['all', 'any'], true)) {
        $normalized['match'] = $rule['match'];
    }

    // Formato nuevo
    if (!empty($rule['conditions']) && is_array($rule['conditions'])) {
        foreach ($rule['conditions'] as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $field    = isset($condition['field']) ? sanitize_text_field($condition['field']) : '';
            $operator = isset($condition['operator']) ? sanitize_text_field($condition['operator']) : '';
            $value    = isset($condition['value']) ? sanitize_text_field($condition['value']) : '';

            if ($field === '' || $operator === '') {
                continue;
            }

            $normalized['conditions'][] = [
                'field'    => $field,
                'operator' => $operator,
                'value'    => $value,
            ];
        }
    }

    // Compatibilidad con formato antiguo
    if (empty($normalized['conditions']) && !empty($rule['field']) && !empty($rule['operator'])) {
        $normalized['conditions'][] = [
            'field'    => sanitize_text_field($rule['field']),
            'operator' => sanitize_text_field($rule['operator']),
            'value'    => isset($rule['value']) ? sanitize_text_field($rule['value']) : '',
        ];
    }

    if (empty($normalized['conditions']) || empty($normalized['action'])) {
        return null;
    }

    return $normalized;
}

/**
 * Evalúa una regla y devuelve detalle completo para depurar.
 */
function eventosapp_evaluate_webhook_rule_detail($ticket_id, $rule) {
    $rule = eventosapp_normalize_webhook_rule($rule);
    if (!$rule) {
        return [
            'matched'    => false,
            'match_mode' => 'all',
            'conditions' => [],
            'rule'       => null,
        ];
    }

    $conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];
    if (empty($conditions)) {
        return [
            'matched'    => false,
            'match_mode' => isset($rule['match']) ? $rule['match'] : 'all',
            'conditions' => [],
            'rule'       => $rule,
        ];
    }

    $match_mode = isset($rule['match']) && $rule['match'] === 'any' ? 'any' : 'all';
    $details    = [];
    $results    = [];

    foreach ($conditions as $condition) {
        $field_key     = isset($condition['field']) ? (string) $condition['field'] : '';
        $operator      = isset($condition['operator']) ? (string) $condition['operator'] : '';
        $field_value   = eventosapp_get_ticket_field_value($ticket_id, $field_key);
        $compare_value = isset($condition['value']) ? $condition['value'] : '';
        $matched       = eventosapp_evaluate_condition_operator($field_value, $operator, $compare_value);

        $results[] = $matched;
        $details[] = [
            'field'               => $field_key,
            'operator'            => $operator,
            'operator_label'      => eventosapp_condition_operator_label($operator),
            'expected'            => eventosapp_condition_value_to_string($compare_value),
            'actual'              => eventosapp_condition_value_to_string($field_value),
            'expected_normalized' => eventosapp_condition_normalize_text($compare_value),
            'actual_normalized'   => eventosapp_condition_normalize_text($field_value),
            'matched'             => $matched,
        ];
    }

    $matched_rule = ($match_mode === 'any')
        ? in_array(true, $results, true)
        : !in_array(false, $results, true);

    return [
        'matched'    => $matched_rule,
        'match_mode' => $match_mode,
        'conditions' => $details,
        'rule'       => $rule,
    ];
}

/**
 * Evalúa una sola regla contra un ticket.
 */
function eventosapp_evaluate_webhook_rule($ticket_id, $rule) {
    $detail = eventosapp_evaluate_webhook_rule_detail($ticket_id, $rule);
    return !empty($detail['matched']);
}

/**
 * Evalúa las condicionales del webhook para un ticket.
 * Retorna: ['send_email' => bool, 'template' => string|null, 'matched_rule' => array|null]
 */
function eventosapp_evaluate_webhook_conditionals($ticket_id, $event_id) {
    $default_result = [
        'send_email'   => true,
        'template'     => null, // null = usar plantilla por defecto del evento
        'matched_rule' => null,
        'debug'        => [
            'enabled'          => false,
            'rules_count'      => 0,
            'matched_index'    => null,
            'variant_template' => null,
            'variant_applied'  => null,
            'rules'            => [],
        ],
    ];

    $ticket_id = absint($ticket_id);
    $event_id  = absint($event_id);

    // NUEVO: antes de evaluar condicionales de webhook, aplica la variante general del ticket.
    // Así una regla por localidad/campo extra puede definir la plantilla de correo incluso si
    // no hay una condicional específica del webhook.
    $variant_template = null;
    $variant_result   = null;
    if ($ticket_id && $event_id && function_exists('eventosapp_ticket_variants_apply_to_ticket')) {
        $variant_result = eventosapp_ticket_variants_apply_to_ticket($ticket_id, $event_id, true);
        $default_result['debug']['variant_applied'] = $variant_result;
    }
    if ($ticket_id && $event_id && function_exists('eventosapp_ticket_variants_get_email_template_for_ticket')) {
        $variant_template = eventosapp_ticket_variants_get_email_template_for_ticket($ticket_id, $event_id);
        if ($variant_template !== '') {
            $default_result['template'] = $variant_template;
            $default_result['debug']['variant_template'] = $variant_template;
        }
    }

    // Obtener configuración de condicionales del evento.
    $config = get_post_meta($event_id, '_eventosapp_webhook_conditionals', true);

    if (!is_array($config) || empty($config['enabled'])) {
        update_post_meta($ticket_id, '_eventosapp_webhook_conditional_last_debug', $default_result['debug']);
        return $default_result;
    }

    $rules = isset($config['rules']) && is_array($config['rules']) ? $config['rules'] : [];
    $debug = [
        'enabled'          => true,
        'rules_count'      => count($rules),
        'matched_index'    => null,
        'variant_template' => $variant_template,
        'variant_applied'  => $variant_result,
        'rules'            => [],
        'evaluated_at'     => current_time('mysql'),
    ];

    if (empty($rules)) {
        $default_result['debug'] = $debug;
        update_post_meta($ticket_id, '_eventosapp_webhook_conditional_last_debug', $debug);
        return $default_result;
    }

    // Evaluar reglas en orden (primera coincidencia gana).
    foreach ($rules as $index => $raw_rule) {
        $rule = eventosapp_normalize_webhook_rule($raw_rule);
        if (!$rule) {
            $debug['rules'][] = [
                'index'   => (int) $index,
                'valid'   => false,
                'matched' => false,
                'reason'  => 'Regla incompleta o inválida',
            ];
            continue;
        }

        $detail = eventosapp_evaluate_webhook_rule_detail($ticket_id, $rule);
        $debug['rules'][] = array_merge([
            'index' => (int) $index,
            'valid' => true,
        ], $detail);

        if (!empty($detail['matched'])) {
            $debug['matched_index'] = (int) $index;

            $result = [
                'matched_rule' => $rule,
                'debug'        => $debug,
            ];

            if ($rule['action'] === 'no_email') {
                $result['send_email'] = false;
                $result['template']   = null;
                $result['debug']['template_effective'] = null;
                $result['debug']['template_source']    = 'no_email';
            } elseif ($rule['action'] === 'send_with_template') {
                $result['send_email'] = true;
                // Si la regla de webhook no define plantilla, usa la plantilla de la variante aplicada al ticket.
                $result['template']   = !empty($rule['template']) ? $rule['template'] : $variant_template;
                $result['debug']['template_effective'] = $result['template'];
                $result['debug']['template_source']    = !empty($rule['template']) ? 'webhook_conditional' : ($variant_template ? 'ticket_variant' : 'event_default');
            } else {
                $default_result['debug'] = $debug;
                update_post_meta($ticket_id, '_eventosapp_webhook_conditional_last_debug', $debug);
                return $default_result;
            }

            update_post_meta($ticket_id, '_eventosapp_webhook_conditional_last_debug', $debug);
            update_post_meta($ticket_id, '_eventosapp_webhook_conditional_matched', $rule);
            update_post_meta($ticket_id, '_eventosapp_webhook_conditional_matched_index', (int) $index);

            error_log('[EventosApp] Webhook conditional MATCH ticket=' . $ticket_id . ' event=' . $event_id . ' rule_index=' . $index . ' action=' . $rule['action'] . (!empty($rule['template']) ? ' template=' . $rule['template'] : ''));

            return $result;
        }
    }

    // Ninguna regla coincidió, comportamiento por defecto.
    $default_result['debug'] = $debug;
    update_post_meta($ticket_id, '_eventosapp_webhook_conditional_last_debug', $debug);
    delete_post_meta($ticket_id, '_eventosapp_webhook_conditional_matched');
    delete_post_meta($ticket_id, '_eventosapp_webhook_conditional_matched_index');

    error_log('[EventosApp] Webhook conditional NO MATCH ticket=' . $ticket_id . ' event=' . $event_id . ' rules=' . count($rules));

    return $default_result;
}

/**
 * Registra el metabox de condicionales
 */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_webhook_conditionals',
        'Webhook → Condicionales de envío de correo',
        'eventosapp_render_webhook_conditionals_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
}, 45);

/**
 * Renderiza una regla individual
 */
function eventosapp_render_single_webhook_rule($index, $rule, $available_fields, $available_templates, $operators) {
    $rule = eventosapp_normalize_webhook_rule($rule);

    if (!$rule) {
        $rule = [
            'match'      => 'all',
            'conditions' => [
                [
                    'field'    => '',
                    'operator' => '',
                    'value'    => '',
                ],
            ],
            'action'     => '',
            'template'   => '',
        ];
    }

    $conditions    = !empty($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];
    $show_template = isset($rule['action']) && $rule['action'] === 'send_with_template';
    ?>
    <div class="rule-item" data-index="<?php echo esc_attr($index); ?>">
        <div class="rule-header">
            <span class="rule-number">Regla #<span class="rule-num"><?php echo esc_html($index + 1); ?></span></span>
            <span class="rule-delete" title="Eliminar regla">❌ Eliminar</span>
        </div>

        <div class="rule-match-row">
            <div class="field-group">
                <label>Lógica de coincidencia de esta regla</label>
                <select name="evapp_cond_rules[<?php echo esc_attr($index); ?>][match]" class="rule-match">
                    <option value="all" <?php selected($rule['match'], 'all'); ?>>Cumplir TODOS los criterios</option>
                    <option value="any" <?php selected($rule['match'], 'any'); ?>>Cumplir AL MENOS UNO de los criterios</option>
                </select>
                <span class="help-text">
                    “TODOS” = condición tipo AND. “AL MENOS UNO” = condición tipo OR.
                </span>
            </div>
        </div>

        <div class="conditions-list">
            <?php foreach ($conditions as $condition_index => $condition): ?>
                <div class="condition-item" data-condition-index="<?php echo esc_attr($condition_index); ?>">
                    <div class="condition-header">
                        <span class="condition-number">Criterio #<span class="condition-num"><?php echo esc_html($condition_index + 1); ?></span></span>
                        <span class="condition-delete" title="Eliminar criterio">✖</span>
                    </div>

                    <div class="rule-row">
                        <div class="field-group">
                            <label>Campo a evaluar</label>
                            <select name="evapp_cond_rules[<?php echo esc_attr($index); ?>][conditions][<?php echo esc_attr($condition_index); ?>][field]" class="rule-field">
                                <option value="">-- Seleccionar --</option>
                                <?php foreach ($available_fields as $key => $label): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected(isset($condition['field']) ? $condition['field'] : '', $key); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field-group">
                            <label>Operador</label>
                            <select name="evapp_cond_rules[<?php echo esc_attr($index); ?>][conditions][<?php echo esc_attr($condition_index); ?>][operator]" class="rule-operator">
                                <option value="">-- Seleccionar --</option>
                                <?php foreach ($operators as $op => $label): ?>
                                    <option value="<?php echo esc_attr($op); ?>" <?php selected(isset($condition['operator']) ? $condition['operator'] : '', $op); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field-group value-field">
                            <label>Valor a comparar</label>
                            <input
                                type="text"
                                name="evapp_cond_rules[<?php echo esc_attr($index); ?>][conditions][<?php echo esc_attr($condition_index); ?>][value]"
                                value="<?php echo esc_attr(isset($condition['value']) ? $condition['value'] : ''); ?>"
                                placeholder="Ej: si, premium, test, Barranquilla, etc.">
                            <span class="help-text">No distingue mayúsculas/minúsculas ni tildes. Para aceptar varios valores usa barra vertical: Virtual|Presencial. Deja vacío si el operador es "está vacío" o "no está vacío".</span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <button type="button" class="button button-secondary add-condition-btn">＋ Agregar criterio</button>

        <div class="rule-row action-row">
            <div class="field-group">
                <label>Acción si coincide</label>
                <select name="evapp_cond_rules[<?php echo esc_attr($index); ?>][action]" class="rule-action">
                    <option value="">-- Seleccionar --</option>
                    <option value="no_email" <?php selected(isset($rule['action']) ? $rule['action'] : '', 'no_email'); ?>>
                        🚫 NO enviar correo
                    </option>
                    <option value="send_with_template" <?php selected(isset($rule['action']) ? $rule['action'] : '', 'send_with_template'); ?>>
                        ✉️ Enviar correo con plantilla específica
                    </option>
                </select>
            </div>

            <div class="field-group template-field <?php echo $show_template ? 'visible' : ''; ?>">
                <label>Plantilla de correo</label>
                <select name="evapp_cond_rules[<?php echo esc_attr($index); ?>][template]" class="rule-template">
                    <option value="">-- Usar plantilla por defecto del evento --</option>
                    <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                        <option value="<?php echo esc_attr($tpl_file); ?>" <?php selected(isset($rule['template']) ? $rule['template'] : '', $tpl_file); ?>>
                            <?php echo esc_html($tpl_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="help-text">Si está vacío, se usa la plantilla configurada en "Personalización del correo".</span>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Renderiza el metabox de condicionales
 */
function eventosapp_render_webhook_conditionals_metabox($post) {
    wp_nonce_field('eventosapp_webhook_conditionals_save', 'eventosapp_webhook_conditionals_nonce');

    $config = get_post_meta($post->ID, '_eventosapp_webhook_conditionals', true);
    if (!is_array($config)) {
        $config = ['enabled' => false, 'rules' => []];
    }

    $enabled = !empty($config['enabled']);
    $rules   = isset($config['rules']) && is_array($config['rules']) ? $config['rules'] : [];

    $available_fields    = eventosapp_get_conditional_fields($post->ID);
    $available_templates = eventosapp_get_available_email_templates();

    $operators = [
        'equals'       => 'Es igual a (exacto)',
        'not_equals'   => 'No es igual a',
        'contains'     => 'Contiene',
        'not_contains' => 'No contiene',
        'starts_with'  => 'Comienza con',
        'ends_with'    => 'Termina con',
        'is_empty'     => 'Está vacío',
        'is_not_empty' => 'No está vacío',
    ];
    ?>
    <style>
        .evapp-cond-wrap { font-size: 13px; line-height: 1.5; }
        .evapp-cond-wrap .intro { color: #666; margin-bottom: 16px; padding: 12px; background: #f8f9fa; border-left: 3px solid #2271b1; }
        .evapp-cond-wrap .enable-toggle { margin-bottom: 20px; padding: 12px; background: #fff; border: 1px solid #ddd; border-radius: 4px; }
        .evapp-cond-wrap .rules-container { margin-top: 20px; }
        .evapp-cond-wrap .rule-item { border: 1px solid #ddd; border-radius: 8px; padding: 16px; margin-bottom: 16px; background: #fff; position: relative; }
        .evapp-cond-wrap .rule-item:hover { border-color: #2271b1; }
        .evapp-cond-wrap .rule-header { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #eee; }
        .evapp-cond-wrap .rule-header .rule-number { font-weight: 600; color: #2271b1; }
        .evapp-cond-wrap .rule-header .rule-delete { margin-left: auto; color: #dc3545; cursor: pointer; }
        .evapp-cond-wrap .rule-match-row { margin-bottom: 14px; }
        .evapp-cond-wrap .rule-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 12px; }
        .evapp-cond-wrap .rule-row.action-row { grid-template-columns: 1fr 1fr; margin-top: 14px; }
        .evapp-cond-wrap .field-group { display: flex; flex-direction: column; }
        .evapp-cond-wrap .field-group label { font-weight: 600; margin-bottom: 4px; color: #333; }
        .evapp-cond-wrap .field-group select,
        .evapp-cond-wrap .field-group input[type="text"] { width: 100%; padding: 6px 8px; border: 1px solid #ddd; border-radius: 4px; }
        .evapp-cond-wrap .add-rule-btn { margin-top: 12px; }
        .evapp-cond-wrap .template-field { display: none; }
        .evapp-cond-wrap .template-field.visible { display: flex; }
        .evapp-cond-wrap .help-text { font-size: 12px; color: #666; margin-top: 4px; font-style: italic; }
        .evapp-cond-wrap .disabled-overlay { pointer-events: none; opacity: 0.5; }

        .evapp-cond-wrap .conditions-list { margin-top: 8px; }
        .evapp-cond-wrap .condition-item {
            border: 1px solid #e5e7eb;
            background: #fafafa;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 12px;
        }
        .evapp-cond-wrap .condition-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1px dashed #ddd;
        }
        .evapp-cond-wrap .condition-number {
            font-weight: 600;
            color: #444;
        }
        .evapp-cond-wrap .condition-delete {
            margin-left: auto;
            color: #b91c1c;
            cursor: pointer;
            font-weight: 700;
        }
        .evapp-cond-wrap .add-condition-btn {
            margin-top: 4px;
            margin-bottom: 8px;
        }

        @media (max-width: 1100px) {
            .evapp-cond-wrap .rule-row,
            .evapp-cond-wrap .rule-row.action-row {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="evapp-cond-wrap">
        <div class="intro">
            <strong>¿Qué hace esto?</strong><br>
            Configura reglas para controlar el envío de correos cuando llegan inscripciones vía webhook.<br>
            Puedes decidir si se envía correo o no, y qué plantilla usar, según el contenido de los campos del ticket.<br><br>
            <strong>Orden de evaluación:</strong> Las reglas se evalúan de arriba hacia abajo. La primera regla que coincida se ejecuta (las demás se ignoran).<br><br>
            <strong>Múltiples criterios por regla:</strong> Dentro de cada regla puedes agregar varios criterios y escoger si deben cumplirse <strong>TODOS</strong> o <strong>AL MENOS UNO</strong>.
        </div>

        <?php
        $last_ticket_debug = null;
        $latest_ticket_ids = get_posts([
            'post_type'      => 'eventosapp_ticket',
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_eventosapp_ticket_evento_id',
                    'value' => $post->ID,
                ],
            ],
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ]);
        if (!empty($latest_ticket_ids[0])) {
            $last_ticket_debug = get_post_meta((int) $latest_ticket_ids[0], '_eventosapp_webhook_conditional_last_debug', true);
        }
        ?>
        <div class="intro" style="border-left-color:#72aee6;">
            <strong>Diagnóstico rápido:</strong><br>
            Condicionales guardadas: <strong><?php echo $enabled ? 'activadas' : 'desactivadas'; ?></strong> · Reglas guardadas: <strong><?php echo esc_html(count($rules)); ?></strong>.<br>
            <?php if (is_array($last_ticket_debug)): ?>
                Última evaluación detectada: reglas evaluadas <strong><?php echo esc_html(isset($last_ticket_debug['rules_count']) ? (int) $last_ticket_debug['rules_count'] : 0); ?></strong> · regla coincidente: <strong><?php echo isset($last_ticket_debug['matched_index']) && $last_ticket_debug['matched_index'] !== null ? '#' . esc_html((int) $last_ticket_debug['matched_index'] + 1) : 'ninguna'; ?></strong>.
            <?php else: ?>
                Todavía no hay diagnóstico guardado en tickets recientes de este evento. Se generará automáticamente al recibir el próximo webhook.
            <?php endif; ?>
        </div>

        <div class="enable-toggle">
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                <input type="checkbox" name="evapp_cond_enabled" value="1" <?php checked($enabled); ?> id="evapp_cond_enabled">
                <strong>Activar condicionales para este evento</strong>
            </label>
            <p class="help-text">Si está desactivado, todos los tickets del webhook recibirán correo con la plantilla por defecto del evento.</p>
        </div>

        <div id="evapp_rules_wrapper" class="<?php echo $enabled ? '' : 'disabled-overlay'; ?>">
            <div class="rules-container" id="evapp_rules_list">
                <?php if (empty($rules)): ?>
                    <p class="evapp-no-rules" style="color: #666; font-style: italic;">No hay reglas configuradas. Agrega una regla para empezar.</p>
                <?php else: ?>
                    <?php foreach ($rules as $index => $rule): ?>
                        <?php eventosapp_render_single_webhook_rule($index, $rule, $available_fields, $available_templates, $operators); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <button type="button" class="button add-rule-btn" id="evapp_add_rule">➕ Agregar regla</button>
        </div>
    </div>

    <script>
    (function($) {
        var ruleIndex = <?php echo (int) count($rules); ?>;
        var availableFields = <?php echo wp_json_encode($available_fields); ?>;
        var operators = <?php echo wp_json_encode($operators); ?>;
        var templates = <?php echo wp_json_encode($available_templates); ?>;

        function escHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function getFieldsOptions() {
            var html = '<option value="">-- Seleccionar --</option>';
            $.each(availableFields, function(key, label) {
                html += '<option value="' + escHtml(key) + '">' + escHtml(label) + '</option>';
            });
            return html;
        }

        function getOperatorsOptions() {
            var html = '<option value="">-- Seleccionar --</option>';
            $.each(operators, function(key, label) {
                html += '<option value="' + escHtml(key) + '">' + escHtml(label) + '</option>';
            });
            return html;
        }

        function getTemplatesOptions() {
            var html = '<option value="">-- Usar plantilla por defecto del evento --</option>';
            $.each(templates, function(file, label) {
                html += '<option value="' + escHtml(file) + '">' + escHtml(label) + '</option>';
            });
            return html;
        }

        function createConditionHTML(ruleIdx, conditionIdx) {
            return '' +
                '<div class="condition-item" data-condition-index="' + conditionIdx + '">' +
                    '<div class="condition-header">' +
                        '<span class="condition-number">Criterio #<span class="condition-num">' + (conditionIdx + 1) + '</span></span>' +
                        '<span class="condition-delete" title="Eliminar criterio">✖</span>' +
                    '</div>' +
                    '<div class="rule-row">' +
                        '<div class="field-group">' +
                            '<label>Campo a evaluar</label>' +
                            '<select name="evapp_cond_rules[' + ruleIdx + '][conditions][' + conditionIdx + '][field]" class="rule-field">' +
                                getFieldsOptions() +
                            '</select>' +
                        '</div>' +
                        '<div class="field-group">' +
                            '<label>Operador</label>' +
                            '<select name="evapp_cond_rules[' + ruleIdx + '][conditions][' + conditionIdx + '][operator]" class="rule-operator">' +
                                getOperatorsOptions() +
                            '</select>' +
                        '</div>' +
                        '<div class="field-group value-field">' +
                            '<label>Valor a comparar</label>' +
                            '<input type="text" name="evapp_cond_rules[' + ruleIdx + '][conditions][' + conditionIdx + '][value]" placeholder="Ej: si, premium, test, Barranquilla, etc.">' +
                            '<span class="help-text">No distingue mayúsculas/minúsculas ni tildes. Para aceptar varios valores usa barra vertical: Virtual|Presencial. Deja vacío si el operador es "está vacío" o "no está vacío".</span>' +
                        '</div>' +
                    '</div>' +
                '</div>';
        }

        function createRuleHTML(index) {
            return '' +
                '<div class="rule-item" data-index="' + index + '">' +
                    '<div class="rule-header">' +
                        '<span class="rule-number">Regla #<span class="rule-num">' + (index + 1) + '</span></span>' +
                        '<span class="rule-delete" title="Eliminar regla">❌ Eliminar</span>' +
                    '</div>' +
                    '<div class="rule-match-row">' +
                        '<div class="field-group">' +
                            '<label>Lógica de coincidencia de esta regla</label>' +
                            '<select name="evapp_cond_rules[' + index + '][match]" class="rule-match">' +
                                '<option value="all">Cumplir TODOS los criterios</option>' +
                                '<option value="any">Cumplir AL MENOS UNO de los criterios</option>' +
                            '</select>' +
                            '<span class="help-text">“TODOS” = condición tipo AND. “AL MENOS UNO” = condición tipo OR.</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="conditions-list">' +
                        createConditionHTML(index, 0) +
                    '</div>' +
                    '<button type="button" class="button button-secondary add-condition-btn">＋ Agregar criterio</button>' +
                    '<div class="rule-row action-row">' +
                        '<div class="field-group">' +
                            '<label>Acción si coincide</label>' +
                            '<select name="evapp_cond_rules[' + index + '][action]" class="rule-action">' +
                                '<option value="">-- Seleccionar --</option>' +
                                '<option value="no_email">🚫 NO enviar correo</option>' +
                                '<option value="send_with_template">✉️ Enviar correo con plantilla específica</option>' +
                            '</select>' +
                        '</div>' +
                        '<div class="field-group template-field">' +
                            '<label>Plantilla de correo</label>' +
                            '<select name="evapp_cond_rules[' + index + '][template]" class="rule-template">' +
                                getTemplatesOptions() +
                            '</select>' +
                            '<span class="help-text">Si está vacío, se usa la plantilla configurada en "Personalización del correo".</span>' +
                        '</div>' +
                    '</div>' +
                '</div>';
        }

        function updateRuleNumbers() {
            $('#evapp_rules_list .rule-item').each(function(i) {
                $(this).find('.rule-num').text(i + 1);
            });
        }

        function updateConditionNumbers($ruleItem) {
            $ruleItem.find('.condition-item').each(function(i) {
                $(this).find('.condition-num').text(i + 1);
            });
        }

        function reindexConditions($ruleItem) {
            var ruleIdx = $ruleItem.data('index');

            $ruleItem.find('.condition-item').each(function(conditionIdx) {
                $(this).attr('data-condition-index', conditionIdx);

                $(this).find('select, input').each(function() {
                    var name = $(this).attr('name');
                    if (!name) return;

                    name = name.replace(
                        /evapp_cond_rules\[\d+\]\[conditions\]\[\d+\]/,
                        'evapp_cond_rules[' + ruleIdx + '][conditions][' + conditionIdx + ']'
                    );

                    $(this).attr('name', name);
                });
            });

            updateConditionNumbers($ruleItem);
        }

        function ensureNoRulesMessage() {
            var $list = $('#evapp_rules_list');
            if ($list.find('.rule-item').length === 0) {
                if ($list.find('.evapp-no-rules').length === 0) {
                    $list.append('<p class="evapp-no-rules" style="color:#666;font-style:italic;">No hay reglas configuradas. Agrega una regla para empezar.</p>');
                }
            } else {
                $list.find('.evapp-no-rules').remove();
            }
        }

        // Agregar regla
        $('#evapp_add_rule').on('click', function() {
            $('#evapp_rules_list .evapp-no-rules').remove();
            $('#evapp_rules_list').append(createRuleHTML(ruleIndex));
            ruleIndex++;
            updateRuleNumbers();
        });

        // Eliminar regla
        $(document).on('click', '.rule-delete', function() {
            if (!confirm('¿Estás seguro de eliminar esta regla?')) {
                return;
            }

            $(this).closest('.rule-item').remove();
            updateRuleNumbers();
            ensureNoRulesMessage();
        });

        // Agregar criterio
        $(document).on('click', '.add-condition-btn', function() {
            var $ruleItem = $(this).closest('.rule-item');
            var ruleIdx = $ruleItem.data('index');
            var nextConditionIdx = $ruleItem.find('.condition-item').length;

            $ruleItem.find('.conditions-list').append(createConditionHTML(ruleIdx, nextConditionIdx));
            updateConditionNumbers($ruleItem);
        });

        // Eliminar criterio
        $(document).on('click', '.condition-delete', function() {
            var $ruleItem = $(this).closest('.rule-item');

            if ($ruleItem.find('.condition-item').length <= 1) {
                alert('Cada regla debe tener al menos un criterio.');
                return;
            }

            $(this).closest('.condition-item').remove();
            reindexConditions($ruleItem);
        });

        // Mostrar/ocultar campo de plantilla según la acción
        $(document).on('change', '.rule-action', function() {
            var $row = $(this).closest('.rule-item');
            var $templateField = $row.find('.template-field');

            if ($(this).val() === 'send_with_template') {
                $templateField.addClass('visible');
            } else {
                $templateField.removeClass('visible');
            }
        });

        // Toggle enabled/disabled
        $('#evapp_cond_enabled').on('change', function() {
            if ($(this).is(':checked')) {
                $('#evapp_rules_wrapper').removeClass('disabled-overlay');
            } else {
                $('#evapp_rules_wrapper').addClass('disabled-overlay');
            }
        });

        ensureNoRulesMessage();

    })(jQuery);
    </script>
    <?php
}

/**
 * Guarda las condicionales del webhook
 */
add_action('save_post_eventosapp_event', function($post_id) {
    // Verificaciones de seguridad
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['eventosapp_webhook_conditionals_nonce'])) return;
    if (!wp_verify_nonce($_POST['eventosapp_webhook_conditionals_nonce'], 'eventosapp_webhook_conditionals_save')) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // Recoger datos
    $enabled   = isset($_POST['evapp_cond_enabled']);
    $rules_raw = isset($_POST['evapp_cond_rules']) && is_array($_POST['evapp_cond_rules']) ? $_POST['evapp_cond_rules'] : [];

    // Procesar y validar reglas
    $rules = [];

    foreach ($rules_raw as $rule) {
        if (!is_array($rule)) {
            continue;
        }

        $clean_rule = [
            'match'      => (isset($rule['match']) && $rule['match'] === 'any') ? 'any' : 'all',
            'conditions' => [],
            'action'     => isset($rule['action']) ? sanitize_text_field($rule['action']) : '',
        ];

        // Formato nuevo: múltiples condiciones
        if (!empty($rule['conditions']) && is_array($rule['conditions'])) {
            foreach ($rule['conditions'] as $condition) {
                if (!is_array($condition)) {
                    continue;
                }

                $field    = isset($condition['field']) ? sanitize_text_field($condition['field']) : '';
                $operator = isset($condition['operator']) ? sanitize_text_field($condition['operator']) : '';
                $value    = isset($condition['value']) ? sanitize_text_field($condition['value']) : '';

                if ($field === '' || $operator === '') {
                    continue;
                }

                $clean_rule['conditions'][] = [
                    'field'    => $field,
                    'operator' => $operator,
                    'value'    => $value,
                ];
            }
        }

        // Compatibilidad por si llega una regla vieja o una estructura parcial
        if (empty($clean_rule['conditions']) && !empty($rule['field']) && !empty($rule['operator'])) {
            $clean_rule['conditions'][] = [
                'field'    => sanitize_text_field($rule['field']),
                'operator' => sanitize_text_field($rule['operator']),
                'value'    => isset($rule['value']) ? sanitize_text_field($rule['value']) : '',
            ];
        }

        if (empty($clean_rule['conditions']) || empty($clean_rule['action'])) {
            continue;
        }

        // Si la acción es enviar con plantilla, guardar la plantilla
        if ($clean_rule['action'] === 'send_with_template' && !empty($rule['template'])) {
            $clean_rule['template'] = sanitize_file_name($rule['template']);
        }

        $rules[] = $clean_rule;
    }

    // Guardar configuración
    $config = [
        'enabled'    => $enabled,
        'rules'      => $rules,
        'updated_at' => current_time('mysql'),
    ];

    update_post_meta($post_id, '_eventosapp_webhook_conditionals', $config);
}, 25);
