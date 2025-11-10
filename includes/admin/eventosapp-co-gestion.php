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
   * 3. Es co-gestor temporal (_evapp_temp_authors) y no ha expirado
   * 4. Es staff asignado (_evapp_event_staff_assigned) y no ha expirado
   * 
   * @param int $event_id ID del evento
   * @param int|null $user_id ID del usuario (null = usuario actual)
   * @return bool
   */
  function eventosapp_user_can_manage_event($event_id, $user_id = null){
    $event_id = (int)$event_id;
    if (!$event_id) return false;

    // Determinar usuario
    if ($user_id === null) {
      $user_id = get_current_user_id();
    }
    $user_id = (int)$user_id;
    if (!$user_id) return false;

    // 1. Administradores siempre pueden
    if (user_can($user_id, 'manage_options')) {
      return true;
    }

    // 2. Autor del evento puede
    $event = get_post($event_id);
    if ($event && (int)$event->post_author === $user_id) {
      return true;
    }

    $now = time();

    // 3. Co-gestores temporales (no expirados)
    $temp_authors = get_post_meta($event_id, '_evapp_temp_authors', true);
    if (is_array($temp_authors)) {
      foreach ($temp_authors as $row) {
        if (!is_array($row)) continue;
        if (empty($row['user_id'])) continue;
        if ((int)$row['user_id'] !== $user_id) continue;
        
        // Verificar expiración
        $until = isset($row['until']) ? (int)$row['until'] : 0;
        if ($until === 0 || $until >= $now) {
          return true; // Co-gestor válido (sin expiración o no ha expirado)
        }
      }
    }

    // 4. Staff asignado (no expirado)
    $staff_assigned = get_post_meta($event_id, '_evapp_event_staff_assigned', true);
    if (is_array($staff_assigned) && isset($staff_assigned[$user_id])) {
      $staff_data = $staff_assigned[$user_id];
      $until = isset($staff_data['until']) ? (int)$staff_data['until'] : 0;
      if ($until === 0 || $until >= $now) {
        return true; // Staff válido (sin expiración o no ha expirado)
      }
    }

    // No cumple ninguna condición
    return false;
  }
}

// ---------- MODIFICADO: Funciones de staff multi-evento ----------

