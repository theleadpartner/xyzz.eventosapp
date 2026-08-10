<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Identidad corporativa central de EventosApp.
 *
 * Esta capa no modifica el layout ni la lógica de los módulos. Expone los
 * activos oficiales, unifica los tokens cromáticos y aplica la marca al final
 * del ciclo de render para que los estilos históricos adopten la identidad
 * corporativa sin reescribir cada módulo.
 */

if ( ! function_exists( 'eventosapp_brand_colors' ) ) {
    function eventosapp_brand_colors() {
        return [
            'dark'      => '#171e37',
            'blue'      => '#3683c5',
            'blue_dark' => '#286291',
        ];
    }
}

if ( ! function_exists( 'eventosapp_brand_asset_url' ) ) {
    function eventosapp_brand_asset_url( $variant = 'logo_color' ) {
        $assets = [
            'logo_color' => 'eventosapp_color.svg',
            'logo_white' => 'eventosapp_blanco.svg',
            'icon_color' => 'eventosapp_icon.svg',
            'icon_white' => 'eventosapp_icon_blanco.svg',
        ];

        $variant = sanitize_key( (string) $variant );
        if ( ! isset( $assets[ $variant ] ) ) {
            $variant = 'logo_color';
        }

        return EVENTOSAPP_PLUGIN_URL . 'assets/branding/' . $assets[ $variant ];
    }
}

if ( ! function_exists( 'eventosapp_branding_css_contents' ) ) {
    function eventosapp_branding_css_contents() {
        static $css = null;

        if ( $css !== null ) {
            return $css;
        }

        $file = EVENTOSAPP_PLUGIN_PATH . 'assets/css/eventosapp-branding.css';
        if ( ! is_readable( $file ) ) {
            $css = '';
            return $css;
        }

        $contents = file_get_contents( $file );
        if ( ! is_string( $contents ) ) {
            $css = '';
            return $css;
        }

        // La hoja se imprime inline al final del documento. Convertimos las
        // rutas relativas de los SVG a URLs absolutas del plugin para que la
        // resolución no dependa de la URL de la página actual.
        $branding_url = EVENTOSAPP_PLUGIN_URL . 'assets/branding/';
        $css = str_replace( '../branding/', $branding_url, $contents );
        return $css;
    }
}

if ( ! function_exists( 'eventosapp_branding_is_admin_screen' ) ) {
    function eventosapp_branding_is_admin_screen() {
        if ( ! is_admin() ) return false;

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( $page !== '' && strpos( $page, 'eventosapp' ) !== false ) {
            return true;
        }

        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
        if ( $post_type !== '' && strpos( $post_type, 'eventosapp' ) === 0 ) {
            return true;
        }

        if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( $screen ) {
                foreach ( [ $screen->id ?? '', $screen->base ?? '', $screen->post_type ?? '' ] as $screen_key ) {
                    if ( is_string( $screen_key ) && strpos( $screen_key, 'eventosapp' ) !== false ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}

if ( ! function_exists( 'eventosapp_branding_front_body_class' ) ) {
    function eventosapp_branding_front_body_class( $classes ) {
        $classes = is_array( $classes ) ? $classes : [];
        $classes[] = 'eventosapp-branding';
        return array_values( array_unique( $classes ) );
    }
}
add_filter( 'body_class', 'eventosapp_branding_front_body_class', 20 );

if ( ! function_exists( 'eventosapp_branding_admin_body_class' ) ) {
    function eventosapp_branding_admin_body_class( $classes ) {
        $classes = trim( (string) $classes );
        if ( strpos( ' ' . $classes . ' ', ' eventosapp-branding ' ) === false ) {
            $classes .= ( $classes === '' ? '' : ' ' ) . 'eventosapp-branding';
        }
        return $classes;
    }
}
add_filter( 'admin_body_class', 'eventosapp_branding_admin_body_class', 20 );

if ( ! function_exists( 'eventosapp_print_branding_styles' ) ) {
    function eventosapp_print_branding_styles() {
        static $printed = false;
        if ( $printed ) return;

        if ( is_admin() && ! eventosapp_branding_is_admin_screen() ) {
            return;
        }

        $css = eventosapp_branding_css_contents();
        if ( $css === '' ) return;

        $printed = true;
        echo "\n<style id=\"eventosapp-corporate-branding\">\n";
        echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS estático interno del plugin.
        echo "\n</style>\n";
    }
}

// Se imprime al final para sobreescribir solo los defaults visuales históricos.
// Los selectores de Elementor mantienen mayor especificidad y siguen operativos.
add_action( 'wp_footer', 'eventosapp_print_branding_styles', 999 );
add_action( 'admin_footer', 'eventosapp_print_branding_styles', 999 );
