<?php
/**
 * CPT: eventosapp_asistente
 * Perfil de asistentes/personas para EventosApp.
 *
 * Campos:
 *   _asistente_nombres    → Nombres
 *   _asistente_apellidos  → Apellidos
 *   _asistente_cedula     → Cédula de Ciudadanía
 *   _asistente_email      → Correo Electrónico principal
 *   _asistente_email_alternativo → Correo Electrónico alternativo
 *   _asistente_telefono   → Número de Contacto
 *   _asistente_empresa    → Nombre de Empresa
 *   _asistente_cargo      → Cargo
 *   _asistente_ciudad     → Ciudad
 *   _asistente_pais       → País
 *   _asistente_foto_id    → Attachment ID de la Foto
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// 1. REGISTRO DEL CPT
// ============================================================

add_action( 'init', function () {
    register_post_type( 'eventosapp_asistente', [
        'labels' => [
            'name'               => 'Asistentes',
            'singular_name'      => 'Asistente',
            'add_new'            => 'Agregar Nuevo',
            'add_new_item'       => 'Agregar Nuevo Asistente',
            'edit_item'          => 'Editar Asistente',
            'new_item'           => 'Nuevo Asistente',
            'view_item'          => 'Ver Asistente',
            'search_items'       => 'Buscar Asistentes',
            'not_found'          => 'No se encontraron asistentes',
            'not_found_in_trash' => 'No se encontraron asistentes en la papelera',
            'menu_name'          => 'Asistentes',
            'all_items'          => 'Todos los Asistentes',
        ],
        'public'              => false,
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'show_ui'             => true,
        'menu_icon'           => 'dashicons-id-alt',
        'supports'            => [ 'title' ],
        'has_archive'         => false,
        'rewrite'             => false,
        'show_in_rest'        => false,
        'show_in_menu'        => false,   // El menú lo gestiona eventosapp.php via add_submenu_page
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
    ] );
} );

// ============================================================
// 2. OCULTAR CAMPO TITLE NATIVO (usamos Nombres + Apellidos como título visual)
// ============================================================

add_action( 'admin_head', function () {
    $screen = get_current_screen();
    if ( $screen && $screen->post_type === 'eventosapp_asistente' ) {
        echo '<style>#titlediv { display: none !important; }</style>';
    }
} );

// ============================================================
// 2.1 HELPERS: Correo electrónico alternativo + historial vía tickets
// ============================================================

if ( ! function_exists( 'evapp_asistente_normalize_email_compare' ) ) {
    function evapp_asistente_normalize_email_compare( $email ) {
        return strtolower( trim( sanitize_email( (string) $email ) ) );
    }
}

if ( ! function_exists( 'evapp_asistente_get_request_ip' ) ) {
    function evapp_asistente_get_request_ip() {
        $keys = [ 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
        foreach ( $keys as $key ) {
            if ( empty( $_SERVER[ $key ] ) ) continue;
            $raw = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
            if ( strpos( $raw, ',' ) !== false ) {
                $raw = trim( explode( ',', $raw )[0] );
            }
            if ( filter_var( $raw, FILTER_VALIDATE_IP ) ) {
                return $raw;
            }
        }
        return '';
    }
}

if ( ! function_exists( 'evapp_asistente_email_alt_source_label' ) ) {
    function evapp_asistente_email_alt_source_label( $source ) {
        $source = sanitize_key( $source );
        $labels = [
            'galeria_envio_fotos_sin_marca' => 'Flujo público de galería: solicitud de fotos sin marca de agua',
            'galeria_envio_fotos'           => 'Flujo público de galería',
            'admin_asistente_metabox'       => 'Edición manual desde el administrador del asistente',
            'unknown'                       => 'Origen no identificado',
        ];
        return $labels[ $source ] ?? ucwords( str_replace( '_', ' ', $source ) );
    }
}

if ( ! function_exists( 'evapp_asistente_safe_title_by_id' ) ) {
    function evapp_asistente_safe_title_by_id( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) return '';
        $title = get_the_title( $post_id );
        return $title ? wp_strip_all_tags( $title ) : '';
    }
}

if ( ! function_exists( 'evapp_asistente_append_ticket_update_history' ) ) {
    /**
     * Agrega una entrada al metabox "📋 Historial de Actualizaciones (vía Tickets)".
     */
    function evapp_asistente_append_ticket_update_history( $asistente_id, $data = [] ) {
        $asistente_id = absint( $asistente_id );
        $post         = $asistente_id ? get_post( $asistente_id ) : null;

        if ( ! $post || $post->post_type !== 'eventosapp_asistente' ) {
            return new WP_Error( 'evapp_asistente_historial_invalido', 'Asistente inválido para registrar historial.' );
        }

        $ticket_id  = isset( $data['ticket_id'] ) ? absint( $data['ticket_id'] ) : 0;
        $galeria_id = isset( $data['galeria_id'] ) ? absint( $data['galeria_id'] ) : 0;
        $evento_id  = isset( $data['evento_id'] ) ? absint( $data['evento_id'] ) : 0;

        if ( ! $evento_id && $ticket_id ) {
            $evento_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        }
        if ( ! $evento_id && $galeria_id ) {
            $evento_id = absint( get_post_meta( $galeria_id, '_galeria_evento_id', true ) );
        }

        $source       = isset( $data['source'] ) ? sanitize_key( $data['source'] ) : 'unknown';
        $source_label = ! empty( $data['source_label'] )
            ? sanitize_text_field( $data['source_label'] )
            : evapp_asistente_email_alt_source_label( $source );

        $entry = [
            'fecha'             => current_time( 'mysql' ),
            'tipo'              => isset( $data['tipo'] ) ? sanitize_key( $data['tipo'] ) : 'actualizacion',
            'accion'            => isset( $data['accion'] ) ? sanitize_key( $data['accion'] ) : 'actualizacion',
            'titulo'            => isset( $data['titulo'] ) && $data['titulo'] !== '' ? sanitize_text_field( $data['titulo'] ) : 'Actualización registrada desde ticket',
            'resultado'         => isset( $data['resultado'] ) ? sanitize_key( $data['resultado'] ) : '',
            'detalle'           => isset( $data['detalle'] ) ? sanitize_textarea_field( $data['detalle'] ) : '',
            'source'            => $source,
            'source_label'      => $source_label,
            'flow'              => isset( $data['flow'] ) ? sanitize_key( $data['flow'] ) : '',
            'ticket_id'         => $ticket_id,
            'ticket_title'      => ! empty( $data['ticket_title'] ) ? sanitize_text_field( $data['ticket_title'] ) : evapp_asistente_safe_title_by_id( $ticket_id ),
            'evento_id'         => $evento_id,
            'evento_title'      => ! empty( $data['evento_title'] ) ? sanitize_text_field( $data['evento_title'] ) : evapp_asistente_safe_title_by_id( $evento_id ),
            'galeria_id'        => $galeria_id,
            'galeria_title'     => ! empty( $data['galeria_title'] ) ? sanitize_text_field( $data['galeria_title'] ) : evapp_asistente_safe_title_by_id( $galeria_id ),
            'email_principal'   => isset( $data['email_principal'] ) ? sanitize_email( $data['email_principal'] ) : sanitize_email( get_post_meta( $asistente_id, '_asistente_email', true ) ),
            'email_alternativo' => isset( $data['email_alternativo'] ) ? sanitize_email( $data['email_alternativo'] ) : sanitize_email( $data['email'] ?? '' ),
            'email_anterior'    => isset( $data['email_anterior'] ) ? sanitize_email( $data['email_anterior'] ) : '',
            'cantidad_fotos'    => isset( $data['cantidad_fotos'] ) ? absint( $data['cantidad_fotos'] ) : 0,
            'user_id'           => get_current_user_id(),
            'ip'                => evapp_asistente_get_request_ip(),
        ];

        $history = get_post_meta( $asistente_id, '_asistente_historial_actualizaciones_tickets', true );
        if ( ! is_array( $history ) ) {
            $history = [];
        }

        $history[] = $entry;
        if ( count( $history ) > 150 ) {
            $history = array_slice( $history, -150 );
        }

        update_post_meta( $asistente_id, '_asistente_historial_actualizaciones_tickets', $history );

        error_log(
            '[EventosApp Asistentes] Historial vía tickets registrado | Asistente ID:' . $asistente_id .
            ' | Acción:' . $entry['accion'] .
            ' | Source:' . $entry['source'] .
            ' | Ticket ID:' . $entry['ticket_id'] .
            ' | Galería ID:' . $entry['galeria_id'] .
            ' | Evento ID:' . $entry['evento_id'] .
            ' | Email alt:' . $entry['email_alternativo']
        );

        return $entry;
    }
}

