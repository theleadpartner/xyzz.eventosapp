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

    // Lugar / contacto
    $dir       = get_post_meta($post_id, '_eventosapp_direccion', true) ?: '';
    $org       = get_post_meta($post_id, '_eventosapp_organizador', true) ?: '';

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
        'zona_horaria' => $tz,
        'direccion'    => $dir,
        'organizador'  => $org,
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
        ['label' => 'Dirección',           'key' => 'direccion',    'val' => $dir,   'is_changed' => in_array('direccion', $changed_keys, true)],
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
