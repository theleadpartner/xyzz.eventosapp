<?php
/**
 * EventosApp - Personalización del Kiosko de Autogestión.
 *
 * Agrega al CPT eventosapp_event un metabox para definir el tema visual,
 * fondo, logo principal y logos adicionales del módulo de autogestión.
 * También registra un widget Elementor para imprimir los logos adicionales
 * configurados por evento.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'eventosapp_kiosk_theme_palette' ) ) {
    function eventosapp_kiosk_theme_palette( $theme = 'dark' ) {
        $theme = sanitize_key( (string) $theme );

        $palettes = [
            'light' => [
                'bg_default'     => '#ffffff',
                'panel_bg'       => '#ffffff',
                'panel_border'   => '#e5e7eb',
                'panel_shadow'   => '0 18px 50px rgba(15, 23, 42, 0.12)',
                'title'          => '#111827',
                'text'           => '#1f2937',
                'muted'          => '#4b5563',
                'label'          => '#1e3a8a',
                'input_bg'       => '#ffffff',
                'input_text'     => '#111827',
                'input_border'   => '#cbd5e1',
                'button_bg'      => '#2563eb',
                'button_text'    => '#ffffff',
                'button_hover'   => '#1d4ed8',
                'button_active'  => '#1e40af',
                'secondary_bg'   => '#e5e7eb',
                'secondary_text' => '#111827',
            ],
            'dark' => [
                'bg_default'     => '#202832',
                'panel_bg'       => '#111827',
                'panel_border'   => 'rgba(255, 255, 255, 0.18)',
                'panel_shadow'   => '0 22px 70px rgba(0, 0, 0, 0.28)',
                'title'          => '#ffffff',
                'text'           => '#f8fafc',
                'muted'          => '#d1d5db',
                'label'          => '#dbeafe',
                'input_bg'       => '#ffffff',
                'input_text'     => '#111827',
                'input_border'   => '#e5e7eb',
                'button_bg'      => '#2563eb',
                'button_text'    => '#ffffff',
                'button_hover'   => '#1d4ed8',
                'button_active'  => '#1e40af',
                'secondary_bg'   => '#e5e7eb',
                'secondary_text' => '#111827',
            ],
        ];

        return $palettes[ $theme ] ?? $palettes['dark'];
    }
}

if ( ! function_exists( 'eventosapp_kiosk_sanitize_css_class' ) ) {
    function eventosapp_kiosk_sanitize_css_class( $class ) {
        $class = trim( (string) $class );
        $class = ltrim( $class, '.' );
        $class = preg_replace( '/\s+/', '-', $class );
        $class = sanitize_html_class( $class );

        return $class ?: 'eventosapp-kiosk-bg';
    }
}

if ( ! function_exists( 'eventosapp_kiosk_validate_choice' ) ) {
    function eventosapp_kiosk_validate_choice( $value, array $allowed, $default ) {
        $value = sanitize_key( (string) $value );
        return in_array( $value, $allowed, true ) ? $value : $default;
    }
}

if ( ! function_exists( 'eventosapp_kiosk_sanitize_positive_int' ) ) {
    function eventosapp_kiosk_sanitize_positive_int( $value, $default, $min = 0, $max = 5000 ) {
        $value = absint( $value );
        if ( $value < $min ) {
            $value = absint( $default );
        }
        if ( $max > 0 && $value > $max ) {
            $value = $max;
        }
        return $value;
    }
}

if ( ! function_exists( 'eventosapp_kiosk_default_settings' ) ) {
    function eventosapp_kiosk_default_settings() {
        return [
            'theme'                    => 'dark',
            'bg_type'                  => 'default',
            'bg_color'                 => '',
            'bg_image_id'              => 0,
            'bg_image_url'             => '',
            'bg_size'                  => 'cover',
            'bg_position'              => 'center-center',
            'bg_repeat'                => 'no-repeat',
            'bg_attachment'            => 'scroll',
            'bg_class'                 => 'eventosapp-kiosk-bg',
            'main_logo_id'             => 0,
            'main_logo_url'            => '',
            'main_logo_max_width'      => 340,
            'main_logo_max_height'     => 110,
            'extra_logos'              => [],
            'extra_logos_wrap_width'   => 920,
            'extra_logos_wrap_height'  => 120,
            'extra_logo_max_height'    => 70,
            'extra_logo_gap'           => 28,
        ];
    }
}

if ( ! function_exists( 'eventosapp_kiosk_get_image_url' ) ) {
    function eventosapp_kiosk_get_image_url( $attachment_id, $stored_url = '' ) {
        $attachment_id = absint( $attachment_id );
        if ( $attachment_id ) {
            $image_url = wp_get_attachment_image_url( $attachment_id, 'full' );
            if ( $image_url ) {
                return esc_url_raw( $image_url );
            }
        }

        return $stored_url ? esc_url_raw( $stored_url ) : '';
    }
}

if ( ! function_exists( 'eventosapp_kiosk_get_settings' ) ) {
    function eventosapp_kiosk_get_settings( $event_id ) {
        $event_id = absint( $event_id );
        $defaults = eventosapp_kiosk_default_settings();

        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            $palette = eventosapp_kiosk_theme_palette( $defaults['theme'] );
            $defaults['palette'] = $palette;
            $defaults['bg_color_effective'] = $palette['bg_default'];
            return $defaults;
        }

        $theme = eventosapp_kiosk_validate_choice(
            get_post_meta( $event_id, '_eventosapp_kiosk_theme', true ) ?: $defaults['theme'],
            [ 'light', 'dark' ],
            $defaults['theme']
        );

        $palette = eventosapp_kiosk_theme_palette( $theme );

        $bg_type = eventosapp_kiosk_validate_choice(
            get_post_meta( $event_id, '_eventosapp_kiosk_bg_type', true ) ?: $defaults['bg_type'],
            [ 'default', 'color', 'image' ],
            $defaults['bg_type']
        );

        $bg_color = sanitize_hex_color( get_post_meta( $event_id, '_eventosapp_kiosk_bg_color', true ) );
        $bg_color_effective = $bg_color ?: $palette['bg_default'];

        $bg_image_id = absint( get_post_meta( $event_id, '_eventosapp_kiosk_bg_image_id', true ) );
        $bg_image_url = eventosapp_kiosk_get_image_url( $bg_image_id, get_post_meta( $event_id, '_eventosapp_kiosk_bg_image_url', true ) );

        $main_logo_id = absint( get_post_meta( $event_id, '_eventosapp_kiosk_main_logo_id', true ) );
        $main_logo_url = eventosapp_kiosk_get_image_url( $main_logo_id, get_post_meta( $event_id, '_eventosapp_kiosk_main_logo_url', true ) );

        $stored_extra_logos = get_post_meta( $event_id, '_eventosapp_kiosk_extra_logos', true );
        $extra_logos = [];
        if ( is_array( $stored_extra_logos ) ) {
            foreach ( $stored_extra_logos as $logo ) {
                if ( ! is_array( $logo ) ) {
                    continue;
                }
                $logo_id = absint( $logo['id'] ?? 0 );
                $logo_url = eventosapp_kiosk_get_image_url( $logo_id, $logo['url'] ?? '' );
                if ( $logo_url ) {
                    $extra_logos[] = [
                        'id'  => $logo_id,
                        'url' => $logo_url,
                    ];
                }
            }
        }

        $settings = [
            'theme'                    => $theme,
            'bg_type'                  => $bg_type,
            'bg_color'                 => $bg_color,
            'bg_color_effective'       => $bg_color_effective,
            'bg_image_id'              => $bg_image_id,
            'bg_image_url'             => $bg_image_url,
            'bg_size'                  => eventosapp_kiosk_validate_choice( get_post_meta( $event_id, '_eventosapp_kiosk_bg_size', true ) ?: $defaults['bg_size'], [ 'cover', 'contain', 'auto' ], $defaults['bg_size'] ),
            'bg_position'              => eventosapp_kiosk_validate_choice( get_post_meta( $event_id, '_eventosapp_kiosk_bg_position', true ) ?: $defaults['bg_position'], [ 'center-center', 'center-top', 'center-bottom', 'left-top', 'left-center', 'left-bottom', 'right-top', 'right-center', 'right-bottom' ], $defaults['bg_position'] ),
            'bg_repeat'                => eventosapp_kiosk_validate_choice( get_post_meta( $event_id, '_eventosapp_kiosk_bg_repeat', true ) ?: $defaults['bg_repeat'], [ 'no-repeat', 'repeat', 'repeat-x', 'repeat-y' ], $defaults['bg_repeat'] ),
            'bg_attachment'            => eventosapp_kiosk_validate_choice( get_post_meta( $event_id, '_eventosapp_kiosk_bg_attachment', true ) ?: $defaults['bg_attachment'], [ 'scroll', 'fixed' ], $defaults['bg_attachment'] ),
            'bg_class'                 => eventosapp_kiosk_sanitize_css_class( get_post_meta( $event_id, '_eventosapp_kiosk_bg_class', true ) ?: $defaults['bg_class'] ),
            'main_logo_id'             => $main_logo_id,
            'main_logo_url'            => $main_logo_url,
            'main_logo_max_width'      => eventosapp_kiosk_sanitize_positive_int( get_post_meta( $event_id, '_eventosapp_kiosk_main_logo_max_width', true ), $defaults['main_logo_max_width'], 40, 1400 ),
            'main_logo_max_height'     => eventosapp_kiosk_sanitize_positive_int( get_post_meta( $event_id, '_eventosapp_kiosk_main_logo_max_height', true ), $defaults['main_logo_max_height'], 30, 800 ),
            'extra_logos'              => $extra_logos,
            'extra_logos_wrap_width'   => eventosapp_kiosk_sanitize_positive_int( get_post_meta( $event_id, '_eventosapp_kiosk_extra_logos_wrap_width', true ), $defaults['extra_logos_wrap_width'], 120, 2200 ),
            'extra_logos_wrap_height'  => eventosapp_kiosk_sanitize_positive_int( get_post_meta( $event_id, '_eventosapp_kiosk_extra_logos_wrap_height', true ), $defaults['extra_logos_wrap_height'], 40, 1000 ),
            'extra_logo_max_height'    => eventosapp_kiosk_sanitize_positive_int( get_post_meta( $event_id, '_eventosapp_kiosk_extra_logo_max_height', true ), $defaults['extra_logo_max_height'], 20, 500 ),
            'extra_logo_gap'           => eventosapp_kiosk_sanitize_positive_int( get_post_meta( $event_id, '_eventosapp_kiosk_extra_logo_gap', true ), $defaults['extra_logo_gap'], 0, 180 ),
            'palette'                  => $palette,
        ];

        return wp_parse_args( $settings, $defaults );
    }
}

if ( ! function_exists( 'eventosapp_kiosk_resolve_event_id' ) ) {
    function eventosapp_kiosk_resolve_event_id( $preferred_event_id = 0 ) {
        $preferred_event_id = absint( $preferred_event_id );
        if ( $preferred_event_id && get_post_type( $preferred_event_id ) === 'eventosapp_event' ) {
            return $preferred_event_id;
        }

        $request_keys = [ 'eventosapp_event_id', 'evento_id', 'event_id' ];
        foreach ( $request_keys as $key ) {
            if ( isset( $_REQUEST[ $key ] ) ) {
                $request_event_id = absint( wp_unslash( $_REQUEST[ $key ] ) );
                if ( $request_event_id && get_post_type( $request_event_id ) === 'eventosapp_event' ) {
                    return $request_event_id;
                }
            }
        }

        if ( is_singular( 'eventosapp_event' ) ) {
            $queried_event_id = absint( get_queried_object_id() );
            if ( $queried_event_id ) {
                return $queried_event_id;
            }
        }

        global $post;
        if ( $post instanceof WP_Post ) {
            if ( $post->post_type === 'eventosapp_event' ) {
                return absint( $post->ID );
            }

            $meta_keys = [
                '_eventosapp_kiosk_event_id',
                '_eventosapp_self_checkin_event_id',
                '_eventosapp_event_id',
            ];
            foreach ( $meta_keys as $meta_key ) {
                $meta_event_id = absint( get_post_meta( $post->ID, $meta_key, true ) );
                if ( $meta_event_id && get_post_type( $meta_event_id ) === 'eventosapp_event' ) {
                    return $meta_event_id;
                }
            }
        }

        return 0;
    }
}

if ( ! function_exists( 'eventosapp_kiosk_css_position' ) ) {
    function eventosapp_kiosk_css_position( $position ) {
        $map = [
            'center-center' => 'center center',
            'center-top'    => 'center top',
            'center-bottom' => 'center bottom',
            'left-top'      => 'left top',
            'left-center'   => 'left center',
            'left-bottom'   => 'left bottom',
            'right-top'     => 'right top',
            'right-center'  => 'right center',
            'right-bottom'  => 'right bottom',
        ];
        return $map[ $position ] ?? 'center center';
    }
}

if ( ! function_exists( 'eventosapp_kiosk_build_frontend_css' ) ) {
    function eventosapp_kiosk_build_frontend_css( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) {
            return '';
        }

        $settings = eventosapp_kiosk_get_settings( $event_id );
        $palette = $settings['palette'];
        $bg_class = eventosapp_kiosk_sanitize_css_class( $settings['bg_class'] );
        $scoped_selector = '.evapp-kiosk-theme-event-' . $event_id;
        $background_selector = '.' . $bg_class . ', ' . $scoped_selector;

        $bg_declarations = [
            'background-color:' . esc_html( $settings['bg_color_effective'] ),
        ];

        if ( $settings['bg_type'] === 'image' && ! empty( $settings['bg_image_url'] ) ) {
            $bg_declarations[] = 'background-image:url("' . esc_url( $settings['bg_image_url'] ) . '")';
            $bg_declarations[] = 'background-size:' . esc_html( $settings['bg_size'] );
            $bg_declarations[] = 'background-position:' . esc_html( eventosapp_kiosk_css_position( $settings['bg_position'] ) );
            $bg_declarations[] = 'background-repeat:' . esc_html( $settings['bg_repeat'] );
            $bg_declarations[] = 'background-attachment:' . esc_html( $settings['bg_attachment'] );
        }

        $vars = [
            '--evapp-kiosk-bg'             => $settings['bg_color_effective'],
            '--evapp-kiosk-panel-bg'       => $palette['panel_bg'],
            '--evapp-kiosk-panel-border'   => $palette['panel_border'],
            '--evapp-kiosk-panel-shadow'   => $palette['panel_shadow'],
            '--evapp-kiosk-title'          => $palette['title'],
            '--evapp-kiosk-text'           => $palette['text'],
            '--evapp-kiosk-muted'          => $palette['muted'],
            '--evapp-kiosk-label'          => $palette['label'],
            '--evapp-kiosk-input-bg'       => $palette['input_bg'],
            '--evapp-kiosk-input-text'     => $palette['input_text'],
            '--evapp-kiosk-input-border'   => $palette['input_border'],
            '--evapp-kiosk-button-bg'      => $palette['button_bg'],
            '--evapp-kiosk-button-text'    => $palette['button_text'],
            '--evapp-kiosk-button-hover'   => $palette['button_hover'],
            '--evapp-kiosk-button-active'  => $palette['button_active'],
            '--evapp-kiosk-secondary-bg'   => $palette['secondary_bg'],
            '--evapp-kiosk-secondary-text' => $palette['secondary_text'],
        ];

        $var_declarations = [];
        foreach ( $vars as $name => $value ) {
            $var_declarations[] = $name . ':' . esc_html( $value );
        }

        $css  = "\n/* EventosApp Kiosko Autogestion - Evento {$event_id} */\n";
        $css .= $background_selector . '{' . implode( ';', array_merge( $var_declarations, $bg_declarations ) ) . ';}\n';
        $css .= $background_selector . ' .eventosapp-kiosk-panel,' . $background_selector . ' .eventosapp-self-checkin-panel,' . $background_selector . ' .evapp-self-checkin-panel,' . $background_selector . ' .eventosapp-checkin-panel{background:var(--evapp-kiosk-panel-bg);border:1px solid var(--evapp-kiosk-panel-border);box-shadow:var(--evapp-kiosk-panel-shadow);color:var(--evapp-kiosk-text);}\n';
        $css .= $background_selector . ' .eventosapp-self-checkin,' . $background_selector . ' .eventosapp-self-checkin-widget,' . $background_selector . ' .eventosapp-kiosk-wrap,' . $background_selector . ' .evapp-self-checkin-wrap{color:var(--evapp-kiosk-text);}\n';
        $css .= $background_selector . ' h1,' . $background_selector . ' h2,' . $background_selector . ' h3,' . $background_selector . ' .eventosapp-kiosk-title,' . $background_selector . ' .evapp-kiosk-title{color:var(--evapp-kiosk-title);}\n';
        $css .= $background_selector . ' p,' . $background_selector . ' .eventosapp-kiosk-muted,' . $background_selector . ' .evapp-kiosk-muted,' . $background_selector . ' .eventosapp-self-checkin-subtitle{color:var(--evapp-kiosk-muted);}\n';
        $css .= $background_selector . ' label,' . $background_selector . ' .eventosapp-kiosk-label,' . $background_selector . ' .evapp-kiosk-label{color:var(--evapp-kiosk-label);}\n';
        $css .= $background_selector . ' input:not([type="checkbox"]):not([type="radio"]):not([type="button"]):not([type="submit"]),' . $background_selector . ' textarea,' . $background_selector . ' select,' . $background_selector . ' .eventosapp-kiosk-input,' . $background_selector . ' .evapp-kiosk-input{background:var(--evapp-kiosk-input-bg)!important;color:var(--evapp-kiosk-input-text)!important;border-color:var(--evapp-kiosk-input-border)!important;}\n';
        $css .= $background_selector . ' button,' . $background_selector . ' .button,' . $background_selector . ' input[type="button"],' . $background_selector . ' input[type="submit"],' . $background_selector . ' .eventosapp-kiosk-button,' . $background_selector . ' .evapp-kiosk-button{background:var(--evapp-kiosk-button-bg);color:var(--evapp-kiosk-button-text);}\n';
        $css .= $background_selector . ' button:hover,' . $background_selector . ' .button:hover,' . $background_selector . ' input[type="button"]:hover,' . $background_selector . ' input[type="submit"]:hover,' . $background_selector . ' .eventosapp-kiosk-button:hover,' . $background_selector . ' .evapp-kiosk-button:hover{background:var(--evapp-kiosk-button-hover);color:var(--evapp-kiosk-button-text);}\n';
        $css .= $background_selector . ' button:active,' . $background_selector . ' .button:active,' . $background_selector . ' input[type="button"]:active,' . $background_selector . ' input[type="submit"]:active,' . $background_selector . ' .eventosapp-kiosk-button:active,' . $background_selector . ' .evapp-kiosk-button:active{background:var(--evapp-kiosk-button-active);}\n';

        if ( ! empty( $settings['main_logo_url'] ) ) {
            $css .= $background_selector . ' .eventosapp-kiosk-main-logo{width:min(100%,' . absint( $settings['main_logo_max_width'] ) . 'px);height:' . absint( $settings['main_logo_max_height'] ) . 'px;background-image:url("' . esc_url( $settings['main_logo_url'] ) . '");background-size:contain;background-position:center;background-repeat:no-repeat;margin-left:auto;margin-right:auto;}\n';
            $css .= $background_selector . ' .eventosapp-kiosk-main-logo img{max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain;}\n';
        }

        $css .= $background_selector . ' .eventosapp-kiosk-additional-logos{width:100%;max-width:' . absint( $settings['extra_logos_wrap_width'] ) . 'px;min-height:' . absint( $settings['extra_logos_wrap_height'] ) . 'px;margin-left:auto;margin-right:auto;display:flex;align-items:center;justify-content:center;gap:' . absint( $settings['extra_logo_gap'] ) . 'px;flex-wrap:wrap;text-align:center;}\n';
        $css .= $background_selector . ' .eventosapp-kiosk-additional-logos img{display:block;width:auto;height:auto;max-width:100%;max-height:' . absint( $settings['extra_logo_max_height'] ) . 'px;object-fit:contain;flex:0 1 auto;}\n';
        $css .= '@media (max-width:767px){' . $background_selector . ' .eventosapp-kiosk-additional-logos{gap:' . max( 8, absint( $settings['extra_logo_gap'] / 2 ) ) . 'px;min-height:auto;}' . $background_selector . ' .eventosapp-kiosk-additional-logos img{max-height:' . max( 24, absint( $settings['extra_logo_max_height'] * 0.72 ) ) . 'px;}}' . "\n";

        return $css;
    }
}

