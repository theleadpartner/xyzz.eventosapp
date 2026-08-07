<?php
/**
 * Frontend: Registro manual de asistentes (creación / actualización de tickets)
 * Shortcode: [eventosapp_front_register]
 * Requiere: evento activo elegido desde el dashboard (o event_id en el shortcode)
 *
 * UI renovada para conservar la misma línea visual del Dashboard de EventosApp.
 * Mantiene compatibilidad con:
 * - permisos y evento activo
 * - modalidades Presencial / Virtual / Presencial + Virtual
 * - QR preimpreso
 * - campos adicionales
 * - deduplicación por cédula + evento
 * - Variantes de Tickets
 * - Envío manual por correo con segundo control por ticket
 * - WhatsApp y anexos mediante eventosapp_frontend_ticket_created
 * - Segundo control frontend de entrega por WhatsApp
 * - Elementor
 */

if ( ! defined('ABSPATH') ) exit;

/** Permisos: organizador/staff/admin */
if ( ! function_exists('eventosapp_user_can_front_tools') ) {
    function eventosapp_user_can_front_tools() {
        if ( ! is_user_logged_in() ) return false;

        $u = wp_get_current_user();

        if ( user_can($u, 'manage_options') ) return true;

        $allowed = array('organizador', 'staff', 'logistico');
        return (bool) array_intersect($allowed, (array) $u->roles);
    }
}

/** Puede gestionar el evento (usa la feature "register" del dashboard) */
if ( ! function_exists('eventosapp_user_can_manage_event') ) {
    function eventosapp_user_can_manage_event( $evento_id ) {
        if ( ! is_user_logged_in() ) return false;

        $u = wp_get_current_user();

        // Admin siempre puede.
        if ( user_can($u, 'manage_options') ) return true;

        // Si el sistema de visibilidad le otorga la feature "register", puede gestionar.
        if ( function_exists('eventosapp_role_can') && eventosapp_role_can('register', $u->ID) ) {
            return true;
        }

        // Fallback: autor del evento (compatibilidad con comportamiento anterior).
        $evento = get_post( $evento_id );
        if ( ! $evento || $evento->post_type !== 'eventosapp_event' ) return false;

        return (int) $evento->post_author === (int) $u->ID;
    }
}

/**
 * Disponibilidad de canales para el Registro Manual.
 *
 * El backend actúa como control maestro:
 * - Correo: _eventosapp_ticket_auto_email_manual
 * - WhatsApp: _eventosapp_ticket_auto_whatsapp_manual
 *   + _eventosapp_ticket_whatsapp_enabled
 *   + integración global de WhatsApp activa.
 *
 * El formulario frontend agrega un segundo control por cada registro.
 */
if ( ! function_exists('eventosapp_front_register_get_delivery_availability') ) {
    function eventosapp_front_register_get_delivery_availability( $event_id ) {
        $event_id = absint($event_id);

        $availability = [
            'email'            => false,
            'whatsapp'         => false,
            'whatsapp_manual'  => false,
            'whatsapp_event'   => false,
            'whatsapp_global'  => false,
            'whatsapp_runtime' => false,
        ];

        if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
            return $availability;
        }

        $availability['email'] = (
            get_post_meta(
                $event_id,
                '_eventosapp_ticket_auto_email_manual',
                true
            ) === '1'
        );

        $availability['whatsapp_manual'] = (
            get_post_meta(
                $event_id,
                '_eventosapp_ticket_auto_whatsapp_manual',
                true
            ) === '1'
        );

        $availability['whatsapp_event'] = (
            get_post_meta(
                $event_id,
                '_eventosapp_ticket_whatsapp_enabled',
                true
            ) === '1'
        );

        $availability['whatsapp_runtime'] = (
            function_exists('eventosapp_whatsapp_send_ticket')
            || function_exists('eventosapp_whatsapp_maybe_send_after_ticket_creation')
            || has_action('eventosapp_frontend_ticket_created')
        );

        if ( function_exists('eventosapp_whatsapp_get_settings') ) {
            $whatsapp_settings = eventosapp_whatsapp_get_settings();

            $availability['whatsapp_global'] = (
                is_array($whatsapp_settings)
                && ! empty($whatsapp_settings['enabled'])
                && (string) $whatsapp_settings['enabled'] === '1'
            );
        } else {
            // Compatibilidad: si el módulo está cargado pero no expone settings,
            // dejamos que su propia validación de runtime sea la autoridad final.
            $availability['whatsapp_global'] = $availability['whatsapp_runtime'];
        }

        $availability['whatsapp'] = (
            $availability['whatsapp_manual']
            && $availability['whatsapp_event']
            && $availability['whatsapp_global']
            && $availability['whatsapp_runtime']
        );

        return $availability;
    }
}

/**
 * Detectar contexto Elementor/AJAX.
 */
if ( ! function_exists('eventosapp_is_elementor_context') ) {
    function eventosapp_is_elementor_context() {
        if ( isset($_GET['elementor-preview']) || isset($_GET['elementor_library']) || isset($_GET['elementor']) ) {
            return true;
        }

        if ( function_exists('wp_doing_ajax') && wp_doing_ajax() ) {
            return true;
        }

        if ( did_action('elementor/loaded') && class_exists('\\Elementor\\Plugin') ) {
            try {
                $pl = \Elementor\Plugin::$instance;

                if ( method_exists($pl, 'editor') && $pl->editor && $pl->editor->is_edit_mode() ) {
                    return true;
                }

                if ( property_exists($pl, 'preview') && $pl->preview && $pl->preview->is_preview_mode() ) {
                    return true;
                }
            } catch (\Throwable $e) {}
        }

        return false;
    }
}

/**
 * ========= AJAX endpoint (sin recarga) =========
 * action: eventosapp_evreg_submit
 */
add_action('wp_ajax_eventosapp_evreg_submit', 'eventosapp_evreg_submit');
add_action('wp_ajax_nopriv_eventosapp_evreg_submit', 'eventosapp_evreg_submit');

