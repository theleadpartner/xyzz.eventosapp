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

  // Flags de control
  $resend_param = $req->get_param('resend') ?? $pick(['resend','send_email','send','reenviar','force_send','send_email_on_update'], '');
  $force_send_on_update = evapp_boolish($resend_param);

  // NUEVO: control de deduplicación
  // dedupe = 'external_id' (default), 'email', 'none'
  $dedupe_pref = strtolower((string)($req->get_param('dedupe') ?? $pick(['dedupe','dedupe_mode'], 'external_id')));
  if (!in_array($dedupe_pref, ['external_id','email','none'], true)) $dedupe_pref = 'external_id';
  $force_new = evapp_boolish( $req->get_param('force_new') ?? $pick(['force_new','create_new','new'], '') );

  // Para deduplicar opcionalmente por envío de la plataforma
  $external_id = sanitize_text_field($pick(['submission_id','ac_submission_id','external_id','id','payload_id']));

  if (!$evento_id)  return new \WP_Error('bad_request','Falta evento_id', ['status'=>400]);
  if (!$email)      return new \WP_Error('bad_request','Falta email', ['status'=>400]);

  // === Dedupe según preferencia/config ===
  $existing = [];
  if (!$force_new && $dedupe_pref !== 'none') {
    // 1) por external_id (cuando aplica)
    if ($external_id && $dedupe_pref !== 'email') {
      $existing = get_posts([
        'post_type'   => 'eventosapp_ticket',
        'post_status' => 'any',
        'meta_key'    => '_eventosapp_external_id',
        'meta_value'  => $external_id,
        'fields'      => 'ids',
        'numberposts' => 1,
      ]);
    }
    // 2) por (evento + email) SOLO si se pide explícitamente dedupe=email
    if (!$existing && $dedupe_pref === 'email') {
      $q = new WP_Query([
        'post_type'       => 'eventosapp_ticket',
        'post_status'     => 'any',
        'fields'          => 'ids',
        'posts_per_page'  => 1,
        'meta_query'      => [
          'relation' => 'AND',
          [ 'key'=>'_eventosapp_ticket_evento_id', 'value'=>$evento_id ],
          [ 'key'=>'_eventosapp_asistente_email',  'value'=>$email   ],
        ],
        'no_found_rows'   => true,
      ]);
      $existing = $q->posts;
    }
  }

// ======================================================================
// Si existe, ACTUALIZA y:
// - Si nunca se ha enviado correo por webhook -> lo envía ahora (idempotente).
// - Si viene resend/force_send -> reenvía aunque ya se haya enviado.
// ======================================================================
if ($existing) {
  $ticket_id = (int) $existing[0];

  eventosapp_update_ticket_from_payload($ticket_id, compact(
    'evento_id','email','first_name','last_name','phone','company','cc','nit','cargo','city','country','localidad','external_id'
  ), $data);

  // ⬇️ Asegurar autor/creador del ticket cuando entra por webhook
  $webhook_author = (int) evapp_webhook_author_id();
  $current_author = (int) get_post_field('post_author', $ticket_id);

  // Si no tiene autor (0) lo fijamos al usuario del webhook
  if ($current_author <= 0) {
    wp_update_post([
      'ID'          => $ticket_id,
      'post_author' => $webhook_author,
    ]);
  }

  // Reflejar también en el meta interno si está vacío
  $meta_uid = (int) get_post_meta($ticket_id, '_eventosapp_ticket_user_id', true);
  if ($meta_uid <= 0) {
    update_post_meta($ticket_id, '_eventosapp_ticket_user_id', $webhook_author);
  }
  // ⬆️ fin asegurar autor

  // 2.3 Webhook (marca como webhook)
  // Solo si aún no tiene canal de creación, lo marcamos como 'webhook'
  if ( ! get_post_meta($ticket_id, '_eventosapp_creation_channel', true) ) {
      update_post_meta($ticket_id, '_eventosapp_creation_channel', 'webhook');
  }

  $email_result = ['email_sent' => false, 'email_msg' => ''];


  $already_sent = get_post_meta($ticket_id, '_eventosapp_ticket_email_webhook_sent', true);
  $should_send  = $force_send_on_update || empty($already_sent);

  if ($should_send && function_exists('eventosapp_send_ticket_email_now')) {
    $args = [
      'source'    => 'webhook',
      'recipient' => $email,
    ];
    if ($force_send_on_update) {
      $args['force'] = true; // ignora idempotencia a propósito
    }

    list($sent, $msg) = eventosapp_send_ticket_email_now($ticket_id, $args);

    update_post_meta($ticket_id, '_eventosapp_ticket_email_webhook_result', $sent ? 'sent' : 'failed');
    update_post_meta($ticket_id, '_eventosapp_ticket_email_webhook_msg', $msg);

    $email_result = ['email_sent' => (bool) $sent, 'email_msg' => $msg];

    error_log('[EventosApp] webhook UPDATE mail ticket '.$ticket_id.' -> '.($sent ? 'SENT' : 'FAILED').' | '.$msg.( $force_send_on_update ? ' (forced)' : ''));
  } else {
    error_log('[EventosApp] webhook UPDATE ticket '.$ticket_id.' (sin envío: '.( $already_sent ? 'ya enviado' : 'resend/force_send no solicitado' ).')');
  }

  return array_merge(['ok'=>true, 'ticket_id'=>$ticket_id, 'updated'=>true], $email_result);
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
  update_post_meta($post_id, '_eventosapp_ticket_user_id', $webhook_author);
  // 2.3 Webhook (marca como webhook)
  update_post_meta($post_id, '_eventosapp_creation_channel', 'webhook');
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
  if (!empty($external_id)) update_post_meta($post_id, '_eventosapp_external_id', $external_id);


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

  // Generar adjuntos/archivos según flags del evento (opcional; el helper de email también genera si están ON)
  $pdf_on = get_post_meta($evento_id, '_eventosapp_ticket_pdf', true) === '1';
  $ics_on = get_post_meta($evento_id, '_eventosapp_ticket_ics', true) === '1';
  if ($pdf_on && function_exists('eventosapp_ticket_generar_pdf')) eventosapp_ticket_generar_pdf($post_id);
  if ($ics_on && function_exists('eventosapp_ticket_generar_ics')) eventosapp_ticket_generar_ics($post_id);

  $wallet_android_on = get_post_meta($evento_id, '_eventosapp_ticket_wallet_android', true);
  if ($wallet_android_on==='1' || $wallet_android_on===1 || $wallet_android_on===true) {
    if (function_exists('eventosapp_generar_enlace_wallet_android')) eventosapp_generar_enlace_wallet_android($post_id, false);
  }

  // Indexar para búsquedas
  if (function_exists('eventosapp_ticket_build_search_blob')) eventosapp_ticket_build_search_blob($post_id);

  // --- Marcar origen y enviar correo (webhook) ---
  update_post_meta($post_id, '_eventosapp_ticket_origin', 'webhook');

  $email_result = ['email_sent' => false, 'email_msg' => ''];
  if ( function_exists('eventosapp_send_ticket_email_now') ) {
    list($sent, $msg) = eventosapp_send_ticket_email_now($post_id, [
      'source'    => 'webhook',  // idempotente en reintentos
      'recipient' => $email,
      // 'force' => true, // descomenta si quieres ignorar idempotencia globalmente
    ]);
    // Trazas de auditoría
    update_post_meta($post_id, '_eventosapp_ticket_email_webhook_result', $sent ? 'sent' : 'failed');
    update_post_meta($post_id, '_eventosapp_ticket_email_webhook_msg', $msg);
    $email_result = ['email_sent' => (bool) $sent, 'email_msg' => $msg];

    error_log('[EventosApp] webhook CREATE mail ticket '.$post_id.' -> '.($sent ? 'SENT' : 'FAILED').' | '.$msg);
  } else {
    $email_result = ['email_sent' => false, 'email_msg' => 'eventosapp_send_ticket_email_now() no disponible'];
    error_log('[EventosApp] webhook CREATE ticket '.$post_id.' -> email helper NO disponible');
  }

  /**
   * Hook de extensión: ticket creado vía webhook.
   * @param int   $post_id
   * @param array $data Payload raw (sanitizado/parseado)
   */
  do_action('eventosapp_ticket_created_via_webhook', $post_id, $data);

  // Respuesta REST incluyendo el resultado del correo
  return array_merge(
    ['ok'=>true, 'ticket_id'=>$post_id, 'public_id'=>$ticket_public_id],
    $email_result
  );
}

