<?php
if (!defined('ABSPATH')) exit;

/**
 * Herramientas por evento: Importador CSV de tickets (asistente 4 pasos) + envío masivo opcional.
 *
 * URL: /wp-admin/admin.php?page=eventosapp_tools&event_id=ID
 */

//
// === Menú y accesos ===
//
add_action('admin_menu', function(){
    // Subpágina "oculta" colgada de EventosApp, pero accesible por URL y enlaces
    add_submenu_page(
        'eventosapp_dashboard',
        'Herramientas',
        'Herramientas',
        'manage_options',
        'eventosapp_tools',
        'eventosapp_tools_render',
        40
    );
}, 20);

// Enlace “Herramientas” en fila de eventos
add_filter('post_row_actions', function($actions, $post){
    if ($post->post_type === 'eventosapp_event' && current_user_can('manage_options')) {
        $url = add_query_arg([
            'page'     => 'eventosapp_tools',
            'event_id' => $post->ID,
        ], admin_url('admin.php'));
        $actions['evapp_tools'] = '<a href="'.esc_url($url).'">Herramientas</a>';
    }
    return $actions;
}, 10, 2);

//
// === Utilidades internas ===
//
function evapp_import_upload_dir() {
    $u = wp_upload_dir();
    $dir = trailingslashit($u['basedir']).'eventosapp-imports/';
    if (!file_exists($dir)) wp_mkdir_p($dir);
    return [$dir, trailingslashit($u['baseurl']).'eventosapp-imports/'];
}
function evapp_sanitize_header_key($s){
    $s = remove_accents(strtolower(trim($s)));
    $s = preg_replace('/[^a-z0-9_]+/','_',$s);
    $s = trim($s,'_');
    return $s;
}
function evapp_current_user_key(){
    $u = wp_get_current_user();
    return 'u'.($u && $u->ID ? $u->ID : 0);
}

// === NUEVO: obtener/crear el usuario importador1 ===
function evapp_get_or_create_importer_user(){
    $login = 'importador1';
    $email = 'importador1@eventosapp.com';

    // Primero por correo (requisito), luego por login como fallback
    $u = get_user_by('email', $email);
    if (!$u) { $u = get_user_by('login', $login); }
    if ($u && isset($u->ID)) {
        return (int) $u->ID;
    }

    // Crear sin notificar por correo
    $user_id = wp_insert_user([
        'user_login'   => $login,
        'user_email'   => $email,
        'user_pass'    => wp_generate_password(20, true, true),
        'display_name' => 'Importador 1',
        'first_name'   => 'Importador',
        'last_name'    => '1',
        'role'         => 'logistico', // suficiente para marcar autoría
    ]);

    if (is_wp_error($user_id)) {
        // Fallback: si falla, regresamos el usuario actual para no romper el flujo
        return (int) get_current_user_id();
    }
    return (int) $user_id;
}


/**
 * Genera CSV de plantilla (descarga). Incluye extras del evento.
 */
add_action('admin_post_eventosapp_csv_template', function(){
    if (!current_user_can('manage_options')) wp_die('No autorizado', '', 403);
    $event_id = intval($_GET['event_id'] ?? 0);
    if (!$event_id || get_post_type($event_id) !== 'eventosapp_event') wp_die('Evento inválido', '', 400);

    $filename = 'plantilla_tickets_evento_'.$event_id.'.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    $out = fopen('php://output','w');

    // Cabeceras base
    $headers = [
        'external_id_opcional', // para idempotencia/reintentos
        'nombre',
        'apellido',
        'email',
        'telefono',
        'empresa',
        'nit',
        'cargo',
        'cc',
        'ciudad',
        'pais',
        'localidad'
    ];

    // Extras del evento
    $extras = function_exists('eventosapp_get_event_extra_fields') ? eventosapp_get_event_extra_fields($event_id) : [];
    foreach ($extras as $f) {
        $headers[] = 'extra__'.$f['key']; // prefijo estable
    }

    fputcsv($out, $headers);

    // Fila de ejemplo
    $example = [
        'ABC-001',
        'Ana',
        'Pérez',
        'ana@example.com',
        '+57 300 1234567',
        'Mi Empresa SAS',
        '900123456',
        'Gerente',
        '1030xxx',
        'Barranquilla',
        'Colombia',
        'VIP'
    ];
    foreach ($extras as $f) {
        // ejemplo genérico
        $example[] = ($f['type']==='select' && !empty($f['options'])) ? $f['options'][0] : 'valor';
    }
    fputcsv($out, $example);
    fclose($out);
    exit;
});

/**
 * Barra fija con selector de evento en la cabecera de "Herramientas".
 */
