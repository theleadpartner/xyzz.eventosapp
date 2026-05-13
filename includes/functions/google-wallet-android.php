<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Incluye el loader y la librería JWT
require_once plugin_dir_path(__FILE__) . '../libs/firebase-jwt-loader.php';
eventosapp_include_firebase_jwt();

use Firebase\JWT\JWT;
use Firebase\JWT\Key; // si lo necesitas

/**
 * Obtiene un access_token de OAuth2 usando el service account configurado en Integraciones.
 * Devuelve array [ 'token' => string|null, 'logs' => string[] ]
 */
function eventosapp_google_wallet_get_access_token() {
    $logs = [];
    $log = function($m) use (&$logs){
        $line = '['.date('Y-m-d H:i:s')."] $m";
        $logs[] = $line;
        error_log("EVENTOSAPP WALLET AUTH $line");
    };

    // 1) Leer JSON de credenciales desde Integraciones
    $cred_path = get_option('eventosapp_wallet_json_path');
    if (!$cred_path || !file_exists($cred_path) || !is_readable($cred_path)) {
        $log("Credenciales no encontradas/ilegibles: " . var_export($cred_path, true));
        return ['token' => null, 'logs' => $logs];
    }
    $credentials = json_decode(file_get_contents($cred_path), true);
    if (!is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
        $log('JSON inválido: falta client_email o private_key.');
        return ['token' => null, 'logs' => $logs];
    }

    // 2) Firmar JWT (JWT-bearer) para el scope de Wallet Objects
    try {
        $now = time();
        $jwt_claim = [
            'iss'   => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now - 60,   // colchón 60s por si hay skew
            'exp'   => $now + 3600, // 1h
        ];
        $signed_jwt = \Firebase\JWT\JWT::encode($jwt_claim, $credentials['private_key'], 'RS256');
        $log('JWT firmado OK para token.');
    } catch (\Throwable $e) {
        $log('Error firmando JWT: ' . $e->getMessage());
        return ['token' => null, 'logs' => $logs];
    }

    // 3) Intercambiar por access_token
    $token_res = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 20,
        'body' => [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $signed_jwt
        ]
    ]);
    if (is_wp_error($token_res)) {
        $log('WP_Error token: ' . $token_res->get_error_message());
        return ['token' => null, 'logs' => $logs];
    }
    $code = wp_remote_retrieve_response_code($token_res);
    $body_raw = wp_remote_retrieve_body($token_res);
    $body = json_decode($body_raw, true);
    if ($code !== 200 || empty($body['access_token'])) {
        $log("Token HTTP $code | body: " . substr($body_raw, 0, 400));
        if (!empty($body['error_description'])) {
            $log("Pista: " . $body['error_description']);
        }
        return ['token' => null, 'logs' => $logs];
    }

    $log('Access token obtenido OK.');
    return ['token' => $body['access_token'], 'logs' => $logs];
}

/**
 * Crea/actualiza una EventTicketClass en Google Wallet para un evento dado.
 * - Crea una clase por evento con ID: "{issuer_id}.event_{evento_id}" (a menos que el evento tenga su propio class_id).
 * - Si ya existe, hace PATCH para actualizar algunos campos seguros.
 *
 * @param int   $evento_id  ID del post tipo eventosapp_event
 * @param array $args       Opcional: ['force_class_id' => 'issuerId.mi_clase']
 * @return array            ['ok' => bool, 'class_id' => string|null, 'logs' => array]
 */