if ( ! function_exists( 'evapp_asistente_append_email_alternativo_log' ) ) {
    /**
     * Registra historial específico del correo alternativo y lo refleja en el historial vía tickets.
     */
    function evapp_asistente_append_email_alternativo_log( $asistente_id, $data = [] ) {
        $asistente_id = absint( $asistente_id );
        $post         = $asistente_id ? get_post( $asistente_id ) : null;

        if ( ! $post || $post->post_type !== 'eventosapp_asistente' ) {
            return new WP_Error( 'evapp_asistente_invalido', 'Asistente inválido.' );
        }

        $source       = isset( $data['source'] ) ? sanitize_key( $data['source'] ) : 'unknown';
        $email        = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : sanitize_email( $data['email_alternativo'] ?? '' );
        $accion       = isset( $data['accion'] ) ? sanitize_key( $data['accion'] ) : 'correo_alternativo_registro';
        $resultado    = isset( $data['resultado'] ) ? sanitize_key( $data['resultado'] ) : 'registrado';
        $source_label = ! empty( $data['source_label'] ) ? sanitize_text_field( $data['source_label'] ) : evapp_asistente_email_alt_source_label( $source );

        $entry = [
            'fecha'             => current_time( 'mysql' ),
            'accion'            => $accion,
            'resultado'         => $resultado,
            'email'             => $email,
            'email_anterior'    => isset( $data['email_anterior'] ) ? sanitize_email( $data['email_anterior'] ) : '',
            'email_principal'   => isset( $data['email_principal'] ) ? sanitize_email( $data['email_principal'] ) : sanitize_email( get_post_meta( $asistente_id, '_asistente_email', true ) ),
            'source'            => $source,
            'source_label'      => $source_label,
            'flow'              => isset( $data['flow'] ) ? sanitize_key( $data['flow'] ) : '',
            'ticket_id'         => isset( $data['ticket_id'] ) ? absint( $data['ticket_id'] ) : 0,
            'ticket_title'      => ! empty( $data['ticket_title'] ) ? sanitize_text_field( $data['ticket_title'] ) : '',
            'galeria_id'        => isset( $data['galeria_id'] ) ? absint( $data['galeria_id'] ) : 0,
            'galeria_title'     => ! empty( $data['galeria_title'] ) ? sanitize_text_field( $data['galeria_title'] ) : '',
            'evento_id'         => isset( $data['evento_id'] ) ? absint( $data['evento_id'] ) : 0,
            'evento_title'      => ! empty( $data['evento_title'] ) ? sanitize_text_field( $data['evento_title'] ) : '',
            'cantidad_fotos'    => isset( $data['cantidad_fotos'] ) ? absint( $data['cantidad_fotos'] ) : 0,
            'detalle'           => isset( $data['detalle'] ) ? sanitize_textarea_field( $data['detalle'] ) : '',
            'user_id'           => get_current_user_id(),
            'ip'                => evapp_asistente_get_request_ip(),
        ];

        $log = get_post_meta( $asistente_id, '_asistente_email_alternativo_log', true );
        if ( ! is_array( $log ) ) {
            $log = [];
        }

        $log[] = $entry;
        if ( count( $log ) > 100 ) {
            $log = array_slice( $log, -100 );
        }

        update_post_meta( $asistente_id, '_asistente_email_alternativo_log', $log );

        if ( function_exists( 'evapp_asistente_append_ticket_update_history' ) ) {
            evapp_asistente_append_ticket_update_history( $asistente_id, array_merge( $entry, [
                'tipo'              => 'correo_alternativo',
                'titulo'            => 'Correo alternativo capturado',
                'email_alternativo' => $email,
            ] ) );
        }

        error_log(
            '[EventosApp Asistentes] Historial correo alternativo | Asistente ID:' . $asistente_id .
            ' | Acción:' . $entry['accion'] .
            ' | Resultado:' . $entry['resultado'] .
            ' | Source:' . $entry['source'] .
            ' | Email:' . $entry['email'] .
            ' | Ticket ID:' . $entry['ticket_id'] .
            ' | Galería ID:' . $entry['galeria_id']
        );

        return $entry;
    }
}

if ( ! function_exists( 'evapp_asistente_guardar_email_alternativo' ) ) {
    /**
     * Guarda un correo alternativo para el CPT asistente sin reemplazar el correo principal.
     */
    function evapp_asistente_guardar_email_alternativo( $asistente_id, $nuevo_email, $contexto = [] ) {
        $asistente_id = absint( $asistente_id );
        $post         = $asistente_id ? get_post( $asistente_id ) : null;

        if ( ! $post || $post->post_type !== 'eventosapp_asistente' ) {
            return new WP_Error( 'evapp_asistente_invalido', 'Asistente inválido.' );
        }

        $nuevo_email = sanitize_email( (string) $nuevo_email );
        if ( ! $nuevo_email || ! is_email( $nuevo_email ) ) {
            return new WP_Error( 'evapp_email_alternativo_invalido', 'Correo alternativo inválido.' );
        }

        $email_principal = sanitize_email( get_post_meta( $asistente_id, '_asistente_email', true ) );
        $email_actual    = sanitize_email( get_post_meta( $asistente_id, '_asistente_email_alternativo', true ) );

        $nuevo_cmp     = evapp_asistente_normalize_email_compare( $nuevo_email );
        $principal_cmp = evapp_asistente_normalize_email_compare( $email_principal );
        $actual_cmp    = evapp_asistente_normalize_email_compare( $email_actual );

        $log_if_unchanged = ! empty( $contexto['log_if_unchanged'] );
        $base_log_context = array_merge( $contexto, [
            'email'           => $nuevo_email,
            'email_anterior'  => $email_actual,
            'email_principal' => $email_principal,
        ] );

        if ( $principal_cmp && $nuevo_cmp === $principal_cmp ) {
            $log_entry = null;
            if ( $log_if_unchanged && function_exists( 'evapp_asistente_append_email_alternativo_log' ) ) {
                $log_entry = evapp_asistente_append_email_alternativo_log( $asistente_id, array_merge( $base_log_context, [
                    'accion'    => 'correo_alternativo_no_guardado',
                    'resultado' => 'coincide_con_principal',
                    'detalle'   => 'El usuario indicó este correo desde el flujo, pero no se guardó como alternativo porque coincide con el correo principal del ticket/asistente. No se reemplazó el correo principal.',
                ] ) );
            }

            return [
                'saved'     => false,
                'logged'    => ! empty( $log_entry ) && ! is_wp_error( $log_entry ),
                'reason'    => 'same_as_primary',
                'email'     => $email_principal,
                'log_entry' => $log_entry,
            ];
        }

        if ( $actual_cmp && $nuevo_cmp === $actual_cmp ) {
            $log_entry = null;
            if ( $log_if_unchanged && function_exists( 'evapp_asistente_append_email_alternativo_log' ) ) {
                $log_entry = evapp_asistente_append_email_alternativo_log( $asistente_id, array_merge( $base_log_context, [
                    'accion'    => 'correo_alternativo_ya_existia',
                    'resultado' => 'ya_existia',
                    'detalle'   => 'El usuario indicó este correo desde el flujo y ya estaba registrado como correo alternativo. No se creó un duplicado.',
                ] ) );
            }

            return [
                'saved'     => false,
                'logged'    => ! empty( $log_entry ) && ! is_wp_error( $log_entry ),
                'reason'    => 'same_as_existing_alternate',
                'email'     => $email_actual,
                'log_entry' => $log_entry,
            ];
        }

        update_post_meta( $asistente_id, '_asistente_email_alternativo', $nuevo_email );

        $log_entry = evapp_asistente_append_email_alternativo_log( $asistente_id, array_merge( $base_log_context, [
            'accion'    => 'correo_alternativo_guardado',
            'resultado' => 'guardado',
            'detalle'   => ! empty( $contexto['detalle'] )
                ? $contexto['detalle']
                : 'Se guardó un nuevo correo alternativo sin reemplazar el correo principal del asistente.',
        ] ) );

        return [
            'saved'     => true,
            'logged'    => ! is_wp_error( $log_entry ),
            'reason'    => 'stored',
            'email'     => $nuevo_email,
            'old_email' => $email_actual,
            'log_entry' => $log_entry,
        ];
    }
}

