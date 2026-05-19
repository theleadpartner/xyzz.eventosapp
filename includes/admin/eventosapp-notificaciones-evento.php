<?php
// includes/admin/eventosapp-notificaciones-evento.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Metabox "Notificaciones a Productor/Operador" para CPT eventosapp_event
 * - Campos: productor/operador (nombre + email), checkbox auto-notificar al CREAR, selector tipo (Creación/Edición)
 * - Envío automático en primera publicación (si checkbox + correos válidos)
 * - Envío manual ilimitado por botón (AJAX)
 * - En EDICIÓN, resalta en rojo los campos cambiados respecto a la última edición (snapshot)
 *
 * Metas usadas:
 *   _evapp_prod_nombre, _evapp_prod_email
 *   _evapp_oper_nombre, _evapp_oper_email
 *   _evapp_notif_on_create       ('1'|'0')
 *   _evapp_notif_tipo            ('creacion'|'edicion')  // preferencia por defecto del botón manual
 *   _evapp_snapshot_prev         (json)
 *   _evapp_snapshot_last         (json)
 *   _evapp_notified_on_create    (datetime|string)       // flag para evitar duplicados
 */

// === 1) Metabox ===
add_action('add_meta_boxes', function(){
    add_meta_box(
        'evapp_metabox_notif_evento',
        'Notificaciones a Productor/Operador',
        'evapp_render_metabox_notif_evento',
        'eventosapp_event',
        'side',
        'default'
    );
}, 20);

