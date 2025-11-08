<?php
// includes/admin/eventosapp-sesiones.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Metabox: Sesiones / Salones internos
 * (título visible “Sesiones/Salones Externos” para mantener tu UI actual)
 */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_sesiones_internas',
        'Sesiones/Salones Externos', // antes: 'Sesiones/Salones Internos'
        'eventosapp_render_metabox_sesiones',
        'eventosapp_event',
        'normal',
        'default'
    );
});

/**
 * Render del metabox
 */
function eventosapp_render_metabox_sesiones($post) {
    $sesiones = get_post_meta($post->ID, '_eventosapp_sesiones_internas', true);
    if (!is_array($sesiones)) $sesiones = [];

    // Cargar localidades del evento
    $localidades = get_post_meta($post->ID, '_eventosapp_localidades', true);
    if (!is_array($localidades) || empty($localidades)) $localidades = ['General', 'VIP', 'Platino'];

    // Estado de job (si está corriendo, mostramos resumen)
    $job_state = get_post_meta($post->ID, '_evapp_sess_job_state', true);
    if (!is_array($job_state)) $job_state = [];

    // NUEVO: Política persistente "sin localidad => TODAS las sesiones"
    $policy_no_loc = get_post_meta($post->ID, '_eventosapp_sesiones_policy_no_localidad', true) ? 1 : 0;

    wp_nonce_field('eventosapp_sesiones_guardar', 'eventosapp_sesiones_nonce');
    ?>
    <div id="eventosapp_sesiones_wrap">
        <p>
            <strong>¿Tu evento tiene sesiones internas?</strong><br>
            <span style="font-size:12px; color:#666;">
                Puedes agregar espacios internos y definir qué localidades pueden acceder por defecto.
            </span>
        </p>
        <div id="sesiones_list">
            <?php foreach ($sesiones as $i => $s):
                $nombre = is_array($s) && isset($s['nombre']) ? $s['nombre'] : (is_string($s) ? $s : '');
                $locs = is_array($s) && isset($s['localidades']) && is_array($s['localidades']) ? $s['localidades'] : $localidades;
            ?>
                <div class="sesion_item" style="margin-bottom:8px; padding-bottom:7px; border-bottom:1px solid #eee;">
                    <input type="text" name="eventosapp_sesiones_internas[<?php echo $i; ?>][nombre]" value="<?php echo esc_attr($nombre); ?>" placeholder="Nombre del espacio interno" style="width:220px;">
                    <button type="button" class="remove_sesion button">Eliminar</button>
                    <div style="margin-top:6px; font-size:12px;">
                        <b>Localidades con acceso por defecto:</b><br>
                        <?php foreach ($localidades as $loc): ?>
                            <label style="margin-right:7px;">
                                <input type="checkbox" name="eventosapp_sesiones_internas[<?php echo $i; ?>][localidades][]" value="<?php echo esc_attr($loc); ?>" <?php checked(in_array($loc, $locs)); ?>> <?php echo esc_html($loc); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <button type="button" id="add_sesion_interna" class="button">Agregar Espacio</button>

        <!-- NUEVO: Política persistente para tickets sin localidad -->
        <div style="margin-top:14px; padding:10px; background:#f6faff; border:1px solid #dbeafe; border-radius:8px;">
            <label style="display:block; margin-bottom:6px;">
                <input type="checkbox" name="eventosapp_sesiones_policy_no_localidad" value="1" <?php checked($policy_no_loc, 1); ?>>
                <strong>Política persistente:</strong> a los asistentes <em>sin localidad</em> asignarles <u>todas</u> las sesiones por defecto
            </label>
            <div style="font-size:12px; color:#466; margin-top:6px;">
                Esta política afecta tickets nuevos (admin/frontend) y también cuando un ticket cambia de evento o localidad.<br>
                <em>Nota:</em> Puedes sobreescribirla manualmente editando el ticket.
            </div>
        </div>

        <div style="margin-top:14px; padding:10px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px;">
            <label style="display:block; margin-bottom:6px;">
                <input type="checkbox" name="eventosapp_sesiones_apply_existing" value="1">
                <strong>Aplicar cambios a tickets ya creados (no bloquea)</strong>
            </label>
            <label style="display:block; margin-bottom:6px;">
                <input type="checkbox" name="eventosapp_sesiones_apply_no_localidad" value="1">
                Incluir también a asistentes <em>sin localidad</em> en <u>esta</u> actualización masiva
            </label>
            <label style="display:block;">
                <input type="checkbox" name="eventosapp_sesiones_remove_deleted" value="1">
                <span><strong>Eliminar accesos de sesiones eliminadas</strong> (pruning ordenado en todos los tickets)</span>
            </label>
            <div style="font-size:12px; color:#666; margin-top:6px;">
                Esto se ejecuta en lotes usando WP-Cron (por defecto 500 tickets por iteración). Es seguro repetirlo; agrega accesos faltantes y, si marcas la opción, quita accesos de sesiones que eliminaste del evento.
            </div>

            <?php if (!empty($job_state) && !empty($job_state['active'])): ?>
                <div style="margin-top:8px; font-size:12px; color:#0b6;">
                    <strong>Actualización en proceso…</strong>
                    Procesados: <?php echo intval($job_state['processed']); ?> tickets.
                    Tamaño de lote: <?php echo intval($job_state['batch']); ?>.
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
    (function($){
        var localidades = <?php echo json_encode(array_values($localidades)); ?>;
        $('#add_sesion_interna').on('click', function(){
            var idx = $('#sesiones_list .sesion_item').length;
            var checkboxes = '';
            for(var i=0;i<localidades.length;i++){
                checkboxes += '<label style="margin-right:7px;">' +
                    '<input type="checkbox" name="eventosapp_sesiones_internas['+idx+'][localidades][]" value="'+localidades[i]+'" checked> '+localidades[i]+
                    '</label>';
            }
            $('#sesiones_list').append(
                '<div class="sesion_item" style="margin-bottom:8px; padding-bottom:7px; border-bottom:1px solid #eee;">' +
                '<input type="text" name="eventosapp_sesiones_internas['+idx+'][nombre]" value="" placeholder="Nombre del espacio interno" style="width:220px;"> ' +
                '<button type="button" class="remove_sesion button">Eliminar</button>' +
                '<div style="margin-top:6px; font-size:12px;"><b>Localidades con acceso por defecto:</b><br>'+ checkboxes +'</div>' +
                '</div>'
            );
        });
        $(document).on('click', '.remove_sesion', function(){
            $(this).closest('.sesion_item').remove();
        });
    })(jQuery);
    </script>
    <?php
}


