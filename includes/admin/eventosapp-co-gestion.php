<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Metabox de Co-gestión temporal y Staff operativo
 * - _evapp_temp_authors         : array de ['user_id'=>int,'until'=>int,'granted_by'=>int]
 * - _evapp_event_staff_required : int
 * - _evapp_event_staff_assigned : array de ['user_id'=>int,'until'=>int]  (se purga expirados)
 *
 * Además, los usuarios con rol "staff" reciben/meten:
 * - usermeta _evapp_event_assignment : ARRAY de ['event_id' => ['event_id'=>int,'until'=>int]]
 *   MODIFICADO: Ahora soporta múltiples eventos simultáneos
 *
 * NOTA: Esto NO altera la matriz de features/visibilidad:
 *       solo dice "este usuario puede gestionar ESTE evento".
 */

// ---------- Helpers de fechas del evento (si no existieran ya) ----------
if ( ! function_exists('eventosapp_get_event_days') ) {
  /**
   * Devuelve array de fechas 'Y-m-d' definidas para el evento (unica/consecutiva/noconsecutiva).
   */
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
      for ($x=$t; $x <= $tfin; $x += DAY_IN_SECONDS) {
        $out[] = gmdate('Y-m-d', $x);
      }
      return $out;
    }
    // noconsecutiva
    $fechas = get_post_meta($event_id, '_eventosapp_fechas_noco', true);
    return (is_array($fechas) && $fechas) ? array_values(array_unique(array_map('strval',$fechas))) : [];
  }
}

if ( ! function_exists('eventosapp_get_last_event_date') ) {
  function eventosapp_get_last_event_date($event_id){
    $days = (array) eventosapp_get_event_days($event_id);
    if (!$days) return gmdate('Y-m-d');
    sort($days);
    return end($days); // última
  }
}

if ( ! function_exists('eventosapp_get_staff_release_ts') ) {
  /**
   * Calcula timestamp de liberación: 5 días después de la última fecha del evento,
   * usando la TZ del evento (guardamos en UTC).
   */
  function eventosapp_get_staff_release_ts($event_id){
    $last = eventosapp_get_last_event_date($event_id); // Y-m-d
    $tzid = get_post_meta($event_id, '_eventosapp_zona_horaria', true);
    if (!$tzid) $tzid = wp_timezone_string() ?: 'UTC';
    try { $tz = new DateTimeZone($tzid); } catch(Exception $e){ $tz = wp_timezone(); }
    $dt = new DateTime($last.' 00:00:00', $tz);
    $dt->modify('+5 days'); // 5 días después de la última fecha
    return $dt->getTimestamp(); // UTC
  }
}

// ---------- FUNCIÓN CRÍTICA: Determina si un usuario puede gestionar un evento ----------
if ( ! function_exists('eventosapp_user_can_manage_event') ) {
  /**
   * NUEVA: Verifica si un usuario puede gestionar/ver un evento específico
   *
   * Retorna true si el usuario cumple alguna de estas condiciones:
   * 1. Es administrador (manage_options)
   * 2. Es el autor del evento (post_author)
   * 3. Tiene acceso personalizado al dashboard frontend del evento
   * 4. Es co-gestor temporal (_evapp_temp_authors) y no ha expirado
   * 5. Es staff asignado (_evapp_event_staff_assigned) y no ha expirado
   * 6. Es staff de apoyo asignado al módulo Asistencia
   *
   * @param int $event_id ID del evento
   * @param int|null $user_id ID del usuario (null = usuario actual)
   * @return bool
   */
  function eventosapp_user_can_manage_event($event_id, $user_id = null){
    $event_id = absint($event_id);
    if (!$event_id) return false;

    // Determinar usuario
    if ($user_id === null) {
      $user_id = get_current_user_id();
    }
    $user_id = absint($user_id);
    if (!$user_id) return false;

    // 1. Administradores siempre pueden
    if (user_can($user_id, 'manage_options')) {
      return true;
    }

    // 2. Autor del evento puede
    $event = get_post($event_id);
    if (!$event || $event->post_type !== 'eventosapp_event') {
      return false;
    }

    if (absint($event->post_author) === $user_id) {
      return true;
    }

    // 3. Acceso personalizado al dashboard frontend para este evento.
    // Si el usuario existe en la nueva sección del metabox Control de Acceso Dashboard Staff,
    // esa decisión manda para el acceso general al evento desde el frontend.
    if (function_exists('eventosapp_staff_access_get_user_feature_value')) {
      $custom_dashboard_access = eventosapp_staff_access_get_user_feature_value($event_id, $user_id, 'dashboard', null);
      if ($custom_dashboard_access !== null) {
        return ((int)$custom_dashboard_access === 1);
      }
    }

    $now = time();

    // 4. Co-gestores temporales (no expirados).
    // Compatibilidad: soporta filas nuevas ['user_id'=>ID,'until'=>TS], filas indexadas
    // por ID de usuario y valores escalares heredados que pudieran existir en instalaciones previas.
    $temp_authors = get_post_meta($event_id, '_evapp_temp_authors', true);
    if (is_array($temp_authors)) {
      foreach ($temp_authors as $key => $row) {
        $row_user_id = 0;
        $until = 0;

        if (is_array($row)) {
          $row_user_id = !empty($row['user_id']) ? absint($row['user_id']) : absint($key);
          $until = isset($row['until']) ? absint($row['until']) : 0;
        } else {
          $row_user_id = absint($row);
        }

        if ($row_user_id !== $user_id) continue;

        if (!$until || $until >= $now) {
          return true; // Co-gestor válido (sin expiración o no ha expirado)
        }
      }
    }

    // 5. Staff operativo asignado (no expirado).
    // El post meta es la fuente principal y se recorre completo para aceptar estructuras
    // uid => datos y también listas numéricas con ['user_id'=>ID].
    $staff_assigned = get_post_meta($event_id, '_evapp_event_staff_assigned', true);
    if (is_array($staff_assigned)) {
      foreach ($staff_assigned as $key => $row) {
        $row_user_id = absint($key);
        $until = 0;

        if (is_array($row)) {
          $row_user_id = !empty($row['user_id']) ? absint($row['user_id']) : $row_user_id;
          $until = isset($row['until']) ? absint($row['until']) : 0;
        }

        if ($row_user_id !== $user_id) continue;

        if (!$until || $until >= $now) {
          return true; // Staff válido (sin expiración o no ha expirado)
        }
      }
    }

    // 5B. Compatibilidad con usermeta _evapp_event_assignment multi-evento.
    $user_assignments = get_user_meta($user_id, '_evapp_event_assignment', true);
    if (is_array($user_assignments)) {
      if (isset($user_assignments[$event_id]) && is_array($user_assignments[$event_id])) {
        $until = isset($user_assignments[$event_id]['until']) ? absint($user_assignments[$event_id]['until']) : 0;
        if (!$until || $until >= $now) {
          return true;
        }
      } elseif (isset($user_assignments['event_id']) && absint($user_assignments['event_id']) === $event_id) {
        $until = isset($user_assignments['until']) ? absint($user_assignments['until']) : 0;
        if (!$until || $until >= $now) {
          return true;
        }
      }
    }

    // 6. Staff de apoyo asignado al módulo Asistencia.
    // El acceso real a secciones se limita después por eventosapp_role_can().
    if ( function_exists('eventosapp_support_user_is_assigned_to_event') && eventosapp_support_user_is_assigned_to_event($event_id, $user_id) ) {
      return true;
    }

    // No cumple ninguna condición
    return false;
  }
}