function eventosapp_sync_wallet_class($evento_id, $args = []) {
    $logs = [];
    $log = function($m) use (&$logs, $evento_id){
        $line = '['.date('Y-m-d H:i:s')."] $m";
        $logs[] = $line;
        error_log("EVENTOSAPP WALLET CLASS [evento:$evento_id] $line");
    };

    if (!function_exists('wp_remote_request')) {
        $log('wp_remote_request no existe (¿fuera de WP?).');
        return ['ok' => false, 'class_id' => null, 'logs' => $logs];
    }

    // 0) Issuer fijo
    $issuer_id = get_option('eventosapp_wallet_issuer_id');
    if (!$issuer_id) {
        $log('issuer_id vacío en Integraciones.');
        return ['ok' => false, 'class_id' => null, 'logs' => $logs];
    }

    // 1) Token
    $tok = eventosapp_google_wallet_get_access_token();
    foreach ($tok['logs'] as $l) { $log($l); }
    if (empty($tok['token'])) {
        $log('No se pudo obtener access_token.');
        return ['ok' => false, 'class_id' => null, 'logs' => $logs];
    }
    $access_token = $tok['token'];

    // 2) Determinar class_id
    $wallet_custom_enable = get_post_meta($evento_id, '_eventosapp_wallet_custom_enable', true) === '1';
    $class_id_event  = $wallet_custom_enable ? (get_post_meta($evento_id, '_eventosapp_wallet_class_id', true) ?: '') : '';
    $class_id_default = get_option('eventosapp_wallet_class_id', '');

    if (!empty($args['force_class_id'])) {
        $class_id = trim($args['force_class_id']);
        $log("force_class_id recibido: $class_id");
    } elseif ($wallet_custom_enable) {
        $class_id = $class_id_event ?: ('event_' . $evento_id);
        $log("Usando class_id del EVENTO (o generado): $class_id");
    } else {
        if ($class_id_default) {
            $class_id = $class_id_default;
            $log("Evento NO personalizado -> usando class_id de Integraciones: $class_id");
        } else {
            $log("Evento NO personalizado y sin class global.");
            return ['ok' => false, 'class_id' => null, 'logs' => $logs];
        }
    }

    // Prefijo issuer
    if (strpos($class_id, '.') === false) {
        $class_id = $issuer_id . '.' . ltrim($class_id, '.');
    } else {
        $pref = substr($class_id, 0, strpos($class_id, '.'));
        if ($pref !== (string)$issuer_id) {
            $log("ADVERTENCIA: prefijo ($pref) != issuer_id ($issuer_id). Se usará tal cual.");
        }
    }

    // === Datos del evento para payload ===
    $issuerName    = get_bloginfo('name') ?: 'EventosApp';
    $nombreEvento  = get_the_title($evento_id) ?: 'Evento';
    $direccion     = get_post_meta($evento_id, '_eventosapp_direccion', true) ?: '';
    $coords        = get_post_meta($evento_id, '_eventosapp_coordenadas', true) ?: '';
    $logo_event    = get_post_meta($evento_id, '_eventosapp_wallet_logo_url', true) ?: '';
    $logo_global   = get_option('eventosapp_wallet_logo_url', '');
    $hero_event    = get_post_meta($evento_id, '_eventosapp_wallet_hero_img_url', true) ?: '';
    $hero_global   = get_option('eventosapp_wallet_hero_img_url', '');
    $hex_event     = get_post_meta($evento_id, '_eventosapp_wallet_hex_color', true) ?: '';
    $hex_global    = get_option('eventosapp_wallet_hex_color', '#3782C4');
    $hex_color     = $wallet_custom_enable ? ($hex_event ?: '#3782C4') : ($hex_global ?: '#3782C4');
    $logo_url      = $wallet_custom_enable ? ($logo_event ?: $logo_global) : $logo_global;
    $hero_url      = $wallet_custom_enable ? ($hero_event ?: $hero_global) : $hero_global;
    $brand_text    = get_option('eventosapp_wallet_branding_text') ?: 'Evento';

    // Overrides explícitos usados por variantes de ticket.
    // Permite que una clase VIP/General tenga branding distinto sin tocar la clase base del evento.
    if (!empty($args['event_name'])) {
        $nombreEvento = sanitize_text_field((string) $args['event_name']);
        $log('Override variant event_name aplicado a clase: ' . $nombreEvento);
    }
    if (!empty($args['logo_url'])) {
        $logo_url = esc_url_raw((string) $args['logo_url']);
        $log('Override variant logo_url aplicado a clase: ' . $logo_url);
    }
    if (!empty($args['hero_url'])) {
        $hero_url = esc_url_raw((string) $args['hero_url']);
        $log('Override variant hero_url aplicado a clase: ' . $hero_url);
    }
    if (!empty($args['hex_color'])) {
        $hex_override = trim((string) $args['hex_color']);
        if (preg_match('/^#?[0-9A-Fa-f]{6}$/', $hex_override)) {
            $hex_color = '#' . ltrim($hex_override, '#');
            $log('Override variant hex_color aplicado a clase: ' . $hex_color);
        }
    }
    if (!empty($args['brand_text'])) {
        $brand_text = sanitize_text_field((string) $args['brand_text']);
    }

//1 editado
	// === FECHAS / HORAS / ZONA (con logs) ===

	// Helper de log (usa $log si existe en la función; si no, error_log)
	$logf = function(string $m) use (&$log) {
		$prefix = 'EVENTOSAPP WALLET FECHAS ';
		if (isset($log) && is_callable($log)) { $log($m); }
		else { error_log($prefix . '['.date('Y-m-d H:i:s')."] $m"); }
	};

	$tipo = get_post_meta($evento_id, '_eventosapp_tipo_fecha', true) ?: 'unica';

	$hi = get_post_meta($evento_id, '_eventosapp_hora_inicio', true) ?: '00:00'; // hora inicio (y puertas)
	$hc = get_post_meta($evento_id, '_eventosapp_hora_cierre', true) ?: '23:59'; // hora fin
	$tz = get_post_meta($evento_id, '_eventosapp_zona_horaria', true) ?: 'UTC';

	$logf("Tipo de fecha: $tipo | hora_inicio=$hi | hora_cierre=$hc | tz=$tz");

	// Convierte a RFC3339 con desplazamiento (ej: 2025-08-06T08:00:00-05:00)
	$toIsoOffset = function($date, $time, $tz) {
		try {
			$z = new DateTimeZone($tz ?: 'UTC');
			$dt = new DateTime(trim($date.' '.$time), $z); // en TZ local del evento
			// IMPORTANTE: Wallet prefiere offset local, no Z
			return $dt->format('Y-m-d\TH:i:sP');
		} catch (\Throwable $e) {
			return null;
		}
	};

	$fi = ''; // fecha inicio (YYYY-MM-DD)
	$ff = ''; // fecha fin    (YYYY-MM-DD)

	if ($tipo === 'unica') {
		$fecha_unica = get_post_meta($evento_id, '_eventosapp_fecha_unica', true) ?: '';
		$fi = $fecha_unica;
		$ff = $fecha_unica;
		$logf("UNICA -> fecha=$fecha_unica");
	} elseif ($tipo === 'consecutiva') {
		$fecha_inicio = get_post_meta($evento_id, '_eventosapp_fecha_inicio', true) ?: '';
		$fecha_fin    = get_post_meta($evento_id, '_eventosapp_fecha_fin', true) ?: $fecha_inicio;
		$fi = $fecha_inicio;
		$ff = $fecha_fin;
		$logf("CONSECUTIVA -> inicio=$fecha_inicio | fin=$fecha_fin");
	} else { // noconsecutiva
		$fechas_noco = get_post_meta($evento_id, '_eventosapp_fechas_noco', true);
		if (!is_array($fechas_noco)) {
			$fechas_noco = is_string($fechas_noco) && $fechas_noco ? array_map('trim', explode(',', $fechas_noco)) : [];
		}
		$fechas_noco = array_values(array_filter($fechas_noco));
		sort($fechas_noco);
		$fi = $fechas_noco[0] ?? '';
		$ff = end($fechas_noco) ?: $fi;
		$logf("NO CONSECUTIVA -> total=".count($fechas_noco)." | primera=$fi | ultima=$ff");
	}

	// Convierte a RFC3339 con offset local (NO Z)
	$startISO = $fi ? $toIsoOffset($fi, $hi, $tz) : null; // inicio + hora_inicio
	$endISO   = $ff ? $toIsoOffset($ff, $hc, $tz) : null; // fin    + hora_cierre
	$doorsISO = $startISO;                                // apertura de puertas = inicio

	$logf("RESUELTO -> fi=$fi $hi | ff=$ff $hc");
	$logf("RFC3339 con offset -> startDateTime=$startISO | endDateTime=$endISO | doorsOpen=$doorsISO");

	// ---- Formateo legible de horario en TZ del evento (para textModules si se usan) ----
	$fmtLocal = function($date, $time, $tz) {
		try {
			$dt = new DateTime(trim($date.' '.$time), new DateTimeZone($tz ?: 'UTC'));
			return $dt->format('g:i A'); // p.ej. 8:00 AM
		} catch (\Throwable $e) { return null; }
	};
	$hora_inicio_local = ($fi && $hi) ? $fmtLocal($fi, $hi, $tz) : null;
	$hora_cierre_local = ($ff && $hc) ? $fmtLocal($ff, $hc, $tz) : null;

	$horario_legible = null;
	if ($hora_inicio_local && $hora_cierre_local) {
		$horario_legible = "{$hora_inicio_local} – {$hora_cierre_local} ({$tz})";
	} elseif ($hora_inicio_local) {
		$horario_legible = "{$hora_inicio_local} ({$tz})";
	}

	$logf("HORARIO LEGIBLE -> " . var_export($horario_legible, true));


	// === LUGAR / COORDENADAS (reinstalado) ===
	$direccion = get_post_meta($evento_id, '_eventosapp_direccion', true) ?: '';
	$coords    = get_post_meta($evento_id, '_eventosapp_coordenadas', true) ?: '';
	$lat = $lng = null;
	if ($coords && strpos($coords, ',') !== false) {
		list($latRaw, $lngRaw) = array_map('trim', explode(',', $coords, 2));
		if ($latRaw !== '' && $lngRaw !== '') {
			$lat = (float) $latRaw;
			$lng = (float) $lngRaw;
		}
	}
	$logf("LUGAR -> direccion=\"{$direccion}\" | coords=\"{$coords}\" | lat=" . var_export($lat, true) . " | lng=" . var_export($lng, true));


    // === Payload de Class ===
    $class_payload = [
        'id'                 => $class_id,
        'issuerName'         => $issuerName,
        'reviewStatus'       => 'UNDER_REVIEW',
        'eventName'          => [
            'defaultValue' => ['language' => 'es', 'value' => $nombreEvento]
        ],
        'hexBackgroundColor' => $hex_color
    ];

    if ($logo_url) {
        $class_payload['logo'] = [
            'sourceUri' => ['uri' => $logo_url],
            'contentDescription' => ['defaultValue' => ['language' => 'es', 'value' => $brand_text]],
        ];
    }

    if ($hero_url) {
        $class_payload['heroImage'] = [
            'sourceUri' => ['uri' => $hero_url],
            'contentDescription' => ['defaultValue' => ['language' => 'es', 'value' => $nombreEvento]],
        ];
    }

    if ($direccion) {
        $class_payload['venue'] = [
            'name' => [
                'defaultValue' => ['language' => 'es', 'value' => $direccion]
            ],
            'address' => [
                'defaultValue' => ['language' => 'es', 'value' => $direccion]
            ],
        ];
    }

    if ($lat !== null && $lng !== null) {
        $class_payload['locations'] = [['latitude' => $lat, 'longitude' => $lng]];
    }

    // ====== dateTime (schema correcto en EventTicketClass) ======
    $dateTime = [];
    if ($doorsISO) $dateTime['doorsOpen'] = $doorsISO; // strings ISO-8601
    if ($startISO) $dateTime['start']     = $startISO;
    if ($endISO)   $dateTime['end']       = $endISO;

    if (!empty($dateTime)) {
        $class_payload['dateTime'] = $dateTime;
    }


    // === Insert o Patch ===
    $insert_url = 'https://walletobjects.googleapis.com/walletobjects/v1/eventTicketClass';
    $res = wp_remote_request($insert_url, [
        'timeout' => 20,
        'headers' => [
            'Authorization' => "Bearer $access_token",
            'Content-Type'  => 'application/json'
        ],
        'body'   => wp_json_encode($class_payload),
        'method' => 'POST',
    ]);
    if (is_wp_error($res)) {
        $log('WP_Error al crear clase: ' . $res->get_error_message());
        return ['ok' => false, 'class_id' => $class_id, 'logs' => $logs];
    }
    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    $log("CREATE class HTTP $code | body: " . substr($body, 0, 600));

    if (in_array((int) $code, [200, 201], true)) {
        $log("Clase creada OK: $class_id");
        return ['ok' => true, 'class_id' => $class_id, 'logs' => $logs];
    }

    if ($code === 409) {
        $patch_url = 'https://walletobjects.googleapis.com/walletobjects/v1/eventTicketClass/' . rawurlencode($class_id);
        // En PATCH también se envía reviewStatus=UNDER_REVIEW.
        // Google Wallet rechaza la actualización de clases aprobadas cuando el payload
        // queda validado con reviewStatus=APPROVED. Para editar una clase existente,
        // el estado correcto solicitado por la API es UNDER_REVIEW.
        $patch_payload = [
            'issuerName'         => $issuerName,
            'reviewStatus'       => 'UNDER_REVIEW',
            'eventName'          => ['defaultValue' => ['language' => 'es', 'value' => $nombreEvento]],
            'hexBackgroundColor' => $hex_color,
        ];

        if ($logo_url) {
            $patch_payload['logo'] = [
                'sourceUri' => ['uri' => $logo_url],
                'contentDescription' => ['defaultValue' => ['language' => 'es', 'value' => $brand_text]],
            ];
        }

        if ($hero_url) {
            $patch_payload['heroImage'] = [
                'sourceUri' => ['uri' => $hero_url],
                'contentDescription' => ['defaultValue' => ['language' => 'es', 'value' => $nombreEvento]],
            ];
        }

        if ($direccion) {
            $patch_payload['venue'] = [
                'name' => [
                    'defaultValue' => ['language' => 'es', 'value' => $direccion]
                ],
                'address' => [
                    'defaultValue' => ['language' => 'es', 'value' => $direccion]
                ],
            ];
        }

        if ($lat !== null && $lng !== null) {
            $patch_payload['locations'] = [['latitude' => $lat, 'longitude' => $lng]];
        }

        // ====== dateTime (schema correcto en EventTicketClass) ======
        $dateTime = [];
        if ($doorsISO) $dateTime['doorsOpen'] = $doorsISO; // strings ISO-8601
        if ($startISO) $dateTime['start']     = $startISO;
        if ($endISO)   $dateTime['end']       = $endISO;

        if (!empty($dateTime)) {
            $patch_payload['dateTime'] = $dateTime;
        }


        $patch_res = wp_remote_request($patch_url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => "Bearer $access_token",
                'Content-Type'  => 'application/json'
            ],
            'body'   => wp_json_encode($patch_payload),
            'method' => 'PATCH',
        ]);
        if (is_wp_error($patch_res)) {
            $log('WP_Error al actualizar clase (PATCH): ' . $patch_res->get_error_message());
            return ['ok' => false, 'class_id' => $class_id, 'logs' => $logs];
        }
        $pcode = wp_remote_retrieve_response_code($patch_res);
        $pbody = wp_remote_retrieve_body($patch_res);
        $log("PATCH class HTTP $pcode | body: " . substr($pbody, 0, 600));

        if ($pcode === 200) {
            $log("Clase actualizada OK: $class_id");
            return ['ok' => true, 'class_id' => $class_id, 'logs' => $logs];
        }
        $log('No fue posible actualizar la clase existente.');
        return ['ok' => false, 'class_id' => $class_id, 'logs' => $logs];
    }

    $log('Fallo creando clase. Revisa el schema/campos del payload.');
    return ['ok' => false, 'class_id' => $class_id, 'logs' => $logs];
}