/**
 * Guardado de sesiones/salones + programación del job de propagación a tickets (add + prune)
 */
add_action('save_post_eventosapp_event', 'eventosapp_save_sesiones_internas', 25);
function eventosapp_save_sesiones_internas($post_id) {
    // Evitar autosaves/revisiones
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;

    // Permisos mínimos
    if ( ! current_user_can('edit_post', $post_id) ) return;

    // Nonce del metabox
    if ( ! isset($_POST['eventosapp_sesiones_nonce']) || ! wp_verify_nonce($_POST['eventosapp_sesiones_nonce'], 'eventosapp_sesiones_guardar') ) {
        return;
    }

    // --- Construir arreglo de sesiones desde POST
    $sesiones = [];
    if (!empty($_POST['eventosapp_sesiones_internas']) && is_array($_POST['eventosapp_sesiones_internas'])) {
        foreach ($_POST['eventosapp_sesiones_internas'] as $item) {
            $nombre = trim($item['nombre'] ?? '');
            if ($nombre !== '') {
                $locs = [];
                if (!empty($item['localidades']) && is_array($item['localidades'])) {
                    foreach ($item['localidades'] as $loc) $locs[] = sanitize_text_field($loc);
                }
                $sesiones[] = [
                    'nombre'      => sanitize_text_field($nombre),
                    'localidades' => array_values(array_unique($locs)),
                ];
            }
        }
    }

    // Guardar sesiones y detectar cambios (y sesiones eliminadas)
    $prev = get_post_meta($post_id, '_eventosapp_sesiones_internas', true);
    if (!is_array($prev)) $prev = [];

    $prev_names = [];
    foreach ($prev as $s) { if (!empty($s['nombre'])) $prev_names[] = (string) $s['nombre']; }
    $new_names  = [];
    foreach ($sesiones as $s) { if (!empty($s['nombre'])) $new_names[]  = (string) $s['nombre']; }

    $removed_names = array_values(array_diff($prev_names, $new_names));
    $changed = (serialize($prev) !== serialize($sesiones));

    update_post_meta($post_id, '_eventosapp_sesiones_internas', $sesiones);

    // NUEVO: guardar política persistente sin-localidad
    $policy_no_loc = !empty($_POST['eventosapp_sesiones_policy_no_localidad']) ? 1 : 0;
    update_post_meta($post_id, '_eventosapp_sesiones_policy_no_localidad', $policy_no_loc);

    // Flags del formulario
    $apply_existing = !empty($_POST['eventosapp_sesiones_apply_existing']);
    $remove_deleted = !empty($_POST['eventosapp_sesiones_remove_deleted']);

    // Para el job: si NO marcan "incluir sin localidad" en esta corrida masiva,
    // usamos como default la política persistente del evento.
    $include_no_loc_run = !empty($_POST['eventosapp_sesiones_apply_no_localidad']) ? true : (bool) $policy_no_loc;

    // Programa job si: (pidió aplicarlo) o (hubo cambios) o (hay sesiones eliminadas con pruning activo)
    if ( $apply_existing || $changed || ($remove_deleted && !empty($removed_names)) ) {
        eventosapp_schedule_apply_sessions_job($post_id, (bool) $include_no_loc_run, (bool) $remove_deleted, $removed_names);
    }
}


