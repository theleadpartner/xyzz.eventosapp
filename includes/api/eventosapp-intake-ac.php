<?php
// includes/api/eventosapp-intake-ac.php
if (!defined('ABSPATH')) exit;

/**
 * Asegurar secreto del webhook aunque este archivo cargue antes que otros.
 * Puedes definirlo en wp-config.php como EVENTOSAPP_INTAKE_KEY;
 * si no existe, se generará y persistirá en la opción _eventosapp_intake_key.
 */
if (!defined('EVENTOSAPP_INTAKE_KEY')) {
  if (!function_exists('evapp_random_token')) {
    function evapp_random_token($bytes = 48){
      try { $b = random_bytes($bytes); } catch (\Throwable $e) { $b = openssl_random_pseudo_bytes($bytes); }
      return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    }
  }
  $opt_key = '_eventosapp_intake_key';
  $k = get_option($opt_key, '');
  if (!$k) { $k = evapp_random_token(48); update_option($opt_key, $k, false); }
  define('EVENTOSAPP_INTAKE_KEY', $k);
}


/** ---------------------------------------------------------------------------
 * Autor por defecto de los tickets creados vía webhook
 * ------------------------------------------------------------------------- */
if ( ! function_exists('evapp_webhook_author_id') ) {
  function evapp_webhook_author_id() : int {
    // Permite sobreescribir por constante (opcional en wp-config.php)
    if (defined('EVENTOSAPP_WEBHOOK_AUTHOR_ID')) {
      return (int) EVENTOSAPP_WEBHOOK_AUTHOR_ID;
    }
    // Por defecto usamos el usuario solicitado
    $fallback = 17; // emisor1
    // Intento de resolución defensiva, por si cambia el ID
    if (function_exists('get_user_by')) {
      if ($u = get_user_by('login', 'emisor1')) return (int)$u->ID;
      if ($u = get_user_by('email', 'emisor1@eventosapp.com')) return (int)$u->ID;
      if ($u = get_user_by('id', 17)) return (int)$u->ID;
    }
    return $fallback;
  }
}


/** ---------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */

/**
 * Obtiene un valor de un arreglo permitiendo rutas tipo:
 *  - "contact.email"
 *  - "contact[email]"
 *  - mezcla de ambas ("contact[custom].email")
 */
if (!function_exists('evapp_arr_get')) {
  function evapp_arr_get($arr, $path) {
    if (!is_array($arr)) return null;
    $path = (string)$path;
    if ($path === '') return null;
    // separa por puntos y/o corchetes
    $segs = preg_split('/[.\[\]]+/', trim($path, "[] \t\n\r\0\x0B"));
    foreach ($segs as $s) {
      if ($s === '') continue;
      if (!is_array($arr) || !array_key_exists($s, $arr)) return null;
      $arr = $arr[$s];
    }
    return $arr;
  }
}

/** Devuelve el primer valor no vacío encontrado entre varias claves/rutas */
if (!function_exists('evapp_pick_from')) {
  function evapp_pick_from(array $data, array $keys, $default = '') {
    foreach ($keys as $k) {
      // 1) clave literal
      if (isset($data[$k]) && $data[$k] !== '') return $data[$k];
      // 2) ruta tipo dot/brackets
      $v = evapp_arr_get($data, $k);
      if ($v !== null && $v !== '') return $v;
    }
    return $default;
  }
}

/** Busca un extra $k en múltiples ubicaciones convencionales */
if (!function_exists('evapp_pick_extra')) {
  function evapp_pick_extra(array $data, string $k) {
    $candidates = [
      "eventosapp_extra.$k",
      "eventosapp_extra[$k]",
      "extra.$k",
      "extra[$k]",
      "extras.$k",
      "extras[$k]",
      "custom_fields.$k",
      "custom_fields[$k]",
      "contact.custom_fields.$k",
      "contact[custom_fields][$k]",
      $k,
      "extra_$k",
    ];
    return evapp_pick_from($data, $candidates, '');
  }
}

/** Normaliza booleanos desde strings/números comunes */
if (!function_exists('evapp_boolish')) {
  function evapp_boolish($v) {
    if (is_bool($v)) return $v;
    if (is_numeric($v)) return (int)$v === 1;
    if (!is_string($v)) return false;
    $v = strtolower(trim($v));
    return in_array($v, ['1','true','yes','si','sí','on','y'], true);
  }
}


/**
 * Sanitiza un estado corto para respuestas públicas del webhook.
 * Mantiene la trazabilidad operativa sin exponer URLs, JWT, reglas completas ni configuración interna.
 */
if (!function_exists('evapp_webhook_public_status')) {
  function evapp_webhook_public_status($value) {
    if (is_bool($value)) return $value;
    if (is_int($value) || is_float($value)) return $value;
    if (is_null($value)) return null;
    if (is_scalar($value)) {
      $status = sanitize_key((string) $value);
      return $status !== '' ? $status : sanitize_text_field((string) $value);
    }
    return 'processed';
  }
}

/**
 * Resume el resultado de QR sin filtrar rutas ni datos internos del manager.
 */
if (!function_exists('evapp_webhook_public_qr_summary')) {
  function evapp_webhook_public_qr_summary($qr_result) {
    if (is_array($qr_result)) {
      $allowed = ['generated', 'skipped', 'failed', 'invalidated', 'regenerated_missing_file'];
      $summary = [];
      foreach ($allowed as $key) {
        if (array_key_exists($key, $qr_result)) {
          $summary[$key] = is_numeric($qr_result[$key]) ? (int) $qr_result[$key] : evapp_webhook_public_status($qr_result[$key]);
        }
      }
      return !empty($summary) ? $summary : ['status' => 'processed'];
    }
    return evapp_webhook_public_status($qr_result);
  }
}

/**
 * Resume la sincronización de clases Google Wallet de variantes sin exponer class_id ni datos de branding.
 */
if (!function_exists('evapp_webhook_public_wallet_classes_summary')) {
  function evapp_webhook_public_wallet_classes_summary($sync_result) {
    if (!is_array($sync_result)) return null;

    $summary = [
      'ok'          => !empty($sync_result['ok']),
      'reason'      => isset($sync_result['reason']) ? sanitize_key((string) $sync_result['reason']) : '',
      'total_rules' => isset($sync_result['total_rules']) ? (int) $sync_result['total_rules'] : 0,
      'synced'      => isset($sync_result['synced']) ? (int) $sync_result['synced'] : 0,
      'failed'      => isset($sync_result['failed']) ? (int) $sync_result['failed'] : 0,
      'skipped'     => isset($sync_result['skipped']) ? (int) $sync_result['skipped'] : 0,
    ];

    return $summary;
  }
}

/**
 * Resume la evaluación de variante para la respuesta pública del webhook.
 * No devuelve reglas completas, valores comparados, URLs ni Class IDs.
 */
if (!function_exists('evapp_webhook_public_variant_summary')) {
  function evapp_webhook_public_variant_summary($asset_refresh_result = null, $ticket_id = 0) {
    $asset_refresh_result = is_array($asset_refresh_result) ? $asset_refresh_result : [];
    $variant_result = isset($asset_refresh_result['ticket_variant']) && is_array($asset_refresh_result['ticket_variant'])
      ? $asset_refresh_result['ticket_variant']
      : [];

    $debug = isset($variant_result['debug']) && is_array($variant_result['debug']) ? $variant_result['debug'] : [];

    $ticket_id = absint($ticket_id);
    $variant_key = isset($variant_result['variant_key']) ? (string) $variant_result['variant_key'] : '';
    $variant_name = isset($variant_result['variant_name']) ? (string) $variant_result['variant_name'] : '';

    if ($variant_key === '' && isset($asset_refresh_result['variant_key'])) {
      $variant_key = (string) $asset_refresh_result['variant_key'];
    }
    if ($variant_name === '' && isset($asset_refresh_result['variant_name'])) {
      $variant_name = (string) $asset_refresh_result['variant_name'];
    }
    if ($ticket_id && $variant_key === '') {
      $variant_key = (string) get_post_meta($ticket_id, '_eventosapp_ticket_variant_key', true);
    }
    if ($ticket_id && $variant_name === '') {
      $variant_name = (string) get_post_meta($ticket_id, '_eventosapp_ticket_variant_name', true);
    }

    $matched_index = null;
    if (isset($variant_result['matched_index'])) {
      $matched_index = is_numeric($variant_result['matched_index']) ? (int) $variant_result['matched_index'] : null;
    } elseif (isset($debug['matched_index']) && $debug['matched_index'] !== null) {
      $matched_index = is_numeric($debug['matched_index']) ? (int) $debug['matched_index'] : null;
    }

    return [
      'evaluated'     => !empty($variant_result) || $variant_key !== '' || $variant_name !== '',
      'applied'       => !empty($variant_result['applied']) || $variant_key !== '',
      'variant_key'   => sanitize_key($variant_key),
      'variant_name'  => sanitize_text_field($variant_name),
      'matched_index' => $matched_index,
      'rules_count'   => isset($debug['rules_count']) ? (int) $debug['rules_count'] : null,
      'reason'        => isset($variant_result['reason']) ? sanitize_key((string) $variant_result['reason']) : '',
    ];
  }
}

/**
 * Resume la evaluación de condicionales del webhook para respuesta pública.
 * No devuelve campos evaluados, valores esperados/reales, reglas completas ni plantillas completas.
 */
