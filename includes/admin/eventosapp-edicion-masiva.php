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
 *  4) Procesar en lotes de 100 hasta completar
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

// Enlace “Edición Masiva” en la fila del evento
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
        'posts_per_page' => 2, // nos basta saber si hay 0, 1 o >1
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
        $sel = selected($eid, $current_event_id, false);
        echo '<option value="'.esc_attr($eid).'" '.$sel.'>'.esc_html($t.' ['.$eid.']').'</option>';
    }
    echo '</select>';

    if ($current_event_id){
        $edit = get_edit_post_link($current_event_id);
        if ($edit){
            echo '<a class="button" href="'.esc_url($edit).'">Editar evento</a>';
        }
    }
    echo '</div>';

    ?>
    <script>
    (function(){
      var sel = document.getElementById('evapp_event_picker');
      if (!sel) return;
      sel.addEventListener('change', function(){
        var v = this.value;
        var url = new URL(window.location.href);
        url.searchParams.set('page','eventosapp_bulk_edit');
        if (v) {
          url.searchParams.set('event_id', v);
          url.searchParams.set('step', '1');
          url.searchParams.delete('hash');
        } else {
          url.searchParams.delete('event_id');
          url.searchParams.set('step','1');
          url.searchParams.delete('hash');
        }
        window.location = url.toString();
      });
    })();
    </script>
    <?php
}


// =====================
// Render principal
// =====================
function eventosapp_bulk_edit_render(){
    if (!current_user_can('manage_options')) wp_die('No autorizado', '', 403);

    $event_id = intval($_GET['event_id'] ?? 0);
    $step     = intval($_GET['step'] ?? 1);
    if ($step < 1 || $step > 4) $step = 1;

    echo '<div class="wrap" style="max-width:1100px">';
    echo '<h1>Edición Masiva de Tickets</h1>';

    evapp_edit_event_picker_bar($event_id);

    if (!$event_id || get_post_type($event_id) !== 'eventosapp_event') {
        echo '<p>Elige un evento para iniciar el asistente de edición.</p>';
        echo '</div>';
        return;
    }

    $event = get_post($event_id);
    $nonce = wp_create_nonce('evapp_edit_'.$event_id);

    $progress = evapp_edit_get_latest_progress($event_id);

    echo '<div class="wrap" style="max-width:1100px">';
    echo '<h1>Edición Masiva — <span style="color:#555">'.esc_html($event->post_title).' ['.$event_id.']</span></h1>';

    echo '<h2 class="nav-tab-wrapper" style="margin-top:20px">';
    $tabs = ['1'=>'Subir CSV','2'=>'Mapear','3'=>'Confirmar','4'=>'Actualizar'];
    foreach($tabs as $i=>$label){
        $cls = ($step===$i*1) ? ' nav-tab-active' : '';
        $url = add_query_arg(['page'=>'eventosapp_bulk_edit','event_id'=>$event_id,'step'=>$i], admin_url('admin.php'));
        echo '<a class="nav-tab'.$cls.'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
    }
    echo '</h2>';

    if ($progress) {
        echo '<div style="background:#fff8e1;border:1px solid #ffe58f;padding:10px 12px;border-radius:8px;margin:8px 0 16px">';
        echo '<b>Edición previa detectada:</b> archivo <code>'.esc_html($progress['filename']).'</code> ';
        echo '(hash <code>'.esc_html(substr($progress['file_hash'],0,10)).'…</code>) — filas procesadas: <b>'.intval($progress['offset']).'</b>.';
        echo ' Puedes re-subir el <i>mismo</i> archivo para continuar o ir directo al paso 4.';
        echo '</div>';
    }

    if ($step === 1) {
        echo '<div class="card" style="padding:18px">';
        echo '<p>Sube un <b>CSV</b> con las columnas que usarás para <b>buscar</b> los tickets y las columnas con los <b>datos nuevos</b> a aplicar.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-ajax.php')).'">';
        echo '<input type="hidden" name="action" value="eventosapp_edit_upload">';
        echo '<input type="hidden" name="event_id" value="'.esc_attr($event_id).'">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'">';
        echo '<p><label><b>Elige tu CSV:</b><br>';
        echo '<input type="file" name="csv" accept=".csv,text/csv" required></label></p>';
        echo '<p><button class="button button-primary">Continuar</button></p>';
        echo '</form>';
        echo '</div>';
    }

    echo '</div>'; // wrap
}


