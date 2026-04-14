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
    (function($){
        if ( typeof wp === 'undefined' || ! wp.media ) return;

        var frame;
        var $grid     = $('#evapp-fotos-grid');
        var $inputIds = $('#_asistente_fotos_ids');
        var $inputPri = $('#_asistente_foto_id');
        var MAX_FOTOS = 5;

        // ── Inicializar Sortable ────────────────────────────────────────────
        $grid.sortable({
            items: '.evapp-foto-item',
            placeholder: 'evapp-foto-placeholder',
            opacity: 0.7,
            tolerance: 'pointer',
            update: function(){ evappSyncIds(); }
        });

        // ── Sincronizar campos ocultos ──────────────────────────────────────
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
            $('#evapp-fotos-empty-msg').toggle( count === 0 );
            // Mostrar/ocultar botón "Eliminar todas"
            if ( count === 0 ) {
                $('#evapp-fotos-clear-btn').hide();
            } else {
                if ( ! $('#evapp-fotos-clear-btn').length ) {
                    $('<button type="button" id="evapp-fotos-clear-btn" class="button" style="color:#d63638;">🗑️ Eliminar todas las fotos</button>')
                        .insertAfter('#evapp-fotos-add-btn');
                    evappBindClear();
                }
                $('#evapp-fotos-clear-btn').show();
            }
        }

        // ── Abrir Media Library ─────────────────────────────────────────────
        $('#evapp-fotos-add-btn').on('click', function(e){
            e.preventDefault();

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

                $grid.sortable('refresh');
                evappSyncIds();
            });

            frame.open();
        });

        // ── Eliminar foto individual ────────────────────────────────────────
        $grid.on('click', '.evapp-foto-remove', function(e){
            e.preventDefault();
            $(this).closest('.evapp-foto-item').remove();
            evappSyncIds();
        });

        // ── Eliminar todas las fotos ────────────────────────────────────────
        function evappBindClear() {
            $(document).on('click', '#evapp-fotos-clear-btn', function(e){
                e.preventDefault();
                if ( ! confirm('¿Estás seguro de que quieres eliminar todas las fotos de este asistente?') ) return;
                $grid.find('.evapp-foto-item').remove();
                evappSyncIds();
            });
        }
        evappBindClear();

        // Inicializar al cargar
        evappSyncIds();

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