/**
 * Genera el enlace de Google Wallet para Android, lo guarda en el meta del ticket y lo retorna.
 * - $ticket_id: ID del post del ticket (CPT eventosapp_ticket)
 * - $debug: si true, devuelve logs en vez del enlace directo (opcional)
 */
function eventosapp_generar_enlace_wallet_android($ticket_id, $debug = false) {
    // Hook para permitir acciones antes de generar el wallet (como generar QR)
    do_action('eventosapp_before_generate_wallet_android', $ticket_id);
    
    $logs = [];
    $log  = function($msg) use (&$logs, $ticket_id) {
        $line = '['.date('Y-m-d H:i:s')."] $msg";
        $logs[] = $line;
        error_log("EVENTOSAPP WALLET ANDROID [ticket:$ticket_id] $line");
    };

    $log('Inicio generación de enlace Wallet Android');
	
    if (!function_exists('wp_remote_post')) {
        $log('wp_remote_post no existe (cargando fuera de WP?)');
        update_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_log', implode("\n", $logs));
        return $debug ? implode("<br>", array_map('esc_html', $logs)) : '';
    }

    $log('PHP version: ' . PHP_VERSION);
    if (defined('OPENSSL_VERSION_TEXT')) $log('OpenSSL: ' . OPENSSL_VERSION_TEXT);

    // 1) Credenciales
    $cred_path = get_option('eventosapp_wallet_json_path');
    $log("Ruta credenciales: " . var_export($cred_path, true));
    if (!$cred_path || !file_exists($cred_path) || !is_readable($cred_path)) {
        $log('No se encuentra o no es legible el JSON de credenciales.');
        update_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_log', implode("\n", $logs));
        return $debug ? implode("<br>", array_map('esc_html', $logs)) : '';
    }
    $credentials = json_decode(file_get_contents($cred_path), true);
    if (!is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
        $log('JSON de credenciales inválido (falta client_email o private_key).');
        update_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_log', implode("\n", $logs));
        return $debug ? implode("<br>", array_map('esc_html', $logs)) : '';
    }
    $log('Credenciales cargadas OK');

    // 2) Integraciones
    $issuer_id        = get_option('eventosapp_wallet_issuer_id');
    $class_id_default = get_option('eventosapp_wallet_class_id');
    $logo_url_default = get_option('eventosapp_wallet_logo_url') ?: 'https://eventosapp.com/wp-content/uploads/2020/12/nuevofeel.jpg';
    $hero_url_default = get_option('eventosapp_wallet_hero_img_url') ?: 'https://eventosapp.com/wp-content/uploads/2025/06/eventosapp_logo_favicon.png';
    $brand_text       = get_option('eventosapp_wallet_branding_text') ?: 'Evento';
    if (!$issuer_id) {
        $log('Falta Issuer ID en Integraciones.');
        update_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_log', implode("\n", $logs));
        return $debug ? implode("<br>", array_map('esc_html', $logs)) : '';
    }
    $log('Service Account (client_email): ' . ($credentials['client_email'] ?? 'desconocido'));
    if (!empty($credentials['project_id'])) $log('GCP project_id del JSON: ' . $credentials['project_id']);

    // 3) Datos ticket/evento
    $evento_id = get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    $log("evento_id del ticket: " . var_export($evento_id, true));
    if (!$evento_id) {
        $log('No se encontró evento asociado al ticket.');
        update_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_log', implode("\n", $logs));
        return $debug ? implode("<br>", array_map('esc_html', $logs)) : '';
    }
    $nombre_evento      = get_the_title($evento_id);
    $asistente_nombre   = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
    $asistente_apellido = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
    $asistente_email    = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
    $localidad          = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);
    
