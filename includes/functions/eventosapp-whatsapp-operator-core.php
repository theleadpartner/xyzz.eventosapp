<?php
/**
 * EventosApp - Núcleo de Operador WhatsApp / Tech Provider.
 *
 * Añade una capa multicliente sobre la integración existente de WhatsApp
 * sin reemplazarla. Mantiene como fallback la configuración histórica global
 * de WhatsApp Tickets y solo inyecta credenciales por WABA/Phone Number ID
 * cuando el activo fue incorporado desde este módulo.
 *
 * Ruta: includes/functions/eventosapp-whatsapp-operator-core.php
 *
 * @package EventosApp
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! defined('EVENTOSAPP_WA_OPERATOR_VERSION') ) {
    define('EVENTOSAPP_WA_OPERATOR_VERSION', '2026.07.20.2');
}
if ( ! defined('EVENTOSAPP_WA_OPERATOR_DB_VERSION') ) {
    define('EVENTOSAPP_WA_OPERATOR_DB_VERSION', '2026.07.20.2');
}
if ( ! defined('EVENTOSAPP_WA_OPERATOR_OPTION') ) {
    define('EVENTOSAPP_WA_OPERATOR_OPTION', 'eventosapp_whatsapp_operator_settings');
}
if ( ! defined('EVENTOSAPP_WA_OPERATOR_HEALTH_HOOK') ) {
    define('EVENTOSAPP_WA_OPERATOR_HEALTH_HOOK', 'eventosapp_whatsapp_operator_token_health');
}

/**
 * Nombres de tablas.
 */
function eventosapp_wa_operator_table($name) {
    global $wpdb;

    $map = [
        'businesses' => 'eventosapp_wa_businesses',
        'accounts'   => 'eventosapp_wa_accounts',
        'phones'     => 'eventosapp_wa_phone_numbers',
        'credentials'=> 'eventosapp_wa_credentials',
        'sessions'   => 'eventosapp_wa_onboarding_sessions',
        'logs'       => 'eventosapp_wa_account_logs',
    ];

    $name = sanitize_key((string) $name);
    return isset($map[$name]) ? $wpdb->prefix . $map[$name] : '';
}

/**
 * Configuración del operador.
 */
function eventosapp_wa_operator_default_settings() {
    return [
        'enabled'                  => '0',
        'app_id'                   => '',
        'app_secret_encrypted'     => '',
        'configuration_id'         => '',
        'api_version'              => 'v23.0',
        'provider_business_id'     => '',
        'system_user_id'           => '',
        'system_user_token_encrypted' => '',
        'auto_assign_system_user'  => '0',
        'verify_webhook_signature' => '0',
        'auto_subscribe_webhook'   => '1',
        'auto_sync_after_signup'   => '1',
        'auto_import_phone_numbers'=> '1',
        'token_health_enabled'     => '1',
        'token_warning_days'       => 7,
        'last_health_run'          => '',
        'last_health_summary'      => [],
    ];
}

function eventosapp_wa_operator_get_settings() {
    $saved = get_option(EVENTOSAPP_WA_OPERATOR_OPTION, []);
    if ( ! is_array($saved) ) {
        $saved = [];
    }

    $settings = wp_parse_args($saved, eventosapp_wa_operator_default_settings());
    $settings['enabled'] = ! empty($settings['enabled']) ? '1' : '0';
    $settings['app_id'] = preg_replace('/\D+/', '', (string)($settings['app_id'] ?? ''));
    $settings['configuration_id'] = sanitize_text_field((string)($settings['configuration_id'] ?? ''));
    $settings['provider_business_id'] = preg_replace('/\D+/', '', (string)($settings['provider_business_id'] ?? ''));
    $settings['system_user_id'] = preg_replace('/\D+/', '', (string)($settings['system_user_id'] ?? ''));
    $settings['api_version'] = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)($settings['api_version'] ?? 'v23.0'));
    if ( $settings['api_version'] === '' ) {
        $settings['api_version'] = 'v23.0';
    }
    foreach ( ['auto_assign_system_user', 'verify_webhook_signature', 'auto_subscribe_webhook', 'auto_sync_after_signup', 'auto_import_phone_numbers', 'token_health_enabled'] as $key ) {
        $settings[$key] = ! empty($settings[$key]) ? '1' : '0';
    }
    $settings['token_warning_days'] = min(60, max(1, absint($settings['token_warning_days'] ?? 7)));
    if ( ! is_array($settings['last_health_summary'] ?? null) ) {
        $settings['last_health_summary'] = [];
    }

    return $settings;
}

function eventosapp_wa_operator_update_settings($settings) {
    $settings = is_array($settings) ? $settings : [];
    $current = eventosapp_wa_operator_get_settings();
    $merged = wp_parse_args($settings, $current);
    update_option(EVENTOSAPP_WA_OPERATOR_OPTION, $merged, false);
    return eventosapp_wa_operator_get_settings();
}

/**
 * Cifrado de secretos.
 *
 * Usa Sodium cuando está disponible. Como respaldo utiliza AES-256-GCM.
 * Nunca devuelve el secreto en texto plano si no hay una primitiva segura.
 */
function eventosapp_wa_operator_encryption_key() {
    $material = wp_salt('auth') . '|' . wp_salt('secure_auth') . '|' . wp_salt('logged_in');
    return hash('sha256', $material, true);
}

function eventosapp_wa_operator_encrypt_secret($plain) {
    $plain = (string) $plain;
    if ( $plain === '' ) {
        return '';
    }

    $key = eventosapp_wa_operator_encryption_key();

    if ( function_exists('sodium_crypto_secretbox') && function_exists('random_bytes') ) {
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
            return 'sodium:' . base64_encode($nonce . $cipher);
        } catch (Throwable $e) {
            // Continúa con OpenSSL.
        }
    }

    if ( function_exists('openssl_encrypt') && function_exists('random_bytes') ) {
        try {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ( $cipher !== false && $tag !== '' ) {
                return 'openssl:' . base64_encode($iv . $tag . $cipher);
            }
        } catch (Throwable $e) {
            // Se devuelve un error controlado debajo.
        }
    }

    return new WP_Error(
        'eventosapp_wa_operator_crypto_unavailable',
        'El servidor no tiene Sodium ni OpenSSL AES-256-GCM disponibles. No se guardó el secreto.'
    );
}

function eventosapp_wa_operator_decrypt_secret($encoded) {
    $encoded = (string) $encoded;
    if ( $encoded === '' ) {
        return '';
    }

    $key = eventosapp_wa_operator_encryption_key();

    if ( strpos($encoded, 'sodium:') === 0 ) {
        if ( ! function_exists('sodium_crypto_secretbox_open') || ! defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES') ) {
            return '';
        }
        $raw = base64_decode(substr($encoded, 7), true);
        if ( ! is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
            return '';
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        if ( function_exists('sodium_crypto_secretbox_open') ) {
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            return is_string($plain) ? $plain : '';
        }
        return '';
    }

    if ( strpos($encoded, 'openssl:') === 0 ) {
        $raw = base64_decode(substr($encoded, 8), true);
        if ( ! is_string($raw) || strlen($raw) <= 28 || ! function_exists('openssl_decrypt') ) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return is_string($plain) ? $plain : '';
    }

    // No se aceptan valores históricos sin prefijo para evitar tratarlos como texto plano.
    return '';
}

function eventosapp_wa_operator_get_app_secret() {
    $settings = eventosapp_wa_operator_get_settings();
    return eventosapp_wa_operator_decrypt_secret($settings['app_secret_encrypted'] ?? '');
}

function eventosapp_wa_operator_set_app_secret($secret) {
    $secret = trim((string) $secret);
    if ( $secret === '' ) {
        return true;
    }

    $encrypted = eventosapp_wa_operator_encrypt_secret($secret);
    if ( is_wp_error($encrypted) ) {
        return $encrypted;
    }

    $settings = eventosapp_wa_operator_get_settings();
    $settings['app_secret_encrypted'] = $encrypted;
    update_option(EVENTOSAPP_WA_OPERATOR_OPTION, $settings, false);
    return true;
}

/**
 * Token administrativo del System User del proveedor.
 * Es opcional y se usa únicamente para asignar ese System User a WABAs
 * compartidas y, una vez asignado, como token operativo estable.
 */
function eventosapp_wa_operator_get_system_user_token() {
    $settings = eventosapp_wa_operator_get_settings();
    return eventosapp_wa_operator_decrypt_secret($settings['system_user_token_encrypted'] ?? '');
}

function eventosapp_wa_operator_set_system_user_token($token) {
    $token = trim((string)$token);
    if ( $token === '' ) {
        return true;
    }

    $encrypted = eventosapp_wa_operator_encrypt_secret($token);
    if ( is_wp_error($encrypted) ) {
        return $encrypted;
    }

    $settings = eventosapp_wa_operator_get_settings();
    $settings['system_user_token_encrypted'] = $encrypted;
    update_option(EVENTOSAPP_WA_OPERATOR_OPTION, $settings, false);
    return true;
}

/**
 * Instalación idempotente de tablas.
 */
function eventosapp_wa_operator_install_tables() {
    global $wpdb;

    $charset = $wpdb->get_charset_collate();
    $businesses = eventosapp_wa_operator_table('businesses');
    $accounts = eventosapp_wa_operator_table('accounts');
    $phones = eventosapp_wa_operator_table('phones');
    $credentials = eventosapp_wa_operator_table('credentials');
    $sessions = eventosapp_wa_operator_table('sessions');
    $logs = eventosapp_wa_operator_table('logs');

    if ( ! function_exists('dbDelta') ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    $sql_businesses = "CREATE TABLE {$businesses} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        client_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        meta_business_id VARCHAR(120) NOT NULL DEFAULT '',
        name VARCHAR(190) NOT NULL DEFAULT '',
        verification_status VARCHAR(80) NOT NULL DEFAULT '',
        status VARCHAR(80) NOT NULL DEFAULT '',
        raw_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_post_id (client_post_id),
        KEY meta_business_id (meta_business_id),
        KEY status (status)
    ) {$charset};";

    $sql_accounts = "CREATE TABLE {$accounts} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        business_row_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        client_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        meta_business_id VARCHAR(120) NOT NULL DEFAULT '',
        waba_id VARCHAR(120) NOT NULL DEFAULT '',
        name VARCHAR(190) NOT NULL DEFAULT '',
        currency VARCHAR(20) NOT NULL DEFAULT '',
        timezone_id VARCHAR(80) NOT NULL DEFAULT '',
        account_status VARCHAR(80) NOT NULL DEFAULT '',
        review_status VARCHAR(80) NOT NULL DEFAULT '',
        business_verification_status VARCHAR(80) NOT NULL DEFAULT '',
        system_user_id VARCHAR(120) NOT NULL DEFAULT '',
        system_user_assigned TINYINT(1) NOT NULL DEFAULT 0,
        webhook_subscribed TINYINT(1) NOT NULL DEFAULT 0,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        last_synced_at DATETIME NULL,
        raw_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY waba_id (waba_id),
        KEY business_row_id (business_row_id),
        KEY client_post_id (client_post_id),
        KEY meta_business_id (meta_business_id),
        KEY account_status (account_status)
    ) {$charset};";

    $sql_phones = "CREATE TABLE {$phones} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        client_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        waba_id VARCHAR(120) NOT NULL DEFAULT '',
        phone_number_id VARCHAR(120) NOT NULL DEFAULT '',
        display_phone_number VARCHAR(80) NOT NULL DEFAULT '',
        verified_name VARCHAR(190) NOT NULL DEFAULT '',
        requested_display_name VARCHAR(190) NOT NULL DEFAULT '',
        name_status VARCHAR(80) NOT NULL DEFAULT '',
        code_verification_status VARCHAR(80) NOT NULL DEFAULT '',
        registration_status VARCHAR(80) NOT NULL DEFAULT '',
        quality_rating VARCHAR(80) NOT NULL DEFAULT '',
        messaging_limit_tier VARCHAR(80) NOT NULL DEFAULT '',
        platform_type VARCHAR(80) NOT NULL DEFAULT '',
        status VARCHAR(80) NOT NULL DEFAULT '',
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        last_synced_at DATETIME NULL,
        raw_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY phone_number_id (phone_number_id),
        KEY account_id (account_id),
        KEY client_post_id (client_post_id),
        KEY waba_id (waba_id),
        KEY display_phone_number (display_phone_number),
        KEY registration_status (registration_status),
        KEY name_status (name_status)
    ) {$charset};";

    $sql_credentials = "CREATE TABLE {$credentials} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        business_row_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        waba_id VARCHAR(120) NOT NULL DEFAULT '',
        phone_number_id VARCHAR(120) NOT NULL DEFAULT '',
        credential_type VARCHAR(80) NOT NULL DEFAULT 'access_token',
        encrypted_value LONGTEXT NOT NULL,
        token_type VARCHAR(80) NOT NULL DEFAULT '',
        scopes TEXT NULL,
        expires_at DATETIME NULL,
        last_validated_at DATETIME NULL,
        status VARCHAR(80) NOT NULL DEFAULT 'active',
        metadata_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY business_row_id (business_row_id),
        KEY account_id (account_id),
        KEY waba_id (waba_id),
        KEY phone_number_id (phone_number_id),
        KEY credential_type (credential_type),
        KEY status (status)
    ) {$charset};";

    $sql_sessions = "CREATE TABLE {$sessions} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_key VARCHAR(190) NOT NULL DEFAULT '',
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        client_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(80) NOT NULL DEFAULT 'created',
        state_hash VARCHAR(190) NOT NULL DEFAULT '',
        meta_business_id VARCHAR(120) NOT NULL DEFAULT '',
        waba_id VARCHAR(120) NOT NULL DEFAULT '',
        phone_number_id VARCHAR(120) NOT NULL DEFAULT '',
        payload_json LONGTEXT NULL,
        error_message TEXT NULL,
        expires_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY session_key (session_key),
        KEY user_id (user_id),
        KEY client_post_id (client_post_id),
        KEY status (status),
        KEY expires_at (expires_at)
    ) {$charset};";

    $sql_logs = "CREATE TABLE {$logs} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        business_row_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        phone_row_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        action VARCHAR(120) NOT NULL DEFAULT '',
        status VARCHAR(80) NOT NULL DEFAULT '',
        message TEXT NULL,
        context_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY business_row_id (business_row_id),
        KEY account_id (account_id),
        KEY phone_row_id (phone_row_id),
        KEY action (action),
        KEY status (status),
        KEY created_at (created_at)
    ) {$charset};";

    dbDelta($sql_businesses);
    dbDelta($sql_accounts);
    dbDelta($sql_phones);
    dbDelta($sql_credentials);
    dbDelta($sql_sessions);
    dbDelta($sql_logs);

    update_option('eventosapp_wa_operator_db_version', EVENTOSAPP_WA_OPERATOR_DB_VERSION, false);
}

