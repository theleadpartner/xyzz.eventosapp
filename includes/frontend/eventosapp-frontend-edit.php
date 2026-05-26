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

        if ( $type === 'success' ) {
            $style = 'padding:12px;border:1px solid #d1fae5;background:#ecfdf5;border-radius:10px;color:#065f46;margin:0 0 12px;';
        } else {
            $style = 'padding:12px;border:1px solid #fca5a5;background:#fee2e2;border-radius:10px;color:#991b1b;margin:0 0 12px;';
        }

        return '<div class="evfe-notice evfe-notice-' . esc_attr($type) . '" style="' . esc_attr($style) . '">' . wp_kses_post($notice['message']) . '</div>';
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
    if ( function_exists('eventosapp_require_feature') ) eventosapp_require_feature('edit');

    $a = shortcode_atts(['event_id'=>0], $atts);
    $eid = eventosapp_front_edit_resolve_event_id($a['event_id']);

    // Debe haber evento
    if ( ! $eid ) {
        if ( function_exists('eventosapp_require_active_event') ) {
            ob_start();
            eventosapp_require_active_event();
            return ob_get_clean();
        }
        $dash = function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/');
        return '<div style="padding:.8rem;border:1px solid #eee;background:#fffdf2;border-radius:8px;color:#8a6d3b;">
            Debes escoger un <strong>evento activo</strong> en el <a href="'.esc_url($dash).'">dashboard</a>.
        </div>';
    }

    // Validar permisos sobre el evento
    if ( ! current_user_can('manage_options') && function_exists('eventosapp_user_can_manage_event') && ! eventosapp_user_can_manage_event($eid) ) {
        return '<div style="padding:.8rem;border:1px solid #eee;background:#fff8f8;border-radius:8px;color:#a33;">
            No tienes permisos sobre este evento.
        </div>';
    }

    // Detectar si el evento usa QR preimpreso
    $use_preprinted_qr = false;
    $flag_meta = get_post_meta($eid, '_eventosapp_ticket_use_preprinted_qr', true);
    if ($flag_meta !== '' && $flag_meta !== null) {
        $use_preprinted_qr = (bool) intval($flag_meta);
    } else {
        $flag_opt = get_option('_eventosapp_ticket_use_preprinted_qr', 0);
        $use_preprinted_qr = (bool) intval($flag_opt);
    }
    $use_preprinted_qr = (bool) apply_filters('eventosapp_use_preprinted_qr', $use_preprinted_qr, $eid);

    // Fallback: si por alguna razón template_redirect no capturó el POST, se procesa aquí
    // dentro de buffers controlados para no romper el layout.
    $msg = '';
    if ( isset($_POST['evedit_action']) && sanitize_key(wp_unslash($_POST['evedit_action'])) === 'update_ticket' ) {
        $fallback_result = eventosapp_front_edit_update_ticket_from_post($eid);
        $msg = eventosapp_front_edit_render_notice($fallback_result);
    } else {
        $msg = eventosapp_front_edit_render_notice(eventosapp_front_edit_consume_notice());
    }

    // Localidades del evento
    $localidades = get_post_meta($eid, '_eventosapp_localidades', true);
    if (!is_array($localidades) || empty($localidades)) $localidades = ['General','VIP','Platino'];

    // Cargar barra de evento activo
    ob_start();
    if ( function_exists('eventosapp_active_event_bar') ) eventosapp_active_event_bar();

    if ($msg) echo $msg;
    ?>

    <div id="evfe-wrap" class="evfe-wrap" style="max-width:980px;margin:0 auto;clear:both;position:relative;z-index:1;box-sizing:border-box;">
        <!-- Buscador -->
        <div class="evfe-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;box-shadow:0 1px 5px rgba(120,140,160,.06);box-sizing:border-box;width:100%;">
            <h2 style="margin:0 0 10px;font-size:22px;">Editar tickets</h2>
            <p style="margin:0 0 10px;color:#555">Busca al asistente segmentando el dato principal. Por defecto se busca por <b>Cédula</b> para acelerar la consulta.</p>
            <div class="evfe-searchbar">
                <label class="screen-reader-text" for="evfe-search-type">Tipo de búsqueda</label>
                <select id="evfe-search-type" class="evfe-select" aria-label="Tipo de búsqueda">
                    <option value="name">Nombres y apellidos</option>
                    <option value="cc" selected>Cédula</option>
                    <option value="phone">Celular</option>
                    <option value="email">Correo electrónico</option>
                    <option value="all">Todos los datos</option>
                </select>
                <input id="evfe-input" type="text" class="evfe-input" placeholder="Buscar por cédula…" autocomplete="off" style="width:100%;padding:.65rem .7rem;border:1px solid #dfe3e7;border-radius:10px;box-sizing:border-box;">
            </div>
            <div id="evfe-results" class="evfe-results" style="margin-top:10px"></div>
        </div>

        <!-- Formulario de edición (se rellena vía AJAX) -->
        <form id="evfe-form" method="post" style="display:none;margin-top:14px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;box-shadow:0 1px 5px rgba(120,140,160,.06);box-sizing:border-box;width:100%;">
            <?php wp_nonce_field('eventosapp_front_edit'); ?>
            <input type="hidden" name="evedit_action" value="update_ticket">
            <input type="hidden" name="ed_ticket_id" id="ed_ticket_id">
            <input type="hidden" name="ed_event_id" value="<?php echo esc_attr($eid); ?>">

            <h3 style="margin:0 0 12px">Editar datos del asistente</h3>

            <!-- Check-in (igual lógica que el buscador: solo hoy y si está permitido) -->
            <div id="evfe-checkin-wrap" style="display:none;margin:8px 0 12px;padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#f9fafb;align-items:center;gap:10px;flex-wrap:wrap">
                <span id="evfe-checkin-badge" class="evfe-badge evfe-badge-no">Not Checked In</span>
                <button type="button" id="evfe-toggle-checkin" class="evfe-btn evfe-toggle">Toggle Check-in</button>
                <small id="evfe-checkin-note" style="color:#555"></small>
            </div>

            <div id="evfe-modalidad-wrap" class="evfe-modalidad-box" style="display:none;margin:8px 0 12px;padding:10px;border:1px solid #dbeafe;border-radius:10px;background:#eff6ff;">
                <label for="ed_modalidad">Modalidad del ticket</label>
                <select name="ed_modalidad" id="ed_modalidad" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #bfdbfe;max-width:320px;"></select>
                <small id="evfe-modalidad-help" style="display:block;color:#1f4f82;margin-top:5px;line-height:1.35"></small>
                <div id="evfe-virtual-access" style="display:none;margin-top:8px;"></div>
            </div>

            <div class="evfe-form-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
                <div><label>Nombre *</label><input type="text" name="ed_nombre" id="ed_nombre" required class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>
                <div><label>Apellido *</label><input type="text" name="ed_apellido" id="ed_apellido" required class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>
                <div><label>CC</label><input type="text" name="ed_cc" id="ed_cc" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>
                <div><label>Email *</label><input type="email" name="ed_email" id="ed_email" required class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>
                <div><label>Teléfono</label><input type="tel" name="ed_tel" id="ed_tel" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>
                <div><label>Empresa</label><input type="text" name="ed_empresa" id="ed_empresa" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>
                <div><label>NIT</label><input type="text" name="ed_nit" id="ed_nit" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>
                <div><label>Cargo</label><input type="text" name="ed_cargo" id="ed_cargo" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>

                <div><label>Ciudad</label><input type="text" name="ed_ciudad" id="ed_ciudad" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>
                <div>
                    <label>País</label>
                    <select name="ed_pais" id="ed_pais" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7">
                        <?php
                        $countries = function_exists('eventosapp_get_countries') ? eventosapp_get_countries() : array('Colombia');
                        foreach ($countries as $c) {
                            echo '<option value="'.esc_attr($c).'">'.esc_html($c).'</option>';
                        }
                        ?>
                    </select>
                </div>

                <div>
                    <label>Localidad</label>
                    <select name="ed_localidad" id="ed_localidad" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7">
                        <option value="">Seleccione…</option>
                        <?php foreach($localidades as $loc): ?>
                            <option value="<?php echo esc_attr($loc); ?>"><?php echo esc_html($loc); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="evfe-preprinted-wrap">
                    <label>ID de QR preimpreso (numérico)</label>
                    <input type="text" name="ed_preprinted_qr_id" id="ed_preprinted_qr_id" class="widefat" placeholder="Ej: 00012345" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7">
                    <small style="color:#666;display:block;margin-top:4px;">
                        <?php echo $use_preprinted_qr ? 'Este evento usa QR preimpreso.' : 'Úsalo solo si el ticket tiene QR preimpreso.'; ?>
                    </small>
                </div>
            </div>

            <div id="evfe-extras" style="display:none;margin-top:12px;padding-top:10px;border-top:1px dashed #e5e7eb"></div>

            <div id="evfe-sesiones" style="margin-top:12px;padding-top:10px;border-top:1px dashed #e5e7eb">
                <b>Acceso a sesiones internas:</b>
                <div id="evfe-sesiones-list" style="margin-top:8px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;"></div>
            </div>

            <div class="evfe-form-actions" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:16px">
                <button type="submit" class="button button-primary evfe-submit" style="padding:.7rem 1.1rem;border-radius:10px;font-weight:700">Guardar cambios</button>

                <div class="evfe-mail-actions" style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <input type="email" id="evfe_email_alt" placeholder="Enviar a otro correo (opcional)" style="padding:.5rem .6rem;border:1px solid #dfe3e7;border-radius:10px;min-width:260px">
                    <button type="button" id="evfe_send_mail" class="button" style="border-color:#2563eb;background:#2563eb;color:#fff;border-radius:10px;">Reenviar ticket por correo</button>
                    <span id="evfe_mail_note" style="font-size:.95rem;color:#555"></span>
                </div>
            </div>
        </form>
    </div>

    <?php
    // ——— Scripts/estilos ———
    wp_enqueue_script('jquery');
    wp_register_script('eventosapp-front-edit', false, ['jquery'], null, true);
    wp_localize_script('eventosapp-front-edit', 'EvFrontEdit', [
        'ajax_url'     => admin_url('admin-ajax.php'),
        'search_nonce' => wp_create_nonce('eventosapp_front_search'),
        'get_nonce'    => wp_create_nonce('eventosapp_front_get_ticket'),
        'mail_nonce'   => wp_create_nonce('eventosapp_front_send_ticket_email'),
        // Usamos el MISMO nonce que el buscador:
        'toggle_nonce' => wp_create_nonce('eventosapp_toggle_checkin'),
        'event_id'     => $eid,
        'msgs'         => [
            'not_allowed' => __('El check-in solo está permitido en las fechas del evento. Hoy no corresponde.', 'eventosapp'),
            'net_error'   => __('Error de red. Intenta de nuevo.', 'eventosapp'),
            'saving'      => __('Guardando cambios…', 'eventosapp')
        ]
    ]);

    // CSS (como estilo de WP, no por JS)
    $css = <<<CSS