// === OBTENER O GENERAR QR DEL QR MANAGER (FORMATO SIMPLIFICADO) ===
    $log("Obteniendo QR de Google Wallet desde QR Manager...");
    $all_qr_codes = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
    
    $qr_image_url = '';
    $qr_content_for_validation = '';
    
    // Verificar si existe el QR de google_wallet
    if (!is_array($all_qr_codes) || !isset($all_qr_codes['google_wallet']) || empty($all_qr_codes['google_wallet']['content'])) {
        $log("QR de Google Wallet no encontrado en meta. Generando QR usando QR Manager...");
        
        // Intentar inicializar el QR Manager y generar el QR
        if (class_exists('EventosApp_QR_Manager')) {
            // Crear instancia del QR Manager
            $qr_manager = new EventosApp_QR_Manager();
            
            // Generar el QR de tipo google_wallet
            $qr_result = $qr_manager->generate_qr_code($ticket_id, 'google_wallet');
            
            if ($qr_result && is_array($qr_result) && isset($qr_result['content']) && isset($qr_result['url'])) {
                $qr_content_for_validation = $qr_result['content'];
                $qr_image_url = $qr_result['url'];
                
                $log("QR generado exitosamente por QR Manager.");
                $log("- QR ID: " . ($qr_result['qr_id'] ?? 'N/A'));
                $log("- Content: " . $qr_content_for_validation);
                $log("- Content length: " . strlen($qr_content_for_validation));
                $log("- Image URL: " . $qr_image_url);
                $log("- Type: google_wallet");
                $log("- Format: ticketID-gwallet (formato simplificado)");
                
                // Validar que el contenido tiene el formato correcto ticketID-gwallet
                if (strpos($qr_content_for_validation, '-gwallet') !== false) {
                    $parts = explode('-', $qr_content_for_validation);
                    if (count($parts) === 2) {
                        $log("- Validación: QR tiene formato simplificado correcto");
                        $log("  * ticketID: " . $parts[0]);
                        $log("  * tag: " . $parts[1]);
                    } else {
                        $log("- ADVERTENCIA: El QR no tiene exactamente 2 partes separadas por guión");
                    }
                } else {
                    $log("- ADVERTENCIA: El QR no contiene el tag '-gwallet'");
                }
            } else {
                // Fallback: generar QR básico con ticketID-gwallet
                $log("ADVERTENCIA: No se pudo generar QR con QR Manager. Usando fallback con ticketID-gwallet");
                $unique_ticket_id = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
                if ($unique_ticket_id) {
                    $qr_content_for_validation = $unique_ticket_id . '-gwallet';
                    $log("- Fallback content: " . $qr_content_for_validation);
                } else {
                    $qr_content_for_validation = $ticket_id . '-gwallet';
                    $log("- Fallback content (usando post ID): " . $qr_content_for_validation);
                }
                $qr_image_url = ''; // Sin imagen, Google Wallet generará el QR
            }
        } else {
            // Fallback: clase no disponible
            $log("ADVERTENCIA: Clase EventosApp_QR_Manager no disponible. Usando fallback con ticketID-gwallet");
            $unique_ticket_id = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
            if ($unique_ticket_id) {
                $qr_content_for_validation = $unique_ticket_id . '-gwallet';
                $log("- Fallback content: " . $qr_content_for_validation);
            } else {
                $qr_content_for_validation = $ticket_id . '-gwallet';
                $log("- Fallback content (usando post ID): " . $qr_content_for_validation);
            }
            $qr_image_url = ''; // Sin imagen, Google Wallet generará el QR
        }
    } else {
        // Usar el QR existente del QR Manager
        $qr_content_for_validation = $all_qr_codes['google_wallet']['content'];
        $qr_image_url = $all_qr_codes['google_wallet']['url'] ?? '';
        
        $log("QR de Google Wallet obtenido desde QR Manager (ya existía en meta).");
        $log("- QR ID: " . ($all_qr_codes['google_wallet']['qr_id'] ?? 'N/A'));
        $log("- Content: " . $qr_content_for_validation);
        $log("- Content length: " . strlen($qr_content_for_validation));
        $log("- Image URL: " . $qr_image_url);
        $log("- Created: " . ($all_qr_codes['google_wallet']['created_date'] ?? 'N/A'));
        $log("- Format: ticketID-gwallet (formato simplificado)");
        
        // Validar integridad del QR (formato simplificado)
        if (strpos($qr_content_for_validation, '-gwallet') !== false) {
            $parts = explode('-', $qr_content_for_validation);
            if (count($parts) === 2) {
                $log("- Validación: QR tiene formato simplificado correcto");
                $log("  * ticketID: " . $parts[0]);
                $log("  * tag: " . $parts[1]);
            } else {
                $log("- ADVERTENCIA: El QR no tiene exactamente 2 partes separadas por guión");
            }
        } else {
            $log("- ADVERTENCIA: El QR no contiene el tag '-gwallet'. Posible formato antiguo detectado.");
            // Si es formato antiguo (base64+JSON), intentar obtener el ticketID único y recrear
            $unique_ticket_id = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
            if ($unique_ticket_id) {
                $qr_content_for_validation = $unique_ticket_id . '-gwallet';
                $log("- QR regenerado con formato nuevo: " . $qr_content_for_validation);
            }
        }
    }
    
    // Validar que tenemos contenido válido
    if (empty($qr_content_for_validation)) {
        $log("ERROR CRÍTICO: No se pudo obtener contenido válido para el QR");
        update_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_log', implode("\n", $logs));
        return $debug ? implode("<br>", array_map('esc_html', $logs)) : '';
    }
    
    // Validar que tenemos una URL de imagen válida
    if (empty($qr_image_url)) {
        $log("ADVERTENCIA: No hay URL de imagen del QR. Google Wallet generará el QR desde el contenido");
    } else {
        $log("Se usará la imagen del QR Manager para Google Wallet: " . $qr_image_url);
    }
    
    $fecha = get_post_meta($evento_id, '_eventosapp_fecha_unica', true);
    $log("Evento: $nombre_evento | Asistente: $asistente_nombre $asistente_apellido | Email:$asistente_email | Localidad:$localidad | Fecha:$fecha");
    
    $fecha = get_post_meta($evento_id, '_eventosapp_fecha_unica', true);
    $log("Evento: $nombre_evento | Asistente: $asistente_nombre $asistente_apellido | Email:$asistente_email | Localidad:$localidad | Fecha:$fecha");

    // 4) Branding / class_id
    $wallet_custom_enable = get_post_meta($evento_id, '_eventosapp_wallet_custom_enable', true) === '1';
    $class_id_event       = get_post_meta($evento_id, '_eventosapp_wallet_class_id', true);
    $logo_url_event       = get_post_meta($evento_id, '_eventosapp_wallet_logo_url', true);
    $hero_url_event       = get_post_meta($evento_id, '_eventosapp_wallet_hero_img_url', true);
    $hex_color_event      = get_post_meta($evento_id, '_eventosapp_wallet_hex_color', true);
    $hex_color_default    = get_option('eventosapp_wallet_hex_color') ?: '#3782C4';
    $hex_color            = $wallet_custom_enable ? ($hex_color_event ?: '#3782C4') : ($hex_color_default ?: '#3782C4');

    if ($wallet_custom_enable) {
        $log('Personalización de Wallet por evento: ACTIVADA. Usando valores del evento.');
        $class_id = $class_id_event ?: ($issuer_id . '.event_' . $evento_id);
        $logo_url = $logo_url_event ?: $logo_url_default;
        $hero_url = $hero_url_event ?: $hero_url_default;
    } else {
        $log('Personalización de Wallet por evento: DESACTIVADA. Usando valores de Integraciones.');
        $class_id = $class_id_default;
        $logo_url = $logo_url_default;
        $hero_url = $hero_url_default;
    }

    // 4.0.1) Variante dirigida para Android Wallet.
    // Esta evaluación ocurre ANTES de sincronizar la clase y ANTES de crear/actualizar el objeto.
    // Así se evita la pelea entre el flujo base del evento y el flujo de variante.
    $variant_result = null;
    if (function_exists('eventosapp_ticket_variants_apply_to_ticket')) {
        $variant_result = eventosapp_ticket_variants_apply_to_ticket($ticket_id, $evento_id, true);
        $log('Resultado evaluación variante Android antes de resolver class_id: ' . wp_json_encode($variant_result));
    }

    $variant_class_id   = get_post_meta($ticket_id, '_eventosapp_wallet_variant_class_id', true);
    $variant_logo_url   = get_post_meta($ticket_id, '_eventosapp_wallet_variant_logo_url', true);
    $variant_hero_url   = get_post_meta($ticket_id, '_eventosapp_wallet_variant_hero_img_url', true);
    $variant_hex_color  = get_post_meta($ticket_id, '_eventosapp_wallet_variant_hex_color', true);
    $variant_event_name = get_post_meta($ticket_id, '_eventosapp_wallet_variant_event_name', true);
    $variant_key        = get_post_meta($ticket_id, '_eventosapp_ticket_variant_key', true);

    if (!empty($variant_class_id)) {
        $class_id = $variant_class_id;
        $log('Android Wallet usará Class ID de variante: ' . $class_id);
    }
    if (!empty($variant_logo_url)) {
        $logo_url = $variant_logo_url;
        $log('Android Wallet usará logo de variante: ' . $logo_url);
    }
    if (!empty($variant_hero_url)) {
        $hero_url = $variant_hero_url;
        $log('Android Wallet usará hero de variante: ' . $hero_url);
    }
    if (!empty($variant_hex_color)) {
        $hex_color = $variant_hex_color;
        $log('Android Wallet usará color de variante: ' . $hex_color);
    }
    if (!empty($variant_event_name)) {
        $nombre_evento = $variant_event_name;
        $log('Android Wallet usará nombre de evento de variante: ' . $nombre_evento);
    }

    if ($class_id) {
        if (function_exists('eventosapp_ticket_variants_normalize_class_id')) {
            $class_id = eventosapp_ticket_variants_normalize_class_id($class_id);
        } elseif (strpos($class_id, '.') === false) {
            $class_id = $issuer_id . '.' . ltrim($class_id, '.');
        } else {
            $prefijo = substr($class_id, 0, strpos($class_id, '.'));
            if ($prefijo !== (string)$issuer_id) $log("ADVERTENCIA: El prefijo del class_id ($prefijo) no coincide con issuer_id ($issuer_id). Se usará tal cual fue ingresado.");
        }
    } else {
        $log('Falta Class ID (ni en evento ni en Integraciones ni en variante).');
        update_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_log', implode("\n", $logs));
        return $debug ? implode("<br>", array_map('esc_html', $logs)) : '';
    }

    update_post_meta($ticket_id, '_eventosapp_wallet_google_class_id_effective', $class_id);

    // 4.1) Sincronizar/crear la clase ANTES de intentar crear el ticket
    // Esto garantiza que la clase exista en Google Wallet con branding de variante cuando aplique.
    $log("Sincronizando clase de Google Wallet antes de crear el ticket...");
    if (function_exists('eventosapp_sync_wallet_class')) {
        $sync = eventosapp_sync_wallet_class($evento_id, [
            'force_class_id' => $class_id,
            'logo_url'       => $logo_url,
            'hero_url'       => $hero_url,
            'hex_color'      => $hex_color,
            'event_name'     => $nombre_evento,
            'brand_text'     => $brand_text,
            'variant_key'    => $variant_key,
            'ticket_id'      => $ticket_id,
        ]);
        foreach ($sync['logs'] as $l) { $log($l); }
        if (!$sync['ok']) {
            $log('ADVERTENCIA: No se pudo sincronizar la clase, pero se intentará crear el ticket de todos modos.');
        } else {
            $log('Clase sincronizada exitosamente: ' . ($sync['class_id'] ?? $class_id));
            // Actualizar el class_id con el que realmente se usó en la sincronización
            if (!empty($sync['class_id'])) {
                $class_id = $sync['class_id'];
            }
        }
    } else {
        $log('ADVERTENCIA: La función eventosapp_sync_wallet_class no está disponible.');
    }

