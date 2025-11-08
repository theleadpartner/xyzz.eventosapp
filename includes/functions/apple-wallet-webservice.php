<?php
if (!defined('ABSPATH')) exit;

/**
 * PassKit Web Service v1 (versión sin logs de producción)
 *
 * Endpoints:
 *  - POST   /devices/{deviceLibraryIdentifier}/registrations/{passTypeId}/{serial}
 *  - GET    /devices/{deviceLibraryIdentifier}/registrations/{passTypeId}?passesUpdatedSince=TAG
 *  - DELETE /devices/{deviceLibraryIdentifier}/registrations/{passTypeId}/{serial}
 *  - GET    /passes/{passTypeId}/{serial}   (descarga del pkpass)
 */

/* ============================================================
 * ====================== HELPERS BÁSICOS =====================
 * ============================================================ */

if (!function_exists('evapp_pkws__headers')) {
    function evapp_pkws__headers(){
        // getallheaders puede no existir en fpm/CGI → reconstrucción
        $h = [];
        foreach ($_SERVER as $k=>$v){
            if (strpos($k,'HTTP_')===0){
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_',' ', substr($k,5)))));
                $h[$name] = $v;
            }
        }
        // algunos servidores colocan Authorization fuera del prefijo
        if (empty($h['Authorization']) && !empty($_SERVER['Authorization'])) $h['Authorization'] = $_SERVER['Authorization'];
        return $h;
    }
}

if (!function_exists('evapp_pkws__ip')) {
    function evapp_pkws__ip(){
        foreach (['HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR'] as $k){
            if (!empty($_SERVER[$k])) return $_SERVER[$k];
        }
        return '';
    }
}

/**
 * No-op: en producción no registramos logs ni en error_log ni en meta.
 * Se deja la firma para compatibilidad con llamadas existentes.
 */
if (!function_exists('evapp_pkws__log_ticket')) {
    function evapp_pkws__log_ticket($ticket_id, $title, $data){
        return;
    }
}

/**
 * Guard: asegurar disponibilidad de eventosapp_url_to_path()
 * (Este archivo puede cargarse antes que otros helpers.)
 */
if (!function_exists('eventosapp_url_to_path')) {
    function eventosapp_url_to_path($url){
        $upload = wp_upload_dir();
        if (strpos($url, $upload['baseurl']) === 0) {
            return $upload['basedir'] . substr($url, strlen($upload['baseurl']));
        }
        return false;
    }
}

/* ============================================================
 * =================== BOOT + REWRITE RULES ===================
 * ============================================================ */

/**
 * Rewrites: /eventosapp-passkit/v1/...
 * (El flush lo realiza el plugin principal en activación.)
 */
add_action('init', function(){
    add_rewrite_rule('^eventosapp-passkit/v1/(.*)$', 'index.php?evapp_pkws=1&pkws_path=$matches[1]', 'top');
    add_filter('query_vars', function($qv){ $qv[]='evapp_pkws'; $qv[]='pkws_path'; return $qv; });
});

add_action('template_redirect', function(){
    if (get_query_var('evapp_pkws') != '1') return;
    evapp_pkws_router();
    exit;
});

function evapp_pkws_cfg(){ return evapp_apple_cfg(); }

function evapp_pkws_json($code, $arr=[], $ticket_id=0, $title='WS JSON'){
    // Respuesta JSON
    $payload = json_encode($arr);
    status_header($code);
    header('Content-Type: application/json');
    echo $payload;
}

/* ============================================================
 * ====================== ROUTER PRINCIPAL ====================
 * ============================================================ */