// ============================================================
// 3. GUARDAR CAMPOS Y SINCRONIZAR TÍTULO AL GUARDAR
// ============================================================

add_action( 'save_post_eventosapp_asistente', function ( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! isset( $_POST['_asistente_nonce'] ) || ! wp_verify_nonce( $_POST['_asistente_nonce'], 'eventosapp_asistente_guardar' ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    // ── Validar unicidad de cédula ANTES de guardar ──────────────────────────
    $cedula_nueva = isset( $_POST['_asistente_cedula'] ) ? sanitize_text_field( $_POST['_asistente_cedula'] ) : '';
    $cedula_error = false;

    if ( $cedula_nueva !== '' ) {
        global $wpdb;
        $duplicado_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT pm.post_id
               FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key   = '_asistente_cedula'
                AND pm.meta_value = %s
                AND p.post_type   = 'eventosapp_asistente'
                AND p.post_status != 'trash'
                AND pm.post_id   != %d
              LIMIT 1",
            $cedula_nueva,
            $post_id
        ) );

        if ( $duplicado_id ) {
            $cedula_error = true;
            set_transient(
                'evapp_asistente_cedula_error_' . get_current_user_id(),
                [
                    'cedula'       => $cedula_nueva,
                    'duplicado_id' => $duplicado_id,
                ],
                60
            );
        }
    }

    // ── Campos de texto/email (sin foto, se gestiona por separado) ───────────
    $campos = [
        '_asistente_nombres'   => 'sanitize_text_field',
        '_asistente_apellidos' => 'sanitize_text_field',
        '_asistente_email'     => 'sanitize_email',
        '_asistente_telefono'  => 'sanitize_text_field',
        '_asistente_empresa'   => 'sanitize_text_field',
        '_asistente_cargo'     => 'sanitize_text_field',
        '_asistente_ciudad'    => 'sanitize_text_field',
        '_asistente_pais'      => 'sanitize_text_field',
    ];

    foreach ( $campos as $key => $sanitizer ) {
        if ( isset( $_POST[ $key ] ) ) {
            update_post_meta( $post_id, $key, $sanitizer( $_POST[ $key ] ) );
        }
    }

    // ── Correo alternativo: no reemplaza el correo principal ────────────────
    if ( isset( $_POST['_asistente_email_alternativo'] ) ) {
        $email_alt            = sanitize_email( wp_unslash( $_POST['_asistente_email_alternativo'] ) );
        $email_alt_previo     = sanitize_email( get_post_meta( $post_id, '_asistente_email_alternativo', true ) );
        $email_principal_meta = sanitize_email( get_post_meta( $post_id, '_asistente_email', true ) );

        if ( $email_alt === '' ) {
            if ( $email_alt_previo !== '' && function_exists( 'evapp_asistente_append_email_alternativo_log' ) ) {
                evapp_asistente_append_email_alternativo_log( $post_id, [
                    'accion'          => 'correo_alternativo_eliminado',
                    'resultado'       => 'eliminado',
                    'email'           => '',
                    'email_anterior'  => $email_alt_previo,
                    'email_principal' => $email_principal_meta,
                    'source'          => 'admin_asistente_metabox',
                    'detalle'         => 'El correo alternativo fue eliminado manualmente desde el administrador del asistente.',
                ] );
            }
            delete_post_meta( $post_id, '_asistente_email_alternativo' );
        } elseif ( is_email( $email_alt ) && function_exists( 'evapp_asistente_guardar_email_alternativo' ) ) {
            evapp_asistente_guardar_email_alternativo( $post_id, $email_alt, [
                'source'           => 'admin_asistente_metabox',
                'log_if_unchanged' => false,
                'detalle'          => 'El correo alternativo fue guardado manualmente desde la pantalla de edición del asistente.',
            ] );
        }
    }

    // Solo actualizar cédula si NO hay duplicado
    if ( ! $cedula_error ) {
        update_post_meta( $post_id, '_asistente_cedula', $cedula_nueva );
    }

    // ── Guardar array de fotos (_asistente_fotos_ids) ────────────────────────
    // El campo oculto `_asistente_fotos_ids` contiene un JSON de IDs ordenados.
    // _asistente_foto_id (foto principal) se deriva automáticamente del primero.
    if ( isset( $_POST['_asistente_fotos_ids'] ) ) {
        $fotos_raw = wp_unslash( $_POST['_asistente_fotos_ids'] );
        $fotos_arr = json_decode( $fotos_raw, true );

        if ( ! is_array( $fotos_arr ) ) $fotos_arr = [];

        // Sanitizar: solo enteros positivos
        $fotos_arr = array_values( array_filter( array_map( 'absint', $fotos_arr ) ) );

        update_post_meta( $post_id, '_asistente_fotos_ids', wp_json_encode( $fotos_arr ) );

        // Sincronizar foto principal (backward compat con face-checkin y otras funciones)
        $foto_principal = ! empty( $fotos_arr ) ? $fotos_arr[0] : 0;
        update_post_meta( $post_id, '_asistente_foto_id', $foto_principal );

    } elseif ( isset( $_POST['_asistente_foto_id'] ) ) {
        // Fallback: si por alguna razón solo viene el campo legacy, guardarlo
        $foto_id_legacy = absint( $_POST['_asistente_foto_id'] );
        update_post_meta( $post_id, '_asistente_foto_id', $foto_id_legacy );
        // Construir el array de fotos si no existe aún
        $fotos_existentes_json = get_post_meta( $post_id, '_asistente_fotos_ids', true );
        if ( ! $fotos_existentes_json && $foto_id_legacy ) {
            update_post_meta( $post_id, '_asistente_fotos_ids', wp_json_encode( [ $foto_id_legacy ] ) );
        }
    }

    // ── Sincronizar post_title con Nombres + Apellidos ───────────────────────
    $nombres   = isset( $_POST['_asistente_nombres'] )   ? sanitize_text_field( $_POST['_asistente_nombres'] )   : '';
    $apellidos = isset( $_POST['_asistente_apellidos'] ) ? sanitize_text_field( $_POST['_asistente_apellidos'] ) : '';
    $titulo    = trim( $nombres . ' ' . $apellidos );

    if ( $titulo ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            [
                'post_title' => $titulo,
                'post_name'  => wp_unique_post_slug(
                    sanitize_title( $titulo ),
                    $post_id,
                    get_post_status( $post_id ),
                    'eventosapp_asistente',
                    0
                ),
            ],
            [ 'ID' => $post_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        clean_post_cache( $post_id );
    }
}, 20 );

// ============================================================
// 3.1 ADMIN NOTICE: Cédula duplicada
// ============================================================

add_action( 'admin_notices', function () {
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'eventosapp_asistente' ) return;

    $error = get_transient( 'evapp_asistente_cedula_error_' . get_current_user_id() );
    if ( ! $error ) return;

    delete_transient( 'evapp_asistente_cedula_error_' . get_current_user_id() );

    $duplicado_url  = get_edit_post_link( $error['duplicado_id'] );
    $cedula_escapada = esc_html( $error['cedula'] );
    ?>
    <div class="notice notice-error is-dismissible">
        <p>
            <strong>⚠️ Cédula duplicada:</strong>
            La cédula <strong><?php echo $cedula_escapada; ?></strong> ya está registrada en
            <a href="<?php echo esc_url( $duplicado_url ); ?>" target="_blank">otro asistente</a>.
            <br>La cédula <strong>no fue actualizada</strong>. Corrija el valor e intente de nuevo.
        </p>
    </div>
    <?php
} );

// ============================================================
// 3.2 AJAX: Verificar unicidad de cédula en tiempo real
// ============================================================

