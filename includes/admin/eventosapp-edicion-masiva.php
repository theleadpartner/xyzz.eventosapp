<?php
if (!defined('ABSPATH')) exit;

/**
 * Edición Masiva de Tickets (asistente 4 pasos)
 * Directorio: includes/admin
 *
 * URL: /wp-admin/admin.php?page=eventosapp_bulk_edit&event_id=ID
 *
 * Flujo:
 *  1) Elegir evento + subir CSV
 *  2) Mapear columnas del CSV:
 *     - Criterios de búsqueda (uno o varios) con lógica AND/OR
 *     - Campos a actualizar (con política: sobrescribir / solo si vacío)
 *  3) Previsualizar (10 filas) con coincidencias 0/1/múltiples y diffs
 *  4) Procesar de forma completa y ordenada con barra de progreso y logs
 */

// =====================
// Menú / accesos
// =====================
add_action('admin_menu', function(){
    add_submenu_page(
        'eventosapp_dashboard',
        'Edición Masiva de Tickets',
        'Edición Masiva',
        'manage_options',
        'eventosapp_bulk_edit',
        'eventosapp_bulk_edit_render',
        41
    );
}, 20);

// Enlace "Edición Masiva" en la fila del evento
add_filter('post_row_actions', function($actions, $post){
    if ($post->post_type === 'eventosapp_event' && current_user_can('manage_options')) {
        $url = add_query_arg([
            'page'     => 'eventosapp_bulk_edit',
            'event_id' => $post->ID,
        ], admin_url('admin.php'));
        $actions['evapp_bulk_edit'] = '<a href="'.esc_url($url).'">Edición masiva</a>';
    }
    return $actions;
}, 10, 2);


// =====================
// Utilidades internas
// =====================

// Subir archivos (reutiliza import dir si existe)
function evapp_edit_upload_dir(){
    if (function_exists('evapp_import_upload_dir')) {
        return evapp_import_upload_dir();
    }
    $u = wp_upload_dir();
    $dir = trailingslashit($u['basedir']).'eventosapp-imports/';
    if (!file_exists($dir)) wp_mkdir_p($dir);
    return [$dir, trailingslashit($u['baseurl']).'eventosapp-imports/'];
}
function evapp_edit_sanitize_header_key($s){
    $s = remove_accents(strtolower(trim($s)));
    $s = preg_replace('/[^a-z0-9_]+/','_',$s);
    $s = trim($s,'_');
    return $s;
}
function evapp_edit_current_user_key(){
    $u = wp_get_current_user();
    return 'u'.($u && $u->ID ? $u->ID : 0);
}

// Estado por archivo
function evapp_edit_state_key($event_id, $hash){
    return 'evapp_edit_state_'.$event_id.'_'.$hash.'_'.evapp_edit_current_user_key();
}
function evapp_edit_get_latest_progress($event_id){
    global $wpdb;
    $like = 'evapp_edit_state_'.$event_id.'_%_'.evapp_edit_current_user_key();
    $opt = $wpdb->get_var( $wpdb->prepare(
        "SELECT option_name FROM $wpdb->options
         WHERE option_name LIKE %s
         ORDER BY option_id DESC
         LIMIT 1", $like
    ) );
    if (!$opt) return null;
    $st = get_option($opt);
    return is_array($st) ? $st : null;
}

// Catálogo de campos disponibles (búsqueda y actualización)
function evapp_edit_field_catalog($event_id){
    $platform = [
        // Búsqueda
        'ticket_id'   => 'TicketID (UUID)',
        'seq'         => 'Secuencia del evento',
        'email'       => 'Email',
        'cc'          => 'Cédula',
        'nit'         => 'NIT',
        'external_id' => 'ID externo',
        // Actualización (comparte los mismos más los datos de perfil)
        'nombre'      => 'Nombre',
        'apellido'    => 'Apellido',
        'telefono'    => 'Teléfono',
        'empresa'     => 'Empresa',
        'cargo'       => 'Cargo',
        'ciudad'      => 'Ciudad',
        'pais'        => 'País',
        'localidad'   => 'Localidad',
    ];

    // Extras configurados para el evento
    $extras = function_exists('eventosapp_get_event_extra_fields') ? eventosapp_get_event_extra_fields($event_id) : [];
    foreach ($extras as $f) {
        $key = 'extra__'.$f['key'];
        $platform[$key] = 'Extra: '.$f['label'].' ('.$f['key'].')';
    }

    return $platform;
}