function eventosapp_wa_operator_maybe_install_tables() {
    if ( get_option('eventosapp_wa_operator_db_version') !== EVENTOSAPP_WA_OPERATOR_DB_VERSION ) {
        eventosapp_wa_operator_install_tables();
    }
}
add_action('init', 'eventosapp_wa_operator_maybe_install_tables', 5);
add_action('admin_init', 'eventosapp_wa_operator_maybe_install_tables', 5);

/**
 * Utilidades de persistencia y logs.
 */
function eventosapp_wa_operator_json($value) {
    if ( function_exists('eventosapp_whatsapp_sanitize_log_context') ) {
        $value = eventosapp_whatsapp_sanitize_log_context($value);
    }
    $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '';
}

function eventosapp_wa_operator_decode_json($json) {
    if ( is_array($json) ) {
        return $json;
    }
    $decoded = json_decode((string) $json, true);
    return is_array($decoded) ? $decoded : [];
}

function eventosapp_wa_operator_now() {
    return current_time('mysql');
}

function eventosapp_wa_operator_log($action, $status, $message, $context = [], $ids = []) {
    global $wpdb;

    $table = eventosapp_wa_operator_table('logs');
    if ( $table === '' ) {
        return false;
    }

    $context = is_array($context) ? $context : [];
    foreach ( array_keys($context) as $key ) {
        $key_lc = strtolower((string) $key);
        if ( strpos($key_lc, 'token') !== false || strpos($key_lc, 'secret') !== false || strpos($key_lc, 'authorization') !== false ) {
            $context[$key] = '[redactado]';
        }
    }

    return false !== $wpdb->insert($table, [
        'business_row_id' => absint($ids['business_row_id'] ?? 0),
        'account_id'      => absint($ids['account_id'] ?? 0),
        'phone_row_id'    => absint($ids['phone_row_id'] ?? 0),
        'user_id'         => get_current_user_id(),
        'action'          => sanitize_key((string) $action),
        'status'          => sanitize_key((string) $status),
        'message'         => sanitize_textarea_field((string) $message),
        'context_json'    => eventosapp_wa_operator_json($context),
        'created_at'      => eventosapp_wa_operator_now(),
    ], ['%d','%d','%d','%d','%s','%s','%s','%s','%s']);
}

function eventosapp_wa_operator_client_name($client_post_id) {
    $client_post_id = absint($client_post_id);
    if ( ! $client_post_id ) {
        return 'Sin cliente asignado';
    }
    $name = get_post_meta($client_post_id, '_cliente_nombre_empresa', true);
    if ( ! is_string($name) || trim($name) === '' ) {
        $name = get_the_title($client_post_id);
    }
    return is_string($name) && trim($name) !== '' ? trim($name) : ('Cliente #' . $client_post_id);
}

function eventosapp_wa_operator_get_event_client_id($event_id) {
    $event_id = absint($event_id);
    return $event_id ? absint(get_post_meta($event_id, '_eventosapp_cliente_id', true)) : 0;
}

/**
 * Repositorios.
 */
function eventosapp_wa_operator_get_business_by_meta_id($meta_business_id) {
    global $wpdb;
    $meta_business_id = preg_replace('/\D+/', '', (string) $meta_business_id);
    if ( $meta_business_id === '' ) {
        return null;
    }
    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM " . eventosapp_wa_operator_table('businesses') . " WHERE meta_business_id = %s ORDER BY id DESC LIMIT 1", $meta_business_id),
        ARRAY_A
    );
}

function eventosapp_wa_operator_upsert_business($data) {
    global $wpdb;

    $data = is_array($data) ? $data : [];
    $table = eventosapp_wa_operator_table('businesses');
    $meta_business_id = preg_replace('/\D+/', '', (string)($data['meta_business_id'] ?? ($data['id'] ?? '')));
    $client_post_id = absint($data['client_post_id'] ?? 0);
    $existing = $meta_business_id !== '' ? eventosapp_wa_operator_get_business_by_meta_id($meta_business_id) : null;

    if ( ! $existing && $client_post_id ) {
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE client_post_id = %d ORDER BY id DESC LIMIT 1", $client_post_id),
            ARRAY_A
        );
    }

    $row = [
        'client_post_id'     => $client_post_id,
        'meta_business_id'   => $meta_business_id,
        'name'               => sanitize_text_field((string)($data['name'] ?? ($data['business_name'] ?? eventosapp_wa_operator_client_name($client_post_id)))),
        'verification_status'=> strtoupper(sanitize_text_field((string)($data['verification_status'] ?? ($data['business_verification_status'] ?? '')))),
        'status'             => strtoupper(sanitize_text_field((string)($data['status'] ?? 'ACTIVE'))),
        'raw_json'           => eventosapp_wa_operator_json($data['raw'] ?? $data),
        'updated_at'         => eventosapp_wa_operator_now(),
    ];

    if ( $existing ) {
        $wpdb->update($table, $row, ['id' => absint($existing['id'])]);
        return absint($existing['id']);
    }

    $row['created_at'] = eventosapp_wa_operator_now();
    $wpdb->insert($table, $row);
    return absint($wpdb->insert_id);
}

function eventosapp_wa_operator_get_account_by_waba($waba_id) {
    global $wpdb;
    $waba_id = preg_replace('/\D+/', '', (string) $waba_id);
    if ( $waba_id === '' ) {
        return null;
    }
    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM " . eventosapp_wa_operator_table('accounts') . " WHERE waba_id = %s LIMIT 1", $waba_id),
        ARRAY_A
    );
}

function eventosapp_wa_operator_get_account($account_id) {
    global $wpdb;
    $account_id = absint($account_id);
    if ( ! $account_id ) {
        return null;
    }
    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM " . eventosapp_wa_operator_table('accounts') . " WHERE id = %d LIMIT 1", $account_id),
        ARRAY_A
    );
}

function eventosapp_wa_operator_upsert_account($data) {
    global $wpdb;

    $data = is_array($data) ? $data : [];
    $table = eventosapp_wa_operator_table('accounts');
    $waba_id = preg_replace('/\D+/', '', (string)($data['waba_id'] ?? ($data['id'] ?? '')));
    if ( $waba_id === '' ) {
        return new WP_Error('missing_waba_id', 'No se puede guardar una cuenta de WhatsApp sin WABA ID.');
    }

    $existing = eventosapp_wa_operator_get_account_by_waba($waba_id);
    $client_post_id = absint($data['client_post_id'] ?? ($existing['client_post_id'] ?? 0));
    $business_row_id = absint($data['business_row_id'] ?? ($existing['business_row_id'] ?? 0));
    $meta_business_id = preg_replace('/\D+/', '', (string)($data['meta_business_id'] ?? ($existing['meta_business_id'] ?? '')));

    $row = [
        'business_row_id'             => $business_row_id,
        'client_post_id'              => $client_post_id,
        'meta_business_id'            => $meta_business_id,
        'waba_id'                     => $waba_id,
        'name'                        => sanitize_text_field((string)($data['name'] ?? ($existing['name'] ?? 'Cuenta WhatsApp'))),
        'currency'                    => sanitize_text_field((string)($data['currency'] ?? ($existing['currency'] ?? ''))),
        'timezone_id'                 => sanitize_text_field((string)($data['timezone_id'] ?? ($existing['timezone_id'] ?? ''))),
        'account_status'              => strtoupper(sanitize_text_field((string)($data['account_status'] ?? ($data['status'] ?? ($existing['account_status'] ?? ''))))),
        'review_status'               => strtoupper(sanitize_text_field((string)($data['review_status'] ?? ($data['account_review_status'] ?? ($existing['review_status'] ?? ''))))),
        'business_verification_status'=> strtoupper(sanitize_text_field((string)($data['business_verification_status'] ?? ($existing['business_verification_status'] ?? '')))),
        'system_user_id'              => preg_replace('/\D+/', '', (string)($data['system_user_id'] ?? ($existing['system_user_id'] ?? ''))),
        'system_user_assigned'        => array_key_exists('system_user_assigned', $data)
            ? (! empty($data['system_user_assigned']) ? 1 : 0)
            : absint($existing['system_user_assigned'] ?? 0),
        'webhook_subscribed'          => ! empty($data['webhook_subscribed']) ? 1 : absint($existing['webhook_subscribed'] ?? 0),
        'is_default'                  => ! empty($data['is_default']) ? 1 : absint($existing['is_default'] ?? 0),
        'last_synced_at'              => ! empty($data['last_synced_at']) ? sanitize_text_field((string)$data['last_synced_at']) : ($existing['last_synced_at'] ?? null),
        'raw_json'                    => eventosapp_wa_operator_json($data['raw'] ?? $data),
        'updated_at'                  => eventosapp_wa_operator_now(),
    ];

    if ( $existing ) {
        $wpdb->update($table, $row, ['id' => absint($existing['id'])]);
        return absint($existing['id']);
    }

    $row['created_at'] = eventosapp_wa_operator_now();
    $wpdb->insert($table, $row);
    return absint($wpdb->insert_id);
}

function eventosapp_wa_operator_get_phone_by_phone_number_id($phone_number_id) {
    global $wpdb;
    $phone_number_id = preg_replace('/\D+/', '', (string) $phone_number_id);
    if ( $phone_number_id === '' ) {
        return null;
    }
    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM " . eventosapp_wa_operator_table('phones') . " WHERE phone_number_id = %s LIMIT 1", $phone_number_id),
        ARRAY_A
    );
}

function eventosapp_wa_operator_get_phone($phone_row_id) {
    global $wpdb;
    $phone_row_id = absint($phone_row_id);
    if ( ! $phone_row_id ) {
        return null;
    }
    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM " . eventosapp_wa_operator_table('phones') . " WHERE id = %d LIMIT 1", $phone_row_id),
        ARRAY_A
    );
}

