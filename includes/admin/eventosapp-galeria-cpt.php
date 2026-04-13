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
    $uid         = 'evapp-galeria-' . $galeria_id;

    // Construir datos de imágenes
    $imagenes = [];
    foreach ( $fotos_ids as $att_id ) {
        $full  = wp_get_attachment_image_url( $att_id, 'large' );
        $thumb = wp_get_attachment_image_url( $att_id, 'thumbnail' );
        $alt   = get_post_meta( $att_id, '_wp_attachment_image_alt', true );
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

    ob_start();
    ?>
    <div id="<?php echo esc_attr( $uid ); ?>" class="evapp-galeria-wrap">

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
        <div class="evapp-galeria-thumbs">
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

    </div><!-- .evapp-galeria-wrap -->

    <?php
    // Pasar datos de imágenes al JS de forma segura
    $imagenes_json = wp_json_encode( $imagenes );
    ?>

    <script>
    (function(){
        var uid        = <?php echo wp_json_encode( $uid ); ?>;
        var imagenes   = <?php echo $imagenes_json; ?>;
        var total      = imagenes.length;
        var current    = 0;
        var wrap       = document.getElementById(uid);

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

            // Scroll miniatura activa al centro
            var activeThumb = thumbBtns[current];
            activeThumb.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }

        // ── Botones nav carrusel ──
        wrap.querySelector('.evapp-galeria-prev').addEventListener('click', function(){
            goTo( current - 1 );
        });
        wrap.querySelector('.evapp-galeria-next').addEventListener('click', function(){
            goTo( current + 1 );
        });

        // ── Miniaturas ──
        thumbBtns.forEach(function(btn){
            btn.addEventListener('click', function(){
                goTo( parseInt( btn.dataset.index, 10 ) );
            });
        });

        // ── Swipe táctil ──
        var touchStartX = 0;
        var slidesWrap = wrap.querySelector('.evapp-galeria-slides-wrap');
        slidesWrap.addEventListener('touchstart', function(e){
            touchStartX = e.changedTouches[0].clientX;
        }, { passive: true });
        slidesWrap.addEventListener('touchend', function(e){
            var diff = touchStartX - e.changedTouches[0].clientX;
            if ( Math.abs(diff) > 40 ) {
                goTo( diff > 0 ? current + 1 : current - 1 );
            }
        }, { passive: true });

        // ── Lightbox ──
        function openLightbox( index ) {
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

        function lbGoTo( index ) {
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
        lb.addEventListener('click', function(e){
            if ( e.target === lb ) closeLightbox();
        });

        wrap.querySelector('.evapp-galeria-lb-prev').addEventListener('click', function(){
            lbGoTo( lbCurrent - 1 );
        });
        wrap.querySelector('.evapp-galeria-lb-next').addEventListener('click', function(){
            lbGoTo( lbCurrent + 1 );
        });

        // Swipe en lightbox
        var lbTouchStart = 0;
        lb.addEventListener('touchstart', function(e){
            lbTouchStart = e.changedTouches[0].clientX;
        }, { passive: true });
        lb.addEventListener('touchend', function(e){
            var diff = lbTouchStart - e.changedTouches[0].clientX;
            if ( Math.abs(diff) > 40 ) {
                lbGoTo( diff > 0 ? lbCurrent + 1 : lbCurrent - 1 );
            }
        }, { passive: true });

        // Teclado: flechas y ESC
        document.addEventListener('keydown', function(e){
            if ( lb.style.display === 'none' ) return;
            if ( e.key === 'ArrowLeft'  ) lbGoTo( lbCurrent - 1 );
            if ( e.key === 'ArrowRight' ) lbGoTo( lbCurrent + 1 );
            if ( e.key === 'Escape'     ) closeLightbox();
        });

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