// 2
	// === FECHAS / HORAS / ZONA (con logs) ===

	// Helper de log (usa $log si existe en la función; si no, error_log)
	$logf = function(string $m) use (&$log) {
		$prefix = 'EVENTOSAPP WALLET FECHAS ';
		if (isset($log) && is_callable($log)) { $log($m); }
		else { error_log($prefix . '['.date('Y-m-d H:i:s')."] $m"); }
	};

	$tipo = get_post_meta($evento_id, '_eventosapp_tipo_fecha', true) ?: 'unica';

	$hi = get_post_meta($evento_id, '_eventosapp_hora_inicio', true) ?: '00:00'; // hora inicio (y puertas)
	$hc = get_post_meta($evento_id, '_eventosapp_hora_cierre', true) ?: '23:59'; // hora fin
	$tz = get_post_meta($evento_id, '_eventosapp_zona_horaria', true) ?: 'UTC';

	$logf("Tipo de fecha: $tipo | hora_inicio=$hi | hora_cierre=$hc | tz=$tz");

	// Convierte a RFC3339 con desplazamiento (ej: 2025-08-06T08:00:00-05:00)
	$toIsoOffset = function($date, $time, $tz) {
		try {
			$z = new DateTimeZone($tz ?: 'UTC');
			$dt = new DateTime(trim($date.' '.$time), $z); // en TZ local del evento
			// IMPORTANTE: Wallet prefiere offset local, no Z
			return $dt->format('Y-m-d\TH:i:sP');
		} catch (\Throwable $e) {
			return null;
		}
	};

	$fi = ''; // fecha inicio (YYYY-MM-DD)
	$ff = ''; // fecha fin    (YYYY-MM-DD)

	if ($tipo === 'unica') {
		$fecha_unica = get_post_meta($evento_id, '_eventosapp_fecha_unica', true) ?: '';
		$fi = $fecha_unica;
		$ff = $fecha_unica;
		$logf("UNICA -> fecha=$fecha_unica");
	} elseif ($tipo === 'consecutiva') {
		$fecha_inicio = get_post_meta($evento_id, '_eventosapp_fecha_inicio', true) ?: '';
		$fecha_fin    = get_post_meta($evento_id, '_eventosapp_fecha_fin', true) ?: $fecha_inicio;
		$fi = $fecha_inicio;
		$ff = $fecha_fin;
		$logf("CONSECUTIVA -> inicio=$fecha_inicio | fin=$fecha_fin");
	} else { // noconsecutiva
		$fechas_noco = get_post_meta($evento_id, '_eventosapp_fechas_noco', true);
		if (!is_array($fechas_noco)) {
			$fechas_noco = is_string($fechas_noco) && $fechas_noco ? array_map('trim', explode(',', $fechas_noco)) : [];
		}
		$fechas_noco = array_values(array_filter($fechas_noco));
		sort($fechas_noco);
		$fi = $fechas_noco[0] ?? '';
		$ff = end($fechas_noco) ?: $fi;
		$logf("NO CONSECUTIVA -> total=".count($fechas_noco)." | primera=$fi | ultima=$ff");
	}

	// Convierte a RFC3339 con offset local (NO Z)
	$startISO = $fi ? $toIsoOffset($fi, $hi, $tz) : null; // inicio + hora_inicio
	$endISO   = $ff ? $toIsoOffset($ff, $hc, $tz) : null; // fin    + hora_cierre
	$doorsISO = $startISO;                                // apertura de puertas = inicio

	$logf("RESUELTO -> fi=$fi $hi | ff=$ff $hc");
	$logf("RFC3339 con offset -> startDateTime=$startISO | endDateTime=$endISO | doorsOpen=$doorsISO");

	// ---- Formateo legible de horario en TZ del evento (para textModules) ----
	$fmtLocal = function($date, $time, $tz) {
		try {
			$dt = new DateTime(trim($date.' '.$time), new DateTimeZone($tz ?: 'UTC'));
			return $dt->format('g:i A'); // p.ej. 8:00 AM
		} catch (\Throwable $e) { return null; }
	};
	$hora_inicio_local = ($fi && $hi) ? $fmtLocal($fi, $hi, $tz) : null;
	$hora_cierre_local = ($ff && $hc) ? $fmtLocal($ff, $hc, $tz) : null;

	$horario_legible = null;
	if ($hora_inicio_local && $hora_cierre_local) {
		$horario_legible = "{$hora_inicio_local} – {$hora_cierre_local} ({$tz})";
	} elseif ($hora_inicio_local) {
		$horario_legible = "{$hora_inicio_local} ({$tz})";
	}

	$logf("HORARIO LEGIBLE -> " . var_export($horario_legible, true));