function eventosapp_wa_operator_upsert_phone($data) {
    global $wpdb;

    $data = is_array($data) ? $data : [];
    $table = eventosapp_wa_operator_table('phones');
    $phone_number_id = preg_replace('/\D+/', '', (string)($data['phone_number_id'] ?? ($data['id'] ?? '')));
    if ( $phone_number_id === '' ) {
        return new WP_Error('missing_phone_number_id', 'No se puede guardar un número sin Phone Number ID.');
    }

    $existing = eventosapp_wa_operator_get_phone_by_phone_number_id($phone_number_id);
    $account_id = absint($data['account_id'] ?? ($existing['account_id'] ?? 0));
    $account = $account_id ? eventosapp_wa_operator_get_account($account_id) : null;
    $waba_id = preg_replace('/\D+/', '', (string)($data['waba_id'] ?? ($account['waba_id'] ?? ($existing['waba_id'] ?? ''))));
    $client_post_id = absint($data['client_post_id'] ?? ($account['client_post_id'] ?? ($existing['client_post_id'] ?? 0)));

    $throughput = $data['throughput'] ?? '';
    if ( is_array($throughput) ) {
        $throughput = $throughput['level'] ?? ($throughput['status'] ?? eventosapp_wa_operator_json($throughput));
    }

    $row = [
        'account_id'             => $account_id,
        'client_post_id'         => $client_post_id,
        'waba_id'                => $waba_id,
        'phone_number_id'        => $phone_number_id,
        'display_phone_number'   => sanitize_text_field((string)($data['display_phone_number'] ?? ($existing['display_phone_number'] ?? ''))),
        'verified_name'          => sanitize_text_field((string)($data['verified_name'] ?? ($existing['verified_name'] ?? ''))),
        'requested_display_name' => sanitize_text_field((string)($data['requested_display_name'] ?? ($data['new_display_name'] ?? ($existing['requested_display_name'] ?? '')))),
        'name_status'            => strtoupper(sanitize_text_field((string)($data['name_status'] ?? ($data['new_name_status'] ?? ($existing['name_status'] ?? ''))))),
        'code_verification_status'=> strtoupper(sanitize_text_field((string)($data['code_verification_status'] ?? ($existing['code_verification_status'] ?? '')))),
        'registration_status'    => strtoupper(sanitize_text_field((string)($data['registration_status'] ?? ($data['status'] ?? ($existing['registration_status'] ?? ''))))),
        'quality_rating'         => strtoupper(sanitize_text_field((string)($data['quality_rating'] ?? ($existing['quality_rating'] ?? '')))),
        'messaging_limit_tier'   => strtoupper(sanitize_text_field((string)($data['messaging_limit_tier'] ?? ($data['current_limit'] ?? ($throughput ?: ($existing['messaging_limit_tier'] ?? '')))))),
        'platform_type'          => strtoupper(sanitize_text_field((string)($data['platform_type'] ?? ($existing['platform_type'] ?? '')))),
        'status'                 => strtoupper(sanitize_text_field((string)($data['status'] ?? ($existing['status'] ?? '')))),
        'is_default'             => ! empty($data['is_default']) ? 1 : absint($existing['is_default'] ?? 0),
        'last_synced_at'         => ! empty($data['last_synced_at']) ? sanitize_text_field((string)$data['last_synced_at']) : ($existing['last_synced_at'] ?? null),
        'raw_json'               => eventosapp_wa_operator_json($data['raw'] ?? $data),
        'updated_at'             => eventosapp_wa_operator_now(),
    ];

    if ( $existing ) {
        $wpdb->update($table, $row, ['id' => absint($existing['id'])]);
        return absint($existing['id']);
    }

    $row['created_at'] = eventosapp_wa_operator_now();
    $wpdb->insert($table, $row);
    return absint($wpdb->insert_id);
}

function eventosapp_wa_operator_get_accounts($args = []) {
    global $wpdb;
    $args = wp_parse_args(is_array($args) ? $args : [], [
        'client_post_id' => 0,
        'status'         => '',
        'limit'          => 500,
    ]);

    $where = ['1=1'];
    $values = [];
    if ( absint($args['client_post_id']) ) {
        $where[] = 'client_post_id = %d';
        $values[] = absint($args['client_post_id']);
    }
    if ( trim((string)$args['status']) !== '' ) {
        $where[] = 'account_status = %s';
        $values[] = strtoupper(sanitize_text_field((string)$args['status']));
    }
    $limit = min(1000, max(1, absint($args['limit'])));

    $sql = "SELECT * FROM " . eventosapp_wa_operator_table('accounts') . " WHERE " . implode(' AND ', $where) . " ORDER BY is_default DESC, name ASC, id DESC LIMIT {$limit}";
    if ( $values ) {
        $sql = $wpdb->prepare($sql, $values);
    }
    return $wpdb->get_results($sql, ARRAY_A);
}

function eventosapp_wa_operator_get_phones($args = []) {
    global $wpdb;
    $args = wp_parse_args(is_array($args) ? $args : [], [
        'account_id'    => 0,
        'client_post_id'=> 0,
        'waba_id'       => '',
        'limit'         => 1000,
    ]);

    $where = ['1=1'];
    $values = [];
    if ( absint($args['account_id']) ) {
        $where[] = 'account_id = %d';
        $values[] = absint($args['account_id']);
    }
    if ( absint($args['client_post_id']) ) {
        $where[] = 'client_post_id = %d';
        $values[] = absint($args['client_post_id']);
    }
    $waba_id = preg_replace('/\D+/', '', (string)$args['waba_id']);
    if ( $waba_id !== '' ) {
        $where[] = 'waba_id = %s';
        $values[] = $waba_id;
    }
    $limit = min(2000, max(1, absint($args['limit'])));

    $sql = "SELECT * FROM " . eventosapp_wa_operator_table('phones') . " WHERE " . implode(' AND ', $where) . " ORDER BY is_default DESC, verified_name ASC, display_phone_number ASC, id DESC LIMIT {$limit}";
    if ( $values ) {
        $sql = $wpdb->prepare($sql, $values);
    }
    return $wpdb->get_results($sql, ARRAY_A);
}

/**
 * Credenciales cifradas.
 */
function eventosapp_wa_operator_store_access_token($account_id, $token, $metadata = []) {
    global $wpdb;

    $account_id = absint($account_id);
    $token = trim((string) $token);
    $metadata = is_array($metadata) ? $metadata : [];

    if ( ! $account_id || $token === '' ) {
        return new WP_Error('invalid_credential', 'Falta la cuenta o el token que se debe guardar.');
    }

    $account = eventosapp_wa_operator_get_account($account_id);
    if ( ! $account ) {
        return new WP_Error('account_not_found', 'La cuenta WhatsApp no existe en EventosApp.');
    }

    $encrypted = eventosapp_wa_operator_encrypt_secret($token);
    if ( is_wp_error($encrypted) ) {
        return $encrypted;
    }

    $table = eventosapp_wa_operator_table('credentials');
    $existing = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table} WHERE account_id = %d AND credential_type = %s ORDER BY id DESC LIMIT 1", $account_id, 'access_token'),
        ARRAY_A
    );

    $expires_at = '';
    if ( ! empty($metadata['expires_at']) ) {
        $expires_at = sanitize_text_field((string)$metadata['expires_at']);
    } elseif ( ! empty($metadata['expires_at_timestamp']) ) {
        $expires_at = get_date_from_gmt(gmdate('Y-m-d H:i:s', absint($metadata['expires_at_timestamp'])), 'Y-m-d H:i:s');
    } elseif ( ! empty($metadata['expires_in']) ) {
        $expires_at = get_date_from_gmt(gmdate('Y-m-d H:i:s', time() + absint($metadata['expires_in'])), 'Y-m-d H:i:s');
    }

    $scopes = $metadata['scopes'] ?? [];
    if ( is_array($scopes) ) {
        $scopes = implode(',', array_map('sanitize_key', $scopes));
    }

    $row = [
        'business_row_id' => absint($account['business_row_id'] ?? 0),
        'account_id'      => $account_id,
        'waba_id'         => preg_replace('/\D+/', '', (string)($account['waba_id'] ?? '')),
        'phone_number_id' => preg_replace('/\D+/', '', (string)($metadata['phone_number_id'] ?? '')),
        'credential_type' => 'access_token',
        'encrypted_value' => $encrypted,
        'token_type'      => sanitize_text_field((string)($metadata['token_type'] ?? 'bearer')),
        'scopes'          => sanitize_textarea_field((string)$scopes),
        'expires_at'      => $expires_at !== '' ? $expires_at : null,
        'last_validated_at'=> ! empty($metadata['last_validated_at']) ? sanitize_text_field((string)$metadata['last_validated_at']) : null,
        'status'          => sanitize_key((string)($metadata['status'] ?? 'active')),
        'metadata_json'   => eventosapp_wa_operator_json($metadata),
        'updated_at'      => eventosapp_wa_operator_now(),
    ];

    if ( $existing ) {
        $wpdb->update($table, $row, ['id' => absint($existing['id'])]);
        return absint($existing['id']);
    }

    $row['created_at'] = eventosapp_wa_operator_now();
    $wpdb->insert($table, $row);
    return absint($wpdb->insert_id);
}

function eventosapp_wa_operator_get_credential_for_account($account_id, $include_secret = false) {
    global $wpdb;

    $account_id = absint($account_id);
    if ( ! $account_id ) {
        return null;
    }

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM " . eventosapp_wa_operator_table('credentials') . " WHERE account_id = %d AND credential_type = %s AND status <> %s ORDER BY id DESC LIMIT 1",
            $account_id,
            'access_token',
            'revoked'
        ),
        ARRAY_A
    );

    if ( $row && $include_secret ) {
        $row['access_token'] = eventosapp_wa_operator_decrypt_secret($row['encrypted_value'] ?? '');
    }
    if ( $row ) {
        unset($row['encrypted_value']);
    }
    return $row;
}

function eventosapp_wa_operator_get_access_token_for_account($account_id) {
    global $wpdb;

    $account_id = absint($account_id);
    if ( ! $account_id ) {
        return '';
    }

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT encrypted_value FROM " . eventosapp_wa_operator_table('credentials') . " WHERE account_id = %d AND credential_type = %s AND status = %s ORDER BY id DESC LIMIT 1",
            $account_id,
            'access_token',
            'active'
        ),
        ARRAY_A
    );
    return $row ? eventosapp_wa_operator_decrypt_secret($row['encrypted_value'] ?? '') : '';
}

function eventosapp_wa_operator_get_operational_token_for_account($account_id) {
    $account = eventosapp_wa_operator_get_account($account_id);
    if ( ! $account ) {
        return '';
    }

    if ( ! empty($account['system_user_assigned']) ) {
        $system_token = eventosapp_wa_operator_get_system_user_token();
        if ( $system_token !== '' ) {
            return $system_token;
        }
    }

    return eventosapp_wa_operator_get_access_token_for_account($account_id);
}

function eventosapp_wa_operator_get_any_access_token() {
    global $wpdb;

    $system_token = eventosapp_wa_operator_get_system_user_token();
    if ( $system_token !== '' ) {
        return $system_token;
    }

    $row = $wpdb->get_row(
        "SELECT encrypted_value FROM " . eventosapp_wa_operator_table('credentials') . " WHERE credential_type = 'access_token' AND status = 'active' ORDER BY id DESC LIMIT 1",
        ARRAY_A
    );
    return $row ? eventosapp_wa_operator_decrypt_secret($row['encrypted_value'] ?? '') : '';
}

function eventosapp_wa_operator_revoke_account_credentials($account_id, $reason = '') {
    global $wpdb;
    $account_id = absint($account_id);
    if ( ! $account_id ) {
        return false;
    }

    $updated = $wpdb->update(
        eventosapp_wa_operator_table('credentials'),
        [
            'status' => 'revoked',
            'updated_at' => eventosapp_wa_operator_now(),
            'metadata_json' => eventosapp_wa_operator_json(['reason' => sanitize_text_field((string)$reason)]),
        ],
        ['account_id' => $account_id, 'credential_type' => 'access_token']
    );

    if ( $updated !== false ) {
        $wpdb->update(
            eventosapp_wa_operator_table('accounts'),
            [
                'account_status' => 'DISCONNECTED',
                'updated_at' => eventosapp_wa_operator_now(),
            ],
            ['id' => $account_id]
        );
    }

    return $updated !== false;
}

/**
 * Cliente Graph API del operador.
 */
function eventosapp_wa_operator_extract_graph_error($decoded, $raw, $code) {
    if ( is_array($decoded) && ! empty($decoded['error']) && is_array($decoded['error']) ) {
        $error = $decoded['error'];
        $parts = [];
        foreach ( ['message', 'type', 'code', 'error_subcode', 'fbtrace_id'] as $key ) {
            if ( isset($error[$key]) && is_scalar($error[$key]) && (string)$error[$key] !== '' ) {
                $parts[] = $key . ': ' . (string)$error[$key];
            }
        }
        return implode(' · ', $parts);
    }
    $raw = trim(wp_strip_all_tags((string)$raw));
    return $raw !== '' ? $raw : ('Meta respondió HTTP ' . absint($code));
}

function eventosapp_wa_operator_graph_request($method, $path, $body = null, $query = [], $access_token = '', $api_version = '') {
    $method = strtoupper((string) $method);
    $settings = eventosapp_wa_operator_get_settings();
    $api_version = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)($api_version ?: $settings['api_version']));
    if ( $api_version === '' ) {
        $api_version = 'v23.0';
    }

    $path = ltrim((string) $path, '/');
    if ( $path === '' ) {
        return ['ok'=>false, 'http_code'=>0, 'message'=>'Ruta Graph API vacía.', 'response'=>null];
    }

    $endpoint = sprintf('https://graph.facebook.com/%s/%s', rawurlencode($api_version), $path);
    if ( is_array($query) && $query ) {
        $endpoint = add_query_arg($query, $endpoint);
    }

    $args = [
        'method'  => $method,
        'timeout' => 45,
        'headers' => [
            'Accept' => 'application/json',
        ],
    ];
    if ( trim((string)$access_token) !== '' ) {
        $args['headers']['Authorization'] = 'Bearer ' . trim((string)$access_token);
    }
    if ( $body !== null ) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $response = wp_remote_request($endpoint, $args);
    if ( is_wp_error($response) ) {
        return ['ok'=>false, 'http_code'=>0, 'message'=>$response->get_error_message(), 'response'=>null, 'endpoint'=>$endpoint];
    }

    $code = absint(wp_remote_retrieve_response_code($response));
    $raw = (string)wp_remote_retrieve_body($response);
    $decoded = json_decode($raw, true);
    $ok = $code >= 200 && $code < 300;

    return [
        'ok'        => $ok,
        'http_code' => $code,
        'message'   => $ok ? 'Solicitud aceptada por Meta.' : eventosapp_wa_operator_extract_graph_error($decoded, $raw, $code),
        'response'  => is_array($decoded) ? $decoded : $raw,
        'endpoint'  => $endpoint,
    ];
}

