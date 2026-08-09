<?php
/**
 * Frontend: Edición de tickets (buscar + cargar + editar)
 * Shortcode: [eventosapp_front_edit]
 * Requiere: evento activo elegido desde el dashboard (o event_id en el shortcode)
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * ===== Helpers de fecha (reusados igual que en eventosapp-frontend-search.php) =====
 */
if ( ! function_exists('eventosapp_get_today_in_event_tz') ) {
    function eventosapp_get_today_in_event_tz( $event_id ) {
        $event_tz = get_post_meta($event_id, '_eventosapp_zona_horaria', true);
        if ( ! $event_tz ) {
            $event_tz = wp_timezone_string();
            if ( ! $event_tz || $event_tz === 'UTC' ) {
                $offset = get_option('gmt_offset');
                $event_tz = $offset ? timezone_name_from_abbr('', $offset * 3600, 0) ?: 'UTC' : 'UTC';
            }
        }
        try {
            $dt = new DateTime('now', new DateTimeZone($event_tz));
        } catch (Exception $e) {
            $dt = new DateTime('now', wp_timezone());
        }
        return $dt->format('Y-m-d');
    }
}
if ( ! function_exists('eventosapp_is_today_valid_for_event') ) {
    function eventosapp_is_today_valid_for_event( $event_id ) {
        $today = eventosapp_get_today_in_event_tz($event_id);
        $days  = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days($event_id) : [];
        return (!empty($days) && in_array($today, $days, true));
    }
}

/**
 * Log interno controlado. Evita imprimir datos en pantalla cuando algún hook del guardado
 * intenta hacer echo/print durante el flujo frontend.
 */
if ( ! function_exists('eventosapp_front_edit_debug_log') ) {
    function eventosapp_front_edit_debug_log( $message, $context = [] ) {
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            $suffix = '';
            if ( ! empty($context) ) {
                $encoded = function_exists('wp_json_encode') ? wp_json_encode($context) : json_encode($context);
                $suffix  = ' | ' . $encoded;
            }
            error_log('EVENTOSAPP FRONT EDIT | ' . $message . $suffix);
        }
    }
}

/**
 * Ejecuta callbacks internos dentro de un buffer para que cualquier salida inesperada
 * de hooks de guardado, variantes, wallet, PDF o ICS no rompa el layout del frontend.
 */