// ---------- MODIFICADO: Funciones de staff multi-evento ----------

if ( ! function_exists('eventosapp_get_all_staff_users') ) {
  /**
   * Obtiene exclusivamente usuarios con rol Staff o Logístico.
   * Estos son los únicos perfiles válidos para el selector de staff operativo.
   *
   * @return WP_User[]
   */
  function eventosapp_get_all_staff_users(){
    return get_users([
      'role__in' => ['staff', 'logistico'],
      'orderby'  => 'display_name',
      'order'    => 'ASC',
      'fields'   => 'all'
    ]);
  }
}

if ( ! function_exists('eventosapp_find_free_staff') ) {
  /**
   * MODIFICADO: Ya no busca "libres" sino todos los staff disponibles
   * (porque ahora pueden estar en múltiples eventos)
   * @return int[] user_ids disponibles
   */
  function eventosapp_find_free_staff($needed, $exclude_ids = []){
    $needed = max(0, (int)$needed);
    if ($needed === 0) return [];

    $users = get_users([
      'role__in' => ['staff', 'logistico'],
      'fields'   => ['ID','user_login','user_email']
    ]);

    $out = [];
    foreach ($users as $u) {
      if (in_array($u->ID, $exclude_ids, true)) continue;
      $out[] = (int) $u->ID;
      if (count($out) >= $needed) break;
    }
    return $out;
  }
}

if ( ! function_exists('eventosapp_next_operador_number') ) {
  /**
   * Detecta el siguiente sufijo para "operadorXX"
   */
  function eventosapp_next_operador_number(){
    $users = get_users([
      'search'         => 'operador*',
      'search_columns' => ['user_login'],
      'fields'         => ['user_login']
    ]);
    $max = 0;
    foreach ($users as $u) {
      if (preg_match('/^operador(\d+)$/i', $u->user_login, $m)) {
        $n = (int)$m[1];
        if ($n > $max) $max = $n;
      }
    }
    return $max + 1;
  }
}

if ( ! function_exists('eventosapp_create_staff_bulk') ) {
  /**
   * Crea N usuarios staff: operadorXX / email operadorXX@eventosapp.com / pass _123456_
   * @return array[] lista de ['ID'=>int,'user_login'=>string,'user_email'=>string,'plain_pass'=>'_123456_']
   */
  function eventosapp_create_staff_bulk($count){
    $count = max(0,(int)$count);
    if ($count === 0) return [];
    $list = [];
    $n = eventosapp_next_operador_number();

    for ($i=0; $i<$count; $i++) {
      $login = 'operador'.$n;
      $email = $login.'@eventosapp.com';
      $pass  = '_123456_';

      $uid = wp_insert_user([
        'user_login'   => $login,
        'user_pass'    => $pass,
        'user_email'   => $email,
        'first_name'   => 'operador',
        'last_name'    => (string)$n,
        'display_name' => 'operador '.$n,
        'role'         => 'staff',
        'show_admin_bar_front' => 'false'
      ]);
      if (!is_wp_error($uid)) {
        $list[] = ['ID'=>(int)$uid,'user_login'=>$login,'user_email'=>$email,'plain_pass'=>$pass];
      }
      $n++;
    }
    return $list;
  }
}

