<?php
/**
 * EventosApp - Consulta pública/embed de estado de inscripción por evento.
 *
 * Este archivo agrega un metabox independiente al CPT eventosapp_event para configurar
 * un formulario embebible por iframe en páginas externas. El formulario permite validar
 * uno o varios datos del asistente, consultar si existe un ticket para el evento y,
 * opcionalmente, reenviar el ticket al correo registrado del ticket encontrado.
 *
 * No modifica la estructura de tickets existente ni reemplaza funciones actuales.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'EVENTOSAPP_REG_STATUS_META_CONFIG' ) ) {
    define( 'EVENTOSAPP_REG_STATUS_META_CONFIG', '_eventosapp_registration_status_embed_config' );
}

if ( ! defined( 'EVENTOSAPP_REG_STATUS_META_TOKEN' ) ) {
    define( 'EVENTOSAPP_REG_STATUS_META_TOKEN', '_eventosapp_registration_status_embed_token' );
}

/**
 * Log controlado para depuración con WP_DEBUG.
 */
if ( ! function_exists( 'eventosapp_registration_status_log' ) ) {
    function eventosapp_registration_status_log( $message, $context = [] ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $suffix = '';
            if ( ! empty( $context ) ) {
                $suffix = ' | ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            }
            error_log( 'EVENTOSAPP REGISTRATION STATUS EMBED | ' . $message . $suffix );
        }
    }
}

/**
 * Configuración base del módulo.
 */
if ( ! function_exists( 'eventosapp_registration_status_default_config' ) ) {
    function eventosapp_registration_status_default_config() {
        return [
            'enabled'                 => '0',
            'match_mode'              => 'all',
            'show_payment_status'     => '1',
            'show_checkin_status'     => '1',
            'show_localidad'          => '1',
            'resend_enabled'          => '0',
            'resend_cooldown_hours'   => '24',
            'primary_color'           => '#2271b1',
            'background_color'        => '#ffffff',
            'text_color'              => '#1d2327',
            'border_color'            => '#dcdcde',
            'title_color'             => '#1d2327',
            'subtitle_color'          => '#646970',
            'button_text_color'       => '#ffffff',
            'input_background_color'  => '#ffffff',
            'content_max_width'       => '760',
            'outer_padding'           => '18',
            'card_padding'            => '22',
            'text_align'              => 'left',
            'fields'                  => [
                'email' => [
                    'enabled'     => '1',
                    'label'       => 'Correo electrónico',
                    'placeholder' => 'Ingresa el correo usado en tu inscripción',
                ],
                'cc' => [
                    'enabled'     => '1',
                    'label'       => 'Documento / Cédula',
                    'placeholder' => 'Ingresa tu número de documento',
                ],
                'tel' => [
                    'enabled'     => '0',
                    'label'       => 'Teléfono',
                    'placeholder' => 'Ingresa tu teléfono registrado',
                ],
                'nit' => [
                    'enabled'     => '0',
                    'label'       => 'NIT',
                    'placeholder' => 'Ingresa tu NIT registrado',
                ],
            ],
            'texts'                   => [
                'form_title'             => 'Consulta el estado de tu inscripción',
                'form_description'       => 'Ingresa los datos solicitados para validar tu identidad y confirmar si tu inscripción está registrada.',
                'header_title'           => 'Consulta el estado de tu inscripción',
                'header_subtitle'        => 'Ingresa los datos solicitados para validar tu identidad y confirmar si tu inscripción está registrada.',
                'submit_label'           => 'Consultar inscripción',
                'success_title'          => 'Inscripción encontrada',
                'success_message'        => 'Encontramos una inscripción registrada para {{evento}}.',
                'not_found_title'        => 'No encontramos una inscripción',
                'not_found_message'      => 'No encontramos un ticket con los datos ingresados para este evento. Revisa la información e intenta nuevamente.',
                'validation_error'       => 'Completa los campos solicitados para consultar tu inscripción.',
                'generic_error'          => 'No fue posible procesar la consulta en este momento. Intenta nuevamente.',
                'resend_button_label'    => 'Reenviar ticket al correo registrado',
                'resend_subject'         => 'Tu ticket para {{evento}}',
                'resend_success_message' => 'El ticket fue reenviado al correo registrado.',
                'resend_error_message'   => 'No fue posible reenviar el ticket en este momento.',
                'resend_cooldown_message' => 'Ya solicitaste el reenvío de este ticket. Podrás solicitarlo nuevamente en {{tiempo_restante}}.',
            ],
        ];
    }
}

/**
 * Merge recursivo conservando las llaves por defecto.
 */
if ( ! function_exists( 'eventosapp_registration_status_merge_config' ) ) {
    function eventosapp_registration_status_merge_config( $defaults, $saved ) {
        if ( ! is_array( $saved ) ) {
            return $defaults;
        }

        foreach ( $saved as $key => $value ) {
            if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) ) {
                $defaults[ $key ] = eventosapp_registration_status_merge_config( $defaults[ $key ], $value );
            } else {
                $defaults[ $key ] = $value;
            }
        }

        return $defaults;
    }
}

/**
 * Obtiene la configuración completa del evento.
 */
if ( ! function_exists( 'eventosapp_registration_status_get_config' ) ) {
    function eventosapp_registration_status_get_config( $event_id ) {
        $event_id = absint( $event_id );
        $saved    = $event_id ? get_post_meta( $event_id, EVENTOSAPP_REG_STATUS_META_CONFIG, true ) : [];
        $defaults = eventosapp_registration_status_default_config();
        $config   = eventosapp_registration_status_merge_config( $defaults, is_array( $saved ) ? $saved : [] );

        if ( empty( $config['match_mode'] ) || ! in_array( $config['match_mode'], [ 'all', 'any' ], true ) ) {
            $config['match_mode'] = 'all';
        }

        if ( empty( $config['texts']['header_title'] ) && ! empty( $config['texts']['form_title'] ) ) {
            $config['texts']['header_title'] = $config['texts']['form_title'];
        }

        if ( empty( $config['texts']['header_subtitle'] ) && ! empty( $config['texts']['form_description'] ) ) {
            $config['texts']['header_subtitle'] = $config['texts']['form_description'];
        }

        if ( empty( $config['text_align'] ) || ! in_array( $config['text_align'], [ 'left', 'center', 'right' ], true ) ) {
            $config['text_align'] = $defaults['text_align'];
        }

        $cooldown_hours = isset( $config['resend_cooldown_hours'] ) ? absint( $config['resend_cooldown_hours'] ) : absint( $defaults['resend_cooldown_hours'] );
        if ( $cooldown_hours < 0 || $cooldown_hours > 720 ) {
            $cooldown_hours = absint( $defaults['resend_cooldown_hours'] );
        }
        $config['resend_cooldown_hours'] = (string) $cooldown_hours;

        foreach ( [ 'content_max_width' => [ 320, 1400 ], 'outer_padding' => [ 0, 120 ], 'card_padding' => [ 0, 120 ] ] as $size_key => $range ) {
            $value = isset( $config[ $size_key ] ) ? absint( $config[ $size_key ] ) : absint( $defaults[ $size_key ] );
            if ( $value < $range[0] || $value > $range[1] ) {
                $value = absint( $defaults[ $size_key ] );
            }
            $config[ $size_key ] = (string) $value;
        }

        foreach ( [ 'primary_color', 'background_color', 'text_color', 'border_color', 'title_color', 'subtitle_color', 'button_text_color', 'input_background_color' ] as $color_key ) {
            $hex = sanitize_hex_color( isset( $config[ $color_key ] ) ? $config[ $color_key ] : '' );
            $config[ $color_key ] = $hex ? $hex : $defaults[ $color_key ];
        }

        return $config;
    }
}

/**
 * Genera/recupera el token público del embed.
 */
if ( ! function_exists( 'eventosapp_registration_status_get_token' ) ) {
    function eventosapp_registration_status_get_token( $event_id, $force_new = false ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) {
            return '';
        }

        $token = get_post_meta( $event_id, EVENTOSAPP_REG_STATUS_META_TOKEN, true );
        if ( $force_new || ! $token ) {
            $token = wp_generate_password( 40, false, false );
            update_post_meta( $event_id, EVENTOSAPP_REG_STATUS_META_TOKEN, $token );
        }

        return $token;
    }
}

/**
 * Campos base y campos extra disponibles para validación.
 */
if ( ! function_exists( 'eventosapp_registration_status_get_available_fields' ) ) {
    function eventosapp_registration_status_get_available_fields( $event_id ) {
        $fields = [
            'email' => [
                'key'          => 'email',
                'label'        => 'Correo electrónico',
                'meta_key'     => '_eventosapp_asistente_email',
                'input_type'   => 'email',
                'normalize_as' => 'email',
                'source'       => 'base',
            ],
            'cc' => [
                'key'          => 'cc',
                'label'        => 'Documento / Cédula',
                'meta_key'     => '_eventosapp_asistente_cc',
                'input_type'   => 'text',
                'normalize_as' => 'document',
                'source'       => 'base',
            ],
            'tel' => [
                'key'          => 'tel',
                'label'        => 'Teléfono',
                'meta_key'     => '_eventosapp_asistente_tel',
                'input_type'   => 'text',
                'normalize_as' => 'document',
                'source'       => 'base',
            ],
            'nit' => [
                'key'          => 'nit',
                'label'        => 'NIT',
                'meta_key'     => '_eventosapp_asistente_nit',
                'input_type'   => 'text',
                'normalize_as' => 'document',
                'source'       => 'base',
            ],
            'localidad' => [
                'key'          => 'localidad',
                'label'        => 'Localidad',
                'meta_key'     => '_eventosapp_asistente_localidad',
                'input_type'   => 'text',
                'normalize_as' => 'text',
                'source'       => 'base',
            ],
        ];

        if ( function_exists( 'eventosapp_get_event_extra_fields' ) ) {
            $extras = eventosapp_get_event_extra_fields( $event_id );
            if ( is_array( $extras ) ) {
                foreach ( $extras as $extra ) {
                    if ( empty( $extra['key'] ) ) {
                        continue;
                    }

                    $extra_key = sanitize_key( $extra['key'] );
                    if ( ! $extra_key ) {
                        continue;
                    }

                    $field_key = 'extra_' . $extra_key;
                    $label     = ! empty( $extra['label'] ) ? sanitize_text_field( $extra['label'] ) : $extra_key;
                    $type      = ! empty( $extra['type'] ) ? sanitize_key( $extra['type'] ) : 'text';

                    $fields[ $field_key ] = [
                        'key'          => $field_key,
                        'label'        => 'Extra: ' . $label,
                        'meta_key'     => '_eventosapp_extra_' . $extra_key,
                        'input_type'   => in_array( $type, [ 'email', 'number', 'date' ], true ) ? $type : 'text',
                        'normalize_as' => $type === 'email' ? 'email' : 'text',
                        'source'       => 'extra',
                    ];
                }
            }
        }

        return $fields;
    }
}

/**
 * Campos habilitados para el formulario público.
 */
if ( ! function_exists( 'eventosapp_registration_status_get_enabled_fields' ) ) {
    function eventosapp_registration_status_get_enabled_fields( $event_id, $config = null ) {
        $event_id   = absint( $event_id );
        $config     = is_array( $config ) ? $config : eventosapp_registration_status_get_config( $event_id );
        $available  = eventosapp_registration_status_get_available_fields( $event_id );
        $enabled    = [];

        foreach ( $available as $key => $field ) {
            $field_config = isset( $config['fields'][ $key ] ) && is_array( $config['fields'][ $key ] ) ? $config['fields'][ $key ] : [];
            if ( ! empty( $field_config['enabled'] ) && $field_config['enabled'] === '1' ) {
                $field['public_label']       = ! empty( $field_config['label'] ) ? sanitize_text_field( $field_config['label'] ) : $field['label'];
                $field['public_placeholder'] = ! empty( $field_config['placeholder'] ) ? sanitize_text_field( $field_config['placeholder'] ) : '';
                $enabled[ $key ]             = $field;
            }
        }

        return $enabled;
    }
}