if (!function_exists('evapp_webhook_public_conditional_summary')) {
  function evapp_webhook_public_conditional_summary($conditional_result = null) {
    $conditional_result = is_array($conditional_result) ? $conditional_result : [];
    $debug = isset($conditional_result['debug']) && is_array($conditional_result['debug']) ? $conditional_result['debug'] : [];
    $matched_rule = isset($conditional_result['matched_rule']) && is_array($conditional_result['matched_rule']) ? $conditional_result['matched_rule'] : [];

    $matched_index = null;
    if (isset($debug['matched_index']) && $debug['matched_index'] !== null) {
      $matched_index = is_numeric($debug['matched_index']) ? (int) $debug['matched_index'] : null;
    }

    return [
      'evaluated'     => !empty($conditional_result),
      'enabled'       => isset($debug['enabled']) ? (bool) $debug['enabled'] : null,
      'matched'       => !empty($matched_rule),
      'matched_index' => $matched_index,
      'rules_count'   => isset($debug['rules_count']) ? (int) $debug['rules_count'] : null,
      'send_email'    => array_key_exists('send_email', $conditional_result) ? (bool) $conditional_result['send_email'] : null,
      'action'        => isset($matched_rule['action']) ? sanitize_key((string) $matched_rule['action']) : '',
    ];
  }
}

/**
 * Convierte el resultado interno de anexos en una salida segura para el consumidor del webhook.
 * La versión completa se conserva en post_meta y error_log para depuración interna.
 */
if (!function_exists('evapp_webhook_public_asset_refresh_summary')) {
  function evapp_webhook_public_asset_refresh_summary($asset_refresh_result = null, $ticket_id = 0) {
    if (!is_array($asset_refresh_result)) return null;

    $errors = isset($asset_refresh_result['errors']) && is_array($asset_refresh_result['errors']) ? $asset_refresh_result['errors'] : [];

    $summary = [
      'context'                    => isset($asset_refresh_result['context']) ? sanitize_key((string) $asset_refresh_result['context']) : '',
      'variant'                    => evapp_webhook_public_variant_summary($asset_refresh_result, $ticket_id),
      'ticket_modalidad'           => isset($asset_refresh_result['ticket_modalidad']) ? sanitize_key((string) $asset_refresh_result['ticket_modalidad']) : '',
      'virtual_ticket'             => !empty($asset_refresh_result['virtual_ticket']),
      'google_wallet_classes_sync' => evapp_webhook_public_wallet_classes_summary($asset_refresh_result['google_wallet_classes_sync'] ?? null),
      'qr'                         => evapp_webhook_public_qr_summary($asset_refresh_result['qr'] ?? null),
      'pdf'                        => evapp_webhook_public_status($asset_refresh_result['pdf'] ?? null),
      'ics'                        => evapp_webhook_public_status($asset_refresh_result['ics'] ?? null),
      'wallet_android'             => evapp_webhook_public_status($asset_refresh_result['wallet_android'] ?? null),
      'wallet_apple'               => evapp_webhook_public_status($asset_refresh_result['wallet_apple'] ?? null),
      'search_index'               => evapp_webhook_public_status($asset_refresh_result['search_index'] ?? null),
      'errors_count'               => count($errors),
    ];

    // Los detalles de errores quedan en _eventosapp_webhook_assets_last_result y error_log.
    // La respuesta pública solo informa la cantidad para no exponer rutas, payloads o mensajes internos.
    return $summary;
  }
}

/**
 * Arma una respuesta pública del webhook con lista blanca de campos.
 * Evita exponer JWT/URLs de Wallet, class IDs, reglas completas, valores comparados y debug interno.
 */
if (!function_exists('evapp_webhook_build_public_response')) {
  function evapp_webhook_build_public_response(array $raw_response, $ticket_id = 0, $evento_id = 0, $context = 'webhook') {
    $ticket_id = absint($ticket_id ?: ($raw_response['ticket_id'] ?? 0));
    $evento_id = absint($evento_id);
    $context = sanitize_key((string) $context);

    $asset_refresh = isset($raw_response['asset_refresh']) && is_array($raw_response['asset_refresh']) ? $raw_response['asset_refresh'] : null;
    $variant_summary = evapp_webhook_public_variant_summary($asset_refresh, $ticket_id);

    $public = [
      'ok'                  => !empty($raw_response['ok']),
      'ticket_id'           => $ticket_id,
    ];

    if (array_key_exists('public_id', $raw_response)) {
      $public['public_id'] = sanitize_text_field((string) $raw_response['public_id']);
    }
    if (array_key_exists('created', $raw_response)) {
      $public['created'] = (bool) $raw_response['created'];
    }
    if (array_key_exists('updated', $raw_response)) {
      $public['updated'] = (bool) $raw_response['updated'];
    }
    if (array_key_exists('dedupe_by', $raw_response)) {
      $public['dedupe_by'] = sanitize_key((string) $raw_response['dedupe_by']);
    }
    if (array_key_exists('fields_changed', $raw_response) && is_array($raw_response['fields_changed'])) {
      $public['fields_changed'] = array_values(array_map('sanitize_key', $raw_response['fields_changed']));
    }
    if (array_key_exists('audit_logged', $raw_response)) {
      $public['audit_logged'] = (bool) $raw_response['audit_logged'];
    }

    $public['conditional_matched'] = !empty($raw_response['conditional_matched']);
    $public['variant_applied'] = !empty($variant_summary['applied']);
    $public['variant_key'] = $variant_summary['variant_key'];
    $public['variant_name'] = $variant_summary['variant_name'];
    $public['asset_refresh'] = evapp_webhook_public_asset_refresh_summary($asset_refresh, $ticket_id);

    if (array_key_exists('email_sent', $raw_response)) {
      $public['email_sent'] = (bool) $raw_response['email_sent'];
    }
    if (array_key_exists('email_msg', $raw_response)) {
      $public['email_msg'] = sanitize_text_field((string) $raw_response['email_msg']);
    }

    if (isset($raw_response['conditional_debug']) && is_array($raw_response['conditional_debug'])) {
      $public['conditional_debug'] = evapp_webhook_public_conditional_summary([
        'send_email'   => $raw_response['email_sent'] ?? null,
        'matched_rule' => !empty($raw_response['conditional_matched']) ? ['action' => 'matched'] : null,
        'debug'        => $raw_response['conditional_debug'],
      ]);
    }

    if (isset($raw_response['conditional_summary']) && is_array($raw_response['conditional_summary'])) {
      $public['conditional_summary'] = $raw_response['conditional_summary'];
    }

    return apply_filters('eventosapp_webhook_public_response', $public, $raw_response, $ticket_id, $evento_id, $context);
  }
}

/**
 * Sanitiza claves de campos adicionales provenientes del webhook.
 */
if (!function_exists('evapp_sanitize_extra_key')) {
  function evapp_sanitize_extra_key($key) : string {
    $key = sanitize_key((string)$key);
    return trim($key);
  }
}

/**
 * Sanitiza valores de extras manteniendo compatibilidad con listas/arrays.
 */
if (!function_exists('evapp_sanitize_extra_value')) {
  function evapp_sanitize_extra_value($value) {
    if (is_array($value)) {
      $clean = [];
      foreach ($value as $k => $v) {
        $clean_key = is_string($k) ? sanitize_key($k) : $k;
        $clean[$clean_key] = evapp_sanitize_extra_value($v);
      }
      return $clean;
    }

    if (is_bool($value)) {
      return $value ? '1' : '0';
    }

    if ($value === null) {
      return '';
    }

    return is_scalar($value) ? sanitize_text_field((string)$value) : '';
  }
}

/**
 * Integra un contenedor de extras al arreglo final.
 */
if (!function_exists('evapp_merge_extra_container')) {
  function evapp_merge_extra_container(array &$extras, $container) : void {
    if (!is_array($container)) return;

    foreach ($container as $key => $value) {
      $clean_key = evapp_sanitize_extra_key($key);
      if ($clean_key === '') continue;
      $extras[$clean_key] = evapp_sanitize_extra_value($value);
    }
  }
}

/**
 * Recopila extras desde:
 * - eventosapp_extra
 * - extra
 * - extras
 * - custom_fields
 * - contact.custom_fields
 * - claves planas extra_xxx
 * - campos adicionales configurados en el evento
 */
if (!function_exists('evapp_collect_payload_extras')) {
  function evapp_collect_payload_extras(array $data, int $evento_id) : array {
    $extras = [];

    foreach (['eventosapp_extra', 'extra', 'extras', 'custom_fields'] as $container_key) {
      if (isset($data[$container_key]) && is_array($data[$container_key])) {
        evapp_merge_extra_container($extras, $data[$container_key]);
      }
    }

    $contact_custom = evapp_arr_get($data, 'contact.custom_fields');
    if (is_array($contact_custom)) {
      evapp_merge_extra_container($extras, $contact_custom);
    }

    foreach ($data as $key => $value) {
      if (!is_string($key)) continue;
      if (strpos($key, 'extra_') !== 0) continue;

      $clean_key = evapp_sanitize_extra_key(substr($key, 6));
      if ($clean_key === '') continue;

      $extras[$clean_key] = evapp_sanitize_extra_value($value);
    }

    // Los extras definidos en el evento mandan sobre el valor crudo porque pueden tener normalización por tipo.
    if (function_exists('eventosapp_get_event_extra_fields')) {
      $schema = (array) eventosapp_get_event_extra_fields($evento_id);
      foreach ($schema as $fld) {
        if (empty($fld['key'])) continue;

        $k = evapp_sanitize_extra_key($fld['key']);
        if ($k === '') continue;

        $raw = evapp_pick_extra($data, $k);
        if (function_exists('eventosapp_normalize_extra_value')) {
          $val = eventosapp_normalize_extra_value($fld, $raw);
        } else {
          $val = evapp_sanitize_extra_value($raw);
        }

        $extras[$k] = evapp_sanitize_extra_value($val);
      }
    }

    return $extras;
  }
}

