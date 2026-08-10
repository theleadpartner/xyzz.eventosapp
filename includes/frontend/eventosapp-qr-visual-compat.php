<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Compatibilidad visual final para los lectores QR de EventosApp.
 *
 * Los módulos QR conservan CSS inline histórico porque su lógica de cámara,
 * validación, check-in, sesiones y doble autenticación ya es estable. Esta capa
 * se imprime después del branding, del pulido general y del aislamiento de
 * Elementor para remapear exclusivamente presentación: marca, superficies,
 * controles, estados y contraste Light/Dark.
 *
 * No modifica permisos, nonces, AJAX, consultas, cámara, BarcodeDetector/jsQR,
 * check-in multidía, sesiones, localidad, acompañantes ni segundo factor.
 */

if ( ! function_exists( 'eventosapp_qr_visual_compat_is_active' ) ) {
    function eventosapp_qr_visual_compat_is_active() {
        if ( function_exists( 'eventosapp_elementor_compat_is_active' ) ) {
            return eventosapp_elementor_compat_is_active();
        }

        return function_exists( 'eventosapp_branding_is_managed_page' )
            && eventosapp_branding_is_managed_page();
    }
}

if ( ! function_exists( 'eventosapp_qr_visual_compat_print_styles' ) ) {
    function eventosapp_qr_visual_compat_print_styles() {
        if ( ! eventosapp_qr_visual_compat_is_active() ) return;

        $icon_color = function_exists( 'eventosapp_brand_asset_url' )
            ? eventosapp_brand_asset_url( 'icon_color' )
            : '';
        $icon_white = function_exists( 'eventosapp_brand_asset_url' )
            ? eventosapp_brand_asset_url( 'icon_white' )
            : '';
        ?>
<style id="eventosapp-qr-visual-compat">
/* ==========================================================
 * EventosApp 1.5.0-rc.11 — Lectores QR
 * Marca + Dark Mode + aislamiento Elementor/tema
 * ========================================================== */

body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-qr-checkin-app,
    .evapp-qr-localidad-app,
    .evapp-qr-sesion-app,
    .evapp-double-auth-app
),
body.eventosapp-app-page .evda-auth-modal {
    --evapp-primary: var(--eventosapp-brand-blue) !important;
    --evapp-primary-dark: var(--eventosapp-brand-blue-dark) !important;
    --evapp-primary-soft: var(--eventosapp-brand-blue-soft) !important;
    --evapp-app-bg: var(--eventosapp-app-surface-soft) !important;
    --evapp-surface: var(--eventosapp-app-surface) !important;
    --evapp-border: var(--eventosapp-app-border) !important;
    --evapp-text: var(--eventosapp-app-text) !important;
    --evapp-muted: var(--eventosapp-app-muted) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-qr-checkin-app,
    .evapp-qr-localidad-app,
    .evapp-qr-sesion-app,
    .evapp-double-auth-app
),
html[data-evapp-theme="dark"] body.eventosapp-app-page .evda-auth-modal {
    --evapp-primary-soft: rgba(54, 131, 197, .16) !important;
    --evapp-app-bg: var(--eventosapp-app-surface-soft) !important;
    --evapp-surface: var(--eventosapp-app-surface) !important;
    --evapp-border: var(--eventosapp-app-border) !important;
    --evapp-text: var(--eventosapp-app-text) !important;
    --evapp-muted: var(--eventosapp-app-muted) !important;
    --evapp-success: #62d4a5 !important;
    --evapp-success-soft: rgba(21, 128, 61, .20) !important;
    --evapp-warning: #f1c861 !important;
    --evapp-warning-soft: rgba(180, 83, 9, .22) !important;
    --evapp-danger: #f08d8d !important;
    --evapp-danger-soft: rgba(180, 35, 24, .20) !important;
}

body.eventosapp-app-page #eventosapp-app-root :where(
    .evqr-eyebrow,
    .evqrl-eyebrow,
    .evqrs-eyebrow,
    .evda-eyebrow
) {
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root :where(
    .evqr-eyebrow,
    .evqrl-eyebrow,
    .evqrs-eyebrow,
    .evda-eyebrow
)::before {
    content: "";
    display: inline-block;
    width: 16px;
    height: 18px;
    flex: 0 0 16px;
    margin: 0 !important;
    background: url("<?php echo esc_url( $icon_color ); ?>") center / contain no-repeat;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    .evqr-eyebrow,
    .evqrl-eyebrow,
    .evqrs-eyebrow,
    .evda-eyebrow
) {
    color: #7bb1df !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    .evqr-eyebrow,
    .evqrl-eyebrow,
    .evqrs-eyebrow,
    .evda-eyebrow
)::before {
    background-image: url("<?php echo esc_url( $icon_white ); ?>") !important;
}

body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-qr-checkin-app .evqr-title,
    .evapp-qr-checkin-app .evqr-panel-title,
    .evapp-qr-checkin-app .evqr-event-name,
    .evapp-qr-checkin-app .evqr-state-title,
    .evapp-qr-checkin-app .evapp-acomp-label,
    .evapp-qr-localidad-app .evqrl-title,
    .evapp-qr-localidad-app .evqrl-panel-title,
    .evapp-qr-localidad-app .evqrl-event-name,
    .evapp-qr-localidad-app .evqrl-state-title,
    .evapp-qr-sesion-app .evqrs-title,
    .evapp-qr-sesion-app .evqrs-panel-title,
    .evapp-qr-sesion-app .evqrs-event-name,
    .evapp-qr-sesion-app .evqrs-label,
    .evapp-qr-sesion-app .evqrs-state-title,
    .evapp-qr-sesion-app .evqrs-session-name,
    .evapp-double-auth-app .evda-title,
    .evapp-double-auth-app .evda-panel-title,
    .evapp-double-auth-app .evda-event-name,
    .evapp-double-auth-app .evda-state-title,
    .evapp-double-auth-app .evda-auth-label,
    .evapp-double-auth-app .evda-companion-label
) {
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-qr-checkin-app .evqr-subtitle,
    .evapp-qr-checkin-app .evqr-panel-desc,
    .evapp-qr-checkin-app .evqr-event-kicker,
    .evapp-qr-checkin-app .evqr-state-text,
    .evapp-qr-checkin-app .evapp-acomp-status,
    .evapp-qr-localidad-app .evqrl-subtitle,
    .evapp-qr-localidad-app .evqrl-panel-desc,
    .evapp-qr-localidad-app .evqrl-event-kicker,
    .evapp-qr-localidad-app .evqrl-state-text,
    .evapp-qr-localidad-app .evqrl-locality-label,
    .evapp-qr-sesion-app .evqrs-subtitle,
    .evapp-qr-sesion-app .evqrs-panel-desc,
    .evapp-qr-sesion-app .evqrs-event-kicker,
    .evapp-qr-sesion-app .evqrs-field-help,
    .evapp-qr-sesion-app .evqrs-state-text,
    .evapp-qr-sesion-app .evqrs-session-kicker,
    .evapp-qr-sesion-app .evqrs-session-note,
    .evapp-double-auth-app .evda-subtitle,
    .evapp-double-auth-app .evda-panel-desc,
    .evapp-double-auth-app .evda-event-kicker,
    .evapp-double-auth-app .evda-state-text,
    .evapp-double-auth-app .evda-auth-help,
    .evapp-double-auth-app .evda-companion-status
) {
    color: var(--evapp-muted) !important;
}

