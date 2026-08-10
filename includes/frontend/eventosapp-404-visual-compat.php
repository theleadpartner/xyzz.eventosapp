<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Compatibilidad visual final para la página 404 de EventosApp.
 *
 * El shortcode [eventosapp_404] vive en el módulo de Seguridad y conserva CSS
 * inline histórico. Esta capa se imprime después del branding, Dark Mode y
 * aislamiento general de Elementor/tema para corregir únicamente presentación:
 * marca oficial, superficies, contraste, botones, ilustración y responsive.
 *
 * No modifica el estado HTTP 404, redirecciones, login, permisos, navegación,
 * seguridad, shortcodes ni la lógica del botón de regreso.
 */

if ( ! function_exists( 'eventosapp_404_visual_compat_is_active' ) ) {
    function eventosapp_404_visual_compat_is_active() {
        if ( is_admin() || ! is_singular( 'page' ) ) return false;

        $page_id  = absint( get_queried_object_id() );
        $security = get_option( 'eventosapp_security', [] );
        $security = is_array( $security ) ? $security : [];
        $page_404 = absint( $security['error_404_page_id'] ?? 0 );

        if ( $page_404 && $page_id === $page_404 ) {
            return true;
        }

        global $post;
        return $post instanceof WP_Post
            && has_shortcode( (string) $post->post_content, 'eventosapp_404' );
    }
}