if ( ! function_exists('eventosapp_assign_staff_to_event') ) {
  /**
   * MODIFICADO: Asigna staff al evento permitiendo múltiples eventos simultáneos
   */
  function eventosapp_assign_staff_to_event($event_id, $user_ids, $release_ts){
    $event_id   = (int)$event_id;
    $release_ts = (int)$release_ts;
    if (!$event_id || !$user_ids) return;

    // 1. Actualizar postmeta del evento
    $assigned = get_post_meta($event_id, '_evapp_event_staff_assigned', true);
    if (!is_array($assigned)) $assigned = [];

    foreach ($user_ids as $uid) {
      $uid = (int)$uid;
      $assigned[$uid] = ['user_id'=>$uid, 'until'=>$release_ts];

      // 2. Actualizar usermeta del usuario (ahora es un array de eventos)
      $user_assignments = get_user_meta($uid, '_evapp_event_assignment', true);
      if (!is_array($user_assignments)) $user_assignments = [];

      // Agregar o actualizar este evento en el array del usuario
      $user_assignments[$event_id] = ['event_id'=>$event_id, 'until'=>$release_ts];
      update_user_meta($uid, '_evapp_event_assignment', $user_assignments);
    }
    update_post_meta($event_id, '_evapp_event_staff_assigned', $assigned);
  }
}

if ( ! function_exists('eventosapp_remove_staff_from_event') ) {
  /**
   * Libera un Staff/Logístico de un evento específico y limpia ambas fuentes.
   * También reconoce estructuras heredadas no indexadas por ID de usuario.
   */
  function eventosapp_remove_staff_from_event($event_id, $user_id){
    $event_id = absint($event_id);
    $user_id  = absint($user_id);
    if (!$event_id || !$user_id) return;

    $assigned = get_post_meta($event_id, '_evapp_event_staff_assigned', true);
    if (is_array($assigned)) {
      foreach ($assigned as $key => $row) {
        $row_user_id = absint($key);
        if (is_array($row) && !empty($row['user_id'])) {
          $row_user_id = absint($row['user_id']);
        } elseif (!is_array($row) && absint($row)) {
          $row_user_id = absint($row);
        }

        if ($row_user_id === $user_id) unset($assigned[$key]);
      }

      if ($assigned) update_post_meta($event_id, '_evapp_event_staff_assigned', $assigned);
      else delete_post_meta($event_id, '_evapp_event_staff_assigned');
    }

    $user_assignments = get_user_meta($user_id, '_evapp_event_assignment', true);
    if (is_array($user_assignments) && isset($user_assignments[$event_id])) {
      unset($user_assignments[$event_id]);
      if (empty($user_assignments)) delete_user_meta($user_id, '_evapp_event_assignment');
      else update_user_meta($user_id, '_evapp_event_assignment', $user_assignments);
    } elseif (is_array($user_assignments) && isset($user_assignments['event_id']) && absint($user_assignments['event_id']) === $event_id) {
      delete_user_meta($user_id, '_evapp_event_assignment');
    }
  }
}

if ( ! function_exists('eventosapp_get_event_staff_assigned') ) {
  /**
   * Lee, normaliza y depura el staff asignado. El valor 0 en "until" representa
   * un permiso sin vencimiento y nunca se purga automáticamente.
   *
   * @return array uid => ['user_id'=>int,'until'=>int]
   */
  function eventosapp_get_event_staff_assigned($event_id){
    $event_id = absint($event_id);
    $stored = get_post_meta($event_id, '_evapp_event_staff_assigned', true);
    if (!is_array($stored)) $stored = [];

    $normalized = [];
    $now = time();
    $changed = false;

    foreach ($stored as $key => $row) {
      $user_id = absint($key);
      $until = 0;

      if (is_array($row)) {
        if (!empty($row['user_id'])) $user_id = absint($row['user_id']);
        $until = isset($row['until']) ? absint($row['until']) : 0;
      } elseif (absint($row)) {
        $user_id = absint($row);
      }

      if (!$user_id) {
        $changed = true;
        continue;
      }

      if ($until && $until < $now) {
        eventosapp_remove_staff_from_event($event_id, $user_id);
        $changed = true;
        continue;
      }

      $normalized[$user_id] = ['user_id' => $user_id, 'until' => $until];
      if ((string)$key !== (string)$user_id || !is_array($row)) $changed = true;
    }

    if ($changed || $normalized !== $stored) {
      if ($normalized) update_post_meta($event_id, '_evapp_event_staff_assigned', $normalized);
      else delete_post_meta($event_id, '_evapp_event_staff_assigned');
    }

    return $normalized;
  }
}

if ( ! function_exists('eventosapp_sanitize_absint_list') ) {
  /**
   * Normaliza una lista de IDs recibida por POST, quitando valores vacíos, repetidos o inválidos.
   *
   * @param mixed $raw Valor recibido desde POST.
   * @return int[]
   */
  function eventosapp_sanitize_absint_list($raw){
    if (!is_array($raw)) {
      $raw = [$raw];
    }

    $ids = [];
    foreach ($raw as $value) {
      if (is_array($value)) {
        continue;
      }

      $id = absint($value);
      if ($id > 0) {
        $ids[] = $id;
      }
    }

    return array_values(array_unique($ids));
  }
}


if ( ! function_exists('eventosapp_assignment_date_to_timestamp') ) {
  /**
   * Convierte una fecha Y-m-d en el final de ese día usando la zona horaria de WordPress.
   * Cuando $never_expires es true retorna 0, valor histórico usado por EventosApp
   * para representar un permiso sin vencimiento.
   */
  function eventosapp_assignment_date_to_timestamp($date, $never_expires = false, $fallback = 0){
    if ($never_expires) return 0;

    $date = sanitize_text_field((string)$date);
    if ($date === '') return max(0, (int)$fallback);

    try {
      $dt = new DateTimeImmutable($date . ' 23:59:59', wp_timezone());
      return $dt->getTimestamp();
    } catch (Exception $e) {
      return max(0, (int)$fallback);
    }
  }
}