if ( ! function_exists('eventosapp_get_all_staff_users') ) {
  /**
   * NUEVO: Obtiene todos los usuarios con rol 'staff' para el select de búsqueda
   * @return array de objetos WP_User con ID, user_login, user_email, display_name
   */
  function eventosapp_get_all_staff_users(){
    return get_users([
      'role'    => 'staff',
      'orderby' => 'display_name',
      'order'   => 'ASC',
      'fields'  => ['ID', 'user_login', 'user_email', 'display_name']
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
      'role'   => 'staff',
      'fields' => ['ID','user_login','user_email']
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
   * NUEVO: Libera un staff de un evento específico
   */
  function eventosapp_remove_staff_from_event($event_id, $user_id){
    $event_id = (int)$event_id;
    $user_id  = (int)$user_id;
    
    // 1. Remover del postmeta del evento
    $assigned = get_post_meta($event_id, '_evapp_event_staff_assigned', true);
    if (is_array($assigned) && isset($assigned[$user_id])) {
      unset($assigned[$user_id]);
      update_post_meta($event_id, '_evapp_event_staff_assigned', $assigned);
    }
    
    // 2. Remover del usermeta del usuario
    $user_assignments = get_user_meta($user_id, '_evapp_event_assignment', true);
    if (is_array($user_assignments) && isset($user_assignments[$event_id])) {
      unset($user_assignments[$event_id]);
      if (empty($user_assignments)) {
        delete_user_meta($user_id, '_evapp_event_assignment');
      } else {
        update_user_meta($user_id, '_evapp_event_assignment', $user_assignments);
      }
    }
  }
}

if ( ! function_exists('eventosapp_get_event_staff_assigned') ) {
  /**
   * MODIFICADO: Lee y depura staff asignado (remueve expirados) considerando múltiples eventos
   * @return array uid => ['user_id'=>int,'until'=>int]
   */
  function eventosapp_get_event_staff_assigned($event_id){
    $assigned = get_post_meta($event_id, '_evapp_event_staff_assigned', true);
    if (!is_array($assigned)) $assigned = [];
    $now = time();
    $changed = false;
    
    foreach ($assigned as $uid => $row) {
      $u = (int)$uid;
      $until = isset($row['until']) ? (int)$row['until'] : 0;
      if ($until && $until < $now) {
        // Liberar de este evento específico
        eventosapp_remove_staff_from_event($event_id, $u);
        unset($assigned[$uid]);
        $changed = true;
      }
    }
    
    if ($changed) update_post_meta($event_id, '_evapp_event_staff_assigned', $assigned);
    return $assigned;
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

  $event_id = $post->ID;
  $release_ts = eventosapp_get_staff_release_ts($event_id);

  // Co-gestores
  $temp_authors = get_post_meta($event_id, '_evapp_temp_authors', true);
  if (!is_array($temp_authors)) $temp_authors = [];

  // Busca usuarios candidatos para co-gestión (administradores u organizadores)
  $opts = get_users([
    'role__in' => ['administrator','organizador'],
    'orderby'  => 'display_name',
    'order'    => 'ASC',
    'fields'   => ['ID','user_login','user_email']
  ]);

  // Staff
  $required = get_post_meta($event_id, '_evapp_event_staff_required', true);
  $required = max(0, (int)$required);
  $assigned = eventosapp_get_event_staff_assigned($event_id);
  
  // NUEVO: Obtener todos los usuarios staff para el select
  $all_staff = eventosapp_get_all_staff_users();

  ?>
  <style>
    .evapp-mini{ font-size:11px; color:#666; margin:3px 0; }
    .evapp-table{ width:100%; border-collapse:collapse; margin:6px 0; font-size:11px; }
    .evapp-table th, .evapp-table td{ border:1px solid #ddd; padding:4px 6px; }
    .evapp-table th{ background:#f6f7f7; }
    .evapp-badge{ display:inline-block; background:#f0f0f1; border:1px solid #ddd; padding:2px 6px; border-radius:3px; font-size:11px; }
    .evapp-danger{ color:#d63638; font-weight:600; }
  </style>

  <div>
    <strong>Co-gestores temporales</strong>
    <div class="evapp-mini">Usuarios que pueden gestionar este evento por tiempo limitado.</div>

    <?php if ($temp_authors): ?>
      <table class="evapp-table">
        <thead><tr><th>Usuario</th><th>Caduca</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($temp_authors as $row):
          $u = get_userdata((int)$row['user_id']);
          $until = isset($row['until']) ? (int)$row['until'] : 0;
          $date_str = $until ? date_i18n('Y-m-d', $until) : 'Sin límite';
          ?>
          <tr>
            <td><?php echo $u ? esc_html($u->user_login) : ('#'.(int)$row['user_id']); ?></td>
            <td><?php echo esc_html($date_str); ?></td>
            <td style="text-align:right">
              <label><input type="checkbox" name="evapp_co_remove[]" value="<?php echo (int)$row['user_id']; ?>"> Eliminar</label>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="evapp-mini">No hay co-gestores.</div>
    <?php endif; ?>

    <div style="margin-top:6px;border-top:1px solid #eee;padding-top:6px;">
      <label style="display:block;margin-bottom:2px;">Agregar co-gestor</label>
      <select name="evapp_co_add_user" style="width:100%;">
        <option value="">— Selecciona usuario —</option>
        <?php foreach ($opts as $u): ?>
          <option value="<?php echo (int)$u->ID; ?>">
            <?php echo esc_html($u->user_login.' ('.$u->user_email.')'); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <small class="evapp-mini">Caduca el: </small>
      <input type="date" name="evapp_co_add_until" value="<?php echo esc_attr( gmdate('Y-m-d', $release_ts) ); ?>" style="width:100%;">
    </div>
  </div>

  <hr>

  <div>
    <strong>Staff operativo</strong>
    <div class="evapp-mini">Se asignan usuarios con rol <b>staff</b>. Ahora pueden estar en múltiples eventos simultáneamente.</div>

    <label>Cantidad requerida:</label>
    <input type="number" min="0" name="evapp_staff_required" value="<?php echo (int)$required; ?>" style="width:100%;">

    <div style="margin:6px 0">
      <span class="evapp-badge">Liberación automática: <?php echo esc_html( date_i18n('Y-m-d', $release_ts) ); ?></span>
    </div>

<?php if ($assigned): ?>
  <table class="evapp-table">
    <thead><tr><th>Usuario</th><th>Correo</th><th>Pass</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($assigned as $uid=>$row):
        $u = get_userdata((int)$uid); ?>
        <tr>
          <td><?php echo $u ? esc_html($u->user_login) : ('#'.(int)$uid); ?></td>
          <td><?php echo $u ? esc_html($u->user_email) : '—'; ?></td>
          <td><code>_123456_</code></td>
          <td style="text-align:right">
            <label><input type="checkbox" name="evapp_staff_remove[]" value="<?php echo (int)$uid; ?>"> Eliminar</label>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php else: ?>
  <div class="evapp-mini">Sin staff asignado.</div>
<?php endif; ?>

<!-- SECCIÓN 1: Agregar staff manualmente desde select -->
<div style="margin-top:12px; border:1px solid #ddd; padding:8px; border-radius:4px; background:#f9f9f9;">
  <label style="display:block; margin-bottom:4px; font-weight:600;">Agregar staff manualmente</label>
  <select name="evapp_staff_add_manual_user" id="evapp_staff_manual_select" style="width:100%; margin-bottom:6px;">
    <option value="">— Buscar y seleccionar staff —</option>
    <?php foreach ($all_staff as $s): 
      // Verificar si ya está asignado a este evento
      $already_assigned = isset($assigned[$s->ID]);
      $disabled = $already_assigned ? 'disabled' : '';
      $suffix = $already_assigned ? ' (ya asignado)' : '';
      ?>
      <option value="<?php echo (int)$s->ID; ?>" <?php echo $disabled; ?>>
        <?php echo esc_html($s->display_name . ' - ' . $s->user_login . ' (' . $s->user_email . ')' . $suffix); ?>
      </option>
    <?php endforeach; ?>
  </select>
  
  <label style="display:block; margin-bottom:2px; font-size:11px;">Expira el:</label>
  <input type="date" name="evapp_staff_add_manual_until" value="<?php echo esc_attr( gmdate('Y-m-d', $release_ts) ); ?>" style="width:100%; margin-bottom:6px;">
  
  <input type="submit" class="button button-primary" name="evapp_staff_btn_add_manual" value="➕ Agregar staff seleccionado" style="width:100%;">
  <div class="evapp-mini" style="margin-top:4px;">Selecciona un usuario del listado y haz clic para agregarlo.</div>
</div>

<!-- SECCIÓN 2: Autocompletar staff según cantidad requerida -->
<div style="margin-top:12px; border:1px solid #0073aa; padding:8px; border-radius:4px; background:#e5f5fa;">
  <label style="display:block; margin-bottom:4px; font-weight:600;">Autocompletar staff requerido</label>
  <div class="evapp-mini" style="margin-bottom:6px;">
    Completa automáticamente hasta la cantidad requerida. Busca staff disponible y si no hay suficientes, crea nuevos usuarios automáticamente.
  </div>
  <input type="submit" class="button button-secondary" name="evapp_staff_btn_autocomplete" value="🔄 Autocompletar hasta cantidad requerida" style="width:100%;">
  <div class="evapp-mini evapp-danger" style="margin-top:6px;">
    * Si marcas <b>Eliminar</b> en la tabla superior, este proceso NO se ejecutará automáticamente en este guardado.
  </div>
</div>

<div class="evapp-mini evapp-danger" style="margin-top:8px;">
  * La contraseña por defecto para staff creado automáticamente es <code>_123456_</code> (débil a propósito).
</div>
  </div>
  <?php
}

// ---------- Guardado ----------
add_action('save_post_eventosapp_event', function($post_id){
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
  if ( empty($_POST['evapp_co_gestion_nonce']) || ! wp_verify_nonce($_POST['evapp_co_gestion_nonce'], 'evapp_co_gestion_save') ) return;

  // Solo administradores u organizadores (autores) pueden modificar
  if ( ! current_user_can('edit_post', $post_id) ) return;

  $release_ts = eventosapp_get_staff_release_ts($post_id);

  // ---- 1) Co-gestores: eliminar seleccionados
  $co = get_post_meta($post_id, '_evapp_temp_authors', true);
  if (!is_array($co)) $co = [];
  $remove = isset($_POST['evapp_co_remove']) ? array_map('intval',(array)$_POST['evapp_co_remove']) : [];
  if ($remove) {
    $co = array_values(array_filter($co, function($row) use ($remove){
      return empty($row['user_id']) || ! in_array( (int)$row['user_id'], $remove, true );
    }));
    update_post_meta($post_id, '_evapp_temp_authors', $co);
  }

  // ---- 2) Co-gestores: agregar uno (opcional)
  $add_user  = isset($_POST['evapp_co_add_user']) ? absint($_POST['evapp_co_add_user']) : 0;
  $add_until = isset($_POST['evapp_co_add_until']) ? sanitize_text_field($_POST['evapp_co_add_until']) : '';
  if ($add_user) {
    $until_ts = $add_until ? strtotime($add_until.' 23:59:59') : $release_ts;
    $co[] = [
      'user_id'    => $add_user,
      'until'      => (int)$until_ts,
      'granted_by' => get_current_user_id(),
    ];
    update_post_meta($post_id, '_evapp_temp_authors', $co);
  }

  // ---- 3) Staff requerido (siempre se guarda)
  $required = isset($_POST['evapp_staff_required']) ? max(0, (int)$_POST['evapp_staff_required']) : 0;
  update_post_meta($post_id, '_evapp_event_staff_required', $required);

  // Staff actual + depuración de expirados
  $assigned = eventosapp_get_event_staff_assigned($post_id);

  // ---- 4) Eliminar staff marcados (se ejecuta siempre si hay checkboxes marcados)
  $rem_staff = isset($_POST['evapp_staff_remove']) ? array_map('intval',(array)$_POST['evapp_staff_remove']) : [];
  if ($rem_staff) {
    foreach ($rem_staff as $uid) {
      eventosapp_remove_staff_from_event($post_id, $uid);
      unset($assigned[$uid]);
    }
    update_post_meta($post_id, '_evapp_event_staff_assigned', $assigned);
  }

  // ========== BOTÓN 1: AGREGAR STAFF MANUAL ==========
  $btn_add_manual = !empty($_POST['evapp_staff_btn_add_manual']);
  
  if ($btn_add_manual) {
    $manual_staff_id = isset($_POST['evapp_staff_add_manual_user']) ? absint($_POST['evapp_staff_add_manual_user']) : 0;
    $manual_until = isset($_POST['evapp_staff_add_manual_until']) ? sanitize_text_field($_POST['evapp_staff_add_manual_until']) : '';
    
    if ($manual_staff_id) {
      // Verificar que el usuario existe y tiene rol staff
      $manual_user = get_userdata($manual_staff_id);
      if ($manual_user && in_array('staff', $manual_user->roles, true)) {
        // Verificar que no esté ya asignado a este evento
        $assigned_refresh = eventosapp_get_event_staff_assigned($post_id);
        if (!isset($assigned_refresh[$manual_staff_id])) {
          $manual_until_ts = $manual_until ? strtotime($manual_until.' 23:59:59') : $release_ts;
          eventosapp_assign_staff_to_event($post_id, [$manual_staff_id], $manual_until_ts);
        }
      }
    }
  }

  // ========== BOTÓN 2: AUTOCOMPLETAR STAFF REQUERIDO ==========
  $btn_autocomplete = !empty($_POST['evapp_staff_btn_autocomplete']);
  
  if ($btn_autocomplete) {
    // Refrescar assigned después de posibles cambios manuales
    $assigned_refresh = eventosapp_get_event_staff_assigned($post_id);
    $current = count($assigned_refresh);
    
    // Determinar cuántos faltan para llegar a la cantidad requerida
    if ($required > $current) {
      $need = $required - $current;
      
      $existing_ids = array_map('intval', array_keys($assigned_refresh));
      // Evitar re-asignar inmediatamente a los que se eliminaron
      $exclude = array_unique(array_merge($existing_ids, $rem_staff));

      // 1) Buscar staff disponible (ahora pueden estar en otros eventos)
      $free = eventosapp_find_free_staff($need, $exclude);
      $took = $free;

      // 2) Si falta, crear nuevos usuarios staff
      $still = $need - count($free);
      if ($still > 0) {
        $created = eventosapp_create_staff_bulk($still);
        $new_ids = array_map(function($r){ return (int)$r['ID']; }, $created);
        $took = array_merge($took, $new_ids);

        // Nota con últimas credenciales creadas (feedback)
        update_post_meta($post_id, '_evapp_last_created_staff', $created);
      }

      // 3) Asignar al evento
      if ($took) {
        eventosapp_assign_staff_to_event($post_id, $took, $release_ts);
      }
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
  $staff = get_users(['role'=>'staff', 'fields'=>['ID']]);
  $now = time();
  
  foreach ($staff as $u) {
    $assignments = get_user_meta($u->ID, '_evapp_event_assignment', true);
    if (is_array($assignments) && !empty($assignments)) {
      $changed = false;
      foreach ($assignments as $event_id => $data) {
        if (isset($data['until']) && (int)$data['until'] < $now) {
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