function eventosapp_wa_operator_exchange_code($code) {
    $code = trim((string) $code);
    $settings = eventosapp_wa_operator_get_settings();
    $app_id = preg_replace('/\D+/', '', (string)$settings['app_id']);
    $app_secret = eventosapp_wa_operator_get_app_secret();

    if ( $code === '' || $app_id === '' || $app_secret === '' ) {
        return new WP_Error('missing_oauth_data', 'Faltan el código, App ID o App Secret para completar Embedded Signup.');
    }

    $result = eventosapp_wa_operator_graph_request('GET', 'oauth/access_token', null, [
        'client_id'     => $app_id,
        'client_secret' => $app_secret,
        'code'          => $code,
    ]);

    if ( empty($result['ok']) || ! is_array($result['response']) || empty($result['response']['access_token']) ) {
        return new WP_Error('token_exchange_failed', $result['message'] ?? 'Meta no entregó un access token.');
    }

    $token_data = $result['response'];
    $token = trim((string)$token_data['access_token']);

    // Intenta convertirlo a token de mayor duración. Si Meta no permite el
    // intercambio, conserva el token original obtenido correctamente.
    $long_result = eventosapp_wa_operator_graph_request('GET', 'oauth/access_token', null, [
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => $app_id,
        'client_secret'     => $app_secret,
        'fb_exchange_token' => $token,
    ]);
    if ( ! empty($long_result['ok']) && is_array($long_result['response']) && ! empty($long_result['response']['access_token']) ) {
        $token_data = array_merge($token_data, $long_result['response']);
        $token = trim((string)$long_result['response']['access_token']);
        $token_data['_long_lived_exchange'] = true;
    } else {
        $token_data['_long_lived_exchange'] = false;
        $token_data['_long_lived_error'] = $long_result['message'] ?? '';
    }

    $token_data['access_token'] = $token;
    return $token_data;
}

function eventosapp_wa_operator_debug_token($token) {
    $token = trim((string) $token);
    $settings = eventosapp_wa_operator_get_settings();
    $app_id = preg_replace('/\D+/', '', (string)$settings['app_id']);
    $app_secret = eventosapp_wa_operator_get_app_secret();

    if ( $token === '' || $app_id === '' || $app_secret === '' ) {
        return new WP_Error('missing_debug_data', 'Faltan el token o las credenciales de la aplicación para validarlo.');
    }

    $result = eventosapp_wa_operator_graph_request('GET', 'debug_token', null, [
        'input_token'  => $token,
        'access_token' => $app_id . '|' . $app_secret,
    ]);

    if ( empty($result['ok']) || ! is_array($result['response']) || empty($result['response']['data']) ) {
        return new WP_Error('debug_token_failed', $result['message'] ?? 'No se pudo validar el token.');
    }
    return $result['response']['data'];
}

/**
 * Descubrimiento de activos compartidos por Embedded Signup.
 */
function eventosapp_wa_operator_extract_asset_ids_from_debug($debug) {
    $debug = is_array($debug) ? $debug : [];
    $out = [
        'business_ids' => [],
        'waba_ids'     => [],
        'phone_number_ids' => [],
        'scopes'       => [],
    ];

    foreach ( (array)($debug['scopes'] ?? []) as $scope ) {
        $scope = sanitize_key((string)$scope);
        if ( $scope !== '' ) {
            $out['scopes'][] = $scope;
        }
    }

    $granular = $debug['granular_scopes'] ?? [];
    if ( is_array($granular) ) {
        foreach ( $granular as $item ) {
            if ( ! is_array($item) ) {
                continue;
            }
            $scope = sanitize_key((string)($item['scope'] ?? ''));
            foreach ( (array)($item['target_ids'] ?? []) as $target_id ) {
                $target_id = preg_replace('/\D+/', '', (string)$target_id);
                if ( $target_id === '' ) {
                    continue;
                }
                if ( strpos($scope, 'whatsapp_business') !== false ) {
                    $out['waba_ids'][] = $target_id;
                } elseif ( $scope === 'business_management' ) {
                    $out['business_ids'][] = $target_id;
                }
            }
        }
    }

    foreach ( ['business_id', 'target_id'] as $key ) {
        $value = preg_replace('/\D+/', '', (string)($debug[$key] ?? ''));
        if ( $value !== '' ) {
            $out['business_ids'][] = $value;
        }
    }

    foreach ( $out as $key => $values ) {
        if ( is_array($values) ) {
            $out[$key] = array_values(array_unique(array_filter($values)));
        }
    }
    return $out;
}

function eventosapp_wa_operator_get_wabas_for_business($business_id, $token) {
    $business_id = preg_replace('/\D+/', '', (string) $business_id);
    if ( $business_id === '' ) {
        return [];
    }

    $paths = [
        $business_id . '/client_whatsapp_business_accounts',
        $business_id . '/owned_whatsapp_business_accounts',
    ];

    $items = [];
    foreach ( $paths as $path ) {
        $result = eventosapp_wa_operator_graph_request('GET', $path, null, [
            'fields' => 'id,name,currency,timezone_id,message_template_namespace,account_review_status,business_verification_status',
            'limit'  => 100,
        ], $token);
        if ( ! empty($result['ok']) && is_array($result['response']) ) {
            foreach ( (array)($result['response']['data'] ?? []) as $row ) {
                if ( is_array($row) && ! empty($row['id']) ) {
                    $items[preg_replace('/\D+/', '', (string)$row['id'])] = $row;
                }
            }
        }
    }
    return array_values($items);
}

/**
 * Asignación opcional del System User del proveedor a una WABA.
 */
function eventosapp_wa_operator_system_user_ready() {
    $settings = eventosapp_wa_operator_get_settings();
    return ! empty($settings['provider_business_id'])
        && ! empty($settings['system_user_id'])
        && eventosapp_wa_operator_get_system_user_token() !== '';
}

function eventosapp_wa_operator_check_system_user_assignment($waba_id) {
    global $wpdb;

    $account = eventosapp_wa_operator_get_account_by_waba($waba_id);
    if ( ! $account ) {
        return new WP_Error('account_not_found', 'No se encontró la WABA en EventosApp.');
    }
    if ( ! eventosapp_wa_operator_system_user_ready() ) {
        return new WP_Error('system_user_not_ready', 'Faltan Business ID del proveedor, System User ID o su token administrativo.');
    }

    $settings = eventosapp_wa_operator_get_settings();
    $token = eventosapp_wa_operator_get_system_user_token();
    $result = eventosapp_wa_operator_graph_request(
        'GET',
        $account['waba_id'] . '/assigned_users',
        null,
        ['business'=>$settings['provider_business_id']],
        $token
    );

    if ( ! empty($result['ok']) && is_array($result['response']) ) {
        $assigned = false;
        foreach ( (array)($result['response']['data'] ?? []) as $user ) {
            $candidate_id = preg_replace('/\D+/', '', (string)($user['id'] ?? ''));
            if ( $candidate_id !== '' && hash_equals((string)$settings['system_user_id'], $candidate_id) ) {
                $assigned = true;
                break;
            }
        }
        $wpdb->update(eventosapp_wa_operator_table('accounts'), [
            'system_user_id' => $settings['system_user_id'],
            'system_user_assigned' => $assigned ? 1 : 0,
            'updated_at' => eventosapp_wa_operator_now(),
        ], ['id'=>absint($account['id'])]);
        $result['assigned'] = $assigned;
    }

    return $result;
}

function eventosapp_wa_operator_assign_system_user($waba_id) {
    global $wpdb;

    $account = eventosapp_wa_operator_get_account_by_waba($waba_id);
    if ( ! $account ) {
        return new WP_Error('account_not_found', 'No se encontró la WABA en EventosApp.');
    }
    if ( ! eventosapp_wa_operator_system_user_ready() ) {
        return new WP_Error('system_user_not_ready', 'Faltan Business ID del proveedor, System User ID o su token administrativo.');
    }

    $settings = eventosapp_wa_operator_get_settings();
    $token = eventosapp_wa_operator_get_system_user_token();
    $result = eventosapp_wa_operator_graph_request(
        'POST',
        $account['waba_id'] . '/assigned_users',
        null,
        [
            'user' => $settings['system_user_id'],
            'tasks' => wp_json_encode(['MANAGE','DEVELOP']),
        ],
        $token
    );

    if ( ! empty($result['ok']) ) {
        $wpdb->update(eventosapp_wa_operator_table('accounts'), [
            'system_user_id' => $settings['system_user_id'],
            'system_user_assigned' => 1,
            'updated_at' => eventosapp_wa_operator_now(),
        ], ['id'=>absint($account['id'])]);
    }

    eventosapp_wa_operator_log('assign_system_user', ! empty($result['ok']) ? 'success' : 'error', $result['message'] ?? '', [
        'waba_id'=>$account['waba_id'],
        'system_user_id'=>$settings['system_user_id'],
        'http_code'=>$result['http_code'] ?? 0,
    ], ['account_id'=>$account['id']]);

    return $result;
}

/**
 * Sincronización de WABA y números.
 */
function eventosapp_wa_operator_get_access_token_for_waba($waba_id) {
    $account = eventosapp_wa_operator_get_account_by_waba($waba_id);
    return $account ? eventosapp_wa_operator_get_operational_token_for_account($account['id']) : '';
}

function eventosapp_wa_operator_get_access_token_for_phone($phone_number_id) {
    $phone = eventosapp_wa_operator_get_phone_by_phone_number_id($phone_number_id);
    return $phone ? eventosapp_wa_operator_get_operational_token_for_account($phone['account_id']) : '';
}

function eventosapp_wa_operator_fetch_account_details($waba_id, $token) {
    $field_groups = [
        'id,name,currency,timezone_id,message_template_namespace,account_review_status,business_verification_status',
        'id,name,currency,timezone_id,account_review_status',
        'id,name',
    ];
    $errors = [];
    foreach ( $field_groups as $fields ) {
        $result = eventosapp_wa_operator_graph_request('GET', $waba_id, null, ['fields' => $fields], $token);
        if ( ! empty($result['ok']) && is_array($result['response']) ) {
            return ['ok'=>true, 'data'=>$result['response'], 'errors'=>$errors];
        }
        $errors[] = $result['message'] ?? 'Error desconocido';
    }
    return ['ok'=>false, 'data'=>[], 'errors'=>$errors];
}

function eventosapp_wa_operator_fetch_phone_details($phone_number_id, $token) {
    $field_groups = [
        'id,display_phone_number,verified_name,quality_rating,code_verification_status,name_status,new_display_name,new_name_status,status,platform_type,throughput',
        'id,display_phone_number,verified_name,quality_rating,code_verification_status,name_status,status,platform_type',
        'id,display_phone_number,verified_name,quality_rating',
        'id,display_phone_number,verified_name',
    ];
    $errors = [];
    foreach ( $field_groups as $fields ) {
        $result = eventosapp_wa_operator_graph_request('GET', $phone_number_id, null, ['fields' => $fields], $token);
        if ( ! empty($result['ok']) && is_array($result['response']) ) {
            return ['ok'=>true, 'data'=>$result['response'], 'errors'=>$errors];
        }
        $errors[] = $result['message'] ?? 'Error desconocido';
    }
    return ['ok'=>false, 'data'=>[], 'errors'=>$errors];
}

function eventosapp_wa_operator_sync_phone($phone_number_id, $account_id = 0, $token = '') {
    $phone_number_id = preg_replace('/\D+/', '', (string)$phone_number_id);
    $existing = eventosapp_wa_operator_get_phone_by_phone_number_id($phone_number_id);
    $account_id = absint($account_id ?: ($existing['account_id'] ?? 0));
    $account = $account_id ? eventosapp_wa_operator_get_account($account_id) : null;
    if ( $phone_number_id === '' || ! $account ) {
        return new WP_Error('phone_sync_missing_data', 'Faltan el Phone Number ID o la cuenta asociada.');
    }

    $token = trim((string)$token);
    if ( $token === '' ) {
        $token = eventosapp_wa_operator_get_operational_token_for_account($account_id);
    }
    if ( $token === '' ) {
        return new WP_Error('missing_access_token', 'La cuenta no tiene un token activo.');
    }

    $fetched = eventosapp_wa_operator_fetch_phone_details($phone_number_id, $token);
    if ( empty($fetched['ok']) ) {
        return new WP_Error('phone_sync_failed', implode(' | ', array_unique($fetched['errors'])));
    }

    $data = $fetched['data'];
    $data['phone_number_id'] = $phone_number_id;
    $data['account_id'] = $account_id;
    $data['waba_id'] = $account['waba_id'];
    $data['client_post_id'] = $account['client_post_id'];
    $data['last_synced_at'] = eventosapp_wa_operator_now();
    $data['raw'] = $fetched['data'];
    $phone_row_id = eventosapp_wa_operator_upsert_phone($data);

    eventosapp_wa_operator_log('sync_phone', 'success', 'Número sincronizado con Meta.', [
        'phone_number_id' => $phone_number_id,
    ], ['account_id'=>$account_id, 'phone_row_id'=>is_wp_error($phone_row_id) ? 0 : $phone_row_id]);

    return is_wp_error($phone_row_id) ? $phone_row_id : eventosapp_wa_operator_get_phone($phone_row_id);
}