if ( ! function_exists('eventosapp_front_edit_run_silent') ) {
    function eventosapp_front_edit_run_silent( $context, $callback ) {
        $level = ob_get_level();
        ob_start();

        try {
            $result = call_user_func($callback);
            $captured = ob_get_clean();

            if ( $captured !== '' ) {
                eventosapp_front_edit_debug_log('Salida inesperada capturada y descartada', [
                    'context' => $context,
                    'length'  => strlen($captured),
                    'preview' => wp_strip_all_tags(substr($captured, 0, 500)),
                ]);
            }

            return $result;
        } catch (Throwable $e) {
            while ( ob_get_level() > $level ) {
                ob_end_clean();
            }

            eventosapp_front_edit_debug_log('Excepción durante ejecución silenciosa', [
                'context' => $context,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            throw $e;
        }
    }
}

/**
 * Guarda una notificación temporal después del POST para mostrarla tras el redirect.
 */
if ( ! function_exists('eventosapp_front_edit_store_notice') ) {
    function eventosapp_front_edit_store_notice( $type, $message ) {
        $uid = get_current_user_id();
        $key = substr(md5($uid . '|' . microtime(true) . '|' . wp_generate_password(12, false, false)), 0, 20);

        set_transient('evfe_notice_' . $uid . '_' . $key, [
            'type'    => $type === 'success' ? 'success' : 'error',
            'message' => (string) $message,
        ], 5 * MINUTE_IN_SECONDS);

        return $key;
    }
}

/**
 * Recupera y consume la notificación temporal del usuario actual.
 */
if ( ! function_exists('eventosapp_front_edit_consume_notice') ) {
    function eventosapp_front_edit_consume_notice() {
        if ( empty($_GET['evfe_notice']) ) {
            return null;
        }

        $uid = get_current_user_id();
        $key = sanitize_text_field(wp_unslash($_GET['evfe_notice']));
        if ( $key === '' ) {
            return null;
        }

        $transient_key = 'evfe_notice_' . $uid . '_' . $key;
        $notice = get_transient($transient_key);
        delete_transient($transient_key);

        return is_array($notice) ? $notice : null;
    }
}

/**
 * Renderiza notificaciones del frontend con estilos seguros y consistentes.
 */
if ( ! function_exists('eventosapp_front_edit_render_notice') ) {
    function eventosapp_front_edit_render_notice( $notice ) {
        if ( ! is_array($notice) || empty($notice['message']) ) {
            return '';
        }

        $type = ! empty($notice['type']) && $notice['type'] === 'success' ? 'success' : 'error';
        $icon = $type === 'success'
            ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>'
            : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4M12 17h.01"></path><circle cx="12" cy="12" r="9"></circle></svg>';

        return '<div class="evfe-notice evfe-notice-' . esc_attr($type) . '" role="' . ($type === 'success' ? 'status' : 'alert') . '">'
            . '<span class="evfe-notice-icon" aria-hidden="true">' . $icon . '</span>'
            . '<div class="evfe-notice-copy">' . wp_kses_post($notice['message']) . '</div>'
            . '</div>';
    }
}

/**
 * Obtiene el evento activo/solicitado para el flujo frontend.
 */
if ( ! function_exists('eventosapp_front_edit_resolve_event_id') ) {
    function eventosapp_front_edit_resolve_event_id( $explicit_event_id = 0 ) {
        $eid = absint($explicit_event_id);
        if ( ! $eid && function_exists('eventosapp_get_active_event') ) {
            $eid = (int) eventosapp_get_active_event();
        }
        return $eid;
    }
}

/**
 * Procesa el guardado real del ticket reutilizando los hooks existentes del admin,
 * pero sin permitir que sus salidas internas rompan el frontend.
 */
if ( ! function_exists('eventosapp_front_edit_update_ticket_from_post') ) {
    function eventosapp_front_edit_update_ticket_from_post( $current_event_id = 0 ) {
        if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('edit') ) {
            return [
                'type'    => 'error',
                'message' => 'Permisos insuficientes.',
            ];
        }

        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if ( ! $nonce || ! wp_verify_nonce($nonce, 'eventosapp_front_edit') ) {
            eventosapp_front_edit_debug_log('Nonce inválido al guardar desde frontend', [
                'user_id'  => get_current_user_id(),
                'event_id' => $current_event_id,
            ]);

            return [
                'type'    => 'error',
                'message' => 'La sesión de seguridad expiró. Recarga la página e intenta de nuevo.',
            ];
        }

        $ticket_id = absint($_POST['ed_ticket_id'] ?? 0);
        if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
            return [
                'type'    => 'error',
                'message' => 'Ticket inválido.',
            ];
        }

        $ticket_event = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
        $eid = $ticket_event ?: eventosapp_front_edit_resolve_event_id($current_event_id);

        if ( ! $eid ) {
            return [
                'type'    => 'error',
                'message' => 'No se pudo identificar el evento del ticket.',
            ];
        }

        if ( ! current_user_can('manage_options') && $ticket_event !== (int) $current_event_id ) {
            eventosapp_front_edit_debug_log('Bloqueo por intento de editar ticket fuera del evento activo', [
                'ticket_id'       => $ticket_id,
                'ticket_event_id' => $ticket_event,
                'active_event_id' => $current_event_id,
                'user_id'         => get_current_user_id(),
            ]);

            return [
                'type'    => 'error',
                'message' => 'No puedes editar este ticket.',
            ];
        }

        if ( ! current_user_can('manage_options')
             && function_exists('eventosapp_user_can_manage_event')
             && ! eventosapp_user_can_manage_event($eid) ) {
            return [
                'type'    => 'error',
                'message' => 'No tienes permisos sobre este evento.',
            ];
        }

        $original_post = $_POST;

        try {
            // Mapear campos del formulario a los esperados por el hook save_post_eventosapp_ticket.
            $_POST['eventosapp_ticket_nonce']       = wp_create_nonce('eventosapp_ticket_guardar');
            $_POST['eventosapp_ticket_evento_id']   = $eid; // no permitimos cambiar el evento aquí
            $_POST['eventosapp_ticket_modalidad']   = sanitize_text_field(wp_unslash($_POST['ed_modalidad'] ?? ''));
            $_POST['eventosapp_ticket_user_id']     = get_current_user_id();

            $_POST['eventosapp_asistente_nombre']   = sanitize_text_field(wp_unslash($_POST['ed_nombre']   ?? ''));
            $_POST['eventosapp_asistente_apellido'] = sanitize_text_field(wp_unslash($_POST['ed_apellido'] ?? ''));
            $_POST['eventosapp_asistente_cc']       = sanitize_text_field(wp_unslash($_POST['ed_cc']       ?? ''));
            $_POST['eventosapp_asistente_email']    = sanitize_email(wp_unslash($_POST['ed_email']         ?? ''));
            $_POST['eventosapp_asistente_tel']      = sanitize_text_field(wp_unslash($_POST['ed_tel']      ?? ''));
            $_POST['eventosapp_asistente_empresa']  = sanitize_text_field(wp_unslash($_POST['ed_empresa']  ?? ''));
            $_POST['eventosapp_asistente_nit']      = sanitize_text_field(wp_unslash($_POST['ed_nit']      ?? ''));
            $_POST['eventosapp_asistente_cargo']    = sanitize_text_field(wp_unslash($_POST['ed_cargo']    ?? ''));
            $_POST['eventosapp_asistente_ciudad']   = sanitize_text_field(wp_unslash($_POST['ed_ciudad']   ?? ''));
            $_POST['eventosapp_asistente_pais']     = sanitize_text_field(wp_unslash($_POST['ed_pais']     ?? 'Colombia'));
            $_POST['eventosapp_asistente_localidad']= sanitize_text_field(wp_unslash($_POST['ed_localidad'] ?? ''));

            // Preimpreso: se conserva la compatibilidad con el guardado existente, solo para modalidad presencial.
            $resolved_modalidad_for_save = function_exists('eventosapp_resolve_ticket_modalidad')
                ? eventosapp_resolve_ticket_modalidad($eid, $_POST['eventosapp_ticket_modalidad'], get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true))
                : (sanitize_key($_POST['eventosapp_ticket_modalidad']) === 'virtual' ? 'virtual' : 'presencial');
            $preprinted_raw = wp_unslash($_POST['ed_preprinted_qr_id'] ?? '');
            if ( $resolved_modalidad_for_save !== 'virtual' && $preprinted_raw !== '' ) {
                $_POST['eventosapp_ticket_preprintedID'] = preg_replace('/\D+/', '', (string) $preprinted_raw);
            }

            // Sesiones internas.
            $_POST['eventosapp_ticket_sesiones_nonce'] = wp_create_nonce('eventosapp_ticket_sesiones_guardar');
            $ses = [];
            if ( ! empty($_POST['ed_sesiones']) && is_array($_POST['ed_sesiones']) ) {
                foreach ( $_POST['ed_sesiones'] as $s ) {
                    $ses[] = sanitize_text_field(wp_unslash($s));
                }
            }
            $_POST['eventosapp_ticket_sesiones_acceso'] = $ses;

            // Extras: los hooks actuales los sanean y guardan.
            if ( ! empty($_POST['ed_extra']) && is_array($_POST['ed_extra']) ) {
                $_POST['eventosapp_extra'] = wp_unslash($_POST['ed_extra']);
            }

            eventosapp_front_edit_run_silent('save_post_eventosapp_ticket', function() use ( $ticket_id ) {
                do_action('save_post_eventosapp_ticket', $ticket_id, get_post($ticket_id), true);
                return true;
            });

            // Compatibilidad Variantes de Tickets: al editar desde frontend,
            // recalcula la variante efectiva después de guardar los campos.
            eventosapp_front_edit_run_silent('ticket_variants_after_frontend_edit', function() use ( $ticket_id, $eid ) {
                if ( function_exists('eventosapp_ticket_variants_prepare_ticket_for_frontend_context') ) {
                    eventosapp_ticket_variants_prepare_ticket_for_frontend_context($ticket_id, $eid, 'frontend_edit_update', [
                        'sync_google_classes' => true,
                        'mark_assets_stale'   => false,
                        'clear_assets_stale'  => true,
                        'refresh_wallets'     => false,
                        'refresh_pdf_ics'     => false,
                        'rebuild_search_index'=> true,
                        'log'                 => true,
                    ]);
                } elseif ( function_exists('eventosapp_ticket_variants_apply_to_ticket') ) {
                    eventosapp_ticket_variants_apply_to_ticket($ticket_id, $eid, true);
                }
                return true;
            });

            $pub = get_post_meta($ticket_id, 'eventosapp_ticketID', true);

            eventosapp_front_edit_debug_log('Ticket actualizado desde frontend', [
                'ticket_id' => $ticket_id,
                'public_id' => $pub,
                'event_id'  => $eid,
                'user_id'   => get_current_user_id(),
            ]);

            return [
                'type'    => 'success',
                'message' => 'Cambios guardados para el Ticket <b>' . esc_html($pub ?: '#' . $ticket_id) . '</b>.',
            ];
        } catch (Throwable $e) {
            eventosapp_front_edit_debug_log('Error guardando ticket desde frontend', [
                'ticket_id' => $ticket_id,
                'event_id'  => $eid,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            return [
                'type'    => 'error',
                'message' => 'No se pudo guardar el ticket. Revisa wp-debug.log para ver el detalle técnico.',
            ];
        } finally {
            $_POST = $original_post;
        }
    }
}

/**
 * Procesa el POST antes de que el tema/Elementor impriman el layout.
 * Esto evita que el HTML del formulario se renderice fuera del contenedor o después del footer.
 */
add_action('template_redirect', function(){
    if ( is_admin() || wp_doing_ajax() ) {
        return;
    }

    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
    if ( $method !== 'POST' ) {
        return;
    }

    $action = isset($_POST['evedit_action']) ? sanitize_key(wp_unslash($_POST['evedit_action'])) : '';
    if ( $action !== 'update_ticket' ) {
        return;
    }

    $posted_event_id = absint($_POST['ed_event_id'] ?? 0);
    $eid = eventosapp_front_edit_resolve_event_id($posted_event_id);
    $result = eventosapp_front_edit_update_ticket_from_post($eid);
    $notice_key = eventosapp_front_edit_store_notice($result['type'], $result['message']);

    $redirect = wp_get_referer();
    if ( ! $redirect ) {
        $redirect = get_permalink();
    }
    if ( ! $redirect ) {
        $redirect = home_url('/');
    }

    $redirect = remove_query_arg(['evfe_notice', 'evedit_action', '_wpnonce', '_wp_http_referer'], $redirect);
    $redirect = add_query_arg(['evfe_notice' => $notice_key], $redirect);

    wp_safe_redirect($redirect);
    exit;
}, 1);

// ———————————————— Shortcode contenedor ————————————————
add_shortcode('eventosapp_front_edit', function($atts){
    if ( function_exists('eventosapp_require_feature') ) {
        eventosapp_require_feature('edit');
    }

    $a = shortcode_atts(['event_id' => 0], $atts);
    $eid = eventosapp_front_edit_resolve_event_id($a['event_id']);

    // Debe haber evento activo.
    if ( ! $eid ) {
        if ( function_exists('eventosapp_require_active_event') ) {
            ob_start();
            eventosapp_require_active_event();
            return ob_get_clean();
        }

        $dash = function_exists('eventosapp_get_dashboard_url')
            ? eventosapp_get_dashboard_url()
            : home_url('/');

        return '<div style="max-width:980px;margin:20px auto;padding:14px 16px;background:#fff8e7;border:1px solid #f3d49b;border-left:4px solid #b45309;border-radius:14px;color:#8a4b08;font-family:inherit;line-height:1.5;">
            Debes escoger un <strong>evento activo</strong> en el <a href="' . esc_url($dash) . '" style="color:#255f96;font-weight:700;">dashboard</a>.
        </div>';
    }

    // Validar permisos sobre el evento.
    if (
        ! current_user_can('manage_options')
        && function_exists('eventosapp_user_can_manage_event')
        && ! eventosapp_user_can_manage_event($eid)
    ) {
        return '<div style="max-width:980px;margin:20px auto;padding:14px 16px;background:#fff1f0;border:1px solid #f2b8b5;border-left:4px solid #b42318;border-radius:14px;color:#8b1e17;font-family:inherit;line-height:1.5;">
            No tienes permisos sobre este evento.
        </div>';
    }

    // Detectar si el evento usa QR preimpreso.
    $use_preprinted_qr = false;
    $flag_meta = get_post_meta($eid, '_eventosapp_ticket_use_preprinted_qr', true);

    if ( $flag_meta !== '' && $flag_meta !== null ) {
        $use_preprinted_qr = (bool) intval($flag_meta);
    } else {
        $flag_opt = get_option('_eventosapp_ticket_use_preprinted_qr', 0);
        $use_preprinted_qr = (bool) intval($flag_opt);
    }

    $use_preprinted_qr = (bool) apply_filters(
        'eventosapp_use_preprinted_qr',
        $use_preprinted_qr,
        $eid
    );

    // Fallback: si template_redirect no capturó el POST, procesarlo aquí.
    $msg = '';
    if (
        isset($_POST['evedit_action'])
        && sanitize_key(wp_unslash($_POST['evedit_action'])) === 'update_ticket'
    ) {
        $fallback_result = eventosapp_front_edit_update_ticket_from_post($eid);
        $msg = eventosapp_front_edit_render_notice($fallback_result);
    } else {
        $msg = eventosapp_front_edit_render_notice(
            eventosapp_front_edit_consume_notice()
        );
    }

    // Localidades del evento.
    $localidades = get_post_meta($eid, '_eventosapp_localidades', true);
    if ( ! is_array($localidades) || empty($localidades) ) {
        $localidades = ['General', 'VIP', 'Platino'];
    }

    // Contexto visual del evento, alineado con Dashboard / Registro Manual / Search.
    $dashboard_url = function_exists('eventosapp_get_dashboard_url')
        ? eventosapp_get_dashboard_url()
        : home_url('/');

    $dashboard_url = remove_query_arg(
        ['evapp', 'evapp_err', 'set'],
        $dashboard_url
    );

    $change_event_url = add_query_arg(
        ['evapp' => 'change_event'],
        $dashboard_url
    );

    $event_name = get_the_title($eid) ?: ('Evento #' . $eid);

    $event_modalidad_label = function_exists('eventosapp_get_event_modalidad_label')
        ? eventosapp_get_event_modalidad_label($eid)
        : '';

    if ( $event_modalidad_label === '' && function_exists('eventosapp_get_event_modalidad') ) {
        $event_modalidad = eventosapp_get_event_modalidad($eid);
        $event_modalidad_label = $event_modalidad === 'virtual'
            ? 'Virtual'
            : ($event_modalidad === 'presencial_virtual' ? 'Presencial y Virtual' : 'Presencial');
    }

    $today_iso = eventosapp_get_today_in_event_tz($eid);
    $today_allowed = eventosapp_is_today_valid_for_event($eid);
    $today_label = $today_iso
        ? date_i18n('D, d M Y', strtotime($today_iso))
        : '';

    // Scripts.
    wp_enqueue_script('jquery');
    wp_register_script('eventosapp-front-edit', false, ['jquery'], null, true);

    wp_localize_script('eventosapp-front-edit', 'EvFrontEdit', [
        'ajax_url'          => admin_url('admin-ajax.php'),
        'search_nonce'      => wp_create_nonce('eventosapp_front_search'),
        'get_nonce'         => wp_create_nonce('eventosapp_front_get_ticket'),
        'mail_nonce'        => wp_create_nonce('eventosapp_front_send_ticket_email'),
        'whatsapp_nonce'    => wp_create_nonce('eventosapp_front_send_ticket_whatsapp'),
        'toggle_nonce'      => wp_create_nonce('eventosapp_toggle_checkin'),
        'event_id'          => $eid,
        'whatsapp_runtime'  => function_exists('eventosapp_whatsapp_send_ticket'),
        'msgs'              => [
            'not_allowed'    => __('El check-in solo está permitido en las fechas del evento. Hoy no corresponde.', 'eventosapp'),
            'net_error'      => __('Error de red. Intenta de nuevo.', 'eventosapp'),
            'search_error'   => __('No fue posible completar la búsqueda. Intenta nuevamente.', 'eventosapp'),
            'load_error'     => __('No fue posible cargar el ticket. Intenta nuevamente.', 'eventosapp'),
            'saving'         => __('Guardando cambios…', 'eventosapp'),
            'sending_email'  => __('Enviando correo…', 'eventosapp'),
            'sending_wa'     => __('Enviando WhatsApp…', 'eventosapp'),
        ],
    ]);

    // CSS: línea gráfica del dashboard moderno, encapsulada en .evfe-app.
    $css = <<<CSS
.evfe-app{
  --evapp-primary:#3279bd;
  --evapp-primary-dark:#255f96;
  --evapp-primary-soft:#eaf4ff;
  --evapp-app-bg:#f5f8fc;
  --evapp-surface:#ffffff;
  --evapp-border:#dfe7f1;
  --evapp-text:#182230;
  --evapp-muted:#64748b;
  --evapp-success:#15803d;
  --evapp-success-soft:#ecfdf3;
  --evapp-danger:#b42318;
  --evapp-danger-soft:#fff1f0;
  --evapp-warning:#b45309;
  --evapp-warning-soft:#fff8e7;
  --evapp-purple:#6d4bc3;
  --evapp-purple-soft:#f3efff;
  --evapp-whatsapp:#158f4a;
  --evapp-radius:18px;
  --evapp-radius-lg:26px;
  width:100%;
  max-width:1180px;
  margin:0 auto;
  color:var(--evapp-text);
  font-family:inherit;
  line-height:1.45;
  box-sizing:border-box;
  isolation:isolate;
}
.evfe-app *,
.evfe-app *::before,
.evfe-app *::after{box-sizing:border-box}
.evfe-app a{text-decoration:none}
.evfe-app svg{
  display:block;
  fill:none;
  stroke:currentColor;
  stroke-width:2;
  stroke-linecap:round;
  stroke-linejoin:round;
}
.evfe-app .screen-reader-text{
  position:absolute!important;
  width:1px!important;
  height:1px!important;
  padding:0!important;
  margin:-1px!important;
  overflow:hidden!important;
  clip:rect(0,0,0,0)!important;
  white-space:nowrap!important;
  border:0!important;
}
.evfe-shell{
  width:100%;
  padding:clamp(18px,3vw,36px);
  background:var(--evapp-app-bg);
  border:1px solid var(--evapp-border);
  border-radius:var(--evapp-radius-lg);
  box-shadow:0 18px 50px rgba(31,52,73,.08);
}
.evfe-header{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:24px;
  margin-bottom:22px;
}
.evfe-heading{min-width:0}
.evfe-eyebrow{
  margin:0 0 6px;
  color:var(--evapp-primary);
  font-size:12px;
  font-weight:800;
  letter-spacing:.11em;
  text-transform:uppercase;
}
.evfe-main-title{
  margin:0;
  color:var(--evapp-text);
  font-size:clamp(27px,4vw,42px);
  font-weight:850;
  line-height:1.08;
  letter-spacing:-.035em;
}
.evfe-subtitle{
  max-width:780px;
  margin:10px 0 0;
  color:var(--evapp-muted);
  font-size:15px;
  line-height:1.6;
}
.evfe-header-actions{flex:0 0 auto}
.evfe-btn{
  min-height:44px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:9px;
  margin:0;
  padding:10px 15px;
  border:1px solid transparent;
  border-radius:12px;
  font:inherit;
  font-size:14px;
  font-weight:750;
  line-height:1.15;
  text-align:center;
  cursor:pointer;
  transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease,background .16s ease,color .16s ease,opacity .16s ease;
  -webkit-tap-highlight-color:transparent;
}
.evfe-btn svg{width:18px;height:18px;flex:0 0 18px}
.evfe-btn:hover:not(:disabled){transform:translateY(-1px)}
.evfe-btn:focus-visible{outline:3px solid rgba(50,121,189,.22);outline-offset:2px}
.evfe-btn:disabled{opacity:.55;cursor:not-allowed;transform:none!important;box-shadow:none!important}
.evfe-btn-secondary{
  color:var(--evapp-text)!important;
  background:var(--evapp-surface);
  border-color:var(--evapp-border);
  box-shadow:0 5px 15px rgba(31,65,99,.05);
  white-space:nowrap;
}
.evfe-btn-secondary:hover{
  color:var(--evapp-primary-dark)!important;
  border-color:#c7d7e8;
  box-shadow:0 8px 20px rgba(31,65,99,.09);
}
.evfe-btn-primary{
  color:#fff!important;
  background:var(--evapp-primary);
  border-color:var(--evapp-primary);
  box-shadow:0 9px 20px rgba(50,121,189,.18);
}
.evfe-btn-primary:hover{
  color:#fff!important;
  background:var(--evapp-primary-dark);
  border-color:var(--evapp-primary-dark);
  box-shadow:0 12px 24px rgba(50,121,189,.24);
}
.evfe-btn-whatsapp{
  color:#fff!important;
  background:var(--evapp-whatsapp);
  border-color:var(--evapp-whatsapp);
  box-shadow:0 9px 20px rgba(21,143,74,.18);
}
.evfe-btn-whatsapp:hover{
  color:#fff!important;
  background:#11783e;
  border-color:#11783e;
  box-shadow:0 12px 24px rgba(21,143,74,.23);
}
.evfe-btn-ghost{
  color:var(--evapp-primary-dark)!important;
  background:#fff;
  border-color:var(--evapp-border);
}
.evfe-btn-ghost:hover{background:var(--evapp-primary-soft);border-color:#c4dbee}
.evfe-event-context{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
  margin-bottom:20px;
  padding:16px 18px;
  background:var(--evapp-surface);
  border:1px solid var(--evapp-border);
  border-radius:var(--evapp-radius);
  box-shadow:0 8px 24px rgba(31,52,73,.045);
}
.evfe-event-main{min-width:0;display:flex;align-items:center;gap:13px}
.evfe-event-icon{
  width:44px;
  height:44px;
  flex:0 0 44px;
  display:grid;
  place-items:center;
  color:var(--evapp-primary);
  background:var(--evapp-primary-soft);
  border-radius:13px;
}
.evfe-event-icon svg{width:22px;height:22px}
.evfe-event-copy{min-width:0}
.evfe-event-kicker{
  display:block;
  margin-bottom:3px;
  color:var(--evapp-muted);
  font-size:11px;
  font-weight:800;
  letter-spacing:.09em;
  text-transform:uppercase;
}
.evfe-event-name{
  display:block;
  overflow:hidden;
  color:var(--evapp-text);
  font-size:15px;
  font-weight:800;
  line-height:1.3;
  text-overflow:ellipsis;
  white-space:nowrap;
}
.evfe-event-meta{display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:8px}
.evfe-chip{
  min-height:30px;
  display:inline-flex;
  align-items:center;
  gap:7px;
  padding:6px 10px;
  border:1px solid var(--evapp-border);
  border-radius:999px;
  background:#fff;
  color:var(--evapp-muted);
  font-size:12px;
  font-weight:750;
  white-space:nowrap;
}
.evfe-chip::before{width:7px;height:7px;border-radius:50%;background:#94a3b8;content:""}
.evfe-chip.is-valid{color:var(--evapp-success);border-color:#cfeadf;background:var(--evapp-success-soft)}
.evfe-chip.is-valid::before{background:var(--evapp-success)}
.evfe-chip.is-warning{color:var(--evapp-warning);border-color:#f1dfad;background:var(--evapp-warning-soft)}
.evfe-chip.is-warning::before{background:#d69e2e}
.evfe-notice{
  display:flex;
  align-items:flex-start;
  gap:11px;
  margin:0 0 18px;
  padding:14px 16px;
  border:1px solid var(--evapp-border);
  border-radius:14px;
  background:#fff;
  font-size:13px;
  line-height:1.5;
}
.evfe-notice-icon{
  width:28px;
  height:28px;
  flex:0 0 28px;
  display:grid;
  place-items:center;
  border-radius:9px;
}
.evfe-notice-icon svg{width:17px;height:17px}
.evfe-notice-copy{min-width:0;padding-top:3px}
.evfe-notice-success{color:#0f5132;border-color:#b7e4c7;background:var(--evapp-success-soft)}
.evfe-notice-success .evfe-notice-icon{color:#fff;background:var(--evapp-success)}
.evfe-notice-error{color:#8b1e17;border-color:#f2b8b5;background:var(--evapp-danger-soft)}
.evfe-notice-error .evfe-notice-icon{color:#fff;background:var(--evapp-danger)}
.evfe-search-card,
.evfe-form-section,
.evfe-editor-head,
.evfe-submit-bar{
  background:var(--evapp-surface);
  border:1px solid var(--evapp-border);
  border-radius:var(--evapp-radius);
  box-shadow:0 8px 26px rgba(31,52,73,.05);
}
.evfe-search-card{padding:18px}
.evfe-card-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  margin-bottom:14px;
}
.evfe-card-title{margin:0;color:var(--evapp-text);font-size:17px;font-weight:800;line-height:1.3}
.evfe-card-desc{margin:5px 0 0;color:var(--evapp-muted);font-size:13px;line-height:1.5}
.evfe-searchbar{
  display:grid;
  grid-template-columns:minmax(190px,245px) minmax(0,1fr);
  gap:10px;
  align-items:center;
}
.evfe-select,
.evfe-input,
.evfe-control{
  width:100%;
  min-height:48px;
  margin:0;
  padding:10px 13px;
  color:var(--evapp-text);
  background:#fff;
  border:1px solid var(--evapp-border);
  border-radius:13px;
  box-shadow:none;
  font:inherit;
  font-size:15px;
  outline:none;
  transition:border-color .16s ease,box-shadow .16s ease,background .16s ease;
}
.evfe-select{padding-right:38px}
.evfe-input-wrap{position:relative;min-width:0}
.evfe-input{padding-left:46px;padding-right:46px}
.evfe-app input.evfe-input[type="search"]{
  padding:10px 46px!important;
  -webkit-appearance:none;
  appearance:none;
}
.evfe-app input.evfe-input[type="search"]::-webkit-search-decoration,
.evfe-app input.evfe-input[type="search"]::-webkit-search-cancel-button,
.evfe-app input.evfe-input[type="search"]::-webkit-search-results-button,
.evfe-app input.evfe-input[type="search"]::-webkit-search-results-decoration{
  -webkit-appearance:none;
  appearance:none;
}
.evfe-select:focus,
.evfe-input:focus,
.evfe-control:focus{
  border-color:var(--evapp-primary);
  box-shadow:0 0 0 4px rgba(50,121,189,.12);
}
.evfe-search-icon{
  position:absolute;
  z-index:2;
  top:50%;
  left:15px;
  width:20px;
  height:20px;
  color:var(--evapp-muted);
  transform:translateY(-50%);
  pointer-events:none;
}
.evfe-search-icon svg{width:20px;height:20px}
.evfe-clear{
  position:absolute;
  z-index:3;
  top:50%;
  right:9px;
  width:32px;
  height:32px;
  display:none;
  align-items:center;
  justify-content:center;
  padding:0;
  border:0;
  border-radius:9px;
  color:var(--evapp-muted);
  background:transparent;
  font:inherit;
  font-size:20px;
  line-height:1;
  cursor:pointer;
  transform:translateY(-50%);
}
.evfe-clear.is-visible{display:flex}
.evfe-clear:hover{color:var(--evapp-text);background:var(--evapp-primary-soft)}
.evfe-search-foot{
  display:flex;
  justify-content:space-between;
  gap:14px;
  margin-top:10px;
  color:var(--evapp-muted);
  font-size:12px;
  line-height:1.45;
}
.evfe-result-count{font-weight:750;white-space:nowrap}
.evfe-results{display:grid;gap:10px;margin-top:14px}
.evfe-state{
  display:flex;
  align-items:center;
  gap:12px;
  min-height:76px;
  padding:14px 16px;
  border:1px dashed var(--evapp-border);
  border-radius:15px;
  background:rgba(255,255,255,.68);
}
.evfe-state-icon{
  width:38px;
  height:38px;
  flex:0 0 38px;
  display:grid;
  place-items:center;
  color:var(--evapp-primary);
  background:var(--evapp-primary-soft);
  border-radius:12px;
}
.evfe-state-icon svg{width:20px;height:20px}
.evfe-state-copy{min-width:0}
.evfe-state strong{display:block;color:var(--evapp-text);font-size:13px;font-weight:800}
.evfe-state span{display:block;margin-top:2px;color:var(--evapp-muted);font-size:12px;line-height:1.45}
.evfe-state-error{border-style:solid;border-color:#f2b8b5;background:var(--evapp-danger-soft)}
.evfe-state-error .evfe-state-icon{color:var(--evapp-danger);background:#ffe0dd}
.evfe-spinner{
  width:22px;
  height:22px;
  flex:0 0 22px;
  border:2px solid rgba(50,121,189,.22);
  border-top-color:var(--evapp-primary);
  border-radius:50%;
  animation:evfeSpin .75s linear infinite;
}
@keyframes evfeSpin{to{transform:rotate(360deg)}}
.evfe-row{
  display:grid;
  grid-template-columns:auto minmax(0,1fr) auto;
  align-items:center;
  gap:14px;
  padding:15px;
  border:1px solid var(--evapp-border);
  border-radius:16px;
  background:#fff;
  box-shadow:0 6px 18px rgba(31,52,73,.035);
  transition:border-color .16s ease,box-shadow .16s ease,transform .16s ease;
}
.evfe-row:hover{
  border-color:#c5d8ea;
  box-shadow:0 10px 24px rgba(31,73,112,.08);
  transform:translateY(-1px);
}
.evfe-avatar{
  width:46px;
  height:46px;
  display:grid;
  place-items:center;
  color:var(--evapp-primary-dark);
  background:var(--evapp-primary-soft);
  border-radius:14px;
  font-size:14px;
  font-weight:850;
  letter-spacing:.03em;
}
.evfe-data{min-width:0}
.evfe-person-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.evfe-person-name{margin:0;color:var(--evapp-text);font-size:15px;font-weight:820;line-height:1.3}
.evfe-mode-badge{
  display:inline-flex;
  align-items:center;
  min-height:24px;
  padding:4px 8px;
  border:1px solid #cfe3f6;
  border-radius:999px;
  color:var(--evapp-primary-dark);
  background:var(--evapp-primary-soft);
  font-size:10px;
  font-weight:800;
  text-transform:uppercase;
  letter-spacing:.04em;
}
.evfe-mode-badge.is-virtual{color:#6547aa;background:var(--evapp-purple-soft);border-color:#ded4f6}
.evfe-person-meta{
  display:flex;
  flex-wrap:wrap;
  gap:6px 12px;
  margin-top:5px;
  color:var(--evapp-muted);
  font-size:12px;
  line-height:1.45;
}
.evfe-person-meta span{min-width:0;overflow-wrap:anywhere}
.evfe-result-actions{display:flex;align-items:center}
.evfe-edit-btn{min-width:110px}
.evfe-editor{
  display:none;
  margin-top:18px;
}
.evfe-editor-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:18px;
  margin-bottom:14px;
  padding:17px 18px;
}
.evfe-editor-person{min-width:0;display:flex;align-items:center;gap:13px}
.evfe-editor-icon{
  width:44px;
  height:44px;
  flex:0 0 44px;
  display:grid;
  place-items:center;
  color:#fff;
  background:linear-gradient(145deg,var(--evapp-primary),var(--evapp-primary-dark));
  border-radius:14px;
  box-shadow:0 8px 18px rgba(47,115,181,.20);
}
.evfe-editor-icon svg{width:22px;height:22px}
.evfe-editor-kicker{
  display:block;
  margin-bottom:3px;
  color:var(--evapp-muted);
  font-size:11px;
  font-weight:800;
  letter-spacing:.07em;
  text-transform:uppercase;
}
.evfe-editor-name{
  margin:0;
  color:var(--evapp-text);
  font-size:17px;
  font-weight:850;
  line-height:1.25;
}
.evfe-editor-meta{
  margin-top:4px;
  color:var(--evapp-muted);
  font-size:12px;
  overflow-wrap:anywhere;
}
.evfe-form{
  display:grid;
  gap:14px;
}
.evfe-form-section{padding:20px}
.evfe-section-heading{
  display:flex;
  align-items:flex-start;
  gap:12px;
  margin-bottom:16px;
}
.evfe-section-icon{
  width:40px;
  height:40px;
  flex:0 0 40px;
  display:grid;
  place-items:center;
  color:var(--evapp-primary);
  background:var(--evapp-primary-soft);
  border-radius:12px;
}
.evfe-section-icon svg{width:20px;height:20px}
.evfe-section-title{margin:0;color:var(--evapp-text);font-size:16px;font-weight:820;line-height:1.3}
.evfe-section-desc{margin:4px 0 0;color:var(--evapp-muted);font-size:12px;line-height:1.5}
.evfe-grid{
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:14px;
}
.evfe-field{min-width:0}
.evfe-field--wide{grid-column:span 2}
.evfe-label,
.evfe-field>label{
  display:block;
  margin:0 0 6px;
  color:var(--evapp-text);
  font-size:12px;
  font-weight:800;
}
.evfe-help{
  display:block;
  margin-top:6px;
  color:var(--evapp-muted);
  font-size:11px;
  line-height:1.45;
}
.evfe-required{color:var(--evapp-danger)}
.evfe-access-top{
  display:grid;
  grid-template-columns:minmax(0,1fr) minmax(0,1fr);
  gap:14px;
  margin-bottom:14px;
}
.evfe-modalidad-box{
  display:none;
  padding:14px;
  border:1px solid #cfe3f6;
  border-radius:14px;
  background:var(--evapp-primary-soft);
}
.evfe-modalidad-box .evfe-label{color:var(--evapp-primary-dark)}
.evfe-modalidad-box .evfe-control{background:#fff}
.evfe-virtual-link{
  min-height:40px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:7px;
  margin-top:9px;
  padding:8px 12px;
  color:#fff!important;
  background:var(--evapp-purple);
  border-radius:11px;
  font-size:12px;
  font-weight:800;
}
.evfe-virtual-link:hover{color:#fff!important;background:#583aa8}
.evfe-checkin{
  display:none;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
  padding:14px;
  border:1px solid var(--evapp-border);
  border-radius:14px;
  background:#fbfdff;
}
.evfe-badge{
  min-height:28px;
  display:inline-flex;
  align-items:center;
  gap:7px;
  padding:6px 10px;
  border-radius:999px;
  font-size:11px;
  font-weight:800;
}
.evfe-badge::before{width:7px;height:7px;border-radius:50%;content:""}
.evfe-badge-ok{color:var(--evapp-success);background:var(--evapp-success-soft);border:1px solid #cfeadf}
.evfe-badge-ok::before{background:var(--evapp-success)}
.evfe-badge-no{color:var(--evapp-danger);background:var(--evapp-danger-soft);border:1px solid #f2b8b5}
.evfe-badge-no::before{background:var(--evapp-danger)}
.evfe-toggle{min-height:38px;padding:8px 11px;font-size:12px}
.evfe-checkin-note{color:var(--evapp-muted);font-size:11px;line-height:1.45}
.evfe-inline-status{
  display:inline-flex;
  align-items:center;
  gap:6px;
  font-size:11px;
  font-weight:750;
}
.evfe-inline-status.is-success{color:var(--evapp-success)}
.evfe-inline-status.is-error{color:var(--evapp-danger)}
.evfe-extras[hidden],
.evfe-sessions[hidden]{display:none!important}
.evfe-dynamic-grid{
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:14px;
}
.evfe-sessions-list{
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:10px;
}
.evfe-check-option{
  display:flex!important;
  align-items:flex-start;
  gap:9px;
  min-height:44px;
  margin:0!important;
  padding:10px 11px;
  border:1px solid var(--evapp-border);
  border-radius:12px;
  background:#fbfdff;
  color:var(--evapp-text)!important;
  font-size:12px!important;
  font-weight:700!important;
  line-height:1.35;
  cursor:pointer;
}
.evfe-check-option input[type="checkbox"]{
  width:17px;
  height:17px;
  flex:0 0 17px;
  margin:1px 0 0;
  accent-color:var(--evapp-primary);
}
.evfe-delivery-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:14px;
}
.evfe-delivery-card{
  display:flex;
  flex-direction:column;
  min-width:0;
  padding:16px;
  border:1px solid var(--evapp-border);
  border-radius:15px;
  background:#fbfdff;
}
.evfe-delivery-head{
  display:flex;
  align-items:center;
  gap:10px;
  margin-bottom:10px;
}
.evfe-delivery-icon{
  width:38px;
  height:38px;
  flex:0 0 38px;
  display:grid;
  place-items:center;
  color:var(--evapp-primary);
  background:var(--evapp-primary-soft);
  border-radius:12px;
}
.evfe-delivery-icon.is-whatsapp{color:var(--evapp-whatsapp);background:#eaf8ef}
.evfe-delivery-icon svg{width:19px;height:19px}
.evfe-delivery-title{margin:0;color:var(--evapp-text);font-size:14px;font-weight:820}
.evfe-delivery-dest{
  margin:2px 0 0;
  color:var(--evapp-muted);
  font-size:11px;
  overflow-wrap:anywhere;
}
.evfe-delivery-card .evfe-control{min-height:44px;font-size:13px}
.evfe-delivery-actions{
  display:flex;
  align-items:center;
  gap:10px;
  margin-top:auto;
  padding-top:12px;
}
.evfe-delivery-actions .evfe-btn{flex:0 0 auto}
.evfe-delivery-note{
  min-width:0;
  color:var(--evapp-muted);
  font-size:11px;
  line-height:1.4;
  overflow-wrap:anywhere;
}
.evfe-delivery-note.is-success{color:var(--evapp-success);font-weight:750}
.evfe-delivery-note.is-error{color:var(--evapp-danger);font-weight:750}
.evfe-submit-bar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:18px;
  padding:18px 20px;
}
.evfe-submit-copy{display:grid;gap:2px;min-width:0}
.evfe-submit-copy strong{color:var(--evapp-text);font-size:14px;font-weight:800}
.evfe-submit-copy span{color:var(--evapp-muted);font-size:12px;line-height:1.45}
.evfe-submit{min-width:170px}
.evfe-form-highlight{box-shadow:0 0 0 4px rgba(50,121,189,.12),0 8px 26px rgba(31,52,73,.05)!important}
.evfe-live-region:empty{display:none}
.evfe-live-region{
  margin-bottom:14px;
  padding:12px 14px;
  border:1px solid var(--evapp-border);
  border-radius:13px;
  background:#fff;
  color:var(--evapp-muted);
  font-size:12px;
}
.evfe-live-region.is-success{color:#0f5132;border-color:#b7e4c7;background:var(--evapp-success-soft)}
.evfe-live-region.is-error{color:#8b1e17;border-color:#f2b8b5;background:var(--evapp-danger-soft)}
@media(max-width:900px){
  .evfe-grid,
  .evfe-dynamic-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .evfe-sessions-list{grid-template-columns:repeat(2,minmax(0,1fr))}
  .evfe-field--wide{grid-column:auto}
}
@media(max-width:767px){
  .evfe-shell{padding:16px;border-radius:20px}
  .evfe-header{display:block;margin-bottom:18px}
  .evfe-header-actions{margin-top:14px}
  .evfe-header-actions .evfe-btn{width:100%}
  .evfe-event-context{align-items:flex-start;flex-direction:column;padding:14px}
  .evfe-event-main{width:100%}
  .evfe-event-meta{width:100%;justify-content:flex-start}
  .evfe-event-context>.evfe-btn{width:100%}
  .evfe-searchbar{grid-template-columns:1fr}
  .evfe-search-foot{align-items:flex-start;flex-direction:column;gap:4px}
  .evfe-row{grid-template-columns:auto minmax(0,1fr);align-items:start}
  .evfe-result-actions{grid-column:1/-1}
  .evfe-result-actions .evfe-btn{width:100%}
  .evfe-editor-head{align-items:flex-start;flex-direction:column}
  .evfe-access-top{grid-template-columns:1fr}
  .evfe-delivery-grid{grid-template-columns:1fr}
}
@media(max-width:620px){
  .evfe-main-title{font-size:clamp(28px,9vw,36px)}
  .evfe-search-card,
  .evfe-form-section{padding:16px}
  .evfe-grid,
  .evfe-dynamic-grid,
  .evfe-sessions-list{grid-template-columns:1fr}
  .evfe-avatar{display:none}
  .evfe-row{grid-template-columns:1fr;padding:14px}
  .evfe-editor-person{align-items:flex-start}
  .evfe-submit-bar{display:grid}
  .evfe-submit{width:100%;min-width:0}
  .evfe-delivery-actions{align-items:stretch;flex-direction:column}
  .evfe-delivery-actions .evfe-btn{width:100%}
}
@media(max-width:430px){
  .evfe-chip{white-space:normal}
  .evfe-event-meta{align-items:stretch;flex-direction:column}
  .evfe-event-meta .evfe-btn{width:100%}
}
@media(prefers-reduced-motion:reduce){
  .evfe-app *,
  .evfe-app *::before,
  .evfe-app *::after{
    scroll-behavior:auto!important;
    transition-duration:.01ms!important;
    animation-duration:.01ms!important;
    animation-iteration-count:1!important;
  }
}
CSS;

    wp_register_style('eventosapp-front-edit', false, [], null);
    wp_add_inline_style('eventosapp-front-edit', $css);
    wp_enqueue_style('eventosapp-front-edit');

    // JS: búsqueda optimizada, estados de carga, edición y reenvíos.
    $js = <<<'JS'
jQuery(function($){
  var $root = $('#evfe-wrap'),
      $type = $('#evfe-search-type'),
      $in = $('#evfe-input'),
      $out = $('#evfe-results'),
      $clear = $('#evfe-clear'),
      $count = $('#evfe-result-count'),
      $editor = $('#evfe-editor'),
      $form = $('#evfe-form'),
      $live = $('#evfe-live-region'),
      eventId = EvFrontEdit.event_id,
      searchTimer = null,
      pendingSearch = null,
      pendingTicket = null,
      searchSeq = 0,
      ticketSeq = 0,
      lastSearchKey = '',
      loadedTicket = null;

  if(!$root.length) return;

  function escHtml(value){
    if(value === null || typeof value === 'undefined') return '';
    return $('<div>').text(String(value)).html();
  }

  function escAttr(value){
    return escHtml(value).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function icon(name){
    if(name === 'search') return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>';
    if(name === 'edit') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l11-11a2.8 2.8 0 0 0-4-4L4 16v4Z"></path><path d="m13.5 6.5 4 4"></path></svg>';
    if(name === 'alert') return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 17h.01"></path></svg>';
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>';
  }

  function prefersReducedMotion(){
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function smoothScrollTo($el){
    if(!$el || !$el.length) return;
    var adminBar = $('#wpadminbar').length ? $('#wpadminbar').outerHeight() : 0;
    var top = Math.max(0, $el.offset().top - adminBar - 14);
    try {
      window.scrollTo({top: top, behavior: prefersReducedMotion() ? 'auto' : 'smooth'});
    } catch(e) {
      window.scrollTo(0, top);
    }
  }

  function setLive(message, kind){
    message = message || '';
    $live.removeClass('is-success is-error').text(message);
    if(!message) return;
    if(kind === 'success') $live.addClass('is-success');
    if(kind === 'error') $live.addClass('is-error');
  }

  function searchPlaceholder(type){
    var labels = {
      name: 'Buscar por nombres y apellidos…',
      cc: 'Buscar por cédula…',
      phone: 'Buscar por celular…',
      email: 'Buscar por correo electrónico…',
      all: 'Buscar en todos los datos…'
    };
    return labels[type] || labels.cc;
  }

  function getSearchType(){
    return ($type.val() || 'cc').toString();
  }

  function getMinLen(type){
    return (type === 'all' || type === 'name' || type === 'email') ? 3 : 2;
  }

  function updateSearchPlaceholder(){
    $in.attr('placeholder', searchPlaceholder(getSearchType()));
  }

  function updateClear(){
    $clear.toggleClass('is-visible', $.trim($in.val()).length > 0);
  }

  function updateCount(value){
    if(!value){
      $count.text('');
      return;
    }
    $count.text(value === 1 ? '1 resultado' : value + ' resultados');
  }

  function stateHtml(kind, title, text){
    var visual = kind === 'loading'
      ? '<div class="evfe-spinner" aria-hidden="true"></div>'
      : '<div class="evfe-state-icon" aria-hidden="true">' + icon(kind === 'error' ? 'alert' : 'search') + '</div>';

    return '<div class="evfe-state evfe-state-' + escAttr(kind) + '">' +
      visual +
      '<div class="evfe-state-copy">' +
        '<strong>' + escHtml(title) + '</strong>' +
        '<span>' + escHtml(text) + '</span>' +
      '</div>' +
    '</div>';
  }

  function showInitial(){
    $out.attr('aria-busy', 'false');
    updateCount(0);
    $out.html(stateHtml(
      'initial',
      'Busca el ticket que deseas editar',
      'Selecciona el tipo de búsqueda y escribe el dato del asistente. Los resultados aparecerán automáticamente.'
    ));
  }

  function showMinChars(minLen){
    $out.attr('aria-busy', 'false');
    updateCount(0);
    $out.html(stateHtml(
      'hint',
      'Continúa escribiendo',
      'Ingresa al menos ' + minLen + ' caracteres para iniciar la búsqueda.'
    ));
  }

  function showLoading(){
    $out.attr('aria-busy', 'true');
    updateCount(0);
    $out.html(stateHtml(
      'loading',
      'Buscando tickets…',
      'Estamos consultando los asistentes del evento activo.'
    ));
  }

  function showSearchError(){
    $out.attr('aria-busy', 'false');
    updateCount(0);
    $out.html(stateHtml(
      'error',
      'No pudimos completar la búsqueda',
      EvFrontEdit.msgs.search_error || EvFrontEdit.msgs.net_error
    ));
  }

  function optionHtml(value, label, selected){
    return '<option value="' + escAttr(value) + '"' + (selected ? ' selected' : '') + '>' + escHtml(label) + '</option>';
  }

  function setSelectValue($select, value, fallback){
    var val = (value || fallback || '').toString();

    if(
      val
      && !$select.find('option').filter(function(){ return $(this).val() === val; }).length
    ){
      $select.append(optionHtml(val, val, false));
    }

    $select.val(val);
  }

  function initials(firstName, lastName){
    var first = $.trim(firstName || '');
    var last = $.trim(lastName || '');
    var out = '';
    if(first) out += first.charAt(0);
    if(last) out += last.charAt(0);
    return (out || 'AS').toUpperCase();
  }

  function render(rows){
    $out.attr('aria-busy', 'false');
    rows = $.isArray(rows) ? rows : [];
    updateCount(rows.length);

    if(!rows.length){
      $out.html(stateHtml(
        'empty',
        'No encontramos coincidencias',
        'Revisa el dato ingresado o prueba otro tipo de búsqueda.'
      ));
      return;
    }

    var html = '';

    $.each(rows, function(i, it){
      it = it || {};

      var isVirtual = it.is_virtual === true || it.modalidad === 'virtual';
      var fullName = $.trim((it.first_name || '') + ' ' + (it.last_name || '')) || 'Asistente sin nombre';
      var modality = it.modalidad_label || (isVirtual ? 'Virtual' : 'Presencial');
      var contact = [];

      if(it.cc) contact.push('CC: ' + it.cc);
      if(it.email) contact.push(it.email);
      if(it.phone) contact.push(it.phone);
      if(it.localidad) contact.push('Localidad: ' + it.localidad);

      html += '<article class="evfe-row" data-ticket-id="' + escAttr(it.ticket_id || '') + '">' +
        '<div class="evfe-avatar" aria-hidden="true">' + escHtml(initials(it.first_name, it.last_name)) + '</div>' +
        '<div class="evfe-data">' +
          '<div class="evfe-person-head">' +
            '<h3 class="evfe-person-name">' + escHtml(fullName) + '</h3>' +
            '<span class="evfe-mode-badge' + (isVirtual ? ' is-virtual' : '') + '">' + escHtml(modality) + '</span>' +
          '</div>' +
          '<div class="evfe-person-meta">' +
            '<span>Ticket: ' + escHtml(it.ticket_pub || ('#' + (it.ticket_id || '—'))) + '</span>' +
            (contact.length ? '<span>' + escHtml(contact.join(' · ')) + '</span>' : '') +
          '</div>' +
        '</div>' +
        '<div class="evfe-result-actions">' +
          '<button type="button" class="evfe-btn evfe-btn-primary evfe-edit-btn" data-ticket-id="' + escAttr(it.ticket_id || '') + '">' +
            icon('edit') + '<span>Editar ticket</span>' +
          '</button>' +
        '</div>' +
      '</article>';
    });

    $out.html(html);
  }

  function runSearch(immediate){
    clearTimeout(searchTimer);
    updateClear();

    var q = $.trim($in.val());
    var searchType = getSearchType();
    var minLen = getMinLen(searchType);

    if(pendingSearch){
      pendingSearch.abort();
      pendingSearch = null;
    }

    if(!q){
      lastSearchKey = '';
      showInitial();
      return;
    }

    if(q.length < minLen){
      lastSearchKey = '';
      showMinChars(minLen);
      return;
    }

    var key = searchType + '|' + q.toLowerCase();
    if(key === lastSearchKey && !immediate){
      return;
    }

    var delay = immediate ? 0 : 280;

    searchTimer = setTimeout(function(){
      var seq = ++searchSeq;
      showLoading();

      pendingSearch = $.getJSON(EvFrontEdit.ajax_url, {
        action: 'eventosapp_front_search',
        security: EvFrontEdit.search_nonce,
        q: q,
        search_type: searchType,
        event_id: eventId
      }).done(function(resp){
        if(seq !== searchSeq) return;

        if(resp && resp.success){
          lastSearchKey = key;
          render(resp.data || []);
        } else {
          lastSearchKey = '';
          showSearchError();
        }
      }).fail(function(xhr, status){
        if(seq !== searchSeq || status === 'abort') return;
        lastSearchKey = '';
        showSearchError();
      }).always(function(){
        if(seq === searchSeq) pendingSearch = null;
      });
    }, delay);
  }

  $in.on('input', function(){ runSearch(false); });

  $in.on('keydown', function(e){
    if(e.key === 'Enter'){
      e.preventDefault();
      lastSearchKey = '';
      runSearch(true);
    }

    if(e.key === 'Escape' && $in.val()){
      e.preventDefault();
      $in.val('');
      lastSearchKey = '';
      runSearch(true);
      $in.trigger('focus');
    }
  });

  $type.on('change', function(){
    updateSearchPlaceholder();
    lastSearchKey = '';
    runSearch(true);
  });

  $clear.on('click', function(){
    $in.val('').trigger('focus');
    lastSearchKey = '';
    runSearch(true);
  });

  updateSearchPlaceholder();
  updateClear();
  showInitial();

  function setBadge(status){
    var $badge = $('#evfe-checkin-badge');
    var $button = $('#evfe-toggle-checkin');
    var checked = status === 'checked_in';

    $badge
      .toggleClass('evfe-badge-ok', checked)
      .toggleClass('evfe-badge-no', !checked)
      .text(checked ? 'Check-in realizado' : 'Sin check-in');

    $button.find('.evfe-toggle-label').text(
      checked ? 'Deshacer check-in' : 'Marcar check-in'
    );
  }

  function syncCheckin(d){
    d = d || {};
    var isVirtual = $('#ed_modalidad').val() === 'virtual' || d.is_virtual === true;

    if(isVirtual){
      $('#evfe-checkin-wrap').hide();
      return;
    }

    if(typeof d.today_allowed === 'undefined'){
      $('#evfe-checkin-wrap').hide();
      return;
    }

    setBadge(d.today_status || 'not_checked_in');

    var $btn = $('#evfe-toggle-checkin');
    $btn.prop('disabled', !d.today_allowed);

    $('#evfe-checkin-note').text(
      d.today_allowed ? '' : EvFrontEdit.msgs.not_allowed
    );

    $('#evfe-checkin-wrap').css('display', 'flex');
  }

  function renderExtras(schema, vals){
    var $extras = $('#evfe-extras');
    var $list = $('#evfe-extras-list').empty();

    schema = $.isArray(schema) ? schema : [];
    vals = vals || {};

    if(!schema.length){
      $extras.prop('hidden', true);
      return;
    }

    schema.forEach(function(f){
      f = f || {};

      var key = (typeof f.key !== 'undefined') ? String(f.key) : '';
      if(!key) return;

      var label = f.label || key;
      var req = !!f.required;
      var val = (vals && typeof vals[key] !== 'undefined') ? vals[key] : '';
      var name = 'ed_extra[' + key + ']';
      var field = '<div class="evfe-field">' +
        '<label>' + escHtml(label) + (req ? ' <span class="evfe-required">*</span>' : '') + '</label>';

      if(f.type === 'number'){
        field += '<input type="number" name="' + escAttr(name) + '" value="' + escAttr(val || '') + '" class="evfe-control">';
      } else if(f.type === 'select'){
        field += '<select name="' + escAttr(name) + '" class="evfe-control">';
        field += optionHtml('', 'Seleccione…', !val);

        ($.isArray(f.options) ? f.options : []).forEach(function(op){
          field += optionHtml(op, op, String(op) === String(val));
        });

        field += '</select>';
      } else {
        field += '<input type="text" name="' + escAttr(name) + '" value="' + escAttr(val || '') + '" class="evfe-control">';
      }

      field += '</div>';
      $list.append(field);
    });

    $extras.prop('hidden', !$list.children().length);
  }

  function renderLocalidades(localidades, current){
    var $sel = $('#ed_localidad');
    current = current || '';

    if($.isArray(localidades) && localidades.length){
      $sel.empty().append(optionHtml('', 'Seleccione…', current === ''));

      localidades.forEach(function(localidad){
        $sel.append(
          optionHtml(
            localidad,
            localidad,
            String(localidad) === String(current)
          )
        );
      });
    }

    setSelectValue($sel, current, '');
  }

  function renderModalidad(d){
    d = d || {};

    var $wrap = $('#evfe-modalidad-wrap');
    var $sel = $('#ed_modalidad');
    var labels = d.modalidad_labels || {
      presencial: 'Presencial',
      virtual: 'Virtual'
    };
    var allowed = $.isArray(d.allowed_modalidades) && d.allowed_modalidades.length
      ? d.allowed_modalidades
      : ['presencial'];
    var current = d.modalidad || allowed[0] || 'presencial';

    $sel.empty();

    allowed.forEach(function(modality){
      $sel.append(
        optionHtml(
          modality,
          labels[modality] || modality,
          String(modality) === String(current)
        )
      );
    });

    setSelectValue($sel, current, allowed[0] || 'presencial');

    $('#evfe-modalidad-help').text(
      d.modalidad_help
      || (
        allowed.length <= 1
          ? 'La modalidad está fijada por la configuración del evento.'
          : 'Este evento permite cambiar el ticket entre Presencial y Virtual.'
      )
    );

    $wrap.show();

    var isVirtual = current === 'virtual';

    $('#evfe-preprinted-wrap').toggle(!isVirtual);

    if(isVirtual){
      $('#ed_preprinted_qr_id').val('');
    }

    var $access = $('#evfe-virtual-access').empty();

    if(isVirtual){
      if(d.virtual_url){
        $access
          .html(
            '<a class="evfe-virtual-link" href="' + escAttr(d.virtual_url) + '" target="_blank" rel="noopener noreferrer">' +
              '<span>Abrir acceso virtual</span>' +
            '</a>'
          )
          .show();
      } else {
        $access
          .html('<span class="evfe-help">El enlace de acceso virtual se generará cuando el ticket tenga ID público.</span>')
          .show();
      }
    } else {
      $access.hide();
    }
  }

  $('#ed_modalidad').on('change', function(){
    var isVirtual = $(this).val() === 'virtual';

    $('#evfe-preprinted-wrap').toggle(!isVirtual);

    if(isVirtual){
      $('#ed_preprinted_qr_id').val('');
    }

    if(loadedTicket){
      loadedTicket.is_virtual = isVirtual;
      syncCheckin(loadedTicket);
    }
  });

  function renderSesiones(sesiones, sesionesAcceso){
    var $section = $('#evfe-sesiones');
    var $list = $('#evfe-sesiones-list').empty();

    sesiones = $.isArray(sesiones) ? sesiones : [];
    sesionesAcceso = $.isArray(sesionesAcceso) ? sesionesAcceso : [];

    if(!sesiones.length){
      $section.prop('hidden', true);
      return;
    }

    sesiones.forEach(function(sessionName){
      var checked = sesionesAcceso.indexOf(sessionName) >= 0;

      $list.append(
        '<label class="evfe-check-option">' +
          '<input type="checkbox" name="ed_sesiones[]" value="' + escAttr(sessionName) + '"' + (checked ? ' checked' : '') + '>' +
          '<span>' + escHtml(sessionName) + '</span>' +
        '</label>'
      );
    });

    $section.prop('hidden', false);
  }

  function syncDeliveryDestinations(d){
    d = d || {};
    $('#evfe-email-dest').text(
      d.email
        ? 'Correo guardado: ' + d.email
        : 'El ticket no tiene correo guardado.'
    );
    $('#evfe-whatsapp-dest').text(
      d.tel
        ? 'WhatsApp guardado: ' + d.tel
        : 'El ticket no tiene teléfono guardado.'
    );
  }

  function setEditorSummary(d){
    var fullName = $.trim((d.nombre || '') + ' ' + (d.apellido || '')) || 'Asistente sin nombre';
    var publicId = d.ticket_pub || ('#' + (d.ticket_id || '—'));
    var modality = d.modalidad_label || (d.is_virtual ? 'Virtual' : 'Presencial');

    $('#evfe-selected-name').text(fullName);
    $('#evfe-selected-meta').text('Ticket ' + publicId + ' · ' + modality);
  }

  $(document).on('click', '.evfe-edit-btn', function(e){
    e.preventDefault();

    var $button = $(this);
    var tid = $button.data('ticket-id');

    if(!tid){
      setLive('Ticket inválido.', 'error');
      return;
    }

    if(pendingTicket){
      pendingTicket.abort();
      pendingTicket = null;
    }

    var seq = ++ticketSeq;
    var originalHtml = $button.html();

    $('.evfe-edit-btn').prop('disabled', true);
    $button.html('<span class="evfe-spinner" aria-hidden="true"></span><span>Cargando…</span>');
    setLive('');

    pendingTicket = $.getJSON(EvFrontEdit.ajax_url, {
      action: 'eventosapp_front_get_ticket',
      security: EvFrontEdit.get_nonce,
      ticket_id: tid,
      event_id: eventId
    }).done(function(resp){
      if(seq !== ticketSeq) return;

      if(!resp || !resp.success || !resp.data){
        setLive(
          (resp && resp.data && resp.data.message)
            ? resp.data.message
            : EvFrontEdit.msgs.load_error,
          'error'
        );
        return;
      }

      var d = resp.data || {};
      loadedTicket = $.extend({}, d);

      $('#ed_ticket_id').val(d.ticket_id || '');
      $('#ed_nombre').val(d.nombre || '');
      $('#ed_apellido').val(d.apellido || '');
      $('#ed_cc').val(d.cc || '');
      $('#ed_email').val(d.email || '');
      $('#ed_tel').val(d.tel || '');
      $('#ed_empresa').val(d.empresa || '');
      $('#ed_nit').val(d.nit || '');
      $('#ed_cargo').val(d.cargo || '');
      $('#ed_ciudad').val(d.ciudad || '');
      setSelectValue($('#ed_pais'), d.pais || 'Colombia', 'Colombia');

      renderExtras(d.extras_schema, d.extras_values);
      renderLocalidades(d.localidades, d.localidad || '');
      renderModalidad(d);

      $('#ed_preprinted_qr_id').val(d.preprinted || '');

      renderSesiones(d.sesiones, d.sesiones_acceso);
      syncCheckin(d);
      syncDeliveryDestinations(d);
      setEditorSummary(d);

      $('#evfe_email_alt').val('');
      $('#evfe_mail_note, #evfe_whatsapp_note').removeClass('is-success is-error').text('');

      if(prefersReducedMotion()){
        $editor.show();
      } else {
        $editor.stop(true, true).slideDown(150);
      }

      $editor.addClass('evfe-form-highlight');
      setTimeout(function(){ $editor.removeClass('evfe-form-highlight'); }, 900);

      smoothScrollTo($editor);

      setTimeout(function(){
        $('#ed_nombre').trigger('focus');
      }, prefersReducedMotion() ? 0 : 170);
    }).fail(function(xhr, status){
      if(seq !== ticketSeq || status === 'abort') return;

      var message = EvFrontEdit.msgs.load_error || EvFrontEdit.msgs.net_error;

      try {
        if(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
          message = xhr.responseJSON.data.message;
        }
      } catch(error){}

      setLive(message, 'error');
    }).always(function(){
      if(seq === ticketSeq) pendingTicket = null;
      $('.evfe-edit-btn').prop('disabled', false);
      $button.html(originalHtml);
    });
  });

  $form.on('submit', function(){
    var $btn = $form.find('.evfe-submit');

    if($btn.data('evfe-submitting')){
      return false;
    }

    if(typeof this.checkValidity === 'function' && !this.checkValidity()){
      if(typeof this.reportValidity === 'function'){
        this.reportValidity();
      }

      var $invalid = $form.find(':invalid').first();
      if($invalid.length){
        smoothScrollTo($invalid.closest('.evfe-field'));
      }

      return false;
    }

    $btn
      .data('evfe-submitting', true)
      .prop('disabled', true)
      .find('.evfe-submit-label')
      .text(EvFrontEdit.msgs.saving || 'Guardando cambios…');

    return true;
  });

  $('#evfe-toggle-checkin').on('click', function(){
    var tid = $('#ed_ticket_id').val();
    var $button = $(this);

    if(!tid){
      setLive('Primero carga un ticket.', 'error');
      return;
    }

    if($button.prop('disabled')) return;

    var wasChecked = $('#evfe-checkin-badge').hasClass('evfe-badge-ok');

    $('#evfe-checkin-note').text('Actualizando…');
    $button.prop('disabled', true);

    $.post(EvFrontEdit.ajax_url, {
      action: 'eventosapp_front_toggle_checkin',
      security: EvFrontEdit.toggle_nonce,
      ticket_id: tid
    }, function(resp){
      if(resp && resp.success && resp.data){
        setBadge(resp.data.today_status);

        if(loadedTicket){
          loadedTicket.today_status = resp.data.today_status;
        }

        $('#evfe-checkin-note')
          .html('<span class="evfe-inline-status is-success">Estado actualizado.</span>');
      } else {
        setBadge(wasChecked ? 'checked_in' : 'not_checked_in');

        var message = (
          resp
          && resp.data
          && resp.data.message
        ) ? resp.data.message : EvFrontEdit.msgs.not_allowed;

        $('#evfe-checkin-note')
          .html('<span class="evfe-inline-status is-error">' + escHtml(message) + '</span>');
      }
    }, 'json').fail(function(xhr){
      setBadge(wasChecked ? 'checked_in' : 'not_checked_in');

      var message = EvFrontEdit.msgs.net_error;

      try {
        if(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
          message = xhr.responseJSON.data.message;
        } else if(xhr.responseText){
          var decoded = JSON.parse(xhr.responseText);
          if(decoded && decoded.data && decoded.data.message){
            message = decoded.data.message;
          }
        }
      } catch(error){}

      $('#evfe-checkin-note')
        .html('<span class="evfe-inline-status is-error">' + escHtml(message) + '</span>');
    }).always(function(){
      if(loadedTicket && loadedTicket.today_allowed){
        $button.prop('disabled', false);
      }
    });
  });

  $('#evfe_send_mail').on('click', function(){
    var tid = $('#ed_ticket_id').val();
    var alt = $.trim($('#evfe_email_alt').val());
    var $button = $(this);
    var $note = $('#evfe_mail_note');

    if(!tid){
      setLive('Primero carga un ticket.', 'error');
      return;
    }

    if(alt && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(alt)){
      $note.removeClass('is-success').addClass('is-error').text('Escribe un correo alternativo válido.');
      $('#evfe_email_alt').trigger('focus');
      return;
    }

    if($button.prop('disabled')) return;

    var original = $button.find('.evfe-action-label').text();

    $note.removeClass('is-success is-error').text(EvFrontEdit.msgs.sending_email || 'Enviando correo…');
    $button.prop('disabled', true).find('.evfe-action-label').text('Enviando…');

    $.post(EvFrontEdit.ajax_url, {
      action: 'eventosapp_front_send_ticket_email',
      security: EvFrontEdit.mail_nonce,
      ticket_id: tid,
      alt_email: alt
    }, function(resp){
      if(resp && resp.success){
        var message = (resp.data && resp.data.message)
          ? resp.data.message
          : 'Ticket enviado por correo.';

        $note.removeClass('is-error').addClass('is-success').text(message);
      } else {
        var message = (
          resp
          && resp.data
          && resp.data.message
        ) ? resp.data.message : 'No se pudo enviar el correo.';

        $note.removeClass('is-success').addClass('is-error').text(message);
      }
    }, 'json').fail(function(xhr){
      var message = EvFrontEdit.msgs.net_error;

      try {
        if(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
          message = xhr.responseJSON.data.message;
        }
      } catch(error){}

      $note.removeClass('is-success').addClass('is-error').text(message);
    }).always(function(){
      $button.prop('disabled', false).find('.evfe-action-label').text(original);
    });
  });

  $('#evfe_send_whatsapp').on('click', function(){
    var tid = $('#ed_ticket_id').val();
    var $button = $(this);
    var $note = $('#evfe_whatsapp_note');

    if(!tid){
      setLive('Primero carga un ticket.', 'error');
      return;
    }

    if($button.prop('disabled')) return;

    var original = $button.find('.evfe-action-label').text();

    $note.removeClass('is-success is-error').text(EvFrontEdit.msgs.sending_wa || 'Enviando WhatsApp…');
    $button.prop('disabled', true).find('.evfe-action-label').text('Enviando…');

    $.post(EvFrontEdit.ajax_url, {
      action: 'eventosapp_front_send_ticket_whatsapp',
      security: EvFrontEdit.whatsapp_nonce,
      ticket_id: tid
    }, function(resp){
      if(resp && resp.success){
        var message = (resp.data && resp.data.message)
          ? resp.data.message
          : 'Solicitud de WhatsApp aceptada.';

        $note.removeClass('is-error').addClass('is-success').text(message);
      } else {
        var message = (
          resp
          && resp.data
          && resp.data.message
        ) ? resp.data.message : 'No se pudo enviar el ticket por WhatsApp.';

        $note.removeClass('is-success').addClass('is-error').text(message);
      }
    }, 'json').fail(function(xhr){
      var message = EvFrontEdit.msgs.net_error;

      try {
        if(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
          message = xhr.responseJSON.data.message;
        }
      } catch(error){}

      $note.removeClass('is-success').addClass('is-error').text(message);
    }).always(function(){
      $button.prop('disabled', false).find('.evfe-action-label').text(original);
    });
  });
});
JS;

    wp_add_inline_script('eventosapp-front-edit', $js);
    wp_enqueue_script('eventosapp-front-edit');

    ob_start();
    ?>
    <div id="evfe-wrap" class="evfe-app" data-event-id="<?php echo esc_attr($eid); ?>">
        <div class="evfe-shell">
            <header class="evfe-header">
                <div class="evfe-heading">
                    <p class="evfe-eyebrow">EVENTOSAPP</p>
                    <h1 class="evfe-main-title">Edición de Tickets</h1>
                    <p class="evfe-subtitle">
                        Busca un asistente, actualiza sus datos y gestiona acciones operativas del ticket sin salir del evento activo.
                    </p>
                </div>

                <div class="evfe-header-actions">
                    <a
                        href="<?php echo esc_url($dashboard_url); ?>"
                        class="evfe-btn evfe-btn-secondary"
                        aria-label="Volver al dashboard"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M15 18l-6-6 6-6"></path>
                        </svg>
                        <span>Volver al dashboard</span>
                    </a>
                </div>
            </header>

            <section class="evfe-event-context" aria-label="Evento activo">
                <div class="evfe-event-main">
                    <div class="evfe-event-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <rect x="3" y="5" width="18" height="16" rx="2"></rect>
                            <path d="M7 3v4M17 3v4M3 9h18"></path>
                        </svg>
                    </div>

                    <div class="evfe-event-copy">
                        <span class="evfe-event-kicker">Evento activo</span>
                        <strong class="evfe-event-name">
                            <?php echo esc_html($event_name); ?>
                        </strong>
                    </div>
                </div>

                <div class="evfe-event-meta">
                    <?php if ( $event_modalidad_label ): ?>
                        <span class="evfe-chip">
                            <?php echo esc_html($event_modalidad_label); ?>
                        </span>
                    <?php endif; ?>

                    <?php if ( $today_label ): ?>
                        <span class="evfe-chip <?php echo $today_allowed ? 'is-valid' : 'is-warning'; ?>">
                            <?php
                            echo esc_html(
                                $today_allowed
                                    ? 'Check-in habilitado · ' . $today_label
                                    : 'Fuera de fecha · ' . $today_label
                            );
                            ?>
                        </span>
                    <?php endif; ?>

                    <a
                        class="evfe-btn evfe-btn-primary"
                        href="<?php echo esc_url($change_event_url); ?>"
                    >
                        Cambiar evento
                    </a>
                </div>
            </section>

            <?php if ( $msg ) echo $msg; ?>

            <div
                id="evfe-live-region"
                class="evfe-live-region"
                role="status"
                aria-live="polite"
                aria-atomic="true"
            ></div>

            <section class="evfe-search-card" aria-labelledby="evfe-search-title">
                <div class="evfe-card-head">
                    <div>
                        <h2 id="evfe-search-title" class="evfe-card-title">
                            Buscar ticket
                        </h2>
                        <p class="evfe-card-desc">
                            Usa el dato más preciso disponible. Cédula y celular buscan desde 2 caracteres; nombre, email y búsqueda general desde 3.
                        </p>
                    </div>
                </div>

                <div class="evfe-searchbar">
                    <div>
                        <label class="screen-reader-text" for="evfe-search-type">
                            Tipo de búsqueda
                        </label>

                        <select
                            id="evfe-search-type"
                            class="evfe-select"
                            aria-label="Tipo de búsqueda"
                        >
                            <option value="name">Nombres y apellidos</option>
                            <option value="cc" selected>Cédula</option>
                            <option value="phone">Celular</option>
                            <option value="email">Correo electrónico</option>
                            <option value="all">Todos los datos</option>
                        </select>
                    </div>

                    <div class="evfe-input-wrap">
                        <span class="evfe-search-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24">
                                <circle cx="11" cy="11" r="7"></circle>
                                <path d="m20 20-4-4"></path>
                            </svg>
                        </span>

                        <label class="screen-reader-text" for="evfe-input">
                            Dato del asistente
                        </label>

                        <input
                            id="evfe-input"
                            type="search"
                            class="evfe-input"
                            placeholder="Buscar por cédula…"
                            autocomplete="off"
                            autocapitalize="off"
                            spellcheck="false"
                        >

                        <button
                            id="evfe-clear"
                            class="evfe-clear"
                            type="button"
                            aria-label="Limpiar búsqueda"
                        >×</button>
                    </div>
                </div>

                <div class="evfe-search-foot">
                    <span>Tip: presiona Enter para buscar de inmediato o Esc para limpiar.</span>
                    <span
                        id="evfe-result-count"
                        class="evfe-result-count"
                        aria-live="polite"
                    ></span>
                </div>

                <div
                    id="evfe-results"
                    class="evfe-results"
                    aria-live="polite"
                    aria-busy="false"
                ></div>
            </section>

            <div id="evfe-editor" class="evfe-editor">
                <div class="evfe-editor-head">
                    <div class="evfe-editor-person">
                        <div class="evfe-editor-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24">
                                <path d="M4 20h4l11-11a2.8 2.8 0 0 0-4-4L4 16v4Z"></path>
                                <path d="m13.5 6.5 4 4"></path>
                            </svg>
                        </div>

                        <div>
                            <span class="evfe-editor-kicker">Ticket seleccionado</span>
                            <h2 id="evfe-selected-name" class="evfe-editor-name">
                                Asistente
                            </h2>
                            <div id="evfe-selected-meta" class="evfe-editor-meta"></div>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="evfe-btn evfe-btn-ghost"
                        onclick="document.getElementById('evfe-input').focus();"
                    >
                        Buscar otro ticket
                    </button>
                </div>

                <form
                    id="evfe-form"
                    class="evfe-form"
                    method="post"
                    autocomplete="off"
                >
                    <?php wp_nonce_field('eventosapp_front_edit'); ?>

                    <input type="hidden" name="evedit_action" value="update_ticket">
                    <input type="hidden" name="ed_ticket_id" id="ed_ticket_id">
                    <input type="hidden" name="ed_event_id" value="<?php echo esc_attr($eid); ?>">

                    <section class="evfe-form-section" aria-labelledby="evfe-person-title">
                        <div class="evfe-section-heading">
                            <div class="evfe-section-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24">
                                    <circle cx="12" cy="8" r="4"></circle>
                                    <path d="M4 21c1-5 4-7 8-7s7 2 8 7"></path>
                                </svg>
                            </div>

                            <div>
                                <h2 id="evfe-person-title" class="evfe-section-title">
                                    Datos del asistente
                                </h2>
                                <p class="evfe-section-desc">
                                    Actualiza la información personal y empresarial asociada al ticket.
                                </p>
                            </div>
                        </div>

                        <div class="evfe-grid">
                            <div class="evfe-field">
                                <label for="ed_nombre">
                                    Nombre <span class="evfe-required">*</span>
                                </label>
                                <input
                                    type="text"
                                    name="ed_nombre"
                                    id="ed_nombre"
                                    required
                                    class="evfe-control"
                                    maxlength="140"
                                >
                            </div>

                            <div class="evfe-field">
                                <label for="ed_apellido">
                                    Apellido <span class="evfe-required">*</span>
                                </label>
                                <input
                                    type="text"
                                    name="ed_apellido"
                                    id="ed_apellido"
                                    required
                                    class="evfe-control"
                                    maxlength="140"
                                >
                            </div>

                            <div class="evfe-field">
                                <label for="ed_cc">CC</label>
                                <input
                                    type="text"
                                    name="ed_cc"
                                    id="ed_cc"
                                    class="evfe-control"
                                    maxlength="120"
                                >
                            </div>

                            <div class="evfe-field">
                                <label for="ed_email">
                                    Email <span class="evfe-required">*</span>
                                </label>
                                <input
                                    type="email"
                                    name="ed_email"
                                    id="ed_email"
                                    required
                                    class="evfe-control"
                                    maxlength="190"
                                >
                            </div>

                            <div class="evfe-field">
                                <label for="ed_tel">Teléfono</label>
                                <input
                                    type="tel"
                                    name="ed_tel"
                                    id="ed_tel"
                                    class="evfe-control"
                                    maxlength="80"
                                >
                            </div>

                            <div class="evfe-field">
                                <label for="ed_empresa">Empresa</label>
                                <input
                                    type="text"
                                    name="ed_empresa"
                                    id="ed_empresa"
                                    class="evfe-control"
                                    maxlength="190"
                                >
                            </div>

                            <div class="evfe-field">
                                <label for="ed_nit">NIT</label>
                                <input
                                    type="text"
                                    name="ed_nit"
                                    id="ed_nit"
                                    class="evfe-control"
                                    maxlength="120"
                                >
                            </div>

                            <div class="evfe-field">
                                <label for="ed_cargo">Cargo</label>
                                <input
                                    type="text"
                                    name="ed_cargo"
                                    id="ed_cargo"
                                    class="evfe-control"
                                    maxlength="190"
                                >
                            </div>

                            <div class="evfe-field">
                                <label for="ed_ciudad">Ciudad</label>
                                <input
                                    type="text"
                                    name="ed_ciudad"
                                    id="ed_ciudad"
                                    class="evfe-control"
                                    maxlength="140"
                                >
                            </div>

                            <div class="evfe-field">
                                <label for="ed_pais">País</label>
                                <select
                                    name="ed_pais"
                                    id="ed_pais"
                                    class="evfe-control"
                                >
                                    <?php
                                    $countries = function_exists('eventosapp_get_countries')
                                        ? eventosapp_get_countries()
                                        : ['Colombia'];

                                    foreach ( $countries as $country ) {
                                        echo '<option value="' . esc_attr($country) . '">' . esc_html($country) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </section>

                    <section class="evfe-form-section" aria-labelledby="evfe-access-title">
                        <div class="evfe-section-heading">
                            <div class="evfe-section-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24">
                                    <path d="M12 3 4 7v5c0 5 3.3 9.3 8 10 4.7-.7 8-5 8-10V7l-8-4Z"></path>
                                    <path d="m9 12 2 2 4-4"></path>
                                </svg>
                            </div>

                            <div>
                                <h2 id="evfe-access-title" class="evfe-section-title">
                                    Acceso al evento
                                </h2>
                                <p class="evfe-section-desc">
                                    Gestiona modalidad, localidad, QR preimpreso y estado de check-in.
                                </p>
                            </div>
                        </div>

                        <div class="evfe-access-top">
                            <div id="evfe-modalidad-wrap" class="evfe-modalidad-box">
                                <label class="evfe-label" for="ed_modalidad">
                                    Modalidad del ticket
                                </label>

                                <select
                                    name="ed_modalidad"
                                    id="ed_modalidad"
                                    class="evfe-control"
                                ></select>

                                <small id="evfe-modalidad-help" class="evfe-help"></small>
                                <div id="evfe-virtual-access" style="display:none;"></div>
                            </div>

                            <div id="evfe-checkin-wrap" class="evfe-checkin">
                                <span
                                    id="evfe-checkin-badge"
                                    class="evfe-badge evfe-badge-no"
                                >
                                    Sin check-in
                                </span>

                                <button
                                    type="button"
                                    id="evfe-toggle-checkin"
                                    class="evfe-btn evfe-btn-secondary evfe-toggle"
                                >
                                    <span class="evfe-toggle-label">Marcar check-in</span>
                                </button>

                                <small
                                    id="evfe-checkin-note"
                                    class="evfe-checkin-note"
                                ></small>
                            </div>
                        </div>

                        <div class="evfe-grid">
                            <div class="evfe-field">
                                <label for="ed_localidad">Localidad</label>

                                <select
                                    name="ed_localidad"
                                    id="ed_localidad"
                                    class="evfe-control"
                                >
                                    <option value="">Seleccione…</option>

                                    <?php foreach ( $localidades as $loc ): ?>
                                        <option value="<?php echo esc_attr($loc); ?>">
                                            <?php echo esc_html($loc); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div id="evfe-preprinted-wrap" class="evfe-field">
                                <label for="ed_preprinted_qr_id">
                                    ID de QR preimpreso
                                </label>

                                <input
                                    type="text"
                                    name="ed_preprinted_qr_id"
                                    id="ed_preprinted_qr_id"
                                    class="evfe-control"
                                    placeholder="Ej: 00012345"
                                    inputmode="numeric"
                                    maxlength="80"
                                >

                                <small class="evfe-help">
                                    <?php
                                    echo esc_html(
                                        $use_preprinted_qr
                                            ? 'Este evento usa QR preimpreso.'
                                            : 'Úsalo solo si el ticket tiene QR preimpreso.'
                                    );
                                    ?>
                                </small>
                            </div>
                        </div>
                    </section>

                    <section
                        id="evfe-extras"
                        class="evfe-form-section evfe-extras"
                        hidden
                        aria-labelledby="evfe-extras-title"
                    >
                        <div class="evfe-section-heading">
                            <div class="evfe-section-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24">
                                    <path d="M5 4h14v16H5z"></path>
                                    <path d="M8 8h8M8 12h8M8 16h5"></path>
                                </svg>
                            </div>

                            <div>
                                <h2 id="evfe-extras-title" class="evfe-section-title">
                                    Campos adicionales
                                </h2>
                                <p class="evfe-section-desc">
                                    Información personalizada configurada para este evento.
                                </p>
                            </div>
                        </div>

                        <div id="evfe-extras-list" class="evfe-dynamic-grid"></div>
                    </section>

                    <section
                        id="evfe-sesiones"
                        class="evfe-form-section evfe-sessions"
                        aria-labelledby="evfe-sessions-title"
                    >
                        <div class="evfe-section-heading">
                            <div class="evfe-section-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24">
                                    <rect x="3" y="5" width="18" height="16" rx="2"></rect>
                                    <path d="M7 3v4M17 3v4M3 9h18M8 14h3M13 14h3"></path>
                                </svg>
                            </div>

                            <div>
                                <h2 id="evfe-sessions-title" class="evfe-section-title">
                                    Acceso a sesiones internas
                                </h2>
                                <p class="evfe-section-desc">
                                    Define las sesiones a las que puede ingresar este ticket.
                                </p>
                            </div>
                        </div>

                        <div
                            id="evfe-sesiones-list"
                            class="evfe-sessions-list"
                        ></div>
                    </section>

                    <section class="evfe-form-section" aria-labelledby="evfe-delivery-title">
                        <div class="evfe-section-heading">
                            <div class="evfe-section-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24">
                                    <path d="M4 6h16v12H4z"></path>
                                    <path d="m4 7 8 6 8-6"></path>
                                </svg>
                            </div>

                            <div>
                                <h2 id="evfe-delivery-title" class="evfe-section-title">
                                    Reenviar ticket
                                </h2>
                                <p class="evfe-section-desc">
                                    Reenvía el ticket manualmente por correo o WhatsApp. Estas acciones no dependen de las reglas automáticas de creación.
                                </p>
                            </div>
                        </div>

                        <div class="evfe-delivery-grid">
                            <div class="evfe-delivery-card">
                                <div class="evfe-delivery-head">
                                    <div class="evfe-delivery-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24">
                                            <path d="M4 6h16v12H4z"></path>
                                            <path d="m4 7 8 6 8-6"></path>
                                        </svg>
                                    </div>

                                    <div>
                                        <h3 class="evfe-delivery-title">Correo electrónico</h3>
                                        <p id="evfe-email-dest" class="evfe-delivery-dest">
                                            Correo del ticket
                                        </p>
                                    </div>
                                </div>

                                <label class="screen-reader-text" for="evfe_email_alt">
                                    Correo alternativo
                                </label>

                                <input
                                    type="email"
                                    id="evfe_email_alt"
                                    class="evfe-control"
                                    placeholder="Otro correo (opcional)"
                                    autocomplete="off"
                                >

                                <div class="evfe-delivery-actions">
                                    <button
                                        type="button"
                                        id="evfe_send_mail"
                                        class="evfe-btn evfe-btn-primary"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M4 6h16v12H4z"></path>
                                            <path d="m4 7 8 6 8-6"></path>
                                        </svg>
                                        <span class="evfe-action-label">Reenviar por correo</span>
                                    </button>

                                    <span
                                        id="evfe_mail_note"
                                        class="evfe-delivery-note"
                                        role="status"
                                        aria-live="polite"
                                    ></span>
                                </div>

                                <small class="evfe-help">
                                    Si dejas el campo alternativo vacío se usará el correo guardado en el ticket. Si editaste el correo principal, guarda primero para actualizarlo.
                                </small>
                            </div>

                            <div class="evfe-delivery-card">
                                <div class="evfe-delivery-head">
                                    <div class="evfe-delivery-icon is-whatsapp" aria-hidden="true">
                                        <svg viewBox="0 0 24 24">
                                            <path d="M20 11.5a8 8 0 0 1-11.9 7l-4.1 1.3 1.4-4A8 8 0 1 1 20 11.5Z"></path>
                                            <path d="M9 8.5c.4 2.2 1.8 3.7 4.1 4.5M13.1 13l1.5-1"></path>
                                        </svg>
                                    </div>

                                    <div>
                                        <h3 class="evfe-delivery-title">WhatsApp</h3>
                                        <p id="evfe-whatsapp-dest" class="evfe-delivery-dest">
                                            Teléfono del ticket
                                        </p>
                                    </div>
                                </div>

                                <p class="evfe-delivery-note">
                                    El reenvío manual usa la plantilla y el número emisor configurados para el evento, forzando el envío y omitiendo reglas automáticas.
                                </p>

                                <div class="evfe-delivery-actions">
                                    <button
                                        type="button"
                                        id="evfe_send_whatsapp"
                                        class="evfe-btn evfe-btn-whatsapp"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M20 11.5a8 8 0 0 1-11.9 7l-4.1 1.3 1.4-4A8 8 0 1 1 20 11.5Z"></path>
                                            <path d="M9 8.5c.4 2.2 1.8 3.7 4.1 4.5M13.1 13l1.5-1"></path>
                                        </svg>
                                        <span class="evfe-action-label">Reenviar por WhatsApp</span>
                                    </button>

                                    <span
                                        id="evfe_whatsapp_note"
                                        class="evfe-delivery-note"
                                        role="status"
                                        aria-live="polite"
                                    ></span>
                                </div>

                                <small class="evfe-help">
                                    Se usa el teléfono guardado en el ticket. Si lo modificaste en este formulario, guarda los cambios antes de reenviar.
                                </small>
                            </div>
                        </div>
                    </section>

                    <footer class="evfe-submit-bar">
                        <div class="evfe-submit-copy">
                            <strong>Listo para guardar</strong>
                            <span>
                                Los cambios conservan las configuraciones actuales del evento, variantes y recursos asociados al ticket.
                            </span>
                        </div>

                        <button
                            type="submit"
                            class="evfe-btn evfe-btn-primary evfe-submit"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M5 12h14M13 6l6 6-6 6"></path>
                            </svg>
                            <span class="evfe-submit-label">Guardar cambios</span>
                        </button>
                    </footer>
                </form>
            </div>
        </div>
    </div>
    <?php

    return ob_get_clean();
});

// ———————————————— AJAX: obtener datos del ticket ————————————————
add_action('wp_ajax_eventosapp_front_get_ticket', function(){
    // 1) CSRF
    check_ajax_referer('eventosapp_front_get_ticket','security');

    // 2) Permiso de feature "edit"
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('edit') ) {
        wp_send_json_error(['message' => 'Permisos insuficientes'], 403);
    }

    // 3) Validaciones
    $tid = absint($_GET['ticket_id'] ?? 0);
    if ( ! $tid || get_post_type($tid) !== 'eventosapp_ticket' ) {
        wp_send_json_error(['message' => 'Ticket inválido'], 400);
    }

    $evento_id = (int) get_post_meta($tid, '_eventosapp_ticket_evento_id', true);
    if ( ! $evento_id ) {
        wp_send_json_error(['message' => 'Ticket sin evento'], 400);
    }

    $requested_event_id = absint($_GET['event_id'] ?? 0);
    if (
        ! current_user_can('manage_options')
        && $requested_event_id
        && $evento_id !== $requested_event_id
    ) {
        wp_send_json_error(['message' => 'El ticket no corresponde al evento activo.'], 403);
    }

    // 4) Seguridad: admin o gestor del evento
    if ( ! current_user_can('manage_options')
         && function_exists('eventosapp_user_can_manage_event')
         && ! eventosapp_user_can_manage_event($evento_id) ) {
        wp_send_json_error(['message' => 'Sin permisos'], 403);
    }

    // 5) Datos: precarga metas del ticket y del evento para reducir consultas repetidas.
    update_meta_cache('post', [$tid, $evento_id]);

    $nombre   = get_post_meta($tid, '_eventosapp_asistente_nombre', true);
    $apellido = get_post_meta($tid, '_eventosapp_asistente_apellido', true);
    $cc       = get_post_meta($tid, '_eventosapp_asistente_cc', true);
    $email    = get_post_meta($tid, '_eventosapp_asistente_email', true);
    $tel      = get_post_meta($tid, '_eventosapp_asistente_tel', true);
    $emp      = get_post_meta($tid, '_eventosapp_asistente_empresa', true);
    $nit      = get_post_meta($tid, '_eventosapp_asistente_nit', true);
    $cargo    = get_post_meta($tid, '_eventosapp_asistente_cargo', true);
    $loc      = get_post_meta($tid, '_eventosapp_asistente_localidad', true);
    $pre      = get_post_meta($tid, 'eventosapp_ticket_preprintedID', true);
    $ciudad   = get_post_meta($tid, '_eventosapp_asistente_ciudad', true);
    $pais     = get_post_meta($tid, '_eventosapp_asistente_pais', true);
    $ticket_pub = get_post_meta($tid, 'eventosapp_ticketID', true);

    $localidades = get_post_meta($evento_id, '_eventosapp_localidades', true);
    if (!is_array($localidades) || empty($localidades)) $localidades = ['General','VIP','Platino'];

    $sesiones = get_post_meta($evento_id, '_eventosapp_sesiones_internas', true);
    if (!is_array($sesiones)) $sesiones = [];
    $ses_nombres = [];
    foreach ($sesiones as $s) {
        if (is_array($s) && isset($s['nombre']) && $s['nombre']!=='') $ses_nombres[] = $s['nombre'];
        elseif (is_string($s) && $s!=='') $ses_nombres[] = $s;
    }
    $ses_acceso = get_post_meta($tid, '_eventosapp_ticket_sesiones_acceso', true);
    if (!is_array($ses_acceso)) $ses_acceso = [];

    $extras_schema = function_exists('eventosapp_get_event_extra_fields') ? eventosapp_get_event_extra_fields($evento_id) : [];
    if ( ! is_array($extras_schema) ) {
        $extras_schema = [];
    }

    $extras_values = [];
    foreach ($extras_schema as $fld){
        if ( ! is_array($fld) || empty($fld['key']) ) {
            continue;
        }
        $extras_values[$fld['key']] = get_post_meta($tid, '_eventosapp_extra_'.$fld['key'], true);
    }

    // Modalidad del ticket y opciones permitidas por el evento.
    $modalidad = function_exists('eventosapp_get_ticket_modalidad') ? eventosapp_get_ticket_modalidad($tid) : (get_post_meta($tid, '_eventosapp_ticket_modalidad', true) ?: 'presencial');
    $modalidad = in_array($modalidad, ['presencial','virtual'], true) ? $modalidad : 'presencial';
    $is_virtual = ($modalidad === 'virtual');
    $event_modalidad = function_exists('eventosapp_get_event_modalidad') ? eventosapp_get_event_modalidad($evento_id) : (get_post_meta($evento_id, '_eventosapp_event_modalidad', true) ?: 'presencial');
    $allowed_modalidades = function_exists('eventosapp_ticket_allowed_modalidades_for_event') ? eventosapp_ticket_allowed_modalidades_for_event($evento_id) : (($event_modalidad === 'virtual') ? ['virtual'] : (($event_modalidad === 'presencial_virtual') ? ['presencial','virtual'] : ['presencial']));
    $modalidad_labels = function_exists('eventosapp_ticket_modalidad_options') ? eventosapp_ticket_modalidad_options() : ['presencial'=>'Presencial','virtual'=>'Virtual'];
    $virtual_url = ($is_virtual && function_exists('eventosapp_get_virtual_landing_url')) ? eventosapp_get_virtual_landing_url($tid) : '';
    if ($event_modalidad === 'virtual') {
        $modalidad_help = 'Este evento es Virtual: todos los tickets quedan como Virtuales.';
    } elseif ($event_modalidad === 'presencial_virtual') {
        $modalidad_help = 'Este evento permite Presencial y Virtual: define la modalidad del asistente.';
    } else {
        $modalidad_help = 'Este evento es Presencial: todos los tickets quedan como Presenciales.';
    }

    // Info de check-in de HOY (misma lógica del buscador)
    $today_allowed = $is_virtual ? false : eventosapp_is_today_valid_for_event($evento_id);
    $today         = eventosapp_get_today_in_event_tz($evento_id);
    $status_arr    = get_post_meta($tid, '_eventosapp_checkin_status', true);
    if (is_string($status_arr)) $status_arr = @unserialize($status_arr);
    if (!is_array($status_arr)) $status_arr = [];
    $today_status  = $today_allowed ? ($status_arr[$today] ?? 'not_checked_in') : 'not_checked_in';

    wp_send_json_success([
        'ticket_id'         => $tid,
        'ticket_pub'        => $ticket_pub,
        'event_id'          => $evento_id,
        'nombre'            => $nombre,
        'apellido'          => $apellido,
        'cc'                => $cc,
        'email'             => $email,
        'tel'               => $tel,
        'empresa'           => $emp,
        'nit'               => $nit,
        'cargo'             => $cargo,
        'ciudad'            => $ciudad,
        'pais'              => $pais ?: 'Colombia',
        'localidad'         => $loc,
        'localidades'       => array_values(array_unique(array_filter($localidades))),
        'preprinted'        => $pre,
        'sesiones'          => array_values(array_unique(array_filter($ses_nombres))),
        'sesiones_acceso'   => $ses_acceso,
        'extras_schema'       => $extras_schema,
        'extras_values'       => $extras_values,
        'modalidad'           => $modalidad,
        'modalidad_label'     => $modalidad_labels[$modalidad] ?? ucfirst($modalidad),
        'modalidad_labels'    => $modalidad_labels,
        'event_modalidad'     => $event_modalidad,
        'allowed_modalidades' => $allowed_modalidades,
        'modalidad_help'      => $modalidad_help,
        'is_virtual'          => $is_virtual,
        'virtual_url'         => $virtual_url,

        // Check-in (hoy)
        'today_allowed'       => $today_allowed,
        'today_status'      => $today_status,
    ]);

});

// ———————————————— (SIN handler local de toggle check-in)
// Usamos el endpoint existente `eventosapp_front_toggle_checkin` del archivo “search”
// con el mismo nonce 'eventosapp_toggle_checkin'.

// ———————————————— AJAX: reenviar ticket por correo ————————————————
add_action('wp_ajax_eventosapp_front_send_ticket_email', function(){
    // 1) CSRF
    check_ajax_referer('eventosapp_front_send_ticket_email','security');

    // 2) Permiso de feature "edit"
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('edit') ) {
        wp_send_json_error(['message' => 'Permisos insuficientes'], 403);
    }

    // 3) Validaciones
    $tid = absint($_POST['ticket_id'] ?? 0);
    if ( ! $tid || get_post_type($tid) !== 'eventosapp_ticket' ) {
        wp_send_json_error(['message' => 'Ticket inválido'], 400);
    }

    $evento_id = (int) get_post_meta($tid, '_eventosapp_ticket_evento_id', true);
    if ( ! $evento_id ) {
        wp_send_json_error(['message' => 'Ticket sin evento'], 400);
    }

    // 4) Seguridad: admin o dueño del evento
    if ( ! current_user_can('manage_options')
         && function_exists('eventosapp_user_can_manage_event')
         && ! eventosapp_user_can_manage_event($evento_id) ) {
        wp_send_json_error(['message' => 'Sin permisos'], 403);
    }

    // 5) Destino
    $stored = get_post_meta($tid, '_eventosapp_asistente_email', true);
    $alt    = sanitize_email(wp_unslash($_POST['alt_email'] ?? ''));
    $to     = $alt ?: $stored;
    if ( ! $to || ! is_email($to) ) {
        wp_send_json_error(['message' => 'Correo destino inválido'], 400);
    }

    // Compatibilidad Variantes de Tickets: antes de reenviar desde frontend,
    // recalcula la variante y refresca Wallets habilitados para evitar enlaces antiguos
    // cuando la variante cambió por edición de campos o por ajustes del evento.
    try {
        $is_virtual_ticket_for_email = function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($tid);
        eventosapp_front_edit_run_silent('ticket_variants_before_frontend_email', function() use ( $tid, $evento_id, $is_virtual_ticket_for_email ) {
            if (function_exists('eventosapp_ticket_variants_prepare_ticket_for_frontend_context')) {
                eventosapp_ticket_variants_prepare_ticket_for_frontend_context($tid, $evento_id, 'frontend_edit_send_email', [
                    'sync_google_classes' => true,
                    'mark_assets_stale'   => false,
                    'clear_assets_stale'  => true,
                    'refresh_wallets'     => !$is_virtual_ticket_for_email,
                    'refresh_pdf_ics'     => false,
                    'rebuild_search_index'=> true,
                    'log'                 => true,
                ]);
            } elseif (function_exists('eventosapp_ticket_variants_apply_to_ticket')) {
                eventosapp_ticket_variants_apply_to_ticket($tid, $evento_id, true);
            }
            return true;
        });
    } catch (Throwable $e) {
        wp_send_json_error(['message' => 'No se pudo preparar la variante del ticket antes del envío. Revisa wp-debug.log.'], 500);
    }

    // 6) Flags del evento: generar PDF e ICS antes de enviar si aplica
    $is_virtual_ticket = function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($tid);
    $pdf_on = !$is_virtual_ticket && get_post_meta($evento_id, '_eventosapp_ticket_pdf', true) === '1';
    $ics_on = (get_post_meta($evento_id, '_eventosapp_ticket_ics', true) === '1') || $is_virtual_ticket;

    try {
        eventosapp_front_edit_run_silent('generate_pdf_ics_before_frontend_email', function() use ( $tid, $pdf_on, $ics_on ) {
            if ($pdf_on && function_exists('eventosapp_ticket_generar_pdf')) eventosapp_ticket_generar_pdf($tid);
            if ($ics_on && function_exists('eventosapp_ticket_generar_ics')) eventosapp_ticket_generar_ics($tid);
            return true;
        });
    } catch (Throwable $e) {
        wp_send_json_error(['message' => 'No se pudieron preparar los anexos antes del envío. Revisa wp-debug.log.'], 500);
    }

    // 7) Delegar el envío a la función centralizada que registra tracking en BD
    if ( ! function_exists('eventosapp_send_ticket_email_now') ) {
        wp_send_json_error(['message' => 'Función de envío no disponible.'], 500);
    }

    try {
        $send_result = eventosapp_front_edit_run_silent('send_ticket_email_from_frontend_edit', function() use ( $tid, $to, $is_virtual_ticket ) {
            return eventosapp_send_ticket_email_now($tid, [
                'recipient'       => $to,
                'source'          => 'frontend_edit',
                'force'           => true,
                'refresh_wallets' => !$is_virtual_ticket,
            ]);
        });
    } catch (Throwable $e) {
        wp_send_json_error(['message' => 'No se pudo enviar el correo. Revisa wp-debug.log.'], 500);
    }

    list($ok, $msg) = is_array($send_result) ? $send_result : [false, 'No se pudo enviar el correo.'];

    if ($ok) {
        wp_send_json_success(['message' => $msg]);
    }
    wp_send_json_error(['message' => $msg ?: 'No se pudo enviar el correo. Revisa configuración SMTP/hosting.'], 500);
});

// ———————————————— AJAX: reenviar ticket por WhatsApp ————————————————
add_action('wp_ajax_eventosapp_front_send_ticket_whatsapp', function(){
    // 1) CSRF
    check_ajax_referer('eventosapp_front_send_ticket_whatsapp', 'security');

    // 2) Permiso de feature "edit"
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('edit') ) {
        wp_send_json_error(['message' => 'Permisos insuficientes'], 403);
    }

    // 3) Validaciones
    $tid = absint($_POST['ticket_id'] ?? 0);
    if ( ! $tid || get_post_type($tid) !== 'eventosapp_ticket' ) {
        wp_send_json_error(['message' => 'Ticket inválido'], 400);
    }

    $evento_id = (int) get_post_meta($tid, '_eventosapp_ticket_evento_id', true);
    if ( ! $evento_id ) {
        wp_send_json_error(['message' => 'Ticket sin evento'], 400);
    }

    // 4) Seguridad: admin o gestor del evento.
    if (
        ! current_user_can('manage_options')
        && function_exists('eventosapp_user_can_manage_event')
        && ! eventosapp_user_can_manage_event($evento_id)
    ) {
        wp_send_json_error(['message' => 'Sin permisos'], 403);
    }

    // 5) El reenvío manual usa siempre el teléfono actualmente guardado en el ticket.
    $stored_phone = sanitize_text_field(
        (string) get_post_meta($tid, '_eventosapp_asistente_tel', true)
    );

    if ( $stored_phone === '' ) {
        wp_send_json_error([
            'message' => 'Este ticket no tiene teléfono. Agrégalo, guarda los cambios y vuelve a intentar.',
        ], 400);
    }

    // Si está disponible el normalizador central, validar el teléfono antes de llamar a Meta.
    if ( function_exists('eventosapp_whatsapp_normalize_phone') ) {
        $default_country_code = '57';

        if ( function_exists('eventosapp_whatsapp_get_settings') ) {
            $wa_settings = eventosapp_whatsapp_get_settings();
            if (
                is_array($wa_settings)
                && ! empty($wa_settings['default_country_code'])
            ) {
                $default_country_code = (string) $wa_settings['default_country_code'];
            }
        }

        $normalized_phone = eventosapp_whatsapp_normalize_phone(
            $stored_phone,
            $default_country_code
        );

        if ( ! $normalized_phone ) {
            wp_send_json_error([
                'message' => 'El teléfono guardado no tiene un formato válido para WhatsApp. Corrígelo y guarda el ticket antes de reenviar.',
            ], 400);
        }
    }

    // 6) Recalcular la variante antes del reenvío para mantener branding,
    // enlaces y recursos consistentes con los datos actuales del ticket.
    try {
        eventosapp_front_edit_run_silent(
            'ticket_variants_before_frontend_whatsapp',
            function() use ( $tid, $evento_id ) {
                if ( function_exists('eventosapp_ticket_variants_prepare_ticket_for_frontend_context') ) {
                    eventosapp_ticket_variants_prepare_ticket_for_frontend_context(
                        $tid,
                        $evento_id,
                        'frontend_edit_send_whatsapp',
                        [
                            'sync_google_classes'  => true,
                            'mark_assets_stale'    => false,
                            'clear_assets_stale'   => true,
                            'refresh_wallets'      => false,
                            'refresh_pdf_ics'      => false,
                            'rebuild_search_index' => true,
                            'log'                  => true,
                        ]
                    );
                } elseif ( function_exists('eventosapp_ticket_variants_apply_to_ticket') ) {
                    eventosapp_ticket_variants_apply_to_ticket(
                        $tid,
                        $evento_id,
                        true
                    );
                }

                return true;
            }
        );
    } catch (Throwable $e) {
        eventosapp_front_edit_debug_log(
            'Error preparando variante antes de reenviar por WhatsApp',
            [
                'ticket_id' => $tid,
                'event_id'  => $evento_id,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]
        );

        wp_send_json_error([
            'message' => 'No se pudo preparar el ticket antes del envío por WhatsApp. Revisa wp-debug.log.',
        ], 500);
    }

    // 7) Reutilizar el MISMO motor de reenvío manual del metabox administrativo.
    // force=true evita el bloqueo por duplicado y skip_rules=true omite las reglas
    // automáticas del evento, sin duplicar la lógica de plantillas, remitente o tracking.
    if ( ! function_exists('eventosapp_whatsapp_send_ticket') ) {
        wp_send_json_error([
            'message' => 'El módulo de envío por WhatsApp no está disponible.',
        ], 500);
    }

    try {
        $send_result = eventosapp_front_edit_run_silent(
            'send_ticket_whatsapp_from_frontend_edit',
            function() use ( $tid ) {
                return eventosapp_whatsapp_send_ticket(
                    $tid,
                    [
                        'context'    => 'manual_frontend_edit',
                        'force'      => true,
                        'skip_rules' => true,
                    ]
                );
            }
        );
    } catch (Throwable $e) {
        eventosapp_front_edit_debug_log(
            'Error reenviando ticket por WhatsApp desde frontend',
            [
                'ticket_id' => $tid,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]
        );

        wp_send_json_error([
            'message' => 'No se pudo enviar el ticket por WhatsApp. Revisa wp-debug.log.',
        ], 500);
    }

    $ok = is_array($send_result) && ! empty($send_result['ok']);
    $message = is_array($send_result) && ! empty($send_result['message'])
        ? sanitize_text_field((string) $send_result['message'])
        : ($ok ? 'Solicitud de WhatsApp aceptada para envío.' : 'No se pudo enviar el ticket por WhatsApp.');

    if ( $ok ) {
        wp_send_json_success([
            'message' => $message,
        ]);
    }

    wp_send_json_error([
        'message' => $message,
    ], 500);
});