function evapp_tools_event_picker_bar($current_event_id = 0){
    // lista de eventos (publicados y borradores), ordenados por título
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

    // placeholder
    echo '<option value="">— Selecciona un evento —</option>';

    foreach ($events as $eid){
        $t = get_the_title($eid);
        $sel = selected($eid, $current_event_id, false);
        echo '<option value="'.esc_attr($eid).'" '.$sel.'>'.esc_html($t.' ['.$eid.']').'</option>';
    }
    echo '</select>';

    // enlace rápido para editar el evento actual
    if ($current_event_id){
        $edit = get_edit_post_link($current_event_id);
        if ($edit){
            echo '<a class="button" href="'.esc_url($edit).'">Editar evento</a>';
        }
    }
    echo '</div>';

    // JS: al cambiar de evento, te lleva a la misma página con step=1
    ?>
    <script>
    (function(){
      var sel = document.getElementById('evapp_event_picker');
      if (!sel) return;
      sel.addEventListener('change', function(){
        var v = this.value;
        var url = new URL(window.location.href);
        url.searchParams.set('page','eventosapp_tools');
        if (v) {
          url.searchParams.set('event_id', v);
          url.searchParams.set('step', '1');   // siempre empezamos en el paso 1
          url.searchParams.delete('hash');     // limpiamos estado previo
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

/**
 * Render de la pantalla Herramientas (asistente)
 */
function eventosapp_tools_render(){
    if (!current_user_can('manage_options')) wp_die('No autorizado', '', 403);

    $event_id = intval($_GET['event_id'] ?? 0);
    $step     = intval($_GET['step'] ?? 1);
    if ($step < 1 || $step > 4) $step = 1;

    echo '<div class="wrap" style="max-width:1100px">';
    echo '<h1>Herramientas</h1>';

    // ——— NUEVO: barra con selector de evento ———
    evapp_tools_event_picker_bar($event_id);

    // si todavía no hay evento seleccionado, paramos aquí (solo mostramos la barra)
    if (!$event_id || get_post_type($event_id) !== 'eventosapp_event') {
        echo '<p>Elige un evento para iniciar el asistente de importación.</p>';
        echo '</div>';
        return;
    }

    $event = get_post($event_id);
    $step  = intval($_GET['step'] ?? 1);
    if ($step < 1 || $step > 4) $step = 1;

    $nonce = wp_create_nonce('evapp_tools_'.$event_id);

    $tpl_url = wp_nonce_url(
        add_query_arg(['action'=>'eventosapp_csv_template','event_id'=>$event_id], admin_url('admin-post.php')),
        'eventosapp_csv_template'
    );

    // Progreso previo (por hash+evento)
    $progress = evapp_import_get_latest_progress($event_id);

    echo '<div class="wrap" style="max-width:1100px">';
    echo '<h1>Herramientas — <span style="color:#555">'.esc_html($event->post_title).' ['.$event_id.']</span></h1>';

    // Migas del asistente
    echo '<h2 class="nav-tab-wrapper" style="margin-top:20px">';
    $tabs = ['1'=>'Subir CSV','2'=>'Mapear columnas','3'=>'Confirmar','4'=>'Importar'];
    foreach($tabs as $i=>$label){
        $cls = ($step===$i*1) ? ' nav-tab-active' : '';
        $url = add_query_arg(['page'=>'eventosapp_tools','event_id'=>$event_id,'step'=>$i], admin_url('admin.php'));
        echo '<a class="nav-tab'.$cls.'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
    }
    echo '</h2>';

    // Barra progreso previa
    if ($progress) {
        echo '<div style="background:#f6fbff;border:1px solid #bee3f8;padding:10px 12px;border-radius:8px;margin:8px 0 16px">';
        echo '<b>Importación previa detectada:</b> archivo <code>'.esc_html($progress['filename']).'</code> ';
        echo '(hash <code>'.esc_html(substr($progress['file_hash'],0,10)).'…</code>) — filas procesadas: <b>'.$progress['offset'].'</b>.';
        echo ' Puedes re-subir el <i>mismo</i> archivo para continuar o ir directo al paso 4.';
        echo '</div>';
    }

    // STEP 1: subir + plantilla
    if ($step === 1) {
        echo '<div class="card" style="padding:18px">';
        echo '<p>Descarga la <b>plantilla CSV</b> con los campos estándar y los <b>extras</b> configurados para este evento: ';
        echo '<a class="button button-secondary" href="'.esc_url($tpl_url).'">Descargar plantilla</a></p>';

        echo '<hr>';
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-ajax.php')).'">';
        echo '<input type="hidden" name="action" value="eventosapp_import_upload">';
        echo '<input type="hidden" name="event_id" value="'.esc_attr($event_id).'">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'">';
        echo '<p><label><b>Elige tu CSV:</b><br>';
        echo '<input type="file" name="csv" accept=".csv,text/csv" required></label></p>';
        echo '<p><button class="button button-primary">Continuar</button></p>';
        echo '</form>';
        echo '</div>';
    }

    // STEP 2 y 3 y 4 renderizados por AJAX / redirecciones
    echo '</div>';
}

/**
 * Estructura de importación (estado por archivo)
 * option: evapp_import_state_{event_id}_{hash}_{userkey} = [
 *   'file' => PATH,
 *   'url'  => URL,
 *   'filename' => string,
 *   'file_hash' => string,
 *   'headers' => ['original'=>..., 'norm'=>...],
 *   'map' => ['csv_key' => 'field_key'],
 *   'offset' => int,
 *   'created_ids' => [ticket_post_id,...],
 *   'created_count' => int,
 * ]
 */
function evapp_import_state_key($event_id, $hash){
    return 'evapp_import_state_'.$event_id.'_'.$hash.'_'.evapp_current_user_key();
}
function evapp_import_get_latest_progress($event_id){
    global $wpdb;
    $like = 'evapp_import_state_'.$event_id.'_%_'.evapp_current_user_key();
    $opt = $wpdb->get_var( $wpdb->prepare(
        "SELECT option_name
         FROM $wpdb->options
         WHERE option_name LIKE %s
         ORDER BY option_id DESC
         LIMIT 1",
         $like
    ) );
    if (!$opt) return null;
    $st = get_option($opt);
    return is_array($st) ? $st : null;
}


//
// === AJAX Paso 1 → recibe CSV, detecta cabeceras y pasa a Step 2 (mapeo) ===
//
add_action('wp_ajax_eventosapp_import_upload', function(){
    if (!current_user_can('manage_options')) wp_die('No autorizado', '', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $nonce    = $_POST['_wpnonce'] ?? '';
    if (!$event_id || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) wp_die('Solicitud inválida', '', 400);

    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        wp_die('Archivo CSV requerido', '', 400);
    }

    // Mover a uploads
    [$dir, $baseurl] = evapp_import_upload_dir();
    $orig_name = sanitize_file_name($_FILES['csv']['name']);
    $tmp = $_FILES['csv']['tmp_name'];
    $dest = $dir . uniqid('evimp_') . '_' . $orig_name;
    if (!move_uploaded_file($tmp, $dest)) wp_die('No se pudo mover el archivo', '', 500);
    $url = $baseurl . basename($dest);

    // Hash del contenido
    $hash = sha1_file($dest);

    // Leer cabeceras
    $fh = fopen($dest, 'r');
    if (!$fh) wp_die('No se pudo abrir el CSV', '', 500);
    $headers = fgetcsv($fh);
    fclose($fh);
    if (!is_array($headers) || !$headers) wp_die('CSV sin cabeceras', '', 400);

    $headers_norm = [];
    foreach ($headers as $h) { $headers_norm[] = evapp_sanitize_header_key($h); }

 // Clave de estado por evento+hash+usuario
$key = evapp_import_state_key($event_id, $hash);
$existing = get_option($key);

// Estado base (datos del archivo recién subido)
$state = [
    'file'         => $dest,
    'url'          => $url,
    'filename'     => $orig_name,
    'file_hash'    => $hash,
    'headers'      => ['original'=>$headers, 'norm'=>$headers_norm],
    'map'          => [],
    'offset'       => 0,
    'created_ids'  => [],
    'created_count'=> 0,
    'event_id'     => $event_id,
];

// ⬅️ NUEVO: si ya existía estado para este mismo hash, preservamos progreso y configuración
if (is_array($existing)) {
    $state['map']           = $existing['map']           ?? [];
    $state['offset']        = intval($existing['offset'] ?? 0);
    $state['created_ids']   = is_array($existing['created_ids'] ?? null) ? $existing['created_ids'] : [];
    $state['created_count'] = intval($existing['created_count'] ?? 0);
    if (isset($existing['queue_email']))  $state['queue_email']  = $existing['queue_email'];
    if (isset($existing['rate_per_min'])) $state['rate_per_min'] = $existing['rate_per_min'];
}

update_option($key, $state, false);

    // Redirigir a paso 2 (mapeo)
    $url2 = add_query_arg([
        'page'     => 'eventosapp_tools',
        'event_id' => $event_id,
        'step'     => 2,
        'hash'     => $hash,
    ], admin_url('admin.php'));
    wp_safe_redirect($url2);
    exit;
});

//
// === Render mapeo (step 2) ===
//
add_action('admin_init', function(){
    if (!is_admin() || !isset($_GET['page']) || $_GET['page']!=='eventosapp_tools') return;
    $step = intval($_GET['step'] ?? 0);
    if ($step !== 2) return;

    $event_id = intval($_GET['event_id'] ?? 0);
    $hash     = sanitize_text_field($_GET['hash'] ?? '');
    $state    = $hash ? get_option( evapp_import_state_key($event_id, $hash) ) : null;
    if (!$state) return;

    add_action('admin_notices', function() use ($state, $event_id, $hash){
        $headers = $state['headers']['original'];
        $headers_norm = $state['headers']['norm'];
        $nonce = wp_create_nonce('evapp_tools_'.$event_id);

        // Campos disponibles en plataforma
        $platform_fields = [
            'external_id' => 'ID externo (opcional, idempotencia)',
            'nombre'      => 'Nombre',
            'apellido'    => 'Apellido',
            'email'       => 'Email',
            'telefono'    => 'Teléfono',
            'empresa'     => 'Empresa',
            'nit'         => 'NIT',
            'cargo'       => 'Cargo',
            'cc'          => 'Cédula',
            'ciudad'      => 'Ciudad',
            'pais'        => 'País',
            'localidad'   => 'Localidad',
        ];
        $extras = function_exists('eventosapp_get_event_extra_fields') ? eventosapp_get_event_extra_fields($event_id) : [];
        foreach ($extras as $f) {
            $platform_fields['extra__'.$f['key']] = 'Extra: '.$f['label'];
        }

        echo '<div class="wrap" style="max-width:1100px">';
        echo '<h2>Mapear columnas — archivo <code>'.esc_html($state['filename']).'</code></h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-ajax.php')).'">';
        echo '<input type="hidden" name="action" value="eventosapp_import_save_map">';
        echo '<input type="hidden" name="event_id" value="'.esc_attr($event_id).'">';
        echo '<input type="hidden" name="hash" value="'.esc_attr($hash).'">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'">';

        echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>Columna CSV</th><th>Columna normalizada</th><th>Mapear a</th></tr></thead><tbody>';
        foreach ($headers as $i=>$raw){
            echo '<tr>';
            echo '<td>'.esc_html($raw).'</td>';
            echo '<td><code>'.esc_html($headers_norm[$i]).'</code></td>';
            echo '<td><select name="map['.$i.']">';
            echo '<option value="">— No importar —</option>';
            foreach ($platform_fields as $k=>$label){
                $sel = '';
                // Autopropuesta simple por coincidencia
                if ($headers_norm[$i] === evapp_sanitize_header_key($k)) $sel = ' selected';
                if ($headers_norm[$i] === evapp_sanitize_header_key('extra__'.str_replace('extra__','',$k))) $sel = ' selected';
                echo '<option value="'.esc_attr($k).'"'.$sel.'>'.esc_html($label.' ['.$k.']').'</option>';
            }
            echo '</select></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<p style="margin-top:12px"><b>Requeridos mínimos:</b> Nombre, Apellido, Email o CC, y Localidad.</p>';
        echo '<p><button class="button button-primary">Continuar</button></p>';
        echo '</form>';
        echo '</div>';
    });
});

//
// === Guardar mapeo → Step 3 (confirmación/validación) ===
//
add_action('wp_ajax_eventosapp_import_save_map', function(){
    if (!current_user_can('manage_options')) wp_die('No autorizado', '', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';
    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) wp_die('Solicitud inválida', '', 400);

    $state = get_option( evapp_import_state_key($event_id, $hash) );
    if (!$state) wp_die('Estado no encontrado', '', 404);

    $map = isset($_POST['map']) && is_array($_POST['map']) ? array_map('sanitize_text_field', $_POST['map']) : [];
    $state['map'] = $map;
    update_option( evapp_import_state_key($event_id, $hash), $state, false );

    // Redirigir a Step 3
    $url3 = add_query_arg([
        'page'=>'eventosapp_tools','event_id'=>$event_id,'step'=>3,'hash'=>$hash
    ], admin_url('admin.php'));
    wp_safe_redirect($url3);
    exit;
});

add_action('admin_init', function(){
    if (!is_admin() || !isset($_GET['page']) || $_GET['page']!=='eventosapp_tools') return;
    $step = intval($_GET['step'] ?? 0);
    if ($step !== 3) return;

    $event_id = intval($_GET['event_id'] ?? 0);
    $hash     = sanitize_text_field($_GET['hash'] ?? '');
    $state    = $hash ? get_option( evapp_import_state_key($event_id, $hash) ) : null;
    if (!$state) return;

    add_action('admin_notices', function() use ($state, $event_id, $hash){
        $nonce = wp_create_nonce('evapp_tools_'.$event_id);

        // Validar mapeo mínimo
        $csv_keys = $state['headers']['norm'];
        $map = $state['map'];
        $rev = []; // indice csv_i => field_key
        foreach ($map as $i=>$k) { if ($k) $rev[intval($i)] = $k; }

        $required = ['nombre','apellido','localidad'];
        $has_contact = (in_array('email', $rev, true) || in_array('cc', $rev, true));
        $missing = [];
        foreach ($required as $r) if (!in_array($r, $rev, true)) $missing[] = $r;

        echo '<div class="wrap" style="max-width:1100px">';
        echo '<h2>Confirmar importación</h2>';

        if ($missing || !$has_contact) {
            echo '<div class="notice notice-error"><p>';
            echo 'Campos obligatorios faltantes: <b>'.esc_html(implode(', ', $missing)).'</b>. ';
            if (!$has_contact) echo 'Debes mapear al menos <b>Email</b> o <b>CC</b>.';
            echo '</p></div>';
            $back = add_query_arg(['step'=>2], remove_query_arg([]));
            echo '<p><a class="button" href="'.esc_url($back).'">Volver al mapeo</a></p>';
            echo '</div>';
            return;
        }

			// Muestra un preview de 10 filas con validaciones básicas
			$fh = fopen($state['file'], 'r');
			$hdr = fgetcsv($fh);
			$preview = [];
			$line = 1;

			// ⬅️ NUEVO: saltar filas ya procesadas (offset actual)
			$offset_now = intval($state['offset'] ?? 0);
			$skipped = 0;
			while ($skipped < $offset_now && ($row = fgetcsv($fh)) !== false) { $skipped++; $line++; }

			while (($row = fgetcsv($fh)) !== false && count($preview) < 10) {
				$line++;
				$item = ['line'=>$line,'errors'=>[],'data'=>[]];
				foreach ($rev as $i=>$field){
					$val = $row[$i] ?? '';
					$item['data'][$field] = $val;
				}
				// Validaciones simples
				if (empty($item['data']['nombre']))    $item['errors'][]='nombre vacío';
				if (empty($item['data']['apellido']))  $item['errors'][]='apellido vacío';
				if (empty($item['data']['localidad'])) $item['errors'][]='localidad vacía';
				if (empty($item['data']['email']) && empty($item['data']['cc'])) $item['errors'][]='email/cc vacío';
				if (!empty($item['data']['email']) && !is_email($item['data']['email'])) $item['errors'][]='email inválido';
				$preview[] = $item;
			}
			fclose($fh);


        echo '<p>Archivo: <code>'.esc_html($state['filename']).'</code> — columnas mapeadas: <code>'.esc_html(count($rev)).'</code>.</p>';
        echo '<table class="widefat striped"><thead><tr><th>#</th><th>Errores</th><th>Datos (parciales)</th></tr></thead><tbody>';
        foreach ($preview as $it){
            echo '<tr>';
            echo '<td>'.intval($it['line']).'</td>';
            echo '<td>'.($it['errors'] ? '<span style="color:#b00">'.esc_html(implode('; ', $it['errors'])).'</span>' : '<span style="color:#0a0">OK</span>').'</td>';
            $show = [];
            foreach (['nombre','apellido','email','cc','localidad'] as $k){
                if (isset($it['data'][$k])) $show[] = $k.': '.$it['data'][$k];
            }
            echo '<td>'.esc_html(implode(' | ', $show)).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Config envío masivo
        echo '<form method="post" action="'.esc_url(admin_url('admin-ajax.php')).'" style="margin-top:12px">';
        echo '<input type="hidden" name="action" value="eventosapp_import_confirm">';
        echo '<input type="hidden" name="event_id" value="'.esc_attr($event_id).'">';
        echo '<input type="hidden" name="hash" value="'.esc_attr($hash).'">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'">';
        echo '<p><label><input type="checkbox" name="queue_email" value="1"> Al finalizar, <b>programar envío masivo por correo</b> a los tickets importados.</label></p>';
        echo '<p style="margin-left:22px">Ritmo: <input type="number" name="rate_per_min" value="30" min="1" max="120" style="width:70px"> emails/min</p>';
        echo '<p><button class="button button-primary">Empezar importación</button></p>';
        echo '</form>';

        echo '</div>';
    });
});

//
// === Step 4: confirmar → arrancar importación y mostrar progreso (100 en 100) ===
//
add_action('wp_ajax_eventosapp_import_confirm', function(){
    if (!current_user_can('manage_options')) wp_die('No autorizado', '', 403);
    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';
    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) wp_die('Solicitud inválida', '', 400);

    $state = get_option( evapp_import_state_key($event_id, $hash) );
    if (!$state) wp_die('Estado no encontrado', '', 404);

    $state['queue_email']  = !empty($_POST['queue_email']) ? 1 : 0;
    $rate = intval($_POST['rate_per_min'] ?? 30);
    $state['rate_per_min'] = max(1, min(120, $rate));
    update_option( evapp_import_state_key($event_id, $hash), $state, false );

    // Redirigir a step 4 (UI de progreso)
    $url4 = add_query_arg(['page'=>'eventosapp_tools','event_id'=>$event_id,'step'=>4,'hash'=>$hash], admin_url('admin.php'));
    wp_safe_redirect($url4);
    exit;
});

add_action('admin_init', function(){
    if (!is_admin() || !isset($_GET['page']) || $_GET['page']!=='eventosapp_tools') return;
    $step = intval($_GET['step'] ?? 0);
    if ($step !== 4) return;

    $event_id = intval($_GET['event_id'] ?? 0);
    $hash     = sanitize_text_field($_GET['hash'] ?? '');
    $state    = $hash ? get_option( evapp_import_state_key($event_id, $hash) ) : null;
    if (!$state) return;

    add_action('admin_notices', function() use ($state, $event_id, $hash){
        $nonce = wp_create_nonce('evapp_tools_'.$event_id);

        echo '<div class="wrap" style="max-width:900px">';
        echo '<h2>Importar — progreso</h2>';
        echo '<p>Archivo: <code>'.esc_html($state['filename']).'</code> | Procesadas: <b id="evapp_count">'.intval($state['offset']).'</b> | Creadas: <b id="evapp_created">'.intval($state['created_count']).'</b></p>';
        echo '<div id="evapp_log" style="background:#0b1020;color:#eaf1ff;padding:8px;border-radius:8px;min-height:140px;max-height:300px;overflow:auto;font-family:monospace;font-size:12px"></div>';
        echo '<p style="margin-top:10px"><button class="button" id="evapp_btn_next">Procesar siguiente lote (100)</button></p>';
        echo '<script>
(function(){
  const btn = document.getElementById("evapp_btn_next");
  const log = document.getElementById("evapp_log");
  const c   = document.getElementById("evapp_count");
  const k   = document.getElementById("evapp_created");
  let busy = false;

  function add(msg){ log.innerHTML += msg + "<br>"; log.scrollTop = log.scrollHeight; }

  async function tick(){
    if (busy) return;
    busy = true;
    btn.disabled = true;
    try{
      const fd = new FormData();
      fd.append("action","eventosapp_import_process");
      fd.append("event_id","'.intval($event_id).'");
      fd.append("hash","'.esc_js($hash).'");
      fd.append("_wpnonce","'.esc_js($nonce).'");
      const resp = await fetch(ajaxurl,{method:"POST",body:fd,credentials:"same-origin"});
      const j = await resp.json();
      if (!j || !j.success) throw new Error(j && j.data ? j.data : "Error");
      c.textContent = j.data.offset;
      k.textContent = j.data.created_count;
      add(j.data.msg);
      if (j.data.done){
        add("✔ Importación finalizada: "+j.data.created_count+" tickets creados.");
        if (j.data.email_queue_created){
          add("✉ Envío masivo programado: "+j.data.email_batch+" e-mails/min.");
        }
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

//
// === Procesar un lote (100) ===
//
add_action('wp_ajax_eventosapp_import_process', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('No autorizado', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';
    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) wp_send_json_error('Solicitud inválida', 400);

    $state = get_option( evapp_import_state_key($event_id, $hash) );
    if (!$state) wp_send_json_error('Estado no encontrado', 404);

    $CHUNK = 100;
    $offset = intval($state['offset']);
    $created = intval($state['created_count']);

    $map = $state['map']; // csv index => field_key
    $rev = [];
    foreach ($map as $i=>$k) if ($k) $rev[intval($i)] = $k;

    $fh = fopen($state['file'],'r');
    if (!$fh) wp_send_json_error('No se pudo abrir el archivo', 500);
    $hdr = fgetcsv($fh); // skip headers
    $line = 1;
    $processed = 0;
    $created_now = 0;

    // Saltar hasta offset actual
    while($line < $offset + 1 && ($row = fgetcsv($fh)) !== false){ $line++; }

    // Procesar hasta CHUNK
    while($processed < $CHUNK && ($row = fgetcsv($fh)) !== false){
        $line++;
        $processed++;
        $data = [];
        foreach ($rev as $i=>$field){
            $data[$field] = isset($row[$i]) ? trim($row[$i]) : '';
        }

        // Validaciones mínimas
        $nombre    = $data['nombre']   ?? '';
        $apellido  = $data['apellido'] ?? '';
        $email     = sanitize_email($data['email'] ?? '');
        $cc        = $data['cc']       ?? '';
        $localidad = $data['localidad']?? '';
        if (!$nombre || !$apellido || (!$email && !$cc) || !$localidad) {
            $offset++; // contamos la fila igual para avanzar
            continue;  // saltamos errores silenciosamente (puedes acumular log si gustas)
        }

        // Idempotencia por external_id o fingerprint
        $ext = $data['external_id'] ?? '';
        $finger = $ext ? 'ext:'.sanitize_text_field($ext) : 'fp:'.md5(strtolower($email.'|'.$cc.'|'.$nombre.'|'.$apellido.'|'.$event_id));

        // ¿ya existe un ticket con esta marca?
        $dup = get_posts([
            'post_type'=>'eventosapp_ticket',
            'meta_key' => '_eventosapp_import_fingerprint',
            'meta_value' => $finger,
            'fields'=>'ids',
            'posts_per_page'=>1,
            'post_status'=>'any',
            'no_found_rows'=>true
        ]);
        if ($dup) { $offset++; continue; }

        // Construir payload
        $payload = [
            'first_name' => $nombre,
            'last_name'  => $apellido,
            'email'      => $email,
            'tel'        => $data['telefono'] ?? '',
            'empresa'    => $data['empresa'] ?? '',
            'nit'        => $data['nit'] ?? '',
            'cargo'      => $data['cargo'] ?? '',
            'cc'         => $cc,
            'ciudad'     => $data['ciudad'] ?? '',
            'pais'       => $data['pais'] ?? 'Colombia',
            'localidad'  => $localidad,
            'extras'     => [],
            'fingerprint'=> $finger,
        ];
        foreach ($data as $k=>$v){
            if (strpos($k,'extra__') === 0) $payload['extras'][substr($k,7)] = $v;
        }

        $new_id = eventosapp_create_ticket_programmatically($event_id, $payload, 'import');
        if ($new_id) {
            $created_now++;
            $created++;
            // Marca import
            update_post_meta($new_id, '_eventosapp_import_fingerprint', $finger);
            $state['created_ids'][] = $new_id;
        }

        $offset++;
    }

    // <— MEJORA: usar el mismo handle para saber si terminó
    $done = feof($fh);
    fclose($fh);

$state['offset'] = $offset;
$state['created_count'] = $created;

$email_queue_created = false;
$admin_notified = false;

if ($done){
    // Programar envío masivo si se eligió
    if (!empty($state['queue_email']) && !empty($state['created_ids'])) {
        evapp_schedule_bulk_mail($state['created_ids'], $state['rate_per_min'] ?? 30);
        $email_queue_created = true;
    }

// ✉ NUEVO: notificar al admin una sola vez por importación
if (empty($state['admin_notified'])) {
    $site_name       = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $admin_email     = get_option('admin_email');
    $ev_title        = get_the_title($event_id);
    $filename        = $state['filename'] ?? '';
    $total_processed = (int) $state['offset'];
    $created_tot     = (int) $state['created_count'];

    if ($admin_email) {
        $subject = sprintf('[%s] Importación finalizada — %s [%d]', $site_name, $ev_title, $event_id);
        $body = "Se completó una importación de tickets.\n\n".
                "Sitio: {$site_name}\n".
                "Evento: {$ev_title} [{$event_id}]\n".
                "Archivo: {$filename}\n".
                "Filas procesadas: {$total_processed}\n".
                "Tickets creados (nuevos): {$created_tot}\n".
                "Autor de los tickets: importador1 <importador1@eventosapp.com>\n";
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        wp_mail($admin_email, $subject, $body, $headers);
    }

    $state['admin_notified'] = 1;
	$admin_notified = true;
}
}

update_option( evapp_import_state_key($event_id, $hash), $state, false );

wp_send_json_success([
    'offset' => $offset,
    'created_count' => $created,
    'msg' => 'Lote procesado: '.$processed.' filas, nuevas: '.$created_now.'.'.
             ($admin_notified ? ' Notifiqué al admin.' : ''),
    'done' => $done ? 1 : 0,
    'email_queue_created' => !empty($email_queue_created),
    'email_batch' => intval($state['rate_per_min'] ?? 30),
]);

});

//
// === Helper: crear ticket programáticamente (sin depender del nonce del metabox) ===
//
if (!function_exists('eventosapp_generate_unique_ticket_id')) {
    function eventosapp_generate_unique_ticket_id(){ return wp_generate_uuid4(); }
}
if (!function_exists('eventosapp_next_event_sequence')) {
    function eventosapp_next_event_sequence($event_id){
        $k = '_eventosapp_event_seq';
        $cur = (int) get_post_meta($event_id, $k, true);
        $cur++;
        update_post_meta($event_id, $k, $cur);
        return $cur;
    }
}

function eventosapp_create_ticket_programmatically($event_id, $p, $source = 'manual'){
    // Usuario importador (crea si no existe)
    $importer_id = evapp_get_or_create_importer_user();

    // Crear post con autor = importador1
    $post_id = wp_insert_post([
        'post_type'   => 'eventosapp_ticket',
        'post_status' => 'publish',
        'post_title'  => 'temp',
        'post_author' => $importer_id,
    ], true);
    if (is_wp_error($post_id)) return 0;

    // Metas base
    update_post_meta($post_id, '_eventosapp_ticket_evento_id', (int)$event_id);
    update_post_meta($post_id, '_eventosapp_ticket_user_id', $importer_id);

    // ⬇️ NUEVO: marca canal de creación
    update_post_meta($post_id, '_eventosapp_creation_channel', $source);

    update_post_meta($post_id, '_eventosapp_asistente_nombre',   sanitize_text_field($p['first_name'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_apellido', sanitize_text_field($p['last_name'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_email',    sanitize_email($p['email'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_tel',      sanitize_text_field($p['tel'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_empresa',  sanitize_text_field($p['empresa'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_nit',      sanitize_text_field($p['nit'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_cargo',    sanitize_text_field($p['cargo'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_cc',       sanitize_text_field($p['cc'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_ciudad',   sanitize_text_field($p['ciudad'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_pais',     sanitize_text_field($p['pais'] ?? 'Colombia'));
    update_post_meta($post_id, '_eventosapp_asistente_localidad',sanitize_text_field($p['localidad'] ?? ''));

    // Extras
    if (!empty($p['extras']) && is_array($p['extras']) && function_exists('eventosapp_get_event_extra_fields')) {
        $schema = eventosapp_get_event_extra_fields($event_id);
        $bykey = [];
        foreach ($schema as $f) { $bykey[$f['key']] = $f; }
        foreach ($p['extras'] as $k=>$v){
            if (isset($bykey[$k])) {
                $val = function_exists('eventosapp_normalize_extra_value') ? eventosapp_normalize_extra_value($bykey[$k], $v) : sanitize_text_field($v);
                update_post_meta($post_id, '_eventosapp_extra_'.$k, $val);
            }
        }
    }

    // ticketID y secuencia + título
    $ticketID = eventosapp_generate_unique_ticket_id();
    update_post_meta($post_id, 'eventosapp_ticketID', $ticketID);
    $seq = eventosapp_next_event_sequence($event_id);
    update_post_meta($post_id, '_eventosapp_ticket_seq', (int)$seq);
    wp_update_post(['ID'=>$post_id,'post_title'=>$ticketID]);

    // Estados por día
    $days = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days($event_id) : [];
    $status_arr = [];
    foreach ($days as $d) $status_arr[$d] = 'not_checked_in';
    update_post_meta($post_id, '_eventosapp_checkin_status', $status_arr);
    update_post_meta($post_id, '_eventosapp_checkin_log', []);

    // Wallet Android on/off por evento
    $wallet_android_on = get_post_meta($event_id, '_eventosapp_ticket_wallet_android', true);
    if ($wallet_android_on) {
        if (function_exists('eventosapp_generar_enlace_wallet_android')) eventosapp_generar_enlace_wallet_android($post_id, false);
    }

    // Generar PDF/ICS si aplica
    if (function_exists('eventosapp_ticket_generar_pdf')) eventosapp_ticket_generar_pdf($post_id);
    if (function_exists('eventosapp_ticket_generar_ics'))  eventosapp_ticket_generar_ics($post_id);

    // Accesos por localidad
    $localidad_ticket = get_post_meta($post_id, '_eventosapp_asistente_localidad', true);
    $sesiones = get_post_meta($event_id, '_eventosapp_sesiones_internas', true);
    if (!is_array($sesiones)) $sesiones = [];
    $accesos = [];
    foreach ($sesiones as $ses) {
        if (isset($ses['nombre'], $ses['localidades']) && is_array($ses['localidades'])) {
            if ($localidad_ticket && in_array($localidad_ticket, $ses['localidades'], true)) $accesos[] = $ses['nombre'];
        }
    }
    if ($accesos) update_post_meta($post_id, '_eventosapp_ticket_sesiones_acceso', $accesos);

    // Reindex
    if (function_exists('eventosapp_ticket_build_search_blob')) eventosapp_ticket_build_search_blob($post_id);

    return $post_id;
}


//
// === Cola de envío masivo (WP-Cron, rate-limit) ===
//
function evapp_bulk_mail_key(){ return 'evapp_bulk_mail_queue'; }

function evapp_schedule_bulk_mail($ticket_ids, $rate_per_min = 30){
    $q = get_option(evapp_bulk_mail_key(), []);
    if (!is_array($q)) $q = [];
    foreach ($ticket_ids as $id) { $q[] = (int)$id; }
    update_option(evapp_bulk_mail_key(), array_values(array_unique($q)), false);

    update_option('evapp_bulk_mail_rate', max(1, (int)$rate_per_min), false);

    // Programación periódica
    $scheduled = wp_next_scheduled('evapp_run_bulk_mail');
    if (!$scheduled) {
        $ok = wp_schedule_event(time()+5, 'minute', 'evapp_run_bulk_mail');
        // Fallback inmediato si el cron está deshabilitado o la programación falla
        if (!$ok || (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) {
            do_action('evapp_run_bulk_mail');
        }
    }
}

// Asegurar 'minute' schedule
add_filter('cron_schedules', function($s){
    if (!isset($s['minute'])) {
        $s['minute'] = ['interval'=>60,'display'=>'Cada minuto'];
    }
    return $s;
});

add_action('evapp_run_bulk_mail', function(){
    $q = get_option(evapp_bulk_mail_key(), []);
    if (!$q) return;

    $rate = (int) get_option('evapp_bulk_mail_rate', 30);
    $rate = max(1, min(120, $rate));
    $slice = array_splice($q, 0, $rate);

    foreach ($slice as $ticket_id){
        if (function_exists('eventosapp_send_ticket_email_now')) {
            eventosapp_send_ticket_email_now($ticket_id, ['source'=>'bulk','force'=>true]);
        }
        // Pequeña pausa opcional (no bloquear cron en algunos hosts)
        usleep(50000); // 50ms
    }

    update_option(evapp_bulk_mail_key(), $q, false);

    // Si ya está vacío, desprogramar
    if (!$q && ($ts = wp_next_scheduled('evapp_run_bulk_mail'))) {
        wp_unschedule_event($ts, 'evapp_run_bulk_mail');
    }
});
