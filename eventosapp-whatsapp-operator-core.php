<?php
/**
 * EventosApp - Respaldo seguro del núcleo del Operador WhatsApp.
 *
 * Este archivo solo se carga desde eventosapp.php cuando no existe la ruta
 * principal includes/functions/eventosapp-whatsapp-operator-core.php. Su única
 * responsabilidad es desactivar de forma controlada las pantallas y acciones
 * del onboarding para que una instalación incompleta no provoque errores
 * fatales al crear o editar Clientes.
 *
 * @package EventosApp
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! defined('EVENTOSAPP_WA_OPERATOR_CORE_FALLBACK_ACTIVE') ) {
    define('EVENTOSAPP_WA_OPERATOR_CORE_FALLBACK_ACTIVE', true);
}

if ( ! function_exists('eventosapp_wa_operator_core_fallback_message') ) {
    function eventosapp_wa_operator_core_fallback_message() {
        return 'El núcleo del Operador WhatsApp no está disponible. Verifica que el despliegue incluya includes/functions/eventosapp-whatsapp-operator-core.php. La creación y edición de clientes continúa disponible sin el onboarding de WhatsApp.';
    }
}

/**
 * El onboarding registra este metabox en prioridad 10. Se elimina al final del
 * mismo hook antes de que WordPress intente ejecutar su callback dependiente.
 */
add_action('add_meta_boxes_eventosapp_cliente', function() {
    remove_meta_box(
        'eventosapp_cliente_whatsapp_operator',
        'eventosapp_cliente',
        'side'
    );
}, PHP_INT_MAX);

/**
 * Oculta la entrada de menú que depende del núcleo ausente.
 */
add_action('admin_menu', function() {
    remove_submenu_page(
        'eventosapp_dashboard',
        'eventosapp_whatsapp_operator'
    );
}, PHP_INT_MAX);

/**
 * Intercepta accesos directos, acciones administrativas y solicitudes AJAX del
 * Operador WhatsApp para devolver un error controlado en vez de un fatal error.
 */
add_action('admin_init', function() {
    $page   = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';

    $is_operator_page   = ($page === 'eventosapp_whatsapp_operator');
    $is_operator_action = (strpos($action, 'eventosapp_wa_operator_') === 0);

    if ( ! $is_operator_page && ! $is_operator_action ) {
        return;
    }

    $message = eventosapp_wa_operator_core_fallback_message();

    if ( wp_doing_ajax() ) {
        wp_send_json_error(['message' => $message], 503);
    }

    wp_die(
        esc_html($message),
        'EventosApp — Operador WhatsApp no disponible',
        ['response' => 503]
    );
}, 1);

/**
 * Aviso visible únicamente al administrar Clientes de EventosApp.
 */
add_action('admin_notices', function() {
    if ( ! current_user_can('manage_options') || ! function_exists('get_current_screen') ) {
        return;
    }

    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'eventosapp_cliente' ) {
        return;
    }

    echo '<div class="notice notice-warning"><p><strong>EventosApp:</strong> ';
    echo esc_html(eventosapp_wa_operator_core_fallback_message());
    echo '</p></div>';
});
