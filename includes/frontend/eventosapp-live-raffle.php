<?php
/**
 * EventosApp - Sorteo en vivo por evento
 *
 * Shortcodes:
 * - [eventosapp_live_raffle]        Panel de gestión para el organizador.
 * - [eventosapp_live_raffle_public] Pantalla pública/proyección.
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Se define antes de cargar eventosapp-configuracion.php para incorporar el
 * permiso del sorteo a la matriz global y al control por evento sin modificar
 * la lógica existente de permisos.
 */
if ( ! function_exists( 'eventosapp_dashboard_features' ) ) {
    function eventosapp_dashboard_features() {
        return [
            'dashboard'            => 'Ver Dashboard',
            'metrics'              => 'Métricas',
            'flow_metrics'         => 'Métricas de Encuestas',
            'search'               => 'Check-In Manual & Escarapela',
            'self_checkin'         => 'Autogestión del Asistente',
            'register'             => 'Registro Manual de Asistentes',
            'qr'                   => 'Check-In con QR',
            'edit'                 => 'Edición de Tickets',
            'qr_localidad'         => 'Validador de Localidad',
            'qr_sesion'            => 'Control por Sesión',
            'checklist'            => 'Checklist de Evento',
            'networking_ranking'   => 'Ranking Networking',
            'qr_double_auth'       => 'Check-In QR Doble Autenticación',
            'face_checkin'         => 'Check-In Facial',
            'support_assistance'   => 'Asistencia',
            'support_team_metrics' => 'Métrica de equipo de apoyo',
            'expositor'            => 'Expositor',
            'expositor_gestion'    => 'Gestión de Expositores',
            'company_checkin'      => 'Empresas con Check-In',
            'live_raffle'          => 'Sorteo en Vivo',
        ];
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_meta_key' ) ) {
    function eventosapp_live_raffle_meta_key( $name ) {
        $keys = [
            'enabled'       => '_eventosapp_ticket_live_raffle_enabled',
            'public_active' => '_eventosapp_live_raffle_public_active',
            'settings'      => '_eventosapp_live_raffle_settings',
            'state'         => '_eventosapp_live_raffle_state',
            'winners'       => '_eventosapp_live_raffle_winners',
        ];
        return isset( $keys[ $name ] ) ? $keys[ $name ] : '';
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_is_enabled' ) ) {
    function eventosapp_live_raffle_is_enabled( $event_id ) {
        $event_id = absint( $event_id );
        return $event_id > 0 && (string) get_post_meta( $event_id, eventosapp_live_raffle_meta_key( 'enabled' ), true ) === '1';
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_user_can_view' ) ) {
    function eventosapp_live_raffle_user_can_view( $event_id, $user_id = 0 ) {
        $event_id = absint( $event_id );
        $user_id  = $user_id ? absint( $user_id ) : get_current_user_id();

        if ( ! $event_id || ! $user_id || ! eventosapp_live_raffle_is_enabled( $event_id ) ) {
            return false;
        }

        if ( ! function_exists( 'eventosapp_role_can' ) || ! eventosapp_role_can( 'live_raffle', $user_id ) ) {
            return false;
        }

        if ( function_exists( 'eventosapp_dashboard_user_can_access_event_scope' ) ) {
            return eventosapp_dashboard_user_can_access_event_scope( $event_id, $user_id );
        }

        if ( user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        if ( function_exists( 'eventosapp_user_can_manage_event' ) ) {
            return eventosapp_user_can_manage_event( $event_id, $user_id );
        }

        $event = get_post( $event_id );
        return $event instanceof WP_Post && absint( $event->post_author ) === $user_id;
    }
}

// ============================================================
// CONFIGURACIÓN DE PÁGINAS
// ============================================================

if ( ! function_exists( 'eventosapp_get_live_raffle_url' ) ) {
    function eventosapp_get_live_raffle_url() {
        return function_exists( 'eventosapp_get_configured_page_url' )
            ? eventosapp_get_configured_page_url( 'live_raffle_page_id', '#' )
            : '#';
    }
}

if ( ! function_exists( 'eventosapp_get_live_raffle_public_page_url' ) ) {
    function eventosapp_get_live_raffle_public_page_url() {
        return function_exists( 'eventosapp_get_configured_page_url' )
            ? eventosapp_get_configured_page_url( 'live_raffle_public_page_id', '' )
            : '';
    }
}

add_action( 'admin_init', function() {
    if ( ! function_exists( 'eventosapp_render_pages_field' ) ) {
        return;
    }

    add_settings_field(
        'live_raffle_page_id',
        'Página de Gestión del Sorteo en Vivo',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        [
            'key'  => 'live_raffle_page_id',
            'desc' => 'Debe contener el shortcode: <code>[eventosapp_live_raffle]</code>',
        ]
    );

    add_settings_field(
        'live_raffle_public_page_id',
        'Página Pública del Sorteo en Vivo',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        [
            'key'  => 'live_raffle_public_page_id',
            'desc' => 'Debe contener el shortcode: <code>[eventosapp_live_raffle_public]</code>. EventosApp agregará el nombre del evento a la URL pública.',
        ]
    );
}, 30 );

/**
 * El sanitizador central de páginas conserva una lista cerrada. Este filtro
 * incorpora de forma segura las dos páginas del sorteo sin cambiar ni borrar
 * las demás claves existentes.
 */
add_filter( 'pre_update_option_eventosapp_pages', function( $new_value, $old_value ) {
    $new_value = is_array( $new_value ) ? $new_value : [];
    $old_value = is_array( $old_value ) ? $old_value : [];
    $raw       = [];

    if ( isset( $_POST['eventosapp_pages'] ) && is_array( $_POST['eventosapp_pages'] ) ) {
        $raw = wp_unslash( $_POST['eventosapp_pages'] );
    }

    foreach ( [ 'live_raffle_page_id', 'live_raffle_public_page_id' ] as $key ) {
        if ( array_key_exists( $key, $raw ) ) {
            $new_value[ $key ] = absint( $raw[ $key ] );
        } elseif ( isset( $old_value[ $key ] ) ) {
            $new_value[ $key ] = absint( $old_value[ $key ] );
        } else {
            $new_value[ $key ] = 0;
        }
    }

    return $new_value;
}, 20, 2 );

add_action( 'update_option_eventosapp_pages', function( $old_value, $new_value ) {
    $old_value = is_array( $old_value ) ? $old_value : [];
    $new_value = is_array( $new_value ) ? $new_value : [];

    $old_public = absint( $old_value['live_raffle_public_page_id'] ?? 0 );
    $new_public = absint( $new_value['live_raffle_public_page_id'] ?? 0 );

    if ( $old_public !== $new_public ) {
        update_option( 'eventosapp_needs_flush', 1 );
    }
}, 10, 2 );

add_filter( 'query_vars', function( $vars ) {
    $vars[] = 'evapp_raffle_event';
    return $vars;
} );

if ( ! function_exists( 'eventosapp_live_raffle_public_page_id' ) ) {
    function eventosapp_live_raffle_public_page_id() {
        $cfg = function_exists( 'eventosapp_get_pages_config' ) ? eventosapp_get_pages_config() : get_option( 'eventosapp_pages', [] );
        return is_array( $cfg ) ? absint( $cfg['live_raffle_public_page_id'] ?? 0 ) : 0;
    }
}

add_action( 'init', function() {
    $page_id = eventosapp_live_raffle_public_page_id();
    if ( ! $page_id || ! get_option( 'permalink_structure' ) ) {
        return;
    }

    $page_path = trim( (string) get_page_uri( $page_id ), '/' );
    if ( $page_path === '' ) {
        return;
    }

    add_rewrite_rule(
        '^' . preg_quote( $page_path, '#' ) . '/([^/]+)/?$',
        'index.php?pagename=' . $page_path . '&evapp_raffle_event=$matches[1]',
        'top'
    );
}, 25 );

if ( ! function_exists( 'eventosapp_live_raffle_event_slug' ) ) {
    function eventosapp_live_raffle_event_slug( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) return '';

        $event = get_post( $event_id );
        if ( ! $event instanceof WP_Post || $event->post_type !== 'eventosapp_event' ) return '';

        $base = $event->post_name ?: sanitize_title( $event->post_title );
        if ( $base === '' ) $base = 'evento';
        return $base . '-' . $event_id;
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_get_public_url' ) ) {
    function eventosapp_live_raffle_get_public_url( $event_id ) {
        $event_id = absint( $event_id );
        $page_id  = eventosapp_live_raffle_public_page_id();
        $slug     = eventosapp_live_raffle_event_slug( $event_id );

        if ( ! $event_id || ! $page_id || $slug === '' ) return '';

        $base = get_permalink( $page_id );
        if ( ! $base ) return '';

        if ( ! get_option( 'permalink_structure' ) ) {
            return add_query_arg( 'evapp_raffle_event', $slug, $base );
        }

        return trailingslashit( $base ) . rawurlencode( $slug ) . '/';
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_resolve_public_event' ) ) {
    function eventosapp_live_raffle_resolve_public_event( $raw = '' ) {
        $raw = $raw !== '' ? $raw : get_query_var( 'evapp_raffle_event' );
        if ( $raw === '' && isset( $_GET['evapp_raffle_event'] ) ) {
            $raw = is_scalar( $_GET['evapp_raffle_event'] ) ? wp_unslash( (string) $_GET['evapp_raffle_event'] ) : '';
        }

        $slug = sanitize_title( (string) $raw );
        if ( $slug === '' || ! preg_match( '/-(\d+)$/', $slug, $matches ) ) {
            return 0;
        }

        $event_id = absint( $matches[1] );
        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return 0;
        }

        return hash_equals( eventosapp_live_raffle_event_slug( $event_id ), $slug ) ? $event_id : 0;
    }
}

/** Protege únicamente la página de gestión. La página pública permanece pública. */
add_action( 'template_redirect', function() {
    if ( is_admin() ) return;

    $cfg     = function_exists( 'eventosapp_get_pages_config' ) ? eventosapp_get_pages_config() : get_option( 'eventosapp_pages', [] );
    $page_id = is_array( $cfg ) ? absint( $cfg['live_raffle_page_id'] ?? 0 ) : 0;

    if ( ! $page_id || get_queried_object_id() !== $page_id ) return;

    if ( ! is_user_logged_in() ) {
        if ( function_exists( 'eventosapp_redirect_with_error' ) ) {
            eventosapp_redirect_with_error( 'Debes iniciar sesión para acceder al Sorteo en Vivo.', [ 'from' => 'live_raffle' ] );
        }
        auth_redirect();
    }

    if ( ! function_exists( 'eventosapp_role_can' ) || ! eventosapp_role_can( 'live_raffle' ) ) {
        if ( function_exists( 'eventosapp_redirect_with_error' ) ) {
            eventosapp_redirect_with_error( 'No tienes permisos para acceder al Sorteo en Vivo.', [ 'from' => 'live_raffle' ] );
        }
        wp_die( 'No tienes permisos para acceder al Sorteo en Vivo.', '', [ 'response' => 403 ] );
    }
}, 8 );

// ============================================================
// NORMALIZACIÓN Y DATOS DEL SORTEO
// ============================================================

if ( ! function_exists( 'eventosapp_live_raffle_default_settings' ) ) {
    function eventosapp_live_raffle_default_settings() {
        return [
            'modalities'    => [ 'presencial', 'virtual' ],
            'locations'     => [],
            'networking'    => 'any',
            'expositor'     => 'any',
            'expositor_ids' => [],
        ];
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_normalize_settings' ) ) {
    function eventosapp_live_raffle_normalize_settings( $raw ) {
        $raw      = is_array( $raw ) ? $raw : [];
        $defaults = eventosapp_live_raffle_default_settings();

        $modalities = [];
        foreach ( isset( $raw['modalities'] ) ? (array) $raw['modalities'] : $defaults['modalities'] as $modality ) {
            if ( ! is_scalar( $modality ) ) continue;
            $modality = sanitize_key( (string) $modality );
            if ( in_array( $modality, [ 'presencial', 'virtual' ], true ) ) $modalities[] = $modality;
        }
        $modalities = array_values( array_unique( $modalities ) );
        if ( empty( $modalities ) ) {
            $modalities = $defaults['modalities'];
        }

        $locations = [];
        foreach ( isset( $raw['locations'] ) ? (array) $raw['locations'] : [] as $location ) {
            if ( ! is_scalar( $location ) ) continue;
            $location = trim( sanitize_text_field( wp_unslash( (string) $location ) ) );
            if ( $location !== '' ) $locations[] = $location;
        }

        $networking_raw = $raw['networking'] ?? 'any';
        $networking = is_scalar( $networking_raw ) ? sanitize_key( (string) $networking_raw ) : 'any';
        if ( ! in_array( $networking, [ 'any', 'yes', 'no' ], true ) ) $networking = 'any';

        $expositor_raw = $raw['expositor'] ?? 'any';
        $expositor = is_scalar( $expositor_raw ) ? sanitize_key( (string) $expositor_raw ) : 'any';
        if ( ! in_array( $expositor, [ 'any', 'yes', 'no', 'specific' ], true ) ) $expositor = 'any';

        $expositor_ids = [];
        foreach ( (array) ( $raw['expositor_ids'] ?? [] ) as $expositor_id ) {
            if ( ! is_scalar( $expositor_id ) ) continue;
            $expositor_id = absint( $expositor_id );
            if ( $expositor_id ) $expositor_ids[] = $expositor_id;
        }
        $expositor_ids = array_values( array_unique( $expositor_ids ) );
        if ( $expositor !== 'specific' ) $expositor_ids = [];
        if ( $expositor === 'specific' && empty( $expositor_ids ) ) $expositor = 'any';

        return [
            'modalities'    => $modalities,
            'locations'     => array_values( array_unique( $locations ) ),
            'networking'    => $networking,
            'expositor'     => $expositor,
            'expositor_ids' => $expositor_ids,
        ];
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_get_settings' ) ) {
    function eventosapp_live_raffle_get_settings( $event_id ) {
        $stored = get_post_meta( absint( $event_id ), eventosapp_live_raffle_meta_key( 'settings' ), true );
        return eventosapp_live_raffle_normalize_settings( $stored );
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_get_state' ) ) {
    function eventosapp_live_raffle_get_state( $event_id ) {
        $state = get_post_meta( absint( $event_id ), eventosapp_live_raffle_meta_key( 'state' ), true );
        if ( ! is_array( $state ) ) $state = [];

        return wp_parse_args( $state, [
            'status'              => 'ready',
            'candidate_ticket_id' => 0,
            'started_at'          => 0,
            'reveal_at'           => 0,
            'eligible_count'      => 0,
            'draw_number'         => 0,
            'updated_at'          => 0,
            'updated_by'          => 0,
        ] );
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_store_state' ) ) {
    function eventosapp_live_raffle_store_state( $event_id, $state ) {
        $state = is_array( $state ) ? $state : [];
        $state['updated_at'] = time();
        $state['updated_by'] = get_current_user_id();
        update_post_meta( absint( $event_id ), eventosapp_live_raffle_meta_key( 'state' ), $state );
        return $state;
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_get_winners' ) ) {
    function eventosapp_live_raffle_get_winners( $event_id ) {
        $rows = get_post_meta( absint( $event_id ), eventosapp_live_raffle_meta_key( 'winners' ), true );
        if ( ! is_array( $rows ) ) return [];

        $out = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $ticket_id = absint( $row['ticket_id'] ?? 0 );
            $winner_id = sanitize_text_field( (string) ( $row['winner_id'] ?? '' ) );
            if ( ! $ticket_id || $winner_id === '' ) continue;

            $row['ticket_id']    = $ticket_id;
            $row['winner_id']    = $winner_id;
            $row['prize']        = sanitize_text_field( (string) ( $row['prize'] ?? '' ) );
            $row['notes']        = sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) );
            $row['confirmed_at'] = absint( $row['confirmed_at'] ?? 0 );
            $out[] = $row;
        }
        return $out;
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_is_public_active' ) ) {
    function eventosapp_live_raffle_is_public_active( $event_id ) {
        return (string) get_post_meta( absint( $event_id ), eventosapp_live_raffle_meta_key( 'public_active' ), true ) === '1';
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_normalize_match_value' ) ) {
    function eventosapp_live_raffle_normalize_match_value( $value ) {
        $value = remove_accents( trim( sanitize_text_field( (string) $value ) ) );
        $value = strtolower( $value );
        return trim( preg_replace( '/\s+/u', ' ', $value ) );
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_ticket_has_checkin' ) ) {
    function eventosapp_live_raffle_ticket_has_checkin( $ticket_id, $type, $valid_days ) {
        if ( function_exists( 'eventosapp_ticket_has_checkin_type' ) ) {
            return eventosapp_ticket_has_checkin_type( $ticket_id, $type, $valid_days );
        }

        $meta_key = $type === 'virtual' ? '_eventosapp_virtual_checkin_status' : '_eventosapp_checkin_status';
        $status   = get_post_meta( $ticket_id, $meta_key, true );
        if ( ! is_array( $status ) ) return false;

        $valid = array_fill_keys( array_map( 'strval', (array) $valid_days ), true );
        foreach ( $status as $day => $value ) {
            if ( ! in_array( $value, [ 'checked_in', 'checked-in' ], true ) ) continue;
            if ( $valid && ! isset( $valid[ $day ] ) ) continue;
            return true;
        }
        return false;
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_get_event_ticket_ids' ) ) {
    function eventosapp_live_raffle_get_event_ticket_ids( $event_id ) {
        return get_posts( [
            'post_type'      => 'eventosapp_ticket',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_query'     => [
                [
                    'key'     => '_eventosapp_ticket_evento_id',
                    'value'   => absint( $event_id ),
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ] );
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_get_networking_ticket_set' ) ) {
    function eventosapp_live_raffle_get_networking_ticket_set( $event_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'eventosapp_networking';
        $like  = $wpdb->esc_like( $table );

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) !== $table ) {
            return [];
        }

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT reader_ticket_id AS ticket_id FROM {$table} WHERE event_id = %d AND reader_ticket_id > 0
             UNION
             SELECT read_ticket_id AS ticket_id FROM {$table} WHERE event_id = %d AND read_ticket_id > 0",
            absint( $event_id ),
            absint( $event_id )
        ) );

        $set = [];
        foreach ( (array) $rows as $ticket_value ) {
            $ticket_id = absint( $ticket_value );
            if ( $ticket_id ) $set[ $ticket_id ] = true;
        }
        return $set;
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_get_expositor_ticket_map' ) ) {
    function eventosapp_live_raffle_get_expositor_ticket_map( $event_id ) {
        global $wpdb;
        $table = function_exists( 'eventosapp_expositores_table_name' )
            ? eventosapp_expositores_table_name()
            : $wpdb->prefix . 'eventosapp_expositor_deliveries';
        $like = $wpdb->esc_like( $table );

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) !== $table ) {
            return [];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ticket_id, expositor_id FROM {$table} WHERE event_id = %d GROUP BY ticket_id, expositor_id",
            absint( $event_id )
        ), ARRAY_A );

        $map = [];
        foreach ( (array) $rows as $row ) {
            $ticket_id    = absint( $row['ticket_id'] ?? 0 );
            $expositor_id = absint( $row['expositor_id'] ?? 0 );
            if ( ! $ticket_id || ! $expositor_id ) continue;
            if ( ! isset( $map[ $ticket_id ] ) ) $map[ $ticket_id ] = [];
            $map[ $ticket_id ][ $expositor_id ] = true;
        }
        return $map;
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_get_expositors' ) ) {
    function eventosapp_live_raffle_get_expositors( $event_id ) {
        $ids = function_exists( 'eventosapp_event_get_expositores' )
            ? eventosapp_event_get_expositores( $event_id )
            : [];

        $out = [];
        foreach ( (array) $ids as $expositor_id ) {
            $expositor_id = absint( $expositor_id );
            if ( ! $expositor_id ) continue;
            $name = get_post_meta( $expositor_id, '_expositor_nombre_empresa', true ) ?: get_the_title( $expositor_id );
            $out[ $expositor_id ] = $name ?: ( 'Expositor #' . $expositor_id );
        }
        natcasesort( $out );
        return $out;
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_ticket_snapshot' ) ) {
    function eventosapp_live_raffle_ticket_snapshot( $ticket_id, $physical = null, $virtual = null ) {
        $ticket_id = absint( $ticket_id );
        $name      = trim( get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true ) . ' ' . get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true ) );
        if ( $name === '' ) $name = 'Asistente #' . $ticket_id;

        $words    = preg_split( '/\s+/u', trim( $name ) );
        $initials = '';
        foreach ( array_slice( array_filter( (array) $words ), 0, 2 ) as $word ) {
            $initials .= function_exists( 'mb_substr' ) ? mb_substr( $word, 0, 1 ) : substr( $word, 0, 1 );
        }

        return [
            'ticket_id'  => $ticket_id,
            'name'       => $name,
            'initials'   => strtoupper( $initials ?: 'A' ),
            'email'      => sanitize_email( get_post_meta( $ticket_id, '_eventosapp_asistente_email', true ) ),
            'phone'      => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_tel', true ) ),
            'company'    => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_empresa', true ) ),
            'location'   => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_localidad', true ) ),
            'modality'   => function_exists( 'eventosapp_get_ticket_modalidad' ) ? eventosapp_get_ticket_modalidad( $ticket_id ) : sanitize_key( get_post_meta( $ticket_id, '_eventosapp_ticket_modalidad', true ) ),
            'physical'   => $physical === null ? false : (bool) $physical,
            'virtual'    => $virtual === null ? false : (bool) $virtual,
            'public_id'  => sanitize_text_field( get_post_meta( $ticket_id, 'eventosapp_ticketID', true ) ),
        ];
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_snapshot_cache_key' ) ) {
    function eventosapp_live_raffle_snapshot_cache_key( $event_id, $settings, $winners ) {
        $winner_ids = array_map( static function( $row ) {
            return absint( $row['ticket_id'] ?? 0 );
        }, (array) $winners );
        return 'evapp_raffle_pool_' . absint( $event_id ) . '_' . md5( wp_json_encode( [ $settings, $winner_ids ] ) );
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_build_snapshot' ) ) {
    function eventosapp_live_raffle_build_snapshot( $event_id, $settings = null, $force = false ) {
        $event_id = absint( $event_id );
        $settings = $settings === null ? eventosapp_live_raffle_get_settings( $event_id ) : eventosapp_live_raffle_normalize_settings( $settings );
        $winners  = eventosapp_live_raffle_get_winners( $event_id );
        $cache_key = eventosapp_live_raffle_snapshot_cache_key( $event_id, $settings, $winners );

        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) return $cached;
        }

        $ticket_ids     = eventosapp_live_raffle_get_event_ticket_ids( $event_id );
        $valid_days     = function_exists( 'eventosapp_get_event_days' ) ? (array) eventosapp_get_event_days( $event_id ) : [];
        $networking_set = eventosapp_live_raffle_get_networking_ticket_set( $event_id );
        $expositor_map  = eventosapp_live_raffle_get_expositor_ticket_map( $event_id );
        $expositors     = eventosapp_live_raffle_get_expositors( $event_id );
        $winner_set     = [];

        foreach ( $winners as $winner ) {
            $winner_set[ absint( $winner['ticket_id'] ) ] = true;
        }

        $location_filter = [];
        foreach ( $settings['locations'] as $location ) {
            $location_filter[ eventosapp_live_raffle_normalize_match_value( $location ) ] = true;
        }

        $selected_expositors = array_fill_keys( array_map( 'absint', $settings['expositor_ids'] ), true );
        $participants = [];
        $eligible_ids = [];
        $locations     = [];
        $counts = [
            'tickets'     => count( $ticket_ids ),
            'present'     => 0,
            'physical'    => 0,
            'virtual'     => 0,
            'both'        => 0,
            'eligible'    => 0,
            'winners'     => count( $winners ),
            'networking'  => 0,
            'expositor'   => 0,
        ];

        foreach ( $ticket_ids as $ticket_id ) {
            $ticket_id = absint( $ticket_id );
            $physical  = eventosapp_live_raffle_ticket_has_checkin( $ticket_id, 'presencial', $valid_days );
            $virtual   = eventosapp_live_raffle_ticket_has_checkin( $ticket_id, 'virtual', $valid_days );

            if ( ! $physical && ! $virtual ) continue;

            $counts['present']++;
            if ( $physical ) $counts['physical']++;
            if ( $virtual ) $counts['virtual']++;
            if ( $physical && $virtual ) $counts['both']++;

            $row = eventosapp_live_raffle_ticket_snapshot( $ticket_id, $physical, $virtual );
            if ( $row['location'] !== '' ) $locations[ $row['location'] ] = true;

            $networking = isset( $networking_set[ $ticket_id ] );
            $ticket_expositors = isset( $expositor_map[ $ticket_id ] ) ? array_map( 'absint', array_keys( $expositor_map[ $ticket_id ] ) ) : [];
            $has_expositor = ! empty( $ticket_expositors );
            if ( $networking ) $counts['networking']++;
            if ( $has_expositor ) $counts['expositor']++;

            $eligible = true;
            $reasons  = [];

            $mode_match = ( $physical && in_array( 'presencial', $settings['modalities'], true ) )
                || ( $virtual && in_array( 'virtual', $settings['modalities'], true ) );
            if ( ! $mode_match ) {
                $eligible = false;
                $reasons[] = 'Modalidad fuera del filtro';
            }

            if ( $eligible && $location_filter ) {
                $location_key = eventosapp_live_raffle_normalize_match_value( $row['location'] );
                if ( ! isset( $location_filter[ $location_key ] ) ) {
                    $eligible = false;
                    $reasons[] = 'Localidad fuera del filtro';
                }
            }

            if ( $eligible && $settings['networking'] === 'yes' && ! $networking ) {
                $eligible = false;
                $reasons[] = 'Sin participación en networking';
            }
            if ( $eligible && $settings['networking'] === 'no' && $networking ) {
                $eligible = false;
                $reasons[] = 'Participó en networking';
            }

            if ( $eligible && $settings['expositor'] === 'yes' && ! $has_expositor ) {
                $eligible = false;
                $reasons[] = 'Sin interacción con expositores';
            }
            if ( $eligible && $settings['expositor'] === 'no' && $has_expositor ) {
                $eligible = false;
                $reasons[] = 'Interactuó con expositores';
            }
            if ( $eligible && $settings['expositor'] === 'specific' ) {
                $matched = false;
                foreach ( $ticket_expositors as $expositor_id ) {
                    if ( isset( $selected_expositors[ $expositor_id ] ) ) {
                        $matched = true;
                        break;
                    }
                }
                if ( ! $matched ) {
                    $eligible = false;
                    $reasons[] = 'Sin interacción con los expositores seleccionados';
                }
            }

            if ( isset( $winner_set[ $ticket_id ] ) ) {
                $eligible = false;
                $reasons[] = 'Ganador confirmado anteriormente';
            }

            $row['networking']       = $networking;
            $row['expositor_ids']    = $ticket_expositors;
            $row['expositor_names']  = array_values( array_filter( array_map( static function( $id ) use ( $expositors ) {
                return $expositors[ $id ] ?? '';
            }, $ticket_expositors ) ) );
            $row['eligible']         = $eligible;
            $row['eligibility_note'] = $eligible ? 'Participa en el sorteo' : implode( '. ', $reasons );

            $participants[] = $row;
            if ( $eligible ) $eligible_ids[] = $ticket_id;
        }

        $counts['eligible'] = count( $eligible_ids );
        $location_names = array_keys( $locations );
        natcasesort( $location_names );

        $snapshot = [
            'settings'      => $settings,
            'counts'        => $counts,
            'participants'  => $participants,
            'eligible_ids'  => array_values( $eligible_ids ),
            'locations'     => array_values( $location_names ),
            'expositors'    => $expositors,
            'generated_at'  => time(),
        ];

        set_transient( $cache_key, $snapshot, 10 );
        return $snapshot;
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_filter_participant_page' ) ) {
    function eventosapp_live_raffle_filter_participant_page( $participants, $search = '', $page = 1, $per_page = 50 ) {
        $search = eventosapp_live_raffle_normalize_match_value( $search );
        if ( $search !== '' ) {
            $participants = array_values( array_filter( $participants, static function( $row ) use ( $search ) {
                $haystack = eventosapp_live_raffle_normalize_match_value( implode( ' ', [
                    $row['name'] ?? '',
                    $row['email'] ?? '',
                    $row['company'] ?? '',
                    $row['location'] ?? '',
                    $row['public_id'] ?? '',
                ] ) );
                return strpos( $haystack, $search ) !== false;
            } ) );
        }

        $page     = max( 1, absint( $page ) );
        $per_page = min( 100, max( 10, absint( $per_page ) ) );
        $total    = count( $participants );
        $pages    = max( 1, (int) ceil( $total / $per_page ) );
        if ( $page > $pages ) $page = $pages;

        return [
            'rows'     => array_slice( $participants, ( $page - 1 ) * $per_page, $per_page ),
            'page'     => $page,
            'pages'    => $pages,
            'total'    => $total,
            'per_page' => $per_page,
        ];
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_refresh_state' ) ) {
    function eventosapp_live_raffle_refresh_state( $event_id ) {
        $state = eventosapp_live_raffle_get_state( $event_id );
        if ( $state['status'] === 'drawing' && absint( $state['reveal_at'] ) > 0 && time() >= absint( $state['reveal_at'] ) ) {
            $state['status'] = 'candidate';
            $state = eventosapp_live_raffle_store_state( $event_id, $state );
        }
        return $state;
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_public_winner_payload' ) ) {
    function eventosapp_live_raffle_public_winner_payload( $ticket_id, $winner = [] ) {
        $row      = eventosapp_live_raffle_ticket_snapshot( $ticket_id );
        $name     = ! empty( $winner['name'] ) ? sanitize_text_field( (string) $winner['name'] ) : $row['name'];
        $company  = array_key_exists( 'company', (array) $winner ) ? sanitize_text_field( (string) $winner['company'] ) : $row['company'];
        $location = array_key_exists( 'location', (array) $winner ) ? sanitize_text_field( (string) $winner['location'] ) : $row['location'];

        $words    = preg_split( '/\s+/u', trim( $name ) );
        $initials = '';
        foreach ( array_slice( array_filter( (array) $words ), 0, 2 ) as $word ) {
            $initials .= function_exists( 'mb_substr' ) ? mb_substr( $word, 0, 1 ) : substr( $word, 0, 1 );
        }

        return [
            'winner_id' => sanitize_text_field( (string) ( $winner['winner_id'] ?? '' ) ),
            'ticket_id' => absint( $ticket_id ),
            'name'      => $name,
            'initials'  => strtoupper( $initials ?: $row['initials'] ),
            'company'   => $company,
            'location'  => $location,
            'prize'     => sanitize_text_field( (string) ( $winner['prize'] ?? '' ) ),
        ];
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_state_payload' ) ) {
    function eventosapp_live_raffle_state_payload( $event_id, $public = false ) {
        $event_id = absint( $event_id );
        $enabled  = eventosapp_live_raffle_is_enabled( $event_id );
        $active   = $enabled && eventosapp_live_raffle_is_public_active( $event_id );
        $state    = eventosapp_live_raffle_refresh_state( $event_id );
        $payload  = [
            'event_id'      => $event_id,
            'event_name'    => get_the_title( $event_id ),
            'enabled'       => $enabled,
            'public_active' => $active,
            'status'        => $active ? $state['status'] : 'inactive',
            'server_time'   => time(),
            'started_at'    => absint( $state['started_at'] ),
            'reveal_at'     => absint( $state['reveal_at'] ),
            'eligible_count'=> absint( $state['eligible_count'] ),
            'draw_number'   => absint( $state['draw_number'] ),
            'candidate'     => null,
            'winner'        => null,
        ];

        $candidate_id = absint( $state['candidate_ticket_id'] );
        if ( $candidate_id && in_array( $state['status'], [ 'candidate', 'winner' ], true ) ) {
            $payload['candidate'] = eventosapp_live_raffle_public_winner_payload( $candidate_id );
        }

        if ( $state['status'] === 'winner' && $candidate_id ) {
            $winner_row = [];
            foreach ( array_reverse( eventosapp_live_raffle_get_winners( $event_id ) ) as $row ) {
                if ( absint( $row['ticket_id'] ) === $candidate_id ) {
                    $winner_row = $row;
                    break;
                }
            }
            $payload['winner'] = eventosapp_live_raffle_public_winner_payload( $candidate_id, $winner_row );
        }

        if ( ! $public ) {
            $payload['state'] = $state;
        }

        return $payload;
    }
}

// Si se deshabilita la función desde Extras del Ticket, se apaga la pantalla pública.
add_action( 'updated_post_meta', function( $meta_id, $object_id, $meta_key, $meta_value ) {
    if ( $meta_key !== eventosapp_live_raffle_meta_key( 'enabled' ) || get_post_type( $object_id ) !== 'eventosapp_event' ) return;
    if ( (string) $meta_value !== '1' ) {
        update_post_meta( $object_id, eventosapp_live_raffle_meta_key( 'public_active' ), '0' );
        eventosapp_live_raffle_store_state( $object_id, [
            'status'              => 'ready',
            'candidate_ticket_id' => 0,
            'started_at'          => 0,
            'reveal_at'           => 0,
            'eligible_count'      => 0,
            'draw_number'         => 0,
        ] );
    }
}, 10, 4 );

// ============================================================
// AJAX PRIVADO
// ============================================================

if ( ! function_exists( 'eventosapp_live_raffle_request_scalar' ) ) {
    function eventosapp_live_raffle_request_scalar( $key, $default = '' ) {
        if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) return $default;
        return wp_unslash( (string) $_POST[ $key ] );
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_admin_request_event' ) ) {
    function eventosapp_live_raffle_admin_request_event() {
        $event_id = absint( eventosapp_live_raffle_request_scalar( 'event_id', '0' ) );
        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            wp_send_json_error( [ 'message' => 'Evento inválido.' ], 400 );
        }

        check_ajax_referer( 'eventosapp_live_raffle_admin_' . $event_id, 'nonce' );

        if ( ! eventosapp_live_raffle_user_can_view( $event_id ) ) {
            wp_send_json_error( [ 'message' => 'No tienes permisos para gestionar este sorteo.' ], 403 );
        }

        return $event_id;
    }
}

if ( ! function_exists( 'eventosapp_live_raffle_read_post_settings' ) ) {
    function eventosapp_live_raffle_read_post_settings() {
        return eventosapp_live_raffle_normalize_settings( [
            'modalities'    => isset( $_POST['modalities'] ) ? (array) wp_unslash( $_POST['modalities'] ) : [],
            'locations'     => isset( $_POST['locations'] ) ? (array) wp_unslash( $_POST['locations'] ) : [],
            'networking'    => eventosapp_live_raffle_request_scalar( 'networking', 'any' ),
            'expositor'     => eventosapp_live_raffle_request_scalar( 'expositor', 'any' ),
            'expositor_ids' => isset( $_POST['expositor_ids'] ) ? (array) wp_unslash( $_POST['expositor_ids'] ) : [],
        ] );
    }
}

add_action( 'wp_ajax_eventosapp_live_raffle_admin', function() {
    $event_id = eventosapp_live_raffle_admin_request_event();
    $op       = sanitize_key( eventosapp_live_raffle_request_scalar( 'op', 'state' ) );

    if ( $op === 'save_settings' ) {
        $settings = eventosapp_live_raffle_read_post_settings();
        update_post_meta( $event_id, eventosapp_live_raffle_meta_key( 'settings' ), $settings );
        $snapshot = eventosapp_live_raffle_build_snapshot( $event_id, $settings, true );
        wp_send_json_success( [ 'message' => 'Configuración guardada.', 'settings' => $settings, 'counts' => $snapshot['counts'] ] );
    }

    if ( $op === 'toggle_public' ) {
        $active = eventosapp_live_raffle_request_scalar( 'active', '0' ) === '1';
        update_post_meta( $event_id, eventosapp_live_raffle_meta_key( 'public_active' ), $active ? '1' : '0' );
        if ( ! $active ) {
            $state = eventosapp_live_raffle_get_state( $event_id );
            if ( $state['status'] === 'drawing' ) {
                $state['status'] = 'ready';
                $state['candidate_ticket_id'] = 0;
                $state['started_at'] = 0;
                $state['reveal_at']  = 0;
                eventosapp_live_raffle_store_state( $event_id, $state );
            }
        }
        wp_send_json_success( eventosapp_live_raffle_state_payload( $event_id ) );
    }

    if ( $op === 'start' ) {
        $settings = eventosapp_live_raffle_read_post_settings();
        update_post_meta( $event_id, eventosapp_live_raffle_meta_key( 'settings' ), $settings );

        if ( ! eventosapp_live_raffle_is_public_active( $event_id ) ) {
            wp_send_json_error( [ 'message' => 'Activa primero la pantalla pública del sorteo.' ], 409 );
        }

        $current_state = eventosapp_live_raffle_refresh_state( $event_id );
        if ( in_array( $current_state['status'], [ 'drawing', 'candidate' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Hay un sorteo en curso o un resultado pendiente de confirmar. Confirma o descarta ese resultado antes de iniciar otro.' ], 409 );
        }

        $lock_key = 'evapp_live_raffle_lock_' . $event_id;
        if ( ! add_option( $lock_key, time(), '', 'no' ) ) {
            $locked_at = absint( get_option( $lock_key, 0 ) );
            if ( $locked_at > time() - 15 ) {
                wp_send_json_error( [ 'message' => 'Ya hay un sorteo iniciándose. Actualiza el estado antes de volver a intentarlo.' ], 409 );
            }
            delete_option( $lock_key );
            add_option( $lock_key, time(), '', 'no' );
        }

        try {
            $snapshot = eventosapp_live_raffle_build_snapshot( $event_id, $settings, true );
            $eligible = array_values( array_map( 'absint', $snapshot['eligible_ids'] ) );
            if ( empty( $eligible ) ) {
                delete_option( $lock_key );
                wp_send_json_error( [ 'message' => 'No hay participantes elegibles con los filtros seleccionados.' ], 409 );
            }

            $index      = random_int( 0, count( $eligible ) - 1 );
            $ticket_id  = $eligible[ $index ];
            $old_state  = eventosapp_live_raffle_get_state( $event_id );
            $state      = [
                'status'              => 'drawing',
                'candidate_ticket_id' => $ticket_id,
                'started_at'          => time(),
                'reveal_at'           => time() + 8,
                'eligible_count'      => count( $eligible ),
                'draw_number'         => absint( $old_state['draw_number'] ) + 1,
                'draw_hash'           => wp_hash( $event_id . '|' . $ticket_id . '|' . microtime( true ) . '|' . wp_rand() ),
            ];
            eventosapp_live_raffle_store_state( $event_id, $state );
        } catch ( Throwable $e ) {
            delete_option( $lock_key );
            wp_send_json_error( [ 'message' => 'No fue posible realizar la selección aleatoria.' ], 500 );
        }

        delete_option( $lock_key );
        wp_send_json_success( eventosapp_live_raffle_state_payload( $event_id ) );
    }

    if ( $op === 'confirm' ) {
        $confirm_lock = 'evapp_live_raffle_confirm_lock_' . $event_id;
        if ( ! add_option( $confirm_lock, time(), '', 'no' ) ) {
            $locked_at = absint( get_option( $confirm_lock, 0 ) );
            if ( $locked_at > time() - 15 ) {
                wp_send_json_error( [ 'message' => 'El resultado ya está siendo confirmado por otro usuario.' ], 409 );
            }
            delete_option( $confirm_lock );
            if ( ! add_option( $confirm_lock, time(), '', 'no' ) ) {
                wp_send_json_error( [ 'message' => 'No fue posible bloquear la confirmación de forma segura.' ], 409 );
            }
        }

        $state = eventosapp_live_raffle_refresh_state( $event_id );
        if ( $state['status'] !== 'candidate' || ! absint( $state['candidate_ticket_id'] ) ) {
            delete_option( $confirm_lock );
            wp_send_json_error( [ 'message' => 'No hay un resultado provisional listo para confirmar.' ], 409 );
        }

        $ticket_id = absint( $state['candidate_ticket_id'] );
        $winners   = eventosapp_live_raffle_get_winners( $event_id );
        foreach ( $winners as $winner ) {
            if ( absint( $winner['ticket_id'] ) === $ticket_id ) {
                delete_option( $confirm_lock );
                wp_send_json_error( [ 'message' => 'Este asistente ya está registrado como ganador.' ], 409 );
            }
        }

        $ticket = eventosapp_live_raffle_ticket_snapshot( $ticket_id );
        $winner = [
            'winner_id'   => wp_generate_uuid4(),
            'ticket_id'   => $ticket_id,
            'name'        => $ticket['name'],
            'email'       => $ticket['email'],
            'phone'       => $ticket['phone'],
            'company'     => $ticket['company'],
            'location'    => $ticket['location'],
            'modality'    => $ticket['modality'],
            'public_id'   => $ticket['public_id'],
            'prize'       => sanitize_text_field( eventosapp_live_raffle_request_scalar( 'prize', '' ) ),
            'notes'       => '',
            'confirmed_at'=> time(),
            'confirmed_by'=> get_current_user_id(),
            'draw_number' => absint( $state['draw_number'] ),
        ];
        $winners[] = $winner;
        update_post_meta( $event_id, eventosapp_live_raffle_meta_key( 'winners' ), $winners );

        $state['status'] = 'winner';
        eventosapp_live_raffle_store_state( $event_id, $state );
        delete_option( $confirm_lock );
        wp_send_json_success( eventosapp_live_raffle_state_payload( $event_id ) );
    }

    if ( $op === 'retry' || $op === 'reset_stage' ) {
        $state = eventosapp_live_raffle_get_state( $event_id );
        $state['status']              = 'ready';
        $state['candidate_ticket_id'] = 0;
        $state['started_at']          = 0;
        $state['reveal_at']           = 0;
        $state['eligible_count']      = 0;
        eventosapp_live_raffle_store_state( $event_id, $state );
        wp_send_json_success( eventosapp_live_raffle_state_payload( $event_id ) );
    }

    if ( $op === 'update_winner' ) {
        $winner_id = sanitize_text_field( eventosapp_live_raffle_request_scalar( 'winner_id', '' ) );
        $winners   = eventosapp_live_raffle_get_winners( $event_id );
        $changed   = false;

        foreach ( $winners as &$winner ) {
            if ( ! hash_equals( (string) $winner['winner_id'], $winner_id ) ) continue;
            $winner['prize'] = sanitize_text_field( eventosapp_live_raffle_request_scalar( 'prize', '' ) );
            $winner['notes'] = sanitize_textarea_field( eventosapp_live_raffle_request_scalar( 'notes', '' ) );
            $winner['updated_at'] = time();
            $winner['updated_by'] = get_current_user_id();
            $changed = true;
            break;
        }
        unset( $winner );

        if ( ! $changed ) wp_send_json_error( [ 'message' => 'Ganador no encontrado.' ], 404 );
        update_post_meta( $event_id, eventosapp_live_raffle_meta_key( 'winners' ), $winners );
        wp_send_json_success( [ 'message' => 'Ganador actualizado.', 'winners' => $winners ] );
    }

    if ( $op === 'remove_winner' ) {
        $winner_id = sanitize_text_field( eventosapp_live_raffle_request_scalar( 'winner_id', '' ) );
        $winners   = eventosapp_live_raffle_get_winners( $event_id );
        $before    = count( $winners );
        $removed_ticket_id = 0;
        foreach ( $winners as $winner ) {
            if ( hash_equals( (string) ( $winner['winner_id'] ?? '' ), $winner_id ) ) {
                $removed_ticket_id = absint( $winner['ticket_id'] ?? 0 );
                break;
            }
        }
        $winners = array_values( array_filter( $winners, static function( $winner ) use ( $winner_id ) {
            return ! hash_equals( (string) ( $winner['winner_id'] ?? '' ), $winner_id );
        } ) );

        if ( count( $winners ) === $before ) wp_send_json_error( [ 'message' => 'Ganador no encontrado.' ], 404 );
        update_post_meta( $event_id, eventosapp_live_raffle_meta_key( 'winners' ), $winners );

        $state = eventosapp_live_raffle_get_state( $event_id );
        if ( $state['status'] === 'winner' && absint( $state['candidate_ticket_id'] ) === $removed_ticket_id ) {
            $state['status']              = 'ready';
            $state['candidate_ticket_id'] = 0;
            $state['started_at']          = 0;
            $state['reveal_at']           = 0;
            eventosapp_live_raffle_store_state( $event_id, $state );
        }
        wp_send_json_success( [ 'message' => 'Ganador eliminado.', 'winners' => $winners ] );
    }

    // Estado general y tabla paginada.
    $settings = eventosapp_live_raffle_get_settings( $event_id );
    $snapshot = eventosapp_live_raffle_build_snapshot( $event_id, $settings );
    $page     = eventosapp_live_raffle_filter_participant_page(
        $snapshot['participants'],
        sanitize_text_field( eventosapp_live_raffle_request_scalar( 'search', '' ) ),
        absint( eventosapp_live_raffle_request_scalar( 'page', '1' ) ),
        absint( eventosapp_live_raffle_request_scalar( 'per_page', '50' ) )
    );

    $payload = eventosapp_live_raffle_state_payload( $event_id );
    $payload['settings']       = $settings;
    $payload['eligible_count'] = absint( $snapshot['counts']['eligible'] ?? 0 );
    $payload['counts']         = $snapshot['counts'];
    $payload['participants'] = $page;
    $payload['locations']    = $snapshot['locations'];
    $payload['expositors']   = $snapshot['expositors'];
    $payload['winners']      = eventosapp_live_raffle_get_winners( $event_id );
    $payload['public_url']   = eventosapp_live_raffle_get_public_url( $event_id );
    $payload['generated_at'] = $snapshot['generated_at'];

    wp_send_json_success( $payload );
} );

if ( ! function_exists( 'eventosapp_live_raffle_public_token' ) ) {
    function eventosapp_live_raffle_public_token( $event_id ) {
        return hash_hmac( 'sha256', 'eventosapp-live-raffle|' . absint( $event_id ), wp_salt( 'nonce' ) );
    }
}

// ============================================================
// AJAX PÚBLICO
// ============================================================

add_action( 'wp_ajax_eventosapp_live_raffle_public_state', 'eventosapp_live_raffle_public_state_ajax' );
add_action( 'wp_ajax_nopriv_eventosapp_live_raffle_public_state', 'eventosapp_live_raffle_public_state_ajax' );

function eventosapp_live_raffle_public_state_ajax() {
    $event_id = absint( eventosapp_live_raffle_request_scalar( 'event_id', '0' ) );
    if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
        wp_send_json_error( [ 'message' => 'Evento inválido.' ], 400 );
    }

    $token = sanitize_text_field( eventosapp_live_raffle_request_scalar( 'nonce', '' ) );
    if ( $token === '' || ! hash_equals( eventosapp_live_raffle_public_token( $event_id ), $token ) ) {
        wp_send_json_error( [ 'message' => 'Token público inválido.' ], 403 );
    }

    wp_send_json_success( eventosapp_live_raffle_state_payload( $event_id, true ) );
}

// ============================================================
// SHORTCODE PRIVADO
// ============================================================

if ( ! function_exists( 'eventosapp_live_raffle_frontend_notice' ) ) {
    function eventosapp_live_raffle_frontend_notice( $message, $show_dashboard = true ) {
        $dashboard_url = function_exists( 'eventosapp_get_dashboard_url' )
            ? remove_query_arg( [ 'evapp', 'evapp_err', 'set' ], eventosapp_get_dashboard_url() )
            : home_url( '/' );

        $button = $show_dashboard
            ? '<a href="' . esc_url( $dashboard_url ) . '" style="min-height:44px;display:inline-flex;align-items:center;justify-content:center;margin-top:14px;padding:10px 15px;color:#182230;text-decoration:none;background:#fff;border:1px solid #dfe7f1;border-radius:12px;box-shadow:0 5px 15px rgba(31,65,99,.05);font-size:14px;font-weight:750;">Volver al dashboard</a>'
            : '';

        return '<div style="width:100%;max-width:1180px;margin:0 auto;padding:clamp(18px,3vw,36px);color:#182230;background:#f5f8fc;border:1px solid #dfe7f1;border-radius:26px;box-shadow:0 18px 50px rgba(31,52,73,.08);font-family:inherit;box-sizing:border-box;"><div style="padding:20px;background:#fff;border:1px solid #dfe7f1;border-radius:18px;box-shadow:0 8px 26px rgba(31,52,73,.05);"><strong style="display:block;margin-bottom:6px;color:#3279bd;font-size:12px;letter-spacing:.10em;text-transform:uppercase;">EVENTOSAPP · SORTEO EN VIVO</strong><div style="color:#475569;font-size:14px;line-height:1.6;">' . wp_kses_post( $message ) . '</div>' . $button . '</div></div>';
    }
}

add_shortcode( 'eventosapp_live_raffle', function() {
    if ( ! is_user_logged_in() ) {
        return eventosapp_live_raffle_frontend_notice( 'Debes iniciar sesión para gestionar el sorteo.', false );
    }

    $event_id = function_exists( 'eventosapp_get_active_event' ) ? absint( eventosapp_get_active_event() ) : 0;
    if ( ! $event_id ) {
        return eventosapp_live_raffle_frontend_notice( 'No hay un evento activo. Selecciónalo desde el dashboard para comenzar.' );
    }

    if ( ! eventosapp_live_raffle_is_enabled( $event_id ) ) {
        return eventosapp_live_raffle_frontend_notice( 'El Sorteo en Vivo no está activado para este evento. Actívalo en <strong>Funciones Extra del Ticket</strong>.' );
    }

    if ( ! eventosapp_live_raffle_user_can_view( $event_id ) ) {
        return eventosapp_live_raffle_frontend_notice( 'No tienes permisos para gestionar el Sorteo en Vivo de este evento.' );
    }

    $settings    = eventosapp_live_raffle_get_settings( $event_id );
    $snapshot    = eventosapp_live_raffle_build_snapshot( $event_id, $settings );
    $expositors  = $snapshot['expositors'];
    $locations   = $snapshot['locations'];
    $public_url  = eventosapp_live_raffle_get_public_url( $event_id );
    $nonce       = wp_create_nonce( 'eventosapp_live_raffle_admin_' . $event_id );
    $ajax_url      = admin_url( 'admin-ajax.php' );
    $dashboard_url = function_exists( 'eventosapp_get_dashboard_url' )
        ? remove_query_arg( [ 'evapp', 'evapp_err', 'set' ], eventosapp_get_dashboard_url() )
        : home_url( '/' );

    ob_start();
    ?>
    <style id="eventosapp-live-raffle-admin-ui">
    .evapp-raffle-admin{
        --evapp-primary:#3279bd;
        --evapp-primary-dark:#255f96;
        --evapp-primary-soft:#eaf4ff;
        --evapp-app-bg:#f5f8fc;
        --evapp-surface:#ffffff;
        --evapp-border:#dfe7f1;
        --evapp-text:#182230;
        --evapp-muted:#64748b;
        --evapp-success:#15803d;
        --evapp-success-soft:#ecfdf3;
        --evapp-warning:#b45309;
        --evapp-warning-soft:#fff8e7;
        --evapp-danger:#b42318;
        --evapp-danger-soft:#fff1f0;
        --evapp-radius:18px;
        --evapp-radius-lg:26px;
        width:100%;
        max-width:1180px;
        margin:0 auto;
        padding:clamp(18px,3vw,36px);
        color:var(--evapp-text);
        background:var(--evapp-app-bg);
        border:1px solid var(--evapp-border);
        border-radius:var(--evapp-radius-lg);
        box-shadow:0 18px 50px rgba(31,52,73,.08);
        font-family:inherit;
        line-height:1.45;
        box-sizing:border-box;
        isolation:isolate;
    }
    .evapp-raffle-admin *,
    .evapp-raffle-admin *::before,
    .evapp-raffle-admin *::after{box-sizing:border-box}
    .evapp-raffle-admin a{text-decoration:none}
    .evapp-raffle-admin button,
    .evapp-raffle-admin input,
    .evapp-raffle-admin select,
    .evapp-raffle-admin textarea{font:inherit}
    .evapp-raffle-admin svg{
        display:block;
        fill:none;
        stroke:currentColor;
        stroke-width:2;
        stroke-linecap:round;
        stroke-linejoin:round;
    }
    .evapp-raffle-head{
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:24px;
        margin-bottom:20px;
    }
    .evapp-raffle-heading{min-width:0}
    .evapp-raffle-head .evapp-raffle-eyebrow{
        margin:0 0 6px;
        color:var(--evapp-primary);
        font-size:12px;
        font-weight:800;
        letter-spacing:.11em;
        text-transform:uppercase;
    }
    .evapp-raffle-head h1{
        margin:0;
        color:var(--evapp-text);
        font-size:clamp(27px,4vw,42px);
        font-weight:850;
        line-height:1.08;
        letter-spacing:-.035em;
    }
    .evapp-raffle-head p{
        max-width:760px;
        margin:10px 0 0;
        color:var(--evapp-muted);
        font-size:15px;
        line-height:1.6;
    }
    .evapp-raffle-head-actions{
        flex:0 0 auto;
        display:flex;
        align-items:center;
        justify-content:flex-end;
        flex-wrap:wrap;
        gap:10px;
    }
    .evapp-raffle-live-toggle{
        min-height:44px;
        display:inline-flex;
        align-items:center;
        gap:9px;
        padding:9px 13px;
        color:var(--evapp-muted);
        background:var(--evapp-surface);
        border:1px solid var(--evapp-border);
        border-radius:999px;
        box-shadow:0 5px 15px rgba(31,65,99,.05);
        font-size:12px;
        white-space:nowrap;
    }
    .evapp-raffle-live-dot{
        width:9px;
        height:9px;
        flex:0 0 9px;
        border-radius:50%;
        background:#94a3b8;
        box-shadow:0 0 0 4px rgba(148,163,184,.14);
    }
    .evapp-raffle-live-toggle.is-active{
        color:#166534;
        background:var(--evapp-success-soft);
        border-color:#c8ead4;
    }
    .evapp-raffle-live-toggle.is-active .evapp-raffle-live-dot{
        background:#22c55e;
        box-shadow:0 0 0 4px rgba(34,197,94,.13),0 0 14px rgba(34,197,94,.45);
    }
    .evapp-raffle-event-context{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:16px;
        margin-bottom:20px;
        padding:15px 16px;
        background:var(--evapp-surface);
        border:1px solid var(--evapp-border);
        border-radius:var(--evapp-radius);
        box-shadow:0 8px 24px rgba(31,52,73,.045);
    }
    .evapp-raffle-event-main{
        min-width:0;
        display:flex;
        align-items:center;
        gap:13px;
    }
    .evapp-raffle-event-icon{
        width:46px;
        height:46px;
        flex:0 0 46px;
        display:grid;
        place-items:center;
        color:var(--evapp-primary);
        background:var(--evapp-primary-soft);
        border-radius:14px;
    }
    .evapp-raffle-event-icon svg{width:23px;height:23px}
    .evapp-raffle-event-copy{min-width:0}
    .evapp-raffle-event-kicker{
        display:block;
        margin-bottom:2px;
        color:var(--evapp-muted);
        font-size:11px;
        font-weight:800;
        letter-spacing:.08em;
        text-transform:uppercase;
    }
    .evapp-raffle-event-name{
        display:block;
        overflow:hidden;
        color:var(--evapp-text);
        font-size:15px;
        font-weight:820;
        line-height:1.3;
        text-overflow:ellipsis;
        white-space:nowrap;
    }
    .evapp-raffle-event-meta{
        display:flex;
        align-items:center;
        justify-content:flex-end;
        flex-wrap:wrap;
        gap:8px;
    }
    .evapp-raffle-chip{
        min-height:30px;
        display:inline-flex;
        align-items:center;
        gap:6px;
        padding:6px 10px;
        color:var(--evapp-muted);
        background:#fff;
        border:1px solid var(--evapp-border);
        border-radius:999px;
        font-size:12px;
        font-weight:730;
        white-space:nowrap;
    }
    .evapp-raffle-chip strong{color:var(--evapp-text);font-weight:850}
    .evapp-raffle-panel{
        min-width:0;
        margin-bottom:16px;
        padding:20px;
        background:var(--evapp-surface);
        border:1px solid var(--evapp-border);
        border-radius:var(--evapp-radius);
        box-shadow:0 8px 26px rgba(31,52,73,.05);
    }
    .evapp-raffle-panel h2,
    .evapp-raffle-panel h3{
        margin:0 0 5px;
        color:var(--evapp-text);
        font-size:17px;
        font-weight:820;
        line-height:1.3;
    }
    .evapp-raffle-panel-intro{margin:0 0 15px;color:var(--evapp-muted);font-size:13px;line-height:1.5}
    .evapp-raffle-layout{
        display:grid;
        grid-template-columns:minmax(300px,350px) minmax(0,1fr);
        gap:16px;
        align-items:start;
        width:100%;
    }
    .evapp-raffle-layout>aside,
    .evapp-raffle-layout>main{min-width:0}
    .evapp-raffle-field{margin-bottom:17px}
    .evapp-raffle-field>label,
    .evapp-raffle-label{
        display:block;
        margin-bottom:7px;
        color:var(--evapp-text);
        font-size:12px;
        font-weight:800;
    }
    .evapp-raffle-help{
        display:block;
        margin-top:6px;
        color:var(--evapp-muted);
        font-size:11px;
        line-height:1.5;
    }
    .evapp-raffle-checks{display:grid;gap:8px}
    .evapp-raffle-check{
        min-height:46px;
        display:flex;
        align-items:center;
        gap:10px;
        padding:10px 11px;
        color:var(--evapp-text);
        background:#fbfdff;
        border:1px solid var(--evapp-border);
        border-radius:13px;
        cursor:pointer;
        transition:border-color .16s ease,background .16s ease,box-shadow .16s ease;
    }
    .evapp-raffle-check:hover{border-color:#c7d7e8;background:#fff}
    .evapp-raffle-check input{width:17px;height:17px;margin:0;accent-color:var(--evapp-primary)}
    .evapp-raffle-select,
    .evapp-raffle-url,
    .evapp-raffle-search,
    .evapp-raffle-winner input,
    .evapp-raffle-winner textarea{
        width:100%;
        margin:0;
        color:var(--evapp-text);
        background:#fff;
        border:1px solid var(--evapp-border);
        border-radius:13px;
        box-shadow:none;
        outline:none;
        transition:border-color .16s ease,box-shadow .16s ease;
    }
    .evapp-raffle-select{min-height:46px;padding:9px 11px}
    .evapp-raffle-select[multiple]{min-height:132px;padding:7px}
    .evapp-raffle-url,
    .evapp-raffle-search{min-height:46px;padding:10px 12px}
    .evapp-raffle-url{min-width:0;background:#fbfdff}
    .evapp-raffle-select:focus,
    .evapp-raffle-url:focus,
    .evapp-raffle-search:focus,
    .evapp-raffle-winner input:focus,
    .evapp-raffle-winner textarea:focus,
    .evapp-raffle-prize:focus{
        border-color:var(--evapp-primary);
        box-shadow:0 0 0 4px rgba(50,121,189,.12);
    }
    .evapp-raffle-actions{display:flex;align-items:center;flex-wrap:wrap;gap:9px}
    .evapp-raffle-btn{
        min-height:44px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:8px;
        margin:0;
        padding:10px 15px;
        appearance:none;
        color:var(--evapp-text)!important;
        background:var(--evapp-surface);
        border:1px solid var(--evapp-border);
        border-radius:12px;
        box-shadow:0 5px 15px rgba(31,65,99,.05);
        font-size:14px;
        font-weight:750;
        line-height:1.15;
        text-align:center;
        cursor:pointer;
        transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease,background .16s ease,color .16s ease,opacity .16s ease;
        -webkit-tap-highlight-color:transparent;
    }
    .evapp-raffle-btn svg{width:18px;height:18px;flex:0 0 18px}
    .evapp-raffle-btn:hover:not(:disabled){transform:translateY(-1px);border-color:#c7d7e8;box-shadow:0 9px 20px rgba(31,65,99,.09)}
    .evapp-raffle-btn:focus-visible,
    .evapp-raffle-check:focus-within,
    .evapp-raffle-select:focus-visible,
    .evapp-raffle-search:focus-visible,
    .evapp-raffle-url:focus-visible{
        outline:3px solid rgba(50,121,189,.20);
        outline-offset:2px;
    }
    .evapp-raffle-btn:disabled{opacity:.5;cursor:not-allowed;transform:none!important;box-shadow:none!important}
    .evapp-raffle-btn.primary{color:#fff!important;background:var(--evapp-primary);border-color:var(--evapp-primary);box-shadow:0 9px 20px rgba(50,121,189,.18)}
    .evapp-raffle-btn.primary:hover:not(:disabled){background:var(--evapp-primary-dark);border-color:var(--evapp-primary-dark);box-shadow:0 12px 24px rgba(50,121,189,.24)}
    .evapp-raffle-btn.success{color:#fff!important;background:var(--evapp-success);border-color:var(--evapp-success)}
    .evapp-raffle-btn.warning{color:#fff!important;background:var(--evapp-warning);border-color:var(--evapp-warning)}
    .evapp-raffle-btn.danger{color:#fff!important;background:var(--evapp-danger);border-color:var(--evapp-danger)}
    .evapp-raffle-btn.large{min-height:50px;padding:12px 19px;font-size:15px}
    .evapp-raffle-url-row{
        display:grid;
        grid-template-columns:minmax(0,1fr) auto auto;
        gap:8px;
        align-items:stretch;
    }
    .evapp-raffle-public-controls{margin-top:12px}
    .evapp-raffle-stats{
        display:grid;
        grid-template-columns:repeat(4,minmax(0,1fr));
        gap:10px;
        margin-bottom:16px;
    }
    .evapp-raffle-stat{
        min-width:0;
        min-height:108px;
        display:flex;
        flex-direction:column;
        justify-content:center;
        padding:15px;
        background:var(--evapp-surface);
        border:1px solid var(--evapp-border);
        border-radius:16px;
        box-shadow:0 6px 18px rgba(31,52,73,.045);
    }
    .evapp-raffle-stat strong{
        display:block;
        color:var(--evapp-text);
        font-size:clamp(24px,3vw,34px);
        font-weight:860;
        line-height:1;
        letter-spacing:-.03em;
    }
    .evapp-raffle-stat span{display:block;margin-top:8px;color:var(--evapp-muted);font-size:11px;line-height:1.35}
    .evapp-raffle-stage{
        position:relative;
        min-height:360px;
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        overflow:hidden;
        margin-bottom:16px;
        padding:clamp(22px,4vw,34px);
        color:#fff;
        background:
            radial-gradient(circle at 15% 12%,rgba(105,183,255,.28),transparent 34%),
            radial-gradient(circle at 88% 84%,rgba(92,151,210,.24),transparent 38%),
            linear-gradient(145deg,#12283d,#1f5f96 58%,#2c78b9);
        border:1px solid rgba(255,255,255,.16);
        border-radius:22px;
        box-shadow:0 18px 40px rgba(31,73,112,.22);
        text-align:center;
    }
    .evapp-raffle-stage::before,
    .evapp-raffle-stage::after{
        position:absolute;
        width:180px;
        height:180px;
        border:1px solid rgba(255,255,255,.12);
        border-radius:38%;
        content:"";
        animation:evappRaffleFloat 8s ease-in-out infinite;
    }
    .evapp-raffle-stage::before{top:-86px;left:-58px;transform:rotate(20deg)}
    .evapp-raffle-stage::after{right:-72px;bottom:-94px;animation-delay:-3s}
    @keyframes evappRaffleFloat{50%{transform:translateY(14px) rotate(42deg)}}
    .evapp-raffle-stage-content{position:relative;z-index:2;width:100%}
    .evapp-raffle-stage-kicker{
        color:#d9edff;
        font-size:11px;
        font-weight:850;
        letter-spacing:.18em;
        text-transform:uppercase;
    }
    .evapp-raffle-stage-name{
        max-width:820px;
        margin:14px auto 8px;
        color:#fff;
        font-size:clamp(32px,5vw,58px);
        font-weight:900;
        line-height:1.05;
        letter-spacing:-.035em;
        overflow-wrap:anywhere;
    }
    .evapp-raffle-stage-meta{max-width:800px;margin:0 auto;color:#d8e7f5;font-size:clamp(14px,2vw,18px);line-height:1.45}
    .evapp-raffle-spinner{
        width:82px;
        height:82px;
        margin:20px auto;
        border:7px solid rgba(255,255,255,.20);
        border-top-color:#fff;
        border-right-color:#8fd0ff;
        border-radius:50%;
        animation:evappRaffleSpin .75s linear infinite;
    }
    @keyframes evappRaffleSpin{to{transform:rotate(360deg)}}
    .evapp-raffle-stage-actions{
        position:relative;
        z-index:3;
        width:min(900px,100%);
        display:flex;
        align-items:center;
        justify-content:center;
        flex-wrap:wrap;
        gap:9px;
        margin-top:22px;
    }
    .evapp-raffle-stage .evapp-raffle-btn:not(.primary):not(.success):not(.warning):not(.danger){color:#fff!important;background:rgba(255,255,255,.10);border-color:rgba(255,255,255,.28);box-shadow:none}
    .evapp-raffle-stage .evapp-raffle-btn:not(.primary):not(.success):not(.warning):not(.danger):hover{background:rgba(255,255,255,.16);border-color:rgba(255,255,255,.38)}
    .evapp-raffle-prize{
        width:min(360px,100%);
        min-height:50px;
        padding:11px 13px;
        color:#fff;
        background:rgba(255,255,255,.11);
        border:1px solid rgba(255,255,255,.32);
        border-radius:12px;
        outline:none;
        text-align:center;
    }
    .evapp-raffle-prize::placeholder{color:rgba(255,255,255,.68)}
    .evapp-raffle-table-tools{
        display:flex;
        align-items:center;
        justify-content:space-between;
        flex-wrap:wrap;
        gap:14px;
        margin-bottom:13px;
    }
    .evapp-raffle-table-tools>div{min-width:0;flex:1 1 320px}
    .evapp-raffle-search{min-width:0;max-width:430px;flex:1 1 280px}
    .evapp-raffle-table-wrap{
        width:100%;
        max-width:100%;
        overflow:auto;
        border:1px solid var(--evapp-border);
        border-radius:14px;
        background:#fff;
        -webkit-overflow-scrolling:touch;
    }
    .evapp-raffle-table{
        width:100%;
        min-width:820px;
        border-collapse:collapse;
        color:var(--evapp-text);
        font-size:12px;
    }
    .evapp-raffle-table th,
    .evapp-raffle-table td{padding:11px 12px;border-bottom:1px solid #edf2f7;text-align:left;vertical-align:top}
    .evapp-raffle-table tbody tr:last-child td{border-bottom:0}
    .evapp-raffle-table tbody tr:hover{background:#fbfdff}
    .evapp-raffle-table th{
        position:sticky;
        top:0;
        z-index:1;
        color:#475569;
        background:#f8fafc;
        font-size:11px;
        font-weight:800;
        letter-spacing:.02em;
    }
    .evapp-raffle-badge{
        display:inline-flex;
        align-items:center;
        max-width:100%;
        margin:1px 3px 1px 0;
        padding:4px 8px;
        color:#475569;
        background:#f1f5f9;
        border-radius:999px;
        font-size:10px;
        font-weight:800;
        line-height:1.3;
        white-space:normal;
    }
    .evapp-raffle-badge.yes{color:#166534;background:#dcfce7}
    .evapp-raffle-badge.no{color:#991b1b;background:#fee2e2}
    .evapp-raffle-badge.info{color:#1d4f7a;background:var(--evapp-primary-soft)}
    .evapp-raffle-pagination{
        display:flex;
        align-items:center;
        justify-content:center;
        flex-wrap:wrap;
        gap:10px;
        margin-top:13px;
        color:var(--evapp-muted);
        font-size:12px;
    }
    .evapp-raffle-winners{display:grid;gap:10px}
    .evapp-raffle-winner{
        min-width:0;
        padding:14px;
        background:#fff;
        border:1px solid var(--evapp-border);
        border-radius:14px;
        box-shadow:0 5px 16px rgba(31,52,73,.035);
    }
    .evapp-raffle-winner-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
    .evapp-raffle-winner h4{margin:0 0 4px;color:var(--evapp-text);font-size:14px;line-height:1.35}
    .evapp-raffle-winner small{display:block;color:var(--evapp-muted);font-size:10px;line-height:1.45;overflow-wrap:anywhere}
    .evapp-raffle-winner-grid{display:grid;grid-template-columns:1fr;gap:10px;margin-top:12px}
    .evapp-raffle-winner label{display:grid;gap:6px;color:var(--evapp-text);font-size:11px;font-weight:800}
    .evapp-raffle-winner input{min-height:42px;padding:9px 10px}
    .evapp-raffle-winner textarea{min-height:78px;padding:9px 10px;line-height:1.45;resize:vertical}
    .evapp-save-winner{margin-top:10px}
    .evapp-raffle-empty{
        padding:22px 16px;
        color:var(--evapp-muted);
        background:#fbfdff;
        border:1px dashed var(--evapp-border);
        border-radius:13px;
        text-align:center;
        font-size:13px;
        line-height:1.5;
    }
    .evapp-raffle-toast{
        position:fixed;
        right:22px;
        bottom:22px;
        z-index:99999;
        width:min(420px,calc(100vw - 32px));
        max-width:420px;
        padding:13px 16px;
        color:#fff;
        background:#172536;
        border-radius:12px;
        box-shadow:0 12px 35px rgba(0,0,0,.25);
        opacity:0;
        transform:translateY(15px);
        pointer-events:none;
        transition:opacity .2s ease,transform .2s ease;
    }
    .evapp-raffle-toast.show{opacity:1;transform:none}
    .evapp-raffle-toast.error{background:#991b1b}
    .evapp-raffle-loading{cursor:progress}
    .evapp-raffle-loading .evapp-raffle-btn{pointer-events:none;opacity:.62}
    .evapp-raffle-notice{padding:16px;border:1px solid #f0c36d;background:#fff8e5;border-radius:12px;color:#7c4b00}
    .evapp-raffle-specific{display:none;margin-top:8px}
    .evapp-raffle-specific.show{display:block}
    @media(max-width:1050px){
        .evapp-raffle-layout{grid-template-columns:1fr}
        .evapp-raffle-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
        .evapp-raffle-winners{grid-template-columns:repeat(2,minmax(0,1fr))}
    }
    @media(max-width:767px){
        .evapp-raffle-admin{padding:16px;border-radius:20px}
        .evapp-raffle-head{display:block;margin-bottom:18px}
        .evapp-raffle-head-actions{justify-content:flex-start;margin-top:14px}
        .evapp-raffle-head-actions .evapp-raffle-btn{flex:1 1 190px}
        .evapp-raffle-live-toggle{flex:1 1 190px;justify-content:center;white-space:normal;text-align:center}
        .evapp-raffle-event-context{align-items:flex-start;flex-direction:column;padding:14px}
        .evapp-raffle-event-meta{width:100%;justify-content:flex-start}
        .evapp-raffle-event-name{white-space:normal;overflow:visible}
        .evapp-raffle-url-row{grid-template-columns:1fr}
        .evapp-raffle-url-row .evapp-raffle-btn{width:100%}
        .evapp-raffle-public-controls .evapp-raffle-btn{flex:1 1 180px}
        .evapp-raffle-stage{min-height:390px;padding:22px 16px}
        .evapp-raffle-stage-actions{display:grid;grid-template-columns:1fr;width:100%}
        .evapp-raffle-stage-actions>*{width:100%!important;max-width:none}
        .evapp-raffle-table-tools{align-items:stretch}
        .evapp-raffle-search{max-width:none;flex-basis:100%}
        .evapp-raffle-winners{grid-template-columns:1fr}
    }
    @media(max-width:520px){
        .evapp-raffle-stats{grid-template-columns:1fr}
        .evapp-raffle-stat{min-height:92px}
        .evapp-raffle-panel{padding:16px}
        .evapp-raffle-winner-head{display:block}
        .evapp-raffle-winner-head .evapp-raffle-btn{width:100%;margin-top:10px}
        .evapp-raffle-pagination{display:grid;grid-template-columns:1fr 1fr;width:100%}
        .evapp-raffle-pagination span{grid-column:1/-1;grid-row:1;text-align:center}
        .evapp-raffle-pagination .evapp-raffle-btn{width:100%}
    }
    @media(prefers-reduced-motion:reduce){
        .evapp-raffle-admin *,
        .evapp-raffle-admin *::before,
        .evapp-raffle-admin *::after{scroll-behavior:auto!important;animation:none!important;transition:none!important}
    }
    </style>

    <div class="evapp-raffle-admin" id="evapp-live-raffle-admin"
         data-event-id="<?php echo esc_attr( $event_id ); ?>"
         data-nonce="<?php echo esc_attr( $nonce ); ?>"
         data-ajax-url="<?php echo esc_url( $ajax_url ); ?>">
        <header class="evapp-raffle-head">
            <div class="evapp-raffle-heading">
                <p class="evapp-raffle-eyebrow">EVENTOSAPP</p>
                <h1>Sorteo en Vivo</h1>
                <p>Gestiona la segmentación, ejecuta la selección aleatoria y controla la pantalla pública sin salir del flujo operativo del evento.</p>
            </div>
            <div class="evapp-raffle-head-actions">
                <div class="evapp-raffle-live-toggle" id="evapp-raffle-live-status" role="status" aria-live="polite">
                    <span class="evapp-raffle-live-dot" aria-hidden="true"></span>
                    <strong id="evapp-raffle-live-label">Pantalla pública desactivada</strong>
                </div>
                <a class="evapp-raffle-btn" href="<?php echo esc_url( $dashboard_url ); ?>" aria-label="Volver al dashboard">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg>
                    <span>Volver al dashboard</span>
                </a>
            </div>
        </header>

        <section class="evapp-raffle-event-context" aria-label="Evento activo">
            <div class="evapp-raffle-event-main">
                <span class="evapp-raffle-event-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M5 4h14v5a7 7 0 0 1-14 0V4Z"></path><path d="M8 20h8M12 16v4M5 7H2v1a4 4 0 0 0 4 4M19 7h3v1a4 4 0 0 1-4 4"></path></svg>
                </span>
                <div class="evapp-raffle-event-copy">
                    <span class="evapp-raffle-event-kicker">Evento activo</span>
                    <strong class="evapp-raffle-event-name"><?php echo esc_html( get_the_title( $event_id ) ); ?></strong>
                </div>
            </div>
            <div class="evapp-raffle-event-meta">
                <span class="evapp-raffle-chip"><strong id="evapp-header-eligible"><?php echo esc_html( absint( $snapshot['counts']['eligible'] ?? 0 ) ); ?></strong> elegibles</span>
                <span class="evapp-raffle-chip"><strong id="evapp-header-winners"><?php echo esc_html( count( eventosapp_live_raffle_get_winners( $event_id ) ) ); ?></strong> ganadores</span>
            </div>
        </section>

        <div class="evapp-raffle-panel">
            <h2>Pantalla pública para asistentes y proyección</h2>
            <p class="evapp-raffle-panel-intro">Comparte esta dirección o ábrela en la pantalla que usarás para mostrar el sorteo en tiempo real.</p>
            <?php if ( $public_url ) : ?>
                <div class="evapp-raffle-url-row">
                    <input class="evapp-raffle-url" id="evapp-raffle-public-url" value="<?php echo esc_attr( $public_url ); ?>" aria-label="URL pública del sorteo" readonly>
                    <button type="button" class="evapp-raffle-btn" id="evapp-raffle-copy-url">Copiar URL</button>
                    <a class="evapp-raffle-btn primary" href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener">Abrir pantalla</a>
                </div>
            <?php else : ?>
                <div class="evapp-raffle-empty">Configura la <strong>Página Pública del Sorteo en Vivo</strong> en EventosApp → Configuración.</div>
            <?php endif; ?>
            <div class="evapp-raffle-actions evapp-raffle-public-controls">
                <button type="button" class="evapp-raffle-btn success" id="evapp-raffle-activate">Activar sorteo público</button>
                <button type="button" class="evapp-raffle-btn danger" id="evapp-raffle-deactivate">Desactivar sorteo público</button>
            </div>
        </div>

        <div class="evapp-raffle-layout">
            <aside>
                <form class="evapp-raffle-panel" id="evapp-raffle-settings">
                    <h2>Configuración y segmentación</h2>
                    <p class="evapp-raffle-panel-intro">Define quiénes pueden entrar al siguiente sorteo. Los ganadores confirmados quedan excluidos automáticamente.</p>
                    <div class="evapp-raffle-field">
                        <span class="evapp-raffle-label">Modalidad de check-in</span>
                        <div class="evapp-raffle-checks">
                            <label class="evapp-raffle-check"><input type="checkbox" name="modalities[]" value="presencial" <?php checked( in_array( 'presencial', $settings['modalities'], true ) ); ?>> Check-in presencial</label>
                            <label class="evapp-raffle-check"><input type="checkbox" name="modalities[]" value="virtual" <?php checked( in_array( 'virtual', $settings['modalities'], true ) ); ?>> Check-in virtual</label>
                        </div>
                        <small class="evapp-raffle-help">Al seleccionar las dos, participan quienes tengan cualquiera de los dos check-ins.</small>
                    </div>

                    <div class="evapp-raffle-field">
                        <label for="evapp-raffle-locations">Localidades</label>
                        <select class="evapp-raffle-select" id="evapp-raffle-locations" name="locations[]" multiple>
                            <?php foreach ( $locations as $location ) : ?>
                                <option value="<?php echo esc_attr( $location ); ?>" <?php selected( in_array( $location, $settings['locations'], true ) ); ?>><?php echo esc_html( $location ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="evapp-raffle-help">Sin selección incluye todas las localidades.</small>
                    </div>

                    <div class="evapp-raffle-field">
                        <label for="evapp-raffle-networking">Participación en networking</label>
                        <select class="evapp-raffle-select" id="evapp-raffle-networking" name="networking">
                            <option value="any" <?php selected( $settings['networking'], 'any' ); ?>>Sin filtro</option>
                            <option value="yes" <?php selected( $settings['networking'], 'yes' ); ?>>Sí participó</option>
                            <option value="no" <?php selected( $settings['networking'], 'no' ); ?>>No participó</option>
                        </select>
                    </div>

                    <div class="evapp-raffle-field">
                        <label for="evapp-raffle-expositor-mode">Interacción o transacción con expositores</label>
                        <select class="evapp-raffle-select" id="evapp-raffle-expositor-mode" name="expositor">
                            <option value="any" <?php selected( $settings['expositor'], 'any' ); ?>>Sin filtro</option>
                            <option value="yes" <?php selected( $settings['expositor'], 'yes' ); ?>>Con cualquier expositor</option>
                            <option value="no" <?php selected( $settings['expositor'], 'no' ); ?>>Sin interacción con expositores</option>
                            <option value="specific" <?php selected( $settings['expositor'], 'specific' ); ?>>Con expositores específicos</option>
                        </select>
                        <div class="evapp-raffle-specific <?php echo $settings['expositor'] === 'specific' ? 'show' : ''; ?>" id="evapp-raffle-specific-wrap">
                            <select class="evapp-raffle-select" id="evapp-raffle-expositors" name="expositor_ids[]" multiple>
                                <?php foreach ( $expositors as $expositor_id => $name ) : ?>
                                    <option value="<?php echo esc_attr( $expositor_id ); ?>" <?php selected( in_array( absint( $expositor_id ), $settings['expositor_ids'], true ) ); ?>><?php echo esc_html( $name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="evapp-raffle-help">Se incluye a quien haya interactuado con al menos uno de los expositores seleccionados.</small>
                        </div>
                    </div>

                    <div class="evapp-raffle-actions">
                        <button type="button" class="evapp-raffle-btn primary" id="evapp-raffle-save-settings">Guardar configuración</button>
                    </div>
                </form>

                <div class="evapp-raffle-panel">
                    <h3>Ganadores confirmados</h3>
                    <p class="evapp-raffle-panel-intro">Edita el premio o las notas sin alterar el resultado guardado.</p>
                    <div class="evapp-raffle-winners" id="evapp-raffle-winners"><div class="evapp-raffle-empty">Cargando ganadores…</div></div>
                </div>
            </aside>

            <main>
                <div class="evapp-raffle-stats">
                    <div class="evapp-raffle-stat"><strong id="evapp-count-present">0</strong><span>Asistentes con check-in</span></div>
                    <div class="evapp-raffle-stat"><strong id="evapp-count-eligible">0</strong><span>Participantes elegibles</span></div>
                    <div class="evapp-raffle-stat"><strong id="evapp-count-physical">0</strong><span>Check-in presencial</span></div>
                    <div class="evapp-raffle-stat"><strong id="evapp-count-virtual">0</strong><span>Check-in virtual</span></div>
                </div>

                <div class="evapp-raffle-stage" id="evapp-raffle-stage" aria-live="polite" aria-atomic="true">
                    <div class="evapp-raffle-stage-content">
                        <div class="evapp-raffle-stage-kicker" id="evapp-stage-kicker">Sorteo preparado</div>
                        <div id="evapp-stage-spinner"></div>
                        <div class="evapp-raffle-stage-name" id="evapp-stage-name">Listo para comenzar</div>
                        <div class="evapp-raffle-stage-meta" id="evapp-stage-meta">Configura los filtros y activa la pantalla pública.</div>
                    </div>
                    <div class="evapp-raffle-stage-actions">
                        <button type="button" class="evapp-raffle-btn primary large" id="evapp-raffle-start">Iniciar sorteo</button>
                        <input type="text" class="evapp-raffle-prize" id="evapp-raffle-prize" aria-label="Premio o categoría del ganador" placeholder="Premio o categoría (opcional)">
                        <button type="button" class="evapp-raffle-btn success large" id="evapp-raffle-confirm" hidden>Confirmar ganador</button>
                        <button type="button" class="evapp-raffle-btn warning large" id="evapp-raffle-retry" hidden>No confirmar e intentar de nuevo</button>
                        <button type="button" class="evapp-raffle-btn" id="evapp-raffle-reset">Limpiar pantalla</button>
                    </div>
                </div>

                <div class="evapp-raffle-panel">
                    <div class="evapp-raffle-table-tools">
                        <div>
                            <h2>Asistentes presentes y participantes</h2>
                            <span class="evapp-raffle-help" id="evapp-raffle-table-summary">Actualización en tiempo real.</span>
                        </div>
                        <input class="evapp-raffle-search" id="evapp-raffle-search" type="search" aria-label="Buscar asistentes presentes" placeholder="Buscar nombre, correo, empresa, localidad o ticket">
                    </div>
                    <div class="evapp-raffle-table-wrap">
                        <table class="evapp-raffle-table">
                            <thead><tr><th>Asistente</th><th>Check-in</th><th>Localidad</th><th>Networking</th><th>Expositores</th><th>Estado</th></tr></thead>
                            <tbody id="evapp-raffle-participants"><tr><td colspan="6">Cargando asistentes…</td></tr></tbody>
                        </table>
                    </div>
                    <div class="evapp-raffle-pagination">
                        <button type="button" class="evapp-raffle-btn" id="evapp-raffle-prev">Anterior</button>
                        <span id="evapp-raffle-page-label">Página 1 de 1</span>
                        <button type="button" class="evapp-raffle-btn" id="evapp-raffle-next">Siguiente</button>
                    </div>
                </div>
            </main>
        </div>
        <div class="evapp-raffle-toast" id="evapp-raffle-toast"></div>
    </div>

    <script>
    (function(){
        'use strict';
        const root = document.getElementById('evapp-live-raffle-admin');
        if (!root) return;

        const ajaxUrl = root.dataset.ajaxUrl;
        const eventId = root.dataset.eventId;
        const nonce = root.dataset.nonce;
        const settingsForm = document.getElementById('evapp-raffle-settings');
        const els = {
            liveStatus: document.getElementById('evapp-raffle-live-status'), liveLabel: document.getElementById('evapp-raffle-live-label'),
            stageKicker: document.getElementById('evapp-stage-kicker'), stageSpinner: document.getElementById('evapp-stage-spinner'),
            stageName: document.getElementById('evapp-stage-name'), stageMeta: document.getElementById('evapp-stage-meta'),
            start: document.getElementById('evapp-raffle-start'), confirm: document.getElementById('evapp-raffle-confirm'), retry: document.getElementById('evapp-raffle-retry'), reset: document.getElementById('evapp-raffle-reset'),
            prize: document.getElementById('evapp-raffle-prize'), participants: document.getElementById('evapp-raffle-participants'),
            winners: document.getElementById('evapp-raffle-winners'), search: document.getElementById('evapp-raffle-search'),
            prev: document.getElementById('evapp-raffle-prev'), next: document.getElementById('evapp-raffle-next'), pageLabel: document.getElementById('evapp-raffle-page-label'),
            tableSummary: document.getElementById('evapp-raffle-table-summary'), toast: document.getElementById('evapp-raffle-toast')
        };
        let page = 1, pages = 1, refreshTimer = null, searchTimer = null, revealTimer = null, busy = false, latestState = null, winnersSignature = '', stateRequestInFlight = false;

        function escapeHtml(value){ return String(value == null ? '' : value).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c])); }
        function showToast(message, error){
            els.toast.textContent = message || '';
            els.toast.classList.toggle('error', !!error);
            els.toast.classList.add('show');
            clearTimeout(els.toast._timer);
            els.toast._timer = setTimeout(() => els.toast.classList.remove('show'), 3200);
        }
        function setBusy(value){ busy = value; root.classList.toggle('evapp-raffle-loading', value); }
        function request(op, extra, includeSettings){
            const fd = new FormData();
            fd.append('action','eventosapp_live_raffle_admin'); fd.append('op',op); fd.append('event_id',eventId); fd.append('nonce',nonce);
            if (includeSettings) {
                new FormData(settingsForm).forEach((v,k) => fd.append(k,v));
            }
            Object.entries(extra || {}).forEach(([k,v]) => {
                if (Array.isArray(v)) v.forEach(item => fd.append(k+'[]', item)); else fd.append(k,v);
            });
            return fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(r => r.json()).then(resp => {
                if (!resp || !resp.success) throw new Error(resp && resp.data && resp.data.message ? resp.data.message : 'No fue posible completar la solicitud.');
                return resp.data;
            });
        }
        function renderState(data){
            latestState = data;
            const active = !!data.public_active;
            els.liveStatus.classList.toggle('is-active', active);
            els.liveLabel.textContent = active ? 'Pantalla pública activa' : 'Pantalla pública desactivada';
            const status = data.status || 'ready';
            clearTimeout(revealTimer); revealTimer = null;
            els.confirm.hidden = true; els.retry.hidden = true; els.start.hidden = false; els.prize.hidden = false;
            els.stageSpinner.innerHTML = '';

            if (!active || status === 'inactive') {
                els.stageKicker.textContent = 'Pantalla pública desactivada';
                els.stageName.textContent = 'Activa el sorteo para comenzar';
                els.stageMeta.textContent = 'Quien abra la URL pública verá un mensaje de espera hasta que la actives.';
                els.start.disabled = true;
                return;
            }
            els.start.disabled = false;
            if (status === 'drawing') {
                const seconds = Math.max(0, Number(data.reveal_at || 0) - Number(data.server_time || 0));
                els.stageKicker.textContent = 'Seleccionando entre '+Number(data.eligible_count || 0)+' participantes';
                els.stageSpinner.innerHTML = '<div class="evapp-raffle-spinner"></div>';
                els.stageName.textContent = 'Eligiendo participante…';
                els.stageMeta.textContent = 'Resultado en aproximadamente '+seconds+' segundos';
                els.start.hidden = true; els.prize.hidden = true;
                revealTimer = setTimeout(() => loadState(true), Math.max(700, (seconds + 0.4) * 1000));
            } else if (status === 'candidate' && data.candidate) {
                els.stageKicker.textContent = 'Resultado provisional';
                els.stageName.textContent = data.candidate.name || 'Participante seleccionado';
                els.stageMeta.textContent = [data.candidate.company, data.candidate.location].filter(Boolean).join(' · ') || 'Confirma el resultado para registrarlo como ganador.';
                els.start.hidden = true; els.confirm.hidden = false; els.retry.hidden = false;
            } else if (status === 'winner' && data.winner) {
                els.stageKicker.textContent = 'Ganador confirmado';
                els.stageName.textContent = data.winner.name || 'Ganador';
                els.stageMeta.textContent = [data.winner.prize, data.winner.company, data.winner.location].filter(Boolean).join(' · ');
                els.retry.hidden = false;
            } else {
                els.stageKicker.textContent = 'Sorteo preparado';
                els.stageName.textContent = 'Listo para comenzar';
                els.stageMeta.textContent = Number(data.eligible_count || 0) ? Number(data.eligible_count)+' participantes disponibles' : 'Guarda la configuración para actualizar participantes.';
            }
        }
        function badge(text, cls){ return '<span class="evapp-raffle-badge '+(cls||'')+'">'+escapeHtml(text)+'</span>'; }
        function renderParticipants(data){
            const p = data.participants || {rows:[],page:1,pages:1,total:0};
            page = Number(p.page || 1); pages = Number(p.pages || 1);
            els.pageLabel.textContent = 'Página '+page+' de '+pages;
            els.prev.disabled = page <= 1; els.next.disabled = page >= pages;
            els.tableSummary.textContent = Number(p.total || 0)+' asistentes presentes coinciden con la búsqueda. Actualizado: '+new Date().toLocaleTimeString('es-CO');
            if (!p.rows || !p.rows.length) {
                els.participants.innerHTML = '<tr><td colspan="6"><div class="evapp-raffle-empty">No hay asistentes para mostrar.</div></td></tr>';
                return;
            }
            els.participants.innerHTML = p.rows.map(row => {
                const checkins = (row.physical ? badge('Presencial','info') : '') + (row.virtual ? badge('Virtual','info') : '');
                const networking = row.networking ? badge('Sí','yes') : badge('No','no');
                const expo = row.expositor_names && row.expositor_names.length ? row.expositor_names.map(n => badge(n,'info')).join('') : badge('Sin interacción','');
                const state = row.eligible ? badge('Participa','yes') : badge(row.eligibility_note || 'No participa','no');
                return '<tr><td><strong>'+escapeHtml(row.name)+'</strong><br><small>'+escapeHtml(row.email || row.public_id || '—')+'</small><br><small>'+escapeHtml(row.company || 'Sin empresa')+'</small></td><td>'+checkins+'</td><td>'+escapeHtml(row.location || '—')+'</td><td>'+networking+'</td><td>'+expo+'</td><td>'+state+'</td></tr>';
            }).join('');
        }
        function renderCounts(counts){
            counts = counts || {};
            document.getElementById('evapp-count-present').textContent = Number(counts.present || 0);
            document.getElementById('evapp-count-eligible').textContent = Number(counts.eligible || 0);
            document.getElementById('evapp-count-physical').textContent = Number(counts.physical || 0);
            document.getElementById('evapp-count-virtual').textContent = Number(counts.virtual || 0);
            const headerEligible = document.getElementById('evapp-header-eligible');
            if (headerEligible) headerEligible.textContent = Number(counts.eligible || 0);
        }
        function renderWinners(rows){
            const headerWinners = document.getElementById('evapp-header-winners');
            if (headerWinners) headerWinners.textContent = Number((rows || []).length);
            const activeElement = document.activeElement;
            if (activeElement && els.winners.contains(activeElement)) return;
            const signature = JSON.stringify((rows || []).map(row => [row.winner_id,row.ticket_id,row.prize,row.notes,row.confirmed_at]));
            if (signature === winnersSignature) return;
            winnersSignature = signature;
            if (!rows || !rows.length) { els.winners.innerHTML = '<div class="evapp-raffle-empty">Todavía no hay ganadores confirmados.</div>'; return; }
            els.winners.innerHTML = rows.slice().reverse().map((row,index) => {
                const date = row.confirmed_at ? new Date(Number(row.confirmed_at)*1000).toLocaleString('es-CO') : '—';
                return '<div class="evapp-raffle-winner" data-winner-id="'+escapeHtml(row.winner_id)+'"><div class="evapp-raffle-winner-head"><div><h4>'+(rows.length-index)+'. '+escapeHtml(row.name || ('Ticket #'+row.ticket_id))+'</h4><small>'+escapeHtml([row.company,row.location,date].filter(Boolean).join(' · '))+'</small></div><button type="button" class="evapp-raffle-btn danger evapp-remove-winner">Eliminar</button></div><div class="evapp-raffle-winner-grid"><label>Premio<input class="evapp-winner-prize" value="'+escapeHtml(row.prize || '')+'"></label><label>Notas<textarea class="evapp-winner-notes">'+escapeHtml(row.notes || '')+'</textarea></label></div><button type="button" class="evapp-raffle-btn primary evapp-save-winner">Guardar cambios</button></div>';
            }).join('');
        }
        function loadState(silent){
            if ((busy || stateRequestInFlight) && silent) return Promise.resolve(latestState);
            stateRequestInFlight = true;
            return request('state',{page:page,per_page:50,search:els.search.value || ''},false).then(data => {
                renderState(data); renderCounts(data.counts); renderParticipants(data); renderWinners(data.winners);
                return data;
            }).catch(err => { if (!silent) showToast(err.message,true); return latestState; }).finally(() => { stateRequestInFlight = false; });
        }
        function scheduleRefresh(){
            clearTimeout(refreshTimer);
            refreshTimer = setTimeout(() => {
                loadState(true).finally(scheduleRefresh);
            }, document.hidden ? 30000 : 8000);
        }
        function action(op, extra, includeSettings, successMessage){
            if (busy) return;
            setBusy(true);
            request(op,extra,includeSettings).then(data => {
                if (data.status !== undefined) renderState(data);
                if (successMessage) showToast(successMessage,false);
                setBusy(false);
                return loadState(true);
            }).catch(err => showToast(err.message,true)).finally(() => setBusy(false));
        }
        function retryDraw(){
            if (busy) return;
            setBusy(true);
            request('retry',{},false).then(() => request('start',{},true)).then(data => {
                renderState(data);
                showToast('Nuevo intento iniciado.',false);
                setBusy(false);
                return loadState(true);
            }).catch(err => showToast(err.message,true)).finally(() => setBusy(false));
        }

        document.getElementById('evapp-raffle-expositor-mode').addEventListener('change', function(){
            document.getElementById('evapp-raffle-specific-wrap').classList.toggle('show', this.value === 'specific');
        });
        document.getElementById('evapp-raffle-save-settings').addEventListener('click', () => action('save_settings',{},true,'Configuración guardada.'));
        document.getElementById('evapp-raffle-activate').addEventListener('click', () => action('toggle_public',{active:'1'},false,'Pantalla pública activada.'));
        document.getElementById('evapp-raffle-deactivate').addEventListener('click', () => action('toggle_public',{active:'0'},false,'Pantalla pública desactivada.'));
        els.start.addEventListener('click', () => action('start',{},true,'Sorteo iniciado.'));
        els.confirm.addEventListener('click', () => action('confirm',{prize:els.prize.value || ''},false,'Ganador confirmado y almacenado.'));
        els.retry.addEventListener('click', retryDraw);
        els.reset.addEventListener('click', () => action('reset_stage',{},false,'Pantalla restablecida.'));
        els.prev.addEventListener('click', () => { if(page>1){page--;loadState(false);} });
        els.next.addEventListener('click', () => { if(page<pages){page++;loadState(false);} });
        els.search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer=setTimeout(() => {page=1;loadState(false);},350); });
        document.getElementById('evapp-raffle-copy-url')?.addEventListener('click', function(){
            const input = document.getElementById('evapp-raffle-public-url'); if(!input) return;
            navigator.clipboard && navigator.clipboard.writeText ? navigator.clipboard.writeText(input.value).then(()=>showToast('URL copiada.')) : (input.select(),document.execCommand('copy'),showToast('URL copiada.'));
        });
        els.winners.addEventListener('click', function(e){
            const card = e.target.closest('[data-winner-id]'); if(!card) return;
            const winnerId = card.dataset.winnerId;
            if(e.target.closest('.evapp-save-winner')) { e.target.blur(); winnersSignature=''; action('update_winner',{winner_id:winnerId,prize:card.querySelector('.evapp-winner-prize').value,notes:card.querySelector('.evapp-winner-notes').value},false,'Ganador actualizado.'); }
            if(e.target.closest('.evapp-remove-winner') && window.confirm('¿Eliminar este ganador? El asistente volverá a quedar disponible para futuros sorteos.')) { e.target.blur(); winnersSignature=''; action('remove_winner',{winner_id:winnerId},false,'Ganador eliminado.'); }
        });

        loadState(false).finally(scheduleRefresh);
        document.addEventListener('visibilitychange', () => {
            clearTimeout(refreshTimer);
            if (document.hidden) {
                scheduleRefresh();
            } else {
                loadState(true).finally(scheduleRefresh);
            }
        });
        window.addEventListener('beforeunload', () => { clearTimeout(refreshTimer); clearTimeout(revealTimer); clearTimeout(searchTimer); });
    })();
    </script>
    <?php
    return ob_get_clean();
} );

// ============================================================
// SHORTCODE PÚBLICO
// ============================================================

add_shortcode( 'eventosapp_live_raffle_public', function( $atts ) {
    $atts = shortcode_atts( [ 'event_id' => 0 ], $atts, 'eventosapp_live_raffle_public' );
    $event_id = absint( $atts['event_id'] );
    if ( ! $event_id ) $event_id = eventosapp_live_raffle_resolve_public_event();

    if ( ! $event_id ) {
        return '<div style="min-height:60vh;display:grid;place-items:center;padding:24px;color:#182230;background:#f5f8fc;border:1px solid #dfe7f1;border-radius:18px;text-align:center;font-family:inherit;box-sizing:border-box;"><div><strong style="display:block;margin-bottom:8px;color:#3279bd;font-size:12px;letter-spacing:.10em;text-transform:uppercase;">EVENTOSAPP · SORTEO EN VIVO</strong>Esta pantalla pública no tiene un evento válido asignado.</div></div>';
    }

    $nonce              = eventosapp_live_raffle_public_token( $event_id );
    $ajax_url           = admin_url( 'admin-ajax.php' );
    $can_back_dashboard = is_user_logged_in() && eventosapp_live_raffle_user_can_view( $event_id );
    $dashboard_url      = function_exists( 'eventosapp_get_dashboard_url' )
        ? remove_query_arg( [ 'evapp', 'evapp_err', 'set' ], eventosapp_get_dashboard_url() )
        : home_url( '/' );

    ob_start();
    ?>
    <style id="eventosapp-live-raffle-public-ui">
    .evapp-raffle-public{
        --evapp-primary:#3279bd;
        --evapp-primary-dark:#255f96;
        --evapp-primary-bright:#63b3ed;
        --evapp-surface:#ffffff;
        --evapp-text:#f8fbff;
        --evapp-muted:#c6d7e7;
        --evapp-success:#4ade80;
        --evapp-gold:#facc15;
        position:fixed;
        inset:0;
        z-index:999999;
        isolation:isolate;
        width:100%;
        min-height:100vh;
        min-height:100dvh;
        display:flex;
        align-items:center;
        justify-content:center;
        overflow:hidden;
        padding:clamp(14px,3.5vw,52px);
        color:var(--evapp-text);
        background:
            radial-gradient(circle at 15% 15%,rgba(50,121,189,.34),transparent 31%),
            radial-gradient(circle at 86% 82%,rgba(99,179,237,.20),transparent 34%),
            linear-gradient(145deg,#07111b,#10283c 54%,#143d5e);
        font-family:inherit;
        box-sizing:border-box;
    }
    .evapp-raffle-public *,
    .evapp-raffle-public *::before,
    .evapp-raffle-public *::after{box-sizing:border-box}
    .evapp-raffle-public-back{
        position:fixed;
        top:14px;
        right:14px;
        z-index:8;
        min-height:42px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:8px;
        padding:9px 13px;
        color:#fff!important;
        background:rgba(7,17,27,.72);
        border:1px solid rgba(255,255,255,.22);
        border-radius:12px;
        box-shadow:0 8px 24px rgba(0,0,0,.22);
        font-size:12px;
        font-weight:800;
        line-height:1.2;
        text-decoration:none!important;
        backdrop-filter:blur(10px);
        -webkit-backdrop-filter:blur(10px);
        transition:transform .16s ease,background .16s ease,border-color .16s ease;
    }
    .evapp-raffle-public-back:hover{color:#fff!important;background:rgba(37,95,150,.90);border-color:rgba(255,255,255,.34);transform:translateY(-1px)}
    .evapp-raffle-public-back:focus-visible{outline:3px solid rgba(143,208,255,.45);outline-offset:2px}
    .evapp-raffle-public-back svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
    .evapp-raffle-public-orb{
        position:absolute;
        border-radius:50%;
        opacity:.18;
        filter:blur(1px);
        animation:evappPublicFloat 10s ease-in-out infinite;
    }
    .evapp-raffle-public-orb.one{width:220px;height:220px;left:-72px;top:10%;background:var(--evapp-primary-bright)}
    .evapp-raffle-public-orb.two{width:300px;height:300px;right:-110px;bottom:1%;background:var(--evapp-primary);animation-delay:-4s}
    .evapp-raffle-public-orb.three{width:96px;height:96px;right:17%;top:7%;background:#d8efff;animation-delay:-7s}
    @keyframes evappPublicFloat{50%{transform:translate3d(18px,-22px,0) scale(1.06)}}
    .evapp-raffle-public-card{
        position:relative;
        z-index:3;
        width:min(1180px,100%);
        min-height:min(720px,88dvh);
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        overflow:hidden;
        padding:clamp(74px,7vw,96px) clamp(22px,5vw,72px) clamp(72px,7vw,92px);
        text-align:center;
        background:linear-gradient(145deg,rgba(255,255,255,.11),rgba(255,255,255,.045));
        border:1px solid rgba(255,255,255,.18);
        border-radius:clamp(24px,3vw,34px);
        box-shadow:0 28px 80px rgba(0,0,0,.34);
        backdrop-filter:blur(16px);
        -webkit-backdrop-filter:blur(16px);
    }
    .evapp-raffle-public-card::before{
        position:absolute;
        inset:0 0 auto;
        height:4px;
        background:linear-gradient(90deg,transparent,var(--evapp-primary-bright),#fff,var(--evapp-primary-bright),transparent);
        opacity:.7;
        content:"";
    }
    .evapp-raffle-public-event{
        position:absolute;
        top:24px;
        left:28px;
        right:28px;
        overflow:hidden;
        color:#d7e7f5;
        font-size:clamp(11px,1.2vw,14px);
        font-weight:800;
        letter-spacing:.10em;
        line-height:1.35;
        text-overflow:ellipsis;
        text-transform:uppercase;
        white-space:nowrap;
    }
    .evapp-raffle-public-kicker{
        color:#bfe2ff;
        font-size:clamp(12px,1.55vw,18px);
        font-weight:850;
        letter-spacing:.20em;
        line-height:1.3;
        text-transform:uppercase;
    }
    .evapp-raffle-public-title{
        max-width:1050px;
        margin:20px 0 14px;
        color:#fff;
        font-size:clamp(46px,7.5vw,112px);
        font-weight:920;
        line-height:.95;
        letter-spacing:-.05em;
        overflow-wrap:anywhere;
        text-wrap:balance;
    }
    .evapp-raffle-public-subtitle{
        max-width:850px;
        color:var(--evapp-muted);
        font-size:clamp(16px,2.1vw,28px);
        line-height:1.4;
        text-wrap:balance;
    }
    .evapp-raffle-public-avatar{
        width:clamp(104px,15vw,178px);
        height:clamp(104px,15vw,178px);
        display:none;
        align-items:center;
        justify-content:center;
        margin-bottom:16px;
        color:#fff;
        background:linear-gradient(145deg,var(--evapp-primary-bright),var(--evapp-primary-dark));
        border:5px solid rgba(255,255,255,.88);
        border-radius:50%;
        box-shadow:0 0 0 10px rgba(255,255,255,.08),0 24px 58px rgba(0,0,0,.32);
        font-size:clamp(42px,6vw,82px);
        font-weight:900;
        letter-spacing:-.04em;
    }
    .evapp-raffle-public.is-result .evapp-raffle-public-avatar{display:flex}
    .evapp-raffle-public-loader{
        width:clamp(96px,14vw,166px);
        height:clamp(96px,14vw,166px);
        display:none;
        margin:24px auto;
        border:clamp(8px,1vw,12px) solid rgba(255,255,255,.14);
        border-top-color:#fff;
        border-right-color:var(--evapp-primary-bright);
        border-radius:50%;
        animation:evappPublicSpin .7s linear infinite;
    }
    @keyframes evappPublicSpin{to{transform:rotate(360deg)}}
    .evapp-raffle-public.is-drawing .evapp-raffle-public-loader{display:block}
    .evapp-raffle-public-countdown{
        min-height:1.05em;
        color:var(--evapp-gold);
        font-size:clamp(34px,5vw,72px);
        font-variant-numeric:tabular-nums;
        font-weight:900;
        line-height:1;
    }
    .evapp-raffle-public-prize{
        display:none;
        margin-top:20px;
        padding:10px 18px;
        color:#fff5b8;
        background:rgba(250,204,21,.13);
        border:1px solid rgba(250,204,21,.42);
        border-radius:999px;
        font-size:clamp(14px,1.7vw,20px);
        font-weight:850;
    }
    .evapp-raffle-public.is-winner .evapp-raffle-public-prize.has-value{display:inline-flex}
    .evapp-raffle-public-status{
        position:absolute;
        right:24px;
        bottom:20px;
        left:24px;
        display:flex;
        align-items:center;
        justify-content:center;
        gap:8px;
        color:#c9d8e6;
        font-size:12px;
        line-height:1.35;
    }
    .evapp-raffle-public-status-dot{
        width:8px;
        height:8px;
        flex:0 0 8px;
        background:#94a3b8;
        border-radius:50%;
    }
    .evapp-raffle-public-status-dot.live{background:var(--evapp-success);box-shadow:0 0 14px rgba(74,222,128,.8)}
    .evapp-raffle-public-slot{
        min-height:1.1em;
        display:none;
        margin:9px 0;
        color:transparent;
        background:linear-gradient(90deg,#fff,#8fd0ff,#d9edff,#fff);
        background-size:220% auto;
        background-clip:text;
        -webkit-background-clip:text;
        font-size:clamp(34px,5.4vw,80px);
        font-weight:900;
        line-height:1;
        animation:evappRaffleShine 1.15s linear infinite;
    }
    @keyframes evappRaffleShine{to{background-position:220% center}}
    .evapp-raffle-public.is-drawing .evapp-raffle-public-slot{display:block}
    .evapp-raffle-public.is-drawing .evapp-raffle-public-title{display:none}
    .evapp-raffle-public-confetti{position:absolute;inset:0;z-index:5;pointer-events:none}
    .evapp-raffle-public-message{padding:24px;color:#182230;background:#f5f8fc;border:1px solid #dfe7f1;border-radius:18px;text-align:center}
    @media(max-width:700px){
        .evapp-raffle-public{padding:9px}
        .evapp-raffle-public-card{min-height:calc(100dvh - 18px);padding:70px 18px 58px;border-radius:22px}
        .evapp-raffle-public-event{top:17px;left:16px;right:16px;white-space:normal}
        .evapp-raffle-public-status{right:16px;bottom:14px;left:16px}
        .evapp-raffle-public-back{top:12px;right:12px;width:42px;height:42px;min-height:42px;padding:0;border-radius:11px}
        .evapp-raffle-public-back span{display:none}
    }
    @media(max-height:600px) and (orientation:landscape){
        .evapp-raffle-public{padding:8px}
        .evapp-raffle-public-card{min-height:calc(100dvh - 16px);padding:50px 28px 42px}
        .evapp-raffle-public-avatar{width:86px;height:86px;margin-bottom:8px;font-size:36px;border-width:4px}
        .evapp-raffle-public-title{margin:10px 0 8px;font-size:clamp(38px,9vh,72px)}
        .evapp-raffle-public-subtitle{font-size:clamp(14px,3.2vh,20px)}
        .evapp-raffle-public-loader{width:88px;height:88px;margin:10px auto}
    }
    @media(prefers-reduced-motion:reduce){
        .evapp-raffle-public *,
        .evapp-raffle-public *::before,
        .evapp-raffle-public *::after{animation:none!important;transition:none!important}
    }
    </style>
    <div class="evapp-raffle-public" id="evapp-live-raffle-public"
         data-event-id="<?php echo esc_attr( $event_id ); ?>"
         data-nonce="<?php echo esc_attr( $nonce ); ?>"
         data-ajax-url="<?php echo esc_url( $ajax_url ); ?>">
        <?php if ( $can_back_dashboard ) : ?>
            <a class="evapp-raffle-public-back" href="<?php echo esc_url( $dashboard_url ); ?>" aria-label="Volver al dashboard">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg>
                <span>Volver al dashboard</span>
            </a>
        <?php endif; ?>
        <div class="evapp-raffle-public-orb one"></div><div class="evapp-raffle-public-orb two"></div><div class="evapp-raffle-public-orb three"></div>
        <canvas class="evapp-raffle-public-confetti" id="evapp-raffle-confetti" aria-hidden="true"></canvas>
        <div class="evapp-raffle-public-card" role="region" aria-label="Sorteo en vivo">
            <div class="evapp-raffle-public-event" id="evapp-public-event"><?php echo esc_html( get_the_title( $event_id ) ); ?></div>
            <div class="evapp-raffle-public-avatar" id="evapp-public-avatar">EA</div>
            <div class="evapp-raffle-public-kicker" id="evapp-public-kicker">Sorteo en vivo</div>
            <div class="evapp-raffle-public-loader" aria-hidden="true"></div>
            <div class="evapp-raffle-public-slot" id="evapp-public-slot" aria-hidden="true">Participante 001</div>
            <div class="evapp-raffle-public-title" id="evapp-public-title" aria-live="polite" aria-atomic="true">Esperando al organizador</div>
            <div class="evapp-raffle-public-countdown" id="evapp-public-countdown"></div>
            <div class="evapp-raffle-public-subtitle" id="evapp-public-subtitle">El sorteo aparecerá aquí cuando el organizador lo active.</div>
            <div class="evapp-raffle-public-prize" id="evapp-public-prize"></div>
            <div class="evapp-raffle-public-status" role="status" aria-live="polite"><span class="evapp-raffle-public-status-dot" id="evapp-public-dot" aria-hidden="true"></span><span id="evapp-public-status-text">Conectando con el evento…</span></div>
        </div>
    </div>
    <script>
    (function(){
        'use strict';
        const root = document.getElementById('evapp-live-raffle-public'); if(!root) return;
        const eventId=root.dataset.eventId, nonce=root.dataset.nonce, ajaxUrl=root.dataset.ajaxUrl;
        const title=document.getElementById('evapp-public-title'), kicker=document.getElementById('evapp-public-kicker'), subtitle=document.getElementById('evapp-public-subtitle'), countdown=document.getElementById('evapp-public-countdown'), slot=document.getElementById('evapp-public-slot'), avatar=document.getElementById('evapp-public-avatar'), prize=document.getElementById('evapp-public-prize'), dot=document.getElementById('evapp-public-dot'), statusText=document.getElementById('evapp-public-status-text');
        let timer=null,countdownTimer=null,slotTimer=null,lastWinner='',serverOffset=0,lastPayload=null,requestInFlight=false;
        const genericNames=['Participante 014','Participante 027','Participante 041','Participante 058','Participante 073','Participante 089','Participante 103','Participante 126','Participante 147','Participante 168'];
        function setMode(mode){root.classList.toggle('is-drawing',mode==='drawing');root.classList.toggle('is-result',mode==='candidate'||mode==='winner');root.classList.toggle('is-winner',mode==='winner');}
        function startSlot(){if(slotTimer)return;let i=0;slotTimer=setInterval(()=>{slot.textContent=genericNames[i++%genericNames.length];},95);}
        function stopSlot(){clearInterval(slotTimer);slotTimer=null;}
        function render(data){
            lastPayload=data; serverOffset=Number(data.server_time||0)-Math.floor(Date.now()/1000);
            dot.classList.toggle('live',!!data.public_active); statusText.textContent=data.public_active?'Sorteo conectado en tiempo real':'El organizador aún no ha activado el sorteo';
            if(!data.enabled){setMode('inactive');stopSlot();kicker.textContent='Sorteo no disponible';title.textContent='Esta función no está habilitada';subtitle.textContent='El organizador no ha habilitado el Sorteo en Vivo para este evento.';countdown.textContent='';return;}
            if(!data.public_active||data.status==='inactive'){setMode('inactive');stopSlot();kicker.textContent='Sorteo en vivo';title.textContent='Esperando al organizador';subtitle.textContent='El sorteo aparecerá aquí cuando el organizador lo active.';countdown.textContent='';prize.classList.remove('has-value');return;}
            if(data.status==='drawing'){setMode('drawing');startSlot();kicker.textContent='La suerte está girando';subtitle.textContent='Seleccionando entre '+Number(data.eligible_count||0)+' participantes presentes';updateCountdown();return;}
            stopSlot();countdown.textContent='';
            if(data.status==='candidate'&&data.candidate){setMode('candidate');kicker.textContent='Resultado provisional';title.textContent=data.candidate.name||'Participante seleccionado';avatar.textContent=data.candidate.initials||'EA';subtitle.textContent=[data.candidate.company,data.candidate.location,'Esperando confirmación del organizador'].filter(Boolean).join(' · ');prize.classList.remove('has-value');return;}
            if(data.status==='winner'&&data.winner){setMode('winner');kicker.textContent='¡Tenemos ganador!';title.textContent=data.winner.name||'Ganador';avatar.textContent=data.winner.initials||'EA';subtitle.textContent=[data.winner.company,data.winner.location].filter(Boolean).join(' · ')||'Ganador confirmado';prize.textContent=data.winner.prize||'';prize.classList.toggle('has-value',!!data.winner.prize);const key=data.winner.winner_id||String(data.winner.ticket_id);if(key&&key!==lastWinner){lastWinner=key;launchConfetti();}return;}
            setMode('ready');kicker.textContent='Sorteo preparado';title.textContent='Todo listo';subtitle.textContent='El organizador iniciará el sorteo en cualquier momento.';prize.classList.remove('has-value');
        }
        function updateCountdown(){if(!lastPayload||lastPayload.status!=='drawing'){countdown.textContent='';return;}const now=Math.floor(Date.now()/1000)+serverOffset;const left=Math.max(0,Number(lastPayload.reveal_at||0)-now);countdown.textContent=left>0?String(left):'…';}
        function load(){if(requestInFlight)return Promise.resolve();requestInFlight=true;const fd=new FormData();fd.append('action','eventosapp_live_raffle_public_state');fd.append('event_id',eventId);fd.append('nonce',nonce);return fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(r=>r.json()).then(resp=>{if(resp&&resp.success)render(resp.data);else throw new Error('Estado no disponible');}).catch(()=>{dot.classList.remove('live');statusText.textContent='Reconectando…';}).finally(()=>{requestInFlight=false;});}
        function scheduleLoad(){clearTimeout(timer);timer=setTimeout(()=>{load().finally(scheduleLoad);},document.hidden?4000:1200);}
        function launchConfetti(){
            if(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
            const canvas=document.getElementById('evapp-raffle-confetti'),ctx=canvas.getContext('2d');if(!ctx)return;let pieces=[],running=true;const colors=['#3279bd','#63b3ed','#facc15','#8fd0ff','#4ade80','#fff'];
            function resize(){canvas.width=root.clientWidth;canvas.height=root.clientHeight;}resize();
            const pieceCount=window.innerWidth<700?90:140;for(let i=0;i<pieceCount;i++)pieces.push({x:Math.random()*canvas.width,y:-Math.random()*canvas.height*.45,w:4+Math.random()*9,h:6+Math.random()*13,vx:-2+Math.random()*4,vy:2+Math.random()*5,r:Math.random()*Math.PI,vr:-.18+Math.random()*.36,c:colors[Math.floor(Math.random()*colors.length)]});
            const started=performance.now();function frame(now){ctx.clearRect(0,0,canvas.width,canvas.height);pieces.forEach(p=>{p.x+=p.vx;p.y+=p.vy;p.vy+=.035;p.r+=p.vr;ctx.save();ctx.translate(p.x,p.y);ctx.rotate(p.r);ctx.fillStyle=p.c;ctx.fillRect(-p.w/2,-p.h/2,p.w,p.h);ctx.restore();});if(running&&now-started<6500)requestAnimationFrame(frame);else ctx.clearRect(0,0,canvas.width,canvas.height);}requestAnimationFrame(frame);setTimeout(()=>{running=false;},6600);window.addEventListener('resize',resize,{once:true});
        }
        load().finally(scheduleLoad);countdownTimer=setInterval(updateCountdown,250);document.addEventListener('visibilitychange',()=>{clearTimeout(timer);if(document.hidden){scheduleLoad();}else{load().finally(scheduleLoad);}});window.addEventListener('beforeunload',()=>{clearTimeout(timer);clearInterval(countdownTimer);stopSlot();});
    })();
    </script>
    <?php
    return ob_get_clean();
} );