// =====================
// AJAX Paso 1 → subir
// =====================
add_action('wp_ajax_eventosapp_edit_upload', function(){
    if (!current_user_can('manage_options')) wp_die('No autorizado', '', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $nonce    = $_POST['_wpnonce'] ?? '';
    if (!$event_id || !wp_verify_nonce($nonce, 'evapp_edit_'.$event_id)) wp_die('Solicitud inválida', '', 400);

    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        wp_die('Archivo CSV requerido', '', 400);
    }

    // Mover a uploads
    [$dir, $baseurl] = evapp_edit_upload_dir();
    $orig_name = sanitize_file_name($_FILES['csv']['name']);
    $tmp = $_FILES['csv']['tmp_name'];
    $dest = $dir . uniqid('evedit_') . '_' . $orig_name;
    if (!move_uploaded_file($tmp, $dest)) wp_die('No se pudo mover el archivo', '', 500);
    $url = $baseurl . basename($dest);

    // Hash
    $hash = sha1_file($dest);

    // Leer cabeceras
    $fh = fopen($dest, 'r');
    if (!$fh) wp_die('No se pudo abrir el CSV', '', 500);
    $headers = fgetcsv($fh);
    fclose($fh);
    if (!is_array($headers) || !$headers) wp_die('CSV sin cabeceras', '', 400);

    $headers_norm = [];
    foreach ($headers as $h) { $headers_norm[] = evapp_edit_sanitize_header_key($h); }

    $key = evapp_edit_state_key($event_id, $hash);
    $existing = get_option($key);

    $state = [
        'file'        => $dest,
        'url'         => $url,
        'filename'    => $orig_name,
        'file_hash'   => $hash,
        'headers'     => ['original'=>$headers, 'norm'=>$headers_norm],
        'event_id'    => $event_id,
        'map_search'  => [], // csv index => field_key (criterios)
        'map_update'  => [], // csv index => field_key (actualizar)
        'policies'    => [], // field_key => 'overwrite'|'if_empty'
        'search_logic'=> 'AND',
        'offset'      => 0, // filas procesadas (sin contar cabecera)
        'updated_count'       => 0,
        'unchanged_count'     => 0,
        'no_match_count'      => 0,
        'multi_match_count'   => 0,
        'error_count'         => 0,
    ];

    if (is_array($existing)) {
        foreach (['map_search','map_update','policies','search_logic','offset','updated_count','unchanged_count','no_match_count','multi_match_count','error_count'] as $k) {
            if (isset($existing[$k])) $state[$k] = $existing[$k];
        }
    }

    update_option($key, $state, false);

    // Ir a mapeo
    $url2 = add_query_arg([
        'page'     => 'eventosapp_bulk_edit',
        'event_id' => $event_id,
        'step'     => 2,
        'hash'     => $hash,
    ], admin_url('admin.php'));
    wp_safe_redirect($url2);
    exit;
});