if ( ! function_exists('eventosapp_assignment_timestamp_to_date') ) {
  function eventosapp_assignment_timestamp_to_date($timestamp, $fallback_timestamp = 0){
    $timestamp = (int)$timestamp;
    if (!$timestamp) $timestamp = (int)$fallback_timestamp;
    return $timestamp ? wp_date('Y-m-d', $timestamp, wp_timezone()) : '';
  }
}

if ( ! function_exists('eventosapp_normalize_temp_authors') ) {
  /**
   * Normaliza estructuras antiguas y actuales de co-gestores, indexadas por usuario.
   */
  function eventosapp_normalize_temp_authors($rows){
    if (!is_array($rows)) return [];

    $out = [];
    foreach ($rows as $key => $row) {
      $user_id = 0;
      $until = 0;
      $granted_by = 0;

      if (is_array($row)) {
        $user_id = !empty($row['user_id']) ? absint($row['user_id']) : absint($key);
        $until = isset($row['until']) ? absint($row['until']) : 0;
        $granted_by = isset($row['granted_by']) ? absint($row['granted_by']) : 0;
      } else {
        $user_id = absint($row);
      }

      if (!$user_id) continue;
      $out[$user_id] = [
        'user_id'    => $user_id,
        'until'      => $until,
        'granted_by' => $granted_by,
      ];
    }

    ksort($out, SORT_NUMERIC);
    return $out;
  }
}

if ( ! function_exists('eventosapp_user_has_staff_or_logistico_role') ) {
  function eventosapp_user_has_staff_or_logistico_role($user){
    if (is_numeric($user)) $user = get_userdata(absint($user));
    if (!$user instanceof WP_User) return false;
    return (bool) array_intersect(['staff', 'logistico'], (array)$user->roles);
  }
}

if ( ! function_exists('eventosapp_update_staff_assignment_until') ) {
  /**
   * Actualiza en una sola operación el vencimiento almacenado en el evento y en el usuario.
   */
  function eventosapp_update_staff_assignment_until($event_id, $user_id, $until){
    $event_id = absint($event_id);
    $user_id = absint($user_id);
    $until = absint($until);
    if (!$event_id || !$user_id) return false;

    $assigned = get_post_meta($event_id, '_evapp_event_staff_assigned', true);
    if (!is_array($assigned)) $assigned = [];
    if (!isset($assigned[$user_id]) || !is_array($assigned[$user_id])) return false;

    $assigned[$user_id]['user_id'] = $user_id;
    $assigned[$user_id]['until'] = $until;
    update_post_meta($event_id, '_evapp_event_staff_assigned', $assigned);

    $user_assignments = get_user_meta($user_id, '_evapp_event_assignment', true);
    if (!is_array($user_assignments)) $user_assignments = [];
    $user_assignments[$event_id] = ['event_id' => $event_id, 'until' => $until];
    update_user_meta($user_id, '_evapp_event_assignment', $user_assignments);

    return true;
  }
}

// ---------- Metabox ----------
add_action('add_meta_boxes', function(){
  add_meta_box(
    'evapp_co_gestion',
    'Co-gestión temporal & Staff operativo',
    'eventosapp_render_metabox_co_gestion',
    'eventosapp_event',
    'side',
    'high'
  );
});