function evapp_render_metabox_notif_evento($post){
    $prod_nombre = get_post_meta($post->ID, '_evapp_prod_nombre', true);
    $prod_email  = get_post_meta($post->ID, '_evapp_prod_email',  true);
    $oper_nombre = get_post_meta($post->ID, '_evapp_oper_nombre', true);
    $oper_email  = get_post_meta($post->ID, '_evapp_oper_email',  true);

    $notif_on_create = get_post_meta($post->ID, '_evapp_notif_on_create', true) === '1' ? '1' : '0';
    $tipo_default    = get_post_meta($post->ID, '_evapp_notif_tipo', true) ?: 'creacion';

    wp_nonce_field('evapp_notif_meta', 'evapp_notif_nonce');
    ?>
    <style>
      .evapp-mb small{color:#666;display:block;margin-top:4px}
      .evapp-mb input[type="text"], .evapp-mb input[type="email"], .evapp-mb select{width:100%}
      .evapp-inline { display:flex; gap:6px; align-items:center; }
      .evapp-inline select{flex:1}
      .evapp-btn{width:100%; margin-top:6px}
      .evapp-note{font-size:12px;color:#666;margin-top:6px}
      .evapp-ok{color:#007cba}
      .evapp-err{color:#B00020}
    </style>

    <div class="evapp-mb">
      <label><strong>Productor (Nombre)</strong></label>
      <input type="text" name="evapp_prod_nombre" value="<?php echo esc_attr($prod_nombre); ?>">

      <label style="margin-top:6px;"><strong>Productor (Email)</strong></label>
      <input type="email" name="evapp_prod_email" value="<?php echo esc_attr($prod_email); ?>">
      <small>Usado como destinatario.</small>

      <hr>

      <label><strong>Operador (Nombre)</strong></label>
      <input type="text" name="evapp_oper_nombre" value="<?php echo esc_attr($oper_nombre); ?>">

      <label style="margin-top:6px;"><strong>Operador (Email)</strong></label>
      <input type="email" name="evapp_oper_email" value="<?php echo esc_attr($oper_email); ?>">
      <small>Usado como destinatario.</small>

      <hr>

      <label class="evapp-inline" style="margin:6px 0;">
        <input type="checkbox" name="evapp_notif_on_create" value="1" <?php checked($notif_on_create, '1'); ?>>
        <span><strong>Notificar al Productor y Operador de la creación del evento</strong></span>
      </label>
      <small>Solo aplica al publicar por primera vez. Si faltan correos, no envía ni muestra error.</small>

      <hr>

      <label><strong>Tipo de notificación (para envío manual)</strong></label>
      <select name="evapp_notif_tipo" id="evapp_notif_tipo">
        <option value="creacion" <?php selected($tipo_default,'creacion'); ?>>Creación</option>
        <option value="edicion"  <?php selected($tipo_default,'edicion');  ?>>Edición</option>
      </select>

      <button type="button" class="button button-primary evapp-btn" id="evapp_send_now"
              data-post="<?php echo esc_attr($post->ID); ?>">Enviar notificación ahora</button>
      <div id="evapp_send_result" class="evapp-note"></div>

      <small class="evapp-note">
        En <em>Edición</em>, se resaltan en <span style="color:#c00;font-weight:600;">rojo</span> los campos cambiados
        en la última actualización. Si nada cambió, todo va en negro.
      </small>
    </div>

    <script>
    (function($){
      $('#evapp_send_now').on('click', function(){
        var $btn = $(this), postId = $btn.data('post'), tipo = $('#evapp_notif_tipo').val();
        var $res = $('#evapp_send_result');
        $res.removeClass('evapp-ok evapp-err').text('Enviando...');

        $.post(ajaxurl, {
          action: 'evapp_send_event_notify',
          _ajax_nonce: '<?php echo wp_create_nonce('evapp_send_now'); ?>',
          post_id: postId,
          tipo: tipo
        }, function(r){
          if (r && r.success) {
            $res.addClass('evapp-ok').text(r.data && r.data.msg ? r.data.msg : 'Enviado.');
          } else {
            var m = (r && r.data && r.data.msg) ? r.data.msg : 'No se pudo enviar.';
            $res.addClass('evapp-err').text(m);
          }
        });
      });
    })(jQuery);
    </script>
    <?php
}


// === 2) Guardar metadatos (campos + preferencia de tipo) ===
add_action('save_post_eventosapp_event', function($post_id, $post){
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( $post->post_type !== 'eventosapp_event' ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;
    if ( ! isset($_POST['evapp_notif_nonce']) || ! wp_verify_nonce($_POST['evapp_notif_nonce'], 'evapp_notif_meta') ) return;

    // Campos
    update_post_meta($post_id, '_evapp_prod_nombre', sanitize_text_field($_POST['evapp_prod_nombre'] ?? ''));
    update_post_meta($post_id, '_evapp_prod_email',  sanitize_email($_POST['evapp_prod_email']  ?? ''));

    update_post_meta($post_id, '_evapp_oper_nombre', sanitize_text_field($_POST['evapp_oper_nombre'] ?? ''));
    update_post_meta($post_id, '_evapp_oper_email',  sanitize_email($_POST['evapp_oper_email']  ?? ''));

    // Checkbox auto-notificar en creación
    $on_create = isset($_POST['evapp_notif_on_create']) ? '1' : '0';
    update_post_meta($post_id, '_evapp_notif_on_create', $on_create);

    // Preferencia de tipo para el botón manual
    $tipo = ( $_POST['evapp_notif_tipo'] ?? 'creacion' );
    $tipo = in_array($tipo, ['creacion','edicion'], true) ? $tipo : 'creacion';
    update_post_meta($post_id, '_evapp_notif_tipo', $tipo);

}, 10, 2);


// === 3) Snapshot de datos relevantes para detectar cambios en "Edición" ===
//     Guardamos SIEMPRE al final del ciclo de guardado para que refleje el último estado.
add_action('save_post_eventosapp_event', function($post_id, $post){
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( $post->post_type !== 'eventosapp_event' ) return;
    if ( wp_is_post_revision($post_id) ) return;

    $curr = evapp_collect_snapshot($post_id);
    $last = evapp_get_json_meta($post_id, '_evapp_snapshot_last');

    if ( $last && is_array($last) && $last !== $curr ) {
        update_post_meta($post_id, '_evapp_snapshot_prev', wp_json_encode($last));
    }
    update_post_meta($post_id, '_evapp_snapshot_last', wp_json_encode($curr));

}, 95, 2);


// === 4) Envío automático en PRIMERA publicación (si checkbox + correos) ===
// Nota: transition_post_status ocurre antes de que se guarden todas las metas; puede no encontrar emails/checkbox.
// Aun así lo mantenemos, pero ahora verificamos flag de idempotencia para evitar duplicados si llegara a enviar.
add_action('transition_post_status', function($new_status, $old_status, $post){
    if ( $post->post_type !== 'eventosapp_event' ) return;

    // Solo primera publicación
    if ( $old_status === 'publish' || $new_status !== 'publish' ) return;

    $post_id = $post->ID;

    // Si ya se notificó por otro flujo, salimos
    if ( get_post_meta($post_id, '_evapp_notified_on_create', true) ) return;

    $auto = get_post_meta($post_id, '_evapp_notif_on_create', true) === '1';
    if ( ! $auto ) return;

    // Requisitos: al menos un correo válido
    $to = evapp_collect_recipients($post_id);
    if ( empty($to) ) return;

    $ok = evapp_send_event_notification($post_id, 'creacion', false);
    if ( $ok ) {
        update_post_meta($post_id, '_evapp_notified_on_create', current_time('mysql'));
        if ( defined('EVENTOSAPP_DEBUG_MAIL') && EVENTOSAPP_DEBUG_MAIL ) {
            error_log('[EventosApp] Auto-notif creación enviada en transition_post_status: post_id='.$post_id);
        }
    }
}, 10, 3);


// === 4-bis) Envío automático al CREAR (después de guardar metas) ===
// Motivo: transition_post_status corre antes de save_post (metas pueden no existir). Aquí garantizamos metas listas.
// Enviamos solo una vez cuando está en publish, el checkbox está activo y hay correos válidos.
add_action('save_post_eventosapp_event', function($post_id, $post, $update){
    // Evitar autosaves / revisiones
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) ) return;

    // Solo si el evento ya quedó publicado
    if ( get_post_status($post_id) !== 'publish' ) return;

    // Ya notificado? (idempotencia)
    if ( get_post_meta($post_id, '_evapp_notified_on_create', true) ) return;

    // Checkbox debe estar activo
    $auto = get_post_meta($post_id, '_evapp_notif_on_create', true) === '1';
    if ( ! $auto ) return;

    // Debe existir al menos un destinatario válido
    $to = evapp_collect_recipients($post_id);
    if ( empty($to) ) return;

    // Enviar como "Creación"
    $ok = evapp_send_event_notification($post_id, 'creacion', false);

    if ( $ok ) {
        update_post_meta($post_id, '_evapp_notified_on_create', current_time('mysql'));

        // Log opcional de depuración
        if ( defined('EVENTOSAPP_DEBUG_MAIL') && EVENTOSAPP_DEBUG_MAIL ) {
            error_log('[EventosApp] Auto-notif creación enviada en save_post: post_id='.$post_id);
        }
    } else {
        if ( defined('EVENTOSAPP_DEBUG_MAIL') && EVENTOSAPP_DEBUG_MAIL ) {
            error_log('[EventosApp] Auto-notif creación FALLÓ en save_post: post_id='.$post_id);
        }
    }
}, 140, 3);


// === 5) AJAX Envío manual ===
add_action('wp_ajax_evapp_send_event_notify', function(){
    if ( ! current_user_can('edit_posts') ) {
        wp_send_json_error(['msg'=>'Permisos insuficientes.']);
    }
    check_ajax_referer('evapp_send_now');

    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    $tipo    = isset($_POST['tipo']) ? sanitize_text_field($_POST['tipo']) : 'creacion';
    if ( ! in_array($tipo, ['creacion','edicion'], true) ) $tipo = 'creacion';

    if ( ! $post_id || get_post_type($post_id) !== 'eventosapp_event' ) {
        wp_send_json_error(['msg'=>'Evento inválido.']);
    }

    $to = evapp_collect_recipients($post_id);
    if ( empty($to) ) {
        wp_send_json_error(['msg'=>'No hay correos válidos (Productor/Operador).']);
    }

    $ok = evapp_send_event_notification($post_id, $tipo, true);

    if ( $ok ) {
        wp_send_json_success(['msg'=>'Notificación enviada.']);
    } else {
        wp_send_json_error(['msg'=>'No se pudo enviar el correo. Revisa el log si tienes EVENTOSAPP_DEBUG_MAIL.']);
    }
});


// === Helpers: snapshots, diff, destinatarios, formato y envío ===

function evapp_collect_snapshot($post_id){
    // Fechas
    $tipo      = get_post_meta($post_id, '_eventosapp_tipo_fecha', true) ?: 'unica';
    $f_unica   = get_post_meta($post_id, '_eventosapp_fecha_unica', true) ?: '';
    $f_ini     = get_post_meta($post_id, '_eventosapp_fecha_inicio', true) ?: '';
    $f_fin     = get_post_meta($post_id, '_eventosapp_fecha_fin', true) ?: '';
    $f_noco    = get_post_meta($post_id, '_eventosapp_fechas_noco', true);
    $f_noco    = is_array($f_noco) ? array_values(array_filter($f_noco)) : [];

    // Horarios y zona
    $h_ini     = get_post_meta($post_id, '_eventosapp_hora_inicio', true) ?: '';
    $h_fin     = get_post_meta($post_id, '_eventosapp_hora_cierre', true) ?: '';
    $tz        = get_post_meta($post_id, '_eventosapp_zona_horaria', true) ?: '';

    // Modalidad / acceso virtual
    $modalidad       = function_exists('eventosapp_get_event_modalidad') ? eventosapp_get_event_modalidad($post_id) : (get_post_meta($post_id, '_eventosapp_event_modalidad', true) ?: 'presencial');
    $modalidad_label = function_exists('eventosapp_get_event_modalidad_label') ? eventosapp_get_event_modalidad_label($post_id) : ucfirst(str_replace('_', ' y ', $modalidad));
    $virtual_platform = get_post_meta($post_id, '_eventosapp_virtual_platform', true) ?: '';
    $virtual_access   = get_post_meta($post_id, '_eventosapp_virtual_access_datetime', true) ?: '';
    $virtual_url      = get_post_meta($post_id, '_eventosapp_virtual_url', true) ?: '';

    // Lugar / contacto
    $dir       = get_post_meta($post_id, '_eventosapp_direccion', true) ?: '';
    $org       = function_exists('eventosapp_get_nombre_organizador') ? eventosapp_get_nombre_organizador($post_id) : (get_post_meta($post_id, '_eventosapp_organizador', true) ?: '');

    // Productor / Operador (nombres; los correos no se resaltan)
    $prod_n    = get_post_meta($post_id, '_evapp_prod_nombre', true) ?: '';
    $oper_n    = get_post_meta($post_id, '_evapp_oper_nombre', true) ?: '';

    return [
        'tipo_fecha'   => $tipo,
        'fecha_unica'  => $f_unica,
        'fecha_inicio' => $f_ini,
        'fecha_fin'    => $f_fin,
        'fechas_noco'  => $f_noco,
        'hora_inicio'  => $h_ini,
        'hora_cierre'  => $h_fin,
        'zona_horaria'     => $tz,
        'modalidad'       => $modalidad,
        'modalidad_label' => $modalidad_label,
        'virtual_platform'=> $virtual_platform,
        'virtual_access'  => $virtual_access,
        'virtual_url'     => $virtual_url,
        'direccion'       => $dir,
        'organizador'     => $org,
        'productor'    => $prod_n,
        'operador'     => $oper_n,
    ];
}

function evapp_get_json_meta($post_id, $key){
    $raw = get_post_meta($post_id, $key, true);
    if ( ! $raw ) return [];
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
}

function evapp_diff_keys_last_edit($post_id){
    $prev = evapp_get_json_meta($post_id, '_evapp_snapshot_prev');
    $last = evapp_get_json_meta($post_id, '_evapp_snapshot_last');
    if ( empty($prev) || empty($last) ) return [];

    $changed = [];
    $keys = array_unique(array_merge(array_keys($prev), array_keys($last)));
    foreach ($keys as $k){
        $a = $prev[$k] ?? null;
        $b = $last[$k] ?? null;
        if ( $k === 'fechas_noco' ) {
            // comparar arrays
            if ( json_encode(array_values($a)) !== json_encode(array_values($b)) ) $changed[] = $k;
        } else {
            if ( $a !== $b ) $changed[] = $k;
        }
    }
    return $changed;
}

function evapp_collect_recipients($post_id){
    $to = [];
    $prod = get_post_meta($post_id, '_evapp_prod_email', true);
    $oper = get_post_meta($post_id, '_evapp_oper_email', true);
    if ( $prod && is_email($prod) ) $to[] = $prod;
    if ( $oper && is_email($oper) ) $to[] = $oper;
    return array_values(array_unique($to));
}

function evapp_format_fechas_humano($post_id){
    $s = evapp_collect_snapshot($post_id);

    switch ($s['tipo_fecha']) {
        case 'unica':
            return $s['fecha_unica'] ? esc_html($s['fecha_unica']) : '-';
        case 'consecutiva':
            $a = $s['fecha_inicio'] ?: '';
            $b = $s['fecha_fin']    ?: '';
            if ($a && $b) return esc_html($a . ' — ' . $b);
            if ($a) return esc_html($a);
            if ($b) return esc_html($b);
            return '-';
        case 'noconsecutiva':
            if ( ! empty($s['fechas_noco']) ) {
                return esc_html(implode(', ', $s['fechas_noco']));
            }
            return '-';
        default:
            return '-';
    }
}

function evapp_build_email_html($post_id, $tipo = 'creacion', $changed_keys = []){
    $post = get_post($post_id);
    $snap = evapp_collect_snapshot($post_id);

    $fechas   = evapp_format_fechas_humano($post_id);
    $h_ini    = $snap['hora_inicio'] ?: '-';
    $h_fin    = $snap['hora_cierre'] ?: '-';
    $tz       = $snap['zona_horaria'] ?: '-';
    $modalidad = $snap['modalidad_label'] ?: '-';
    $virtual_platform = $snap['virtual_platform'] ?: '-';
    $virtual_access   = $snap['virtual_access'] ?: '-';
    $virtual_url      = $snap['virtual_url'] ?: '-';
    $dir      = $snap['direccion'] ?: '-';
    $org      = $snap['organizador'] ?: '-';
    $prod_n   = $snap['productor'] ?: '-';
    $oper_n   = $snap['operador'] ?: '-';
    $event_id = $post_id;

    $c = function($key, $val) use ($changed_keys){
        $v = esc_html($val);
        if ( in_array($key, $changed_keys, true) ) {
            return '<span style="color:#c00;font-weight:600;">'.$v.'</span>';
        }
        return $v;
    };

    // Mapeo de claves snapshot -> fila
    $rows = [
        ['label' => 'Fecha(s) del evento', 'key' => 'fechas',       'val' => $fechas,
            // 'fechas' se considera cambiada si cambió cualquiera de: tipo_fecha | fecha_unica | fecha_inicio | fecha_fin | fechas_noco
            'is_changed' => array_intersect($changed_keys, ['tipo_fecha','fecha_unica','fecha_inicio','fecha_fin','fechas_noco']) ? true : false
        ],
        ['label' => 'Hora de inicio',      'key' => 'hora_inicio',  'val' => $h_ini, 'is_changed' => in_array('hora_inicio', $changed_keys, true)],
        ['label' => 'Hora de cierre',      'key' => 'hora_cierre',  'val' => $h_fin, 'is_changed' => in_array('hora_cierre', $changed_keys, true)],
        ['label' => 'Zona horaria',        'key' => 'zona_horaria', 'val' => $tz,    'is_changed' => in_array('zona_horaria', $changed_keys, true)],
        ['label' => 'Modalidad',           'key' => 'modalidad',    'val' => $modalidad, 'is_changed' => in_array('modalidad', $changed_keys, true)],
        ['label' => 'Dirección',           'key' => 'direccion',    'val' => $dir,   'is_changed' => in_array('direccion', $changed_keys, true)],
        ['label' => 'Plataforma virtual',  'key' => 'virtual_platform', 'val' => $virtual_platform, 'is_changed' => in_array('virtual_platform', $changed_keys, true)],
        ['label' => 'Acceso virtual desde','key' => 'virtual_access','val' => $virtual_access, 'is_changed' => in_array('virtual_access', $changed_keys, true)],
        ['label' => 'Enlace virtual',      'key' => 'virtual_url',   'val' => $virtual_url, 'is_changed' => in_array('virtual_url', $changed_keys, true)],
        ['label' => 'Organizador',         'key' => 'organizador',  'val' => $org,   'is_changed' => in_array('organizador', $changed_keys, true)],
        ['label' => 'Productor',           'key' => 'productor',    'val' => $prod_n,'is_changed' => in_array('productor', $changed_keys, true)],
        ['label' => 'Operador',            'key' => 'operador',     'val' => $oper_n,'is_changed' => in_array('operador', $changed_keys, true)],
        ['label' => 'EVENT ID',            'key' => 'event_id',     'val' => $event_id, 'is_changed' => false],
    ];

    ob_start();
    ?>
    <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;line-height:1.4;color:#222;">
      <h2 style="margin:0 0 10px;">
        <?php echo esc_html(get_bloginfo('name')); ?>
      </h2>
      <p style="margin:6px 0 14px;">
        <?php if ($tipo === 'edicion'): ?>
          Se ha <strong>EDITADO</strong> el evento <strong><?php echo esc_html(get_the_title($post)); ?></strong>.
        <?php else: ?>
          Se ha <strong>CREADO</strong> el evento <strong><?php echo esc_html(get_the_title($post)); ?></strong>.
        <?php endif; ?>
      </p>

      <table role="presentation" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%;max-width:640px;border:1px solid #e2e2e2;">
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td style="background:#f7f7f7;border-bottom:1px solid #eee;width:42%;"><strong><?php echo esc_html($r['label']); ?></strong></td>
              <td style="border-bottom:1px solid #eee;">
                <?php
                  if ($tipo === 'edicion' && $r['is_changed']) {
                      echo '<span style="color:#c00;font-weight:600;">'.wp_kses_post($c($r['key'], $r['val'])).'</span>';
                  } else {
                      echo wp_kses_post($c($r['key'], $r['val']));
                  }
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <p style="margin-top:14px;font-size:12px;color:#666;">
        Mensaje enviado por EventosApp.
      </p>
    </div>
    <?php
    return trim(ob_get_clean());
}

function evapp_send_event_notification($post_id, $tipo = 'creacion', $manual = false){
    $post = get_post($post_id);
    if ( ! $post || $post->post_type !== 'eventosapp_event' ) return false;

    $to = evapp_collect_recipients($post_id);
    if ( empty($to) ) return false;

    // Detectar cambios para "Edición"
    $changed = ($tipo === 'edicion') ? evapp_diff_keys_last_edit($post_id) : [];

    // Asunto SIN corchetes alrededor del nombre del evento
    $subject = sprintf(
        'EventosApp: Se ha %s el Evento %s',
        ($tipo === 'edicion' ? 'EDITADO' : 'CREADO'),
        get_the_title($post) // ← sin [ ]
    );

    $message = evapp_build_email_html($post_id, $tipo, $changed);

    // Remitente FORZADO para este metabox: EVENTOSAPP (independiente de otros metabox)
    $from_name  = 'EVENTOSAPP';
    $from_email = get_option('admin_email');

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from_name . ' <' . $from_email . '>',
    ];

    // Enviar
    $sent = wp_mail($to, $subject, $message, $headers);

    // Log opcional
    if ( defined('EVENTOSAPP_DEBUG_MAIL') && EVENTOSAPP_DEBUG_MAIL ) {
        error_log('[EventosApp] evapp_send_event_notification '.($sent?'OK':'FAIL')." post_id=$post_id tipo=$tipo to=".implode(',', $to));
    }

    return (bool) $sent;
}

/**
 * Compatibilidad de segmentación por modalidad para recordatorios/notificaciones de tickets.
 *
 * Este archivo no contiene el motor cerrado del metabox "Recordatorio de Ticket", pero deja
 * expuesto el criterio "Modalidad" para los módulos de notificaciones que consumen filtros
 * y agrega un fallback visual no destructivo en la pantalla de edición del evento.
 */
if ( ! function_exists('evapp_notif_modalidad_filter_options') ) {
    function evapp_notif_modalidad_filter_options($fields) {
        if (!is_array($fields)) $fields = [];

        $modalidad_options = [
            'presencial' => 'Presencial',
            'virtual'    => 'Virtual',
        ];

        if (function_exists('eventosapp_ticket_modalidad_options')) {
            $maybe_options = eventosapp_ticket_modalidad_options();
            if (is_array($maybe_options) && !empty($maybe_options)) {
                $modalidad_options = $maybe_options;
            }
        }

        // Caso 1: arreglo asociativo tipo ['localidad' => 'Localidad'].
        if (array_keys($fields) !== range(0, count($fields) - 1)) {
            if (!isset($fields['modalidad'])) {
                $fields['modalidad'] = 'Modalidad';
            }
            return $fields;
        }

        // Caso 2: arreglo de definiciones tipo [['key'=>'localidad','label'=>'Localidad']].
        foreach ($fields as $field) {
            if (is_array($field)) {
                $key = isset($field['key']) ? (string) $field['key'] : (isset($field['value']) ? (string) $field['value'] : '');
                if ($key === 'modalidad') return $fields;
            } elseif ((string) $field === 'modalidad') {
                return $fields;
            }
        }

        $fields[] = [
            'key'     => 'modalidad',
            'value'   => 'modalidad',
            'label'   => 'Modalidad',
            'type'    => 'select',
            'options' => $modalidad_options,
        ];

        return $fields;
    }
}

if ( ! function_exists('evapp_notif_ticket_filter_value') ) {
    function evapp_notif_ticket_filter_value($value, $field = '', $ticket_id = 0, $context = []) {
        if ($field !== 'modalidad') return $value;

        $ticket_id = absint($ticket_id);
        if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
            return '';
        }

        if (function_exists('eventosapp_get_ticket_modalidad')) {
            return eventosapp_get_ticket_modalidad($ticket_id);
        }

        $modalidad = get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);
        if (function_exists('eventosapp_normalize_ticket_modalidad')) {
            return eventosapp_normalize_ticket_modalidad($modalidad);
        }

        return in_array($modalidad, ['presencial', 'virtual'], true) ? $modalidad : 'presencial';
    }
}