// Mapeo campo lógico → meta_key (o especial)
function evapp_edit_field_to_meta($field_key){
    switch ($field_key) {
        case 'ticket_id':   return ['type'=>'meta','key'=>'eventosapp_ticketID'];
        case 'seq':         return ['type'=>'meta','key'=>'_eventosapp_ticket_seq'];
        case 'email':       return ['type'=>'meta','key'=>'_eventosapp_asistente_email'];
        case 'cc':          return ['type'=>'meta','key'=>'_eventosapp_asistente_cc'];
        case 'nit':         return ['type'=>'meta','key'=>'_eventosapp_asistente_nit'];
        case 'external_id': return ['type'=>'meta','key'=>'_eventosapp_external_id']; // si no existe, no matcheará
        case 'nombre':      return ['type'=>'meta','key'=>'_eventosapp_asistente_nombre'];
        case 'apellido':    return ['type'=>'meta','key'=>'_eventosapp_asistente_apellido'];
        case 'telefono':    return ['type'=>'meta','key'=>'_eventosapp_asistente_tel'];
        case 'empresa':     return ['type'=>'meta','key'=>'_eventosapp_asistente_empresa'];
        case 'cargo':       return ['type'=>'meta','key'=>'_eventosapp_asistente_cargo'];
        case 'ciudad':      return ['type'=>'meta','key'=>'_eventosapp_asistente_ciudad'];
        case 'pais':        return ['type'=>'meta','key'=>'_eventosapp_asistente_pais'];
        case 'localidad':   return ['type'=>'meta','key'=>'_eventosapp_asistente_localidad'];
        default:
            if (strpos($field_key, 'extra__') === 0) {
                $ek = substr($field_key, 7);
                return ['type'=>'meta','key'=>'_eventosapp_extra_'.$ek];
            }
            return null;
    }
}

// Construir argumentos WP_Query según criterios y lógica AND/OR
// MODIFICADO: Ahora obtiene TODOS los tickets que coincidan
function evapp_edit_build_query_args($event_id, $criteria_map, $row, $logic = 'AND'){
    $logic = (strtoupper($logic)==='OR') ? 'OR' : 'AND';
    $meta = [
        'relation' => 'AND',
        [
            'key'   => '_eventosapp_ticket_evento_id',
            'value' => (int)$event_id,
            'compare' => '='
        ],
    ];
    $group = ['relation' => $logic];

    foreach ($criteria_map as $csv_index => $field_key) {
        if ($field_key === '' || $field_key === null) continue;
        $spec = evapp_edit_field_to_meta($field_key);
        if (!$spec) continue;
        $val = isset($row[$csv_index]) ? trim((string)$row[$csv_index]) : '';
        if ($val === '') continue;

        // Normalizaciones simples
        if ($field_key === 'email') $val = sanitize_email($val);
        if ($field_key === 'seq') $val = (string) intval($val);

        $group[] = [
            'key'     => $spec['key'],
            'value'   => $val,
            'compare' => '='
        ];
    }

    if (count($group) > 1) {
        $meta[] = $group;
    }

    return [
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'any',
        'posts_per_page' => -1, // MODIFICADO: obtener TODOS los que coincidan
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => $meta,
    ];
}