/**
 * Normaliza valores para comparar sin depender de mayúsculas, espacios o signos comunes.
 */
if ( ! function_exists( 'eventosapp_registration_status_normalize_value' ) ) {
    function eventosapp_registration_status_normalize_value( $value, $mode = 'text' ) {
        $value = is_scalar( $value ) ? (string) $value : '';
        $value = wp_unslash( $value );
        $value = trim( $value );

        if ( $mode === 'email' ) {
            return strtolower( sanitize_email( $value ) );
        }

        $value = remove_accents( $value );
        $value = strtolower( $value );

        if ( $mode === 'document' ) {
            return preg_replace( '/[^a-z0-9]/', '', $value );
        }

        $value = preg_replace( '/\s+/', ' ', $value );
        return trim( $value );
    }
}

/**
 * Sanitiza el valor público según el campo configurado.
 */
if ( ! function_exists( 'eventosapp_registration_status_sanitize_public_value' ) ) {
    function eventosapp_registration_status_sanitize_public_value( $raw, $field ) {
        $raw = is_scalar( $raw ) ? wp_unslash( (string) $raw ) : '';

        if ( isset( $field['normalize_as'] ) && $field['normalize_as'] === 'email' ) {
            return sanitize_email( $raw );
        }

        return sanitize_text_field( $raw );
    }
}

/**
 * Valida que el token público corresponda al evento y que el módulo esté activo.
 */
if ( ! function_exists( 'eventosapp_registration_status_validate_public_access' ) ) {
    function eventosapp_registration_status_validate_public_access( $event_id, $token ) {
        $event_id = absint( $event_id );
        $token    = sanitize_text_field( wp_unslash( (string) $token ) );

        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return new WP_Error( 'invalid_event', 'Evento inválido.' );
        }

        $config = eventosapp_registration_status_get_config( $event_id );
        if ( empty( $config['enabled'] ) || $config['enabled'] !== '1' ) {
            return new WP_Error( 'disabled', 'La consulta pública de inscripción no está activa para este evento.' );
        }

        $saved_token = eventosapp_registration_status_get_token( $event_id );
        if ( ! $saved_token || ! $token || ! hash_equals( (string) $saved_token, (string) $token ) ) {
            return new WP_Error( 'invalid_token', 'Acceso inválido.' );
        }

        return $config;
    }
}

/**
 * URL pública del iframe.
 */
if ( ! function_exists( 'eventosapp_registration_status_get_embed_url' ) ) {
    function eventosapp_registration_status_get_embed_url( $event_id ) {
        $token = eventosapp_registration_status_get_token( $event_id );

        /**
         * IMPORTANTE:
         * No se usa admin-ajax.php para el documento del iframe porque algunas cabeceras de seguridad
         * aplicadas sobre /wp-admin/ bloquean el embebido externo con frame-ancestors 'self'.
         * Esta URL entra por el frontend y se intercepta en template_redirect sin requerir reglas rewrite.
         */
        return add_query_arg(
            [
                'eventosapp_registration_status_embed' => '1',
                'event_id'                            => absint( $event_id ),
                'token'                               => $token,
            ],
            home_url( '/' )
        );
    }
}

/**
 * Iframe listo para copiar.
 */
if ( ! function_exists( 'eventosapp_registration_status_get_iframe_code' ) ) {
    function eventosapp_registration_status_get_iframe_code( $event_id ) {
        $url    = eventosapp_registration_status_get_embed_url( $event_id );
        $config = eventosapp_registration_status_get_config( $event_id );
        $width  = isset( $config['content_max_width'] ) ? absint( $config['content_max_width'] ) : 760;
        $title  = ! empty( $config['texts']['header_title'] ) ? $config['texts']['header_title'] : 'Consulta de inscripción';

        return '<iframe src="' . esc_url_raw( $url ) . '" width="100%" height="620" style="border:0;max-width:' . esc_attr( $width ) . 'px;width:100%;display:block;margin:0 auto;" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="' . esc_attr( $title ) . '"></iframe>';
    }
}

/**
 * Detecta la solicitud pública del documento iframe por frontend.
 */
if ( ! function_exists( 'eventosapp_registration_status_is_embed_request' ) ) {
    function eventosapp_registration_status_is_embed_request() {
        return isset( $_GET['eventosapp_registration_status_embed'] ) && sanitize_text_field( wp_unslash( $_GET['eventosapp_registration_status_embed'] ) ) === '1';
    }
}

/**
 * Origen seguro para cabeceras CSP generadas por este documento.
 */
if ( ! function_exists( 'eventosapp_registration_status_url_origin' ) ) {
    function eventosapp_registration_status_url_origin( $url ) {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return '';
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if ( ! empty( $parts['port'] ) ) {
            $origin .= ':' . absint( $parts['port'] );
        }

        return $origin;
    }
}

/**
 * Cabeceras específicas del documento embedible.
 * Remueve cabeceras de frame heredadas desde WordPress/plugins cuando aún es posible.
 */
if ( ! function_exists( 'eventosapp_registration_status_send_embed_headers' ) ) {
    function eventosapp_registration_status_send_embed_headers() {
        if ( headers_sent() ) {
            return;
        }

        header_remove( 'X-Frame-Options' );
        header_remove( 'Content-Security-Policy' );
        header_remove( 'Content-Security-Policy-Report-Only' );

        $site_origin  = eventosapp_registration_status_url_origin( home_url( '/' ) );
        $admin_origin = eventosapp_registration_status_url_origin( admin_url( 'admin-ajax.php' ) );
        $connect_src  = array_unique( array_filter( [ "'self'", $site_origin, $admin_origin ] ) );

        header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
        header( 'X-Robots-Tag: noindex, nofollow', true );
        header( 'Referrer-Policy: no-referrer-when-downgrade', true );
        header( "Content-Security-Policy: frame-ancestors *; default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src " . implode( ' ', $connect_src ) . "; form-action 'self'; base-uri 'self';", true );
    }
}

add_action( 'send_headers', function() {
    if ( eventosapp_registration_status_is_embed_request() ) {
        eventosapp_registration_status_send_embed_headers();
    }
}, 0 );

add_action( 'template_redirect', function() {
    if ( eventosapp_registration_status_is_embed_request() ) {
        eventosapp_registration_status_render_public_embed();
    }
}, 0 );

/**
 * Registra el metabox independiente en eventosapp_event.
 */
add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_registration_status_embed_metabox',
        'Consulta externa de estado de inscripción',
        'eventosapp_registration_status_render_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
} );

/**
 * Carga el color picker nativo de WordPress solo en el CPT de eventos.
 */
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
        return;
    }

    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'eventosapp_event' ) {
        return;
    }

    wp_enqueue_style( 'wp-color-picker' );
    wp_enqueue_script( 'wp-color-picker' );

    wp_add_inline_script(
        'wp-color-picker',
        "jQuery(function($){ $('.evapp-rs-color-field').wpColorPicker(); });"
    );
} );

/**
 * Render del metabox de configuración.
 */