// === LUGAR / COORDENADAS (reinstalado) ===
$direccion = get_post_meta($evento_id, '_eventosapp_direccion', true) ?: '';
$coords    = get_post_meta($evento_id, '_eventosapp_coordenadas', true) ?: '';
$lat = $lng = null;
if ($coords && strpos($coords, ',') !== false) {
    list($latRaw, $lngRaw) = array_map('trim', explode(',', $coords, 2));
    if ($latRaw !== '' && $lngRaw !== '') {
        $lat = (float) $latRaw;
        $lng = (float) $lngRaw;
    }
}
$logf("LUGAR -> direccion=\"{$direccion}\" | coords=\"{$coords}\" | lat=" . var_export($lat, true) . " | lng=" . var_export($lng, true));



    // 6) Payload del objeto
    // === GENERAR OBJECT_ID USANDO eventosapp_ticketID ===
// Obtener el ticket ID único
$unique_ticket_id = get_post_meta($ticket_id, 'eventosapp_ticketID', true);

if (empty($unique_ticket_id)) {
    $log("ERROR CRÍTICO: No se encontró eventosapp_ticketID para el ticket $ticket_id");
    update_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_log', implode("\n", $logs));
    return $debug ? implode("<br>", array_map('esc_html', $logs)) : '';
}

// Construir object_id canónico.
// Para tickets con variante de Android se usa un objeto distinto por variante.
// Esto evita el conflicto irreversible donde un objeto ya existente nació con la clase base
// y Google Wallet no permite moverlo a otra classId mediante PATCH.
$object_ticket_suffix = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $unique_ticket_id);
$variant_object_suffix = '';
if (!empty($variant_key) && !empty($variant_class_id)) {
    $variant_object_suffix = sanitize_key((string) $variant_key);
    if ($variant_object_suffix !== '') {
        $object_ticket_suffix .= '_variant_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $variant_object_suffix);
        $log('Object ID dirigido por variante Android: variant_key=' . $variant_object_suffix . ' | public_ticket_id=' . $unique_ticket_id);
    }
}