/**
 * Guarda extras en formato individual y también en arreglo.
 * El arreglo _eventosapp_ticket_extras es importante para condicionales/debug,
 * especialmente cuando llegan extras en el payload aunque no estén visibles todavía en otros metaboxes.
 */
if (!function_exists('evapp_save_ticket_extras_from_payload')) {
  function evapp_save_ticket_extras_from_payload(int $ticket_id, int $evento_id, array $data) : array {
    $extras = evapp_collect_payload_extras($data, $evento_id);

    foreach ($extras as $key => $value) {
      $clean_key = evapp_sanitize_extra_key($key);
      if ($clean_key === '') continue;
      update_post_meta($ticket_id, '_eventosapp_extra_' . $clean_key, $value);
    }

    update_post_meta($ticket_id, '_eventosapp_ticket_extras', $extras);
    update_post_meta($ticket_id, '_eventosapp_ticket_extras_last_sync', current_time('mysql'));

    return $extras;
  }
}

/** ---------------------------------------------------------------------------
 * Agrega una entrada al log de auditoría del ticket.
 * Guarda un historial de máximo 50 entradas en el meta _eventosapp_ticket_audit_log.
 *
 * @param int   $ticket_id
 * @param array $entry Datos de la entrada: trigger, changed_fields, before, after, etc.
 * ------------------------------------------------------------------------- */
if ( ! function_exists('eventosapp_add_ticket_audit_log') ) {
  function eventosapp_add_ticket_audit_log(int $ticket_id, array $entry) {
    $entry['timestamp']     = current_time('mysql');
    $entry['timestamp_gmt'] = current_time('mysql', 1);

    $log = get_post_meta($ticket_id, '_eventosapp_ticket_audit_log', true);
    if (!is_array($log)) $log = [];

    // Insertar al inicio (más reciente primero)
    array_unshift($log, $entry);

    // Máximo 50 entradas para no inflar la BD
    if (count($log) > 50) {
      $log = array_slice($log, 0, 50);
    }

    update_post_meta($ticket_id, '_eventosapp_ticket_audit_log', $log);
  }
}


/** ---------------------------------------------------------------------------
 * Helpers de deduplicación segura por evento para el webhook
 * ------------------------------------------------------------------------- */

/**
 * Construye una llave explícita para que un external_id solo sea reutilizable
 * dentro del mismo evento. Esto evita que una cédula usada como external_id
 * actualice tickets de eventos anteriores o futuros.
 */
if (!function_exists('evapp_build_webhook_scope_key')) {
  function evapp_build_webhook_scope_key(int $evento_id, $external_id) : string {
    $evento_id = absint($evento_id);
    $external_id = sanitize_text_field((string)$external_id);
    if (!$evento_id || $external_id === '') return '';
    return $evento_id . '|' . md5($external_id);
  }
}

/** Busca ticket por external_id, SIEMPRE limitado al evento recibido. */
if (!function_exists('evapp_find_ticket_by_external_id_evento')) {
  function evapp_find_ticket_by_external_id_evento($external_id, $evento_id) {
    $external_id = sanitize_text_field((string)$external_id);
    $evento_id   = absint($evento_id);
    if ($external_id === '' || !$evento_id) return false;

    $scope_key = evapp_build_webhook_scope_key($evento_id, $external_id);

    // 1) Preferir la llave compuesta nueva cuando exista.
    if ($scope_key !== '') {
      $q_scope = new WP_Query([
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'posts_per_page' => 1,
        'orderby'        => 'ID',
        'order'          => 'DESC',
        'no_found_rows'  => true,
        'meta_query'     => [
          'relation' => 'AND',
          [
            'key'     => '_eventosapp_ticket_evento_id',
            'value'   => $evento_id,
            'type'    => 'NUMERIC',
            'compare' => '=',
          ],
          [
            'key'     => '_eventosapp_external_scope_key',
            'value'   => $scope_key,
            'compare' => '=',
          ],
        ],
      ]);

      if (!empty($q_scope->posts)) {
        return (int)$q_scope->posts[0];
      }
    }

    // 2) Compatibilidad con tickets existentes: external_id + evento.
    $q = new WP_Query([
      'post_type'      => 'eventosapp_ticket',
      'post_status'    => 'any',
      'fields'         => 'ids',
      'posts_per_page' => 1,
      'orderby'        => 'ID',
      'order'          => 'DESC',
      'no_found_rows'  => true,
      'meta_query'     => [
        'relation' => 'AND',
        [
          'key'     => '_eventosapp_ticket_evento_id',
          'value'   => $evento_id,
          'type'    => 'NUMERIC',
          'compare' => '=',
        ],
        [
          'key'     => '_eventosapp_external_id',
          'value'   => $external_id,
          'compare' => '=',
        ],
      ],
    ]);

    return !empty($q->posts) ? (int)$q->posts[0] : false;
  }
}

/** Busca ticket por email dentro del mismo evento. */
if (!function_exists('evapp_find_ticket_by_email_evento')) {
  function evapp_find_ticket_by_email_evento($email, $evento_id) {
    $email     = sanitize_email((string)$email);
    $evento_id = absint($evento_id);
    if ($email === '' || !$evento_id) return false;

    $q = new WP_Query([
      'post_type'      => 'eventosapp_ticket',
      'post_status'    => 'any',
      'fields'         => 'ids',
      'posts_per_page' => 1,
      'orderby'        => 'ID',
      'order'          => 'DESC',
      'no_found_rows'  => true,
      'meta_query'     => [
        'relation' => 'AND',
        [
          'key'     => '_eventosapp_ticket_evento_id',
          'value'   => $evento_id,
          'type'    => 'NUMERIC',
          'compare' => '=',
        ],
        [
          'key'     => '_eventosapp_asistente_email',
          'value'   => $email,
          'compare' => '=',
        ],
      ],
    ]);

    return !empty($q->posts) ? (int)$q->posts[0] : false;
  }
}

/** Verifica defensivamente que un ticket encontrado sí pertenezca al evento del webhook. */
if (!function_exists('evapp_webhook_ticket_matches_event')) {
  function evapp_webhook_ticket_matches_event($ticket_id, $evento_id) : bool {
    $ticket_id = absint($ticket_id);
    $evento_id = absint($evento_id);
    if (!$ticket_id || !$evento_id) return false;
    if (get_post_type($ticket_id) !== 'eventosapp_ticket') return false;
    $ticket_event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    return $ticket_event_id === $evento_id;
  }
}

/**
 * Regenera/actualiza anexos en actualizaciones vía webhook del mismo evento.
 * No se usa para permitir cambios de evento; solo se ejecuta cuando el ticket
 * encontrado ya pertenece al evento recibido.
 */
if (!function_exists('evapp_webhook_event_flag_on')) {
  /**
   * Lee flags booleanos del evento de forma tolerante.
   * Mantiene compatibilidad con valores guardados como string, entero o booleano.
   */
  function evapp_webhook_event_flag_on($evento_id, $meta_key) : bool {
    $evento_id = absint($evento_id);
    if (!$evento_id || $meta_key === '') return false;
    $value = get_post_meta($evento_id, (string)$meta_key, true);
    return ($value === '1' || $value === 1 || $value === true || $value === 'true' || $value === 'on');
  }
}

if (!function_exists('evapp_webhook_qr_manager')) {
  /**
   * Devuelve una instancia única del QR Manager sin registrar hooks duplicados.
   */
  function evapp_webhook_qr_manager() {
    if (!class_exists('EventosApp_QR_Manager')) return null;

    if (method_exists('EventosApp_QR_Manager', 'get_instance')) {
      return EventosApp_QR_Manager::get_instance();
    }

    static $fallback_qr_manager = null;
    if (!$fallback_qr_manager instanceof EventosApp_QR_Manager) {
      $fallback_qr_manager = new EventosApp_QR_Manager();
    }
    return $fallback_qr_manager;
  }
}

