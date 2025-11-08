<?php
// includes/admin/eventosapp-event-checklist.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Checklist de evento — Configuración (metabox), frontend (shortcode) y notificaciones.
 * Meta por evento (config):
 *  - _evchk_montaje_dt           : 'Y-m-d H:i' (hora local del evento)
 *  - _evchk_logistica_cant       : int
 *  - _evchk_need_evid_manual     : '1'|'0'
 *  - _evchk_need_evid_qr         : '1'|'0'
 *  - _evchk_need_evid_multi      : '1'|'0'
 *  - _evchk_tipo_correcto        : 'escarapela'|'manilla_colores'|'manilla_qr'
 *  - _evchk_deadline_dt          : 'Y-m-d H:i' (hora local del evento)
 *  - _evchk_aprobador_email      : email
 *
 * Estado del checklist por usuario coordinador:
 *  - _evchk_submissions : array [ user_id => array {
 *        'completed'           => bool,
 *        'completed_at'        => int (UTC),
 *        'montaje_choice'      => 'Y-m-d H:i',
 *        'montaje_ok'          => bool,
 *        'logistica_nombres'   => string[],
 *        'evid_manual_id'      => int|0,
 *        'evid_qr_id'          => int|0,
 *        'evid_multi_id'       => int|0,
 *        'tipo_choice'         => string,
 *        'tipo_ok'             => bool,
 *        'message_to_approver' => string,
 *    } ]
 */

// ---------- Helpers de fecha del evento (si no existieran) ----------
if ( ! function_exists('eventosapp_get_event_days') ) {
    function eventosapp_get_event_days($event_id){
        $tipo = get_post_meta($event_id, '_eventosapp_tipo_fecha', true) ?: 'unica';
        if ($tipo === 'unica') {
            $d = get_post_meta($event_id, '_eventosapp_fecha_unica', true);
            return $d ? [$d] : [];
        }
        if ($tipo === 'consecutiva') {
            $ini = get_post_meta($event_id, '_eventosapp_fecha_inicio', true);
            $fin = get_post_meta($event_id, '_eventosapp_fecha_fin', true);
            if (!$ini || !$fin) return [];
            $out = [];
            $t = strtotime($ini); $tfin = strtotime($fin);
            if ($t === false || $tfin === false) return [];
            for ($x=$t; $x <= $tfin; $x += DAY_IN_SECONDS) $out[] = gmdate('Y-m-d', $x);
            return $out;
        }
        $fechas = get_post_meta($event_id, '_eventosapp_fechas_noco', true);
        return (is_array($fechas) && $fechas) ? array_values(array_unique(array_map('strval',$fechas))) : [];
    }
}
if ( ! function_exists('eventosapp_event_first_day') ) {
    function eventosapp_event_first_day($event_id){
        $days = (array) eventosapp_get_event_days($event_id);
        if (!$days) return '';
        sort($days);
        return reset($days);
    }
}
if ( ! function_exists('eventosapp_event_timezone') ) {
    function eventosapp_event_timezone($event_id){
        $tzid = get_post_meta($event_id, '_eventosapp_zona_horaria', true);
        if (!$tzid) $tzid = wp_timezone_string() ?: 'UTC';
        try { return new DateTimeZone($tzid); } catch (Exception $e) { return wp_timezone(); }
    }
}

// ---------- Permisos: ¿usuario es coordinador asignado al evento? ----------
function evchk_user_is_assigned_coordinator($event_id, $user_id){
    $u = get_userdata($user_id);
    if ( ! $u ) return false;

    // Debe tener rol 'coordinador'
    if ( ! in_array('coordinador', (array)$u->roles, true) ) return false;

    // Debe estar asignado en _evapp_temp_authors y no vencido
    $co = get_post_meta($event_id, '_evapp_temp_authors', true);
    if (!is_array($co) || !$co) return false;

    $now = time();
    foreach ($co as $row){
        if ((int)($row['user_id'] ?? 0) === (int)$user_id){
            $until = isset($row['until']) ? (int)$row['until'] : 0;
            if (!$until || $until >= $now) return true;
        }
    }
    return false;
}

// ---------- Defaults calculados ----------
function evchk_default_montaje_dt($event_id){
    $first = eventosapp_event_first_day($event_id);
    if (!$first) $first = gmdate('Y-m-d');
    return $first.' 06:30';
}
function evchk_default_deadline_dt($event_id){
    $first = eventosapp_event_first_day($event_id);
    if (!$first) $first = gmdate('Y-m-d');
    // 18:00 del día anterior
    $tz  = eventosapp_event_timezone($event_id);
    $dt  = new DateTime($first.' 18:00:00', $tz);
    $dt->modify('-1 day');
    return $dt->format('Y-m-d H:i');
}