function evapp_pkws_router(){
    $path   = trim(get_query_var('pkws_path') ?: '', '/');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $hdrs   = evapp_pkws__headers();

    // Auth: Header "Authorization: ApplePass <token>"
    $auth_raw = $hdrs['Authorization'] ?? ($hdrs['authorization'] ?? '');
    $auth = $auth_raw;
    if ($auth && stripos($auth,'ApplePass ')===0) $auth = trim(substr($auth,10));

    $parts = explode('/', $path);

    // Intenta detectar serial (para adjuntar logs al ticket, deshabilitado)
    $possible_serial = null;
    if ($method==='GET' && count($parts)===3 && $parts[0]==='passes') { $possible_serial = $parts[2]; }
    if (count($parts)>=5 && $parts[0]==='devices' && $parts[2]==='registrations'){ $possible_serial = $parts[4] ?? null; }

    $ticket_id = 0;
    if ($possible_serial) {
        $ticket_id = evapp_ticket_id_by_serial($possible_serial);
    }

    // /passes/<passTypeId>/<serial>
    if ($method==='GET' && count($parts)===3 && $parts[0]==='passes'){
        list(,$passTypeId,$serial) = $parts;
        evapp_pkws_get_pass($passTypeId, $serial, $auth, $auth_raw);
        return;
    }

    // /devices/<deviceLibId>/registrations/<passTypeId>/<serial>
    if (count($parts)>=4 && $parts[0]==='devices' && $parts[2]==='registrations'){
        $device = $parts[1];
        $passTypeId = $parts[3];
        $serial = $parts[4] ?? '';
        if ($method==='POST' && $serial) { evapp_pkws_register($device,$passTypeId,$serial,$auth,$auth_raw); return; }
        if ($method==='DELETE' && $serial){ evapp_pkws_unregister($device,$passTypeId,$serial,$auth,$auth_raw); return; }
        if ($method==='GET' && !$serial)  { evapp_pkws_updates($device,$passTypeId); return; }
    }

    evapp_pkws_json(404, ['error'=>'not found']);
}

/* ============================================================
 * ===================== ENDPOINTS WS v1 ======================
 * ============================================================ */

function evapp_pkws_register($device,$passTypeId,$serial,$auth,$auth_raw=''){
    $cfg = evapp_pkws_cfg();
    $ticket_id = evapp_ticket_id_by_serial($serial);

    if ($passTypeId !== ($cfg['pass_type_id'] ?? '')) {
        return evapp_pkws_json(401, [], $ticket_id, 'register mismatch');
    }
    if (!$ticket_id){
        return evapp_pkws_json(404, ['error'=>'ticket not found'], 0, 'register 404');
    }
    $token = get_post_meta($ticket_id, '_evapp_pk_auth', true);
    if (!$token || $auth !== $token){
        return evapp_pkws_json(401, [], $ticket_id, 'register unauthorized');
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    $pushToken = $body['pushToken'] ?? '';

    if (!$pushToken){
        return evapp_pkws_json(400, ['error'=>'missing pushToken'], $ticket_id, 'register missing pushToken');
    }

    $devs = get_post_meta($ticket_id, '_evapp_pk_devices', true);
    if (!is_array($devs)) $devs=[];
    $is_new = empty($devs[$device]);
    $devs[$device] = ['pushToken'=>$pushToken,'ts'=>time()];
    update_post_meta($ticket_id, '_evapp_pk_devices', $devs);

    return evapp_pkws_json($is_new ? 201 : 200, [], $ticket_id, 'register saved');
}

function evapp_pkws_unregister($device,$passTypeId,$serial,$auth,$auth_raw=''){
    $cfg = evapp_pkws_cfg();
    $ticket_id = evapp_ticket_id_by_serial($serial);

    if ($passTypeId !== ($cfg['pass_type_id'] ?? '')) {
        return evapp_pkws_json(401, [], $ticket_id, 'unregister mismatch');
    }
    if (!$ticket_id){
        return evapp_pkws_json(404, [], 0, 'unregister 404');
    }
    $token = get_post_meta($ticket_id, '_evapp_pk_auth', true);
    if (!$token || $auth !== $token){
        return evapp_pkws_json(401, [], $ticket_id, 'unregister unauthorized');
    }

    $devs = get_post_meta($ticket_id, '_evapp_pk_devices', true);
    if (is_array($devs) && isset($devs[$device])) {
        unset($devs[$device]);
        update_post_meta($ticket_id, '_evapp_pk_devices', $devs);
    }
    return evapp_pkws_json(200, [], $ticket_id, 'unregister ok');
}

function evapp_pkws_updates($device,$passTypeId){
    $cfg = evapp_pkws_cfg();
    // iOS suele confiar en push; devolvemos un tag vacío/actual siempre
    if ($passTypeId !== ($cfg['pass_type_id'] ?? '')) {
        return evapp_pkws_json(401, [], 0, 'updates mismatch');
    }
    $payload = ['lastUpdatedTag'=> (string) time(), 'serialNumbers'=>[]];
    return evapp_pkws_json(200, $payload, 0, 'updates ok');
}

function evapp_pkws_get_pass($passTypeId,$serial,$auth,$auth_raw=''){
    $cfg = evapp_pkws_cfg();
    $ticket_id = evapp_ticket_id_by_serial($serial);

    if ($passTypeId !== ($cfg['pass_type_id'] ?? '')) {
        return evapp_pkws_json(401, [], $ticket_id, 'get_pass mismatch');
    }

    if (!$ticket_id) {
        return evapp_pkws_json(404, [], 0, 'get_pass 404');
    }

    $token = get_post_meta($ticket_id, '_evapp_pk_auth', true);
    if (!$token || $auth !== $token){
        return evapp_pkws_json(401, [], $ticket_id, 'get_pass unauthorized');
    }

    $url = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple', true);
    if (!$url) {
        // Genera en caliente si no existe
        $url = eventosapp_apple_generate_pass($ticket_id, false);
    }
    if (!$url) {
        return evapp_pkws_json(404, [], $ticket_id, 'get_pass no url');
    }

    $path = eventosapp_url_to_path($url);
    if (!$path || !file_exists($path)) {
        return evapp_pkws_json(404, [], $ticket_id, 'get_pass file missing');
    }

    // Headers caché/condicionales
    $mtime = gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT';
    $ims   = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';

    header('Last-Modified: '.$mtime);
    if ($ims && $ims === $mtime) {
        status_header(304);
        return;
    }

    header('Content-Type: application/vnd.apple.pkpass');
    header('Content-Disposition: attachment; filename="ticket_'.$ticket_id.'.pkpass"');

    readfile($path);
    exit;
}

/* ============================================================
 * ================ UTIL: RESOLVER TICKET POR SERIAL ==========
 * ============================================================ */

/** Encuentra el ticket por su serial (usamos eventosapp_ticketID como serial) */
function evapp_ticket_id_by_serial($serial){
    global $wpdb;
    $serial = sanitize_text_field($serial);
    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='eventosapp_ticketID' AND meta_value=%s LIMIT 1", $serial
    ));
    return $id ? (int)$id : 0;
}

