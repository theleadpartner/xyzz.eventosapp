<?php
/**
 * EventosApp - Módulo de Expositores
 *
 * - CPT Expositores.
 * - Metabox de activación/asignación por evento.
 * - Productos/consumibles por expositor y evento.
 * - Control de entregas por QR.
 * - Métricas, autorización de descarga y CSV.
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'EVENTOSAPP_EXPOSITORES_DB_VERSION' ) ) {
    define( 'EVENTOSAPP_EXPOSITORES_DB_VERSION', '1.0.0' );
}

// ============================================================
// 1. TABLA DE ENTREGAS
// ============================================================

if ( ! function_exists( 'eventosapp_expositores_table_name' ) ) {
    function eventosapp_expositores_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'eventosapp_expositor_deliveries';
    }
}

if ( ! function_exists( 'eventosapp_expositores_install_tables' ) ) {
    function eventosapp_expositores_install_tables() {
        global $wpdb;

        $table_name      = eventosapp_expositores_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            expositor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            product_id VARCHAR(80) NOT NULL DEFAULT '',
            product_name VARCHAR(190) NOT NULL DEFAULT '',
            ticket_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            public_ticket_id VARCHAR(120) NOT NULL DEFAULT '',
            attendee_name VARCHAR(190) NOT NULL DEFAULT '',
            attendee_lastname VARCHAR(190) NOT NULL DEFAULT '',
            attendee_email VARCHAR(190) NOT NULL DEFAULT '',
            attendee_phone VARCHAR(80) NOT NULL DEFAULT '',
            attendee_company VARCHAR(190) NOT NULL DEFAULT '',
            attendee_document VARCHAR(120) NOT NULL DEFAULT '',
            attendee_location VARCHAR(190) NOT NULL DEFAULT '',
            delivered_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            delivered_by_name VARCHAR(190) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            created_at_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY event_expositor (event_id, expositor_id),
            KEY product_lookup (event_id, expositor_id, product_id),
            KEY attendee_lookup (event_id, expositor_id, product_id, ticket_id),
            KEY created_lookup (created_at_gmt)
        ) {$charset_collate};";

        dbDelta( $sql );
        update_option( 'eventosapp_expositores_db_version', EVENTOSAPP_EXPOSITORES_DB_VERSION );
    }
}

add_action( 'init', function () {
    if ( get_option( 'eventosapp_expositores_db_version' ) !== EVENTOSAPP_EXPOSITORES_DB_VERSION ) {
        eventosapp_expositores_install_tables();
    }
}, 20 );

// ============================================================
// 2. CPT EXPOSITORES
// ============================================================

add_action( 'init', function () {
    register_post_type( 'eventosapp_expositor', [
        'labels' => [
            'name'               => 'Expositores',
            'singular_name'      => 'Expositor',
            'add_new'            => 'Agregar Nuevo',
            'add_new_item'       => 'Agregar Nuevo Expositor',
            'edit_item'          => 'Editar Expositor',
            'new_item'           => 'Nuevo Expositor',
            'view_item'          => 'Ver Expositor',
            'search_items'       => 'Buscar Expositores',
            'not_found'          => 'No se encontraron expositores',
            'not_found_in_trash' => 'No se encontraron expositores en la papelera',
            'menu_name'          => 'Expositores',
            'all_items'          => 'Todos los Expositores',
        ],
        'public'              => false,
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'show_ui'             => true,
        'menu_icon'           => 'dashicons-store',
        'supports'            => [ 'title' ],
        'has_archive'         => false,
        'rewrite'             => false,
        'show_in_rest'        => false,
        'show_in_menu'        => false,
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
    ] );
} );

add_action( 'admin_head', function () {
    $screen = get_current_screen();
    if ( $screen && $screen->post_type === 'eventosapp_expositor' ) {
        echo '<style>#titlediv{display:none!important;}</style>';
    }
} );

add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'eventosapp_expositor_datos',
        '🏪 Datos del Expositor',
        'eventosapp_expositor_datos_metabox',
        'eventosapp_expositor',
        'normal',
        'high'
    );

    add_meta_box(
        'eventosapp_expositor_logo',
        '🖼️ Logo del Expositor',
        'eventosapp_expositor_logo_metabox',
        'eventosapp_expositor',
        'side',
        'high'
    );

    add_meta_box(
        'eventosapp_expositor_eventos',
        '📅 Participación en Eventos',
        'eventosapp_expositor_eventos_metabox',
        'eventosapp_expositor',
        'normal',
        'default'
    );

    add_meta_box(
        'eventosapp_event_expositores',
        '🏪 Expositores del Evento',
        'eventosapp_event_expositores_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
} );

function eventosapp_expositor_get_clientes_options() {
    return get_posts( [
        'post_type'      => 'eventosapp_cliente',
        'post_status'    => [ 'publish', 'draft', 'private' ],
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ] );
}

function eventosapp_expositor_datos_metabox( $post ) {
    wp_nonce_field( 'eventosapp_expositor_guardar', '_expositor_nonce' );

    $nombre     = get_post_meta( $post->ID, '_expositor_nombre_empresa', true );
    $razon      = get_post_meta( $post->ID, '_expositor_razon_social', true );
    $nit        = get_post_meta( $post->ID, '_expositor_nit', true );
    $ciudad     = get_post_meta( $post->ID, '_expositor_ciudad', true );
    $depto      = get_post_meta( $post->ID, '_expositor_departamento', true );
    $direccion  = get_post_meta( $post->ID, '_expositor_direccion', true );
    $telefono   = get_post_meta( $post->ID, '_expositor_telefono', true );
    $email      = get_post_meta( $post->ID, '_expositor_email', true );
    $contacto   = get_post_meta( $post->ID, '_expositor_contacto', true );
    $cliente_id = absint( get_post_meta( $post->ID, '_expositor_cliente_id', true ) );
    $clientes   = eventosapp_expositor_get_clientes_options();

    $departamentos = [
        'Amazonas','Antioquia','Arauca','Atlántico','Bolívar','Boyacá','Caldas',
        'Caquetá','Casanare','Cauca','Cesar','Chocó','Córdoba','Cundinamarca',
        'Guainía','Guaviare','Huila','La Guajira','Magdalena','Meta','Nariño',
        'Norte de Santander','Putumayo','Quindío','Risaralda','San Andrés y Providencia',
        'Santander','Sucre','Tolima','Valle del Cauca','Vaupés','Vichada','Bogotá D.C.',
    ];
    sort( $departamentos );
    ?>
    <style>
        .evapp-expositor-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 24px;margin-top:8px;}
        .evapp-expositor-grid .full-width{grid-column:1/-1;}
        .evapp-expositor-field label{display:block;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:#50575e;margin-bottom:5px;}
        .evapp-expositor-field input,.evapp-expositor-field select{width:100%;padding:8px 10px;border:1px solid #c3c4c7;border-radius:6px;font-size:14px;background:#fff;}
        .evapp-expositor-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#8a8f98;border-bottom:1px solid #e9ecef;padding-bottom:6px;margin:20px 0 14px;grid-column:1/-1;}
        @media(max-width:782px){.evapp-expositor-grid{grid-template-columns:1fr;}}
    </style>
    <div class="evapp-expositor-grid">
        <div class="evapp-expositor-section-title">📋 Identificación</div>

        <div class="evapp-expositor-field full-width">
            <label for="_expositor_nombre_empresa">Nombre comercial del expositor <span style="color:#d63638">*</span></label>
            <input type="text" id="_expositor_nombre_empresa" name="_expositor_nombre_empresa" value="<?php echo esc_attr( $nombre ); ?>" required placeholder="Ej. Marca Aliada S.A.S.">
        </div>

        <div class="evapp-expositor-field">
            <label for="_expositor_razon_social">Razón social</label>
            <input type="text" id="_expositor_razon_social" name="_expositor_razon_social" value="<?php echo esc_attr( $razon ); ?>">
        </div>

        <div class="evapp-expositor-field">
            <label for="_expositor_nit">NIT / Identificación fiscal</label>
            <input type="text" id="_expositor_nit" name="_expositor_nit" value="<?php echo esc_attr( $nit ); ?>">
        </div>

        <div class="evapp-expositor-section-title">🔗 Asociación comercial</div>

        <div class="evapp-expositor-field full-width">
            <label for="_expositor_cliente_id">Cliente / Organizador asociado</label>
            <select id="_expositor_cliente_id" name="_expositor_cliente_id">
                <option value="0">— Sin cliente asociado —</option>
                <?php foreach ( $clientes as $cid ) :
                    $cnombre = get_post_meta( $cid, '_cliente_nombre_empresa', true ) ?: get_the_title( $cid );
                    ?>
                    <option value="<?php echo esc_attr( $cid ); ?>" <?php selected( $cliente_id, $cid ); ?>><?php echo esc_html( $cnombre ); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description">Este campo controla en qué eventos puede seleccionarse el expositor. Sólo aparecerá en eventos cuyo organizador sea este cliente.</p>
        </div>

        <div class="evapp-expositor-section-title">📍 Ubicación y contacto</div>

        <div class="evapp-expositor-field">
            <label for="_expositor_contacto">Persona de contacto</label>
            <input type="text" id="_expositor_contacto" name="_expositor_contacto" value="<?php echo esc_attr( $contacto ); ?>">
        </div>

        <div class="evapp-expositor-field">
            <label for="_expositor_email">Correo electrónico</label>
            <input type="email" id="_expositor_email" name="_expositor_email" value="<?php echo esc_attr( $email ); ?>">
        </div>

        <div class="evapp-expositor-field">
            <label for="_expositor_telefono">Teléfono</label>
            <input type="text" id="_expositor_telefono" name="_expositor_telefono" value="<?php echo esc_attr( $telefono ); ?>">
        </div>

        <div class="evapp-expositor-field">
            <label for="_expositor_ciudad">Ciudad</label>
            <input type="text" id="_expositor_ciudad" name="_expositor_ciudad" value="<?php echo esc_attr( $ciudad ); ?>">
        </div>

        <div class="evapp-expositor-field">
            <label for="_expositor_departamento">Departamento</label>
            <select id="_expositor_departamento" name="_expositor_departamento">
                <option value="">— Seleccionar —</option>
                <?php foreach ( $departamentos as $dep ) : ?>
                    <option value="<?php echo esc_attr( $dep ); ?>" <?php selected( $depto, $dep ); ?>><?php echo esc_html( $dep ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="evapp-expositor-field full-width">
            <label for="_expositor_direccion">Dirección</label>
            <input type="text" id="_expositor_direccion" name="_expositor_direccion" value="<?php echo esc_attr( $direccion ); ?>">
        </div>
    </div>
    <?php
}

function eventosapp_expositor_logo_metabox( $post ) {
    $logo_id = absint( get_post_meta( $post->ID, '_expositor_logo_id', true ) );
    $logo    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
    ?>
    <div id="evapp-expositor-logo-preview" style="text-align:center;margin-bottom:12px;">
        <?php if ( $logo ) : ?>
            <img src="<?php echo esc_url( $logo ); ?>" style="max-width:100%;height:auto;border:1px solid #ddd;border-radius:6px;padding:6px;background:#fff;">
        <?php else : ?>
            <div style="border:1px dashed #ccd0d4;border-radius:6px;padding:22px;color:#777;">Sin logo</div>
        <?php endif; ?>
    </div>
    <input type="hidden" name="_expositor_logo_id" id="_expositor_logo_id" value="<?php echo esc_attr( $logo_id ); ?>">
    <button type="button" class="button" id="evapp-expositor-logo-select">Seleccionar logo</button>
    <button type="button" class="button" id="evapp-expositor-logo-remove" <?php disabled( ! $logo_id ); ?>>Quitar</button>
    <script>
    jQuery(function($){
        var frame;
        $('#evapp-expositor-logo-select').on('click', function(e){
            e.preventDefault();
            if(frame){ frame.open(); return; }
            frame = wp.media({title:'Seleccionar logo del expositor', button:{text:'Usar este logo'}, multiple:false});
            frame.on('select', function(){
                var attachment = frame.state().get('selection').first().toJSON();
                $('#_expositor_logo_id').val(attachment.id);
                var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                $('#evapp-expositor-logo-preview').html('<img src="'+url+'" style="max-width:100%;height:auto;border:1px solid #ddd;border-radius:6px;padding:6px;background:#fff;">');
                $('#evapp-expositor-logo-remove').prop('disabled', false);
            });
            frame.open();
        });
        $('#evapp-expositor-logo-remove').on('click', function(e){
            e.preventDefault();
            $('#_expositor_logo_id').val('');
            $('#evapp-expositor-logo-preview').html('<div style="border:1px dashed #ccd0d4;border-radius:6px;padding:22px;color:#777;">Sin logo</div>');
            $(this).prop('disabled', true);
        });
    });
    </script>
    <?php
}

function eventosapp_expositor_eventos_metabox( $post ) {
    $expositor_id = absint( $post->ID );
    $eventos = get_posts( [
        'post_type'      => 'eventosapp_event',
        'post_status'    => [ 'publish', 'draft', 'private' ],
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => '_eventosapp_event_expositores',
                'value'   => 'i:' . $expositor_id . ';',
                'compare' => 'LIKE',
            ],
        ],
    ] );

    if ( empty( $eventos ) ) {
        echo '<p style="color:#666;margin:8px 0;">Este expositor todavía no está asociado a eventos.</p>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr><th>Evento</th><th>Cliente/Organizador</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';
    foreach ( $eventos as $event_id ) {
        $cliente_id = absint( get_post_meta( $event_id, '_eventosapp_cliente_id', true ) );
        $cliente    = $cliente_id ? ( get_post_meta( $cliente_id, '_cliente_nombre_empresa', true ) ?: get_the_title( $cliente_id ) ) : '—';
        printf(
            '<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td><a class="button button-small" href="%s">Ir al evento</a></td></tr>',
            esc_html( get_the_title( $event_id ) ),
            esc_html( $cliente ),
            esc_html( get_post_status( $event_id ) ),
            esc_url( get_edit_post_link( $event_id ) )
        );
    }
    echo '</tbody></table>';
}

add_action( 'save_post_eventosapp_expositor', function ( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! isset( $_POST['_expositor_nonce'] ) || ! wp_verify_nonce( $_POST['_expositor_nonce'], 'eventosapp_expositor_guardar' ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $fields = [
        '_expositor_nombre_empresa' => 'sanitize_text_field',
        '_expositor_razon_social'   => 'sanitize_text_field',
        '_expositor_nit'            => 'sanitize_text_field',
        '_expositor_ciudad'         => 'sanitize_text_field',
        '_expositor_departamento'   => 'sanitize_text_field',
        '_expositor_direccion'      => 'sanitize_text_field',
        '_expositor_telefono'       => 'sanitize_text_field',
        '_expositor_email'          => 'sanitize_email',
        '_expositor_contacto'       => 'sanitize_text_field',
        '_expositor_cliente_id'     => 'absint',
        '_expositor_logo_id'        => 'absint',
    ];

    foreach ( $fields as $key => $sanitize ) {
        if ( isset( $_POST[ $key ] ) ) {
            update_post_meta( $post_id, $key, $sanitize( wp_unslash( $_POST[ $key ] ) ) );
        }
    }

    $nombre = isset( $_POST['_expositor_nombre_empresa'] ) ? sanitize_text_field( wp_unslash( $_POST['_expositor_nombre_empresa'] ) ) : '';
    if ( $nombre ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            [
                'post_title' => $nombre,
                'post_name'  => wp_unique_post_slug( sanitize_title( $nombre ), $post_id, get_post_status( $post_id ), 'eventosapp_expositor', 0 ),
            ],
            [ 'ID' => $post_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        clean_post_cache( $post_id );
    }
}, 20 );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
    $screen = get_current_screen();
    if ( ! $screen ) return;
    if ( in_array( $screen->post_type, [ 'eventosapp_expositor', 'eventosapp_event' ], true ) ) {
        wp_enqueue_media();
    }
} );

// ============================================================
// 3. HELPERS DE EVENTO / ASIGNACIÓN
// ============================================================

if ( ! function_exists( 'eventosapp_expositor_get_event_cliente_id' ) ) {
    function eventosapp_expositor_get_event_cliente_id( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) return 0;

        $usar_cliente = get_post_meta( $event_id, '_eventosapp_usar_cliente', true );
        $cliente_id   = absint( get_post_meta( $event_id, '_eventosapp_cliente_id', true ) );

        if ( $usar_cliente !== '1' || ! $cliente_id || get_post_type( $cliente_id ) !== 'eventosapp_cliente' ) {
            return 0;
        }
        return $cliente_id;
    }
}

if ( ! function_exists( 'eventosapp_expositores_get_by_cliente' ) ) {
    function eventosapp_expositores_get_by_cliente( $cliente_id ) {
        $cliente_id = absint( $cliente_id );
        if ( ! $cliente_id ) return [];

        return get_posts( [
            'post_type'      => 'eventosapp_expositor',
            'post_status'    => [ 'publish', 'draft', 'private' ],
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_expositor_cliente_id',
                    'value'   => $cliente_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ] );
    }
}

if ( ! function_exists( 'eventosapp_event_expositores_enabled' ) ) {
    function eventosapp_event_expositores_enabled( $event_id ) {
        return get_post_meta( absint( $event_id ), '_eventosapp_expositores_enabled', true ) === '1';
    }
}

if ( ! function_exists( 'eventosapp_event_get_expositores' ) ) {
    function eventosapp_event_get_expositores( $event_id ) {
        $ids = get_post_meta( absint( $event_id ), '_eventosapp_event_expositores', true );
        if ( ! is_array( $ids ) ) $ids = [];
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
        return $ids;
    }
}

if ( ! function_exists( 'eventosapp_event_get_expositor_user_map' ) ) {
    function eventosapp_event_get_expositor_user_map( $event_id ) {
        $map = get_post_meta( absint( $event_id ), '_eventosapp_expositor_user_map', true );
        if ( ! is_array( $map ) ) $map = [];

        $out = [];
        foreach ( $map as $expositor_id => $users ) {
            $eid = absint( $expositor_id );
            if ( ! $eid ) continue;
            $out[ $eid ] = is_array( $users )
                ? array_values( array_unique( array_filter( array_map( 'absint', $users ) ) ) )
                : [];
        }
        return $out;
    }
}

if ( ! function_exists( 'eventosapp_expositor_user_get_expositor_ids_for_event' ) ) {
    function eventosapp_expositor_user_get_expositor_ids_for_event( $event_id, $user_id = 0 ) {
        $event_id = absint( $event_id );
        $user_id  = $user_id ? absint( $user_id ) : get_current_user_id();
        if ( ! $event_id || ! $user_id || ! eventosapp_event_expositores_enabled( $event_id ) ) return [];

        $assigned_expositores = eventosapp_event_get_expositores( $event_id );
        $map                  = eventosapp_event_get_expositor_user_map( $event_id );
        $out                  = [];

        foreach ( $assigned_expositores as $expositor_id ) {
            $users = isset( $map[ $expositor_id ] ) ? (array) $map[ $expositor_id ] : [];
            if ( in_array( $user_id, array_map( 'absint', $users ), true ) ) {
                $out[] = $expositor_id;
            }
        }
        return array_values( array_unique( $out ) );
    }
}

if ( ! function_exists( 'eventosapp_expositor_user_has_assignment_in_event' ) ) {
    function eventosapp_expositor_user_has_assignment_in_event( $event_id, $user_id = 0 ) {
        return ! empty( eventosapp_expositor_user_get_expositor_ids_for_event( $event_id, $user_id ) );
    }
}

if ( ! function_exists( 'eventosapp_expositor_user_can_select_event_in_dashboard' ) ) {
    function eventosapp_expositor_user_can_select_event_in_dashboard( $event_id, $user_id = 0 ) {
        return eventosapp_expositor_user_has_assignment_in_event( $event_id, $user_id );
    }
}

if ( ! function_exists( 'eventosapp_expositor_manager_can_access_event' ) ) {
    function eventosapp_expositor_manager_can_access_event( $event_id, $user_id = 0 ) {
        $event_id = absint( $event_id );
        $user_id  = $user_id ? absint( $user_id ) : get_current_user_id();
        if ( ! $event_id || ! $user_id ) return false;
        if ( user_can( $user_id, 'manage_options' ) ) return true;
        if ( function_exists( 'eventosapp_user_can_manage_event' ) && eventosapp_user_can_manage_event( $event_id, $user_id ) ) return true;
        if ( function_exists( 'eventosapp_role_can' ) && eventosapp_role_can( 'expositor_gestion', $user_id ) ) return true;
        return false;
    }
}

if ( ! function_exists( 'eventosapp_expositor_current_event_id' ) ) {
    function eventosapp_expositor_current_event_id() {
        $event_id = function_exists( 'eventosapp_get_active_event' ) ? absint( eventosapp_get_active_event() ) : 0;
        if ( ! $event_id && isset( $_GET['event_id'] ) ) {
            $event_id = absint( wp_unslash( $_GET['event_id'] ) );
        }
        return $event_id && get_post_type( $event_id ) === 'eventosapp_event' ? $event_id : 0;
    }
}

if ( ! function_exists( 'eventosapp_event_expositor_metabox_user_label' ) ) {
    function eventosapp_event_expositor_metabox_user_label( $user ) {
        if ( ! $user instanceof WP_User ) return '';
        $name  = trim( (string) $user->display_name );
        $login = trim( (string) $user->user_login );
        $email = trim( (string) $user->user_email );

        $label = $name !== '' ? $name : $login;
        if ( $login !== '' && $login !== $label ) {
            $label .= ' - ' . $login;
        }
        if ( $email !== '' ) {
            $label .= ' (' . $email . ')';
        }
        return $label;
    }
}

if ( ! function_exists( 'eventosapp_event_expositor_metabox_render_user_row' ) ) {
    function eventosapp_event_expositor_metabox_render_user_row( $expositor_id, $user_id, $label ) {
        $expositor_id = absint( $expositor_id );
        $user_id      = absint( $user_id );
        $label        = sanitize_text_field( $label );

        if ( ! $expositor_id || ! $user_id || $label === '' ) return;

        echo '<li class="evapp-expo-user-item" data-user-row="' . esc_attr( $user_id ) . '">';
        echo '<span class="evapp-expo-user-name">' . esc_html( $label ) . '</span>';
        echo '<input type="hidden" data-expositor-user-input="1" name="_eventosapp_expositor_users[' . esc_attr( $expositor_id ) . '][]" value="' . esc_attr( $user_id ) . '">';
        echo '<button type="button" class="button-link-delete evapp-remove-expositor-user">Quitar</button>';
        echo '</li>';
    }
}

if ( ! function_exists( 'eventosapp_event_expositor_metabox_render_card' ) ) {
    function eventosapp_event_expositor_metabox_render_card( $expositor_id, $nombre, $users, $selected_user_ids = [] ) {
        $expositor_id      = absint( $expositor_id );
        $nombre            = sanitize_text_field( $nombre );
        $selected_user_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $selected_user_ids ) ) ) );

        if ( ! $expositor_id || $nombre === '' ) return;

        echo '<div class="evapp-expo-card" data-expositor-card="' . esc_attr( $expositor_id ) . '">';
        echo '<div class="evapp-expo-card-head">';
        echo '<div>';
        echo '<h4><span class="dashicons dashicons-store"></span> ' . esc_html( $nombre ) . '</h4>';
        echo '<p>Configura aquí únicamente los usuarios que podrán operar este expositor dentro de este evento.</p>';
        echo '</div>';
        echo '<button type="button" class="button-link-delete evapp-remove-expositor-card">Quitar expositor</button>';
        echo '</div>';

        echo '<input type="hidden" class="evapp-expo-card-input" name="_eventosapp_event_expositores[]" value="' . esc_attr( $expositor_id ) . '">';

        echo '<div class="evapp-expo-user-add-row">';
        echo '<select class="evapp-expo-user-picker" data-expositor-user-picker="' . esc_attr( $expositor_id ) . '">';
        echo '<option value="">— Selecciona usuario —</option>';
        foreach ( (array) $users as $user ) {
            if ( ! $user instanceof WP_User ) continue;
            $label = eventosapp_event_expositor_metabox_user_label( $user );
            echo '<option value="' . esc_attr( $user->ID ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<button type="button" class="button evapp-add-user-to-expositor">Agregar usuario</button>';
        echo '</div>';

        echo '<div class="evapp-expo-added-users">';
        echo '<strong>Usuarios agregados a este expositor</strong>';
        echo '<ul class="evapp-expo-user-list">';

        $has_users = false;
        foreach ( $selected_user_ids as $uid ) {
            $user = get_user_by( 'id', $uid );
            if ( ! $user ) continue;
            $label     = eventosapp_event_expositor_metabox_user_label( $user );
            $has_users = true;
            eventosapp_event_expositor_metabox_render_user_row( $expositor_id, $uid, $label );
        }

        echo '<li class="evapp-expo-empty-users" style="display:' . ( $has_users ? 'none' : 'block' ) . ';">Todavía no hay usuarios agregados a este expositor.</li>';
        echo '</ul>';
        echo '</div>';

        echo '<p class="evapp-expo-muted">Al guardar, estos usuarios reciben el rol Expositor si todavía no lo tienen. No se eliminan otros roles ni otros permisos existentes.</p>';
        echo '</div>';
    }
}

if ( ! function_exists( 'eventosapp_save_event_expositores_config' ) ) {
    function eventosapp_save_event_expositores_config( $event_id, $enabled, $selected_expositores = [], $raw_user_map = [] ) {
        $event_id = absint( $event_id );
        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return new WP_Error( 'invalid_event', 'Evento inválido.' );
        }

        $cliente_id = eventosapp_expositor_get_event_cliente_id( $event_id );
        if ( ! $cliente_id ) {
            update_post_meta( $event_id, '_eventosapp_expositores_enabled', '0' );
            update_post_meta( $event_id, '_eventosapp_event_expositores', [] );
            update_post_meta( $event_id, '_eventosapp_expositor_user_map', [] );
            return new WP_Error( 'invalid_client', 'Este evento no tiene un cliente/organizador válido. No se pueden asociar expositores.' );
        }

        $allowed_expositores = array_values( array_unique( array_filter( array_map( 'absint', eventosapp_expositores_get_by_cliente( $cliente_id ) ) ) ) );
        $selected            = array_values( array_unique( array_filter( array_map( 'absint', (array) $selected_expositores ) ) ) );
        $selected            = array_values( array_intersect( $selected, $allowed_expositores ) );

        $raw_user_map = is_array( $raw_user_map ) ? $raw_user_map : [];
        $user_map     = [];

        foreach ( $selected as $expositor_id ) {
            $users_for_expositor = isset( $raw_user_map[ $expositor_id ] ) && is_array( $raw_user_map[ $expositor_id ] ) ? $raw_user_map[ $expositor_id ] : [];
            $users_for_expositor = array_values( array_unique( array_filter( array_map( 'absint', (array) $users_for_expositor ) ) ) );
            $valid_users         = [];

            foreach ( $users_for_expositor as $uid ) {
                $user = get_user_by( 'id', $uid );
                if ( ! $user ) continue;

                $valid_users[] = $uid;
                if ( ! in_array( 'expositor', (array) $user->roles, true ) ) {
                    $user->add_role( 'expositor' );
                }
            }

            $user_map[ $expositor_id ] = array_values( array_unique( $valid_users ) );
        }

        update_post_meta( $event_id, '_eventosapp_expositores_enabled', (string) $enabled === '1' ? '1' : '0' );
        update_post_meta( $event_id, '_eventosapp_event_expositores', $selected );
        update_post_meta( $event_id, '_eventosapp_expositor_user_map', $user_map );

        return true;
    }
}

function eventosapp_event_expositores_metabox( $post ) {
    $event_id   = absint( $post->ID );
    $cliente_id = eventosapp_expositor_get_event_cliente_id( $event_id );
    $enabled    = eventosapp_event_expositores_enabled( $event_id );
    $assigned   = eventosapp_event_get_expositores( $event_id );
    $user_map   = eventosapp_event_get_expositor_user_map( $event_id );
    $nonce      = wp_create_nonce( 'eventosapp_save_event_expositores_' . $event_id );

    wp_nonce_field( 'eventosapp_save_event_expositores_normal_' . $event_id, '_eventosapp_expositores_nonce' );

    echo '<style>
        .evapp-expo-box{border:1px solid #dcdcde;border-radius:10px;padding:16px;background:#fff;}
        .evapp-expo-box *{box-sizing:border-box;}
        .evapp-expo-muted{color:#646970;font-size:13px;line-height:1.5;}
        .evapp-expo-warning{border-left:4px solid #d63638;background:#fcf0f1;padding:10px 12px;margin:10px 0;border-radius:4px;}
        .evapp-expo-info{border-left:4px solid #2271b1;background:#f0f6fc;padding:12px 14px;margin:14px 0;border-radius:6px;color:#1d2327;}
        .evapp-expo-success{border-left:4px solid #00a32a;background:#edfaef;padding:10px 12px;margin:10px 0;border-radius:4px;display:none;}
        .evapp-expo-toolbar{display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:10px;align-items:end;margin:16px 0 14px;padding:14px;border:1px solid #dcdcde;border-radius:10px;background:#f6f7f7;}
        .evapp-expo-toolbar label{display:block;font-weight:700;margin-bottom:6px;}
        .evapp-expo-toolbar select{width:100%;max-width:100%;min-height:36px;}
        .evapp-expo-cards{display:grid;grid-template-columns:1fr;gap:14px;margin-top:12px;}
        .evapp-expo-card{border:1px solid #cfd6dd;border-radius:12px;padding:14px;background:#fbfbfc;box-shadow:0 1px 2px rgba(0,0,0,.04);}
        .evapp-expo-card-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;border-bottom:1px solid #e2e6ea;padding-bottom:10px;margin-bottom:12px;}
        .evapp-expo-card h4{display:flex;align-items:center;gap:6px;margin:0 0 4px;font-size:15px;}
        .evapp-expo-card h4 .dashicons{color:#2271b1;}
        .evapp-expo-card-head p{margin:0;color:#646970;font-size:13px;line-height:1.45;}
        .evapp-expo-user-add-row{display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:10px;align-items:center;margin:10px 0 12px;}
        .evapp-expo-user-picker{width:100%;max-width:100%;min-height:34px;}
        .evapp-expo-added-users{border:1px solid #e0e4e8;border-radius:8px;background:#fff;padding:10px;}
        .evapp-expo-added-users strong{display:block;margin-bottom:8px;}
        .evapp-expo-user-list{margin:0;padding:0;list-style:none;}
        .evapp-expo-user-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;padding:8px 10px;margin:0 0 6px;border:1px solid #eef0f2;border-radius:7px;background:#f9fafb;}
        .evapp-expo-user-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .evapp-expo-empty-users{color:#646970;font-style:italic;padding:8px 0;margin:0;}
        .evapp-expo-empty-cards{border:1px dashed #b8c2cc;border-radius:10px;padding:18px;text-align:center;color:#646970;background:#fff;}
        .evapp-expo-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px;}
        @media(max-width:782px){.evapp-expo-toolbar,.evapp-expo-user-add-row{grid-template-columns:1fr;}.evapp-expo-card-head{display:block;}.evapp-remove-expositor-card{margin-top:8px;}}
    </style>';

    echo '<div class="evapp-expo-box" id="evapp-event-expositores-box" data-event-id="' . esc_attr( $event_id ) . '" data-nonce="' . esc_attr( $nonce ) . '">';

    if ( ! $cliente_id ) {
        echo '<div class="evapp-expo-warning"><strong>No se pueden agregar expositores todavía.</strong><br>Este evento debe tener activada la opción de usar un Cliente/Organizador del CPT Clientes y debe tener un cliente seleccionado.</div>';
        echo '<p class="evapp-expo-muted">Guarda primero el organizador del evento desde el metabox principal y luego vuelve a esta sección.</p>';
        echo '</div>';
        return;
    }

    $cliente_nombre = get_post_meta( $cliente_id, '_cliente_nombre_empresa', true ) ?: get_the_title( $cliente_id );
    $expositores    = eventosapp_expositores_get_by_cliente( $cliente_id );
    $expositores    = array_values( array_unique( array_filter( array_map( 'absint', $expositores ) ) ) );
    $assigned       = array_values( array_intersect( array_values( array_unique( array_filter( array_map( 'absint', $assigned ) ) ) ), $expositores ) );
    $users          = get_users( [ 'orderby' => 'display_name', 'order' => 'ASC', 'number' => 500 ] );

    $expositor_data = [];
    foreach ( $expositores as $expositor_id ) {
        $nombre = get_post_meta( $expositor_id, '_expositor_nombre_empresa', true ) ?: get_the_title( $expositor_id );
        $expositor_data[] = [
            'id'   => $expositor_id,
            'name' => $nombre,
        ];
    }

    $users_data = [];
    foreach ( $users as $user ) {
        if ( ! $user instanceof WP_User ) continue;
        $users_data[] = [
            'id'    => absint( $user->ID ),
            'label' => eventosapp_event_expositor_metabox_user_label( $user ),
        ];
    }

    echo '<p><strong>Cliente/Organizador asociado:</strong> ' . esc_html( $cliente_nombre ) . '</p>';
    echo '<label style="display:block;margin:8px 0 12px;"><input type="checkbox" id="evapp_expositores_enabled" name="_eventosapp_expositores_enabled" value="1" ' . checked( $enabled, true, false ) . '> Activar módulo de expositores para este evento</label>';

    if ( empty( $expositores ) ) {
        echo '<div class="evapp-expo-warning"><strong>No hay expositores creados para este cliente.</strong><br>Crea un expositor y asígnalo al cliente <strong>' . esc_html( $cliente_nombre ) . '</strong> para poder seleccionarlo aquí.</div>';
        echo '<p><a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=eventosapp_expositor' ) ) . '" target="_blank">+ Crear expositor</a></p>';
        echo '<div class="evapp-expo-actions"><button type="button" class="button button-primary" id="evapp_save_event_expositores">Guardar configuración de expositores</button> <span id="evapp-expo-save-msg" class="evapp-expo-success"></span></div>';
        echo '</div>';
        return;
    }

    echo '<div class="evapp-expo-info"><strong>Flujo de configuración:</strong> selecciona un expositor disponible y presiona <strong>Agregar</strong>. Cada expositor agregado tendrá su propia caja para agregar usuarios con botón y listado independiente.</div>';

    echo '<div class="evapp-expo-toolbar">';
    echo '<div>';
    echo '<label for="evapp_available_expositor_select">Expositores disponibles</label>';
    echo '<select id="evapp_available_expositor_select">';
    echo '<option value="">— Selecciona un expositor —</option>';
    foreach ( $expositor_data as $expositor ) {
        echo '<option value="' . esc_attr( $expositor['id'] ) . '">' . esc_html( $expositor['name'] ) . '</option>';
    }
    echo '</select>';
    echo '<p class="evapp-expo-muted" style="margin:6px 0 0;">Sólo aparecen expositores asociados al cliente/organizador de este evento.</p>';
    echo '</div>';
    echo '<button type="button" class="button button-secondary" id="evapp_add_expositor_card">Agregar</button>';
    echo '</div>';

    echo '<div class="evapp-expo-cards" id="evapp-expo-cards-list">';
    echo '<div class="evapp-expo-empty-cards" id="evapp-expo-empty-cards" style="display:' . ( empty( $assigned ) ? 'block' : 'none' ) . ';">Todavía no has agregado expositores a este evento.</div>';

    foreach ( $assigned as $expositor_id ) {
        $nombre = get_post_meta( $expositor_id, '_expositor_nombre_empresa', true ) ?: get_the_title( $expositor_id );
        $u_ids  = isset( $user_map[ $expositor_id ] ) ? (array) $user_map[ $expositor_id ] : [];
        eventosapp_event_expositor_metabox_render_card( $expositor_id, $nombre, $users, $u_ids );
    }
    echo '</div>';

    echo '<div class="evapp-expo-actions"><button type="button" class="button button-primary" id="evapp_save_event_expositores">Guardar configuración de expositores</button> <span id="evapp-expo-save-msg" class="evapp-expo-success"></span></div>';
    echo '<p class="evapp-expo-muted">También puedes usar el botón principal <strong>Actualizar</strong> del evento. El botón azul guarda por AJAX sin salir de esta pantalla.</p>';
    echo '</div>';
    ?>
    <script>
    jQuery(function($){
        var evappExpoData = {
            expositores: <?php echo wp_json_encode( $expositor_data ); ?>,
            users: <?php echo wp_json_encode( $users_data ); ?>
        };

        function escapeHtml(value){
            return String(value || '').replace(/[&<>"]|'/g, function(match){
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[match];
            });
        }

        function getExpositorName(expositorId){
            expositorId = String(expositorId);
            for(var i = 0; i < evappExpoData.expositores.length; i++){
                if(String(evappExpoData.expositores[i].id) === expositorId){
                    return evappExpoData.expositores[i].name;
                }
            }
            return '';
        }

        function userOptionsHtml(){
            var html = '<option value="">— Selecciona usuario —</option>';
            for(var i = 0; i < evappExpoData.users.length; i++){
                html += '<option value="' + escapeHtml(evappExpoData.users[i].id) + '">' + escapeHtml(evappExpoData.users[i].label) + '</option>';
            }
            return html;
        }

        function buildUserRow(expositorId, userId, label){
            return '' +
                '<li class="evapp-expo-user-item" data-user-row="' + escapeHtml(userId) + '">' +
                    '<span class="evapp-expo-user-name">' + escapeHtml(label) + '</span>' +
                    '<input type="hidden" data-expositor-user-input="1" name="_eventosapp_expositor_users[' + escapeHtml(expositorId) + '][]" value="' + escapeHtml(userId) + '">' +
                    '<button type="button" class="button-link-delete evapp-remove-expositor-user">Quitar</button>' +
                '</li>';
        }

        function buildCard(expositorId, expositorName){
            return '' +
                '<div class="evapp-expo-card" data-expositor-card="' + escapeHtml(expositorId) + '">' +
                    '<div class="evapp-expo-card-head">' +
                        '<div>' +
                            '<h4><span class="dashicons dashicons-store"></span> ' + escapeHtml(expositorName) + '</h4>' +
                            '<p>Configura aquí únicamente los usuarios que podrán operar este expositor dentro de este evento.</p>' +
                        '</div>' +
                        '<button type="button" class="button-link-delete evapp-remove-expositor-card">Quitar expositor</button>' +
                    '</div>' +
                    '<input type="hidden" class="evapp-expo-card-input" name="_eventosapp_event_expositores[]" value="' + escapeHtml(expositorId) + '">' +
                    '<div class="evapp-expo-user-add-row">' +
                        '<select class="evapp-expo-user-picker" data-expositor-user-picker="' + escapeHtml(expositorId) + '">' + userOptionsHtml() + '</select>' +
                        '<button type="button" class="button evapp-add-user-to-expositor">Agregar usuario</button>' +
                    '</div>' +
                    '<div class="evapp-expo-added-users">' +
                        '<strong>Usuarios agregados a este expositor</strong>' +
                        '<ul class="evapp-expo-user-list">' +
                            '<li class="evapp-expo-empty-users">Todavía no hay usuarios agregados a este expositor.</li>' +
                        '</ul>' +
                    '</div>' +
                    '<p class="evapp-expo-muted">Al guardar, estos usuarios reciben el rol Expositor si todavía no lo tienen. No se eliminan otros roles ni otros permisos existentes.</p>' +
                '</div>';
        }

        function cardExists(expositorId){
            return $('[data-expositor-card="' + String(expositorId).replace(/"/g, '\\"') + '"]').length > 0;
        }

        function refreshEmptyStates(){
            $('#evapp-expo-empty-cards').toggle($('#evapp-expo-cards-list [data-expositor-card]').length === 0);
            $('#evapp-expo-cards-list [data-expositor-card]').each(function(){
                var $list = $(this).find('.evapp-expo-user-list');
                $list.find('.evapp-expo-empty-users').toggle($list.find('[data-user-row]').length === 0);
            });
        }

        function refreshAvailableSelect(){
            $('#evapp_available_expositor_select option').each(function(){
                var value = $(this).attr('value');
                if(!value) return;
                $(this).prop('disabled', cardExists(value));
            });
        }

        $('#evapp_add_expositor_card').on('click', function(e){
            e.preventDefault();
            var expositorId = $('#evapp_available_expositor_select').val();
            if(!expositorId){
                alert('Selecciona un expositor para agregarlo.');
                return;
            }
            if(cardExists(expositorId)){
                alert('Este expositor ya está agregado al evento.');
                return;
            }
            var expositorName = getExpositorName(expositorId);
            if(!expositorName){
                alert('No se pudo identificar el expositor seleccionado.');
                return;
            }
            $('#evapp-expo-cards-list').append(buildCard(expositorId, expositorName));
            $('#evapp_available_expositor_select').val('');
            refreshAvailableSelect();
            refreshEmptyStates();
        });

        $('#evapp-expo-cards-list').on('click', '.evapp-remove-expositor-card', function(e){
            e.preventDefault();
            if(!confirm('¿Quieres quitar este expositor del evento? También se quitarán los usuarios asignados a este expositor en este evento.')){
                return;
            }
            $(this).closest('[data-expositor-card]').remove();
            refreshAvailableSelect();
            refreshEmptyStates();
        });

        $('#evapp-expo-cards-list').on('click', '.evapp-add-user-to-expositor', function(e){
            e.preventDefault();
            var $card = $(this).closest('[data-expositor-card]');
            var expositorId = String($card.data('expositor-card'));
            var $picker = $card.find('.evapp-expo-user-picker');
            var userId = $picker.val();
            var label = $picker.find('option:selected').text();
            var $list = $card.find('.evapp-expo-user-list');

            if(!userId){
                alert('Selecciona un usuario para agregarlo a este expositor.');
                return;
            }
            if($list.find('[data-user-row="' + String(userId).replace(/"/g, '\\"') + '"]').length){
                alert('Este usuario ya está agregado a este expositor.');
                return;
            }
            $list.append(buildUserRow(expositorId, userId, label));
            $picker.val('');
            refreshEmptyStates();
        });

        $('#evapp-expo-cards-list').on('click', '.evapp-remove-expositor-user', function(e){
            e.preventDefault();
            $(this).closest('[data-user-row]').remove();
            refreshEmptyStates();
        });

        $('#evapp_save_event_expositores').on('click', function(e){
            e.preventDefault();
            var $box = $('#evapp-event-expositores-box');
            var $btn = $(this);
            var $msg = $('#evapp-expo-save-msg');
            var selected = [];
            var users = {};

            $('#evapp-expo-cards-list [data-expositor-card]').each(function(){
                var eid = String($(this).data('expositor-card'));
                selected.push(eid);
                users[eid] = [];
                $(this).find('[data-expositor-user-input]').each(function(){
                    users[eid].push($(this).val());
                });
            });

            $btn.prop('disabled', true).text('Guardando...');
            $msg.hide().removeClass('notice-error').text('');

            $.post(ajaxurl, {
                action: 'eventosapp_save_event_expositores',
                event_id: $box.data('event-id'),
                nonce: $box.data('nonce'),
                enabled: $('#evapp_expositores_enabled').is(':checked') ? '1' : '0',
                expositores: selected,
                users: users
            }).done(function(resp){
                if(resp && resp.success){
                    $msg.text(resp.data && resp.data.message ? resp.data.message : 'Configuración guardada.').show();
                }else{
                    alert(resp && resp.data && resp.data.message ? resp.data.message : 'No se pudo guardar la configuración.');
                }
            }).fail(function(xhr){
                var message = 'Error de conexión al guardar.';
                if(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
                    message = xhr.responseJSON.data.message;
                }
                alert(message);
            }).always(function(){
                $btn.prop('disabled', false).text('Guardar configuración de expositores');
            });
        });

        refreshAvailableSelect();
        refreshEmptyStates();
    });
    </script>
    <?php
}

add_action( 'save_post_eventosapp_event', function ( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( ! isset( $_POST['_eventosapp_expositores_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_eventosapp_expositores_nonce'] ) ), 'eventosapp_save_event_expositores_normal_' . $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $enabled  = isset( $_POST['_eventosapp_expositores_enabled'] ) ? '1' : '0';
    $selected = isset( $_POST['_eventosapp_event_expositores'] ) && is_array( $_POST['_eventosapp_event_expositores'] )
        ? array_map( 'absint', wp_unslash( $_POST['_eventosapp_event_expositores'] ) )
        : [];
    $users    = isset( $_POST['_eventosapp_expositor_users'] ) && is_array( $_POST['_eventosapp_expositor_users'] )
        ? wp_unslash( $_POST['_eventosapp_expositor_users'] )
        : [];

    eventosapp_save_event_expositores_config( $post_id, $enabled, $selected, $users );
}, 20 );

add_action( 'wp_ajax_eventosapp_save_event_expositores', function () {
    $event_id = absint( $_POST['event_id'] ?? 0 );
    if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
        wp_send_json_error( [ 'message' => 'Evento inválido.' ], 400 );
    }

    check_ajax_referer( 'eventosapp_save_event_expositores_' . $event_id, 'nonce' );

    if ( ! current_user_can( 'edit_post', $event_id ) ) {
        wp_send_json_error( [ 'message' => 'No tienes permisos para editar este evento.' ], 403 );
    }

    $enabled  = isset( $_POST['enabled'] ) && (string) $_POST['enabled'] === '1' ? '1' : '0';
    $selected = isset( $_POST['expositores'] ) && is_array( $_POST['expositores'] )
        ? array_map( 'absint', wp_unslash( $_POST['expositores'] ) )
        : [];
    $users    = isset( $_POST['users'] ) && is_array( $_POST['users'] )
        ? wp_unslash( $_POST['users'] )
        : [];

    $result = eventosapp_save_event_expositores_config( $event_id, $enabled, $selected, $users );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
    }

    wp_send_json_success( [ 'message' => 'Configuración de expositores guardada correctamente.' ] );
} );

// ============================================================
// 4. PRODUCTOS / ENTREGAS
// ============================================================

if ( ! function_exists( 'eventosapp_expositor_get_products_map' ) ) {
    function eventosapp_expositor_get_products_map( $event_id ) {
        $map = get_post_meta( absint( $event_id ), '_eventosapp_expositor_products', true );
        return is_array( $map ) ? $map : [];
    }
}

if ( ! function_exists( 'eventosapp_expositor_get_products' ) ) {
    function eventosapp_expositor_get_products( $event_id, $expositor_id ) {
        $map          = eventosapp_expositor_get_products_map( $event_id );
        $expositor_id = absint( $expositor_id );
        $products     = isset( $map[ $expositor_id ] ) && is_array( $map[ $expositor_id ] ) ? $map[ $expositor_id ] : [];

        $out = [];
        foreach ( $products as $product ) {
            if ( ! is_array( $product ) || empty( $product['id'] ) ) continue;
            $id         = sanitize_key( $product['id'] );
            $out[ $id ] = [
                'id'                 => $id,
                'name'               => sanitize_text_field( $product['name'] ?? '' ),
                'inventory'          => isset( $product['inventory'] ) && $product['inventory'] !== '' ? max( 0, absint( $product['inventory'] ) ) : '',
                'limit_per_attendee' => isset( $product['limit_per_attendee'] ) && $product['limit_per_attendee'] !== '' ? max( 0, absint( $product['limit_per_attendee'] ) ) : 1,
                'active'             => empty( $product['active'] ) ? '0' : '1',
                'created_at'         => sanitize_text_field( $product['created_at'] ?? current_time( 'mysql' ) ),
            ];
        }
        return $out;
    }
}

if ( ! function_exists( 'eventosapp_expositor_save_products' ) ) {
    function eventosapp_expositor_save_products( $event_id, $expositor_id, array $products ) {
        $event_id     = absint( $event_id );
        $expositor_id = absint( $expositor_id );
        $map          = eventosapp_expositor_get_products_map( $event_id );
        $map[ $expositor_id ] = $products;
        update_post_meta( $event_id, '_eventosapp_expositor_products', $map );
    }
}

if ( ! function_exists( 'eventosapp_expositor_get_product' ) ) {
    function eventosapp_expositor_get_product( $event_id, $expositor_id, $product_id ) {
        $products   = eventosapp_expositor_get_products( $event_id, $expositor_id );
        $product_id = sanitize_key( $product_id );
        return isset( $products[ $product_id ] ) ? $products[ $product_id ] : false;
    }
}

if ( ! function_exists( 'eventosapp_expositor_user_can_manage_expositor' ) ) {
    function eventosapp_expositor_user_can_manage_expositor( $event_id, $expositor_id, $user_id = 0 ) {
        $event_id     = absint( $event_id );
        $expositor_id = absint( $expositor_id );
        $user_id      = $user_id ? absint( $user_id ) : get_current_user_id();
        if ( ! $event_id || ! $expositor_id || ! $user_id ) return false;
        if ( eventosapp_expositor_manager_can_access_event( $event_id, $user_id ) ) return true;
        return in_array( $expositor_id, eventosapp_expositor_user_get_expositor_ids_for_event( $event_id, $user_id ), true );
    }
}

if ( ! function_exists( 'eventosapp_expositor_get_download_permissions' ) ) {
    function eventosapp_expositor_get_download_permissions( $event_id ) {
        $permissions = get_post_meta( absint( $event_id ), '_eventosapp_expositor_download_permissions', true );
        return is_array( $permissions ) ? $permissions : [];
    }
}

if ( ! function_exists( 'eventosapp_expositor_download_is_allowed' ) ) {
    function eventosapp_expositor_download_is_allowed( $event_id, $expositor_id, $user_id = 0 ) {
        if ( eventosapp_expositor_manager_can_access_event( $event_id, $user_id ) ) return true;
        $permissions = eventosapp_expositor_get_download_permissions( $event_id );
        return ! empty( $permissions[ absint( $expositor_id ) ] );
    }
}

if ( ! function_exists( 'eventosapp_expositor_count_deliveries' ) ) {
    function eventosapp_expositor_count_deliveries( $event_id, $expositor_id = 0, $product_id = '', $ticket_id = 0 ) {
        global $wpdb;
        $table = eventosapp_expositores_table_name();
        $where = [ 'event_id = %d' ];
        $args  = [ absint( $event_id ) ];

        if ( $expositor_id ) {
            $where[] = 'expositor_id = %d';
            $args[]  = absint( $expositor_id );
        }
        if ( $product_id !== '' ) {
            $where[] = 'product_id = %s';
            $args[]  = sanitize_key( $product_id );
        }
        if ( $ticket_id ) {
            $where[] = 'ticket_id = %d';
            $args[]  = absint( $ticket_id );
        }

        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where ), $args ) );
    }
}

if ( ! function_exists( 'eventosapp_expositor_get_attendee_data' ) ) {
    function eventosapp_expositor_get_attendee_data( $ticket_id ) {
        $ticket_id = absint( $ticket_id );
        if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) return false;

        $nombre   = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true );
        $apellido = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );
        $cedula   = get_post_meta( $ticket_id, '_eventosapp_asistente_cc', true );
        if ( ! $cedula ) $cedula = get_post_meta( $ticket_id, '_eventosapp_asistente_documento', true );
        if ( ! $cedula ) $cedula = get_post_meta( $ticket_id, 'eventosapp_cedula', true );

        return [
            'ticket_id'        => $ticket_id,
            'public_ticket_id' => get_post_meta( $ticket_id, 'eventosapp_ticketID', true ),
            'event_id'         => absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ),
            'name'             => sanitize_text_field( $nombre ),
            'lastname'         => sanitize_text_field( $apellido ),
            'full_name'        => trim( sanitize_text_field( $nombre . ' ' . $apellido ) ),
            'email'            => sanitize_email( get_post_meta( $ticket_id, '_eventosapp_asistente_email', true ) ),
            'phone'            => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_tel', true ) ),
            'company'          => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_empresa', true ) ),
            'document'         => sanitize_text_field( $cedula ),
            'location'         => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_localidad', true ) ),
        ];
    }
}

if ( ! function_exists( 'eventosapp_expositor_find_ticket_by_public_code' ) ) {
    function eventosapp_expositor_find_ticket_by_public_code( $code ) {
        global $wpdb;
        $code = trim( sanitize_text_field( (string) $code ) );
        if ( $code === '' ) return 0;

        $ticket_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key IN ('eventosapp_ticketID','eventosapp_ticket_preprintedID')
             AND meta_value = %s
             LIMIT 1",
            $code
        ) );

        return ( $ticket_id && get_post_type( $ticket_id ) === 'eventosapp_ticket' ) ? absint( $ticket_id ) : 0;
    }
}

if ( ! function_exists( 'eventosapp_expositor_resolve_ticket_from_qr' ) ) {
    function eventosapp_expositor_resolve_ticket_from_qr( $qr_content ) {
        $qr_content = trim( sanitize_text_field( (string) wp_unslash( $qr_content ) ) );
        if ( $qr_content === '' ) return 0;

        if ( class_exists( 'EventosApp_QR_Manager' ) && method_exists( 'EventosApp_QR_Manager', 'validate_qr' ) ) {
            $validation = EventosApp_QR_Manager::validate_qr( $qr_content );
            if ( is_array( $validation ) && ! empty( $validation['valid'] ) && ! empty( $validation['ticket_id'] ) ) {
                return absint( $validation['ticket_id'] );
            }
        }

        $ticket_id = eventosapp_expositor_find_ticket_by_public_code( $qr_content );
        if ( $ticket_id ) return $ticket_id;

        if ( preg_match( '/ticketid=([^\s&#]+)/i', $qr_content, $matches ) ) {
            $candidate = rawurldecode( $matches[1] );
            $candidate = preg_replace( '/-[A-Za-z0-9]+$/', '', $candidate );
            $ticket_id = eventosapp_expositor_find_ticket_by_public_code( $candidate );
            if ( $ticket_id ) return $ticket_id;
        }

        if ( preg_match( '/^(.+)-(?:email|gwallet|awallet|pdf|whatsapp|badge)$/i', $qr_content, $matches ) ) {
            $ticket_id = eventosapp_expositor_find_ticket_by_public_code( $matches[1] );
            if ( $ticket_id ) return $ticket_id;
        }

        return 0;
    }
}

add_action( 'wp_ajax_eventosapp_expositor_save_product', function () {
    $event_id     = absint( $_POST['event_id'] ?? 0 );
    $expositor_id = absint( $_POST['expositor_id'] ?? 0 );
    check_ajax_referer( 'eventosapp_expositor_front_' . $event_id, 'nonce' );

    if ( ! eventosapp_expositor_user_can_manage_expositor( $event_id, $expositor_id ) ) {
        wp_send_json_error( [ 'message' => 'No tienes permisos para administrar este expositor.' ], 403 );
    }

    $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
    if ( $name === '' ) {
        wp_send_json_error( [ 'message' => 'El nombre del producto es obligatorio.' ], 400 );
    }

    $product_id = sanitize_key( wp_unslash( $_POST['product_id'] ?? '' ) );
    if ( ! $product_id ) {
        $product_id = 'prod_' . strtolower( wp_generate_password( 12, false, false ) );
    }

    $inventory_raw = isset( $_POST['inventory'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['inventory'] ) ) ) : '';
    $limit_raw     = isset( $_POST['limit_per_attendee'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['limit_per_attendee'] ) ) ) : '1';

    $products = eventosapp_expositor_get_products( $event_id, $expositor_id );
    $products[ $product_id ] = [
        'id'                 => $product_id,
        'name'               => $name,
        'inventory'          => $inventory_raw === '' ? '' : max( 0, absint( $inventory_raw ) ),
        'limit_per_attendee' => $limit_raw === '' ? 1 : max( 0, absint( $limit_raw ) ),
        'active'             => isset( $_POST['active'] ) && (string) $_POST['active'] === '0' ? '0' : '1',
        'created_at'         => isset( $products[ $product_id ]['created_at'] ) ? $products[ $product_id ]['created_at'] : current_time( 'mysql' ),
    ];

    eventosapp_expositor_save_products( $event_id, $expositor_id, $products );
    wp_send_json_success( [ 'message' => 'Producto guardado correctamente.', 'products_html' => eventosapp_expositor_render_products_table( $event_id, $expositor_id, false ) ] );
} );

add_action( 'wp_ajax_eventosapp_expositor_validate_qr', function () {
    $event_id     = absint( $_POST['event_id'] ?? 0 );
    $expositor_id = absint( $_POST['expositor_id'] ?? 0 );
    $product_id   = sanitize_key( wp_unslash( $_POST['product_id'] ?? '' ) );
    $qr_content   = wp_unslash( $_POST['qr_content'] ?? '' );
    check_ajax_referer( 'eventosapp_expositor_front_' . $event_id, 'nonce' );

    if ( ! eventosapp_expositor_user_can_manage_expositor( $event_id, $expositor_id ) ) {
        wp_send_json_error( [ 'message' => 'No tienes permisos para usar este expositor.' ], 403 );
    }

    $product = eventosapp_expositor_get_product( $event_id, $expositor_id, $product_id );
    if ( ! $product || $product['active'] !== '1' ) {
        wp_send_json_error( [ 'message' => 'Selecciona un producto activo.' ], 400 );
    }

    $ticket_id = eventosapp_expositor_resolve_ticket_from_qr( $qr_content );
    if ( ! $ticket_id ) {
        wp_send_json_error( [ 'message' => 'QR inválido o ticket no encontrado.' ], 404 );
    }

    $attendee = eventosapp_expositor_get_attendee_data( $ticket_id );
    if ( ! $attendee || absint( $attendee['event_id'] ) !== $event_id ) {
        wp_send_json_error( [ 'message' => 'El ticket leído no pertenece al evento activo.' ], 400 );
    }

    $previous = eventosapp_expositor_count_deliveries( $event_id, $expositor_id, $product_id, $ticket_id );
    wp_send_json_success( [
        'attendee'        => $attendee,
        'previous_count'  => $previous,
        'product'         => $product,
        'duplicate'       => $previous > 0,
        'duplicate_label' => $previous > 0 ? 'Este asistente ya recibió este beneficio ' . $previous . ' vez/veces.' : '',
    ] );
} );

add_action( 'wp_ajax_eventosapp_expositor_register_delivery', function () {
    global $wpdb;

    $event_id     = absint( $_POST['event_id'] ?? 0 );
    $expositor_id = absint( $_POST['expositor_id'] ?? 0 );
    $product_id   = sanitize_key( wp_unslash( $_POST['product_id'] ?? '' ) );
    $ticket_id    = absint( $_POST['ticket_id'] ?? 0 );
    $force        = isset( $_POST['force'] ) && (string) $_POST['force'] === '1';
    check_ajax_referer( 'eventosapp_expositor_front_' . $event_id, 'nonce' );

    if ( ! eventosapp_expositor_user_can_manage_expositor( $event_id, $expositor_id ) ) {
        wp_send_json_error( [ 'message' => 'No tienes permisos para registrar entregas.' ], 403 );
    }

    $product = eventosapp_expositor_get_product( $event_id, $expositor_id, $product_id );
    if ( ! $product || $product['active'] !== '1' ) {
        wp_send_json_error( [ 'message' => 'Producto inválido o inactivo.' ], 400 );
    }

    $attendee = eventosapp_expositor_get_attendee_data( $ticket_id );
    if ( ! $attendee || absint( $attendee['event_id'] ) !== $event_id ) {
        wp_send_json_error( [ 'message' => 'El ticket no pertenece al evento activo.' ], 400 );
    }

    $product_total = eventosapp_expositor_count_deliveries( $event_id, $expositor_id, $product_id );
    if ( $product['inventory'] !== '' && $product_total >= absint( $product['inventory'] ) ) {
        wp_send_json_error( [ 'message' => 'Inventario agotado para este producto.' ], 400 );
    }

    $previous = eventosapp_expositor_count_deliveries( $event_id, $expositor_id, $product_id, $ticket_id );
    $limit    = isset( $product['limit_per_attendee'] ) ? absint( $product['limit_per_attendee'] ) : 1;

    if ( $previous > 0 && ! $force ) {
        wp_send_json_error( [
            'code'      => 'duplicate',
            'message'   => 'Este asistente ya recibió este beneficio ' . $previous . ' vez/veces. Confirma nuevamente si deseas registrar otra entrega.',
            'can_force' => ( $limit === 0 || $previous < $limit ),
        ], 409 );
    }

    if ( $limit > 0 && $previous >= $limit ) {
        wp_send_json_error( [ 'message' => 'Este asistente ya alcanzó el límite configurado para este producto.' ], 400 );
    }

    $user = wp_get_current_user();
    $now  = current_time( 'mysql' );
    $gmt  = current_time( 'mysql', 1 );

    $inserted = $wpdb->insert(
        eventosapp_expositores_table_name(),
        [
            'event_id'          => $event_id,
            'expositor_id'      => $expositor_id,
            'product_id'        => $product_id,
            'product_name'      => $product['name'],
            'ticket_id'         => $ticket_id,
            'public_ticket_id'  => $attendee['public_ticket_id'],
            'attendee_name'     => $attendee['name'],
            'attendee_lastname' => $attendee['lastname'],
            'attendee_email'    => $attendee['email'],
            'attendee_phone'    => $attendee['phone'],
            'attendee_company'  => $attendee['company'],
            'attendee_document' => $attendee['document'],
            'attendee_location' => $attendee['location'],
            'delivered_by'      => get_current_user_id(),
            'delivered_by_name' => $user && $user->exists() ? $user->display_name : 'Sistema',
            'created_at'        => $now,
            'created_at_gmt'    => $gmt,
        ],
        [ '%d','%d','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s' ]
    );

    if ( ! $inserted ) {
        wp_send_json_error( [ 'message' => 'No se pudo registrar la entrega.' ], 500 );
    }

    wp_send_json_success( [
        'message'       => 'Entrega registrada correctamente.',
        'total'         => eventosapp_expositor_count_deliveries( $event_id, $expositor_id ),
        'product_total' => eventosapp_expositor_count_deliveries( $event_id, $expositor_id, $product_id ),
        'products_html' => eventosapp_expositor_render_products_table( $event_id, $expositor_id, false ),
    ] );
} );

add_action( 'wp_ajax_eventosapp_expositor_save_download_permissions', function () {
    $event_id = absint( $_POST['event_id'] ?? 0 );
    check_ajax_referer( 'eventosapp_expositor_gestion_' . $event_id, 'nonce' );

    if ( ! eventosapp_expositor_manager_can_access_event( $event_id ) ) {
        wp_send_json_error( [ 'message' => 'No tienes permisos para gestionar expositores.' ], 403 );
    }

    $assigned = eventosapp_event_get_expositores( $event_id );
    $raw      = isset( $_POST['permissions'] ) && is_array( $_POST['permissions'] ) ? wp_unslash( $_POST['permissions'] ) : [];
    $out      = [];

    foreach ( $assigned as $expositor_id ) {
        $out[ $expositor_id ] = ! empty( $raw[ $expositor_id ] ) ? '1' : '0';
    }

    update_post_meta( $event_id, '_eventosapp_expositor_download_permissions', $out );
    wp_send_json_success( [ 'message' => 'Permisos de descarga actualizados.' ] );
} );

// ============================================================
// 5. RENDER FRONTEND
// ============================================================

function eventosapp_expositor_front_css() {
    static $printed = false;
    if ( $printed ) return;
    $printed = true;
    ?>
    <style>
        .evapp-expositor-module{--evapp-blue:#2F73B5;--evapp-border:#dfe3e8;--evapp-soft:#f6f8fb;font-family:inherit;}
        .evapp-expositor-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px;}
        .evapp-expositor-header h2{margin:0 0 4px;font-size:28px;line-height:1.15;}
        .evapp-expositor-muted{color:#637083;font-size:14px;}
        .evapp-expositor-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:16px 0;}
        .evapp-expositor-card{border:1px solid var(--evapp-border);border-radius:14px;background:#fff;padding:16px;box-shadow:0 3px 14px rgba(0,0,0,.04);}
        .evapp-expositor-card h3{margin:0 0 10px;font-size:18px;}
        .evapp-expositor-stat{font-size:34px;font-weight:800;color:var(--evapp-blue);line-height:1;}
        .evapp-expositor-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:12px 0;}
        .evapp-expositor-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:0;border-radius:10px;background:var(--evapp-blue);color:#fff!important;text-decoration:none;padding:10px 14px;font-weight:700;cursor:pointer;line-height:1.2;}
        .evapp-expositor-btn:hover{background:#275f95;color:#fff!important;}
        .evapp-expositor-btn.secondary{background:#eef2f7;color:#1f2937!important;border:1px solid #d7dce2;}
        .evapp-expositor-btn.secondary:hover{background:#e3e8ef;color:#1f2937!important;}
        .evapp-expositor-btn.danger{background:#d63638;}
        .evapp-expositor-btn[disabled]{opacity:.55;cursor:not-allowed;}
        .evapp-expositor-input,.evapp-expositor-select{width:100%;border:1px solid #cfd6df;border-radius:10px;padding:10px 12px;background:#fff;box-sizing:border-box;}
        .evapp-expositor-form-grid{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:10px;align-items:end;}
        .evapp-expositor-field label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.4px;font-weight:800;color:#667085;margin-bottom:5px;}
        .evapp-expositor-table{width:100%;border-collapse:collapse;font-size:14px;}
        .evapp-expositor-table th,.evapp-expositor-table td{border-bottom:1px solid #edf0f3;padding:9px;text-align:left;vertical-align:middle;}
        .evapp-expositor-table th{font-size:12px;text-transform:uppercase;color:#667085;background:#f8fafc;}
        .evapp-expositor-notice{border-radius:12px;padding:12px 14px;margin:12px 0;border:1px solid #d7e2f2;background:#f4f8ff;}
        .evapp-expositor-notice.error{border-color:#f0b7b8;background:#fff5f5;color:#7f1d1d;}
        .evapp-expositor-notice.success{border-color:#b7e2c1;background:#f0fff4;color:#14532d;}
        .evapp-expositor-scanner{display:none;border:1px solid var(--evapp-border);border-radius:14px;background:#0f172a;padding:12px;margin:12px 0;color:#fff;}
        .evapp-expositor-scanner video{width:100%;max-height:420px;background:#000;border-radius:10px;display:block;}
        .evapp-expositor-attendee{display:none;border:1px solid #bfd4f0;background:#f7fbff;border-radius:14px;padding:14px;margin:12px 0;}
        .evapp-expositor-attendee h3{margin:0 0 6px;}
        .evapp-expositor-badge{display:inline-flex;border-radius:999px;padding:3px 9px;font-size:12px;font-weight:800;background:#edf2f7;color:#344054;}
        @media(max-width:900px){.evapp-expositor-grid{grid-template-columns:1fr;}.evapp-expositor-form-grid{grid-template-columns:1fr;}.evapp-expositor-header{display:block;}}
    </style>
    <?php
}

function eventosapp_expositor_render_products_table( $event_id, $expositor_id, $echo = true ) {
    $products = eventosapp_expositor_get_products( $event_id, $expositor_id );
    ob_start();

    if ( empty( $products ) ) {
        echo '<p class="evapp-expositor-muted">Todavía no has creado productos o consumibles para entregar.</p>';
    } else {
        echo '<table class="evapp-expositor-table"><thead><tr><th>Producto</th><th>Inventario</th><th>Límite por asistente</th><th>Entregados</th><th>Estado</th></tr></thead><tbody>';
        foreach ( $products as $product ) {
            $delivered = eventosapp_expositor_count_deliveries( $event_id, $expositor_id, $product['id'] );
            $inventory = $product['inventory'] === '' ? 'Sin límite' : ( $delivered . ' / ' . absint( $product['inventory'] ) );
            $limit     = absint( $product['limit_per_attendee'] ) === 0 ? 'Sin límite' : absint( $product['limit_per_attendee'] );
            echo '<tr>';
            echo '<td><strong>' . esc_html( $product['name'] ) . '</strong><br><code>' . esc_html( $product['id'] ) . '</code></td>';
            echo '<td>' . esc_html( $inventory ) . '</td>';
            echo '<td>' . esc_html( $limit ) . '</td>';
            echo '<td><strong>' . esc_html( $delivered ) . '</strong></td>';
            echo '<td><span class="evapp-expositor-badge">' . ( $product['active'] === '1' ? 'Activo' : 'Inactivo' ) . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    $html = ob_get_clean();
    if ( $echo ) echo $html;
    return $html;
}

function eventosapp_expositor_products_select_options( $event_id, $expositor_id ) {
    $products = eventosapp_expositor_get_products( $event_id, $expositor_id );
    foreach ( $products as $product ) {
        if ( $product['active'] !== '1' ) continue;
        echo '<option value="' . esc_attr( $product['id'] ) . '">' . esc_html( $product['name'] ) . '</option>';
    }
}

add_shortcode( 'eventosapp_expositor', function () {
    if ( ! is_user_logged_in() ) {
        return '<p>Debes iniciar sesión para acceder al módulo de expositor.</p>';
    }
    if ( function_exists( 'eventosapp_require_feature' ) ) {
        eventosapp_require_feature( 'expositor' );
    }

    $event_id = eventosapp_expositor_current_event_id();
    if ( ! $event_id ) {
        return '<div class="evapp-expositor-module"><p>Debes seleccionar primero un evento desde el dashboard.</p></div>';
    }
    if ( ! eventosapp_event_expositores_enabled( $event_id ) ) {
        return '<div class="evapp-expositor-module"><p>El módulo de expositores no está activo para este evento.</p></div>';
    }

    $user_id       = get_current_user_id();
    $expositor_ids = eventosapp_expositor_user_get_expositor_ids_for_event( $event_id, $user_id );
    if ( eventosapp_expositor_manager_can_access_event( $event_id, $user_id ) ) {
        $expositor_ids = eventosapp_event_get_expositores( $event_id );
    }

    if ( empty( $expositor_ids ) ) {
        return '<div class="evapp-expositor-module"><p>No tienes un expositor asignado para este evento.</p></div>';
    }

    $current_expositor = isset( $_GET['expositor_id'] ) ? absint( $_GET['expositor_id'] ) : absint( $expositor_ids[0] );
    if ( ! in_array( $current_expositor, $expositor_ids, true ) ) {
        $current_expositor = absint( $expositor_ids[0] );
    }

    $event_title       = get_the_title( $event_id );
    $expositor_name    = get_post_meta( $current_expositor, '_expositor_nombre_empresa', true ) ?: get_the_title( $current_expositor );
    $total_deliveries  = eventosapp_expositor_count_deliveries( $event_id, $current_expositor );
    $total_products    = count( eventosapp_expositor_get_products( $event_id, $current_expositor ) );
    $download_allowed  = eventosapp_expositor_download_is_allowed( $event_id, $current_expositor, $user_id );
    $nonce             = wp_create_nonce( 'eventosapp_expositor_front_' . $event_id );
    $download_url      = wp_nonce_url( admin_url( 'admin-post.php?action=eventosapp_expositor_download_csv&event_id=' . $event_id . '&expositor_id=' . $current_expositor ), 'eventosapp_expositor_download_' . $event_id . '_' . $current_expositor );

    ob_start();
    eventosapp_expositor_front_css();
    ?>
    <div class="evapp-expositor-module" id="evapp-expositor-module" data-event-id="<?php echo esc_attr( $event_id ); ?>" data-expositor-id="<?php echo esc_attr( $current_expositor ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
        <div class="evapp-expositor-header">
            <div>
                <h2>Expositor</h2>
                <div class="evapp-expositor-muted"><strong><?php echo esc_html( $expositor_name ); ?></strong> · <?php echo esc_html( $event_title ); ?></div>
            </div>
            <?php if ( count( $expositor_ids ) > 1 ) : ?>
                <form method="get">
                    <label class="evapp-expositor-muted" for="evapp_expositor_switch">Cambiar expositor</label>
                    <select id="evapp_expositor_switch" name="expositor_id" class="evapp-expositor-select" onchange="this.form.submit()">
                        <?php foreach ( $expositor_ids as $eid ) :
                            $name = get_post_meta( $eid, '_expositor_nombre_empresa', true ) ?: get_the_title( $eid );
                            ?>
                            <option value="<?php echo esc_attr( $eid ); ?>" <?php selected( $current_expositor, $eid ); ?>><?php echo esc_html( $name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
        </div>

        <div class="evapp-expositor-grid">
            <div class="evapp-expositor-card"><h3>Personas entregadas</h3><div class="evapp-expositor-stat" id="evapp-expo-total-deliveries"><?php echo esc_html( $total_deliveries ); ?></div></div>
            <div class="evapp-expositor-card"><h3>Productos creados</h3><div class="evapp-expositor-stat"><?php echo esc_html( $total_products ); ?></div></div>
            <div class="evapp-expositor-card"><h3>Base de datos</h3><?php if ( $download_allowed ) : ?><a class="evapp-expositor-btn" href="<?php echo esc_url( $download_url ); ?>">Descargar CSV</a><?php else : ?><p class="evapp-expositor-muted">Descarga pendiente de autorización por el organizador.</p><?php endif; ?></div>
        </div>

        <div class="evapp-expositor-card">
            <h3>Crear producto o consumible</h3>
            <div class="evapp-expositor-form-grid">
                <div class="evapp-expositor-field"><label>Producto</label><input type="text" id="evapp_product_name" class="evapp-expositor-input" placeholder="Ej. Muestra gratis, bebida, cupón"></div>
                <div class="evapp-expositor-field"><label>Inventario</label><input type="number" id="evapp_product_inventory" class="evapp-expositor-input" min="0" placeholder="Vacío = sin límite"></div>
                <div class="evapp-expositor-field"><label>Límite por asistente</label><input type="number" id="evapp_product_limit" class="evapp-expositor-input" min="0" value="1" placeholder="0 = sin límite"></div>
                <button class="evapp-expositor-btn" id="evapp_save_product" type="button">Guardar</button>
            </div>
            <div id="evapp-products-table" style="margin-top:14px;">
                <?php eventosapp_expositor_render_products_table( $event_id, $current_expositor ); ?>
            </div>
        </div>

        <div class="evapp-expositor-card" style="margin-top:14px;">
            <h3>Control de entrega por QR</h3>
            <div class="evapp-expositor-form-grid" style="grid-template-columns:2fr auto auto;">
                <div class="evapp-expositor-field">
                    <label>Producto a entregar</label>
                    <select id="evapp_delivery_product" class="evapp-expositor-select">
                        <option value="">— Selecciona un producto —</option>
                        <?php eventosapp_expositor_products_select_options( $event_id, $current_expositor ); ?>
                    </select>
                </div>
                <button class="evapp-expositor-btn" id="evapp_open_camera" type="button">Abrir cámara</button>
                <button class="evapp-expositor-btn secondary" id="evapp_stop_camera" type="button" style="display:none;">Cerrar cámara</button>
            </div>

            <div class="evapp-expositor-actions">
                <input type="text" id="evapp_manual_qr" class="evapp-expositor-input" style="max-width:520px;" placeholder="También puedes pegar o escribir el contenido del QR">
                <button class="evapp-expositor-btn secondary" id="evapp_validate_manual" type="button">Validar QR</button>
            </div>

            <div id="evapp-scanner" class="evapp-expositor-scanner">
                <video id="evapp-scanner-video" playsinline muted></video>
                <div class="evapp-expositor-muted" id="evapp-scanner-status" style="color:#dbeafe;margin-top:8px;">Apunta la cámara al QR del asistente.</div>
            </div>

            <div id="evapp-expo-message"></div>
            <div class="evapp-expositor-attendee" id="evapp-attendee-card">
                <h3 id="evapp-attendee-name"></h3>
                <div id="evapp-attendee-data" class="evapp-expositor-muted"></div>
                <div id="evapp-attendee-warning"></div>
                <div class="evapp-expositor-actions">
                    <button class="evapp-expositor-btn" id="evapp_confirm_delivery" type="button">Confirmar entrega</button>
                    <button class="evapp-expositor-btn secondary" id="evapp_cancel_delivery" type="button">Cancelar</button>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var root = document.getElementById('evapp-expositor-module');
        if(!root) return;
        var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var state = {stream:null, detector:null, scanning:false, ticketId:0, force:false};
        function qs(id){ return document.getElementById(id); }
        function message(text, type){
            var el = qs('evapp-expo-message');
            el.innerHTML = text ? '<div class="evapp-expositor-notice '+(type||'')+'">'+text+'</div>' : '';
        }
        function form(params){
            var fd = new FormData();
            Object.keys(params).forEach(function(k){ fd.append(k, params[k]); });
            return fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:fd}).then(function(r){ return r.json().then(function(j){ j.httpStatus = r.status; return j; }); });
        }
        function refreshProductSelect(){
            var tmp = document.createElement('div');
            tmp.innerHTML = qs('evapp-products-table').innerHTML;
        }
        qs('evapp_save_product').addEventListener('click', function(){
            var name = qs('evapp_product_name').value.trim();
            if(!name){ message('El nombre del producto es obligatorio.', 'error'); return; }
            this.disabled = true;
            form({action:'eventosapp_expositor_save_product', nonce:root.dataset.nonce, event_id:root.dataset.eventId, expositor_id:root.dataset.expositorId, name:name, inventory:qs('evapp_product_inventory').value, limit_per_attendee:qs('evapp_product_limit').value, active:'1'}).then(function(resp){
                if(resp.success){
                    message(resp.data.message, 'success');
                    qs('evapp-products-table').innerHTML = resp.data.products_html;
                    window.location.reload();
                }else{
                    message(resp.data && resp.data.message ? resp.data.message : 'No se pudo guardar el producto.', 'error');
                }
            }).catch(function(){ message('Error de conexión.', 'error'); }).finally(function(){ qs('evapp_save_product').disabled = false; });
        });
        function validateQr(content){
            var product = qs('evapp_delivery_product').value;
            if(!product){ message('Selecciona primero el producto que quieres entregar.', 'error'); return; }
            if(!content){ message('No se detectó contenido de QR.', 'error'); return; }
            message('Validando QR...', '');
            form({action:'eventosapp_expositor_validate_qr', nonce:root.dataset.nonce, event_id:root.dataset.eventId, expositor_id:root.dataset.expositorId, product_id:product, qr_content:content}).then(function(resp){
                if(!resp.success){ message(resp.data && resp.data.message ? resp.data.message : 'No se pudo validar el QR.', 'error'); return; }
                var a = resp.data.attendee;
                state.ticketId = a.ticket_id;
                state.force = false;
                qs('evapp-attendee-name').textContent = a.full_name || 'Asistente sin nombre';
                qs('evapp-attendee-data').innerHTML = '<strong>Ticket:</strong> '+(a.public_ticket_id || a.ticket_id)+'<br><strong>Email:</strong> '+(a.email || '—')+'<br><strong>Empresa:</strong> '+(a.company || '—')+'<br><strong>Documento:</strong> '+(a.document || '—');
                qs('evapp-attendee-warning').innerHTML = resp.data.duplicate ? '<div class="evapp-expositor-notice error">'+resp.data.duplicate_label+'</div>' : '';
                qs('evapp-attendee-card').style.display = 'block';
                message('QR válido. Confirma la entrega para registrar el beneficio.', 'success');
                stopCamera();
            }).catch(function(){ message('Error de conexión al validar.', 'error'); });
        }
        function registerDelivery(force){
            var product = qs('evapp_delivery_product').value;
            if(!state.ticketId || !product){ message('Primero valida un QR y selecciona un producto.', 'error'); return; }
            qs('evapp_confirm_delivery').disabled = true;
            form({action:'eventosapp_expositor_register_delivery', nonce:root.dataset.nonce, event_id:root.dataset.eventId, expositor_id:root.dataset.expositorId, product_id:product, ticket_id:state.ticketId, force:force ? '1' : '0'}).then(function(resp){
                if(resp.success){
                    message(resp.data.message, 'success');
                    qs('evapp-expo-total-deliveries').textContent = resp.data.total;
                    qs('evapp-products-table').innerHTML = resp.data.products_html;
                    qs('evapp-attendee-card').style.display = 'none';
                    state.ticketId = 0;
                    state.force = false;
                    return;
                }
                if(resp.data && resp.data.code === 'duplicate' && resp.data.can_force){
                    message(resp.data.message, 'error');
                    state.force = true;
                    qs('evapp_confirm_delivery').textContent = 'Confirmar de todas formas';
                    return;
                }
                message(resp.data && resp.data.message ? resp.data.message : 'No se pudo registrar la entrega.', 'error');
            }).catch(function(){ message('Error de conexión al registrar.', 'error'); }).finally(function(){ qs('evapp_confirm_delivery').disabled = false; });
        }
        qs('evapp_validate_manual').addEventListener('click', function(){ validateQr(qs('evapp_manual_qr').value.trim()); });
        qs('evapp_confirm_delivery').addEventListener('click', function(){ registerDelivery(state.force); });
        qs('evapp_cancel_delivery').addEventListener('click', function(){ qs('evapp-attendee-card').style.display = 'none'; state.ticketId = 0; state.force = false; qs('evapp_confirm_delivery').textContent = 'Confirmar entrega'; });
        function stopCamera(){
            state.scanning = false;
            if(state.stream){ state.stream.getTracks().forEach(function(t){ t.stop(); }); state.stream = null; }
            qs('evapp-scanner').style.display = 'none';
            qs('evapp_stop_camera').style.display = 'none';
        }
        qs('evapp_stop_camera').addEventListener('click', stopCamera);
        function loadJsQr(){
            return new Promise(function(resolve, reject){
                if(window.jsQR){ resolve(); return; }
                var existing = document.querySelector('script[data-evapp-jsqr="1"]');
                if(existing){ existing.addEventListener('load', resolve); existing.addEventListener('error', reject); return; }
                var script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js';
                script.async = true;
                script.setAttribute('data-evapp-jsqr', '1');
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }
        function startQrLoop(video){
            if('BarcodeDetector' in window){
                state.detector = new BarcodeDetector({formats:['qr_code']});
                function nativeTick(){
                    if(!state.scanning) return;
                    state.detector.detect(video).then(function(codes){
                        if(codes && codes.length){
                            var raw = codes[0].rawValue || '';
                            if(raw){ validateQr(raw); return; }
                        }
                        requestAnimationFrame(nativeTick);
                    }).catch(function(){ requestAnimationFrame(nativeTick); });
                }
                requestAnimationFrame(nativeTick);
                return;
            }
            loadJsQr().then(function(){
                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d', {willReadFrequently:true});
                function jsQrTick(){
                    if(!state.scanning) return;
                    if(video.readyState === video.HAVE_ENOUGH_DATA){
                        canvas.width = video.videoWidth || 640;
                        canvas.height = video.videoHeight || 480;
                        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                        var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                        var code = window.jsQR ? window.jsQR(imageData.data, canvas.width, canvas.height) : null;
                        if(code && code.data){ validateQr(code.data); return; }
                    }
                    requestAnimationFrame(jsQrTick);
                }
                requestAnimationFrame(jsQrTick);
            }).catch(function(){
                stopCamera();
                message('No se pudo cargar el lector QR alternativo. Usa el campo manual o revisa la conexión del navegador.', 'error');
            });
        }
        qs('evapp_open_camera').addEventListener('click', function(){
            if(!('mediaDevices' in navigator) || !navigator.mediaDevices.getUserMedia){ message('Este navegador no permite abrir la cámara. Usa el campo manual.', 'error'); return; }
            navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}}}).then(function(stream){
                state.stream = stream;
                state.scanning = true;
                qs('evapp-scanner').style.display = 'block';
                qs('evapp_stop_camera').style.display = 'inline-flex';
                var video = qs('evapp-scanner-video');
                video.srcObject = stream;
                video.play();
                startQrLoop(video);
            }).catch(function(){ message('No se pudo abrir la cámara. Revisa permisos del navegador.', 'error'); });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
} );

add_shortcode( 'eventosapp_expositor_gestion', function () {
    if ( ! is_user_logged_in() ) return '<p>Debes iniciar sesión.</p>';
    if ( function_exists( 'eventosapp_require_feature' ) ) {
        eventosapp_require_feature( 'expositor_gestion' );
    }

    $event_id = eventosapp_expositor_current_event_id();
    if ( ! $event_id ) return '<p>Debes seleccionar primero un evento desde el dashboard.</p>';
    if ( ! eventosapp_expositor_manager_can_access_event( $event_id ) ) return '<p>No tienes permisos para gestionar expositores en este evento.</p>';

    $assigned = eventosapp_event_get_expositores( $event_id );
    $permissions = eventosapp_expositor_get_download_permissions( $event_id );
    $nonce = wp_create_nonce( 'eventosapp_expositor_gestion_' . $event_id );

    ob_start();
    eventosapp_expositor_front_css();
    ?>
    <div class="evapp-expositor-module" id="evapp-expositor-gestion" data-event-id="<?php echo esc_attr( $event_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
        <div class="evapp-expositor-header">
            <div>
                <h2>Gestión de Expositores</h2>
                <div class="evapp-expositor-muted"><?php echo esc_html( get_the_title( $event_id ) ); ?></div>
            </div>
        </div>

        <?php if ( ! eventosapp_event_expositores_enabled( $event_id ) ) : ?>
            <div class="evapp-expositor-notice error">El módulo de expositores no está activo para este evento.</div>
        <?php endif; ?>

        <?php if ( empty( $assigned ) ) : ?>
            <div class="evapp-expositor-notice">No hay expositores asociados a este evento todavía.</div>
        <?php else : ?>
            <div class="evapp-expositor-card">
                <h3>Autorización de descarga y métricas</h3>
                <table class="evapp-expositor-table">
                    <thead><tr><th>Expositor</th><th>Productos</th><th>Entregas</th><th>Autorizar descarga al expositor</th><th>CSV organizador</th></tr></thead>
                    <tbody>
                    <?php foreach ( $assigned as $expositor_id ) :
                        $name = get_post_meta( $expositor_id, '_expositor_nombre_empresa', true ) ?: get_the_title( $expositor_id );
                        $products = eventosapp_expositor_get_products( $event_id, $expositor_id );
                        $total = eventosapp_expositor_count_deliveries( $event_id, $expositor_id );
                        $url = wp_nonce_url( admin_url( 'admin-post.php?action=eventosapp_expositor_download_csv&event_id=' . $event_id . '&expositor_id=' . $expositor_id ), 'eventosapp_expositor_download_' . $event_id . '_' . $expositor_id );
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( $name ); ?></strong></td>
                            <td><?php echo esc_html( count( $products ) ); ?></td>
                            <td><strong><?php echo esc_html( $total ); ?></strong></td>
                            <td><label><input type="checkbox" class="evapp-expo-permission" data-expositor="<?php echo esc_attr( $expositor_id ); ?>" value="1" <?php checked( ! empty( $permissions[ $expositor_id ] ) ); ?>> Permitir descarga</label></td>
                            <td><a class="evapp-expositor-btn secondary" href="<?php echo esc_url( $url ); ?>">Descargar CSV</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="evapp-expositor-actions"><button type="button" class="evapp-expositor-btn" id="evapp-save-expo-permissions">Guardar autorizaciones</button></div>
                <div id="evapp-gestion-msg"></div>
            </div>
        <?php endif; ?>
    </div>
    <script>
    (function(){
        var root = document.getElementById('evapp-expositor-gestion');
        if(!root) return;
        var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var btn = document.getElementById('evapp-save-expo-permissions');
        var msg = document.getElementById('evapp-gestion-msg');
        if(!btn) return;
        btn.addEventListener('click', function(){
            var fd = new FormData();
            fd.append('action', 'eventosapp_expositor_save_download_permissions');
            fd.append('event_id', root.dataset.eventId);
            fd.append('nonce', root.dataset.nonce);
            document.querySelectorAll('.evapp-expo-permission').forEach(function(chk){
                if(chk.checked) fd.append('permissions['+chk.dataset.expositor+']', '1');
            });
            btn.disabled = true;
            fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:fd}).then(function(r){ return r.json(); }).then(function(resp){
                msg.innerHTML = '<div class="evapp-expositor-notice '+(resp.success ? 'success' : 'error')+'">'+(resp.data && resp.data.message ? resp.data.message : (resp.success ? 'Guardado.' : 'No se pudo guardar.'))+'</div>';
            }).catch(function(){
                msg.innerHTML = '<div class="evapp-expositor-notice error">Error de conexión.</div>';
            }).finally(function(){ btn.disabled = false; });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
} );

// ============================================================
// 6. CSV
// ============================================================

add_action( 'admin_post_eventosapp_expositor_download_csv', function () {
    global $wpdb;

    $event_id     = absint( $_GET['event_id'] ?? 0 );
    $expositor_id = absint( $_GET['expositor_id'] ?? 0 );

    if ( ! $event_id || ! $expositor_id ) {
        wp_die( 'Parámetros inválidos.' );
    }

    check_admin_referer( 'eventosapp_expositor_download_' . $event_id . '_' . $expositor_id );

    if ( ! eventosapp_expositor_user_can_manage_expositor( $event_id, $expositor_id ) && ! eventosapp_expositor_download_is_allowed( $event_id, $expositor_id ) ) {
        wp_die( 'No tienes autorización para descargar esta base de datos.' );
    }

    if ( ! eventosapp_expositor_download_is_allowed( $event_id, $expositor_id ) ) {
        wp_die( 'La descarga todavía no fue autorizada por el organizador.' );
    }

    $rows = $wpdb->get_results( $wpdb->prepare(
        'SELECT * FROM ' . eventosapp_expositores_table_name() . ' WHERE event_id = %d AND expositor_id = %d ORDER BY created_at_gmt DESC',
        $event_id,
        $expositor_id
    ), ARRAY_A );

    $event_slug     = sanitize_title( get_the_title( $event_id ) ?: 'evento' );
    $expositor_slug = sanitize_title( get_the_title( $expositor_id ) ?: 'expositor' );
    $filename       = 'entregas-' . $event_slug . '-' . $expositor_slug . '-' . date_i18n( 'Ymd-His' ) . '.csv';

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=' . $filename );

    $out = fopen( 'php://output', 'w' );
    fprintf( $out, chr(0xEF) . chr(0xBB) . chr(0xBF) );
    fputcsv( $out, [
        'Fecha',
        'Evento',
        'Expositor',
        'Producto',
        'Ticket público',
        'Post ID ticket',
        'Nombre',
        'Apellido',
        'Email',
        'Teléfono',
        'Empresa',
        'Documento',
        'Localidad',
        'Entregado por',
    ] );

    foreach ( $rows as $row ) {
        fputcsv( $out, [
            $row['created_at'],
            get_the_title( $event_id ),
            get_the_title( $expositor_id ),
            $row['product_name'],
            $row['public_ticket_id'],
            $row['ticket_id'],
            $row['attendee_name'],
            $row['attendee_lastname'],
            $row['attendee_email'],
            $row['attendee_phone'],
            $row['attendee_company'],
            $row['attendee_document'],
            $row['attendee_location'],
            $row['delivered_by_name'],
        ] );
    }

    fclose( $out );
    exit;
} );

// ============================================================
// 7. COLUMNAS ADMIN
// ============================================================

add_filter( 'manage_eventosapp_expositor_posts_columns', function ( $cols ) {
    $new = [];
    $new['cb']                 = $cols['cb'];
    $new['evapp_expo_logo']    = 'Logo';
    $new['title']              = 'Expositor';
    $new['evapp_expo_cliente'] = 'Cliente asociado';
    $new['evapp_expo_nit']     = 'NIT';
    $new['evapp_expo_contact'] = 'Contacto';
    $new['date']               = $cols['date'];
    return $new;
} );

add_action( 'manage_eventosapp_expositor_posts_custom_column', function ( $column, $post_id ) {
    switch ( $column ) {
        case 'evapp_expo_logo':
            $logo_id = absint( get_post_meta( $post_id, '_expositor_logo_id', true ) );
            $url     = $logo_id ? wp_get_attachment_image_url( $logo_id, [ 48, 48 ] ) : '';
            echo $url ? '<img src="' . esc_url( $url ) . '" style="width:48px;height:48px;object-fit:contain;border:1px solid #ddd;border-radius:4px;padding:3px;background:#fff;">' : '<span class="dashicons dashicons-store" style="color:#bbb;font-size:28px;"></span>';
            break;
        case 'evapp_expo_cliente':
            $cliente_id = absint( get_post_meta( $post_id, '_expositor_cliente_id', true ) );
            echo $cliente_id ? esc_html( get_post_meta( $cliente_id, '_cliente_nombre_empresa', true ) ?: get_the_title( $cliente_id ) ) : '—';
            break;
        case 'evapp_expo_nit':
            echo esc_html( get_post_meta( $post_id, '_expositor_nit', true ) ?: '—' );
            break;
        case 'evapp_expo_contact':
            $contact = get_post_meta( $post_id, '_expositor_contacto', true );
            $email   = get_post_meta( $post_id, '_expositor_email', true );
            echo esc_html( $contact ?: '—' );
            if ( $email ) echo '<br><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
            break;
    }
}, 10, 2 );

add_filter( 'manage_edit-eventosapp_expositor_sortable_columns', function ( $cols ) {
    $cols['title'] = 'title';
    return $cols;
} );

if ( ! function_exists( 'eventosapp_get_expositor' ) ) {
    function eventosapp_get_expositor( $expositor_id ) {
        $post = get_post( $expositor_id );
        if ( ! $post || $post->post_type !== 'eventosapp_expositor' ) return false;
        $logo_id = absint( get_post_meta( $expositor_id, '_expositor_logo_id', true ) );
        return [
            'id'             => absint( $expositor_id ),
            'nombre_empresa' => get_post_meta( $expositor_id, '_expositor_nombre_empresa', true ),
            'razon_social'   => get_post_meta( $expositor_id, '_expositor_razon_social', true ),
            'nit'            => get_post_meta( $expositor_id, '_expositor_nit', true ),
            'cliente_id'     => absint( get_post_meta( $expositor_id, '_expositor_cliente_id', true ) ),
            'contacto'       => get_post_meta( $expositor_id, '_expositor_contacto', true ),
            'email'          => get_post_meta( $expositor_id, '_expositor_email', true ),
            'telefono'       => get_post_meta( $expositor_id, '_expositor_telefono', true ),
            'ciudad'         => get_post_meta( $expositor_id, '_expositor_ciudad', true ),
            'departamento'   => get_post_meta( $expositor_id, '_expositor_departamento', true ),
            'direccion'      => get_post_meta( $expositor_id, '_expositor_direccion', true ),
            'logo_id'        => $logo_id,
            'logo_url'       => $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '',
        ];
    }
}