if ( ! function_exists('evapp_notif_ticket_matches_modalidad_filter') ) {
    function evapp_notif_ticket_matches_modalidad_filter($matches, $ticket_id, $operator, $expected_value) {
        $ticket_id = absint($ticket_id);
        if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') return false;

        $current = evapp_notif_ticket_filter_value('', 'modalidad', $ticket_id, []);
        $expected = function_exists('eventosapp_normalize_ticket_modalidad')
            ? eventosapp_normalize_ticket_modalidad($expected_value)
            : sanitize_key((string) $expected_value);
        $operator = sanitize_key((string) $operator);

        if ($operator === 'not_equals' || $operator === '!=' || $operator === 'not') {
            return $current !== $expected;
        }

        return $current === $expected;
    }
}

foreach ([
    'eventosapp_ticket_notification_filter_fields',
    'eventosapp_email_reminder_filter_fields',
    'eventosapp_reminder_segment_filter_fields',
    'eventosapp_mass_email_filter_fields',
    'eventosapp_notification_segment_fields',
] as $evapp_modalidad_filter_hook) {
    add_filter($evapp_modalidad_filter_hook, 'evapp_notif_modalidad_filter_options', 10, 1);
}

foreach ([
    'eventosapp_ticket_notification_filter_value',
    'eventosapp_email_reminder_filter_value',
    'eventosapp_reminder_segment_filter_value',
    'eventosapp_mass_email_filter_value',
    'eventosapp_notification_segment_value',
] as $evapp_modalidad_value_hook) {
    add_filter($evapp_modalidad_value_hook, 'evapp_notif_ticket_filter_value', 10, 4);
}