function eventosapp_wa_operator_sync_waba($waba_id, $account_id = 0, $token = '', $import_phones = true) {
    $waba_id = preg_replace('/\D+/', '', (string)$waba_id);
    $account = $account_id ? eventosapp_wa_operator_get_account($account_id) : eventosapp_wa_operator_get_account_by_waba($waba_id);
    if ( $waba_id === '' || ! $account ) {
        return new WP_Error('waba_sync_missing_data', 'No se encontró la WABA que se debe sincronizar.');
    }

    $token = trim((string)$token);
    if ( $token === '' ) {
        $token = eventosapp_wa_operator_get_operational_token_for_account($account['id']);
    }
    if ( $token === '' ) {
        return new WP_Error('missing_access_token', 'La cuenta no tiene un token activo.');
    }

    $details = eventosapp_wa_operator_fetch_account_details($waba_id, $token);
    if ( ! empty($details['ok']) ) {
        $account_data = $details['data'];
        $account_data['waba_id'] = $waba_id;
        $account_data['business_row_id'] = $account['business_row_id'];
        $account_data['client_post_id'] = $account['client_post_id'];
        $account_data['meta_business_id'] = $account['meta_business_id'];
        $account_data['last_synced_at'] = eventosapp_wa_operator_now();
        $account_data['raw'] = $details['data'];
        eventosapp_wa_operator_upsert_account($account_data);
        $account = eventosapp_wa_operator_get_account_by_waba($waba_id);
    }

    $phones = [];
    $list_result = [
        'ok' => true,
        'http_code' => 0,
        'message' => 'Importación de números omitida por configuración.',
        'response' => ['data'=>[]],
    ];

    if ( $import_phones ) {
        $list_result = eventosapp_wa_operator_graph_request('GET', $waba_id . '/phone_numbers', null, [
            'fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status,name_status,status,platform_type',
            'limit'  => 100,
        ], $token);
    }

    if ( ! empty($list_result['ok']) && is_array($list_result['response']) ) {
        foreach ( (array)($list_result['response']['data'] ?? []) as $phone_data ) {
            if ( ! is_array($phone_data) || empty($phone_data['id']) ) {
                continue;
            }
            $phone_data['phone_number_id'] = preg_replace('/\D+/', '', (string)$phone_data['id']);
            $phone_data['account_id'] = absint($account['id']);
            $phone_data['waba_id'] = $waba_id;
            $phone_data['client_post_id'] = absint($account['client_post_id']);
            $phone_data['last_synced_at'] = eventosapp_wa_operator_now();
            $phone_data['raw'] = $phone_data;
            $phone_row_id = eventosapp_wa_operator_upsert_phone($phone_data);
            if ( ! is_wp_error($phone_row_id) ) {
                $phones[] = eventosapp_wa_operator_get_phone($phone_row_id);
            }
        }
    } else {
        eventosapp_wa_operator_log('sync_waba', 'partial', 'La WABA se consultó, pero no fue posible listar sus números.', [
            'waba_id' => $waba_id,
            'error'   => $list_result['message'] ?? '',
        ], ['account_id'=>$account['id']]);
    }

    eventosapp_wa_operator_log('sync_waba', 'success', 'Cuenta WhatsApp sincronizada.', [
        'waba_id' => $waba_id,
        'phones_found' => count($phones),
        'account_details_errors' => $details['errors'] ?? [],
    ], ['account_id'=>$account['id']]);

    return [
        'account' => eventosapp_wa_operator_get_account_by_waba($waba_id),
        'phones'  => $phones,
        'phone_list_result' => $list_result,
    ];
}

function eventosapp_wa_operator_subscribe_waba($waba_id) {
    global $wpdb;

    $account = eventosapp_wa_operator_get_account_by_waba($waba_id);
    if ( ! $account ) {
        return new WP_Error('account_not_found', 'No se encontró la WABA en EventosApp.');
    }
    $token = eventosapp_wa_operator_get_operational_token_for_account($account['id']);
    if ( $token === '' ) {
        return new WP_Error('missing_access_token', 'La cuenta no tiene un token activo.');
    }

    $result = eventosapp_wa_operator_graph_request('POST', $account['waba_id'] . '/subscribed_apps', null, [], $token);
    if ( ! empty($result['ok']) ) {
        $wpdb->update(eventosapp_wa_operator_table('accounts'), [
            'webhook_subscribed' => 1,
            'updated_at' => eventosapp_wa_operator_now(),
        ], ['id' => absint($account['id'])]);
    }

    eventosapp_wa_operator_log('subscribe_waba', ! empty($result['ok']) ? 'success' : 'error', $result['message'] ?? '', [
        'waba_id' => $account['waba_id'],
        'http_code' => $result['http_code'] ?? 0,
    ], ['account_id'=>$account['id']]);

    return $result;
}

function eventosapp_wa_operator_check_waba_subscription($waba_id) {
    global $wpdb;

    $account = eventosapp_wa_operator_get_account_by_waba($waba_id);
    if ( ! $account ) {
        return new WP_Error('account_not_found', 'No se encontró la WABA en EventosApp.');
    }
    $token = eventosapp_wa_operator_get_operational_token_for_account($account['id']);
    if ( $token === '' ) {
        return new WP_Error('missing_access_token', 'La cuenta no tiene un token activo.');
    }

    $result = eventosapp_wa_operator_graph_request('GET', $account['waba_id'] . '/subscribed_apps', null, [], $token);
    if ( ! empty($result['ok']) && is_array($result['response']) ) {
        $subscribed = ! empty($result['response']['data']);
        $wpdb->update(eventosapp_wa_operator_table('accounts'), [
            'webhook_subscribed' => $subscribed ? 1 : 0,
            'updated_at' => eventosapp_wa_operator_now(),
        ], ['id' => absint($account['id'])]);
        $result['subscribed'] = $subscribed;
    }
    return $result;
}

/**
 * Operaciones de número.
 */
function eventosapp_wa_operator_request_verification_code($phone_number_id, $method = 'SMS', $language = 'es_CO') {
    $phone = eventosapp_wa_operator_get_phone_by_phone_number_id($phone_number_id);
    if ( ! $phone ) {
        return new WP_Error('phone_not_found', 'El Phone Number ID no está administrado por el operador.');
    }
    $token = eventosapp_wa_operator_get_operational_token_for_account($phone['account_id']);
    if ( $token === '' ) {
        return new WP_Error('missing_access_token', 'La cuenta no tiene un token activo.');
    }

    $method = strtoupper(sanitize_key((string)$method));
    $method = $method === 'VOICE' ? 'VOICE' : 'SMS';
    $language = preg_replace('/[^a-zA-Z_]/', '', (string)$language);
    if ( $language === '' ) {
        $language = 'es_CO';
    }

    $result = eventosapp_wa_operator_graph_request('POST', $phone['phone_number_id'] . '/request_code', [
        'code_method' => $method,
        'locale'      => $language,
    ], [], $token);

    eventosapp_wa_operator_log('request_code', ! empty($result['ok']) ? 'success' : 'error', $result['message'] ?? '', [
        'phone_number_id' => $phone['phone_number_id'],
        'code_method' => $method,
        'locale' => $language,
        'http_code' => $result['http_code'] ?? 0,
    ], ['account_id'=>$phone['account_id'], 'phone_row_id'=>$phone['id']]);

    return $result;
}

function eventosapp_wa_operator_verify_code($phone_number_id, $code) {
    global $wpdb;

    $phone = eventosapp_wa_operator_get_phone_by_phone_number_id($phone_number_id);
    if ( ! $phone ) {
        return new WP_Error('phone_not_found', 'El Phone Number ID no está administrado por el operador.');
    }
    $token = eventosapp_wa_operator_get_operational_token_for_account($phone['account_id']);
    $code = preg_replace('/\D+/', '', (string)$code);
    if ( $token === '' || $code === '' ) {
        return new WP_Error('missing_verify_data', 'Faltan el token o el código de verificación.');
    }

    $result = eventosapp_wa_operator_graph_request('POST', $phone['phone_number_id'] . '/verify_code', [
        'code' => $code,
    ], [], $token);

    if ( ! empty($result['ok']) ) {
        $wpdb->update(eventosapp_wa_operator_table('phones'), [
            'code_verification_status' => 'VERIFIED',
            'updated_at' => eventosapp_wa_operator_now(),
        ], ['id' => absint($phone['id'])]);
    }

    eventosapp_wa_operator_log('verify_code', ! empty($result['ok']) ? 'success' : 'error', $result['message'] ?? '', [
        'phone_number_id' => $phone['phone_number_id'],
        'http_code' => $result['http_code'] ?? 0,
    ], ['account_id'=>$phone['account_id'], 'phone_row_id'=>$phone['id']]);

    return $result;
}

function eventosapp_wa_operator_register_phone($phone_number_id, $pin) {
    global $wpdb;

    $phone = eventosapp_wa_operator_get_phone_by_phone_number_id($phone_number_id);
    if ( ! $phone ) {
        return new WP_Error('phone_not_found', 'El Phone Number ID no está administrado por el operador.');
    }
    $token = eventosapp_wa_operator_get_operational_token_for_account($phone['account_id']);
    $pin = preg_replace('/\D+/', '', (string)$pin);
    if ( $token === '' || strlen($pin) !== 6 ) {
        return new WP_Error('invalid_register_data', 'Se requiere un PIN de verificación en dos pasos de seis dígitos.');
    }

    $result = eventosapp_wa_operator_graph_request('POST', $phone['phone_number_id'] . '/register', [
        'messaging_product' => 'whatsapp',
        'pin'               => $pin,
    ], [], $token);

    if ( ! empty($result['ok']) ) {
        $wpdb->update(eventosapp_wa_operator_table('phones'), [
            'registration_status' => 'REGISTERED',
            'status' => 'CONNECTED',
            'updated_at' => eventosapp_wa_operator_now(),
        ], ['id' => absint($phone['id'])]);
    }

    eventosapp_wa_operator_log('register_phone', ! empty($result['ok']) ? 'success' : 'error', $result['message'] ?? '', [
        'phone_number_id' => $phone['phone_number_id'],
        'http_code' => $result['http_code'] ?? 0,
    ], ['account_id'=>$phone['account_id'], 'phone_row_id'=>$phone['id']]);

    return $result;
}

function eventosapp_wa_operator_deregister_phone($phone_number_id) {
    global $wpdb;

    $phone = eventosapp_wa_operator_get_phone_by_phone_number_id($phone_number_id);
    if ( ! $phone ) {
        return new WP_Error('phone_not_found', 'El Phone Number ID no está administrado por el operador.');
    }
    $token = eventosapp_wa_operator_get_operational_token_for_account($phone['account_id']);
    if ( $token === '' ) {
        return new WP_Error('missing_access_token', 'La cuenta no tiene un token activo.');
    }

    $result = eventosapp_wa_operator_graph_request('POST', $phone['phone_number_id'] . '/deregister', null, [], $token);
    if ( ! empty($result['ok']) ) {
        $wpdb->update(eventosapp_wa_operator_table('phones'), [
            'registration_status' => 'DEREGISTERED',
            'status' => 'DISCONNECTED',
            'updated_at' => eventosapp_wa_operator_now(),
        ], ['id' => absint($phone['id'])]);
    }

    eventosapp_wa_operator_log('deregister_phone', ! empty($result['ok']) ? 'success' : 'error', $result['message'] ?? '', [
        'phone_number_id' => $phone['phone_number_id'],
        'http_code' => $result['http_code'] ?? 0,
    ], ['account_id'=>$phone['account_id'], 'phone_row_id'=>$phone['id']]);

    return $result;
}

/**
 * Integración transparente con la configuración histórica.
 */
function eventosapp_wa_operator_phone_accounts_filter($accounts, $legacy_settings = []) {
    $accounts = is_array($accounts) ? $accounts : [];
    $operator_settings = eventosapp_wa_operator_get_settings();
    if ( empty($operator_settings['enabled']) || get_option('eventosapp_wa_operator_db_version') !== EVENTOSAPP_WA_OPERATOR_DB_VERSION ) {
        return $accounts;
    }

    $active_accounts = [];
    foreach ( eventosapp_wa_operator_get_phones() as $phone ) {
        $account_id = absint($phone['account_id'] ?? 0);
        if ( ! array_key_exists($account_id, $active_accounts) ) {
            $active_accounts[$account_id] = $account_id > 0 && eventosapp_wa_operator_get_operational_token_for_account($account_id) !== '';
        }
        if ( empty($active_accounts[$account_id]) ) {
            continue;
        }
        $phone_id = preg_replace('/\D+/', '', (string)($phone['phone_number_id'] ?? ''));
        if ( $phone_id === '' ) {
            continue;
        }
        $alias = trim((string)($phone['verified_name'] ?? ''));
        if ( $alias === '' ) {
            $alias = trim((string)($phone['display_phone_number'] ?? ''));
        }
        if ( $alias === '' ) {
            $alias = 'WhatsApp ' . substr($phone_id, -4);
        }

        $client_name = eventosapp_wa_operator_client_name($phone['client_post_id'] ?? 0);
        $accounts[$phone_id] = [
            'alias'             => $alias,
            'phone_number_id'   => $phone_id,
            'label'             => $alias . ' — ' . ($phone['display_phone_number'] ?: $phone_id) . ' · ' . $client_name,
            'is_default'        => ! empty($phone['is_default']),
            'operator_managed'  => true,
            'operator_phone_id' => absint($phone['id']),
            'operator_account_id'=> absint($phone['account_id']),
            'client_post_id'    => absint($phone['client_post_id']),
            'waba_id'           => preg_replace('/\D+/', '', (string)$phone['waba_id']),
            'display_phone_number'=> sanitize_text_field((string)$phone['display_phone_number']),
            'verified_name'     => sanitize_text_field((string)$phone['verified_name']),
            'name_status'       => sanitize_text_field((string)$phone['name_status']),
            'registration_status'=> sanitize_text_field((string)$phone['registration_status']),
            'quality_rating'    => sanitize_text_field((string)$phone['quality_rating']),
        ];
    }
    return $accounts;
}
add_filter('eventosapp_whatsapp_phone_accounts', 'eventosapp_wa_operator_phone_accounts_filter', 20, 2);

