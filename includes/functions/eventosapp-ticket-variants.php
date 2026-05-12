<?php
/**
 * includes/functions/eventosapp-ticket-variants.php
 *
 * Variantes de ticket por evento.
 *
 * Permite que un evento cree tickets con configuración diferente según reglas sobre
 * localidad, campos base o campos extra del asistente. La primera regla coincidente
 * guarda en el ticket los overrides de plantilla de correo, Google Wallet y Apple Wallet.
 *
 * Este archivo es defensivo: no reemplaza los flujos existentes, solo agrega metadatos
 * y filtros/helpers que los módulos actuales pueden consultar.
 */

if (!defined('ABSPATH')) exit;

/* ============================================================
 * ========================= HELPERS ===========================
 * ============================================================ */

if (!function_exists('eventosapp_ticket_variants_enabled')) {
    function eventosapp_ticket_variants_enabled($event_id) {
        $event_id = absint($event_id);
        if (!$event_id) return false;
        return get_post_meta($event_id, '_eventosapp_ticket_variants_enabled', true) === '1';
    }
}

if (!function_exists('eventosapp_ticket_variants_log')) {
    function eventosapp_ticket_variants_log($message, $context = []) {
        if (is_array($context) && !empty($context)) {
            $message .= ' | ' . wp_json_encode($context);
        }
        error_log('EVENTOSAPP VARIANTS | ' . $message);
    }
}

if (!function_exists('eventosapp_ticket_variants_build_auto_google_class_id')) {
    function eventosapp_ticket_variants_build_auto_google_class_id($event_id, $variant_key = '') {
        $event_id = absint($event_id);
        $variant_key = sanitize_key((string) $variant_key);
        if (!$event_id) return '';
        if ($variant_key === '') $variant_key = 'variant';

        $local_id = 'event_' . $event_id . '_variant_' . $variant_key;
        $local_id = preg_replace('/[^A-Za-z0-9._-]/', '_', $local_id);
        $local_id = trim($local_id, '._-');
        if ($local_id === '') return '';

        $issuer_id = trim((string) get_option('eventosapp_wallet_issuer_id', ''));
        if ($issuer_id !== '') {
            return $issuer_id . '.' . $local_id;
        }

        return $local_id;
    }
}

if (!function_exists('eventosapp_ticket_variants_meta_keys')) {
    function eventosapp_ticket_variants_meta_keys() {
        return [
            '_eventosapp_ticket_variant_key',
            '_eventosapp_ticket_variant_name',
            '_eventosapp_ticket_variant_rule_index',
            '_eventosapp_ticket_variant_rule',
            '_eventosapp_ticket_variant_last_debug',
            '_eventosapp_ticket_email_template_override',
            '_eventosapp_ticket_email_template',
            '_eventosapp_ticket_email_template_path',
            '_eventosapp_ticket_email_header_image_url',
            '_eventosapp_ticket_email_heading_color',
            '_eventosapp_ticket_email_subheading_color',
            '_eventosapp_ticket_email_text_color',
            '_eventosapp_wallet_variant_class_id',
            '_eventosapp_wallet_variant_class_auto',
            '_eventosapp_wallet_variant_class_source',
            '_eventosapp_wallet_variant_logo_url',
            '_eventosapp_wallet_variant_hero_img_url',
            '_eventosapp_wallet_variant_hex_color',
            '_eventosapp_wallet_variant_event_name',
            '_eventosapp_apple_variant_icon_url',
            '_eventosapp_apple_variant_logo_url',
            '_eventosapp_apple_variant_strip_url',
            '_eventosapp_apple_variant_hex_bg',
            '_eventosapp_apple_variant_hex_fg',
            '_eventosapp_apple_variant_hex_label',
            '_eventosapp_apple_variant_event_name',
        ];
    }
}

if (!function_exists('eventosapp_ticket_variants_clear_ticket_meta')) {
    function eventosapp_ticket_variants_clear_ticket_meta($ticket_id) {
        $ticket_id = absint($ticket_id);
        if (!$ticket_id) return;
        foreach (eventosapp_ticket_variants_meta_keys() as $meta_key) {
            delete_post_meta($ticket_id, $meta_key);
        }
    }
}

if (!function_exists('eventosapp_ticket_variants_value_to_string')) {
    function eventosapp_ticket_variants_value_to_string($value) {
        if (function_exists('eventosapp_condition_value_to_string')) {
            return eventosapp_condition_value_to_string($value);
        }

        if (is_array($value)) {
            $parts = [];
            array_walk_recursive($value, function($item) use (&$parts) {
                if (is_scalar($item) || $item === null) $parts[] = (string) $item;
            });
            return implode(', ', $parts);
        }

        if (is_bool($value)) return $value ? '1' : '0';
        if ($value === null) return '';
        return is_scalar($value) ? (string) $value : '';
    }
}