add_action( 'wp_ajax_evapp_verificar_cedula', function () {
    check_ajax_referer( 'evapp_verificar_cedula_nonce', 'nonce' );

    $cedula    = sanitize_text_field( $_POST['cedula'] ?? '' );
    $post_id   = absint( $_POST['post_id'] ?? 0 );

    if ( ! $cedula ) {
        wp_send_json_success( [ 'disponible' => true ] );
    }

    global $wpdb;
    $duplicado_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT pm.post_id
           FROM {$wpdb->postmeta} pm
           JOIN {$wpdb->posts} p ON p.ID = pm.post_id
          WHERE pm.meta_key   = '_asistente_cedula'
            AND pm.meta_value = %s
            AND p.post_type   = 'eventosapp_asistente'
            AND p.post_status != 'trash'
            AND pm.post_id   != %d
          LIMIT 1",
        $cedula,
        $post_id
    ) );

    if ( $duplicado_id ) {
        $nombre_dup = get_the_title( $duplicado_id );
        wp_send_json_success( [
            'disponible'  => false,
            'duplicado_id'=> $duplicado_id,
            'nombre'      => $nombre_dup,
            'edit_url'    => get_edit_post_link( $duplicado_id ),
        ] );
    }

    wp_send_json_success( [ 'disponible' => true ] );
} );

// ============================================================
// 4. METABOXES
// ============================================================

add_action( 'add_meta_boxes', function () {

    add_meta_box(
        'eventosapp_asistente_datos',
        '👤 Datos del Asistente',
        'eventosapp_asistente_datos_metabox',
        'eventosapp_asistente',
        'normal',
        'high'
    );

    add_meta_box(
        'eventosapp_asistente_tickets_asociados',
        '🎟️ Tickets Asociados',
        'eventosapp_asistente_tickets_asociados_metabox',
        'eventosapp_asistente',
        'normal',
        'default'
    );

    add_meta_box(
        'eventosapp_asistente_historial_actualizaciones',
        '📋 Historial de Actualizaciones (vía Tickets)',
        'eventosapp_asistente_historial_actualizaciones_metabox',
        'eventosapp_asistente',
        'normal',
        'default'
    );

    add_meta_box(
        'eventosapp_asistente_foto',
        '📷 Foto del Asistente',
        'eventosapp_asistente_foto_metabox',
        'eventosapp_asistente',
        'side',
        'high'
    );

} );

// ============================================================
// 4.1 RENDER: Metabox Datos del Asistente
// ============================================================

function eventosapp_asistente_datos_metabox( $post ) {
    wp_nonce_field( 'eventosapp_asistente_guardar', '_asistente_nonce' );

    $nombres   = get_post_meta( $post->ID, '_asistente_nombres',   true );
    $apellidos = get_post_meta( $post->ID, '_asistente_apellidos', true );
    $cedula    = get_post_meta( $post->ID, '_asistente_cedula',    true );
    $email     = get_post_meta( $post->ID, '_asistente_email',     true );
    $email_alt = get_post_meta( $post->ID, '_asistente_email_alternativo', true );
    $telefono  = get_post_meta( $post->ID, '_asistente_telefono',  true );
    $empresa   = get_post_meta( $post->ID, '_asistente_empresa',   true );
    $cargo     = get_post_meta( $post->ID, '_asistente_cargo',     true );
    $ciudad    = get_post_meta( $post->ID, '_asistente_ciudad',    true );
    $pais      = get_post_meta( $post->ID, '_asistente_pais',      true );

    // Lista de países (los más comunes en América Latina + España primero)
    $paises = [
        'Colombia', 'Argentina', 'Bolivia', 'Brasil', 'Chile', 'Costa Rica',
        'Cuba', 'Ecuador', 'El Salvador', 'España', 'Guatemala', 'Honduras',
        'México', 'Nicaragua', 'Panamá', 'Paraguay', 'Perú', 'Puerto Rico',
        'República Dominicana', 'Uruguay', 'Venezuela',
        'Alemania', 'Canada', 'Estados Unidos', 'Francia', 'Italia',
        'Portugal', 'Reino Unido', 'Otro',
    ];
    ?>
    <style>
        .evapp-asistente-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 24px;
            margin-top: 10px;
        }
        .evapp-asistente-grid .evapp-full {
            grid-column: 1 / -1;
        }
        .evapp-asistente-grid label {
            display: block;
            font-weight: 600;
            margin-bottom: 4px;
            color: #1d2327;
            font-size: 13px;
        }
        .evapp-asistente-grid input[type="text"],
        .evapp-asistente-grid input[type="email"],
        .evapp-asistente-grid select {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            font-size: 13px;
            box-sizing: border-box;
        }
        .evapp-asistente-grid input:focus,
        .evapp-asistente-grid select:focus {
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
            outline: none;
        }
        .evapp-asistente-section-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #646970;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 6px;
            margin: 16px 0 4px;
            grid-column: 1 / -1;
        }
        /* Estados del campo cédula */
        #_asistente_cedula.evapp-cedula-ok {
            border-color: #00a32a !important;
            box-shadow: 0 0 0 1px #00a32a !important;
        }
        #_asistente_cedula.evapp-cedula-error {
            border-color: #d63638 !important;
            box-shadow: 0 0 0 1px #d63638 !important;
        }
        #evapp-cedula-feedback {
            margin-top: 5px;
            font-size: 12px;
            min-height: 18px;
        }
        #evapp-cedula-feedback.ok   { color: #00a32a; }
        #evapp-cedula-feedback.error{ color: #d63638; }
        #evapp-cedula-feedback.checking { color: #646970; font-style: italic; }
    </style>

    <div class="evapp-asistente-grid">

        <!-- Sección: Identificación -->
        <p class="evapp-asistente-section-title">Identificación Personal</p>

        <div>
            <label for="_asistente_nombres">Nombres <span style="color:#d63638">*</span></label>
            <input type="text" id="_asistente_nombres" name="_asistente_nombres"
                   value="<?php echo esc_attr( $nombres ); ?>" placeholder="Ej: Juan Carlos" />
        </div>

        <div>
            <label for="_asistente_apellidos">Apellidos <span style="color:#d63638">*</span></label>
            <input type="text" id="_asistente_apellidos" name="_asistente_apellidos"
                   value="<?php echo esc_attr( $apellidos ); ?>" placeholder="Ej: Gómez Martínez" />
        </div>

        <div>
            <label for="_asistente_cedula">
                Cédula de Ciudadanía <span style="color:#d63638">*</span>
                <span style="font-weight:400;color:#646970;font-size:11px;">(identificador único)</span>
            </label>
            <input type="text" id="_asistente_cedula" name="_asistente_cedula"
                   value="<?php echo esc_attr( $cedula ); ?>" placeholder="Ej: 1234567890"
                   autocomplete="off" />
            <div id="evapp-cedula-feedback"></div>
        </div>

        <!-- Sección: Contacto -->
        <p class="evapp-asistente-section-title">Información de Contacto</p>

        <div>
            <label for="_asistente_email">Correo Electrónico principal</label>
            <input type="email" id="_asistente_email" name="_asistente_email"
                   value="<?php echo esc_attr( $email ); ?>" placeholder="correo@ejemplo.com" />
        </div>

        <div>
            <label for="_asistente_email_alternativo">Correo Electrónico alternativo</label>
            <input type="email" id="_asistente_email_alternativo" name="_asistente_email_alternativo"
                   value="<?php echo esc_attr( $email_alt ); ?>" placeholder="correo-alternativo@ejemplo.com" />
            <p class="description" style="margin:4px 0 0;">Se usa para envíos solicitados por el asistente sin reemplazar el correo principal.</p>
        </div>

        <div>
            <label for="_asistente_telefono">Número de Contacto</label>
            <input type="text" id="_asistente_telefono" name="_asistente_telefono"
                   value="<?php echo esc_attr( $telefono ); ?>" placeholder="Ej: +57 300 000 0000" />
        </div>

        <!-- Sección: Empresa -->
        <p class="evapp-asistente-section-title">Información Profesional</p>

        <div>
            <label for="_asistente_empresa">Nombre de Empresa</label>
            <input type="text" id="_asistente_empresa" name="_asistente_empresa"
                   value="<?php echo esc_attr( $empresa ); ?>" placeholder="Ej: Acme S.A.S." />
        </div>

        <div>
            <label for="_asistente_cargo">Cargo</label>
            <input type="text" id="_asistente_cargo" name="_asistente_cargo"
                   value="<?php echo esc_attr( $cargo ); ?>" placeholder="Ej: Gerente General" />
        </div>

        <!-- Sección: Ubicación -->
        <p class="evapp-asistente-section-title">Ubicación</p>

        <div>
            <label for="_asistente_ciudad">Ciudad</label>
            <input type="text" id="_asistente_ciudad" name="_asistente_ciudad"
                   value="<?php echo esc_attr( $ciudad ); ?>" placeholder="Ej: Bogotá" />
        </div>

        <div>
            <label for="_asistente_pais">País</label>
            <select id="_asistente_pais" name="_asistente_pais">
                <option value="">— Seleccionar país —</option>
                <?php foreach ( $paises as $p ) : ?>
                    <option value="<?php echo esc_attr( $p ); ?>" <?php selected( $pais, $p ); ?>>
                        <?php echo esc_html( $p ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

    </div>

    <script>
    (function($){
        var cedulaTimer  = null;
        var cedulaActual = <?php echo json_encode( $cedula ); ?>;
        var postId       = <?php echo (int) $post->ID; ?>;
        var nonce        = <?php echo json_encode( wp_create_nonce( 'evapp_verificar_cedula_nonce' ) ); ?>;

        var $campo    = $('#_asistente_cedula');
        var $feedback = $('#evapp-cedula-feedback');

        function verificarCedula( valor ) {
            if ( ! valor ) {
                $campo.removeClass('evapp-cedula-ok evapp-cedula-error');
                $feedback.text('').removeClass('ok error checking');
                return;
            }

            // Si es la misma cédula que ya tenía el asistente, no verificar
            if ( valor === cedulaActual ) {
                $campo.removeClass('evapp-cedula-ok evapp-cedula-error').addClass('evapp-cedula-ok');
                $feedback.text('✔ Cédula actual del asistente.').removeClass('error checking').addClass('ok');
                return;
            }

            $campo.removeClass('evapp-cedula-ok evapp-cedula-error');
            $feedback.text('Verificando disponibilidad…').removeClass('ok error').addClass('checking');

            $.post( ajaxurl, {
                action : 'evapp_verificar_cedula',
                nonce  : nonce,
                cedula : valor,
                post_id: postId
            }, function( res ) {
                if ( ! res.success ) return;

                if ( res.data.disponible ) {
                    $campo.addClass('evapp-cedula-ok');
                    $feedback.text('✔ Cédula disponible.').removeClass('error checking').addClass('ok');
                } else {
                    $campo.addClass('evapp-cedula-error');
                    var msg = '✖ Esta cédula ya está registrada en: '
                        + '<a href="' + res.data.edit_url + '" target="_blank">'
                        + res.data.nombre + '</a>';
                    $feedback.html( msg ).removeClass('ok checking').addClass('error');
                }
            } );
        }

        $campo.on('input', function(){
            clearTimeout( cedulaTimer );
            var val = $(this).val().trim();
            cedulaTimer = setTimeout( function(){ verificarCedula( val ); }, 600 );
        });

        // Bloquear envío si hay cédula duplicada
        $( '#post' ).on( 'submit', function(e){
            if ( $campo.hasClass('evapp-cedula-error') ) {
                e.preventDefault();
                alert('⚠️ La cédula ingresada ya existe en otro asistente. Corrija el valor antes de guardar.');
                $campo.focus();
                return false;
            }
        });

    })(jQuery);
    </script>
    <?php
}