if (!function_exists('evapp_webhook_apply_ticket_variant')) {
  /**
   * Aplica la variante de ticket y deja trazas legibles para WP_DEBUG.
   */
  function evapp_webhook_apply_ticket_variant($ticket_id, $evento_id, $context = 'webhook') : array {
    $ticket_id = absint($ticket_id);
    $evento_id = absint($evento_id);

    $result = [
      'applied' => false,
      'reason'  => 'helper_not_available',
    ];

    if (!$ticket_id || !$evento_id) {
      $result['reason'] = 'ticket_or_event_invalid';
      return $result;
    }

    if (!function_exists('eventosapp_ticket_variants_apply_to_ticket')) {
      update_post_meta($ticket_id, '_eventosapp_webhook_variant_result', $result);
      return $result;
    }

    try {
      $result = eventosapp_ticket_variants_apply_to_ticket($ticket_id, $evento_id, true);
      if (!is_array($result)) {
        $result = ['applied' => (bool)$result, 'reason' => 'non_array_result'];
      }
      update_post_meta($ticket_id, '_eventosapp_webhook_variant_result', $result);
      update_post_meta($ticket_id, '_eventosapp_webhook_variant_last_context', sanitize_key((string)$context));
      update_post_meta($ticket_id, '_eventosapp_webhook_variant_last_sync', current_time('mysql'));

      error_log('[EventosApp] Webhook variant '.$context.' ticket='.$ticket_id.' event='.$evento_id.' result='.wp_json_encode($result));
    } catch (\Throwable $e) {
      $result = [
        'applied' => false,
        'reason'  => 'exception',
        'error'   => $e->getMessage(),
      ];
      update_post_meta($ticket_id, '_eventosapp_webhook_variant_result', $result);
      error_log('[EventosApp] Webhook variant ERROR '.$context.' ticket='.$ticket_id.' event='.$evento_id.' error='.$e->getMessage());
    }

    return $result;
  }
}

/**
 * Regenera/actualiza anexos en tickets creados o actualizados vía webhook del mismo evento.
 *
 * Importante:
 * - Nunca mueve tickets entre eventos.
 * - Aplica variante antes de correo, PDF, ICS, Android Wallet y Apple Wallet.
 * - Sincroniza clases Android de variantes antes de crear/actualizar objetos Google Wallet.
 * - Genera solo QR faltantes o con archivo físico perdido, sin tocar QR válidos existentes.
 */
if (!function_exists('evapp_webhook_refresh_ticket_assets')) {
  function evapp_webhook_refresh_ticket_assets($ticket_id, $evento_id, $context = 'webhook_update') : array {
    $ticket_id = absint($ticket_id);
    $evento_id = absint($evento_id);
    $context   = sanitize_key((string)$context);
    if ($context === '') $context = 'webhook';

    $result = [
      'context'                    => $context,
      'ticket_variant'             => null,
      'variant_key'                => '',
      'variant_name'               => '',
      'variant_google_class_id'    => '',
      'ticket_modalidad'           => '',
      'virtual_ticket'             => false,
      'google_wallet_classes_sync' => null,
      'qr'                         => null,
      'pdf'                        => null,
      'ics'                        => null,
      'wallet_android'             => null,
      'wallet_android_url'         => '',
      'wallet_android_object_id'   => '',
      'wallet_android_class_id'    => '',
      'wallet_apple'               => null,
      'wallet_apple_url'           => '',
      'search_index'               => null,
      'errors'                     => [],
    ];

    if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
      $result['errors'][] = 'ticket_invalido';
      return $result;
    }

    if (!evapp_webhook_ticket_matches_event($ticket_id, $evento_id)) {
      $result['errors'][] = 'evento_no_coincide';
      error_log('[EventosApp] Webhook assets SKIPPED: ticket '.$ticket_id.' no pertenece al evento '.$evento_id.' context='.$context);
      return $result;
    }

    update_post_meta($ticket_id, '_eventosapp_webhook_assets_last_context', $context);

    // 1) Variante primero: todos los anexos posteriores deben leer estos metadatos.
    $variant_result = evapp_webhook_apply_ticket_variant($ticket_id, $evento_id, $context);
    $result['ticket_variant']          = $variant_result;
    $result['variant_key']             = (string) get_post_meta($ticket_id, '_eventosapp_ticket_variant_key', true);
    $result['variant_name']            = (string) get_post_meta($ticket_id, '_eventosapp_ticket_variant_name', true);
    $result['variant_google_class_id'] = (string) get_post_meta($ticket_id, '_eventosapp_wallet_variant_class_id', true);

    if (function_exists('eventosapp_ticket_sync_modalidad')) {
      eventosapp_ticket_sync_modalidad($ticket_id);
    }
    $is_virtual_ticket = function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id);
    $result['ticket_modalidad'] = (string) get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);
    $result['virtual_ticket']   = (bool) $is_virtual_ticket;

    // 2) Si hay Android Wallet y variantes, asegurar que las clases existan antes de crear objetos.
    $wallet_android_on = (!$is_virtual_ticket) && evapp_webhook_event_flag_on($evento_id, '_eventosapp_ticket_wallet_android');
    if ($wallet_android_on && function_exists('eventosapp_ticket_variants_sync_google_wallet_classes_for_event')) {
      try {
        $result['google_wallet_classes_sync'] = eventosapp_ticket_variants_sync_google_wallet_classes_for_event($evento_id, $context);
      } catch (\Throwable $e) {
        $result['errors'][] = 'google_wallet_classes_sync: '.$e->getMessage();
      }
    }

    // 3) QR: completar faltantes o archivos físicos perdidos. No regenera QR existentes válidos.
    $qr_manager = evapp_webhook_qr_manager();
    if ($qr_manager) {
      try {
        if (method_exists($qr_manager, 'generate_missing_qr_codes')) {
          $result['qr'] = $qr_manager->generate_missing_qr_codes($ticket_id);
        } elseif (method_exists($qr_manager, 'generate_all_qr_codes')) {
          $qr_manager->generate_all_qr_codes($ticket_id);
          $result['qr'] = 'checked';
        }
      } catch (\Throwable $e) {
        $result['errors'][] = 'qr: '.$e->getMessage();
      }
    } else {
      $result['qr'] = 'manager_not_available';
    }

    // 4) PDF: usa QR tipo PDF y metadatos de variante ya aplicados.
    $pdf_on = (!$is_virtual_ticket) && evapp_webhook_event_flag_on($evento_id, '_eventosapp_ticket_pdf');
    if ($is_virtual_ticket) {
      delete_post_meta($ticket_id, '_eventosapp_ticket_pdf_url');
      $result['pdf'] = 'disabled_virtual';
    } elseif ($pdf_on && function_exists('eventosapp_ticket_generar_pdf')) {
      try {
        eventosapp_ticket_generar_pdf($ticket_id);
        $result['pdf'] = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true) ? 'regenerated' : 'generated_no_url_meta';
      } catch (\Throwable $e) {
        $result['errors'][] = 'pdf: '.$e->getMessage();
      }
    } else {
      $result['pdf'] = $pdf_on ? 'helper_not_available' : 'disabled';
    }

    // 5) ICS: se delega al generador actual si está disponible.
    $ics_on = evapp_webhook_event_flag_on($evento_id, '_eventosapp_ticket_ics');
    if ($ics_on && function_exists('eventosapp_ticket_generar_ics')) {
      try {
        eventosapp_ticket_generar_ics($ticket_id);
        $result['ics'] = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true) ? 'regenerated' : 'generated_no_url_meta';
      } catch (\Throwable $e) {
        $result['errors'][] = 'ics: '.$e->getMessage();
      }
    } else {
      $result['ics'] = $ics_on ? 'helper_not_available' : 'disabled';
    }

    // 6) Google Wallet / Android.
    if ($is_virtual_ticket) {
      delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url');
      delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_android');
      delete_post_meta($ticket_id, '_eventosapp_wallet_google_object_id');
      $result['wallet_android'] = 'disabled_virtual';
    } elseif ($wallet_android_on) {
      if (function_exists('eventosapp_generar_enlace_wallet_android')) {
        try {
          $before_effective_class = (string) get_post_meta($ticket_id, '_eventosapp_wallet_google_class_id_effective', true);
          $before_object_id       = (string) get_post_meta($ticket_id, '_eventosapp_wallet_google_object_id', true);

          $android_url = eventosapp_generar_enlace_wallet_android($ticket_id, false);
          if ($android_url) {
            update_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url', esc_url_raw($android_url));
          }

          $after_effective_class = (string) get_post_meta($ticket_id, '_eventosapp_wallet_google_class_id_effective', true);
          $after_object_id       = (string) get_post_meta($ticket_id, '_eventosapp_wallet_google_object_id', true);
          $variant_class_id      = (string) get_post_meta($ticket_id, '_eventosapp_wallet_variant_class_id', true);

          $result['wallet_android_url']       = $android_url ? (string)$android_url : (string) get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url', true);
          $result['wallet_android_object_id'] = $after_object_id;
          $result['wallet_android_class_id']  = $after_effective_class;

          if (!$android_url) {
            $result['wallet_android'] = 'failed_or_empty_url';
            error_log('[EventosApp] Webhook Android Wallet URL vacía ticket='.$ticket_id.' event='.$evento_id.' context='.$context.' variant_class='.$variant_class_id.' before_class='.$before_effective_class.' after_class='.$after_effective_class.' before_object='.$before_object_id.' after_object='.$after_object_id);
          } elseif ($variant_class_id !== '' && $after_effective_class !== '' && $after_effective_class !== $variant_class_id && function_exists('eventosapp_ticket_variants_refresh_google_wallet_object')) {
            // Respaldo para instalaciones donde el generador principal todavía no toma el class_id de variante.
            $refresh = eventosapp_ticket_variants_refresh_google_wallet_object($ticket_id, $evento_id);
            $result['wallet_android'] = $refresh ? 'regenerated_variant_refresh' : 'regenerated_variant_refresh_failed';
            $result['wallet_android_class_id'] = (string) get_post_meta($ticket_id, '_eventosapp_wallet_google_class_id_effective', true);
          } else {
            $result['wallet_android'] = 'regenerated';
          }
        } catch (\Throwable $e) {
          $result['errors'][] = 'wallet_android: '.$e->getMessage();
        }
      } else {
        $result['wallet_android'] = 'helper_not_available';
      }
    } else {
      $result['wallet_android'] = 'disabled';
    }

    // 7) Apple Wallet.
    $wallet_ios_on = (!$is_virtual_ticket) && evapp_webhook_event_flag_on($evento_id, '_eventosapp_ticket_wallet_apple');
    if ($is_virtual_ticket) {
      delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple');
      delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url');
      delete_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url');
      $result['wallet_apple'] = 'disabled_virtual';
    } elseif ($wallet_ios_on) {
      try {
        if (function_exists('eventosapp_apple_generate_pass')) {
          $apple_url = eventosapp_apple_generate_pass($ticket_id);
          $result['wallet_apple'] = $apple_url ? 'regenerated' : 'generated_empty_url';
        } elseif (function_exists('eventosapp_generar_enlace_wallet_apple')) {
          $apple_url = eventosapp_generar_enlace_wallet_apple($ticket_id);
          $result['wallet_apple'] = $apple_url ? 'regenerated' : 'generated_empty_url';
        } else {
          $apple_url = '';
          $result['wallet_apple'] = 'helper_not_available';
        }

        $result['wallet_apple_url'] = $apple_url ? (string)$apple_url : (string) get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple', true);
      } catch (\Throwable $e) {
        $result['errors'][] = 'wallet_apple: '.$e->getMessage();
      }
    } else {
      $result['wallet_apple'] = 'disabled';
    }

    // 8) Índice de búsqueda.
    if (function_exists('eventosapp_ticket_build_search_blob')) {
      try {
        eventosapp_ticket_build_search_blob($ticket_id);
        $result['search_index'] = 'rebuilt';
      } catch (\Throwable $e) {
        $result['errors'][] = 'search_index: '.$e->getMessage();
      }
    }

    update_post_meta($ticket_id, '_eventosapp_webhook_assets_last_sync', current_time('mysql'));
    update_post_meta($ticket_id, '_eventosapp_webhook_assets_last_result', $result);

    do_action('eventosapp_webhook_assets_refreshed', $ticket_id, $evento_id, $context, $result);

    error_log('[EventosApp] Webhook assets '.$context.' ticket='.$ticket_id.' event='.$evento_id.' result='.wp_json_encode($result));

    return $result;
  }
}