function eventosapp_wa_operator_filter_phone_accounts_for_event($accounts, $event_id) {
    $accounts = is_array($accounts) ? $accounts : [];
    $client_id = eventosapp_wa_operator_get_event_client_id($event_id);
    if ( ! $client_id ) {
        return $accounts;
    }

    $filtered = [];
    foreach ( $accounts as $phone_id => $account ) {
        if ( empty($account['operator_managed']) ) {
            // Conserva números globales históricos para no romper eventos existentes.
            $filtered[$phone_id] = $account;
            continue;
        }
        if ( absint($account['client_post_id'] ?? 0) === $client_id ) {
            $filtered[$phone_id] = $account;
        }
    }
    return $filtered;
}

function eventosapp_wa_operator_default_phone_for_client($client_post_id) {
    $phones = eventosapp_wa_operator_get_phones(['client_post_id'=>absint($client_post_id)]);
    if ( empty($phones) ) {
        return '';
    }
    foreach ( $phones as $phone ) {
        if ( ! empty($phone['is_default']) ) {
            return preg_replace('/\D+/', '', (string)$phone['phone_number_id']);
        }
    }
    return preg_replace('/\D+/', '', (string)$phones[0]['phone_number_id']);
}

function eventosapp_wa_operator_apply_credentials_to_settings($settings, $phone_number_id = '', $waba_id = '') {
    $settings = is_array($settings) ? $settings : [];
    $operator_settings = eventosapp_wa_operator_get_settings();
    if ( empty($operator_settings['enabled']) ) {
        return $settings;
    }

    $phone_number_id = preg_replace('/\D+/', '', (string)$phone_number_id);
    $waba_id = preg_replace('/\D+/', '', (string)$waba_id);

    $phone = $phone_number_id !== '' ? eventosapp_wa_operator_get_phone_by_phone_number_id($phone_number_id) : null;
    $account = null;
    if ( $phone ) {
        $account = eventosapp_wa_operator_get_account($phone['account_id']);
    } elseif ( $waba_id !== '' ) {
        $account = eventosapp_wa_operator_get_account_by_waba($waba_id);
    }

    if ( ! $account ) {
        return $settings;
    }

    $token = eventosapp_wa_operator_get_operational_token_for_account($account['id']);
    if ( $token === '' ) {
        return $settings;
    }

    $settings['access_token'] = $token;
    $settings['webhook_waba_id'] = preg_replace('/\D+/', '', (string)$account['waba_id']);
    $settings['_eventosapp_operator_managed'] = '1';
    $settings['_eventosapp_operator_account_id'] = absint($account['id']);
    $settings['_eventosapp_operator_client_post_id'] = absint($account['client_post_id']);
    $settings['_eventosapp_operator_waba_id'] = preg_replace('/\D+/', '', (string)$account['waba_id']);

    if ( $phone ) {
        $settings['phone_number_id'] = preg_replace('/\D+/', '', (string)$phone['phone_number_id']);
        $settings['sender_phone_number_id'] = $settings['phone_number_id'];
        $settings['sender_phone_label'] = sanitize_text_field((string)($phone['verified_name'] ?: ($phone['display_phone_number'] ?: 'Número WhatsApp')));
        $settings['_eventosapp_operator_phone_row_id'] = absint($phone['id']);
    }
    return $settings;
}

function eventosapp_wa_operator_graph_settings_filter($settings, $method = '', $path = '', $body = null, $query_args = []) {
    $settings = is_array($settings) ? $settings : [];
    $operator_settings = eventosapp_wa_operator_get_settings();
    if ( empty($operator_settings['enabled']) ) {
        return $settings;
    }
    if ( ! empty($settings['_eventosapp_operator_managed']) && ! empty($settings['access_token']) ) {
        return $settings;
    }

    $path = ltrim((string)$path, '/');
    $first = strtok($path, '/');
    $first = preg_replace('/\D+/', '', (string)$first);
    if ( $first === '' ) {
        return $settings;
    }

    $phone = eventosapp_wa_operator_get_phone_by_phone_number_id($first);
    if ( $phone ) {
        return eventosapp_wa_operator_apply_credentials_to_settings($settings, $first, '');
    }

    $account = eventosapp_wa_operator_get_account_by_waba($first);
    if ( $account ) {
        return eventosapp_wa_operator_apply_credentials_to_settings($settings, '', $first);
    }

    return $settings;
}
add_filter('eventosapp_whatsapp_graph_api_request_settings', 'eventosapp_wa_operator_graph_settings_filter', 20, 6);

/**
 * Verificación opcional de firma X-Hub-Signature-256.
 * Se activa únicamente cuando existe App Secret y la opción está habilitada.
 */
function eventosapp_wa_operator_verify_webhook_signature_filter($allowed, $raw_body, $server = []) {
    $settings = eventosapp_wa_operator_get_settings();
    if ( empty($settings['verify_webhook_signature']) ) {
        return $allowed;
    }

    $secret = eventosapp_wa_operator_get_app_secret();
    if ( $secret === '' ) {
        eventosapp_wa_operator_log('webhook_signature', 'rejected', 'La validación de firma está activa, pero el App Secret no está disponible.', []);
        return false;
    }

    $server = is_array($server) ? $server : [];
    $signature = (string)($server['HTTP_X_HUB_SIGNATURE_256'] ?? ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
    if ( strpos($signature, 'sha256=') !== 0 ) {
        eventosapp_wa_operator_log('webhook_signature', 'rejected', 'Webhook sin firma X-Hub-Signature-256.', []);
        return false;
    }

    $expected = 'sha256=' . hash_hmac('sha256', (string)$raw_body, $secret);
    $valid = hash_equals($expected, $signature);
    if ( ! $valid ) {
        eventosapp_wa_operator_log('webhook_signature', 'rejected', 'La firma del webhook no coincide con el App Secret.', []);
    }
    return (bool) $allowed && $valid;
}
add_filter('eventosapp_whatsapp_verify_webhook_signature', 'eventosapp_wa_operator_verify_webhook_signature_filter', 20, 3);

/**
 * Procesamiento de eventos administrativos del webhook.
 */
function eventosapp_wa_operator_find_phone_by_display($display_phone_number, $waba_id = '') {
    global $wpdb;

    $digits = preg_replace('/\D+/', '', (string)$display_phone_number);
    if ( $digits === '' ) {
        return null;
    }

    $table = eventosapp_wa_operator_table('phones');
    $sql = "SELECT * FROM {$table} WHERE REPLACE(REPLACE(REPLACE(REPLACE(display_phone_number, '+', ''), ' ', ''), '-', ''), '(', '') LIKE %s";
    $values = ['%' . $wpdb->esc_like(substr($digits, -10)) . '%'];
    if ( preg_replace('/\D+/', '', (string)$waba_id) !== '' ) {
        $sql .= " AND waba_id = %s";
        $values[] = preg_replace('/\D+/', '', (string)$waba_id);
    }
    $sql .= " ORDER BY id DESC LIMIT 1";
    return $wpdb->get_row($wpdb->prepare($sql, $values), ARRAY_A);
}

function eventosapp_wa_operator_update_local_template_from_webhook($value) {
    if ( ! function_exists('eventosapp_whatsapp_templates_get_settings') || ! function_exists('eventosapp_whatsapp_templates_update_settings') ) {
        return;
    }

    $value = is_array($value) ? $value : [];
    $template_id = preg_replace('/\D+/', '', (string)($value['message_template_id'] ?? ($value['id'] ?? '')));
    $name = sanitize_key((string)($value['message_template_name'] ?? ($value['name'] ?? '')));
    $status = strtoupper(sanitize_text_field((string)($value['event'] ?? ($value['status'] ?? ''))));
    $reason = sanitize_text_field((string)($value['reason'] ?? ($value['rejection_reason'] ?? '')));
    $category = strtoupper(sanitize_key((string)($value['message_template_category'] ?? ($value['category'] ?? ''))));

    $settings = eventosapp_whatsapp_templates_get_settings();
    $changed = false;
    foreach ( (array)($settings['templates'] ?? []) as $key => $template ) {
        if ( ! is_array($template) ) {
            continue;
        }
        $matches = ($template_id !== '' && preg_replace('/\D+/', '', (string)($template['meta_template_id'] ?? '')) === $template_id)
            || ($name !== '' && sanitize_key((string)($template['name'] ?? '')) === $name);
        if ( ! $matches ) {
            continue;
        }
        if ( $status !== '' ) {
            $settings['templates'][$key]['meta_status'] = $status;
        }
        if ( $reason !== '' ) {
            $settings['templates'][$key]['meta_rejected_reason'] = $reason;
        }
        if ( $category !== '' ) {
            $settings['templates'][$key]['meta_category'] = $category;
        }
        $settings['templates'][$key]['last_checked_at'] = eventosapp_wa_operator_now();
        $settings['templates'][$key]['last_api_response'] = $value;
        $changed = true;
    }
    if ( $changed ) {
        eventosapp_whatsapp_templates_update_settings($settings);
    }
}

function eventosapp_wa_operator_handle_webhook_payload($payload, $unused_value = [], $unused_entry = [], $unused_change = [], $context = []) {
    global $wpdb;

    $entries = isset($payload['entry']) && is_array($payload['entry']) ? $payload['entry'] : [];
    foreach ( $entries as $entry ) {
        if ( ! is_array($entry) ) {
            continue;
        }
        $entry_waba_id = preg_replace('/\D+/', '', (string)($entry['id'] ?? ''));
        $account = $entry_waba_id !== '' ? eventosapp_wa_operator_get_account_by_waba($entry_waba_id) : null;

        foreach ( (array)($entry['changes'] ?? []) as $change ) {
            if ( ! is_array($change) ) {
                continue;
            }
            $field = sanitize_key((string)($change['field'] ?? ''));
            $value = is_array($change['value'] ?? null) ? $change['value'] : [];

            if ( $field === 'phone_number_name_update' || $field === 'phone_number_quality_update' || $field === 'phone_number_status_update' ) {
                $phone_number_id = preg_replace('/\D+/', '', (string)($value['phone_number_id'] ?? ($value['metadata']['phone_number_id'] ?? '')));
                $phone = $phone_number_id !== '' ? eventosapp_wa_operator_get_phone_by_phone_number_id($phone_number_id) : null;
                if ( ! $phone && ! empty($value['display_phone_number']) ) {
                    $phone = eventosapp_wa_operator_find_phone_by_display($value['display_phone_number'], $entry_waba_id);
                    $phone_number_id = $phone ? $phone['phone_number_id'] : $phone_number_id;
                }

                if ( $phone_number_id !== '' ) {
                    $phone_data = [
                        'phone_number_id' => $phone_number_id,
                        'account_id' => absint($phone['account_id'] ?? ($account['id'] ?? 0)),
                        'waba_id' => $entry_waba_id ?: ($phone['waba_id'] ?? ''),
                        'client_post_id' => absint($phone['client_post_id'] ?? ($account['client_post_id'] ?? 0)),
                        'display_phone_number' => $value['display_phone_number'] ?? ($phone['display_phone_number'] ?? ''),
                        'verified_name' => $value['verified_name'] ?? ($phone['verified_name'] ?? ''),
                        'requested_display_name' => $value['requested_verified_name'] ?? ($value['requested_display_name'] ?? ($value['new_display_name'] ?? ($phone['requested_display_name'] ?? ''))),
                        'name_status' => $value['decision'] ?? ($value['name_status'] ?? ($value['new_name_status'] ?? ($phone['name_status'] ?? ''))),
                        'quality_rating' => $value['quality_rating'] ?? ($value['current_quality_rating'] ?? ($phone['quality_rating'] ?? '')),
                        'messaging_limit_tier' => $value['current_limit'] ?? ($value['messaging_limit_tier'] ?? ($phone['messaging_limit_tier'] ?? '')),
                        'registration_status' => $value['status'] ?? ($phone['registration_status'] ?? ''),
                        'status' => $value['event'] ?? ($value['status'] ?? ($phone['status'] ?? '')),
                        'last_synced_at' => eventosapp_wa_operator_now(),
                        'raw' => $value,
                    ];
                    $phone_row_id = eventosapp_wa_operator_upsert_phone($phone_data);
                    eventosapp_wa_operator_log($field, 'received', 'Meta notificó una actualización del número.', [
                        'waba_id' => $entry_waba_id,
                        'phone_number_id' => $phone_number_id,
                        'field' => $field,
                        'value' => $value,
                    ], [
                        'account_id' => absint($phone_data['account_id']),
                        'phone_row_id' => is_wp_error($phone_row_id) ? 0 : absint($phone_row_id),
                    ]);
                }
                continue;
            }

            if ( $field === 'account_update' || $field === 'account_review_update' ) {
                if ( $entry_waba_id !== '' ) {
                    $data = [
                        'waba_id' => $entry_waba_id,
                        'business_row_id' => absint($account['business_row_id'] ?? 0),
                        'client_post_id' => absint($account['client_post_id'] ?? 0),
                        'meta_business_id' => $account['meta_business_id'] ?? '',
                        'name' => $account['name'] ?? 'Cuenta WhatsApp',
                        'account_status' => $value['event'] ?? ($value['status'] ?? ($account['account_status'] ?? '')),
                        'review_status' => $value['decision'] ?? ($value['account_review_status'] ?? ($account['review_status'] ?? '')),
                        'business_verification_status' => $value['business_verification_status'] ?? ($account['business_verification_status'] ?? ''),
                        'last_synced_at' => eventosapp_wa_operator_now(),
                        'raw' => $value,
                    ];
                    $account_id = eventosapp_wa_operator_upsert_account($data);
                    eventosapp_wa_operator_log($field, 'received', 'Meta notificó una actualización de la cuenta WhatsApp.', [
                        'waba_id' => $entry_waba_id,
                        'field' => $field,
                        'value' => $value,
                    ], ['account_id'=>is_wp_error($account_id) ? 0 : $account_id]);
                }
                continue;
            }

            if ( $field === 'message_template_status_update' ) {
                eventosapp_wa_operator_update_local_template_from_webhook($value);
                eventosapp_wa_operator_log($field, 'received', 'Meta notificó el estado de una plantilla.', [
                    'waba_id' => $entry_waba_id,
                    'value' => $value,
                ], ['account_id'=>absint($account['id'] ?? 0)]);
                continue;
            }

            if ( in_array($field, ['business_capability_update', 'template_category_update'], true) ) {
                eventosapp_wa_operator_log($field, 'received', 'Meta notificó un evento administrativo.', [
                    'waba_id' => $entry_waba_id,
                    'value' => $value,
                ], ['account_id'=>absint($account['id'] ?? 0)]);
            }
        }
    }
}
add_action('eventosapp_whatsapp_webhook_payload_received', 'eventosapp_wa_operator_handle_webhook_payload', 20, 5);

/**
 * Sesiones de Embedded Signup.
 */
function eventosapp_wa_operator_create_session($client_post_id = 0) {
    global $wpdb;

    $settings = eventosapp_wa_operator_get_settings();
    if ( empty($settings['enabled']) || empty($settings['app_id']) || empty($settings['configuration_id']) || eventosapp_wa_operator_get_app_secret() === '' ) {
        return new WP_Error('operator_not_ready', 'El operador no está habilitado o faltan App ID, App Secret y Configuration ID.');
    }

    $client_post_id = absint($client_post_id);
    if ( $client_post_id && get_post_type($client_post_id) !== 'eventosapp_cliente' ) {
        return new WP_Error('invalid_client', 'El cliente seleccionado no es válido.');
    }

    $session_key = wp_generate_password(48, false, false);
    $state = wp_generate_password(40, false, false);
    $now = eventosapp_wa_operator_now();
    $expires = get_date_from_gmt(gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS), 'Y-m-d H:i:s');

    $inserted = $wpdb->insert(eventosapp_wa_operator_table('sessions'), [
        'session_key'   => $session_key,
        'user_id'       => get_current_user_id(),
        'client_post_id'=> $client_post_id,
        'status'        => 'created',
        'state_hash'    => wp_hash_password($state),
        'expires_at'    => $expires,
        'created_at'    => $now,
        'updated_at'    => $now,
    ]);

    if ( $inserted === false ) {
        return new WP_Error('session_db_error', 'No fue posible crear la sesión segura de onboarding.');
    }

    return [
        'session_key' => $session_key,
        'state'       => $state,
        'expires_at'  => $expires,
    ];
}