// ============================================================
// 4.2 RENDER: Metabox Tickets Asociados
// ============================================================

if ( ! function_exists( 'evapp_asistente_get_related_ticket_ids' ) ) {
    function evapp_asistente_get_related_ticket_ids( $asistente_id ) {
        $asistente_id = absint( $asistente_id );
        $ids          = [];

        $guardados = get_post_meta( $asistente_id, '_asistente_tickets_asociados', true );
        if ( is_array( $guardados ) ) {
            $ids = array_merge( $ids, array_map( 'absint', $guardados ) );
        }

        $por_asistente = get_posts( [
            'post_type'      => 'eventosapp_ticket',
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => 100,
            'meta_query'     => [
                [
                    'key'     => '_eventosapp_ticket_asistente_cpt_id',
                    'value'   => $asistente_id,
                    'compare' => '=',
                ],
            ],
        ] );

        if ( ! empty( $por_asistente ) ) {
            $ids = array_merge( $ids, array_map( 'absint', $por_asistente ) );
        }

        $cedula = sanitize_text_field( get_post_meta( $asistente_id, '_asistente_cedula', true ) );
        if ( $cedula !== '' ) {
            $por_cedula = get_posts( [
                'post_type'      => 'eventosapp_ticket',
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => 100,
                'meta_query'     => [
                    [
                        'key'     => '_eventosapp_asistente_cc',
                        'value'   => $cedula,
                        'compare' => '=',
                    ],
                ],
            ] );

            if ( ! empty( $por_cedula ) ) {
                $ids = array_merge( $ids, array_map( 'absint', $por_cedula ) );
            }
        }

        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

        usort( $ids, function( $a, $b ) {
            return strtotime( get_post_field( 'post_date', $b ) ) <=> strtotime( get_post_field( 'post_date', $a ) );
        } );

        return $ids;
    }
}

