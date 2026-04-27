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

// ============================================================
// 5.1 HELPERS: Textos configurables del flujo IA
// ============================================================

if ( ! function_exists( 'evapp_galeria_ia_default_texts' ) ) {
    function evapp_galeria_ia_default_texts() {
        return [
            'promo_question'              => '¿Quieres ver las fotos en donde apareces?',
            'promo_highlight'             => 'Deja que la Inteligencia Artificial lo haga por ti.',
            'promo_button'                => '🔍 Buscar',

            'badge_step_1'                => 'Paso 1 de 3',
            'step1_title'                 => 'Valida tu identidad',
            'step1_desc'                  => 'Necesitamos validar que eres asistente del evento, por favor ingresa los siguientes datos para continuar.',
            'cedula_label'                => 'Número de Identificación',
            'cedula_placeholder'          => 'Ej: 1234567890',
            'apellidos_label'             => 'Apellidos',
            'apellidos_placeholder'       => 'Ej: García López',
            'step1_hint'                  => '✏️ Escribe tus datos tal cual como en tu inscripción al evento.',
            'validate_button'             => 'Continuar →',
            'validate_loading'            => 'Buscando...',
            'validate_empty_error'        => '⚠️ Por favor ingresa tu número de identificación y tus apellidos.',
            'validate_server_error'       => '❌ No encontramos un asistente con esos datos. Intenta de nuevo.',
            'connection_error'            => '❌ Error de conexión. Por favor intenta de nuevo.',

            'badge_verified'              => '✓ Verificado',
            'step2_title'                 => '¡Te encontramos!',
            'step2_button'                => 'Continuar → Capturar Foto',
            'assistant_company_icon'      => '🏢',
            'assistant_role_icon'         => '💼',
            'assistant_email_icon'        => '✉️',

            'badge_step_2'                => 'Paso 2 de 3',
            'step3_title'                 => 'Captura tus fotos',
            'step3_desc'                  => 'Cuantas más fotos agregues con diferentes características, mejor será la detección. Puedes agregar hasta 3.',
            'tip1_icon'                   => '😊',
            'tip1_image_url'              => '',
            'tip1_image_alt'              => '',
            'tip1_text'                   => 'De frente, sin accesorios',
            'tip2_icon'                   => '🕶️',
            'tip2_image_url'              => '',
            'tip2_image_alt'              => '',
            'tip2_text'                   => 'Con gafas o sombrero si los usas',
            'tip3_icon'                   => '↗️',
            'tip3_image_url'              => '',
            'tip3_image_alt'              => '',
            'tip3_text'                   => 'Leve ángulo lateral',
            'strip_empty'                 => 'Aún no has agregado ninguna foto. Agrega al menos una para continuar.',
            'strip_one'                   => '✅ 1 foto agregada. ¡Agrega 1 o 2 más para mejorar los resultados!',
            'strip_two'                   => '✅ 2 fotos agregadas. Puedes agregar 1 más o ya continuar.',
            'strip_three'                 => '✅ 3 fotos agregadas. ¡Perfecto! Ya puedes continuar.',
            'photo_label'                 => 'Foto {num}',
            'upload_button'               => '📁 Subir una Foto',
            'camera_button'               => '📷 Tomar Foto',
            'upload_guide_text'           => 'Asegúrate que tu cara quede centrada dentro del óvalo antes de continuar:',
            'upload_preview_alt'          => 'Vista previa de tu foto',
            'approve_upload_button'       => '✓ La foto se ve bien, continuar',
            'choose_other_button'         => '↩ Elegir otra foto',
            'camera_label'                => 'Centra tu cara aquí',
            'capture_button'              => '📸 Capturar Foto',
            'cancel_camera_button'        => 'Cancelar Cámara',
            'continue_photos_prefix'      => '✓ Continuar con',
            'continue_photos_suffix'      => 'foto(s)',
            'camera_permission_error'     => 'No se pudo acceder a la cámara. Verifica los permisos del navegador, o usa "Subir una Foto".',

            'badge_step_3'                => 'Paso 3 de 3',
            'step4_title'                 => '¿La foto se ve bien?',
            'step4_desc'                  => 'Revisa que tu cara se vea con claridad antes de continuar.',
            'preview_final_alt'           => 'Tu foto',
            'confirm_photo_button'        => '✓ Sí, agregar esta foto',
            'retake_photo_button'         => '↩ Tomar otra foto',

            'spinner_type'                => 'css',
            'spinner_icon'                => '⏳',
            'spinner_image_url'           => '',
            'loading_title'               => 'Procesando tus fotos...',
            'loading_desc'                => 'Estamos registrando tu información, por favor espera.',
            'save_server_error'           => '❌ Error al guardar las fotos. Por favor intenta de nuevo.',

            'success_icon'                => '🎉',
            'success_image_url'           => '',
            'success_image_alt'           => '',
            'success_title'               => '¡Ya tenemos todo!',
            'success_desc'                => 'Vamos a comenzar la búsqueda de tus fotos usando Inteligencia Artificial.',
            'success_button'              => '🔍 Buscar mis fotos',

            'searching_title'             => 'Buscando tus fotos con IA...',
            'progress_loading_models'     => 'Cargando modelos de reconocimiento facial...',
            'progress_analyzing_refs'     => 'Analizando tus {count} foto(s) de referencia...',
            'progress_comparing'          => 'Comparando con fotos de la galería...',
            'progress_analyzing_photo'    => 'Analizando foto {current} de {total}...',
            'progress_completed'          => 'Búsqueda completada.',
            'face_engine_error'           => 'Motor de reconocimiento facial no disponible.',
            'image_load_error'            => 'No se pudo cargar: {src}',

            'results_badge'               => '✓ Búsqueda completada',
            'results_title'               => '¡Encontramos tus fotos!',
            'results_count_one'           => '🎉 ¡Encontramos 1 foto en donde apareces!',
            'results_count_many'          => '🎉 ¡Encontramos {count} fotos en donde apareces!',
            'results_prev_label'          => 'Anterior',
            'results_next_label'          => 'Siguiente',
            'download_button'             => '⬇️ Descargar esta foto',
            'back_start_button'           => '↩ Volver al inicio',

            'no_results_icon'             => '😔',
            'no_results_title'            => 'No encontramos coincidencias',
            'no_results_desc'             => 'No detectamos tu rostro en las fotos de la galería. Puede ser que no hayas sido fotografiado/a aún, o que la foto que usaste no sea muy clara. Intenta con otras fotos de referencia.',
            'try_other_photo_button'      => '📷 Intentar con otras fotos',
        ];
    }
}

if ( ! function_exists( 'evapp_galeria_ia_sanitize_texts' ) ) {
    function evapp_galeria_ia_sanitize_texts( $atts ) {
        $defaults = evapp_galeria_ia_default_texts();
        $texts    = [];

        foreach ( $defaults as $key => $default ) {
            $value = isset( $atts[ $key ] ) ? $atts[ $key ] : $default;

            if ( $key === 'spinner_type' ) {
                $value   = sanitize_key( $value );
                $allowed = [ 'css', 'emoji', 'image', 'none' ];
                $texts[ $key ] = in_array( $value, $allowed, true ) ? $value : 'css';
                continue;
            }

            if ( substr( $key, -10 ) === '_image_url' ) {
                $texts[ $key ] = esc_url_raw( $value );
                continue;
            }

            $texts[ $key ] = sanitize_text_field( $value );
        }

        return $texts;
    }
}

if ( ! function_exists( 'evapp_galeria_ia_default_cta_settings' ) ) {
    function evapp_galeria_ia_default_cta_settings() {
        return [
            'cta_layout'          => 'vertical',
            'cta_image_url'       => '',
            'cta_image_hover_url' => '',
            'cta_image_alt'       => '',
            'cta_order_image'     => 10,
            'cta_order_text'      => 20,
            'cta_order_button'    => 30,
        ];
    }
}

if ( ! function_exists( 'evapp_galeria_ia_sanitize_cta_settings' ) ) {
    function evapp_galeria_ia_sanitize_cta_settings( $atts ) {
        $defaults = evapp_galeria_ia_default_cta_settings();
        $settings = [];

        $layout = isset( $atts['cta_layout'] ) ? sanitize_key( $atts['cta_layout'] ) : $defaults['cta_layout'];
        $settings['cta_layout'] = in_array( $layout, [ 'vertical', 'horizontal' ], true ) ? $layout : 'vertical';

        $settings['cta_image_url']       = isset( $atts['cta_image_url'] ) ? esc_url_raw( $atts['cta_image_url'] ) : '';
        $settings['cta_image_hover_url'] = isset( $atts['cta_image_hover_url'] ) ? esc_url_raw( $atts['cta_image_hover_url'] ) : '';
        $settings['cta_image_alt']       = isset( $atts['cta_image_alt'] ) ? sanitize_text_field( $atts['cta_image_alt'] ) : '';

        $settings['cta_order_image']  = isset( $atts['cta_order_image'] ) ? absint( $atts['cta_order_image'] ) : (int) $defaults['cta_order_image'];
        $settings['cta_order_text']   = isset( $atts['cta_order_text'] ) ? absint( $atts['cta_order_text'] ) : (int) $defaults['cta_order_text'];
        $settings['cta_order_button'] = isset( $atts['cta_order_button'] ) ? absint( $atts['cta_order_button'] ) : (int) $defaults['cta_order_button'];

        return $settings;
    }
}


if ( ! function_exists( 'evapp_galeria_ia_default_results_settings' ) ) {
    function evapp_galeria_ia_default_results_settings() {
        return [
            'results_image_url'       => '',
            'results_image_alt'       => '',
            'results_order_image'     => 5,
            'results_order_badge'     => 10,
            'results_order_title'     => 20,
            'results_order_subtitle'  => 30,
        ];
    }
}

if ( ! function_exists( 'evapp_galeria_ia_sanitize_results_settings' ) ) {
    function evapp_galeria_ia_sanitize_results_settings( $atts ) {
        $defaults = evapp_galeria_ia_default_results_settings();
        $settings = [];

        $settings['results_image_url'] = isset( $atts['results_image_url'] )
            ? esc_url_raw( $atts['results_image_url'] )
            : '';

        $settings['results_image_alt'] = isset( $atts['results_image_alt'] )
            ? sanitize_text_field( $atts['results_image_alt'] )
            : '';

        foreach ( [ 'results_order_image', 'results_order_badge', 'results_order_title', 'results_order_subtitle' ] as $order_key ) {
            $settings[ $order_key ] = ( isset( $atts[ $order_key ] ) && $atts[ $order_key ] !== '' )
                ? absint( $atts[ $order_key ] )
                : (int) $defaults[ $order_key ];
        }

        return $settings;
    }
}


if ( ! function_exists( 'evapp_galeria_ia_spinner_html' ) ) {
    function evapp_galeria_ia_spinner_html( $texts ) {
        $type = isset( $texts['spinner_type'] ) ? sanitize_key( $texts['spinner_type'] ) : 'css';

        if ( $type === 'emoji' ) {
            $icon = isset( $texts['spinner_icon'] ) && $texts['spinner_icon'] !== '' ? $texts['spinner_icon'] : '⏳';
            return '<div class="evapp-gi-spinner evapp-gi-spinner-emoji" aria-hidden="true">' . esc_html( $icon ) . '</div>';
        }

        if ( $type === 'image' ) {
            $url = isset( $texts['spinner_image_url'] ) ? esc_url( $texts['spinner_image_url'] ) : '';
            if ( $url ) {
                return '<div class="evapp-gi-spinner evapp-gi-spinner-image" aria-hidden="true"><img src="' . $url . '" alt="" loading="lazy" /></div>';
            }
        }

        if ( $type === 'none' ) {
            return '<div class="evapp-gi-spinner evapp-gi-spinner-none" aria-hidden="true"></div>';
        }

        return '<div class="evapp-gi-spinner evapp-gi-spinner-css" aria-hidden="true"></div>';
    }
}

if ( ! function_exists( 'evapp_galeria_ia_tip_media_html' ) ) {
    function evapp_galeria_ia_tip_media_html( $texts, $tip_number ) {
        $tip_number = absint( $tip_number );
        if ( $tip_number < 1 || $tip_number > 3 ) {
            return '';
        }

        $icon_key  = 'tip' . $tip_number . '_icon';
        $image_key = 'tip' . $tip_number . '_image_url';
        $alt_key   = 'tip' . $tip_number . '_image_alt';
        $text_key  = 'tip' . $tip_number . '_text';

        $image_url = isset( $texts[ $image_key ] ) ? esc_url( $texts[ $image_key ] ) : '';
        $image_alt = isset( $texts[ $alt_key ] ) && $texts[ $alt_key ] !== ''
            ? $texts[ $alt_key ]
            : ( isset( $texts[ $text_key ] ) ? $texts[ $text_key ] : '' );

        if ( $image_url ) {
            return '<span class="evapp-gi-tip-media evapp-gi-tip-media-image"><img src="' . $image_url . '" alt="' . esc_attr( $image_alt ) . '" loading="lazy" /></span>';
        }

        $icon = isset( $texts[ $icon_key ] ) && $texts[ $icon_key ] !== '' ? $texts[ $icon_key ] : '';
        return '<span class="evapp-gi-tip-media evapp-gi-tip-icon">' . esc_html( $icon ) . '</span>';
    }
}