/** ---------------------------------------------------------------------------
 * Rutas REST (alias genérico + ruta histórica /ac-webhook)
 * ------------------------------------------------------------------------- */
add_action('rest_api_init', function () {
  register_rest_route('eventosapp/v1', '/ac-webhook', [
    'methods'  => 'POST',
    'callback' => 'eventosapp_ac_webhook_handler',
    'permission_callback' => '__return_true',
  ]);

  // Alias más genérico (idéntico callback)
  register_rest_route('eventosapp/v1', '/webhook', [
    'methods'  => 'POST',
    'callback' => 'eventosapp_ac_webhook_handler',
    'permission_callback' => '__return_true',
  ]);
});

/**
 * Handler del webhook (agnóstico de plataforma).
 * Acepta secreto por:
 *  - query:        ?key=...
 *  - header:       X-Webhook-Secret: ...
 *  - header:       X-API-Key: ...
 *  - Authorization: Bearer ...
 * Payload: JSON o x-www-form-urlencoded (con fallback a raw JSON).
 */
function eventosapp_ac_webhook_handler(\WP_REST_Request $req){
  // --- Autenticación por secreto ---
  $key = $req->get_param('key')
       ?: $req->get_header('x-webhook-secret')
       ?: $req->get_header('X-Webhook-Secret')
       ?: $req->get_header('x-api-key')
       ?: $req->get_header('X-API-Key');

  if (!$key) {
    $auth = $req->get_header('authorization');
    if ($auth && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m)) {
      $key = $m[1];
    }
  }

  if (!$key || !defined('EVENTOSAPP_INTAKE_KEY') || $key !== EVENTOSAPP_INTAKE_KEY) {
    return new \WP_Error('forbidden','Token inválido', ['status'=>403]);
  }

  // --- Cargar datos (JSON robusto o x-www-form-urlencoded) ---
  $data = $req->get_json_params();
  if (!is_array($data) || !$data) {
    // Si WP no parseó JSON, intenta con el raw body
    $raw = method_exists($req, 'get_body') ? $req->get_body() : '';
    if (is_string($raw) && trim($raw) !== '') {
      $try = json_decode($raw, true);
      if (is_array($try)) $data = $try;
    }
  }
  if (!is_array($data) || !$data) {
    $data = $req->get_body_params();
  }
  if (!is_array($data)) $data = [];

  // Helper inline
  $pick = function(array $keys, $default='') use ($data) {
    return evapp_pick_from($data, $keys, $default);
  };

  // === Mapeo flexible ===
  $evento_id  = absint($pick(['event_id','evento_id','ev_evento_id'], 0));
  $email      = sanitize_email($pick(['email','correo','contact[email]','contact.email']));
  $first_name = sanitize_text_field($pick(['first_name','firstname','nombre','contact[first_name]','contact.first_name']));
  $last_name  = sanitize_text_field($pick(['last_name','lastname','apellido','contact[last_name]','contact.last_name']));
  $phone      = sanitize_text_field($pick(['phone','telefono','celular','contact[phone]','contact.phone']));
  $company    = sanitize_text_field($pick(['company','empresa']));
  $cc         = sanitize_text_field($pick(['cc','cedula']));
  $nit        = sanitize_text_field($pick(['nit']));
  $cargo      = sanitize_text_field($pick(['cargo','job_title','position']));
  $city       = sanitize_text_field($pick(['city','ciudad']));
  $country    = sanitize_text_field($pick(['country','pais'], 'Colombia'));
  $localidad  = sanitize_text_field($pick(['localidad','ticket_localidad']));
  $modalidad  = sanitize_text_field($pick(['modalidad','ticket_modalidad','eventosapp_ticket_modalidad','modality','mode','attendance_mode','attendance_modality']));

  // Flags de control
  $resend_param = $req->get_param('resend') ?? $pick(['resend','send_email','send','reenviar','force_send','send_email_on_update'], '');
  $force_send_on_update = evapp_boolish($resend_param);

  // NUEVO: control de deduplicación
  // dedupe = 'external_id' (default), 'email', 'none'
  $dedupe_pref = strtolower((string)($req->get_param('dedupe') ?? $pick(['dedupe','dedupe_mode'], 'external_id')));
  if (!in_array($dedupe_pref, ['external_id','email','none'], true)) $dedupe_pref = 'external_id';
  $force_new = evapp_boolish( $req->get_param('force_new') ?? $pick(['force_new','create_new','new'], '') );

  // Permite devolver el detalle de evaluación de condicionales en la respuesta REST solo cuando se pida explícitamente.
  $debug_conditionals = evapp_boolish($req->get_param('debug_conditionals') ?? $pick(['debug_conditionals','conditionals_debug'], ''));

  // Para deduplicar opcionalmente por envío de la plataforma
  $external_id = sanitize_text_field($pick(['submission_id','ac_submission_id','external_id','id','payload_id']));

  if (!$evento_id)  return new \WP_Error('bad_request','Falta evento_id', ['status'=>400]);
  if (!$email)      return new \WP_Error('bad_request','Falta email', ['status'=>400]);

  // === Dedupe según preferencia/config ===
  // Regla crítica: NUNCA reutilizar un ticket de otro evento, aunque el external_id sea igual.
  // Esto permite usar la cédula como external_id sin pisar tickets históricos del mismo asistente.
  $existing = [];
  $dedupe_used = 'none';
  $external_scope_key = $external_id ? evapp_build_webhook_scope_key($evento_id, $external_id) : '';

  if (!$force_new && $dedupe_pref !== 'none') {
    // 1) por external_id + evento (cuando aplica)
    if ($external_id && $dedupe_pref !== 'email') {
      $found_by_external = evapp_find_ticket_by_external_id_evento($external_id, $evento_id);
      if ($found_by_external) {
        $existing = [$found_by_external];
        $dedupe_used = 'external_id_event';
        error_log('[EventosApp] Webhook dedupe by external_id+event: ticket '.$found_by_external.' reutilizado para external_id='.$external_id.' evento='.$evento_id);
      }
    }

    // 2) por (evento + email) SOLO si se pide explícitamente dedupe=email
    if (!$existing && $dedupe_pref === 'email') {
      $found_by_email = evapp_find_ticket_by_email_evento($email, $evento_id);
      if ($found_by_email) {
        $existing = [$found_by_email];
        $dedupe_used = 'email_event';
        error_log('[EventosApp] Webhook dedupe by email+event: ticket '.$found_by_email.' reutilizado para email='.$email.' evento='.$evento_id);
      }
    }

    // 3) por (evento + cédula) — fallback universal que aplica en TODOS los modos salvo dedupe=none.
    //    También está limitado al evento; no cruza asistentes entre eventos.
    if (!$existing && $cc) {
      $found_by_cc = function_exists('evapp_find_ticket_by_cedula_evento')
        ? evapp_find_ticket_by_cedula_evento($cc, $evento_id)
        : false;
      if ($found_by_cc) {
        $existing = [$found_by_cc];
        $dedupe_used = 'cc_event';
        error_log('[EventosApp] Webhook dedupe by CC+event: ticket '.$found_by_cc.' reutilizado para cc='.$cc.' evento='.$evento_id);
      }
    }
  }

  // Protección adicional: si alguna función externa devolviera un ticket de otro evento,
  // se bloquea la actualización y se continúa por la ruta de creación de ticket nuevo.
  if ($existing) {
    $candidate_ticket_id = (int)$existing[0];
    if (!evapp_webhook_ticket_matches_event($candidate_ticket_id, $evento_id)) {
      $candidate_event_id = (int)get_post_meta($candidate_ticket_id, '_eventosapp_ticket_evento_id', true);

      eventosapp_add_ticket_audit_log($candidate_ticket_id, [
        'trigger'            => 'webhook_cross_event_reuse_blocked',
        'dedupe_by'          => $dedupe_used,
        'external_id'        => $external_id,
        'incoming_event_id'  => $evento_id,
        'ticket_event_id'    => $candidate_event_id,
        'message'            => 'Se bloqueó la actualización de este ticket porque el webhook pertenece a otro evento. El sistema continuará creando un ticket nuevo.',
        'ip'                 => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
      ]);

      error_log('[EventosApp] Webhook BLOCKED cross-event reuse: candidate_ticket='.$candidate_ticket_id.' ticket_event='.$candidate_event_id.' incoming_event='.$evento_id.' external_id='.$external_id.' dedupe='.$dedupe_used);
      $existing = [];
      $dedupe_used = 'blocked_cross_event';
    }
  }