// =====================
// STEP 2: Mapeo
// =====================
add_action('admin_init', function(){
    if (!is_admin() || !isset($_GET['page']) || $_GET['page']!=='eventosapp_bulk_edit') return;
    if (intval($_GET['step'] ?? 0) !== 2) return;

    $event_id = intval($_GET['event_id'] ?? 0);
    $hash     = sanitize_text_field($_GET['hash'] ?? '');
    if (!$event_id || !$hash) return;

    $state = get_option( evapp_edit_state_key($event_id, $hash) );
    if (!$state) return;

    add_action('admin_notices', function() use ($state, $event_id, $hash){
        $headers = $state['headers']['original'];
        $headers_norm = $state['headers']['norm'];
        $nonce = wp_create_nonce('evapp_edit_'.$event_id);

        $catalog = evapp_edit_field_catalog($event_id);

        echo '<div class="wrap" style="max-width:1100px">';
        echo '<h2>Mapear columnas — archivo <code>'.esc_html($state['filename']).'</code></h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-ajax.php')).'">';
        echo '<input type="hidden" name="action" value="eventosapp_edit_save_map">';
        echo '<input type="hidden" name="event_id" value="'.esc_attr($event_id).'">';
        echo '<input type="hidden" name="hash" value="'.esc_attr($hash).'">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'">';

        echo '<p><b>Lógica de búsqueda:</b> ';
        $logic = $state['search_logic'] ?? 'AND';
        echo '<label style="margin-right:12px"><input type="radio" name="search_logic" value="AND" '.checked($logic,'AND',false).'> AND (coinciden todos)</label>';
        echo '<label><input type="radio" name="search_logic" value="OR" '.checked($logic,'OR',false).'> OR (coincide cualquiera)</label>';
        echo '</p>';

        echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>#</th><th>Columna CSV</th><th>Normalizada</th><th>Usar como criterio</th><th>Actualizar a</th><th>Política</th></tr></thead><tbody>';
        foreach ($headers as $i=>$raw){
            echo '<tr>';
            echo '<td>'.intval($i+1).'</td>';
            echo '<td>'.esc_html($raw).'</td>';
            echo '<td><code>'.esc_html($headers_norm[$i]).'</code></td>';

            // Criterio
            $selCrit = $state['map_search'][$i] ?? '';
            echo '<td><select name="map_search['.$i.']">';
            echo '<option value="">— Ninguno —</option>';
            foreach ($catalog as $k=>$label){
                echo '<option value="'.esc_attr($k).'" '.selected($selCrit,$k,false).'>'.esc_html($label.' ['.$k.']').'</option>';
            }
            echo '</select></td>';

            // Actualizar
            $selUpd = $state['map_update'][$i] ?? '';
            echo '<td><select name="map_update['.$i.']">';
            echo '<option value="">— No actualizar —</option>';
            foreach ($catalog as $k=>$label){
                echo '<option value="'.esc_attr($k).'" '.selected($selUpd,$k,false).'>'.esc_html($label.' ['.$k.']').'</option>';
            }
            echo '</select></td>';

            // Política
            $policy_key = $selUpd ?: '__none__';
            $policy_val = $state['policies'][$policy_key] ?? 'overwrite';
            echo '<td>';
            echo '<select name="policy['.$i.']">';
            echo '<option value="overwrite" '.selected($policy_val,'overwrite',false).'>Sobrescribir</option>';
            echo '<option value="if_empty" '.selected($policy_val,'if_empty',false).'>Solo si está vacío</option>';
            echo '</select>';
            echo '</td>';

            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<p class="description" style="margin-top:10px">Consejo: define <b>al menos un criterio</b> y <b>al menos un campo a actualizar</b>. Para <i>extras</i>, usa las entradas "Extra: …".</p>';
        echo '<p><button class="button button-primary">Continuar</button></p>';
        echo '</form>';
        echo '</div>';
    });
});