// =====================
// Barra selector evento
// =====================
function evapp_edit_event_picker_bar($current_event_id = 0){
    $events = get_posts([
        'post_type'      => 'eventosapp_event',
        'post_status'    => ['publish','draft'],
        'posts_per_page' => 200,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    echo '<div class="evapp-tools-bar" style="display:flex;gap:10px;align-items:center;margin:8px 0 18px">';
    echo '<label for="evapp_event_picker" style="font-weight:600">Evento:</label>';
    echo '<select id="evapp_event_picker" style="min-width:360px">';
    echo '<option value="">— Selecciona un evento —</option>';
    foreach ($events as $eid){
        $t = get_the_title($eid);
        if (!$t) $t = '(sin título)';
        $sel = ($eid == $current_event_id) ? ' selected' : '';
        echo '<option value="'.(int)$eid.'"'.$sel.'>'.esc_html($t).' (ID: '.(int)$eid.')</option>';
    }
    echo '</select>';
    echo ' <button class="button" id="evapp_go_picker">Ir</button>';
    echo '<script>
      document.getElementById("evapp_go_picker").addEventListener("click",function(){
        const v = document.getElementById("evapp_event_picker").value;
        if (!v) return alert("Selecciona un evento");
        location.href = "'.admin_url('admin.php?page=eventosapp_bulk_edit').'&event_id="+v;
      });
    </script>';
    echo '</div>';
}


// =====================
// Función de renderizado principal
// =====================
function eventosapp_bulk_edit_render(){
    if (!current_user_can('manage_options')) {
        echo '<div class="wrap"><h1>Edición Masiva</h1><p>No autorizado.</p></div>';
        return;
    }

    $event_id = intval($_GET['event_id'] ?? 0);
    $step     = intval($_GET['step'] ?? 1);

    // Solo STEP 1, 2, 3 se muestran. STEP 4 usa admin_notices
    if ($step === 4) {
        // El paso 4 se maneja con admin_notices (ver más abajo)
        return;
    }

    echo '<div class="wrap" style="max-width:1200px">';
    echo '<h1>Edición Masiva de Tickets</h1>';

    if ($step === 1) {
        evapp_edit_step_1($event_id);
    } elseif ($step === 2) {
        evapp_edit_step_2($event_id);
    } elseif ($step === 3) {
        evapp_edit_step_3($event_id);
    }

    echo '</div>';
}


// =====================
// STEP 1: Seleccionar evento + subir CSV
// =====================
function evapp_edit_step_1($event_id){
    evapp_edit_event_picker_bar($event_id);

    if (!$event_id) {
        echo '<p style="color:#666">Selecciona un evento de la lista para continuar.</p>';
        return;
    }

    $evt_title = get_the_title($event_id);
    if (!$evt_title) $evt_title = '(sin título)';

    echo '<h3>Paso 1: Subir CSV — <code>'.esc_html($evt_title).'</code></h3>';
    echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
    wp_nonce_field('evapp_edit_upload','evapp_edit_nonce');
    echo '<input type="hidden" name="action" value="eventosapp_edit_upload_csv">';
    echo '<input type="hidden" name="event_id" value="'.intval($event_id).'">';
    echo '<p><input type="file" name="csv_file" accept=".csv" required></p>';
    echo '<p><button class="button button-primary">Subir CSV y continuar</button></p>';
    echo '</form>';

    // Mostrar si hay algún proceso previo en curso
    $last = evapp_edit_get_latest_progress($event_id);
    if ($last) {
        echo '<hr style="margin-top:30px">';
        echo '<p style="color:#888">Hay un proceso previo. Progreso: '.intval($last['offset']).' de '.intval($last['total_rows']).' — ';
        $hash = $last['hash'] ?? '';
        if ($hash) {
            $url4 = add_query_arg(['page'=>'eventosapp_bulk_edit','event_id'=>$event_id,'step'=>4,'hash'=>$hash], admin_url('admin.php'));
            echo '<a href="'.esc_url($url4).'">Continuar</a>';
        }
        echo '</p>';
    }
}


// =====================
// STEP 2: Mapear columnas
// =====================
function evapp_edit_step_2($event_id){
    evapp_edit_event_picker_bar($event_id);

    if (!$event_id) {
        echo '<p>Evento no especificado.</p>';
        return;
    }

    $hash = sanitize_text_field($_GET['hash'] ?? '');
    if (!$hash) {
        echo '<p>Falta el parámetro hash.</p>';
        return;
    }

    $state = get_option( evapp_edit_state_key($event_id, $hash) );
    if (!$state) {
        echo '<p>Estado no encontrado. Vuelve a subir el CSV.</p>';
        return;
    }

    $evt_title = get_the_title($event_id);
    if (!$evt_title) $evt_title = '(sin título)';

    echo '<h3>Paso 2: Mapear — <code>'.esc_html($evt_title).'</code></h3>';
    echo '<p>Archivo: <code>'.esc_html($state['filename']).'</code> &nbsp;|&nbsp; ';
    echo 'Columnas: <b>'.count($state['headers']).'</b> &nbsp;|&nbsp; ';
    echo 'Filas: <b>'.intval($state['total_rows']).'</b></p>';

    $catalog = evapp_edit_field_catalog($event_id);

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    wp_nonce_field('evapp_edit_map','evapp_edit_nonce');
    echo '<input type="hidden" name="action" value="eventosapp_edit_map_columns">';
    echo '<input type="hidden" name="event_id" value="'.intval($event_id).'">';
    echo '<input type="hidden" name="hash" value="'.esc_attr($hash).'">';

    echo '<table class="widefat" style="margin:20px 0;max-width:900px">';
    echo '<thead><tr><th style="width:30%">Columna CSV</th><th>Usar como</th><th>Política</th></tr></thead>';
    echo '<tbody>';
    foreach ($state['headers'] as $i => $col) {
        $coltxt = esc_html($col ? $col : '(vacía)');
        echo '<tr>';
        echo '<td><strong>#'.($i+1).' — '.$coltxt.'</strong></td>';
        echo '<td style="display:flex;gap:10px;align-items:center">';
        // Búsqueda
        echo '<div>';
        echo '<label style="font-size:11px;font-weight:600;text-transform:uppercase;color:#666">Búsqueda</label><br>';
        echo '<select name="map_search['.$i.']" style="min-width:200px">';
        echo '<option value="">— No usar —</option>';
        foreach ($catalog as $k => $lbl) {
            echo '<option value="'.esc_attr($k).'">'.esc_html($lbl).'</option>';
        }
        echo '</select>';
        echo '</div>';
        // Actualización
        echo '<div>';
        echo '<label style="font-size:11px;font-weight:600;text-transform:uppercase;color:#666">Actualizar</label><br>';
        echo '<select name="map_update['.$i.']" style="min-width:200px">';
        echo '<option value="">— No actualizar —</option>';
        foreach ($catalog as $k => $lbl) {
            echo '<option value="'.esc_attr($k).'">'.esc_html($lbl).'</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '</td>';
        // Política (solo afecta campos de actualización)
        echo '<td>';
        echo '<select name="policy['.$i.']" style="min-width:160px">';
        echo '<option value="overwrite">Sobrescribir siempre</option>';
        echo '<option value="if_empty">Solo si vacío</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<p style="margin-top:20px">';
    echo '<label><strong>Lógica de búsqueda:</strong></label><br>';
    echo '<label><input type="radio" name="search_logic" value="AND" checked> AND (deben coincidir TODOS los criterios)</label><br>';
    echo '<label><input type="radio" name="search_logic" value="OR"> OR (basta que coincida UNO)</label>';
    echo '</p>';

    echo '<p><button class="button button-primary">Continuar</button></p>';
    echo '</form>';
}


// =====================
// STEP 3: Previsualizar
// =====================
function evapp_edit_step_3($event_id){
    evapp_edit_event_picker_bar($event_id);

    if (!$event_id) {
        echo '<p>Evento no especificado.</p>';
        return;
    }

    $hash = sanitize_text_field($_GET['hash'] ?? '');
    if (!$hash) {
        echo '<p>Falta el parámetro hash.</p>';
        return;
    }

    $state = get_option( evapp_edit_state_key($event_id, $hash) );
    if (!$state) {
        echo '<p>Estado no encontrado. Vuelve a subir el CSV.</p>';
        return;
    }

    $evt_title = get_the_title($event_id);
    if (!$evt_title) $evt_title = '(sin título)';

    echo '<h3>Paso 3: Previsualizar — <code>'.esc_html($evt_title).'</code></h3>';
    echo '<p>Archivo: <code>'.esc_html($state['filename']).'</code> &nbsp;|&nbsp; ';
    echo 'Filas a procesar: <b>'.intval($state['total_rows']).'</b></p>';

    $mapS = $state['map_search'];
    $mapU = $state['map_update'];
    $pols = $state['policies'];
    $logic = $state['search_logic'] ?? 'AND';

    echo '<p><strong>Resumen de configuración:</strong></p>';
    echo '<ul>';
    echo '<li>Lógica de búsqueda: <b>'.esc_html($logic).'</b></li>';
    $cat = evapp_edit_field_catalog($event_id);
    $cS = []; foreach($mapS as $k=>$v) if($v) $cS[]=$cat[$v]??$v;
    echo '<li>Criterios de búsqueda: '.($cS?esc_html(implode(', ',$cS)):'(ninguno)').'</li>';
    $cU = []; foreach($mapU as $k=>$v) if($v) $cU[]=$cat[$v]??$v;
    echo '<li>Campos a actualizar: '.($cU?esc_html(implode(', ',$cU)):'(ninguno)').'</li>';
    echo '</ul>';

    // Previsualizar 10 filas
    $fh = fopen($state['file'],'r');
    if (!$fh) {
        echo '<p>No se pudo abrir el archivo CSV.</p>';
        return;
    }
    $hdr = fgetcsv($fh);
    $preview = [];
    $line = 0;
    while($line < 10 && ($row = fgetcsv($fh)) !== false){
        $line++;
        // Buscar coincidencias
        $args = evapp_edit_build_query_args($event_id, $mapS, $row, $logic);
        $ids = get_posts($args);
        $count = count($ids);

        $diffs = [];
        if ($count === 1) {
            $pid = (int)$ids[0];
            foreach ($mapU as $i=>$fk){
                if (!$fk) continue;
                $spec = evapp_edit_field_to_meta($fk);
                if (!$spec) continue;
                $new = isset($row[$i]) ? trim((string)$row[$i]) : '';
                if ($fk === 'email') $new = sanitize_email($new);
                $cur = get_post_meta($pid, $spec['key'], true);
                if ($new !== $cur) {
                    $diffs[] = [$cat[$fk]??$fk, $cur, $new];
                }
            }
        } elseif ($count > 1) {
            // NUEVO: Mostrar diffs para todos los tickets coincidentes
            foreach ($ids as $pid) {
                $ticket_seq = get_post_meta($pid, '_eventosapp_ticket_seq', true);
                $ticket_label = 'Ticket #' . $ticket_seq . ' (ID: ' . $pid . ')';
                foreach ($mapU as $i=>$fk){
                    if (!$fk) continue;
                    $spec = evapp_edit_field_to_meta($fk);
                    if (!$spec) continue;
                    $new = isset($row[$i]) ? trim((string)$row[$i]) : '';
                    if ($fk === 'email') $new = sanitize_email($new);
                    $cur = get_post_meta($pid, $spec['key'], true);
                    if ($new !== $cur) {
                        $diffs[] = [$ticket_label . ' - ' . ($cat[$fk]??$fk), $cur, $new];
                    }
                }
            }
        }

        $preview[] = ['line'=>$line, 'row'=>$row, 'count'=>$count, 'diffs'=>$diffs];
    }
    fclose($fh);

    echo '<table class="widefat" style="margin:20px 0;max-width:1200px">';
    echo '<thead><tr><th style="width:80px">Fila</th><th>Datos CSV</th><th>Coincidencias</th><th>Cambios a aplicar</th></tr></thead>';
    echo '<tbody>';
    foreach ($preview as $p) {
        $rc = ($p['count'] === 0) ? 'background:#fff3cd;' : (($p['count'] > 1) ? 'background:#cce5ff;' : 'background:#d4edda;');
        echo '<tr style="'.$rc.'">';
        echo '<td><strong>'.intval($p['line']).'</strong></td>';
        echo '<td><small>'.esc_html(implode(' | ', array_slice($p['row'], 0, 5))).'</small></td>';
        
        // Mostrar coincidencias
        if ($p['count'] === 0) {
            echo '<td style="color:#856404"><strong>0 tickets</strong></td>';
        } elseif ($p['count'] === 1) {
            echo '<td style="color:#155724"><strong>1 ticket</strong></td>';
        } else {
            echo '<td style="color:#004085"><strong>'.$p['count'].' tickets</strong> (se actualizarán TODOS)</td>';
        }
        
        // Mostrar diffs
        echo '<td>';
        if ($p['diffs']) {
            echo '<ul style="margin:0;padding-left:18px;font-size:12px">';
            foreach ($p['diffs'] as $d) {
                echo '<li><b>'.esc_html($d[0]).':</b> <code>'.esc_html($d[1]).'</code> → <code>'.esc_html($d[2]).'</code></li>';
            }
            echo '</ul>';
        } else {
            echo '<span style="color:#999">Sin cambios</span>';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<p style="margin-top:20px;padding:10px;background:#f0f0f0;border-left:4px solid #2271b1">';
    echo '<strong>Nota:</strong> Cuando hay múltiples coincidencias (mismo email, cédula, etc.), ';
    echo '<strong>TODOS los tickets que coincidan serán actualizados</strong> con los datos del CSV.';
    echo '</p>';

    $url4 = add_query_arg(['page'=>'eventosapp_bulk_edit','event_id'=>$event_id,'step'=>4,'hash'=>$hash], admin_url('admin.php'));
    echo '<p><a href="'.esc_url($url4).'" class="button button-primary button-large">Proceder con la actualización completa</a></p>';
}


// =====================
// Procesar subida CSV
// =====================
add_action('admin_post_eventosapp_edit_upload_csv', function(){
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    if (!isset($_POST['evapp_edit_nonce']) || !wp_verify_nonce($_POST['evapp_edit_nonce'],'evapp_edit_upload')) wp_die('Nonce inválido');

    $event_id = intval($_POST['event_id'] ?? 0);
    if (!$event_id) wp_die('Evento inválido');

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) wp_die('Error al subir archivo');

    list($dir, $url) = evapp_edit_upload_dir();
    $hash = md5(uniqid('', true));
    $fname = 'bulk_edit_'.$event_id.'_'.$hash.'.csv';
    $dest = $dir.$fname;

    if (!move_uploaded_file($_FILES['csv_file']['tmp_name'], $dest)) wp_die('No se pudo guardar el archivo');

    // Leer cabeceras + contar filas
    $fh = fopen($dest,'r');
    if (!$fh) wp_die('No se pudo abrir el CSV guardado');
    $headers = fgetcsv($fh);
    if (!$headers) {
        fclose($fh);
        wp_die('CSV sin cabeceras');
    }
    $total_rows = 0;
    while (fgetcsv($fh) !== false) $total_rows++;
    fclose($fh);

    $state = [
        'event_id'   => $event_id,
        'hash'       => $hash,
        'file'       => $dest,
        'filename'   => $_FILES['csv_file']['name'],
        'headers'    => $headers,
        'total_rows' => $total_rows,
        'offset'     => 0,
        'updated_count'     => 0,
        'unchanged_count'   => 0,
        'no_match_count'    => 0,
        'multi_match_count' => 0,
        'error_count'       => 0,
    ];
    update_option( evapp_edit_state_key($event_id, $hash), $state, false );

    $url2 = add_query_arg(['page'=>'eventosapp_bulk_edit','event_id'=>$event_id,'step'=>2,'hash'=>$hash], admin_url('admin.php'));
    wp_safe_redirect($url2);
    exit;
});


// =====================
// Procesar mapeo (guardar config)
// =====================
add_action('admin_post_eventosapp_edit_map_columns', function(){
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    if (!isset($_POST['evapp_edit_nonce']) || !wp_verify_nonce($_POST['evapp_edit_nonce'],'evapp_edit_map')) wp_die('Nonce inválido');

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    if (!$event_id || !$hash) wp_die('Parámetros inválidos');

    $state = get_option( evapp_edit_state_key($event_id, $hash) );
    if (!$state) wp_die('Estado no encontrado');

    $mapS = $_POST['map_search'] ?? [];
    $mapU = $_POST['map_update'] ?? [];
    $pols = $_POST['policy'] ?? [];
    $logic = sanitize_text_field($_POST['search_logic'] ?? 'AND');

    // Construir políticas por campo
    $policiesMap = [];
    foreach ($mapU as $i => $fk) {
        if ($fk) {
            $policiesMap[$fk] = ($pols[$i] ?? 'overwrite');
        }
    }

    $state['map_search']    = $mapS;
    $state['map_update']    = $mapU;
    $state['policies']      = $policiesMap;
    $state['search_logic']  = $logic;
    update_option( evapp_edit_state_key($event_id, $hash), $state, false );

    $url3 = add_query_arg(['page'=>'eventosapp_bulk_edit','event_id'=>$event_id,'step'=>3,'hash'=>$hash], admin_url('admin.php'));
    wp_safe_redirect($url3);
    exit;
});


// =====================
// STEP 4: Progreso + botón automático
// =====================
add_action('admin_init', function(){
    if (!is_admin() || !isset($_GET['page']) || $_GET['page']!=='eventosapp_bulk_edit') return;
    if (intval($_GET['step'] ?? 0) !== 4) return;

    $event_id = intval($_GET['event_id'] ?? 0);
    $hash     = sanitize_text_field($_GET['hash'] ?? '');
    if (!$event_id || !$hash) return;

    $state = get_option( evapp_edit_state_key($event_id, $hash) );
    if (!$state) return;

    add_action('admin_notices', function() use ($state, $event_id, $hash){
        $nonce = wp_create_nonce('evapp_edit_'.$event_id);
        $total_rows = intval($state['total_rows']);

        echo '<div class="wrap" style="max-width:1000px">';
        echo '<h2>Actualización Masiva — Procesamiento Completo</h2>';
        echo '<p>Archivo: <code>'.esc_html($state['filename']).'</code> &nbsp;|&nbsp; Total de filas: <b>'.$total_rows.'</b></p>';
        
        echo '<div style="margin:20px 0">';
        echo '<div style="background:#f0f0f0;border-radius:8px;height:30px;position:relative;overflow:hidden">';
        echo '<div id="ev_progress_bar" style="background:linear-gradient(90deg, #2271b1, #72aee6);height:100%;width:0%;transition:width 0.3s"></div>';
        echo '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-weight:600;color:#000" id="ev_progress_text">0%</div>';
        echo '</div>';
        echo '</div>';

        echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin:20px 0">';
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:15px;text-align:center">';
        echo '<div style="font-size:11px;color:#666;text-transform:uppercase;margin-bottom:5px">Procesadas</div>';
        echo '<div style="font-size:28px;font-weight:700" id="ev_cnt">'.intval($state['offset']).'</div>';
        echo '</div>';
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:15px;text-align:center">';
        echo '<div style="font-size:11px;color:#666;text-transform:uppercase;margin-bottom:5px">Actualizadas</div>';
        echo '<div style="font-size:28px;font-weight:700;color:#0a8043" id="ev_upd">'.intval($state['updated_count']).'</div>';
        echo '</div>';
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:15px;text-align:center">';
        echo '<div style="font-size:11px;color:#666;text-transform:uppercase;margin-bottom:5px">Sin cambios</div>';
        echo '<div style="font-size:28px;font-weight:700;color:#666" id="ev_same">'.intval($state['unchanged_count']).'</div>';
        echo '</div>';
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:15px;text-align:center">';
        echo '<div style="font-size:11px;color:#666;text-transform:uppercase;margin-bottom:5px">Sin coincidencia</div>';
        echo '<div style="font-size:28px;font-weight:700;color:#f9a825" id="ev_nom">'.intval($state['no_match_count']).'</div>';
        echo '</div>';
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:15px;text-align:center">';
        echo '<div style="font-size:11px;color:#666;text-transform:uppercase;margin-bottom:5px">Múltiples (actualizados)</div>';
        echo '<div style="font-size:28px;font-weight:700;color:#0277bd" id="ev_mul">'.intval($state['multi_match_count']).'</div>';
        echo '</div>';
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:15px;text-align:center">';
        echo '<div style="font-size:11px;color:#666;text-transform:uppercase;margin-bottom:5px">Errores</div>';
        echo '<div style="font-size:28px;font-weight:700;color:#c62828" id="ev_err">'.intval($state['error_count']).'</div>';
        echo '</div>';
        echo '</div>';

        echo '<div id="ev_log" style="background:#0b1020;color:#eaf1ff;padding:12px;border-radius:8px;min-height:200px;max-height:400px;overflow:auto;font-family:\'Courier New\',monospace;font-size:12px;line-height:1.6"></div>';
        
        echo '<div id="ev_status" style="margin-top:15px;padding:12px;background:#e3f2fd;border-radius:6px;display:none">';
        echo '<div style="font-weight:600;color:#0277bd">Estado: <span id="ev_status_text">Preparando...</span></div>';
        echo '</div>';

        echo '<script>
(function(){
  const totalRows = '.intval($total_rows).';
  const progressBar = document.getElementById("ev_progress_bar");
  const progressText = document.getElementById("ev_progress_text");
  const log = document.getElementById("ev_log");
  const status = document.getElementById("ev_status");
  const statusText = document.getElementById("ev_status_text");
  const c   = document.getElementById("ev_cnt");
  const u   = document.getElementById("ev_upd");
  const s   = document.getElementById("ev_same");
  const nm  = document.getElementById("ev_nom");
  const mm  = document.getElementById("ev_mul");
  const er  = document.getElementById("ev_err");
  
  let busy = false;
  let isDone = false;

  function updateProgress(current) {
    const percent = Math.min(100, Math.round((current / totalRows) * 100));
    progressBar.style.width = percent + "%";
    progressText.textContent = percent + "%";
  }

  function add(msg, isError = false){ 
    const color = isError ? "#ff5252" : "#eaf1ff";
    log.innerHTML += "<span style=\"color:" + color + "\">" + msg + "</span><br>"; 
    log.scrollTop = log.scrollHeight; 
  }

  function setStatus(msg, show = true) {
    statusText.textContent = msg;
    status.style.display = show ? "block" : "none";
  }

  async function processChunk(){
    if (busy || isDone) return;
    busy = true;
    
    try{
      setStatus("Procesando filas...");
      
      const fd = new FormData();
      fd.append("action","eventosapp_edit_process");
      fd.append("event_id","'.intval($event_id).'");
      fd.append("hash","'.esc_js($hash).'");
      fd.append("_wpnonce","'.esc_js($nonce).'");
      
      const resp = await fetch(ajaxurl,{method:"POST",body:fd,credentials:"same-origin"});
      const j = await resp.json();
      
      if (!j || !j.success) throw new Error(j && j.data ? j.data : "Error desconocido");
      
      // Actualizar contadores
      c.textContent  = j.data.offset;
      u.textContent  = j.data.updated_count;
      s.textContent  = j.data.unchanged_count;
      nm.textContent = j.data.no_match_count;
      mm.textContent = j.data.multi_match_count;
      er.textContent = j.data.error_count;
      
      // Actualizar barra de progreso
      updateProgress(j.data.offset);
      
      // Agregar mensaje al log
      add(j.data.msg);
      
      if (j.data.done){
        isDone = true;
        add("✅ ¡Actualización completada exitosamente!", false);
        add("📊 Resumen final: " + j.data.updated_count + " actualizadas, " + j.data.unchanged_count + " sin cambios, " + j.data.no_match_count + " sin coincidencia, " + j.data.multi_match_count + " múltiples, " + j.data.error_count + " errores", false);
        setStatus("Proceso finalizado", true);
        setTimeout(() => setStatus("", false), 5000);
      } else {
        // Continuar procesando automáticamente
        setTimeout(processChunk, 100); // 100ms entre chunks para no saturar
      }
    } catch(e){ 
      add("❌ Error: " + e.message, true); 
      isDone = true;
      setStatus("Error en el proceso", true);
    }
    finally { busy = false; }
  }

  // Iniciar procesamiento automático
  add("🚀 Iniciando procesamiento completo de " + totalRows + " filas...");
  add("⏳ El proceso se ejecutará de forma continua hasta completar todas las filas");
  add("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
  setTimeout(processChunk, 500);
})();
        </script>';

        echo '</div>';
    });
});


// =====================
// Procesar chunk (MODIFICADO para procesar múltiples coincidencias)
// =====================
add_action('wp_ajax_eventosapp_edit_process', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('No autorizado', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';
    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_edit_'.$event_id)) wp_send_json_error('Solicitud inválida', 400);

    $state = get_option( evapp_edit_state_key($event_id, $hash) );
    if (!$state) wp_send_json_error('Estado no encontrado', 404);

    // NUEVO: Procesar chunks de 50 filas a la vez para mantener estabilidad
    $CHUNK = 50;
    $offset  = intval($state['offset']);
    $updated = intval($state['updated_count']);
    $same    = intval($state['unchanged_count']);
    $nomatch = intval($state['no_match_count']);
    $multi   = intval($state['multi_match_count']);
    $errs    = intval($state['error_count']);

    $mapS = $state['map_search'];
    $mapU = $state['map_update'];
    $pols = $state['policies'];
    $logic = $state['search_logic'] ?? 'AND';

    $fh = fopen($state['file'],'r');
    if (!$fh) wp_send_json_error('No se pudo abrir el archivo', 500);
    $hdr = fgetcsv($fh); // headers
    $line = 1;

    // Saltar hasta offset actual (data lines)
    while ($line < $offset + 1 && ($row = fgetcsv($fh)) !== false){ $line++; }

    $processed = 0;
    $log_msgs = [];

    while($processed < $CHUNK && ($row = fgetcsv($fh)) !== false){
        $line++;
        $processed++;
        try {
            // 1) Buscar ticket(s) - MODIFICADO: ahora obtiene TODOS
            $args = evapp_edit_build_query_args($event_id, $mapS, $row, $logic);
            $ids = get_posts($args);

            if (!$ids) { 
                $nomatch++; 
                $offset++; 
                continue; 
            }

            // NUEVO: Procesar TODOS los tickets que coincidan
            $tickets_count = count($ids);
            if ($tickets_count > 1) {
                $multi += $tickets_count; // Contar todos los tickets múltiples
            }

            $total_changes = 0;
            $tickets_updated = 0;

            // Iterar sobre TODOS los tickets encontrados
            foreach ($ids as $pid) {
                $pid = (int)$pid;
                $changes = 0;

                // 2) Aplicar actualizaciones
                foreach ($mapU as $i=>$field_key){
                    if (!$field_key) continue;
                    $spec = evapp_edit_field_to_meta($field_key);
                    if (!$spec) continue;

                    $new = isset($row[$i]) ? trim((string)$row[$i]) : '';
                    if ($field_key === 'email') $new = sanitize_email($new);

                    // Normaliza extras si aplica
                    if (strpos($field_key,'extra__')===0 && function_exists('eventosapp_normalize_extra_value')) {
                        $ek = substr($field_key,7);
                        $schema = function_exists('eventosapp_get_event_extra_fields') ? eventosapp_get_event_extra_fields($event_id) : [];
                        $bykey = [];
                        foreach ($schema as $f){ $bykey[$f['key']] = $f; }
                        if (isset($bykey[$ek])) $new = eventosapp_normalize_extra_value($bykey[$ek], $new);
                    }

                    $cur = get_post_meta($pid, $spec['key'], true);
                    $policy = $pols[$field_key] ?? 'overwrite';

                    $should = false;
                    if ($policy === 'overwrite') {
                        $should = ($new !== '' && $new !== $cur);
                    } else { // if_empty
                        $should = ($new !== '' && ($cur === '' || $cur === null));
                    }

                    if ($should) {
                        update_post_meta($pid, $spec['key'], $new);
                        $changes++;
                    }
                }

                // Reindexar si hubo cambios
                if ($changes > 0) {
                    if (function_exists('eventosapp_ticket_build_search_blob')) {
                        eventosapp_ticket_build_search_blob($pid);
                    }
                    $tickets_updated++;
                    $total_changes += $changes;
                }
            }

            // Actualizar contadores
            if ($tickets_updated > 0) {
                $updated += $tickets_updated;
            } else {
                $same++;
            }

            // Log detallado para múltiples coincidencias
            if ($tickets_count > 1 && $tickets_updated > 0) {
                $log_msgs[] = 'L'.$line.': '.$tickets_count.' tickets actualizados ('.$total_changes.' cambios totales)';
            }

        } catch (\Throwable $e) {
            $errs++;
            $log_msgs[] = 'L'.intval($line).': ❌ '.$e->getMessage();
        }

        $offset++;
        
        // NUEVO: Pequeña pausa cada 10 registros para evitar saturación
        if ($processed % 10 === 0) {
            usleep(5000); // 5ms de pausa
        }
    }

    $done = feof($fh);
    fclose($fh);

    // Guardar estado
    $state['offset']            = $offset;
    $state['updated_count']     = $updated;
    $state['unchanged_count']   = $same;
    $state['no_match_count']    = $nomatch;
    $state['multi_match_count'] = $multi;
    $state['error_count']       = $errs;
    update_option( evapp_edit_state_key($event_id, $hash), $state, false );

    // Construir mensaje del log
    $msg = '📝 Chunk '.$offset.'/'.$state['total_rows'].' - Procesadas: '.$processed.' | Actualizadas: '.$updated.' | Sin cambios: '.$same.' | Sin match: '.$nomatch.' | Múltiples: '.$multi.' | Errores: '.$errs;
    
    if ($log_msgs) {
        $msg .= ' | ' . implode(' | ', array_slice($log_msgs, 0, 5));
    }

    wp_send_json_success([
        'offset'            => $offset,
        'updated_count'     => $updated,
        'unchanged_count'   => $same,
        'no_match_count'    => $nomatch,
        'multi_match_count' => $multi,
        'error_count'       => $errs,
        'msg'               => $msg,
        'done'              => $done ? 1 : 0,
    ]);
});