if ( ! function_exists( 'eventosapp_404_visual_compat_print_styles' ) ) {
    function eventosapp_404_visual_compat_print_styles() {
        if ( ! eventosapp_404_visual_compat_is_active() ) return;

        $logo_color = function_exists( 'eventosapp_brand_asset_url' )
            ? eventosapp_brand_asset_url( 'logo_color' )
            : '';
        $logo_white = function_exists( 'eventosapp_brand_asset_url' )
            ? eventosapp_brand_asset_url( 'logo_white' )
            : '';
        ?>
<style id="eventosapp-404-visual-compat">
/* ==========================================================
 * EventosApp 1.5.0-rc.19 — Página 404
 * Marca + Dark Mode + aislamiento Elementor/tema
 * ========================================================== */

body.eventosapp-app-page #eventosapp-app-root .evapp-404 {
    --ev: var(--eventosapp-brand-blue) !important;
    --ev2: var(--eventosapp-brand-blue-dark) !important;
    --ink: var(--eventosapp-app-text) !important;
    --muted: var(--eventosapp-app-muted) !important;
    width: 100% !important;
    max-width: none !important;
    min-height: calc(100vh - 76px) !important;
    min-height: calc(100dvh - 76px) !important;
    margin: 0 !important;
    padding: clamp(36px, 6vw, 72px) 18px !important;
    border: 0 !important;
    border-radius: 0 !important;
    background:
        radial-gradient(circle at 14% 18%, rgba(54, 131, 197, .15), transparent 31%),
        radial-gradient(circle at 88% 76%, rgba(40, 98, 145, .12), transparent 30%),
        var(--eventosapp-app-surface-soft) !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: none !important;
    font-family: inherit !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-404 {
    background:
        radial-gradient(circle at 14% 18%, rgba(54, 131, 197, .18), transparent 31%),
        radial-gradient(circle at 88% 76%, rgba(40, 98, 145, .22), transparent 30%),
        var(--eventosapp-app-canvas) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-card {
    position: relative !important;
    z-index: 3 !important;
    width: min(880px, 100%) !important;
    margin: 0 auto !important;
    padding: clamp(30px, 4.5vw, 50px) clamp(22px, 5vw, 54px) !important;
    border: 1px solid var(--eventosapp-app-border) !important;
    border-radius: 28px !important;
    background: var(--eventosapp-app-surface) !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
    text-align: center !important;
}

/* La imagen configurada por WordPress se oculta para impedir variaciones de
 * marca. La firma visual usa siempre el wordmark oficial del plugin. */
body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-logo {
    display: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-card::before {
    content: "" !important;
    display: block !important;
    width: min(230px, 72%) !important;
    height: 50px !important;
    margin: 0 auto 22px !important;
    background-image: url("<?php echo esc_url( $logo_color ); ?>") !important;
    background-position: center !important;
    background-repeat: no-repeat !important;
    background-size: contain !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-card::before {
    background-image: url("<?php echo esc_url( $logo_white ); ?>") !important;
}

/* Ilustración: se conserva la metáfora de ticket/QR pero se blinda frente a
 * colores heredados del tema. El ticket se mantiene claro como objeto gráfico. */
body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-visual {
    width: min(420px, 86vw) !important;
    max-width: 100% !important;
    margin: 0 auto 20px !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-ticket {
    background: #f8fbff !important;
    border-color: #dce8f3 !important;
    color: #171e37 !important;
    box-shadow: 0 24px 60px rgba(23, 30, 55, .18) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-ticket-side {
    background: linear-gradient(160deg, var(--eventosapp-brand-blue), var(--eventosapp-brand-blue-dark)) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-404 :where(
    .evapp-404-ticket-body,
    .evapp-404-ticket-body b
) {
    color: #171e37 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-lines i {
    background: #e8f0f7 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-scan {
    background: linear-gradient(transparent, #38adcc, transparent) !important;
    filter: drop-shadow(0 0 8px rgba(56, 173, 204, .9)) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-badge {
    background: #fff !important;
    border-color: #dce8f3 !important;
    color: var(--eventosapp-brand-blue) !important;
    box-shadow: 0 12px 30px rgba(23, 30, 55, .16) !important;
}

/* Tipografía y estados. Elementor no puede reemplazar tamaños, colores,
 * márgenes ni transformaciones de texto de la experiencia 404. */
body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-code {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    margin: 0 0 13px !important;
    padding: 7px 12px !important;
    border: 1px solid rgba(54, 131, 197, .18) !important;
    border-radius: 999px !important;
    background: var(--eventosapp-brand-blue-soft) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
    font: inherit !important;
    font-size: 12px !important;
    font-weight: 900 !important;
    line-height: 1.2 !important;
    letter-spacing: .08em !important;
    text-transform: uppercase !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-code {
    background: rgba(54, 131, 197, .16) !important;
    border-color: rgba(123, 177, 223, .28) !important;
    color: #8fc0e8 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-404 h1#evapp-404-title {
    max-width: 760px !important;
    margin: 0 auto 14px !important;
    color: var(--eventosapp-app-text) !important;
    font: inherit !important;
    font-size: clamp(34px, 5.2vw, 58px) !important;
    font-weight: 780 !important;
    line-height: 1.04 !important;
    letter-spacing: -.035em !important;
    text-transform: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-404 p {
    max-width: 680px !important;
    margin: 0 auto !important;
    color: var(--eventosapp-app-muted) !important;
    font: inherit !important;
    font-size: clamp(15px, 2vw, 18px) !important;
    font-weight: 400 !important;
    line-height: 1.65 !important;
    letter-spacing: normal !important;
    text-transform: none !important;
}

/* Acciones. Se normaliza <a> para impedir hovers, subrayados y colores del
 * Elementor Kit o del tema global. */
body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-actions {
    display: flex !important;
    justify-content: center !important;
    align-items: stretch !important;
    flex-wrap: wrap !important;
    gap: 10px !important;
    margin: 28px 0 0 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-404 a.evapp-404-btn {
    appearance: none !important;
    min-height: 48px !important;
    margin: 0 !important;
    padding: 11px 18px !important;
    border: 1px solid var(--eventosapp-app-border) !important;
    border-radius: 12px !important;
    background: var(--eventosapp-app-surface-raised) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
    box-shadow: 0 8px 20px rgba(23, 30, 55, .07) !important;
    font: inherit !important;
    font-size: 15px !important;
    font-weight: 800 !important;
    line-height: 1.2 !important;
    letter-spacing: normal !important;
    text-align: center !important;
    text-decoration: none !important;
    text-transform: none !important;
    cursor: pointer !important;
    transition: background-color .16s ease, border-color .16s ease, color .16s ease, transform .16s ease !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-404 a.evapp-404-btn:hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-404 a.evapp-404-btn:focus-visible {
    background: var(--eventosapp-brand-blue-soft) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
    text-decoration: none !important;
    outline: 0 !important;
    transform: translateY(-1px) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-404 a.evapp-404-btn {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: #9fc8ea !important;
    box-shadow: none !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-404 a.evapp-404-btn:hover,
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-404 a.evapp-404-btn:focus-visible {
    background: rgba(54, 131, 197, .16) !important;
    border-color: #5f99ca !important;
    color: #c2ddf3 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-404 a.evapp-404-btn.is-primary,
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-404 a.evapp-404-btn.is-primary {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
    box-shadow: 0 10px 24px rgba(40, 98, 145, .20) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-404 a.evapp-404-btn.is-primary:hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-404 a.evapp-404-btn.is-primary:focus-visible,
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-404 a.evapp-404-btn.is-primary:hover,
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-404 a.evapp-404-btn.is-primary:focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-404 a.evapp-404-btn:focus-visible {
    box-shadow: 0 0 0 4px var(--eventosapp-brand-focus) !important;
}

/* Elementos decorativos siempre dentro de la paleta oficial. */
body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-orb.o1 {
    background: #38adcc !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-orb.o2 {
    background: var(--eventosapp-brand-blue) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-orb.o3 {
    background: var(--eventosapp-brand-blue-dark) !important;
}

@media (max-width: 760px) {
    body.eventosapp-app-page #eventosapp-app-root .evapp-404 {
        padding: 24px 14px !important;
    }

    body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-card {
        padding: 30px 18px !important;
        border-radius: 22px !important;
    }
}

@media (max-width: 560px) {
    body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-card::before {
        width: min(210px, 76%) !important;
        height: 44px !important;
        margin-bottom: 18px !important;
    }

    body.eventosapp-app-page #eventosapp-app-root .evapp-404 h1#evapp-404-title {
        font-size: clamp(31px, 10vw, 42px) !important;
    }

    body.eventosapp-app-page #eventosapp-app-root .evapp-404 .evapp-404-actions {
        display: grid !important;
        grid-template-columns: 1fr !important;
    }

    body.eventosapp-app-page #eventosapp-app-root .evapp-404 a.evapp-404-btn {
        width: 100% !important;
    }
}
</style>
        <?php
    }
}
add_action( 'wp_footer', 'eventosapp_404_visual_compat_print_styles', 1012 );