// ======================================================================
// Si existe, ACTUALIZA y:
// - Siempre reenvía correo (el ticket fue modificado con nueva info)
// - Registra log de auditoría con campos que cambiaron
// ======================================================================
if ($existing) {
  $ticket_id = (int) $existing[0];

  // ---- CAPTURAR ESTADO PREVIO PARA AUDITORÍA ----
  $before_snapshot = [
    'nombre'    => get_post_meta($ticket_id, '_eventosapp_asistente_nombre',   true),
    'apellido'  => get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true),
    'email'     => get_post_meta($ticket_id, '_eventosapp_asistente_email',    true),
    'tel'       => get_post_meta($ticket_id, '_eventosapp_asistente_tel',      true),
    'empresa'   => get_post_meta($ticket_id, '_eventosapp_asistente_empresa',  true),
    'cc'        => get_post_meta($ticket_id, '_eventosapp_asistente_cc',       true),
    'nit'       => get_post_meta($ticket_id, '_eventosapp_asistente_nit',      true),
    'cargo'     => get_post_meta($ticket_id, '_eventosapp_asistente_cargo',    true),
    'ciudad'    => get_post_meta($ticket_id, '_eventosapp_asistente_ciudad',   true),
    'pais'      => get_post_meta($ticket_id, '_eventosapp_asistente_pais',     true),
    'localidad' => get_post_meta($ticket_id, '_eventosapp_asistente_localidad',true),
    'modalidad' => get_post_meta($ticket_id, '_eventosapp_ticket_modalidad',true),
  ];

  // Si hay campos extras del evento, capturarlos también
  if (function_exists('eventosapp_get_event_extra_fields')) {
    $schema_before = (array) eventosapp_get_event_extra_fields($evento_id);
    foreach ($schema_before as $fld) {
      if (empty($fld['key'])) continue;
      $before_snapshot['extra_' . $fld['key']] = get_post_meta($ticket_id, '_eventosapp_extra_' . $fld['key'], true);
    }
  }
  // ---- FIN CAPTURA PREVIA ----

  $payload_update_ok = eventosapp_update_ticket_from_payload($ticket_id, compact(
    'evento_id','email','first_name','last_name','phone','company','cc','nit','cargo','city','country','localidad','modalidad','external_id'
  ), $data);

  if ($payload_update_ok === false) {
    error_log('[EventosApp] Webhook UPDATE abortado por protección de evento para ticket '.$ticket_id.' evento='.$evento_id);
    return new \WP_Error('conflict', 'El ticket encontrado no pertenece al evento recibido; actualización bloqueada para evitar anexos de otro evento.', ['status' => 409]);
  }

  // Al actualizar un ticket del mismo evento por webhook, refrescar anexos para que PDF/ICS/Wallet
  // reflejen los datos actuales. Esta función NO permite cambiar de evento.
  $asset_refresh_result_upd = evapp_webhook_refresh_ticket_assets($ticket_id, $evento_id, 'webhook_update');

  // ⬇️ Asegurar autor/creador del ticket cuando entra por webhook
  $webhook_author = (int) evapp_webhook_author_id();
  $current_author = (int) get_post_field('post_author', $ticket_id);

  if ($current_author <= 0) {
    wp_update_post([
      'ID'          => $ticket_id,
      'post_author' => $webhook_author,
    ]);
  }

  $meta_uid = (int) get_post_meta($ticket_id, '_eventosapp_ticket_user_id', true);
  if ($meta_uid <= 0) {
    update_post_meta($ticket_id, '_eventosapp_ticket_user_id', $webhook_author);
  }
  // ⬆️ fin asegurar autor

  if ( ! get_post_meta($ticket_id, '_eventosapp_creation_channel', true) ) {
      update_post_meta($ticket_id, '_eventosapp_creation_channel', 'webhook');
  }

  // ---- CAPTURAR ESTADO POSTERIOR Y GUARDAR AUDITORÍA ----
  $after_snapshot = [
    'nombre'    => get_post_meta($ticket_id, '_eventosapp_asistente_nombre',   true),
    'apellido'  => get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true),
    'email'     => get_post_meta($ticket_id, '_eventosapp_asistente_email',    true),
    'tel'       => get_post_meta($ticket_id, '_eventosapp_asistente_tel',      true),
    'empresa'   => get_post_meta($ticket_id, '_eventosapp_asistente_empresa',  true),
    'cc'        => get_post_meta($ticket_id, '_eventosapp_asistente_cc',       true),
    'nit'       => get_post_meta($ticket_id, '_eventosapp_asistente_nit',      true),
    'cargo'     => get_post_meta($ticket_id, '_eventosapp_asistente_cargo',    true),
    'ciudad'    => get_post_meta($ticket_id, '_eventosapp_asistente_ciudad',   true),
    'pais'      => get_post_meta($ticket_id, '_eventosapp_asistente_pais',     true),
    'localidad' => get_post_meta($ticket_id, '_eventosapp_asistente_localidad',true),
    'modalidad' => get_post_meta($ticket_id, '_eventosapp_ticket_modalidad',true),
  ];

  if (function_exists('eventosapp_get_event_extra_fields')) {
    $schema_after = (array) eventosapp_get_event_extra_fields($evento_id);
    foreach ($schema_after as $fld) {
      if (empty($fld['key'])) continue;
      $after_snapshot['extra_' . $fld['key']] = get_post_meta($ticket_id, '_eventosapp_extra_' . $fld['key'], true);
    }
  }

  // Determinar qué campos cambiaron
  $changed_fields = [];
  foreach ($after_snapshot as $field => $new_val) {
    $old_val = isset($before_snapshot[$field]) ? $before_snapshot[$field] : '';
    if ((string)$old_val !== (string)$new_val) {
      $changed_fields[$field] = ['before' => $old_val, 'after' => $new_val];
    }
  }

  // Guardar entrada de auditoría solo si algo cambió
  if (!empty($changed_fields)) {
    eventosapp_add_ticket_audit_log($ticket_id, [
      'trigger'         => 'webhook_update',
      'dedupe_by'       => $dedupe_used,
      'dedupe_pref'     => $dedupe_pref,
      'external_id'     => $external_id,
      'changed_fields'  => $changed_fields,
      'before'          => $before_snapshot,
      'after'           => $after_snapshot,
      'ip'              => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
    ]);
  }
  // ---- FIN AUDITORÍA ----

  // Aplicar variantes actualizadas antes de evaluar condicionales y reenviar correo.
  if (function_exists('eventosapp_ticket_variants_apply_to_ticket')) {
    try {
      $variant_result_update = eventosapp_ticket_variants_apply_to_ticket($ticket_id, $evento_id, true);
      update_post_meta($ticket_id, '_eventosapp_webhook_variant_result', $variant_result_update);
      error_log('[EventosApp] Webhook UPDATE variant ticket='.$ticket_id.' event='.$evento_id.' result='.wp_json_encode($variant_result_update));
    } catch (\Throwable $e) {
      error_log('[EventosApp] Webhook UPDATE variant ERROR ticket='.$ticket_id.' event='.$evento_id.' error='.$e->getMessage());
    }
  }

  // ---- ENVÍO DE CORREO (siempre en actualización por CC) ----
  $email_result = ['email_sent' => false, 'email_msg' => ''];

  // En actualización por CC: SIEMPRE reenviar (el asistente tiene datos nuevos)
  // Solo se puede omitir si el payload explícitamente dice resend=false
  $resend_explicitly_false = (strtolower((string)($req->get_param('resend') ?? '')) === 'false');
  $should_send = !$resend_explicitly_false;

  // Si hay condicionales del webhook, evaluarlas también en update
  $conditional_result_upd = null;
  if ($should_send && function_exists('eventosapp_evaluate_webhook_conditionals')) {
    $conditional_result_upd = eventosapp_evaluate_webhook_conditionals($ticket_id, $evento_id);
    if (is_array($conditional_result_upd) && isset($conditional_result_upd['send_email'])) {
      $should_send = !empty($conditional_result_upd['send_email']);
    }
  }

  if ($should_send && function_exists('eventosapp_send_ticket_email_now')) {
    $args = [
      'source'    => 'webhook',
      'recipient' => $email,
      'force'     => true, // siempre forzar en update para ignorar idempotencia
    ];

    // Si condicional indicó plantilla específica
    $email_template_upd = null;
    if (is_array($conditional_result_upd) && !empty($conditional_result_upd['template'])) {
      $email_template_upd = $conditional_result_upd['template'];
      $original_template_upd = get_post_meta($evento_id, '_eventosapp_email_tpl', true);
      update_post_meta($evento_id, '_eventosapp_email_tpl', $email_template_upd);
    }

    list($sent, $msg) = eventosapp_send_ticket_email_now($ticket_id, $args);

    // Restaurar plantilla si fue modificada
    if ($email_template_upd) {
      if ($original_template_upd === '' || $original_template_upd === false) {
        delete_post_meta($evento_id, '_eventosapp_email_tpl');
      } else {
        update_post_meta($evento_id, '_eventosapp_email_tpl', $original_template_upd);
      }
    }

    update_post_meta($ticket_id, '_eventosapp_ticket_email_webhook_result', $sent ? 'sent' : 'failed');
    update_post_meta($ticket_id, '_eventosapp_ticket_email_webhook_msg', $msg);
    update_post_meta($ticket_id, '_eventosapp_ticket_email_webhook_sent', current_time('mysql'));

    $email_result = ['email_sent' => (bool) $sent, 'email_msg' => $msg];

    error_log('[EventosApp] webhook UPDATE mail ticket ' . $ticket_id . ' -> ' . ($sent ? 'SENT' : 'FAILED') . ' | ' . $msg . ' (forced resend on CC update)');
  } elseif (!$should_send) {
    $email_result = ['email_sent' => false, 'email_msg' => 'Correo no enviado: ' . ($resend_explicitly_false ? 'resend=false en payload' : 'regla condicional')];
    error_log('[EventosApp] webhook UPDATE ticket ' . $ticket_id . ' -> email SKIPPED');
  }