if ( ! function_exists( 'eventosapp_registration_status_render_metabox' ) ) {
    function eventosapp_registration_status_render_metabox( $post ) {
        $event_id         = absint( $post->ID );
        $config           = eventosapp_registration_status_get_config( $event_id );
        $available_fields = eventosapp_registration_status_get_available_fields( $event_id );
        $token            = eventosapp_registration_status_get_token( $event_id );
        $embed_url        = eventosapp_registration_status_get_embed_url( $event_id );
        $iframe_code      = eventosapp_registration_status_get_iframe_code( $event_id );

        wp_nonce_field( 'eventosapp_registration_status_embed_save', 'eventosapp_registration_status_embed_nonce' );
        ?>
        <style>
            .evapp-rs-box { border:1px solid #dcdcde; background:#fff; padding:14px; margin:12px 0; border-radius:6px; }
            .evapp-rs-box h4 { margin:0 0 10px; font-size:14px; }
            .evapp-rs-muted { color:#646970; font-size:12px; line-height:1.45; }
            .evapp-rs-grid { display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
            .evapp-rs-grid-3 { display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:10px; }
            .evapp-rs-grid-4 { display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap:10px; }
            .evapp-rs-field-table { width:100%; border-collapse:collapse; }
            .evapp-rs-field-table th, .evapp-rs-field-table td { border-bottom:1px solid #f0f0f1; padding:8px 6px; text-align:left; vertical-align:top; }
            .evapp-rs-field-table th { font-size:12px; color:#1d2327; }
            .evapp-rs-field-table input[type="text"] { width:100%; }
            .evapp-rs-textarea { width:100%; min-height:76px; }
            .evapp-rs-code { width:100%; font-family:Consolas, Monaco, monospace; font-size:12px; background:#f6f7f7; }
            .evapp-rs-pill { display:inline-block; background:#f0f6fc; color:#0969da; padding:2px 7px; border-radius:999px; font-size:11px; margin-left:4px; }
            .evapp-rs-number { max-width:120px; }
            .evapp-rs-color-field.wp-color-picker { max-width:95px; }
            @media (max-width: 980px) {
                .evapp-rs-grid, .evapp-rs-grid-3, .evapp-rs-grid-4 { grid-template-columns:1fr; }
            }
        </style>

        <div class="evapp-rs-box">
            <h4>Activación</h4>
            <label>
                <input type="checkbox" name="eventosapp_registration_status_config[enabled]" value="1" <?php checked( $config['enabled'], '1' ); ?>>
                Activar formulario externo para consultar estado de inscripción
            </label>
            <p class="evapp-rs-muted">
                Este formulario se muestra por iframe y valida únicamente tickets asociados a este evento mediante
                <code>_eventosapp_ticket_evento_id</code>. El token evita que otro evento pueda reutilizar la URL.
            </p>
        </div>

        <div class="evapp-rs-box">
            <h4>Datos que se pedirán para validar identidad</h4>
            <p class="evapp-rs-muted">Marca los campos que debe diligenciar el usuario. Por defecto se sugieren correo electrónico y documento/cédula.</p>

            <p>
                <label for="eventosapp_registration_status_match_mode"><strong>Modo de validación:</strong></label><br>
                <select id="eventosapp_registration_status_match_mode" name="eventosapp_registration_status_config[match_mode]">
                    <option value="all" <?php selected( $config['match_mode'], 'all' ); ?>>Todos los campos seleccionados deben coincidir</option>
                    <option value="any" <?php selected( $config['match_mode'], 'any' ); ?>>Al menos uno de los campos seleccionados debe coincidir</option>
                </select>
            </p>

            <table class="evapp-rs-field-table">
                <thead>
                    <tr>
                        <th style="width:110px;">Usar</th>
                        <th style="width:190px;">Campo</th>
                        <th>Etiqueta visible</th>
                        <th>Placeholder visible</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $available_fields as $field_key => $field ) :
                        $field_config = isset( $config['fields'][ $field_key ] ) && is_array( $config['fields'][ $field_key ] ) ? $config['fields'][ $field_key ] : [];
                        $enabled      = isset( $field_config['enabled'] ) ? $field_config['enabled'] : '0';
                        $label        = ! empty( $field_config['label'] ) ? $field_config['label'] : $field['label'];
                        $placeholder  = ! empty( $field_config['placeholder'] ) ? $field_config['placeholder'] : '';
                        ?>
                        <tr>
                            <td>
                                <label>
                                    <input type="checkbox" name="eventosapp_registration_status_config[fields][<?php echo esc_attr( $field_key ); ?>][enabled]" value="1" <?php checked( $enabled, '1' ); ?>>
                                    Pedir
                                </label>
                            </td>
                            <td>
                                <strong><?php echo esc_html( $field['label'] ); ?></strong>
                                <?php if ( $field['source'] === 'extra' ) : ?>
                                    <span class="evapp-rs-pill">campo extra</span>
                                <?php endif; ?>
                                <br><span class="evapp-rs-muted"><code><?php echo esc_html( $field['meta_key'] ); ?></code></span>
                            </td>
                            <td>
                                <input type="text" name="eventosapp_registration_status_config[fields][<?php echo esc_attr( $field_key ); ?>][label]" value="<?php echo esc_attr( $label ); ?>">
                            </td>
                            <td>
                                <input type="text" name="eventosapp_registration_status_config[fields][<?php echo esc_attr( $field_key ); ?>][placeholder]" value="<?php echo esc_attr( $placeholder ); ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="evapp-rs-box">
            <h4>Encabezado, título y subtítulo del formulario</h4>
            <p class="evapp-rs-muted">
                Estos textos se muestran en la parte superior del formulario embebido. Puedes usar estas variables: <code>{{evento}}</code>, <code>{{nombre}}</code>, <code>{{apellido}}</code>, <code>{{email}}</code>, <code>{{localidad}}</code>, <code>{{estado_inscripcion}}</code>, <code>{{estado_pago}}</code>, <code>{{estado_checkin}}</code>.
            </p>

            <div class="evapp-rs-grid">
                <p>
                    <label><strong>Título del encabezado</strong></label><br>
                    <input type="text" class="widefat" name="eventosapp_registration_status_config[texts][header_title]" value="<?php echo esc_attr( $config['texts']['header_title'] ); ?>">
                </p>
                <p>
                    <label><strong>Texto del botón consultar</strong></label><br>
                    <input type="text" class="widefat" name="eventosapp_registration_status_config[texts][submit_label]" value="<?php echo esc_attr( $config['texts']['submit_label'] ); ?>">
                </p>
            </div>

            <p>
                <label><strong>Subtítulo del encabezado</strong></label><br>
                <textarea class="evapp-rs-textarea" name="eventosapp_registration_status_config[texts][header_subtitle]"><?php echo esc_textarea( $config['texts']['header_subtitle'] ); ?></textarea>
            </p>

            <input type="hidden" name="eventosapp_registration_status_config[texts][form_title]" value="<?php echo esc_attr( $config['texts']['header_title'] ); ?>">
            <input type="hidden" name="eventosapp_registration_status_config[texts][form_description]" value="<?php echo esc_attr( $config['texts']['header_subtitle'] ); ?>">
        </div>

        <div class="evapp-rs-box">
            <h4>Mensajes editables de respuesta</h4>
            <div class="evapp-rs-grid">
                <p>
                    <label><strong>Título cuando existe inscripción</strong></label><br>
                    <input type="text" class="widefat" name="eventosapp_registration_status_config[texts][success_title]" value="<?php echo esc_attr( $config['texts']['success_title'] ); ?>">
                </p>
                <p>
                    <label><strong>Título cuando no existe inscripción</strong></label><br>
                    <input type="text" class="widefat" name="eventosapp_registration_status_config[texts][not_found_title]" value="<?php echo esc_attr( $config['texts']['not_found_title'] ); ?>">
                </p>
            </div>

            <div class="evapp-rs-grid">
                <p>
                    <label><strong>Mensaje cuando existe inscripción</strong></label><br>
                    <textarea class="evapp-rs-textarea" name="eventosapp_registration_status_config[texts][success_message]"><?php echo esc_textarea( $config['texts']['success_message'] ); ?></textarea>
                </p>
                <p>
                    <label><strong>Mensaje cuando no existe inscripción</strong></label><br>
                    <textarea class="evapp-rs-textarea" name="eventosapp_registration_status_config[texts][not_found_message]"><?php echo esc_textarea( $config['texts']['not_found_message'] ); ?></textarea>
                </p>
            </div>

            <div class="evapp-rs-grid">
                <p>
                    <label><strong>Error de validación</strong></label><br>
                    <textarea class="evapp-rs-textarea" name="eventosapp_registration_status_config[texts][validation_error]"><?php echo esc_textarea( $config['texts']['validation_error'] ); ?></textarea>
                </p>
                <p>
                    <label><strong>Error genérico</strong></label><br>
                    <textarea class="evapp-rs-textarea" name="eventosapp_registration_status_config[texts][generic_error]"><?php echo esc_textarea( $config['texts']['generic_error'] ); ?></textarea>
                </p>
            </div>
        </div>

        <div class="evapp-rs-box">
            <h4>Respuesta y reenvío de ticket</h4>
            <p>
                <label>
                    <input type="checkbox" name="eventosapp_registration_status_config[show_payment_status]" value="1" <?php checked( $config['show_payment_status'], '1' ); ?>>
                    Mostrar estado de pago del ticket
                </label><br>
                <label>
                    <input type="checkbox" name="eventosapp_registration_status_config[show_checkin_status]" value="1" <?php checked( $config['show_checkin_status'], '1' ); ?>>
                    Mostrar estado de check-in si existe
                </label><br>
                <label>
                    <input type="checkbox" name="eventosapp_registration_status_config[show_localidad]" value="1" <?php checked( $config['show_localidad'], '1' ); ?>>
                    Mostrar localidad si existe
                </label>
            </p>

            <hr>

            <p>
                <label>
                    <input type="checkbox" name="eventosapp_registration_status_config[resend_enabled]" value="1" <?php checked( $config['resend_enabled'], '1' ); ?>>
                    Activar botón para reenviar el ticket al correo registrado cuando la inscripción exista
                </label>
            </p>

            <p>
                <label><strong>Tiempo mínimo entre reenvíos del mismo ticket</strong></label><br>
                <input type="number" class="evapp-rs-number" min="0" max="720" step="1" name="eventosapp_registration_status_config[resend_cooldown_hours]" value="<?php echo esc_attr( $config['resend_cooldown_hours'] ); ?>"> horas
                <br><span class="evapp-rs-muted">Por defecto son 24 horas. Usa 0 solo si quieres permitir reenvíos sin bloqueo por ticket.</span>
            </p>

            <div class="evapp-rs-grid">
                <p>
                    <label><strong>Texto del botón de reenvío</strong></label><br>
                    <input type="text" class="widefat" name="eventosapp_registration_status_config[texts][resend_button_label]" value="<?php echo esc_attr( $config['texts']['resend_button_label'] ); ?>">
                </p>
                <p>
                    <label><strong>Asunto del correo de reenvío</strong></label><br>
                    <input type="text" class="widefat" name="eventosapp_registration_status_config[texts][resend_subject]" value="<?php echo esc_attr( $config['texts']['resend_subject'] ); ?>">
                </p>
            </div>

            <div class="evapp-rs-grid">
                <p>
                    <label><strong>Mensaje si el reenvío fue exitoso</strong></label><br>
                    <textarea class="evapp-rs-textarea" name="eventosapp_registration_status_config[texts][resend_success_message]"><?php echo esc_textarea( $config['texts']['resend_success_message'] ); ?></textarea>
                </p>
                <p>
                    <label><strong>Mensaje si el reenvío falla</strong></label><br>
                    <textarea class="evapp-rs-textarea" name="eventosapp_registration_status_config[texts][resend_error_message]"><?php echo esc_textarea( $config['texts']['resend_error_message'] ); ?></textarea>
                </p>
            </div>

            <p>
                <label><strong>Mensaje cuando el usuario está en bloqueo por tiempo</strong></label><br>
                <textarea class="evapp-rs-textarea" name="eventosapp_registration_status_config[texts][resend_cooldown_message]"><?php echo esc_textarea( $config['texts']['resend_cooldown_message'] ); ?></textarea>
                <br><span class="evapp-rs-muted">Variable disponible: <code>{{tiempo_restante}}</code>.</span>
            </p>
        </div>

        <div class="evapp-rs-box">
            <h4>Estilos del formulario embebido</h4>
            <p class="evapp-rs-muted">Los colores usan el color picker nativo de WordPress. El padding y ancho se aplican directamente al iframe público.</p>

            <div class="evapp-rs-grid-4">
                <p>
                    <label><strong>Color principal</strong></label><br>
                    <input type="text" class="evapp-rs-color-field" name="eventosapp_registration_status_config[primary_color]" value="<?php echo esc_attr( $config['primary_color'] ); ?>" data-default-color="#2271b1">
                </p>
                <p>
                    <label><strong>Fondo tarjeta</strong></label><br>
                    <input type="text" class="evapp-rs-color-field" name="eventosapp_registration_status_config[background_color]" value="<?php echo esc_attr( $config['background_color'] ); ?>" data-default-color="#ffffff">
                </p>
                <p>
                    <label><strong>Texto general</strong></label><br>
                    <input type="text" class="evapp-rs-color-field" name="eventosapp_registration_status_config[text_color]" value="<?php echo esc_attr( $config['text_color'] ); ?>" data-default-color="#1d2327">
                </p>
                <p>
                    <label><strong>Borde</strong></label><br>
                    <input type="text" class="evapp-rs-color-field" name="eventosapp_registration_status_config[border_color]" value="<?php echo esc_attr( $config['border_color'] ); ?>" data-default-color="#dcdcde">
                </p>
            </div>

            <div class="evapp-rs-grid-4">
                <p>
                    <label><strong>Color título</strong></label><br>
                    <input type="text" class="evapp-rs-color-field" name="eventosapp_registration_status_config[title_color]" value="<?php echo esc_attr( $config['title_color'] ); ?>" data-default-color="#1d2327">
                </p>
                <p>
                    <label><strong>Color subtítulo</strong></label><br>
                    <input type="text" class="evapp-rs-color-field" name="eventosapp_registration_status_config[subtitle_color]" value="<?php echo esc_attr( $config['subtitle_color'] ); ?>" data-default-color="#646970">
                </p>
                <p>
                    <label><strong>Texto botón</strong></label><br>
                    <input type="text" class="evapp-rs-color-field" name="eventosapp_registration_status_config[button_text_color]" value="<?php echo esc_attr( $config['button_text_color'] ); ?>" data-default-color="#ffffff">
                </p>
                <p>
                    <label><strong>Fondo inputs</strong></label><br>
                    <input type="text" class="evapp-rs-color-field" name="eventosapp_registration_status_config[input_background_color]" value="<?php echo esc_attr( $config['input_background_color'] ); ?>" data-default-color="#ffffff">
                </p>
            </div>

            <div class="evapp-rs-grid-3">
                <p>
                    <label><strong>Ancho máximo del formulario</strong></label><br>
                    <input type="number" class="evapp-rs-number" min="320" max="1400" step="10" name="eventosapp_registration_status_config[content_max_width]" value="<?php echo esc_attr( $config['content_max_width'] ); ?>"> px
                </p>
                <p>
                    <label><strong>Padding externo</strong></label><br>
                    <input type="number" class="evapp-rs-number" min="0" max="120" step="1" name="eventosapp_registration_status_config[outer_padding]" value="<?php echo esc_attr( $config['outer_padding'] ); ?>"> px
                </p>
                <p>
                    <label><strong>Padding interno tarjeta</strong></label><br>
                    <input type="number" class="evapp-rs-number" min="0" max="120" step="1" name="eventosapp_registration_status_config[card_padding]" value="<?php echo esc_attr( $config['card_padding'] ); ?>"> px
                </p>
            </div>

            <p>
                <label><strong>Alineación del encabezado y textos</strong></label><br>
                <select name="eventosapp_registration_status_config[text_align]">
                    <option value="left" <?php selected( $config['text_align'], 'left' ); ?>>Izquierda</option>
                    <option value="center" <?php selected( $config['text_align'], 'center' ); ?>>Centro</option>
                    <option value="right" <?php selected( $config['text_align'], 'right' ); ?>>Derecha</option>
                </select>
            </p>
        </div>

        <div class="evapp-rs-box">
            <h4>Código para embeber</h4>
            <p class="evapp-rs-muted">Copia este iframe en cualquier página externa donde quieras mostrar la consulta.</p>

            <p>
                <label><strong>URL pública del formulario</strong></label><br>
                <input type="text" readonly class="widefat evapp-rs-code" value="<?php echo esc_attr( $embed_url ); ?>" onclick="this.select();">
            </p>

            <p>
                <label><strong>Código iframe</strong></label><br>
                <textarea readonly rows="4" class="evapp-rs-code" onclick="this.select();"><?php echo esc_textarea( $iframe_code ); ?></textarea>
            </p>

            <p>
                <label>
                    <input type="checkbox" name="eventosapp_registration_status_regenerate_token" value="1">
                    Regenerar token al guardar este evento
                </label><br>
                <span class="evapp-rs-muted">Úsalo si quieres invalidar códigos iframe anteriores. Token actual: <code><?php echo esc_html( substr( $token, 0, 10 ) ); ?>...</code></span>
            </p>
        </div>
        <?php
    }
}

/**
 * Guardado del metabox. Usa nonce propio para no depender del metabox de Detalles del Evento.
 */
add_action( 'save_post_eventosapp_event', 'eventosapp_registration_status_save_metabox', 35, 2 );

if ( ! function_exists( 'eventosapp_registration_status_save_metabox' ) ) {
    function eventosapp_registration_status_save_metabox( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! $post || $post->post_type !== 'eventosapp_event' ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( empty( $_POST['eventosapp_registration_status_embed_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eventosapp_registration_status_embed_nonce'] ) ), 'eventosapp_registration_status_embed_save' ) ) {
            return;
        }

        $raw      = isset( $_POST['eventosapp_registration_status_config'] ) && is_array( $_POST['eventosapp_registration_status_config'] ) ? wp_unslash( $_POST['eventosapp_registration_status_config'] ) : [];
        $defaults = eventosapp_registration_status_default_config();
        $current  = eventosapp_registration_status_get_config( $post_id );
        $fields   = eventosapp_registration_status_get_available_fields( $post_id );

        $config = $defaults;
        $config['enabled']             = ! empty( $raw['enabled'] ) ? '1' : '0';
        $config['match_mode']          = ! empty( $raw['match_mode'] ) && in_array( $raw['match_mode'], [ 'all', 'any' ], true ) ? sanitize_key( $raw['match_mode'] ) : 'all';
        $config['show_payment_status'] = ! empty( $raw['show_payment_status'] ) ? '1' : '0';
        $config['show_checkin_status'] = ! empty( $raw['show_checkin_status'] ) ? '1' : '0';
        $config['show_localidad']      = ! empty( $raw['show_localidad'] ) ? '1' : '0';
        $config['resend_enabled']      = ! empty( $raw['resend_enabled'] ) ? '1' : '0';
        $cooldown_hours               = isset( $raw['resend_cooldown_hours'] ) ? absint( $raw['resend_cooldown_hours'] ) : absint( $defaults['resend_cooldown_hours'] );
        if ( $cooldown_hours < 0 || $cooldown_hours > 720 ) {
            $cooldown_hours = absint( $defaults['resend_cooldown_hours'] );
        }
        $config['resend_cooldown_hours'] = (string) $cooldown_hours;

        foreach ( [ 'primary_color', 'background_color', 'text_color', 'border_color', 'title_color', 'subtitle_color', 'button_text_color', 'input_background_color' ] as $color_key ) {
            $value = isset( $raw[ $color_key ] ) ? sanitize_text_field( $raw[ $color_key ] ) : $defaults[ $color_key ];
            $hex   = sanitize_hex_color( $value );
            $config[ $color_key ] = $hex ? $hex : $defaults[ $color_key ];
        }

        foreach ( [ 'content_max_width' => [ 320, 1400 ], 'outer_padding' => [ 0, 120 ], 'card_padding' => [ 0, 120 ] ] as $size_key => $range ) {
            $value = isset( $raw[ $size_key ] ) ? absint( $raw[ $size_key ] ) : absint( $defaults[ $size_key ] );
            if ( $value < $range[0] || $value > $range[1] ) {
                $value = absint( $defaults[ $size_key ] );
            }
            $config[ $size_key ] = (string) $value;
        }

        $config['text_align'] = ! empty( $raw['text_align'] ) && in_array( $raw['text_align'], [ 'left', 'center', 'right' ], true ) ? sanitize_key( $raw['text_align'] ) : $defaults['text_align'];

        $posted_texts = isset( $raw['texts'] ) && is_array( $raw['texts'] ) ? $raw['texts'] : [];
        foreach ( $defaults['texts'] as $text_key => $default_value ) {
            $value = isset( $posted_texts[ $text_key ] ) ? $posted_texts[ $text_key ] : $default_value;
            $config['texts'][ $text_key ] = sanitize_textarea_field( $value );
        }

        if ( empty( $config['texts']['form_title'] ) && ! empty( $config['texts']['header_title'] ) ) {
            $config['texts']['form_title'] = $config['texts']['header_title'];
        }
        if ( empty( $config['texts']['form_description'] ) && ! empty( $config['texts']['header_subtitle'] ) ) {
            $config['texts']['form_description'] = $config['texts']['header_subtitle'];
        }

        $posted_fields = isset( $raw['fields'] ) && is_array( $raw['fields'] ) ? $raw['fields'] : [];
        $config['fields'] = [];
        foreach ( $fields as $field_key => $field ) {
            $posted_field = isset( $posted_fields[ $field_key ] ) && is_array( $posted_fields[ $field_key ] ) ? $posted_fields[ $field_key ] : [];
            $existing     = isset( $current['fields'][ $field_key ] ) && is_array( $current['fields'][ $field_key ] ) ? $current['fields'][ $field_key ] : [];

            $fallback_label       = ! empty( $existing['label'] ) ? $existing['label'] : $field['label'];
            $fallback_placeholder = ! empty( $existing['placeholder'] ) ? $existing['placeholder'] : '';

            $config['fields'][ $field_key ] = [
                'enabled'     => ! empty( $posted_field['enabled'] ) ? '1' : '0',
                'label'       => isset( $posted_field['label'] ) ? sanitize_text_field( $posted_field['label'] ) : sanitize_text_field( $fallback_label ),
                'placeholder' => isset( $posted_field['placeholder'] ) ? sanitize_text_field( $posted_field['placeholder'] ) : sanitize_text_field( $fallback_placeholder ),
            ];
        }

        update_post_meta( $post_id, EVENTOSAPP_REG_STATUS_META_CONFIG, $config );

        if ( ! empty( $_POST['eventosapp_registration_status_regenerate_token'] ) ) {
            eventosapp_registration_status_get_token( $post_id, true );
        } else {
            eventosapp_registration_status_get_token( $post_id, false );
        }

        eventosapp_registration_status_log( 'Configuración guardada', [
            'event_id'       => $post_id,
            'enabled'        => $config['enabled'],
            'match_mode'     => $config['match_mode'],
            'resend_enabled'        => $config['resend_enabled'],
            'resend_cooldown_hours' => $config['resend_cooldown_hours'],
        ] );
    }
}

/**
 * Lee IP pública para rate limit básico.
 */
if ( ! function_exists( 'eventosapp_registration_status_get_ip' ) ) {
    function eventosapp_registration_status_get_ip() {
        $ip = '';
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
            $ip    = trim( $parts[0] );
        }

        if ( ! $ip && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }

        return $ip ?: '0.0.0.0';
    }
}

/**
 * Rate limit simple por IP para proteger consulta y reenvío.
 */
if ( ! function_exists( 'eventosapp_registration_status_rate_limit' ) ) {
    function eventosapp_registration_status_rate_limit( $scope, $event_id, $limit, $window_seconds, $extra = '' ) {
        $ip  = eventosapp_registration_status_get_ip();
        $key = 'evapp_rs_rl_' . md5( $scope . '|' . absint( $event_id ) . '|' . $ip . '|' . $extra );
        $num = absint( get_transient( $key ) );

        if ( $num >= $limit ) {
            return false;
        }

        set_transient( $key, $num + 1, absint( $window_seconds ) );
        return true;
    }
}

/**
 * Retorna el tiempo configurado de bloqueo por ticket entre reenvíos.
 */
if ( ! function_exists( 'eventosapp_registration_status_get_resend_cooldown_seconds' ) ) {
    function eventosapp_registration_status_get_resend_cooldown_seconds( $config ) {
        $hours = isset( $config['resend_cooldown_hours'] ) ? absint( $config['resend_cooldown_hours'] ) : 24;
        if ( $hours > 720 ) {
            $hours = 24;
        }

        return $hours * HOUR_IN_SECONDS;
    }
}

/**
 * Formatea segundos restantes para mostrar el bloqueo en el formulario público.
 */
if ( ! function_exists( 'eventosapp_registration_status_format_remaining_time' ) ) {
    function eventosapp_registration_status_format_remaining_time( $seconds ) {
        $seconds = max( 0, absint( $seconds ) );
        if ( $seconds <= 0 ) {
            return '0 minutos';
        }

        $hours   = floor( $seconds / HOUR_IN_SECONDS );
        $minutes = ceil( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

        if ( $hours > 0 && $minutes > 0 ) {
            return $hours . ' hora' . ( $hours === 1 ? '' : 's' ) . ' y ' . $minutes . ' minuto' . ( $minutes === 1 ? '' : 's' );
        }

        if ( $hours > 0 ) {
            return $hours . ' hora' . ( $hours === 1 ? '' : 's' );
        }

        return $minutes . ' minuto' . ( $minutes === 1 ? '' : 's' );
    }
}

/**
 * Estado de disponibilidad del botón de reenvío para un ticket específico.
 */
if ( ! function_exists( 'eventosapp_registration_status_get_resend_cooldown_state' ) ) {
    function eventosapp_registration_status_get_resend_cooldown_state( $ticket_id, $config ) {
        $ticket_id         = absint( $ticket_id );
        $cooldown_seconds  = eventosapp_registration_status_get_resend_cooldown_seconds( $config );
        $last_resend_ts    = absint( get_post_meta( $ticket_id, '_eventosapp_registration_status_last_resend_ts', true ) );
        $now               = current_time( 'timestamp' );
        $remaining_seconds = 0;

        if ( $ticket_id && $cooldown_seconds > 0 && $last_resend_ts > 0 ) {
            $elapsed = max( 0, $now - $last_resend_ts );
            if ( $elapsed < $cooldown_seconds ) {
                $remaining_seconds = $cooldown_seconds - $elapsed;
            }
        }

        $remaining_text = eventosapp_registration_status_format_remaining_time( $remaining_seconds );
        $message        = ! empty( $config['texts']['resend_cooldown_message'] ) ? $config['texts']['resend_cooldown_message'] : 'Ya solicitaste el reenvío de este ticket. Podrás solicitarlo nuevamente en {{tiempo_restante}}.';
        $message        = str_replace( '{{tiempo_restante}}', $remaining_text, $message );

        return [
            'allowed'           => $remaining_seconds <= 0,
            'remaining_seconds' => $remaining_seconds,
            'remaining_text'    => $remaining_text,
            'message'           => sanitize_text_field( $message ),
            'last_resend_ts'    => $last_resend_ts,
            'cooldown_seconds'  => $cooldown_seconds,
        ];
    }
}

/**
 * Agrega una entrada resumida al historial de consulta externa del ticket.
 */
if ( ! function_exists( 'eventosapp_registration_status_append_ticket_activity' ) ) {
    function eventosapp_registration_status_append_ticket_activity( $ticket_id, $entry ) {
        $ticket_id = absint( $ticket_id );
        if ( ! $ticket_id || ! is_array( $entry ) ) {
            return;
        }

        $history = get_post_meta( $ticket_id, '_eventosapp_registration_status_embed_history', true );
        if ( ! is_array( $history ) ) {
            $history = [];
        }

        $entry = wp_parse_args( $entry, [
            'at'      => current_time( 'mysql' ),
            'action'  => '',
            'status'  => '',
            'message' => '',
            'source'  => 'registration_status_embed',
        ] );

        $history[] = [
            'at'      => sanitize_text_field( $entry['at'] ),
            'action'  => sanitize_key( $entry['action'] ),
            'status'  => sanitize_key( $entry['status'] ),
            'message' => sanitize_text_field( $entry['message'] ),
            'source'  => sanitize_key( $entry['source'] ),
        ];

        update_post_meta( $ticket_id, '_eventosapp_registration_status_embed_history', array_slice( $history, -30 ) );
    }
}

/**
 * Marca que el ticket fue consultado desde el formulario incrustable.
 */
if ( ! function_exists( 'eventosapp_registration_status_record_lookup_activity' ) ) {
    function eventosapp_registration_status_record_lookup_activity( $ticket_id, $event_id ) {
        $ticket_id = absint( $ticket_id );
        $event_id  = absint( $event_id );
        if ( ! $ticket_id ) {
            return;
        }

        $count = absint( get_post_meta( $ticket_id, '_eventosapp_registration_status_embed_lookup_count', true ) ) + 1;
        update_post_meta( $ticket_id, '_eventosapp_registration_status_embed_consulted', '1' );
        update_post_meta( $ticket_id, '_eventosapp_registration_status_embed_lookup_count', $count );
        update_post_meta( $ticket_id, '_eventosapp_registration_status_embed_last_lookup_at', current_time( 'mysql' ) );
        update_post_meta( $ticket_id, '_eventosapp_registration_status_embed_last_event_id', $event_id );

        eventosapp_registration_status_append_ticket_activity( $ticket_id, [
            'action'  => 'lookup',
            'status'  => 'found',
            'message' => 'Ticket consultado desde formulario incrustable.',
        ] );
    }
}

/**
 * Marca intentos y resultados de reenvío solicitados desde el formulario incrustable.
 */
if ( ! function_exists( 'eventosapp_registration_status_record_resend_activity' ) ) {
    function eventosapp_registration_status_record_resend_activity( $ticket_id, $event_id, $status, $message = '', $mark_success = false ) {
        $ticket_id = absint( $ticket_id );
        $event_id  = absint( $event_id );
        if ( ! $ticket_id ) {
            return;
        }

        $now                  = current_time( 'mysql' );
        $status_key           = sanitize_key( $status );
        $should_count_request = in_array( $status_key, [ 'requested', 'rate_limited', 'cooldown_blocked' ], true );
        $request_count        = absint( get_post_meta( $ticket_id, '_eventosapp_registration_status_embed_resend_request_count', true ) );

        update_post_meta( $ticket_id, '_eventosapp_registration_status_embed_resend_requested', '1' );
        if ( $should_count_request ) {
            $request_count++;
            update_post_meta( $ticket_id, '_eventosapp_registration_status_embed_resend_request_count', $request_count );
            update_post_meta( $ticket_id, '_eventosapp_registration_status_embed_last_resend_request_at', $now );
        }
        update_post_meta( $ticket_id, '_eventosapp_registration_status_embed_last_resend_status', $status_key );
        update_post_meta( $ticket_id, '_eventosapp_registration_status_embed_last_resend_message', sanitize_text_field( $message ) );
        update_post_meta( $ticket_id, '_eventosapp_registration_status_embed_last_event_id', $event_id );

        if ( $mark_success ) {
            $sent_count = absint( get_post_meta( $ticket_id, '_eventosapp_registration_status_embed_resend_sent_count', true ) ) + 1;
            update_post_meta( $ticket_id, '_eventosapp_registration_status_embed_resend_sent_count', $sent_count );
            update_post_meta( $ticket_id, '_eventosapp_registration_status_embed_last_resend_at', $now );
            update_post_meta( $ticket_id, '_eventosapp_registration_status_last_resend_ts', current_time( 'timestamp' ) );
        }

        eventosapp_registration_status_append_ticket_activity( $ticket_id, [
            'action'  => 'resend',
            'status'  => $status,
            'message' => $message,
        ] );
    }
}

/**
 * Renderiza el iframe público.
 */
add_action( 'wp_ajax_eventosapp_registration_status_embed', 'eventosapp_registration_status_render_public_embed' );
add_action( 'wp_ajax_nopriv_eventosapp_registration_status_embed', 'eventosapp_registration_status_render_public_embed' );

if ( ! function_exists( 'eventosapp_registration_status_render_public_embed' ) ) {
    function eventosapp_registration_status_render_public_embed() {
        $event_id = absint( $_GET['event_id'] ?? 0 );
        $token    = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
        $config   = eventosapp_registration_status_validate_public_access( $event_id, $token );

        nocache_headers();
        eventosapp_registration_status_send_embed_headers();

        if ( is_wp_error( $config ) ) {
            eventosapp_registration_status_log( 'Acceso público rechazado', [
                'event_id' => $event_id,
                'code'     => $config->get_error_code(),
            ] );
            echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="font-family:Arial,sans-serif;margin:0;padding:18px;color:#1d2327;"><p>Formulario no disponible.</p></body></html>';
            exit;
        }

        $enabled_fields = eventosapp_registration_status_get_enabled_fields( $event_id, $config );
        $nonce          = wp_create_nonce( 'eventosapp_registration_status_public_' . $event_id . '_' . $token );
        $ajax_url       = admin_url( 'admin-ajax.php' );
        $event_title    = get_the_title( $event_id );

        $primary_color          = sanitize_hex_color( $config['primary_color'] ) ?: '#2271b1';
        $background_color       = sanitize_hex_color( $config['background_color'] ) ?: '#ffffff';
        $text_color             = sanitize_hex_color( $config['text_color'] ) ?: '#1d2327';
        $border_color           = sanitize_hex_color( $config['border_color'] ) ?: '#dcdcde';
        $title_color            = sanitize_hex_color( $config['title_color'] ) ?: '#1d2327';
        $subtitle_color         = sanitize_hex_color( $config['subtitle_color'] ) ?: '#646970';
        $button_text_color      = sanitize_hex_color( $config['button_text_color'] ) ?: '#ffffff';
        $input_background_color = sanitize_hex_color( $config['input_background_color'] ) ?: '#ffffff';
        $content_max_width      = absint( $config['content_max_width'] );
        $outer_padding          = absint( $config['outer_padding'] );
        $card_padding           = absint( $config['card_padding'] );
        $text_align             = in_array( $config['text_align'], [ 'left', 'center', 'right' ], true ) ? $config['text_align'] : 'left';
        $header_title           = eventosapp_registration_status_format_public_text( $config['texts']['header_title'], $event_id, 0 );
        $header_subtitle        = eventosapp_registration_status_format_public_text( $config['texts']['header_subtitle'], $event_id, 0 );

        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html( wp_strip_all_tags( $config['texts']['header_title'] ) ); ?></title>
            <style>
                :root {
                    --evapp-rs-primary: <?php echo esc_html( $primary_color ); ?>;
                    --evapp-rs-bg: <?php echo esc_html( $background_color ); ?>;
                    --evapp-rs-text: <?php echo esc_html( $text_color ); ?>;
                    --evapp-rs-border: <?php echo esc_html( $border_color ); ?>;
                    --evapp-rs-title: <?php echo esc_html( $title_color ); ?>;
                    --evapp-rs-subtitle: <?php echo esc_html( $subtitle_color ); ?>;
                    --evapp-rs-button-text: <?php echo esc_html( $button_text_color ); ?>;
                    --evapp-rs-input-bg: <?php echo esc_html( $input_background_color ); ?>;
                    --evapp-rs-max-width: <?php echo esc_html( $content_max_width ); ?>px;
                    --evapp-rs-outer-padding: <?php echo esc_html( $outer_padding ); ?>px;
                    --evapp-rs-card-padding: <?php echo esc_html( $card_padding ); ?>px;
                    --evapp-rs-align: <?php echo esc_html( $text_align ); ?>;
                }
                html, body { margin:0; padding:0; background:transparent; }
                body { font-family: Arial, Helvetica, sans-serif; color:var(--evapp-rs-text); }
                .evapp-rs-public-wrap { box-sizing:border-box; width:100%; max-width:var(--evapp-rs-max-width); margin:0 auto; padding:var(--evapp-rs-outer-padding); }
                .evapp-rs-card { background:var(--evapp-rs-bg); border:1px solid var(--evapp-rs-border); border-radius:14px; box-shadow:0 8px 24px rgba(0,0,0,.06); padding:var(--evapp-rs-card-padding); text-align:var(--evapp-rs-align); }
                .evapp-rs-card h2 { margin:0 0 8px; font-size:24px; line-height:1.2; color:var(--evapp-rs-title); text-align:var(--evapp-rs-align); }
                .evapp-rs-desc { margin:0 0 18px; color:var(--evapp-rs-subtitle); line-height:1.5; font-size:15px; text-align:var(--evapp-rs-align); }
                .evapp-rs-event { display:inline-block; margin:0 0 14px; padding:6px 10px; border-radius:999px; background:rgba(34,113,177,.10); color:var(--evapp-rs-primary); font-size:12px; font-weight:700; }
                .evapp-rs-field { margin-bottom:14px; text-align:left; }
                .evapp-rs-field label { display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:var(--evapp-rs-text); }
                .evapp-rs-field input { box-sizing:border-box; width:100%; border:1px solid var(--evapp-rs-border); border-radius:10px; padding:12px 13px; font-size:15px; color:var(--evapp-rs-text); background:var(--evapp-rs-input-bg); outline:none; }
                .evapp-rs-field input:focus { border-color:var(--evapp-rs-primary); box-shadow:0 0 0 3px rgba(34,113,177,.14); }
                .evapp-rs-submit, .evapp-rs-resend { width:100%; border:0; border-radius:10px; padding:13px 16px; font-size:15px; font-weight:700; cursor:pointer; background:var(--evapp-rs-primary); color:var(--evapp-rs-button-text); transition:opacity .2s ease, transform .2s ease; }
                .evapp-rs-submit:hover, .evapp-rs-resend:hover { opacity:.92; }
                .evapp-rs-submit:disabled, .evapp-rs-resend:disabled { opacity:.58; cursor:not-allowed; transform:none; }
                .evapp-rs-result { display:none; margin-top:18px; border-radius:12px; padding:16px; border:1px solid var(--evapp-rs-border); background:#f6f7f7; text-align:left; }
                .evapp-rs-result.is-visible { display:block; }
                .evapp-rs-result h3 { margin:0 0 8px; font-size:18px; line-height:1.25; }
                .evapp-rs-result p { margin:0 0 10px; line-height:1.5; }
                .evapp-rs-result.success { border-color:#8bd3a7; background:#f0fff4; }
                .evapp-rs-result.error { border-color:#f1b8b8; background:#fff5f5; }
                .evapp-rs-status-table { width:100%; border-collapse:collapse; margin-top:12px; font-size:14px; }
                .evapp-rs-status-table th, .evapp-rs-status-table td { text-align:left; border-top:1px solid rgba(0,0,0,.08); padding:9px 0; vertical-align:top; }
                .evapp-rs-status-table th { width:42%; font-weight:700; color:rgba(29,35,39,.78); }
                .evapp-rs-resend { margin-top:12px; }
                .evapp-rs-small { display:block; margin-top:10px; font-size:12px; color:rgba(29,35,39,.66); line-height:1.4; }
                @media (max-width: 520px) {
                    .evapp-rs-public-wrap { padding:min(var(--evapp-rs-outer-padding), 10px); }
                    .evapp-rs-card { padding:min(var(--evapp-rs-card-padding), 18px); border-radius:12px; }
                    .evapp-rs-card h2 { font-size:21px; }
                }
            </style>
        </head>
        <body>
            <div class="evapp-rs-public-wrap" id="evapp-rs-public-wrap">
                <div class="evapp-rs-card">
                    <span class="evapp-rs-event"><?php echo esc_html( $event_title ); ?></span>
                    <?php if ( ! empty( $config['texts']['header_title'] ) ) : ?>
                        <h2><?php echo $header_title; ?></h2>
                    <?php endif; ?>
                    <?php if ( ! empty( $config['texts']['header_subtitle'] ) ) : ?>
                        <p class="evapp-rs-desc"><?php echo $header_subtitle; ?></p>
                    <?php endif; ?>

                    <?php if ( empty( $enabled_fields ) ) : ?>
                        <div class="evapp-rs-result error is-visible">
                            <h3>Formulario incompleto</h3>
                            <p>No hay campos de validación activos para este evento.</p>
                        </div>
                    <?php else : ?>
                        <form id="evapp-rs-form">
                            <input type="hidden" name="action" value="eventosapp_registration_status_lookup">
                            <input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
                            <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
                            <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">

                            <?php foreach ( $enabled_fields as $field_key => $field ) : ?>
                                <div class="evapp-rs-field">
                                    <label for="evapp-rs-field-<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $field['public_label'] ); ?></label>
                                    <input
                                        id="evapp-rs-field-<?php echo esc_attr( $field_key ); ?>"
                                        type="<?php echo esc_attr( $field['input_type'] ); ?>"
                                        name="fields[<?php echo esc_attr( $field_key ); ?>]"
                                        placeholder="<?php echo esc_attr( $field['public_placeholder'] ); ?>"
                                        autocomplete="off"
                                    >
                                </div>
                            <?php endforeach; ?>

                            <button class="evapp-rs-submit" type="submit"><?php echo esc_html( $config['texts']['submit_label'] ); ?></button>
                        </form>
                        <div id="evapp-rs-result" class="evapp-rs-result" aria-live="polite"></div>
                    <?php endif; ?>
                </div>
            </div>

            <script>
            (function(){
                var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
                var form = document.getElementById('evapp-rs-form');
                var result = document.getElementById('evapp-rs-result');
                var lastLookupKey = '';

                function resizeFrame(){
                    try {
                        var h = Math.max(
                            document.body.scrollHeight || 0,
                            document.documentElement.scrollHeight || 0
                        );
                        window.parent.postMessage({ type:'eventosappRegistrationStatusHeight', height:h }, '*');
                    } catch(e) {}
                }

                function showResult(html, statusClass){
                    if (!result) return;
                    result.className = 'evapp-rs-result is-visible ' + (statusClass || '');
                    result.innerHTML = html;
                    bindResend();
                    resizeFrame();
                }

                function bindResend(){
                    var btn = document.getElementById('evapp-rs-resend');
                    if (!btn) return;
                    btn.addEventListener('click', function(){
                        if (!lastLookupKey) return;
                        btn.disabled = true;
                        var originalText = btn.textContent;
                        btn.textContent = 'Enviando...';

                        var body = new URLSearchParams();
                        body.set('action', 'eventosapp_registration_status_resend');
                        body.set('event_id', <?php echo wp_json_encode( $event_id ); ?>);
                        body.set('token', <?php echo wp_json_encode( $token ); ?>);
                        body.set('nonce', <?php echo wp_json_encode( $nonce ); ?>);
                        body.set('lookup_key', lastLookupKey);

                        fetch(ajaxUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                            body: body.toString()
                        })
                        .then(function(r){ return r.json(); })
                        .then(function(json){
                            var message = json && json.data && json.data.message ? json.data.message : '';
                            var node = document.getElementById('evapp-rs-resend-message');
                            if (node) {
                                node.textContent = message || (json && json.success ? 'Ticket reenviado.' : 'No fue posible reenviar el ticket.');
                            }
                            if (json && json.success) {
                                btn.disabled = true;
                                if (json.data && json.data.cooldown_message && node) {
                                    node.textContent = message + ' ' + json.data.cooldown_message;
                                }
                            } else {
                                btn.disabled = false;
                            }
                            btn.textContent = originalText;
                            resizeFrame();
                        })
                        .catch(function(){
                            var node = document.getElementById('evapp-rs-resend-message');
                            if (node) node.textContent = 'No fue posible reenviar el ticket.';
                            btn.disabled = false;
                            btn.textContent = originalText;
                            resizeFrame();
                        });
                    });
                }

                if (form) {
                    form.addEventListener('submit', function(e){
                        e.preventDefault();
                        var submit = form.querySelector('button[type="submit"]');
                        if (submit) submit.disabled = true;
                        lastLookupKey = '';

                        fetch(ajaxUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                            body: new URLSearchParams(new FormData(form)).toString()
                        })
                        .then(function(r){ return r.json(); })
                        .then(function(json){
                            if (submit) submit.disabled = false;
                            if (json && json.success && json.data) {
                                lastLookupKey = json.data.lookup_key || '';
                                showResult(json.data.html || '', json.data.found ? 'success' : 'error');
                            } else {
                                var msg = json && json.data && json.data.message ? json.data.message : <?php echo wp_json_encode( $config['texts']['generic_error'] ); ?>;
                                showResult('<h3>Error</h3><p>' + String(msg).replace(/[&<>"']/g, function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]); }) + '</p>', 'error');
                            }
                        })
                        .catch(function(){
                            if (submit) submit.disabled = false;
                            showResult('<h3>Error</h3><p><?php echo esc_js( $config['texts']['generic_error'] ); ?></p>', 'error');
                        });
                    });
                }

                window.addEventListener('load', resizeFrame);
                setTimeout(resizeFrame, 250);
            })();
            </script>
        </body>
        </html>
        <?php
        exit;
    }
}

/**
 * Busca ticket por los datos configurados para el evento.
 */
if ( ! function_exists( 'eventosapp_registration_status_find_ticket' ) ) {
    function eventosapp_registration_status_find_ticket( $event_id, $submitted_values, $config ) {
        $event_id       = absint( $event_id );
        $enabled_fields = eventosapp_registration_status_get_enabled_fields( $event_id, $config );
        $submitted      = [];

        foreach ( $enabled_fields as $field_key => $field ) {
            $value = isset( $submitted_values[ $field_key ] ) ? eventosapp_registration_status_sanitize_public_value( $submitted_values[ $field_key ], $field ) : '';
            if ( $value !== '' ) {
                $submitted[ $field_key ] = $value;
            }
        }

        if ( empty( $enabled_fields ) ) {
            return new WP_Error( 'no_fields', 'No hay campos activos para consultar.' );
        }

        if ( $config['match_mode'] === 'all' && count( $submitted ) < count( $enabled_fields ) ) {
            return new WP_Error( 'missing_fields', $config['texts']['validation_error'] );
        }

        if ( $config['match_mode'] === 'any' && empty( $submitted ) ) {
            return new WP_Error( 'missing_fields', $config['texts']['validation_error'] );
        }

        $first_field_key = key( $submitted );
        $first_field     = $first_field_key && isset( $enabled_fields[ $first_field_key ] ) ? $enabled_fields[ $first_field_key ] : null;

        $candidate_ids = [];
        if ( $first_field ) {
            $exact_query = new WP_Query( [
                'post_type'      => 'eventosapp_ticket',
                'post_status'    => 'any',
                'posts_per_page' => 20,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => [
                    'relation' => 'AND',
                    [
                        'key'     => '_eventosapp_ticket_evento_id',
                        'value'   => $event_id,
                        'compare' => '=',
                    ],
                    [
                        'key'     => $first_field['meta_key'],
                        'value'   => $submitted[ $first_field_key ],
                        'compare' => '=',
                    ],
                ],
            ] );
            $candidate_ids = array_map( 'absint', $exact_query->posts );
        }

        if ( empty( $candidate_ids ) ) {
            $page = 1;
            do {
                $fallback_query = new WP_Query( [
                    'post_type'      => 'eventosapp_ticket',
                    'post_status'    => 'any',
                    'posts_per_page' => 500,
                    'paged'          => $page,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'meta_query'     => [
                        [
                            'key'     => '_eventosapp_ticket_evento_id',
                            'value'   => $event_id,
                            'compare' => '=',
                        ],
                    ],
                ] );

                $candidate_ids = array_merge( $candidate_ids, array_map( 'absint', $fallback_query->posts ) );
                $page++;
            } while ( ! empty( $fallback_query->posts ) && count( $fallback_query->posts ) === 500 && $page <= 10 );
        }

        foreach ( array_unique( $candidate_ids ) as $ticket_id ) {
            $matches = 0;
            foreach ( $submitted as $field_key => $submitted_value ) {
                if ( empty( $enabled_fields[ $field_key ] ) ) {
                    continue;
                }

                $field       = $enabled_fields[ $field_key ];
                $ticket_raw  = get_post_meta( $ticket_id, $field['meta_key'], true );
                $ticket_norm = eventosapp_registration_status_normalize_value( $ticket_raw, $field['normalize_as'] );
                $input_norm  = eventosapp_registration_status_normalize_value( $submitted_value, $field['normalize_as'] );

                if ( $ticket_norm !== '' && $input_norm !== '' && hash_equals( $ticket_norm, $input_norm ) ) {
                    $matches++;
                }
            }

            if ( $config['match_mode'] === 'all' && $matches === count( $enabled_fields ) ) {
                return $ticket_id;
            }

            if ( $config['match_mode'] === 'any' && $matches > 0 ) {
                return $ticket_id;
            }
        }

        return 0;
    }
}

/**
 * Estado de check-in legible.
 */
if ( ! function_exists( 'eventosapp_registration_status_get_checkin_label' ) ) {
    function eventosapp_registration_status_get_checkin_label( $ticket_id ) {
        $status_arr = get_post_meta( $ticket_id, '_eventosapp_checkin_status', true );
        if ( ! is_array( $status_arr ) || empty( $status_arr ) ) {
            return 'Not Checked In';
        }

        foreach ( $status_arr as $status ) {
            if ( is_array( $status ) ) {
                $status = implode( ' ', $status );
            }
            $status = strtolower( (string) $status );
            if ( in_array( $status, [ 'checked_in', 'checked in', '1', 'true', 'si', 'sí' ], true ) ) {
                return 'Checked In';
            }
        }

        return 'Not Checked In';
    }
}

/**
 * Estado de pago legible.
 */
if ( ! function_exists( 'eventosapp_registration_status_get_payment_label' ) ) {
    function eventosapp_registration_status_get_payment_label( $ticket_id ) {
        $status = get_post_meta( $ticket_id, '_eventosapp_estado_pago', true );
        if ( $status === 'pagado' ) {
            return 'Pagado';
        }
        if ( $status === 'no_pagado' ) {
            return 'No pagado';
        }
        return $status ? sanitize_text_field( $status ) : 'Sin estado de pago';
    }
}

/**
 * Placeholders disponibles para mensajes.
 */
if ( ! function_exists( 'eventosapp_registration_status_get_placeholders' ) ) {
    function eventosapp_registration_status_get_placeholders( $event_id, $ticket_id = 0 ) {
        $event_id  = absint( $event_id );
        $ticket_id = absint( $ticket_id );

        $data = [
            '{{evento}}'             => $event_id ? get_the_title( $event_id ) : '',
            '{{nombre}}'             => '',
            '{{apellido}}'           => '',
            '{{email}}'              => '',
            '{{localidad}}'          => '',
            '{{estado_inscripcion}}' => $ticket_id ? 'Confirmada' : 'No encontrada',
            '{{estado_pago}}'        => '',
            '{{estado_checkin}}'     => '',
        ];

        if ( $ticket_id ) {
            $data['{{nombre}}']         = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true );
            $data['{{apellido}}']       = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );
            $data['{{email}}']          = get_post_meta( $ticket_id, '_eventosapp_asistente_email', true );
            $data['{{localidad}}']      = get_post_meta( $ticket_id, '_eventosapp_asistente_localidad', true );
            $data['{{estado_pago}}']    = eventosapp_registration_status_get_payment_label( $ticket_id );
            $data['{{estado_checkin}}'] = eventosapp_registration_status_get_checkin_label( $ticket_id );
        }

        foreach ( $data as $key => $value ) {
            $data[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
        }

        return $data;
    }
}

/**
 * Aplica placeholders y escapa para HTML visible.
 */
if ( ! function_exists( 'eventosapp_registration_status_format_public_text' ) ) {
    function eventosapp_registration_status_format_public_text( $text, $event_id, $ticket_id = 0 ) {
        $placeholders = eventosapp_registration_status_get_placeholders( $event_id, $ticket_id );
        $text         = strtr( (string) $text, $placeholders );
        return nl2br( esc_html( $text ) );
    }
}

/**
 * Render HTML para inscripción encontrada.
 */
if ( ! function_exists( 'eventosapp_registration_status_render_found_html' ) ) {
    function eventosapp_registration_status_render_found_html( $ticket_id, $event_id, $config, $lookup_key = '' ) {
        $ticket_id = absint( $ticket_id );
        $event_id  = absint( $event_id );

        $title   = eventosapp_registration_status_format_public_text( $config['texts']['success_title'], $event_id, $ticket_id );
        $message = eventosapp_registration_status_format_public_text( $config['texts']['success_message'], $event_id, $ticket_id );

        ob_start();
        ?>
        <h3><?php echo $title; ?></h3>
        <p><?php echo $message; ?></p>
        <table class="evapp-rs-status-table">
            <tbody>
                <tr>
                    <th>Estado de inscripción</th>
                    <td>Confirmada</td>
                </tr>
                <?php if ( ! empty( $config['show_payment_status'] ) && $config['show_payment_status'] === '1' ) : ?>
                    <tr>
                        <th>Estado de pago</th>
                        <td><?php echo esc_html( eventosapp_registration_status_get_payment_label( $ticket_id ) ); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ( ! empty( $config['show_checkin_status'] ) && $config['show_checkin_status'] === '1' ) : ?>
                    <tr>
                        <th>Check-in</th>
                        <td><?php echo esc_html( eventosapp_registration_status_get_checkin_label( $ticket_id ) ); ?></td>
                    </tr>
                <?php endif; ?>
                <?php
                $localidad = get_post_meta( $ticket_id, '_eventosapp_asistente_localidad', true );
                if ( ! empty( $config['show_localidad'] ) && $config['show_localidad'] === '1' && $localidad ) :
                    ?>
                    <tr>
                        <th>Localidad</th>
                        <td><?php echo esc_html( $localidad ); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( ! empty( $config['resend_enabled'] ) && $config['resend_enabled'] === '1' && $lookup_key ) : ?>
            <?php $cooldown_state = eventosapp_registration_status_get_resend_cooldown_state( $ticket_id, $config ); ?>
            <button type="button" id="evapp-rs-resend" class="evapp-rs-resend" <?php disabled( ! $cooldown_state['allowed'] ); ?>><?php echo esc_html( $config['texts']['resend_button_label'] ); ?></button>
            <span id="evapp-rs-resend-message" class="evapp-rs-small"><?php echo $cooldown_state['allowed'] ? '' : esc_html( $cooldown_state['message'] ); ?></span>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }
}

/**
 * Render HTML para no encontrado o error controlado.
 */
if ( ! function_exists( 'eventosapp_registration_status_render_not_found_html' ) ) {
    function eventosapp_registration_status_render_not_found_html( $event_id, $config ) {
        $title   = eventosapp_registration_status_format_public_text( $config['texts']['not_found_title'], $event_id, 0 );
        $message = eventosapp_registration_status_format_public_text( $config['texts']['not_found_message'], $event_id, 0 );

        return '<h3>' . $title . '</h3><p>' . $message . '</p>';
    }
}

/**
 * Acción AJAX pública para consulta.
 */
add_action( 'wp_ajax_eventosapp_registration_status_lookup', 'eventosapp_registration_status_ajax_lookup' );
add_action( 'wp_ajax_nopriv_eventosapp_registration_status_lookup', 'eventosapp_registration_status_ajax_lookup' );

if ( ! function_exists( 'eventosapp_registration_status_ajax_lookup' ) ) {
    function eventosapp_registration_status_ajax_lookup() {
        $event_id = absint( $_POST['event_id'] ?? 0 );
        $token    = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
        $nonce    = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );

        $config = eventosapp_registration_status_validate_public_access( $event_id, $token );
        if ( is_wp_error( $config ) ) {
            wp_send_json_error( [ 'message' => $config->get_error_message() ], 403 );
        }

        if ( ! wp_verify_nonce( $nonce, 'eventosapp_registration_status_public_' . $event_id . '_' . $token ) ) {
            wp_send_json_error( [ 'message' => $config['texts']['generic_error'] ], 403 );
        }

        if ( ! eventosapp_registration_status_rate_limit( 'lookup', $event_id, 30, 10 * MINUTE_IN_SECONDS ) ) {
            wp_send_json_error( [ 'message' => 'Has realizado demasiadas consultas. Intenta nuevamente más tarde.' ], 429 );
        }

        $submitted = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : [];
        $ticket_id = eventosapp_registration_status_find_ticket( $event_id, $submitted, $config );

        if ( is_wp_error( $ticket_id ) ) {
            wp_send_json_error( [ 'message' => $ticket_id->get_error_message() ], 400 );
        }

        if ( ! $ticket_id ) {
            eventosapp_registration_status_log( 'Consulta sin coincidencia', [ 'event_id' => $event_id ] );
            wp_send_json_success( [
                'found'      => false,
                'html'       => eventosapp_registration_status_render_not_found_html( $event_id, $config ),
                'lookup_key' => '',
            ] );
        }

        $lookup_key = wp_generate_password( 32, false, false );
        set_transient(
            'evapp_rs_lookup_' . md5( $lookup_key ),
            [
                'ticket_id' => absint( $ticket_id ),
                'event_id'  => absint( $event_id ),
                'token'     => $token,
            ],
            15 * MINUTE_IN_SECONDS
        );

        eventosapp_registration_status_record_lookup_activity( $ticket_id, $event_id );

        eventosapp_registration_status_log( 'Consulta con coincidencia', [
            'event_id'  => $event_id,
            'ticket_id' => $ticket_id,
        ] );

        wp_send_json_success( [
            'found'      => true,
            'html'       => eventosapp_registration_status_render_found_html( $ticket_id, $event_id, $config, $lookup_key ),
            'lookup_key' => $lookup_key,
        ] );
    }
}

/**
 * Registra auditoría básica del reenvío.
 */
if ( ! function_exists( 'eventosapp_registration_status_record_email_history' ) ) {
    function eventosapp_registration_status_record_email_history( $ticket_id, $email, $status, $message = '' ) {
        $ticket_id = absint( $ticket_id );
        $history   = get_post_meta( $ticket_id, '_eventosapp_ticket_email_history', true );
        if ( ! is_array( $history ) ) {
            $history = [];
        }

        $history[] = [
            'date'    => current_time( 'mysql' ),
            'to'      => sanitize_email( $email ),
            'status'  => sanitize_text_field( $status ),
            'source'  => 'registration_status_embed',
            'message' => sanitize_text_field( $message ),
        ];

        update_post_meta( $ticket_id, '_eventosapp_ticket_email_history', array_slice( $history, -30 ) );
        update_post_meta( $ticket_id, '_eventosapp_ticket_last_email_at', current_time( 'mysql' ) );
        update_post_meta( $ticket_id, '_eventosapp_ticket_last_email_to', sanitize_email( $email ) );
        update_post_meta( $ticket_id, '_eventosapp_ticket_email_sent_status', $status === 'sent' ? 'enviado' : 'error' );
    }
}

/**
 * Convierte URL de upload a path local para adjuntos opcionales.
 */
if ( ! function_exists( 'eventosapp_registration_status_upload_url_to_path' ) ) {
    function eventosapp_registration_status_upload_url_to_path( $url ) {
        $url = esc_url_raw( $url );
        if ( ! $url ) {
            return '';
        }

        $upload = wp_upload_dir();
        $baseurl = isset( $upload['baseurl'] ) ? $upload['baseurl'] : '';
        $basedir = isset( $upload['basedir'] ) ? $upload['basedir'] : '';

        $clean_url = strtok( $url, '?' );
        if ( $baseurl && $basedir && strpos( $clean_url, $baseurl ) === 0 ) {
            $path = str_replace( $baseurl, $basedir, $clean_url );
            return file_exists( $path ) ? $path : '';
        }

        return '';
    }
}

/**
 * Envío básico de respaldo cuando no existe una función personalizada disponible.
 */
if ( ! function_exists( 'eventosapp_registration_status_send_basic_ticket_email' ) ) {
    function eventosapp_registration_status_send_basic_ticket_email( $ticket_id, $event_id, $config ) {
        $email = sanitize_email( get_post_meta( $ticket_id, '_eventosapp_asistente_email', true ) );
        if ( ! $email ) {
            return new WP_Error( 'missing_email', 'El ticket no tiene correo registrado.' );
        }

        $event_name = get_the_title( $event_id );
        $first_name = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true );
        $last_name  = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );
        $ticket_uid = get_post_meta( $ticket_id, 'eventosapp_ticketID', true );

        $subject = strtr( $config['texts']['resend_subject'], eventosapp_registration_status_get_placeholders( $event_id, $ticket_id ) );
        $subject = sanitize_text_field( $subject ?: 'Tu ticket para ' . $event_name );

        $qr_url = '';
        if ( $ticket_uid && function_exists( 'eventosapp_get_ticket_qr_url' ) ) {
            $qr_url = eventosapp_get_ticket_qr_url( $ticket_uid );
        }

        $pdf_url            = get_post_meta( $ticket_id, '_eventosapp_ticket_pdf_url', true );
        $ics_url            = get_post_meta( $ticket_id, '_eventosapp_ticket_ics_url', true );
        $wallet_android_url = get_post_meta( $ticket_id, '_eventosapp_ticket_wallet_android_url', true );
        $wallet_apple_url   = get_post_meta( $ticket_id, '_eventosapp_ticket_wallet_apple', true );
        if ( ! $wallet_apple_url ) {
            $wallet_apple_url = get_post_meta( $ticket_id, '_eventosapp_ticket_wallet_apple_url', true );
        }
        if ( ! $wallet_apple_url ) {
            $wallet_apple_url = get_post_meta( $ticket_id, '_eventosapp_ticket_pkpass_url', true );
        }

        $links = [];
        if ( $qr_url ) {
            $links[] = '<li><a href="' . esc_url( $qr_url ) . '">Ver código QR</a></li>';
        }
        if ( $pdf_url ) {
            $links[] = '<li><a href="' . esc_url( $pdf_url ) . '">Descargar PDF del ticket</a></li>';
        }
        if ( $ics_url ) {
            $links[] = '<li><a href="' . esc_url( $ics_url ) . '">Agregar al calendario</a></li>';
        }
        if ( $wallet_android_url ) {
            $links[] = '<li><a href="' . esc_url( $wallet_android_url ) . '">Agregar a Google Wallet</a></li>';
        }
        if ( $wallet_apple_url ) {
            $links[] = '<li><a href="' . esc_url( $wallet_apple_url ) . '">Agregar a Apple Wallet</a></li>';
        }

        $full_name = trim( $first_name . ' ' . $last_name );
        $body  = '<div style="font-family:Arial,Helvetica,sans-serif;line-height:1.55;color:#1d2327;">';
        $body .= '<h2 style="margin:0 0 12px;">Tu ticket está disponible</h2>';
        $body .= '<p>Hola ' . esc_html( $full_name ?: 'asistente' ) . ',</p>';
        $body .= '<p>Te reenviamos la información de tu ticket para <strong>' . esc_html( $event_name ) . '</strong>.</p>';
        if ( $ticket_uid ) {
            $body .= '<p><strong>ID del ticket:</strong> ' . esc_html( $ticket_uid ) . '</p>';
        }
        if ( ! empty( $links ) ) {
            $body .= '<ul>' . implode( '', $links ) . '</ul>';
        } else {
            $body .= '<p>Tu inscripción está registrada. Si necesitas el archivo del ticket, contacta al organizador.</p>';
        }
        $body .= '<p style="font-size:12px;color:#646970;">Este correo fue solicitado desde el formulario de consulta de inscripción.</p>';
        $body .= '</div>';

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $attachments = [];

        $pdf_path = eventosapp_registration_status_upload_url_to_path( $pdf_url );
        if ( $pdf_path ) {
            $attachments[] = $pdf_path;
        }

        $ics_path = eventosapp_registration_status_upload_url_to_path( $ics_url );
        if ( $ics_path ) {
            $attachments[] = $ics_path;
        }

        $sent = wp_mail( $email, $subject, $body, $headers, $attachments );
        eventosapp_registration_status_record_email_history( $ticket_id, $email, $sent ? 'sent' : 'failed', $sent ? 'fallback_basic_email' : 'wp_mail_failed' );

        return $sent ? true : new WP_Error( 'mail_failed', 'wp_mail no pudo enviar el correo.' );
    }
}

/**
 * Intenta usar un enviador existente y, si no existe, usa el envío básico de respaldo.
 */
if ( ! function_exists( 'eventosapp_registration_status_resend_ticket_email' ) ) {
    function eventosapp_registration_status_resend_ticket_email( $ticket_id, $event_id, $config ) {
        $ticket_id = absint( $ticket_id );
        $event_id  = absint( $event_id );
        $email     = sanitize_email( get_post_meta( $ticket_id, '_eventosapp_asistente_email', true ) );

        if ( ! $ticket_id || ! $event_id ) {
            return new WP_Error( 'invalid_ticket', 'Ticket inválido.' );
        }

        if ( ! $email ) {
            return new WP_Error( 'missing_email', 'El ticket no tiene correo registrado.' );
        }

        /**
         * Permite que una instalación conecte aquí su enviador oficial sin editar este archivo.
         * Retornar true si el envío fue exitoso, WP_Error si falló, o null para continuar con el enviador oficial.
         */
        $custom_result = apply_filters( 'eventosapp_registration_status_custom_resend_ticket', null, $ticket_id, $event_id, $config );
        if ( $custom_result === true ) {
            eventosapp_registration_status_record_email_history( $ticket_id, $email, 'sent', 'custom_filter' );
            return true;
        }
        if ( is_wp_error( $custom_result ) ) {
            eventosapp_registration_status_record_email_history( $ticket_id, $email, 'failed', $custom_result->get_error_message() );
            return $custom_result;
        }

        /**
         * Enviador oficial de EventosApp.
         * Este es el mismo flujo usado por los otros medios: aplica variantes, prepara Wallet/PDF/ICS,
         * construye el HTML desde la plantilla efectiva y registra el historial de correo.
         */
        if ( function_exists( 'eventosapp_send_ticket_email_now' ) ) {
            $result = eventosapp_send_ticket_email_now( $ticket_id, [
                'source' => 'registration_status_embed',
                'force'  => true,
            ] );

            $ok      = is_array( $result ) && isset( $result[0] ) ? (bool) $result[0] : ( $result === true );
            $message = is_array( $result ) && isset( $result[1] ) ? (string) $result[1] : '';

            eventosapp_registration_status_log( 'Resultado enviador oficial', [
                'ticket_id'   => $ticket_id,
                'event_id'    => $event_id,
                'ok'          => $ok ? 'yes' : 'no',
                'message'     => $message,
                'variant_key' => get_post_meta( $ticket_id, '_eventosapp_ticket_variant_key', true ),
                'template'    => get_post_meta( $ticket_id, '_eventosapp_ticket_email_template_override', true ),
            ] );

            if ( $ok ) {
                return true;
            }

            return new WP_Error( 'official_mail_failed', $message ?: 'El enviador oficial no pudo completar el reenvío.' );
        }

        $candidate_functions = [
            'eventosapp_enviar_ticket_evento',
            'eventosapp_send_ticket_email_by_id',
            'eventosapp_send_ticket_email_to_attendee',
            'eventosapp_ticket_send_email',
            'enviar_ticket_evento',
        ];

        foreach ( $candidate_functions as $function_name ) {
            if ( ! function_exists( $function_name ) ) {
                continue;
            }

            try {
                $reflection = new ReflectionFunction( $function_name );
                if ( $reflection->getNumberOfParameters() < 1 ) {
                    continue;
                }

                $result = $function_name( $ticket_id );
                if ( $result === false || is_wp_error( $result ) ) {
                    eventosapp_registration_status_log( 'Función de envío existente no completó el envío', [
                        'function'  => $function_name,
                        'ticket_id' => $ticket_id,
                    ] );
                    continue;
                }

                eventosapp_registration_status_record_email_history( $ticket_id, $email, 'sent', 'existing_function:' . $function_name );
                return true;
            } catch ( Throwable $e ) {
                eventosapp_registration_status_log( 'Error usando función de envío existente', [
                    'function'  => $function_name,
                    'ticket_id' => $ticket_id,
                    'error'     => $e->getMessage(),
                ] );
            }
        }

        /**
         * Respaldo extremo: solo se usa si el enviador oficial no existe en esta instalación.
         */
        return eventosapp_registration_status_send_basic_ticket_email( $ticket_id, $event_id, $config );
    }
}

/**
 * Acción AJAX pública para reenvío del ticket al correo registrado.
 */
add_action( 'wp_ajax_eventosapp_registration_status_resend', 'eventosapp_registration_status_ajax_resend' );
add_action( 'wp_ajax_nopriv_eventosapp_registration_status_resend', 'eventosapp_registration_status_ajax_resend' );

if ( ! function_exists( 'eventosapp_registration_status_ajax_resend' ) ) {
    function eventosapp_registration_status_ajax_resend() {
        $event_id    = absint( $_POST['event_id'] ?? 0 );
        $token       = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
        $nonce       = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
        $lookup_key  = sanitize_text_field( wp_unslash( $_POST['lookup_key'] ?? '' ) );

        $config = eventosapp_registration_status_validate_public_access( $event_id, $token );
        if ( is_wp_error( $config ) ) {
            wp_send_json_error( [ 'message' => $config->get_error_message() ], 403 );
        }

        if ( empty( $config['resend_enabled'] ) || $config['resend_enabled'] !== '1' ) {
            wp_send_json_error( [ 'message' => $config['texts']['resend_error_message'] ], 403 );
        }

        if ( ! wp_verify_nonce( $nonce, 'eventosapp_registration_status_public_' . $event_id . '_' . $token ) ) {
            wp_send_json_error( [ 'message' => $config['texts']['resend_error_message'] ], 403 );
        }

        if ( ! $lookup_key ) {
            wp_send_json_error( [ 'message' => $config['texts']['resend_error_message'] ], 400 );
        }

        $lookup = get_transient( 'evapp_rs_lookup_' . md5( $lookup_key ) );
        if ( ! is_array( $lookup ) || empty( $lookup['ticket_id'] ) || absint( $lookup['event_id'] ) !== $event_id || empty( $lookup['token'] ) || ! hash_equals( (string) $lookup['token'], (string) $token ) ) {
            wp_send_json_error( [ 'message' => $config['texts']['resend_error_message'] ], 403 );
        }

        $ticket_id = absint( $lookup['ticket_id'] );
        if ( get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
            wp_send_json_error( [ 'message' => $config['texts']['resend_error_message'] ], 400 );
        }

        if ( ! eventosapp_registration_status_rate_limit( 'resend', $event_id, 3, 15 * MINUTE_IN_SECONDS, (string) $ticket_id ) ) {
            eventosapp_registration_status_record_resend_activity( $ticket_id, $event_id, 'rate_limited', 'Bloqueado por rate limit de seguridad.', false );
            wp_send_json_error( [ 'message' => 'Has solicitado demasiados reenvíos. Intenta nuevamente más tarde.' ], 429 );
        }

        $cooldown_state = eventosapp_registration_status_get_resend_cooldown_state( $ticket_id, $config );
        if ( empty( $cooldown_state['allowed'] ) ) {
            eventosapp_registration_status_record_resend_activity( $ticket_id, $event_id, 'cooldown_blocked', $cooldown_state['message'], false );
            wp_send_json_error( [
                'message'           => $cooldown_state['message'],
                'cooldown_remaining' => $cooldown_state['remaining_seconds'],
            ], 429 );
        }

        eventosapp_registration_status_record_resend_activity( $ticket_id, $event_id, 'requested', 'Solicitud de reenvío recibida.', false );

        $result = eventosapp_registration_status_resend_ticket_email( $ticket_id, $event_id, $config );

        if ( is_wp_error( $result ) || $result !== true ) {
            $error_message = is_wp_error( $result ) ? $result->get_error_message() : 'unknown';
            eventosapp_registration_status_record_resend_activity( $ticket_id, $event_id, 'failed', $error_message, false );
            eventosapp_registration_status_log( 'Reenvío fallido', [
                'event_id'  => $event_id,
                'ticket_id' => $ticket_id,
                'error'     => $error_message,
            ] );
            wp_send_json_error( [ 'message' => $config['texts']['resend_error_message'] ], 500 );
        }

        eventosapp_registration_status_record_resend_activity( $ticket_id, $event_id, 'sent', 'Correo reenviado correctamente.', true );
        $cooldown_state = eventosapp_registration_status_get_resend_cooldown_state( $ticket_id, $config );

        eventosapp_registration_status_log( 'Reenvío exitoso', [
            'event_id'  => $event_id,
            'ticket_id' => $ticket_id,
        ] );

        wp_send_json_success( [
            'message'           => $config['texts']['resend_success_message'],
            'cooldown_remaining' => $cooldown_state['remaining_seconds'],
            'cooldown_message'   => $cooldown_state['message'],
        ] );
    }
}