if ( ! function_exists( 'eventosapp_kiosk_print_inline_css' ) ) {
    function eventosapp_kiosk_print_inline_css( $event_id ) {
        static $printed = [];

        $event_id = absint( $event_id );
        if ( ! $event_id || isset( $printed[ $event_id ] ) ) {
            return;
        }

        $css = eventosapp_kiosk_build_frontend_css( $event_id );
        if ( ! $css ) {
            return;
        }

        $printed[ $event_id ] = true;
        echo '<style id="eventosapp-kiosk-css-' . esc_attr( $event_id ) . '">' . wp_strip_all_tags( $css ) . '</style>' . "\n";
    }
}

add_action( 'wp_head', function() {
    $event_id = eventosapp_kiosk_resolve_event_id();
    if ( $event_id ) {
        eventosapp_kiosk_print_inline_css( $event_id );
    }
}, 45 );

add_action( 'admin_enqueue_scripts', function() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'eventosapp_event' ) {
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_media();
    }
} );

add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_kiosk_customization',
        'Kiosko Autogestión - Personalización visual',
        'eventosapp_render_kiosk_customization_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
} );

if ( ! function_exists( 'eventosapp_render_kiosk_customization_metabox' ) ) {
    function eventosapp_render_kiosk_customization_metabox( $post ) {
        $settings = eventosapp_kiosk_get_settings( $post->ID );
        $defaults = eventosapp_kiosk_default_settings();

        $bg_size_options = [
            'cover'   => 'Cubrir todo el contenedor (cover)',
            'contain' => 'Mostrar imagen completa (contain)',
            'auto'    => 'Tamaño original (auto)',
        ];
        $bg_position_options = [
            'center-center' => 'Centro / Centro',
            'center-top'    => 'Centro / Arriba',
            'center-bottom' => 'Centro / Abajo',
            'left-top'      => 'Izquierda / Arriba',
            'left-center'   => 'Izquierda / Centro',
            'left-bottom'   => 'Izquierda / Abajo',
            'right-top'     => 'Derecha / Arriba',
            'right-center'  => 'Derecha / Centro',
            'right-bottom'  => 'Derecha / Abajo',
        ];
        $bg_repeat_options = [
            'no-repeat' => 'No repetir',
            'repeat'    => 'Repetir en mosaico',
            'repeat-x'  => 'Repetir horizontal',
            'repeat-y'  => 'Repetir vertical',
        ];
        $bg_attachment_options = [
            'scroll' => 'Normal',
            'fixed'  => 'Fijo en pantalla',
        ];

        wp_nonce_field( 'eventosapp_kiosk_customization_save', 'eventosapp_kiosk_customization_nonce' );
        ?>
        <style>
            .evapp-kiosk-admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:10px;}
            .evapp-kiosk-admin-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04);}
            .evapp-kiosk-admin-card h3{margin:0 0 12px;font-size:15px;}
            .evapp-kiosk-admin-card .description{display:block;margin-top:5px;color:#646970;}
            .evapp-kiosk-field{margin-bottom:14px;}
            .evapp-kiosk-field label{display:block;font-weight:600;margin-bottom:6px;}
            .evapp-kiosk-field input[type="text"],.evapp-kiosk-field input[type="url"],.evapp-kiosk-field input[type="number"],.evapp-kiosk-field select{width:100%;max-width:100%;}
            .evapp-kiosk-media-line{display:flex;gap:8px;align-items:center;}
            .evapp-kiosk-media-line input[type="url"],.evapp-kiosk-media-line input[type="text"]{flex:1;}
            .evapp-kiosk-preview{margin-top:10px;min-height:46px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
            .evapp-kiosk-preview img{max-width:180px;max-height:80px;width:auto;height:auto;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7;padding:6px;}
            .evapp-kiosk-extra-logo-row{display:grid;grid-template-columns:80px minmax(0,1fr) auto auto;gap:8px;align-items:center;margin-bottom:8px;padding:8px;border:1px solid #e2e4e7;border-radius:10px;background:#f6f7f7;}
            .evapp-kiosk-extra-logo-row img{max-width:72px;max-height:44px;width:auto;height:auto;object-fit:contain;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:4px;}
            .evapp-kiosk-extra-logo-row .evapp-kiosk-extra-placeholder{width:72px;height:44px;display:flex;align-items:center;justify-content:center;background:#fff;border:1px dashed #b5b5b5;border-radius:6px;color:#777;font-size:11px;}
            .evapp-kiosk-admin-note{padding:10px 12px;border-left:4px solid #2271b1;background:#f0f6fc;margin:12px 0;border-radius:4px;}
            @media (max-width:1100px){.evapp-kiosk-admin-grid{grid-template-columns:1fr;}.evapp-kiosk-extra-logo-row{grid-template-columns:70px minmax(0,1fr);}.evapp-kiosk-extra-logo-row .button{width:100%;}}
        </style>

        <div class="evapp-kiosk-admin-note">
            <strong>Clase para el fondo en Elementor:</strong> agrega la clase
            <code><?php echo esc_html( $settings['bg_class'] ); ?></code>
            en el contenedor principal del diseño del kiosko. La clase se configura abajo y recibirá automáticamente el fondo y los colores del evento.
        </div>

        <div class="evapp-kiosk-admin-grid">
            <div class="evapp-kiosk-admin-card">
                <h3>1. Tema del panel</h3>
                <div class="evapp-kiosk-field">
                    <label for="eventosapp_kiosk_theme">Tema visual preestablecido</label>
                    <select id="eventosapp_kiosk_theme" name="eventosapp_kiosk_theme">
                        <option value="dark" <?php selected( $settings['theme'], 'dark' ); ?>>Modo oscuro</option>
                        <option value="light" <?php selected( $settings['theme'], 'light' ); ?>>Modo claro</option>
                    </select>
                    <span class="description">El modo oscuro usa paneles, textos y botones preparados para fondos oscuros. El modo claro está preparado para fondos blancos o muy claros.</span>
                </div>
            </div>

            <div class="evapp-kiosk-admin-card">
                <h3>2. Contenedor de fondo</h3>
                <div class="evapp-kiosk-field">
                    <label for="eventosapp_kiosk_bg_class">Clase CSS del contenedor en Elementor</label>
                    <input type="text" id="eventosapp_kiosk_bg_class" name="eventosapp_kiosk_bg_class" value="<?php echo esc_attr( $settings['bg_class'] ); ?>" placeholder="<?php echo esc_attr( $defaults['bg_class'] ); ?>">
                    <span class="description">Escribe una sola clase, sin punto. Ejemplo: <code>eventosapp-kiosk-bg</code>.</span>
                </div>
            </div>

            <div class="evapp-kiosk-admin-card">
                <h3>3. Fondo del módulo</h3>
                <div class="evapp-kiosk-field">
                    <label for="eventosapp_kiosk_bg_type">Tipo de fondo</label>
                    <select id="eventosapp_kiosk_bg_type" name="eventosapp_kiosk_bg_type">
                        <option value="default" <?php selected( $settings['bg_type'], 'default' ); ?>>Color por defecto del tema</option>
                        <option value="color" <?php selected( $settings['bg_type'], 'color' ); ?>>Color personalizado</option>
                        <option value="image" <?php selected( $settings['bg_type'], 'image' ); ?>>Imagen</option>
                    </select>
                </div>

                <div class="evapp-kiosk-field evapp-kiosk-bg-color-field">
                    <label for="eventosapp_kiosk_bg_color">Color de fondo</label>
                    <input type="text" id="eventosapp_kiosk_bg_color" class="evapp-kiosk-color" name="eventosapp_kiosk_bg_color" value="<?php echo esc_attr( $settings['bg_color'] ?: $settings['bg_color_effective'] ); ?>" data-default-color="<?php echo esc_attr( $settings['bg_color_effective'] ); ?>">
                    <span class="description">Si lo dejas vacío, se usará el color base del tema seleccionado.</span>
                </div>

                <div class="evapp-kiosk-field evapp-kiosk-bg-image-field">
                    <label>Imagen de fondo</label>
                    <input type="hidden" id="eventosapp_kiosk_bg_image_id" name="eventosapp_kiosk_bg_image_id" value="<?php echo esc_attr( $settings['bg_image_id'] ); ?>">
                    <div class="evapp-kiosk-media-line">
                        <input type="url" id="eventosapp_kiosk_bg_image_url" name="eventosapp_kiosk_bg_image_url" value="<?php echo esc_url( $settings['bg_image_url'] ); ?>" placeholder="https://.../fondo.jpg">
                        <button type="button" class="button evapp-kiosk-media-upload" data-target-id="eventosapp_kiosk_bg_image_id" data-target-url="eventosapp_kiosk_bg_image_url" data-preview="eventosapp_kiosk_bg_preview">Subir</button>
                        <button type="button" class="button evapp-kiosk-media-clear" data-target-id="eventosapp_kiosk_bg_image_id" data-target-url="eventosapp_kiosk_bg_image_url" data-preview="eventosapp_kiosk_bg_preview">Quitar</button>
                    </div>
                    <div id="eventosapp_kiosk_bg_preview" class="evapp-kiosk-preview">
                        <?php if ( $settings['bg_image_url'] ) : ?>
                            <img src="<?php echo esc_url( $settings['bg_image_url'] ); ?>" alt="Vista previa fondo">
                        <?php endif; ?>
                    </div>
                </div>

                <div class="evapp-kiosk-field evapp-kiosk-bg-image-field">
                    <label for="eventosapp_kiosk_bg_size">Tamaño de imagen</label>
                    <select id="eventosapp_kiosk_bg_size" name="eventosapp_kiosk_bg_size">
                        <?php foreach ( $bg_size_options as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['bg_size'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="evapp-kiosk-field evapp-kiosk-bg-image-field">
                    <label for="eventosapp_kiosk_bg_position">Posición de imagen</label>
                    <select id="eventosapp_kiosk_bg_position" name="eventosapp_kiosk_bg_position">
                        <?php foreach ( $bg_position_options as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['bg_position'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="evapp-kiosk-field evapp-kiosk-bg-image-field">
                    <label for="eventosapp_kiosk_bg_repeat">Repetición</label>
                    <select id="eventosapp_kiosk_bg_repeat" name="eventosapp_kiosk_bg_repeat">
                        <?php foreach ( $bg_repeat_options as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['bg_repeat'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="evapp-kiosk-field evapp-kiosk-bg-image-field">
                    <label for="eventosapp_kiosk_bg_attachment">Comportamiento del fondo</label>
                    <select id="eventosapp_kiosk_bg_attachment" name="eventosapp_kiosk_bg_attachment">
                        <?php foreach ( $bg_attachment_options as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['bg_attachment'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="evapp-kiosk-admin-card">
                <h3>4. Logo principal</h3>
                <div class="evapp-kiosk-field">
                    <label>Imagen del logo principal</label>
                    <input type="hidden" id="eventosapp_kiosk_main_logo_id" name="eventosapp_kiosk_main_logo_id" value="<?php echo esc_attr( $settings['main_logo_id'] ); ?>">
                    <div class="evapp-kiosk-media-line">
                        <input type="url" id="eventosapp_kiosk_main_logo_url" name="eventosapp_kiosk_main_logo_url" value="<?php echo esc_url( $settings['main_logo_url'] ); ?>" placeholder="https://.../logo.png">
                        <button type="button" class="button evapp-kiosk-media-upload" data-target-id="eventosapp_kiosk_main_logo_id" data-target-url="eventosapp_kiosk_main_logo_url" data-preview="eventosapp_kiosk_main_logo_preview">Subir</button>
                        <button type="button" class="button evapp-kiosk-media-clear" data-target-id="eventosapp_kiosk_main_logo_id" data-target-url="eventosapp_kiosk_main_logo_url" data-preview="eventosapp_kiosk_main_logo_preview">Quitar</button>
                    </div>
                    <div id="eventosapp_kiosk_main_logo_preview" class="evapp-kiosk-preview">
                        <?php if ( $settings['main_logo_url'] ) : ?>
                            <img src="<?php echo esc_url( $settings['main_logo_url'] ); ?>" alt="Logo principal">
                        <?php endif; ?>
                    </div>
                    <span class="description">Para imprimir este logo desde Elementor puedes crear un contenedor con la clase <code>eventosapp-kiosk-main-logo</code> dentro del contenedor de fondo del kiosko.</span>
                </div>

                <div class="evapp-kiosk-field">
                    <label for="eventosapp_kiosk_main_logo_max_width">Ancho máximo del logo principal (px)</label>
                    <input type="number" min="40" max="1400" id="eventosapp_kiosk_main_logo_max_width" name="eventosapp_kiosk_main_logo_max_width" value="<?php echo esc_attr( $settings['main_logo_max_width'] ); ?>">
                </div>

                <div class="evapp-kiosk-field">
                    <label for="eventosapp_kiosk_main_logo_max_height">Alto del área del logo principal (px)</label>
                    <input type="number" min="30" max="800" id="eventosapp_kiosk_main_logo_max_height" name="eventosapp_kiosk_main_logo_max_height" value="<?php echo esc_attr( $settings['main_logo_max_height'] ); ?>">
                </div>
            </div>

            <div class="evapp-kiosk-admin-card" style="grid-column:1/-1;">
                <h3>5. Logos adicionales</h3>
                <div class="evapp-kiosk-admin-note">
                    Estos logos se mostrarán centrados y se acomodarán automáticamente con flex-wrap dentro del widget Elementor <strong>EventosApp - Logos adicionales Kiosko</strong>.
                </div>

                <div class="evapp-kiosk-admin-grid">
                    <div class="evapp-kiosk-field">
                        <label for="eventosapp_kiosk_extra_logos_wrap_width">Ancho máximo del contenedor de logos (px)</label>
                        <input type="number" min="120" max="2200" id="eventosapp_kiosk_extra_logos_wrap_width" name="eventosapp_kiosk_extra_logos_wrap_width" value="<?php echo esc_attr( $settings['extra_logos_wrap_width'] ); ?>">
                    </div>
                    <div class="evapp-kiosk-field">
                        <label for="eventosapp_kiosk_extra_logos_wrap_height">Alto mínimo del contenedor de logos (px)</label>
                        <input type="number" min="40" max="1000" id="eventosapp_kiosk_extra_logos_wrap_height" name="eventosapp_kiosk_extra_logos_wrap_height" value="<?php echo esc_attr( $settings['extra_logos_wrap_height'] ); ?>">
                    </div>
                    <div class="evapp-kiosk-field">
                        <label for="eventosapp_kiosk_extra_logo_max_height">Alto máximo de cada logo (px)</label>
                        <input type="number" min="20" max="500" id="eventosapp_kiosk_extra_logo_max_height" name="eventosapp_kiosk_extra_logo_max_height" value="<?php echo esc_attr( $settings['extra_logo_max_height'] ); ?>">
                    </div>
                    <div class="evapp-kiosk-field">
                        <label for="eventosapp_kiosk_extra_logo_gap">Espacio entre logos (px)</label>
                        <input type="number" min="0" max="180" id="eventosapp_kiosk_extra_logo_gap" name="eventosapp_kiosk_extra_logo_gap" value="<?php echo esc_attr( $settings['extra_logo_gap'] ); ?>">
                    </div>
                </div>

                <div id="eventosapp_kiosk_extra_logos_list">
                    <?php if ( ! empty( $settings['extra_logos'] ) ) : ?>
                        <?php foreach ( $settings['extra_logos'] as $logo ) : ?>
                            <div class="evapp-kiosk-extra-logo-row">
                                <span class="evapp-kiosk-extra-preview"><?php if ( ! empty( $logo['url'] ) ) : ?><img src="<?php echo esc_url( $logo['url'] ); ?>" alt="Logo adicional"><?php else : ?><span class="evapp-kiosk-extra-placeholder">Logo</span><?php endif; ?></span>
                                <input type="hidden" name="eventosapp_kiosk_extra_logo_id[]" value="<?php echo esc_attr( $logo['id'] ?? 0 ); ?>">
                                <input type="url" name="eventosapp_kiosk_extra_logo_url[]" value="<?php echo esc_url( $logo['url'] ?? '' ); ?>" placeholder="https://.../logo.png">
                                <button type="button" class="button evapp-kiosk-extra-upload">Subir</button>
                                <button type="button" class="button evapp-kiosk-extra-remove">Eliminar</button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <button type="button" class="button button-primary" id="eventosapp_kiosk_add_extra_logo">Agregar logo adicional</button>
            </div>
        </div>

        <script type="text/html" id="eventosapp-kiosk-extra-logo-template">
            <div class="evapp-kiosk-extra-logo-row">
                <span class="evapp-kiosk-extra-preview"><span class="evapp-kiosk-extra-placeholder">Logo</span></span>
                <input type="hidden" name="eventosapp_kiosk_extra_logo_id[]" value="0">
                <input type="url" name="eventosapp_kiosk_extra_logo_url[]" value="" placeholder="https://.../logo.png">
                <button type="button" class="button evapp-kiosk-extra-upload">Subir</button>
                <button type="button" class="button evapp-kiosk-extra-remove">Eliminar</button>
            </div>
        </script>

        <script>
        (function($){
            function evappKioskToggleBgFields(){
                var type = $('#eventosapp_kiosk_bg_type').val() || 'default';
                $('.evapp-kiosk-bg-color-field').toggle(type === 'color');
                $('.evapp-kiosk-bg-image-field').toggle(type === 'image');
            }

            function evappKioskSetPreview(previewId, url){
                var $preview = $('#' + previewId);
                if (!$preview.length) return;
                if (url) {
                    $preview.html('<img src="' + $('<div/>').text(url).html() + '" alt="Vista previa">');
                } else {
                    $preview.empty();
                }
            }

            function evappKioskOpenMedia(callback){
                if (typeof wp === 'undefined' || !wp.media) return;
                var frame = wp.media({
                    title: 'Seleccionar imagen',
                    button: { text: 'Usar esta imagen' },
                    multiple: false,
                    library: { type: 'image' }
                });
                frame.on('select', function(){
                    var attachment = frame.state().get('selection').first().toJSON();
                    callback(attachment);
                });
                frame.open();
            }

            $(function(){
                if ($.fn.wpColorPicker) {
                    $('.evapp-kiosk-color').wpColorPicker();
                }

                $('#eventosapp_kiosk_bg_type').on('change', evappKioskToggleBgFields);
                evappKioskToggleBgFields();

                $(document).on('click', '.evapp-kiosk-media-upload', function(e){
                    e.preventDefault();
                    var $button = $(this);
                    evappKioskOpenMedia(function(attachment){
                        $('#' + $button.data('target-id')).val(attachment.id || 0);
                        $('#' + $button.data('target-url')).val(attachment.url || '');
                        evappKioskSetPreview($button.data('preview'), attachment.url || '');
                    });
                });

                $(document).on('click', '.evapp-kiosk-media-clear', function(e){
                    e.preventDefault();
                    var $button = $(this);
                    $('#' + $button.data('target-id')).val('0');
                    $('#' + $button.data('target-url')).val('');
                    evappKioskSetPreview($button.data('preview'), '');
                });

                $('#eventosapp_kiosk_add_extra_logo').on('click', function(e){
                    e.preventDefault();
                    $('#eventosapp_kiosk_extra_logos_list').append($('#eventosapp-kiosk-extra-logo-template').html());
                });

                $(document).on('click', '.evapp-kiosk-extra-remove', function(e){
                    e.preventDefault();
                    $(this).closest('.evapp-kiosk-extra-logo-row').remove();
                });

                $(document).on('click', '.evapp-kiosk-extra-upload', function(e){
                    e.preventDefault();
                    var $row = $(this).closest('.evapp-kiosk-extra-logo-row');
                    evappKioskOpenMedia(function(attachment){
                        $row.find('input[type="hidden"]').val(attachment.id || 0);
                        $row.find('input[type="url"]').val(attachment.url || '');
                        $row.find('.evapp-kiosk-extra-preview').html('<img src="' + $('<div/>').text(attachment.url || '').html() + '" alt="Logo adicional">');
                    });
                });
            });
        })(jQuery);
        </script>
        <?php
    }
}

add_action( 'save_post_eventosapp_event', function( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    if ( ! isset( $_POST['eventosapp_kiosk_customization_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eventosapp_kiosk_customization_nonce'] ) ), 'eventosapp_kiosk_customization_save' ) ) {
        return;
    }

    $defaults = eventosapp_kiosk_default_settings();

    $theme = eventosapp_kiosk_validate_choice( $_POST['eventosapp_kiosk_theme'] ?? $defaults['theme'], [ 'light', 'dark' ], $defaults['theme'] );
    update_post_meta( $post_id, '_eventosapp_kiosk_theme', $theme );

    $bg_type = eventosapp_kiosk_validate_choice( $_POST['eventosapp_kiosk_bg_type'] ?? $defaults['bg_type'], [ 'default', 'color', 'image' ], $defaults['bg_type'] );
    update_post_meta( $post_id, '_eventosapp_kiosk_bg_type', $bg_type );

    $bg_color = sanitize_hex_color( wp_unslash( $_POST['eventosapp_kiosk_bg_color'] ?? '' ) );
    if ( $bg_color ) {
        update_post_meta( $post_id, '_eventosapp_kiosk_bg_color', $bg_color );
    } else {
        delete_post_meta( $post_id, '_eventosapp_kiosk_bg_color' );
    }

    update_post_meta( $post_id, '_eventosapp_kiosk_bg_image_id', absint( $_POST['eventosapp_kiosk_bg_image_id'] ?? 0 ) );
    update_post_meta( $post_id, '_eventosapp_kiosk_bg_image_url', esc_url_raw( wp_unslash( $_POST['eventosapp_kiosk_bg_image_url'] ?? '' ) ) );
    update_post_meta( $post_id, '_eventosapp_kiosk_bg_size', eventosapp_kiosk_validate_choice( $_POST['eventosapp_kiosk_bg_size'] ?? $defaults['bg_size'], [ 'cover', 'contain', 'auto' ], $defaults['bg_size'] ) );
    update_post_meta( $post_id, '_eventosapp_kiosk_bg_position', eventosapp_kiosk_validate_choice( $_POST['eventosapp_kiosk_bg_position'] ?? $defaults['bg_position'], [ 'center-center', 'center-top', 'center-bottom', 'left-top', 'left-center', 'left-bottom', 'right-top', 'right-center', 'right-bottom' ], $defaults['bg_position'] ) );
    update_post_meta( $post_id, '_eventosapp_kiosk_bg_repeat', eventosapp_kiosk_validate_choice( $_POST['eventosapp_kiosk_bg_repeat'] ?? $defaults['bg_repeat'], [ 'no-repeat', 'repeat', 'repeat-x', 'repeat-y' ], $defaults['bg_repeat'] ) );
    update_post_meta( $post_id, '_eventosapp_kiosk_bg_attachment', eventosapp_kiosk_validate_choice( $_POST['eventosapp_kiosk_bg_attachment'] ?? $defaults['bg_attachment'], [ 'scroll', 'fixed' ], $defaults['bg_attachment'] ) );
    update_post_meta( $post_id, '_eventosapp_kiosk_bg_class', eventosapp_kiosk_sanitize_css_class( $_POST['eventosapp_kiosk_bg_class'] ?? $defaults['bg_class'] ) );

    update_post_meta( $post_id, '_eventosapp_kiosk_main_logo_id', absint( $_POST['eventosapp_kiosk_main_logo_id'] ?? 0 ) );
    update_post_meta( $post_id, '_eventosapp_kiosk_main_logo_url', esc_url_raw( wp_unslash( $_POST['eventosapp_kiosk_main_logo_url'] ?? '' ) ) );
    update_post_meta( $post_id, '_eventosapp_kiosk_main_logo_max_width', eventosapp_kiosk_sanitize_positive_int( $_POST['eventosapp_kiosk_main_logo_max_width'] ?? $defaults['main_logo_max_width'], $defaults['main_logo_max_width'], 40, 1400 ) );
    update_post_meta( $post_id, '_eventosapp_kiosk_main_logo_max_height', eventosapp_kiosk_sanitize_positive_int( $_POST['eventosapp_kiosk_main_logo_max_height'] ?? $defaults['main_logo_max_height'], $defaults['main_logo_max_height'], 30, 800 ) );

    update_post_meta( $post_id, '_eventosapp_kiosk_extra_logos_wrap_width', eventosapp_kiosk_sanitize_positive_int( $_POST['eventosapp_kiosk_extra_logos_wrap_width'] ?? $defaults['extra_logos_wrap_width'], $defaults['extra_logos_wrap_width'], 120, 2200 ) );
    update_post_meta( $post_id, '_eventosapp_kiosk_extra_logos_wrap_height', eventosapp_kiosk_sanitize_positive_int( $_POST['eventosapp_kiosk_extra_logos_wrap_height'] ?? $defaults['extra_logos_wrap_height'], $defaults['extra_logos_wrap_height'], 40, 1000 ) );
    update_post_meta( $post_id, '_eventosapp_kiosk_extra_logo_max_height', eventosapp_kiosk_sanitize_positive_int( $_POST['eventosapp_kiosk_extra_logo_max_height'] ?? $defaults['extra_logo_max_height'], $defaults['extra_logo_max_height'], 20, 500 ) );
    update_post_meta( $post_id, '_eventosapp_kiosk_extra_logo_gap', eventosapp_kiosk_sanitize_positive_int( $_POST['eventosapp_kiosk_extra_logo_gap'] ?? $defaults['extra_logo_gap'], $defaults['extra_logo_gap'], 0, 180 ) );

    $logo_ids  = isset( $_POST['eventosapp_kiosk_extra_logo_id'] ) && is_array( $_POST['eventosapp_kiosk_extra_logo_id'] ) ? wp_unslash( $_POST['eventosapp_kiosk_extra_logo_id'] ) : [];
    $logo_urls = isset( $_POST['eventosapp_kiosk_extra_logo_url'] ) && is_array( $_POST['eventosapp_kiosk_extra_logo_url'] ) ? wp_unslash( $_POST['eventosapp_kiosk_extra_logo_url'] ) : [];
    $extra_logos = [];

    foreach ( $logo_urls as $index => $url ) {
        $url = esc_url_raw( $url );
        $id = absint( $logo_ids[ $index ] ?? 0 );
        if ( ! $url && $id ) {
            $url = wp_get_attachment_image_url( $id, 'full' );
            $url = $url ? esc_url_raw( $url ) : '';
        }
        if ( $url ) {
            $extra_logos[] = [
                'id'  => $id,
                'url' => $url,
            ];
        }
    }

    update_post_meta( $post_id, '_eventosapp_kiosk_extra_logos', $extra_logos );
}, 25 );

if ( ! function_exists( 'eventosapp_kiosk_render_additional_logos' ) ) {
    function eventosapp_kiosk_render_additional_logos( $event_id = 0, $echo = true ) {
        $event_id = eventosapp_kiosk_resolve_event_id( $event_id );
        if ( ! $event_id ) {
            return '';
        }

        $settings = eventosapp_kiosk_get_settings( $event_id );
        eventosapp_kiosk_print_inline_css( $event_id );

        if ( empty( $settings['extra_logos'] ) ) {
            return '';
        }

        $html = '<div class="eventosapp-kiosk-additional-logos-wrap evapp-kiosk-theme-event-' . esc_attr( $event_id ) . '">';
        $html .= '<div class="eventosapp-kiosk-additional-logos">';
        foreach ( $settings['extra_logos'] as $index => $logo ) {
            $html .= '<img src="' . esc_url( $logo['url'] ) . '" alt="Logo adicional ' . esc_attr( $index + 1 ) . '" loading="lazy" decoding="async">';
        }
        $html .= '</div></div>';

        if ( $echo ) {
            echo $html;
        }

        return $html;
    }
}

add_shortcode( 'eventosapp_kiosk_logos', function( $atts ) {
    $atts = shortcode_atts(
        [
            'event_id' => 0,
        ],
        $atts,
        'eventosapp_kiosk_logos'
    );

    return eventosapp_kiosk_render_additional_logos( absint( $atts['event_id'] ), false );
} );

if ( ! function_exists( 'eventosapp_kiosk_elementor_event_options' ) ) {
    function eventosapp_kiosk_elementor_event_options() {
        $options = [ '' => 'Evento actual / dinámico' ];
        $events = get_posts( [
            'post_type'      => 'eventosapp_event',
            'post_status'    => [ 'publish', 'draft', 'private', 'future' ],
            'posts_per_page' => 250,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ] );

        foreach ( $events as $event_id ) {
            $options[ (string) $event_id ] = get_the_title( $event_id ) ?: sprintf( 'Evento #%d', $event_id );
        }

        return $options;
    }
}

if ( ! function_exists( 'eventosapp_kiosk_register_elementor_widgets' ) ) {
    function eventosapp_kiosk_register_elementor_widgets( $widgets_manager ) {
        static $registered = false;

        if ( $registered || ! class_exists( '\\Elementor\\Widget_Base' ) ) {
            return;
        }

        if ( ! class_exists( 'EventosApp_Kiosk_Additional_Logos_Widget' ) ) {
            class EventosApp_Kiosk_Additional_Logos_Widget extends \Elementor\Widget_Base {
                public function get_name() {
                    return 'eventosapp_kiosk_additional_logos';
                }

                public function get_title() {
                    return 'EventosApp - Logos adicionales Kiosko';
                }

                public function get_icon() {
                    return 'eicon-gallery-grid';
                }

                public function get_categories() {
                    return [ 'general' ];
                }

                public function get_keywords() {
                    return [ 'eventosapp', 'kiosko', 'autogestion', 'logos', 'patrocinadores' ];
                }

                protected function register_controls() {
                    $this->start_controls_section(
                        'section_content',
                        [
                            'label' => 'Configuración',
                            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                        ]
                    );

                    $this->add_control(
                        'event_id',
                        [
                            'label'       => 'Evento',
                            'type'        => \Elementor\Controls_Manager::SELECT2,
                            'options'     => eventosapp_kiosk_elementor_event_options(),
                            'default'     => '',
                            'label_block' => true,
                            'description' => 'Si se deja vacío, intentará detectar el evento actual o el ID recibido por URL.',
                        ]
                    );

                    $this->add_control(
                        'empty_notice',
                        [
                            'type'            => \Elementor\Controls_Manager::RAW_HTML,
                            'raw'             => 'Los logos, tamaños y espaciados se administran desde el metabox del evento: Kiosko Autogestión - Personalización visual.',
                            'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
                        ]
                    );

                    $this->end_controls_section();
                }

                protected function render() {
                    $settings = $this->get_settings_for_display();
                    $event_id = eventosapp_kiosk_resolve_event_id( $settings['event_id'] ?? 0 );

                    if ( ! $event_id ) {
                        if ( current_user_can( 'edit_posts' ) ) {
                            echo '<div class="eventosapp-kiosk-additional-logos-wrap"><p>No se pudo detectar el evento. Selecciona el evento en las opciones del widget.</p></div>';
                        }
                        return;
                    }

                    $settings_event = eventosapp_kiosk_get_settings( $event_id );
                    if ( empty( $settings_event['extra_logos'] ) ) {
                        if ( current_user_can( 'edit_posts' ) ) {
                            eventosapp_kiosk_print_inline_css( $event_id );
                            echo '<div class="eventosapp-kiosk-additional-logos-wrap evapp-kiosk-theme-event-' . esc_attr( $event_id ) . '"><div class="eventosapp-kiosk-additional-logos"><p>No hay logos adicionales configurados para este evento.</p></div></div>';
                        }
                        return;
                    }

                    eventosapp_kiosk_render_additional_logos( $event_id, true );
                }
            }
        }

        if ( method_exists( $widgets_manager, 'register' ) ) {
            $widgets_manager->register( new \EventosApp_Kiosk_Additional_Logos_Widget() );
        } elseif ( method_exists( $widgets_manager, 'register_widget_type' ) ) {
            $widgets_manager->register_widget_type( new \EventosApp_Kiosk_Additional_Logos_Widget() );
        }

        $registered = true;
    }
}

add_action( 'elementor/widgets/register', 'eventosapp_kiosk_register_elementor_widgets' );
add_action( 'elementor/widgets/widgets_registered', 'eventosapp_kiosk_register_elementor_widgets' );