function eventosapp_evreg_submit() {
    header('Content-Type: application/json; charset=' . get_option('blog_charset'));

    // Nonce AJAX.
    check_ajax_referer('eventosapp_evreg_ajax', 'nonce');

    // Permisos base.
    if ( ! eventosapp_user_can_front_tools() ) {
        wp_send_json_error(
            ['message' => 'No tienes permisos para usar esta herramienta.'],
            403
        );
    }

    // Evento activo o pasado en POST.
    $eid = isset($_POST['evreg_event_id']) ? absint($_POST['evreg_event_id']) : 0;

    if ( ! $eid && function_exists('eventosapp_get_active_event') ) {
        $eid = (int) eventosapp_get_active_event();
    }

    if ( ! $eid ) {
        wp_send_json_error(['message' => 'Debes seleccionar un evento activo.']);
    }

    if ( ! eventosapp_user_can_manage_event($eid) ) {
        wp_send_json_error(
            ['message' => 'No tienes permisos sobre este evento.'],
            403
        );
    }

    // Segundo control de entrega por registro.
    // El POST nunca puede habilitar un canal que esté desactivado en backend.
    $delivery_availability = eventosapp_front_register_get_delivery_availability($eid);

    $send_email = (
        ! empty($delivery_availability['email'])
        && isset($_POST['evreg_send_email'])
        && (string) wp_unslash($_POST['evreg_send_email']) === '1'
    );

    $send_whatsapp = (
        ! empty($delivery_availability['whatsapp'])
        && isset($_POST['evreg_send_whatsapp'])
        && (string) wp_unslash($_POST['evreg_send_whatsapp']) === '1'
    );

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

    // Localidades válidas.
    $localidades = get_post_meta($eid, '_eventosapp_localidades', true);

    if ( ! is_array($localidades) || empty($localidades) ) {
        $localidades = ['General', 'VIP', 'Platino'];
    }

    $localidades_allowed = array_fill_keys(array_map('strval', $localidades), true);

    // Modalidad del ticket según la modalidad del evento.
    $event_modalidad = function_exists('eventosapp_get_event_modalidad')
        ? eventosapp_get_event_modalidad($eid)
        : (get_post_meta($eid, '_eventosapp_event_modalidad', true) ?: 'presencial');

    $event_modalidad = function_exists('eventosapp_normalize_event_modalidad')
        ? eventosapp_normalize_event_modalidad($event_modalidad)
        : (
            in_array($event_modalidad, ['presencial', 'virtual', 'presencial_virtual'], true)
                ? $event_modalidad
                : 'presencial'
        );

    $requested_modalidad = isset($_POST['as_ticket_modalidad'])
        ? sanitize_text_field(wp_unslash($_POST['as_ticket_modalidad']))
        : '';

    if (
        $event_modalidad === 'presencial_virtual'
        && ! in_array($requested_modalidad, ['presencial', 'virtual'], true)
    ) {
        wp_send_json_error([
            'message' => 'Selecciona si el asistente participará de forma presencial o virtual.',
        ]);
    }

    $ticket_modalidad = function_exists('eventosapp_resolve_ticket_modalidad')
        ? eventosapp_resolve_ticket_modalidad($eid, $requested_modalidad, '')
        : (
            ($event_modalidad === 'virtual')
                ? 'virtual'
                : ($requested_modalidad ?: 'presencial')
        );

    $is_virtual_ticket = ($ticket_modalidad === 'virtual');

    // Token de idempotencia.
    // Importante: NO se consume hasta terminar todas las validaciones,
    // para permitir corregir el formulario sin tener que recargar la página.
    $ev_token = isset($_POST['evreg_token'])
        ? sanitize_text_field(wp_unslash($_POST['evreg_token']))
        : '';

    $tval = $ev_token ? get_transient('evreg_token_' . $ev_token) : false;

    if ( empty($ev_token) || false === $tval ) {
        wp_send_json_error([
            'message' => 'Esta solicitud ya fue procesada o el formulario expiró. Recarga e inténtalo de nuevo.',
            'code'    => 'token',
        ]);
    }

    // Campos.
    $nombre    = sanitize_text_field(wp_unslash($_POST['as_nombre'] ?? ''));
    $apellido  = sanitize_text_field(wp_unslash($_POST['as_apellido'] ?? ''));
    $cc        = sanitize_text_field(wp_unslash($_POST['as_cc'] ?? ''));
    $email     = sanitize_email(wp_unslash($_POST['as_email'] ?? ''));
    $tel       = sanitize_text_field(wp_unslash($_POST['as_tel'] ?? ''));
    $empresa   = sanitize_text_field(wp_unslash($_POST['as_empresa'] ?? ''));
    $nit       = sanitize_text_field(wp_unslash($_POST['as_nit'] ?? ''));
    $cargo     = sanitize_text_field(wp_unslash($_POST['as_cargo'] ?? ''));
    $ciudad    = sanitize_text_field(wp_unslash($_POST['as_ciudad'] ?? ''));
    $pais      = sanitize_text_field(wp_unslash($_POST['as_pais'] ?? 'Colombia'));
    $localidad = sanitize_text_field(wp_unslash($_POST['as_localidad'] ?? ''));
    $preprinted_qr_id = sanitize_text_field(
        wp_unslash($_POST['as_preprinted_qr_id'] ?? '')
    );

    if ( ! $nombre || ! $apellido || ! $email ) {
        wp_send_json_error([
            'message' => 'Completa al menos Nombre, Apellido y Email.',
        ]);
    }

    if ( ! is_email($email) ) {
        wp_send_json_error([
            'message' => 'Escribe un correo electrónico válido.',
        ]);
    }

    // Si el staff decidió enviar por WhatsApp, el teléfono pasa a ser
    // obligatorio para esta operación concreta.
    if ( $send_whatsapp ) {
        if ( trim((string) $tel) === '' ) {
            wp_send_json_error([
                'message' => 'Para enviar el ticket por WhatsApp, ingresa el número de teléfono del asistente o desactiva ese envío.',
            ]);
        }

        if (
            function_exists('eventosapp_whatsapp_normalize_phone')
            && function_exists('eventosapp_whatsapp_get_settings')
        ) {
            $wa_settings = eventosapp_whatsapp_get_settings();
            $default_country_code = is_array($wa_settings)
                ? (string) ($wa_settings['default_country_code'] ?? '57')
                : '57';

            $normalized_whatsapp_phone = eventosapp_whatsapp_normalize_phone(
                $tel,
                $default_country_code
            );

            if ( ! $normalized_whatsapp_phone ) {
                wp_send_json_error([
                    'message' => 'El teléfono no tiene un formato válido para WhatsApp. Corrígelo o desactiva el envío por WhatsApp.',
                ]);
            }
        }
    }

    if ( $localidad !== '' && empty($localidades_allowed[$localidad]) ) {
        wp_send_json_error([
            'message' => 'La localidad seleccionada no existe en este evento.',
        ]);
    }

    // Extras requeridos.
    $extras_in = [];
    $extras_schema = [];

    if ( function_exists('eventosapp_get_event_extra_fields') ) {
        $extras_schema = eventosapp_get_event_extra_fields($eid) ?: [];
        $extras_in = (
            isset($_POST['as_extra'])
            && is_array($_POST['as_extra'])
        )
            ? $_POST['as_extra']
            : [];

        foreach ( $extras_schema as $f ) {
            if ( empty($f['required']) ) continue;

            $k = isset($f['key']) ? (string) $f['key'] : '';
            $v = trim((string) ($extras_in[$k] ?? ''));

            if ( $v === '' ) {
                wp_send_json_error([
                    'message' => 'Falta el campo obligatorio: ' . ($f['label'] ?? 'Campo adicional'),
                ]);
            }
        }
    }

    // A partir de este punto la solicitud ya superó validaciones.
    // Consumimos el token antes de mutar datos para bloquear doble submit.
    delete_transient('evreg_token_' . $ev_token);

    // Preparar payload para hooks (compatibilidad con save_post existente).
    $_POST['eventosapp_ticket_nonce']        = wp_create_nonce('eventosapp_ticket_guardar');
    $_POST['eventosapp_ticket_evento_id']    = $eid;
    $_POST['eventosapp_ticket_modalidad']    = $ticket_modalidad;
    $_POST['eventosapp_ticket_user_id']      = get_current_user_id();
    $_POST['eventosapp_asistente_nombre']    = $nombre;
    $_POST['eventosapp_asistente_apellido']  = $apellido;
    $_POST['eventosapp_asistente_cc']        = $cc;
    $_POST['eventosapp_asistente_email']     = $email;
    $_POST['eventosapp_asistente_tel']       = $tel;
    $_POST['eventosapp_asistente_empresa']   = $empresa;
    $_POST['eventosapp_asistente_nit']       = $nit;
    $_POST['eventosapp_asistente_cargo']     = $cargo;
    $_POST['eventosapp_asistente_ciudad']    = $ciudad;
    $_POST['eventosapp_asistente_pais']      = $pais;
    $_POST['eventosapp_asistente_localidad'] = $localidad;

    if ( ! $is_virtual_ticket && $use_preprinted_qr && $preprinted_qr_id !== '' ) {
        $_POST['eventosapp_ticket_preprintedID'] = preg_replace(
            '/\D+/',
            '',
            (string) $preprinted_qr_id
        );
    }

    if ( ! empty($extras_in) ) {
        $_POST['eventosapp_extra'] = array_map(
            'sanitize_text_field',
            array_map('wp_unslash', $extras_in)
        );
    }

    // ── Deduplicar por (evento + cédula): reutilizar ticket si ya existe ──────
    $existing_ticket_id = (
        $cc
        && function_exists('evapp_find_ticket_by_cedula_evento')
    )
        ? evapp_find_ticket_by_cedula_evento($cc, $eid)
        : false;

    if ( $existing_ticket_id ) {
        // Actualizar ticket existente. save_post_eventosapp_ticket ya está configurado
        // para leer $_POST, así que dispararlo con wp_update_post es suficiente.
        //
        // IMPORTANTE: se marca el canal como frontend ANTES de wp_update_post.
        // Así evitamos que el hook histórico de auto-correo para channel=manual
        // envíe el ticket antes de respetar el checkbox de este formulario.
        $previous_creation_channel = get_post_meta(
            $existing_ticket_id,
            '_eventosapp_creation_channel',
            true
        );

        update_post_meta(
            $existing_ticket_id,
            '_eventosapp_creation_channel',
            'frontend'
        );

        remove_action(
            'save_post_eventosapp_ticket',
            'evapp_vincular_ticket_a_asistente',
            40
        );

        $updated = wp_update_post([
            'ID'          => $existing_ticket_id,
            'post_status' => 'publish',
        ], true);

        add_action(
            'save_post_eventosapp_ticket',
            'evapp_vincular_ticket_a_asistente',
            40,
            3
        );

        if ( is_wp_error($updated) ) {
            // Restaurar el canal anterior si la actualización no llegó a completarse.
            if ( $previous_creation_channel === '' ) {
                delete_post_meta(
                    $existing_ticket_id,
                    '_eventosapp_creation_channel'
                );
            } else {
                update_post_meta(
                    $existing_ticket_id,
                    '_eventosapp_creation_channel',
                    $previous_creation_channel
                );
            }

            wp_send_json_error([
                'message' => 'No fue posible actualizar el ticket existente. Recarga la página e inténtalo de nuevo.',
            ]);
        }

        // Llamar la vinculación con asistente directamente
        // (metas ya actualizadas por save_post).
        if ( function_exists('evapp_process_vincular_asistente') ) {
            evapp_process_vincular_asistente($existing_ticket_id);
        }

        $post_id = $existing_ticket_id;
    } else {
        // Crear ticket nuevo.
        $post_id = wp_insert_post([
            'post_type'   => 'eventosapp_ticket',
            'post_status' => 'publish',
            'post_title'  => 'tmp',
            'post_author' => get_current_user_id(),
        ], true);

        if ( is_wp_error($post_id) || ! $post_id ) {
            wp_send_json_error([
                'message' => 'Error al crear el ticket. Recarga la página e inténtalo de nuevo.',
            ]);
        }
    }

    update_post_meta($post_id, '_eventosapp_creation_channel', 'frontend');

    // Compatibilidad Variantes de Tickets:
    // asegurar que el ticket tenga calculada la variante efectiva antes de responder.
    $variant_context = $existing_ticket_id
        ? 'frontend_register_update'
        : 'frontend_register_create';

    if ( function_exists('eventosapp_ticket_variants_prepare_ticket_for_frontend_context') ) {
        eventosapp_ticket_variants_prepare_ticket_for_frontend_context(
            $post_id,
            $eid,
            $variant_context,
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
        eventosapp_ticket_variants_apply_to_ticket($post_id, $eid, true);
    }

    /**
     * Entrega seleccionada por el staff.
     *
     * Importante:
     * - La disponibilidad del backend ya fue validada antes de crear/actualizar.
     * - El correo usa source=manual para conservar la independencia de canales:
     *   el puente automático Correo -> WhatsApp del módulo WhatsApp ignora
     *   expresamente los correos manuales.
     * - WhatsApp solo recibe el hook cuando el checkbox frontend fue marcado.
     */
    $delivery_result = [
        'email' => [
            'requested' => (bool) $send_email,
            'status'    => $send_email ? 'pending' : 'not_requested',
            'message'   => '',
        ],
        'whatsapp' => [
            'requested' => (bool) $send_whatsapp,
            'status'    => $send_whatsapp ? 'pending' : 'not_requested',
            'message'   => '',
        ],
    ];

    if ( $send_email ) {
        if ( function_exists('eventosapp_send_ticket_email_now') ) {
            try {
                $email_send_result = eventosapp_send_ticket_email_now(
                    $post_id,
                    [
                        'recipient' => $email,
                        'source'    => 'manual',
                        'force'     => true,
                    ]
                );

                $email_ok = false;
                $email_message = '';

                if ( is_array($email_send_result) ) {
                    $email_ok = ! empty($email_send_result[0]);
                    $email_message = isset($email_send_result[1])
                        ? sanitize_text_field((string) $email_send_result[1])
                        : '';
                } else {
                    $email_ok = (bool) $email_send_result;
                }

                if ( $email_ok ) {
                    update_post_meta(
                        $post_id,
                        '_eventosapp_ticket_email_sent',
                        '1'
                    );

                    $delivery_result['email']['status'] = 'sent';
                    $delivery_result['email']['message'] = (
                        $email_message !== ''
                            ? $email_message
                            : 'Ticket enviado por correo.'
                    );
                } else {
                    $delivery_result['email']['status'] = 'error';
                    $delivery_result['email']['message'] = (
                        $email_message !== ''
                            ? $email_message
                            : 'El ticket se guardó, pero no fue posible enviarlo por correo.'
                    );
                }
            } catch (\Throwable $e) {
                $delivery_result['email']['status'] = 'error';
                $delivery_result['email']['message'] = 'El ticket se guardó, pero el envío por correo produjo un error.';
            }
        } else {
            $delivery_result['email']['status'] = 'error';
            $delivery_result['email']['message'] = 'El módulo de envío por correo no está disponible.';
        }
    }

    if ( $send_whatsapp ) {
        if ( has_action('eventosapp_frontend_ticket_created') ) {
            do_action(
                'eventosapp_frontend_ticket_created',
                $post_id,
                $eid,
                $variant_context
            );

            // El módulo WhatsApp registra el resultado del hook en el ticket.
            $whatsapp_hook_result = get_post_meta(
                $post_id,
                '_eventosapp_whatsapp_last_creation_hook_result',
                true
            );

            $whatsapp_send_result = (
                is_array($whatsapp_hook_result)
                && isset($whatsapp_hook_result['send'])
                && is_array($whatsapp_hook_result['send'])
            )
                ? $whatsapp_hook_result['send']
                : [];

            if ( ! empty($whatsapp_send_result['skipped_rules']) ) {
                $delivery_result['whatsapp']['status'] = 'skipped';
                $delivery_result['whatsapp']['message'] = (
                    ! empty($whatsapp_send_result['message'])
                        ? sanitize_text_field((string) $whatsapp_send_result['message'])
                        : 'El envío por WhatsApp fue omitido por las reglas del evento.'
                );
            } elseif ( ! empty($whatsapp_send_result['skipped_duplicate']) ) {
                $delivery_result['whatsapp']['status'] = 'skipped';
                $delivery_result['whatsapp']['message'] = (
                    ! empty($whatsapp_send_result['message'])
                        ? sanitize_text_field((string) $whatsapp_send_result['message'])
                        : 'WhatsApp omitió un envío duplicado.'
                );
            } elseif (
                array_key_exists('ok', $whatsapp_send_result)
                && ! empty($whatsapp_send_result['ok'])
            ) {
                $delivery_result['whatsapp']['status'] = 'sent';
                $delivery_result['whatsapp']['message'] = (
                    ! empty($whatsapp_send_result['message'])
                        ? sanitize_text_field((string) $whatsapp_send_result['message'])
                        : 'Solicitud de WhatsApp aceptada para envío.'
                );
            } elseif (
                array_key_exists('ok', $whatsapp_send_result)
                && empty($whatsapp_send_result['ok'])
            ) {
                $delivery_result['whatsapp']['status'] = 'error';
                $delivery_result['whatsapp']['message'] = (
                    ! empty($whatsapp_send_result['message'])
                        ? sanitize_text_field((string) $whatsapp_send_result['message'])
                        : 'El ticket se guardó, pero no fue posible enviarlo por WhatsApp.'
                );
            } else {
                // El hook pudo tener otros listeners o una versión antigua
                // del módulo WhatsApp que no persiste un resultado estructurado.
                $delivery_result['whatsapp']['status'] = 'processed';
                $delivery_result['whatsapp']['message'] = 'La solicitud de WhatsApp fue procesada por el módulo de mensajería.';
            }
        } else {
            $delivery_result['whatsapp']['status'] = 'error';
            $delivery_result['whatsapp']['message'] = 'El módulo de envío por WhatsApp no está disponible.';
        }
    }

    // ID público.
    $ticket_pub = get_post_meta($post_id, 'eventosapp_ticketID', true);
    $tid        = $ticket_pub ?: '#' . $post_id;

    wp_send_json_success([
        'tid'      => (string) $tid,
        'updated'  => (bool) $existing_ticket_id,
        'delivery' => $delivery_result,
    ]);
}

/**
 * ========= Shortcode (con AJAX) =========
 * Uso: [eventosapp_front_register] o [eventosapp_front_register event_id="123"]
 */
add_shortcode('eventosapp_front_register', function($atts) {
    if ( function_exists('eventosapp_require_feature') ) {
        eventosapp_require_feature('register');
    }

    $debug_enabled = defined('WP_DEBUG') && WP_DEBUG;
    $dbg = [];

    if ( $debug_enabled ) {
        $dbg[] = 'Shortcode eventosapp_front_register start';
    }

    // Instancias (por si Elementor evalúa más de una vez).
    if ( ! isset($GLOBALS['eventosapp_evreg_render_count']) ) {
        $GLOBALS['eventosapp_evreg_render_count'] = 0;
    }

    $GLOBALS['eventosapp_evreg_render_count']++;
    $instance = (int) $GLOBALS['eventosapp_evreg_render_count'];

    $in_elem = eventosapp_is_elementor_context();

    if ( $debug_enabled ) {
        $dbg[] = 'instance=' . $instance;
        $dbg[] = 'elementor_context=' . ($in_elem ? '1' : '0');
        $dbg[] = 'is_admin=' . (is_admin() ? '1' : '0');
        $dbg[] = 'doing_ajax=' . (
            function_exists('wp_doing_ajax') && wp_doing_ajax()
                ? '1'
                : '0'
        );
    }

    // Atributos.
    $a   = shortcode_atts(['event_id' => 0], $atts);
    $eid = absint($a['event_id']);

    if ( $debug_enabled ) {
        $dbg[] = 'eid_initial=' . $eid;
    }

    if ( ! $eid && function_exists('eventosapp_get_active_event') ) {
        $eid = (int) eventosapp_get_active_event();

        if ( $debug_enabled ) {
            $dbg[] = 'eid_from_active=' . $eid;
        }
    }

    // Si no hay evento, obligar a elegirlo.
    if ( ! $eid ) {
        if ( function_exists('eventosapp_require_active_event') ) {
            eventosapp_require_active_event();
            return '';
        }

        $dash = function_exists('eventosapp_get_dashboard_url')
            ? eventosapp_get_dashboard_url()
            : home_url('/');

        return '<div class="evreg-inline-notice evreg-inline-notice--warning">
            Debes escoger un <strong>evento activo</strong> en el
            <a href="' . esc_url($dash) . '">dashboard</a>.
        </div>';
    }

    // Permisos.
    if (
        ! current_user_can('manage_options')
        && ! eventosapp_user_can_manage_event($eid)
    ) {
        return '<div class="evreg-inline-notice evreg-inline-notice--error">
            No tienes permisos sobre este evento.
        </div>';
    }

    // QR preimpreso.
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

    if ( $debug_enabled ) {
        $dbg[] = 'use_preprinted_qr=' . ($use_preprinted_qr ? '1' : '0');
    }

    // Modalidad del evento/ticket para el formulario.
    $event_modalidad = function_exists('eventosapp_get_event_modalidad')
        ? eventosapp_get_event_modalidad($eid)
        : (get_post_meta($eid, '_eventosapp_event_modalidad', true) ?: 'presencial');

    $event_modalidad = function_exists('eventosapp_normalize_event_modalidad')
        ? eventosapp_normalize_event_modalidad($event_modalidad)
        : (
            in_array($event_modalidad, ['presencial', 'virtual', 'presencial_virtual'], true)
                ? $event_modalidad
                : 'presencial'
        );

    $ticket_modalidad_labels = function_exists('eventosapp_ticket_modalidad_options')
        ? eventosapp_ticket_modalidad_options()
        : [
            'presencial' => 'Presencial',
            'virtual'    => 'Virtual',
        ];

    $allowed_modalidades = function_exists('eventosapp_ticket_allowed_modalidades_for_event')
        ? eventosapp_ticket_allowed_modalidades_for_event($eid)
        : (
            ($event_modalidad === 'virtual')
                ? ['virtual']
                : (
                    ($event_modalidad === 'presencial_virtual')
                        ? ['presencial', 'virtual']
                        : ['presencial']
                )
        );

    $default_ticket_modalidad = reset($allowed_modalidades) ?: 'presencial';
    $show_modalidad_select = ($event_modalidad === 'presencial_virtual');

    $event_modalidad_options = function_exists('eventosapp_event_modalidad_options')
        ? eventosapp_event_modalidad_options()
        : [
            'presencial'         => 'Presencial',
            'virtual'            => 'Virtual',
            'presencial_virtual' => 'Presencial y Virtual',
        ];

    $event_modalidad_label = $event_modalidad_options[$event_modalidad]
        ?? ucfirst(str_replace('_', ' ', $event_modalidad));

    if ( $debug_enabled ) {
        $dbg[] = 'event_modalidad=' . $event_modalidad;
    }

    // Localidades.
    $localidades = get_post_meta($eid, '_eventosapp_localidades', true);

    if ( ! is_array($localidades) || empty($localidades) ) {
        $localidades = ['General', 'VIP', 'Platino'];
    }

    // Campos adicionales: se consulta una sola vez para esta renderización.
    $extras_schema = function_exists('eventosapp_get_event_extra_fields')
        ? (eventosapp_get_event_extra_fields($eid) ?: [])
        : [];

    // Canales habilitados por el backend para el segundo control frontend.
    $delivery_availability = eventosapp_front_register_get_delivery_availability($eid);
    $manual_email_available = ! empty($delivery_availability['email']);
    $manual_whatsapp_available = ! empty($delivery_availability['whatsapp']);
    $show_delivery_controls = (
        $manual_email_available
        || $manual_whatsapp_available
    );

    // URLs.
    $url_search = function_exists('eventosapp_get_search_url')
        ? eventosapp_get_search_url()
        : '';

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

    $current_url = (
        function_exists('get_permalink')
        && is_singular()
    )
        ? get_permalink()
        : home_url(add_query_arg([], $_SERVER['REQUEST_URI'] ?? ''));

    $base_url = remove_query_arg(['evreg_ok', 'evreg_updated', 'tid'], $current_url);

    if ( $debug_enabled ) {
        $dbg[] = 'current_url=' . $current_url;
    }

    // Éxito por GET/flash (compatibilidad con visitas directas / refresh).
    $flash_key = 'eventosapp_evreg_flash_'
        . get_current_user_id()
        . '_'
        . md5($current_url . '|' . (int) $eid);

    $success = false;
    $success_id = '';
    $success_updated = false;

    if ( isset($_GET['evreg_ok']) && (int) $_GET['evreg_ok'] === 1 ) {
        $success = true;
        $success_updated = (
            isset($_GET['evreg_updated'])
            && (int) $_GET['evreg_updated'] === 1
        );
        $success_id = sanitize_text_field(
            wp_unslash($_GET['tid'] ?? '')
        );
    } else {
        $flash_data = get_transient($flash_key);

        if ( is_array($flash_data) && ! empty($flash_data['tid']) ) {
            delete_transient($flash_key);
            $success = true;
            $success_updated = ! empty($flash_data['updated']);
            $success_id = (string) $flash_data['tid'];
        }
    }

    if ( $debug_enabled ) {
        $dbg[] = 'success_pre=' . ($success ? '1' : '0')
            . '; success_id=' . $success_id;
    }

    $event_label = get_the_title($eid) ?: ('Evento #' . $eid);

    // UI.
    $uid = function_exists('wp_unique_id')
        ? wp_unique_id('evreg-')
        : ('evreg-' . uniqid());

    $processing_id = $uid . '-processing';
    $form_id = $uid . '-form';

    // Token de idempotencia (5 min) solo UI.
    $evreg_token = wp_generate_uuid4();
    set_transient(
        'evreg_token_' . $evreg_token,
        1,
        5 * MINUTE_IN_SECONDS
    );

    ob_start();
    ?>
    <div
        id="<?php echo esc_attr($uid); ?>"
        class="evreg-wrap<?php echo $success ? ' evreg-success' : ''; ?>"
        data-instance="<?php echo esc_attr($instance); ?>"
    >
        <div class="evreg-shell">
            <header class="evreg-header">
                <div class="evreg-heading">
                    <p class="evreg-eyebrow">EVENTOSAPP</p>
                    <h1 class="evreg-main-title">Registro Manual de Asistentes</h1>
                    <p class="evreg-main-subtitle">
                        Crea o actualiza asistentes desde el panel operativo y genera su ticket sin salir del evento activo.
                    </p>
                </div>

                <div class="evreg-header-actions">
                    <a
                        href="<?php echo esc_url($dashboard_url); ?>"
                        class="evreg-btn evreg-btn-secondary"
                        aria-label="Volver al dashboard"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M15 18l-6-6 6-6"></path>
                        </svg>
                        <span>Volver al dashboard</span>
                    </a>
                </div>
            </header>

            <section class="evreg-event-context" aria-label="Evento activo">
                <div class="evreg-event-context-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <rect x="3" y="5" width="18" height="16" rx="2"></rect>
                        <path d="M7 3v4M17 3v4M3 9h18"></path>
                    </svg>
                </div>

                <div class="evreg-event-context-copy">
                    <span class="evreg-event-label">Evento activo</span>
                    <strong class="evreg-event-name">
                        <?php echo esc_html($event_label); ?>
                    </strong>
                    <span class="evreg-event-meta">
                        <?php echo esc_html($event_modalidad_label); ?>
                    </span>
                </div>

                <a
                    class="evreg-btn evreg-btn-primary evreg-change-event"
                    href="<?php echo esc_url($change_event_url); ?>"
                >
                    Cambiar evento
                </a>
            </section>

            <div
                class="evreg-live-region"
                aria-live="polite"
                aria-atomic="true"
            ></div>

            <?php if ( $success ): ?>
                <section class="evreg-card-success" role="status">
                    <div class="evreg-success-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <path d="M20 6 9 17l-5-5"></path>
                        </svg>
                    </div>

                    <div class="evreg-success-copy">
                        <div class="evreg-success-title">
                            <?php echo $success_updated
                                ? '¡Ticket actualizado correctamente!'
                                : '¡Ticket creado correctamente!'; ?>
                        </div>

                        <?php if ( $success_id ): ?>
                            <div class="evreg-success-id">
                                ID del Ticket:
                                <strong><?php echo esc_html($success_id); ?></strong>
                            </div>
                        <?php endif; ?>

                        <div class="evreg-actions">
                            <a
                                href="<?php echo esc_url($base_url); ?>"
                                class="evreg-btn evreg-btn-success"
                            >
                                Crear otro asistente
                            </a>

                            <?php if ( $url_search ): ?>
                                <a
                                    href="<?php echo esc_url($url_search); ?>"
                                    class="evreg-btn evreg-btn-primary"
                                >
                                    Ir a Check-In
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <form
                id="<?php echo esc_attr($form_id); ?>"
                method="post"
                class="evreg-form"
                autocomplete="off"
                novalidate
                <?php echo $success ? 'style="display:none;"' : ''; ?>
            >
                <?php wp_nonce_field('eventosapp_front_register'); ?>

                <input
                    type="hidden"
                    name="evreg_action"
                    value="create_ticket"
                >

                <input
                    type="hidden"
                    name="evreg_event_id"
                    value="<?php echo esc_attr($eid); ?>"
                >

                <input
                    type="hidden"
                    name="evreg_token"
                    value="<?php echo esc_attr($evreg_token); ?>"
                >

                <?php if ( ! $show_modalidad_select ): ?>
                    <input
                        type="hidden"
                        name="as_ticket_modalidad"
                        value="<?php echo esc_attr($default_ticket_modalidad); ?>"
                    >
                <?php endif; ?>

                <section class="evreg-form-section">
                    <div class="evreg-section-heading">
                        <div class="evreg-section-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24">
                                <circle cx="12" cy="8" r="4"></circle>
                                <path d="M4 21c1-5 4-7 8-7s7 2 8 7"></path>
                            </svg>
                        </div>

                        <div>
                            <h2 class="evreg-title">Datos del asistente</h2>
                            <p class="evreg-sub">
                                Los campos marcados con
                                <span class="evreg-required-mark">*</span>
                                son obligatorios.
                            </p>
                        </div>
                    </div>

                    <div class="evreg-grid evreg-grid--3">
                        <div class="evreg-field">
                            <label
                                class="evreg-label"
                                for="<?php echo esc_attr($uid); ?>-nombre"
                            >
                                Nombre <span class="evreg-required-mark">*</span>
                            </label>

                            <input
                                id="<?php echo esc_attr($uid); ?>-nombre"
                                class="evreg-input"
                                type="text"
                                name="as_nombre"
                                maxlength="120"
                                required
                                autofocus
                            >
                        </div>

                        <div class="evreg-field">
                            <label
                                class="evreg-label"
                                for="<?php echo esc_attr($uid); ?>-apellido"
                            >
                                Apellido <span class="evreg-required-mark">*</span>
                            </label>

                            <input
                                id="<?php echo esc_attr($uid); ?>-apellido"
                                class="evreg-input"
                                type="text"
                                name="as_apellido"
                                maxlength="120"
                                required
                            >
                        </div>

                        <div class="evreg-field">
                            <label
                                class="evreg-label"
                                for="<?php echo esc_attr($uid); ?>-cc"
                            >
                                Cédula / identificación
                            </label>

                            <input
                                id="<?php echo esc_attr($uid); ?>-cc"
                                class="evreg-input"
                                type="text"
                                name="as_cc"
                                maxlength="80"
                                inputmode="text"
                            >

                            <small class="evreg-help">
                                Si ya existe en este evento, se actualizará el ticket existente.
                            </small>
                        </div>

                        <div class="evreg-field">
                            <label
                                class="evreg-label"
                                for="<?php echo esc_attr($uid); ?>-email"
                            >
                                Email <span class="evreg-required-mark">*</span>
                            </label>

                            <input
                                id="<?php echo esc_attr($uid); ?>-email"
                                class="evreg-input"
                                type="email"
                                name="as_email"
                                maxlength="190"
                                inputmode="email"
                                required
                            >
                        </div>

                        <div class="evreg-field">
                            <label
                                class="evreg-label"
                                for="<?php echo esc_attr($uid); ?>-tel"
                            >
                                Teléfono
                            </label>

                            <input
                                id="<?php echo esc_attr($uid); ?>-tel"
                                class="evreg-input"
                                type="text"
                                name="as_tel"
                                maxlength="80"
                                inputmode="tel"
                            >
                        </div>
                    </div>
                </section>

                <section class="evreg-form-section">
                    <div class="evreg-section-heading">
                        <div class="evreg-section-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24">
                                <path d="M4 21V7l8-4 8 4v14"></path>
                                <path d="M8 10h2M14 10h2M8 14h2M14 14h2M9 21v-4h6v4"></path>
                            </svg>
                        </div>

                        <div>
                            <h2 class="evreg-title">Perfil y contacto</h2>
                            <p class="evreg-sub">
                                Información complementaria para segmentación, acreditación y operación.
                            </p>
                        </div>
                    </div>

                    <div class="evreg-grid evreg-grid--3">
                        <div class="evreg-field">
                            <label
                                class="evreg-label"
                                for="<?php echo esc_attr($uid); ?>-empresa"
                            >
                                Empresa
                            </label>

                            <input
                                id="<?php echo esc_attr($uid); ?>-empresa"
                                class="evreg-input"
                                type="text"
                                name="as_empresa"
                                maxlength="190"
                            >
                        </div>

                        <div class="evreg-field">
                            <label
                                class="evreg-label"
                                for="<?php echo esc_attr($uid); ?>-nit"
                            >
                                NIT
                            </label>

                            <input
                                id="<?php echo esc_attr($uid); ?>-nit"
                                class="evreg-input"
                                type="text"
                                name="as_nit"
                                maxlength="80"
                            >
                        </div>

                        <div class="evreg-field">
                            <label
                                class="evreg-label"
                                for="<?php echo esc_attr($uid); ?>-cargo"
                            >
                                Cargo
                            </label>

                            <input
                                id="<?php echo esc_attr($uid); ?>-cargo"
                                class="evreg-input"
                                type="text"
                                name="as_cargo"
                                maxlength="160"
                            >
                        </div>

                        <div class="evreg-field">
                            <label
                                class="evreg-label"
                                for="<?php echo esc_attr($uid); ?>-ciudad"
                            >
                                Ciudad
                            </label>

                            <input
                                id="<?php echo esc_attr($uid); ?>-ciudad"
                                class="evreg-input"
                                type="text"
                                name="as_ciudad"
                                maxlength="140"
                            >
                        </div>

                        <div class="evreg-field">
                            <label
                                class="evreg-label"
                                for="<?php echo esc_attr($uid); ?>-pais"
                            >
                                País
                            </label>

                            <select
                                id="<?php echo esc_attr($uid); ?>-pais"
                                class="evreg-input"
                                name="as_pais"
                            >
                                <?php
                                $countries = function_exists('eventosapp_get_countries')
                                    ? eventosapp_get_countries()
                                    : ['Colombia'];

                                foreach ( $countries as $c ) {
                                    ?>
                                    <option
                                        value="<?php echo esc_attr($c); ?>"
                                        <?php selected($c, 'Colombia'); ?>
                                    >
                                        <?php echo esc_html($c); ?>
                                    </option>
                                    <?php
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </section>

                <section class="evreg-form-section">
                    <div class="evreg-section-heading">
                        <div class="evreg-section-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24">
                                <path d="M12 3 4 7v5c0 5 3.3 9.3 8 10 4.7-.7 8-5 8-10V7l-8-4Z"></path>
                                <path d="m9 12 2 2 4-4"></path>
                            </svg>
                        </div>

                        <div>
                            <h2 class="evreg-title">Acceso al evento</h2>
                            <p class="evreg-sub">
                                Define modalidad, localidad y datos operativos asociados al ticket.
                            </p>
                        </div>
                    </div>

                    <div class="evreg-grid evreg-grid--3">
                        <?php if ( $show_modalidad_select ): ?>
                            <div class="evreg-field">
                                <label
                                    class="evreg-label"
                                    for="<?php echo esc_attr($uid); ?>-modalidad"
                                >
                                    Modalidad
                                    <span class="evreg-required-mark">*</span>
                                </label>

                                <select
                                    class="evreg-input"
                                    id="<?php echo esc_attr($uid); ?>-modalidad"
                                    name="as_ticket_modalidad"
                                    required
                                    data-controls-preprinted="1"
                                >
                                    <?php foreach ( $allowed_modalidades as $mod_key ): ?>
                                        <option
                                            value="<?php echo esc_attr($mod_key); ?>"
                                            <?php selected($default_ticket_modalidad, $mod_key); ?>
                                        >
                                            <?php
                                            echo esc_html(
                                                $ticket_modalidad_labels[$mod_key]
                                                ?? ucfirst($mod_key)
                                            );
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <small class="evreg-help">
                                    Define si se generan recursos presenciales o acceso virtual.
                                </small>
                            </div>
                        <?php else: ?>
                            <div class="evreg-field evreg-field--readonly">
                                <span class="evreg-label">Modalidad</span>
                                <div class="evreg-readonly-value">
                                    <?php
                                    echo esc_html(
                                        $ticket_modalidad_labels[$default_ticket_modalidad]
                                        ?? ucfirst($default_ticket_modalidad)
                                    );
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="evreg-field">
                            <label
                                class="evreg-label"
                                for="<?php echo esc_attr($uid); ?>-localidad"
                            >
                                Localidad
                            </label>

                            <select
                                id="<?php echo esc_attr($uid); ?>-localidad"
                                class="evreg-input"
                                name="as_localidad"
                            >
                                <option value="">Seleccione…</option>

                                <?php foreach ( $localidades as $loc ): ?>
                                    <option value="<?php echo esc_attr($loc); ?>">
                                        <?php echo esc_html($loc); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ( $use_preprinted_qr && $event_modalidad !== 'virtual' ): ?>
                            <div
                                class="evreg-field evreg-preprinted-wrap"
                                data-presential-only="1"
                            >
                                <label
                                    class="evreg-label"
                                    for="<?php echo esc_attr($uid); ?>-preprinted"
                                >
                                    ID de QR preimpreso
                                </label>

                                <input
                                    id="<?php echo esc_attr($uid); ?>-preprinted"
                                    class="evreg-input"
                                    type="text"
                                    name="as_preprinted_qr_id"
                                    placeholder="Ej: 00012345"
                                    inputmode="numeric"
                                    maxlength="80"
                                >

                                <small class="evreg-help">
                                    Solo aplica para tickets presenciales.
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <?php if ( $extras_schema ): ?>
                    <section class="evreg-form-section">
                        <div class="evreg-section-heading">
                            <div class="evreg-section-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24">
                                    <path d="M5 4h14v16H5z"></path>
                                    <path d="M8 8h8M8 12h8M8 16h5"></path>
                                </svg>
                            </div>

                            <div>
                                <h2 class="evreg-title">Campos adicionales</h2>
                                <p class="evreg-sub">
                                    Completa la información personalizada configurada para este evento.
                                </p>
                            </div>
                        </div>

                        <div class="evreg-grid evreg-grid--3 evreg-grid--extras">
                            <?php foreach ( $extras_schema as $index => $f ): ?>
                                <?php
                                $field_key = isset($f['key'])
                                    ? (string) $f['key']
                                    : ('extra_' . $index);

                                $field_label = isset($f['label'])
                                    ? (string) $f['label']
                                    : 'Campo adicional';

                                $field_type = isset($f['type'])
                                    ? sanitize_key($f['type'])
                                    : 'text';

                                $field_required = ! empty($f['required']);
                                $field_name = 'as_extra[' . $field_key . ']';
                                $field_id = $uid . '-extra-' . $index;
                                ?>
                                <div class="evreg-field">
                                    <label
                                        class="evreg-label"
                                        for="<?php echo esc_attr($field_id); ?>"
                                    >
                                        <?php echo esc_html($field_label); ?>

                                        <?php if ( $field_required ): ?>
                                            <span class="evreg-required-mark">*</span>
                                        <?php endif; ?>
                                    </label>

                                    <?php if ( $field_type === 'number' ): ?>
                                        <input
                                            id="<?php echo esc_attr($field_id); ?>"
                                            class="evreg-input"
                                            type="number"
                                            name="<?php echo esc_attr($field_name); ?>"
                                            <?php echo $field_required ? 'required' : ''; ?>
                                        >
                                    <?php elseif (
                                        $field_type === 'select'
                                        && ! empty($f['options'])
                                        && is_array($f['options'])
                                    ): ?>
                                        <select
                                            id="<?php echo esc_attr($field_id); ?>"
                                            class="evreg-input"
                                            name="<?php echo esc_attr($field_name); ?>"
                                            <?php echo $field_required ? 'required' : ''; ?>
                                        >
                                            <option value="">Seleccione…</option>

                                            <?php foreach ( $f['options'] as $op ): ?>
                                                <option value="<?php echo esc_attr($op); ?>">
                                                    <?php echo esc_html($op); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input
                                            id="<?php echo esc_attr($field_id); ?>"
                                            class="evreg-input"
                                            type="text"
                                            name="<?php echo esc_attr($field_name); ?>"
                                            <?php echo $field_required ? 'required' : ''; ?>
                                        >
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ( $show_delivery_controls ): ?>
                    <section class="evreg-form-section evreg-delivery-section">
                        <div class="evreg-section-heading">
                            <div class="evreg-section-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24">
                                    <path d="M4 6h16v12H4z"></path>
                                    <path d="m4 7 8 6 8-6"></path>
                                </svg>
                            </div>

                            <div>
                                <h2 class="evreg-title">Entrega del ticket</h2>
                                <p class="evreg-sub">
                                    Elige los canales que se usarán para este registro.
                                    Puedes activar o desactivar cada envío antes de crear el ticket.
                                </p>
                            </div>
                        </div>

                        <div class="evreg-delivery-grid">
                            <?php if ( $manual_email_available ): ?>
                                <label class="evreg-delivery-option">
                                    <input
                                        class="evreg-delivery-checkbox"
                                        type="checkbox"
                                        name="evreg_send_email"
                                        value="1"
                                        checked
                                    >

                                    <span
                                        class="evreg-delivery-check"
                                        aria-hidden="true"
                                    >
                                        <svg viewBox="0 0 24 24">
                                            <path d="m5 12 4 4L19 6"></path>
                                        </svg>
                                    </span>

                                    <span class="evreg-delivery-option-copy">
                                        <strong>Enviar por correo</strong>
                                        <small>
                                            Usa el email del asistente y las configuraciones actuales del ticket.
                                        </small>
                                    </span>
                                </label>
                            <?php endif; ?>

                            <?php if ( $manual_whatsapp_available ): ?>
                                <label class="evreg-delivery-option">
                                    <input
                                        class="evreg-delivery-checkbox"
                                        type="checkbox"
                                        name="evreg_send_whatsapp"
                                        value="1"
                                        checked
                                    >

                                    <span
                                        class="evreg-delivery-check"
                                        aria-hidden="true"
                                    >
                                        <svg viewBox="0 0 24 24">
                                            <path d="m5 12 4 4L19 6"></path>
                                        </svg>
                                    </span>

                                    <span class="evreg-delivery-option-copy">
                                        <strong>Enviar por WhatsApp</strong>
                                        <small>
                                            Requiere un teléfono válido y respeta las reglas de WhatsApp del evento.
                                        </small>
                                    </span>
                                </label>
                            <?php endif; ?>
                        </div>

                        <p class="evreg-delivery-note">
                            Estos controles aparecen porque los canales están habilitados en la configuración del evento.
                            Desmarcarlos afecta únicamente este ticket.
                        </p>
                    </section>
                <?php endif; ?>

                <div
                    id="<?php echo esc_attr($processing_id); ?>"
                    class="evreg-processing"
                    role="status"
                    aria-live="polite"
                    hidden
                >
                    <span class="evspinner" aria-hidden="true"></span>
                    <span>
                        <strong>Procesando ticket…</strong>
                        Espera unos segundos y no cierres esta página.
                    </span>
                </div>

                <footer class="evreg-submit">
                    <div class="evreg-submit-copy">
                        <strong>Listo para guardar</strong>
                        <span>
                            El ticket conservará las configuraciones actuales del evento.
                        </span>
                    </div>

                    <button
                        type="submit"
                        class="evreg-btn evreg-btn-primary evbtn-submit"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M5 12h14M13 6l6 6-6 6"></path>
                        </svg>
                        <span class="evreg-submit-label">Crear Ticket</span>
                    </button>
                </footer>
            </form>
        </div>
    </div>

    <style>
        /* ============================================================
         * EventosApp — Registro Manual
         * Línea gráfica alineada con el dashboard moderno.
         * Todo queda encapsulado dentro de .evreg-wrap.
         * ========================================================== */

        .elementor .evreg-safe::before,
        .elementor .evreg-safe::after {
            content: none !important;
            display: none !important;
        }

        .evreg-wrap {
            --evapp-primary: #3279bd;
            --evapp-primary-dark: #255f96;
            --evapp-primary-soft: #eaf4ff;
            --evapp-app-bg: #f5f8fc;
            --evapp-surface: #ffffff;
            --evapp-border: #dfe7f1;
            --evapp-text: #182230;
            --evapp-muted: #64748b;
            --evapp-success: #15803d;
            --evapp-success-soft: #ecfdf3;
            --evapp-danger: #b42318;
            --evapp-danger-soft: #fff1f0;
            --evapp-warning: #b45309;
            --evapp-warning-soft: #fff8e7;

            position: relative;
            z-index: 0;
            width: 100%;
            max-width: 1180px;
            margin: 0 auto;
            color: var(--evapp-text);
            font-family: inherit;
            line-height: 1.45;
            isolation: isolate;
        }

        .evreg-wrap,
        .evreg-wrap * {
            box-sizing: border-box;
        }

        .evreg-wrap a {
            text-decoration: none;
        }

        .evreg-wrap svg {
            display: block;
            width: 1em;
            height: 1em;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .evreg-shell {
            width: 100%;
            padding: clamp(18px, 3vw, 36px);
            background: var(--evapp-app-bg);
            border: 1px solid var(--evapp-border);
            border-radius: 26px;
            box-shadow: 0 18px 50px rgba(31, 52, 73, .08);
        }

        .evreg-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 22px;
        }

        .evreg-heading {
            min-width: 0;
        }

        .evreg-eyebrow {
            margin: 0 0 6px;
            color: var(--evapp-primary);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .11em;
            text-transform: uppercase;
        }

        .evreg-main-title {
            margin: 0;
            color: var(--evapp-text);
            font-size: clamp(27px, 4vw, 42px);
            font-weight: 850;
            line-height: 1.08;
            letter-spacing: -.035em;
        }

        .evreg-main-subtitle {
            max-width: 760px;
            margin: 10px 0 0;
            color: var(--evapp-muted);
            font-size: 15px;
        }

        .evreg-header-actions {
            display: flex;
            flex: 0 0 auto;
            align-items: center;
            gap: 10px;
        }

        .evreg-event-context {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: center;
            gap: 14px;
            margin-bottom: 22px;
            padding: 14px;
            background: var(--evapp-surface);
            border: 1px solid var(--evapp-border);
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(31, 52, 73, .05);
        }

        .evreg-event-context-icon,
        .evreg-section-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            color: var(--evapp-primary);
            background: var(--evapp-primary-soft);
        }

        .evreg-event-context-icon {
            width: 46px;
            height: 46px;
            border-radius: 14px;
        }

        .evreg-event-context-icon svg {
            width: 24px;
            height: 24px;
        }

        .evreg-event-context-copy {
            min-width: 0;
        }

        .evreg-event-label {
            display: block;
            margin-bottom: 2px;
            color: var(--evapp-muted);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .evreg-event-name {
            display: block;
            overflow: hidden;
            color: var(--evapp-text);
            font-size: 16px;
            font-weight: 800;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .evreg-event-meta {
            display: inline-flex;
            margin-top: 3px;
            color: var(--evapp-muted);
            font-size: 12px;
            font-weight: 650;
        }

        .evreg-form {
            display: grid;
            gap: 18px;
        }

        .evreg-form-section {
            padding: clamp(18px, 2.4vw, 26px);
            background: var(--evapp-surface);
            border: 1px solid var(--evapp-border);
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(31, 52, 73, .045);
        }

        .evreg-section-heading {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 18px;
        }

        .evreg-section-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
        }

        .evreg-section-icon svg {
            width: 21px;
            height: 21px;
        }

        .evreg-title {
            margin: 0;
            color: var(--evapp-text);
            font-size: 18px;
            font-weight: 800;
            line-height: 1.25;
            letter-spacing: -.01em;
        }

        .evreg-sub {
            margin: 4px 0 0;
            color: var(--evapp-muted);
            font-size: 13px;
        }

        .evreg-required-mark {
            color: var(--evapp-danger);
            font-weight: 800;
        }

        .evreg-grid {
            display: grid;
            gap: 16px;
            width: 100%;
        }

        .evreg-grid--3 {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .evreg-grid--extras {
            align-items: start;
        }

        .evreg-field {
            min-width: 0;
        }

        .evreg-label {
            display: block;
            margin-bottom: 7px;
            color: var(--evapp-text);
            font-size: 13px;
            font-weight: 750;
        }

        .evreg-input {
            width: 100%;
            min-height: 46px;
            margin: 0;
            padding: 10px 12px;
            color: var(--evapp-text);
            background: #fbfdff;
            border: 1px solid var(--evapp-border);
            border-radius: 12px;
            box-shadow: none;
            font: inherit;
            font-size: 14px;
            outline: none;
            transition:
                border-color .18s ease,
                box-shadow .18s ease,
                background-color .18s ease;
        }

        select.evreg-input {
            padding-right: 34px;
        }

        .evreg-input:hover {
            border-color: #c8d5e5;
            background: #ffffff;
        }

        .evreg-input:focus {
            border-color: var(--evapp-primary);
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(50, 121, 189, .13);
        }

        .evreg-input[aria-invalid="true"],
        .evreg-input.evreg-invalid {
            border-color: var(--evapp-danger);
            box-shadow: 0 0 0 4px rgba(180, 35, 24, .09);
        }

        .evreg-help {
            display: block;
            margin-top: 6px;
            color: var(--evapp-muted);
            font-size: 11px;
            line-height: 1.35;
        }

        .evreg-field--readonly .evreg-label {
            margin-bottom: 7px;
        }

        .evreg-readonly-value {
            display: flex;
            align-items: center;
            min-height: 46px;
            padding: 10px 12px;
            color: var(--evapp-primary-dark);
            background: var(--evapp-primary-soft);
            border: 1px solid #d6e8fa;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 750;
        }

        .evreg-delivery-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .evreg-delivery-option {
            position: relative;
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            align-items: flex-start;
            gap: 12px;
            min-height: 92px;
            margin: 0;
            padding: 16px;
            background: #fbfdff;
            border: 1px solid var(--evapp-border);
            border-radius: 15px;
            cursor: pointer;
            transition:
                border-color .18s ease,
                box-shadow .18s ease,
                background-color .18s ease,
                transform .18s ease;
        }

        .evreg-delivery-option:hover {
            border-color: #bfd3e7;
            background: #ffffff;
            box-shadow: 0 8px 20px rgba(31, 52, 73, .055);
            transform: translateY(-1px);
        }

        .evreg-delivery-checkbox {
            position: absolute;
            width: 1px;
            height: 1px;
            margin: -1px;
            padding: 0;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
            border: 0;
        }

        .evreg-delivery-check {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            margin-top: 1px;
            color: transparent;
            background: #ffffff;
            border: 1.5px solid #b9c8d9;
            border-radius: 7px;
            transition:
                color .18s ease,
                background-color .18s ease,
                border-color .18s ease,
                box-shadow .18s ease;
        }

        .evreg-delivery-check svg {
            width: 15px;
            height: 15px;
            stroke-width: 2.7;
        }

        .evreg-delivery-checkbox:checked + .evreg-delivery-check {
            color: #ffffff;
            background: var(--evapp-primary);
            border-color: var(--evapp-primary);
            box-shadow: 0 0 0 4px rgba(50, 121, 189, .10);
        }

        .evreg-delivery-checkbox:focus-visible + .evreg-delivery-check {
            outline: 3px solid rgba(50, 121, 189, .22);
            outline-offset: 3px;
        }

        .evreg-delivery-option:has(.evreg-delivery-checkbox:checked) {
            border-color: #bcd7ef;
            background: #f8fbff;
        }

        .evreg-delivery-option-copy {
            display: grid;
            gap: 4px;
            min-width: 0;
        }

        .evreg-delivery-option-copy strong {
            color: var(--evapp-text);
            font-size: 14px;
            font-weight: 800;
            line-height: 1.3;
        }

        .evreg-delivery-option-copy small {
            color: var(--evapp-muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .evreg-delivery-note {
            margin: 12px 0 0;
            color: var(--evapp-muted);
            font-size: 11px;
            line-height: 1.45;
        }

        .evreg-delivery-result-list {
            display: grid;
            gap: 7px;
            margin-top: 12px;
        }

        .evreg-delivery-result {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 8px 10px;
            border-radius: 10px;
            font-size: 12px;
            line-height: 1.4;
        }

        .evreg-delivery-result strong {
            flex: 0 0 auto;
        }

        .evreg-delivery-result--sent {
            color: #166534;
            background: rgba(21, 128, 61, .08);
        }

        .evreg-delivery-result--skipped,
        .evreg-delivery-result--processed {
            color: #8a4b08;
            background: var(--evapp-warning-soft);
        }

        .evreg-delivery-result--error {
            color: #8b1e17;
            background: var(--evapp-danger-soft);
        }

        .evreg-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            padding: 10px 16px;
            border: 1px solid transparent;
            border-radius: 12px;
            font: inherit;
            font-size: 14px;
            font-weight: 750;
            line-height: 1;
            cursor: pointer;
            transition:
                transform .18s ease,
                background-color .18s ease,
                border-color .18s ease,
                box-shadow .18s ease,
                color .18s ease,
                opacity .18s ease;
        }

        .evreg-btn svg {
            width: 17px;
            height: 17px;
        }

        .evreg-btn:hover {
            transform: translateY(-1px);
        }

        .evreg-btn:active {
            transform: translateY(0);
        }

        .evreg-btn:focus-visible {
            outline: 3px solid rgba(50, 121, 189, .26);
            outline-offset: 3px;
        }

        .evreg-btn:disabled {
            opacity: .55;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .evreg-btn-primary {
            color: #ffffff !important;
            background: var(--evapp-primary);
            border-color: var(--evapp-primary);
        }

        .evreg-btn-primary:hover {
            color: #ffffff !important;
            background: var(--evapp-primary-dark);
            border-color: var(--evapp-primary-dark);
            box-shadow: 0 8px 18px rgba(38, 100, 157, .22);
        }

        .evreg-btn-secondary {
            color: var(--evapp-text) !important;
            background: var(--evapp-surface);
            border-color: var(--evapp-border);
        }

        .evreg-btn-secondary:hover {
            color: var(--evapp-primary-dark) !important;
            border-color: #bfd3e7;
            background: #ffffff;
            box-shadow: 0 8px 18px rgba(31, 52, 73, .08);
        }

        .evreg-btn-success {
            color: #ffffff !important;
            background: var(--evapp-success);
            border-color: var(--evapp-success);
        }

        .evreg-btn-success:hover {
            color: #ffffff !important;
            background: #116b34;
            border-color: #116b34;
            box-shadow: 0 8px 18px rgba(21, 128, 61, .18);
        }

        .evreg-submit {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 18px 20px;
            background: var(--evapp-surface);
            border: 1px solid var(--evapp-border);
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(31, 52, 73, .045);
        }

        .evreg-submit-copy {
            display: grid;
            gap: 2px;
            min-width: 0;
        }

        .evreg-submit-copy strong {
            color: var(--evapp-text);
            font-size: 14px;
            font-weight: 800;
        }

        .evreg-submit-copy span {
            color: var(--evapp-muted);
            font-size: 12px;
        }

        .evbtn-submit {
            flex: 0 0 auto;
            min-width: 150px;
        }

        .evreg-processing {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 13px 15px;
            color: var(--evapp-primary-dark);
            background: var(--evapp-primary-soft);
            border: 1px solid #cfe2f5;
            border-radius: 14px;
            font-size: 13px;
        }

        .evreg-processing[hidden] {
            display: none !important;
        }

        .evspinner {
            display: inline-block;
            flex: 0 0 auto;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(50, 121, 189, .3);
            border-top-color: var(--evapp-primary);
            border-radius: 50%;
            animation: evspin .8s linear infinite;
        }

        @keyframes evspin {
            to {
                transform: rotate(360deg);
            }
        }

        .evreg-card-success {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 20px;
            padding: 18px;
            color: #0f5132;
            background: var(--evapp-success-soft);
            border: 1px solid #b7e4c7;
            border-radius: 18px;
        }

        .evreg-success-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            color: #ffffff;
            background: var(--evapp-success);
            border-radius: 50%;
        }

        .evreg-success-icon svg {
            width: 22px;
            height: 22px;
            stroke-width: 2.5;
        }

        .evreg-success-title {
            color: #0f5132;
            font-size: 17px;
            font-weight: 850;
        }

        .evreg-success-id {
            margin-top: 3px;
            color: #28714b;
            font-size: 13px;
        }

        .evreg-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 13px;
        }

        .evreg-alert {
            margin-bottom: 16px;
            padding: 13px 15px;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 650;
        }

        .evreg-alert-error {
            color: #8b1e17;
            background: var(--evapp-danger-soft);
            border: 1px solid #f2b8b5;
        }

        .evreg-alert-warn {
            color: #8a4b08;
            background: var(--evapp-warning-soft);
            border: 1px solid #f3d49b;
        }

        .evreg-inline-notice {
            max-width: 980px;
            margin: 20px auto;
            padding: 14px 16px;
            border-radius: 14px;
            font-family: inherit;
        }

        .evreg-inline-notice--warning {
            color: #8a4b08;
            background: #fff8e7;
            border: 1px solid #f3d49b;
        }

        .evreg-inline-notice--error {
            color: #8b1e17;
            background: #fff1f0;
            border: 1px solid #f2b8b5;
        }

        .evreg-success .evreg-form {
            display: none;
        }

        .evreg-live-region:empty {
            display: none;
        }

        @media (max-width: 900px) {
            .evreg-grid--3 {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .evreg-header {
                align-items: flex-start;
            }
        }

        @media (max-width: 680px) {
            .evreg-delivery-grid {
                grid-template-columns: 1fr;
            }

            .evreg-shell {
                padding: 16px;
                border-radius: 20px;
            }

            .evreg-header {
                display: grid;
                gap: 16px;
            }

            .evreg-header-actions,
            .evreg-header-actions .evreg-btn {
                width: 100%;
            }

            .evreg-main-title {
                font-size: clamp(28px, 9vw, 36px);
            }

            .evreg-event-context {
                grid-template-columns: auto minmax(0, 1fr);
            }

            .evreg-change-event {
                grid-column: 1 / -1;
                width: 100%;
            }

            .evreg-grid--3 {
                grid-template-columns: 1fr;
            }

            .evreg-form-section {
                padding: 18px 16px;
            }

            .evreg-submit {
                display: grid;
            }

            .evbtn-submit {
                width: 100%;
                min-width: 0;
            }

            .evreg-actions {
                display: grid;
            }

            .evreg-actions .evreg-btn {
                width: 100%;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .evreg-wrap *,
            .evreg-wrap *::before,
            .evreg-wrap *::after {
                scroll-behavior: auto !important;
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .01ms !important;
            }
        }
    </style>

    <script>
    (function() {
        var root = document.getElementById('<?php echo esc_js($uid); ?>');
        if (!root) return;

        var form = root.querySelector('.evreg-form');
        if (!form) return;

        var btn = form.querySelector('.evbtn-submit');
        var btnLabel = btn ? btn.querySelector('.evreg-submit-label') : null;
        var processing = root.querySelector('.evreg-processing');
        var liveRegion = root.querySelector('.evreg-live-region');
        var whatsappToggle = form.querySelector('[name="evreg_send_whatsapp"]');
        var phoneInput = form.querySelector('[name="as_tel"]');

        var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode(wp_create_nonce('eventosapp_evreg_ajax')); ?>;
        var baseUrl = <?php echo wp_json_encode($base_url); ?>;
        var urlSearch = <?php echo wp_json_encode($url_search); ?>;

        function smoothScrollTo(el) {
            if (!el) return;

            try {
                el.scrollIntoView({
                    behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
                        ? 'auto'
                        : 'smooth',
                    block: 'start'
                });
            } catch (e) {
                el.scrollIntoView(true);
            }
        }

        function syncModalidadUI() {
            var sel = form.querySelector('[name="as_ticket_modalidad"]');
            var mode = sel ? sel.value : 'presencial';
            var pre = form.querySelector('.evreg-preprinted-wrap');

            if (!pre) return;

            var hide = (mode === 'virtual');
            pre.hidden = hide;

            var input = pre.querySelector('input');

            if (input) {
                input.disabled = hide;

                if (hide) {
                    input.value = '';
                }
            }
        }

        function syncDeliveryUI() {
            if (!phoneInput || !whatsappToggle) {
                return;
            }

            var whatsappRequested = !!whatsappToggle.checked;

            if (whatsappRequested) {
                phoneInput.setAttribute('required', 'required');
                phoneInput.setAttribute('aria-required', 'true');
            } else {
                phoneInput.removeAttribute('required');
                phoneInput.removeAttribute('aria-required');
                phoneInput.setCustomValidity('');
            }
        }

        function clearValidationState() {
            form.querySelectorAll('.evreg-invalid, [aria-invalid="true"]')
                .forEach(function(field) {
                    field.classList.remove('evreg-invalid');
                    field.removeAttribute('aria-invalid');
                });
        }

        function validateForm() {
            clearValidationState();

            if (form.checkValidity()) {
                return true;
            }

            var invalid = form.querySelector(':invalid');

            if (invalid) {
                invalid.classList.add('evreg-invalid');
                invalid.setAttribute('aria-invalid', 'true');

                try {
                    invalid.focus({ preventScroll: true });
                } catch (e) {
                    invalid.focus();
                }

                smoothScrollTo(invalid.closest('.evreg-field') || invalid);
            }

            if (typeof form.reportValidity === 'function') {
                form.reportValidity();
            }

            return false;
        }

        function removeError() {
            var current = root.querySelector('.evreg-msg');

            if (current) {
                current.remove();
            }

            if (liveRegion) {
                liveRegion.textContent = '';
            }
        }

        function showError(message) {
            removeError();

            var m = document.createElement('div');
            m.className = 'evreg-alert evreg-alert-error evreg-msg';
            m.setAttribute('role', 'alert');
            m.textContent = message || 'No fue posible procesar la solicitud.';

            var eventContext = root.querySelector('.evreg-event-context');

            if (eventContext && eventContext.parentNode) {
                eventContext.parentNode.insertBefore(m, eventContext.nextSibling);
            } else {
                form.parentNode.insertBefore(m, form);
            }

            if (liveRegion) {
                liveRegion.textContent = m.textContent;
            }

            smoothScrollTo(m);
        }

        function createActionLink(href, className, text) {
            var a = document.createElement('a');
            a.href = href;
            a.className = 'evreg-btn ' + className;
            a.textContent = text;
            return a;
        }

        function showSuccess(tid, updated, delivery) {
            removeError();

            var current = root.querySelector('.evreg-card-success');

            if (current) {
                current.remove();
            }

            var card = document.createElement('section');
            card.className = 'evreg-card-success';
            card.setAttribute('role', 'status');

            var icon = document.createElement('div');
            icon.className = 'evreg-success-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.innerHTML =
                '<svg viewBox="0 0 24 24">' +
                    '<path d="M20 6 9 17l-5-5"></path>' +
                '</svg>';

            var copy = document.createElement('div');
            copy.className = 'evreg-success-copy';

            var title = document.createElement('div');
            title.className = 'evreg-success-title';
            title.textContent = updated
                ? '¡Ticket actualizado correctamente!'
                : '¡Ticket creado correctamente!';

            copy.appendChild(title);

            if (tid) {
                var id = document.createElement('div');
                id.className = 'evreg-success-id';
                id.appendChild(document.createTextNode('ID del Ticket: '));

                var strong = document.createElement('strong');
                strong.textContent = tid;

                id.appendChild(strong);
                copy.appendChild(id);
            }

            if (delivery && typeof delivery === 'object') {
                var deliveryList = document.createElement('div');
                deliveryList.className = 'evreg-delivery-result-list';

                [
                    ['email', 'Correo'],
                    ['whatsapp', 'WhatsApp']
                ].forEach(function(config) {
                    var key = config[0];
                    var label = config[1];
                    var item = delivery[key];

                    if (!item || !item.requested) {
                        return;
                    }

                    var status = item.status || 'processed';
                    var statusLabel = '';

                    if (status === 'sent') {
                        statusLabel = 'enviado';
                    } else if (status === 'error') {
                        statusLabel = 'no enviado';
                    } else if (status === 'skipped') {
                        statusLabel = 'omitido';
                    } else {
                        statusLabel = 'procesado';
                    }

                    var row = document.createElement('div');
                    row.className = 'evreg-delivery-result evreg-delivery-result--' + status;

                    var rowStrong = document.createElement('strong');
                    rowStrong.textContent = label + ': ' + statusLabel + '.';

                    row.appendChild(rowStrong);

                    if (item.message) {
                        row.appendChild(
                            document.createTextNode(' ' + String(item.message))
                        );
                    }

                    deliveryList.appendChild(row);
                });

                if (deliveryList.children.length) {
                    copy.appendChild(deliveryList);
                }
            }

            var actions = document.createElement('div');
            actions.className = 'evreg-actions';

            actions.appendChild(
                createActionLink(
                    baseUrl,
                    'evreg-btn-success',
                    'Crear otro asistente'
                )
            );

            if (urlSearch) {
                actions.appendChild(
                    createActionLink(
                        urlSearch,
                        'evreg-btn-primary',
                        'Ir a Check-In'
                    )
                );
            }

            copy.appendChild(actions);
            card.appendChild(icon);
            card.appendChild(copy);

            form.parentNode.insertBefore(card, form);
            root.classList.add('evreg-success');
            form.hidden = true;

            if (liveRegion) {
                liveRegion.textContent = title.textContent
                    + (tid ? ' ID del Ticket: ' + tid : '');
            }

            try {
                if (window.history && window.history.replaceState) {
                    var separator = baseUrl.indexOf('?') > -1 ? '&' : '?';
                    var url = baseUrl
                        + separator
                        + 'evreg_ok=1&evreg_updated='
                        + (updated ? '1' : '0')
                        + '&tid='
                        + encodeURIComponent(tid || '');

                    history.replaceState({}, document.title, url);
                }
            } catch (e) {}

            smoothScrollTo(card);
        }

        function setLoading(isLoading) {
            if (btn) {
                btn.disabled = !!isLoading;
                btn.classList.toggle('is-loading', !!isLoading);
                btn.setAttribute('aria-busy', isLoading ? 'true' : 'false');
            }

            if (btnLabel) {
                btnLabel.textContent = isLoading
                    ? 'Procesando…'
                    : 'Crear Ticket';
            }

            if (processing) {
                processing.hidden = !isLoading;
            }
        }

        form.addEventListener('change', function(ev) {
            if (
                ev.target
                && ev.target.name === 'as_ticket_modalidad'
            ) {
                syncModalidadUI();
            }

            if (
                ev.target
                && ev.target.name === 'evreg_send_whatsapp'
            ) {
                syncDeliveryUI();
            }

            if (
                ev.target
                && ev.target.classList
                && ev.target.classList.contains('evreg-invalid')
            ) {
                ev.target.classList.remove('evreg-invalid');
                ev.target.removeAttribute('aria-invalid');
            }
        });

        form.addEventListener('input', function(ev) {
            if (
                ev.target
                && ev.target.classList
                && ev.target.classList.contains('evreg-invalid')
            ) {
                ev.target.classList.remove('evreg-invalid');
                ev.target.removeAttribute('aria-invalid');
            }
        });

        syncModalidadUI();
        syncDeliveryUI();

        // Marca contenedores Elementor como "seguros".
        try {
            var widget = root.closest('.elementor-widget');
            var container = root.closest('.evreg-safe')
                || root.closest('.elementor-widget-container');
            var column = widget
                ? widget.closest('.elementor-column')
                : null;
            var section = widget
                ? widget.closest('.elementor-section')
                : null;

            [container, widget, column, section].forEach(function(el) {
                if (el && !el.classList.contains('evreg-safe')) {
                    el.classList.add('evreg-safe');
                }
            });

            <?php if ( $debug_enabled ): ?>
            console.groupCollapsed(
                '%c[eventosapp] front_register mount',
                'color:#3279bd;font-weight:bold'
            );
            console.log('root', '#<?php echo esc_js($uid); ?>');
            console.log('ajax', ajax);
            console.groupEnd();
            <?php endif; ?>
        } catch (e) {}

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            if (form.dataset.submitted === '1') {
                return;
            }

            if (!validateForm()) {
                return;
            }

            removeError();
            form.dataset.submitted = '1';
            setLoading(true);

            var fd = new FormData(form);
            fd.append('action', 'eventosapp_evreg_submit');
            fd.append('nonce', nonce);

            fetch(ajax, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function(response) {
                return response.text().then(function(text) {
                    var data;

                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        throw new Error('La respuesta del servidor no es válida.');
                    }

                    return {
                        ok: response.ok,
                        data: data
                    };
                });
            })
            .then(function(result) {
                var resp = result.data;

                if (resp && resp.success) {
                    var tid = (
                        resp.data
                        && resp.data.tid
                    )
                        ? String(resp.data.tid)
                        : '';

                    var updated = !!(
                        resp.data
                        && resp.data.updated
                    );

                    var delivery = (
                        resp.data
                        && resp.data.delivery
                        && typeof resp.data.delivery === 'object'
                    )
                        ? resp.data.delivery
                        : null;

                    showSuccess(tid, updated, delivery);
                    return;
                }

                var message = (
                    resp
                    && resp.data
                    && resp.data.message
                )
                    ? resp.data.message
                    : 'No fue posible procesar la solicitud.';

                showError(message);
            })
            .catch(function(error) {
                showError(
                    error && error.message
                        ? error.message
                        : 'Error de red. Verifica tu conexión e inténtalo de nuevo.'
                );
            })
            .finally(function() {
                setLoading(false);
                form.dataset.submitted = '0';
            });
        }, { passive: false });
    })();
    </script>

    <?php
    if ( $debug_enabled ) {
        add_action('wp_footer', function() use ($dbg) {
            ?>
            <script>
            (function() {
                try {
                    var msgs = <?php echo wp_json_encode($dbg); ?>;

                    console.groupCollapsed(
                        '%c[eventosapp] front_register',
                        'color:#3279bd;font-weight:bold'
                    );

                    msgs.forEach(function(m) {
                        console.log(m);
                    });

                    console.groupEnd();
                } catch (e) {}
            })();
            </script>
            <?php
        });
    }

    return ob_get_clean();
});