/**
 * Programa un job (si no hay uno activo) para propagar accesos de sesiones a tickets existentes
 * Incluye pruning opcional de sesiones eliminadas.
 */
function eventosapp_schedule_apply_sessions_job($event_id, $include_no_loc = false, $remove_deleted = false, $removed_names = []) {
    $state = get_post_meta($event_id, '_evapp_sess_job_state', true);
    if (!is_array($state)) $state = [];

    $batch = (int) apply_filters('eventosapp_sesiones_apply_batch', 500);
    if ($batch < 50) $batch = 50;

    if (!empty($state['active'])) {
        // Un job ya corre: OR a flags y merge a removed_names
        $state['include_no_loc'] = !empty($state['include_no_loc']) || $include_no_loc ? 1 : 0;
        $state['remove_deleted'] = !empty($state['remove_deleted']) || $remove_deleted ? 1 : 0;

        $existing_removed = isset($state['removed_names']) && is_array($state['removed_names']) ? $state['removed_names'] : [];
        $state['removed_names'] = array_values(array_unique(array_merge($existing_removed, array_map('strval', (array)$removed_names))));
        update_post_meta($event_id, '_evapp_sess_job_state', $state);
        return;
    }

    $new_state = [
        'active'        => 1,
        'include_no_loc'=> $include_no_loc ? 1 : 0,
        'remove_deleted'=> $remove_deleted ? 1 : 0,
        'removed_names' => array_values(array_map('strval', (array)$removed_names)),
        'last_id'       => 0,           // ID de ticket último procesado
        'processed'     => 0,           // contador
        'batch'         => $batch,
        'started_at'    => current_time('mysql'),
    ];
    update_post_meta($event_id, '_evapp_sess_job_state', $new_state);

    // Dispara la primera iteración en 5 segundos
    if ( ! wp_next_scheduled('eventosapp_run_apply_sessions_job', [$event_id]) ) {
        wp_schedule_single_event(time() + 5, 'eventosapp_run_apply_sessions_job', [$event_id]);
    }
}

/**
 * Runner (iterativo por lotes) — se reprograma hasta terminar
 * Hace:
 *  - Alta idempotente de accesos según localidad (y/o todos si no hay localidad y así se pidió)
 *  - Pruning opcional: elimina accesos de sesiones eliminadas y limpia sus check-ins
 */
