<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * EventosApp — Metabox externo para configurar la landing virtual del evento.
 *
 * Este archivo NO modifica el metabox principal de detalles del evento.
 * Solo agrega una caja independiente para la URL pública, cabezote, logo del organizador
 * y colores de la landing virtual que se carga desde el widget/shortcode de Elementor.
 */

if ( ! function_exists('eventosapp_virtual_landing_event_post_types') ) {
    function eventosapp_virtual_landing_event_post_types() {
        $post_types = [ 'eventosapp_event', 'eventosapp_events' ];
        $post_types = array_values( array_unique( array_filter( $post_types, static function( $post_type ) {
            return is_string( $post_type ) && $post_type !== '';
        } ) ) );

        return apply_filters( 'eventosapp_virtual_landing_event_post_types', $post_types );
    }
}

if ( ! function_exists('eventosapp_virtual_landing_is_event_post_type') ) {
    function eventosapp_virtual_landing_is_event_post_type( $post_type ) {
        return in_array( (string) $post_type, eventosapp_virtual_landing_event_post_types(), true );
    }
}

if ( ! function_exists('eventosapp_virtual_landing_active_event_post_types') ) {
    function eventosapp_virtual_landing_active_event_post_types() {
        $active = [];
        foreach ( eventosapp_virtual_landing_event_post_types() as $post_type ) {
            if ( post_type_exists( $post_type ) ) {
                $active[] = $post_type;
            }
        }
        return $active ?: [ 'eventosapp_event' ];
    }
}

if ( ! function_exists('eventosapp_virtual_landing_default_colors') ) {
    function eventosapp_virtual_landing_default_colors() {
        return [
            'page_bg'     => '#f4f7fb',
            'header_bg'   => '#0f172a',
            'card_bg'     => '#ffffff',
            'primary'     => '#2563eb',
            'button_bg'   => '#2563eb',
            'button_text' => '#ffffff',
            'text'        => '#111827',
            'muted'       => '#64748b',
            'border'      => '#e5e7eb',
            'badge_bg'    => '#eef2ff',
            'badge_text'  => '#3730a3',
        ];
    }
}

if ( ! function_exists('eventosapp_virtual_landing_get_colors') ) {
    function eventosapp_virtual_landing_get_colors( $event_id ) {
        $defaults = eventosapp_virtual_landing_default_colors();
        $saved    = get_post_meta( absint($event_id), '_eventosapp_virtual_landing_colors', true );
        if ( ! is_array($saved) ) {
            $saved = [];
        }

        $colors = [];
        foreach ( $defaults as $key => $default ) {
            $value = isset($saved[$key]) ? sanitize_hex_color($saved[$key]) : '';
            $colors[$key] = $value ?: $default;
        }
        return $colors;
    }
}

add_action('admin_enqueue_scripts', function(){
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( $screen && eventosapp_virtual_landing_is_event_post_type( $screen->post_type ?? '' ) ) {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_media();
    }
});

if ( ! function_exists('eventosapp_register_virtual_landing_metabox') ) {
    function eventosapp_register_virtual_landing_metabox() {
        foreach ( eventosapp_virtual_landing_active_event_post_types() as $screen ) {
            add_meta_box(
                'eventosapp_virtual_landing_config',
                'Landing Virtual del Evento',
                'eventosapp_render_virtual_landing_metabox',
                $screen,
                'normal',
                'default'
            );
        }
    }
}
add_action('add_meta_boxes', 'eventosapp_register_virtual_landing_metabox', 20);