// Formato base sin variante: issuerID.uniqueTicketID
// Formato con variante: issuerID.uniqueTicketID_variant_{variant_key}
$object_id = $issuer_id . '.' . $object_ticket_suffix;

$log("=== OBJECT ID GENERADO ===");
$log("issuer_id: $issuer_id");
$log("class_id: $class_id");
$log("unique_ticket_id (eventosapp_ticketID): $unique_ticket_id");
$log("variant_key para object_id: " . ($variant_object_suffix !== '' ? $variant_object_suffix : ''));
$log("object_id final: $object_id");
$log("QR content (debe coincidir con ticketID): $qr_content_for_validation");

// Validar que el object_id y el QR content son consistentes
if (strpos($qr_content_for_validation, $unique_ticket_id) !== 0) {
    $log("⚠️ ADVERTENCIA: El QR content no comienza con el unique_ticket_id");
    $log("   - unique_ticket_id: $unique_ticket_id");
    $log("   - QR content: $qr_content_for_validation");
} else {
    $log("✅ Validación OK: Object ID y QR content son consistentes");
}
    $log("issuer_id=$issuer_id | class_id=$class_id | object_id=$object_id");

    // ----- Construye textModulesData (unimos Fecha + Horario para que entre en el top-4) -----
    $fecha_mas_horario = $fi;
    if (!empty($horario_legible)) {
        // Ej: "2025-08-06 · 8:00 AM – 6:00 PM (America/Bogota)"
        $fecha_mas_horario = $fi . ' · ' . $horario_legible;
    }

    $textModules = [
        ['header' => 'Asistente', 'body' => trim($asistente_nombre . ' ' . $asistente_apellido)],
        ['header' => 'Email',     'body' => $asistente_email],
        ['header' => 'Localidad', 'body' => $localidad],
        // Mantén este como cuarto ítem para asegurar visibilidad
        ['header' => 'Fecha',     'body' => $fecha_mas_horario],
    ];

    // ====== validTimeInterval (schema correcto en EventTicketObject) ======
    // TimeInterval -> DateTime -> { date: "ISO-8601" }
    $valid = [];
    if ($startISO) $valid['start'] = ['date' => $startISO];
    if ($endISO)   $valid['end']   = ['date' => $endISO];