if ( ! function_exists( 'evapp_galeria_ia_success_media_html' ) ) {
    function evapp_galeria_ia_success_media_html( $texts ) {
        $image_url = isset( $texts['success_image_url'] ) ? esc_url( $texts['success_image_url'] ) : '';
        $image_alt = isset( $texts['success_image_alt'] ) && $texts['success_image_alt'] !== ''
            ? $texts['success_image_alt']
            : ( isset( $texts['success_title'] ) ? $texts['success_title'] : '' );

        if ( $image_url ) {
            return '<div class="evapp-gi-success-media evapp-gi-success-media-image"><img src="' . $image_url . '" alt="' . esc_attr( $image_alt ) . '" loading="lazy" /></div>';
        }

        $icon = isset( $texts['success_icon'] ) && $texts['success_icon'] !== '' ? $texts['success_icon'] : '🎉';
        return '<div class="evapp-gi-success-icon">' . esc_html( $icon ) . '</div>';
    }
}

add_shortcode( 'eventosapp_galeria', function ( $atts ) {
    $atts = shortcode_atts(
        array_merge(
            [ 'id' => 0 ],
            evapp_galeria_ia_default_texts(),
            evapp_galeria_ia_default_cta_settings(),
            evapp_galeria_ia_default_results_settings()
        ),
        $atts,
        'eventosapp_galeria'
    );
    $galeria_id = absint( $atts['id'] );
    $gi_text    = evapp_galeria_ia_sanitize_texts( $atts );
    $gi_cta     = evapp_galeria_ia_sanitize_cta_settings( $atts );
    $gi_results = evapp_galeria_ia_sanitize_results_settings( $atts );

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

        // Lugar del evento — clave confirmada desde el formulario admin del CPT evento
        $ev_direccion = get_post_meta( $evento_id, '_eventosapp_direccion',    true ); // "Dirección del Evento" (campo principal)
        $ev_lugar     = get_post_meta( $evento_id, '_eventosapp_lugar',        true ); // fallback: campo lugar/venue
        $ev_ubic      = get_post_meta( $evento_id, '_eventosapp_ubicacion',    true ); // fallback: alias ubicación
        $ev_ciudad    = get_post_meta( $evento_id, '_eventosapp_ciudad',       true ); // fallback: ciudad separada
        $ev_depto     = get_post_meta( $evento_id, '_eventosapp_departamento', true ); // fallback: departamento separado

        if ( $ev_direccion ) {
            $header_lugar = $ev_direccion;
        } elseif ( $ev_lugar ) {
            $header_lugar = $ev_lugar;
        } elseif ( $ev_ubic ) {
            $header_lugar = $ev_ubic;
        } elseif ( $ev_ciudad && $ev_depto ) {
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
                        <span class="evapp-gh-organizador-label">Organizador:</span>
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
            <div class="evapp-gi-trigger-wrap evapp-gi-trigger-layout-<?php echo esc_attr( $gi_cta['cta_layout'] ); ?>" id="<?php echo esc_attr( $uid ); ?>-trigger">
                <?php if ( ! empty( $gi_cta['cta_image_url'] ) ) : ?>
                    <div class="evapp-gi-promo-image-wrap" style="order:<?php echo esc_attr( $gi_cta['cta_order_image'] ); ?>;">
                        <img class="evapp-gi-promo-image evapp-gi-promo-image-normal<?php echo ! empty( $gi_cta['cta_image_hover_url'] ) ? ' has-hover' : ''; ?>"
                             src="<?php echo esc_url( $gi_cta['cta_image_url'] ); ?>"
                             alt="<?php echo esc_attr( $gi_cta['cta_image_alt'] ?: $gi_text['promo_question'] ); ?>"
                             loading="lazy" />
                        <?php if ( ! empty( $gi_cta['cta_image_hover_url'] ) ) : ?>
                            <img class="evapp-gi-promo-image evapp-gi-promo-image-hover"
                                 src="<?php echo esc_url( $gi_cta['cta_image_hover_url'] ); ?>"
                                 alt=""
                                 aria-hidden="true"
                                 loading="lazy" />
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <p class="evapp-gi-promo-text" style="order:<?php echo esc_attr( $gi_cta['cta_order_text'] ); ?>;">
                    <?php echo esc_html( $gi_text['promo_question'] ); ?><br>
                    <strong><?php echo esc_html( $gi_text['promo_highlight'] ); ?></strong>
                </p>
                <button type="button" class="evapp-gi-btn-abrir" id="<?php echo esc_attr( $uid ); ?>-btn-abrir" style="order:<?php echo esc_attr( $gi_cta['cta_order_button'] ); ?>;">
                    <?php echo esc_html( $gi_text['promo_button'] ); ?>
                </button>
            </div>

            <!-- WIZARD CONTAINER -->
            <div class="evapp-gi-wizard" id="<?php echo esc_attr( $uid ); ?>-wizard" style="display:none;" aria-live="polite">

                <!-- ── PASO 1: Validar identidad ── -->
                <div class="evapp-gi-step evapp-gi-step-1" data-step="1">
                    <div class="evapp-gi-step-header">
                        <span class="evapp-gi-badge"><?php echo esc_html( $gi_text['badge_step_1'] ); ?></span>
                        <h3 class="evapp-gi-step-title"><?php echo esc_html( $gi_text['step1_title'] ); ?></h3>
                    </div>
                    <p class="evapp-gi-step-desc">
                        <?php echo esc_html( $gi_text['step1_desc'] ); ?>
                    </p>
                    <div class="evapp-gi-field-wrap">
                        <label class="evapp-gi-label" for="<?php echo esc_attr($uid); ?>-cedula"><?php echo esc_html( $gi_text['cedula_label'] ); ?></label>
                        <input type="text" id="<?php echo esc_attr($uid); ?>-cedula"
                               class="evapp-gi-input evapp-gi-cedula"
                               placeholder="<?php echo esc_attr( $gi_text['cedula_placeholder'] ); ?>" autocomplete="off" inputmode="text" />
                    </div>
                    <div class="evapp-gi-field-wrap">
                        <label class="evapp-gi-label" for="<?php echo esc_attr($uid); ?>-apellidos"><?php echo esc_html( $gi_text['apellidos_label'] ); ?></label>
                        <input type="text" id="<?php echo esc_attr($uid); ?>-apellidos"
                               class="evapp-gi-input evapp-gi-apellidos"
                               placeholder="<?php echo esc_attr( $gi_text['apellidos_placeholder'] ); ?>" autocomplete="off" />
                    </div>
                    <p class="evapp-gi-hint-text"><?php echo esc_html( $gi_text['step1_hint'] ); ?></p>
                    <div class="evapp-gi-msg evapp-gi-msg-1" role="alert" style="display:none;"></div>
                    <button type="button" class="evapp-gi-btn-primary evapp-gi-btn-validar"><?php echo esc_html( $gi_text['validate_button'] ); ?></button>
                </div>

                <!-- ── PASO 2: Confirmación de identidad ── -->
                <div class="evapp-gi-step evapp-gi-step-2" data-step="2" style="display:none;">
                    <div class="evapp-gi-step-header">
                        <span class="evapp-gi-badge evapp-gi-badge-ok"><?php echo esc_html( $gi_text['badge_verified'] ); ?></span>
                        <h3 class="evapp-gi-step-title"><?php echo esc_html( $gi_text['step2_title'] ); ?></h3>
                    </div>
                    <div class="evapp-gi-asistente-card"><!-- Se llena desde JS --></div>
                    <button type="button" class="evapp-gi-btn-primary evapp-gi-btn-ir-paso3">
                        <?php echo esc_html( $gi_text['step2_button'] ); ?>
                    </button>
                </div>

                <!-- ── PASO 3: Captura de fotos (multi) ── -->
                <div class="evapp-gi-step evapp-gi-step-3" data-step="3" style="display:none;">
                    <div class="evapp-gi-step-header">
                        <span class="evapp-gi-badge"><?php echo esc_html( $gi_text['badge_step_2'] ); ?></span>
                        <h3 class="evapp-gi-step-title"><?php echo esc_html( $gi_text['step3_title'] ); ?></h3>
                    </div>
                    <p class="evapp-gi-step-desc">
                        <?php echo esc_html( $gi_text['step3_desc'] ); ?>
                    </p>
                    <!-- Tips -->
                    <div class="evapp-gi-foto-tips">
                        <div class="evapp-gi-tip-item"><?php echo evapp_galeria_ia_tip_media_html( $gi_text, 1 ); ?><span><?php echo esc_html( $gi_text['tip1_text'] ); ?></span></div>
                        <div class="evapp-gi-tip-item"><?php echo evapp_galeria_ia_tip_media_html( $gi_text, 2 ); ?><span><?php echo esc_html( $gi_text['tip2_text'] ); ?></span></div>
                        <div class="evapp-gi-tip-item"><?php echo evapp_galeria_ia_tip_media_html( $gi_text, 3 ); ?><span><?php echo esc_html( $gi_text['tip3_text'] ); ?></span></div>
                    </div>
                    <!-- Tira de fotos -->
                    <div class="evapp-gi-foto-strip"><!-- Se llena desde JS --></div>
                    <p class="evapp-gi-strip-status"><?php echo esc_html( $gi_text['strip_empty'] ); ?></p>
                    <!-- Mensaje error -->
                    <div class="evapp-gi-msg evapp-gi-msg-step3" role="alert" style="display:none;"></div>
                    <!-- Opciones de captura -->
                    <div class="evapp-gi-foto-opciones evapp-gi-foto-opciones-main">
                        <button type="button" class="evapp-gi-btn-opcion evapp-gi-btn-subir-foto">
                            <span><?php echo esc_html( $gi_text['upload_button'] ); ?></span>
                        </button>
                        <button type="button" class="evapp-gi-btn-opcion evapp-gi-btn-abrir-cam">
                            <span><?php echo esc_html( $gi_text['camera_button'] ); ?></span>
                        </button>
                    </div>
                    <input type="file" class="evapp-gi-file-input" accept="image/*" style="display:none;" />
                    <!-- Preview upload -->
                    <div class="evapp-gi-upload-guide-wrap" style="display:none;">
                        <p class="evapp-gi-guide-instruc"><?php echo esc_html( $gi_text['upload_guide_text'] ); ?></p>
                        <div class="evapp-gi-guide-frame">
                            <img class="evapp-gi-upload-preview-img" src="" alt="<?php echo esc_attr( $gi_text['upload_preview_alt'] ); ?>" />
                            <div class="evapp-gi-oval-overlay"><div class="evapp-gi-oval-ring"></div></div>
                        </div>
                        <div class="evapp-gi-guide-actions">
                            <button type="button" class="evapp-gi-btn-primary evapp-gi-btn-aprobar-upload"><?php echo esc_html( $gi_text['approve_upload_button'] ); ?></button>
                            <button type="button" class="evapp-gi-btn-secondary evapp-gi-btn-elegir-otra"><?php echo esc_html( $gi_text['choose_other_button'] ); ?></button>
                        </div>
                    </div>
                    <!-- Cámara -->
                    <div class="evapp-gi-cam-wrap" style="display:none;">
                        <div class="evapp-gi-cam-view-frame">
                            <video class="evapp-gi-video" autoplay playsinline muted></video>
                            <div class="evapp-gi-oval-overlay evapp-gi-oval-cam">
                                <div class="evapp-gi-oval-ring"></div>
                                <p class="evapp-gi-cam-label"><?php echo esc_html( $gi_text['camera_label'] ); ?></p>
                            </div>
                        </div>
                        <canvas class="evapp-gi-canvas" style="display:none;"></canvas>
                        <div class="evapp-gi-cam-actions">
                            <button type="button" class="evapp-gi-btn-primary evapp-gi-btn-capturar"><?php echo esc_html( $gi_text['capture_button'] ); ?></button>
                            <button type="button" class="evapp-gi-btn-secondary evapp-gi-btn-cancel-cam"><?php echo esc_html( $gi_text['cancel_camera_button'] ); ?></button>
                        </div>
                    </div>
                    <!-- Continuar con fotos -->
                    <div class="evapp-gi-step3-actions" style="display:none;">
                        <button type="button" class="evapp-gi-btn-primary evapp-gi-btn-step3-continuar">
                            <?php echo esc_html( $gi_text['continue_photos_prefix'] ); ?> <span class="evapp-gi-fotos-count">0</span> <?php echo esc_html( $gi_text['continue_photos_suffix'] ); ?>
                        </button>
                    </div>
                </div>

                <!-- ── PASO 4: Aprobar foto ── -->
                <div class="evapp-gi-step evapp-gi-step-4" data-step="4" style="display:none;">
                    <div class="evapp-gi-step-header">
                        <span class="evapp-gi-badge"><?php echo esc_html( $gi_text['badge_step_3'] ); ?></span>
                        <h3 class="evapp-gi-step-title"><?php echo esc_html( $gi_text['step4_title'] ); ?></h3>
                    </div>
                    <p class="evapp-gi-step-desc"><?php echo esc_html( $gi_text['step4_desc'] ); ?></p>
                    <div class="evapp-gi-preview-circular-wrap">
                        <img class="evapp-gi-preview-final-img" src="" alt="<?php echo esc_attr( $gi_text['preview_final_alt'] ); ?>" />
                    </div>
                    <div class="evapp-gi-msg evapp-gi-msg-4" role="alert" style="display:none;"></div>
                    <div class="evapp-gi-paso4-actions">
                        <button type="button" class="evapp-gi-btn-primary evapp-gi-btn-confirmar-foto"><?php echo esc_html( $gi_text['confirm_photo_button'] ); ?></button>
                        <button type="button" class="evapp-gi-btn-secondary evapp-gi-btn-retomar-cam"><?php echo esc_html( $gi_text['retake_photo_button'] ); ?></button>
                    </div>
                </div>

                <!-- ── CARGANDO ── -->
                <div class="evapp-gi-step evapp-gi-step-loading" data-step="loading" style="display:none;">
                    <div class="evapp-gi-loading-wrap">
                        <?php echo evapp_galeria_ia_spinner_html( $gi_text ); ?>
                        <h3 class="evapp-gi-loading-title"><?php echo esc_html( $gi_text['loading_title'] ); ?></h3>
                        <p class="evapp-gi-loading-desc"><?php echo esc_html( $gi_text['loading_desc'] ); ?></p>
                    </div>
                </div>

                <!-- ── ÉXITO ── -->
                <div class="evapp-gi-step evapp-gi-step-success" data-step="success" style="display:none;">
                    <div class="evapp-gi-success-wrap">
                        <?php echo evapp_galeria_ia_success_media_html( $gi_text ); ?>
                        <h3 class="evapp-gi-success-title"><?php echo esc_html( $gi_text['success_title'] ); ?></h3>
                        <p class="evapp-gi-success-desc"><?php echo esc_html( $gi_text['success_desc'] ); ?></p>
                        <button type="button" class="evapp-gi-btn-primary evapp-gi-btn-continuar"><?php echo esc_html( $gi_text['success_button'] ); ?></button>
                    </div>
                </div>

                <!-- ── BUSCANDO ── -->
                <div class="evapp-gi-step evapp-gi-step-searching" data-step="searching" style="display:none;">
                    <div class="evapp-gi-loading-wrap">
                        <?php echo evapp_galeria_ia_spinner_html( $gi_text ); ?>
                        <h3 class="evapp-gi-loading-title"><?php echo esc_html( $gi_text['searching_title'] ); ?></h3>
                        <p class="evapp-gi-loading-desc" id="<?php echo esc_attr( $uid ); ?>-search-progress"><?php echo esc_html( $gi_text['progress_loading_models'] ); ?></p>
                        <div class="evapp-gi-search-bar-wrap">
                            <div class="evapp-gi-search-bar-inner" id="<?php echo esc_attr( $uid ); ?>-search-bar"></div>
                        </div>
                    </div>
                </div>

                <!-- ── RESULTADOS ── -->
                <div class="evapp-gi-step evapp-gi-step-results" data-step="results" style="display:none;">
                    <div class="evapp-gi-final-response-heading evapp-gi-step-header">
                        <?php if ( ! empty( $gi_results['results_image_url'] ) ) : ?>
                            <div class="evapp-gi-results-image-wrap evapp-gi-final-response-el" style="order:<?php echo esc_attr( $gi_results['results_order_image'] ); ?>;">
                                <img class="evapp-gi-results-image"
                                     src="<?php echo esc_url( $gi_results['results_image_url'] ); ?>"
                                     alt="<?php echo esc_attr( $gi_results['results_image_alt'] ?: $gi_text['results_title'] ); ?>"
                                     loading="lazy" />
                            </div>
                        <?php endif; ?>
                        <span class="evapp-gi-badge evapp-gi-badge-ok evapp-gi-final-response-el" style="order:<?php echo esc_attr( $gi_results['results_order_badge'] ); ?>;"><?php echo esc_html( $gi_text['results_badge'] ); ?></span>
                        <h3 class="evapp-gi-step-title evapp-gi-final-response-el" style="order:<?php echo esc_attr( $gi_results['results_order_title'] ); ?>;"><?php echo esc_html( $gi_text['results_title'] ); ?></h3>
                        <p class="evapp-gi-results-count evapp-gi-final-response-el" style="order:<?php echo esc_attr( $gi_results['results_order_subtitle'] ); ?>;"></p>
                    </div>
                    <div class="evapp-gi-results-carousel-wrap"><!-- Se llena desde JS --></div>
                    <button type="button" class="evapp-gi-btn-secondary evapp-gi-btn-nueva-busqueda" style="margin-top:18px;"><?php echo esc_html( $gi_text['back_start_button'] ); ?></button>
                </div>

                <!-- ── SIN RESULTADOS ── -->
                <div class="evapp-gi-step evapp-gi-step-no-results" data-step="no-results" style="display:none;">
                    <div class="evapp-gi-success-wrap">
                        <div class="evapp-gi-success-icon" style="font-size:52px;"><?php echo esc_html( $gi_text['no_results_icon'] ); ?></div>
                        <h3 class="evapp-gi-step-title" style="font-size:20px;"><?php echo esc_html( $gi_text['no_results_title'] ); ?></h3>
                        <p class="evapp-gi-step-desc">
                            <?php echo esc_html( $gi_text['no_results_desc'] ); ?>
                        </p>
                        <button type="button" class="evapp-gi-btn-primary evapp-gi-btn-intentar-otra-foto"><?php echo esc_html( $gi_text['try_other_photo_button'] ); ?></button>
                        <button type="button" class="evapp-gi-btn-secondary evapp-gi-btn-nueva-busqueda-2" style="margin-top:8px;"><?php echo esc_html( $gi_text['back_start_button'] ); ?></button>
                    </div>
                </div>

            </div><!-- .evapp-gi-wizard -->
        </div><!-- .evapp-gi-finder-section -->

        <style>
        /* ── Sección contenedor ── */
        .evapp-gi-finder-section { margin-top:36px; padding:32px 28px; background:linear-gradient(145deg,#f0f4ff,#e8eeff); border-radius:14px; border:1px solid #c7d4ff; }
        .evapp-gi-trigger-wrap { text-align:center; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:18px; }
        .evapp-gi-trigger-layout-horizontal { flex-direction:row; flex-wrap:wrap; text-align:left; }
        .evapp-gi-trigger-layout-vertical { flex-direction:column; text-align:center; }
        .evapp-gi-promo-image-wrap { position:relative; display:inline-flex; align-items:center; justify-content:center; width:120px; max-width:100%; flex:0 0 auto; line-height:0; overflow:hidden; }
        .evapp-gi-promo-image { display:block; width:100%; height:auto; max-width:100%; object-fit:contain; transition:opacity .32s ease, transform .32s ease; }
        .evapp-gi-promo-image-hover { position:absolute; inset:0; opacity:0; transform:scale(.98); }
        .evapp-gi-promo-image-normal.has-hover { opacity:1; }
        .evapp-gi-finder-section:hover .evapp-gi-promo-image-normal.has-hover { opacity:0; transform:scale(1.02); }
        .evapp-gi-finder-section:hover .evapp-gi-promo-image-hover { opacity:1; transform:scale(1); }
        .evapp-gi-promo-text { font-size:16px; color:#444; margin:0; line-height:1.65; flex:0 1 auto; }
        .evapp-gi-trigger-layout-horizontal .evapp-gi-promo-text { flex:1 1 260px; }
        .evapp-gi-promo-text strong { color:#1c3d8f; }
        .evapp-gi-btn-abrir { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:14px 40px; background:#1c3d8f; color:#fff; border:none; border-radius:50px; font-size:16px; font-weight:700; cursor:pointer; transition:background .2s,transform .15s,box-shadow .2s; box-shadow:0 4px 14px rgba(28,61,143,.25); flex:0 0 auto; }
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
        .evapp-gi-tip-media { display:flex; align-items:center; justify-content:center; width:42px; height:42px; flex:0 0 auto; }
        .evapp-gi-tip-icon { font-size:20px; line-height:1; }
        .evapp-gi-tip-media-image img { display:block; width:100%; height:100%; object-fit:contain; }
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
        .evapp-gi-spinner-emoji { width:auto; height:auto; border:0; border-radius:0; font-size:52px; line-height:1; animation:evapp-gi-pulse 1s ease-in-out infinite; }
        .evapp-gi-spinner-image { border:0; border-radius:0; background:transparent; animation:none; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .evapp-gi-spinner-image img { width:100%; height:100%; object-fit:contain; display:block; }
        .evapp-gi-spinner-none { display:none; }
        @keyframes evapp-gi-spin { to { transform:rotate(360deg); } }
        @keyframes evapp-gi-pulse { 0%,100%{ transform:scale(1); opacity:1; } 50%{ transform:scale(1.12); opacity:.72; } }
        .evapp-gi-loading-title { font-size:18px; font-weight:700; color:#111827; margin:0 0 8px; }
        .evapp-gi-loading-desc { font-size:14px; color:#666; margin:0; }
        /* Éxito */
        .evapp-gi-success-wrap { text-align:center; padding:40px 20px; }
        .evapp-gi-success-icon { font-size:62px; margin-bottom:16px; display:block; animation:evapp-gi-pop .45s cubic-bezier(.34,1.56,.64,1) both; }
        .evapp-gi-success-media { width:82px; height:82px; margin:0 auto 16px; display:flex; align-items:center; justify-content:center; animation:evapp-gi-pop .45s cubic-bezier(.34,1.56,.64,1) both; }
        .evapp-gi-success-media img { display:block; width:100%; height:100%; object-fit:contain; }
        @keyframes evapp-gi-pop { 0%{transform:scale(.4);opacity:0} 100%{transform:scale(1);opacity:1} }
        .evapp-gi-success-title { font-size:24px; font-weight:800; color:#111827; margin:0 0 10px; }
        .evapp-gi-success-desc { font-size:15px; color:#555; margin-bottom:24px; }
        /* Barra progreso búsqueda */
        .evapp-gi-search-bar-wrap { width:100%; background:#dde8ff; border-radius:50px; height:8px; margin:18px auto 0; max-width:320px; overflow:hidden; }
        .evapp-gi-search-bar-inner { height:100%; background:linear-gradient(90deg,#1c3d8f,#4f7cff); border-radius:50px; width:0%; transition:width .4s ease; }
        /* Resultados */
        .evapp-gi-final-response-heading { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; text-align:center; margin-bottom:16px; }
        .evapp-gi-final-response-heading .evapp-gi-badge,
        .evapp-gi-final-response-heading .evapp-gi-step-title,
        .evapp-gi-final-response-heading .evapp-gi-results-count { margin-top:0; margin-bottom:0; }
        .evapp-gi-results-image-wrap { width:96px; max-width:100%; margin:0 auto 2px; line-height:0; display:flex; align-items:center; justify-content:center; }
        .evapp-gi-results-image { display:block; width:100%; height:auto; max-width:100%; object-fit:contain; }
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
            .evapp-gi-trigger-wrap.evapp-gi-trigger-layout-horizontal { flex-direction:column; text-align:center; }
            .evapp-gi-promo-image-wrap { width:96px; }
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
            var giText        = <?php echo wp_json_encode( $gi_text ); ?>;

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
            function t(key, replacements) {
                var value = giText && Object.prototype.hasOwnProperty.call(giText, key) ? String(giText[key] || '') : '';
                if ( replacements ) {
                    Object.keys(replacements).forEach(function(repKey){
                        value = value.split('{' + repKey + '}').join(String(replacements[repKey]));
                    });
                }
                return value;
            }
            function evappGiGetScrollTop() {
                return window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
            }

            function evappGiGetSafeTopOffset() {
                var reservedBottom = 0;
                var adminBar = document.getElementById('wpadminbar');

                if ( adminBar ) {
                    var adminRect = adminBar.getBoundingClientRect();
                    if ( adminRect.height > 0 ) {
                        reservedBottom = Math.max( reservedBottom, adminRect.bottom );
                    }
                }

                var stickySelectors = [
                    'body > header',
                    'header',
                    '#masthead',
                    '.site-header',
                    '.elementor-location-header',
                    '.elementor-sticky--active',
                    '.main-header',
                    '.navbar',
                    '.navbar-fixed-top',
                    '.evapp-header'
                ];

                stickySelectors.forEach(function(selector){
                    document.querySelectorAll(selector).forEach(function(el){
                        if ( ! el || el === adminBar ) return;
                        var style = window.getComputedStyle(el);
                        if ( ! style || ( style.position !== 'fixed' && style.position !== 'sticky' ) ) return;
                        var rect = el.getBoundingClientRect();
                        if ( rect.height <= 0 || rect.bottom <= 0 ) return;
                        if ( rect.top <= reservedBottom + 12 ) {
                            reservedBottom = Math.max( reservedBottom, rect.bottom );
                        }
                    });
                });

                return Math.max(0, Math.ceil(reservedBottom)) + 22;
            }

            function evappGiScrollToElement(target, preferCenter) {
                if ( ! target || typeof window === 'undefined' ) return;

                var runScroll = function(){
                    var rect = target.getBoundingClientRect();
                    if ( ! rect || ( rect.height <= 0 && rect.width <= 0 ) ) return;

                    var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
                    var safeOffset     = evappGiGetSafeTopOffset();
                    var bottomPadding  = 22;
                    var available      = Math.max( 160, viewportHeight - safeOffset - bottomPadding );
                    var absoluteTop    = rect.top + evappGiGetScrollTop();
                    var targetTop;

                    if ( preferCenter && viewportHeight && rect.height < available ) {
                        targetTop = absoluteTop - safeOffset - Math.round( ( available - rect.height ) / 2 );
                    } else {
                        targetTop = absoluteTop - safeOffset;
                    }

                    targetTop = Math.max(0, targetTop);

                    try {
                        window.scrollTo({ top: targetTop, behavior: 'smooth' });
                    } catch(e) {
                        window.scrollTo(0, targetTop);
                    }
                };

                window.requestAnimationFrame(function(){
                    window.requestAnimationFrame(function(){
                        runScroll();
                        window.setTimeout(runScroll, 140);
                    });
                });
            }

            function evappGiScrollToActiveStep(activeStep) {
                if ( ! activeStep || ! finder ) return;
                evappGiScrollToElement(finder, true);
            }

            function evappGiScrollToGalleryStart() {
                if ( ! wrap ) return;
                var galleryTarget = wrap.querySelector('.evapp-galeria-main-wrap') || wrap;
                evappGiScrollToElement(galleryTarget, false);
            }

            function evappGiSetFinalResponseMode(isFinalResponse) {
                if ( ! wrap ) return;
                wrap.classList.toggle('evapp-gi-final-response-active', !! isFinalResponse);
            }

            function showStep(cls) {
                wizard.querySelectorAll('.evapp-gi-step').forEach(function(s){ s.style.display = 'none'; });
                var el = wizard.querySelector('.' + cls);
                if ( el ) el.style.display = '';

                evappGiSetFinalResponseMode(cls === 'evapp-gi-step-results' || cls === 'evapp-gi-step-no-results');
                evappGiScrollToActiveStep(el);
            }
            function showMsg(el, txt, tipo) {
                el.textContent = txt;
                el.className   = 'evapp-gi-msg ' + ( tipo || 'error' );
                el.style.display = '';
            }
            function hideMsg(el) { if (el) el.style.display = 'none'; }
            function setLoading(btn, lbl) { btn.disabled = true;  btn.textContent = lbl || t('loading_title'); }
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
                    img.src = dataUrl; img.alt = t('photo_label', { num: idx + 1 });
                    item.appendChild(img);
                    var label = document.createElement('span');
                    label.className = 'evapp-gi-strip-label';
                    label.textContent = t('photo_label', { num: idx + 1 });
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
                    if ( fotosDataUrls.length === 0 )      { statusEl.textContent = t('strip_empty'); statusEl.className = 'evapp-gi-strip-status'; }
                    else if ( fotosDataUrls.length === 1 ) { statusEl.textContent = t('strip_one'); statusEl.className = 'evapp-gi-strip-status is-hint'; }
                    else if ( fotosDataUrls.length === 2 ) { statusEl.textContent = t('strip_two'); statusEl.className = 'evapp-gi-strip-status is-hint'; }
                    else                                    { statusEl.textContent = t('strip_three'); statusEl.className = 'evapp-gi-strip-status is-ok'; }
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
                        showMsg( msg1, t('validate_empty_error'), 'error' );
                        return;
                    }
                    hideMsg( msg1 );
                    setLoading( btnValidar, t('validate_loading') );
                    var fd = new FormData();
                    fd.append('action', 'evapp_galeria_buscar_ticket');
                    fd.append('security', nonceBuscar);
                    fd.append('galeria_id', galeriaId);
                    fd.append('cedula', cedula);
                    fd.append('apellidos', apellidos);
                    fetch( ajaxUrl, { method: 'POST', body: fd } )
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            setReady( btnValidar, t('validate_button') );
                            if ( res.success ) {
                                ticketId  = res.data.ticket_id;
                                cedulaVal = cedula;
                                var card = wizard.querySelector('.evapp-gi-asistente-card');
                                card.innerHTML =
                                    '<div class="evapp-gi-as-name">' + escHtml(res.data.nombre_completo) + '</div>' +
                                    '<div class="evapp-gi-as-info">' +
                                    ( res.data.empresa ? escHtml(t('assistant_company_icon')) + ' ' + escHtml(res.data.empresa) + '<br>' : '' ) +
                                    ( res.data.cargo   ? escHtml(t('assistant_role_icon')) + ' ' + escHtml(res.data.cargo) + '<br>' : '' ) +
                                    ( res.data.email   ? escHtml(t('assistant_email_icon')) + ' ' + escHtml(res.data.email) : '' ) +
                                    '</div>';
                                showStep('evapp-gi-step-2');
                            } else {
                                showMsg( msg1, t('validate_server_error'), 'error' );
                            }
                        })
                        .catch(function(){
                            setReady( btnValidar, t('validate_button') );
                            showMsg( msg1, t('connection_error'), 'error' );
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
                            alert(t('camera_permission_error'));
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
                            if ( msgStep3 ) showMsg( msgStep3, t('save_server_error'), 'error' );
                        }
                    })
                    .catch(function(){
                        showStep('evapp-gi-step-3');
                        if ( msgStep3 ) showMsg( msgStep3, t('connection_error'), 'error' );
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
                    img.onerror = function() { reject(new Error(t('image_load_error', { src: (isDataUrl ? '[data URL]' : src) }))); };
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
                    evappGiSetProgress(5, t('progress_loading_models'));
                    if ( typeof faceapi === 'undefined' ) throw new Error(t('face_engine_error'));
                    await Promise.all([
                        faceapi.nets.ssdMobilenetv1.isLoaded    ? Promise.resolve() : faceapi.nets.ssdMobilenetv1.loadFromUri(faceModelsUrl),
                        faceapi.nets.faceLandmark68Net.isLoaded  ? Promise.resolve() : faceapi.nets.faceLandmark68Net.loadFromUri(faceModelsUrl),
                        faceapi.nets.faceRecognitionNet.isLoaded ? Promise.resolve() : faceapi.nets.faceRecognitionNet.loadFromUri(faceModelsUrl),
                    ]);
                    evappGiSetProgress(15, t('progress_analyzing_refs', { count: fotosDataUrls.length }));
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
                    evappGiSetProgress(25, t('progress_comparing'));
                    var matches = [], total = imagenes.length;
                    for ( var i = 0; i < total; i++ ) {
                        var foto = imagenes[i];
                        evappGiSetProgress(25 + Math.round((i / total) * 70), t('progress_analyzing_photo', { current: i + 1, total: total }));
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
                    evappGiSetProgress(100, t('progress_completed'));
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
                        ? t('results_count_one')
                        : t('results_count_many', { count: matches.length });
                }
                var html = '<div class="evapp-gi-results-slides">';
                matches.forEach(function(m, idx) {
                    var altTxt = escHtml(m.photo.alt || t('photo_label', { num: idx + 1 }));
                    html += '<div class="evapp-gi-result-slide' + (idx === 0 ? ' active' : '') + '" data-ri="' + idx + '">' +
                            '<img src="' + escHtml(m.photo.full) + '" alt="' + altTxt + '" loading="' + (idx === 0 ? 'eager' : 'lazy') + '" /></div>';
                });
                html += '</div>';
                html += '<div class="evapp-gi-results-nav-row">' +
                        '<button type="button" class="evapp-gi-results-nav-btn evapp-gi-res-prev" aria-label="' + escHtml(t('results_prev_label')) + '">&#8249;</button>' +
                        '<span class="evapp-gi-results-counter"><span class="evapp-gi-res-cur">1</span> / ' + matches.length + '</span>' +
                        '<button type="button" class="evapp-gi-results-nav-btn evapp-gi-res-next" aria-label="' + escHtml(t('results_next_label')) + '">&#8250;</button>' +
                        '</div>' +
                        '<a class="evapp-gi-download-btn evapp-gi-dl-btn" href="' + escHtml(matches[0].photo.full) + '" download target="_blank">' + escHtml(t('download_button')) + '</a>';
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
                detenerCamara();
                evappGiSetFinalResponseMode(false);

                if ( inputCedula ) inputCedula.value = '';
                if ( inputApell  ) inputApell.value  = '';
                if ( uploadGuide  ) uploadGuide.style.display  = 'none';
                if ( camWrap      ) camWrap.style.display      = 'none';
                if ( fotoOpciones ) fotoOpciones.style.display = '';

                wizard.querySelectorAll('.evapp-gi-msg').forEach(function(msg){ hideMsg(msg); });

                var fi = wizard.querySelector('.evapp-gi-file-input');
                if ( fi ) fi.value = '';

                var uploadImg = wizard.querySelector('.evapp-gi-upload-preview-img');
                if ( uploadImg ) uploadImg.removeAttribute('src');

                var previewImg = wizard.querySelector('.evapp-gi-preview-final-img');
                if ( previewImg ) previewImg.removeAttribute('src');

                var resultsCount = wizard.querySelector('.evapp-gi-results-count');
                if ( resultsCount ) resultsCount.textContent = '';

                var resultsCarousel = wizard.querySelector('.evapp-gi-results-carousel-wrap');
                if ( resultsCarousel ) resultsCarousel.innerHTML = '';

                evappGiActualizarStrip();
                wizard.style.display      = 'none';
                triggerWrap.style.display = '';
                evappGiScrollToGalleryStart();
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
    // Encolar siempre en frontend: las reglas son específicas de .evapp-galeria-*
    // y también deben cargarse cuando la galería se inserta desde el widget Elementor.
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
    display: flex;
    flex-direction: column;
}
.evapp-galeria-wrap > .evapp-galeria-header { order: 10; }
.evapp-galeria-wrap > .evapp-galeria-descripcion { order: 20; }
.evapp-galeria-wrap > .evapp-galeria-main-wrap { order: 30; }
.evapp-galeria-wrap > .evapp-galeria-counter { order: 40; }
.evapp-galeria-wrap > .evapp-galeria-thumbs-wrap { order: 50; }
.evapp-galeria-wrap > .evapp-gi-finder-section { order: 60; }
.evapp-galeria-wrap > .evapp-galeria-lightbox { order: 999; }
.evapp-galeria-wrap.evapp-gi-final-response-active > .evapp-galeria-main-wrap,
.evapp-galeria-wrap.evapp-gi-final-response-active > .evapp-galeria-counter,
.evapp-galeria-wrap.evapp-gi-final-response-active > .evapp-galeria-thumbs-wrap {
    display: none !important;
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
.evapp-gh-organizador-label {
    color: #888;
    font-size: 13px;
    margin-right: 1px;
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
.evapp-galeria-thumbs-wrap,
.evapp-galeria-thumbs {
    display: flex;
    gap: 6px;
    overflow-x: auto;
    padding: 6px 0 8px;
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
}
.evapp-galeria-thumbs-wrap::-webkit-scrollbar,
.evapp-galeria-thumbs::-webkit-scrollbar { height: 4px; }
.evapp-galeria-thumbs-wrap::-webkit-scrollbar-thumb,
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


// ============================================================
// 9. WIDGET ELEMENTOR: Galería EventosApp con controles de estilo
// ============================================================

add_action( 'elementor/elements/categories_registered', function ( $elements_manager ) {
    if ( is_object( $elements_manager ) && method_exists( $elements_manager, 'add_category' ) ) {
        $elements_manager->add_category( 'eventosapp', [
            'title' => 'EventosApp',
            'icon'  => 'fa fa-plug',
        ] );
    }
} );

add_action( 'elementor/widgets/register', 'evapp_galeria_register_elementor_widget' );
add_action( 'elementor/widgets/widgets_registered', 'evapp_galeria_register_elementor_widget' );

function evapp_galeria_register_elementor_widget( $widgets_manager ) {
    static $registered = false;

    if ( $registered ) return;
    if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\\Elementor\\Widget_Base' ) ) return;

    if ( ! class_exists( 'Evapp_Elementor_Galeria_Widget' ) ) {
        class Evapp_Elementor_Galeria_Widget extends \Elementor\Widget_Base {

            public function get_name() { return 'eventosapp_galeria'; }
            public function get_title() { return 'EventosApp – Galería'; }
            public function get_icon() { return 'eicon-gallery-grid'; }
            public function get_categories() { return [ 'eventosapp' ]; }
            public function get_keywords() { return [ 'eventosapp', 'galeria', 'galería', 'fotos', 'evento', 'ia', 'buscador' ]; }

            private function get_galerias_options() {
                $options = [ '' => '— Selecciona una galería —' ];
                $galerias = get_posts( [
                    'post_type'      => 'eventosapp_galeria',
                    'post_status'    => [ 'publish', 'draft', 'private' ],
                    'posts_per_page' => -1,
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                ] );
                foreach ( $galerias as $galeria ) {
                    $options[ $galeria->ID ] = $galeria->post_title . ' (#' . $galeria->ID . ')';
                }
                return $options;
            }

            private function add_box_controls( $prefix, $selector, $include_background = true, $include_shadow = true ) {
                if ( $include_background ) {
                    $this->add_group_control( \Elementor\Group_Control_Background::get_type(), [
                        'name'     => $prefix . '_background',
                        'selector' => $selector,
                    ] );
                }
                $this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
                    'name'     => $prefix . '_border',
                    'selector' => $selector,
                ] );
                $this->add_responsive_control( $prefix . '_radius', [
                    'label'      => 'Radio de borde',
                    'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                    'size_units' => [ 'px', '%', 'em' ],
                    'selectors'  => [ $selector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                ] );
                $this->add_responsive_control( $prefix . '_padding', [
                    'label'      => 'Padding',
                    'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                    'size_units' => [ 'px', '%', 'em' ],
                    'selectors'  => [ $selector => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                ] );
                $this->add_responsive_control( $prefix . '_margin', [
                    'label'      => 'Margen',
                    'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                    'size_units' => [ 'px', '%', 'em' ],
                    'selectors'  => [ $selector => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                ] );
                if ( $include_shadow ) {
                    $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
                        'name'     => $prefix . '_shadow',
                        'selector' => $selector,
                    ] );
                }
            }

            private function add_text_controls( $prefix, $selector, $label = 'Texto' ) {
                $this->add_control( $prefix . '_heading', [
                    'label'     => $label,
                    'type'      => \Elementor\Controls_Manager::HEADING,
                    'separator' => 'before',
                ] );
                $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                    'name'     => $prefix . '_typography',
                    'selector' => $selector,
                ] );
                $this->add_control( $prefix . '_color', [
                    'label'     => 'Color',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ $selector => 'color: {{VALUE}};' ],
                ] );
                $this->add_responsive_control( $prefix . '_align', [
                    'label'     => 'Alineación del texto',
                    'type'      => \Elementor\Controls_Manager::CHOOSE,
                    'options'   => [
                        'left'   => [ 'title' => 'Izquierda', 'icon' => 'eicon-text-align-left' ],
                        'center' => [ 'title' => 'Centro',    'icon' => 'eicon-text-align-center' ],
                        'right'  => [ 'title' => 'Derecha',   'icon' => 'eicon-text-align-right' ],
                    ],
                    'selectors' => [ $selector => 'text-align: {{VALUE}};' ],
                    'toggle'    => true,
                ] );
                $this->add_responsive_control( $prefix . '_spacing', [
                    'label'      => 'Margen',
                    'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                    'size_units' => [ 'px', 'em', '%' ],
                    'selectors'  => [ $selector => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                ] );
            }

            private function add_text_align_control( $control_id, $label, $selector ) {
                $this->add_responsive_control( $control_id, [
                    'label'     => $label,
                    'type'      => \Elementor\Controls_Manager::CHOOSE,
                    'options'   => [
                        'left'   => [ 'title' => 'Izquierda', 'icon' => 'eicon-text-align-left' ],
                        'center' => [ 'title' => 'Centro',    'icon' => 'eicon-text-align-center' ],
                        'right'  => [ 'title' => 'Derecha',   'icon' => 'eicon-text-align-right' ],
                    ],
                    'selectors' => [ $selector => 'text-align: {{VALUE}};' ],
                    'toggle'    => true,
                ] );
            }

            private function add_flex_justify_control( $control_id, $label, $selector ) {
                $this->add_responsive_control( $control_id, [
                    'label'     => $label,
                    'type'      => \Elementor\Controls_Manager::CHOOSE,
                    'options'   => [
                        'flex-start' => [ 'title' => 'Izquierda', 'icon' => 'eicon-text-align-left' ],
                        'center'     => [ 'title' => 'Centro',    'icon' => 'eicon-text-align-center' ],
                        'flex-end'   => [ 'title' => 'Derecha',   'icon' => 'eicon-text-align-right' ],
                    ],
                    'selectors' => [ $selector => 'justify-content: {{VALUE}};' ],
                    'toggle'    => true,
                ] );
            }

            private function add_flex_items_align_control( $control_id, $label, $selector ) {
                $this->add_responsive_control( $control_id, [
                    'label'     => $label,
                    'type'      => \Elementor\Controls_Manager::CHOOSE,
                    'options'   => [
                        'flex-start' => [ 'title' => 'Izquierda', 'icon' => 'eicon-text-align-left' ],
                        'center'     => [ 'title' => 'Centro',    'icon' => 'eicon-text-align-center' ],
                        'flex-end'   => [ 'title' => 'Derecha',   'icon' => 'eicon-text-align-right' ],
                    ],
                    'selectors' => [ $selector => 'align-items: {{VALUE}};' ],
                    'toggle'    => true,
                ] );
            }

            private function add_button_controls( $prefix, $selector, $hover_selector, $label = 'Botón' ) {
                $this->add_control( $prefix . '_heading', [
                    'label'     => $label,
                    'type'      => \Elementor\Controls_Manager::HEADING,
                    'separator' => 'before',
                ] );
                $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                    'name'     => $prefix . '_typography',
                    'selector' => $selector,
                ] );
                $this->add_flex_justify_control( $prefix . '_content_justify', 'Alineación del contenido', $selector );
                $this->add_text_align_control( $prefix . '_text_align', 'Alineación del texto', $selector );
                $this->start_controls_tabs( $prefix . '_tabs' );
                $this->start_controls_tab( $prefix . '_normal', [ 'label' => 'Normal' ] );
                $this->add_control( $prefix . '_color', [
                    'label'     => 'Color texto',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ $selector => 'color: {{VALUE}};' ],
                ] );
                $this->add_control( $prefix . '_bg', [
                    'label'     => 'Fondo',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ $selector => 'background: {{VALUE}};' ],
                ] );
                $this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
                    'name'     => $prefix . '_border',
                    'selector' => $selector,
                ] );
                $this->add_responsive_control( $prefix . '_radius', [
                    'label'      => 'Radio',
                    'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                    'size_units' => [ 'px', '%', 'em' ],
                    'selectors'  => [ $selector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                ] );
                $this->add_responsive_control( $prefix . '_padding', [
                    'label'      => 'Padding',
                    'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                    'size_units' => [ 'px', 'em' ],
                    'selectors'  => [ $selector => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                ] );
                $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
                    'name'     => $prefix . '_shadow',
                    'selector' => $selector,
                ] );
                $this->end_controls_tab();

                $this->start_controls_tab( $prefix . '_hover', [ 'label' => 'Hover' ] );
                $this->add_control( $prefix . '_hover_color', [
                    'label'     => 'Color texto hover',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ $hover_selector => 'color: {{VALUE}};' ],
                ] );
                $this->add_control( $prefix . '_hover_bg', [
                    'label'     => 'Fondo hover',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ $hover_selector => 'background: {{VALUE}};' ],
                ] );
                $this->add_control( $prefix . '_hover_border_color', [
                    'label'     => 'Color borde hover',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ $hover_selector => 'border-color: {{VALUE}};' ],
                ] );
                $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
                    'name'     => $prefix . '_hover_shadow',
                    'selector' => $hover_selector,
                ] );
                $this->add_control( $prefix . '_hover_transform', [
                    'label'     => 'Animación hover',
                    'type'      => \Elementor\Controls_Manager::SELECT,
                    'default'   => '',
                    'options'   => [
                        ''                 => 'Por defecto',
                        'scale(1.04)'      => 'Aumentar suave',
                        'translateY(-2px)' => 'Subir suave',
                        'none'             => 'Sin movimiento',
                    ],
                    'selectors' => [ $hover_selector => 'transform: {{VALUE}};' ],
                ] );
                $this->end_controls_tab();
                $this->end_controls_tabs();

                $this->add_control( $prefix . '_transition', [
                    'label'      => 'Duración transición',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 's', 'ms' ],
                    'range'      => [
                        's'  => [ 'min' => 0, 'max' => 3, 'step' => 0.05 ],
                        'ms' => [ 'min' => 0, 'max' => 3000, 'step' => 50 ],
                    ],
                    'selectors'  => [ $selector => 'transition-duration: {{SIZE}}{{UNIT}};' ],
                    'separator'  => 'before',
                ] );
            }

            private function add_order_control( $control_id, $label, $selector, $default, $description = '' ) {
                $this->add_responsive_control( $control_id, [
                    'label'       => $label,
                    'type'        => \Elementor\Controls_Manager::NUMBER,
                    'default'     => $default,
                    'min'         => 0,
                    'max'         => 999,
                    'step'        => 1,
                    'description' => $description,
                    'selectors'   => [
                        '{{WRAPPER}} .evapp-galeria-wrap' => 'display:flex; flex-direction:column;',
                        $selector                         => 'order: {{VALUE}};',
                    ],
                ] );
            }

            private function add_ai_text_control( $key, $label, $type = 'text' ) {
                $defaults = evapp_galeria_ia_default_texts();
                $control_type = $type === 'textarea' ? \Elementor\Controls_Manager::TEXTAREA : \Elementor\Controls_Manager::TEXT;

                $this->add_control( $key, [
                    'label'       => $label,
                    'type'        => $control_type,
                    'default'     => isset( $defaults[ $key ] ) ? $defaults[ $key ] : '',
                    'label_block' => true,
                ] );
            }

            private function add_ai_text_heading( $label ) {
                $this->add_control( sanitize_key( 'ai_text_heading_' . md5( $label ) ), [
                    'label'     => $label,
                    'type'      => \Elementor\Controls_Manager::HEADING,
                    'separator' => 'before',
                ] );
            }

            protected function register_controls() {

                $this->start_controls_section( 'section_content', [
                    'label' => 'Contenido',
                    'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                ] );
                $this->add_control( 'galeria_id', [
                    'label'       => 'Galería',
                    'type'        => \Elementor\Controls_Manager::SELECT2,
                    'options'     => $this->get_galerias_options(),
                    'default'     => '',
                    'label_block' => true,
                    'description' => 'Selecciona una galería creada en EventosApp. Conserva el mismo render del shortcode para no romper compatibilidad.',
                ] );
                $this->end_controls_section();
                $this->start_controls_section( 'section_ai_texts_cta_identity', [
                    'label' => 'Flujo IA: textos CTA e identidad',
                    'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                ] );
                $this->add_ai_text_heading( 'CTA inicial' );
                $this->add_ai_text_control( 'promo_question', 'Pregunta principal' );
                $this->add_ai_text_control( 'promo_highlight', 'Frase destacada' );
                $this->add_ai_text_control( 'promo_button', 'Botón CTA' );

                $this->add_ai_text_heading( 'Paso 1 — Validación' );
                $this->add_ai_text_control( 'badge_step_1', 'Etiqueta paso 1' );
                $this->add_ai_text_control( 'step1_title', 'Título paso 1' );
                $this->add_ai_text_control( 'step1_desc', 'Descripción paso 1', 'textarea' );
                $this->add_ai_text_control( 'cedula_label', 'Label identificación' );
                $this->add_ai_text_control( 'cedula_placeholder', 'Placeholder identificación' );
                $this->add_ai_text_control( 'apellidos_label', 'Label apellidos' );
                $this->add_ai_text_control( 'apellidos_placeholder', 'Placeholder apellidos' );
                $this->add_ai_text_control( 'step1_hint', 'Texto de ayuda' );
                $this->add_ai_text_control( 'validate_button', 'Botón validar' );
                $this->add_ai_text_control( 'validate_loading', 'Texto botón validando' );
                $this->add_ai_text_control( 'validate_empty_error', 'Error campos vacíos' );
                $this->add_ai_text_control( 'validate_server_error', 'Error validación no encontrada' );
                $this->add_ai_text_control( 'connection_error', 'Error conexión' );

                $this->add_ai_text_heading( 'Paso 2 — Asistente encontrado' );
                $this->add_ai_text_control( 'badge_verified', 'Etiqueta verificado' );
                $this->add_ai_text_control( 'step2_title', 'Título paso 2' );
                $this->add_ai_text_control( 'step2_button', 'Botón hacia captura' );
                $this->add_ai_text_control( 'assistant_company_icon', 'Icono empresa' );
                $this->add_ai_text_control( 'assistant_role_icon', 'Icono cargo' );
                $this->add_ai_text_control( 'assistant_email_icon', 'Icono email' );
                $this->end_controls_section();

                $this->start_controls_section( 'section_ai_texts_capture', [
                    'label' => 'Flujo IA: textos captura y cámara',
                    'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                ] );
                $this->add_ai_text_heading( 'Paso 3 — Captura de fotos' );
                $this->add_ai_text_control( 'badge_step_2', 'Etiqueta paso 2' );
                $this->add_ai_text_control( 'step3_title', 'Título captura' );
                $this->add_ai_text_control( 'step3_desc', 'Descripción captura', 'textarea' );
                $this->add_ai_text_control( 'tip1_icon', 'Emoji fallback tip 1' );
                $this->add_ai_text_control( 'tip1_text', 'Texto tip 1' );
                $this->add_ai_text_control( 'tip2_icon', 'Emoji fallback tip 2' );
                $this->add_ai_text_control( 'tip2_text', 'Texto tip 2' );
                $this->add_ai_text_control( 'tip3_icon', 'Emoji fallback tip 3' );
                $this->add_ai_text_control( 'tip3_text', 'Texto tip 3' );
                $this->add_ai_text_control( 'strip_empty', 'Estado sin fotos', 'textarea' );
                $this->add_ai_text_control( 'strip_one', 'Estado 1 foto', 'textarea' );
                $this->add_ai_text_control( 'strip_two', 'Estado 2 fotos', 'textarea' );
                $this->add_ai_text_control( 'strip_three', 'Estado 3 fotos', 'textarea' );
                $this->add_ai_text_control( 'photo_label', 'Label foto cargada. Usa {num}' );
                $this->add_ai_text_control( 'upload_button', 'Botón subir foto' );
                $this->add_ai_text_control( 'camera_button', 'Botón tomar foto' );
                $this->add_ai_text_control( 'upload_guide_text', 'Instrucción preview', 'textarea' );
                $this->add_ai_text_control( 'upload_preview_alt', 'Alt preview subida' );
                $this->add_ai_text_control( 'approve_upload_button', 'Botón aprobar foto subida' );
                $this->add_ai_text_control( 'choose_other_button', 'Botón elegir otra foto' );
                $this->add_ai_text_control( 'camera_label', 'Label cámara' );
                $this->add_ai_text_control( 'capture_button', 'Botón capturar' );
                $this->add_ai_text_control( 'cancel_camera_button', 'Botón cancelar cámara' );
                $this->add_ai_text_control( 'continue_photos_prefix', 'Texto antes del contador' );
                $this->add_ai_text_control( 'continue_photos_suffix', 'Texto después del contador' );
                $this->add_ai_text_control( 'camera_permission_error', 'Alerta permiso cámara', 'textarea' );

                $this->add_ai_text_heading( 'Paso 4 — Confirmación de foto' );
                $this->add_ai_text_control( 'badge_step_3', 'Etiqueta paso 3' );
                $this->add_ai_text_control( 'step4_title', 'Título confirmar foto' );
                $this->add_ai_text_control( 'step4_desc', 'Descripción confirmar foto', 'textarea' );
                $this->add_ai_text_control( 'preview_final_alt', 'Alt foto final' );
                $this->add_ai_text_control( 'confirm_photo_button', 'Botón agregar foto' );
                $this->add_ai_text_control( 'retake_photo_button', 'Botón repetir foto' );
                $this->end_controls_section();

                $this->start_controls_section( 'section_ai_texts_states_results', [
                    'label' => 'Flujo IA: textos carga y resultados',
                    'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                ] );
                $this->add_ai_text_heading( 'Carga después de recibir las fotos' );
                $this->add_ai_text_control( 'loading_title', 'Título procesando fotos' );
                $this->add_ai_text_control( 'loading_desc', 'Descripción procesando fotos', 'textarea' );
                $this->add_ai_text_control( 'save_server_error', 'Error guardando fotos' );

                $this->add_ai_text_heading( 'Fotos recibidas / éxito' );
                $this->add_ai_text_control( 'success_icon', 'Emoticon de fotos recibidas' );
                $this->add_control( 'success_image', [
                    'label'       => 'Imagen o GIF de fotos recibidas',
                    'type'        => \Elementor\Controls_Manager::MEDIA,
                    'description' => 'Reemplaza el emoticon del paso “¡Ya tenemos todo!”. Si no subes imagen o GIF, se seguirá usando el emoticon configurado arriba.',
                ] );
                $this->add_ai_text_control( 'success_image_alt', 'Texto alternativo imagen/GIF fotos recibidas' );
                $this->add_ai_text_control( 'success_title', 'Título fotos recibidas' );
                $this->add_ai_text_control( 'success_desc', 'Descripción fotos recibidas', 'textarea' );
                $this->add_ai_text_control( 'success_button', 'Botón buscar mis fotos' );

                $this->add_ai_text_heading( 'Búsqueda IA' );
                $this->add_ai_text_control( 'searching_title', 'Título buscando' );
                $this->add_ai_text_control( 'progress_loading_models', 'Progreso cargando modelos' );
                $this->add_ai_text_control( 'progress_analyzing_refs', 'Progreso analizando referencias. Usa {count}' );
                $this->add_ai_text_control( 'progress_comparing', 'Progreso comparando' );
                $this->add_ai_text_control( 'progress_analyzing_photo', 'Progreso foto. Usa {current} y {total}' );
                $this->add_ai_text_control( 'progress_completed', 'Progreso completado' );
                $this->add_ai_text_control( 'face_engine_error', 'Error motor IA' );
                $this->add_ai_text_control( 'image_load_error', 'Error cargar imagen. Usa {src}' );

                $this->add_ai_text_heading( 'Resultados' );
                $this->add_ai_text_control( 'results_badge', 'Etiqueta búsqueda completada' );
                $this->add_control( 'results_image', [
                    'label'       => 'Imagen superior resultado final',
                    'type'        => \Elementor\Controls_Manager::MEDIA,
                    'description' => 'Imagen o GIF opcional para mostrar encima del indicador, título y subtítulo del resultado final.',
                ] );
                $this->add_control( 'results_image_alt', [
                    'label'       => 'Texto alternativo imagen resultado final',
                    'type'        => \Elementor\Controls_Manager::TEXT,
                    'default'     => '',
                    'label_block' => true,
                ] );
                $this->add_ai_text_control( 'results_title', 'Título resultados' );
                $this->add_ai_text_control( 'results_count_one', 'Texto resultado 1 foto' );
                $this->add_ai_text_control( 'results_count_many', 'Texto resultados varias fotos. Usa {count}' );
                $this->add_control( 'results_order_heading', [
                    'label'     => 'Orden de elementos del resultado final',
                    'type'      => \Elementor\Controls_Manager::HEADING,
                    'separator' => 'before',
                ] );
                $this->add_control( 'results_order_image', [
                    'label'       => 'Orden: imagen superior',
                    'type'        => \Elementor\Controls_Manager::NUMBER,
                    'default'     => 5,
                    'min'         => 0,
                    'max'         => 999,
                    'step'        => 1,
                    'description' => 'El número más bajo aparece primero. Si no subes imagen, este orden no se usa.',
                ] );
                $this->add_control( 'results_order_badge', [
                    'label'   => 'Orden: indicador del paso',
                    'type'    => \Elementor\Controls_Manager::NUMBER,
                    'default' => 10,
                    'min'     => 0,
                    'max'     => 999,
                    'step'    => 1,
                ] );
                $this->add_control( 'results_order_title', [
                    'label'   => 'Orden: título',
                    'type'    => \Elementor\Controls_Manager::NUMBER,
                    'default' => 20,
                    'min'     => 0,
                    'max'     => 999,
                    'step'    => 1,
                ] );
                $this->add_control( 'results_order_subtitle', [
                    'label'   => 'Orden: subtítulo / contador encontrado',
                    'type'    => \Elementor\Controls_Manager::NUMBER,
                    'default' => 30,
                    'min'     => 0,
                    'max'     => 999,
                    'step'    => 1,
                ] );
                $this->add_ai_text_control( 'results_prev_label', 'Aria flecha anterior' );
                $this->add_ai_text_control( 'results_next_label', 'Aria flecha siguiente' );
                $this->add_ai_text_control( 'download_button', 'Botón descargar foto' );
                $this->add_ai_text_control( 'back_start_button', 'Botón volver al inicio' );

                $this->add_ai_text_heading( 'Sin resultados' );
                $this->add_ai_text_control( 'no_results_icon', 'Emoticon sin resultados' );
                $this->add_ai_text_control( 'no_results_title', 'Título sin resultados' );
                $this->add_ai_text_control( 'no_results_desc', 'Descripción sin resultados', 'textarea' );
                $this->add_ai_text_control( 'try_other_photo_button', 'Botón intentar con otras fotos' );
                $this->end_controls_section();

                $this->start_controls_section( 'section_ai_instruction_images', [
                    'label' => 'Flujo IA: imágenes instrucciones de fotos',
                    'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                ] );
                $this->add_control( 'tip_images_help', [
                    'type'            => \Elementor\Controls_Manager::RAW_HTML,
                    'raw'             => 'Sube una imagen para reemplazar el emoji de cada tarjeta de instrucciones. Si dejas una imagen vacía, se seguirá usando el emoji fallback configurado en “Flujo IA: textos captura y cámara”.',
                    'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
                ] );
                $this->add_control( 'tip1_image_heading', [
                    'label'     => 'Instrucción 1',
                    'type'      => \Elementor\Controls_Manager::HEADING,
                    'separator' => 'before',
                ] );
                $this->add_control( 'tip1_image', [
                    'label'       => 'Imagen instrucción 1',
                    'type'        => \Elementor\Controls_Manager::MEDIA,
                    'description' => 'Reemplaza el emoji de “De frente, sin accesorios”.',
                ] );
                $this->add_ai_text_control( 'tip1_image_alt', 'Texto alternativo imagen 1' );

                $this->add_control( 'tip2_image_heading', [
                    'label'     => 'Instrucción 2',
                    'type'      => \Elementor\Controls_Manager::HEADING,
                    'separator' => 'before',
                ] );
                $this->add_control( 'tip2_image', [
                    'label'       => 'Imagen instrucción 2',
                    'type'        => \Elementor\Controls_Manager::MEDIA,
                    'description' => 'Reemplaza el emoji de “Con gafas o sombrero si los usas”.',
                ] );
                $this->add_ai_text_control( 'tip2_image_alt', 'Texto alternativo imagen 2' );

                $this->add_control( 'tip3_image_heading', [
                    'label'     => 'Instrucción 3',
                    'type'      => \Elementor\Controls_Manager::HEADING,
                    'separator' => 'before',
                ] );
                $this->add_control( 'tip3_image', [
                    'label'       => 'Imagen instrucción 3',
                    'type'        => \Elementor\Controls_Manager::MEDIA,
                    'description' => 'Reemplaza el emoji de “Leve ángulo lateral”.',
                ] );
                $this->add_ai_text_control( 'tip3_image_alt', 'Texto alternativo imagen 3' );
                $this->end_controls_section();

                $this->start_controls_section( 'section_ai_spinner_icons', [
                    'label' => 'Flujo IA: spinner e iconos',
                    'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                ] );
                $this->add_control( 'spinner_type', [
                    'label'   => 'Tipo de spinner',
                    'type'    => \Elementor\Controls_Manager::SELECT,
                    'default' => 'css',
                    'options' => [
                        'css'   => 'Spinner circular actual',
                        'emoji' => 'Emoji / texto',
                        'image' => 'Imagen personalizada',
                        'none'  => 'Ocultar spinner',
                    ],
                ] );
                $this->add_ai_text_control( 'spinner_icon', 'Emoji / texto del spinner' );
                $this->add_control( 'spinner_image', [
                    'label'     => 'Imagen del spinner',
                    'type'      => \Elementor\Controls_Manager::MEDIA,
                    'condition' => [ 'spinner_type' => 'image' ],
                ] );
                $this->end_controls_section();

                $this->start_controls_section( 'section_ai_cta_layout_media', [
                    'label' => 'Flujo IA: CTA imagen, orientación y orden',
                    'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                ] );
                $this->add_control( 'cta_layout', [
                    'label'   => 'Orientación de elementos del CTA inicial',
                    'type'    => \Elementor\Controls_Manager::SELECT,
                    'default' => 'vertical',
                    'options' => [
                        'vertical'   => 'Vertical',
                        'horizontal' => 'Horizontal',
                    ],
                ] );
                $this->add_control( 'cta_image', [
                    'label'       => 'Imagen inicial del CTA',
                    'type'        => \Elementor\Controls_Manager::MEDIA,
                    'description' => 'Imagen normal de la mascota o caricatura. Si no se sube imagen, el CTA conserva solo texto y botón.',
                ] );
                $this->add_control( 'cta_hover_image', [
                    'label'       => 'Imagen al pasar el cursor sobre el banner',
                    'type'        => \Elementor\Controls_Manager::MEDIA,
                    'description' => 'Esta imagen reemplaza de forma animada a la imagen inicial cuando el cursor pasa sobre el contenedor del flujo IA.',
                    'condition'   => [ 'cta_image[url]!' => '' ],
                ] );
                $this->add_control( 'cta_image_alt', [
                    'label'       => 'Texto alternativo de la imagen',
                    'type'        => \Elementor\Controls_Manager::TEXT,
                    'default'     => '',
                    'label_block' => true,
                ] );
                $this->add_control( 'cta_order_heading', [
                    'label'     => 'Orden de elementos del CTA inicial',
                    'type'      => \Elementor\Controls_Manager::HEADING,
                    'separator' => 'before',
                ] );
                $this->add_control( 'cta_order_image', [
                    'label'       => 'Orden: imagen',
                    'type'        => \Elementor\Controls_Manager::NUMBER,
                    'default'     => 10,
                    'min'         => 0,
                    'max'         => 999,
                    'step'        => 1,
                    'description' => 'El número más bajo aparece primero.',
                ] );
                $this->add_control( 'cta_order_text', [
                    'label'       => 'Orden: frases',
                    'type'        => \Elementor\Controls_Manager::NUMBER,
                    'default'     => 20,
                    'min'         => 0,
                    'max'         => 999,
                    'step'        => 1,
                ] );
                $this->add_control( 'cta_order_button', [
                    'label'       => 'Orden: botón',
                    'type'        => \Elementor\Controls_Manager::NUMBER,
                    'default'     => 30,
                    'min'         => 0,
                    'max'         => 999,
                    'step'        => 1,
                ] );
                $this->end_controls_section();


                $this->start_controls_section( 'style_layout_order', [
                    'label' => 'Ubicación / orden de bloques',
                    'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                ] );
                $this->add_control( 'layout_order_help', [
                    'type'            => \Elementor\Controls_Manager::RAW_HTML,
                    'raw'             => 'Cada bloque se puede mover de forma independiente. El número más bajo aparece más arriba. Los valores por defecto conservan el orden actual.',
                    'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
                ] );
                $this->add_order_control(
                    'order_header',
                    'Título y detalles del evento',
                    '{{WRAPPER}} .evapp-galeria-wrap > .evapp-galeria-header',
                    10,
                    'Bloque superior con el título, fecha, lugar, cantidad de fotos y organizador.'
                );
                $this->add_order_control(
                    'order_description',
                    'Descripción de la galería',
                    '{{WRAPPER}} .evapp-galeria-wrap > .evapp-galeria-descripcion',
                    20,
                    'Solo se verá cuando la galería tenga descripción guardada.'
                );
                $this->add_order_control(
                    'order_main_gallery',
                    'Galería principal / foto grande',
                    '{{WRAPPER}} .evapp-galeria-wrap > .evapp-galeria-main-wrap',
                    30,
                    'Caja principal de imagen grande con flechas laterales.'
                );
                $this->add_order_control(
                    'order_counter',
                    'Contador de fotos',
                    '{{WRAPPER}} .evapp-galeria-wrap > .evapp-galeria-counter',
                    40,
                    'Contador tipo 1 / 24.'
                );
                $this->add_order_control(
                    'order_thumbnails',
                    'Miniaturas',
                    '{{WRAPPER}} .evapp-galeria-wrap > .evapp-galeria-thumbs-wrap',
                    50,
                    'Tira horizontal de miniaturas.'
                );
                $this->add_order_control(
                    'order_ai_flow',
                    'Flujo IA: contenedor y CTA',
                    '{{WRAPPER}} .evapp-galeria-wrap > .evapp-gi-finder-section',
                    60,
                    'Para ubicarlo antes de la galería principal, usa un número menor que el bloque “Galería principal / foto grande”. Ejemplo: 25.'
                );
                $this->end_controls_section();

                $this->start_controls_section( 'style_container', [ 'label' => 'Contenedor general', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ] );
                $this->add_responsive_control( 'container_max_width', [
                    'label'      => 'Ancho máximo',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', '%', 'vw' ],
                    'range'      => [ 'px' => [ 'min' => 300, 'max' => 1800 ], '%' => [ 'min' => 20, 'max' => 100 ], 'vw' => [ 'min' => 20, 'max' => 100 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap' => 'max-width: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_box_controls( 'container', '{{WRAPPER}} .evapp-galeria-wrap' );
                $this->end_controls_section();

                $this->start_controls_section( 'style_header', [ 'label' => 'Título y detalles del evento', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ] );
                $this->add_box_controls( 'header', '{{WRAPPER}} .evapp-galeria-header' );
                $this->add_text_controls( 'gallery_title', '{{WRAPPER}} .evapp-galeria-header-title', 'Título' );
                $this->add_flex_justify_control( 'gallery_meta_group_align', 'Alineación del bloque de detalles', '{{WRAPPER}} .evapp-galeria-header-meta' );
                $this->add_text_controls( 'gallery_meta', '{{WRAPPER}} .evapp-galeria-header-meta, {{WRAPPER}} .evapp-gh-meta-item, {{WRAPPER}} .evapp-gh-organizador-label, {{WRAPPER}} .evapp-gh-cliente-nombre', 'Detalles del evento' );
                $this->add_flex_justify_control( 'gallery_meta_items_align', 'Alineación interna texto + ícono', '{{WRAPPER}} .evapp-gh-meta-item' );
                $this->add_control( 'meta_icon_color', [
                    'label'     => 'Color íconos de detalles',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ '{{WRAPPER}} .evapp-gh-icon' => 'color: {{VALUE}};' ],
                ] );
                $this->add_responsive_control( 'meta_gap', [
                    'label'      => 'Separación entre detalles',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'em' ],
                    'range'      => [ 'px' => [ 'min' => 0, 'max' => 80 ], 'em' => [ 'min' => 0, 'max' => 5 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-header-meta' => 'column-gap: {{SIZE}}{{UNIT}}; row-gap: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_responsive_control( 'client_logo_size', [
                    'label'      => 'Tamaño logo organizador',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px' ],
                    'range'      => [ 'px' => [ 'min' => 12, 'max' => 160 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-gh-cliente-logo' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->end_controls_section();

                $this->start_controls_section( 'style_description', [ 'label' => 'Descripción de la galería', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ] );
                $this->add_text_controls( 'description', '{{WRAPPER}} .evapp-galeria-descripcion', 'Descripción' );
                $this->add_box_controls( 'description_box', '{{WRAPPER}} .evapp-galeria-descripcion' );
                $this->end_controls_section();

                $this->start_controls_section( 'style_main_photo', [ 'label' => 'Caja de foto principal', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ] );
                $this->add_box_controls( 'main_box', '{{WRAPPER}} .evapp-galeria-main-wrap, {{WRAPPER}} .evapp-galeria-slides-wrap' );
                $this->add_responsive_control( 'main_min_height', [
                    'label'      => 'Altura mínima caja',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'vh' ],
                    'range'      => [ 'px' => [ 'min' => 120, 'max' => 1200 ], 'vh' => [ 'min' => 10, 'max' => 100 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-slides-wrap' => 'min-height: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_responsive_control( 'main_max_height', [
                    'label'      => 'Altura máxima caja',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'vh' ],
                    'range'      => [ 'px' => [ 'min' => 160, 'max' => 1400 ], 'vh' => [ 'min' => 10, 'max' => 100 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-slides-wrap' => 'max-height: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_responsive_control( 'main_image_width', [
                    'label'      => 'Ancho imagen principal',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', '%', 'vw' ],
                    'range'      => [ 'px' => [ 'min' => 100, 'max' => 1800 ], '%' => [ 'min' => 10, 'max' => 100 ], 'vw' => [ 'min' => 10, 'max' => 100 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-slide img' => 'width: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_responsive_control( 'main_image_max_height', [
                    'label'      => 'Altura máxima imagen principal',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'vh' ],
                    'range'      => [ 'px' => [ 'min' => 120, 'max' => 1400 ], 'vh' => [ 'min' => 10, 'max' => 100 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-slide img' => 'max-height: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_control( 'main_image_fit', [
                    'label'     => 'Ajuste imagen principal',
                    'type'      => \Elementor\Controls_Manager::SELECT,
                    'default'   => '',
                    'options'   => [ '' => 'Por defecto', 'contain' => 'Contain', 'cover' => 'Cover', 'fill' => 'Fill', 'none' => 'None' ],
                    'selectors' => [ '{{WRAPPER}} .evapp-galeria-slide img' => 'object-fit: {{VALUE}};' ],
                ] );
                $this->add_text_controls( 'caption', '{{WRAPPER}} .evapp-galeria-caption', 'Caption / texto sobre foto' );
                $this->add_control( 'caption_bg', [
                    'label'     => 'Fondo caption',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ '{{WRAPPER}} .evapp-galeria-caption' => 'background: {{VALUE}};' ],
                ] );
                $this->add_responsive_control( 'caption_padding', [
                    'label'      => 'Padding caption',
                    'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                    'size_units' => [ 'px', '%', 'em' ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-caption' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                ] );
                $this->end_controls_section();

                $this->start_controls_section( 'style_arrows', [ 'label' => 'Botones / flechas del carrusel', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ] );
                $this->add_responsive_control( 'arrows_width', [
                    'label'      => 'Ancho flecha',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px' ],
                    'range'      => [ 'px' => [ 'min' => 20, 'max' => 180 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-nav' => 'width: {{SIZE}}{{UNIT}}; padding-left:0; padding-right:0;' ],
                ] );
                $this->add_responsive_control( 'arrows_height', [
                    'label'      => 'Altura mínima flecha',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', '%' ],
                    'range'      => [ 'px' => [ 'min' => 20, 'max' => 800 ], '%' => [ 'min' => 10, 'max' => 100 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-nav' => 'min-height: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_button_controls( 'arrows', '{{WRAPPER}} .evapp-galeria-nav', '{{WRAPPER}} .evapp-galeria-nav:hover', 'Flechas del carrusel' );
                $this->end_controls_section();

                $this->start_controls_section( 'style_counter', [ 'label' => 'Contador de fotos', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ] );
                $this->add_text_controls( 'counter', '{{WRAPPER}} .evapp-galeria-counter', 'Contador' );
                $this->add_control( 'counter_current_color', [
                    'label'     => 'Color número actual',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ '{{WRAPPER}} .evapp-galeria-current' => 'color: {{VALUE}};' ],
                ] );
                $this->end_controls_section();

                $this->start_controls_section( 'style_thumbnails', [ 'label' => 'Miniaturas', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ] );
                $this->add_responsive_control( 'thumbs_gap', [
                    'label'      => 'Separación miniaturas',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'em' ],
                    'range'      => [ 'px' => [ 'min' => 0, 'max' => 80 ], 'em' => [ 'min' => 0, 'max' => 6 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-thumbs-wrap, {{WRAPPER}} .evapp-galeria-thumbs' => 'gap: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_flex_justify_control( 'thumbs_align', 'Alineación horizontal de miniaturas', '{{WRAPPER}} .evapp-galeria-thumbs-wrap, {{WRAPPER}} .evapp-galeria-thumbs' );
                $this->add_responsive_control( 'thumb_width', [
                    'label'      => 'Ancho miniatura',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'em' ],
                    'range'      => [ 'px' => [ 'min' => 24, 'max' => 260 ], 'em' => [ 'min' => 2, 'max' => 20 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-thumb' => 'width: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_responsive_control( 'thumb_height', [
                    'label'      => 'Alto miniatura',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'em' ],
                    'range'      => [ 'px' => [ 'min' => 24, 'max' => 260 ], 'em' => [ 'min' => 2, 'max' => 20 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-thumb' => 'height: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_box_controls( 'thumb', '{{WRAPPER}} .evapp-galeria-thumb', false );
                $this->add_control( 'thumb_opacity', [
                    'label'     => 'Opacidad normal',
                    'type'      => \Elementor\Controls_Manager::SLIDER,
                    'range'     => [ 'px' => [ 'min' => 0, 'max' => 1, 'step' => 0.01 ] ],
                    'selectors' => [ '{{WRAPPER}} .evapp-galeria-thumb' => 'opacity: {{SIZE}};' ],
                ] );
                $this->add_control( 'thumb_active_border_color', [
                    'label'     => 'Color borde hover/activo',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ '{{WRAPPER}} .evapp-galeria-thumb.active, {{WRAPPER}} .evapp-galeria-thumb:hover' => 'border-color: {{VALUE}};' ],
                ] );
                $this->add_control( 'thumb_active_opacity', [
                    'label'     => 'Opacidad hover/activo',
                    'type'      => \Elementor\Controls_Manager::SLIDER,
                    'range'     => [ 'px' => [ 'min' => 0, 'max' => 1, 'step' => 0.01 ] ],
                    'selectors' => [ '{{WRAPPER}} .evapp-galeria-thumb.active, {{WRAPPER}} .evapp-galeria-thumb:hover' => 'opacity: {{SIZE}};' ],
                ] );
                $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
                    'name'     => 'thumb_active_shadow',
                    'selector' => '{{WRAPPER}} .evapp-galeria-thumb.active, {{WRAPPER}} .evapp-galeria-thumb:hover',
                ] );
                $this->end_controls_section();

                $this->start_controls_section( 'style_lightbox', [ 'label' => 'Lightbox', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ] );
                $this->add_control( 'lightbox_bg', [
                    'label'     => 'Fondo overlay',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ '{{WRAPPER}} .evapp-galeria-lightbox' => 'background: {{VALUE}};' ],
                ] );
                $this->add_responsive_control( 'lightbox_image_max_height', [
                    'label'      => 'Altura máxima imagen',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'vh' ],
                    'range'      => [ 'px' => [ 'min' => 120, 'max' => 1400 ], 'vh' => [ 'min' => 20, 'max' => 100 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-lb-img' => 'max-height: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_box_controls( 'lightbox_image', '{{WRAPPER}} .evapp-galeria-lb-img', false );
                $this->add_button_controls( 'lightbox_nav', '{{WRAPPER}} .evapp-galeria-lb-prev, {{WRAPPER}} .evapp-galeria-lb-next', '{{WRAPPER}} .evapp-galeria-lb-prev:hover, {{WRAPPER}} .evapp-galeria-lb-next:hover', 'Flechas lightbox' );
                $this->add_text_controls( 'lightbox_close', '{{WRAPPER}} .evapp-galeria-lb-close', 'Botón cerrar' );
                $this->end_controls_section();

                $this->start_controls_section( 'style_ai_container', [ 'label' => 'Flujo IA: contenedor y CTA', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ] );
                $this->add_box_controls( 'ai_container', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-finder-section' );
                $this->add_text_align_control( 'ai_trigger_wrap_align', 'Alineación del bloque CTA', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-trigger-wrap' );
                $this->add_responsive_control( 'ai_cta_elements_gap', [
                    'label'      => 'Separación entre imagen, frases y botón',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'em' ],
                    'range'      => [ 'px' => [ 'min' => 0, 'max' => 120 ], 'em' => [ 'min' => 0, 'max' => 8 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-trigger-wrap' => 'gap: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_responsive_control( 'ai_cta_image_width', [
                    'label'      => 'Ancho imagen CTA',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', '%', 'em' ],
                    'range'      => [ 'px' => [ 'min' => 32, 'max' => 520 ], '%' => [ 'min' => 5, 'max' => 100 ], 'em' => [ 'min' => 2, 'max' => 32 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-promo-image-wrap' => 'width: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_box_controls( 'ai_cta_image_box', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-promo-image-wrap', false );
                $this->add_text_controls( 'ai_promo', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-promo-text, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-promo-text strong', 'Texto CTA inicial' );
                $this->add_button_controls( 'ai_open_btn', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-btn-abrir', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-btn-abrir:hover', 'Botón abrir buscador' );
                $this->end_controls_section();

                $this->start_controls_section( 'style_ai_text_forms', [ 'label' => 'Flujo IA: textos, campos y tarjetas', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ] );
                $this->add_responsive_control( 'ai_wizard_width', [
                    'label'      => 'Ancho máximo del flujo',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', '%', 'vw' ],
                    'range'      => [ 'px' => [ 'min' => 260, 'max' => 1200 ], '%' => [ 'min' => 20, 'max' => 100 ], 'vw' => [ 'min' => 20, 'max' => 100 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-wizard' => 'max-width: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_text_align_control( 'ai_step_header_align', 'Alineación encabezados de pasos', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-step-header' );
                $this->add_text_controls( 'ai_titles', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-step-title, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-loading-title, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-success-title', 'Títulos del flujo' );
                $this->add_text_controls( 'ai_descriptions', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-step-desc, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-loading-desc, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-success-desc, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-hint-text, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-guide-instruc', 'Descripciones e instrucciones' );
                $this->add_button_controls( 'ai_badge', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-badge', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-badge', 'Badges de pasos' );
                $this->add_text_controls( 'ai_labels', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-label', 'Labels de campos' );
                $this->add_box_controls( 'ai_inputs', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-input' );
                $this->add_text_controls( 'ai_inputs_text', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-input', 'Texto de campos' );
                $this->add_control( 'ai_input_focus_color', [
                    'label'     => 'Borde campo en foco',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-input:focus' => 'border-color: {{VALUE}};' ],
                ] );
                $this->add_box_controls( 'ai_cards', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-asistente-card, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-tip-item' );
                $this->add_text_controls( 'ai_asistente_text', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-asistente-card, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-as-name, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-as-info', 'Texto tarjeta asistente' );
                $this->add_text_controls( 'ai_tips_text', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-tip-item, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-tip-item span', 'Textos e íconos de tips' );
                $this->add_text_controls( 'ai_messages', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-msg, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-strip-status', 'Mensajes y estados' );
                $this->end_controls_section();

                $this->start_controls_section( 'style_ai_buttons', [ 'label' => 'Flujo IA: botones y estados', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ] );
                $this->add_button_controls( 'ai_primary', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-btn-primary, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-download-btn', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-btn-primary:hover, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-download-btn:hover', 'Botones primarios / descargar' );
                $this->add_button_controls( 'ai_secondary', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-btn-secondary', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-btn-secondary:hover', 'Botones secundarios' );
                $this->add_button_controls( 'ai_option', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-btn-opcion', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-btn-opcion:hover', 'Botones de opción de foto' );
                $this->add_flex_items_align_control( 'ai_option_items_align', 'Alineación horizontal ícono + texto en opciones', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-btn-opcion' );
                $this->add_responsive_control( 'ai_option_icon_size', [
                    'label'      => 'Tamaño ícono opción',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'em' ],
                    'range'      => [ 'px' => [ 'min' => 10, 'max' => 100 ], 'em' => [ 'min' => 1, 'max' => 8 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-btn-opcion-icon' => 'font-size: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->end_controls_section();

                $this->start_controls_section( 'style_ai_photo_flow', [ 'label' => 'Flujo IA: fotos, cámara y guía', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ] );
                $this->add_responsive_control( 'ai_tips_gap', [
                    'label'      => 'Separación tips',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'em' ],
                    'range'      => [ 'px' => [ 'min' => 0, 'max' => 60 ], 'em' => [ 'min' => 0, 'max' => 5 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-foto-tips' => 'gap: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_flex_justify_control( 'ai_tips_row_align', 'Alineación del grupo de tips', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-foto-tips' );
                $this->add_flex_items_align_control( 'ai_tips_items_align', 'Alineación interna tips imagen + texto', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-tip-item' );
                $this->add_responsive_control( 'ai_tip_media_size', [
                    'label'      => 'Tamaño imagen / emoji de instrucciones',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'em' ],
                    'range'      => [ 'px' => [ 'min' => 18, 'max' => 180 ], 'em' => [ 'min' => 1, 'max' => 12 ] ],
                    'selectors'  => [
                        '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-tip-media' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
                        '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-tip-icon'  => 'font-size: {{SIZE}}{{UNIT}};',
                    ],
                ] );
                $this->add_responsive_control( 'ai_strip_gap', [
                    'label'      => 'Separación tira fotos',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'em' ],
                    'range'      => [ 'px' => [ 'min' => 0, 'max' => 60 ], 'em' => [ 'min' => 0, 'max' => 5 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-foto-strip' => 'gap: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_flex_justify_control( 'ai_strip_align', 'Alineación tira de fotos cargadas', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-foto-strip' );
                $this->add_text_align_control( 'ai_strip_label_align', 'Alineación labels de fotos cargadas', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-strip-label' );
                $this->add_responsive_control( 'ai_strip_item_size', [
                    'label'      => 'Tamaño mini foto cargada',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'em' ],
                    'range'      => [ 'px' => [ 'min' => 36, 'max' => 240 ], 'em' => [ 'min' => 3, 'max' => 18 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-foto-strip-item' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_box_controls( 'ai_strip_item', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-foto-strip-item' );
                $this->add_responsive_control( 'ai_frame_width', [
                    'label'      => 'Ancho marco guía/cámara',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', '%', 'vw' ],
                    'range'      => [ 'px' => [ 'min' => 180, 'max' => 900 ], '%' => [ 'min' => 20, 'max' => 100 ], 'vw' => [ 'min' => 20, 'max' => 100 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-guide-frame, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-cam-view-frame' => 'max-width: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_box_controls( 'ai_frame', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-guide-frame, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-cam-view-frame' );
                $this->add_text_controls( 'ai_cam_label', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-cam-label', 'Texto dentro del óvalo de cámara' );
                $this->add_responsive_control( 'ai_oval_width', [
                    'label'      => 'Ancho óvalo rostro',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', '%' ],
                    'range'      => [ 'px' => [ 'min' => 80, 'max' => 520 ], '%' => [ 'min' => 10, 'max' => 95 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-oval-ring' => 'width: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_responsive_control( 'ai_oval_height', [
                    'label'      => 'Alto óvalo rostro',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', '%' ],
                    'range'      => [ 'px' => [ 'min' => 100, 'max' => 680 ], '%' => [ 'min' => 10, 'max' => 95 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-oval-ring' => 'height: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_control( 'ai_oval_color', [
                    'label'     => 'Color borde óvalo',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-oval-ring' => 'border-color: {{VALUE}};' ],
                ] );
                $this->add_responsive_control( 'ai_preview_size', [
                    'label'      => 'Tamaño preview circular',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'em' ],
                    'range'      => [ 'px' => [ 'min' => 90, 'max' => 540 ], 'em' => [ 'min' => 6, 'max' => 32 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-preview-circular-wrap' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_box_controls( 'ai_preview', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-preview-circular-wrap', false );
                $this->end_controls_section();

                $this->start_controls_section( 'style_ai_loading_results', [ 'label' => 'Flujo IA: carga, animaciones y resultados', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ] );
                $this->add_responsive_control( 'ai_loading_padding', [
                    'label'      => 'Padding carga/éxito',
                    'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                    'size_units' => [ 'px', '%', 'em' ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-loading-wrap, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-success-wrap' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                ] );
                $this->add_text_align_control( 'ai_loading_success_align', 'Alineación textos e íconos carga/éxito', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-loading-wrap, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-success-wrap' );
                $this->add_responsive_control( 'ai_spinner_size', [
                    'label'      => 'Tamaño spinner',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'em' ],
                    'range'      => [ 'px' => [ 'min' => 18, 'max' => 180 ], 'em' => [ 'min' => 1, 'max' => 12 ] ],
                    'selectors'  => [
                        '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-spinner'       => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
                        '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-spinner-emoji' => 'font-size: {{SIZE}}{{UNIT}}; width:auto; height:auto;',
                    ],
                ] );
                $this->add_responsive_control( 'ai_spinner_border_width', [
                    'label'      => 'Grosor spinner',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px' ],
                    'range'      => [ 'px' => [ 'min' => 1, 'max' => 24 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-spinner-css' => 'border-width: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_control( 'ai_spinner_base_color', [
                    'label'     => 'Color base spinner',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-spinner-css' => 'border-color: {{VALUE}};' ],
                ] );
                $this->add_control( 'ai_spinner_active_color', [
                    'label'     => 'Color activo spinner',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-spinner-css' => 'border-top-color: {{VALUE}};' ],
                ] );
                $this->add_control( 'ai_spinner_duration', [
                    'label'      => 'Velocidad spinner',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 's' ],
                    'range'      => [ 's' => [ 'min' => 0.2, 'max' => 4, 'step' => 0.05 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-spinner-css, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-spinner-emoji' => 'animation-duration: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_responsive_control( 'ai_success_icon_size', [
                    'label'      => 'Tamaño icono/imagen éxito / sin resultados',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'em' ],
                    'range'      => [ 'px' => [ 'min' => 20, 'max' => 220 ], 'em' => [ 'min' => 1, 'max' => 14 ] ],
                    'selectors'  => [
                        '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-success-icon'        => 'font-size: {{SIZE}}{{UNIT}};',
                        '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-success-media-image' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
                    ],
                ] );
                $this->add_responsive_control( 'ai_success_image_size', [
                    'label'       => 'Tamaño imagen/GIF fotos recibidas',
                    'type'        => \Elementor\Controls_Manager::SLIDER,
                    'size_units'  => [ 'px', 'em' ],
                    'range'       => [
                        'px' => [ 'min' => 24, 'max' => 420 ],
                        'em' => [ 'min' => 1, 'max' => 24 ],
                    ],
                    'description' => 'Control dedicado para cambiar el tamaño de la imagen o GIF que reemplaza el emoticon en el paso “¡Ya tenemos todo!”.',
                    'selectors'   => [
                        '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-success-media-image' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}}; max-width: 100%;',
                    ],
                ] );
                $this->add_responsive_control( 'ai_results_top_image_size', [
                    'label'       => 'Tamaño imagen superior resultado final',
                    'type'        => \Elementor\Controls_Manager::SLIDER,
                    'size_units'  => [ 'px', 'em', '%' ],
                    'range'       => [
                        'px' => [ 'min' => 24, 'max' => 520 ],
                        'em' => [ 'min' => 1, 'max' => 28 ],
                        '%'  => [ 'min' => 5, 'max' => 100 ],
                    ],
                    'description' => 'Controla el tamaño de la nueva imagen superior del último paso de resultados.',
                    'selectors'   => [
                        '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-results-image-wrap' => 'width: {{SIZE}}{{UNIT}};',
                    ],
                ] );
                $this->add_control( 'ai_progress_bg', [
                    'label'     => 'Fondo barra progreso',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-search-bar-wrap' => 'background: {{VALUE}};' ],
                ] );
                $this->add_control( 'ai_progress_active_bg', [
                    'label'     => 'Color barra progreso',
                    'type'      => \Elementor\Controls_Manager::COLOR,
                    'selectors' => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-search-bar-inner' => 'background: {{VALUE}};' ],
                ] );
                $this->add_responsive_control( 'ai_progress_height', [
                    'label'      => 'Alto barra progreso',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px' ],
                    'range'      => [ 'px' => [ 'min' => 2, 'max' => 40 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-search-bar-wrap' => 'height: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_text_controls( 'ai_results_count', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-results-count, {{WRAPPER}} .evapp-galeria-wrap .evapp-gi-results-counter', 'Contadores de resultados' );
                $this->add_box_controls( 'ai_results_box', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-results-slides' );
                $this->add_responsive_control( 'ai_results_min_height', [
                    'label'      => 'Altura mínima resultados',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'vh' ],
                    'range'      => [ 'px' => [ 'min' => 120, 'max' => 1000 ], 'vh' => [ 'min' => 10, 'max' => 100 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-results-slides' => 'min-height: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_responsive_control( 'ai_results_image_max_height', [
                    'label'      => 'Altura máxima imagen resultado',
                    'type'       => \Elementor\Controls_Manager::SLIDER,
                    'size_units' => [ 'px', 'vh' ],
                    'range'      => [ 'px' => [ 'min' => 120, 'max' => 1200 ], 'vh' => [ 'min' => 10, 'max' => 100 ] ],
                    'selectors'  => [ '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-result-slide img' => 'max-height: {{SIZE}}{{UNIT}};' ],
                ] );
                $this->add_flex_justify_control( 'ai_results_nav_row_align', 'Alineación fila contador/flechas resultados', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-results-nav-row' );
                $this->add_button_controls( 'ai_results_nav', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-results-nav-btn', '{{WRAPPER}} .evapp-galeria-wrap .evapp-gi-results-nav-btn:hover', 'Flechas resultados IA' );
                $this->end_controls_section();
            }

            protected function render() {
                $settings   = $this->get_settings_for_display();
                $galeria_id = absint( $settings['galeria_id'] ?? 0 );

                if ( ! $galeria_id ) {
                    if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
                        echo '<div style="padding:14px;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;background:#f8fafc;">Selecciona una galería de EventosApp en el panel del widget.</div>';
                    }
                    return;
                }

                $shortcode_attrs = [ 'id' => $galeria_id ];
                $defaults        = evapp_galeria_ia_default_texts();

                foreach ( $defaults as $key => $default ) {
                    if ( in_array( $key, [ 'spinner_image_url', 'success_image_url' ], true ) ) {
                        continue;
                    }
                    if ( isset( $settings[ $key ] ) && $settings[ $key ] !== '' ) {
                        $shortcode_attrs[ $key ] = $settings[ $key ];
                    }
                }

                $cta_defaults = evapp_galeria_ia_default_cta_settings();
                foreach ( [ 'cta_layout', 'cta_image_alt', 'cta_order_image', 'cta_order_text', 'cta_order_button' ] as $cta_key ) {
                    if ( isset( $settings[ $cta_key ] ) && $settings[ $cta_key ] !== '' ) {
                        $shortcode_attrs[ $cta_key ] = $settings[ $cta_key ];
                    } elseif ( isset( $cta_defaults[ $cta_key ] ) ) {
                        $shortcode_attrs[ $cta_key ] = $cta_defaults[ $cta_key ];
                    }
                }

                $results_defaults = evapp_galeria_ia_default_results_settings();
                foreach ( [ 'results_image_alt', 'results_order_image', 'results_order_badge', 'results_order_title', 'results_order_subtitle' ] as $results_key ) {
                    if ( isset( $settings[ $results_key ] ) && $settings[ $results_key ] !== '' ) {
                        $shortcode_attrs[ $results_key ] = $settings[ $results_key ];
                    } elseif ( isset( $results_defaults[ $results_key ] ) ) {
                        $shortcode_attrs[ $results_key ] = $results_defaults[ $results_key ];
                    }
                }

                if ( ! empty( $settings['cta_image']['url'] ) ) {
                    $shortcode_attrs['cta_image_url'] = esc_url_raw( $settings['cta_image']['url'] );
                }

                if ( ! empty( $settings['cta_hover_image']['url'] ) ) {
                    $shortcode_attrs['cta_image_hover_url'] = esc_url_raw( $settings['cta_hover_image']['url'] );
                }

                for ( $tip_i = 1; $tip_i <= 3; $tip_i++ ) {
                    $tip_image_control = 'tip' . $tip_i . '_image';
                    $tip_image_attr    = 'tip' . $tip_i . '_image_url';

                    if ( ! empty( $settings[ $tip_image_control ]['url'] ) ) {
                        $shortcode_attrs[ $tip_image_attr ] = esc_url_raw( $settings[ $tip_image_control ]['url'] );
                    }
                }

                if ( ! empty( $settings['spinner_image']['url'] ) ) {
                    $shortcode_attrs['spinner_image_url'] = esc_url_raw( $settings['spinner_image']['url'] );
                }

                if ( ! empty( $settings['success_image']['url'] ) ) {
                    $shortcode_attrs['success_image_url'] = esc_url_raw( $settings['success_image']['url'] );
                }

                if ( ! empty( $settings['results_image']['url'] ) ) {
                    $shortcode_attrs['results_image_url'] = esc_url_raw( $settings['results_image']['url'] );
                }

                $shortcode = '[eventosapp_galeria';
                foreach ( $shortcode_attrs as $attr_key => $attr_value ) {
                    $shortcode .= ' ' . sanitize_key( $attr_key ) . '="' . esc_attr( $attr_value ) . '"';
                }
                $shortcode .= ']';

                echo do_shortcode( $shortcode );
            }

        }
    }

    if ( method_exists( $widgets_manager, 'register' ) ) {
        $widgets_manager->register( new \Evapp_Elementor_Galeria_Widget() );
        $registered = true;
        return;
    }

    if ( method_exists( $widgets_manager, 'register_widget_type' ) ) {
        $widgets_manager->register_widget_type( new \Evapp_Elementor_Galeria_Widget() );
        $registered = true;
    }
}