foreach ([
    'eventosapp_ticket_notification_filter_match_modalidad',
    'eventosapp_email_reminder_filter_match_modalidad',
    'eventosapp_reminder_segment_filter_match_modalidad',
    'eventosapp_mass_email_filter_match_modalidad',
] as $evapp_modalidad_match_hook) {
    add_filter($evapp_modalidad_match_hook, 'evapp_notif_ticket_matches_modalidad_filter', 10, 4);
}

add_action('admin_footer-post.php', 'evapp_notif_modalidad_admin_segment_fallback');
add_action('admin_footer-post-new.php', 'evapp_notif_modalidad_admin_segment_fallback');

if ( ! function_exists('evapp_notif_modalidad_admin_segment_fallback') ) {
    function evapp_notif_modalidad_admin_segment_fallback() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'eventosapp_event') return;
        ?>
        <script>
        (function($){
            function evappAddModalidadOption(){
                $('select').each(function(){
                    var $select = $(this);
                    if ($select.find('option[value="modalidad"]').length) return;

                    var labels = $select.find('option').map(function(){ return String($(this).text() || '').toLowerCase(); }).get().join(' | ');
                    var values = $select.find('option').map(function(){ return String($(this).val() || '').toLowerCase(); }).get().join(' | ');
                    var looksLikeSegmentField =
                        labels.indexOf('localidad') !== -1 ||
                        labels.indexOf('estado de envío del correo') !== -1 ||
                        labels.indexOf('estado de envio del correo') !== -1 ||
                        values.indexOf('localidad') !== -1 ||
                        values.indexOf('email') !== -1 ||
                        values.indexOf('correo') !== -1;

                    if (looksLikeSegmentField) {
                        $select.append($('<option/>', { value: 'modalidad', text: 'Modalidad' }));
                    }
                });
            }

            $(document).on('ready', evappAddModalidadOption);
            $(document).on('click', '.button, button, a', function(){ setTimeout(evappAddModalidadOption, 250); });
            $(document).on('change', 'select', function(){ setTimeout(evappAddModalidadOption, 250); });
            setTimeout(evappAddModalidadOption, 700);
        })(jQuery);
        </script>
        <?php
    }
}

/**
 * Integración: recordatorio de ticket por WhatsApp sincronizado con el recordatorio de correo.
 *
 * Esta capa es intencionalmente defensiva: el motor del recordatorio de correo puede vivir
 * en otro archivo. Por eso detecta el horario guardado en metas conocidas, expone una UI propia
 * y también permite que el módulo de WhatsApp delegue aquí los envíos que provengan de recordatorios.
 */
if ( ! function_exists('evapp_whatsapp_reminder_is_enabled_for_event') ) {
    function evapp_whatsapp_reminder_is_enabled_for_event($event_id) {
        $event_id = absint($event_id);
        if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
            return false;
        }

        return get_post_meta($event_id, '_eventosapp_whatsapp_reminder_enabled', true) === '1';
    }
}

if ( ! function_exists('evapp_whatsapp_email_reminder_is_enabled_for_ticket') ) {
    function evapp_whatsapp_email_reminder_is_enabled_for_ticket($ticket_id) {
        $ticket_id = absint($ticket_id);
        if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
            return false;
        }

        $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        return evapp_whatsapp_reminder_is_enabled_for_event($event_id);
    }
}

add_action('add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_whatsapp_email_reminder_bridge',
        'WhatsApp para Recordatorio de Ticket',
        'evapp_render_whatsapp_email_reminder_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
}, 45);

if ( ! function_exists('evapp_whatsapp_reminder_get_rule_fields') ) {
    function evapp_whatsapp_reminder_get_rule_fields($event_id = 0) {
        if ( function_exists('eventosapp_whatsapp_get_rule_fields') ) {
            $fields = eventosapp_whatsapp_get_rule_fields($event_id);
            return is_array($fields) ? $fields : [];
        }

        return [
            'nombre'           => 'Nombre',
            'apellido'         => 'Apellido',
            'cedula'           => 'Cédula',
            'email'            => 'Correo electrónico',
            'telefono'         => 'Celular',
            'empresa'          => 'Empresa',
            'nit'              => 'NIT',
            'cargo'            => 'Cargo',
            'ciudad'           => 'Ciudad',
            'pais'             => 'País',
            'localidad'        => 'Localidad',
            'modalidad'        => 'Modalidad del ticket',
            'creation_channel' => 'Canal de creación del ticket',
            'estado_pago'      => 'Estado de pago',
        ];
    }
}

if ( ! function_exists('evapp_whatsapp_reminder_get_rule_operators') ) {
    function evapp_whatsapp_reminder_get_rule_operators() {
        if ( function_exists('eventosapp_whatsapp_get_rule_operators') ) {
            $operators = eventosapp_whatsapp_get_rule_operators();
            return is_array($operators) ? $operators : [];
        }

        return [
            'equals'       => 'Es igual a',
            'not_equals'   => 'No es igual a',
            'contains'     => 'Contiene',
            'not_contains' => 'No contiene',
            'starts_with'  => 'Empieza por',
            'ends_with'    => 'Termina en',
            'empty'        => 'Está vacío',
            'not_empty'    => 'No está vacío',
        ];
    }
}

if ( ! function_exists('evapp_whatsapp_reminder_normalize_rules') ) {
    function evapp_whatsapp_reminder_normalize_rules($rules) {
        if ( function_exists('eventosapp_whatsapp_normalize_rules') ) {
            return eventosapp_whatsapp_normalize_rules($rules);
        }

        if ( ! is_array($rules) ) {
            return [];
        }

        $operators = evapp_whatsapp_reminder_get_rule_operators();
        $clean = [];

        foreach ( $rules as $rule ) {
            if ( ! is_array($rule) ) {
                continue;
            }

            $conditions = [];
            if ( ! empty($rule['conditions']) && is_array($rule['conditions']) ) {
                foreach ( $rule['conditions'] as $condition ) {
                    if ( ! is_array($condition) ) {
                        continue;
                    }

                    $field = isset($condition['field']) ? sanitize_text_field(wp_unslash($condition['field'])) : '';
                    $operator = isset($condition['operator']) ? sanitize_key(wp_unslash($condition['operator'])) : 'equals';
                    $value = isset($condition['value']) ? sanitize_text_field(wp_unslash($condition['value'])) : '';

                    if ( $field === '' ) {
                        continue;
                    }

                    if ( ! array_key_exists($operator, $operators) ) {
                        $operator = 'equals';
                    }

                    $conditions[] = [
                        'field'    => $field,
                        'operator' => $operator,
                        'value'    => $value,
                    ];
                }
            }

            $action = isset($rule['action']) ? sanitize_key(wp_unslash($rule['action'])) : 'allow';
            if ( ! in_array($action, ['allow', 'deny'], true) ) {
                $action = 'allow';
            }

            $match = isset($rule['match']) ? sanitize_key(wp_unslash($rule['match'])) : 'all';
            if ( ! in_array($match, ['all', 'any'], true) ) {
                $match = 'all';
            }

            $clean[] = [
                'enabled'    => isset($rule['enabled']) ? '1' : '0',
                'name'       => isset($rule['name']) ? sanitize_text_field(wp_unslash($rule['name'])) : '',
                'action'     => $action,
                'match'      => $match,
                'conditions' => $conditions,
            ];
        }

        return $clean;
    }
}

