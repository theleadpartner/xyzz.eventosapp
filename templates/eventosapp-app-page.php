<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Template frontend exclusivo para páginas mapeadas por EventosApp.
 *
 * Mantiene el ciclo normal de WordPress (wp_head, wp_body_open, the_content,
 * wp_footer) para conservar assets, Elementor, shortcodes y hooks, pero no llama
 * get_header() ni get_footer(). De esta manera la experiencia se presenta como
 * una aplicación y no hereda el chrome visual del tema activo.
 */

$eventosapp_branding_css = EVENTOSAPP_PLUGIN_URL . 'assets/css/eventosapp-branding.css';
$eventosapp_branding_file = EVENTOSAPP_PLUGIN_PATH . 'assets/css/eventosapp-branding.css';
$eventosapp_branding_ver = is_readable( $eventosapp_branding_file ) ? (string) filemtime( $eventosapp_branding_file ) : '1.5.0-rc.6';
$eventosapp_is_dashboard_page = function_exists( 'eventosapp_branding_is_dashboard_page' )
    && eventosapp_branding_is_dashboard_page();
?><!doctype html>
<html <?php language_attributes(); ?> data-evapp-app-document>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="stylesheet" id="eventosapp-branding-critical-css" href="<?php echo esc_url( add_query_arg( 'ver', rawurlencode( $eventosapp_branding_ver ), $eventosapp_branding_css ) ); ?>">
    <?php if ( $eventosapp_is_dashboard_page ) : ?>
        <style id="eventosapp-dashboard-boxed-layout">
            body.eventosapp-app-page #eventosapp-app-root.is-dashboard-page {
                padding: clamp(12px, 1.4vw, 20px) 16px clamp(24px, 2.4vw, 36px);
            }

            body.eventosapp-app-page #eventosapp-app-root.is-dashboard-page .evapp-dashboard {
                width: 100% !important;
                max-width: 1200px !important;
                margin-left: auto !important;
                margin-right: auto !important;
            }

            @media (max-width: 767px) {
                body.eventosapp-app-page #eventosapp-app-root.is-dashboard-page {
                    padding: 12px;
                }
            }
        </style>
    <?php endif; ?>
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<?php
if ( function_exists( 'eventosapp_branding_render_app_header' ) ) {
    eventosapp_branding_render_app_header();
}
?>

<main id="eventosapp-app-root" class="eventosapp-app-root<?php echo $eventosapp_is_dashboard_page ? ' is-dashboard-page' : ''; ?>" role="main">
    <?php
    while ( have_posts() ) {
        the_post();
        the_content();
    }
    ?>
</main>

<?php wp_footer(); ?>
</body>
</html>