function eventosapp_render_metabox_co_gestion($post){
  wp_nonce_field('evapp_co_gestion_save','evapp_co_gestion_nonce');

  $event_id = absint($post->ID);
  $release_ts = eventosapp_get_staff_release_ts($event_id);
  $default_date = eventosapp_assignment_timestamp_to_date($release_ts, $release_ts);

  $temp_authors = eventosapp_normalize_temp_authors(get_post_meta($event_id, '_evapp_temp_authors', true));
  $co_user_ids = array_map('intval', array_keys($temp_authors));

  // Conserva los perfiles históricos habilitados para co-gestión.
  $co_candidates = get_users([
    'role__in' => ['administrator','organizador'],
    'orderby'  => 'display_name',
    'order'    => 'ASC',
    'fields'   => 'all'
  ]);

  $required = max(0, (int)get_post_meta($event_id, '_evapp_event_staff_required', true));
  $assigned = eventosapp_get_event_staff_assigned($event_id);
  $all_staff = eventosapp_get_all_staff_users();
  $support_staff_ids = function_exists('eventosapp_support_get_event_staff_user_ids')
    ? array_map('intval', (array)eventosapp_support_get_event_staff_user_ids($event_id))
    : [];
  ?>
  <style>
    .evapp-mini{font-size:11px;color:#646970;margin:4px 0;line-height:1.35}
    .evapp-table-wrap{overflow:auto;margin:8px 0}
    .evapp-table{width:100%;min-width:620px;border-collapse:collapse;font-size:11px;background:#fff}
    .evapp-table th,.evapp-table td{border:1px solid #dcdcde;padding:6px;vertical-align:middle}
    .evapp-table th{background:#f6f7f7;text-align:left}
    .evapp-badge{display:inline-block;background:#f0f0f1;border:1px solid #dcdcde;padding:3px 7px;border-radius:999px;font-size:11px}
    .evapp-danger{color:#b32d2e;font-weight:600}
    .evapp-assignment-box{margin-top:12px;border:1px solid #dcdcde;padding:10px;border-radius:6px;background:#f9f9f9}
    .evapp-assignment-box label.evapp-main-label{display:block;margin-bottom:5px;font-weight:600}
    .evapp-multi-select{width:100%;min-height:150px;margin-bottom:7px}
    .evapp-tools{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:7px 0}
    .evapp-tools .button{min-height:28px;line-height:26px;padding:0 9px;font-size:11px}
    .evapp-count{color:#2271b1;font-weight:600;font-size:11px}
    .evapp-expiry-grid{display:grid;grid-template-columns:minmax(135px,1fr) auto;gap:7px;align-items:center}
    .evapp-expiry-grid input[type="date"]{width:100%;min-width:130px}
    .evapp-never-label{display:flex;align-items:center;gap:4px;white-space:nowrap;font-size:11px}
    .evapp-role-pill{display:inline-block;border-radius:999px;background:#e8f2fc;color:#135e96;padding:2px 6px;font-size:10px;margin-top:3px}
  </style>

  <div id="evapp-co-gestion-wrap">
    <strong>Co-gestores</strong>
    <div class="evapp-mini">Organizadores o administradores autorizados para gestionar este evento. Cada permiso puede vencer o permanecer activo sin límite.</div>

    <?php if ($temp_authors): ?>
      <div class="evapp-table-wrap">
        <table class="evapp-table">
          <thead><tr><th>Usuario</th><th>Vencimiento</th><th>Quitar</th></tr></thead>
          <tbody>
          <?php foreach ($temp_authors as $uid => $row):
            $uid = absint($uid);
            $u = get_userdata($uid);
            $until = absint($row['until'] ?? 0);
            $never = !$until;
            ?>
            <tr>
              <td>
                <?php echo $u ? esc_html($u->display_name ?: $u->user_login) : ('#'.$uid); ?><br>
                <small><?php echo $u ? esc_html($u->user_email) : ''; ?></small>
              </td>
              <td>
                <div class="evapp-expiry-grid" data-evapp-expiry>
                  <input type="date" name="evapp_co_existing[<?php echo $uid; ?>][until_date]" value="<?php echo esc_attr($never ? $default_date : eventosapp_assignment_timestamp_to_date($until)); ?>" <?php disabled($never); ?>>
                  <label class="evapp-never-label"><input type="checkbox" name="evapp_co_existing[<?php echo $uid; ?>][never]" value="1" data-never <?php checked($never); ?>> Sin vencimiento</label>
                </div>
              </td>
              <td><label><input type="checkbox" name="evapp_co_remove[]" value="<?php echo $uid; ?>"> Eliminar</label></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="evapp-mini">No hay co-gestores asignados.</div>
    <?php endif; ?>

    <div class="evapp-assignment-box">
      <label class="evapp-main-label" for="evapp_co_add_users">Agregar varios co-gestores</label>
      <select name="evapp_co_add_users[]" id="evapp_co_add_users" class="evapp-multi-select" multiple size="7">
        <?php foreach ($co_candidates as $u):
          $already = in_array((int)$u->ID, $co_user_ids, true);
          ?>
          <option value="<?php echo (int)$u->ID; ?>" <?php disabled($already); ?>>
            <?php echo esc_html(($u->display_name ?: $u->user_login).' - '.$u->user_login.' ('.$u->user_email.')'.($already ? ' — ya asignado' : '')); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="evapp-expiry-grid" data-evapp-expiry>
        <input type="date" name="evapp_co_add_until" value="<?php echo esc_attr($default_date); ?>">
        <label class="evapp-never-label"><input type="checkbox" name="evapp_co_add_never" value="1" data-never> Sin vencimiento</label>
      </div>
      <div class="evapp-mini">Usa Ctrl/Cmd + clic o Shift + clic para seleccionar varios usuarios.</div>
    </div>
  </div>

  <hr>

  <div id="evapp-staff-operativo-wrap">
    <strong>Staff operativo</strong>
    <div class="evapp-mini">Solo se admiten usuarios con rol <b>Staff</b> o <b>Logístico</b>. Pueden gestionar varios eventos simultáneamente.</div>

    <label for="evapp_staff_required">Cantidad requerida:</label>
    <input id="evapp_staff_required" type="number" min="0" name="evapp_staff_required" value="<?php echo (int)$required; ?>" style="width:100%;">
    <div style="margin:7px 0"><span class="evapp-badge">Vencimiento sugerido: <?php echo esc_html($default_date); ?></span></div>

    <?php if ($assigned): ?>
      <div class="evapp-table-wrap">
        <table class="evapp-table">
          <thead><tr><th>Usuario</th><th>Rol</th><th>Vencimiento</th><th>Pass</th><th>Quitar</th></tr></thead>
          <tbody>
          <?php foreach ($assigned as $uid => $row):
            $uid = absint($uid);
            $u = get_userdata($uid);
            $until = is_array($row) ? absint($row['until'] ?? 0) : 0;
            $never = !$until;
            $roles = $u ? array_intersect(['staff','logistico'], (array)$u->roles) : [];
            ?>
            <tr>
              <td><?php echo $u ? esc_html($u->display_name ?: $u->user_login) : ('#'.$uid); ?><br><small><?php echo $u ? esc_html($u->user_email) : ''; ?></small></td>
              <td><span class="evapp-role-pill"><?php echo esc_html($roles ? implode(', ', $roles) : '—'); ?></span></td>
              <td>
                <div class="evapp-expiry-grid" data-evapp-expiry>
                  <input type="date" name="evapp_staff_existing[<?php echo $uid; ?>][until_date]" value="<?php echo esc_attr($never ? $default_date : eventosapp_assignment_timestamp_to_date($until)); ?>" <?php disabled($never); ?>>
                  <label class="evapp-never-label"><input type="checkbox" name="evapp_staff_existing[<?php echo $uid; ?>][never]" value="1" data-never <?php checked($never); ?>> Sin vencimiento</label>
                </div>
              </td>
              <td><code>_123456_</code></td>
              <td><label><input type="checkbox" class="evapp-staff-remove-check" name="evapp_staff_remove[]" value="<?php echo $uid; ?>"> Eliminar</label></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="evapp-tools" style="justify-content:flex-end"><label><input type="checkbox" id="evapp_staff_remove_all"> Marcar todos</label><span id="evapp_staff_remove_count" class="evapp-count">0 seleccionados para quitar</span></div>
    <?php else: ?>
      <div class="evapp-mini">Sin staff asignado.</div>
    <?php endif; ?>

    <div class="evapp-assignment-box">
      <label class="evapp-main-label">Agregar staff manualmente</label>
      <select name="evapp_staff_add_manual_users[]" id="evapp_staff_manual_select" class="evapp-multi-select" multiple size="8">
        <?php foreach ($all_staff as $staff_user):
          $already_assigned = isset($assigned[$staff_user->ID]);
          $already_support = in_array((int)$staff_user->ID, $support_staff_ids, true);
          $disabled = $already_assigned || $already_support;
          $suffix = $already_assigned ? ' — ya asignado' : ($already_support ? ' — asignado en Asistencia' : '');
          $allowed_roles = array_intersect(['staff','logistico'], (array)$staff_user->roles);
          ?>
          <option value="<?php echo (int)$staff_user->ID; ?>" <?php disabled($disabled); ?>>
            <?php echo esc_html(($staff_user->display_name ?: $staff_user->user_login).' - '.$staff_user->user_login.' ('.implode('/', $allowed_roles).')'.$suffix); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="evapp-tools">
        <button type="button" class="button" id="evapp_staff_select_all_available">Seleccionar disponibles</button>
        <button type="button" class="button" id="evapp_staff_clear_selection">Limpiar selección</button>
        <span id="evapp_staff_add_count" class="evapp-count">0 seleccionados para agregar</span>
      </div>
      <div class="evapp-expiry-grid" data-evapp-expiry>
        <input type="date" name="evapp_staff_add_manual_until" value="<?php echo esc_attr($default_date); ?>">
        <label class="evapp-never-label"><input type="checkbox" name="evapp_staff_add_manual_never" value="1" data-never> Sin vencimiento</label>
      </div>
      <input type="submit" class="button button-primary" name="evapp_staff_btn_add_manual" value="➕ Agregar staff seleccionados" style="width:100%;margin-top:8px">
    </div>

    <div class="evapp-assignment-box" style="border-color:#72aee6;background:#f0f6fc">
      <label class="evapp-main-label">Autocompletar staff requerido</label>
      <div class="evapp-mini">Completa hasta la cantidad requerida usando Staff/Logístico disponibles y crea usuarios Staff si hacen falta.</div>
      <div class="evapp-expiry-grid" data-evapp-expiry>
        <input type="date" name="evapp_staff_autocomplete_until" value="<?php echo esc_attr($default_date); ?>">
        <label class="evapp-never-label"><input type="checkbox" name="evapp_staff_autocomplete_never" value="1" data-never> Sin vencimiento</label>
      </div>
      <input type="submit" class="button button-secondary" name="evapp_staff_btn_autocomplete" value="🔄 Autocompletar hasta cantidad requerida" style="width:100%;margin-top:8px">
    </div>

    <div class="evapp-mini evapp-danger" style="margin-top:8px">* La contraseña por defecto para usuarios Staff creados automáticamente es <code>_123456_</code>.</div>
  </div>

  <script>
  (function(){
    function toggleExpiry(container){
      var never = container.querySelector('[data-never]');
      var date = container.querySelector('input[type="date"]');
      if (!never || !date) return;
      date.disabled = !!never.checked;
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-evapp-expiry]'), function(container){
      var never = container.querySelector('[data-never]');
      toggleExpiry(container);
      if (never) never.addEventListener('change', function(){ toggleExpiry(container); });
    });

    var manualSelect = document.getElementById('evapp_staff_manual_select');
    var selectAllBtn = document.getElementById('evapp_staff_select_all_available');
    var clearBtn = document.getElementById('evapp_staff_clear_selection');
    var addCount = document.getElementById('evapp_staff_add_count');
    var removeAll = document.getElementById('evapp_staff_remove_all');
    var removeCount = document.getElementById('evapp_staff_remove_count');

    function updateAddCount(){
      if (!manualSelect || !addCount) return;
      var count = Array.prototype.filter.call(manualSelect.options, function(option){ return option.selected && !option.disabled && option.value; }).length;
      addCount.textContent = count + (count === 1 ? ' seleccionado para agregar' : ' seleccionados para agregar');
    }
    function removeChecks(){ return Array.prototype.slice.call(document.querySelectorAll('.evapp-staff-remove-check')); }
    function updateRemoveCount(){
      var checks = removeChecks();
      var checked = checks.filter(function(input){ return input.checked; }).length;
      if (removeCount) removeCount.textContent = checked + (checked === 1 ? ' seleccionado para quitar' : ' seleccionados para quitar');
      if (removeAll) {
        removeAll.checked = checks.length > 0 && checked === checks.length;
        removeAll.indeterminate = checked > 0 && checked < checks.length;
      }
    }
    if (manualSelect) manualSelect.addEventListener('change', updateAddCount);
    if (selectAllBtn && manualSelect) selectAllBtn.addEventListener('click', function(e){ e.preventDefault(); Array.prototype.forEach.call(manualSelect.options, function(o){ if (!o.disabled && o.value) o.selected = true; }); updateAddCount(); });
    if (clearBtn && manualSelect) clearBtn.addEventListener('click', function(e){ e.preventDefault(); Array.prototype.forEach.call(manualSelect.options, function(o){ o.selected = false; }); updateAddCount(); });
    removeChecks().forEach(function(input){ input.addEventListener('change', updateRemoveCount); });
    if (removeAll) removeAll.addEventListener('change', function(){ var value = removeAll.checked; removeChecks().forEach(function(input){ input.checked = value; }); updateRemoveCount(); });
    updateAddCount();
    updateRemoveCount();
  })();
  </script>
  <?php
}

// ---------- Guardado ----------
add_action('save_post_eventosapp_event', function($post_id){
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
  if (empty($_POST['evapp_co_gestion_nonce']) || !wp_verify_nonce(wp_unslash($_POST['evapp_co_gestion_nonce']), 'evapp_co_gestion_save')) return;
  if (!current_user_can('edit_post', $post_id)) return;

  $release_ts = eventosapp_get_staff_release_ts($post_id);
  $current_user_id = get_current_user_id();

  // 1) Co-gestores existentes: actualizar vencimiento o eliminar.
  $co = eventosapp_normalize_temp_authors(get_post_meta($post_id, '_evapp_temp_authors', true));
  $remove_co = isset($_POST['evapp_co_remove']) ? eventosapp_sanitize_absint_list(wp_unslash($_POST['evapp_co_remove'])) : [];
  $existing_co_input = isset($_POST['evapp_co_existing']) && is_array($_POST['evapp_co_existing'])
    ? wp_unslash($_POST['evapp_co_existing'])
    : [];

  foreach ($co as $uid => &$row) {
    $uid = absint($uid);
    if (in_array($uid, $remove_co, true)) {
      unset($co[$uid]);
      continue;
    }

    $input = isset($existing_co_input[$uid]) && is_array($existing_co_input[$uid]) ? $existing_co_input[$uid] : [];
    $never = !empty($input['never']);
    $date = isset($input['until_date']) ? sanitize_text_field($input['until_date']) : '';
    $row['until'] = eventosapp_assignment_date_to_timestamp($date, $never, $row['until'] ?? $release_ts);
    $row['user_id'] = $uid;
  }
  unset($row);

  // 2) Agregar varios co-gestores con un mismo comportamiento de vencimiento.
  $new_co_ids = isset($_POST['evapp_co_add_users']) ? eventosapp_sanitize_absint_list(wp_unslash($_POST['evapp_co_add_users'])) : [];
  // Compatibilidad con el selector único anterior.
  $legacy_co_id = isset($_POST['evapp_co_add_user']) ? absint($_POST['evapp_co_add_user']) : 0;
  if ($legacy_co_id) $new_co_ids[] = $legacy_co_id;
  $new_co_ids = array_values(array_unique(array_filter($new_co_ids)));

  $new_co_never = !empty($_POST['evapp_co_add_never']);
  $new_co_date = isset($_POST['evapp_co_add_until']) ? sanitize_text_field(wp_unslash($_POST['evapp_co_add_until'])) : '';
  $new_co_until = eventosapp_assignment_date_to_timestamp($new_co_date, $new_co_never, $release_ts);

  foreach ($new_co_ids as $uid) {
    $user = get_userdata($uid);
    if (!$user || !array_intersect(['administrator','organizador'], (array)$user->roles)) continue;
    $co[$uid] = [
      'user_id'    => $uid,
      'until'      => $new_co_until,
      'granted_by' => $current_user_id,
    ];
  }

  if ($co) {
    ksort($co, SORT_NUMERIC);
    update_post_meta($post_id, '_evapp_temp_authors', array_values($co));
  } else {
    delete_post_meta($post_id, '_evapp_temp_authors');
  }

  // 3) Cantidad requerida.
  $required = isset($_POST['evapp_staff_required']) ? max(0, (int)$_POST['evapp_staff_required']) : 0;
  update_post_meta($post_id, '_evapp_event_staff_required', $required);

  // 4) Staff existente: eliminar o actualizar vencimiento en postmeta y usermeta.
  $assigned = eventosapp_get_event_staff_assigned($post_id);
  $remove_staff = isset($_POST['evapp_staff_remove']) ? eventosapp_sanitize_absint_list(wp_unslash($_POST['evapp_staff_remove'])) : [];
  $existing_staff_input = isset($_POST['evapp_staff_existing']) && is_array($_POST['evapp_staff_existing'])
    ? wp_unslash($_POST['evapp_staff_existing'])
    : [];

  foreach (array_keys($assigned) as $uid) {
    $uid = absint($uid);
    if (in_array($uid, $remove_staff, true)) {
      eventosapp_remove_staff_from_event($post_id, $uid);
      continue;
    }

    $input = isset($existing_staff_input[$uid]) && is_array($existing_staff_input[$uid]) ? $existing_staff_input[$uid] : [];
    $never = !empty($input['never']);
    $date = isset($input['until_date']) ? sanitize_text_field($input['until_date']) : '';
    $current_until = is_array($assigned[$uid]) ? absint($assigned[$uid]['until'] ?? 0) : 0;
    $until = eventosapp_assignment_date_to_timestamp($date, $never, $current_until ?: $release_ts);
    eventosapp_update_staff_assignment_until($post_id, $uid, $until);
  }

  // 5) Agregar Staff/Logístico seleccionados manualmente.
  if (!empty($_POST['evapp_staff_btn_add_manual'])) {
    $manual_ids = isset($_POST['evapp_staff_add_manual_users'])
      ? eventosapp_sanitize_absint_list(wp_unslash($_POST['evapp_staff_add_manual_users']))
      : [];
    $legacy_manual_id = isset($_POST['evapp_staff_add_manual_user']) ? absint($_POST['evapp_staff_add_manual_user']) : 0;
    if ($legacy_manual_id) $manual_ids[] = $legacy_manual_id;
    $manual_ids = array_values(array_unique(array_filter($manual_ids)));

    $manual_never = !empty($_POST['evapp_staff_add_manual_never']);
    $manual_date = isset($_POST['evapp_staff_add_manual_until']) ? sanitize_text_field(wp_unslash($_POST['evapp_staff_add_manual_until'])) : '';
    $manual_until = eventosapp_assignment_date_to_timestamp($manual_date, $manual_never, $release_ts);

    $assigned_refresh = eventosapp_get_event_staff_assigned($post_id);
    $support_ids = function_exists('eventosapp_support_get_event_staff_user_ids')
      ? array_map('intval', (array)eventosapp_support_get_event_staff_user_ids($post_id))
      : [];
    $staff_to_add = [];

    foreach ($manual_ids as $uid) {
      $user = get_userdata($uid);
      if (!eventosapp_user_has_staff_or_logistico_role($user)) continue;
      if (isset($assigned_refresh[$uid]) || in_array($uid, $support_ids, true)) continue;
      $staff_to_add[] = $uid;
      $assigned_refresh[$uid] = ['user_id' => $uid, 'until' => $manual_until];
    }

    if ($staff_to_add) eventosapp_assign_staff_to_event($post_id, $staff_to_add, $manual_until);
  }

  // 6) Autocompletar hasta la cantidad requerida.
  if (!empty($_POST['evapp_staff_btn_autocomplete'])) {
    $autocomplete_never = !empty($_POST['evapp_staff_autocomplete_never']);
    $autocomplete_date = isset($_POST['evapp_staff_autocomplete_until']) ? sanitize_text_field(wp_unslash($_POST['evapp_staff_autocomplete_until'])) : '';
    $autocomplete_until = eventosapp_assignment_date_to_timestamp($autocomplete_date, $autocomplete_never, $release_ts);

    $assigned_refresh = eventosapp_get_event_staff_assigned($post_id);
    $current = count($assigned_refresh);
    if ($required > $current) {
      $need = $required - $current;
      $support_ids = function_exists('eventosapp_support_get_event_staff_user_ids')
        ? array_map('intval', (array)eventosapp_support_get_event_staff_user_ids($post_id))
        : [];
      $exclude = array_values(array_unique(array_merge(array_map('intval', array_keys($assigned_refresh)), $remove_staff, $support_ids)));
      $selected = eventosapp_find_free_staff($need, $exclude);

      $missing = $need - count($selected);
      if ($missing > 0) {
        $created = eventosapp_create_staff_bulk($missing);
        $selected = array_merge($selected, array_map(static function($row){ return absint($row['ID'] ?? 0); }, $created));
        update_post_meta($post_id, '_evapp_last_created_staff', $created);
      }

      $selected = array_values(array_filter(array_unique($selected)));
      if ($selected) eventosapp_assign_staff_to_event($post_id, $selected, $autocomplete_until);
    }
  }
}, 30);

// ---------- Limpieza automática diaria (libera staff vencido y co-gestores vencidos) ----------
add_action('init', function(){
  if ( ! wp_next_scheduled('evapp_daily_staff_release') ) {
    wp_schedule_event( time() + 300, 'daily', 'evapp_daily_staff_release' );
  }
});

add_action('evapp_daily_staff_release', function(){
  // 1) MODIFICADO: Liberar staff expirado considerando múltiples eventos
  $staff = get_users(['role__in'=>['staff','logistico'], 'fields'=>['ID']]);
  $now = time();

  foreach ($staff as $u) {
    $assignments = get_user_meta($u->ID, '_evapp_event_assignment', true);
    if (is_array($assignments) && !empty($assignments)) {
      $changed = false;
      foreach ($assignments as $event_id => $data) {
        if (!empty($data['until']) && (int)$data['until'] < $now) {
          unset($assignments[$event_id]);
          $changed = true;
        }
      }
      if ($changed) {
        if (empty($assignments)) {
          delete_user_meta($u->ID, '_evapp_event_assignment');
        } else {
          update_user_meta($u->ID, '_evapp_event_assignment', $assignments);
        }
      }
    }
  }

  // 2) Purgar asignaciones expiradas y co-gestores por evento
  $events = get_posts([
    'post_type'   => 'eventosapp_event',
    'post_status' => ['publish','draft','pending','private'],
    'numberposts' => -1,
    'fields'      => 'ids'
  ]);

  foreach ($events as $eid) {
    // staff asignado
    $assigned = get_post_meta($eid, '_evapp_event_staff_assigned', true);
    if (is_array($assigned) && $assigned) {
      $changed = false;
      foreach ($assigned as $uid=>$row) {
        $until = isset($row['until']) ? (int)$row['until'] : 0;
        if ($until && $until < $now) {
          // Liberar usando la nueva función
          eventosapp_remove_staff_from_event($eid, $uid);
          unset($assigned[$uid]);
          $changed=true;
        }
      }
      if ($changed) update_post_meta($eid, '_evapp_event_staff_assigned', $assigned);
    }
    // co-gestores
    $co = get_post_meta($eid, '_evapp_temp_authors', true);
    if (is_array($co) && $co) {
      $co = array_values(array_filter($co, function($r) use($now){
        return is_array($r) && !empty($r['user_id']) && (empty($r['until']) || (int)$r['until'] >= $now);
      }));
      update_post_meta($eid, '_evapp_temp_authors', $co);
    }
  }
});