function eventosapp_asistente_tickets_asociados_metabox( $post ) {
    $ticket_ids = evapp_asistente_get_related_ticket_ids( $post->ID );

    if ( empty( $ticket_ids ) ) {
        echo '<p style="color:#888; padding:8px 0;">No hay tickets asociados a este asistente.</p>';
        return;
    }

    echo '<table class="widefat striped" style="margin-top:6px;">';
    echo '<thead><tr>';
    echo '<th>Ticket ID</th>';
    echo '<th>Evento</th>';
    echo '<th>Fecha del Evento</th>';
    echo '<th>Check-In</th>';
    echo '<th>Ir al Evento</th>';
    echo '</tr></thead><tbody>';

    foreach ( $ticket_ids as $ticket_id ) {
        $evento_id    = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        $evento_title = $evento_id ? get_the_title( $evento_id ) : '—';
        $fecha_evento = $evento_id ? get_post_meta( $evento_id, '_eventosapp_event_fecha', true ) : '';
        if ( ! $fecha_evento && $evento_id ) {
            $fecha_evento = get_post_meta( $evento_id, '_event_fecha', true );
        }

        $checkin_status = get_post_meta( $ticket_id, '_eventosapp_checkin_status', true );
        $checkin_done   = in_array( $checkin_status, [ 'checked_in', 'si', 'yes', '1' ], true );

        echo '<tr>';
        echo '<td><code>' . esc_html( get_the_title( $ticket_id ) ?: $ticket_id ) . '</code></td>';
        echo '<td>' . esc_html( $evento_title ?: '—' ) . '</td>';
        echo '<td>' . esc_html( $fecha_evento ?: '—' ) . '</td>';
        echo '<td>' . ( $checkin_done ? '<span style="color:#008a20;">✓ Sí</span>' : '<span style="color:#777;">—</span>' ) . '</td>';
        echo '<td>';
        if ( $evento_id && current_user_can( 'edit_post', $evento_id ) ) {
            echo '<a class="button button-primary button-small" href="' . esc_url( get_edit_post_link( $evento_id ) ) . '">Ver Evento ➜</a>';
        } else {
            echo '—';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '<p style="color:#666;font-size:12px;margin:8px 0 0;">Total de tickets asociados: ' . esc_html( count( $ticket_ids ) ) . '</p>';
}

// ============================================================
// 4.3 RENDER: Historial de Actualizaciones (vía Tickets)
// ============================================================

function eventosapp_asistente_historial_actualizaciones_metabox( $post ) {
    $history = get_post_meta( $post->ID, '_asistente_historial_actualizaciones_tickets', true );
    if ( ! is_array( $history ) ) {
        $history = [];
    }

    // Fallback: si existían registros anteriores solo en el historial específico de correo alternativo,
    // se muestran aquí para que no queden invisibles dentro del metabox solicitado.
    if ( empty( $history ) ) {
        $legacy_email_log = get_post_meta( $post->ID, '_asistente_email_alternativo_log', true );
        if ( is_array( $legacy_email_log ) ) {
            foreach ( $legacy_email_log as $legacy ) {
                if ( ! is_array( $legacy ) ) continue;
                $history[] = [
                    'fecha'             => $legacy['fecha'] ?? '',
                    'tipo'              => 'correo_alternativo',
                    'accion'            => $legacy['accion'] ?? 'correo_alternativo_registro',
                    'titulo'            => 'Correo alternativo capturado',
                    'resultado'         => $legacy['resultado'] ?? 'registrado',
                    'detalle'           => $legacy['detalle'] ?? 'Registro importado desde el historial específico del correo alternativo.',
                    'source'            => $legacy['source'] ?? 'unknown',
                    'source_label'      => $legacy['source_label'] ?? ( function_exists( 'evapp_asistente_email_alt_source_label' ) ? evapp_asistente_email_alt_source_label( $legacy['source'] ?? 'unknown' ) : '' ),
                    'flow'              => $legacy['flow'] ?? '',
                    'ticket_id'         => absint( $legacy['ticket_id'] ?? 0 ),
                    'ticket_title'      => $legacy['ticket_title'] ?? '',
                    'evento_id'         => absint( $legacy['evento_id'] ?? 0 ),
                    'evento_title'      => $legacy['evento_title'] ?? '',
                    'galeria_id'        => absint( $legacy['galeria_id'] ?? 0 ),
                    'galeria_title'     => $legacy['galeria_title'] ?? '',
                    'email_principal'   => $legacy['email_principal'] ?? '',
                    'email_alternativo' => $legacy['email'] ?? '',
                    'email_anterior'    => $legacy['email_anterior'] ?? '',
                    'cantidad_fotos'    => absint( $legacy['cantidad_fotos'] ?? 0 ),
                    'user_id'           => absint( $legacy['user_id'] ?? 0 ),
                    'ip'                => $legacy['ip'] ?? '',
                ];
            }
        }
    }

    if ( empty( $history ) ) {
        echo '<p style="color:#888; padding:8px 0;">No hay historial de actualizaciones registradas mediante tickets.</p>';
        return;
    }

    $history = array_reverse( $history );

    echo '<style>
        .evapp-update-history-card{border-left:4px solid #2271b1;background:#fff;margin:0 0 10px;padding:10px 12px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
        .evapp-update-history-card.is-warning{border-left-color:#dba617}
        .evapp-update-history-card.is-success{border-left-color:#00a32a}
        .evapp-update-history-card.is-deleted{border-left-color:#d63638}
        .evapp-update-history-title{display:flex;justify-content:space-between;gap:12px;font-weight:700;color:#1d2327;margin-bottom:6px}
        .evapp-update-history-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 16px;font-size:12px;color:#50575e;margin-top:8px}
        .evapp-update-history-detail{font-size:13px;color:#2c3338;margin-top:6px;line-height:1.45}
        .evapp-update-history-badge{display:inline-block;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;background:#eef5ff;color:#135e96;margin-right:4px}
        @media(max-width:782px){.evapp-update-history-meta{grid-template-columns:1fr}.evapp-update-history-title{display:block}}
    </style>';

    foreach ( $history as $entry ) {
        if ( ! is_array( $entry ) ) continue;

        $resultado = sanitize_key( $entry['resultado'] ?? '' );
        $cls       = 'evapp-update-history-card';
        if ( in_array( $resultado, [ 'guardado', 'registrado' ], true ) ) $cls .= ' is-success';
        if ( in_array( $resultado, [ 'coincide_con_principal', 'ya_existia' ], true ) ) $cls .= ' is-warning';
        if ( in_array( $resultado, [ 'eliminado' ], true ) ) $cls .= ' is-deleted';

        $ticket_id  = absint( $entry['ticket_id'] ?? 0 );
        $evento_id  = absint( $entry['evento_id'] ?? 0 );
        $galeria_id = absint( $entry['galeria_id'] ?? 0 );
        $user_id    = absint( $entry['user_id'] ?? 0 );
        $user       = $user_id ? get_userdata( $user_id ) : null;

        $ticket_title  = ! empty( $entry['ticket_title'] ) ? $entry['ticket_title'] : ( $ticket_id ? get_the_title( $ticket_id ) : '' );
        $evento_title  = ! empty( $entry['evento_title'] ) ? $entry['evento_title'] : ( $evento_id ? get_the_title( $evento_id ) : '' );
        $galeria_title = ! empty( $entry['galeria_title'] ) ? $entry['galeria_title'] : ( $galeria_id ? get_the_title( $galeria_id ) : '' );

        echo '<div class="' . esc_attr( $cls ) . '">';
        echo '<div class="evapp-update-history-title">';
        echo '<span>' . esc_html( $entry['titulo'] ?? 'Actualización registrada' ) . '</span>';
        echo '<span style="font-weight:400;color:#646970;">' . esc_html( $entry['fecha'] ?? '' ) . '</span>';
        echo '</div>';

        echo '<div>';
        echo '<span class="evapp-update-history-badge">' . esc_html( str_replace( '_', ' ', sanitize_key( $entry['accion'] ?? '' ) ) ) . '</span>';
        if ( $resultado !== '' ) {
            echo '<span class="evapp-update-history-badge">' . esc_html( str_replace( '_', ' ', $resultado ) ) . '</span>';
        }
        echo '</div>';

        if ( ! empty( $entry['detalle'] ) ) {
            echo '<div class="evapp-update-history-detail">' . esc_html( $entry['detalle'] ) . '</div>';
        }

        echo '<div class="evapp-update-history-meta">';
        echo '<div><strong>Origen:</strong> ' . esc_html( $entry['source_label'] ?? ( $entry['source'] ?? '—' ) ) . '</div>';
        echo '<div><strong>Flujo:</strong> ' . esc_html( $entry['flow'] ?? '—' ) . '</div>';
        echo '<div><strong>Correo principal:</strong> ' . esc_html( sanitize_email( $entry['email_principal'] ?? '' ) ?: '—' ) . '</div>';
        echo '<div><strong>Correo alternativo:</strong> ' . esc_html( sanitize_email( $entry['email_alternativo'] ?? '' ) ?: '—' ) . '</div>';
        if ( ! empty( $entry['email_anterior'] ) ) {
            echo '<div><strong>Correo anterior:</strong> ' . esc_html( sanitize_email( $entry['email_anterior'] ) ) . '</div>';
        }
        echo '<div><strong>Ticket:</strong> ' . esc_html( $ticket_title ?: ( $ticket_id ? '#' . $ticket_id : '—' ) ) . '</div>';
        echo '<div><strong>Evento:</strong> ' . esc_html( $evento_title ?: ( $evento_id ? '#' . $evento_id : '—' ) ) . '</div>';
        echo '<div><strong>Galería:</strong> ' . esc_html( $galeria_title ?: ( $galeria_id ? '#' . $galeria_id : '—' ) ) . '</div>';
        echo '<div><strong>Fotos solicitadas:</strong> ' . esc_html( absint( $entry['cantidad_fotos'] ?? 0 ) ) . '</div>';
        echo '<div><strong>IP:</strong> ' . esc_html( $entry['ip'] ?? '—' ) . '</div>';
        echo '<div><strong>Usuario:</strong> ' . esc_html( $user ? $user->display_name : ( $user_id ? 'Usuario #' . $user_id : 'No logueado' ) ) . '</div>';
        echo '</div>';
        echo '</div>';
    }
}

// ============================================================
// 4.2 RENDER: Metabox Foto del Asistente
// ============================================================

function eventosapp_asistente_foto_metabox( $post ) {

    // Obtener fotos existentes: primero desde el array, luego fallback legacy
    $fotos_ids_json = get_post_meta( $post->ID, '_asistente_fotos_ids', true );
    $fotos_ids      = [];

    if ( $fotos_ids_json ) {
        $decoded = json_decode( $fotos_ids_json, true );
        if ( is_array( $decoded ) ) {
            $fotos_ids = array_values( array_filter( array_map( 'absint', $decoded ) ) );
        }
    }

    // Fallback: si solo existe la foto legacy y no hay array aún
    if ( empty( $fotos_ids ) ) {
        $foto_id_legacy = (int) get_post_meta( $post->ID, '_asistente_foto_id', true );
        if ( $foto_id_legacy ) {
            $fotos_ids = [ $foto_id_legacy ];
        }
    }

    $foto_principal_id = ! empty( $fotos_ids ) ? $fotos_ids[0] : 0;
    ?>
    <style>
        .evapp-fotos-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
            min-height: 50px;
        }
        .evapp-foto-item {
            position: relative;
            width: 80px;
            flex-shrink: 0;
            cursor: grab;
        }
        .evapp-foto-item img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 6px;
            border: 2px solid #c3c4c7;
            display: block;
        }
        .evapp-foto-item.is-principal img {
            border-color: #2271b1;
            box-shadow: 0 0 0 2px #2271b1;
        }
        .evapp-foto-principal-badge {
            position: absolute;
            bottom: 20px;
            left: 0;
            right: 0;
            background: #2271b1;
            color: #fff;
            font-size: 9px;
            font-weight: 700;
            text-align: center;
            padding: 2px 0;
            text-transform: uppercase;
            letter-spacing: .3px;
        }
        .evapp-foto-remove {
            position: absolute;
            top: -6px;
            right: -6px;
            width: 20px;
            height: 20px;
            background: #d63638;
            color: #fff;
            border: 2px solid #fff;
            border-radius: 50%;
            font-size: 12px;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            z-index: 10;
        }
        .evapp-foto-remove:hover { background: #b32d2e; }
        .evapp-foto-num {
            display: block;
            text-align: center;
            font-size: 10px;
            color: #646970;
            margin-top: 3px;
        }
        .evapp-foto-empty {
            width: 80px;
            height: 80px;
            border: 2px dashed #c3c4c7;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #8c8f94;
            font-size: 11px;
            text-align: center;
            line-height: 1.3;
            padding: 6px;
            box-sizing: border-box;
        }
        .evapp-fotos-actions {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 4px;
        }
        .evapp-fotos-actions .button { width: 100%; text-align: center; }
        .evapp-fotos-hint {
            font-size: 11px;
            color: #8c8f94;
            margin-top: 6px;
            line-height: 1.4;
        }
    </style>

    <!-- Campo oculto: array JSON de todos los IDs (nuevo, multi-foto) -->
    <input type="hidden"
           id="_asistente_fotos_ids"
           name="_asistente_fotos_ids"
           value="<?php echo esc_attr( wp_json_encode( $fotos_ids ) ); ?>" />

    <!-- Campo oculto: foto principal (backward compat con face-checkin y SQL queries) -->
    <input type="hidden"
           id="_asistente_foto_id"
           name="_asistente_foto_id"
           value="<?php echo esc_attr( $foto_principal_id ); ?>" />

    <!-- Grid de fotos -->
    <div id="evapp-fotos-grid" class="evapp-fotos-grid">
        <?php if ( empty( $fotos_ids ) ) : ?>
            <div class="evapp-foto-empty" id="evapp-fotos-empty-msg">
                📷<br>Sin fotos
            </div>
        <?php else : ?>
            <?php foreach ( $fotos_ids as $idx => $att_id ) :
                $thumb_url = wp_get_attachment_image_url( $att_id, [ 80, 80 ] );
                if ( ! $thumb_url ) continue;
                ?>
                <div class="evapp-foto-item<?php echo $idx === 0 ? ' is-principal' : ''; ?>"
                     data-id="<?php echo esc_attr( $att_id ); ?>">
                    <img src="<?php echo esc_url( $thumb_url ); ?>"
                         alt="Foto <?php echo esc_attr( $idx + 1 ); ?>" />
                    <?php if ( $idx === 0 ) : ?>
                        <span class="evapp-foto-principal-badge">Principal</span>
                    <?php endif; ?>
                    <button type="button" class="evapp-foto-remove" title="Eliminar foto">×</button>
                    <span class="evapp-foto-num">#<?php echo esc_html( $idx + 1 ); ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="evapp-fotos-actions">
        <button type="button" id="evapp-fotos-add-btn" class="button button-primary">
            📷 Agregar foto(s)
        </button>
        <?php if ( ! empty( $fotos_ids ) ) : ?>
        <button type="button" id="evapp-fotos-clear-btn" class="button" style="color:#d63638;">
            🗑️ Eliminar todas las fotos
        </button>
        <?php endif; ?>
    </div>

    <p class="evapp-fotos-hint">
        Arrastra para reordenar. La <strong>primera foto</strong> es la principal (usada en reconocimiento facial y columnas). Puedes agregar hasta 5 fotos para mejorar la precisión del reconocimiento.
    </p>

    <script>
    jQuery(document).ready(function($){

        var $grid     = $('#evapp-fotos-grid');
        var $inputIds = $('#_asistente_fotos_ids');
        var $inputPri = $('#_asistente_foto_id');
        var MAX_FOTOS = 5;
        var frame;

        // ── Inicializar Sortable (solo si jQuery UI está disponible) ──────────
        if ( $.fn.sortable ) {
            $grid.sortable({
                items: '.evapp-foto-item',
                placeholder: 'evapp-foto-placeholder',
                opacity: 0.7,
                tolerance: 'pointer',
                update: function(){ evappSyncIds(); }
            });
        }

        // ── Sincronizar campos ocultos ────────────────────────────────────────
        function evappSyncIds() {
            var ids = [];
            $grid.find('.evapp-foto-item').each(function(){
                ids.push( parseInt( $(this).data('id'), 10 ) );
            });
            $inputIds.val( JSON.stringify(ids) );
            $inputPri.val( ids.length ? ids[0] : '' );
            // Actualizar badges de "Principal" y numeración
            $grid.find('.evapp-foto-item').each(function(i){
                $(this).toggleClass('is-principal', i === 0);
                $(this).find('.evapp-foto-principal-badge').remove();
                $(this).find('.evapp-foto-num').text( '#' + (i + 1) );
                if ( i === 0 ) {
                    $(this).find('img').after(
                        '<span class="evapp-foto-principal-badge">Principal</span>'
                    );
                }
            });
            evappToggleEmpty();
        }

        function evappToggleEmpty() {
            var count = $grid.find('.evapp-foto-item').length;

            // Mostrar/ocultar mensaje vacío
            var $empty = $('#evapp-fotos-empty-msg');
            if ( $empty.length ) {
                $empty.toggle( count === 0 );
            } else if ( count === 0 ) {
                $grid.append('<div class="evapp-foto-empty" id="evapp-fotos-empty-msg">📷<br>Sin fotos</div>');
            }

            // Mostrar/ocultar botón "Eliminar todas"
            if ( count === 0 ) {
                $('#evapp-fotos-clear-btn').hide();
            } else {
                if ( ! $('#evapp-fotos-clear-btn').length ) {
                    $('<button type="button" id="evapp-fotos-clear-btn" class="button" style="color:#d63638;">🗑️ Eliminar todas las fotos</button>')
                        .insertAfter('#evapp-fotos-add-btn');
                }
                $('#evapp-fotos-clear-btn').show();
            }
        }

        // ── Eliminar foto individual (delegación sobre el grid) ───────────────
        $grid.on('click', '.evapp-foto-remove', function(e){
            e.preventDefault();
            e.stopPropagation();
            $(this).closest('.evapp-foto-item').remove();
            evappSyncIds();
        });

        // ── Eliminar todas las fotos (delegación sobre document) ─────────────
        $(document).on('click', '#evapp-fotos-clear-btn', function(e){
            e.preventDefault();
            if ( ! confirm('¿Estás seguro de que quieres eliminar todas las fotos de este asistente?') ) return;
            $grid.find('.evapp-foto-item').remove();
            evappSyncIds();
        });

        // ── Agregar fotos (solo si wp.media está disponible) ─────────────────
        $('#evapp-fotos-add-btn').on('click', function(e){
            e.preventDefault();

            if ( typeof wp === 'undefined' || ! wp.media ) {
                alert('El gestor de medios no está disponible. Por favor recarga la página.');
                return;
            }

            var currentCount = $grid.find('.evapp-foto-item').length;
            if ( currentCount >= MAX_FOTOS ) {
                alert('Ya tienes ' + MAX_FOTOS + ' fotos, que es el máximo permitido. Elimina alguna antes de agregar otra.');
                return;
            }

            if ( frame ) { frame.open(); return; }

            frame = wp.media({
                title   : 'Seleccionar fotos del asistente',
                button  : { text: 'Agregar foto(s)' },
                multiple: true,
                library : { type: 'image' }
            });

            frame.on('select', function(){
                var selection = frame.state().get('selection');
                var added     = 0;
                var current   = $grid.find('.evapp-foto-item').length;

                selection.each(function(attachment){
                    if ( current + added >= MAX_FOTOS ) return;
                    var att   = attachment.toJSON();
                    // Evitar duplicados
                    if ( $grid.find('[data-id="' + att.id + '"]').length ) return;

                    var thumb = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                    var $item = $(
                        '<div class="evapp-foto-item" data-id="' + att.id + '">' +
                        '<img src="' + thumb + '" alt="Foto" />' +
                        '<button type="button" class="evapp-foto-remove" title="Eliminar foto">&times;</button>' +
                        '<span class="evapp-foto-num">#?</span>' +
                        '</div>'
                    );
                    $grid.append($item);
                    added++;
                });

                if ( $.fn.sortable ) {
                    $grid.sortable('refresh');
                }
                evappSyncIds();
            });

            frame.open();
        });

        // Sincronizar al cargar para inicializar numeración y estado
        evappSyncIds();

    });
    </script>
    <?php
}

// ============================================================
// 5. ENCOLAR wp.media SOLO EN EDICIÓN DE ESTE CPT
// ============================================================

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'eventosapp_asistente' ) return;

    wp_enqueue_media();
} );

// ============================================================
// 6. COLUMNAS PERSONALIZADAS EN EL LISTADO DEL CPT
// ============================================================

add_filter( 'manage_eventosapp_asistente_posts_columns', function ( $cols ) {
    $new = [];
    $new['cb']              = $cols['cb'];
    $new['evapp_foto']      = 'Foto';
    $new['title']           = 'Nombre';
    $new['evapp_cedula']    = 'Cédula';
    $new['evapp_empresa']   = 'Empresa';
    $new['evapp_cargo']     = 'Cargo';
    $new['evapp_ciudad']    = 'Ciudad';
    $new['evapp_pais']      = 'País';
    $new['evapp_email']     = 'Correo';
    $new['evapp_email_alt'] = 'Correo alt.';
    $new['evapp_telefono']  = 'Teléfono';
    $new['date']            = $cols['date'];
    return $new;
} );

add_action( 'manage_eventosapp_asistente_posts_custom_column', function ( $column, $post_id ) {
    switch ( $column ) {

        case 'evapp_foto':
            $foto_id = (int) get_post_meta( $post_id, '_asistente_foto_id', true );
            if ( $foto_id ) {
                $url = wp_get_attachment_image_url( $foto_id, [ 48, 48 ] );
                if ( $url ) {
                    echo '<img src="' . esc_url( $url ) . '" style="width:48px;height:48px;object-fit:cover;border-radius:50%;border:1px solid #e0e0e0;" />';
                }
            } else {
                echo '<span class="dashicons dashicons-id-alt" style="color:#ccc;font-size:32px;width:32px;height:32px;"></span>';
            }
            break;

        case 'evapp_cedula':
            echo esc_html( get_post_meta( $post_id, '_asistente_cedula', true ) ?: '—' );
            break;

        case 'evapp_empresa':
            echo esc_html( get_post_meta( $post_id, '_asistente_empresa', true ) ?: '—' );
            break;

        case 'evapp_cargo':
            echo esc_html( get_post_meta( $post_id, '_asistente_cargo', true ) ?: '—' );
            break;

        case 'evapp_ciudad':
            echo esc_html( get_post_meta( $post_id, '_asistente_ciudad', true ) ?: '—' );
            break;

        case 'evapp_pais':
            echo esc_html( get_post_meta( $post_id, '_asistente_pais', true ) ?: '—' );
            break;

        case 'evapp_email':
            $mail = get_post_meta( $post_id, '_asistente_email', true );
            echo $mail ? '<a href="mailto:' . esc_attr( $mail ) . '">' . esc_html( $mail ) . '</a>' : '—';
            break;

        case 'evapp_email_alt':
            $mail_alt = get_post_meta( $post_id, '_asistente_email_alternativo', true );
            echo $mail_alt ? '<a href="mailto:' . esc_attr( $mail_alt ) . '">' . esc_html( $mail_alt ) . '</a>' : '—';
            break;

        case 'evapp_telefono':
            $tel = get_post_meta( $post_id, '_asistente_telefono', true );
            echo $tel ? '<a href="tel:' . esc_attr( $tel ) . '">' . esc_html( $tel ) . '</a>' : '—';
            break;
    }
}, 10, 2 );

// ============================================================
// 7. COLUMNAS ORDENABLES
// ============================================================

add_filter( 'manage_edit-eventosapp_asistente_sortable_columns', function ( $cols ) {
    $cols['title']         = 'title';
    $cols['evapp_cedula']  = 'evapp_cedula';
    $cols['evapp_empresa'] = 'evapp_empresa';
    $cols['evapp_ciudad']  = 'evapp_ciudad';
    $cols['evapp_pais']    = 'evapp_pais';
    return $cols;
} );

// ============================================================
// 8. HELPER PÚBLICO: Obtener asistente por ID
//    Uso: $data = eventosapp_get_asistente( $id );
//    Retorna array con todos los campos o false si no existe.
// ============================================================

if ( ! function_exists( 'eventosapp_get_asistente' ) ) {
    function eventosapp_get_asistente( $asistente_id ) {
        $post = get_post( $asistente_id );
        if ( ! $post || $post->post_type !== 'eventosapp_asistente' ) return false;

        $foto_id = (int) get_post_meta( $asistente_id, '_asistente_foto_id', true );

        return [
            'id'         => $asistente_id,
            'nombres'    => get_post_meta( $asistente_id, '_asistente_nombres',   true ),
            'apellidos'  => get_post_meta( $asistente_id, '_asistente_apellidos', true ),
            'nombre_completo' => trim(
                get_post_meta( $asistente_id, '_asistente_nombres', true ) . ' ' .
                get_post_meta( $asistente_id, '_asistente_apellidos', true )
            ),
            'cedula'     => get_post_meta( $asistente_id, '_asistente_cedula',    true ),
            'email'      => get_post_meta( $asistente_id, '_asistente_email',     true ),
            'email_alternativo' => get_post_meta( $asistente_id, '_asistente_email_alternativo', true ),
            'telefono'   => get_post_meta( $asistente_id, '_asistente_telefono',  true ),
            'empresa'    => get_post_meta( $asistente_id, '_asistente_empresa',   true ),
            'cargo'      => get_post_meta( $asistente_id, '_asistente_cargo',     true ),
            'ciudad'     => get_post_meta( $asistente_id, '_asistente_ciudad',    true ),
            'pais'       => get_post_meta( $asistente_id, '_asistente_pais',      true ),
            'foto_id'       => $foto_id,
            'foto_url'      => $foto_id ? wp_get_attachment_image_url( $foto_id, 'full' )      : '',
            'foto_url_thumb'=> $foto_id ? wp_get_attachment_image_url( $foto_id, 'thumbnail' ) : '',
        ];
    }
}

// ============================================================
// 9. HELPER PÚBLICO: Buscar asistente por Cédula
//    Uso: $asistente_id = eventosapp_find_asistente_by_cedula( '1234567890' );
// ============================================================

if ( ! function_exists( 'eventosapp_find_asistente_by_cedula' ) ) {
    function eventosapp_find_asistente_by_cedula( $cedula ) {
        if ( ! $cedula ) return false;
        $q = new WP_Query( [
            'post_type'      => 'eventosapp_asistente',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'     => '_asistente_cedula',
                    'value'   => sanitize_text_field( $cedula ),
                    'compare' => '=',
                ],
            ],
            'fields' => 'ids',
        ] );
        return ! empty( $q->posts ) ? (int) $q->posts[0] : false;
    }
}