if ( ! function_exists('eventosapp_render_virtual_landing_metabox') ) {
    function eventosapp_render_virtual_landing_metabox( $post ) {
        $event_id = absint($post->ID);

        $stored_path     = get_post_meta($event_id, '_eventosapp_virtual_landing_path', true);
        $effective_path  = function_exists('eventosapp_get_event_virtual_landing_path')
            ? eventosapp_get_event_virtual_landing_path($event_id)
            : ('/virtual/' . sanitize_title(get_the_title($event_id)));
        $effective_url   = home_url($effective_path);

        $header_url      = get_post_meta($event_id, '_eventosapp_virtual_landing_header_url', true);
        $organizer_logo  = get_post_meta($event_id, '_eventosapp_virtual_landing_organizer_logo_url', true);
        $intro_title     = get_post_meta($event_id, '_eventosapp_virtual_landing_intro_title', true);
        $intro_text      = get_post_meta($event_id, '_eventosapp_virtual_landing_intro_text', true);
        $button_label    = get_post_meta($event_id, '_eventosapp_virtual_landing_button_label', true) ?: 'Ingresar a la sesión virtual';
        $whatsapp_use_landing_raw = get_post_meta($event_id, '_eventosapp_virtual_landing_whatsapp_use_landing', true);
        $whatsapp_use_landing = ($whatsapp_use_landing_raw === '' || $whatsapp_use_landing_raw === null)
            ? true
            : ! in_array(strtolower(trim((string) $whatsapp_use_landing_raw)), ['0', 'no', 'false', 'off'], true);
        $platform_url    = get_post_meta($event_id, '_eventosapp_virtual_url', true);
        $whatsapp_target_preview = $whatsapp_use_landing ? $effective_url : $platform_url;
        $colors          = eventosapp_virtual_landing_get_colors($event_id);
        $defaults        = eventosapp_virtual_landing_default_colors();

        wp_nonce_field('eventosapp_virtual_landing_save', 'eventosapp_virtual_landing_nonce');
        ?>
        <style>
            .evapp-vlanding-wrap { border:1px solid #e5e7eb; border-radius:12px; background:#fff; padding:16px; }
            .evapp-vlanding-grid { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
            .evapp-vlanding-field { margin-bottom:14px; }
            .evapp-vlanding-field label { display:block; font-weight:700; margin-bottom:5px; }
            .evapp-vlanding-field input[type="text"],
            .evapp-vlanding-field input[type="url"],
            .evapp-vlanding-field textarea { width:100%; max-width:100%; }
            .evapp-vlanding-field textarea { min-height:82px; }
            .evapp-vlanding-help { color:#64748b; font-size:12px; line-height:1.4; margin:5px 0 0; }
            .evapp-vlanding-effective { background:#f8fafc; border:1px dashed #cbd5e1; border-radius:10px; padding:12px; margin:0 0 16px; }
            .evapp-vlanding-effective code { font-size:13px; word-break:break-all; }
            .evapp-vlanding-check { display:flex; gap:10px; align-items:flex-start; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px; }
            .evapp-vlanding-check input[type="checkbox"] { margin-top:2px; }
            .evapp-vlanding-check strong { display:block; margin-bottom:3px; }
            .evapp-vlanding-color-grid { display:grid; grid-template-columns: repeat(2, minmax(220px, 1fr)); gap:12px 18px; }
            .evapp-vlanding-media-row { display:flex; gap:8px; align-items:center; }
            .evapp-vlanding-media-row input { flex:1; }
            .evapp-vlanding-preview-img { margin-top:8px; max-width:260px; max-height:90px; border:1px solid #e5e7eb; border-radius:8px; background:#f8fafc; padding:4px; display:none; }
            .evapp-vlanding-preview-img[src]:not([src=""]) { display:block; }
            @media (max-width: 900px) {
                .evapp-vlanding-grid,
                .evapp-vlanding-color-grid { grid-template-columns:1fr; }
            }
        </style>

        <div class="evapp-vlanding-wrap">
            <div class="evapp-vlanding-effective">
                <strong>URL efectiva de esta landing:</strong><br>
                <code><?php echo esc_html($effective_url); ?></code>
                <p class="evapp-vlanding-help">
                    Esta es la URL que usarán los botones de acceso virtual enviados en los correos. El enlace del correo agregará automáticamente el identificador público del ticket para cargar la información del asistente.
                </p>
            </div>

            <div class="evapp-vlanding-grid">
                <div>
                    <h3>URL y contenido</h3>

                    <div class="evapp-vlanding-effective" style="margin-bottom:14px;">
                        <strong>Variable disponible para textos:</strong><br>
                        <code>{{evento_nombre}}</code>
                        <p class="evapp-vlanding-help">
                            Puedes usar <code>{{evento_nombre}}</code> en el título personalizado, texto introductorio y texto del botón. En la landing pública se reemplazará automáticamente por el nombre real del evento: <strong><?php echo esc_html(get_the_title($event_id)); ?></strong>.
                        </p>
                    </div>

                    <div class="evapp-vlanding-field">
                        <label for="eventosapp_virtual_landing_path">URL personalizada de la landing</label>
                        <input type="text" id="eventosapp_virtual_landing_path" name="eventosapp_virtual_landing_path" value="<?php echo esc_attr($stored_path); ?>" placeholder="<?php echo esc_attr($effective_path); ?>">
                        <p class="evapp-vlanding-help">
                            Si la dejas vacía, se genera automáticamente como <code>/virtual/nombre-del-evento</code>. También puedes escribir una ruta como <code>/virtual/mi-evento-vip</code>. No uses la URL de la plataforma virtual aquí; esa se configura en Detalles del Evento.
                        </p>
                    </div>

                    <div class="evapp-vlanding-field">
                        <label for="eventosapp_virtual_landing_whatsapp_use_landing">Destino del botón virtual de WhatsApp</label>
                        <div class="evapp-vlanding-check">
                            <input type="checkbox" id="eventosapp_virtual_landing_whatsapp_use_landing" name="eventosapp_virtual_landing_whatsapp_use_landing" value="1" <?php checked($whatsapp_use_landing); ?>>
                            <div>
                                <strong>Enviar primero a la landing virtual de EventosApp</strong>
                                <p class="evapp-vlanding-help" style="margin:0;">
                                    Esta opción queda activa por defecto para mantener el comportamiento actual. Si la desmarcas, el botón virtual enviado por WhatsApp redirigirá directamente al enlace de la plataforma configurado en Detalles del Evento.
                                </p>
                                <p class="evapp-vlanding-help">
                                    Destino actual de referencia: <code><?php echo esc_html($whatsapp_target_preview ?: 'No configurado'); ?></code>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="evapp-vlanding-field">
                        <label for="eventosapp_virtual_landing_header_url">Cabezote personalizado de la landing</label>
                        <div class="evapp-vlanding-media-row">
                            <input type="url" id="eventosapp_virtual_landing_header_url" name="eventosapp_virtual_landing_header_url" value="<?php echo esc_url($header_url); ?>" placeholder="https://.../cabezote.jpg">
                            <button type="button" class="button evapp-vlanding-media-btn" data-target="eventosapp_virtual_landing_header_url">Seleccionar</button>
                        </div>
                        <img class="evapp-vlanding-preview-img" src="<?php echo esc_url($header_url); ?>" alt="Preview cabezote">
                        <p class="evapp-vlanding-help">Imagen superior de la landing. Si no se configura, se usa un cabezote visual generado con los colores de la landing.</p>
                    </div>

                    <div class="evapp-vlanding-field">
                        <label for="eventosapp_virtual_landing_organizer_logo_url">Logo / foto del organizador</label>
                        <div class="evapp-vlanding-media-row">
                            <input type="url" id="eventosapp_virtual_landing_organizer_logo_url" name="eventosapp_virtual_landing_organizer_logo_url" value="<?php echo esc_url($organizer_logo); ?>" placeholder="https://.../logo.png">
                            <button type="button" class="button evapp-vlanding-media-btn" data-target="eventosapp_virtual_landing_organizer_logo_url">Seleccionar</button>
                        </div>
                        <img class="evapp-vlanding-preview-img" src="<?php echo esc_url($organizer_logo); ?>" alt="Preview logo organizador">
                        <p class="evapp-vlanding-help">Se mostrará junto al nombre del organizador. Si el evento usa un cliente y ese cliente tiene imagen destacada, se usará como respaldo cuando este campo esté vacío.</p>
                    </div>

                    <div class="evapp-vlanding-field">
                        <label for="eventosapp_virtual_landing_intro_title">Título personalizado</label>
                        <input type="text" id="eventosapp_virtual_landing_intro_title" name="eventosapp_virtual_landing_intro_title" value="<?php echo esc_attr($intro_title); ?>" placeholder="Bienvenido a {{evento_nombre}}">
                        <p class="evapp-vlanding-help">Puedes usar <code>{{evento_nombre}}</code>. Ejemplo: <code>Bienvenido a {{evento_nombre}}</code>.</p>
                    </div>

                    <div class="evapp-vlanding-field">
                        <label for="eventosapp_virtual_landing_intro_text">Texto introductorio</label>
                        <textarea id="eventosapp_virtual_landing_intro_text" name="eventosapp_virtual_landing_intro_text" placeholder="Estás a punto de ingresar a la sesión virtual de {{evento_nombre}}."><?php echo esc_textarea($intro_text); ?></textarea>
                        <p class="evapp-vlanding-help">Puedes usar <code>{{evento_nombre}}</code>. Ejemplo: <code>Gracias por registrarte a {{evento_nombre}}. Presiona el botón para ingresar.</code></p>
                    </div>

                    <div class="evapp-vlanding-field">
                        <label for="eventosapp_virtual_landing_button_label">Texto del botón de ingreso</label>
                        <input type="text" id="eventosapp_virtual_landing_button_label" name="eventosapp_virtual_landing_button_label" value="<?php echo esc_attr($button_label); ?>" placeholder="Ingresar a {{evento_nombre}}">
                        <p class="evapp-vlanding-help">Puedes usar <code>{{evento_nombre}}</code>. Ejemplo: <code>Ingresar a {{evento_nombre}}</code>.</p>
                    </div>
                </div>

                <div>
                    <h3>Colores de la landing</h3>
                    <div class="evapp-vlanding-color-grid">
                        <?php
                        $labels = [
                            'page_bg'     => 'Fondo general',
                            'header_bg'   => 'Fondo del cabezote',
                            'card_bg'     => 'Fondo de tarjetas',
                            'primary'     => 'Color principal',
                            'button_bg'   => 'Fondo del botón',
                            'button_text' => 'Texto del botón',
                            'text'        => 'Texto principal',
                            'muted'       => 'Texto secundario',
                            'border'      => 'Bordes',
                            'badge_bg'    => 'Fondo de etiquetas',
                            'badge_text'  => 'Texto de etiquetas',
                        ];
                        foreach ( $labels as $key => $label ):
                            $field_id = 'eventosapp_virtual_landing_color_' . $key;
                            ?>
                            <div class="evapp-vlanding-field">
                                <label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($label); ?></label>
                                <input type="text" class="evapp-vlanding-color" id="<?php echo esc_attr($field_id); ?>" name="eventosapp_virtual_landing_colors[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($colors[$key]); ?>" data-default-color="<?php echo esc_attr($defaults[$key]); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function($){
            function evappInitVirtualLandingMetabox(){
                $('.evapp-vlanding-color').wpColorPicker();

                $('.evapp-vlanding-media-btn').off('click.evappVirtualLanding').on('click.evappVirtualLanding', function(e){
                    e.preventDefault();
                    var targetId = $(this).data('target');
                    var $input = $('#' + targetId);
                    var $preview = $input.closest('.evapp-vlanding-field').find('.evapp-vlanding-preview-img');
                    var frame = wp.media({
                        title: 'Seleccionar imagen',
                        button: { text: 'Usar esta imagen' },
                        multiple: false
                    });
                    frame.on('select', function(){
                        var attachment = frame.state().get('selection').first().toJSON();
                        if (attachment && attachment.url) {
                            $input.val(attachment.url).trigger('change');
                            $preview.attr('src', attachment.url).show();
                        }
                    });
                    frame.open();
                });

                $('#eventosapp_virtual_landing_header_url, #eventosapp_virtual_landing_organizer_logo_url').on('input change', function(){
                    var $input = $(this);
                    var $preview = $input.closest('.evapp-vlanding-field').find('.evapp-vlanding-preview-img');
                    $preview.attr('src', $input.val());
                });
            }
            $(evappInitVirtualLandingMetabox);
        })(jQuery);
        </script>
        <?php
    }
}

if ( ! function_exists('eventosapp_save_virtual_landing_metabox') ) {
    function eventosapp_save_virtual_landing_metabox( $post_id ) {
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( ! eventosapp_virtual_landing_is_event_post_type( get_post_type( $post_id ) ) ) return;
        if ( ! current_user_can('edit_post', $post_id) ) return;
        if ( ! isset($_POST['eventosapp_virtual_landing_nonce']) || ! wp_verify_nonce($_POST['eventosapp_virtual_landing_nonce'], 'eventosapp_virtual_landing_save') ) return;

    $previous_path = get_post_meta($post_id, '_eventosapp_virtual_landing_path', true);
    $raw_path = isset($_POST['eventosapp_virtual_landing_path']) ? wp_unslash($_POST['eventosapp_virtual_landing_path']) : '';
    $raw_path = trim((string) $raw_path);

    if ( $raw_path === '' ) {
        delete_post_meta($post_id, '_eventosapp_virtual_landing_path');
    } else {
        $clean_path = function_exists('eventosapp_sanitize_virtual_landing_path')
            ? eventosapp_sanitize_virtual_landing_path($raw_path, $post_id)
            : ('/virtual/' . sanitize_title(basename($raw_path)));
        update_post_meta($post_id, '_eventosapp_virtual_landing_path', $clean_path);
    }

    $new_path = get_post_meta($post_id, '_eventosapp_virtual_landing_path', true);
    if ( $previous_path !== $new_path ) {
        update_option('eventosapp_needs_flush', 1);
    }

    update_post_meta($post_id, '_eventosapp_virtual_landing_header_url', esc_url_raw($_POST['eventosapp_virtual_landing_header_url'] ?? ''));
    update_post_meta($post_id, '_eventosapp_virtual_landing_organizer_logo_url', esc_url_raw($_POST['eventosapp_virtual_landing_organizer_logo_url'] ?? ''));
    update_post_meta($post_id, '_eventosapp_virtual_landing_whatsapp_use_landing', isset($_POST['eventosapp_virtual_landing_whatsapp_use_landing']) ? '1' : '0');
    update_post_meta($post_id, '_eventosapp_virtual_landing_intro_title', sanitize_text_field(wp_unslash($_POST['eventosapp_virtual_landing_intro_title'] ?? '')));
    update_post_meta($post_id, '_eventosapp_virtual_landing_intro_text', wp_kses_post(wp_unslash($_POST['eventosapp_virtual_landing_intro_text'] ?? '')));
    update_post_meta($post_id, '_eventosapp_virtual_landing_button_label', sanitize_text_field(wp_unslash($_POST['eventosapp_virtual_landing_button_label'] ?? 'Ingresar a la sesión virtual')));

    $defaults = eventosapp_virtual_landing_default_colors();
    $posted_colors = isset($_POST['eventosapp_virtual_landing_colors']) && is_array($_POST['eventosapp_virtual_landing_colors'])
        ? $_POST['eventosapp_virtual_landing_colors']
        : [];
    $colors = [];
    foreach ( $defaults as $key => $default ) {
        $value = isset($posted_colors[$key]) ? sanitize_hex_color(wp_unslash($posted_colors[$key])) : '';
        $colors[$key] = $value ?: $default;
    }
        update_post_meta($post_id, '_eventosapp_virtual_landing_colors', $colors);
    }
}
add_action('save_post_eventosapp_event', 'eventosapp_save_virtual_landing_metabox', 30);
add_action('save_post_eventosapp_events', 'eventosapp_save_virtual_landing_metabox', 30);