/* Check-In QR estándar */
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app .evqr-shell {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evqr-event-context,.evqr-panel,.evqr-state,.evapp-qr-grid) {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evqr-guide-item,.evapp-qr-help,.evapp-acomp-panel,.evqr-camera-state,.evqr-chip) {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-muted) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app .evapp-qr-grid > div {
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app .evapp-qr-grid > div:nth-child(odd) {
    background: var(--eventosapp-app-surface-soft) !important;
    color: var(--evapp-muted) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app .evapp-acomp-input {
    -webkit-appearance: none !important;
    appearance: none !important;
    background: var(--eventosapp-app-input) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app .evapp-acomp-input:hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app .evapp-acomp-input:focus {
    border-color: var(--eventosapp-brand-blue) !important;
    background: var(--eventosapp-app-input) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evqr-btn,.evapp-qr-btn,.evapp-qr-btn-secondary,.evapp-payment-reminder-btn,.evapp-acomp-btn) {
    -webkit-appearance: none !important;
    appearance: none !important;
    text-decoration: none !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evapp-qr-btn,.evapp-payment-reminder-btn,.evapp-acomp-btn):not(.is-live):not(.is-success) {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evapp-qr-btn,.evapp-payment-reminder-btn,.evapp-acomp-btn):not(.is-live):not(.is-success):hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evapp-qr-btn,.evapp-payment-reminder-btn,.evapp-acomp-btn):not(.is-live):not(.is-success):focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evqr-btn-secondary,.evapp-qr-btn-secondary) {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evqr-btn-secondary,.evapp-qr-btn-secondary):hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evqr-btn-secondary,.evapp-qr-btn-secondary):focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evqr-chip.is-valid,.evqr-camera-state.is-live,.evqr-state.is-success,.evqr-payment-note,.evqr-inline-status.is-success) {
    background: var(--evapp-success-soft) !important;
    border-color: var(--evapp-success) !important;
    color: var(--evapp-success) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evqr-chip.is-warning,.evqr-state.is-warning) {
    background: var(--evapp-warning-soft) !important;
    border-color: var(--evapp-warning) !important;
    color: var(--evapp-warning) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evqr-state.is-danger,.evqr-inline-status.is-danger) {
    background: var(--evapp-danger-soft) !important;
    border-color: var(--evapp-danger) !important;
    color: var(--evapp-danger) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evqr-state.is-info,.evqr-inline-status.is-info,.evqr-camera-state.is-busy) {
    background: var(--evapp-primary-soft) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evqr-state.is-success,.evqr-state.is-warning,.evqr-state.is-danger,.evqr-state.is-info) .evqr-state-title {
    color: inherit !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evqr-state.is-success,.evqr-state.is-warning,.evqr-state.is-danger,.evqr-state.is-info) .evqr-state-text {
    color: var(--evapp-muted) !important;
}
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evqr-btn-secondary,.evapp-qr-btn-secondary):hover:not(:disabled),
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evqr-btn-secondary,.evapp-qr-btn-secondary):focus-visible,
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app :where(.evqr-qr-type,.evqr-camera-state.is-busy,.evqr-inline-status.is-info) {
    color: #8fc0e8 !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app .evapp-qr-video-wrap {
    border-color: var(--evapp-border) !important;
}