/**
   * Hook de extensión: ticket actualizado vía webhook.
   * Se dispara después de que todos los metas han sido escritos
   * y el correo ha sido procesado.
   *
   * @param int   $ticket_id
   * @param array $data Payload raw (sanitizado/parseado)
   */
  do_action( 'eventosapp_ticket_updated_via_webhook', $ticket_id, $data );

  $response = array_merge([
    'ok'         => true,
    'ticket_id'  => $ticket_id,
    'updated'    => true,
    'dedupe_by'  => $dedupe_used,
    'fields_changed' => array_keys($changed_fields),
    'audit_logged'   => !empty($changed_fields),
    // Resultado interno completo: se conserva solo para construir la salida pública segura.
    // No se devuelve directamente para evitar exponer reglas, class IDs, JWTs o URLs de Wallet.
    'asset_refresh'  => isset($asset_refresh_result_upd) ? $asset_refresh_result_upd : null,
    'conditional_matched' => is_array($conditional_result_upd) && !empty($conditional_result_upd['matched_rule']),
    'conditional_summary' => evapp_webhook_public_conditional_summary($conditional_result_upd),
  ], $email_result);

  if ($debug_conditionals && is_array($conditional_result_upd) && isset($conditional_result_upd['debug'])) {
    // Compatibilidad con debug=1, pero sin devolver valores comparados ni reglas completas.
    $response['conditional_debug'] = evapp_webhook_public_conditional_summary($conditional_result_upd);
  }

  return evapp_webhook_build_public_response($response, $ticket_id, $evento_id, 'webhook_update');
}


  // === Crear ticket nuevo ===

  // Generar ID público antes de insertar y usarlo como título del post
  $ticket_public_id = function_exists('eventosapp_generate_unique_ticket_id')
    ? eventosapp_generate_unique_ticket_id()
    : wp_generate_uuid4();

  // Autor: emisor1 (ID 17) o resuelto por helper
  $webhook_author = (int) evapp_webhook_author_id();

  $post_id = wp_insert_post([
    'post_type'   => 'eventosapp_ticket',
    'post_status' => 'publish',
    'post_title'  => sanitize_text_field($ticket_public_id), // título = ID público
    'post_author' => $webhook_author,                        // ⬅️ autor del webhook
  ], true);
  if (is_wp_error($post_id)) return $post_id;

  // Guardar ID público y secuencia
  update_post_meta($post_id, 'eventosapp_ticketID', $ticket_public_id);
  $seq = function_exists('eventosapp_next_event_sequence') ? (int) eventosapp_next_event_sequence($evento_id) : 0;
  update_post_meta($post_id, '_eventosapp_ticket_seq', $seq);

  // Guardar metadatos principales (incluye el user_id del creador)
  update_post_meta($post_id, '_eventosapp_ticket_evento_id', $evento_id);
  update_post_meta($post_id, '_eventosapp_ticket_evento_id_original', $evento_id);
  update_post_meta($post_id, '_eventosapp_ticket_user_id', $webhook_author);
  // 2.3 Webhook (marca como webhook)
  update_post_meta($post_id, '_eventosapp_creation_channel', 'webhook');
  update_post_meta($post_id, '_eventosapp_ticket_webhook_created_at', current_time('mysql'));
  update_post_meta($post_id, '_eventosapp_asistente_nombre',   $first_name);
  update_post_meta($post_id, '_eventosapp_asistente_apellido', $last_name);
  update_post_meta($post_id, '_eventosapp_asistente_cc',       $cc);
  update_post_meta($post_id, '_eventosapp_asistente_email',    $email);
  update_post_meta($post_id, '_eventosapp_asistente_tel',      $phone);
  update_post_meta($post_id, '_eventosapp_asistente_empresa',  $company);
  update_post_meta($post_id, '_eventosapp_asistente_nit',      $nit);
  update_post_meta($post_id, '_eventosapp_asistente_cargo',    $cargo);
  update_post_meta($post_id, '_eventosapp_asistente_ciudad',   $city);
  update_post_meta($post_id, '_eventosapp_asistente_pais',     $country);
  update_post_meta($post_id, '_eventosapp_asistente_localidad',$localidad);
  $ticket_modalidad = function_exists('eventosapp_resolve_ticket_modalidad') ? eventosapp_resolve_ticket_modalidad($evento_id, $modalidad, '') : sanitize_key($modalidad ?: 'presencial');
  update_post_meta($post_id, '_eventosapp_ticket_modalidad', $ticket_modalidad);
  if (!empty($external_id)) {
    update_post_meta($post_id, '_eventosapp_external_id', $external_id);
    update_post_meta($post_id, '_eventosapp_external_scope_key', $external_scope_key);
  }


  // Campos adicionales del evento (si llegan en el payload)
  if (function_exists('eventosapp_get_event_extra_fields')) {
    $schema = (array) eventosapp_get_event_extra_fields($evento_id);
    foreach ($schema as $fld) {
      if (empty($fld['key'])) continue;
      $k = (string)$fld['key'];
      // Acepta varias ubicaciones convencionales + sueltas
      $raw = evapp_pick_extra($data, $k);
      if (function_exists('eventosapp_normalize_extra_value')) {
        $val = eventosapp_normalize_extra_value($fld, $raw);
      } else {
        $val = is_scalar($raw) ? sanitize_text_field($raw) : '';
      }
      update_post_meta($post_id, '_eventosapp_extra_'.$k, $val);
    }
  }

  // Guardar también un índice consolidado de extras para que las condicionales puedan leerlos de forma confiable.
  $webhook_extras_saved = evapp_save_ticket_extras_from_payload((int)$post_id, (int)$evento_id, $data);

  // Inicializar check-in por día
  $days = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days($evento_id) : [];
  if ($days) {
    $status = [];
    foreach ($days as $d) $status[$d] = 'not_checked_in';
    update_post_meta($post_id, '_eventosapp_checkin_status', $status);
  }

  // Auto-asignar acceso a sesiones según localidad
  $sesiones = get_post_meta($evento_id, '_eventosapp_sesiones_internas', true);
  if (is_array($sesiones) && $localidad) {
    $accesos = [];
    foreach ($sesiones as $s) {
      if (isset($s['nombre'],$s['localidades']) && is_array($s['localidades']) && in_array($localidad, $s['localidades'], true)) {
        $accesos[] = $s['nombre'];
      }
    }
    if ($accesos) update_post_meta($post_id, '_eventosapp_ticket_sesiones_acceso', $accesos);
  }

  // ============================================================================
  // Compatibilidad webhook + variantes + anexos.
  // Unifica el mismo flujo usado en updates para que creación por webhook genere:
  // QRs, PDF, ICS, Google Wallet, Apple Wallet, clase Android de variante y search index
  // después de guardar datos base/extras y antes de evaluar condicionales/correo.
  // ============================================================================
  $asset_refresh_result_create = evapp_webhook_refresh_ticket_assets($post_id, $evento_id, 'webhook_create');

  // --- Marcar origen ---
  update_post_meta($post_id, '_eventosapp_ticket_origin', 'webhook');

  // --- EVALUAR CONDICIONALES Y ENVIAR CORREO SI CORRESPONDE ---
  $email_result = ['email_sent' => false, 'email_msg' => '', 'conditional_matched' => false];
  
  $should_send_email = true; // Por defecto SÍ enviar
  $email_template = null;
  $conditional_result = null;
  
  // Evaluar condicionales si la función existe
  if (function_exists('eventosapp_evaluate_webhook_conditionals')) {
    $conditional_result = eventosapp_evaluate_webhook_conditionals($post_id, $evento_id);
    
    if (is_array($conditional_result)) {
      $should_send_email = !empty($conditional_result['send_email']);
      $email_template = $conditional_result['template'] ?? null;
      
      // Log de qué regla se aplicó (para debugging)
      if (isset($conditional_result['matched_rule']) && is_array($conditional_result['matched_rule'])) {
        update_post_meta($post_id, '_eventosapp_webhook_conditional_matched', $conditional_result['matched_rule']);
        $email_result['conditional_matched'] = true;
      }
    }
  }
  
  // Enviar correo si corresponde
  if ($should_send_email && function_exists('eventosapp_send_ticket_email_now')) {
    // Si hay una plantilla específica de la condicional, temporalmente cambiar la plantilla del evento
    $original_template = null;
    if ($email_template) {
      $original_template = get_post_meta($evento_id, '_eventosapp_email_tpl', true);
      update_post_meta($evento_id, '_eventosapp_email_tpl', $email_template);
    }
    
    // Enviar el correo
    list($sent, $msg) = eventosapp_send_ticket_email_now($post_id, [
      'source'    => 'webhook',  // idempotente en reintentos
      'recipient' => $email,
      // 'force' => true, // descomenta si quieres ignorar idempotencia globalmente
    ]);
    
    // Restaurar plantilla original si fue modificada
    if ($email_template && $original_template !== null) {
      if ($original_template === '' || $original_template === false) {
        delete_post_meta($evento_id, '_eventosapp_email_tpl');
      } else {
        update_post_meta($evento_id, '_eventosapp_email_tpl', $original_template);
      }
    }
    
    // Trazas de auditoría
    update_post_meta($post_id, '_eventosapp_ticket_email_webhook_result', $sent ? 'sent' : 'failed');
    update_post_meta($post_id, '_eventosapp_ticket_email_webhook_msg', $msg);
    if ($email_template) {
      update_post_meta($post_id, '_eventosapp_ticket_email_webhook_template', $email_template);
    }
    
    $email_result = [
      'email_sent' => (bool) $sent, 
      'email_msg' => $msg,
      'conditional_matched' => $email_result['conditional_matched'],
      'template_used' => $email_template ?: 'default'
    ];

    error_log('[EventosApp] webhook CREATE mail ticket '.$post_id.' -> '.($sent ? 'SENT' : 'FAILED').' | '.$msg.($email_template ? ' | Template: '.$email_template : ''));
  } elseif (!$should_send_email) {
    // Si no se envió correo por una condicional, registrarlo
    update_post_meta($post_id, '_eventosapp_webhook_email_skipped', [
      'reason' => 'conditional_rule',
      'matched_rule' => $conditional_result['matched_rule'] ?? null,
      'at' => current_time('mysql')
    ]);
    $email_result = [
      'email_sent' => false, 
      'email_msg' => 'Correo no enviado por regla condicional',
      'conditional_matched' => true
    ];
    error_log('[EventosApp] webhook CREATE ticket '.$post_id.' -> email SKIPPED by conditional rule');
  } elseif (!function_exists('eventosapp_send_ticket_email_now')) {
    $email_result = ['email_sent' => false, 'email_msg' => 'eventosapp_send_ticket_email_now() no disponible', 'conditional_matched' => false];
    error_log('[EventosApp] webhook CREATE ticket '.$post_id.' -> email helper NO disponible');
  }

  /**
   * Hook de extensión: ticket creado vía webhook.
   * @param int   $post_id
   * @param array $data Payload raw (sanitizado/parseado)
   */
  do_action('eventosapp_ticket_created_via_webhook', $post_id, $data);

  // Respuesta REST segura: conserva trazabilidad operativa sin exponer reglas, JWTs, URLs ni configuración interna.
  $response = array_merge(
    [
      'ok'=>true,
      'ticket_id'=>$post_id,
      'public_id'=>$ticket_public_id,
      'created'=>true,
      'dedupe_by'=>$dedupe_used,
      // Resultado interno completo: se conserva solo para construir la salida pública segura.
      // No se devuelve directamente para evitar exponer reglas, class IDs, JWTs o URLs de Wallet.
      'asset_refresh'=>isset($asset_refresh_result_create) ? $asset_refresh_result_create : null,
      'conditional_summary' => evapp_webhook_public_conditional_summary($conditional_result),
    ],
    $email_result
  );

  if ($debug_conditionals && is_array($conditional_result) && isset($conditional_result['debug'])) {
    // Compatibilidad con debug=1, pero sin devolver valores comparados ni reglas completas.
    $response['conditional_debug'] = evapp_webhook_public_conditional_summary($conditional_result);
  }

  return evapp_webhook_build_public_response($response, $post_id, $evento_id, 'webhook_create');
}