if (!function_exists('eventosapp_ticket_variants_normalize_text')) {
    function eventosapp_ticket_variants_normalize_text($value) {
        if (function_exists('eventosapp_condition_normalize_text')) {
            return eventosapp_condition_normalize_text($value);
        }

        $value = eventosapp_ticket_variants_value_to_string($value);
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
}

if (!function_exists('eventosapp_ticket_variants_compare_values')) {
    function eventosapp_ticket_variants_compare_values($compare_value) {
        if (function_exists('eventosapp_condition_compare_values')) {
            return eventosapp_condition_compare_values($compare_value);
        }

        $raw = eventosapp_ticket_variants_value_to_string($compare_value);
        if ($raw === '') return [''];

        $parts = preg_split('/\s*\|\s*/', $raw);
        $parts = array_map('trim', is_array($parts) ? $parts : [$raw]);
        $parts = array_values(array_filter($parts, function($item) { return $item !== ''; }));
        return $parts ?: [''];
    }
}

if (!function_exists('eventosapp_ticket_variants_evaluate_operator')) {
    function eventosapp_ticket_variants_evaluate_operator($field_value, $operator, $compare_value) {
        if (function_exists('eventosapp_evaluate_condition_operator')) {
            return eventosapp_evaluate_condition_operator($field_value, $operator, $compare_value);
        }

        $raw_field   = trim(eventosapp_ticket_variants_value_to_string($field_value));
        $raw_compare = trim(eventosapp_ticket_variants_value_to_string($compare_value));
        $field_norm  = eventosapp_ticket_variants_normalize_text($raw_field);
        $compare_norms = array_map('eventosapp_ticket_variants_normalize_text', eventosapp_ticket_variants_compare_values($raw_compare));

        switch ($operator) {
            case 'equals':
                foreach ($compare_norms as $compare_norm) if ($field_norm === $compare_norm) return true;
                return false;
            case 'not_equals':
                foreach ($compare_norms as $compare_norm) if ($field_norm === $compare_norm) return false;
                return true;
            case 'contains':
                foreach ($compare_norms as $compare_norm) if ($compare_norm !== '' && strpos($field_norm, $compare_norm) !== false) return true;
                return false;
            case 'not_contains':
                foreach ($compare_norms as $compare_norm) if ($compare_norm !== '' && strpos($field_norm, $compare_norm) !== false) return false;
                return true;
            case 'starts_with':
                foreach ($compare_norms as $compare_norm) if ($compare_norm !== '' && strpos($field_norm, $compare_norm) === 0) return true;
                return false;
            case 'ends_with':
                foreach ($compare_norms as $compare_norm) {
                    if ($compare_norm === '') continue;
                    $len = strlen($compare_norm);
                    if (substr($field_norm, -$len) === $compare_norm) return true;
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
}

if (!function_exists('eventosapp_ticket_variants_operator_label')) {
    function eventosapp_ticket_variants_operator_label($operator) {
        if (function_exists('eventosapp_condition_operator_label')) {
            return eventosapp_condition_operator_label($operator);
        }

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
}

if (!function_exists('eventosapp_ticket_variants_get_ticket_field_value')) {
    function eventosapp_ticket_variants_get_ticket_field_value($ticket_id, $field_key) {
        $ticket_id = absint($ticket_id);
        $field_key = sanitize_text_field((string) $field_key);

        if (!$ticket_id || $field_key === '') return '';

        if (function_exists('eventosapp_get_ticket_field_value')) {
            return eventosapp_get_ticket_field_value($ticket_id, $field_key);
        }

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
        if (isset($aliases[$field_key])) $field_key = $aliases[$field_key];

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

        if (isset($meta_map[$field_key])) {
            return eventosapp_ticket_variants_value_to_string(get_post_meta($ticket_id, $meta_map[$field_key], true));
        }

        $extra_key = $field_key;
        if (strpos($extra_key, 'extra_') === 0) $extra_key = substr($extra_key, 6);
        $extra_key = sanitize_key((string) $extra_key);
        if ($extra_key !== '') {
            $value = get_post_meta($ticket_id, '_eventosapp_extra_' . $extra_key, true);
            if ($value !== '' && $value !== false && $value !== null) {
                return eventosapp_ticket_variants_value_to_string($value);
            }

            $extras = get_post_meta($ticket_id, '_eventosapp_ticket_extras', true);
            if (is_array($extras)) {
                if (array_key_exists($extra_key, $extras)) return eventosapp_ticket_variants_value_to_string($extras[$extra_key]);
                foreach ($extras as $key => $item_value) {
                    if (sanitize_key((string) $key) === $extra_key) return eventosapp_ticket_variants_value_to_string($item_value);
                }
            }
        }

        return '';
    }
}

if (!function_exists('eventosapp_ticket_variants_get_available_fields')) {
    function eventosapp_ticket_variants_get_available_fields($event_id) {
        $event_id = absint($event_id);

        if (function_exists('eventosapp_get_conditional_fields')) {
            $fields = eventosapp_get_conditional_fields($event_id);
            return is_array($fields) ? $fields : [];
        }

        $fields = [
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

        if (function_exists('eventosapp_get_event_extra_fields') && $event_id) {
            foreach ((array) eventosapp_get_event_extra_fields($event_id) as $extra) {
                if (empty($extra['key'])) continue;
                $key = sanitize_key((string) $extra['key']);
                if ($key === '') continue;
                $fields['extra_' . $key] = (!empty($extra['label']) ? (string) $extra['label'] : $key) . ' (extra)';
            }
        }

        return apply_filters('eventosapp_ticket_variant_fields', $fields, $event_id);
    }
}

if (!function_exists('eventosapp_ticket_variants_get_available_email_templates')) {
    function eventosapp_ticket_variants_get_available_email_templates() {
        // Escanea las ubicaciones reales usadas por el plugin y conserva compatibilidad
        // con instalaciones antiguas que hayan usado /templates/email_tickets/.
        $templates = [];
        $plugin_root = plugin_dir_path(dirname(dirname(__FILE__)));
        $dirs = [
            $plugin_root . 'includes/templates/email_tickets/',
            $plugin_root . 'templates/email_tickets/',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            foreach ((array) glob(trailingslashit($dir) . '*.html') as $file) {
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

        return apply_filters('eventosapp_ticket_variants_available_email_templates', $templates, $dirs);
    }
}

if (!function_exists('eventosapp_ticket_variants_resolve_email_template_path')) {
    function eventosapp_ticket_variants_resolve_email_template_path($template_file) {
        $template_file = sanitize_file_name((string) $template_file);
        if ($template_file === '') return '';

        $plugin_root = plugin_dir_path(dirname(dirname(__FILE__)));
        $dirs = [
            $plugin_root . 'includes/templates/email_tickets/',
            $plugin_root . 'templates/email_tickets/',
        ];

        foreach ($dirs as $dir) {
            $path = trailingslashit($dir) . $template_file;
            if (is_readable($path) && is_file($path)) return $path;
        }

        return '';
    }
}

if (!function_exists('eventosapp_ticket_variants_sanitize_hex')) {
    function eventosapp_ticket_variants_sanitize_hex($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        if ($value[0] !== '#') $value = '#' . $value;
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtoupper($value) : '';
    }
}

if (!function_exists('eventosapp_ticket_variants_normalize_class_id')) {
    function eventosapp_ticket_variants_normalize_class_id($class_id) {
        $class_id = trim(sanitize_text_field((string) $class_id));
        if ($class_id === '') return '';

        $issuer_id = trim((string) get_option('eventosapp_wallet_issuer_id', ''));
        if ($issuer_id && strpos($class_id, '.') === false) {
            $class_id = $issuer_id . '.' . ltrim($class_id, '.');
        }

        return $class_id;
    }
}

if (!function_exists('eventosapp_ticket_variants_sanitize_rule')) {
    function eventosapp_ticket_variants_sanitize_rule($rule) {
        if (!is_array($rule)) return null;

        $clean = [
            'name'       => isset($rule['name']) ? sanitize_text_field($rule['name']) : '',
            'key'        => isset($rule['key']) ? sanitize_key($rule['key']) : '',
            'match'      => (isset($rule['match']) && $rule['match'] === 'any') ? 'any' : 'all',
            'conditions' => [],
            'email_template' => isset($rule['email_template']) ? sanitize_file_name($rule['email_template']) : '',
            'email_header_image_url'  => isset($rule['email_header_image_url']) ? esc_url_raw($rule['email_header_image_url']) : '',
            'email_heading_color'     => isset($rule['email_heading_color']) ? eventosapp_ticket_variants_sanitize_hex($rule['email_heading_color']) : '',
            'email_subheading_color'  => isset($rule['email_subheading_color']) ? eventosapp_ticket_variants_sanitize_hex($rule['email_subheading_color']) : '',
            'email_text_color'        => isset($rule['email_text_color']) ? eventosapp_ticket_variants_sanitize_hex($rule['email_text_color']) : '',
            'google_wallet_class_id'  => isset($rule['google_wallet_class_id']) ? sanitize_text_field($rule['google_wallet_class_id']) : '',
            'google_wallet_logo_url'  => isset($rule['google_wallet_logo_url']) ? esc_url_raw($rule['google_wallet_logo_url']) : '',
            'google_wallet_hero_url'  => isset($rule['google_wallet_hero_url']) ? esc_url_raw($rule['google_wallet_hero_url']) : '',
            'google_wallet_hex_color' => isset($rule['google_wallet_hex_color']) ? eventosapp_ticket_variants_sanitize_hex($rule['google_wallet_hex_color']) : '',
            'google_wallet_event_name'=> isset($rule['google_wallet_event_name']) ? sanitize_text_field($rule['google_wallet_event_name']) : '',
            'apple_icon_url'          => isset($rule['apple_icon_url']) ? esc_url_raw($rule['apple_icon_url']) : '',
            'apple_logo_url'          => isset($rule['apple_logo_url']) ? esc_url_raw($rule['apple_logo_url']) : '',
            'apple_strip_url'         => isset($rule['apple_strip_url']) ? esc_url_raw($rule['apple_strip_url']) : '',
            'apple_hex_bg'            => isset($rule['apple_hex_bg']) ? eventosapp_ticket_variants_sanitize_hex($rule['apple_hex_bg']) : '',
            'apple_hex_fg'            => isset($rule['apple_hex_fg']) ? eventosapp_ticket_variants_sanitize_hex($rule['apple_hex_fg']) : '',
            'apple_hex_label'         => isset($rule['apple_hex_label']) ? eventosapp_ticket_variants_sanitize_hex($rule['apple_hex_label']) : '',
            'apple_event_name'        => isset($rule['apple_event_name']) ? sanitize_text_field($rule['apple_event_name']) : '',
            'internal_notes'          => isset($rule['internal_notes']) ? sanitize_textarea_field($rule['internal_notes']) : '',
        ];

        if ($clean['key'] === '') {
            $clean['key'] = sanitize_key($clean['name']);
        }
        if ($clean['key'] === '') {
            $clean['key'] = 'variant_' . substr(md5(wp_json_encode($rule) . microtime(true)), 0, 8);
        }

        if (!empty($rule['conditions']) && is_array($rule['conditions'])) {
            foreach ($rule['conditions'] as $condition) {
                if (!is_array($condition)) continue;
                $field    = isset($condition['field']) ? sanitize_text_field($condition['field']) : '';
                $operator = isset($condition['operator']) ? sanitize_text_field($condition['operator']) : '';
                $value    = isset($condition['value']) ? sanitize_text_field($condition['value']) : '';
                if ($field === '' || $operator === '') continue;
                $clean['conditions'][] = [
                    'field'    => $field,
                    'operator' => $operator,
                    'value'    => $value,
                ];
            }
        }

        if (empty($clean['conditions']) || $clean['name'] === '') {
            return null;
        }

        return $clean;
    }
}

if (!function_exists('eventosapp_ticket_variants_get_config')) {
    function eventosapp_ticket_variants_get_config($event_id) {
        $event_id = absint($event_id);
        $raw = $event_id ? get_post_meta($event_id, '_eventosapp_ticket_variants_config', true) : [];
        if (!is_array($raw)) $raw = [];

        $rules = [];
        if (!empty($raw['rules']) && is_array($raw['rules'])) {
            foreach ($raw['rules'] as $rule) {
                $clean = eventosapp_ticket_variants_sanitize_rule($rule);
                if ($clean) $rules[] = $clean;
            }
        }

        return [
            'enabled'    => eventosapp_ticket_variants_enabled($event_id),
            'rules'      => $rules,
            'updated_at' => isset($raw['updated_at']) ? sanitize_text_field($raw['updated_at']) : '',
        ];
    }
}

if (!function_exists('eventosapp_ticket_variants_evaluate_rule_detail')) {
    function eventosapp_ticket_variants_evaluate_rule_detail($ticket_id, $rule) {
        $rule = eventosapp_ticket_variants_sanitize_rule($rule);
        if (!$rule) {
            return [
                'matched'    => false,
                'match_mode' => 'all',
                'conditions' => [],
                'rule'       => null,
            ];
        }

        $match_mode = $rule['match'] === 'any' ? 'any' : 'all';
        $details = [];
        $results = [];

        foreach ($rule['conditions'] as $condition) {
            $field_key     = isset($condition['field']) ? (string) $condition['field'] : '';
            $operator      = isset($condition['operator']) ? (string) $condition['operator'] : '';
            $compare_value = isset($condition['value']) ? $condition['value'] : '';
            $field_value   = eventosapp_ticket_variants_get_ticket_field_value($ticket_id, $field_key);
            $matched       = eventosapp_ticket_variants_evaluate_operator($field_value, $operator, $compare_value);

            $results[] = $matched;
            $details[] = [
                'field'               => $field_key,
                'operator'            => $operator,
                'operator_label'      => eventosapp_ticket_variants_operator_label($operator),
                'expected'            => eventosapp_ticket_variants_value_to_string($compare_value),
                'actual'              => eventosapp_ticket_variants_value_to_string($field_value),
                'expected_normalized' => eventosapp_ticket_variants_normalize_text($compare_value),
                'actual_normalized'   => eventosapp_ticket_variants_normalize_text($field_value),
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
}

if (!function_exists('eventosapp_ticket_variants_evaluate')) {
    function eventosapp_ticket_variants_evaluate($ticket_id, $event_id = 0) {
        $ticket_id = absint($ticket_id);
        $event_id  = absint($event_id ?: get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));

        $result = [
            'enabled'       => false,
            'matched'       => false,
            'matched_index' => null,
            'rule'          => null,
            'debug'         => [
                'enabled'       => false,
                'rules_count'   => 0,
                'matched_index' => null,
                'rules'         => [],
                'evaluated_at'  => current_time('mysql'),
            ],
        ];

        if (!$ticket_id || !$event_id) return $result;

        $config = eventosapp_ticket_variants_get_config($event_id);
        $rules  = isset($config['rules']) && is_array($config['rules']) ? $config['rules'] : [];

        $result['enabled'] = !empty($config['enabled']);
        $result['debug']['enabled'] = !empty($config['enabled']);
        $result['debug']['rules_count'] = count($rules);

        if (empty($config['enabled']) || empty($rules)) {
            return $result;
        }

        foreach ($rules as $index => $rule) {
            $detail = eventosapp_ticket_variants_evaluate_rule_detail($ticket_id, $rule);
            $result['debug']['rules'][] = array_merge([
                'index' => (int) $index,
                'valid' => !empty($detail['rule']),
            ], $detail);

            if (!empty($detail['matched']) && !empty($detail['rule'])) {
                $result['matched'] = true;
                $result['matched_index'] = (int) $index;
                $result['rule'] = $detail['rule'];
                $result['debug']['matched_index'] = (int) $index;
                return $result;
            }
        }

        return $result;
    }
}

if (!function_exists('eventosapp_ticket_variants_apply_to_ticket')) {
    function eventosapp_ticket_variants_apply_to_ticket($ticket_id, $event_id = 0, $force = false) {
        $ticket_id = absint($ticket_id);
        $event_id  = absint($event_id ?: get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));

        if (!$ticket_id || !$event_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
            return [
                'applied' => false,
                'reason'  => 'ticket_or_event_invalid',
            ];
        }

        $evaluation = eventosapp_ticket_variants_evaluate($ticket_id, $event_id);
        update_post_meta($ticket_id, '_eventosapp_ticket_variant_last_debug', $evaluation['debug']);

        if (empty($evaluation['enabled'])) {
            eventosapp_ticket_variants_clear_ticket_meta($ticket_id);
            update_post_meta($ticket_id, '_eventosapp_ticket_variant_last_debug', $evaluation['debug']);
            return [
                'applied' => false,
                'reason'  => 'disabled',
                'debug'   => $evaluation['debug'],
            ];
        }

        if (empty($evaluation['matched']) || empty($evaluation['rule'])) {
            eventosapp_ticket_variants_clear_ticket_meta($ticket_id);
            update_post_meta($ticket_id, '_eventosapp_ticket_variant_last_debug', $evaluation['debug']);
            return [
                'applied' => false,
                'reason'  => 'no_match',
                'debug'   => $evaluation['debug'],
            ];
        }

        $rule = $evaluation['rule'];

        update_post_meta($ticket_id, '_eventosapp_ticket_variant_key', $rule['key']);
        update_post_meta($ticket_id, '_eventosapp_ticket_variant_name', $rule['name']);
        update_post_meta($ticket_id, '_eventosapp_ticket_variant_rule_index', (int) $evaluation['matched_index']);
        update_post_meta($ticket_id, '_eventosapp_ticket_variant_rule', $rule);

        if (!empty($rule['email_template'])) {
            update_post_meta($ticket_id, '_eventosapp_ticket_email_template_override', $rule['email_template']);
            update_post_meta($ticket_id, '_eventosapp_ticket_email_template', $rule['email_template']);
            $template_path = function_exists('eventosapp_ticket_variants_resolve_email_template_path') ? eventosapp_ticket_variants_resolve_email_template_path($rule['email_template']) : '';
            if ($template_path !== '') {
                update_post_meta($ticket_id, '_eventosapp_ticket_email_template_path', $template_path);
            } else {
                delete_post_meta($ticket_id, '_eventosapp_ticket_email_template_path');
            }
        } else {
            delete_post_meta($ticket_id, '_eventosapp_ticket_email_template_override');
            delete_post_meta($ticket_id, '_eventosapp_ticket_email_template');
            delete_post_meta($ticket_id, '_eventosapp_ticket_email_template_path');
        }

        if (!empty($rule['email_header_image_url'])) update_post_meta($ticket_id, '_eventosapp_ticket_email_header_image_url', $rule['email_header_image_url']); else delete_post_meta($ticket_id, '_eventosapp_ticket_email_header_image_url');
        if (!empty($rule['email_heading_color'])) update_post_meta($ticket_id, '_eventosapp_ticket_email_heading_color', $rule['email_heading_color']); else delete_post_meta($ticket_id, '_eventosapp_ticket_email_heading_color');
        if (!empty($rule['email_subheading_color'])) update_post_meta($ticket_id, '_eventosapp_ticket_email_subheading_color', $rule['email_subheading_color']); else delete_post_meta($ticket_id, '_eventosapp_ticket_email_subheading_color');
        if (!empty($rule['email_text_color'])) update_post_meta($ticket_id, '_eventosapp_ticket_email_text_color', $rule['email_text_color']); else delete_post_meta($ticket_id, '_eventosapp_ticket_email_text_color');

        $google_class_id_manual = eventosapp_ticket_variants_normalize_class_id($rule['google_wallet_class_id'] ?? '');
        $google_class_id_auto   = eventosapp_ticket_variants_normalize_class_id(eventosapp_ticket_variants_build_auto_google_class_id($event_id, $rule['key'] ?? ''));
        $google_class_id        = $google_class_id_manual !== '' ? $google_class_id_manual : $google_class_id_auto;
        $google_class_source    = $google_class_id_manual !== '' ? 'manual' : 'auto';

        if ($google_class_id !== '') {
            update_post_meta($ticket_id, '_eventosapp_wallet_variant_class_id', $google_class_id);
            update_post_meta($ticket_id, '_eventosapp_wallet_variant_class_source', $google_class_source);
            if ($google_class_id_auto !== '') {
                update_post_meta($ticket_id, '_eventosapp_wallet_variant_class_auto', $google_class_id_auto);
            } else {
                delete_post_meta($ticket_id, '_eventosapp_wallet_variant_class_auto');
            }
        } else {
            delete_post_meta($ticket_id, '_eventosapp_wallet_variant_class_id');
            delete_post_meta($ticket_id, '_eventosapp_wallet_variant_class_auto');
            delete_post_meta($ticket_id, '_eventosapp_wallet_variant_class_source');
        }

        if (!empty($rule['google_wallet_logo_url'])) update_post_meta($ticket_id, '_eventosapp_wallet_variant_logo_url', $rule['google_wallet_logo_url']); else delete_post_meta($ticket_id, '_eventosapp_wallet_variant_logo_url');
        if (!empty($rule['google_wallet_hero_url'])) update_post_meta($ticket_id, '_eventosapp_wallet_variant_hero_img_url', $rule['google_wallet_hero_url']); else delete_post_meta($ticket_id, '_eventosapp_wallet_variant_hero_img_url');
        if (!empty($rule['google_wallet_hex_color'])) update_post_meta($ticket_id, '_eventosapp_wallet_variant_hex_color', $rule['google_wallet_hex_color']); else delete_post_meta($ticket_id, '_eventosapp_wallet_variant_hex_color');
        if (!empty($rule['google_wallet_event_name'])) update_post_meta($ticket_id, '_eventosapp_wallet_variant_event_name', $rule['google_wallet_event_name']); else delete_post_meta($ticket_id, '_eventosapp_wallet_variant_event_name');

        if (!empty($rule['apple_icon_url'])) update_post_meta($ticket_id, '_eventosapp_apple_variant_icon_url', $rule['apple_icon_url']); else delete_post_meta($ticket_id, '_eventosapp_apple_variant_icon_url');
        if (!empty($rule['apple_logo_url'])) update_post_meta($ticket_id, '_eventosapp_apple_variant_logo_url', $rule['apple_logo_url']); else delete_post_meta($ticket_id, '_eventosapp_apple_variant_logo_url');
        if (!empty($rule['apple_strip_url'])) update_post_meta($ticket_id, '_eventosapp_apple_variant_strip_url', $rule['apple_strip_url']); else delete_post_meta($ticket_id, '_eventosapp_apple_variant_strip_url');
        if (!empty($rule['apple_hex_bg'])) update_post_meta($ticket_id, '_eventosapp_apple_variant_hex_bg', $rule['apple_hex_bg']); else delete_post_meta($ticket_id, '_eventosapp_apple_variant_hex_bg');
        if (!empty($rule['apple_hex_fg'])) update_post_meta($ticket_id, '_eventosapp_apple_variant_hex_fg', $rule['apple_hex_fg']); else delete_post_meta($ticket_id, '_eventosapp_apple_variant_hex_fg');
        if (!empty($rule['apple_hex_label'])) update_post_meta($ticket_id, '_eventosapp_apple_variant_hex_label', $rule['apple_hex_label']); else delete_post_meta($ticket_id, '_eventosapp_apple_variant_hex_label');
        if (!empty($rule['apple_event_name'])) update_post_meta($ticket_id, '_eventosapp_apple_variant_event_name', $rule['apple_event_name']); else delete_post_meta($ticket_id, '_eventosapp_apple_variant_event_name');

        update_post_meta($ticket_id, '_eventosapp_ticket_variant_last_debug', $evaluation['debug']);

        do_action('eventosapp_ticket_variant_applied', $ticket_id, $event_id, $rule, $evaluation);

        eventosapp_ticket_variants_log('Variante de ticket aplicada', [
            'ticket_id' => $ticket_id,
            'event_id' => $event_id,
            'variant_key' => $rule['key'],
            'variant_name' => $rule['name'],
            'matched_index' => (int) $evaluation['matched_index'],
            'google_wallet_class_id' => get_post_meta($ticket_id, '_eventosapp_wallet_variant_class_id', true),
            'google_wallet_class_source' => get_post_meta($ticket_id, '_eventosapp_wallet_variant_class_source', true),
        ]);

        return [
            'applied'       => true,
            'variant_key'   => $rule['key'],
            'variant_name'  => $rule['name'],
            'matched_index' => (int) $evaluation['matched_index'],
            'rule'          => $rule,
            'debug'         => $evaluation['debug'],
        ];
    }
}

if (!function_exists('eventosapp_ticket_variants_apply_on_save')) {
    add_action('save_post_eventosapp_ticket', 'eventosapp_ticket_variants_apply_on_save', 21, 3);
    function eventosapp_ticket_variants_apply_on_save($post_id, $post = null, $update = null) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (get_post_type($post_id) !== 'eventosapp_ticket') return;

        $event_id = (int) get_post_meta($post_id, '_eventosapp_ticket_evento_id', true);
        if (!$event_id) return;

        eventosapp_ticket_variants_apply_to_ticket($post_id, $event_id, true);
    }
}

if (!function_exists('eventosapp_ticket_variants_get_email_template_for_ticket')) {
    function eventosapp_ticket_variants_get_email_template_for_ticket($ticket_id, $event_id = 0) {
        $ticket_id = absint($ticket_id);
        $event_id  = absint($event_id ?: get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        if (!$ticket_id || !$event_id) return '';

        eventosapp_ticket_variants_apply_to_ticket($ticket_id, $event_id, true);

        $template = (string) get_post_meta($ticket_id, '_eventosapp_ticket_email_template_override', true);
        if ($template !== '') {
            $GLOBALS['eventosapp_ticket_variants_last_email_ticket_id'] = $ticket_id;
            $GLOBALS['eventosapp_ticket_variants_last_email_event_id'] = $event_id;
        }

        return $template;
    }
}

if (!function_exists('eventosapp_ticket_variants_email_template_filter')) {
    function eventosapp_ticket_variants_email_template_filter($template = '', $ticket_id = 0, $event_id = 0) {
        $ticket_id = absint($ticket_id);
        if (!$ticket_id) return $template;

        $variant_template = eventosapp_ticket_variants_get_email_template_for_ticket($ticket_id, $event_id);
        return $variant_template !== '' ? $variant_template : $template;
    }

    add_filter('eventosapp_ticket_email_template', 'eventosapp_ticket_variants_email_template_filter', 20, 3);
    add_filter('eventosapp_ticket_email_template_file', 'eventosapp_ticket_variants_email_template_filter', 20, 3);
    add_filter('eventosapp_email_ticket_template', 'eventosapp_ticket_variants_email_template_filter', 20, 3);
    add_filter('eventosapp_email_template_for_ticket', 'eventosapp_ticket_variants_email_template_filter', 20, 3);
}



if (!function_exists('eventosapp_ticket_variants_email_template_path_filter')) {
    function eventosapp_ticket_variants_email_template_path_filter($template_path = '', $ticket_id = 0, $event_id = 0) {
        $ticket_id = absint($ticket_id);
        if (!$ticket_id) return $template_path;

        $event_id = absint($event_id ?: get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        $variant_template = eventosapp_ticket_variants_get_email_template_for_ticket($ticket_id, $event_id);
        if ($variant_template === '') return $template_path;

        $resolved = eventosapp_ticket_variants_resolve_email_template_path($variant_template);
        return $resolved !== '' ? $resolved : $template_path;
    }

    add_filter('eventosapp_ticket_email_template_path', 'eventosapp_ticket_variants_email_template_path_filter', 20, 3);
    add_filter('eventosapp_email_ticket_template_path', 'eventosapp_ticket_variants_email_template_path_filter', 20, 3);
    add_filter('eventosapp_template_email_ticket_path', 'eventosapp_ticket_variants_email_template_path_filter', 20, 3);
}

if (!function_exists('eventosapp_ticket_variants_get_email_branding_for_ticket')) {
    function eventosapp_ticket_variants_get_email_branding_for_ticket($ticket_id, $event_id = 0) {
        $ticket_id = absint($ticket_id);
        $event_id  = absint($event_id ?: get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        if (!$ticket_id || !$event_id) return [];

        eventosapp_ticket_variants_apply_to_ticket($ticket_id, $event_id, true);

        $branding = [
            'header_image_url' => (string) get_post_meta($ticket_id, '_eventosapp_ticket_email_header_image_url', true),
            'heading_color'    => (string) get_post_meta($ticket_id, '_eventosapp_ticket_email_heading_color', true),
            'subheading_color' => (string) get_post_meta($ticket_id, '_eventosapp_ticket_email_subheading_color', true),
            'text_color'       => (string) get_post_meta($ticket_id, '_eventosapp_ticket_email_text_color', true),
        ];

        return apply_filters('eventosapp_ticket_variant_email_branding', $branding, $ticket_id, $event_id);
    }
}

if (!function_exists('eventosapp_ticket_variants_email_tokens_filter')) {
    function eventosapp_ticket_variants_email_tokens_filter($tokens, $ticket_id = 0, $event_id = 0) {
        if (!is_array($tokens)) $tokens = [];
        $ticket_id = absint($ticket_id);
        if (!$ticket_id) return $tokens;

        $event_id = absint($event_id ?: get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        $branding = eventosapp_ticket_variants_get_email_branding_for_ticket($ticket_id, $event_id);
        $template = eventosapp_ticket_variants_get_email_template_for_ticket($ticket_id, $event_id);
        $path     = $template ? eventosapp_ticket_variants_resolve_email_template_path($template) : '';

        $tokens['{{ticket_variant_email_template}}'] = $template;
        $tokens['{{ticket_variant_email_template_path}}'] = $path;
        $tokens['{{ticket_variant_email_header_image_url}}'] = $branding['header_image_url'] ?? '';
        $tokens['{{ticket_variant_email_heading_color}}'] = $branding['heading_color'] ?? '';
        $tokens['{{ticket_variant_email_subheading_color}}'] = $branding['subheading_color'] ?? '';
        $tokens['{{ticket_variant_email_text_color}}'] = $branding['text_color'] ?? '';

        return $tokens;
    }

    add_filter('eventosapp_ticket_email_tokens', 'eventosapp_ticket_variants_email_tokens_filter', 20, 3);
    add_filter('eventosapp_email_ticket_tokens', 'eventosapp_ticket_variants_email_tokens_filter', 20, 3);
    add_filter('eventosapp_ticket_email_replacements', 'eventosapp_ticket_variants_email_tokens_filter', 20, 3);
}

if (!function_exists('eventosapp_ticket_variants_apply_email_branding_to_html')) {
    function eventosapp_ticket_variants_apply_email_branding_to_html($html, $ticket_id = 0, $event_id = 0) {
        $ticket_id = absint($ticket_id);
        if (!$ticket_id || !is_string($html) || $html === '') return $html;

        $event_id = absint($event_id ?: get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        $branding = eventosapp_ticket_variants_get_email_branding_for_ticket($ticket_id, $event_id);

        $header_url = esc_url($branding['header_image_url'] ?? '');
        $heading    = eventosapp_ticket_variants_sanitize_hex($branding['heading_color'] ?? '');
        $subheading = eventosapp_ticket_variants_sanitize_hex($branding['subheading_color'] ?? '');
        $text       = eventosapp_ticket_variants_sanitize_hex($branding['text_color'] ?? '');

        $had_header_placeholder = (stripos($html, '{{ticket_variant_email_header_image_url}}') !== false || stripos($html, '[[ticket_variant_email_header_image_url]]') !== false);

        $replacements = [
            '{{ticket_variant_email_header_image_url}}' => $header_url,
            '{{ticket_variant_email_heading_color}}'    => $heading,
            '{{ticket_variant_email_subheading_color}}' => $subheading,
            '{{ticket_variant_email_text_color}}'       => $text,
            '[[ticket_variant_email_header_image_url]]' => $header_url,
            '[[ticket_variant_email_heading_color]]'    => $heading,
            '[[ticket_variant_email_subheading_color]]' => $subheading,
            '[[ticket_variant_email_text_color]]'       => $text,
        ];
        $html = strtr($html, $replacements);

        $css = '';
        if ($heading) {
            $css .= 'h1,.evapp-email-title,.eventosapp-email-title{color:' . esc_attr($heading) . ' !important;}';
        }
        if ($subheading) {
            $css .= 'h2,h3,.evapp-email-subtitle,.eventosapp-email-subtitle{color:' . esc_attr($subheading) . ' !important;}';
        }
        if ($text) {
            $css .= '.evapp-email-heading,.eventosapp-email-heading,.evapp-email-header-text,.eventosapp-email-header-text{color:' . esc_attr($text) . ' !important;}';
        }
        if ($css !== '') {
            $style = '<style type="text/css">' . $css . '</style>';
            if (stripos($html, '</head>') !== false) {
                $html = preg_replace('/<\/head>/i', $style . '</head>', $html, 1);
            } else {
                $html = $style . $html;
            }
        }

        if ($header_url !== '' && !$had_header_placeholder && stripos($html, 'evapp-variant-email-header') === false) {
            $img = '<div class="evapp-variant-email-header" style="width:100%;text-align:center;margin:0 0 18px 0;"><img src="' . esc_url($header_url) . '" alt="" style="display:block;width:100%;max-width:100%;height:auto;border:0;margin:0 auto;"></div>';
            if (preg_match('/<body[^>]*>/i', $html)) {
                $html = preg_replace('/(<body[^>]*>)/i', '$1' . $img, $html, 1);
            } else {
                $html = $img . $html;
            }
        }

        return $html;
    }

    add_filter('eventosapp_ticket_email_html', 'eventosapp_ticket_variants_apply_email_branding_to_html', 20, 3);
    add_filter('eventosapp_email_ticket_html', 'eventosapp_ticket_variants_apply_email_branding_to_html', 20, 3);
    add_filter('eventosapp_ticket_email_body', 'eventosapp_ticket_variants_apply_email_branding_to_html', 20, 3);
}

if (!function_exists('eventosapp_ticket_variants_wp_mail_filter')) {
    function eventosapp_ticket_variants_wp_mail_filter($atts) {
        $ticket_id = isset($GLOBALS['eventosapp_ticket_variants_last_email_ticket_id']) ? absint($GLOBALS['eventosapp_ticket_variants_last_email_ticket_id']) : 0;
        $event_id  = isset($GLOBALS['eventosapp_ticket_variants_last_email_event_id']) ? absint($GLOBALS['eventosapp_ticket_variants_last_email_event_id']) : 0;

        if (!$ticket_id || !is_array($atts) || empty($atts['message']) || !is_string($atts['message'])) {
            return $atts;
        }

        // Se aplica una sola vez al correo inmediatamente posterior a la resolución de plantilla del ticket.
        unset($GLOBALS['eventosapp_ticket_variants_last_email_ticket_id'], $GLOBALS['eventosapp_ticket_variants_last_email_event_id']);

        $atts['message'] = eventosapp_ticket_variants_apply_email_branding_to_html($atts['message'], $ticket_id, $event_id);
        return $atts;
    }

    add_filter('wp_mail', 'eventosapp_ticket_variants_wp_mail_filter', 20, 1);
}

if (!function_exists('eventosapp_ticket_variants_filter_google_wallet_context')) {
    function eventosapp_ticket_variants_filter_google_wallet_context($ctx, $ticket_id, $event_id = 0) {
        if (!is_array($ctx)) $ctx = [];

        $ticket_id = absint($ticket_id);
        $event_id  = absint($event_id ?: get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        if (!$ticket_id || !$event_id) return $ctx;

        eventosapp_ticket_variants_apply_to_ticket($ticket_id, $event_id, true);

        $class_id   = get_post_meta($ticket_id, '_eventosapp_wallet_variant_class_id', true);
        $logo_url   = get_post_meta($ticket_id, '_eventosapp_wallet_variant_logo_url', true);
        $hero_url   = get_post_meta($ticket_id, '_eventosapp_wallet_variant_hero_img_url', true);
        $hex_color  = get_post_meta($ticket_id, '_eventosapp_wallet_variant_hex_color', true);
        $event_name = get_post_meta($ticket_id, '_eventosapp_wallet_variant_event_name', true);

        if ($class_id !== '') $ctx['class_id'] = eventosapp_ticket_variants_normalize_class_id($class_id);
        if ($class_id !== '') {
            $ctx['variant_key'] = get_post_meta($ticket_id, '_eventosapp_ticket_variant_key', true);
            $ctx['variant_name'] = get_post_meta($ticket_id, '_eventosapp_ticket_variant_name', true);
            $ctx['variant_class_source'] = get_post_meta($ticket_id, '_eventosapp_wallet_variant_class_source', true);
        }
        if ($logo_url !== '') $ctx['logo_url'] = esc_url_raw($logo_url);
        if ($hero_url !== '') $ctx['hero_url'] = esc_url_raw($hero_url);
        if ($hex_color !== '') $ctx['hex_color'] = eventosapp_ticket_variants_sanitize_hex($hex_color);
        if ($event_name !== '') $ctx['nombre_evento'] = sanitize_text_field($event_name);

        if ($class_id !== '') {
            eventosapp_ticket_variants_log('Contexto Google Wallet de variante aplicado', [
                'ticket_id' => $ticket_id,
                'event_id' => $event_id,
                'class_id' => $ctx['class_id'] ?? '',
                'class_source' => $ctx['variant_class_source'] ?? '',
                'logo_override' => $logo_url !== '' ? 'yes' : 'no',
                'hero_override' => $hero_url !== '' ? 'yes' : 'no',
                'event_name_override' => $event_name !== '' ? 'yes' : 'no',
            ]);
        }

        return $ctx;
    }
}

if (!function_exists('eventosapp_ticket_variants_filter_apple_context')) {
    function eventosapp_ticket_variants_filter_apple_context($ctx, $ticket_id, $event_id = 0) {
        if (!is_array($ctx)) $ctx = [];

        $ticket_id = absint($ticket_id);
        $event_id  = absint($event_id ?: get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        if (!$ticket_id || !$event_id) return $ctx;

        eventosapp_ticket_variants_apply_to_ticket($ticket_id, $event_id, true);

        $event_name = get_post_meta($ticket_id, '_eventosapp_apple_variant_event_name', true);
        if ($event_name !== '') $ctx['nombre_evento'] = sanitize_text_field($event_name);

        return $ctx;
    }
}

if (!function_exists('eventosapp_ticket_variants_refresh_google_wallet_object')) {
    function eventosapp_ticket_variants_refresh_google_wallet_object($ticket_id, $event_id = 0) {
        $ticket_id = absint($ticket_id);
        $event_id  = absint($event_id ?: get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        if (!$ticket_id || !$event_id) return false;

        if (!function_exists('eventosapp_google_wallet_get_access_token') || !function_exists('eventosapp_wallet_build_event_context') || !function_exists('eventosapp_wallet_patch_single_ticket')) {
            return false;
        }

        eventosapp_ticket_variants_apply_to_ticket($ticket_id, $event_id, true);

        $has_variant_wallet = get_post_meta($ticket_id, '_eventosapp_wallet_variant_class_id', true)
            || get_post_meta($ticket_id, '_eventosapp_wallet_variant_logo_url', true)
            || get_post_meta($ticket_id, '_eventosapp_wallet_variant_hero_img_url', true)
            || get_post_meta($ticket_id, '_eventosapp_wallet_variant_hex_color', true)
            || get_post_meta($ticket_id, '_eventosapp_wallet_variant_event_name', true);

        if (!$has_variant_wallet) return false;

        $auth = eventosapp_google_wallet_get_access_token();
        $access_token = is_array($auth) ? ($auth['access_token'] ?? ($auth['token'] ?? '')) : '';
        if (empty($access_token)) {
            error_log('[EventosApp] Variante Wallet Google no pudo refrescar ticket=' . $ticket_id . ' porque no hay access_token/token');
            return false;
        }

        $ctx = eventosapp_wallet_build_event_context($event_id);
        $ctx = eventosapp_ticket_variants_filter_google_wallet_context($ctx, $ticket_id, $event_id);

        if (empty($ctx['issuer_id']) || empty($ctx['class_id'])) {
            error_log('[EventosApp] Variante Wallet Google sin issuer/class ticket=' . $ticket_id . ' evento=' . $event_id);
            return false;
        }

        return eventosapp_wallet_patch_single_ticket($event_id, $ticket_id, $access_token, $ctx);
    }
}



/* ============================================================
 * =========== GOOGLE WALLET: CLASES DE VARIANTES =============
 * ============================================================ */

if (!function_exists('eventosapp_ticket_variants_get_google_class_id_for_rule')) {
    /**
     * Devuelve el Class ID efectivo de Google Wallet para una regla de variante.
     * Si el metabox no trae Class ID manual, genera uno automático por evento + variante.
     */
    function eventosapp_ticket_variants_get_google_class_id_for_rule($event_id, $rule) {
        $event_id = absint($event_id);
        $rule = is_array($rule) ? $rule : [];
        if (!$event_id || empty($rule)) return '';

        $manual = eventosapp_ticket_variants_normalize_class_id($rule['google_wallet_class_id'] ?? '');
        if ($manual !== '') return $manual;

        $variant_key = isset($rule['key']) ? sanitize_key($rule['key']) : '';
        $auto = eventosapp_ticket_variants_build_auto_google_class_id($event_id, $variant_key);
        return eventosapp_ticket_variants_normalize_class_id($auto);
    }
}

if (!function_exists('eventosapp_ticket_variants_get_google_class_source_for_rule')) {
    function eventosapp_ticket_variants_get_google_class_source_for_rule($rule) {
        $rule = is_array($rule) ? $rule : [];
        $manual = eventosapp_ticket_variants_normalize_class_id($rule['google_wallet_class_id'] ?? '');
        return $manual !== '' ? 'manual' : 'auto';
    }
}

if (!function_exists('eventosapp_ticket_variants_apply_rule_to_google_context')) {
    /**
     * Construye el contexto de clase Google Wallet para una variante sin depender de un ticket.
     * Esto permite crear/actualizar la clase al guardar el evento, incluso antes del batch por tickets.
     */
    function eventosapp_ticket_variants_apply_rule_to_google_context($ctx, $event_id, $rule) {
        $ctx = is_array($ctx) ? $ctx : [];
        $event_id = absint($event_id);
        $rule = is_array($rule) ? $rule : [];

        $class_id = eventosapp_ticket_variants_get_google_class_id_for_rule($event_id, $rule);
        if ($class_id !== '') {
            $ctx['class_id'] = $class_id;
        }

        if (!empty($rule['google_wallet_logo_url'])) {
            $ctx['logo_url'] = esc_url_raw($rule['google_wallet_logo_url']);
        }
        if (!empty($rule['google_wallet_hero_url'])) {
            $ctx['hero_url'] = esc_url_raw($rule['google_wallet_hero_url']);
        }
        if (!empty($rule['google_wallet_hex_color'])) {
            $ctx['hex_color'] = eventosapp_ticket_variants_sanitize_hex($rule['google_wallet_hex_color']);
        }
        if (!empty($rule['google_wallet_event_name'])) {
            $ctx['nombre_evento'] = sanitize_text_field($rule['google_wallet_event_name']);
        }

        $ctx['variant_key'] = isset($rule['key']) ? sanitize_key($rule['key']) : '';
        $ctx['variant_name'] = isset($rule['name']) ? sanitize_text_field($rule['name']) : '';
        $ctx['variant_class_source'] = eventosapp_ticket_variants_get_google_class_source_for_rule($rule);

        return $ctx;
    }
}

if (!function_exists('eventosapp_ticket_variants_sync_google_wallet_classes_for_event')) {
    /**
     * Crea/actualiza en Google Wallet todas las clases Android asociadas a variantes configuradas.
     *
     * Esta función es intencionalmente a nivel EVENTO, no a nivel ticket:
     * - Si la variante ya existe en el metabox y se guarda el evento, la clase se crea si falta.
     * - Si la clase ya existe, se actualiza con logo/hero/color/nombre de la variante.
     * - Después el batch puede regenerar/actualizar los tickets existentes usando esa clase.
     */
    function eventosapp_ticket_variants_sync_google_wallet_classes_for_event($event_id, $reason = '') {
        $event_id = absint($event_id);
        $summary = [
            'ok' => false,
            'reason' => '',
            'total_rules' => 0,
            'synced' => 0,
            'failed' => 0,
            'skipped' => 0,
            'classes' => [],
        ];

        if (!$event_id || get_post_type($event_id) !== 'eventosapp_event') {
            $summary['reason'] = 'invalid_event';
            return $summary;
        }

        $wallet_android_on = get_post_meta($event_id, '_eventosapp_ticket_wallet_android', true) === '1';
        if (!$wallet_android_on) {
            $summary['reason'] = 'android_wallet_disabled';
            eventosapp_ticket_variants_log('Sync clases Android variantes omitido: Android Wallet no está activo en el evento', [
                'event_id' => $event_id,
                'reason' => $reason,
            ]);
            return $summary;
        }

        $config = eventosapp_ticket_variants_get_config($event_id);
        $rules = isset($config['rules']) && is_array($config['rules']) ? $config['rules'] : [];
        $summary['total_rules'] = count($rules);

        if (empty($config['enabled']) || empty($rules)) {
            $summary['reason'] = empty($config['enabled']) ? 'variants_disabled' : 'no_rules';
            eventosapp_ticket_variants_log('Sync clases Android variantes omitido: no hay variantes activas', [
                'event_id' => $event_id,
                'enabled' => !empty($config['enabled']) ? 'yes' : 'no',
                'rules_count' => count($rules),
                'reason' => $reason,
            ]);
            return $summary;
        }

        if (!function_exists('eventosapp_wallet_build_event_context')) {
            $summary['reason'] = 'missing_event_context_function';
            eventosapp_ticket_variants_log('Sync clases Android variantes falló: falta eventosapp_wallet_build_event_context()', [
                'event_id' => $event_id,
                'reason' => $reason,
            ]);
            return $summary;
        }

        $base_ctx = eventosapp_wallet_build_event_context($event_id);
        if (empty($base_ctx['issuer_id'])) {
            $summary['reason'] = 'missing_issuer_id';
            eventosapp_ticket_variants_log('Sync clases Android variantes falló: falta issuer_id', [
                'event_id' => $event_id,
                'reason' => $reason,
            ]);
            return $summary;
        }

        $access_token = '';
        if (function_exists('eventosapp_google_wallet_get_access_token')) {
            $auth = eventosapp_google_wallet_get_access_token();
            $access_token = is_array($auth) ? (string) ($auth['token'] ?? ($auth['access_token'] ?? '')) : '';
        }

        $can_ensure = $access_token !== '' && function_exists('eventosapp_wallet_ensure_event_ticket_class');
        $can_sync   = function_exists('eventosapp_sync_wallet_class');

        if (!$can_ensure && !$can_sync) {
            $summary['reason'] = 'missing_wallet_sync_functions';
            eventosapp_ticket_variants_log('Sync clases Android variantes falló: no hay funciones de sincronización disponibles', [
                'event_id' => $event_id,
                'has_token' => $access_token !== '' ? 'yes' : 'no',
                'reason' => $reason,
            ]);
            return $summary;
        }

        foreach ($rules as $index => $rule) {
            if (!is_array($rule)) {
                $summary['skipped']++;
                continue;
            }

            $class_id = eventosapp_ticket_variants_get_google_class_id_for_rule($event_id, $rule);
            if ($class_id === '') {
                $summary['skipped']++;
                eventosapp_ticket_variants_log('Regla de variante sin Class ID Android efectivo', [
                    'event_id' => $event_id,
                    'index' => (int) $index,
                    'variant_key' => $rule['key'] ?? '',
                    'reason' => $reason,
                ]);
                continue;
            }

            $ctx = eventosapp_ticket_variants_apply_rule_to_google_context($base_ctx, $event_id, $rule);
            $ctx['class_id'] = $class_id;

            $ok = false;
            if ($can_ensure) {
                $ok = (bool) eventosapp_wallet_ensure_event_ticket_class($event_id, $access_token, $ctx, 0);
            } elseif ($can_sync) {
                $sync = eventosapp_sync_wallet_class($event_id, [
                    'force_class_id' => $class_id,
                    'logo_url' => $ctx['logo_url'] ?? '',
                    'hero_url' => $ctx['hero_url'] ?? '',
                    'hex_color' => $ctx['hex_color'] ?? '',
                    'brand_text' => $ctx['brand_text'] ?? '',
                    'event_name' => $ctx['nombre_evento'] ?? '',
                    'variant_key' => $ctx['variant_key'] ?? '',
                    'variant_name' => $ctx['variant_name'] ?? '',
                ]);
                $ok = is_array($sync) && !empty($sync['ok']);
            }

            $class_row = [
                'index' => (int) $index,
                'variant_key' => $rule['key'] ?? '',
                'variant_name' => $rule['name'] ?? '',
                'class_id' => $class_id,
                'class_source' => $ctx['variant_class_source'] ?? '',
                'ok' => $ok ? 'yes' : 'no',
            ];
            $summary['classes'][] = $class_row;

            if ($ok) {
                $summary['synced']++;
            } else {
                $summary['failed']++;
            }

            eventosapp_ticket_variants_log($ok ? 'Clase Android de variante sincronizada' : 'No se pudo sincronizar clase Android de variante', array_merge([
                'event_id' => $event_id,
                'reason' => $reason,
            ], $class_row));
        }

        $summary['ok'] = $summary['failed'] === 0 && ($summary['synced'] > 0 || $summary['skipped'] === $summary['total_rules']);
        $summary['reason'] = $summary['reason'] ?: 'processed';

        update_post_meta($event_id, '_eventosapp_ticket_variants_wallet_classes_last_sync', $summary);
        update_post_meta($event_id, '_eventosapp_ticket_variants_wallet_classes_last_sync_at', current_time('mysql'));

        eventosapp_ticket_variants_log('Resumen sync clases Android variantes', [
            'event_id' => $event_id,
            'reason' => $reason,
            'synced' => $summary['synced'],
            'failed' => $summary['failed'],
            'skipped' => $summary['skipped'],
            'total_rules' => $summary['total_rules'],
        ]);

        return $summary;
    }
}

if (!function_exists('eventosapp_ticket_variants_sync_google_wallet_classes_on_event_save')) {
    function eventosapp_ticket_variants_sync_google_wallet_classes_on_event_save($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (get_post_type($post_id) !== 'eventosapp_event') return;

        $valid_event_nonce = isset($_POST['eventosapp_nonce']) && wp_verify_nonce($_POST['eventosapp_nonce'], 'eventosapp_detalles_evento');
        $valid_variant_nonce = isset($_POST['eventosapp_ticket_variants_nonce']) && wp_verify_nonce($_POST['eventosapp_ticket_variants_nonce'], 'eventosapp_ticket_variants_save');

        if (!$valid_event_nonce && !$valid_variant_nonce) return;
        if (!current_user_can('edit_post', $post_id)) return;

        eventosapp_ticket_variants_sync_google_wallet_classes_for_event($post_id, 'save_post_eventosapp_event');
    }
}
add_action('save_post_eventosapp_event', 'eventosapp_ticket_variants_sync_google_wallet_classes_on_event_save', 35);

/* ============================================================
 * ======================== METABOX ===========================
 * ============================================================ */

add_action('add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_ticket_variants',
        '🎟️ Variantes de Ticket por Reglas',
        'eventosapp_ticket_variants_render_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
}, 47);

if (!function_exists('eventosapp_ticket_variants_render_metabox')) {
    function eventosapp_ticket_variants_render_metabox($post) {
        wp_nonce_field('eventosapp_ticket_variants_save', 'eventosapp_ticket_variants_nonce');

        $enabled = eventosapp_ticket_variants_enabled($post->ID);
        $config  = eventosapp_ticket_variants_get_config($post->ID);
        $rules   = isset($config['rules']) && is_array($config['rules']) ? $config['rules'] : [];

        $fields = eventosapp_ticket_variants_get_available_fields($post->ID);
        $templates = eventosapp_ticket_variants_get_available_email_templates();

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
            .evapp-var-wrap { font-size:13px; line-height:1.5; }
            .evapp-var-intro { color:#555; margin-bottom:16px; padding:12px; background:#f8fafc; border-left:3px solid #2271b1; }
            .evapp-var-disabled { color:#666; padding:12px; background:#fff7ed; border-left:3px solid #f97316; margin-bottom:16px; }
            .evapp-var-rule { border:1px solid #dcdcde; border-radius:8px; padding:16px; margin-bottom:16px; background:#fff; }
            .evapp-var-rule:hover { border-color:#2271b1; }
            .evapp-var-rule-header { display:flex; gap:8px; align-items:center; border-bottom:1px solid #eee; padding-bottom:8px; margin-bottom:12px; }
            .evapp-var-rule-title { font-weight:700; color:#2271b1; }
            .evapp-var-delete-rule { margin-left:auto; color:#b91c1c; cursor:pointer; font-weight:700; }
            .evapp-var-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px; }
            .evapp-var-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-bottom:12px; }
            .evapp-var-field { display:flex; flex-direction:column; }
            .evapp-var-field label { font-weight:600; margin-bottom:4px; }
            .evapp-var-field input[type="text"],
            .evapp-var-field input[type="url"],
            .evapp-var-field select,
            .evapp-var-field textarea { width:100%; max-width:100%; }
            .evapp-var-field input[type="color"] { width:68px; height:32px; padding:1px; }
            .evapp-var-help { color:#666; font-size:12px; margin-top:4px; font-style:italic; }
            .evapp-var-section { margin:14px 0 10px; padding:9px 10px; background:#f6f7f7; border-left:3px solid #72aee6; font-weight:700; }
            .evapp-var-condition { background:#fafafa; border:1px solid #e5e7eb; border-radius:8px; padding:12px; margin-bottom:10px; }
            .evapp-var-condition-header { display:flex; gap:8px; align-items:center; margin-bottom:8px; border-bottom:1px dashed #ddd; padding-bottom:6px; }
            .evapp-var-condition-title { font-weight:600; }
            .evapp-var-delete-condition { margin-left:auto; color:#b91c1c; cursor:pointer; font-weight:700; }
            .evapp-var-add-rule { margin-top:8px; }
            .evapp-var-muted { color:#666; }
            .evapp-var-wrapper-disabled { opacity:.55; pointer-events:none; }
            @media (max-width:1100px) {
                .evapp-var-grid-2, .evapp-var-grid-3 { grid-template-columns:1fr; }
            }
        </style>

        <div class="evapp-var-wrap">
            <div class="evapp-var-intro">
                <strong>Qué hace:</strong> permite crear variaciones del ticket dentro del mismo evento. La primera regla que coincida puede definir una plantilla de correo específica, una clase/branding distinto para Google Wallet y assets/colores distintos para Apple Wallet.<br>
                <strong>Ejemplo:</strong> si <code>Localidad</code> es <code>VIP</code>, usar plantilla VIP, clase Google Wallet VIP y diseño Apple VIP.
            </div>

            <?php if (!$enabled): ?>
                <div class="evapp-var-disabled">
                    Este sistema está desactivado. Para usarlo, marca <strong>“Activar variantes de ticket por reglas”</strong> en el metabox lateral <strong>Funciones Extra del Ticket</strong> y guarda el evento.
                </div>
            <?php endif; ?>

            <div id="evapp_ticket_variants_rules" class="<?php echo $enabled ? '' : 'evapp-var-wrapper-disabled'; ?>">
                <?php if (empty($rules)): ?>
                    <p class="evapp-var-no-rules evapp-var-muted">No hay variantes configuradas. Agrega una regla para empezar.</p>
                <?php else: ?>
                    <?php foreach ($rules as $index => $rule): ?>
                        <?php eventosapp_ticket_variants_render_rule($index, $rule, $fields, $templates, $operators); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <button type="button" class="button button-secondary evapp-var-add-rule" id="evapp_ticket_variants_add_rule">➕ Agregar variante</button>
        </div>

        <script>
        (function($){
            var ruleIndex = <?php echo (int) count($rules); ?>;
            var fields = <?php echo wp_json_encode($fields); ?>;
            var templates = <?php echo wp_json_encode($templates); ?>;
            var operators = <?php echo wp_json_encode($operators); ?>;

            function escHtml(str){
                return String(str === null || typeof str === 'undefined' ? '' : str)
                    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                    .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
            }
            function fieldOptions(){
                var html = '<option value="">-- Seleccionar --</option>';
                $.each(fields, function(key, label){ html += '<option value="'+escHtml(key)+'">'+escHtml(label)+'</option>'; });
                return html;
            }
            function operatorOptions(){
                var html = '<option value="">-- Seleccionar --</option>';
                $.each(operators, function(key, label){ html += '<option value="'+escHtml(key)+'">'+escHtml(label)+'</option>'; });
                return html;
            }
            function templateOptions(){
                var html = '<option value="">-- Sin override / usar plantilla por defecto --</option>';
                $.each(templates, function(file, label){ html += '<option value="'+escHtml(file)+'">'+escHtml(label)+'</option>'; });
                return html;
            }
            function colorField(name, label, value){
                value = value || '';
                return '<div class="evapp-var-field"><label>'+escHtml(label)+'</label><input type="text" name="'+name+'" value="'+escHtml(value)+'" placeholder="#3782C4"><span class="evapp-var-help">Formato: #RRGGBB.</span></div>';
            }
            function conditionHtml(ruleIdx, conditionIdx){
                return ''+
                '<div class="evapp-var-condition" data-condition-index="'+conditionIdx+'">'+
                    '<div class="evapp-var-condition-header">'+
                        '<span class="evapp-var-condition-title">Criterio #<span class="evapp-var-condition-num">'+(conditionIdx+1)+'</span></span>'+
                        '<span class="evapp-var-delete-condition" title="Eliminar criterio">✖</span>'+
                    '</div>'+
                    '<div class="evapp-var-grid-3">'+
                        '<div class="evapp-var-field"><label>Campo a evaluar</label><select name="evapp_ticket_variants_rules['+ruleIdx+'][conditions]['+conditionIdx+'][field]">'+fieldOptions()+'</select></div>'+
                        '<div class="evapp-var-field"><label>Operador</label><select name="evapp_ticket_variants_rules['+ruleIdx+'][conditions]['+conditionIdx+'][operator]">'+operatorOptions()+'</select></div>'+
                        '<div class="evapp-var-field"><label>Valor a comparar</label><input type="text" name="evapp_ticket_variants_rules['+ruleIdx+'][conditions]['+conditionIdx+'][value]" placeholder="Ej: VIP, General, Barranquilla"><span class="evapp-var-help">No distingue mayúsculas/minúsculas ni tildes. Para varios valores usa: VIP|Platino.</span></div>'+
                    '</div>'+
                '</div>';
            }
            function ruleHtml(index){
                var base = 'evapp_ticket_variants_rules['+index+']';
                return ''+
                '<div class="evapp-var-rule" data-index="'+index+'">'+
                    '<div class="evapp-var-rule-header"><span class="evapp-var-rule-title">Variante #<span class="evapp-var-rule-num">'+(index+1)+'</span></span><span class="evapp-var-delete-rule">❌ Eliminar</span></div>'+
                    '<div class="evapp-var-grid-2">'+
                        '<div class="evapp-var-field"><label>Nombre de la variante</label><input type="text" name="'+base+'[name]" placeholder="Ej: VIP, General, Staff"><span class="evapp-var-help">Se guarda en el ticket para diagnóstico.</span></div>'+
                        '<div class="evapp-var-field"><label>Lógica de coincidencia</label><select name="'+base+'[match]"><option value="all">Cumplir TODOS los criterios</option><option value="any">Cumplir AL MENOS UNO</option></select><span class="evapp-var-help">TODOS = AND. AL MENOS UNO = OR.</span></div>'+
                    '</div>'+
                    '<div class="evapp-var-conditions">'+conditionHtml(index,0)+'</div>'+
                    '<button type="button" class="button button-secondary evapp-var-add-condition">＋ Agregar criterio</button>'+
                    '<div class="evapp-var-section">Correo</div>'+
                    '<div class="evapp-var-grid-2">'+
                        '<div class="evapp-var-field"><label>Plantilla de correo para esta variante</label><select name="'+base+'[email_template]">'+templateOptions()+'</select><span class="evapp-var-help">Lee las plantillas desde <code>includes/templates/email_tickets/</code>. Si se deja vacío, se usa la plantilla normal del evento.</span></div>'+
                        '<div class="evapp-var-field"><label>Notas internas</label><textarea name="'+base+'[internal_notes]" rows="2" placeholder="Notas de uso interno"></textarea></div>'+
                        '<div class="evapp-var-field"><label>Cabezote URL del correo</label><input type="url" name="'+base+'[email_header_image_url]" placeholder="https://..."><span class="evapp-var-help">Imagen superior específica para el correo de esta variante.</span></div>'+
                    '</div>'+
                    '<div class="evapp-var-grid-3">'+
                        colorField(base+'[email_heading_color]', 'Color encabezado principal', '')+
                        colorField(base+'[email_subheading_color]', 'Color encabezado secundario', '')+
                        colorField(base+'[email_text_color]', 'Color texto de encabezados', '')+
                    '</div>'+
                    '<div class="evapp-var-section">Google Wallet / Android</div>'+
                    '<div class="evapp-var-grid-2">'+
                        '<div class="evapp-var-field"><label>Class ID de Google Wallet</label><input type="text" name="'+base+'[google_wallet_class_id]" placeholder="3388...event_vip"><span class="evapp-var-help">Puede ser una clase distinta del mismo evento. Si no existe, el sistema intentará crearla en Google Wallet antes de generar el objeto. Si no incluye issuer ID, se antepone automáticamente.</span></div>'+
                        '<div class="evapp-var-field"><label>Nombre del evento en Google Wallet</label><input type="text" name="'+base+'[google_wallet_event_name]" placeholder="Opcional"></div>'+
                        '<div class="evapp-var-field"><label>Logo URL Google Wallet</label><input type="url" name="'+base+'[google_wallet_logo_url]" placeholder="https://..."></div>'+
                        '<div class="evapp-var-field"><label>Hero image URL Google Wallet</label><input type="url" name="'+base+'[google_wallet_hero_url]" placeholder="https://..."></div>'+
                    '</div>'+
                    '<div class="evapp-var-grid-3">'+colorField(base+'[google_wallet_hex_color]', 'Color Google Wallet', '')+'</div>'+
                    '<div class="evapp-var-section">Apple Wallet / iPhone</div>'+
                    '<div class="evapp-var-grid-2">'+
                        '<div class="evapp-var-field"><label>Nombre del evento en Apple Wallet</label><input type="text" name="'+base+'[apple_event_name]" placeholder="Opcional"></div>'+
                        '<div class="evapp-var-field"><label>Icon URL Apple</label><input type="url" name="'+base+'[apple_icon_url]" placeholder="https://..."></div>'+
                        '<div class="evapp-var-field"><label>Logo URL Apple</label><input type="url" name="'+base+'[apple_logo_url]" placeholder="https://..."></div>'+
                        '<div class="evapp-var-field"><label>Strip URL Apple</label><input type="url" name="'+base+'[apple_strip_url]" placeholder="https://..."></div>'+
                    '</div>'+
                    '<div class="evapp-var-grid-3">'+
                        colorField(base+'[apple_hex_bg]', 'Color fondo Apple', '')+
                        colorField(base+'[apple_hex_fg]', 'Color texto Apple', '')+
                        colorField(base+'[apple_hex_label]', 'Color etiquetas Apple', '')+
                    '</div>'+
                '</div>';
            }
            function renumberRules(){
                $('#evapp_ticket_variants_rules .evapp-var-rule').each(function(i){ $(this).find('.evapp-var-rule-num').text(i+1); });
            }
            function renumberConditions($rule){
                $rule.find('.evapp-var-condition').each(function(i){ $(this).find('.evapp-var-condition-num').text(i+1); });
            }
            function reindexConditions($rule){
                var ruleIdx = $rule.data('index');
                $rule.find('.evapp-var-condition').each(function(conditionIdx){
                    $(this).attr('data-condition-index', conditionIdx);
                    $(this).find('select,input').each(function(){
                        var name = $(this).attr('name');
                        if (!name) return;
                        name = name.replace(/evapp_ticket_variants_rules\[\d+\]\[conditions\]\[\d+\]/, 'evapp_ticket_variants_rules['+ruleIdx+'][conditions]['+conditionIdx+']');
                        $(this).attr('name', name);
                    });
                });
                renumberConditions($rule);
            }
            function noRulesMessage(){
                var $wrap = $('#evapp_ticket_variants_rules');
                if ($wrap.find('.evapp-var-rule').length === 0) {
                    if (!$wrap.find('.evapp-var-no-rules').length) $wrap.append('<p class="evapp-var-no-rules evapp-var-muted">No hay variantes configuradas. Agrega una regla para empezar.</p>');
                } else {
                    $wrap.find('.evapp-var-no-rules').remove();
                }
            }

            $('#evapp_ticket_variants_add_rule').on('click', function(){
                $('#evapp_ticket_variants_rules .evapp-var-no-rules').remove();
                $('#evapp_ticket_variants_rules').append(ruleHtml(ruleIndex));
                ruleIndex++;
                renumberRules();
            });
            $(document).on('click', '.evapp-var-delete-rule', function(){
                if (!confirm('¿Eliminar esta variante?')) return;
                $(this).closest('.evapp-var-rule').remove();
                renumberRules();
                noRulesMessage();
            });
            $(document).on('click', '.evapp-var-add-condition', function(){
                var $rule = $(this).closest('.evapp-var-rule');
                var ruleIdx = $rule.data('index');
                var nextIdx = $rule.find('.evapp-var-condition').length;
                $rule.find('.evapp-var-conditions').append(conditionHtml(ruleIdx, nextIdx));
                renumberConditions($rule);
            });
            $(document).on('click', '.evapp-var-delete-condition', function(){
                var $rule = $(this).closest('.evapp-var-rule');
                if ($rule.find('.evapp-var-condition').length <= 1) {
                    alert('Cada variante debe tener al menos un criterio.');
                    return;
                }
                $(this).closest('.evapp-var-condition').remove();
                reindexConditions($rule);
            });
            noRulesMessage();
        })(jQuery);
        </script>
        <?php
    }
}

if (!function_exists('eventosapp_ticket_variants_render_rule')) {
    function eventosapp_ticket_variants_render_rule($index, $rule, $fields, $templates, $operators) {
        $rule = eventosapp_ticket_variants_sanitize_rule($rule);
        if (!$rule) return;

        $base = 'evapp_ticket_variants_rules[' . (int) $index . ']';
        $conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];
        ?>
        <div class="evapp-var-rule" data-index="<?php echo esc_attr((int) $index); ?>">
            <div class="evapp-var-rule-header">
                <span class="evapp-var-rule-title">Variante #<span class="evapp-var-rule-num"><?php echo esc_html((int) $index + 1); ?></span></span>
                <span class="evapp-var-delete-rule">❌ Eliminar</span>
            </div>

            <div class="evapp-var-grid-2">
                <div class="evapp-var-field">
                    <label>Nombre de la variante</label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[name]" value="<?php echo esc_attr($rule['name']); ?>" placeholder="Ej: VIP, General, Staff">
                    <span class="evapp-var-help">Se guarda en el ticket para diagnóstico.</span>
                </div>
                <div class="evapp-var-field">
                    <label>Lógica de coincidencia</label>
                    <select name="<?php echo esc_attr($base); ?>[match]">
                        <option value="all" <?php selected($rule['match'], 'all'); ?>>Cumplir TODOS los criterios</option>
                        <option value="any" <?php selected($rule['match'], 'any'); ?>>Cumplir AL MENOS UNO</option>
                    </select>
                    <span class="evapp-var-help">TODOS = AND. AL MENOS UNO = OR.</span>
                </div>
            </div>

            <div class="evapp-var-conditions">
                <?php foreach ($conditions as $condition_index => $condition): ?>
                    <div class="evapp-var-condition" data-condition-index="<?php echo esc_attr((int) $condition_index); ?>">
                        <div class="evapp-var-condition-header">
                            <span class="evapp-var-condition-title">Criterio #<span class="evapp-var-condition-num"><?php echo esc_html((int) $condition_index + 1); ?></span></span>
                            <span class="evapp-var-delete-condition" title="Eliminar criterio">✖</span>
                        </div>
                        <div class="evapp-var-grid-3">
                            <div class="evapp-var-field">
                                <label>Campo a evaluar</label>
                                <select name="<?php echo esc_attr($base); ?>[conditions][<?php echo esc_attr((int) $condition_index); ?>][field]">
                                    <option value="">-- Seleccionar --</option>
                                    <?php foreach ($fields as $field_key => $field_label): ?>
                                        <option value="<?php echo esc_attr($field_key); ?>" <?php selected($condition['field'] ?? '', $field_key); ?>><?php echo esc_html($field_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="evapp-var-field">
                                <label>Operador</label>
                                <select name="<?php echo esc_attr($base); ?>[conditions][<?php echo esc_attr((int) $condition_index); ?>][operator]">
                                    <option value="">-- Seleccionar --</option>
                                    <?php foreach ($operators as $operator_key => $operator_label): ?>
                                        <option value="<?php echo esc_attr($operator_key); ?>" <?php selected($condition['operator'] ?? '', $operator_key); ?>><?php echo esc_html($operator_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="evapp-var-field">
                                <label>Valor a comparar</label>
                                <input type="text" name="<?php echo esc_attr($base); ?>[conditions][<?php echo esc_attr((int) $condition_index); ?>][value]" value="<?php echo esc_attr($condition['value'] ?? ''); ?>" placeholder="Ej: VIP, General, Barranquilla">
                                <span class="evapp-var-help">Para varios valores usa: VIP|Platino.</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button button-secondary evapp-var-add-condition">＋ Agregar criterio</button>

            <div class="evapp-var-section">Correo</div>
            <div class="evapp-var-grid-2">
                <div class="evapp-var-field">
                    <label>Plantilla de correo para esta variante</label>
                    <select name="<?php echo esc_attr($base); ?>[email_template]">
                        <option value="">-- Sin override / usar plantilla por defecto --</option>
                        <?php foreach ($templates as $template_file => $template_label): ?>
                            <option value="<?php echo esc_attr($template_file); ?>" <?php selected($rule['email_template'] ?? '', $template_file); ?>><?php echo esc_html($template_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="evapp-var-help">Lee las plantillas desde <code>includes/templates/email_tickets/</code>. Si se deja vacío, se usa la plantilla normal del evento.</span>
                </div>
                <div class="evapp-var-field">
                    <label>Notas internas</label>
                    <textarea name="<?php echo esc_attr($base); ?>[internal_notes]" rows="2" placeholder="Notas de uso interno"><?php echo esc_textarea($rule['internal_notes'] ?? ''); ?></textarea>
                </div>
                <div class="evapp-var-field">
                    <label>Cabezote URL del correo</label>
                    <input type="url" name="<?php echo esc_attr($base); ?>[email_header_image_url]" value="<?php echo esc_attr($rule['email_header_image_url'] ?? ''); ?>" placeholder="https://...">
                    <span class="evapp-var-help">Imagen superior específica para la plantilla de correo de esta variante.</span>
                </div>
            </div>
            <div class="evapp-var-grid-3">
                <div class="evapp-var-field">
                    <label>Color encabezado principal</label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[email_heading_color]" value="<?php echo esc_attr($rule['email_heading_color'] ?? ''); ?>" placeholder="#111827">
                    <span class="evapp-var-help">Formato: #RRGGBB.</span>
                </div>
                <div class="evapp-var-field">
                    <label>Color encabezado secundario</label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[email_subheading_color]" value="<?php echo esc_attr($rule['email_subheading_color'] ?? ''); ?>" placeholder="#334155">
                    <span class="evapp-var-help">Formato: #RRGGBB.</span>
                </div>
                <div class="evapp-var-field">
                    <label>Color texto de encabezados</label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[email_text_color]" value="<?php echo esc_attr($rule['email_text_color'] ?? ''); ?>" placeholder="#475569">
                    <span class="evapp-var-help">Formato: #RRGGBB.</span>
                </div>
            </div>

            <div class="evapp-var-section">Google Wallet / Android</div>
            <div class="evapp-var-grid-2">
                <div class="evapp-var-field">
                    <label>Class ID de Google Wallet</label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[google_wallet_class_id]" value="<?php echo esc_attr($rule['google_wallet_class_id'] ?? ''); ?>" placeholder="3388...event_vip">
                    <span class="evapp-var-help">Puede ser una clase distinta del mismo evento. Si no existe, el sistema intentará crearla en Google Wallet antes de generar el objeto.</span>
                </div>
                <div class="evapp-var-field">
                    <label>Nombre del evento en Google Wallet</label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[google_wallet_event_name]" value="<?php echo esc_attr($rule['google_wallet_event_name'] ?? ''); ?>" placeholder="Opcional">
                </div>
                <div class="evapp-var-field">
                    <label>Logo URL Google Wallet</label>
                    <input type="url" name="<?php echo esc_attr($base); ?>[google_wallet_logo_url]" value="<?php echo esc_attr($rule['google_wallet_logo_url'] ?? ''); ?>" placeholder="https://...">
                </div>
                <div class="evapp-var-field">
                    <label>Hero image URL Google Wallet</label>
                    <input type="url" name="<?php echo esc_attr($base); ?>[google_wallet_hero_url]" value="<?php echo esc_attr($rule['google_wallet_hero_url'] ?? ''); ?>" placeholder="https://...">
                </div>
            </div>
            <div class="evapp-var-grid-3">
                <div class="evapp-var-field">
                    <label>Color Google Wallet</label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[google_wallet_hex_color]" value="<?php echo esc_attr($rule['google_wallet_hex_color'] ?? ''); ?>" placeholder="#3782C4">
                    <span class="evapp-var-help">Formato: #RRGGBB.</span>
                </div>
            </div>

            <div class="evapp-var-section">Apple Wallet / iPhone</div>
            <div class="evapp-var-grid-2">
                <div class="evapp-var-field">
                    <label>Nombre del evento en Apple Wallet</label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[apple_event_name]" value="<?php echo esc_attr($rule['apple_event_name'] ?? ''); ?>" placeholder="Opcional">
                </div>
                <div class="evapp-var-field">
                    <label>Icon URL Apple</label>
                    <input type="url" name="<?php echo esc_attr($base); ?>[apple_icon_url]" value="<?php echo esc_attr($rule['apple_icon_url'] ?? ''); ?>" placeholder="https://...">
                </div>
                <div class="evapp-var-field">
                    <label>Logo URL Apple</label>
                    <input type="url" name="<?php echo esc_attr($base); ?>[apple_logo_url]" value="<?php echo esc_attr($rule['apple_logo_url'] ?? ''); ?>" placeholder="https://...">
                </div>
                <div class="evapp-var-field">
                    <label>Strip URL Apple</label>
                    <input type="url" name="<?php echo esc_attr($base); ?>[apple_strip_url]" value="<?php echo esc_attr($rule['apple_strip_url'] ?? ''); ?>" placeholder="https://...">
                </div>
            </div>
            <div class="evapp-var-grid-3">
                <div class="evapp-var-field">
                    <label>Color fondo Apple</label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[apple_hex_bg]" value="<?php echo esc_attr($rule['apple_hex_bg'] ?? ''); ?>" placeholder="#3782C4">
                </div>
                <div class="evapp-var-field">
                    <label>Color texto Apple</label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[apple_hex_fg]" value="<?php echo esc_attr($rule['apple_hex_fg'] ?? ''); ?>" placeholder="#FFFFFF">
                </div>
                <div class="evapp-var-field">
                    <label>Color etiquetas Apple</label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[apple_hex_label]" value="<?php echo esc_attr($rule['apple_hex_label'] ?? ''); ?>" placeholder="#FFFFFF">
                </div>
            </div>
        </div>
        <?php
    }
}

add_action('save_post_eventosapp_event', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (!isset($_POST['eventosapp_ticket_variants_nonce'])) return;
    if (!wp_verify_nonce($_POST['eventosapp_ticket_variants_nonce'], 'eventosapp_ticket_variants_save')) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $rules_raw = isset($_POST['evapp_ticket_variants_rules']) && is_array($_POST['evapp_ticket_variants_rules'])
        ? wp_unslash($_POST['evapp_ticket_variants_rules'])
        : [];

    $rules = [];
    foreach ($rules_raw as $rule) {
        $clean = eventosapp_ticket_variants_sanitize_rule($rule);
        if ($clean) $rules[] = $clean;
    }

    $previous_config = get_post_meta($post_id, '_eventosapp_ticket_variants_config', true);

    $config = [
        'rules'      => $rules,
        'updated_at' => current_time('mysql'),
    ];

    update_post_meta($post_id, '_eventosapp_ticket_variants_config', $config);

    // Si cambian variantes, clases, assets o plantillas, se invalida la firma del batch.
    // Así una actualización del evento o una refrescada forzada vuelve a aplicar los assets correctos.
    if (wp_json_encode($previous_config) !== wp_json_encode($config)) {
        delete_post_meta($post_id, '_eventosapp_wallet_signature');
        delete_post_meta($post_id, '_eventosapp_wallet_last_synced_class');
        update_post_meta($post_id, '_eventosapp_ticket_variants_last_config_change', current_time('mysql'));
        error_log('[EventosApp] Variantes de ticket actualizadas evento=' . (int) $post_id . ' - firma wallet invalidada para regenerar assets.');
    }
}, 27);