add_action('eventosapp_run_apply_sessions_job', 'eventosapp_run_apply_sessions_job_cb', 10, 1);
function eventosapp_run_apply_sessions_job_cb($event_id) {
    global $wpdb;

    $state = get_post_meta($event_id, '_evapp_sess_job_state', true);
    if (!is_array($state) || empty($state['active'])) {
        return;
    }

    $batch          = (int) ($state['batch'] ?? 500);
    $last_id        = (int) ($state['last_id'] ?? 0);
    $include_no_loc = !empty($state['include_no_loc']);
    $remove_deleted = !empty($state['remove_deleted']);
    $removed_names  = isset($state['removed_names']) && is_array($state['removed_names']) ? $state['removed_names'] : [];

    // Cargar sesiones actuales del evento
    $sesiones = get_post_meta($event_id, '_eventosapp_sesiones_internas', true);
    if (!is_array($sesiones)) $sesiones = [];

    // Mapeos para cálculo rápido
    $all_session_names = [];
    $by_loc = []; // localidad => [sesion1, sesion2...]
    foreach ($sesiones as $s) {
        if (empty($s['nombre'])) continue;
        $name = (string) $s['nombre'];
        $all_session_names[] = $name;
        if (!empty($s['localidades']) && is_array($s['localidades'])) {
            foreach ($s['localidades'] as $loc) {
                $loc = (string) $loc;
                if (!isset($by_loc[$loc])) $by_loc[$loc] = [];
                $by_loc[$loc][] = $name;
            }
        }
    }
    $all_session_names = array_values(array_unique($all_session_names));

    // Traer un lote de tickets del evento con ID > last_id
    $posts_table = $wpdb->posts;
    $meta_table  = $wpdb->postmeta;

    $ids = $wpdb->get_col( $wpdb->prepare("
        SELECT p.ID
        FROM {$posts_table} p
        INNER JOIN {$meta_table} m ON (m.post_id = p.ID AND m.meta_key = '_eventosapp_ticket_evento_id')
        WHERE p.post_type = 'eventosapp_ticket'
          AND p.post_status NOT IN ('trash','auto-draft')
          AND m.meta_value = %d
          AND p.ID > %d
        ORDER BY p.ID ASC
        LIMIT %d
    ", $event_id, $last_id, $batch) );

    if (empty($ids)) {
        // Nada más por procesar: finalizar
        $state['active']     = 0;
        $state['finished_at']= current_time('mysql');
        update_post_meta($event_id, '_evapp_sess_job_state', $state);
        update_post_meta($event_id, '_evapp_sess_job_last', $state); // guardar snapshot final
        return;
    }

    $max_id_this_run = $last_id;
    $processed_this  = 0;

    foreach ($ids as $tid) {
        $tid = (int) $tid;
        if ($tid > $max_id_this_run) $max_id_this_run = $tid;

        // Localidad del ticket
        $loc = get_post_meta($tid, '_eventosapp_asistente_localidad', true);
        $loc = is_string($loc) ? trim($loc) : '';

        // Determinar sesiones a asignar
        if ($loc !== '' && isset($by_loc[$loc])) {
            $to_add = $by_loc[$loc];
        } elseif ($loc === '' && $include_no_loc) {
            $to_add = $all_session_names; // sin localidad: todo
        } else {
            $to_add = [];
        }

        // Accesos actuales
        $accesos = get_post_meta($tid, '_eventosapp_ticket_sesiones_acceso', true);
        if (!is_array($accesos)) $accesos = [];

        // 1) Alta idempotente
        if ($to_add) {
            $accesos = array_values(array_unique(array_merge($accesos, $to_add)));
        }

        // 2) Pruning de sesiones eliminadas (si está activo y hay nombres a quitar)
        if ($remove_deleted && $removed_names) {
            // Quitamos solo las que están listadas como eliminadas en el evento
            $accesos = array_values(array_diff($accesos, $removed_names));
        }

        // Escribir accesos si cambiaron
        // (Para comparar, traemos de nuevo el valor "antes" de escribir)
        $prev_accesos = get_post_meta($tid, '_eventosapp_ticket_sesiones_acceso', true);
        if (!is_array($prev_accesos)) $prev_accesos = [];
        $need_write_access = (count($accesos) !== count($prev_accesos)) || array_diff($accesos, $prev_accesos) || array_diff($prev_accesos, $accesos);
        if ($need_write_access) {
            update_post_meta($tid, '_eventosapp_ticket_sesiones_acceso', $accesos);
        }

        // Estados de check-in por sesión
        $st = get_post_meta($tid, '_eventosapp_ticket_checkin_sesiones', true);
        if (!is_array($st)) $st = [];

        $changed_st = false;

        // Asegurar estado para añadidas
        foreach ((array)$to_add as $sname) {
            if (!isset($st[$sname])) {
                $st[$sname] = 'not_checked_in';
                $changed_st = true;
            }
        }

        // Limpiar estados para removidas
        if ($remove_deleted && $removed_names) {
            foreach ($removed_names as $rname) {
                if (isset($st[$rname])) {
                    unset($st[$rname]);
                    $changed_st = true;
                }
            }
        }

        if ($changed_st) {
            update_post_meta($tid, '_eventosapp_ticket_checkin_sesiones', $st);
        }

        $processed_this++;
    }

    // Actualizar estado y reprogramar si quedan tickets
    $state['last_id']   = $max_id_this_run;
    $state['processed'] = (int) $state['processed'] + $processed_this;
    update_post_meta($event_id, '_evapp_sess_job_state', $state);

    // Reprogramar próxima iteración
    wp_schedule_single_event(time() + 10, 'eventosapp_run_apply_sessions_job', [$event_id]);
}

/**
 * Aviso en el editor de eventos indicando el progreso del job (si está activo)
 */
add_action('admin_notices', function(){
    if ( ! is_admin() ) return;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'eventosapp_event' ) return;
    if ( empty($_GET['post']) ) return;

    $event_id = absint($_GET['post']);
    if ( ! $event_id ) return;

    $state = get_post_meta($event_id, '_evapp_sess_job_state', true);
    if (!is_array($state) || empty($state['active'])) return;

    $processed = intval($state['processed'] ?? 0);
    $batch     = intval($state['batch'] ?? 500);

    echo '<div class="notice notice-info is-dismissible"><p>'.
         '<b>EventosApp:</b> Aplicando accesos a sesiones en tickets existentes… '.
         'Procesados <b>'.$processed.'</b> (lotes de '.$batch.'). '.
         'Se ejecuta en background con WP-Cron y no bloquea el editor.'.
         '</p></div>';
});

/* ============================================================
 *  Herencia automática de accesos a sesiones para tickets NUEVOS
 *  Archivo: includes/admin/eventosapp-sesiones.php
 *  (Pegar al final del archivo, sin eliminar nada previo)
 * ============================================================ */

/**
 * Construye mapeos de sesiones por localidad para un evento.
 * @return array [ 'by_loc' => [ 'General'=>['Sala A',...], ... ], 'all' => ['Sala A','Sala B',...] ]
 */
if ( ! function_exists('eventosapp_get_event_session_mappings') ) {
    function eventosapp_get_event_session_mappings($event_id) {
        $sesiones = get_post_meta($event_id, '_eventosapp_sesiones_internas', true);
        if (!is_array($sesiones)) $sesiones = [];

        $by_loc = [];
        $all    = [];

        foreach ($sesiones as $s) {
            if (empty($s['nombre'])) continue;
            $name = (string) $s['nombre'];
            $all[] = $name;

            if (!empty($s['localidades']) && is_array($s['localidades'])) {
                foreach ($s['localidades'] as $loc) {
                    $loc = (string) $loc;
                    if (!isset($by_loc[$loc])) $by_loc[$loc] = [];
                    $by_loc[$loc][] = $name;
                }
            }
        }

        // Unicidad
        $all = array_values(array_unique($all));
        foreach ($by_loc as $k => $arr) {
            $by_loc[$k] = array_values(array_unique($arr));
        }

        return ['by_loc' => $by_loc, 'all' => $all];
    }
}

/**
 * Aplica accesos por defecto a un ticket, si estos aún no existen.
 *
 * Reglas:
 *  - Si el ticket YA tiene meta '_eventosapp_ticket_sesiones_acceso' NO vacía, no se toca (respeta edición manual).
 *  - Si tiene localidad, se asignan las sesiones mapeadas a esa localidad.
 *  - Si NO tiene localidad, por defecto se asignan TODAS las sesiones (se puede desactivar con el filtro).
 *  - Setea/Completa '_eventosapp_ticket_checkin_sesiones' con 'not_checked_in' para sesiones recién añadidas.
 *
 * @param int  $ticket_id
 * @param bool $force_when_empty_loc  (opcional) Si true, sin localidad asigna todas las sesiones (default true vía filtro).
 * @return bool true si escribió cambios, false si no hizo nada.
 */
if ( ! function_exists('eventosapp_apply_default_session_access') ) {
    /**
     * Aplica accesos por defecto a un ticket, si estos aún no existen.
     *
     * Reglas:
     *  - Si el ticket YA tiene meta '_eventosapp_ticket_sesiones_acceso' NO vacía, no se toca (respeta edición manual).
     *  - Si tiene localidad, se asignan las sesiones mapeadas a esa localidad.
     *  - Si NO tiene localidad, usa la política persistente del evento:
     *      _eventosapp_sesiones_policy_no_localidad = 1  => asigna TODAS las sesiones
     *      _eventosapp_sesiones_policy_no_localidad = 0  => no asigna
     *  - Setea/Completa '_eventosapp_ticket_checkin_sesiones' con 'not_checked_in' para sesiones recién añadidas.
     *
     * @param int  $ticket_id
     * @param bool $force_when_empty_loc  (opcional) Forzar comportamiento sin localidad (si es null, usa la política del evento).
     * @return bool true si escribió cambios, false si no hizo nada.
     */
    function eventosapp_apply_default_session_access($ticket_id, $force_when_empty_loc = null) {
        $post = get_post($ticket_id);
        if ( ! $post || $post->post_type !== 'eventosapp_ticket' ) return false;
        if ( in_array($post->post_status, ['trash','auto-draft'], true) ) return false;

        $event_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
        if ( ! $event_id ) return false;

        // Si ya hay accesos, no tocamos (evita pisar edición manual)
        $current_access = get_post_meta($ticket_id, '_eventosapp_ticket_sesiones_acceso', true);
        if ( is_array($current_access) && ! empty($current_access) ) return false;

        // Determinar localidad del ticket
        $loc = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);
        $loc = is_string($loc) ? trim($loc) : '';

        $maps = eventosapp_get_event_session_mappings($event_id);

        // Política para "sin localidad": si no se pasó, usa la del evento (persistente)
        if ($force_when_empty_loc === null) {
            $force_when_empty_loc = (bool) get_post_meta($event_id, '_eventosapp_sesiones_policy_no_localidad', true);
            // (Opcional) permite sobrescribir vía filtro si lo necesitas en integraciones
            $force_when_empty_loc = apply_filters('eventosapp_new_ticket_include_no_localidad', $force_when_empty_loc, $event_id, $ticket_id);
        }

        // Determinar sesiones a añadir
        $to_add = [];
        if ($loc !== '' && !empty($maps['by_loc'][$loc])) {
            $to_add = (array) $maps['by_loc'][$loc];
        } elseif ($loc === '' && $force_when_empty_loc) {
            $to_add = (array) $maps['all'];
        }

        $to_add = array_values(array_unique(array_filter($to_add, 'strlen')));

        // Nada que agregar, salir silencioso
        if (empty($to_add)) return false;

        // Escribir accesos
        update_post_meta($ticket_id, '_eventosapp_ticket_sesiones_acceso', $to_add);

        // Asegurar estado de check-in por sesión
        $st = get_post_meta($ticket_id, '_eventosapp_ticket_checkin_sesiones', true);
        if (!is_array($st)) $st = [];
        $changed = false;
        foreach ($to_add as $sname) {
            if (!isset($st[$sname])) {
                $st[$sname] = 'not_checked_in';
                $changed = true;
            }
        }
        if ($changed) {
            update_post_meta($ticket_id, '_eventosapp_ticket_checkin_sesiones', $st);
        }

        return true;
    }
}


