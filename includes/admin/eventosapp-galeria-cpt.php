<?php
/**
 * CPT: eventosapp_galeria
 * Galería de fotos de eventos para EventosApp.
 *
 * Campos:
 *   _galeria_evento_id    → ID del Evento asociado
 *   _galeria_fotos        → JSON array de Attachment IDs
 *   _galeria_descripcion  → Descripción de la galería
 *
 * Shortcode: [eventosapp_galeria id="POST_ID"]
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// 1. REGISTRO DEL CPT
// ============================================================

add_action( 'init', function () {
    register_post_type( 'eventosapp_galeria', [
        'labels' => [
            'name'               => 'Galerías',
            'singular_name'      => 'Galería',
            'add_new'            => 'Agregar Nueva',
            'add_new_item'       => 'Agregar Nueva Galería',
            'edit_item'          => 'Editar Galería',
            'new_item'           => 'Nueva Galería',
            'view_item'          => 'Ver Galería',
            'search_items'       => 'Buscar Galerías',
            'not_found'          => 'No se encontraron galerías',
            'not_found_in_trash' => 'No se encontraron galerías en la papelera',
            'menu_name'          => 'Galerías',
            'all_items'          => 'Todas las Galerías',
        ],
        'public'              => false,
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'show_ui'             => true,
        'menu_icon'           => 'dashicons-format-gallery',
        'supports'            => [ 'title' ],
        'has_archive'         => false,
        'rewrite'             => false,
        'show_in_rest'        => false,
        'show_in_menu'        => false, // Menú gestionado por eventosapp.php
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
    ] );
} );

// ============================================================
// 2. REGISTRAR METABOXES
// ============================================================

add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'evapp_galeria_datos',
        '📸 Datos de la Galería',
        'evapp_galeria_render_metabox_datos',
        'eventosapp_galeria',
        'normal',
        'high'
    );

    add_meta_box(
        'evapp_galeria_fotos',
        '🖼️ Fotos de la Galería',
        'evapp_galeria_render_metabox_fotos',
        'eventosapp_galeria',
        'normal',
        'default'
    );

    add_meta_box(
        'evapp_galeria_shortcode',
        '🔗 Shortcode',
        'evapp_galeria_render_metabox_shortcode',
        'eventosapp_galeria',
        'side',
        'high'
    );
} );

// ============================================================
// 2.1 RENDER: Datos generales
// ============================================================

function evapp_galeria_render_metabox_datos( $post ) {
    wp_nonce_field( 'evapp_galeria_guardar', '_galeria_nonce' );

    $evento_id    = (int) get_post_meta( $post->ID, '_galeria_evento_id', true );
    $descripcion  = get_post_meta( $post->ID, '_galeria_descripcion', true );

    // Obtener eventos disponibles
    $eventos = get_posts( [
        'post_type'      => 'eventosapp_event',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );
    ?>
    <table class="form-table" style="width:100%;">
        <tr>
            <th style="width:180px;padding:10px 0;"><label for="_galeria_evento_id">Evento Asociado</label></th>
            <td style="padding:10px 0;">
                <select name="_galeria_evento_id" id="_galeria_evento_id" style="width:100%;max-width:400px;">
                    <option value="">— Sin evento vinculado —</option>
                    <?php foreach ( $eventos as $ev ) : ?>
                        <option value="<?php echo esc_attr( $ev->ID ); ?>" <?php selected( $evento_id, $ev->ID ); ?>>
                            <?php echo esc_html( $ev->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Vincula esta galería a un evento (opcional).</p>
            </td>
        </tr>
        <tr>
            <th style="padding:10px 0;"><label for="_galeria_descripcion">Descripción</label></th>
            <td style="padding:10px 0;">
                <textarea name="_galeria_descripcion" id="_galeria_descripcion"
                          rows="3" style="width:100%;max-width:600px;"
                          placeholder="Descripción o contexto de esta galería..."><?php echo esc_textarea( $descripcion ); ?></textarea>
            </td>
        </tr>
    </table>
    <?php
}

// ============================================================
// 2.2 RENDER: Fotos (uploader múltiple + reordenable)
// ============================================================

function evapp_galeria_render_metabox_fotos( $post ) {
    $fotos_raw = get_post_meta( $post->ID, '_galeria_fotos', true );
    $fotos_ids = [];

    if ( $fotos_raw ) {
        $decoded = json_decode( $fotos_raw, true );
        if ( is_array( $decoded ) ) {
            $fotos_ids = array_map( 'absint', $decoded );
        }
    }
    ?>
    <div id="evapp-galeria-container">

        <p style="margin-bottom:12px;">
            <button type="button" id="evapp-galeria-add-btn" class="button button-primary">
                ➕ Agregar Fotos
            </button>
            <span style="color:#666;margin-left:10px;font-size:12px;">Puedes seleccionar varias fotos a la vez. Arrastra para reordenar.</span>
        </p>

        <ul id="evapp-galeria-sortable" style="
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            list-style:none;
            margin:0;
            padding:0;
            min-height:80px;
            border:2px dashed #ddd;
            border-radius:6px;
            padding:12px;
        ">
            <?php foreach ( $fotos_ids as $att_id ) :
                $thumb = wp_get_attachment_image_url( $att_id, [ 100, 100 ] );
                if ( ! $thumb ) continue;
                ?>
                <li class="evapp-galeria-item" data-id="<?php echo esc_attr( $att_id ); ?>" style="
                    position:relative;
                    cursor:grab;
                    border-radius:4px;
                    overflow:hidden;
                    border:2px solid #e0e0e0;
                    width:100px;
                    height:100px;
                    background:#f5f5f5;
                ">
                    <img src="<?php echo esc_url( $thumb ); ?>" style="width:100px;height:100px;object-fit:cover;display:block;" />
                    <button type="button" class="evapp-galeria-remove" title="Eliminar" style="
                        position:absolute;top:2px;right:2px;
                        background:rgba(0,0,0,0.6);
                        color:#fff;border:none;
                        border-radius:50%;
                        width:22px;height:22px;
                        font-size:14px;line-height:1;
                        cursor:pointer;padding:0;
                    ">×</button>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ( empty( $fotos_ids ) ) : ?>
            <p id="evapp-galeria-empty" style="color:#999;margin-top:8px;display:block;">No hay fotos aún. Haz clic en "Agregar Fotos".</p>
        <?php else : ?>
            <p id="evapp-galeria-empty" style="color:#999;margin-top:8px;display:none;">No hay fotos aún. Haz clic en "Agregar Fotos".</p>
        <?php endif; ?>

    </div>

    <!-- Campo oculto con los IDs serializados como JSON -->
    <input type="hidden" name="_galeria_fotos" id="_galeria_fotos_input"
           value="<?php echo esc_attr( $fotos_raw ?: '[]' ); ?>" />

    <style>
    .evapp-galeria-placeholder {
        width:100px; height:100px;
        background:#f0f0f0;
        border:2px dashed #aaa;
        border-radius:4px;
        display:inline-block;
    }
    .evapp-galeria-item:hover { border-color:#0073aa !important; }
    </style>
    <?php
}

// ============================================================
// 2.3 RENDER: Shortcode info
// ============================================================

function evapp_galeria_render_metabox_shortcode( $post ) {
    if ( $post->post_status === 'auto-draft' || ! $post->ID ) {
        echo '<p style="color:#888;">Guarda la galería para obtener el shortcode.</p>';
        return;
    }
    $shortcode = '[eventosapp_galeria id="' . $post->ID . '"]';
    ?>
    <p style="margin-bottom:8px;font-size:12px;color:#555;">
        Copia y pega este shortcode en cualquier página o entrada:
    </p>
    <code id="evapp-galeria-sc" style="
        display:block;
        background:#f6f7f7;
        border:1px solid #ddd;
        border-radius:4px;
        padding:8px 10px;
        font-size:13px;
        user-select:all;
        word-break:break-all;
    "><?php echo esc_html( $shortcode ); ?></code>
    <button type="button" id="evapp-sc-copy-btn" class="button" style="margin-top:8px;width:100%;">
        📋 Copiar Shortcode
    </button>
    <script>
    document.getElementById('evapp-sc-copy-btn').addEventListener('click', function(){
        var text = document.getElementById('evapp-galeria-sc').innerText;
        navigator.clipboard.writeText(text).then(function(){
            document.getElementById('evapp-sc-copy-btn').textContent = '✅ ¡Copiado!';
            setTimeout(function(){
                document.getElementById('evapp-sc-copy-btn').textContent = '📋 Copiar Shortcode';
            }, 2000);
        });
    });
    </script>
    <?php
}

// ============================================================
// 3. GUARDAR CAMPOS
// ============================================================

add_action( 'save_post_eventosapp_galeria', function ( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! isset( $_POST['_galeria_nonce'] ) || ! wp_verify_nonce( $_POST['_galeria_nonce'], 'evapp_galeria_guardar' ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    // Evento asociado
    $evento_id = absint( $_POST['_galeria_evento_id'] ?? 0 );
    update_post_meta( $post_id, '_galeria_evento_id', $evento_id );

    // Descripción
    $descripcion = sanitize_textarea_field( $_POST['_galeria_descripcion'] ?? '' );
    update_post_meta( $post_id, '_galeria_descripcion', $descripcion );

    // Fotos: JSON array de IDs
    $fotos_raw = wp_unslash( $_POST['_galeria_fotos'] ?? '[]' );
    $fotos_arr = json_decode( $fotos_raw, true );

    if ( ! is_array( $fotos_arr ) ) {
        $fotos_arr = [];
    }

    // Sanitizar: solo integers positivos
    $fotos_arr = array_values( array_filter( array_map( 'absint', $fotos_arr ) ) );
    update_post_meta( $post_id, '_galeria_fotos', wp_json_encode( $fotos_arr ) );

} , 20 );

// ============================================================
// 4. ENCOLAR wp.media + jQuery UI Sortable EN ADMIN
//    El JS del uploader se inyecta en admin_footer para garantizar
//    que wp.media y jquery-ui-sortable ya estén disponibles.
// ============================================================

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'eventosapp_galeria' ) return;

    wp_enqueue_media();
    wp_enqueue_script( 'jquery-ui-sortable' );
} );

add_action( 'admin_footer', function () {
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'eventosapp_galeria' ) return;
    if ( ! in_array( $screen->base, [ 'post' ], true ) ) return;
    ?>
    <script>
    jQuery(document).ready(function($){

        var frame;
        var $sortable  = $('#evapp-galeria-sortable');
        var $input     = $('#_galeria_fotos_input');
        var $emptyMsg  = $('#evapp-galeria-empty');

        if ( ! $sortable.length ) return; // Salir si el metabox no está en pantalla

        // ── Inicializar jQuery UI Sortable ──
        $sortable.sortable({
            items: '.evapp-galeria-item',
            placeholder: 'evapp-galeria-placeholder',
            opacity: 0.7,
            update: function(){ syncIds(); }
        });

        // ── Abrir Media Library ──
        $('#evapp-galeria-add-btn').on('click', function(e){
            e.preventDefault();

            // Si el frame ya existe, reabrirlo directamente
            if ( frame ) {
                frame.open();
                return;
            }

            frame = wp.media({
                title   : 'Seleccionar fotos para la galería',
                button  : { text: 'Agregar a la galería' },
                multiple: true,
                library : { type: 'image' }
            });

            frame.on('select', function(){
                var selection = frame.state().get('selection');
                selection.each(function(attachment){
                    var att   = attachment.toJSON();
                    var thumb = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;

                    // Evitar duplicados
                    if ( $sortable.find('[data-id="' + att.id + '"]').length ) return;

                    var $li = $(
                        '<li class="evapp-galeria-item" style="position:relative;cursor:grab;border-radius:4px;overflow:hidden;border:2px solid #e0e0e0;width:100px;height:100px;background:#f5f5f5;"></li>'
                    );
                    $li.attr('data-id', att.id);
                    $li.append('<img src="' + thumb + '" style="width:100px;height:100px;object-fit:cover;display:block;" />');
                    $li.append(
                        '<button type="button" class="evapp-galeria-remove" title="Eliminar" style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,0.6);color:#fff;border:none;border-radius:50%;width:22px;height:22px;font-size:14px;line-height:1;cursor:pointer;padding:0;">×</button>'
                    );
                    $sortable.append($li);
                });

                $sortable.sortable('refresh');
                syncIds();
                toggleEmpty();
            });

            frame.open();
        });

        // ── Eliminar foto ──
        $sortable.on('click', '.evapp-galeria-remove', function(){
            $(this).closest('.evapp-galeria-item').remove();
            syncIds();
            toggleEmpty();
        });

        // ── Sincronizar IDs al campo oculto ──
        function syncIds(){
            var ids = [];
            $sortable.find('.evapp-galeria-item').each(function(){
                ids.push( parseInt( $(this).data('id'), 10 ) );
            });
            $input.val( JSON.stringify(ids) );
        }

        // ── Mostrar/ocultar mensaje vacío ──
        function toggleEmpty(){
            var count = $sortable.find('.evapp-galeria-item').length;
            $emptyMsg.toggle( count === 0 );
        }

        // Sincronizar al cargar
        syncIds();
        toggleEmpty();

    });
    </script>
    <?php
} );

// ============================================================
// 5. COLUMNAS PERSONALIZADAS EN EL LISTADO
// ============================================================

add_filter( 'manage_eventosapp_galeria_posts_columns', function ( $cols ) {
    $new = [];
    $new['cb']             = $cols['cb'];
    $new['title']          = 'Nombre de la Galería';
    $new['evapp_g_evento'] = 'Evento';
    $new['evapp_g_fotos']  = 'Fotos';
    $new['evapp_g_sc']     = 'Shortcode';
    $new['date']           = $cols['date'];
    return $new;
} );

add_action( 'manage_eventosapp_galeria_posts_custom_column', function ( $column, $post_id ) {
    switch ( $column ) {

        case 'evapp_g_evento':
            $evento_id = (int) get_post_meta( $post_id, '_galeria_evento_id', true );
            if ( $evento_id ) {
                $evento = get_post( $evento_id );
                if ( $evento ) {
                    echo '<a href="' . esc_url( get_edit_post_link( $evento_id ) ) . '">' . esc_html( $evento->post_title ) . '</a>';
                } else {
                    echo '—';
                }
            } else {
                echo '<span style="color:#999;">Sin evento</span>';
            }
            break;

        case 'evapp_g_fotos':
            $fotos_raw = get_post_meta( $post_id, '_galeria_fotos', true );
            $fotos_ids = [];
            if ( $fotos_raw ) {
                $decoded = json_decode( $fotos_raw, true );
                if ( is_array( $decoded ) ) {
                    $fotos_ids = $decoded;
                }
            }
            $total = count( $fotos_ids );
            echo '<span style="font-weight:600;color:' . ( $total > 0 ? '#0073aa' : '#999' ) . ';">';
            echo esc_html( $total ) . ' foto' . ( $total !== 1 ? 's' : '' );
            echo '</span>';

            // Miniatura preview de las primeras 3
            if ( $total > 0 ) {
                echo '<div style="margin-top:4px;display:flex;gap:3px;">';
                foreach ( array_slice( $fotos_ids, 0, 3 ) as $att_id ) {
                    $thumb = wp_get_attachment_image_url( $att_id, [ 36, 36 ] );
                    if ( $thumb ) {
                        echo '<img src="' . esc_url( $thumb ) . '" style="width:36px;height:36px;object-fit:cover;border-radius:3px;border:1px solid #ddd;" />';
                    }
                }
                if ( $total > 3 ) {
                    echo '<span style="line-height:36px;font-size:11px;color:#666;padding-left:3px;">+' . esc_html( $total - 3 ) . '</span>';
                }
                echo '</div>';
            }
            break;

        case 'evapp_g_sc':
            $sc = '[eventosapp_galeria id="' . $post_id . '"]';
            echo '<code style="font-size:11px;background:#f6f7f7;padding:2px 5px;border-radius:3px;">' . esc_html( $sc ) . '</code>';
            break;
    }
}, 10, 2 );

// ============================================================
// 6. SHORTCODE: [eventosapp_galeria id="POST_ID"]
// ============================================================
// REEMPLAZA LA SECCIÓN 6 EXISTENTE EN:
//   includes/admin/eventosapp-galeria-cpt.php
// ============================================================

add_shortcode( 'eventosapp_galeria', function ( $atts ) {
    $atts = shortcode_atts( [ 'id' => 0 ], $atts, 'eventosapp_galeria' );
    $galeria_id = absint( $atts['id'] );

    if ( ! $galeria_id ) {
        return '<p style="color:#c00;">⚠️ Shortcode: falta el atributo <code>id</code>.</p>';
    }

    $post = get_post( $galeria_id );
    if ( ! $post || $post->post_type !== 'eventosapp_galeria' ) {
        return '<p style="color:#c00;">⚠️ Galería no encontrada (ID: ' . esc_html( $galeria_id ) . ').</p>';
    }

    // Obtener fotos
    $fotos_raw = get_post_meta( $galeria_id, '_galeria_fotos', true );
    $fotos_ids = [];

    if ( $fotos_raw ) {
        $decoded = json_decode( $fotos_raw, true );
        if ( is_array( $decoded ) ) {
            $fotos_ids = array_filter( array_map( 'absint', $decoded ) );
        }
    }

    if ( empty( $fotos_ids ) ) {
        return '<p style="color:#888;font-style:italic;">Esta galería aún no tiene fotos.</p>';
    }

    $descripcion = get_post_meta( $galeria_id, '_galeria_descripcion', true );
    $evento_id   = (int) get_post_meta( $galeria_id, '_galeria_evento_id', true );
    $uid         = 'evapp-galeria-' . $galeria_id;

    // ── Datos del evento y cliente para el header informativo ────────────
    $header_titulo       = $post->post_title; // título de la galería
    $header_fecha_dia    = '';
    $header_fecha_str    = '';
    $header_lugar        = '';
    $header_cliente_nombre   = '';
    $header_cliente_logo_url = '';

    if ( $evento_id ) {
        // Fecha del evento
        $tipo_fecha = get_post_meta( $evento_id, '_eventosapp_tipo_fecha', true ) ?: 'unica';

        if ( $tipo_fecha === 'unica' ) {
            $fecha_raw = get_post_meta( $evento_id, '_eventosapp_fecha_unica', true );
            if ( $fecha_raw ) {
                $ts                = strtotime( $fecha_raw );
                $header_fecha_dia  = date_i18n( 'l', $ts );   // ej: "domingo"
                $header_fecha_str  = date_i18n( 'd.m.y', $ts ); // ej: "21.12.25"
            }
        } elseif ( $tipo_fecha === 'consecutiva' ) {
            $fi = get_post_meta( $evento_id, '_eventosapp_fecha_inicio', true );
            $ff = get_post_meta( $evento_id, '_eventosapp_fecha_fin',    true );
            if ( $fi ) {
                $ts               = strtotime( $fi );
                $header_fecha_dia = date_i18n( 'l', $ts );
                $header_fecha_str = date_i18n( 'd.m.y', $ts );
                if ( $ff ) {
                    $header_fecha_str .= ' – ' . date_i18n( 'd.m.y', strtotime( $ff ) );
                }
            }
        } else {
            // fechas no consecutivas
            $fnoco = get_post_meta( $evento_id, '_eventosapp_fechas_noco', true );
            if ( is_array( $fnoco ) && ! empty( $fnoco ) ) {
                $ts               = strtotime( $fnoco[0] );
                $header_fecha_dia = date_i18n( 'l', $ts );
                $header_fecha_str = date_i18n( 'd.m.y', $ts );
                if ( count( $fnoco ) > 1 ) {
                    $header_fecha_str .= ' (+' . ( count( $fnoco ) - 1 ) . ')';
                }
            }
        }

        // Lugar del evento
        $ev_ciudad = get_post_meta( $evento_id, '_eventosapp_ciudad',       true );
        $ev_depto  = get_post_meta( $evento_id, '_eventosapp_departamento', true );
        if ( $ev_ciudad && $ev_depto ) {
            $header_lugar = $ev_ciudad . ', ' . $ev_depto;
        } elseif ( $ev_ciudad ) {
            $header_lugar = $ev_ciudad;
        } elseif ( $ev_depto ) {
            $header_lugar = $ev_depto;
        }

        // Cliente dueño del evento
        $cliente_id = (int) get_post_meta( $evento_id, '_eventosapp_cliente_id', true );
        if ( $cliente_id ) {
            $cli_nombre  = get_post_meta( $cliente_id, '_cliente_nombre_empresa', true );
            $cli_logo_id = (int) get_post_meta( $cliente_id, '_cliente_logo_id', true );
            if ( $cli_nombre ) {
                $header_cliente_nombre = $cli_nombre;
            }
            if ( $cli_logo_id ) {
                $header_cliente_logo_url = wp_get_attachment_image_url( $cli_logo_id, [ 48, 48 ] );
            }
        }
    }
    // ── Fin datos header ─────────────────────────────────────────────────

    // Construir datos de imágenes
    $imagenes = [];
    foreach ( $fotos_ids as $att_id ) {
        $full    = wp_get_attachment_image_url( $att_id, 'large' );
        $thumb   = wp_get_attachment_image_url( $att_id, 'thumbnail' );
        $alt     = get_post_meta( $att_id, '_wp_attachment_image_alt', true );
        $caption = wp_get_attachment_caption( $att_id );

        if ( ! $full ) continue;

        $imagenes[] = [
            'full'    => $full,
            'thumb'   => $thumb ?: $full,
            'alt'     => $alt ?: get_the_title( $galeria_id ),
            'caption' => $caption,
        ];
    }

    if ( empty( $imagenes ) ) {
        return '<p style="color:#888;font-style:italic;">No se pudieron cargar las imágenes.</p>';
    }

    $total = count( $imagenes );

    // Nonces para el wizard IA (sólo si hay evento vinculado)
    $nonce_buscar   = $evento_id ? wp_create_nonce( 'evapp_gi_buscar_ticket' )  : '';
    $nonce_registro = $evento_id ? wp_create_nonce( 'evapp_gi_registrar_foto' ) : '';

    ob_start();
    if ( $evento_id ) {
        echo '<script src="' . esc_url( EVENTOSAPP_PLUGIN_URL . 'includes/assets/js/face-api.min.js' ) . '"></script>' . "\n";
    }
    ?>
    <div id="<?php echo esc_attr( $uid ); ?>" class="evapp-galeria-wrap">

        <!-- ── Header informativo de la galería ── -->
        <div class="evapp-galeria-header">
            <div class="evapp-galeria-header-top">
                <h2 class="evapp-galeria-header-title"><?php echo esc_html( $header_titulo ); ?></h2>
            </div>
            <div class="evapp-galeria-header-meta">
                <?php if ( $header_fecha_dia || $header_fecha_str ) : ?>
                    <span class="evapp-gh-meta-item">
                        <span class="evapp-gh-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </span>
                        <?php
                        if ( $header_fecha_dia && $header_fecha_str ) {
                            echo esc_html( $header_fecha_dia ) . ' &ndash; ' . esc_html( $header_fecha_str );
                        } elseif ( $header_fecha_str ) {
                            echo esc_html( $header_fecha_str );
                        }
                        ?>
                    </span>
                <?php endif; ?>

                <?php if ( $header_lugar ) : ?>
                    <span class="evapp-gh-meta-item">
                        <span class="evapp-gh-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        </span>
                        <?php echo esc_html( $header_lugar ); ?>
                    </span>
                <?php endif; ?>

                <span class="evapp-gh-meta-item">
                    <span class="evapp-gh-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    </span>
                    <?php echo esc_html( number_format_i18n( $total ) ); ?> foto<?php echo $total !== 1 ? 's' : ''; ?>
                </span>

                <?php if ( $header_cliente_nombre ) : ?>
                    <span class="evapp-gh-meta-item evapp-gh-cliente">
                        <span class="evapp-gh-hash">#</span>
                        <span class="evapp-gh-evento-por">Evento por</span>
                        <?php if ( $header_cliente_logo_url ) : ?>
                            <img src="<?php echo esc_url( $header_cliente_logo_url ); ?>"
                                 alt="<?php echo esc_attr( $header_cliente_nombre ); ?>"
                                 class="evapp-gh-cliente-logo" />
                        <?php endif; ?>
                        <strong class="evapp-gh-cliente-nombre"><?php echo esc_html( $header_cliente_nombre ); ?></strong>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <!-- ── Fin header ── -->

        <?php if ( $descripcion ) : ?>
            <p class="evapp-galeria-descripcion"><?php echo esc_html( $descripcion ); ?></p>
        <?php endif; ?>

        <!-- ── Área principal ── -->
        <div class="evapp-galeria-main-wrap">

            <!-- Botón anterior -->
            <button class="evapp-galeria-nav evapp-galeria-prev" aria-label="Anterior" title="Anterior">
                &#8249;
            </button>

            <!-- Slides -->
            <div class="evapp-galeria-slides-wrap">
                <?php foreach ( $imagenes as $i => $img ) : ?>
                    <div class="evapp-galeria-slide<?php echo $i === 0 ? ' active' : ''; ?>"
                         data-index="<?php echo esc_attr( $i ); ?>">
                        <a class="evapp-galeria-lightbox-trigger"
                           href="<?php echo esc_url( $img['full'] ); ?>"
                           data-index="<?php echo esc_attr( $i ); ?>">
                            <img src="<?php echo esc_url( $img['full'] ); ?>"
                                 alt="<?php echo esc_attr( $img['alt'] ); ?>"
                                 loading="<?php echo $i === 0 ? 'eager' : 'lazy'; ?>" />
                        </a>
                        <?php if ( $img['caption'] ) : ?>
                            <p class="evapp-galeria-caption"><?php echo esc_html( $img['caption'] ); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Botón siguiente -->
            <button class="evapp-galeria-nav evapp-galeria-next" aria-label="Siguiente" title="Siguiente">
                &#8250;
            </button>

        </div><!-- .evapp-galeria-main-wrap -->

        <!-- ── Contador ── -->
        <div class="evapp-galeria-counter">
            <span class="evapp-galeria-current">1</span> / <span class="evapp-galeria-total"><?php echo esc_html( $total ); ?></span>
        </div>

        <!-- ── Miniaturas ── -->
        <div class="evapp-galeria-thumbs-wrap">
            <?php foreach ( $imagenes as $i => $img ) : ?>
                <button class="evapp-galeria-thumb<?php echo $i === 0 ? ' active' : ''; ?>"
                        data-index="<?php echo esc_attr( $i ); ?>"
                        aria-label="Ir a foto <?php echo esc_attr( $i + 1 ); ?>">
                    <img src="<?php echo esc_url( $img['thumb'] ); ?>"
                         alt="<?php echo esc_attr( $img['alt'] ); ?>"
                         loading="lazy" />
                </button>
            <?php endforeach; ?>
        </div>

        <!-- ── Lightbox overlay ── -->
        <div class="evapp-galeria-lightbox" aria-modal="true" role="dialog" style="display:none;">
            <button class="evapp-galeria-lb-close" aria-label="Cerrar">×</button>
            <button class="evapp-galeria-lb-prev" aria-label="Anterior">&#8249;</button>
            <div class="evapp-galeria-lb-img-wrap">
                <img src="" alt="" class="evapp-galeria-lb-img" />
                <p class="evapp-galeria-lb-caption"></p>
            </div>
            <button class="evapp-galeria-lb-next" aria-label="Siguiente">&#8250;</button>
        </div>

        <?php if ( $evento_id ) : ?>
        <!-- ================================================================
             WIZARD IA – BUSCADOR DE FOTOS POR RECONOCIMIENTO FACIAL
        ================================================================ -->
        <div class="evapp-gi-finder-section" id="<?php echo esc_attr( $uid ); ?>-finder">

            <!-- CTA inicial -->
            <div class="evapp-gi-trigger-wrap" id="<?php echo esc_attr( $uid ); ?>-trigger">
                <p class="evapp-gi-promo-text">
                    ¿Quieres ver las fotos en donde apareces?<br>
                    <strong>Deja que la Inteligencia Artificial lo haga por ti.</strong>
                </p>
                <button type="button" class="evapp-gi-btn-abrir" id="<?php echo esc_attr( $uid ); ?>-btn-abrir">
                    🔍 &nbsp;Buscar
                </button>
            </div>

            <!-- WIZARD CONTAINER -->
            <div class="evapp-gi-wizard" id="<?php echo esc_attr( $uid ); ?>-wizard" style="display:none;" aria-live="polite">

                <!-- ── PASO 1: Validar identidad ── -->
                <div class="evapp-gi-step evapp-gi-step-1" data-step="1">
                    <div class="evapp-gi-step-header">
                        <span class="evapp-gi-badge">Paso 1 de 3</span>
                        <h3 class="evapp-gi-step-title">Valida tu identidad</h3>
                    </div>
                    <p class="evapp-gi-step-desc">
                        Necesitamos validar que eres asistente del evento, por favor ingresa los siguientes datos para continuar.
                    </p>
                    <div class="evapp-gi-field-wrap">
                        <label class="evapp-gi-label" for="<?php echo esc_attr($uid); ?>-cedula">Número de Identificación</label>
                        <input type="text" id="<?php echo esc_attr($uid); ?>-cedula"
                               class="evapp-gi-input evapp-gi-cedula"
                               placeholder="Ej: 1234567890" autocomplete="off" inputmode="text" />
                    </div>
                    <div class="evapp-gi-field-wrap">
                        <label class="evapp-gi-label" for="<?php echo esc_attr($uid); ?>-apellidos">Apellidos</label>
                        <input type="text" id="<?php echo esc_attr($uid); ?>-apellidos"
                               class="evapp-gi-input evapp-gi-apellidos"
                               placeholder="Ej: García López" autocomplete="off" />
                    </div>
                    <p class="evapp-gi-hint-text">✏️ Escribe tus datos tal cual como en tu inscripción al evento.</p>
                    <div class="evapp-gi-msg evapp-gi-msg-1" role="alert" style="display:none;"></div>
                    <button type="button" class="evapp-gi-btn-primary evapp-gi-btn-validar">Continuar &rarr;</button>
                </div>

                <!-- ── PASO 2: Confirmación de identidad ── -->
                <div class="evapp-gi-step evapp-gi-step-2" data-step="2" style="display:none;">
                    <div class="evapp-gi-step-header">
                        <span class="evapp-gi-badge evapp-gi-badge-ok">✓ Verificado</span>
                        <h3 class="evapp-gi-step-title">¡Te encontramos!</h3>
                    </div>
                    <div class="evapp-gi-asistente-card"><!-- Se llena desde JS --></div>
                    <button type="button" class="evapp-gi-btn-primary evapp-gi-btn-ir-paso3">
                        Continuar &rarr; Capturar Foto
                    </button>
                </div>

                <!-- ── PASO 3: Captura de fotos (multi) ── -->
                <div class="evapp-gi-step evapp-gi-step-3" data-step="3" style="display:none;">
                    <div class="evapp-gi-step-header">
                        <span class="evapp-gi-badge">Paso 2 de 3</span>
                        <h3 class="evapp-gi-step-title">Captura tus fotos</h3>
                    </div>
                    <p class="evapp-gi-step-desc">
                        Cuantas más fotos agregues con diferentes características, mejor será la detección. Puedes agregar hasta 3.
                    </p>
                    <!-- Tips -->
                    <div class="evapp-gi-foto-tips">
                        <div class="evapp-gi-tip-item"><span class="evapp-gi-tip-icon">😊</span><span>De frente, sin accesorios</span></div>
                        <div class="evapp-gi-tip-item"><span class="evapp-gi-tip-icon">🕶️</span><span>Con gafas o sombrero si los usas</span></div>
                        <div class="evapp-gi-tip-item"><span class="evapp-gi-tip-icon">↗️</span><span>Leve ángulo lateral</span></div>
                    </div>
                    <!-- Tira de fotos -->
                    <div class="evapp-gi-foto-strip"><!-- Se llena desde JS --></div>
                    <p class="evapp-gi-strip-status">Aún no has agregado ninguna foto. Agrega al menos una para continuar.</p>
                    <!-- Mensaje error -->
                    <div class="evapp-gi-msg evapp-gi-msg-step3" role="alert" style="display:none;"></div>
                    <!-- Opciones de captura -->
                    <div class="evapp-gi-foto-opciones evapp-gi-foto-opciones-main">
                        <button type="button" class="evapp-gi-btn-opcion evapp-gi-btn-subir-foto">
                            <span class="evapp-gi-btn-opcion-icon">📁</span><span>Subir una Foto</span>
                        </button>
                        <button type="button" class="evapp-gi-btn-opcion evapp-gi-btn-abrir-cam">
                            <span class="evapp-gi-btn-opcion-icon">📷</span><span>Tomar Foto</span>
                        </button>
                    </div>
                    <input type="file" class="evapp-gi-file-input" accept="image/*" style="display:none;" />
                    <!-- Preview upload -->
                    <div class="evapp-gi-upload-guide-wrap" style="display:none;">
                        <p class="evapp-gi-guide-instruc">Asegúrate que tu cara quede centrada dentro del óvalo antes de continuar:</p>
                        <div class="evapp-gi-guide-frame">
                            <img class="evapp-gi-upload-preview-img" src="" alt="Vista previa de tu foto" />
                            <div class="evapp-gi-oval-overlay"><div class="evapp-gi-oval-ring"></div></div>
                        </div>
                        <div class="evapp-gi-guide-actions">
                            <button type="button" class="evapp-gi-btn-primary evapp-gi-btn-aprobar-upload">✓ &nbsp;La foto se ve bien, continuar</button>
                            <button type="button" class="evapp-gi-btn-secondary evapp-gi-btn-elegir-otra">↩ &nbsp;Elegir otra foto</button>
                        </div>
                    </div>
                    <!-- Cámara -->
                    <div class="evapp-gi-cam-wrap" style="display:none;">
                        <div class="evapp-gi-cam-view-frame">
                            <video class="evapp-gi-video" autoplay playsinline muted></video>
                            <div class="evapp-gi-oval-overlay evapp-gi-oval-cam">
                                <div class="evapp-gi-oval-ring"></div>
                                <p class="evapp-gi-cam-label">Centra tu cara aquí</p>
                            </div>
                        </div>
                        <canvas class="evapp-gi-canvas" style="display:none;"></canvas>
                        <div class="evapp-gi-cam-actions">
                            <button type="button" class="evapp-gi-btn-primary evapp-gi-btn-capturar">📸 &nbsp;Capturar Foto</button>
                            <button type="button" class="evapp-gi-btn-secondary evapp-gi-btn-cancel-cam">Cancelar Cámara</button>
                        </div>
                    </div>
                    <!-- Continuar con fotos -->
                    <div class="evapp-gi-step3-actions" style="display:none;">
                        <button type="button" class="evapp-gi-btn-primary evapp-gi-btn-step3-continuar">
                            ✓ &nbsp;Continuar con <span class="evapp-gi-fotos-count">0</span> foto(s)
                        </button>
                    </div>
                </div>

                <!-- ── PASO 4: Aprobar foto ── -->
                <div class="evapp-gi-step evapp-gi-step-4" data-step="4" style="display:none;">
                    <div class="evapp-gi-step-header">
                        <span class="evapp-gi-badge">Paso 3 de 3</span>
                        <h3 class="evapp-gi-step-title">¿La foto se ve bien?</h3>
                    </div>
                    <p class="evapp-gi-step-desc">Revisa que tu cara se vea con claridad antes de continuar.</p>
                    <div class="evapp-gi-preview-circular-wrap">
                        <img class="evapp-gi-preview-final-img" src="" alt="Tu foto" />
                    </div>
                    <div class="evapp-gi-msg evapp-gi-msg-4" role="alert" style="display:none;"></div>
                    <div class="evapp-gi-paso4-actions">
                        <button type="button" class="evapp-gi-btn-primary evapp-gi-btn-confirmar-foto">✓ &nbsp;Sí, agregar esta foto</button>
                        <button type="button" class="evapp-gi-btn-secondary evapp-gi-btn-retomar-cam">↩ &nbsp;Tomar otra foto</button>
                    </div>
                </div>

                <!-- ── CARGANDO ── -->
                <div class="evapp-gi-step evapp-gi-step-loading" data-step="loading" style="display:none;">
                    <div class="evapp-gi-loading-wrap">
                        <div class="evapp-gi-spinner"></div>
                        <h3 class="evapp-gi-loading-title">Procesando tus fotos...</h3>
                        <p class="evapp-gi-loading-desc">Estamos registrando tu información, por favor espera.</p>
                    </div>
                </div>

                <!-- ── ÉXITO ── -->
                <div class="evapp-gi-step evapp-gi-step-success" data-step="success" style="display:none;">
                    <div class="evapp-gi-success-wrap">
                        <div class="evapp-gi-success-icon">🎉</div>
                        <h3 class="evapp-gi-success-title">¡Ya tenemos todo!</h3>
                        <p class="evapp-gi-success-desc">Vamos a comenzar la búsqueda de tus fotos usando Inteligencia Artificial.</p>
                        <button type="button" class="evapp-gi-btn-primary evapp-gi-btn-continuar">🔍 &nbsp;Buscar mis fotos</button>
                    </div>
                </div>

                <!-- ── BUSCANDO ── -->
                <div class="evapp-gi-step evapp-gi-step-searching" data-step="searching" style="display:none;">
                    <div class="evapp-gi-loading-wrap">
                        <div class="evapp-gi-spinner"></div>
                        <h3 class="evapp-gi-loading-title">Buscando tus fotos con IA...</h3>
                        <p class="evapp-gi-loading-desc" id="<?php echo esc_attr( $uid ); ?>-search-progress">Cargando modelos de reconocimiento facial...</p>
                        <div class="evapp-gi-search-bar-wrap">
                            <div class="evapp-gi-search-bar-inner" id="<?php echo esc_attr( $uid ); ?>-search-bar"></div>
                        </div>
                    </div>
                </div>

                <!-- ── RESULTADOS ── -->
                <div class="evapp-gi-step evapp-gi-step-results" data-step="results" style="display:none;">
                    <div class="evapp-gi-step-header">
                        <span class="evapp-gi-badge evapp-gi-badge-ok">✓ Búsqueda completada</span>
                        <h3 class="evapp-gi-step-title">¡Encontramos tus fotos!</h3>
                    </div>
                    <p class="evapp-gi-results-count"></p>
                    <div class="evapp-gi-results-carousel-wrap"><!-- Se llena desde JS --></div>
                    <button type="button" class="evapp-gi-btn-secondary evapp-gi-btn-nueva-busqueda" style="margin-top:18px;">↩ &nbsp;Volver al inicio</button>
                </div>

                <!-- ── SIN RESULTADOS ── -->
                <div class="evapp-gi-step evapp-gi-step-no-results" data-step="no-results" style="display:none;">
                    <div class="evapp-gi-success-wrap">
                        <div class="evapp-gi-success-icon" style="font-size:52px;">😔</div>
                        <h3 class="evapp-gi-step-title" style="font-size:20px;">No encontramos coincidencias</h3>
                        <p class="evapp-gi-step-desc">
                            No detectamos tu rostro en las fotos de la galería. Puede ser que no hayas sido fotografiado/a aún, o que la foto que usaste no sea muy clara. Intenta con otras fotos de referencia.
                        </p>
                        <button type="button" class="evapp-gi-btn-primary evapp-gi-btn-intentar-otra-foto">📷 &nbsp;Intentar con otras fotos</button>
                        <button type="button" class="evapp-gi-btn-secondary evapp-gi-btn-nueva-busqueda-2" style="margin-top:8px;">↩ &nbsp;Volver al inicio</button>
                    </div>
                </div>

            </div><!-- .evapp-gi-wizard -->
        </div><!-- .evapp-gi-finder-section -->

        <style>
        /* ── Sección contenedor ── */
        .evapp-gi-finder-section { margin-top:36px; padding:32px 28px; background:linear-gradient(145deg,#f0f4ff,#e8eeff); border-radius:14px; border:1px solid #c7d4ff; }
        .evapp-gi-trigger-wrap { text-align:center; }
        .evapp-gi-promo-text { font-size:16px; color:#444; margin:0 0 18px; line-height:1.65; }
        .evapp-gi-promo-text strong { color:#1c3d8f; }
        .evapp-gi-btn-abrir { display:inline-flex; align-items:center; gap:6px; padding:14px 40px; background:#1c3d8f; color:#fff; border:none; border-radius:50px; font-size:16px; font-weight:700; cursor:pointer; transition:background .2s,transform .15s,box-shadow .2s; box-shadow:0 4px 14px rgba(28,61,143,.25); }
        .evapp-gi-btn-abrir:hover { background:#122d6e; transform:translateY(-2px); box-shadow:0 6px 20px rgba(28,61,143,.35); }
        .evapp-gi-wizard { max-width:500px; margin:0 auto; }
        .evapp-gi-step-header { margin-bottom:14px; }
        .evapp-gi-badge { display:inline-block; background:#1c3d8f; color:#fff; font-size:11px; font-weight:700; padding:3px 12px; border-radius:20px; text-transform:uppercase; letter-spacing:.5px; margin-bottom:8px; }
        .evapp-gi-badge-ok { background:#15803d; }
        .evapp-gi-step-title { font-size:22px; font-weight:800; color:#111827; margin:0 0 4px; line-height:1.2; }
        .evapp-gi-step-desc { color:#5a6377; font-size:14px; line-height:1.65; margin-bottom:22px; }
        .evapp-gi-field-wrap { margin-bottom:16px; }
        .evapp-gi-label { display:block; font-size:13px; font-weight:700; color:#2d3748; margin-bottom:6px; }
        .evapp-gi-input { width:100%; padding:12px 15px; border:2px solid #d1dafe; border-radius:9px; font-size:15px; color:#111; background:#fff; transition:border-color .2s,box-shadow .2s; box-sizing:border-box; }
        .evapp-gi-input:focus { outline:none; border-color:#1c3d8f; box-shadow:0 0 0 3px rgba(28,61,143,.12); }
        .evapp-gi-hint-text { font-size:12px; color:#888; margin:0 0 22px; line-height:1.5; }
        .evapp-gi-msg { padding:12px 16px; border-radius:8px; font-size:14px; margin-bottom:14px; font-weight:500; line-height:1.5; }
        .evapp-gi-msg.error { background:#fef2f2; border:1px solid #fca5a5; color:#b91c1c; }
        .evapp-gi-msg.success { background:#f0fdf4; border:1px solid #86efac; color:#15803d; }
        .evapp-gi-btn-primary { display:flex; align-items:center; justify-content:center; width:100%; padding:14px 24px; background:#1c3d8f; color:#fff; border:none; border-radius:9px; font-size:15px; font-weight:700; cursor:pointer; transition:background .2s,opacity .2s; text-align:center; margin-top:10px; box-sizing:border-box; }
        .evapp-gi-btn-primary:hover { background:#122d6e; }
        .evapp-gi-btn-primary:disabled { opacity:.6; cursor:not-allowed; }
        .evapp-gi-btn-secondary { display:flex; align-items:center; justify-content:center; width:100%; padding:12px 24px; background:transparent; color:#555; border:2px solid #d1d5db; border-radius:9px; font-size:14px; font-weight:600; cursor:pointer; transition:border-color .2s,color .2s,background .2s; text-align:center; margin-top:8px; box-sizing:border-box; }
        .evapp-gi-btn-secondary:hover { border-color:#9ca3af; color:#111; background:#f9fafb; }
        /* Tarjeta asistente */
        .evapp-gi-asistente-card { background:#fff; border:1px solid #dde8ff; border-radius:10px; padding:18px 20px; margin-bottom:22px; box-shadow:0 2px 8px rgba(28,61,143,.07); }
        .evapp-gi-as-name { font-size:19px; font-weight:800; color:#111827; margin-bottom:6px; }
        .evapp-gi-as-info { font-size:13px; color:#666; line-height:1.75; }
        /* Tips */
        .evapp-gi-foto-tips { display:flex; gap:8px; margin-bottom:16px; }
        .evapp-gi-tip-item { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; background:#fff; border:1px solid #dde8ff; border-radius:9px; padding:10px 6px; font-size:11px; font-weight:600; color:#444; text-align:center; line-height:1.35; }
        .evapp-gi-tip-icon { font-size:20px; }
        /* Tira de fotos */
        .evapp-gi-foto-strip { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px; }
        .evapp-gi-foto-strip-item { position:relative; width:88px; height:88px; border-radius:10px; overflow:visible; border:3px solid #1c3d8f; box-shadow:0 3px 10px rgba(28,61,143,.18); flex-shrink:0; }
        .evapp-gi-foto-strip-item img { width:100%; height:100%; object-fit:cover; border-radius:8px; display:block; }
        .evapp-gi-strip-label { display:block; text-align:center; font-size:10px; font-weight:700; color:#1c3d8f; margin-top:4px; }
        .evapp-gi-foto-strip-remove { position:absolute; top:-8px; right:-8px; width:22px; height:22px; background:#e74c3c; color:#fff; border:2px solid #fff; border-radius:50%; font-size:13px; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center; padding:0; z-index:2; }
        .evapp-gi-foto-strip-remove:hover { background:#c0392b; }
        .evapp-gi-strip-status { font-size:13px; color:#666; margin:0 0 14px; line-height:1.5; }
        .evapp-gi-strip-status.is-hint { color:#1c3d8f; font-weight:600; }
        .evapp-gi-strip-status.is-ok   { color:#15803d; font-weight:700; }
        .evapp-gi-step3-actions { margin-top:14px; }
        /* Opciones foto */
        .evapp-gi-foto-opciones { display:flex; gap:14px; margin-bottom:24px; }
        .evapp-gi-btn-opcion { flex:1; display:flex; flex-direction:column; align-items:center; gap:10px; padding:22px 14px; background:#fff; border:2px solid #d1dafe; border-radius:12px; font-size:14px; font-weight:700; color:#2d3748; cursor:pointer; transition:border-color .2s,box-shadow .2s,transform .15s; }
        .evapp-gi-btn-opcion:hover { border-color:#1c3d8f; box-shadow:0 4px 14px rgba(28,61,143,.14); transform:translateY(-3px); }
        .evapp-gi-btn-opcion-icon { font-size:30px; }
        /* Guide frame */
        .evapp-gi-guide-frame { position:relative; width:100%; max-width:320px; margin:0 auto 16px; border-radius:12px; overflow:hidden; background:#111; aspect-ratio:3/4; }
        .evapp-gi-upload-preview-img { width:100%; height:100%; object-fit:cover; display:block; }
        .evapp-gi-guide-instruc { font-size:13px; color:#555; margin-bottom:10px; text-align:center; }
        .evapp-gi-oval-overlay { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; flex-direction:column; pointer-events:none; }
        .evapp-gi-oval-ring { width:190px; height:250px; border-radius:50%; border:3px solid rgba(255,255,255,.92); box-shadow:0 0 0 9999px rgba(0,0,0,.48),0 0 0 4px rgba(255,255,255,.18); }
        /* Cámara */
        .evapp-gi-cam-view-frame { position:relative; width:100%; max-width:320px; margin:0 auto 16px; border-radius:12px; overflow:hidden; background:#111; aspect-ratio:3/4; }
        .evapp-gi-video { width:100%; height:100%; object-fit:cover; display:block; transform:scaleX(-1); }
        .evapp-gi-oval-cam { z-index:2; }
        .evapp-gi-cam-label { color:rgba(255,255,255,.92); font-size:13px; font-weight:700; margin-top:14px; text-shadow:0 1px 4px rgba(0,0,0,.7); }
        .evapp-gi-cam-actions { display:flex; flex-direction:column; gap:8px; }
        /* Preview circular */
        .evapp-gi-preview-circular-wrap { width:240px; height:240px; margin:0 auto 22px; border-radius:50%; overflow:hidden; border:5px solid #1c3d8f; box-shadow:0 8px 28px rgba(28,61,143,.22); }
        .evapp-gi-preview-final-img { width:100%; height:100%; object-fit:cover; display:block; }
        .evapp-gi-paso4-actions, .evapp-gi-guide-actions { display:flex; flex-direction:column; gap:8px; }
        /* Cargando */
        .evapp-gi-loading-wrap { text-align:center; padding:44px 20px; }
        .evapp-gi-spinner { width:52px; height:52px; border:5px solid #dde8ff; border-top-color:#1c3d8f; border-radius:50%; animation:evapp-gi-spin .75s linear infinite; margin:0 auto 20px; }
        @keyframes evapp-gi-spin { to { transform:rotate(360deg); } }
        .evapp-gi-loading-title { font-size:18px; font-weight:700; color:#111827; margin:0 0 8px; }
        .evapp-gi-loading-desc { font-size:14px; color:#666; margin:0; }
        /* Éxito */
        .evapp-gi-success-wrap { text-align:center; padding:40px 20px; }
        .evapp-gi-success-icon { font-size:62px; margin-bottom:16px; display:block; animation:evapp-gi-pop .45s cubic-bezier(.34,1.56,.64,1) both; }
        @keyframes evapp-gi-pop { 0%{transform:scale(.4);opacity:0} 100%{transform:scale(1);opacity:1} }
        .evapp-gi-success-title { font-size:24px; font-weight:800; color:#111827; margin:0 0 10px; }
        .evapp-gi-success-desc { font-size:15px; color:#555; margin-bottom:24px; }
        /* Barra progreso búsqueda */
        .evapp-gi-search-bar-wrap { width:100%; background:#dde8ff; border-radius:50px; height:8px; margin:18px auto 0; max-width:320px; overflow:hidden; }
        .evapp-gi-search-bar-inner { height:100%; background:linear-gradient(90deg,#1c3d8f,#4f7cff); border-radius:50px; width:0%; transition:width .4s ease; }
        /* Resultados */
        .evapp-gi-results-count { font-size:15px; font-weight:700; color:#15803d; margin:0 0 16px; text-align:center; }
        .evapp-gi-results-carousel-wrap { position:relative; }
        .evapp-gi-results-slides { background:#111; border-radius:12px; overflow:hidden; min-height:260px; display:flex; align-items:center; justify-content:center; }
        .evapp-gi-result-slide { display:none; width:100%; text-align:center; }
        .evapp-gi-result-slide.active { display:block; }
        .evapp-gi-result-slide img { max-width:100%; max-height:460px; width:auto; height:auto; object-fit:contain; display:block; margin:0 auto; }
        .evapp-gi-results-nav-row { display:flex; align-items:center; justify-content:center; gap:14px; margin-top:10px; }
        .evapp-gi-results-nav-btn { background:#1c3d8f; color:#fff; border:none; border-radius:50%; width:40px; height:40px; font-size:24px; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .2s; flex-shrink:0; }
        .evapp-gi-results-nav-btn:hover { background:#122d6e; }
        .evapp-gi-results-nav-btn:disabled { opacity:.35; cursor:not-allowed; }
        .evapp-gi-results-counter { font-size:13px; color:#555; min-width:60px; text-align:center; }
        .evapp-gi-download-btn { display:flex; align-items:center; justify-content:center; gap:6px; margin:12px auto 0; padding:12px 28px; background:#15803d; color:#fff; border:none; border-radius:9px; font-size:14px; font-weight:700; cursor:pointer; text-decoration:none; transition:background .2s; width:fit-content; max-width:100%; box-sizing:border-box; }
        .evapp-gi-download-btn:hover { background:#166534; color:#fff; text-decoration:none; }
        /* Responsive */
        @media (max-width:500px) {
            .evapp-gi-finder-section { padding:22px 16px; }
            .evapp-gi-step-title { font-size:19px; }
            .evapp-gi-foto-opciones { flex-direction:column; gap:10px; }
            .evapp-gi-btn-opcion { padding:16px 12px; }
            .evapp-gi-preview-circular-wrap { width:200px; height:200px; }
            .evapp-gi-oval-ring { width:160px; height:210px; }
            .evapp-gi-cam-view-frame, .evapp-gi-guide-frame { max-width:100%; }
            .evapp-gi-result-slide img { max-height:320px; }
            .evapp-gi-download-btn { width:100%; }
            .evapp-gi-foto-tips { flex-direction:column; gap:6px; }
            .evapp-gi-tip-item { flex-direction:row; justify-content:flex-start; gap:8px; padding:8px 12px; }
            .evapp-gi-foto-strip-item { width:72px; height:72px; }
        }
        </style>
        <?php endif; // $evento_id — wizard HTML + CSS ?>

    </div><!-- .evapp-galeria-wrap -->

    <?php
    $imagenes_json = wp_json_encode( $imagenes );
    $ajax_url      = admin_url( 'admin-ajax.php' );
    ?>

    <script>
    (function(){
        var uid      = <?php echo wp_json_encode( $uid ); ?>;
        var imagenes = <?php echo $imagenes_json; ?>;
        var total    = imagenes.length;
        var current  = 0;
        var wrap     = document.getElementById(uid);

        if ( ! wrap || ! total ) return;

        var slides     = wrap.querySelectorAll('.evapp-galeria-slide');
        var thumbBtns  = wrap.querySelectorAll('.evapp-galeria-thumb');
        var counterCur = wrap.querySelector('.evapp-galeria-current');
        var lb         = wrap.querySelector('.evapp-galeria-lightbox');
        var lbImg      = wrap.querySelector('.evapp-galeria-lb-img');
        var lbCaption  = wrap.querySelector('.evapp-galeria-lb-caption');
        var lbCurrent  = 0;

        // ── Ir a slide ──
        function goTo( index ) {
            slides[current].classList.remove('active');
            thumbBtns[current].classList.remove('active');
            current = ( index + total ) % total;
            slides[current].classList.add('active');
            thumbBtns[current].classList.add('active');
            counterCur.textContent = current + 1;
            thumbBtns[current].scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }

        wrap.querySelector('.evapp-galeria-prev').addEventListener('click', function(){ goTo( current - 1 ); });
        wrap.querySelector('.evapp-galeria-next').addEventListener('click', function(){ goTo( current + 1 ); });

        thumbBtns.forEach(function(btn){
            btn.addEventListener('click', function(){ goTo( parseInt( btn.dataset.index, 10 ) ); });
        });

        // Swipe táctil (carrusel)
        var touchStartX = 0;
        var slidesWrap  = wrap.querySelector('.evapp-galeria-slides-wrap');
        slidesWrap.addEventListener('touchstart', function(e){ touchStartX = e.changedTouches[0].clientX; }, { passive: true });
        slidesWrap.addEventListener('touchend',   function(e){
            var diff = touchStartX - e.changedTouches[0].clientX;
            if ( Math.abs(diff) > 40 ) goTo( diff > 0 ? current + 1 : current - 1 );
        }, { passive: true });

        // ── Lightbox ──
        function openLightbox(index) {
            lbCurrent = ( index + total ) % total;
            lbImg.src = imagenes[lbCurrent].full;
            lbImg.alt = imagenes[lbCurrent].alt;
            lbCaption.textContent = imagenes[lbCurrent].caption || '';
            lb.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        function closeLightbox() {
            lb.style.display = 'none';
            document.body.style.overflow = '';
            lbImg.src = '';
        }
        function lbGoTo(index) {
            lbCurrent = ( index + total ) % total;
            lbImg.src = imagenes[lbCurrent].full;
            lbImg.alt = imagenes[lbCurrent].alt;
            lbCaption.textContent = imagenes[lbCurrent].caption || '';
        }

        wrap.querySelectorAll('.evapp-galeria-lightbox-trigger').forEach(function(a){
            a.addEventListener('click', function(e){
                e.preventDefault();
                openLightbox( parseInt( a.dataset.index, 10 ) );
            });
        });
        wrap.querySelector('.evapp-galeria-lb-close').addEventListener('click', closeLightbox);
        lb.addEventListener('click', function(e){ if ( e.target === lb ) closeLightbox(); });
        wrap.querySelector('.evapp-galeria-lb-prev').addEventListener('click', function(){ lbGoTo( lbCurrent - 1 ); });
        wrap.querySelector('.evapp-galeria-lb-next').addEventListener('click', function(){ lbGoTo( lbCurrent + 1 ); });

        // Swipe táctil (lightbox)
        var lbTouchStart = 0;
        lb.addEventListener('touchstart', function(e){ lbTouchStart = e.changedTouches[0].clientX; }, { passive: true });
        lb.addEventListener('touchend',   function(e){
            var diff = lbTouchStart - e.changedTouches[0].clientX;
            if ( Math.abs(diff) > 40 ) lbGoTo( diff > 0 ? lbCurrent + 1 : lbCurrent - 1 );
        }, { passive: true });

        // Teclado lightbox
        document.addEventListener('keydown', function(e){
            if ( lb.style.display === 'none' ) return;
            if ( e.key === 'ArrowLeft'  ) lbGoTo( lbCurrent - 1 );
            if ( e.key === 'ArrowRight' ) lbGoTo( lbCurrent + 1 );
            if ( e.key === 'Escape'     ) closeLightbox();
        });

        // ====================================================================
        // WIZARD IA – BUSCADOR DE FOTOS
        // ====================================================================
        <?php if ( $evento_id ) : ?>
        (function(){

            var ajaxUrl       = <?php echo wp_json_encode( $ajax_url ); ?>;
            var galeriaId     = <?php echo wp_json_encode( $galeria_id ); ?>;
            var nonceBuscar   = <?php echo wp_json_encode( $nonce_buscar ); ?>;
            var nonceRegistro = <?php echo wp_json_encode( $nonce_registro ); ?>;

            var finder      = document.getElementById(uid + '-finder');
            var wizard      = document.getElementById(uid + '-wizard');
            var triggerWrap = document.getElementById(uid + '-trigger');
            var btnAbrir    = document.getElementById(uid + '-btn-abrir');

            if ( ! finder || ! wizard ) return;

            // ── Estado del wizard ────────────────────────────────────────────
            var ticketId       = null;
            var cedulaVal      = '';
            var fotoDataUrl    = null;
            var fotosDataUrls  = [];
            var faceDescsQuery = [];
            var camStream      = null;
            var MAX_FOTOS      = 3;

            // ── Helpers generales ────────────────────────────────────────────
            function showStep(cls) {
                wizard.querySelectorAll('.evapp-gi-step').forEach(function(s){ s.style.display = 'none'; });
                var el = wizard.querySelector('.' + cls);
                if ( el ) el.style.display = '';
            }
            function showMsg(el, txt, tipo) {
                el.textContent = txt;
                el.className   = 'evapp-gi-msg ' + ( tipo || 'error' );
                el.style.display = '';
            }
            function hideMsg(el) { if (el) el.style.display = 'none'; }
            function setLoading(btn, lbl) { btn.disabled = true;  btn.textContent = lbl || 'Procesando...'; }
            function setReady(btn, lbl)   { btn.disabled = false; btn.textContent = lbl; }
            function escHtml(str) {
                return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }
            function comprimirImagen(dataUrl, callback) {
                var img = new Image();
                img.onload = function() {
                    var MAX = 900, w = img.naturalWidth, h = img.naturalHeight;
                    if ( w > MAX || h > MAX ) {
                        if ( w > h ) { h = Math.round(h * MAX / w); w = MAX; }
                        else         { w = Math.round(w * MAX / h); h = MAX; }
                    }
                    var cv = document.createElement('canvas');
                    cv.width = w; cv.height = h;
                    cv.getContext('2d').drawImage(img, 0, 0, w, h);
                    callback( cv.toDataURL('image/jpeg', 0.88) );
                };
                img.onerror = function() { callback(dataUrl); };
                img.src = dataUrl;
            }
            function detenerCamara() {
                if ( camStream ) {
                    camStream.getTracks().forEach(function(t){ t.stop(); });
                    camStream = null;
                    var vid = wizard.querySelector('.evapp-gi-video');
                    if (vid) vid.srcObject = null;
                }
            }

            // ── Actualizar tira de fotos (paso 3) ───────────────────────────
            function evappGiActualizarStrip() {
                var strip     = wizard.querySelector('.evapp-gi-foto-strip');
                var statusEl  = wizard.querySelector('.evapp-gi-strip-status');
                var actionsEl = wizard.querySelector('.evapp-gi-step3-actions');
                var countEl   = wizard.querySelector('.evapp-gi-fotos-count');
                var mainOpts  = wizard.querySelector('.evapp-gi-foto-opciones-main');
                if ( ! strip ) return;
                strip.innerHTML = '';
                fotosDataUrls.forEach(function(dataUrl, idx) {
                    var item = document.createElement('div');
                    item.className = 'evapp-gi-foto-strip-item';
                    var img = document.createElement('img');
                    img.src = dataUrl; img.alt = 'Foto ' + (idx + 1);
                    item.appendChild(img);
                    var label = document.createElement('span');
                    label.className = 'evapp-gi-strip-label';
                    label.textContent = 'Foto ' + (idx + 1);
                    item.appendChild(label);
                    var rmBtn = document.createElement('button');
                    rmBtn.type = 'button'; rmBtn.className = 'evapp-gi-foto-strip-remove';
                    rmBtn.innerHTML = '&times;'; rmBtn.setAttribute('data-idx', idx);
                    rmBtn.addEventListener('click', function() {
                        fotosDataUrls.splice( parseInt(this.getAttribute('data-idx'), 10), 1 );
                        evappGiActualizarStrip();
                    });
                    item.appendChild(rmBtn);
                    strip.appendChild(item);
                });
                if ( statusEl ) {
                    if ( fotosDataUrls.length === 0 )      { statusEl.textContent = 'Aún no has agregado ninguna foto. Agrega al menos una para continuar.'; statusEl.className = 'evapp-gi-strip-status'; }
                    else if ( fotosDataUrls.length === 1 ) { statusEl.textContent = '✅ 1 foto agregada. ¡Agrega 1 o 2 más para mejorar los resultados!'; statusEl.className = 'evapp-gi-strip-status is-hint'; }
                    else if ( fotosDataUrls.length === 2 ) { statusEl.textContent = '✅ 2 fotos agregadas. Puedes agregar 1 más o ya continuar.'; statusEl.className = 'evapp-gi-strip-status is-hint'; }
                    else                                    { statusEl.textContent = '✅ 3 fotos agregadas. ¡Perfecto! Ya puedes continuar.'; statusEl.className = 'evapp-gi-strip-status is-ok'; }
                }
                if ( actionsEl ) actionsEl.style.display = fotosDataUrls.length > 0 ? '' : 'none';
                if ( countEl   ) countEl.textContent     = fotosDataUrls.length;
                if ( mainOpts  ) mainOpts.style.display  = fotosDataUrls.length < MAX_FOTOS ? '' : 'none';
            }

            // ── Abrir wizard ──────────────────────────────────────────────────
            if ( btnAbrir ) {
                btnAbrir.addEventListener('click', function(){
                    triggerWrap.style.display = 'none';
                    wizard.style.display      = '';
                    showStep('evapp-gi-step-1');
                });
            }

            // ── PASO 1: Validar ticket ────────────────────────────────────────
            var btnValidar  = wizard.querySelector('.evapp-gi-btn-validar');
            var inputCedula = wizard.querySelector('.evapp-gi-cedula');
            var inputApell  = wizard.querySelector('.evapp-gi-apellidos');
            var msg1        = wizard.querySelector('.evapp-gi-msg-1');

            if ( btnValidar ) {
                btnValidar.addEventListener('click', function(){
                    var cedula    = inputCedula.value.trim();
                    var apellidos = inputApell.value.trim();
                    if ( ! cedula || ! apellidos ) {
                        showMsg( msg1, '⚠️ Por favor ingresa tu número de identificación y tus apellidos.', 'error' );
                        return;
                    }
                    hideMsg( msg1 );
                    setLoading( btnValidar, 'Buscando...' );
                    var fd = new FormData();
                    fd.append('action', 'evapp_galeria_buscar_ticket');
                    fd.append('security', nonceBuscar);
                    fd.append('galeria_id', galeriaId);
                    fd.append('cedula', cedula);
                    fd.append('apellidos', apellidos);
                    fetch( ajaxUrl, { method: 'POST', body: fd } )
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            setReady( btnValidar, 'Continuar →' );
                            if ( res.success ) {
                                ticketId  = res.data.ticket_id;
                                cedulaVal = cedula;
                                var card = wizard.querySelector('.evapp-gi-asistente-card');
                                card.innerHTML =
                                    '<div class="evapp-gi-as-name">' + escHtml(res.data.nombre_completo) + '</div>' +
                                    '<div class="evapp-gi-as-info">' +
                                    ( res.data.empresa ? '🏢 ' + escHtml(res.data.empresa) + '<br>' : '' ) +
                                    ( res.data.cargo   ? '💼 ' + escHtml(res.data.cargo)   + '<br>' : '' ) +
                                    ( res.data.email   ? '✉️ ' + escHtml(res.data.email)            : '' ) +
                                    '</div>';
                                showStep('evapp-gi-step-2');
                            } else {
                                showMsg( msg1, res.data.error || '❌ No encontramos un asistente con esos datos. Intenta de nuevo.', 'error' );
                            }
                        })
                        .catch(function(){
                            setReady( btnValidar, 'Continuar →' );
                            showMsg( msg1, '❌ Error de conexión. Por favor intenta de nuevo.', 'error' );
                        });
                });
                [inputCedula, inputApell].forEach(function(inp){
                    inp.addEventListener('keydown', function(e){ if ( e.key === 'Enter' ) btnValidar.click(); });
                });
            }

            // ── PASO 2 → PASO 3 ──────────────────────────────────────────────
            var btnIrPaso3 = wizard.querySelector('.evapp-gi-btn-ir-paso3');
            if ( btnIrPaso3 ) {
                btnIrPaso3.addEventListener('click', function(){
                    evappGiActualizarStrip();
                    showStep('evapp-gi-step-3');
                });
            }

            // ── PASO 3: Referencias ───────────────────────────────────────────
            var fileInput     = wizard.querySelector('.evapp-gi-file-input');
            var btnSubirFoto  = wizard.querySelector('.evapp-gi-btn-subir-foto');
            var fotoOpciones  = wizard.querySelector('.evapp-gi-foto-opciones-main');
            var uploadGuide   = wizard.querySelector('.evapp-gi-upload-guide-wrap');
            var uploadPreview = wizard.querySelector('.evapp-gi-upload-preview-img');
            var btnAprobarUp  = wizard.querySelector('.evapp-gi-btn-aprobar-upload');
            var btnElegirOtra = wizard.querySelector('.evapp-gi-btn-elegir-otra');
            var btnAbrirCam   = wizard.querySelector('.evapp-gi-btn-abrir-cam');
            var camWrap       = wizard.querySelector('.evapp-gi-cam-wrap');
            var video         = wizard.querySelector('.evapp-gi-video');
            var canvas        = wizard.querySelector('.evapp-gi-canvas');
            var btnCapturar   = wizard.querySelector('.evapp-gi-btn-capturar');
            var btnCancelCam  = wizard.querySelector('.evapp-gi-btn-cancel-cam');
            var msgStep3      = wizard.querySelector('.evapp-gi-msg-step3');

            if ( btnSubirFoto ) {
                btnSubirFoto.addEventListener('click', function(){ fileInput.click(); });
            }
            if ( fileInput ) {
                fileInput.addEventListener('change', function(){
                    var file = fileInput.files[0];
                    if ( ! file ) return;
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        comprimirImagen(e.target.result, function(compressed){
                            fotoDataUrl            = compressed;
                            uploadPreview.src      = compressed;
                            fotoOpciones.style.display = 'none';
                            uploadGuide.style.display  = '';
                        });
                    };
                    reader.readAsDataURL(file);
                });
            }
            if ( btnAprobarUp ) {
                btnAprobarUp.addEventListener('click', function(){
                    var prevImg = wizard.querySelector('.evapp-gi-preview-final-img');
                    prevImg.src = fotoDataUrl;
                    showStep('evapp-gi-step-4');
                });
            }
            if ( btnElegirOtra ) {
                btnElegirOtra.addEventListener('click', function(){
                    fotoDataUrl = null; fileInput.value = '';
                    uploadGuide.style.display  = 'none';
                    fotoOpciones.style.display = fotosDataUrls.length < MAX_FOTOS ? '' : 'none';
                });
            }
            if ( btnAbrirCam ) {
                btnAbrirCam.addEventListener('click', function(){
                    fotoOpciones.style.display = 'none';
                    camWrap.style.display      = '';
                    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 720 }, height: { ideal: 960 } }, audio: false })
                        .then(function(stream){ camStream = stream; video.srcObject = stream; })
                        .catch(function(err){
                            console.error('[EventosApp GaleriaIA] Cámara:', err);
                            camWrap.style.display      = 'none';
                            fotoOpciones.style.display = fotosDataUrls.length < MAX_FOTOS ? '' : 'none';
                            alert('No se pudo acceder a la cámara. Verifica los permisos del navegador, o usa "Subir una Foto".');
                        });
                });
            }
            if ( btnCapturar ) {
                btnCapturar.addEventListener('click', function(){
                    canvas.width  = video.videoWidth  || 720;
                    canvas.height = video.videoHeight || 960;
                    var ctx = canvas.getContext('2d');
                    ctx.translate( canvas.width, 0 ); ctx.scale(-1, 1);
                    ctx.drawImage(video, 0, 0);
                    detenerCamara();
                    camWrap.style.display = 'none';
                    comprimirImagen(canvas.toDataURL('image/jpeg', 0.92), function(compressed){
                        fotoDataUrl = compressed;
                        var prevImg = wizard.querySelector('.evapp-gi-preview-final-img');
                        prevImg.src = fotoDataUrl;
                        showStep('evapp-gi-step-4');
                    });
                });
            }
            if ( btnCancelCam ) {
                btnCancelCam.addEventListener('click', function(){
                    detenerCamara();
                    camWrap.style.display      = 'none';
                    fotoOpciones.style.display = fotosDataUrls.length < MAX_FOTOS ? '' : 'none';
                });
            }

            // Botón "Continuar con X foto(s)" en paso 3
            var btnStep3Continuar = wizard.querySelector('.evapp-gi-btn-step3-continuar');
            if ( btnStep3Continuar ) {
                btnStep3Continuar.addEventListener('click', function(){
                    if ( fotosDataUrls.length === 0 ) return;
                    hideMsg( msgStep3 );
                    showStep('evapp-gi-step-loading');
                    enviarFoto();
                });
            }

            // ── PASO 4: Confirmar foto → agregar al array y volver a paso 3 ──
            var btnConfirmar = wizard.querySelector('.evapp-gi-btn-confirmar-foto');
            var btnRetomar   = wizard.querySelector('.evapp-gi-btn-retomar-cam');
            var msg4         = wizard.querySelector('.evapp-gi-msg-4');

            if ( btnConfirmar ) {
                btnConfirmar.addEventListener('click', function(){
                    if ( fotoDataUrl ) { fotosDataUrls.push(fotoDataUrl); fotoDataUrl = null; }
                    evappGiActualizarStrip();
                    if ( fileInput    ) fileInput.value = '';
                    if ( uploadGuide  ) uploadGuide.style.display  = 'none';
                    if ( camWrap      ) camWrap.style.display      = 'none';
                    if ( fotoOpciones ) fotoOpciones.style.display = fotosDataUrls.length < MAX_FOTOS ? '' : 'none';
                    showStep('evapp-gi-step-3');
                });
            }
            if ( btnRetomar ) {
                btnRetomar.addEventListener('click', function(){
                    fotoDataUrl = null;
                    showStep('evapp-gi-step-3');
                    fotoOpciones.style.display = 'none';
                    camWrap.style.display      = '';
                    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 720 }, height: { ideal: 960 } }, audio: false })
                        .then(function(stream){ camStream = stream; video.srcObject = stream; })
                        .catch(function(){
                            camWrap.style.display      = 'none';
                            fotoOpciones.style.display = fotosDataUrls.length < MAX_FOTOS ? '' : 'none';
                        });
                });
            }

            // ── Enviar TODAS las fotos al servidor ───────────────────────────
            function enviarFoto() {
                var fd = new FormData();
                fd.append('action',          'evapp_galeria_registrar_foto');
                fd.append('security',        nonceRegistro);
                fd.append('galeria_id',      galeriaId);
                fd.append('ticket_id',       ticketId);
                fd.append('cedula',          cedulaVal);
                fd.append('foto_data_multi', JSON.stringify(fotosDataUrls));
                fetch( ajaxUrl, { method: 'POST', body: fd } )
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if ( res.success ) {
                            showStep('evapp-gi-step-success');
                        } else {
                            showStep('evapp-gi-step-3');
                            if ( msgStep3 ) showMsg( msgStep3, res.data.error || '❌ Error al guardar las fotos. Por favor intenta de nuevo.', 'error' );
                        }
                    })
                    .catch(function(){
                        showStep('evapp-gi-step-3');
                        if ( msgStep3 ) showMsg( msgStep3, '❌ Error de conexión. Por favor intenta de nuevo.', 'error' );
                    });
            }

            // ── PASO 6: Buscar mis fotos ─────────────────────────────────────
            var btnContinuar  = wizard.querySelector('.evapp-gi-btn-continuar');
            var faceModelsUrl = <?php echo wp_json_encode( trailingslashit( EVENTOSAPP_PLUGIN_URL ) . 'includes/assets/face-models' ); ?>;
            var progressEl    = document.getElementById(uid + '-search-progress');
            var barEl         = document.getElementById(uid + '-search-bar');

            // ── IndexedDB v2 ──────────────────────────────────────────────────
            var IDB_NAME    = 'evapp_gallery_faces_v2';
            var IDB_STORE   = 'photo_descriptors';
            var IDB_VERSION = 1;
            var idbConn     = null;

            function evappGiOpenIDB() {
                return new Promise(function(resolve) {
                    if ( ! window.indexedDB ) { resolve(null); return; }
                    var req = indexedDB.open(IDB_NAME, IDB_VERSION);
                    req.onupgradeneeded = function(e) { e.target.result.createObjectStore(IDB_STORE, { keyPath: 'url' }); };
                    req.onsuccess = function(e) { idbConn = e.target.result; resolve(idbConn); };
                    req.onerror   = function()  { resolve(null); };
                });
            }
            function evappGiIdbGet(url) {
                return new Promise(function(resolve) {
                    if ( ! idbConn ) { resolve(null); return; }
                    try {
                        var tx  = idbConn.transaction(IDB_STORE, 'readonly');
                        var req = tx.objectStore(IDB_STORE).get(url);
                        req.onsuccess = function() { resolve(req.result || null); };
                        req.onerror   = function() { resolve(null); };
                    } catch(e) { resolve(null); }
                });
            }
            function evappGiIdbPut(url, descriptorsArray) {
                if ( ! idbConn ) return;
                try {
                    var tx = idbConn.transaction(IDB_STORE, 'readwrite');
                    tx.objectStore(IDB_STORE).put({ url: url, descriptors: descriptorsArray });
                } catch(e) {}
            }

            function evappGiSetProgress(pct, msg) {
                if ( barEl )      barEl.style.width    = Math.min(100, pct) + '%';
                if ( progressEl ) progressEl.textContent = msg || '';
            }
            function evappGiCargarImagen(src) {
                return new Promise(function(resolve, reject) {
                    var img = new Image(), isDataUrl = src.indexOf('data:') === 0;
                    if ( ! isDataUrl ) img.crossOrigin = 'anonymous';
                    img.onload  = function() { resolve(img); };
                    img.onerror = function() { reject(new Error('No se pudo cargar: ' + (isDataUrl ? '[data URL]' : src))); };
                    img.src = isDataUrl ? src : src + (src.indexOf('?') === -1 ? '?' : '&') + '_evappf=' + Date.now();
                });
            }

            async function evappGiGetDescriptoresGaleria(photoUrl) {
                var cached = await evappGiIdbGet(photoUrl);
                if ( cached && cached.descriptors ) {
                    if ( cached.descriptors.length === 0 ) return [];
                    return cached.descriptors.map(function(d) { return new Float32Array(d); });
                }
                var img  = await evappGiCargarImagen(photoUrl);
                var dets = await faceapi
                    .detectAllFaces(img, new faceapi.SsdMobilenetv1Options({ minConfidence: 0.38 }))
                    .withFaceLandmarks().withFaceDescriptors();
                if ( ! dets || ! dets.length ) { evappGiIdbPut(photoUrl, []); return []; }
                var descriptors = dets.map(function(d) { return d.descriptor; });
                evappGiIdbPut(photoUrl, descriptors.map(function(d) { return Array.from(d); }));
                return descriptors;
            }

            async function evappGiIniciarBusqueda() {
                try {
                    evappGiSetProgress(5, 'Cargando modelos de reconocimiento facial...');
                    if ( typeof faceapi === 'undefined' ) throw new Error('Motor de reconocimiento facial no disponible.');
                    await Promise.all([
                        faceapi.nets.ssdMobilenetv1.isLoaded    ? Promise.resolve() : faceapi.nets.ssdMobilenetv1.loadFromUri(faceModelsUrl),
                        faceapi.nets.faceLandmark68Net.isLoaded  ? Promise.resolve() : faceapi.nets.faceLandmark68Net.loadFromUri(faceModelsUrl),
                        faceapi.nets.faceRecognitionNet.isLoaded ? Promise.resolve() : faceapi.nets.faceRecognitionNet.loadFromUri(faceModelsUrl),
                    ]);
                    evappGiSetProgress(15, 'Analizando tus ' + fotosDataUrls.length + ' foto(s) de referencia...');
                    await evappGiOpenIDB();
                    faceDescsQuery = [];
                    for (var pi = 0; pi < fotosDataUrls.length; pi++) {
                        try {
                            var qImg = await evappGiCargarImagen(fotosDataUrls[pi]);
                            var qDet = await faceapi
                                .detectSingleFace(qImg, new faceapi.SsdMobilenetv1Options({ minConfidence: 0.45 }))
                                .withFaceLandmarks().withFaceDescriptor();
                            if ( qDet ) faceDescsQuery.push(qDet.descriptor);
                        } catch(eQuery) { console.warn('[EventosApp GaleriaIA] Skip foto referencia ' + pi + ':', eQuery.message); }
                    }
                    if ( faceDescsQuery.length === 0 ) { evappGiSetProgress(100, ''); showStep('evapp-gi-step-no-results'); return; }
                    evappGiSetProgress(25, 'Comparando con fotos de la galería...');
                    var matches = [], total = imagenes.length;
                    for ( var i = 0; i < total; i++ ) {
                        var foto = imagenes[i];
                        evappGiSetProgress(25 + Math.round((i / total) * 70), 'Analizando foto ' + (i + 1) + ' de ' + total + '...');
                        try {
                            var galleryDescs = await evappGiGetDescriptoresGaleria(foto.full);
                            if ( galleryDescs && galleryDescs.length ) {
                                var minDist = Infinity;
                                for (var qi = 0; qi < faceDescsQuery.length; qi++) {
                                    for (var gi = 0; gi < galleryDescs.length; gi++) {
                                        var d = faceapi.euclideanDistance(faceDescsQuery[qi], galleryDescs[gi]);
                                        if ( d < minDist ) minDist = d;
                                    }
                                }
                                if ( minDist < 0.56 ) matches.push({ index: i, photo: foto, distance: minDist });
                            }
                        } catch(ePhoto) { console.warn('[EventosApp GaleriaIA] Skip foto ' + i + ':', ePhoto.message); }
                    }
                    evappGiSetProgress(100, 'Búsqueda completada.');
                    setTimeout(function(){ evappGiMostrarResultados(matches); }, 600);
                } catch (err) {
                    console.error('[EventosApp GaleriaIA] Error en búsqueda facial:', err);
                    showStep('evapp-gi-step-no-results');
                }
            }

            function evappGiMostrarResultados(matches) {
                if ( ! matches || ! matches.length ) { showStep('evapp-gi-step-no-results'); return; }
                matches.sort(function(a, b){ return a.distance - b.distance; });
                var resCount    = wizard.querySelector('.evapp-gi-results-count');
                var resCarousel = wizard.querySelector('.evapp-gi-results-carousel-wrap');
                if ( resCount ) {
                    resCount.textContent = matches.length === 1
                        ? '🎉 ¡Encontramos 1 foto en donde apareces!'
                        : '🎉 ¡Encontramos ' + matches.length + ' fotos en donde apareces!';
                }
                var html = '<div class="evapp-gi-results-slides">';
                matches.forEach(function(m, idx) {
                    var altTxt = escHtml(m.photo.alt || ('Foto ' + (idx + 1)));
                    html += '<div class="evapp-gi-result-slide' + (idx === 0 ? ' active' : '') + '" data-ri="' + idx + '">' +
                            '<img src="' + escHtml(m.photo.full) + '" alt="' + altTxt + '" loading="' + (idx === 0 ? 'eager' : 'lazy') + '" /></div>';
                });
                html += '</div>';
                html += '<div class="evapp-gi-results-nav-row">' +
                        '<button type="button" class="evapp-gi-results-nav-btn evapp-gi-res-prev" aria-label="Anterior">&#8249;</button>' +
                        '<span class="evapp-gi-results-counter"><span class="evapp-gi-res-cur">1</span> / ' + matches.length + '</span>' +
                        '<button type="button" class="evapp-gi-results-nav-btn evapp-gi-res-next" aria-label="Siguiente">&#8250;</button>' +
                        '</div>' +
                        '<a class="evapp-gi-download-btn evapp-gi-dl-btn" href="' + escHtml(matches[0].photo.full) + '" download target="_blank">⬇️ &nbsp;Descargar esta foto</a>';
                resCarousel.innerHTML = html;
                var rSlides = resCarousel.querySelectorAll('.evapp-gi-result-slide');
                var rCur = 0;
                var rPrev = resCarousel.querySelector('.evapp-gi-res-prev');
                var rNext = resCarousel.querySelector('.evapp-gi-res-next');
                var rCurLbl = resCarousel.querySelector('.evapp-gi-res-cur');
                var rDlBtn  = resCarousel.querySelector('.evapp-gi-dl-btn');
                function rGoTo(idx) {
                    rSlides[rCur].classList.remove('active');
                    rCur = (idx + matches.length) % matches.length;
                    rSlides[rCur].classList.add('active');
                    if ( rCurLbl ) rCurLbl.textContent = rCur + 1;
                    if ( rDlBtn  ) rDlBtn.href = matches[rCur].photo.full;
                }
                if ( matches.length <= 1 ) {
                    if ( rPrev ) rPrev.style.display = 'none';
                    if ( rNext ) rNext.style.display = 'none';
                } else {
                    if ( rPrev ) rPrev.addEventListener('click', function(){ rGoTo(rCur - 1); });
                    if ( rNext ) rNext.addEventListener('click', function(){ rGoTo(rCur + 1); });
                }
                var rSlidesCont = resCarousel.querySelector('.evapp-gi-results-slides');
                if ( rSlidesCont ) {
                    var rTouchX = 0;
                    rSlidesCont.addEventListener('touchstart', function(e){ rTouchX = e.changedTouches[0].clientX; }, { passive: true });
                    rSlidesCont.addEventListener('touchend', function(e){
                        var diff = rTouchX - e.changedTouches[0].clientX;
                        if ( Math.abs(diff) > 40 ) rGoTo(diff > 0 ? rCur + 1 : rCur - 1);
                    }, { passive: true });
                }
                showStep('evapp-gi-step-results');
            }

            if ( btnContinuar ) {
                btnContinuar.addEventListener('click', function(){
                    showStep('evapp-gi-step-searching');
                    evappGiIniciarBusqueda();
                });
            }

            // ── Reset completo ────────────────────────────────────────────────
            function evappGiResetWizard() {
                fotoDataUrl = null; fotosDataUrls = []; ticketId = null; cedulaVal = ''; faceDescsQuery = [];
                if ( inputCedula ) inputCedula.value = '';
                if ( inputApell  ) inputApell.value  = '';
                if ( uploadGuide  ) uploadGuide.style.display  = 'none';
                if ( camWrap      ) camWrap.style.display      = 'none';
                if ( fotoOpciones ) fotoOpciones.style.display = '';
                var fi = wizard.querySelector('.evapp-gi-file-input');
                if ( fi ) fi.value = '';
                evappGiActualizarStrip();
                wizard.style.display      = 'none';
                triggerWrap.style.display = '';
            }

            var btnNuevaBusqueda = wizard.querySelector('.evapp-gi-btn-nueva-busqueda');
            if ( btnNuevaBusqueda ) btnNuevaBusqueda.addEventListener('click', evappGiResetWizard);

            var btnNuevaBusqueda2 = wizard.querySelector('.evapp-gi-btn-nueva-busqueda-2');
            if ( btnNuevaBusqueda2 ) btnNuevaBusqueda2.addEventListener('click', evappGiResetWizard);

            var btnIntentarOtraFoto = wizard.querySelector('.evapp-gi-btn-intentar-otra-foto');
            if ( btnIntentarOtraFoto ) {
                btnIntentarOtraFoto.addEventListener('click', function(){
                    fotoDataUrl = null; fotosDataUrls = []; faceDescsQuery = [];
                    if ( uploadGuide  ) uploadGuide.style.display  = 'none';
                    if ( camWrap      ) camWrap.style.display      = 'none';
                    if ( fotoOpciones ) fotoOpciones.style.display = '';
                    var fi = wizard.querySelector('.evapp-gi-file-input');
                    if ( fi ) fi.value = '';
                    evappGiActualizarStrip();
                    showStep('evapp-gi-step-3');
                });
            }

        })(); // fin IIFE wizard
        <?php endif; // $evento_id ?>

    })();
    </script>

    <?php
    return ob_get_clean();
} );

// ============================================================
// 7. ESTILOS CSS DEL SHORTCODE (encolar en frontend)
// ============================================================

add_action( 'wp_enqueue_scripts', function () {
    // Solo encolar si hay un shortcode de galería en la página actual
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) ) return;
    if ( ! has_shortcode( $post->post_content, 'eventosapp_galeria' ) ) return;

    $css_id = 'evapp-galeria-styles';
    if ( wp_style_is( $css_id, 'enqueued' ) ) return;

    $css = '
/* ===== EventosApp Galería ===== */
.evapp-galeria-wrap {
    max-width: 900px;
    margin: 0 auto;
    font-family: inherit;
    --evapp-g-accent: #0073aa;
    --evapp-g-radius: 8px;
    --evapp-g-shadow: 0 4px 20px rgba(0,0,0,.15);
}
/* ── Header informativo ── */
.evapp-galeria-header {
    padding: 14px 0 12px;
    border-bottom: 1px solid #e8e8e8;
    margin-bottom: 14px;
}
.evapp-galeria-header-top {
    margin-bottom: 8px;
}
.evapp-galeria-header-title {
    font-size: 22px;
    font-weight: 800;
    color: #111;
    margin: 0;
    line-height: 1.25;
}
.evapp-galeria-header-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 16px;
    font-size: 13px;
    color: #555;
}
.evapp-gh-meta-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
}
.evapp-gh-icon {
    display: inline-flex;
    align-items: center;
    color: #888;
    flex-shrink: 0;
}
.evapp-gh-cliente {
    font-size: 13px;
    color: #444;
    gap: 5px;
}
.evapp-gh-hash {
    color: #888;
    font-weight: 700;
    font-size: 13px;
    margin-right: 1px;
}
.evapp-gh-evento-por {
    color: #888;
    font-size: 13px;
}
.evapp-gh-cliente-logo {
    width: 24px;
    height: 24px;
    object-fit: contain;
    border-radius: 3px;
    vertical-align: middle;
    flex-shrink: 0;
    background: transparent;
}
.evapp-gh-cliente-nombre {
    font-weight: 700;
    color: #222;
    font-size: 13px;
}
@media (max-width: 600px) {
    .evapp-galeria-header-title { font-size: 17px; }
    .evapp-galeria-header-meta  { font-size: 12px; gap: 5px 12px; }
    .evapp-gh-cliente-logo      { width: 20px; height: 20px; }
}
.evapp-galeria-descripcion {
    color: #555;
    font-size: 15px;
    margin-bottom: 16px;
    line-height: 1.6;
}
/* ── Área principal ── */
.evapp-galeria-main-wrap {
    position: relative;
    display: flex;
    align-items: center;
    gap: 0;
    background: #111;
    border-radius: var(--evapp-g-radius);
    overflow: hidden;
}
.evapp-galeria-slides-wrap {
    flex: 1;
    position: relative;
    min-height: 300px;
    max-height: 600px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #000;
}
.evapp-galeria-slide {
    display: none;
    width: 100%;
    text-align: center;
}
.evapp-galeria-slide.active {
    display: block;
}
.evapp-galeria-slide img {
    max-width: 100%;
    max-height: 560px;
    width: auto;
    height: auto;
    object-fit: contain;
    display: block;
    margin: 0 auto;
    cursor: zoom-in;
}
.evapp-galeria-caption {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(0,0,0,.55);
    color: #fff;
    font-size: 13px;
    padding: 8px 16px;
    margin: 0;
    text-align: center;
}
/* ── Botones nav ── */
.evapp-galeria-nav {
    background: rgba(0,0,0,.45);
    color: #fff;
    border: none;
    font-size: 40px;
    line-height: 1;
    padding: 0 14px;
    cursor: pointer;
    transition: background .2s;
    min-height: 60px;
    align-self: stretch;
    flex-shrink: 0;
    z-index: 2;
}
.evapp-galeria-nav:hover { background: rgba(0,0,0,.75); }
/* ── Contador ── */
.evapp-galeria-counter {
    text-align: center;
    font-size: 13px;
    color: #777;
    margin: 8px 0 6px;
}
/* ── Miniaturas ── */
.evapp-galeria-thumbs {
    display: flex;
    gap: 6px;
    overflow-x: auto;
    padding: 6px 0 8px;
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
}
.evapp-galeria-thumbs::-webkit-scrollbar { height: 4px; }
.evapp-galeria-thumbs::-webkit-scrollbar-thumb { background: #ccc; border-radius: 2px; }
.evapp-galeria-thumb {
    flex-shrink: 0;
    width: 70px;
    height: 70px;
    padding: 0;
    border: 2px solid transparent;
    border-radius: 4px;
    overflow: hidden;
    background: #eee;
    cursor: pointer;
    transition: border-color .2s, opacity .2s;
    opacity: .65;
}
.evapp-galeria-thumb.active,
.evapp-galeria-thumb:hover {
    border-color: var(--evapp-g-accent);
    opacity: 1;
}
.evapp-galeria-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
/* ── Lightbox ── */
.evapp-galeria-lightbox {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.92);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    box-sizing: border-box;
}
.evapp-galeria-lb-img-wrap {
    max-width: 90vw;
    max-height: 88vh;
    position: relative;
    text-align: center;
}
.evapp-galeria-lb-img {
    max-width: 100%;
    max-height: 82vh;
    object-fit: contain;
    border-radius: 4px;
    box-shadow: var(--evapp-g-shadow);
}
.evapp-galeria-lb-caption {
    color: rgba(255,255,255,.75);
    font-size: 13px;
    margin-top: 8px;
    text-align: center;
}
.evapp-galeria-lb-close {
    position: fixed;
    top: 16px;
    right: 20px;
    background: none;
    border: none;
    color: #fff;
    font-size: 42px;
    line-height: 1;
    cursor: pointer;
    z-index: 100000;
    opacity: .8;
    transition: opacity .2s;
}
.evapp-galeria-lb-close:hover { opacity: 1; }
.evapp-galeria-lb-prev,
.evapp-galeria-lb-next {
    background: rgba(255,255,255,.12);
    color: #fff;
    border: none;
    font-size: 48px;
    line-height: 1;
    padding: 10px 20px;
    cursor: pointer;
    border-radius: 4px;
    transition: background .2s;
    flex-shrink: 0;
}
.evapp-galeria-lb-prev:hover,
.evapp-galeria-lb-next:hover { background: rgba(255,255,255,.25); }
/* ── Responsive ── */
@media (max-width: 600px) {
    .evapp-galeria-nav { font-size: 28px; padding: 0 8px; }
    .evapp-galeria-thumb { width: 54px; height: 54px; }
    .evapp-galeria-slides-wrap { min-height: 200px; max-height: 360px; }
    .evapp-galeria-lb-prev,
    .evapp-galeria-lb-next { font-size: 32px; padding: 6px 12px; }
}
';

    wp_register_style( $css_id, false );
    wp_enqueue_style( $css_id );
    wp_add_inline_style( $css_id, $css );
} );

// ============================================================
// 8. HELPER PÚBLICO: Obtener datos de una galería
//    Uso: $data = eventosapp_get_galeria( $id );
// ============================================================

if ( ! function_exists( 'eventosapp_get_galeria' ) ) {
    function eventosapp_get_galeria( $galeria_id ) {
        $post = get_post( $galeria_id );
        if ( ! $post || $post->post_type !== 'eventosapp_galeria' ) return false;

        $fotos_raw = get_post_meta( $galeria_id, '_galeria_fotos', true );
        $fotos_ids = [];

        if ( $fotos_raw ) {
            $decoded = json_decode( $fotos_raw, true );
            if ( is_array( $decoded ) ) {
                $fotos_ids = array_filter( array_map( 'absint', $decoded ) );
            }
        }

        return [
            'id'           => $galeria_id,
            'titulo'       => $post->post_title,
            'descripcion'  => get_post_meta( $galeria_id, '_galeria_descripcion', true ),
            'evento_id'    => (int) get_post_meta( $galeria_id, '_galeria_evento_id', true ),
            'fotos_ids'    => array_values( $fotos_ids ),
            'total_fotos'  => count( $fotos_ids ),
            'shortcode'    => '[eventosapp_galeria id="' . $galeria_id . '"]',
        ];
    }
}
