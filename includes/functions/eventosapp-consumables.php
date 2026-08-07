<?php
/**
 * EventosApp — Cargador seguro del Control de Consumibles.
 *
 * Este archivo conserva la implementación completa en
 * eventosapp-consumables-core.php y define antes de cargarla únicamente la
 * integración segura con la landing pública del ticket presencial.
 *
 * La separación evita iniciar un nuevo búfer de salida desde el callback de
 * otro búfer, condición que provoca un error fatal de PHP y deja la landing
 * pública completamente en blanco.
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('eventosapp_consumables_resolve_public_ticket_request') ) {
    /**
     * Resuelve el ticket público usando primero el motor oficial de WhatsApp.
     *
     * @return int
     */
    function eventosapp_consumables_resolve_public_ticket_request() {
        if ( function_exists('eventosapp_whatsapp_resolve_public_ticket_from_request') ) {
            return absint(eventosapp_whatsapp_resolve_public_ticket_from_request());
        }

        if ( function_exists('eventosapp_whatsapp_templates_resolve_ticket_from_request') ) {
            return absint(eventosapp_whatsapp_templates_resolve_ticket_from_request());
        }

        if ( function_exists('eventosapp_resolve_ticket_from_request') ) {
            return absint(eventosapp_resolve_ticket_from_request($_REQUEST));
        }

        foreach ( ['ticket', 'ticket_pub', 'public_id', 'ticketID'] as $key ) {
            if ( empty($_GET[$key]) ) continue;

            $public_id = sanitize_text_field(wp_unslash($_GET[$key]));
            if ( function_exists('eventosapp_find_ticket_by_public_id') ) {
                $ticket_id = absint(eventosapp_find_ticket_by_public_id($public_id));
                if ( $ticket_id ) return $ticket_id;
            }
        }

        return 0;
    }
}

if ( ! function_exists('eventosapp_consumables_inject_public_inventory') ) {
    /**
     * Inserta en la respuesta un bloque de inventario preparado previamente.
     *
     * IMPORTANTE: este callback no consulta la base de datos, no genera HTML y
     * no abre otro ob_start(). Los manejadores de salida de PHP no permiten
     * iniciar un nuevo búfer mientras están procesando la respuesta.
     *
     * @param string $html Respuesta HTML completa.
     * @return string
     */
    function eventosapp_consumables_inject_public_inventory($html) {
        if ( ! is_string($html) || $html === '' ) return $html;
        if ( strpos($html, 'evapp-ticket-consumables') !== false ) return $html;

        $inventory_html = isset($GLOBALS['eventosapp_consumables_public_inventory_html'])
            ? (string) $GLOBALS['eventosapp_consumables_public_inventory_html']
            : '';
        if ( $inventory_html === '' ) return $html;

        // Solo transforma una respuesta que realmente corresponda a la landing
        // pública de ticket, nunca la landing virtual ni otra página del sitio.
        if (
            strpos($html, 'evapp-wa-ticket-public') === false
            && strpos($html, 'evapp-ticket-card') === false
        ) {
            return $html;
        }

        $marker = '<div class="evapp-ticket-actions">';
        $position = strpos($html, $marker);
        if ( $position !== false ) {
            unset($GLOBALS['eventosapp_consumables_public_inventory_html']);
            return substr($html, 0, $position) . $inventory_html . substr($html, $position);
        }

        $fallback = '</section>';
        $position = strrpos($html, $fallback);
        if ( $position !== false ) {
            unset($GLOBALS['eventosapp_consumables_public_inventory_html']);
            return substr($html, 0, $position) . $inventory_html . substr($html, $position);
        }

        return $html;
    }
}

if ( ! function_exists('eventosapp_consumables_start_public_landing_buffer') ) {
    /**
     * Prepara el inventario antes de iniciar el búfer de la landing presencial.
     *
     * @return void
     */
    function eventosapp_consumables_start_public_landing_buffer() {
        static $started = false;
        if ( $started ) return;

        $current_hook = current_filter();
        $legacy_admin_post = in_array($current_hook, [
            'admin_post_nopriv_eventosapp_whatsapp_ticket_landing',
            'admin_post_eventosapp_whatsapp_ticket_landing',
        ], true);

        if ( is_admin() && ! $legacy_admin_post ) return;

        $action = isset($_GET['eventosapp_whatsapp_public_action'])
            ? sanitize_key(wp_unslash($_GET['eventosapp_whatsapp_public_action']))
            : '';
        if ( $action !== '' && $action !== 'ticket_landing' ) return;

        if ( ! $legacy_admin_post ) {
            // Limita la intervención exclusivamente a /ticket/ y nunca a /virtual/.
            $is_ticket_route = false;

            if ( function_exists('eventosapp_whatsapp_get_public_ticket_route_context') ) {
                $route_context = eventosapp_whatsapp_get_public_ticket_route_context();
                $is_ticket_route = ! empty($route_context['is_ticket_route']);
            } else {
                $request_uri  = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
                $request_path = trim((string) wp_parse_url($request_uri, PHP_URL_PATH), '/');
                $ticket_slug  = function_exists('eventosapp_whatsapp_public_ticket_page_slug')
                    ? eventosapp_whatsapp_public_ticket_page_slug()
                    : 'ticket';
                $is_ticket_route = $request_path === $ticket_slug
                    || strpos($request_path, $ticket_slug . '/') === 0;
            }

            if ( ! $is_ticket_route ) return;
        }

        $ticket_id = eventosapp_consumables_resolve_public_ticket_request();
        if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) return;
        if ( function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id) ) return;

        $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        if ( ! $event_id ) return;
        if ( ! function_exists('eventosapp_consumables_public_inventory_html') ) return;

        // El HTML se genera aquí, fuera del manejador del búfer de salida.
        $inventory_html = eventosapp_consumables_public_inventory_html($ticket_id, $event_id);
        if ( $inventory_html === '' ) return;

        $GLOBALS['eventosapp_consumables_public_inventory_html'] = $inventory_html;
        $started = true;
        ob_start('eventosapp_consumables_inject_public_inventory');
    }
}

$core_file = __DIR__ . '/eventosapp-consumables-core.php';
if ( ! is_file($core_file) ) {
    return;
}

require_once $core_file;

// Mejoras de auditoría, consulta, reversos y movimientos del asistente.
$transactions_file = __DIR__ . '/eventosapp-consumables-transactions.php';
if ( is_file($transactions_file) ) {
    require_once $transactions_file;
}
