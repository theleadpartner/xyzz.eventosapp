<?php
/**
 * CPT: eventosapp_cliente
 * Perfil de organizadores/empresas para EventosApp.
 *
 * Campos:
 *   _cliente_nombre_empresa   → Nombre de la Empresa
 *   _cliente_razon_social     → Razón Social
 *   _cliente_nit              → NIT
 *   _cliente_ciudad           → Ciudad
 *   _cliente_departamento     → Departamento
 *   _cliente_direccion        → Dirección
 *   _cliente_codigo_postal    → Código Postal
 *   _cliente_telefono         → Número de Contacto
 *   _cliente_email            → Correo Electrónico
 *   _cliente_logo_id          → Attachment ID del Logo
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// 1. REGISTRO DEL CPT
// ============================================================

add_action( 'init', function () {
    register_post_type( 'eventosapp_cliente', [
        'labels' => [
            'name'               => 'Clientes',
            'singular_name'      => 'Cliente',
            'add_new'            => 'Agregar Nuevo',
            'add_new_item'       => 'Agregar Nuevo Cliente',
            'edit_item'          => 'Editar Cliente',
            'new_item'           => 'Nuevo Cliente',
            'view_item'          => 'Ver Cliente',
            'search_items'       => 'Buscar Clientes',
            'not_found'          => 'No se encontraron clientes',
            'not_found_in_trash' => 'No se encontraron clientes en la papelera',
            'menu_name'          => 'Clientes',
            'all_items'          => 'Todos los Clientes',
        ],
        'public'              => false,
        'publicly_queryable'  => false,   // Evita el warning en class-wp-comments-list-table.php
        'exclude_from_search' => true,    // No aparece en búsquedas del sitio
        'show_ui'             => true,
        'menu_icon'           => 'dashicons-building',
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
// 2. OCULTAR CAMPO TITLE NATIVO (usamos Nombre de Empresa como título visual)
// ============================================================

add_action( 'admin_head', function () {
    $screen = get_current_screen();
    if ( $screen && $screen->post_type === 'eventosapp_cliente' ) {
        echo '<style>
        #titlediv { display: none !important; }
        </style>';
    }
} );

// ============================================================
// 3. GUARDAR CAMPOS Y SINCRONIZAR TÍTULO AL GUARDAR
// ============================================================

add_action( 'save_post_eventosapp_cliente', function ( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! isset( $_POST['_cliente_nonce'] ) || ! wp_verify_nonce( $_POST['_cliente_nonce'], 'eventosapp_cliente_guardar' ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    // Guardar todos los campos meta
    $campos = [
        '_cliente_nombre_empresa' => 'sanitize_text_field',
        '_cliente_razon_social'   => 'sanitize_text_field',
        '_cliente_nit'            => 'sanitize_text_field',
        '_cliente_ciudad'         => 'sanitize_text_field',
        '_cliente_departamento'   => 'sanitize_text_field',
        '_cliente_direccion'      => 'sanitize_text_field',
        '_cliente_codigo_postal'  => 'sanitize_text_field',
        '_cliente_telefono'       => 'sanitize_text_field',
        '_cliente_email'          => 'sanitize_email',
        '_cliente_logo_id'        => 'absint',
    ];

    foreach ( $campos as $key => $sanitizer ) {
        if ( isset( $_POST[ $key ] ) ) {
            update_post_meta( $post_id, $key, $sanitizer( $_POST[ $key ] ) );
        }
    }

    // Sincronizar post_title y post_name con el nombre de empresa usando
    // una query directa ($wpdb) en lugar de wp_update_post() para evitar
    // disparar el ciclo completo de hooks de WordPress y agotar el servidor (503).
    $nombre = isset( $_POST['_cliente_nombre_empresa'] ) ? sanitize_text_field( $_POST['_cliente_nombre_empresa'] ) : '';

    if ( $nombre ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            [
                'post_title' => $nombre,
                'post_name'  => wp_unique_post_slug(
                    sanitize_title( $nombre ),
                    $post_id,
                    get_post_status( $post_id ),
                    'eventosapp_cliente',
                    0
                ),
            ],
            [ 'ID' => $post_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        // Limpiar la caché del post para que WordPress lea el título actualizado
        clean_post_cache( $post_id );
    }
}, 20 );

// ============================================================
// 4. METABOXES
// ============================================================

add_action( 'add_meta_boxes', function () {

    // --- Metabox principal: Datos de la Empresa ---
    add_meta_box(
        'eventosapp_cliente_datos',
        '🏢 Datos de la Empresa',
        'eventosapp_cliente_datos_metabox',
        'eventosapp_cliente',
        'normal',
        'high'
    );

    // --- Metabox: Logo ---
    add_meta_box(
        'eventosapp_cliente_logo',
        '🖼️ Logo de la Empresa',
        'eventosapp_cliente_logo_metabox',
        'eventosapp_cliente',
        'side',
        'high'
    );

} );

// ============================================================
// 4.1 RENDER: Metabox Datos de la Empresa
// ============================================================

function eventosapp_cliente_datos_metabox( $post ) {
    wp_nonce_field( 'eventosapp_cliente_guardar', '_cliente_nonce' );

    $nombre     = get_post_meta( $post->ID, '_cliente_nombre_empresa', true );
    $razon      = get_post_meta( $post->ID, '_cliente_razon_social',   true );
    $nit        = get_post_meta( $post->ID, '_cliente_nit',            true );
    $ciudad     = get_post_meta( $post->ID, '_cliente_ciudad',         true );
    $depto      = get_post_meta( $post->ID, '_cliente_departamento',   true );
    $direccion  = get_post_meta( $post->ID, '_cliente_direccion',      true );
    $cp         = get_post_meta( $post->ID, '_cliente_codigo_postal',  true );
    $telefono   = get_post_meta( $post->ID, '_cliente_telefono',       true );
    $email      = get_post_meta( $post->ID, '_cliente_email',          true );

    // Departamentos de Colombia
    $departamentos = [
        'Amazonas','Antioquia','Arauca','Atlántico','Bolívar','Boyacá','Caldas',
        'Caquetá','Casanare','Cauca','Cesar','Chocó','Córdoba','Cundinamarca',
        'Guainía','Guaviare','Huila','La Guajira','Magdalena','Meta','Nariño',
        'Norte de Santander','Putumayo','Quindío','Risaralda','San Andrés y Providencia',
        'Santander','Sucre','Tolima','Valle del Cauca','Vaupés','Vichada',
        'Bogotá D.C.',
    ];
    sort( $departamentos );

    ?>
    <style>
        .evapp-cliente-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 24px;
            margin-top: 8px;
        }
        .evapp-cliente-grid .full-width {
            grid-column: 1 / -1;
        }
        .evapp-cliente-field label {
            display: block;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: #50575e;
            margin-bottom: 5px;
        }
        .evapp-cliente-field input,
        .evapp-cliente-field select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #c3c4c7;
            border-radius: 6px;
            font-size: 14px;
            color: #1e1e1e;
            background: #fff;
            transition: border-color .15s ease;
        }
        .evapp-cliente-field input:focus,
        .evapp-cliente-field select:focus {
            border-color: #4f7cff;
            outline: none;
            box-shadow: 0 0 0 2px rgba(79, 124, 255, .15);
        }
        .evapp-cliente-section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: #9ea3a8;
            border-bottom: 1px solid #e9ecef;
            padding-bottom: 6px;
            margin: 20px 0 14px;
            grid-column: 1 / -1;
        }
    </style>

    <div class="evapp-cliente-grid">

        <!-- SECCIÓN: Identificación -->
        <div class="evapp-cliente-section-title">📋 Identificación</div>

        <div class="evapp-cliente-field full-width">
            <label for="_cliente_nombre_empresa">Nombre de la Empresa <span style="color:#e04f5f">*</span></label>
            <input
                type="text"
                id="_cliente_nombre_empresa"
                name="_cliente_nombre_empresa"
                value="<?php echo esc_attr( $nombre ); ?>"
                placeholder="Ej. Eventos Colombia S.A.S."
                required
            />
        </div>

        <div class="evapp-cliente-field">
            <label for="_cliente_razon_social">Razón Social</label>
            <input
                type="text"
                id="_cliente_razon_social"
                name="_cliente_razon_social"
                value="<?php echo esc_attr( $razon ); ?>"
                placeholder="Razón social legal"
            />
        </div>

        <div class="evapp-cliente-field">
            <label for="_cliente_nit">NIT</label>
            <input
                type="text"
                id="_cliente_nit"
                name="_cliente_nit"
                value="<?php echo esc_attr( $nit ); ?>"
                placeholder="Ej. 900.123.456-7"
            />
        </div>

        <!-- SECCIÓN: Ubicación -->
        <div class="evapp-cliente-section-title">📍 Ubicación</div>

        <div class="evapp-cliente-field">
            <label for="_cliente_departamento">Departamento</label>
            <select id="_cliente_departamento" name="_cliente_departamento">
                <option value="">— Seleccionar —</option>
                <?php foreach ( $departamentos as $d ) : ?>
                    <option value="<?php echo esc_attr( $d ); ?>" <?php selected( $depto, $d ); ?>>
                        <?php echo esc_html( $d ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="evapp-cliente-field">
            <label for="_cliente_ciudad">Ciudad</label>
            <input
                type="text"
                id="_cliente_ciudad"
                name="_cliente_ciudad"
                value="<?php echo esc_attr( $ciudad ); ?>"
                placeholder="Ej. Barranquilla"
            />
        </div>

        <div class="evapp-cliente-field full-width">
            <label for="_cliente_direccion">Dirección</label>
            <input
                type="text"
                id="_cliente_direccion"
                name="_cliente_direccion"
                value="<?php echo esc_attr( $direccion ); ?>"
                placeholder="Ej. Calle 72 # 57-43"
            />
        </div>

        <div class="evapp-cliente-field">
            <label for="_cliente_codigo_postal">Código Postal</label>
            <input
                type="text"
                id="_cliente_codigo_postal"
                name="_cliente_codigo_postal"
                value="<?php echo esc_attr( $cp ); ?>"
                placeholder="Ej. 080001"
            />
        </div>

        <!-- SECCIÓN: Contacto -->
        <div class="evapp-cliente-section-title">📞 Contacto</div>

        <div class="evapp-cliente-field">
            <label for="_cliente_telefono">Número de Contacto</label>
            <input
                type="text"
                id="_cliente_telefono"
                name="_cliente_telefono"
                value="<?php echo esc_attr( $telefono ); ?>"
                placeholder="Ej. +57 300 123 4567"
            />
        </div>

        <div class="evapp-cliente-field">
            <label for="_cliente_email">Correo Electrónico</label>
            <input
                type="email"
                id="_cliente_email"
                name="_cliente_email"
                value="<?php echo esc_attr( $email ); ?>"
                placeholder="contacto@empresa.com"
            />
        </div>

    </div>
    <?php
}

// ============================================================
// 4.2 RENDER: Metabox Logo
// ============================================================

function eventosapp_cliente_logo_metabox( $post ) {
    $logo_id  = (int) get_post_meta( $post->ID, '_cliente_logo_id', true );
    $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
    ?>
    <style>
        #evapp-logo-preview {
            display: <?php echo $logo_url ? 'block' : 'none'; ?>;
            margin-bottom: 10px;
        }
        #evapp-logo-preview img {
            max-width: 100%;
            max-height: 160px;
            border-radius: 6px;
            border: 1px solid #e0e0e0;
            object-fit: contain;
            background: #f8f9fa;
            padding: 6px;
        }
        .evapp-logo-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid;
        }
        .evapp-logo-btn-upload {
            background: #4f7cff;
            color: #fff;
            border-color: #4f7cff;
        }
        .evapp-logo-btn-remove {
            background: #fff;
            color: #e04f5f;
            border-color: #e04f5f;
            margin-top: 8px;
            display: <?php echo $logo_url ? 'inline-flex' : 'none'; ?>;
        }
        .evapp-logo-btn:hover { filter: brightness(.93); }
    </style>

    <input type="hidden" id="_cliente_logo_id" name="_cliente_logo_id" value="<?php echo esc_attr( $logo_id ); ?>" />

    <div id="evapp-logo-preview">
        <img src="<?php echo esc_url( $logo_url ); ?>" alt="Logo de la empresa" />
    </div>

    <button type="button" class="evapp-logo-btn evapp-logo-btn-upload" id="evapp-logo-upload-btn">
        <span class="dashicons dashicons-upload" style="font-size:16px;width:16px;height:16px;"></span>
        <?php echo $logo_url ? 'Cambiar Logo' : 'Subir Logo'; ?>
    </button>

    <br>

    <button type="button" class="evapp-logo-btn evapp-logo-btn-remove" id="evapp-logo-remove-btn">
        <span class="dashicons dashicons-trash" style="font-size:16px;width:16px;height:16px;"></span>
        Quitar Logo
    </button>

    <p style="font-size:11px;color:#9ea3a8;margin-top:10px;line-height:1.5;">
        Formatos recomendados: PNG o SVG con fondo transparente.<br>
        Tamaño sugerido: mínimo 400×400 px.
    </p>

    <script>
    (function(){
        var frame;
        var uploadBtn  = document.getElementById('evapp-logo-upload-btn');
        var removeBtn  = document.getElementById('evapp-logo-remove-btn');
        var logoIdInput = document.getElementById('_cliente_logo_id');
        var preview    = document.getElementById('evapp-logo-preview');
        var previewImg = preview.querySelector('img');

        uploadBtn.addEventListener('click', function(e){
            e.preventDefault();
            if (frame) { frame.open(); return; }
            frame = wp.media({
                title:    'Seleccionar Logo',
                button:   { text: 'Usar este logo' },
                multiple: false,
                library:  { type: 'image' }
            });
            frame.on('select', function(){
                var att = frame.state().get('selection').first().toJSON();
                logoIdInput.value = att.id;
                previewImg.src    = att.url;
                preview.style.display = 'block';
                removeBtn.style.display = 'inline-flex';
                uploadBtn.childNodes[1].textContent = ' Cambiar Logo';
            });
            frame.open();
        });

        removeBtn.addEventListener('click', function(e){
            e.preventDefault();
            logoIdInput.value = '';
            previewImg.src    = '';
            preview.style.display = 'none';
            removeBtn.style.display = 'none';
            uploadBtn.childNodes[1].textContent = ' Subir Logo';
        });
    })();
    </script>
    <?php
}

// ============================================================
// 5. ENCOLAR wp.media SOLO EN EDICIÓN DE ESTE CPT
// ============================================================

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'eventosapp_cliente' ) return;

    wp_enqueue_media();
} );

// ============================================================
// 6. COLUMNAS PERSONALIZADAS EN EL LISTADO DEL CPT
// ============================================================

add_filter( 'manage_eventosapp_cliente_posts_columns', function ( $cols ) {
    $new = [];
    $new['cb']                    = $cols['cb'];
    $new['evapp_logo']            = 'Logo';
    $new['title']                 = 'Empresa';
    $new['evapp_nit']             = 'NIT';
    $new['evapp_ciudad']          = 'Ciudad';
    $new['evapp_departamento']    = 'Departamento';
    $new['evapp_telefono']        = 'Teléfono';
    $new['evapp_email']           = 'Correo';
    $new['date']                  = $cols['date'];
    return $new;
} );

add_action( 'manage_eventosapp_cliente_posts_custom_column', function ( $column, $post_id ) {
    switch ( $column ) {

        case 'evapp_logo':
            $logo_id = (int) get_post_meta( $post_id, '_cliente_logo_id', true );
            if ( $logo_id ) {
                $url = wp_get_attachment_image_url( $logo_id, [ 48, 48 ] );
                if ( $url ) {
                    echo '<img src="' . esc_url( $url ) . '" style="width:48px;height:48px;object-fit:contain;border-radius:4px;border:1px solid #e0e0e0;background:#f8f9fa;padding:3px;" />';
                }
            } else {
                echo '<span style="color:#ccc;font-size:20px;" class="dashicons dashicons-building"></span>';
            }
            break;

        case 'evapp_nit':
            echo esc_html( get_post_meta( $post_id, '_cliente_nit', true ) ?: '—' );
            break;

        case 'evapp_ciudad':
            echo esc_html( get_post_meta( $post_id, '_cliente_ciudad', true ) ?: '—' );
            break;

        case 'evapp_departamento':
            echo esc_html( get_post_meta( $post_id, '_cliente_departamento', true ) ?: '—' );
            break;

        case 'evapp_telefono':
            $tel = get_post_meta( $post_id, '_cliente_telefono', true );
            echo $tel ? '<a href="tel:' . esc_attr( $tel ) . '">' . esc_html( $tel ) . '</a>' : '—';
            break;

        case 'evapp_email':
            $mail = get_post_meta( $post_id, '_cliente_email', true );
            echo $mail ? '<a href="mailto:' . esc_attr( $mail ) . '">' . esc_html( $mail ) . '</a>' : '—';
            break;
    }
}, 10, 2 );

// ============================================================
// 7. COLUMNAS ORDENABLES
// ============================================================

add_filter( 'manage_edit-eventosapp_cliente_sortable_columns', function ( $cols ) {
    $cols['title']           = 'title';
    $cols['evapp_nit']       = 'evapp_nit';
    $cols['evapp_ciudad']    = 'evapp_ciudad';
    return $cols;
} );

// ============================================================
// 8. HELPER PÚBLICO: Obtener cliente por ID
//    Uso: $data = eventosapp_get_cliente( $id );
//    Retorna array con todos los campos o false si no existe.
// ============================================================

if ( ! function_exists( 'eventosapp_get_cliente' ) ) {
    function eventosapp_get_cliente( $cliente_id ) {
        $post = get_post( $cliente_id );
        if ( ! $post || $post->post_type !== 'eventosapp_cliente' ) return false;

        $logo_id = (int) get_post_meta( $cliente_id, '_cliente_logo_id', true );

        return [
            'id'             => $cliente_id,
            'nombre_empresa' => get_post_meta( $cliente_id, '_cliente_nombre_empresa', true ),
            'razon_social'   => get_post_meta( $cliente_id, '_cliente_razon_social',   true ),
            'nit'            => get_post_meta( $cliente_id, '_cliente_nit',            true ),
            'ciudad'         => get_post_meta( $cliente_id, '_cliente_ciudad',         true ),
            'departamento'   => get_post_meta( $cliente_id, '_cliente_departamento',   true ),
            'direccion'      => get_post_meta( $cliente_id, '_cliente_direccion',      true ),
            'codigo_postal'  => get_post_meta( $cliente_id, '_cliente_codigo_postal',  true ),
            'telefono'       => get_post_meta( $cliente_id, '_cliente_telefono',       true ),
            'email'          => get_post_meta( $cliente_id, '_cliente_email',          true ),
            'logo_id'        => $logo_id,
            'logo_url'       => $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '',
            'logo_url_thumb' => $logo_id ? wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '',
        ];
    }
}

// ============================================================
// 9. HELPER PÚBLICO: Buscar cliente por NIT
//    Uso: $cliente_id = eventosapp_find_cliente_by_nit( '900.123.456-7' );
// ============================================================

if ( ! function_exists( 'eventosapp_find_cliente_by_nit' ) ) {
    function eventosapp_find_cliente_by_nit( $nit ) {
        if ( ! $nit ) return false;
        $q = new WP_Query( [
            'post_type'      => 'eventosapp_cliente',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'     => '_cliente_nit',
                    'value'   => sanitize_text_field( $nit ),
                    'compare' => '=',
                ],
            ],
            'fields'         => 'ids',
        ] );
        return ! empty( $q->posts ) ? (int) $q->posts[0] : false;
    }
}