// ---------- Metabox: Configuración de Checklist ----------
add_action('add_meta_boxes', function(){
    add_meta_box(
        'evapp_event_checklist_cfg',
        'Checklist del Evento (Configuración)',
        'evchk_render_metabox',
        'eventosapp_event',
        'normal',
        'high'
    );
});

function evchk_render_metabox($post){
    wp_nonce_field('evchk_save','evchk_nonce');
    $eid = $post->ID;

    $montaje_dt   = get_post_meta($eid, '_evchk_montaje_dt', true) ?: evchk_default_montaje_dt($eid);
    $log_cant     = (int) get_post_meta($eid, '_evchk_logistica_cant', true);
    $need_manual  = get_post_meta($eid, '_evchk_need_evid_manual', true) === '1' ? '1' : '0';
    $need_qr      = get_post_meta($eid, '_evchk_need_evid_qr', true) === '1' ? '1' : '0';
    $need_multi   = get_post_meta($eid, '_evchk_need_evid_multi', true) === '1' ? '1' : '0';
    $tipo_ok      = get_post_meta($eid, '_evchk_tipo_correcto', true) ?: 'escarapela';
    $deadline_dt  = get_post_meta($eid, '_evchk_deadline_dt', true) ?: evchk_default_deadline_dt($eid);
    $approver     = get_post_meta($eid, '_evchk_aprobador_email', true) ?: '';

    // Dirección / lugar (solo lectura informativa)
    $lugar = get_post_meta($eid, '_eventosapp_direccion', true) ?: '';

    ?>
    <style>
      .evchk-grid { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
      @media (max-width:1024px){ .evchk-grid { grid-template-columns:1fr; } }
      .evchk-box { border:1px solid #e2e8f0; background:#fafafa; padding:12px; border-radius:6px; }
      .evchk-box h4{ margin:0 0 8px; }
      .muted{ color:#666;font-size:12px; }
      .wide { width:100%; }
    </style>
    <div class="evchk-grid">
      <div class="evchk-box">
        <h4>Datos generales</h4>
        <p><label><strong>Lugar (referencia):</strong><br>
        <input type="text" class="wide" value="<?php echo esc_attr($lugar); ?>" readonly></label></p>

        <p><label><strong>Fecha y Hora de montaje</strong><br>
        <input type="datetime-local" name="evchk_montaje_dt" class="regular-text"
               value="<?php echo esc_attr( str_replace(' ','T',$montaje_dt) ); ?>">
        <br><span class="muted">Default: <?php echo esc_html(evchk_default_montaje_dt($eid)); ?></span></label></p>

        <p><label><strong>Cantidad de personal de logística</strong><br>
        <input type="number" min="0" name="evchk_logistica_cant" value="<?php echo esc_attr($log_cant); ?>" style="width:120px"></label></p>
      </div>

      <div class="evchk-box">
        <h4>Evidencias y validaciones</h4>
        <p><label><input type="checkbox" name="evchk_need_evid_manual" value="1" <?php checked($need_manual,'1'); ?>> Pedir evidencia de <strong>Check-in Manual</strong></label></p>
        <p><label><input type="checkbox" name="evchk_need_evid_qr" value="1" <?php checked($need_qr,'1'); ?>> Pedir evidencia de <strong>Check-in por QR</strong></label></p>
        <p><label><input type="checkbox" name="evchk_need_evid_multi" value="1" <?php checked($need_multi,'1'); ?>> Pedir evidencia de <strong>Check-in de varias sesiones</strong></label></p>

        <p><label><strong>Tipo correcto del evento</strong><br>
          <select name="evchk_tipo_correcto">
            <option value="escarapela"     <?php selected($tipo_ok,'escarapela'); ?>>Escarapela</option>
            <option value="manilla_colores"<?php selected($tipo_ok,'manilla_colores'); ?>>Manilla de Colores</option>
            <option value="manilla_qr"     <?php selected($tipo_ok,'manilla_qr'); ?>>Manilla con QR</option>
          </select>
        </label></p>
      </div>

      <div class="evchk-box">
        <h4>Control de tiempo</h4>
        <p><label><strong>Fecha y Hora máxima para elaborar el checklist</strong><br>
        <input type="datetime-local" name="evchk_deadline_dt" class="regular-text"
               value="<?php echo esc_attr( str_replace(' ','T',$deadline_dt) ); ?>">
        <br><span class="muted">Default: <?php echo esc_html(evchk_default_deadline_dt($eid)); ?></span></label></p>
      </div>

      <div class="evchk-box">
        <h4>Aprobación</h4>
        <p><label><strong>Correo del aprobador</strong><br>
        <input type="email" name="evchk_aprobador_email" class="wide" value="<?php echo esc_attr($approver); ?>">
        </label><br><span class="muted">
          Enviaremos un correo con el detalle cuando el coordinador complete el checklist,
          y un aviso si llega la hora límite sin haberse completado.
        </span></p>
      </div>
    </div>
    <?php
}

add_action('save_post_eventosapp_event', function($post_id){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if ( empty($_POST['evchk_nonce']) || ! wp_verify_nonce($_POST['evchk_nonce'], 'evchk_save') ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;

    // Sanitización
    $montaje_dt  = isset($_POST['evchk_montaje_dt']) ? sanitize_text_field(str_replace('T',' ', $_POST['evchk_montaje_dt'])) : evchk_default_montaje_dt($post_id);
    $log_cant    = isset($_POST['evchk_logistica_cant']) ? max(0, (int)$_POST['evchk_logistica_cant']) : 0;
    $need_manual = !empty($_POST['evchk_need_evid_manual']) ? '1' : '0';
    $need_qr     = !empty($_POST['evchk_need_evid_qr'])     ? '1' : '0';
    $need_multi  = !empty($_POST['evchk_need_evid_multi'])  ? '1' : '0';
    $tipo_ok     = isset($_POST['evchk_tipo_correcto']) ? sanitize_text_field($_POST['evchk_tipo_correcto']) : 'escarapela';
    $deadline_dt = isset($_POST['evchk_deadline_dt']) ? sanitize_text_field(str_replace('T',' ', $_POST['evchk_deadline_dt'])) : evchk_default_deadline_dt($post_id);
    $approver    = isset($_POST['evchk_aprobador_email']) ? sanitize_email($_POST['evchk_aprobador_email']) : '';

    update_post_meta($post_id, '_evchk_montaje_dt',          $montaje_dt);
    update_post_meta($post_id, '_evchk_logistica_cant',      $log_cant);
    update_post_meta($post_id, '_evchk_need_evid_manual',    $need_manual);
    update_post_meta($post_id, '_evchk_need_evid_qr',        $need_qr);
    update_post_meta($post_id, '_evchk_need_evid_multi',     $need_multi);
    update_post_meta($post_id, '_evchk_tipo_correcto',       $tipo_ok);
    update_post_meta($post_id, '_evchk_deadline_dt',         $deadline_dt);
    update_post_meta($post_id, '_evchk_aprobador_email',     $approver);

    // (Re)programar chequeo de deadline
    evchk_schedule_deadline_check($post_id, $deadline_dt);
}, 40);

// ---------- Programación del aviso de deadline ----------
function evchk_schedule_deadline_check($event_id, $deadline_local){
    // Cancelar si ya había
    $hook = 'evchk_deadline_check';
    $args = [ (int)$event_id ];
    $ts_existing = wp_next_scheduled($hook, $args);
    if ($ts_existing) wp_unschedule_event($ts_existing, $hook, $args);

    // Convertir hora local a timestamp UTC
    $tz = eventosapp_event_timezone($event_id);
    try {
        $dt = DateTime::createFromFormat('Y-m-d H:i', $deadline_local, $tz);
        if ($dt === false) return;
        $utc_ts = $dt->getTimestamp(); // WP-cron espera timestamps de servidor/UTC
        if ($utc_ts > time() - 60) {
            wp_schedule_single_event($utc_ts, $hook, $args);
        }
    } catch(Exception $e){ /* noop */ }
}
add_action('evchk_deadline_check', function($event_id){
    $subs = get_post_meta($event_id, '_evchk_submissions', true);
    $completed = false;
    if (is_array($subs)) {
        foreach ($subs as $u => $row) {
            if (!empty($row['completed'])) { $completed = true; break; }
        }
    }
    if ($completed) return;

    $to = get_post_meta($event_id, '_evchk_aprobador_email', true);
    if (!$to) return;

    $subject = 'Checklist NO realizado a tiempo — '.get_the_title($event_id);
    $body  = '<p>El checklist del evento <strong>'.esc_html(get_the_title($event_id)).'</strong> no fue completado antes de la hora límite.</p>';
    $body .= '<p><a href="'.esc_url( eventosapp_get_checklist_url() ).'">Ver página del checklist</a></p>';

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    wp_mail($to, $subject, $body, $headers);
});

// ---------- Shortcode frontend ----------
add_shortcode('eventosapp_event_checklist', function(){
    // Seguridad y acceso a la sección
    if ( ! function_exists('eventosapp_require_feature') ) {
        return '<p>Falta helper: eventosapp_require_feature().</p>';
    }
    eventosapp_require_feature('checklist');

    // Determinar evento activo
    $event_id = function_exists('eventosapp_get_active_event') ? (int) eventosapp_get_active_event() : 0;
    if (!$event_id) {
        return '<div class="notice notice-warning" style="padding:10px;">Primero selecciona un evento desde el Dashboard.</div>';
    }

    $current_user = wp_get_current_user();
    $is_admin     = current_user_can('manage_options');
    $is_coord     = evchk_user_is_assigned_coordinator($event_id, $current_user->ID);

    // Datos de configuración
    $name     = get_the_title($event_id);
    $lugar    = get_post_meta($event_id, '_eventosapp_direccion', true) ?: '—';
    $fechas   = (array) eventosapp_get_event_days($event_id);

    $montaje_dt   = get_post_meta($event_id, '_evchk_montaje_dt', true) ?: evchk_default_montaje_dt($event_id);
    $log_cant     = (int) get_post_meta($event_id, '_evchk_logistica_cant', true);
    $need_manual  = get_post_meta($event_id, '_evchk_need_evid_manual', true) === '1';
    $need_qr      = get_post_meta($event_id, '_evchk_need_evid_qr', true) === '1';
    $need_multi   = get_post_meta($event_id, '_evchk_need_evid_multi', true) === '1';
    $tipo_ok      = get_post_meta($event_id, '_evchk_tipo_correcto', true) ?: 'escarapela';
    $deadline_dt  = get_post_meta($event_id, '_evchk_deadline_dt', true) ?: evchk_default_deadline_dt($event_id);
    $approver     = get_post_meta($event_id, '_evchk_aprobador_email', true) ?: '';

    // Estado previo del usuario
    $subs = get_post_meta($event_id, '_evchk_submissions', true);
    if (!is_array($subs)) $subs = [];
    $mine = isset($subs[$current_user->ID]) && is_array($subs[$current_user->ID]) ? $subs[$current_user->ID] : [];

    $read_only = !$is_coord && !$is_admin;

    // Procesar POST (solo coordinador)
    $msg = '';
    if (!$read_only && !empty($_POST['evchk_action']) && $_POST['evchk_action']==='submit' && check_admin_referer('evchk_front_'.$event_id)) {

        // Validaciones servidor
        $ok_montaje = false; $ok_log = false; $ok_tipo = false;
        $names = [];
        $sel_montaje = isset($_POST['evchk_montaje_option']) ? sanitize_text_field($_POST['evchk_montaje_option']) : '';
        if ($sel_montaje && $sel_montaje === $montaje_dt) $ok_montaje = true;

        if ($log_cant > 0) {
            for ($i=0; $i<$log_cant; $i++){
                $key = 'evchk_log_name_'.$i;
                $names[$i] = isset($_POST[$key]) ? trim(sanitize_text_field($_POST[$key])) : '';
            }
            $ok_log = count(array_filter($names, function($s){ return $s !== ''; })) === $log_cant;
        } else {
            $ok_log = true; // no requerido
        }

        $tipo_choice = isset($_POST['evchk_tipo']) ? sanitize_text_field($_POST['evchk_tipo']) : '';
        if ($tipo_choice && $tipo_choice === $tipo_ok) $ok_tipo = true;

        // Uploads si se requieren
        $att_manual = isset($mine['evid_manual_id']) ? (int)$mine['evid_manual_id'] : 0;
        $att_qr     = isset($mine['evid_qr_id']) ? (int)$mine['evid_qr_id'] : 0;
        $att_multi  = isset($mine['evid_multi_id']) ? (int)$mine['evid_multi_id'] : 0;

        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';

        if ($need_manual && !empty($_FILES['evchk_evid_manual']['name'])) {
            $att_manual = media_handle_upload('evchk_evid_manual', 0);
            if (is_wp_error($att_manual)) $att_manual = 0;
        }
        if ($need_qr && !empty($_FILES['evchk_evid_qr']['name'])) {
            $att_qr = media_handle_upload('evchk_evid_qr', 0);
            if (is_wp_error($att_qr)) $att_qr = 0;
        }
        if ($need_multi && !empty($_FILES['evchk_evid_multi']['name'])) {
            $att_multi = media_handle_upload('evchk_evid_multi', 0);
            if (is_wp_error($att_multi)) $att_multi = 0;
        }

        $need_manual_ok = $need_manual ? ($att_manual > 0) : true;
        $need_qr_ok     = $need_qr ? ($att_qr > 0) : true;
        $need_multi_ok  = $need_multi ? ($att_multi > 0) : true;

        $all_ok = $ok_montaje && $ok_log && $ok_tipo && $need_manual_ok && $need_qr_ok && $need_multi_ok;

        $subs[$current_user->ID] = [
            'completed'           => $all_ok && !empty($_POST['evchk_finish']),
            'completed_at'        => ($all_ok && !empty($_POST['evchk_finish'])) ? time() : 0,
            'montaje_choice'      => $sel_montaje,
            'montaje_ok'          => $ok_montaje,
            'logistica_nombres'   => $names,
            'evid_manual_id'      => $att_manual,
            'evid_qr_id'          => $att_qr,
            'evid_multi_id'       => $att_multi,
            'tipo_choice'         => $tipo_choice,
            'tipo_ok'             => $ok_tipo,
            'message_to_approver' => isset($_POST['evchk_msg']) ? wp_kses_post($_POST['evchk_msg']) : '',
        ];
        update_post_meta($event_id, '_evchk_submissions', $subs);
        $mine = $subs[$current_user->ID];

        if ($all_ok && !empty($_POST['evchk_finish'])) {
            $msg = '<div class="notice notice-success" style="padding:8px;">Checklist completado y enviado al aprobador.</div>';
            // Enviar correo
            if ($approver) evchk_send_completion_email($event_id, $current_user->ID, $approver, $mine);
        } else {
            $msg = '<div class="notice notice-warning" style="padding:8px;">Se guardó el avance. Aún hay tareas pendientes o respuestas incorrectas.</div>';
        }
    }

    // Construir 3 opciones para la hora de montaje: 1 real + 2 cercanas aleatorias
    $tz = eventosapp_event_timezone($event_id);
    $dt_real = DateTime::createFromFormat('Y-m-d H:i', $montaje_dt, $tz);
    $opts = [$montaje_dt];
    if ($dt_real) {
        $d1 = (clone $dt_real); $d1->modify( (mt_rand(0,1)?'+':'-') . mt_rand(5,25) . ' minutes' );
        $d2 = (clone $dt_real); $d2->modify( (mt_rand(0,1)?'+':'-') . mt_rand(6,35) . ' minutes' );
        $opts[] = $d1->format('Y-m-d H:i');
        $opts[] = $d2->format('Y-m-d H:i');
    }
    $opts = array_values(array_unique($opts));
    shuffle($opts);
    if (count($opts) < 3) { // respaldo
        $opts = array_unique(array_merge($opts, [$montaje_dt, $montaje_dt]));
        $opts = array_slice($opts, 0, 3);
        shuffle($opts);
    }

    // UI
    ob_start();
    ?>
    <style>
      .evchk-wrap{ max-width:900px; margin:0 auto; }
      .evchk-hdr{ background:#f6f7f7; border:1px solid #e2e8f0; border-radius:8px; padding:14px; }
      .evchk-task{ border:1px solid #e2e8f0; border-radius:8px; padding:12px; margin-top:12px; }
      .evchk-task h3{ margin:0 0 8px; font-size:16px; }
      .evchk-err{ color:#b91c1c; font-weight:600; }
      .evchk-ok { color:#065f46; }
      .muted{ color:#666; font-size:12px; }
      .evchk-grid-2{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
      @media (max-width:900px){ .evchk-grid-2{ grid-template-columns:1fr; } }
      .evchk-deadline{ padding:10px; background:#fff8e1; border:1px solid #facc15; border-radius:6px; margin-top:10px; }
      .evchk-deadline.over{ background:#fee2e2; border-color:#ef4444; }
      .button[disabled]{ opacity:.5; pointer-events:none; }
    </style>
    <div class="evchk-wrap">
      <div class="evchk-hdr">
        <h2 style="margin:0 0 6px;"><?php echo esc_html($name); ?></h2>
        <div><strong>Lugar:</strong> <?php echo esc_html($lugar); ?></div>
        <?php if ($fechas): ?>
          <div><strong>Fechas:</strong> <?php echo esc_html( implode(', ', $fechas) ); ?></div>
        <?php endif; ?>

        <?php
          $dl_local = $deadline_dt;
          $dt_dl = DateTime::createFromFormat('Y-m-d H:i', $dl_local, $tz);
          $deadline_iso = $dt_dl ? $dt_dl->format('c') : '';
        ?>
        <div id="evchkDeadline" class="evchk-deadline">
          <strong>Fecha y hora límite: </strong><?php echo esc_html($deadline_dt); ?>
          <div><span>Tiempo restante: </span><span id="evchkCountdown">—</span></div>
        </div>
      </div>

      <?php echo $msg; ?>

      <?php if ($read_only): ?>
        <div class="notice notice-error" style="padding:10px;margin-top:12px;">
          No estás autorizado para diligenciar este checklist.
        </div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('evchk_front_'.$event_id); ?>
        <input type="hidden" name="evchk_action" value="submit">

        <!-- Tarea: Montaje -->
        <div class="evchk-task" id="tarea-montaje">
          <h3>Fecha y Hora de montaje</h3>
          <p class="muted">Selecciona la hora correcta de montaje entre las siguientes opciones.</p>
          <div class="evchk-grid-2">
            <?php foreach ($opts as $opt): ?>
              <label><input type="radio" name="evchk_montaje_option" value="<?php echo esc_attr($opt); ?>" <?php checked(($mine['montaje_choice']??'') === $opt); ?> <?php disabled($read_only); ?>>
              <?php echo esc_html($opt); ?></label>
            <?php endforeach; ?>
          </div>
          <?php if (!empty($mine['montaje_choice']) && empty($mine['montaje_ok'])): ?>
            <div class="evchk-err">Respuesta incorrecta.</div>
          <?php endif; ?>
          <div><label><input type="checkbox" id="chk_montaje" disabled <?php checked(!empty($mine['montaje_ok'])); ?>> Tarea lista</label></div>
        </div>

        <!-- Tarea: Logística -->
        <div class="evchk-task" id="tarea-logistica">
          <h3>Cantidad de personal de logística</h3>
          <?php if ($log_cant>0): ?>
            <p class="muted">Diligencia los nombres (<?php echo (int)$log_cant; ?>):</p>
            <?php for($i=0; $i<$log_cant; $i++): ?>
              <p><input type="text" name="evchk_log_name_<?php echo $i; ?>" value="<?php echo esc_attr($mine['logistica_nombres'][$i] ?? ''); ?>" <?php disabled($read_only); ?> class="regular-text" placeholder="Nombre de logístico #<?php echo ($i+1); ?>"></p>
            <?php endfor; ?>
          <?php else: ?>
            <p class="muted">No se configuró cantidad de logística. (Se marca como cumplida automáticamente)</p>
          <?php endif; ?>
          <div><label><input type="checkbox" id="chk_logistica" disabled <?php checked( empty($log_cant) || ( !empty($mine['logistica_nombres']) && count(array_filter($mine['logistica_nombres']))==$log_cant ) ); ?>> Tarea lista</label></div>
        </div>

        <!-- Evidencias -->
        <?php if ($need_manual): ?>
          <div class="evchk-task" id="tarea-evid-manual">
            <h3>Evidencia: Check-in Manual</h3>
            <p><input type="file" name="evchk_evid_manual" accept="image/*" <?php disabled($read_only); ?>></p>
            <?php if (!empty($mine['evid_manual_id'])): ?>
              <p class="evchk-ok">Imagen cargada: <?php echo esc_html( basename( get_attached_file($mine['evid_manual_id']) ) ); ?></p>
            <?php endif; ?>
            <div><label><input type="checkbox" id="chk_evid_manual" disabled <?php checked(!empty($mine['evid_manual_id'])); ?>> Tarea lista</label></div>
          </div>
        <?php endif; ?>

        <?php if ($need_qr): ?>
          <div class="evchk-task" id="tarea-evid-qr">
            <h3>Evidencia: Check-in por QR</h3>
            <p><input type="file" name="evchk_evid_qr" accept="image/*" <?php disabled($read_only); ?>></p>
            <?php if (!empty($mine['evid_qr_id'])): ?>
              <p class="evchk-ok">Imagen cargada: <?php echo esc_html( basename( get_attached_file($mine['evid_qr_id']) ) ); ?></p>
            <?php endif; ?>
            <div><label><input type="checkbox" id="chk_evid_qr" disabled <?php checked(!empty($mine['evid_qr_id'])); ?>> Tarea lista</label></div>
          </div>
        <?php endif; ?>

        <?php if ($need_multi): ?>
          <div class="evchk-task" id="tarea-evid-multi">
            <h3>Evidencia: Check-in de varias sesiones</h3>
            <p><input type="file" name="evchk_evid_multi" accept="image/*" <?php disabled($read_only); ?>></p>
            <?php if (!empty($mine['evid_multi_id'])): ?>
              <p class="evchk-ok">Imagen cargada: <?php echo esc_html( basename( get_attached_file($mine['evid_multi_id']) ) ); ?></p>
            <?php endif; ?>
            <div><label><input type="checkbox" id="chk_evid_multi" disabled <?php checked(!empty($mine['evid_multi_id'])); ?>> Tarea lista</label></div>
          </div>
        <?php endif; ?>

        <!-- Tipo de evento -->
        <div class="evchk-task" id="tarea-tipo">
          <h3>Tipo de evento</h3>
          <p>Selecciona una opción:</p>
          <label><input type="radio" name="evchk_tipo" value="escarapela"      <?php checked(($mine['tipo_choice']??'')==='escarapela'); ?> <?php disabled($read_only); ?>> Escarapela</label><br>
          <label><input type="radio" name="evchk_tipo" value="manilla_colores" <?php checked(($mine['tipo_choice']??'')==='manilla_colores'); ?> <?php disabled($read_only); ?>> Manilla de Colores</label><br>
          <label><input type="radio" name="evchk_tipo" value="manilla_qr"      <?php checked(($mine['tipo_choice']??'')==='manilla_qr'); ?> <?php disabled($read_only); ?>> Manilla con QR</label>
          <?php if (!empty($mine['tipo_choice']) && empty($mine['tipo_ok'])): ?>
            <div class="evchk-err">Respuesta incorrecta.</div>
          <?php endif; ?>
          <div><label><input type="checkbox" id="chk_tipo" disabled <?php checked(!empty($mine['tipo_ok'])); ?>> Tarea lista</label></div>
        </div>

        <!-- Finalización -->
        <div class="evchk-task">
          <h3>Enviar a aprobador</h3>
          <p class="muted">Este mensaje es opcional; se incluirá en el correo al aprobador.</p>
          <p><textarea name="evchk_msg" rows="4" style="width:100%;" <?php disabled($read_only); ?>><?php echo isset($mine['message_to_approver']) ? esc_textarea($mine['message_to_approver']) : ''; ?></textarea></p>
          <p>
            <button type="submit" class="button" name="evchk_save_progress" value="1" <?php disabled($read_only); ?>>Guardar avance</button>
            <button type="submit" class="button button-primary" id="evchkFinishBtn" name="evchk_finish" value="1" disabled <?php disabled($read_only); ?>>Terminado</button>
          </p>
        </div>
      </form>
    </div>

    <script>
    (function(){
      // Countdown
      var elBox = document.getElementById('evchkDeadline');
      var elC   = document.getElementById('evchkCountdown');
      var deadlineISO = <?php echo $deadline_iso ? '"'.esc_js($deadline_iso).'"' : 'null'; ?>;
      function pad(n){ return (n<10?'0':'')+n; }
      function tick(){
        if(!deadlineISO){ elC.textContent='—'; return; }
        var dl = new Date(deadlineISO).getTime();
        var now = Date.now();
        var diff = dl - now;
        if (diff <= 0) {
          elC.textContent = 'VENCIDO';
          elBox.classList.add('over');
          return;
        }
        var s = Math.floor(diff/1000);
        var d = Math.floor(s/86400); s -= d*86400;
        var h = Math.floor(s/3600);  s -= h*3600;
        var m = Math.floor(s/60);    s -= m*60;
        elC.textContent = (d>0?(d+'d '):'') + pad(h)+':'+pad(m)+':'+pad(s);
        requestAnimationFrame(tick);
      }
      tick();

      // Habilitar checkboxes locales en el cliente (UX)
      function byId(id){return document.getElementById(id);}
      function enableIf(cond, id){ var el=byId(id); if(el) el.checked = !!cond; }
      function allReady(){
        var ids = ['chk_montaje','chk_logistica','chk_tipo'<?php
          if($need_manual) echo ",'chk_evid_manual'";
          if($need_qr)     echo ",'chk_evid_qr'";
          if($need_multi)  echo ",'chk_evid_multi'";
        ?>];
        for(var i=0;i<ids.length;i++){ var el=document.getElementById(ids[i]); if(!el || !el.checked) return false; }
        return true;
      }
      function toggleFinish(){ var b=document.getElementById('evchkFinishBtn'); if(b) b.disabled = !allReady(); }

      // Montaje: si selecciona la correcta, marcar
      var radios = document.querySelectorAll('input[name="evchk_montaje_option"]');
      radios.forEach(function(r){
        r.addEventListener('change', function(){
          var isOk = (this.value === <?php echo '"'.esc_js($montaje_dt).'"'; ?>);
          enableIf(isOk, 'chk_montaje'); toggleFinish();
          if(!isOk){
            // mostrar mensaje en vivo
            var t = document.querySelector('#tarea-montaje .evchk-err');
            if(!t){ t = document.createElement('div'); t.className='evchk-err'; t.textContent='Respuesta incorrecta.'; document.getElementById('tarea-montaje').appendChild(t); }
          }
        });
      });

      // Logística: cuando todos los nombres estén llenos
      <?php if ($log_cant>0): ?>
      var inputs = [];
      for (var i=0;i<<?php echo (int)$log_cant; ?>;i++){
        var el = document.querySelector('input[name="evchk_log_name_'+i+'"]');
        if (el) { inputs.push(el); el.addEventListener('input', checkLog); }
      }
      function checkLog(){
        var ok = true;
        for (var i=0;i<inputs.length;i++){ if(!inputs[i].value.trim()){ ok=false; break; } }
        enableIf(ok, 'chk_logistica'); toggleFinish();
      }
      checkLog();
      <?php else: ?>
      enableIf(true,'chk_logistica'); toggleFinish();
      <?php endif; ?>

      // Tipo
      var tipoRad = document.querySelectorAll('input[name="evchk_tipo"]');
      tipoRad.forEach(function(r){
        r.addEventListener('change', function(){
          var ok = (this.value === <?php echo '"'.esc_js($tipo_ok).'"'; ?>);
          enableIf(ok, 'chk_tipo'); toggleFinish();
          if(!ok){
            var t = document.querySelector('#tarea-tipo .evchk-err');
            if(!t){ t = document.createElement('div'); t.className='evchk-err'; t.textContent='Respuesta incorrecta.'; document.getElementById('tarea-tipo').appendChild(t); }
          }
        });
      });

      // Evidencias: al seleccionar archivo, marcar
      <?php if ($need_manual): ?>
      var f1 = document.querySelector('input[name="evchk_evid_manual"]');
      if (f1) f1.addEventListener('change', function(){ enableIf(!!this.files.length, 'chk_evid_manual'); toggleFinish(); });
      <?php endif; ?>
      <?php if ($need_qr): ?>
      var f2 = document.querySelector('input[name="evchk_evid_qr"]');
      if (f2) f2.addEventListener('change', function(){ enableIf(!!this.files.length, 'chk_evid_qr'); toggleFinish(); });
      <?php endif; ?>
      <?php if ($need_multi): ?>
      var f3 = document.querySelector('input[name="evchk_evid_multi"]');
      if (f3) f3.addEventListener('change', function(){ enableIf(!!this.files.length, 'chk_evid_multi'); toggleFinish(); });
      <?php endif; ?>

      toggleFinish();
    })();
    </script>
    <?php
    return ob_get_clean();
});

// ---------- Email de finalización ----------
function evchk_send_completion_email($event_id, $user_id, $to, $data){
    $u = get_userdata($user_id);
    $subject = 'Checklist completado — '.get_the_title($event_id);

    $rows = [];
    $rows[] = ['Campo','Valor'];
    $rows[] = ['Coordinador', $u ? $u->display_name.' ('.$u->user_email.')' : ('#'.$user_id)];
    $rows[] = ['Fecha/Hora de montaje', !empty($data['montaje_choice']) ? esc_html($data['montaje_choice']).($data['montaje_ok']?' (OK)':' (INCORRECTA)') : '—'];

    $names = isset($data['logistica_nombres']) && is_array($data['logistica_nombres']) ? array_filter($data['logistica_nombres']) : [];
    $rows[] = ['Logística (nombres)', $names ? esc_html(implode(', ', $names)) : '—'];

    if (!empty($data['evid_manual_id'])) $rows[] = ['Evidencia Check-in Manual', esc_url( wp_get_attachment_url($data['evid_manual_id']) )];
    if (!empty($data['evid_qr_id']))     $rows[] = ['Evidencia Check-in QR',     esc_url( wp_get_attachment_url($data['evid_qr_id']) )];
    if (!empty($data['evid_multi_id']))  $rows[] = ['Evidencia Multi-sesión',    esc_url( wp_get_attachment_url($data['evid_multi_id']) )];

    if (!empty($data['tipo_choice'])) $rows[] = ['Tipo de evento elegido', esc_html($data['tipo_choice']).(!empty($data['tipo_ok'])?' (OK)':' (INCORRECTO)')];
    if (!empty($data['message_to_approver'])) $rows[] = ['Mensaje del coordinador', wp_kses_post($data['message_to_approver'])];

    $table = '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#e5e7eb;">';
    foreach ($rows as $r){
        $table .= '<tr><td style="background:#f9fafb;font-weight:600;">'.$r[0].'</td><td>'.$r[1].'</td></tr>';
    }
    $table .= '</table>';

    $body  = '<p>El coordinador ha completado el checklist del evento <strong>'.esc_html(get_the_title($event_id)).'</strong>.</p>';
    $body .= $table;
    $body .= '<p><a href="'.esc_url( eventosapp_get_checklist_url() ).'">Ver checklist</a></p>';

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    wp_mail($to, $subject, $body, $headers);
}