if ( ! function_exists('evapp_render_whatsapp_email_reminder_metabox') ) {
    function evapp_render_whatsapp_email_reminder_metabox($post) {
        $enabled = get_post_meta($post->ID, '_eventosapp_whatsapp_reminder_enabled', true) === '1';
        $respect_rules = get_post_meta($post->ID, '_eventosapp_whatsapp_reminder_respect_rules', true);
        $respect_rules = $respect_rules === '' ? '1' : ($respect_rules === '0' ? '0' : '1');
        $rules = evapp_whatsapp_reminder_normalize_rules(get_post_meta($post->ID, '_eventosapp_whatsapp_reminder_rules', true));
        $fields = evapp_whatsapp_reminder_get_rule_fields($post->ID);
        $operators = evapp_whatsapp_reminder_get_rule_operators();
        $email_schedule = evapp_whatsapp_reminder_get_email_schedule($post->ID);
        $scheduled_at = absint(get_post_meta($post->ID, '_eventosapp_whatsapp_reminder_scheduled_at', true));
        $scheduled_source = get_post_meta($post->ID, '_eventosapp_whatsapp_reminder_schedule_source', true);
        $schedule_status = get_post_meta($post->ID, '_eventosapp_whatsapp_reminder_schedule_status', true);
        $next_cron = wp_next_scheduled('eventosapp_whatsapp_email_reminder_send', [$post->ID]);
        $whatsapp_event_enabled = get_post_meta($post->ID, '_eventosapp_ticket_whatsapp_enabled', true) === '1';
        $log = get_post_meta($post->ID, '_eventosapp_whatsapp_reminder_log', true);
        $log = is_array($log) ? array_slice(array_reverse($log), 0, 5) : [];

        wp_nonce_field('evapp_whatsapp_email_reminder_save', 'evapp_whatsapp_email_reminder_nonce');
        ?>
        <style>
            .evapp-wa-reminder-box{border:1px solid #dcdcde;background:#fff;border-radius:8px;padding:14px;margin:10px 0;}
            .evapp-wa-reminder-alert{padding:10px 12px;border-radius:6px;margin:10px 0;font-size:13px;line-height:1.45;}
            .evapp-wa-reminder-alert.warn{background:#fff8e5;border:1px solid #f0c36d;color:#665200;}
            .evapp-wa-reminder-alert.ok{background:#edfaef;border:1px solid #8bd18f;color:#145a20;}
            .evapp-wa-reminder-alert.info{background:#f0f6fc;border:1px solid #9ec5fe;color:#084298;}
            .evapp-wa-reminder-grid{display:grid;grid-template-columns:minmax(220px,1fr) minmax(220px,1fr);gap:12px;align-items:start;}
            .evapp-wa-reminder-card{background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:12px;}
            .evapp-wa-reminder-card h4{margin:0 0 8px;}
            .evapp-wa-reminder-small{font-size:12px;color:#646970;line-height:1.45;}
            .evapp-wa-reminder-rule{border:1px solid #ccd0d4;border-left:4px solid #25D366;border-radius:8px;background:#fafafa;margin:14px 0;padding:14px;}
            .evapp-wa-reminder-rule-head{display:grid;grid-template-columns:90px 1fr 150px 150px auto;gap:10px;align-items:center;margin-bottom:12px;}
            .evapp-wa-reminder-rule-head input[type="text"],.evapp-wa-reminder-rule-head select{width:100%;}
            .evapp-wa-reminder-conditions table{width:100%;border-collapse:collapse;background:#fff;}
            .evapp-wa-reminder-conditions th,.evapp-wa-reminder-conditions td{padding:8px;border-bottom:1px solid #eee;text-align:left;vertical-align:middle;}
            .evapp-wa-reminder-conditions select,.evapp-wa-reminder-conditions input{width:100%;}
            .evapp-wa-reminder-empty{padding:12px;background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:6px;}
            .evapp-wa-reminder-log{margin:8px 0 0;padding-left:18px;}
            @media (max-width: 900px){.evapp-wa-reminder-grid{grid-template-columns:1fr}.evapp-wa-reminder-rule-head{grid-template-columns:1fr;}}
        </style>

        <div class="evapp-wa-reminder-box">
            <?php if ( ! $whatsapp_event_enabled ) : ?>
                <div class="evapp-wa-reminder-alert warn">
                    WhatsApp todavía no está activo para este evento. Actívalo en <strong>Funciones Extra del Ticket</strong> para que el recordatorio pueda enviarse.
                </div>
            <?php endif; ?>

            <div class="evapp-wa-reminder-grid">
                <div class="evapp-wa-reminder-card">
                    <h4>Activación</h4>
                    <label style="display:block;margin:8px 0;">
                        <input type="checkbox" name="eventosapp_whatsapp_reminder_enabled" value="1" <?php checked($enabled); ?>>
                        <strong>Enviar recordatorio del ticket por WhatsApp junto con el recordatorio de correo</strong>
                    </label>
                    <p class="evapp-wa-reminder-small">
                        Usa la misma configuración del ticket por WhatsApp: API global, plantilla seleccionada en <strong>Diseño WhatsApp y Landing</strong>, QR, landing pública, Wallet, PDF e ICS cuando aplique.
                    </p>

                    <label style="display:block;margin:12px 0 4px;">
                        <input type="checkbox" name="eventosapp_whatsapp_reminder_respect_rules" value="1" <?php checked($respect_rules, '1'); ?>>
                        <strong>Respetar reglas generales de envío de WhatsApp</strong>
                    </label>
                    <p class="evapp-wa-reminder-small">
                        Si lo desmarcas, el recordatorio ignora las reglas del metabox <strong>WhatsApp Tickets - Reglas de Envío</strong>, pero siempre respeta el filtro específico de recordatorio definido abajo.
                    </p>
                </div>

                <div class="evapp-wa-reminder-card">
                    <h4>Horario tomado del correo</h4>
                    <?php if ( ! empty($email_schedule['timestamp']) ) : ?>
                        <div class="evapp-wa-reminder-alert ok">
                            Horario detectado: <strong><?php echo esc_html(date_i18n('d/m/Y H:i', (int) $email_schedule['timestamp'])); ?></strong><br>
                            Fuente: <code><?php echo esc_html($email_schedule['source']); ?></code>
                        </div>
                    <?php else : ?>
                        <div class="evapp-wa-reminder-alert info">
                            Aún no se detecta un horario guardado para el recordatorio de correo. Cuando guardes el evento con el horario del correo, este módulo intentará programar WhatsApp en ese mismo momento.
                        </div>
                    <?php endif; ?>

                    <p class="evapp-wa-reminder-small">
                        Estado WhatsApp: <strong><?php echo esc_html($schedule_status ?: 'sin estado'); ?></strong>
                    </p>
                    <?php if ( $scheduled_at ) : ?>
                        <p class="evapp-wa-reminder-small">
                            Último horario WhatsApp guardado: <strong><?php echo esc_html(date_i18n('d/m/Y H:i', $scheduled_at)); ?></strong><br>
                            Fuente guardada: <code><?php echo esc_html($scheduled_source ?: '-'); ?></code>
                        </p>
                    <?php endif; ?>
                    <?php if ( $next_cron ) : ?>
                        <p class="evapp-wa-reminder-small">
                            Próximo cron WhatsApp: <strong><?php echo esc_html(date_i18n('d/m/Y H:i', $next_cron)); ?></strong>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <hr>
            <h4>Filtro específico para el recordatorio por WhatsApp</h4>
            <p class="evapp-wa-reminder-small">
                Este filtro funciona igual que el filtro de WhatsApp: las reglas <strong>No enviar</strong> tienen prioridad sobre las reglas <strong>Enviar</strong>. Si no agregas reglas, el recordatorio de WhatsApp aplica a todos los tickets con celular válido, sujeto a las reglas generales si las dejaste activas.
            </p>

            <div id="evapp-wa-reminder-rules-list">
                <?php if ( empty($rules) ) : ?>
                    <p class="evapp-wa-reminder-empty" id="evapp-wa-reminder-no-rules">No hay filtros específicos para el recordatorio. Se usará el alcance general.</p>
                <?php else : ?>
                    <?php foreach ( $rules as $rule_index => $rule ) : ?>
                        <?php evapp_whatsapp_reminder_render_rule_row($rule_index, $rule, $fields, $operators); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <p>
                <button type="button" class="button button-secondary" id="evapp-wa-reminder-add-rule">+ Agregar filtro de recordatorio WhatsApp</button>
            </p>

            <?php if ( ! empty($log) ) : ?>
                <hr>
                <h4>Últimos envíos de recordatorio WhatsApp</h4>
                <ul class="evapp-wa-reminder-log evapp-wa-reminder-small">
                    <?php foreach ( $log as $entry ) : ?>
                        <li>
                            <strong><?php echo esc_html($entry['date'] ?? '-'); ?></strong> —
                            <?php echo esc_html($entry['message'] ?? 'Registro'); ?>
                            <?php if ( isset($entry['total']) ) : ?>
                                · Total: <?php echo absint($entry['total']); ?>,
                                enviados: <?php echo absint($entry['sent'] ?? 0); ?>,
                                omitidos: <?php echo absint($entry['skipped'] ?? 0); ?>,
                                errores: <?php echo absint($entry['errors'] ?? 0); ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <script type="text/html" id="tmpl-evapp-wa-reminder-rule">
            <?php evapp_whatsapp_reminder_render_rule_row('__RULE_INDEX__', [
                'enabled' => '1',
                'name' => '',
                'action' => 'allow',
                'match' => 'all',
                'conditions' => [],
            ], $fields, $operators); ?>
        </script>

        <script type="text/html" id="tmpl-evapp-wa-reminder-condition">
            <?php evapp_whatsapp_reminder_render_condition_row('__RULE_INDEX__', '__COND_INDEX__', [], $fields, $operators); ?>
        </script>

        <script>
        jQuery(function($){
            var ruleIndex = $('#evapp-wa-reminder-rules-list .evapp-wa-reminder-rule').length;

            function replaceAllIndexes(html, ruleIdx, condIdx) {
                html = html.replace(/__RULE_INDEX__/g, ruleIdx);
                if (typeof condIdx !== 'undefined') {
                    html = html.replace(/__COND_INDEX__/g, condIdx);
                }
                return html;
            }

            $('#evapp-wa-reminder-add-rule').on('click', function(){
                $('#evapp-wa-reminder-no-rules').remove();
                var html = $('#tmpl-evapp-wa-reminder-rule').html();
                $('#evapp-wa-reminder-rules-list').append(replaceAllIndexes(html, ruleIndex));
                ruleIndex++;
            });

            $(document).on('click', '.evapp-wa-reminder-remove-rule', function(){
                $(this).closest('.evapp-wa-reminder-rule').remove();
                if ($('#evapp-wa-reminder-rules-list .evapp-wa-reminder-rule').length === 0) {
                    $('#evapp-wa-reminder-rules-list').append('<p class="evapp-wa-reminder-empty" id="evapp-wa-reminder-no-rules">No hay filtros específicos para el recordatorio. Se usará el alcance general.</p>');
                }
            });

            $(document).on('click', '.evapp-wa-reminder-add-condition', function(){
                var $rule = $(this).closest('.evapp-wa-reminder-rule');
                var rIdx = $rule.data('rule-index');
                var cIdx = $rule.find('tbody tr').length;
                var html = $('#tmpl-evapp-wa-reminder-condition').html();
                $rule.find('tbody').append(replaceAllIndexes(html, rIdx, cIdx));
            });

            $(document).on('click', '.evapp-wa-reminder-remove-condition', function(){
                $(this).closest('tr').remove();
            });
        });
        </script>
        <?php
    }
}

if ( ! function_exists('evapp_whatsapp_reminder_render_rule_row') ) {
    function evapp_whatsapp_reminder_render_rule_row($rule_index, $rule, $fields, $operators) {
        $rule = wp_parse_args($rule, [
            'enabled' => '1',
            'name' => '',
            'action' => 'allow',
            'match' => 'all',
            'conditions' => [],
        ]);
        ?>
        <div class="evapp-wa-reminder-rule" data-rule-index="<?php echo esc_attr($rule_index); ?>">
            <div class="evapp-wa-reminder-rule-head">
                <label>
                    <input type="checkbox" name="eventosapp_whatsapp_reminder_rules[<?php echo esc_attr($rule_index); ?>][enabled]" value="1" <?php checked($rule['enabled'], '1'); ?>> Activa
                </label>
                <input type="text" name="eventosapp_whatsapp_reminder_rules[<?php echo esc_attr($rule_index); ?>][name]" value="<?php echo esc_attr($rule['name']); ?>" placeholder="Nombre del filtro">
                <select name="eventosapp_whatsapp_reminder_rules[<?php echo esc_attr($rule_index); ?>][action]">
                    <option value="allow" <?php selected($rule['action'], 'allow'); ?>>Enviar</option>
                    <option value="deny" <?php selected($rule['action'], 'deny'); ?>>No enviar</option>
                </select>
                <select name="eventosapp_whatsapp_reminder_rules[<?php echo esc_attr($rule_index); ?>][match]">
                    <option value="all" <?php selected($rule['match'], 'all'); ?>>Cumple todas</option>
                    <option value="any" <?php selected($rule['match'], 'any'); ?>>Cumple cualquiera</option>
                </select>
                <button type="button" class="button-link-delete evapp-wa-reminder-remove-rule">Eliminar</button>
            </div>
            <div class="evapp-wa-reminder-conditions">
                <table>
                    <thead>
                        <tr>
                            <th>Campo</th>
                            <th>Operador</th>
                            <th>Valor</th>
                            <th style="width:70px;">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty($rule['conditions']) ) : ?>
                            <?php foreach ( $rule['conditions'] as $condition_index => $condition ) : ?>
                                <?php evapp_whatsapp_reminder_render_condition_row($rule_index, $condition_index, $condition, $fields, $operators); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p>
                    <button type="button" class="button evapp-wa-reminder-add-condition">+ Agregar condición</button>
                </p>
            </div>
        </div>
        <?php
    }
}

if ( ! function_exists('evapp_whatsapp_reminder_render_condition_row') ) {
    function evapp_whatsapp_reminder_render_condition_row($rule_index, $condition_index, $condition, $fields, $operators) {
        $condition = wp_parse_args($condition, [
            'field' => 'localidad',
            'operator' => 'equals',
            'value' => '',
        ]);
        ?>
        <tr>
            <td>
                <select name="eventosapp_whatsapp_reminder_rules[<?php echo esc_attr($rule_index); ?>][conditions][<?php echo esc_attr($condition_index); ?>][field]">
                    <?php foreach ( $fields as $field_key => $field_label ) : ?>
                        <option value="<?php echo esc_attr($field_key); ?>" <?php selected($condition['field'], $field_key); ?>><?php echo esc_html($field_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <select name="eventosapp_whatsapp_reminder_rules[<?php echo esc_attr($rule_index); ?>][conditions][<?php echo esc_attr($condition_index); ?>][operator]">
                    <?php foreach ( $operators as $operator_key => $operator_label ) : ?>
                        <option value="<?php echo esc_attr($operator_key); ?>" <?php selected($condition['operator'], $operator_key); ?>><?php echo esc_html($operator_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <input type="text" name="eventosapp_whatsapp_reminder_rules[<?php echo esc_attr($rule_index); ?>][conditions][<?php echo esc_attr($condition_index); ?>][value]" value="<?php echo esc_attr($condition['value']); ?>" placeholder="Valor a comparar">
            </td>
            <td>
                <button type="button" class="button-link-delete evapp-wa-reminder-remove-condition">Quitar</button>
            </td>
        </tr>
        <?php
    }
}

add_action('save_post_eventosapp_event', function($post_id) {
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision($post_id) ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;
    if ( ! isset($_POST['evapp_whatsapp_email_reminder_nonce']) || ! wp_verify_nonce($_POST['evapp_whatsapp_email_reminder_nonce'], 'evapp_whatsapp_email_reminder_save') ) return;

    update_post_meta($post_id, '_eventosapp_whatsapp_reminder_enabled', isset($_POST['eventosapp_whatsapp_reminder_enabled']) ? '1' : '0');
    update_post_meta($post_id, '_eventosapp_whatsapp_reminder_respect_rules', isset($_POST['eventosapp_whatsapp_reminder_respect_rules']) ? '1' : '0');

    $raw_rules = isset($_POST['eventosapp_whatsapp_reminder_rules']) && is_array($_POST['eventosapp_whatsapp_reminder_rules']) ? $_POST['eventosapp_whatsapp_reminder_rules'] : [];
    $rules = evapp_whatsapp_reminder_normalize_rules($raw_rules);
    update_post_meta($post_id, '_eventosapp_whatsapp_reminder_rules', $rules);
}, 980);

add_action('save_post_eventosapp_event', function($post_id) {
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision($post_id) ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;

    evapp_whatsapp_reminder_schedule_from_email($post_id);
}, 999);

if ( ! function_exists('evapp_whatsapp_reminder_parse_timestamp') ) {
    function evapp_whatsapp_reminder_parse_timestamp($raw, $source = '') {
        if ( is_array($raw) || is_object($raw) ) {
            return 0;
        }

        $raw = trim((string) $raw);
        if ( $raw === '' ) {
            return 0;
        }

        if ( is_numeric($raw) ) {
            $timestamp = (int) $raw;
            return $timestamp > 1000000000 ? $timestamp : 0;
        }

        if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ) {
            return 0;
        }

        $normalized = str_replace('T', ' ', $raw);

        try {
            $dt = new DateTime($normalized, wp_timezone());
            $timestamp = $dt->getTimestamp();
            return $timestamp > 1000000000 ? $timestamp : 0;
        } catch (Exception $e) {
            return 0;
        }
    }
}

if ( ! function_exists('evapp_whatsapp_reminder_get_email_schedule') ) {
    function evapp_whatsapp_reminder_get_email_schedule($event_id) {
        $event_id = absint($event_id);
        if ( ! $event_id ) {
            return [];
        }

        $timestamp_keys = [
            '_eventosapp_email_reminder_timestamp',
            '_eventosapp_ticket_reminder_timestamp',
            '_eventosapp_recordatorio_ticket_timestamp',
            '_eventosapp_recordatorio_correo_timestamp',
            '_eventosapp_notificacion_correo_timestamp',
            '_eventosapp_email_notification_timestamp',
            '_eventosapp_notification_timestamp',
            '_eventosapp_reminder_timestamp',
            '_eventosapp_email_scheduled_timestamp',
            '_eventosapp_ticket_email_scheduled_timestamp',
            '_eventosapp_mass_email_scheduled_timestamp',
            '_eventosapp_notificacion_ticket_timestamp',
        ];

        foreach ( $timestamp_keys as $key ) {
            $timestamp = evapp_whatsapp_reminder_parse_timestamp(get_post_meta($event_id, $key, true), $key);
            if ( $timestamp ) {
                return [
                    'timestamp' => $timestamp,
                    'source'    => $key,
                ];
            }
        }

        $datetime_keys = [
            '_eventosapp_email_reminder_datetime',
            '_eventosapp_ticket_reminder_datetime',
            '_eventosapp_recordatorio_ticket_datetime',
            '_eventosapp_recordatorio_correo_datetime',
            '_eventosapp_notificacion_correo_datetime',
            '_eventosapp_email_notification_datetime',
            '_eventosapp_notification_datetime',
            '_eventosapp_reminder_datetime',
            '_eventosapp_email_scheduled_datetime',
            '_eventosapp_ticket_email_scheduled_datetime',
            '_eventosapp_mass_email_scheduled_datetime',
            '_eventosapp_notificacion_ticket_datetime',
        ];

        foreach ( $datetime_keys as $key ) {
            $timestamp = evapp_whatsapp_reminder_parse_timestamp(get_post_meta($event_id, $key, true), $key);
            if ( $timestamp ) {
                return [
                    'timestamp' => $timestamp,
                    'source'    => $key,
                ];
            }
        }

        $pairs = [
            ['_eventosapp_email_reminder_date', '_eventosapp_email_reminder_time'],
            ['_eventosapp_ticket_reminder_date', '_eventosapp_ticket_reminder_time'],
            ['_eventosapp_recordatorio_fecha', '_eventosapp_recordatorio_hora'],
            ['_eventosapp_recordatorio_correo_fecha', '_eventosapp_recordatorio_correo_hora'],
            ['_eventosapp_notificacion_fecha', '_eventosapp_notificacion_hora'],
            ['_eventosapp_notificacion_correo_fecha', '_eventosapp_notificacion_correo_hora'],
            ['_eventosapp_email_scheduled_date', '_eventosapp_email_scheduled_time'],
            ['_eventosapp_mass_email_date', '_eventosapp_mass_email_time'],
        ];

        foreach ( $pairs as $pair ) {
            $date = trim((string) get_post_meta($event_id, $pair[0], true));
            $time = trim((string) get_post_meta($event_id, $pair[1], true));
            if ( $date !== '' && $time !== '' ) {
                $timestamp = evapp_whatsapp_reminder_parse_timestamp($date . ' ' . $time, $pair[0] . '+' . $pair[1]);
                if ( $timestamp ) {
                    return [
                        'timestamp' => $timestamp,
                        'source'    => $pair[0] . ' + ' . $pair[1],
                    ];
                }
            }
        }

        $all_meta = get_post_meta($event_id);
        foreach ( $all_meta as $key => $values ) {
            $key_l = strtolower((string) $key);
            if ( strpos($key_l, 'whatsapp') !== false ) {
                continue;
            }

            $looks_email = (strpos($key_l, 'email') !== false || strpos($key_l, 'correo') !== false || strpos($key_l, 'mail') !== false);
            $looks_schedule = (strpos($key_l, 'reminder') !== false || strpos($key_l, 'recordatorio') !== false || strpos($key_l, 'notificacion') !== false || strpos($key_l, 'notification') !== false || strpos($key_l, 'schedule') !== false || strpos($key_l, 'program') !== false);

            if ( ! $looks_email || ! $looks_schedule ) {
                continue;
            }

            foreach ( (array) $values as $value ) {
                $timestamp = evapp_whatsapp_reminder_parse_timestamp($value, $key);
                if ( $timestamp ) {
                    return [
                        'timestamp' => $timestamp,
                        'source'    => $key,
                    ];
                }
            }
        }

        return [];
    }
}

if ( ! function_exists('evapp_whatsapp_reminder_schedule_from_email') ) {
    function evapp_whatsapp_reminder_schedule_from_email($event_id) {
        $event_id = absint($event_id);
        if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
            return false;
        }

        wp_clear_scheduled_hook('eventosapp_whatsapp_email_reminder_send', [$event_id]);

        if ( ! evapp_whatsapp_reminder_is_enabled_for_event($event_id) ) {
            update_post_meta($event_id, '_eventosapp_whatsapp_reminder_schedule_status', 'desactivado');
            delete_post_meta($event_id, '_eventosapp_whatsapp_reminder_scheduled_at');
            delete_post_meta($event_id, '_eventosapp_whatsapp_reminder_schedule_source');
            return false;
        }

        $schedule = evapp_whatsapp_reminder_get_email_schedule($event_id);
        if ( empty($schedule['timestamp']) ) {
            update_post_meta($event_id, '_eventosapp_whatsapp_reminder_schedule_status', 'esperando_horario_correo');
            return false;
        }

        $timestamp = (int) $schedule['timestamp'];
        update_post_meta($event_id, '_eventosapp_whatsapp_reminder_scheduled_at', $timestamp);
        update_post_meta($event_id, '_eventosapp_whatsapp_reminder_schedule_source', sanitize_text_field((string) $schedule['source']));

        if ( $timestamp <= (time() + 30) ) {
            update_post_meta($event_id, '_eventosapp_whatsapp_reminder_schedule_status', 'horario_pasado_o_muy_cercano');
            return false;
        }

        $scheduled = wp_schedule_single_event($timestamp, 'eventosapp_whatsapp_email_reminder_send', [$event_id]);
        update_post_meta($event_id, '_eventosapp_whatsapp_reminder_schedule_status', $scheduled ? 'programado' : 'error_programando');

        return (bool) $scheduled;
    }
}

if ( ! function_exists('evapp_whatsapp_reminder_get_event_ticket_ids') ) {
    function evapp_whatsapp_reminder_get_event_ticket_ids($event_id) {
        $event_id = absint($event_id);
        if ( ! $event_id ) {
            return [];
        }

        $query = new WP_Query([
            'post_type'              => 'eventosapp_ticket',
            'post_status'            => 'any',
            'fields'                 => 'ids',
            'posts_per_page'         => -1,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => [
                [
                    'key'     => '_eventosapp_ticket_evento_id',
                    'value'   => $event_id,
                    'compare' => '=',
                ],
            ],
        ]);

        return array_map('absint', $query->posts);
    }
}

if ( ! function_exists('evapp_whatsapp_reminder_ticket_passes_filters') ) {
    function evapp_whatsapp_reminder_ticket_passes_filters($ticket_id, $event_id = 0) {
        $ticket_id = absint($ticket_id);
        $event_id = $event_id ? absint($event_id) : absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));

        $rules = evapp_whatsapp_reminder_normalize_rules(get_post_meta($event_id, '_eventosapp_whatsapp_reminder_rules', true));
        if ( empty($rules) ) {
            return [
                'allowed' => true,
                'reason'  => 'Sin filtros específicos de recordatorio WhatsApp.',
            ];
        }

        $has_allow_rules = false;
        $matched_allow = false;

        foreach ( $rules as $rule_index => $rule ) {
            if ( empty($rule['enabled']) || $rule['enabled'] !== '1' ) {
                continue;
            }

            if ( $rule['action'] === 'allow' ) {
                $has_allow_rules = true;
            }

            if ( function_exists('eventosapp_whatsapp_rule_matches_ticket') ) {
                $matches = eventosapp_whatsapp_rule_matches_ticket($ticket_id, $rule);
            } else {
                $matches = evapp_whatsapp_reminder_rule_matches_ticket($ticket_id, $rule);
            }

            if ( ! $matches ) {
                continue;
            }

            if ( $rule['action'] === 'deny' ) {
                return [
                    'allowed' => false,
                    'reason'  => 'Bloqueado por filtro de recordatorio: ' . ($rule['name'] ?: ('Filtro #' . ((int) $rule_index + 1))),
                ];
            }

            if ( $rule['action'] === 'allow' ) {
                $matched_allow = true;
            }
        }

        if ( $has_allow_rules && ! $matched_allow ) {
            return [
                'allowed' => false,
                'reason'  => 'No cumple ningún filtro de recordatorio permitido.',
            ];
        }

        return [
            'allowed' => true,
            'reason'  => $matched_allow ? 'Cumple filtro de recordatorio WhatsApp.' : 'Sin filtro restrictivo específico.',
        ];
    }
}

if ( ! function_exists('evapp_whatsapp_reminder_rule_matches_ticket') ) {
    function evapp_whatsapp_reminder_rule_matches_ticket($ticket_id, $rule) {
        $conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];
        if ( empty($conditions) ) {
            return true;
        }

        $match = isset($rule['match']) && $rule['match'] === 'any' ? 'any' : 'all';
        $results = [];

        foreach ( $conditions as $condition ) {
            $field = isset($condition['field']) ? (string) $condition['field'] : '';
            $operator = isset($condition['operator']) ? (string) $condition['operator'] : 'equals';
            $expected = isset($condition['value']) ? (string) $condition['value'] : '';

            if ( function_exists('eventosapp_whatsapp_get_rule_field_value') ) {
                $actual = eventosapp_whatsapp_get_rule_field_value($ticket_id, $field);
            } else {
                $actual = get_post_meta($ticket_id, '_' . ltrim($field, '_'), true);
            }

            if ( function_exists('eventosapp_whatsapp_compare_values') ) {
                $results[] = eventosapp_whatsapp_compare_values($actual, $operator, $expected);
            } else {
                $actual_norm = strtolower(trim(remove_accents((string) $actual)));
                $expected_norm = strtolower(trim(remove_accents((string) $expected)));
                $results[] = $operator === 'not_equals' ? ($actual_norm !== $expected_norm) : ($actual_norm === $expected_norm);
            }
        }

        return $match === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }
}

if ( ! function_exists('evapp_whatsapp_reminder_context_is_reminder') ) {
    function evapp_whatsapp_reminder_context_is_reminder($meta_key = '', $email_entry = [], $ticket_id = 0) {
        $haystack = strtolower((string) $meta_key . ' ' . wp_json_encode($email_entry));
        $needles = [
            'recordatorio',
            'reminder',
            'ticket_reminder',
            'email_reminder',
            'scheduled_reminder',
            'programado',
            'programada',
            'cron_reminder',
            'notificacion_programada',
            'notification_reminder',
        ];

        $is_reminder = false;
        foreach ( $needles as $needle ) {
            if ( strpos($haystack, $needle) !== false ) {
                $is_reminder = true;
                break;
            }
        }

        if ( ! $is_reminder && function_exists('wp_doing_cron') && wp_doing_cron() && $meta_key === '_eventosapp_ticket_email_sent_status' ) {
            $is_reminder = true;
        }

        return (bool) apply_filters('evapp_whatsapp_reminder_context_is_reminder', $is_reminder, $meta_key, $email_entry, $ticket_id);
    }
}

if ( ! function_exists('evapp_whatsapp_reminder_source_key') ) {
    function evapp_whatsapp_reminder_source_key($event_id, $fallback = '') {
        $event_id = absint($event_id);
        $scheduled_at = absint(get_post_meta($event_id, '_eventosapp_whatsapp_reminder_scheduled_at', true));
        if ( ! $scheduled_at ) {
            $schedule = evapp_whatsapp_reminder_get_email_schedule($event_id);
            $scheduled_at = ! empty($schedule['timestamp']) ? (int) $schedule['timestamp'] : 0;
        }

        if ( $scheduled_at ) {
            return 'email_reminder:' . $event_id . ':' . $scheduled_at;
        }

        return $fallback !== '' ? sanitize_text_field($fallback) : ('email_reminder:' . $event_id . ':' . current_time('YmdHi'));
    }
}

if ( ! function_exists('evapp_whatsapp_send_email_reminder_for_ticket') ) {
    function evapp_whatsapp_send_email_reminder_for_ticket($ticket_id, $context = 'email_reminder', $source_key = '', $email_entry = []) {
        $ticket_id = absint($ticket_id);
        if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
            return ['ok' => false, 'message' => 'Ticket inválido para recordatorio WhatsApp.'];
        }

        $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
            return ['ok' => false, 'message' => 'El ticket no tiene evento válido para recordatorio WhatsApp.'];
        }

        if ( ! evapp_whatsapp_reminder_is_enabled_for_event($event_id) ) {
            evapp_whatsapp_reminder_add_event_log($event_id, [
                'message'   => 'Recordatorio WhatsApp omitido: integración desactivada.',
                'ticket_id' => $ticket_id,
                'status'    => 'skipped_disabled',
            ]);
            return ['ok' => true, 'message' => 'Recordatorio WhatsApp desactivado para este evento.', 'skipped_disabled' => true];
        }

        $filter_result = evapp_whatsapp_reminder_ticket_passes_filters($ticket_id, $event_id);
        if ( empty($filter_result['allowed']) ) {
            if ( function_exists('eventosapp_whatsapp_add_ticket_log') ) {
                eventosapp_whatsapp_add_ticket_log($ticket_id, 'skipped', $filter_result['reason'], [
                    'context' => 'email_reminder_whatsapp',
                    'source_key' => $source_key,
                ]);
            }
            return ['ok' => true, 'message' => $filter_result['reason'], 'skipped_reminder_filter' => true];
        }

        if ( ! function_exists('eventosapp_whatsapp_send_ticket') ) {
            return ['ok' => false, 'message' => 'La función de envío WhatsApp no está disponible.'];
        }

        $respect_rules = get_post_meta($event_id, '_eventosapp_whatsapp_reminder_respect_rules', true);
        $respect_rules = $respect_rules === '' ? '1' : ($respect_rules === '0' ? '0' : '1');
        $source_key = evapp_whatsapp_reminder_source_key($event_id, $source_key);

        return eventosapp_whatsapp_send_ticket($ticket_id, [
            'context'    => sanitize_key('email_reminder_whatsapp_' . $context),
            'force'      => false,
            'skip_rules' => ($respect_rules !== '1'),
            'source_key' => $source_key,
        ]);
    }
}

if ( ! function_exists('evapp_whatsapp_handle_email_meta_ticket_send') ) {
    function evapp_whatsapp_handle_email_meta_ticket_send($ticket_id, $source_key = '', $email_entry = [], $meta_key = '') {
        $ticket_id = absint($ticket_id);
        if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
            return ['handled' => false];
        }

        if ( ! evapp_whatsapp_reminder_context_is_reminder($meta_key, $email_entry, $ticket_id) ) {
            return ['handled' => false];
        }

        $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        if ( ! $event_id ) {
            return ['handled' => true, 'result' => ['ok' => false, 'message' => 'Ticket sin evento para recordatorio WhatsApp.']];
        }

        $result = evapp_whatsapp_send_email_reminder_for_ticket($ticket_id, 'email_meta', $source_key, $email_entry);

        if ( function_exists('eventosapp_whatsapp_add_activity_log') ) {
            eventosapp_whatsapp_add_activity_log('recordatorio_email_meta_whatsapp_' . (! empty($result['ok']) ? 'procesado' : 'error'), [
                'ticket_id' => $ticket_id,
                'event_id'  => $event_id,
                'meta_key'  => $meta_key,
                'source_key'=> $source_key,
                'result'    => $result,
            ]);
        }

        return ['handled' => true, 'result' => $result];
    }
}

if ( ! function_exists('evapp_whatsapp_reminder_add_event_log') ) {
    function evapp_whatsapp_reminder_add_event_log($event_id, $entry) {
        $event_id = absint($event_id);
        if ( ! $event_id ) {
            return;
        }

        $log = get_post_meta($event_id, '_eventosapp_whatsapp_reminder_log', true);
        $log = is_array($log) ? $log : [];
        $entry = is_array($entry) ? $entry : ['message' => (string) $entry];
        $entry = array_merge([
            'date' => current_time('mysql'),
        ], $entry);

        $log[] = $entry;
        if ( count($log) > 30 ) {
            $log = array_slice($log, -30);
        }

        update_post_meta($event_id, '_eventosapp_whatsapp_reminder_log', $log);
    }
}

add_action('eventosapp_whatsapp_email_reminder_send', 'evapp_whatsapp_send_email_reminder_for_event', 10, 1);

if ( ! function_exists('evapp_whatsapp_send_email_reminder_for_event') ) {
    function evapp_whatsapp_send_email_reminder_for_event($event_id) {
        $event_id = absint($event_id);
        if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
            return;
        }

        if ( ! evapp_whatsapp_reminder_is_enabled_for_event($event_id) ) {
            evapp_whatsapp_reminder_add_event_log($event_id, [
                'message' => 'Cron de recordatorio WhatsApp omitido: integración desactivada.',
                'status'  => 'skipped_disabled',
            ]);
            return;
        }

        $ticket_ids = evapp_whatsapp_reminder_get_event_ticket_ids($event_id);
        $stats = [
            'total'   => count($ticket_ids),
            'sent'    => 0,
            'skipped' => 0,
            'errors'  => 0,
        ];
        $source_key = evapp_whatsapp_reminder_source_key($event_id);

        foreach ( $ticket_ids as $ticket_id ) {
            $result = evapp_whatsapp_send_email_reminder_for_ticket($ticket_id, 'cron', $source_key);

            if ( ! empty($result['skipped_rules']) || ! empty($result['skipped_duplicate']) || ! empty($result['skipped_disabled']) || ! empty($result['skipped_reminder_filter']) ) {
                $stats['skipped']++;
            } elseif ( ! empty($result['ok']) ) {
                $stats['sent']++;
            } else {
                $stats['errors']++;
            }
        }

        update_post_meta($event_id, '_eventosapp_whatsapp_reminder_last_run_at', current_time('mysql'));
        update_post_meta($event_id, '_eventosapp_whatsapp_reminder_last_stats', $stats);
        update_post_meta($event_id, '_eventosapp_whatsapp_reminder_schedule_status', 'ejecutado');

        evapp_whatsapp_reminder_add_event_log($event_id, array_merge($stats, [
            'message' => 'Recordatorio WhatsApp ejecutado.',
            'status'  => 'completed',
        ]));
    }
}