function eventosapp_wa_operator_get_session($session_key) {
    global $wpdb;
    $session_key = sanitize_text_field((string)$session_key);
    if ( $session_key === '' ) {
        return null;
    }
    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM " . eventosapp_wa_operator_table('sessions') . " WHERE session_key = %s LIMIT 1", $session_key),
        ARRAY_A
    );
}

function eventosapp_wa_operator_update_session($session_key, $data) {
    global $wpdb;
    $session_key = sanitize_text_field((string)$session_key);
    $data = is_array($data) ? $data : [];
    $allowed = ['status','meta_business_id','waba_id','phone_number_id','payload_json','error_message','expires_at'];
    $row = ['updated_at'=>eventosapp_wa_operator_now()];
    foreach ( $allowed as $key ) {
        if ( array_key_exists($key, $data) ) {
            $row[$key] = $key === 'payload_json'
                ? eventosapp_wa_operator_json($data[$key])
                : sanitize_textarea_field((string)$data[$key]);
        }
    }
    return $wpdb->update(eventosapp_wa_operator_table('sessions'), $row, ['session_key'=>$session_key]);
}

/**
 * Completa onboarding a partir del código devuelto por Facebook Login for Business.
 */
function eventosapp_wa_operator_complete_embedded_signup($args) {
    $args = is_array($args) ? $args : [];
    $session_key = sanitize_text_field((string)($args['session_key'] ?? ''));
    $state = sanitize_text_field((string)($args['state'] ?? ''));
    $code = trim((string)($args['code'] ?? ''));
    $session_info = is_array($args['session_info'] ?? null) ? $args['session_info'] : [];

    $session = eventosapp_wa_operator_get_session($session_key);
    if ( ! $session || absint($session['user_id']) !== get_current_user_id() ) {
        return new WP_Error('invalid_session', 'La sesión de Embedded Signup no existe o no pertenece al usuario actual.');
    }
    if ( ! wp_check_password($state, $session['state_hash']) ) {
        return new WP_Error('invalid_state', 'La validación de seguridad de Embedded Signup falló.');
    }
    if ( $code === '' ) {
        return new WP_Error('missing_code', 'Meta no devolvió el código de autorización.');
    }
    if ( ! empty($session['expires_at']) && strtotime((string)$session['expires_at']) < current_time('timestamp') ) {
        return new WP_Error('session_expired', 'La sesión de Embedded Signup expiró. Inicia nuevamente.');
    }
    if ( (string)($session['status'] ?? '') !== 'created' ) {
        return new WP_Error('session_already_used', 'Esta sesión de Embedded Signup ya fue procesada. Inicia una nueva conexión.');
    }

    // Cambio atómico para impedir que una misma sesión sea procesada dos veces.
    global $wpdb;
    $claimed = $wpdb->query(
        $wpdb->prepare(
            "UPDATE " . eventosapp_wa_operator_table('sessions') . " SET status = %s, payload_json = %s, updated_at = %s WHERE session_key = %s AND status = %s",
            'exchanging_token',
            eventosapp_wa_operator_json($session_info),
            eventosapp_wa_operator_now(),
            $session_key,
            'created'
        )
    );
    if ( $claimed !== 1 ) {
        return new WP_Error('session_claim_failed', 'No fue posible reservar la sesión de onboarding. Inicia una nueva conexión.');
    }

    $token_data = eventosapp_wa_operator_exchange_code($code);
    if ( is_wp_error($token_data) ) {
        eventosapp_wa_operator_update_session($session_key, ['status'=>'error','error_message'=>$token_data->get_error_message()]);
        return $token_data;
    }
    $access_token = trim((string)$token_data['access_token']);

    $debug = eventosapp_wa_operator_debug_token($access_token);
    if ( is_wp_error($debug) ) {
        eventosapp_wa_operator_update_session($session_key, ['status'=>'error','error_message'=>$debug->get_error_message()]);
        return $debug;
    }
    if ( isset($debug['is_valid']) && ! $debug['is_valid'] ) {
        eventosapp_wa_operator_update_session($session_key, ['status'=>'error','error_message'=>'Meta reportó que el token obtenido no es válido.']);
        return new WP_Error('invalid_embedded_token', 'Meta reportó que el token obtenido no es válido.');
    }
    $asset_ids = eventosapp_wa_operator_extract_asset_ids_from_debug($debug);

    $business_id = preg_replace('/\D+/', '', (string)($session_info['business_id'] ?? ($session_info['businessId'] ?? '')));
    $waba_id = preg_replace('/\D+/', '', (string)($session_info['waba_id'] ?? ($session_info['wabaId'] ?? '')));
    $phone_number_id = preg_replace('/\D+/', '', (string)($session_info['phone_number_id'] ?? ($session_info['phoneNumberId'] ?? '')));

    if ( $business_id === '' && ! empty($asset_ids['business_ids'][0]) ) {
        $business_id = $asset_ids['business_ids'][0];
    }
    if ( $waba_id === '' && ! empty($asset_ids['waba_ids'][0]) ) {
        $waba_id = $asset_ids['waba_ids'][0];
    }

    $waba_candidates = [];
    if ( $waba_id !== '' ) {
        $waba_candidates[$waba_id] = ['id'=>$waba_id];
    }
    foreach ( (array)$asset_ids['waba_ids'] as $candidate ) {
        $candidate = preg_replace('/\D+/', '', (string)$candidate);
        if ( $candidate !== '' ) {
            $waba_candidates[$candidate] = ['id'=>$candidate];
        }
    }
    if ( $business_id !== '' ) {
        foreach ( eventosapp_wa_operator_get_wabas_for_business($business_id, $access_token) as $candidate ) {
            $candidate_id = preg_replace('/\D+/', '', (string)($candidate['id'] ?? ''));
            if ( $candidate_id !== '' ) {
                $waba_candidates[$candidate_id] = $candidate;
            }
        }
    }
    if ( empty($waba_candidates) ) {
        eventosapp_wa_operator_update_session($session_key, [
            'status'=>'error',
            'error_message'=>'No fue posible identificar una WABA compartida por Meta.',
        ]);
        return new WP_Error('waba_not_found', 'Meta entregó autorización, pero no fue posible identificar la cuenta de WhatsApp compartida.');
    }

    $business_row_id = eventosapp_wa_operator_upsert_business([
        'client_post_id' => absint($session['client_post_id']),
        'meta_business_id'=> $business_id,
        'name'            => eventosapp_wa_operator_client_name($session['client_post_id']),
        'status'          => 'ACTIVE',
        'raw'             => [
            'session_info' => $session_info,
            'debug' => $debug,
        ],
    ]);

    $operator_settings = eventosapp_wa_operator_get_settings();
    $created_accounts = [];
    $created_phones = [];
    foreach ( $waba_candidates as $candidate_waba_id => $candidate ) {
        $account_id = eventosapp_wa_operator_upsert_account([
            'business_row_id' => $business_row_id,
            'client_post_id'  => absint($session['client_post_id']),
            'meta_business_id'=> $business_id,
            'waba_id'         => $candidate_waba_id,
            'name'            => $candidate['name'] ?? ('WhatsApp ' . substr($candidate_waba_id, -4)),
            'currency'        => $candidate['currency'] ?? '',
            'timezone_id'     => $candidate['timezone_id'] ?? '',
            'review_status'   => $candidate['account_review_status'] ?? '',
            'business_verification_status' => $candidate['business_verification_status'] ?? '',
            'account_status'  => 'CONNECTED',
            'is_default'      => count($created_accounts) === 0 ? 1 : 0,
            'raw'             => $candidate,
        ]);
        if ( is_wp_error($account_id) ) {
            continue;
        }

        $credential_meta = [
            'token_type' => $token_data['token_type'] ?? 'bearer',
            'expires_in' => absint($token_data['expires_in'] ?? 0),
            'scopes'     => $asset_ids['scopes'],
            'debug'      => is_wp_error($debug) ? ['error'=>$debug->get_error_message()] : $debug,
            'status'     => 'active',
        ];
        $credential_id = eventosapp_wa_operator_store_access_token($account_id, $access_token, $credential_meta);
        if ( is_wp_error($credential_id) ) {
            eventosapp_wa_operator_log('embedded_signup', 'error', 'No fue posible cifrar o guardar el token de la WABA.', [
                'waba_id' => $candidate_waba_id,
                'error' => $credential_id->get_error_message(),
            ], ['business_row_id'=>$business_row_id, 'account_id'=>$account_id]);
            continue;
        }

        $created_accounts[] = $account_id;

        if ( ! empty($operator_settings['auto_assign_system_user']) && eventosapp_wa_operator_system_user_ready() ) {
            eventosapp_wa_operator_assign_system_user($candidate_waba_id);
        }

        if ( ! empty($operator_settings['auto_sync_after_signup']) ) {
            $sync = eventosapp_wa_operator_sync_waba(
                $candidate_waba_id,
                $account_id,
                $access_token,
                ! empty($operator_settings['auto_import_phone_numbers'])
            );
            if ( ! is_wp_error($sync) ) {
                foreach ( (array)($sync['phones'] ?? []) as $phone ) {
                    if ( is_array($phone) ) {
                        $created_phones[] = $phone;
                    }
                }
            }
        }

        if ( ! empty($operator_settings['auto_subscribe_webhook']) ) {
            eventosapp_wa_operator_subscribe_waba($candidate_waba_id);
        }
    }

    if ( empty($created_accounts) ) {
        eventosapp_wa_operator_update_session($session_key, [
            'status'=>'error',
            'error_message'=>'No fue posible guardar credenciales activas para las WABAs compartidas.',
        ]);
        return new WP_Error('credentials_not_saved', 'No fue posible guardar credenciales activas para las WABAs compartidas.');
    }

    if ( $phone_number_id !== '' && ! eventosapp_wa_operator_get_phone_by_phone_number_id($phone_number_id) && ! empty($created_accounts[0]) ) {
        $account = eventosapp_wa_operator_get_account($created_accounts[0]);
        $phone_row_id = eventosapp_wa_operator_upsert_phone([
            'account_id' => $created_accounts[0],
            'client_post_id' => absint($session['client_post_id']),
            'waba_id' => $account['waba_id'] ?? '',
            'phone_number_id' => $phone_number_id,
            'registration_status' => 'PENDING',
            'status' => 'PENDING',
            'last_synced_at' => eventosapp_wa_operator_now(),
            'raw' => $session_info,
        ]);
        if ( ! is_wp_error($phone_row_id) ) {
            $created_phones[] = eventosapp_wa_operator_get_phone($phone_row_id);
        }
    }

    $primary_waba_id = '';
    if ( ! empty($created_accounts[0]) ) {
        $primary_account = eventosapp_wa_operator_get_account($created_accounts[0]);
        $primary_waba_id = $primary_account['waba_id'] ?? '';
    }
    if ( $phone_number_id === '' && ! empty($created_phones[0]['phone_number_id']) ) {
        $phone_number_id = $created_phones[0]['phone_number_id'];
    }

    eventosapp_wa_operator_update_session($session_key, [
        'status' => 'completed',
        'meta_business_id' => $business_id,
        'waba_id' => $primary_waba_id,
        'phone_number_id' => $phone_number_id,
        'payload_json' => [
            'session_info' => $session_info,
            'asset_ids' => $asset_ids,
            'accounts' => $created_accounts,
            'phones' => array_map(static function($phone) {
                return is_array($phone) ? [
                    'id'=>absint($phone['id'] ?? 0),
                    'phone_number_id'=>$phone['phone_number_id'] ?? '',
                ] : [];
            }, $created_phones),
        ],
    ]);

    eventosapp_wa_operator_log('embedded_signup', 'success', 'Embedded Signup completado y activos importados.', [
        'business_id' => $business_id,
        'waba_ids' => array_keys($waba_candidates),
        'phone_number_id' => $phone_number_id,
        'accounts_created' => count($created_accounts),
        'phones_created' => count($created_phones),
    ], ['business_row_id'=>$business_row_id, 'account_id'=>absint($created_accounts[0] ?? 0)]);

    return [
        'business_row_id' => $business_row_id,
        'meta_business_id'=> $business_id,
        'account_ids'     => $created_accounts,
        'phone_rows'      => $created_phones,
        'waba_id'         => $primary_waba_id,
        'phone_number_id' => $phone_number_id,
    ];
}