/** Reutilizable: actualiza un ticket existente con nuevo payload */
function eventosapp_update_ticket_from_payload($post_id, array $flat, array $data){
  foreach ($flat as $k=>$v) $$k = $v; // vars locales
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
  if (!empty($external_id)) update_post_meta($post_id, '_eventosapp_external_id', (string)$external_id);

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

  // Reindexar búsqueda si aplica
  if (function_exists('eventosapp_ticket_build_search_blob')) {
    eventosapp_ticket_build_search_blob($post_id);
  }
}


if ( ! function_exists('eventosapp_update_ticket_from_payload') ) {
  function eventosapp_update_ticket_from_payload($ticket_id, $core, $all = []) {
    // metas base
    update_post_meta($ticket_id, '_eventosapp_ticket_evento_id', (int)($core['evento_id'] ?? 0));
    update_post_meta($ticket_id, '_eventosapp_asistente_email',    sanitize_email($core['email']      ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_nombre',   sanitize_text_field($core['first_name'] ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_apellido', sanitize_text_field($core['last_name']  ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_tel',      sanitize_text_field($core['phone']      ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_empresa',  sanitize_text_field($core['company']    ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_cc',       sanitize_text_field($core['cc']         ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_nit',      sanitize_text_field($core['nit']        ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_cargo',    sanitize_text_field($core['cargo']      ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_ciudad',   sanitize_text_field($core['city']       ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_pais',     sanitize_text_field($core['country']    ?? 'Colombia'));
    update_post_meta($ticket_id, '_eventosapp_asistente_localidad',sanitize_text_field($core['localidad']  ?? ''));

    // ID externo (para idempotencia)
    if (!empty($core['external_id'])) {
      update_post_meta($ticket_id, '_eventosapp_external_id', sanitize_text_field($core['external_id']));
    }

    // Recalcular accesos por localidad si hay esquema de sesiones
    $evento_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    $localidad = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);
    $sesiones  = get_post_meta($evento_id, '_eventosapp_sesiones_internas', true);
    if (is_array($sesiones)) {
      $accesos = [];
      foreach ($sesiones as $s) {
        if (!empty($s['nombre']) && !empty($s['localidades']) && is_array($s['localidades'])) {
          if ($localidad && in_array($localidad, $s['localidades'], true)) $accesos[] = $s['nombre'];
        }
      }
      update_post_meta($ticket_id, '_eventosapp_ticket_sesiones_acceso', $accesos);
    }

    // Reindexar
    if (function_exists('eventosapp_ticket_build_search_blob')) {
      eventosapp_ticket_build_search_blob($ticket_id);
    }
  }
}

