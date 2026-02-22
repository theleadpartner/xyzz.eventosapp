<?php
/**
 * CPT: eventosapp_asistente
 * Perfil de asistentes/personas para EventosApp.
 *
 * Campos:
 *   _asistente_nombres    → Nombres
 *   _asistente_apellidos  → Apellidos
 *   _asistente_cedula     → Cédula de Ciudadanía
 *   _asistente_email      → Correo Electrónico
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
// 3. GUARDAR CAMPOS Y SINCRONIZAR TÍTULO AL GUARDAR
// ============================================================

add_action( 'save_post_eventosapp_asistente', function ( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! isset( $_POST['_asistente_nonce'] ) || ! wp_verify_nonce( $_POST['_asistente_nonce'], 'eventosapp_asistente_guardar' ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $campos = [
        '_asistente_nombres'   => 'sanitize_text_field',
        '_asistente_apellidos' => 'sanitize_text_field',
        '_asistente_cedula'    => 'sanitize_text_field',
        '_asistente_email'     => 'sanitize_email',
        '_asistente_telefono'  => 'sanitize_text_field',
        '_asistente_empresa'   => 'sanitize_text_field',
        '_asistente_cargo'     => 'sanitize_text_field',
        '_asistente_ciudad'    => 'sanitize_text_field',
        '_asistente_pais'      => 'sanitize_text_field',
        '_asistente_foto_id'   => 'absint',
    ];

    foreach ( $campos as $key => $sanitizer ) {
        if ( isset( $_POST[ $key ] ) ) {
            update_post_meta( $post_id, $key, $sanitizer( $_POST[ $key ] ) );
        }
    }

    // Sincronizar post_title con Nombres + Apellidos usando wpdb directo
    // para evitar bucle de hooks y posibles errores 503.
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
            <label for="_asistente_cedula">Cédula de Ciudadanía</label>
            <input type="text" id="_asistente_cedula" name="_asistente_cedula"
                   value="<?php echo esc_attr( $cedula ); ?>" placeholder="Ej: 1234567890" />
        </div>

        <!-- Sección: Contacto -->
        <p class="evapp-asistente-section-title">Información de Contacto</p>

        <div>
            <label for="_asistente_email">Correo Electrónico</label>
            <input type="email" id="_asistente_email" name="_asistente_email"
                   value="<?php echo esc_attr( $email ); ?>" placeholder="correo@ejemplo.com" />
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
    <?php
}

// ============================================================
// 4.2 RENDER: Metabox Foto del Asistente
// ============================================================

function eventosapp_asistente_foto_metabox( $post ) {
    $foto_id  = (int) get_post_meta( $post->ID, '_asistente_foto_id', true );
    $foto_url = $foto_id ? wp_get_attachment_image_url( $foto_id, 'medium' ) : '';
    ?>
    <style>
        #evapp-foto-preview {
            width: 100%;
            max-width: 180px;
            height: 180px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #c3c4c7;
            display: <?php echo $foto_url ? 'block' : 'none'; ?>;
            margin: 0 auto 10px;
        }
        #evapp-foto-placeholder {
            width: 100%;
            max-width: 180px;
            height: 180px;
            border-radius: 8px;
            border: 2px dashed #c3c4c7;
            display: <?php echo $foto_url ? 'none' : 'flex'; ?>;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            margin: 0 auto 10px;
            color: #8c8f94;
            font-size: 12px;
            gap: 8px;
        }
        .evapp-foto-actions {
            display: flex;
            flex-direction: column;
            gap: 6px;
            align-items: center;
        }
        .evapp-foto-actions .button { width: 100%; max-width: 180px; text-align: center; }
    </style>

    <input type="hidden" id="_asistente_foto_id" name="_asistente_foto_id"
           value="<?php echo esc_attr( $foto_id ); ?>" />

    <img id="evapp-foto-preview" src="<?php echo esc_url( $foto_url ); ?>" alt="Foto del asistente" />

    <div id="evapp-foto-placeholder">
        <span class="dashicons dashicons-format-image" style="font-size:36px;width:36px;height:36px;"></span>
        <span>Sin foto</span>
    </div>

    <div class="evapp-foto-actions">
        <button type="button" id="evapp-foto-upload" class="button button-secondary">
            📷 <?php echo $foto_id ? 'Cambiar foto' : 'Subir foto'; ?>
        </button>
        <?php if ( $foto_id ) : ?>
        <button type="button" id="evapp-foto-remove" class="button" style="color:#d63638;">
            🗑️ Eliminar foto
        </button>
        <?php endif; ?>
    </div>

    <script>
    (function($){
        var frame;

        $('#evapp-foto-upload').on('click', function(e){
            e.preventDefault();

            if ( frame ) { frame.open(); return; }

            frame = wp.media({
                title: 'Seleccionar foto del asistente',
                button: { text: 'Usar esta foto' },
                multiple: false,
                library: { type: 'image' }
            });

            frame.on('select', function(){
                var attachment = frame.state().get('selection').first().toJSON();
                $('#_asistente_foto_id').val( attachment.id );
                $('#evapp-foto-preview').attr('src', attachment.url).show();
                $('#evapp-foto-placeholder').hide();
                $('#evapp-foto-upload').text('📷 Cambiar foto');

                // Mostrar botón de eliminar si no existe
                if ( ! $('#evapp-foto-remove').length ) {
                    $('<button type="button" id="evapp-foto-remove" class="button" style="color:#d63638;">🗑️ Eliminar foto</button>')
                        .insertAfter('#evapp-foto-upload');
                    evappBindRemoveFoto();
                }
            });

            frame.open();
        });

        function evappBindRemoveFoto() {
            $(document).on('click', '#evapp-foto-remove', function(e){
                e.preventDefault();
                $('#_asistente_foto_id').val('');
                $('#evapp-foto-preview').hide().attr('src', '');
                $('#evapp-foto-placeholder').show();
                $('#evapp-foto-upload').text('📷 Subir foto');
                $(this).remove();
            });
        }

        evappBindRemoveFoto();

    })(jQuery);
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
