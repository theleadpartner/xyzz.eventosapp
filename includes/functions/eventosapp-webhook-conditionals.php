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
 * Obtiene las plantillas de correo disponibles
 */
function eventosapp_get_available_email_templates() {
    $templates = [];
    $dir = plugin_dir_path(dirname(dirname(__FILE__))) . 'templates/email_tickets/';

    if (is_dir($dir)) {
        $files = glob($dir . '*.html');
        foreach ($files as $file) {
            $basename = basename($file);
            $label = str_replace(['-', '_', '.html'], [' ', ' ', ''], $basename);
            $label = ucwords($label);
            $templates[$basename] = $label;
        }
    }

    // Asegurar que siempre haya al menos la plantilla por defecto
    if (empty($templates)) {
        $templates['email-ticket.html'] = 'Email Ticket (por defecto)';
    }

    return $templates;
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

    // Agregar campos extras del evento si existen
    if (function_exists('eventosapp_get_event_extra_fields') && $event_id) {
        $extras = eventosapp_get_event_extra_fields($event_id);
        if (is_array($extras)) {
            foreach ($extras as $extra) {
                if (isset($extra['key']) && isset($extra['label'])) {
                    $fields['extra_' . $extra['key']] = $extra['label'] . ' (extra)';
                }
            }
        }
    }

    return $fields;
}

/**
 * Obtiene el valor de un campo del ticket
 */
function eventosapp_get_ticket_field_value($ticket_id, $field_key) {
    // Mapeo de campos base a sus meta_keys
    $meta_map = [
        'email'     => '_eventosapp_asistente_email',
        'nombre'    => '_eventosapp_asistente_nombre',
        'apellido'  => '_eventosapp_asistente_apellido',
        'cc'        => '_eventosapp_asistente_cc',
        'nit'       => '_eventosapp_asistente_nit',
        'empresa'   => '_eventosapp_asistente_empresa',
        'cargo'     => '_eventosapp_asistente_cargo',
        'tel'       => '_eventosapp_asistente_tel',
        'ciudad'    => '_eventosapp_asistente_ciudad',
        'pais'      => '_eventosapp_asistente_pais',
        'localidad' => '_eventosapp_asistente_localidad',
    ];

    // Si es un campo base
    if (isset($meta_map[$field_key])) {
        return (string) get_post_meta($ticket_id, $meta_map[$field_key], true);
    }

    // Si es un campo extra (formato: extra_nombredelcampo)
    if (strpos($field_key, 'extra_') === 0) {
        $extra_key = substr($field_key, 6); // Quitar 'extra_'

        // Formato usado por eventosapp-intake-ac.php: meta individual por campo
        $value = get_post_meta($ticket_id, '_eventosapp_extra_' . $extra_key, true);
        if ($value !== '' && $value !== false) {
            return (string) $value;
        }

        // Fallback: formato alternativo de array (por si se usó el otro sistema)
        $extras = get_post_meta($ticket_id, '_eventosapp_ticket_extras', true);
        if (is_array($extras) && isset($extras[$extra_key])) {
            return (string) $extras[$extra_key];
        }
    }

    return '';
}

/**
 * Evalúa un operador de comparación
 */
function eventosapp_evaluate_condition_operator($field_value, $operator, $compare_value) {
    $field_value   = is_scalar($field_value) ? trim((string) $field_value) : '';
    $compare_value = is_scalar($compare_value) ? trim((string) $compare_value) : '';

    switch ($operator) {
        case 'equals':
            return strcasecmp($field_value, $compare_value) === 0;

        case 'not_equals':
            return strcasecmp($field_value, $compare_value) !== 0;

        case 'contains':
            return stripos($field_value, $compare_value) !== false;

        case 'not_contains':
            return stripos($field_value, $compare_value) === false;

        case 'starts_with':
            return stripos($field_value, $compare_value) === 0;

        case 'ends_with':
            $len = strlen($compare_value);
            if ($len === 0) return true;
            return strcasecmp(substr($field_value, -$len), $compare_value) === 0;

        case 'is_empty':
            return $field_value === '';

        case 'is_not_empty':
            return $field_value !== '';

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
 * Evalúa una sola regla contra un ticket
 */
function eventosapp_evaluate_webhook_rule($ticket_id, $rule) {
    $rule = eventosapp_normalize_webhook_rule($rule);
    if (!$rule) {
        return false;
    }

    $conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];
    if (empty($conditions)) {
        return false;
    }

    $match_mode = isset($rule['match']) && $rule['match'] === 'any' ? 'any' : 'all';
    $results    = [];

    foreach ($conditions as $condition) {
        $field_value   = eventosapp_get_ticket_field_value($ticket_id, $condition['field']);
        $compare_value = isset($condition['value']) ? $condition['value'] : '';

        $results[] = eventosapp_evaluate_condition_operator(
            $field_value,
            $condition['operator'],
            $compare_value
        );
    }

    if ($match_mode === 'any') {
        return in_array(true, $results, true);
    }

    return !in_array(false, $results, true);
}

/**
 * Evalúa las condicionales del webhook para un ticket
 * Retorna: ['send_email' => bool, 'template' => string|null, 'matched_rule' => array|null]
 */
function eventosapp_evaluate_webhook_conditionals($ticket_id, $event_id) {
    $default_result = [
        'send_email'   => true,
        'template'     => null, // null = usar plantilla por defecto del evento
        'matched_rule' => null,
    ];

    // Obtener configuración de condicionales del evento
    $config = get_post_meta($event_id, '_eventosapp_webhook_conditionals', true);

    // Si no hay configuración o no está habilitada, comportamiento por defecto
    if (!is_array($config) || empty($config['enabled'])) {
        return $default_result;
    }

    $rules = isset($config['rules']) && is_array($config['rules']) ? $config['rules'] : [];

    // Si no hay reglas, comportamiento por defecto
    if (empty($rules)) {
        return $default_result;
    }

    // Evaluar reglas en orden (primera coincidencia gana)
    foreach ($rules as $index => $raw_rule) {
        $rule = eventosapp_normalize_webhook_rule($raw_rule);
        if (!$rule) {
            continue;
        }

        $condition_met = eventosapp_evaluate_webhook_rule($ticket_id, $rule);

        // Si la regla coincide, ejecutar la acción
        if ($condition_met) {
            $result = ['matched_rule' => $rule];

            if ($rule['action'] === 'no_email') {
                $result['send_email'] = false;
                $result['template']   = null;
            } elseif ($rule['action'] === 'send_with_template') {
                $result['send_email'] = true;
                $result['template']   = !empty($rule['template']) ? $rule['template'] : null;
            } else {
                return $default_result;
            }

            return $result;
        }
    }

    // Ninguna regla coincidió, comportamiento por defecto
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
                            <span class="help-text">No distingue mayúsculas/minúsculas. Deja vacío si el operador es "está vacío" o "no está vacío".</span>
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
                            '<span class="help-text">No distingue mayúsculas/minúsculas. Deja vacío si el operador es "está vacío" o "no está vacío".</span>' +
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
        'enabled' => $enabled,
        'rules'   => $rules,
    ];

    update_post_meta($post_id, '_eventosapp_webhook_conditionals', $config);
}, 25);
