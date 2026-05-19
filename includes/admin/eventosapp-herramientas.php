<?php
if (!defined('ABSPATH')) exit;

/**
 * Herramientas por evento: Importador CSV de tickets (asistente 4 pasos) + envío masivo opcional.
 * OPTIMIZADO para evitar timeouts en servidores con recursos limitados
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

// Enlace "Herramientas" en fila de eventos
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
        'localidad',
        'modalidad'
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
        'VIP',
        'presencial'
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
 *   'total_rows' => int,
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

function evapp_import_append_log($event_id, $hash, $message){
    $key = evapp_import_state_key($event_id, $hash);
    $state = get_option($key);
    if (!is_array($state)) return;

    if (empty($state['runtime_log']) || !is_array($state['runtime_log'])) {
        $state['runtime_log'] = [];
    }

    $state['runtime_log'][] = [
        'time'    => current_time('H:i:s'),
        'message' => wp_strip_all_tags((string) $message),
    ];

    if (count($state['runtime_log']) > 300) {
        $state['runtime_log'] = array_slice($state['runtime_log'], -300);
    }

    update_option($key, $state, false);
}

function evapp_import_generate_assets_now($ticket_id, $event_id){
    $ticket_id = (int) $ticket_id;
    $event_id  = (int) $event_id;

    if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
        return false;
    }

    if (function_exists('eventosapp_ticket_sync_modalidad')) {
        $ticket_modalidad = eventosapp_ticket_sync_modalidad($ticket_id);
    } else {
        $ticket_modalidad = get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true) ?: 'presencial';
    }

    $is_virtual_ticket = ($ticket_modalidad === 'virtual');

    // Compatibilidad con Variantes:
    // Antes de crear/regenerar QR, ICS, PDF o Wallets desde la importación masiva,
    // se recalcula la variante efectiva usando los metadatos ya guardados del ticket.
    // Esto evita que los anexos se generen con plantilla, clase Wallet o branding del evento base
    // cuando la fila importada cumple una regla de variante.
    if (function_exists('eventosapp_ticket_variants_prepare_ticket_for_batch_context')) {
        eventosapp_ticket_variants_prepare_ticket_for_batch_context($ticket_id, $event_id, 'import_generate_assets', [
            'sync_google_classes' => true,
            'clear_assets_stale'  => true,
            'log'                 => true,
        ]);
    } elseif (function_exists('eventosapp_ticket_variants_apply_to_ticket')) {
        eventosapp_ticket_variants_apply_to_ticket($ticket_id, $event_id, true);
    }

    if ($is_virtual_ticket) {
        if (function_exists('eventosapp_ticket_clear_presential_assets')) {
            eventosapp_ticket_clear_presential_assets($ticket_id);
        } else {
            delete_post_meta($ticket_id, '_eventosapp_ticket_pdf_url');
            delete_post_meta($ticket_id, '_eventosapp_wallet_android_url');
            delete_post_meta($ticket_id, '_eventosapp_apple_wallet_url');
            delete_post_meta($ticket_id, '_eventosapp_qr_codes');
        }
    }

    if (!$is_virtual_ticket && class_exists('EventosApp_QR_Manager')) {
        $qr = EventosApp_QR_Manager::get_instance();
        if ($qr && method_exists($qr, 'regenerate_all_qr_codes_forced')) {
            $qr->regenerate_all_qr_codes_forced($ticket_id, true);
        }
    }

    if (function_exists('eventosapp_ticket_generar_ics')) {
        eventosapp_ticket_generar_ics($ticket_id);
    }

    if ($is_virtual_ticket) {
        if (function_exists('eventosapp_whatsapp_prepare_ticket_assets')) {
            eventosapp_whatsapp_prepare_ticket_assets($ticket_id, [
                'event_id'               => $event_id,
                'context'                => 'import_generate_assets_virtual',
                'apply_variant'          => false,
                'refresh_enabled_assets' => false,
                'ensure_qr'              => false,
                'ensure_landing'         => true,
                'ensure_message_image'   => true,
                'rebuild_search_index'   => false,
                'log'                    => true,
            ]);
        }
        return true;
    }

    $wallet_android_on = get_post_meta($event_id, '_eventosapp_ticket_wallet_android', true);
    if ($wallet_android_on && function_exists('eventosapp_generar_enlace_wallet_android')) {
        eventosapp_generar_enlace_wallet_android($ticket_id, false);
    } else {
        delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_android');
    }

    $wallet_apple_on = get_post_meta($event_id, '_eventosapp_ticket_wallet_apple', true);
    if ($wallet_apple_on) {
        if (function_exists('eventosapp_apple_generate_pass')) {
            eventosapp_apple_generate_pass($ticket_id);
        } elseif (function_exists('eventosapp_generar_enlace_wallet_apple')) {
            eventosapp_generar_enlace_wallet_apple($ticket_id);
        }
    } else {
        delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple');
        delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url');
        delete_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url');
    }

    if (function_exists('eventosapp_ticket_generar_pdf')) {
        eventosapp_ticket_generar_pdf($ticket_id);
    }

    if (function_exists('eventosapp_whatsapp_prepare_ticket_assets')) {
        eventosapp_whatsapp_prepare_ticket_assets($ticket_id, [
            'event_id'               => $event_id,
            'context'                => 'import_generate_assets_presencial',
            'apply_variant'          => false,
            'refresh_enabled_assets' => false,
            'ensure_qr'              => true,
            'ensure_landing'         => true,
            'ensure_message_image'   => true,
            'rebuild_search_index'   => false,
            'log'                    => true,
        ]);
    }

    return true;
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
    'total_rows'   => 0,
];

// ⬅️ NUEVO: si ya existía estado para este mismo hash, preservamos progreso y configuración
if (is_array($existing)) {
    $state['map']           = $existing['map']           ?? [];
    $state['offset']        = intval($existing['offset'] ?? 0);
    $state['created_ids']   = is_array($existing['created_ids'] ?? null) ? $existing['created_ids'] : [];
    $state['created_count'] = intval($existing['created_count'] ?? 0);
    $state['total_rows']    = intval($existing['total_rows'] ?? 0);
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
            'modalidad'   => 'Modalidad del ticket (presencial/virtual)',
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
            foreach (['nombre','apellido','email','cc','localidad','modalidad'] as $k){
                if (isset($it['data'][$k])) $show[] = $k.': '.$it['data'][$k];
            }
            echo '<td>'.esc_html(implode(' | ', $show)).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Config de importación por lotes
        echo '<form method="post" action="'.esc_url(admin_url('admin-ajax.php')).'" style="margin-top:12px">';
        echo '<input type="hidden" name="action" value="eventosapp_import_confirm">';
        echo '<input type="hidden" name="event_id" value="'.esc_attr($event_id).'">';
        echo '<input type="hidden" name="hash" value="'.esc_attr($hash).'">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'">';
        echo '<p><b>Procesamiento:</b> el sistema creará cada ticket con sus archivos en el mismo lote (QR, PDF, ICS y Wallet solo cuando la modalidad del ticket lo permita), sin programar correos desde este importador.</p>';
        echo '<p><label>Tamaño del lote: <input type="number" name="batch_size" value="5" min="1" max="20" style="width:70px"></label> <span style="color:#666">Recomendado: 5</span></p>';
        echo '<p><button class="button button-primary">Empezar importación</button></p>';
        echo '</form>';

        echo '</div>';
    });
});

//
// === Step 4: confirmar → arrancar importación y mostrar progreso (10 en 10) ===
//
add_action('wp_ajax_eventosapp_import_confirm', function(){
    if (!current_user_can('manage_options')) wp_die('No autorizado', '', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';

    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) wp_die('Solicitud inválida', '', 400);

    $state = get_option(evapp_import_state_key($event_id, $hash));
    if (!$state) wp_die('Estado no encontrado', '', 404);

    if (!file_exists($state['file'])) {
        wp_die('El archivo CSV no existe', '', 500);
    }

    $fh = @fopen($state['file'], 'r');
    if (!$fh) {
        wp_die('No se pudo abrir el archivo CSV', '', 500);
    }

    $total_rows = 0;
    fgetcsv($fh);
    while (fgetcsv($fh) !== false) {
        $total_rows++;
    }
    fclose($fh);

    $batch_size = intval($_POST['batch_size'] ?? 5);
    $batch_size = max(1, min(20, $batch_size));

    $state['total_rows']     = $total_rows;
    $state['batch_size']     = $batch_size;
    $state['status']         = 'ready';
    $state['cancelled']      = 0;
    $state['stopped']        = 0;
    $state['done']           = 0;
    $state['runtime_log']    = [];
    $state['started_at']     = '';
    $state['finished_at']    = '';
    $state['last_error']     = '';
    $state['admin_notified'] = 0;

    update_option(evapp_import_state_key($event_id, $hash), $state, false);
    evapp_import_append_log($event_id, $hash, 'Importación preparada. Lote configurado en '.$batch_size.' registro(s).');

    $url4 = add_query_arg([
        'page'     => 'eventosapp_tools',
        'event_id' => $event_id,
        'step'     => 4,
        'hash'     => $hash,
    ], admin_url('admin.php'));
    wp_safe_redirect($url4);
    exit;
});

add_action('admin_init', function(){
    if (!is_admin() || !isset($_GET['page']) || $_GET['page']!=='eventosapp_tools') return;
    $step = intval($_GET['step'] ?? 0);
    if ($step !== 4) return;

    $event_id = intval($_GET['event_id'] ?? 0);
    $hash     = sanitize_text_field($_GET['hash'] ?? '');
    $state    = $hash ? get_option(evapp_import_state_key($event_id, $hash)) : null;
    if (!$state) return;

    add_action('admin_notices', function() use ($state, $event_id, $hash){
        $nonce    = wp_create_nonce('evapp_tools_'.$event_id);
        $ajax_url = admin_url('admin-ajax.php');

        echo '<div style="border:1px solid #ccc; background:#fff; padding:1rem; margin-top:1rem; border-radius:4px;">';
        echo '<h3>Paso 4: Importar</h3>';
        echo '<p>Procesaremos el CSV por lotes controlados. Cada lote crea el ticket completo con sus archivos inmediatamente.</p>';
        echo '<p><strong>Total de filas:</strong> '.intval($state['total_rows'] ?? 0).' | <strong>Lote:</strong> '.intval($state['batch_size'] ?? 5).' registro(s)</p>';
        echo '<p style="background:#e7f5ff;padding:8px;border-left:4px solid #2271b1;"><strong>Importante:</strong> este flujo ya no programa correos de tickets. Solo crea/actualiza tickets y genera sus anexos en el mismo lote.</p>';

        echo '<p style="display:flex; gap:8px; flex-wrap:wrap; margin:0 0 14px 0;">';
        echo '<button id="evapp_start_import" class="button button-primary button-large">Iniciar / Reanudar</button>';
        echo '<button id="evapp_stop_import" class="button button-secondary button-large">Detener</button>';
        echo '<button id="evapp_cancel_import" class="button button-link-delete">Cancelar proceso</button>';
        echo '</p>';

        echo '<div style="margin-top:1rem; padding:1rem; border:1px solid #ccc; background:#f9f9f9; border-radius:4px;">';
        echo '<div style="margin-bottom:0.5rem;"><strong>Progreso:</strong> <span id="evapp_status_badge" style="display:inline-block;padding:2px 8px;border-radius:10px;background:#eef2ff;color:#4338ca;">'.esc_html($state['status'] ?? 'ready').'</span></div>';
        echo '<div style="margin-bottom:1rem;">';
        echo '<div style="background:#e0e0e0; height:24px; border-radius:4px; overflow:hidden; position:relative;">';
        echo '<div id="evapp_progress_bar" style="background:#2271b1; height:100%; width:0%; transition:width 0.3s;"></div>';
        echo '<div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); font-size:12px; font-weight:600; color:#333;">';
        echo '<span id="evapp_progress_text">0%</span>';
        echo '</div></div></div>';
        echo '<p><strong>Offset:</strong> <span id="evapp_offset">0</span> | <strong>Creados:</strong> <span id="evapp_created">0</span> | <strong>Actualizados:</strong> <span id="evapp_updated">0</span> | <strong>Duplicados omitidos:</strong> <span id="evapp_skipped">0</span></p>';
        echo '<div id="evapp_log" style="max-height:320px; overflow-y:auto; font-family:monospace; font-size:13px; background:#fff; padding:8px; border:1px solid #ccc; border-radius:4px;"></div>';
        echo '</div>';
        ?>
        <script>
        (function(){
          if (typeof ajaxurl === 'undefined') {
            var ajaxurl = '<?php echo esc_js($ajax_url); ?>';
          }

          const totalRows = <?php echo intval($state['total_rows'] ?? 0); ?>;
          const nonce     = '<?php echo esc_js($nonce); ?>';
          const eventId   = '<?php echo intval($event_id); ?>';
          const hash      = '<?php echo esc_js($hash); ?>';

          const btnStart  = document.getElementById('evapp_start_import');
          const btnStop   = document.getElementById('evapp_stop_import');
          const btnCancel = document.getElementById('evapp_cancel_import');
          const logBox    = document.getElementById('evapp_log');
          const txt       = document.getElementById('evapp_progress_text');
          const bar       = document.getElementById('evapp_progress_bar');
          const offsetEl  = document.getElementById('evapp_offset');
          const createdEl = document.getElementById('evapp_created');
          const updatedEl = document.getElementById('evapp_updated');
          const skippedEl = document.getElementById('evapp_skipped');
          const badgeEl   = document.getElementById('evapp_status_badge');

          let busy = false;
          let autoRun = false;

          function addLog(line){
            if (!line) return;
            logBox.innerHTML += line + '<br>';
            logBox.scrollTop = logBox.scrollHeight;
          }

          function render(data){
            const offset  = parseInt(data.offset || 0, 10);
            const created = parseInt(data.created_count || 0, 10);
            const updated = parseInt(data.updated_existing || 0, 10);
            const skipped = parseInt(data.skipped_dup || 0, 10);
            const percent = totalRows > 0 ? Math.min(100, Math.round((offset / totalRows) * 100)) : 0;

            offsetEl.textContent  = offset;
            createdEl.textContent = created;
            updatedEl.textContent = updated;
            skippedEl.textContent = skipped;
            txt.textContent       = percent + '%';
            bar.style.width       = percent + '%';
            badgeEl.textContent   = data.status || 'ready';

            if (Array.isArray(data.runtime_log)) {
              logBox.innerHTML = '';
              data.runtime_log.forEach(function(row){
                addLog('[' + row.time + '] ' + row.message);
              });
            }

            if (data.status === 'done') {
              bar.style.background = '#00a32a';
            } else if (data.status === 'cancelled') {
              bar.style.background = '#d63638';
            } else if (data.status === 'stopped') {
              bar.style.background = '#dba617';
            } else {
              bar.style.background = '#2271b1';
            }
          }

          async function request(action){
            const fd = new FormData();
            fd.append('action', action);
            fd.append('event_id', eventId);
            fd.append('hash', hash);
            fd.append('_wpnonce', nonce);

            const response = await fetch(ajaxurl, {
              method: 'POST',
              body: fd,
              credentials: 'same-origin'
            });

            if (!response.ok) {
              throw new Error('HTTP ' + response.status + ': ' + response.statusText);
            }

            const json = await response.json();
            if (!json.success) {
              throw new Error(json.data || 'Error desconocido');
            }
            return json.data;
          }

          async function refreshStatus(){
            try {
              const data = await request('eventosapp_import_status');
              render(data);
            } catch (e) {
              addLog('[' + new Date().toLocaleTimeString() + '] ERROR consultando estado: ' + e.message);
            }
          }

          async function tick(){
            if (busy) return;
            busy = true;
            btnStart.disabled = true;

            try {
              const data = await request('eventosapp_import_process');
              render(data);

              if (data.done || data.status === 'done' || data.status === 'cancelled' || data.status === 'stopped') {
                autoRun = false;
              }
            } catch (e) {
              autoRun = false;
              addLog('[' + new Date().toLocaleTimeString() + '] ERROR: ' + e.message);
              badgeEl.textContent = 'error';
              bar.style.background = '#d63638';
            } finally {
              busy = false;
              btnStart.disabled = false;
              if (autoRun) {
                window.setTimeout(tick, 250);
              }
            }
          }

          btnStart.addEventListener('click', async function(){
            try {
              autoRun = true;
              await request('eventosapp_import_resume');
              await refreshStatus();
              tick();
            } catch (e) {
              addLog('[' + new Date().toLocaleTimeString() + '] ERROR al iniciar/reanudar: ' + e.message);
            }
          });

          btnStop.addEventListener('click', async function(){
            try {
              autoRun = false;
              const data = await request('eventosapp_import_stop');
              render(data);
            } catch (e) {
              addLog('[' + new Date().toLocaleTimeString() + '] ERROR al detener: ' + e.message);
            }
          });

          btnCancel.addEventListener('click', async function(){
            if (!window.confirm('Esto cancelará la ejecución actual. Los tickets ya creados se conservan y el proceso podrá reanudarse sin duplicar.')) {
              return;
            }
            try {
              autoRun = false;
              const data = await request('eventosapp_import_cancel');
              render(data);
            } catch (e) {
              addLog('[' + new Date().toLocaleTimeString() + '] ERROR al cancelar: ' + e.message);
            }
          });

          window.setInterval(refreshStatus, 3000);
          refreshStatus();
        })();
        </script>
        <?php
        echo '</div>';
    });
});

add_action('wp_ajax_eventosapp_import_status', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('No autorizado', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';

    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) {
        wp_send_json_error('Solicitud inválida', 400);
    }

    $state = get_option(evapp_import_state_key($event_id, $hash));
    if (!is_array($state)) {
        wp_send_json_error('Estado no encontrado', 404);
    }

    wp_send_json_success([
        'status'           => $state['status'] ?? 'ready',
        'offset'           => intval($state['offset'] ?? 0),
        'created_count'    => intval($state['created_count'] ?? 0),
        'updated_existing' => intval($state['updated_existing'] ?? 0),
        'skipped_dup'      => intval($state['skipped_dup'] ?? 0),
        'runtime_log'      => is_array($state['runtime_log'] ?? null) ? $state['runtime_log'] : [],
        'done'             => !empty($state['done']) ? 1 : 0,
    ]);
});

add_action('wp_ajax_eventosapp_import_resume', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('No autorizado', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';

    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) {
        wp_send_json_error('Solicitud inválida', 400);
    }

    $key   = evapp_import_state_key($event_id, $hash);
    $state = get_option($key);
    if (!is_array($state)) {
        wp_send_json_error('Estado no encontrado', 404);
    }

    $state['status']    = 'running';
    $state['stopped']   = 0;
    $state['cancelled'] = 0;
    if (empty($state['started_at'])) {
        $state['started_at'] = current_time('mysql');
    }
    update_option($key, $state, false);
    evapp_import_append_log($event_id, $hash, 'Proceso reanudado.');

    wp_send_json_success([
        'status'           => $state['status'],
        'offset'           => intval($state['offset'] ?? 0),
        'created_count'    => intval($state['created_count'] ?? 0),
        'updated_existing' => intval($state['updated_existing'] ?? 0),
        'skipped_dup'      => intval($state['skipped_dup'] ?? 0),
        'runtime_log'      => is_array($state['runtime_log'] ?? null) ? $state['runtime_log'] : [],
        'done'             => !empty($state['done']) ? 1 : 0,
    ]);
});

add_action('wp_ajax_eventosapp_import_stop', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('No autorizado', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';

    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) {
        wp_send_json_error('Solicitud inválida', 400);
    }

    $key   = evapp_import_state_key($event_id, $hash);
    $state = get_option($key);
    if (!is_array($state)) {
        wp_send_json_error('Estado no encontrado', 404);
    }

    $state['status']  = 'stopped';
    $state['stopped'] = 1;
    update_option($key, $state, false);
    evapp_import_append_log($event_id, $hash, 'Proceso detenido por el usuario.');

    wp_send_json_success([
        'status'           => $state['status'],
        'offset'           => intval($state['offset'] ?? 0),
        'created_count'    => intval($state['created_count'] ?? 0),
        'updated_existing' => intval($state['updated_existing'] ?? 0),
        'skipped_dup'      => intval($state['skipped_dup'] ?? 0),
        'runtime_log'      => is_array($state['runtime_log'] ?? null) ? $state['runtime_log'] : [],
        'done'             => !empty($state['done']) ? 1 : 0,
    ]);
});

add_action('wp_ajax_eventosapp_import_cancel', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('No autorizado', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';

    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) {
        wp_send_json_error('Solicitud inválida', 400);
    }

    $key   = evapp_import_state_key($event_id, $hash);
    $state = get_option($key);
    if (!is_array($state)) {
        wp_send_json_error('Estado no encontrado', 404);
    }

    $state['status']    = 'cancelled';
    $state['cancelled'] = 1;
    $state['stopped']   = 1;
    update_option($key, $state, false);
    evapp_import_append_log($event_id, $hash, 'Proceso cancelado por el usuario. El progreso queda registrado para evitar duplicados.');

    wp_send_json_success([
        'status'           => $state['status'],
        'offset'           => intval($state['offset'] ?? 0),
        'created_count'    => intval($state['created_count'] ?? 0),
        'updated_existing' => intval($state['updated_existing'] ?? 0),
        'skipped_dup'      => intval($state['skipped_dup'] ?? 0),
        'runtime_log'      => is_array($state['runtime_log'] ?? null) ? $state['runtime_log'] : [],
        'done'             => !empty($state['done']) ? 1 : 0,
    ]);
});
//
// === Procesar un lote (OPTIMIZADO: 10 tickets con throttling) ===
//
add_action('wp_ajax_eventosapp_import_process', function(){
    $start_time = microtime(true);

    if (!current_user_can('manage_options')) {
        wp_send_json_error('No autorizado', 403);
    }

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';

    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) {
        wp_send_json_error('Solicitud inválida', 400);
    }

    $key   = evapp_import_state_key($event_id, $hash);
    $state = get_option($key);
    if (!is_array($state)) {
        wp_send_json_error('Estado no encontrado', 404);
    }

    if (!empty($state['cancelled'])) {
        $state['status'] = 'cancelled';
        update_option($key, $state, false);
        wp_send_json_success([
            'status'           => $state['status'],
            'offset'           => intval($state['offset'] ?? 0),
            'created_count'    => intval($state['created_count'] ?? 0),
            'updated_existing' => intval($state['updated_existing'] ?? 0),
            'skipped_dup'      => intval($state['skipped_dup'] ?? 0),
            'runtime_log'      => is_array($state['runtime_log'] ?? null) ? $state['runtime_log'] : [],
            'msg'              => 'Proceso cancelado.',
            'done'             => 1,
        ]);
    }

    if (!empty($state['stopped']) && ($state['status'] ?? '') !== 'running') {
        $state['status'] = 'stopped';
        update_option($key, $state, false);
        wp_send_json_success([
            'status'           => $state['status'],
            'offset'           => intval($state['offset'] ?? 0),
            'created_count'    => intval($state['created_count'] ?? 0),
            'updated_existing' => intval($state['updated_existing'] ?? 0),
            'skipped_dup'      => intval($state['skipped_dup'] ?? 0),
            'runtime_log'      => is_array($state['runtime_log'] ?? null) ? $state['runtime_log'] : [],
            'msg'              => 'Proceso detenido.',
            'done'             => 0,
        ]);
    }

    $state['status'] = 'running';
    update_option($key, $state, false);

    $chunk    = max(1, min(20, intval($state['batch_size'] ?? 5)));
    $offset   = intval($state['offset'] ?? 0);
    $created  = intval($state['created_count'] ?? 0);
    $updated  = intval($state['updated_existing'] ?? 0);
    $skipped  = intval($state['skipped_dup'] ?? 0);

    $map = $state['map'] ?? [];
    $rev = [];
    foreach ($map as $i => $k) {
        if ($k) $rev[intval($i)] = $k;
    }

    if (!file_exists($state['file'])) {
        $state['status']     = 'error';
        $state['last_error'] = 'El archivo CSV no existe.';
        update_option($key, $state, false);
        wp_send_json_error('El archivo CSV no existe', 500);
    }

    $fh = @fopen($state['file'], 'r');
    if (!$fh) {
        $state['status']     = 'error';
        $state['last_error'] = 'No se pudo abrir el archivo CSV.';
        update_option($key, $state, false);
        wp_send_json_error('No se pudo abrir el archivo', 500);
    }

    fgetcsv($fh);
    $line = 1;
    while ($line < $offset + 1 && ($row = fgetcsv($fh)) !== false) {
        $line++;
    }

    $processed_now = 0;
    $created_now   = 0;
    $updated_now   = 0;
    $skipped_now   = 0;

    while ($processed_now < $chunk && ($row = fgetcsv($fh)) !== false) {
        $line++;
        $processed_now++;

        $data = [];
        foreach ($rev as $i => $field) {
            $data[$field] = isset($row[$i]) ? trim((string) $row[$i]) : '';
        }

        $nombre    = $data['nombre'] ?? '';
        $apellido  = $data['apellido'] ?? '';
        $email     = sanitize_email($data['email'] ?? '');
        $cc        = sanitize_text_field($data['cc'] ?? '');
        $localidad = $data['localidad'] ?? '';
        $modalidad = $data['modalidad'] ?? '';

        if (!$nombre || !$apellido || (!$email && !$cc) || !$localidad) {
            $offset++;
            $skipped_now++;
            evapp_import_append_log($event_id, $hash, 'L'.$line.': fila omitida por datos mínimos incompletos.');
            continue;
        }

        $ext    = $data['external_id'] ?? '';
        $finger = $ext
            ? 'ext:'.sanitize_text_field($ext)
            : 'fp:'.md5(strtolower($email.'|'.$cc.'|'.$nombre.'|'.$apellido.'|'.$event_id));

        $dup_by_finger = get_posts([
            'post_type'      => 'eventosapp_ticket',
            'meta_key'       => '_eventosapp_import_fingerprint',
            'meta_value'     => $finger,
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'no_found_rows'  => true,
        ]);

        if ($dup_by_finger) {
            $offset++;
            $skipped_now++;
            $skipped++;
            evapp_import_append_log($event_id, $hash, 'L'.$line.': fingerprint duplicado, fila omitida.');
            continue;
        }

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
            'modalidad'  => $modalidad,
            'extras'     => [],
            'fingerprint'=> $finger,
        ];
        foreach ($data as $k => $v) {
            if (strpos($k, 'extra__') === 0) {
                $payload['extras'][substr($k, 7)] = $v;
            }
        }

        $existing_ticket_id = false;
        if ($cc && function_exists('evapp_find_ticket_by_cedula_evento')) {
            $existing_ticket_id = evapp_find_ticket_by_cedula_evento($cc, $event_id);
        }

        if ($existing_ticket_id) {
            $pid = (int) $existing_ticket_id;

            update_post_meta($pid, '_eventosapp_asistente_nombre', sanitize_text_field($payload['first_name']));
            update_post_meta($pid, '_eventosapp_asistente_apellido', sanitize_text_field($payload['last_name']));
            update_post_meta($pid, '_eventosapp_asistente_email', sanitize_email($payload['email']));
            update_post_meta($pid, '_eventosapp_asistente_tel', sanitize_text_field($payload['tel']));
            update_post_meta($pid, '_eventosapp_asistente_empresa', sanitize_text_field($payload['empresa']));
            update_post_meta($pid, '_eventosapp_asistente_nit', sanitize_text_field($payload['nit']));
            update_post_meta($pid, '_eventosapp_asistente_cargo', sanitize_text_field($payload['cargo']));
            update_post_meta($pid, '_eventosapp_asistente_ciudad', sanitize_text_field($payload['ciudad']));
            update_post_meta($pid, '_eventosapp_asistente_pais', sanitize_text_field($payload['pais']));
            update_post_meta($pid, '_eventosapp_asistente_localidad', sanitize_text_field($payload['localidad']));
            update_post_meta($pid, '_eventosapp_ticket_evento_id', (int) $event_id);

            if (function_exists('eventosapp_ticket_sync_modalidad')) {
                eventosapp_ticket_sync_modalidad($pid, $payload['modalidad'] ?? '');
            } else {
                update_post_meta($pid, '_eventosapp_ticket_modalidad', sanitize_key($payload['modalidad'] ?? 'presencial'));
            }

            if (!empty($payload['extras']) && function_exists('eventosapp_get_event_extra_fields')) {
                $schema = eventosapp_get_event_extra_fields($event_id);
                $bykey  = [];
                foreach ($schema as $f) {
                    $bykey[$f['key']] = $f;
                }
                foreach ($payload['extras'] as $ek => $ev) {
                    if (isset($bykey[$ek])) {
                        $val = function_exists('eventosapp_normalize_extra_value')
                            ? eventosapp_normalize_extra_value($bykey[$ek], $ev)
                            : sanitize_text_field($ev);
                        update_post_meta($pid, '_eventosapp_extra_'.$ek, $val);
                    }
                }
            }

            update_post_meta($pid, '_eventosapp_import_fingerprint', $finger);

            if (function_exists('eventosapp_ticket_build_search_blob')) {
                eventosapp_ticket_build_search_blob($pid);
            }
            if (function_exists('evapp_process_vincular_asistente')) {
                evapp_process_vincular_asistente($pid);
            }

            evapp_import_generate_assets_now($pid, $event_id);

            $updated_now++;
            $updated++;
            evapp_import_append_log($event_id, $hash, 'L'.$line.': ticket '.$pid.' actualizado y anexos regenerados.');
        } else {
            $new_id = eventosapp_create_ticket_programmatically($event_id, $payload, 'import', false);

            if ($new_id) {
                $created_now++;
                $created++;
                update_post_meta($new_id, '_eventosapp_import_fingerprint', $finger);
                $state['created_ids'][] = $new_id;

                if (function_exists('evapp_process_vincular_asistente')) {
                    evapp_process_vincular_asistente($new_id);
                }

                evapp_import_append_log($event_id, $hash, 'L'.$line.': ticket '.$new_id.' creado con anexos inmediatos.');
            }
        }

        $offset++;
        usleep(150000);
    }

    $done = feof($fh);
    fclose($fh);

    $elapsed = round((microtime(true) - $start_time) * 1000);

    $state = get_option($key);
    if (!is_array($state)) {
        $state = [];
    }
    $state['offset']           = $offset;
    $state['created_count']    = $created;
    $state['updated_existing'] = $updated;
    $state['skipped_dup']      = $skipped;
    $state['done']             = $done ? 1 : 0;
    $state['status']           = $done ? 'done' : ((!empty($state['stopped']) || !empty($state['cancelled'])) ? ($state['cancelled'] ? 'cancelled' : 'stopped') : 'running');
    if ($done) {
        $state['finished_at'] = current_time('mysql');
    }

    if ($done && empty($state['admin_notified'])) {
        $site_name       = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $admin_email     = get_option('admin_email');
        $ev_title        = get_the_title($event_id);
        $filename        = $state['filename'] ?? '';
        $total_processed = (int) $state['offset'];

        if ($admin_email) {
            $subject = sprintf('[%s] Importación finalizada — %s [%d]', $site_name, $ev_title, $event_id);
            $body = "Se completó una importación de tickets.\n\n".
                    "Sitio: {$site_name}\n".
                    "Evento: {$ev_title} [{$event_id}]\n".
                    "Archivo: {$filename}\n".
                    "Filas procesadas: {$total_processed}\n".
                    "Tickets creados: ".(int) $state['created_count']."\n".
                    "Tickets actualizados: ".(int) $state['updated_existing']."\n".
                    "Duplicados omitidos: ".(int) $state['skipped_dup']."\n".
                    "Los anexos se generaron dentro del mismo lote, no en segundo plano.\n";
            wp_mail($admin_email, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
        }
        $state['admin_notified'] = 1;
        evapp_import_append_log($event_id, $hash, 'Importación finalizada. Correo de resumen enviado al administrador.');
    }

    update_option($key, $state, false);

    wp_send_json_success([
        'status'           => $state['status'],
        'offset'           => $offset,
        'created_count'    => $created,
        'updated_existing' => $updated,
        'skipped_dup'      => $skipped,
        'runtime_log'      => is_array($state['runtime_log'] ?? null) ? $state['runtime_log'] : [],
        'msg'              => 'Lote procesado en '.$elapsed.'ms: '.$processed_now.' fila(s) | +'.$created_now.' nuevas | ↺'.$updated_now.' actualizadas | ✗'.$skipped_now.' omitidas.',
        'done'             => $done ? 1 : 0,
    ]);
});
//
// === Helper: crear ticket programáticamente (IMPORTACIÓN POR LOTES) ===
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

function eventosapp_create_ticket_programmatically($event_id, $p, $source = 'manual', $skip_heavy_operations = false){
    $importer_id = evapp_get_or_create_importer_user();

    $post_id = wp_insert_post([
        'post_type'   => 'eventosapp_ticket',
        'post_status' => 'publish',
        'post_title'  => 'temp',
        'post_author' => $importer_id,
    ], true);
    if (is_wp_error($post_id)) return 0;

    update_post_meta($post_id, '_eventosapp_ticket_evento_id', (int) $event_id);
    update_post_meta($post_id, '_eventosapp_ticket_user_id', $importer_id);
    update_post_meta($post_id, '_eventosapp_creation_channel', $source);

    update_post_meta($post_id, '_eventosapp_asistente_nombre', sanitize_text_field($p['first_name'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_apellido', sanitize_text_field($p['last_name'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_email', sanitize_email($p['email'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_tel', sanitize_text_field($p['tel'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_empresa', sanitize_text_field($p['empresa'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_nit', sanitize_text_field($p['nit'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_cargo', sanitize_text_field($p['cargo'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_cc', sanitize_text_field($p['cc'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_ciudad', sanitize_text_field($p['ciudad'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_pais', sanitize_text_field($p['pais'] ?? 'Colombia'));
    update_post_meta($post_id, '_eventosapp_asistente_localidad', sanitize_text_field($p['localidad'] ?? ''));

    if (function_exists('eventosapp_ticket_sync_modalidad')) {
        eventosapp_ticket_sync_modalidad($post_id, $p['modalidad'] ?? '');
    } else {
        update_post_meta($post_id, '_eventosapp_ticket_modalidad', sanitize_key($p['modalidad'] ?? 'presencial'));
    }

    if (function_exists('eventosapp_ticket_init_email_status')) {
        eventosapp_ticket_init_email_status($post_id);
    }

    if (!empty($p['extras']) && is_array($p['extras']) && function_exists('eventosapp_get_event_extra_fields')) {
        $schema = eventosapp_get_event_extra_fields($event_id);
        $bykey = [];
        foreach ($schema as $f) {
            $bykey[$f['key']] = $f;
        }
        foreach ($p['extras'] as $k => $v) {
            if (isset($bykey[$k])) {
                $val = function_exists('eventosapp_normalize_extra_value')
                    ? eventosapp_normalize_extra_value($bykey[$k], $v)
                    : sanitize_text_field($v);
                update_post_meta($post_id, '_eventosapp_extra_'.$k, $val);
            }
        }
    }

    $ticketID = eventosapp_generate_unique_ticket_id();
    update_post_meta($post_id, 'eventosapp_ticketID', $ticketID);
    $seq = eventosapp_next_event_sequence($event_id);
    update_post_meta($post_id, '_eventosapp_ticket_seq', (int) $seq);
    wp_update_post(['ID' => $post_id, 'post_title' => $ticketID]);

    $days = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days($event_id) : [];
    $status_arr = [];
    foreach ($days as $d) {
        $status_arr[$d] = 'not_checked_in';
    }
    update_post_meta($post_id, '_eventosapp_checkin_status', $status_arr);
    update_post_meta($post_id, '_eventosapp_checkin_log', []);

    $localidad_ticket = get_post_meta($post_id, '_eventosapp_asistente_localidad', true);
    $sesiones = get_post_meta($event_id, '_eventosapp_sesiones_internas', true);
    if (!is_array($sesiones)) $sesiones = [];
    $accesos = [];
    foreach ($sesiones as $ses) {
        if (isset($ses['nombre'], $ses['localidades']) && is_array($ses['localidades'])) {
            if ($localidad_ticket && in_array($localidad_ticket, $ses['localidades'], true)) {
                $accesos[] = $ses['nombre'];
            }
        }
    }
    if ($accesos) {
        update_post_meta($post_id, '_eventosapp_ticket_sesiones_acceso', $accesos);
    }

    if (!$skip_heavy_operations) {
        evapp_import_generate_assets_now($post_id, $event_id);
    } else {
        // Si se crea el ticket sin anexos pesados, al menos queda guardada su variante efectiva
        // para que una generación posterior use la configuración correcta.
        if (function_exists('eventosapp_ticket_variants_prepare_ticket_for_batch_context')) {
            eventosapp_ticket_variants_prepare_ticket_for_batch_context($post_id, $event_id, 'import_create_ticket_skip_assets', [
                'sync_google_classes' => true,
                'log'                 => true,
            ]);
        } elseif (function_exists('eventosapp_ticket_variants_apply_to_ticket')) {
            eventosapp_ticket_variants_apply_to_ticket($post_id, $event_id, true);
        }
    }

    if (function_exists('eventosapp_ticket_build_search_blob')) {
        eventosapp_ticket_build_search_blob($post_id);
    }

    return $post_id;
}