/**
 * Hook 1) Save tardío del ticket: intenta heredar accesos si todavía no existen.
 * Corre SIEMPRE (sin exigir nonce) y muy tarde para no interferir con otros guardados.
 */
add_action('save_post_eventosapp_ticket', function($post_id, $post, $update){
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision($post_id) ) return;
    if ( $post->post_status === 'auto-draft' ) return;

    // Solo si aún no hay accesos:
    $have = get_post_meta($post_id, '_eventosapp_ticket_sesiones_acceso', true);
    if (!is_array($have) || empty($have)) {
        eventosapp_apply_default_session_access($post_id);
    }
}, 999, 3);

/**
 * Hook 2) Reacción a cambios de meta CLAVE (evento o localidad) que suelen ocurrir
 * en flujos frontend después del wp_insert_post: hereda accesos si aún no están.
 *
 * Evita recursión con un guard estático.
 */
if ( ! function_exists('eventosapp__ensure_sessions_on_meta_change') ) {
    function eventosapp__ensure_sessions_on_meta_change($meta_id, $post_id, $meta_key, $_value) {
        static $running = false;
        if ($running) return;

        // Solo para tickets
        $post = get_post($post_id);
        if ( ! $post || $post->post_type !== 'eventosapp_ticket' ) return;

        // Solo si cambian claves relevantes
        if ($meta_key !== '_eventosapp_ticket_evento_id' && $meta_key !== '_eventosapp_asistente_localidad') {
            return;
        }

        // Si ya hay accesos, respetar y no tocar
        $have = get_post_meta($post_id, '_eventosapp_ticket_sesiones_acceso', true);
        if (is_array($have) && !empty($have)) return;

        // Aplicar
        $running = true;
        try {
            eventosapp_apply_default_session_access($post_id);
        } finally {
            $running = false;
        }
    }
    add_action('added_post_meta',   'eventosapp__ensure_sessions_on_meta_change', 10, 4);
    add_action('updated_post_meta', 'eventosapp__ensure_sessions_on_meta_change', 10, 4);
}