// Guardar mapeo → paso 3
add_action('wp_ajax_eventosapp_edit_save_map', function(){
    if (!current_user_can('manage_options')) wp_die('No autorizado', '', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';
    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_edit_'.$event_id)) wp_die('Solicitud inválida', '', 400);

    $state = get_option( evapp_edit_state_key($event_id, $hash) );
    if (!$state) wp_die('Estado no encontrado', '', 404);

    $map_search = isset($_POST['map_search']) && is_array($_POST['map_search']) ? array_map('sanitize_text_field', $_POST['map_search']) : [];
    $map_update = isset($_POST['map_update']) && is_array($_POST['map_update']) ? array_map('sanitize_text_field', $_POST['map_update']) : [];
    $policy_in  = isset($_POST['policy']) && is_array($_POST['policy']) ? $_POST['policy'] : [];
    $logic      = strtoupper(sanitize_text_field($_POST['search_logic'] ?? 'AND'));
    if ($logic !== 'OR') $logic = 'AND';

    // Construir policies por field_key (usa el field mapeado en update para esa fila)
    $policies = [];
    foreach ($policy_in as $i=>$pol) {
        $fk = $map_update[$i] ?? '';
        if ($fk) $policies[$fk] = ($pol === 'if_empty') ? 'if_empty' : 'overwrite';
    }

    $state['map_search']   = $map_search;
    $state['map_update']   = $map_update;
    $state['policies']     = $policies;
    $state['search_logic'] = $logic;

    update_option( evapp_edit_state_key($event_id, $hash), $state, false );

    $url3 = add_query_arg([
        'page'=>'eventosapp_bulk_edit','event_id'=>$event_id,'step'=>3,'hash'=>$hash
    ], admin_url('admin.php'));
    wp_safe_redirect($url3);
    exit;
});


// =====================
// STEP 3: Previsualizar
// =====================
add_action('admin_init', function(){
    if (!is_admin() || !isset($_GET['page']) || $_GET['page']!=='eventosapp_bulk_edit') return;
    if (intval($_GET['step'] ?? 0) !== 3) return;

    $event_id = intval($_GET['event_id'] ?? 0);
    $hash     = sanitize_text_field($_GET['hash'] ?? '');
    if (!$event_id || !$hash) return;

    $state = get_option( evapp_edit_state_key($event_id, $hash) );
    if (!$state) return;

    add_action('admin_notices', function() use ($state, $event_id, $hash){
        $nonce = wp_create_nonce('evapp_edit_'.$event_id);
        $headers = $state['headers']['original'];
        $mapS = $state['map_search']; // criterios
        $mapU = $state['map_update']; // actualizaciones
        $logic = $state['search_logic'] ?? 'AND';

        // Validaciones mínimas
        $has_criteria = false;
        foreach ($mapS as $k=>$v){ if ($v) { $has_criteria = true; break; } }
        $has_updates = false;
        foreach ($mapU as $k=>$v){ if ($v) { $has_updates = true; break; } }

        echo '<div class="wrap" style="max-width:1100px">';
        echo '<h2>Confirmar edición</h2>';

        if (!$has_criteria || !$has_updates) {
            echo '<div class="notice notice-error"><p>';
            if (!$has_criteria) echo 'Debes definir al menos <b>un criterio</b> de búsqueda. ';
            if (!$has_updates)  echo 'Debes seleccionar al menos <b>un campo a actualizar</b>.';
            echo '</p></div>';
            $back = add_query_arg(['step'=>2], remove_query_arg([]));
            echo '<p><a class="button" href="'.esc_url($back).'">Volver al mapeo</a></p>';
            echo '</div>';
            return;
        }

        // Leer 10 filas de vista previa desde offset actual
        $fh = fopen($state['file'], 'r');
        $hdr = fgetcsv($fh);
        $line = 1;

        $offset_now = intval($state['offset'] ?? 0);
        $skipped = 0;
        while ($skipped < $offset_now && ($row = fgetcsv($fh)) !== false) { $skipped++; $line++; }

        $preview = [];
        while (($row = fgetcsv($fh)) !== false && count($preview) < 10) {
            $line++;
            // consulta
            $args = evapp_edit_build_query_args($event_id, $mapS, $row, $logic);
            $ids = get_posts($args);
            $status = count($ids) === 1 ? 'match1' : (count($ids) === 0 ? 'nomatch' : 'multi');

            $diffs = [];
            if ($status === 'match1') {
                $pid = (int) $ids[0];
                foreach ($mapU as $i=>$field_key){
                    if (!$field_key) continue;
                    $spec = evapp_edit_field_to_meta($field_key);
                    if (!$spec) continue;
                    $new = isset($row[$i]) ? trim((string)$row[$i]) : '';
                    if ($field_key === 'email') $new = sanitize_email($new);
                    $cur = get_post_meta($pid, $spec['key'], true);

                    // Normalización de extras si aplica
                    if (strpos($field_key,'extra__')===0 && function_exists('eventosapp_normalize_extra_value')) {
                        $ek = substr($field_key,7);
                        $schema = function_exists('eventosapp_get_event_extra_fields') ? eventosapp_get_event_extra_fields($event_id) : [];
                        $bykey = [];
                        foreach ($schema as $f){ $bykey[$f['key']] = $f; }
                        if (isset($bykey[$ek])) $new = eventosapp_normalize_extra_value($bykey[$ek], $new);
                    }

                    $policy = $state['policies'][$field_key] ?? 'overwrite';
                    $should = false;
                    if ($policy === 'overwrite') {
                        $should = ($new !== '' && $new !== $cur);
                    } else { // if_empty
                        $should = ($new !== '' && ($cur === '' || $cur === null));
                    }
                    if ($should) {
                        $diffs[] = [
                            'field'=>$field_key,
                            'meta' =>$spec['key'],
                            'from' =>$cur,
                            'to'   =>$new,
                        ];
                    }
                }
            }

            $preview[] = [
                'line'   => $line,
                'status' => $status,
                'diffs'  => $diffs,
            ];
        }
        fclose($fh);

        echo '<p>Archivo: <code>'.esc_html($state['filename']).'</code> — lógica: <b>'.esc_html($logic).'</b> — columnas mapeadas (criterios/actualizaciones) listas.</p>';
        echo '<table class="widefat striped"><thead><tr><th>#</th><th>Estado</th><th>Cambios previstos</th></tr></thead><tbody>';
        foreach ($preview as $it){
            echo '<tr>';
            echo '<td>'.intval($it['line']).'</td>';
            $s = $it['status']==='match1' ? '1 coincidencia' : ($it['status']==='nomatch' ? 'sin coincidencia' : 'múltiples coincidencias');
            $color = $it['status']==='match1' ? '#0a0' : ($it['status']==='nomatch' ? '#b00' : '#b60');
            echo '<td style="color:'.$color.'">'.esc_html($s).'</td>';

            if ($it['diffs']) {
                echo '<td>';
                foreach ($it['diffs'] as $d) {
                    echo '<div><code>'.esc_html($d['field']).'</code>: <span style="color:#b00">'.esc_html(mb_strimwidth((string)$d['from'],0,60,'…')).'</span> → <span style="color:#0a0">'.esc_html(mb_strimwidth((string)$d['to'],0,60,'…')).'</span></div>';
                }
                echo '</td>';
            } else {
                echo '<td><em>Sin cambios</em></td>';
            }

            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-ajax.php')).'" style="margin-top:12px">';
        echo '<input type="hidden" name="action" value="eventosapp_edit_confirm">';
        echo '<input type="hidden" name="event_id" value="'.esc_attr($event_id).'">';
        echo '<input type="hidden" name="hash" value="'.esc_attr($hash).'">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'">';
        echo '<p><button class="button button-primary">Empezar actualización</button></p>';
        echo '</form>';

        echo '</div>';
    });
});

// Confirmar → paso 4 UI
add_action('wp_ajax_eventosapp_edit_confirm', function(){
    if (!current_user_can('manage_options')) wp_die('No autorizado', '', 403);
    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';
    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_edit_'.$event_id)) wp_die('Solicitud inválida', '', 400);

    // Nada más que redirigir al step 4 (progreso)
    $url4 = add_query_arg(['page'=>'eventosapp_bulk_edit','event_id'=>$event_id,'step'=>4,'hash'=>$hash], admin_url('admin.php'));
    wp_safe_redirect($url4);
    exit;
});


// =====================
// STEP 4: Progreso + botón
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

        echo '<div class="wrap" style="max-width:900px">';
        echo '<h2>Actualizar — progreso</h2>';
        echo '<p>Archivo: <code>'.esc_html($state['filename']).'</code></p>';
        echo '<p>Procesadas: <b id="ev_cnt">'.intval($state['offset']).'</b> &nbsp;|&nbsp; ';
        echo 'Actualizadas: <b id="ev_upd">'.intval($state['updated_count']).'</b> &nbsp;|&nbsp; ';
        echo 'Sin cambio: <b id="ev_same">'.intval($state['unchanged_count']).'</b> &nbsp;|&nbsp; ';
        echo 'Sin match: <b id="ev_nom">'.intval($state['no_match_count']).'</b> &nbsp;|&nbsp; ';
        echo 'Múltiples: <b id="ev_mul">'.intval($state['multi_match_count']).'</b> &nbsp;|&nbsp; ';
        echo 'Errores: <b id="ev_err">'.intval($state['error_count']).'</b></p>';

        echo '<div id="ev_log" style="background:#0b1020;color:#eaf1ff;padding:8px;border-radius:8px;min-height:140px;max-height:300px;overflow:auto;font-family:monospace;font-size:12px"></div>';
        echo '<p style="margin-top:10px"><button class="button" id="ev_btn_next">Procesar siguiente lote (100)</button></p>';

        echo '<script>
(function(){
  const btn = document.getElementById("ev_btn_next");
  const log = document.getElementById("ev_log");
  const c   = document.getElementById("ev_cnt");
  const u   = document.getElementById("ev_upd");
  const s   = document.getElementById("ev_same");
  const nm  = document.getElementById("ev_nom");
  const mm  = document.getElementById("ev_mul");
  const er  = document.getElementById("ev_err");
  let busy = false;

  function add(msg){ log.innerHTML += msg + "<br>"; log.scrollTop = log.scrollHeight; }

  async function tick(){
    if (busy) return;
    busy = true;
    btn.disabled = true;
    try{
      const fd = new FormData();
      fd.append("action","eventosapp_edit_process");
      fd.append("event_id","'.intval($event_id).'");
      fd.append("hash","'.esc_js($hash).'");
      fd.append("_wpnonce","'.esc_js($nonce).'");
      const resp = await fetch(ajaxurl,{method:"POST",body:fd,credentials:"same-origin"});
      const j = await resp.json();
      if (!j || !j.success) throw new Error(j && j.data ? j.data : "Error");
      c.textContent  = j.data.offset;
      u.textContent  = j.data.updated_count;
      s.textContent  = j.data.unchanged_count;
      nm.textContent = j.data.no_match_count;
      mm.textContent = j.data.multi_match_count;
      er.textContent = j.data.error_count;
      add(j.data.msg);
      if (j.data.done){
        add("✔ Actualización finalizada.");
        btn.remove();
      }
    } catch(e){ add("❌ "+e.message); }
    finally { busy = false; btn.disabled = false; }
  }

  btn.addEventListener("click", tick);
})();
        </script>';

        echo '</div>';
    });
});