/* Validador de Localidad */
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app .evqrl-shell {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app :where(.evqrl-event-context,.evqrl-panel,.evqrl-state,.evqrl-grid) {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app :where(.evqrl-guide-item,.evqrl-help,.evqrl-camera-state,.evqrl-chip) {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-muted) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app .evqrl-grid > div {
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app .evqrl-grid > div:nth-child(odd) {
    background: var(--eventosapp-app-surface-soft) !important;
    color: var(--evapp-muted) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app :where(.evqrl-btn,.evqrl-scan-btn,.evqrl-scan-again) {
    -webkit-appearance: none !important;
    appearance: none !important;
    text-decoration: none !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app .evqrl-scan-btn:not(.is-live) {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app .evqrl-scan-btn:not(.is-live):hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app .evqrl-scan-btn:not(.is-live):focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app :where(.evqrl-btn-secondary,.evqrl-scan-again) {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app :where(.evqrl-btn-secondary,.evqrl-scan-again):hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app :where(.evqrl-btn-secondary,.evqrl-scan-again):focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app :where(.evqrl-chip.is-readonly,.evqrl-state.is-info,.evqrl-camera-state.is-busy) {
    background: var(--evapp-primary-soft) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app :where(.evqrl-chip.is-safe,.evqrl-camera-state.is-live,.evqrl-state.is-success) {
    background: var(--evapp-success-soft) !important;
    border-color: var(--evapp-success) !important;
    color: var(--evapp-success) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app .evqrl-state.is-warning,
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app .evqrl-locality-card.is-missing {
    background: var(--evapp-warning-soft) !important;
    border-color: var(--evapp-warning) !important;
    color: var(--evapp-warning) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app .evqrl-state.is-danger {
    background: var(--evapp-danger-soft) !important;
    border-color: var(--evapp-danger) !important;
    color: var(--evapp-danger) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app .evqrl-locality-card:not(.is-missing) {
    background: var(--evapp-primary-soft) !important;
    border-color: var(--eventosapp-brand-blue) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app .evqrl-locality-card:not(.is-missing) .evqrl-locality-value {
    color: var(--eventosapp-brand-blue-dark) !important;
}
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app :where(.evqrl-btn-secondary,.evqrl-scan-again):hover:not(:disabled),
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app :where(.evqrl-btn-secondary,.evqrl-scan-again):focus-visible,
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app :where(.evqrl-chip.is-readonly,.evqrl-camera-state.is-busy,.evqrl-locality-value) {
    color: #8fc0e8 !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app .evqrl-video-wrap {
    border-color: var(--evapp-border) !important;
}

/* Control de acceso por sesión */
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app .evqrs-shell {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app :where(.evqrs-event-context,.evqrs-panel,.evqrs-state,.evqrs-grid) {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app :where(.evqrs-field,.evqrs-guide-item,.evqrs-help,.evqrs-session-card,.evqrs-camera-state,.evqrs-chip) {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-muted) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app .evqrs-select {
    -webkit-appearance: none !important;
    appearance: none !important;
    background-color: var(--eventosapp-app-input) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app .evqrs-select option {
    background: var(--eventosapp-app-input) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app .evqrs-select:hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app .evqrs-select:focus {
    border-color: var(--eventosapp-brand-blue) !important;
    background-color: var(--eventosapp-app-input) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app .evqrs-select:disabled {
    background-color: var(--eventosapp-app-surface-soft) !important;
    color: var(--evapp-muted) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app .evqrs-selected-session {
    background: var(--evapp-primary-soft) !important;
    border-color: var(--eventosapp-brand-blue) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app .evqrs-selected-session span:first-child {
    color: var(--evapp-muted) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app .evqrs-selected-session strong {
    color: var(--eventosapp-brand-blue-dark) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app .evqrs-grid > div {
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app .evqrs-grid > div:nth-child(odd) {
    background: var(--eventosapp-app-surface-soft) !important;
    color: var(--evapp-muted) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app :where(.evqrs-btn,.evqrs-scan-btn,.evqrs-scan-again,.evqrs-session-check) {
    -webkit-appearance: none !important;
    appearance: none !important;
    text-decoration: none !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app .evqrs-scan-btn:not(.is-live) {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app .evqrs-scan-btn:not(.is-live):hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app .evqrs-scan-btn:not(.is-live):focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app :where(.evqrs-btn-secondary,.evqrs-scan-again) {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app :where(.evqrs-btn-secondary,.evqrs-scan-again):hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app :where(.evqrs-btn-secondary,.evqrs-scan-again):focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app .evqrs-session-check {
    background: var(--evapp-success) !important;
    border-color: var(--evapp-success) !important;
    color: #fff !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app :where(.evqrs-chip.is-valid,.evqrs-camera-state.is-live,.evqrs-state.is-success,.evqrs-access-badge.is-success) {
    background: var(--evapp-success-soft) !important;
    border-color: var(--evapp-success) !important;
    color: var(--evapp-success) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app :where(.evqrs-chip.is-warning,.evqrs-empty,.evqrs-state.is-warning,.evqrs-access-badge.is-warning) {
    background: var(--evapp-warning-soft) !important;
    border-color: var(--evapp-warning) !important;
    color: var(--evapp-warning) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app :where(.evqrs-state.is-danger,.evqrs-access-badge.is-danger) {
    background: var(--evapp-danger-soft) !important;
    border-color: var(--evapp-danger) !important;
    color: var(--evapp-danger) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app :where(.evqrs-chip.is-info,.evqrs-state.is-info,.evqrs-camera-state.is-busy) {
    background: var(--evapp-primary-soft) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app :where(.evqrs-btn-secondary,.evqrs-scan-again):hover:not(:disabled),
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app :where(.evqrs-btn-secondary,.evqrs-scan-again):focus-visible,
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app :where(.evqrs-selected-session strong,.evqrs-chip.is-info,.evqrs-camera-state.is-busy) {
    color: #8fc0e8 !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app .evqrs-video-wrap {
    border-color: var(--evapp-border) !important;
}

/* Check-In QR con Doble Autenticación */
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app .evda-shell {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evda-event-context,.evda-panel,.evda-state,.evapp-qr-grid,.evda-notice) {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evapp-qr-help,.evda-auth-form,.evda-companion-panel,.evda-camera-state,.evda-chip,.evda-guide-item) {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-muted) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app .evapp-qr-grid .evda-label {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-muted) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app .evapp-qr-grid .evda-value {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evda-auth-input,.evda-companion-input) {
    -webkit-appearance: none !important;
    appearance: none !important;
    background: var(--eventosapp-app-input) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evda-auth-input,.evda-companion-input):hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evda-auth-input,.evda-companion-input):focus {
    background: var(--eventosapp-app-input) !important;
    border-color: var(--eventosapp-brand-blue) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evda-btn,.evapp-qr-btn,.evapp-qr-btn-secondary,.evda-action-btn) {
    -webkit-appearance: none !important;
    appearance: none !important;
    text-decoration: none !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evapp-qr-btn,.evda-action-btn):not(.is-live):not(.evda-verify-btn):not(.evda-cancel-btn) {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evapp-qr-btn,.evda-action-btn):not(.is-live):not(.evda-verify-btn):not(.evda-cancel-btn):hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evapp-qr-btn,.evda-action-btn):not(.is-live):not(.evda-verify-btn):not(.evda-cancel-btn):focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evda-btn-secondary,.evapp-qr-btn-secondary,.evda-cancel-btn) {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evda-btn-secondary,.evapp-qr-btn-secondary,.evda-cancel-btn):hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evda-btn-secondary,.evapp-qr-btn-secondary,.evda-cancel-btn):focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app .evda-verify-btn {
    background: var(--evapp-success) !important;
    border-color: var(--evapp-success) !important;
    color: #fff !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evda-chip.is-success,.evda-camera-state.is-live,.evda-state.is-success,.evda-inline-status.is-success) {
    background: var(--evapp-success-soft) !important;
    border-color: var(--evapp-success) !important;
    color: var(--evapp-success) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evda-chip.is-warning,.evda-notice.is-warning,.evda-state.is-warning) {
    background: var(--evapp-warning-soft) !important;
    border-color: var(--evapp-warning) !important;
    color: var(--evapp-warning) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evda-chip.is-danger,.evda-notice.is-danger,.evda-state.is-danger,.evda-inline-status.is-danger) {
    background: var(--evapp-danger-soft) !important;
    border-color: var(--evapp-danger) !important;
    color: var(--evapp-danger) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evda-state.is-info,.evda-camera-state.is-busy,.evda-payment-note) {
    background: var(--evapp-primary-soft) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evda-btn-secondary,.evapp-qr-btn-secondary,.evda-cancel-btn):hover:not(:disabled),
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evda-btn-secondary,.evapp-qr-btn-secondary,.evda-cancel-btn):focus-visible,
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app :where(.evda-camera-state.is-busy,.evda-payment-note,.evda-qr-type) {
    color: #8fc0e8 !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app .evapp-qr-video-wrap {
    border-color: var(--evapp-border) !important;
}

/* Modal del segundo factor. Puede estar fuera de #eventosapp-app-root. */
body.eventosapp-app-page .evda-auth-modal {
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page .evda-auth-modal .evda-auth-dialog {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page .evda-auth-modal :where(.evda-auth-modal-head,.evda-auth-modal-foot) {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
}
body.eventosapp-app-page .evda-auth-modal :where(.evda-auth-modal-title,.evda-state-title,.evda-auth-label,.evda-companion-label) {
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page .evda-auth-modal :where(.evda-auth-modal-subtitle,.evda-state-text,.evda-auth-help,.evda-companion-status) {
    color: var(--evapp-muted) !important;
}
body.eventosapp-app-page .evda-auth-modal :where(.evda-auth-modal-close,.evda-cancel-btn,.evda-action-btn.is-secondary) {
    -webkit-appearance: none !important;
    appearance: none !important;
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
    box-shadow: none !important;
}
body.eventosapp-app-page .evda-auth-modal :where(.evda-auth-modal-close,.evda-cancel-btn,.evda-action-btn.is-secondary):hover:not(:disabled),
body.eventosapp-app-page .evda-auth-modal :where(.evda-auth-modal-close,.evda-cancel-btn,.evda-action-btn.is-secondary):focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}
body.eventosapp-app-page .evda-auth-modal :where(.evda-state,.evapp-qr-grid) {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page .evda-auth-modal .evapp-qr-grid .evda-label {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-muted) !important;
}
body.eventosapp-app-page .evda-auth-modal .evapp-qr-grid .evda-value {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page .evda-auth-modal :where(.evda-auth-form,.evda-companion-panel) {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page .evda-auth-modal :where(.evda-auth-input,.evda-companion-input) {
    -webkit-appearance: none !important;
    appearance: none !important;
    background: var(--eventosapp-app-input) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}
body.eventosapp-app-page .evda-auth-modal :where(.evda-auth-input,.evda-companion-input):hover,
body.eventosapp-app-page .evda-auth-modal :where(.evda-auth-input,.evda-companion-input):focus {
    background: var(--eventosapp-app-input) !important;
    border-color: var(--eventosapp-brand-blue) !important;
}
body.eventosapp-app-page .evda-auth-modal .evda-action-btn:not(.evda-verify-btn):not(.evda-cancel-btn):not(.is-secondary) {
    -webkit-appearance: none !important;
    appearance: none !important;
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}
body.eventosapp-app-page .evda-auth-modal .evda-action-btn:not(.evda-verify-btn):not(.evda-cancel-btn):not(.is-secondary):hover:not(:disabled),
body.eventosapp-app-page .evda-auth-modal .evda-action-btn:not(.evda-verify-btn):not(.evda-cancel-btn):not(.is-secondary):focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}
body.eventosapp-app-page .evda-auth-modal .evda-verify-btn {
    background: var(--evapp-success) !important;
    border-color: var(--evapp-success) !important;
    color: #fff !important;
}
body.eventosapp-app-page .evda-auth-modal :where(.evda-state.is-success,.evda-inline-status.is-success) {
    background: var(--evapp-success-soft) !important;
    border-color: var(--evapp-success) !important;
    color: var(--evapp-success) !important;
}
body.eventosapp-app-page .evda-auth-modal .evda-state.is-warning {
    background: var(--evapp-warning-soft) !important;
    border-color: var(--evapp-warning) !important;
    color: var(--evapp-warning) !important;
}
body.eventosapp-app-page .evda-auth-modal :where(.evda-state.is-danger,.evda-inline-status.is-danger) {
    background: var(--evapp-danger-soft) !important;
    border-color: var(--evapp-danger) !important;
    color: var(--evapp-danger) !important;
}
body.eventosapp-app-page .evda-auth-modal :where(.evda-state.is-info,.evda-payment-note,.evda-inline-status:not(.is-success):not(.is-danger)) {
    background: var(--evapp-primary-soft) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}
html[data-evapp-theme="dark"] body.eventosapp-app-page .evda-auth-modal :where(.evda-auth-modal-close,.evda-cancel-btn,.evda-action-btn.is-secondary,.evda-payment-note,.evda-inline-status:not(.is-success):not(.is-danger),.evda-qr-type) {
    color: #8fc0e8 !important;
}

/* Focus y controles deshabilitados: prioridad frente al tema/Elementor. */
body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-qr-checkin-app button,
    .evapp-qr-checkin-app a,
    .evapp-qr-checkin-app input,
    .evapp-qr-localidad-app button,
    .evapp-qr-localidad-app a,
    .evapp-qr-sesion-app button,
    .evapp-qr-sesion-app a,
    .evapp-qr-sesion-app select,
    .evapp-double-auth-app button,
    .evapp-double-auth-app a,
    .evapp-double-auth-app input
):focus-visible,
body.eventosapp-app-page .evda-auth-modal :where(button,input):focus-visible {
    outline: 3px solid var(--eventosapp-brand-focus) !important;
    outline-offset: 2px !important;
}
body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-qr-checkin-app button:disabled,
    .evapp-qr-localidad-app button:disabled,
    .evapp-qr-sesion-app button:disabled,
    .evapp-double-auth-app button:disabled
),
body.eventosapp-app-page .evda-auth-modal button:disabled {
    cursor: not-allowed !important;
    filter: none !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-qr-checkin-app .evqr-qr-type,
    .evapp-qr-localidad-app .evqrl-locality-card:not(.is-missing) .evqrl-locality-value,
    .evapp-qr-sesion-app .evqrs-selected-session strong,
    .evapp-double-auth-app .evda-qr-type
) {
    color: #8fc0e8 !important;
}
</style>
        <?php
    }
}
add_action( 'wp_footer', 'eventosapp_qr_visual_compat_print_styles', 1003 );