/* ============================================================
 * ====================== PUSH VIA APNs =======================
 * ============================================================ */

/** Push APNs a todos los dispositivos registrados de un ticket */
function evapp_pkws_push_ticket_update($ticket_id){
    $cfg = evapp_pkws_cfg();
    $devs = get_post_meta($ticket_id, '_evapp_pk_devices', true);
    if (!is_array($devs) || !$devs) {
        return;
    }

    $host = ($cfg['env']==='production') ? 'https://api.push.apple.com' : 'https://api.sandbox.push.apple.com';
    $topic = $cfg['pass_type_id'];

    // Para HTTP/2 con certificado cliente, necesitamos un PEM combinado (cert+key).
    // Lo generamos (y cacheamos) a partir del .p12
    $pem = evapp_p12_to_pem_cached($cfg['p12_path'], $cfg['p12_pass']);
    if (!$pem) {
        return;
    }

    foreach ($devs as $deviceId => $info){
        $token = $info['pushToken'] ?? '';
        if (!$token) continue;

        $url = $host . '/3/device/' . $token;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apns-topic: '.$topic]);
        curl_setopt($ch, CURLOPT_SSLCERT, $pem);            // cert+key
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');           // vacío para PassKit
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    }
}

/* ============================================================
 * ============== P12 → PEM (CACHÉ Y UTILIDAD) ================
 * ============================================================ */

/** Convierte .p12 a .pem temporal (con caché básica y carpeta asegurada) */
function evapp_p12_to_pem_cached($p12_path, $password){
    $uploads = wp_upload_dir();
    $cache   = trailingslashit($uploads['basedir']) . 'eventosapp-integraciones/apple/passcert.pem';

    // Asegurar directorio antes de escribir
    $cacheDir = dirname($cache);
    if (!file_exists($cacheDir)) wp_mkdir_p($cacheDir);

    if (file_exists($cache) && (time()-filemtime($cache) < 86400)) {
        return $cache; // 1 día de caché
    }

    $pkcs12 = @file_get_contents($p12_path);
    if ($pkcs12 === false || $pkcs12 === '') {
        return null;
    }

    $certs = [];
    $ok = @openssl_pkcs12_read($pkcs12, $certs, (string)$password);
    if (!$ok) {
        return null;
    }

    $pem = ($certs['cert'] ?? '') . "\n" . ($certs['pkey'] ?? '');
    if ($pem === "\n") {
        return null;
    }

    file_put_contents($cache, $pem);
    return $cache;
}