// === BARCODE CON CONTENIDO DEL QR MANAGER (FORMATO SIMPLIFICADO) ===
    // El contenido del QR Manager (ticketID-gwallet) se envía en el value
    // Google Wallet lo renderizará como código QR visual
    $barcode_config = [
        'type'          => 'qrCode',
        'value'         => $qr_content_for_validation,
        'alternateText' => '',
        'renderEncoding' => 'utf_8'
    ];
    
    $log("Barcode configurado con contenido del QR Manager");
    $log("- Content format: ticketID-gwallet (formato simplificado)");
    $log("- Content value: " . $qr_content_for_validation);
    $log("- Content length: " . strlen($qr_content_for_validation));
    
    $object_payload = [
        'id'        => $object_id,
        'classId'   => $class_id,
        'state'     => 'active',
        'heroImage' => [
            'sourceUri'           => ['uri' => $hero_url],
            'contentDescription'  => ['defaultValue' => ['language' => 'es', 'value' => $brand_text]]
        ],
        'logo'       => ['sourceUri' => ['uri' => $logo_url]],
        'eventName'  => ['defaultValue' => ['language' => 'es', 'value' => $nombre_evento]],
        // ⛔️ NO poner startDateTime/endDateTime/doorsOpen en el objeto
        'venue' => $direccion ? [
            'name'    => ['defaultValue' => ['language' => 'es', 'value' => $direccion]],
            'address' => ['defaultValue' => ['language' => 'es', 'value' => $direccion]],
        ] : null,
        'locations' => ($lat !== null && $lng !== null) ? [['latitude' => $lat, 'longitude' => $lng]] : null,
        'textModulesData' => $textModules,
        'barcode' => $barcode_config
    ];

    if (!empty($valid)) {
        $object_payload['validTimeInterval'] = $valid;
    }

    // Limpia nulls
    foreach ($object_payload as $k => $v) { if ($v === null) unset($object_payload[$k]); }
    $log('Payload de objeto preparado (Fecha + Horario combinados + validTimeInterval + Barcode con QR Manager)');


    // 7) Access token
    $auth = eventosapp_google_wallet_get_access_token();
    foreach ($auth['logs'] as $l) { $log($l); }
    $access_token = $auth['token'] ?? null;
    if (!$access_token) {
        $log('No se obtuvo access_token');
        update_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_log', implode("\n", $logs));
        return $debug ? implode("<br>", array_map('esc_html', $logs)) : '';
    }

    // 8) Crear/actualizar objeto Google Wallet con flujo dirigido por variante.
    // Importante: si el objeto ya existe con otra classId, Google no permite moverlo con PATCH simple.
    // Por eso se consulta el objeto, se compara classId y, si no coincide, se elimina y recrea.
    $api_url = "https://walletobjects.googleapis.com/walletobjects/v1/eventTicketObject";
    $object_url = $api_url . '/' . rawurlencode($object_id);
    $headers_json = [
        'Authorization' => "Bearer $access_token",
        'Content-Type'  => 'application/json',
    ];

    $insert_object = function($reason = 'insert') use ($api_url, $headers_json, &$object_payload, &$object_id, $class_id, $ticket_id, $evento_id, $log) {
        $res = wp_remote_post($api_url, [
            'timeout' => 20,
            'headers' => $headers_json,
            'body'    => wp_json_encode($object_payload),
        ]);
        $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
        $body = is_wp_error($res) ? ('WP_Error: ' . $res->get_error_message()) : wp_remote_retrieve_body($res);
        $log("INSERT WalletObject ($reason) HTTP $code | object=$object_id | class=$class_id | body: " . substr($body, 0, 700));

        if (in_array((int) $code, [200, 201], true)) {
            update_post_meta($ticket_id, '_eventosapp_wallet_google_object_id', $object_id);
            update_post_meta($ticket_id, '_eventosapp_wallet_object_id', $object_id);
            update_post_meta($ticket_id, '_eventosapp_wallet_google_class_id_effective', $class_id);
            update_post_meta($ticket_id, '_eventosapp_wallet_google_last_flow', 'insert_' . $reason);
        }

        return [$code, $body];
    };

    $patch_object = function($reason = 'patch') use (&$object_url, $headers_json, &$object_payload, &$object_id, $class_id, $ticket_id, $evento_id, $log) {
        $patch_payload = $object_payload;
        unset($patch_payload['id'], $patch_payload['classId']);

        $res = wp_remote_request($object_url, [
            'timeout' => 20,
            'headers' => $headers_json,
            'body'    => wp_json_encode($patch_payload),
            'method'  => 'PATCH',
        ]);
        $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
        $body = is_wp_error($res) ? ('WP_Error: ' . $res->get_error_message()) : wp_remote_retrieve_body($res);
        $log("PATCH WalletObject ($reason) HTTP $code | object=$object_id | class=$class_id | body: " . substr($body, 0, 700));

        if ((int) $code === 200) {
            update_post_meta($ticket_id, '_eventosapp_wallet_google_object_id', $object_id);
            update_post_meta($ticket_id, '_eventosapp_wallet_object_id', $object_id);
            update_post_meta($ticket_id, '_eventosapp_wallet_google_class_id_effective', $class_id);
            update_post_meta($ticket_id, '_eventosapp_wallet_google_last_flow', 'patch_' . $reason);
        }

        return [$code, $body];
    };

    list($api_code, $api_body) = $insert_object('initial');

    // Si la clase no existe, se crea/actualiza con el contexto ya filtrado por variante y se reintenta.
    if (in_array((int) $api_code, [400, 404], true) && $class_id) {
        $maybe_body = json_decode($api_body, true);
        $reason = is_array($maybe_body) && !empty($maybe_body['error']['errors'][0]['reason'])
            ? (string) $maybe_body['error']['errors'][0]['reason'] : '';

        if ($reason === 'classNotFound' || (int) $api_code === 404) {
            $log('La Class no existe para el objeto. Intentando crear/actualizar clase con contexto efectivo y reintentar...');
            $sync = eventosapp_sync_wallet_class($evento_id, [
                'force_class_id' => $class_id,
                'logo_url'       => $logo_url,
                'hero_url'       => $hero_url,
                'hex_color'      => $hex_color,
                'event_name'     => $nombre_evento,
                'brand_text'     => $brand_text,
                'variant_key'    => $variant_key,
                'ticket_id'      => $ticket_id,
            ]);
            foreach ($sync['logs'] as $l) { $log($l); }
            if (!empty($sync['ok'])) {
                list($api_code, $api_body) = $insert_object('retry_after_class_sync');
            }
        }
    }

    // Si el objeto existe, consultar classId actual y decidir entre PATCH o DELETE+INSERT.
    if ((int) $api_code === 409) {
        $get_res = wp_remote_get($object_url, [
            'timeout' => 20,
            'headers' => ['Authorization' => "Bearer $access_token"],
        ]);
        $get_code = is_wp_error($get_res) ? 0 : wp_remote_retrieve_response_code($get_res);
        $get_body = is_wp_error($get_res) ? ('WP_Error: ' . $get_res->get_error_message()) : wp_remote_retrieve_body($get_res);
        $log('GET WalletObject existente HTTP ' . $get_code . ' | object=' . $object_id . ' | body: ' . substr($get_body, 0, 700));

        $current_class = '';
        if ((int) $get_code === 200) {
            $existing = json_decode($get_body, true);
            if (is_array($existing)) {
                if (!empty($existing['classId'])) {
                    $current_class = (string) $existing['classId'];
                } elseif (!empty($existing['classReference']['id'])) {
                    $current_class = (string) $existing['classReference']['id'];
                }
            }
        }

        if ((int) $get_code === 200 && $current_class !== '' && $current_class !== (string) $class_id) {
            // No se intenta mover el objeto existente de clase base a clase variante.
            // Google Wallet no permite cambiar classId con PATCH y en algunos entornos DELETE
            // devuelve 404 aunque el GET confirme que el objeto existe.
            // La solución segura es usar un object_id distinto para la variante.
            $log('Class mismatch detectado. current=' . $current_class . ' | wanted=' . $class_id . ' | object_id actual=' . $object_id . '. Se usará object_id alternativo de variante si aplica.');

            if (!empty($variant_key)) {
                $suffix_alt = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $unique_ticket_id) . '_variant_' . preg_replace('/[^A-Za-z0-9._-]/', '_', sanitize_key((string) $variant_key));
                $object_id = $issuer_id . '.' . $suffix_alt;
                $object_url = $api_url . '/' . rawurlencode($object_id);
                $log('Object ID alternativo por class mismatch: ' . $object_id);
                $object_payload['id'] = $object_id;
                list($api_code, $api_body) = $insert_object('variant_object_after_class_mismatch');
                if ((int) $api_code === 409) {
                    $log('El object_id alternativo ya existe. Se aplicará PATCH conservador sobre la clase de variante.');
                    list($api_code, $api_body) = $patch_object('variant_object_existing_after_class_mismatch');
                }
            } else {
                $log('Class mismatch sin variant_key. Se aplicará PATCH conservador sin classId para no romper el objeto existente.');
                list($api_code, $api_body) = $patch_object('class_mismatch_no_variant_key');
            }
        } elseif ((int) $get_code === 200) {
            $log('Objeto existente usa la class correcta. Se aplicará PATCH sin classId. current=' . $current_class . ' | wanted=' . $class_id);
            list($api_code, $api_body) = $patch_object('same_class');
        } elseif ((int) $get_code === 404) {
            $log('El POST devolvió 409 pero el GET ya no encuentra el objeto. Se reintentará INSERT.');
            list($api_code, $api_body) = $insert_object('after_409_get_404');
        } else {
            $log('No se pudo consultar objeto existente tras 409. Se intentará PATCH conservador sin classId.');
            list($api_code, $api_body) = $patch_object('get_failed_after_409');
        }
    }

    if (!in_array((int) $api_code, [200, 201], true)) {
        $log("Creación/actualización de objeto falló (esperado 200/201). Último HTTP $api_code | body: " . substr((string) $api_body, 0, 700));
        update_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_log', implode("\n", $logs));
        return $debug ? implode("<br>", array_map('esc_html', $logs)) : '';
    }

    // 9) Save-to-Wallet link
    try {
        $payload = [
            'iss' => $credentials['client_email'],
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => time(),
            'payload' => [
                'eventTicketObjects' => [['id' => $object_id]]
            ]
        ];
        $save_jwt = \Firebase\JWT\JWT::encode($payload, $credentials['private_key'], 'RS256');
        $url = 'https://pay.google.com/gp/v/save/' . $save_jwt;
        $log('Save-to-Wallet JWT generado OK');
    } catch (\Throwable $e) {
        $log('Error firmando Save-to-Wallet JWT: ' . $e->getMessage());
        update_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_log', implode("\n", $logs));
        return $debug ? implode("<br>", array_map('esc_html', $logs)) : '';
    }

    // 10) Persistir
    update_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url', $url);
    update_post_meta($ticket_id, '_eventosapp_wallet_google_object_id', $object_id);
    update_post_meta($ticket_id, '_eventosapp_wallet_object_id', $object_id);
    update_post_meta($ticket_id, '_eventosapp_wallet_google_class_id_effective', $class_id);
    update_post_meta($ticket_id, '_eventosapp_wallet_google_last_generated_at', current_time('mysql'));
    update_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_log', implode("\n", $logs));
    $log("URL guardada en meta: $url");
    $log("Objeto Google Wallet efectivo guardado: object_id=$object_id | class_id=$class_id");

    if ($debug) {
        $log("Proceso finalizado (debug ON).");
        return implode("<br>", array_map('esc_html', $logs)) . "<br><br>🔗 <a href='$url' target='_blank'>Agregar a Wallet</a>";
    }
    return $url;
}


// Función opcional para limpiar el enlace de Wallet
function eventosapp_eliminar_enlace_wallet_android($ticket_id) {
    delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url');
}
