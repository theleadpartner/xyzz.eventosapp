<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Hotfix visual específico para Transacciones de Consumibles.
 *
 * Corrige los estados Activa / Cancelación solicitada / Anulada en Dark Mode
 * sin modificar consultas, filtros, AJAX, permisos, ledger, solicitudes de
 * cancelación, reversos, saldos ni auditoría.
 *
 * Se mantiene separado de la capa general de Consumibles para que este ajuste
 * pueda cargarse al final y prevalecer sobre el CSS inline histórico de
 * eventosapp-consumables-transactions.php y sobre estilos de Elementor/tema.
 */

if ( ! function_exists( 'eventosapp_consumables_transactions_dark_compat_is_active' ) ) {
    function eventosapp_consumables_transactions_dark_compat_is_active() {
        if ( function_exists( 'eventosapp_elementor_compat_is_active' ) ) {
            return eventosapp_elementor_compat_is_active();
        }

        return function_exists( 'eventosapp_branding_is_managed_page' )
            && eventosapp_branding_is_managed_page();
    }
}

if ( ! function_exists( 'eventosapp_consumables_transactions_dark_compat_print_styles' ) ) {
    function eventosapp_consumables_transactions_dark_compat_print_styles() {
        if ( ! eventosapp_consumables_transactions_dark_compat_is_active() ) return;
        ?>
<style id="eventosapp-consumables-transactions-dark-compat">
/* ==========================================================
 * EventosApp 1.5.0-rc.14 — Consumibles / Transacciones
 * Dark Mode definitivo de estados y tarjetas
 * ========================================================== */

/* Reafirma el aspecto base de ambas vistas de transacciones. */
body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions.evapp-tx-shell,
    #evapp-consumables-my-transactions.evapp-tx-shell
) {
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions,
    #evapp-consumables-my-transactions
) :where(
    .evapp-tx-summary-card,
    .evapp-tx-card,
    .evapp-tx-empty,
    .evapp-tx-table-wrap,
    .evapp-tx-filters
) {
    border-color: var(--eventosapp-app-border) !important;
}

/* ==========================================================
 * DARK MODE
 * ========================================================== */
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions.evapp-tx-shell,
    #evapp-consumables-my-transactions.evapp-tx-shell
) {
    --evapp-tx-pending: #f1c861;
    --evapp-tx-reversed: #cbd5e1;
    --evapp-tx-active: #7bb1df;
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions,
    #evapp-consumables-my-transactions
) :where(
    .evapp-tx-summary-card,
    .evapp-tx-card,
    .evapp-tx-empty,
    .evapp-tx-table-wrap,
    .evapp-tx-filters
) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions,
    #evapp-consumables-my-transactions
) :where(
    h2,
    h3,
    h4,
    .evapp-tx-summary-card span
) {
    color: var(--eventosapp-app-text) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions,
    #evapp-consumables-my-transactions
) :where(
    .lead,
    .evapp-tx-head p,
    .evapp-tx-muted,
    .evapp-tx-summary-card small,
    .evapp-tx-summary-card em
) {
    color: var(--eventosapp-app-muted) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions,
    #evapp-consumables-my-transactions
) .evapp-tx-summary-card strong {
    color: var(--evapp-tx-active) !important;
}

/* Estado ACTIVA: azul corporativo oscuro, nunca blanco. */
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions,
    #evapp-consumables-my-transactions
) .evapp-tx-badge:not(.is-pending):not(.is-reversed) {
    background: rgba(54, 131, 197, .16) !important;
    border: 1px solid rgba(123, 177, 223, .30) !important;
    color: var(--evapp-tx-active) !important;
}

/*
 * Estado PENDIENTE.
 * La tarjeta conserva una superficie oscura neutra. La semántica pasa al
 * borde/acento y al badge, evitando el bloque marrón mostrado en la captura.
 */
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root #evapp-consumables-my-transactions .evapp-tx-card.is-pending {
    background: var(--eventosapp-app-surface) !important;
    border-color: rgba(241, 200, 97, .42) !important;
    box-shadow: inset 3px 0 0 rgba(241, 200, 97, .82) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root #evapp-consumables-transactions .evapp-tx-table tr.is-pending td {
    background: var(--eventosapp-app-surface) !important;
    border-bottom-color: rgba(241, 200, 97, .24) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root #evapp-consumables-transactions .evapp-tx-table tr.is-pending td:first-child {
    box-shadow: inset 3px 0 0 rgba(241, 200, 97, .82) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions,
    #evapp-consumables-my-transactions
) .evapp-tx-badge.is-pending {
    background: rgba(241, 200, 97, .14) !important;
    border: 1px solid rgba(241, 200, 97, .34) !important;
    color: var(--evapp-tx-pending) !important;
    box-shadow: none !important;
}

/* Estado ANULADA: gris azulado oscuro con contraste suficiente. */
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions,
    #evapp-consumables-my-transactions
) .evapp-tx-badge.is-reversed {
    background: rgba(148, 163, 184, .14) !important;
    border: 1px solid rgba(203, 213, 225, .24) !important;
    color: var(--evapp-tx-reversed) !important;
    box-shadow: none !important;
}

/* Chips de artículos: acento corporativo legible sobre superficie oscura. */
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions,
    #evapp-consumables-my-transactions
) .evapp-tx-card-item {
    background: rgba(54, 131, 197, .16) !important;
    border: 1px solid rgba(123, 177, 223, .20) !important;
    color: var(--evapp-tx-active) !important;
}

/* Tabla administrativa: evita cualquier blanco histórico. */
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root #evapp-consumables-transactions .evapp-tx-table th {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-muted) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root #evapp-consumables-transactions .evapp-tx-table td {
    color: var(--eventosapp-app-text) !important;
    border-color: var(--eventosapp-app-border) !important;
}

/* Inputs y selects de filtros. */
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root #evapp-consumables-transactions :where(
    .evapp-tx-field input,
    .evapp-tx-field select
) {
    background: var(--eventosapp-app-input) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root #evapp-consumables-transactions .evapp-tx-field select option {
    background: var(--eventosapp-app-input) !important;
    color: var(--eventosapp-app-text) !important;
}

/* Paginación y empty states. */
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions,
    #evapp-consumables-my-transactions
) .evapp-tx-pages :where(a, span) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions,
    #evapp-consumables-my-transactions
) .evapp-tx-pages .current {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

/* Botones de Staff y administrador, incluyendo disabled durante AJAX. */
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions,
    #evapp-consumables-my-transactions
) .evapp-tx-btn:not(.is-danger):not(.is-secondary) {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions,
    #evapp-consumables-my-transactions
) .evapp-tx-btn:not(.is-danger):not(.is-secondary):hover:not(:disabled),
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions,
    #evapp-consumables-my-transactions
) .evapp-tx-btn:not(.is-danger):not(.is-secondary):focus-visible:not(:disabled) {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    #evapp-consumables-transactions,
    #evapp-consumables-my-transactions
) .evapp-tx-btn:disabled {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-muted) !important;
    opacity: 1 !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root #evapp-consumables-transactions .evapp-tx-btn.is-secondary {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--evapp-tx-active) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root #evapp-consumables-transactions .evapp-tx-btn.is-danger {
    background: var(--eventosapp-app-surface) !important;
    border-color: rgba(240, 141, 141, .42) !important;
    color: #f4aaaa !important;
}
</style>
        <?php
    }
}
add_action( 'wp_footer', 'eventosapp_consumables_transactions_dark_compat_print_styles', 1006 );