// =====================
// Procesar lote (100)
// =====================
add_action('wp_ajax_eventosapp_edit_process', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('No autorizado', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';
    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_edit_'.$event_id)) wp_send_json_error('Solicitud inválida', 400);

    $state = get_option( evapp_edit_state_key($event_id, $hash) );
    if (!$state) wp_send_json_error('Estado no encontrado', 404);

    $CHUNK = 100;
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
            // 1) Buscar ticket(s)
            $args = evapp_edit_build_query_args($event_id, $mapS, $row, $logic);
            $ids = get_posts($args);

            if (!$ids) { $nomatch++; $offset++; continue; }
            if (count($ids) > 1) { $multi++; $offset++; continue; }

            $pid = (int)$ids[0];
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
            if ($changes > 0 && function_exists('eventosapp_ticket_build_search_blob')) {
                eventosapp_ticket_build_search_blob($pid);
            }

            if ($changes > 0) $updated++; else $same++;
        } catch (\Throwable $e) {
            $errs++;
            $log_msgs[] = 'L'.intval($line).': '.$e->getMessage();
        }

        $offset++;
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

    wp_send_json_success([
        'offset'            => $offset,
        'updated_count'     => $updated,
        'unchanged_count'   => $same,
        'no_match_count'    => $nomatch,
        'multi_match_count' => $multi,
        'error_count'       => $errs,
        'msg'               => 'Lote: '.$processed.' filas — upd: '.$updated.', same: '.$same.', no-match: '.$nomatch.', multi: '.$multi.', err: '.$errs.( $log_msgs ? ' | '.implode(' | ', array_slice($log_msgs,0,3)) : '' ),
        'done'              => $done ? 1 : 0,
    ]);
});