.evfe-wrap{max-width:980px;margin:0 auto;clear:both;position:relative;z-index:1;box-sizing:border-box;width:100%}
.evfe-wrap *{box-sizing:border-box}
.evfe-card,#evfe-form{width:100%;box-sizing:border-box}
.evfe-searchbar{display:grid;grid-template-columns:minmax(185px,240px) 1fr;gap:8px;align-items:center}
.evfe-select{width:100%;padding:.65rem .7rem;border:1px solid #dfe3e7;border-radius:10px;background:#fff;color:#111;box-sizing:border-box}
.evfe-row{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;padding:.8rem;border:1px solid #eee;border-radius:12px;background:#fff;margin-bottom:8px;box-shadow:0 1px 5px rgba(120,140,160,.07)}
.evfe-data{flex:1 1 auto;min-width:0;word-break:break-word}
.evfe-actions{flex:0 0 auto;display:flex;gap:8px}
.evfe-btn{display:inline-block;border-radius:8px;border:0;font-size:1rem;font-weight:600;cursor:pointer;padding:.55rem .9rem;box-shadow:0 1px 4px rgba(30,60,100,.07);text-decoration:none;line-height:1.2}
.evfe-edit{background:#2563eb;color:#fff}
.evfe-edit:hover{background:#1d4ed8;color:#fff}
.evfe-note{display:inline-block;margin-left:8px;font-size:.92rem;color:#0f5132;background:#d1e7dd;border:1px solid #badbcc;padding:.25rem .45rem;border-radius:6px}
.evfe-form-grid > div label{display:block;margin:0 0 5px}
.evfe-modalidad-box label{display:block;margin:0 0 5px;font-weight:700;color:#111}
.evfe-virtual-link{display:inline-flex;align-items:center;justify-content:center;padding:.55rem .9rem;border-radius:8px;background:#7c3aed;color:#fff;text-decoration:none;font-weight:700}
.evfe-virtual-link:hover{background:#5b21b6;color:#fff}
.evfe-mail-actions input{max-width:100%}
@media(max-width:650px){
  .evfe-searchbar{grid-template-columns:1fr}
  .evfe-row{flex-direction:column}
  .evfe-actions{justify-content:stretch;width:100%}
  .evfe-btn{width:100%;text-align:center}
  .evfe-form-actions{align-items:stretch!important}
  .evfe-mail-actions{margin-left:0!important;width:100%}
  .evfe-mail-actions input,.evfe-mail-actions button{width:100%;min-width:0!important}
}

/* Accesibilidad visual del formulario */
#evfe-form label{font-weight:700;color:#111}
#evfe-form input:not([type]),
#evfe-form input[type="text"],
#evfe-form input[type="email"],
#evfe-form input[type="tel"],
#evfe-form input[type="number"],
#evfe-form select,
#evfe-form textarea{
  background:#f6f7fb;
  border-color:#dfe3e7;
  width:100%;
}
#evfe-form input[type="checkbox"]{background:transparent;width:auto}
#evfe-form input:focus,
#evfe-form select:focus,
#evfe-form textarea:focus{
  outline:none;
  box-shadow:0 0 0 3px rgba(37,99,235,.15);
  background:#f3f5fb;
}
#evfe-form.evfe-form-highlight{
  box-shadow:0 0 0 3px rgba(37,99,235,.15), 0 1px 6px rgba(120,140,160,.12) !important;
}

/* Badge Check-in */
.evfe-badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:.92rem;font-weight:700}
.evfe-badge-ok{background:#16a34a;color:#fff}
.evfe-badge-no{background:#b91c1c;color:#fff}

/* Botón toggle */
.evfe-toggle{background:#111827;color:#fff}
.evfe-toggle:hover{background:#0b1220;color:#fff}
CSS;

    wp_register_style('eventosapp-front-edit', false, [], null);
    wp_add_inline_style('eventosapp-front-edit', $css);
    wp_enqueue_style('eventosapp-front-edit');

    // JS en NOWDOC para evitar interpolación de PHP y permitir nombres $var en JS
    $js = <<<'JS'
jQuery(function($){
  var $type = $('#evfe-search-type'),
      $in  = $('#evfe-input'),
      $out = $('#evfe-results'),
      $form= $('#evfe-form'),
      eventId = EvFrontEdit.event_id,
      timer;

  function escHtml(value){
    if(value === null || typeof value === 'undefined') return '';
    return $('<div>').text(String(value)).html();
  }

  function escAttr(value){
    return escHtml(value).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
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

  function updateSearchPlaceholder(){
    $in.attr('placeholder', searchPlaceholder(getSearchType()));
  }


  function optionHtml(value, label, selected){
    return '<option value="'+escAttr(value)+'"'+(selected ? ' selected' : '')+'>'+escHtml(label)+'</option>';
  }

  function setSelectValue($select, value, fallback){
    var val = (value || fallback || '').toString();
    if(val && !$select.find('option').filter(function(){ return $(this).val() === val; }).length){
      $select.append(optionHtml(val, val, false));
    }
    $select.val(val);
  }

  function render(rows){
    rows = Array.isArray(rows) ? rows : [];
    if(!rows.length){ $out.html('<div style="padding:.5rem;color:#666;">No hay resultados.</div>'); return; }
    var html='';
    $.each(rows,function(i,it){
      it = it || {};
      var full = $.trim((it.first_name||'')+' '+(it.last_name||''));
      html += '<div class="evfe-row">'
           +   '<div class="evfe-data">'
           +     '<strong>'+ escHtml(full || 'Sin nombre') +'</strong> <span style="color:#888">('+escHtml(it.cc||'—')+')</span><br>'
           +     'Email: '+escHtml(it.email||'—')+'<br>'
           +     'TicketID: '+escHtml(it.ticket_pub||'—')+' · Evento: '+escHtml(it.event_name||'—')+'<br>'
           +     'Modalidad: '+escHtml(it.modalidad_label||'Presencial')
           +   '</div>'
           +   '<div class="evfe-actions">'
           +     '<button type="button" class="evfe-btn evfe-edit" data-ticket-id="'+escAttr(it.ticket_id||'')+'">Editar</button>'
           +   '</div>'
           + '</div>';
    });
    $out.html(html);
  }

  function runSearch(){
    clearTimeout(timer);
    var q = $in.val().trim();
    var searchType = getSearchType();
    if(!q || q.length < 2){ $out.empty(); return; }
    timer = setTimeout(function(){
      $.getJSON(EvFrontEdit.ajax_url, {
        action: 'eventosapp_front_search',
        security: EvFrontEdit.search_nonce,
        q: q,
        search_type: searchType,
        event_id: eventId
      }).done(function(resp){
        if(resp && resp.success){ render(resp.data||[]); } else { render([]); }
      }).fail(function(){ render([]); });
    }, 300);
  }

  $in.on('input', runSearch);

  $type.on('change', function(){
    updateSearchPlaceholder();
    $out.empty();
    runSearch();
  });

  updateSearchPlaceholder();

  function setBadge(status){
    var $b = $('#evfe-checkin-badge');
    if(status==='checked_in'){
      $b.removeClass('evfe-badge-no').addClass('evfe-badge-ok').text('Checked In');
    } else {
      $b.removeClass('evfe-badge-ok').addClass('evfe-badge-no').text('Not Checked In');
    }
  }

  function renderExtras(schema, vals){
    var $extras = $('#evfe-extras').empty();
    schema = Array.isArray(schema) ? schema : [];
    vals = vals || {};

    if (!schema.length) {
      $extras.hide();
      return;
    }

    var html = '<b>Campos adicionales:</b><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-top:8px;">';

    schema.forEach(function(f){
      f = f || {};
      var key = (typeof f.key !== 'undefined') ? String(f.key) : '';
      if(!key) return;

      var label = f.label || key;
      var req = !!f.required;
      var val = (vals && typeof vals[key] !== 'undefined') ? vals[key] : '';
      var name = 'ed_extra['+key+']';

      html += '<div><label>'+escHtml(label)+(req?' *':'')+'</label>';

      if (f.type === 'number') {
        html += '<input type="number" name="'+escAttr(name)+'" value="'+escAttr(val||'')+'" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7">';
      } else if (f.type === 'select') {
        html += '<select name="'+escAttr(name)+'" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7">';
        html += optionHtml('', 'Seleccione…', !val);
        (Array.isArray(f.options) ? f.options : []).forEach(function(op){
          html += optionHtml(op, op, String(op) === String(val));
        });
        html += '</select>';
      } else {
        html += '<input type="text" name="'+escAttr(name)+'" value="'+escAttr(val||'')+'" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7">';
      }

      html += '</div>';
    });

    html += '</div>';
    $extras.html(html).show();
  }

  function renderLocalidades(localidades, current){
    var $sel = $('#ed_localidad');
    current = current || '';

    if (Array.isArray(localidades) && localidades.length){
      $sel.empty().append(optionHtml('', 'Seleccione…', current === ''));
      localidades.forEach(function(l){
        $sel.append(optionHtml(l, l, String(l) === String(current)));
      });
      setSelectValue($sel, current, '');
    } else {
      setSelectValue($sel, current, '');
    }
  }

  function renderModalidad(d){
    d = d || {};
    var $wrap = $('#evfe-modalidad-wrap');
    var $sel = $('#ed_modalidad');
    var labels = d.modalidad_labels || {presencial:'Presencial', virtual:'Virtual'};
    var allowed = Array.isArray(d.allowed_modalidades) && d.allowed_modalidades.length ? d.allowed_modalidades : ['presencial'];
    var current = d.modalidad || allowed[0] || 'presencial';

    $sel.empty();
    allowed.forEach(function(m){
      $sel.append(optionHtml(m, labels[m] || m, String(m) === String(current)));
    });
    setSelectValue($sel, current, allowed[0] || 'presencial');
    $wrap.show();

    var fixed = allowed.length <= 1;
    $sel.prop('disabled', false);
    $('#evfe-modalidad-help').text(d.modalidad_help || (fixed ? 'La modalidad está fijada por la configuración del evento.' : 'Este evento permite cambiar el ticket entre Presencial y Virtual.'));

    var isVirtual = current === 'virtual';
    var $pre = $('#evfe-preprinted-wrap');
    if($pre.length){
      $pre.toggle(!isVirtual);
      if(isVirtual) $('#ed_preprinted_qr_id').val('');
    }

    var $access = $('#evfe-virtual-access').empty();
    if(isVirtual){
      if(d.virtual_url){
        $access.html('<a class="evfe-virtual-link" href="'+escAttr(d.virtual_url)+'" target="_blank" rel="noopener noreferrer">Abrir acceso virtual</a>').show();
      } else {
        $access.html('<small style="color:#555">El enlace de acceso virtual se generará cuando el ticket tenga ID público.</small>').show();
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
      $('#evfe-checkin-wrap').hide();
    }
  });

  function renderSesiones(sesiones, sesionesAcceso){
    var $list = $('#evfe-sesiones-list').empty();
    sesiones = Array.isArray(sesiones) ? sesiones : [];
    sesionesAcceso = Array.isArray(sesionesAcceso) ? sesionesAcceso : [];

    if (sesiones.length){
      sesiones.forEach(function(s){
        var checked = (sesionesAcceso.indexOf(s)>=0) ? 'checked' : '';
        $list.append('<label style="display:flex;align-items:center;gap:8px;border:1px solid #eee;border-radius:8px;padding:8px;background:#fafbfc;">'
                     +'<input type="checkbox" name="ed_sesiones[]" value="'+escAttr(s)+'" '+checked+'> '+escHtml(s)+'</label>');
      });
      $('#evfe-sesiones').show();
    } else {
      $('#evfe-sesiones').hide();
    }
  }

  // Cargar datos del ticket en el formulario
  $(document).on('click', '.evfe-edit', function(e){
    e.preventDefault();
    var tid = $(this).data('ticket-id');
    if(!tid){ alert('Ticket inválido.'); return; }

    $.getJSON(EvFrontEdit.ajax_url, {
      action: 'eventosapp_front_get_ticket',
      security: EvFrontEdit.get_nonce,
      ticket_id: tid
    }).done(function(resp){
      if(!resp || !resp.success || !resp.data){ alert('No se pudo cargar el ticket.'); return; }
      var d = resp.data || {};

      // Campos base
      $('#ed_ticket_id').val(d.ticket_id || '');
      $('#ed_nombre').val(d.nombre||'');
      $('#ed_apellido').val(d.apellido||'');
      $('#ed_cc').val(d.cc||'');
      $('#ed_email').val(d.email||'');
      $('#ed_tel').val(d.tel||'');
      $('#ed_empresa').val(d.empresa||'');
      $('#ed_nit').val(d.nit||'');
      $('#ed_cargo').val(d.cargo||'');
      $('#ed_ciudad').val(d.ciudad||'');
      setSelectValue($('#ed_pais'), d.pais || 'Colombia', 'Colombia');

      renderExtras(d.extras_schema, d.extras_values);
      renderLocalidades(d.localidades, d.localidad || '');
      renderModalidad(d);

      // Preimpreso
      $('#ed_preprinted_qr_id').val(d.preprinted||'');

      // Sesiones checkbox
      renderSesiones(d.sesiones, d.sesiones_acceso);

      // Check-in hoy (igual que el buscador). Los tickets virtuales no usan check-in presencial.
      if (d.is_virtual) {
        $('#evfe-checkin-wrap').hide();
      } else if (typeof d.today_allowed !== 'undefined') {
        setBadge(d.today_status || 'not_checked_in');
        $('#evfe-checkin-wrap').css('display','flex');
        var $btn = $('#evfe-toggle-checkin');
        $btn.prop('disabled', !d.today_allowed);
        $('#evfe-checkin-note').text(d.today_allowed ? '' : EvFrontEdit.msgs.not_allowed);
      } else {
        $('#evfe-checkin-wrap').hide();
      }

      // Mostrar formulario + scroll y focus
      $form.stop(true, true).slideDown(140, function(){
        var adminBar = $('#wpadminbar').length ? $('#wpadminbar').outerHeight() : 0;
        var top = Math.max(0, $form.offset().top - adminBar - 12);
        window.scrollTo({top: top, behavior: 'smooth'});
        $('#ed_nombre').trigger('focus');
        $form.addClass('evfe-form-highlight');
        setTimeout(function(){ $form.removeClass('evfe-form-highlight'); }, 1200);
      });

      // Nota del mail limpia
      $('#evfe_mail_note').text('');
    }).fail(function(){
      alert('Error de red.');
    });
  });

  $form.on('submit', function(){
    var $btn = $form.find('.evfe-submit');
    if($btn.data('evfe-submitting')){
      return false;
    }
    $btn.data('evfe-submitting', true).prop('disabled', true).text(EvFrontEdit.msgs.saving || 'Guardando cambios…');
    return true;
  });

  // Toggle Check-in (usando el MISMO endpoint del buscador)
  $('#evfe-toggle-checkin').on('click', function(){
    var tid = $('#ed_ticket_id').val();
    if(!tid){ alert('Primero carga un ticket.'); return; }
    $('#evfe-checkin-note').text('Actualizando...');
    $('#evfe-toggle-checkin').prop('disabled', true);

    $.post(EvFrontEdit.ajax_url, {
      action: 'eventosapp_front_toggle_checkin',
      security: EvFrontEdit.toggle_nonce,
      ticket_id: tid
    }, function(resp){
      if(resp && resp.success && resp.data){
        setBadge(resp.data.today_status);
        $('#evfe-checkin-note').html('<span class="evfe-note">Estado actualizado.</span>');
        $('#evfe-toggle-checkin').prop('disabled', false);
      } else {
        var m = (resp && resp.data && resp.data.message) ? resp.data.message : EvFrontEdit.msgs.not_allowed;
        $('#evfe-checkin-note').text(m);
        $('#evfe-toggle-checkin').prop('disabled', false);
      }
    }, 'json').fail(function(xhr){
      var msg = EvFrontEdit.msgs.net_error;
      try {
        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          msg = xhr.responseJSON.data.message;
        } else if (xhr.responseText) {
          var j = JSON.parse(xhr.responseText);
          if (j && j.data && j.data.message) msg = j.data.message;
        }
      } catch(e){}
      $('#evfe-checkin-note').text(msg);
      $('#evfe-toggle-checkin').prop('disabled', false);
    });
  });

  // Reenviar correo
  $('#evfe_send_mail').on('click', function(){
    var tid = $('#ed_ticket_id').val();
    if(!tid){ alert('Primero carga un ticket.'); return; }
    var alt = $('#evfe_email_alt').val().trim();
    $('#evfe_mail_note').text('Enviando…');
    $.post(EvFrontEdit.ajax_url, {
      action: 'eventosapp_front_send_ticket_email',
      security: EvFrontEdit.mail_nonce,
      ticket_id: tid,
      alt_email: alt
    }, function(resp){
      if(resp && resp.success){
        $('#evfe_mail_note').html('<span class="evfe-note">Correo enviado.</span>');
      } else {
        var m = (resp && resp.data && resp.data.message) ? resp.data.message : 'No se pudo enviar el correo.';
        $('#evfe_mail_note').text(m);
      }
    }, 'json').fail(function(){
      $('#evfe_mail_note').text(EvFrontEdit.msgs.net_error);
    });
  });
});
JS;

    wp_add_inline_script('eventosapp-front-edit', $js);
    wp_enqueue_script('eventosapp-front-edit');

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