/**
 * Registro manual seguro para cuentas ya creadas en Meta.
 */
function eventosapp_wa_operator_manual_connect($args) {
    $args = is_array($args) ? $args : [];
    $client_post_id = absint($args['client_post_id'] ?? 0);
    $business_id = preg_replace('/\D+/', '', (string)($args['meta_business_id'] ?? ''));
    $waba_id = preg_replace('/\D+/', '', (string)($args['waba_id'] ?? ''));
    $phone_number_id = preg_replace('/\D+/', '', (string)($args['phone_number_id'] ?? ''));
    $token = trim((string)($args['access_token'] ?? ''));

    if ( $waba_id === '' || $token === '' ) {
        return new WP_Error('missing_manual_data', 'Para conectar manualmente se requieren WABA ID y Access Token.');
    }

    $debug = eventosapp_wa_operator_debug_token($token);
    if ( is_wp_error($debug) ) {
        return $debug;
    }
    if ( isset($debug['is_valid']) && ! $debug['is_valid'] ) {
        return new WP_Error('invalid_access_token', 'Meta reporta que el token no es válido.');
    }

    $waba_check = eventosapp_wa_operator_fetch_account_details($waba_id, $token);
    if ( empty($waba_check['ok']) ) {
        return new WP_Error(
            'waba_not_accessible',
            'El token es válido, pero no permite consultar la WABA indicada: ' . implode(' | ', array_unique((array)($waba_check['errors'] ?? [])))
        );
    }
    $waba_details = is_array($waba_check['data'] ?? null) ? $waba_check['data'] : [];

    $business_row_id = eventosapp_wa_operator_upsert_business([
        'client_post_id' => $client_post_id,
        'meta_business_id'=> $business_id,
        'name' => eventosapp_wa_operator_client_name($client_post_id),
        'status' => 'ACTIVE',
        'raw' => ['source'=>'manual_connect','debug'=>$debug],
    ]);
    $account_id = eventosapp_wa_operator_upsert_account([
        'business_row_id' => $business_row_id,
        'client_post_id' => $client_post_id,
        'meta_business_id' => $business_id,
        'waba_id' => $waba_id,
        'name' => sanitize_text_field((string)($args['account_name'] ?? ($waba_details['name'] ?? ('WhatsApp ' . substr($waba_id, -4))))),
        'currency' => $waba_details['currency'] ?? '',
        'timezone_id' => $waba_details['timezone_id'] ?? '',
        'review_status' => $waba_details['account_review_status'] ?? '',
        'business_verification_status' => $waba_details['business_verification_status'] ?? '',
        'account_status' => 'CONNECTED',
        'is_default' => ! empty($args['is_default']),
        'raw' => ['source'=>'manual_connect','waba'=>$waba_details],
    ]);
    if ( is_wp_error($account_id) ) {
        return $account_id;
    }

    $credential_id = eventosapp_wa_operator_store_access_token($account_id, $token, [
        'token_type' => $debug['type'] ?? 'bearer',
        'scopes' => $debug['scopes'] ?? [],
        'expires_at_timestamp' => absint($debug['expires_at'] ?? 0),
        'last_validated_at' => eventosapp_wa_operator_now(),
        'status' => 'active',
        'debug' => $debug,
    ]);
    if ( is_wp_error($credential_id) ) {
        return $credential_id;
    }

    if ( $phone_number_id !== '' ) {
        eventosapp_wa_operator_upsert_phone([
            'account_id' => $account_id,
            'client_post_id' => $client_post_id,
            'waba_id' => $waba_id,
            'phone_number_id' => $phone_number_id,
            'registration_status' => 'PENDING',
            'status' => 'PENDING',
            'raw' => ['source'=>'manual_connect'],
        ]);
    }

    $settings = eventosapp_wa_operator_get_settings();
    if ( ! empty($settings['auto_assign_system_user']) && eventosapp_wa_operator_system_user_ready() ) {
        eventosapp_wa_operator_assign_system_user($waba_id);
    }

    $sync = eventosapp_wa_operator_sync_waba($waba_id, $account_id, $token);
    if ( ! empty($settings['auto_subscribe_webhook']) ) {
        eventosapp_wa_operator_subscribe_waba($waba_id);
    }

    return [
        'business_row_id' => $business_row_id,
        'account_id' => $account_id,
        'credential_id' => $credential_id,
        'sync' => $sync,
    ];
}

/**
 * Salud de tokens.
 */
function eventosapp_wa_operator_validate_account_token($account_id) {
    global $wpdb;

    $account = eventosapp_wa_operator_get_account($account_id);
    if ( ! $account ) {
        return new WP_Error('account_not_found', 'La cuenta no existe.');
    }
    $token = eventosapp_wa_operator_get_access_token_for_account($account_id);
    if ( $token === '' ) {
        return new WP_Error('missing_access_token', 'La cuenta no tiene un token activo.');
    }

    $debug = eventosapp_wa_operator_debug_token($token);
    $status = 'invalid';
    $metadata = [];
    if ( ! is_wp_error($debug) ) {
        $status = ! empty($debug['is_valid']) ? 'active' : 'invalid';
        $metadata = $debug;
    } else {
        $metadata = ['error'=>$debug->get_error_message()];
    }

    $expires_at = ! empty($metadata['expires_at'])
        ? get_date_from_gmt(gmdate('Y-m-d H:i:s', absint($metadata['expires_at'])), 'Y-m-d H:i:s')
        : null;

    $wpdb->update(eventosapp_wa_operator_table('credentials'), [
        'status' => $status,
        'scopes' => is_array($metadata['scopes'] ?? null) ? implode(',', array_map('sanitize_key', $metadata['scopes'])) : '',
        'expires_at' => $expires_at,
        'last_validated_at' => eventosapp_wa_operator_now(),
        'metadata_json' => eventosapp_wa_operator_json($metadata),
        'updated_at' => eventosapp_wa_operator_now(),
    ], [
        'account_id' => absint($account_id),
        'credential_type' => 'access_token',
    ]);

    eventosapp_wa_operator_log('validate_token', $status === 'active' ? 'success' : 'error', $status === 'active' ? 'Token validado correctamente.' : 'Token inválido o no verificable.', [
        'waba_id' => $account['waba_id'],
        'debug' => $metadata,
    ], ['account_id'=>$account_id]);

    return is_wp_error($debug) ? $debug : $debug;
}

function eventosapp_wa_operator_token_health_run() {
    $settings = eventosapp_wa_operator_get_settings();
    if ( empty($settings['token_health_enabled']) ) {
        return;
    }

    $summary = [
        'checked'=>0,
        'valid'=>0,
        'invalid'=>0,
        'expiring'=>0,
        'expired'=>0,
        'system_user_token_configured'=>eventosapp_wa_operator_get_system_user_token() !== '',
        'system_user_token_valid'=>null,
        'warnings'=>[],
        'errors'=>[],
    ];
    $warning_threshold = current_time('timestamp') + (DAY_IN_SECONDS * absint($settings['token_warning_days'] ?? 7));

    $system_token = eventosapp_wa_operator_get_system_user_token();
    if ( $system_token !== '' ) {
        $system_debug = eventosapp_wa_operator_debug_token($system_token);
        $summary['system_user_token_valid'] = ! is_wp_error($system_debug) && ! empty($system_debug['is_valid']);
        if ( is_wp_error($system_debug) || empty($system_debug['is_valid']) ) {
            $summary['errors'][] = [
                'account_id'=>0,
                'waba_id'=>'',
                'message'=>is_wp_error($system_debug) ? $system_debug->get_error_message() : 'Token del System User inválido',
            ];
        }
    }

    foreach ( eventosapp_wa_operator_get_accounts(['limit'=>1000]) as $account ) {
        $summary['checked']++;
        $result = eventosapp_wa_operator_validate_account_token($account['id']);
        if ( is_wp_error($result) || empty($result['is_valid']) ) {
            $summary['invalid']++;
            $summary['errors'][] = [
                'account_id'=>absint($account['id']),
                'waba_id'=>$account['waba_id'],
                'message'=>is_wp_error($result) ? $result->get_error_message() : 'Token inválido',
            ];
            continue;
        }

        $summary['valid']++;
        $credential = eventosapp_wa_operator_get_credential_for_account($account['id']);
        $expires_at = ! empty($credential['expires_at']) ? strtotime((string)$credential['expires_at']) : 0;
        if ( $expires_at > 0 && $expires_at <= current_time('timestamp') ) {
            $summary['expired']++;
            $summary['warnings'][] = [
                'account_id'=>absint($account['id']),
                'waba_id'=>$account['waba_id'],
                'message'=>'El token aparece vencido.',
            ];
        } elseif ( $expires_at > 0 && $expires_at <= $warning_threshold ) {
            $summary['expiring']++;
            $summary['warnings'][] = [
                'account_id'=>absint($account['id']),
                'waba_id'=>$account['waba_id'],
                'message'=>'El token está próximo a vencer.',
            ];
        }
    }

    $settings['last_health_run'] = eventosapp_wa_operator_now();
    $settings['last_health_summary'] = $summary;
    update_option(EVENTOSAPP_WA_OPERATOR_OPTION, $settings, false);
}
add_action(EVENTOSAPP_WA_OPERATOR_HEALTH_HOOK, 'eventosapp_wa_operator_token_health_run');

function eventosapp_wa_operator_schedule_health() {
    $settings = eventosapp_wa_operator_get_settings();
    if ( ! empty($settings['token_health_enabled']) && ! wp_next_scheduled(EVENTOSAPP_WA_OPERATOR_HEALTH_HOOK) ) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', EVENTOSAPP_WA_OPERATOR_HEALTH_HOOK);
    }
    if ( empty($settings['token_health_enabled']) ) {
        $timestamp = wp_next_scheduled(EVENTOSAPP_WA_OPERATOR_HEALTH_HOOK);
        if ( $timestamp ) {
            wp_unschedule_event($timestamp, EVENTOSAPP_WA_OPERATOR_HEALTH_HOOK);
        }
    }
}
add_action('init', 'eventosapp_wa_operator_schedule_health', 20);