if ( ! function_exists('evapp_whatsapp_reminder_from_email_hook') ) {
    function evapp_whatsapp_reminder_from_email_hook($arg1 = null, $arg2 = null, $arg3 = null, $arg4 = null) {
        $args = [$arg1, $arg2, $arg3, $arg4];
        $ticket_id = 0;
        $event_id = 0;

        foreach ( $args as $arg ) {
            if ( is_numeric($arg) ) {
                $maybe_id = absint($arg);
                if ( $maybe_id && get_post_type($maybe_id) === 'eventosapp_ticket' ) {
                    $ticket_id = $maybe_id;
                    break;
                }
                if ( $maybe_id && get_post_type($maybe_id) === 'eventosapp_event' ) {
                    $event_id = $maybe_id;
                }
            } elseif ( is_array($arg) ) {
                foreach ( ['ticket_id', 'post_id', 'id'] as $key ) {
                    if ( ! empty($arg[$key]) && get_post_type(absint($arg[$key])) === 'eventosapp_ticket' ) {
                        $ticket_id = absint($arg[$key]);
                        break 2;
                    }
                }
                foreach ( ['event_id', 'evento_id'] as $key ) {
                    if ( ! empty($arg[$key]) && get_post_type(absint($arg[$key])) === 'eventosapp_event' ) {
                        $event_id = absint($arg[$key]);
                    }
                }
            }
        }

        if ( $ticket_id ) {
            evapp_whatsapp_send_email_reminder_for_ticket($ticket_id, 'email_hook', '');
            return;
        }

        if ( $event_id ) {
            evapp_whatsapp_send_email_reminder_for_event($event_id);
        }
    }
}

foreach ( [
    'eventosapp_email_reminder_sent',
    'eventosapp_ticket_reminder_sent',
    'eventosapp_after_email_reminder_sent',
    'eventosapp_after_ticket_reminder_sent',
    'eventosapp_mass_email_reminder_sent',
    'eventosapp_scheduled_ticket_email_sent',
] as $evapp_wa_reminder_hook ) {
    add_action($evapp_wa_reminder_hook, 'evapp_whatsapp_reminder_from_email_hook', 20, 4);
}