/** Reutilizable: actualiza un ticket existente con nuevo payload */
function eventosapp_update_ticket_from_payload($post_id, array $flat, array $data){
  foreach ($flat as $k=>$v) $$k = $v; // vars locales

  // Defensa adicional: esta función no debe mover tickets entre eventos.
  // Si se intenta actualizar un ticket que ya pertenece a otro evento, se bloquea.
  $current_event_id = absint(get_post_meta($post_id, '_eventosapp_ticket_evento_id', true));
  if ($current_event_id && $current_event_id !== absint($evento_id)) {
    error_log('[EventosApp] Webhook update payload BLOCKED: ticket '.$post_id.' pertenece al evento '.$current_event_id.' y el payload intenta usar evento '.absint($evento_id));
    return false;
  }

  update_post_meta($post_id, '_eventosapp_ticket_evento_id',   (int)$evento_id);
  update_post_meta($post_id, '_eventosapp_asistente_nombre',   (string)$first_name);
  update_post_meta($post_id, '_eventosapp_asistente_apellido', (string)$last_name);
  update_post_meta($post_id, '_eventosapp_asistente_email',    (string)$email);
  update_post_meta($post_id, '_eventosapp_asistente_tel',      (string)$phone);
  update_post_meta($post_id, '_eventosapp_asistente_empresa',  (string)$company);
  update_post_meta($post_id, '_eventosapp_asistente_cc',       (string)$cc);
  update_post_meta($post_id, '_eventosapp_asistente_nit',      (string)$nit);
  update_post_meta($post_id, '_eventosapp_asistente_cargo',    (string)$cargo);
  update_post_meta($post_id, '_eventosapp_asistente_ciudad',   (string)$city);
  update_post_meta($post_id, '_eventosapp_asistente_pais',     (string)($country ?: 'Colombia'));
  update_post_meta($post_id, '_eventosapp_asistente_localidad',(string)$localidad);
  $ticket_modalidad = function_exists('eventosapp_resolve_ticket_modalidad') ? eventosapp_resolve_ticket_modalidad((int)$evento_id, isset($modalidad) ? $modalidad : evapp_pick_from($data, ['modalidad','ticket_modalidad','eventosapp_ticket_modalidad','modality','mode','attendance_mode','attendance_modality']), get_post_meta($post_id, '_eventosapp_ticket_modalidad', true)) : sanitize_key((string)(isset($modalidad) ? $modalidad : 'presencial'));
  update_post_meta($post_id, '_eventosapp_ticket_modalidad', $ticket_modalidad);
  if (!empty($external_id)) {
    update_post_meta($post_id, '_eventosapp_external_id', (string)$external_id);
    if (function_exists('evapp_build_webhook_scope_key')) {
      update_post_meta($post_id, '_eventosapp_external_scope_key', evapp_build_webhook_scope_key((int)$evento_id, (string)$external_id));
    }
  }

  // Extras del evento
  if (function_exists('eventosapp_get_event_extra_fields')) {
    $schema = (array) eventosapp_get_event_extra_fields($evento_id);
    foreach ($schema as $fld) {
      if (empty($fld['key'])) continue;
      $k = (string)$fld['key'];
      $raw = evapp_pick_extra($data, $k);
      if (function_exists('eventosapp_normalize_extra_value')) {
        $val = eventosapp_normalize_extra_value($fld, $raw);
      } else {
        $val = is_scalar($raw) ? sanitize_text_field($raw) : '';
      }
      update_post_meta($post_id, '_eventosapp_extra_'.$k, $val);
    }
  }

  // Guardar también un índice consolidado de extras para evaluación de condicionales y diagnóstico.
  evapp_save_ticket_extras_from_payload((int)$post_id, (int)$evento_id, $data);

  // Recalcular accesos por localidad si hay esquema de sesiones
  $sesiones_upd = get_post_meta((int)$evento_id, '_eventosapp_sesiones_internas', true);
  if (is_array($sesiones_upd) && !empty($localidad)) {
    $accesos_upd = [];
    foreach ($sesiones_upd as $s) {
      if (!empty($s['nombre']) && !empty($s['localidades']) && is_array($s['localidades'])) {
        if (in_array($localidad, $s['localidades'], true)) $accesos_upd[] = $s['nombre'];
      }
    }
    update_post_meta($post_id, '_eventosapp_ticket_sesiones_acceso', $accesos_upd);
  }

  // Reindexar búsqueda si aplica
  if (function_exists('eventosapp_ticket_build_search_blob')) {
    eventosapp_ticket_build_search_blob($post_id);
  }

  return true;
}
