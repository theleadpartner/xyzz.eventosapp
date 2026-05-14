<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * EventosApp — Landing virtual pública + Widget Elementor.
 *
 * - Registra la ruta /virtual/nombre-del-evento.
 * - Expone el shortcode [eventosapp_virtual_landing].
 * - Registra un widget de Elementor para colocar la landing en una página base.
 * - El botón del correo debe apuntar a esta landing, no al backend.
 */

add_action('init', function(){
    add_rewrite_rule(
        '^virtual/([^/]+)/?$',
        'index.php?pagename=virtual&eventosapp_virtual_slug=$matches[1]',
        'top'
    );

    if ( ! get_option('eventosapp_virtual_landing_rules_ready') ) {
        update_option('eventosapp_virtual_landing_rules_ready', 1);
        update_option('eventosapp_needs_flush', 1);
    }
}, 20);

add_filter('query_vars', function( $vars ) {
    $vars[] = 'eventosapp_virtual_slug';
    return $vars;
});

if ( ! function_exists('eventosapp_virtual_landing_current_slug') ) {
    function eventosapp_virtual_landing_current_slug() {
        $slug = get_query_var('eventosapp_virtual_slug');
        if ( $slug ) {
            return sanitize_title($slug);
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = wp_parse_url($request_uri, PHP_URL_PATH);
        $path = is_string($path) ? trim($path, '/') : '';

        if ( preg_match('#(?:^|/)virtual/([^/]+)/?$#', $path, $m) ) {
            return sanitize_title($m[1]);
        }

        if ( isset($_GET['eventosapp_virtual_slug']) ) {
            return sanitize_title(wp_unslash($_GET['eventosapp_virtual_slug']));
        }

        return '';
    }
}

if ( ! function_exists('eventosapp_find_event_by_virtual_slug') ) {
    function eventosapp_find_event_by_virtual_slug( $slug ) {
        $slug = sanitize_title($slug);
        if ( $slug === '' ) return 0;

        $cached = wp_cache_get('eventosapp_virtual_event_' . $slug, 'eventosapp');
        if ( $cached !== false ) {
            return absint($cached);
        }

        $event_post_types = function_exists('eventosapp_virtual_landing_active_event_post_types')
            ? eventosapp_virtual_landing_active_event_post_types()
            : [ 'eventosapp_event', 'eventosapp_events' ];

        $events = get_posts([
            'post_type'      => $event_post_types,
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ]);

        foreach ( $events as $event_id ) {
            if ( function_exists('eventosapp_event_has_virtual_access') && ! eventosapp_event_has_virtual_access($event_id) ) {
                continue;
            }

            $path = function_exists('eventosapp_get_event_virtual_landing_path')
                ? eventosapp_get_event_virtual_landing_path($event_id)
                : ('/virtual/' . sanitize_title(get_the_title($event_id)));
            $event_slug = sanitize_title(basename(untrailingslashit($path)));

            if ( $event_slug === $slug ) {
                wp_cache_set('eventosapp_virtual_event_' . $slug, (int) $event_id, 'eventosapp', 300);
                return (int) $event_id;
            }
        }

        wp_cache_set('eventosapp_virtual_event_' . $slug, 0, 'eventosapp', 300);
        return 0;
    }
}

if ( ! function_exists('eventosapp_virtual_landing_access_is_enabled') ) {
    function eventosapp_virtual_landing_access_is_enabled( $event_id ) {
        $event_id = absint($event_id);
        $access_at = get_post_meta($event_id, '_eventosapp_virtual_access_datetime', true);
        if ( ! $access_at ) {
            return [
                'enabled' => true,
                'message' => '',
                'label'   => '',
            ];
        }

        try {
            $tz  = function_exists('eventosapp_get_event_timezone_object') ? eventosapp_get_event_timezone_object($event_id) : wp_timezone();
            $now = new DateTime('now', $tz);
            $at  = new DateTime($access_at, $tz);
        } catch ( Exception $e ) {
            return [ 'enabled' => true, 'message' => '', 'label' => '' ];
        }

        if ( $now >= $at ) {
            return [
                'enabled' => true,
                'message' => '',
                'label'   => date_i18n('d/m/Y H:i', $at->getTimestamp()),
            ];
        }

        return [
            'enabled' => false,
            'message' => 'El acceso virtual se habilitará el ' . date_i18n('d/m/Y H:i', $at->getTimestamp()) . '.',
            'label'   => date_i18n('d/m/Y H:i', $at->getTimestamp()),
        ];
    }
}

if ( ! function_exists('eventosapp_get_virtual_landing_organizer_logo_url') ) {
    function eventosapp_get_virtual_landing_organizer_logo_url( $event_id ) {
        $event_id = absint($event_id);
        if ( ! $event_id ) return '';

        $logo = get_post_meta($event_id, '_eventosapp_virtual_landing_organizer_logo_url', true);
        if ( $logo ) return esc_url_raw($logo);

        $cliente_id = absint(get_post_meta($event_id, '_eventosapp_cliente_id', true));
        if ( $cliente_id ) {
            $candidate_meta_keys = [
                '_cliente_logo_url',
                'cliente_logo_url',
                '_eventosapp_cliente_logo_url',
                '_cliente_logo',
                'logo_url',
            ];
            foreach ( $candidate_meta_keys as $meta_key ) {
                $candidate = get_post_meta($cliente_id, $meta_key, true);
                if ( is_numeric($candidate) ) {
                    $candidate = wp_get_attachment_image_url(absint($candidate), 'medium');
                }
                if ( $candidate ) {
                    return esc_url_raw($candidate);
                }
            }

            $thumb = get_the_post_thumbnail_url($cliente_id, 'medium');
            if ( $thumb ) return esc_url_raw($thumb);
        }

        $wallet_logo = get_post_meta($event_id, '_eventosapp_wallet_logo_url', true);
        return $wallet_logo ? esc_url_raw($wallet_logo) : '';
    }
}

if ( ! function_exists('eventosapp_virtual_landing_get_event_dates_label') ) {
    function eventosapp_virtual_landing_get_event_dates_label( $event_id ) {
        $tipo_fecha = get_post_meta($event_id, '_eventosapp_tipo_fecha', true);
        if ( $tipo_fecha === 'unica' ) {
            $fecha = get_post_meta($event_id, '_eventosapp_fecha_unica', true);
            return $fecha ? date_i18n('d/m/Y', strtotime($fecha)) : '-';
        }
        if ( $tipo_fecha === 'consecutiva' ) {
            $inicio = get_post_meta($event_id, '_eventosapp_fecha_inicio', true);
            $fin    = get_post_meta($event_id, '_eventosapp_fecha_fin', true);
            if ( $inicio && $fin ) return date_i18n('d/m/Y', strtotime($inicio)) . ' — ' . date_i18n('d/m/Y', strtotime($fin));
            return '-';
        }
        if ( $tipo_fecha === 'noconsecutiva' ) {
            $fechas = get_post_meta($event_id, '_eventosapp_fechas_noco', true);
            if ( is_string($fechas) ) $fechas = @unserialize($fechas);
            if ( ! is_array($fechas) ) $fechas = [];
            $labels = [];
            foreach ( $fechas as $fecha ) {
                if ( $fecha ) $labels[] = date_i18n('d/m/Y', strtotime($fecha));
            }
            return $labels ? implode(', ', $labels) : '-';
        }
        return '-';
    }
}

if ( ! function_exists('eventosapp_virtual_landing_escape_css_color') ) {
    function eventosapp_virtual_landing_escape_css_color( $value, $fallback ) {
        $value = sanitize_hex_color($value);
        return $value ?: $fallback;
    }
}

if ( ! function_exists('eventosapp_render_virtual_landing') ) {
    function eventosapp_render_virtual_landing( $atts = [] ) {
        $atts = shortcode_atts([
            'event_id' => 0,
        ], is_array($atts) ? $atts : []);

        $event_id = absint($atts['event_id']);
        $slug     = eventosapp_virtual_landing_current_slug();

        if ( ! $event_id && $slug ) {
            $event_id = eventosapp_find_event_by_virtual_slug($slug);
        }

        $event_post_type = $event_id ? get_post_type($event_id) : '';
        $is_valid_event_post_type = function_exists('eventosapp_virtual_landing_is_event_post_type')
            ? eventosapp_virtual_landing_is_event_post_type($event_post_type)
            : in_array($event_post_type, [ 'eventosapp_event', 'eventosapp_events' ], true);

        if ( ! $event_id || ! $is_valid_event_post_type ) {
            return '<div class="evapp-virtual-landing evapp-virtual-landing--empty"><div class="evapp-vl-card"><h2>Landing virtual no encontrada</h2><p>No se encontró un evento virtual para esta URL. Revisa la URL personalizada configurada en el evento.</p></div></div>';
        }

        if ( function_exists('eventosapp_event_has_virtual_access') && ! eventosapp_event_has_virtual_access($event_id) ) {
            return '<div class="evapp-virtual-landing evapp-virtual-landing--empty"><div class="evapp-vl-card"><h2>Este evento no tiene acceso virtual</h2><p>La modalidad actual del evento no permite una landing virtual.</p></div></div>';
        }

        $ticket_id = function_exists('eventosapp_resolve_ticket_from_request') ? eventosapp_resolve_ticket_from_request($_GET) : 0;
        if ( $ticket_id ) {
            $ticket_event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
            if ( $ticket_event_id !== $event_id ) {
                $ticket_id = 0;
            }
        }

        $event_title      = get_the_title($event_id);
        $organizer_name   = function_exists('eventosapp_get_nombre_organizador') ? eventosapp_get_nombre_organizador($event_id) : get_post_meta($event_id, '_eventosapp_organizador', true);
        $organizer_logo   = eventosapp_get_virtual_landing_organizer_logo_url($event_id);
        $header_url       = get_post_meta($event_id, '_eventosapp_virtual_landing_header_url', true);
        $intro_title      = get_post_meta($event_id, '_eventosapp_virtual_landing_intro_title', true) ?: 'Bienvenido a ' . $event_title;
        $intro_text       = get_post_meta($event_id, '_eventosapp_virtual_landing_intro_text', true);
        $button_label     = get_post_meta($event_id, '_eventosapp_virtual_landing_button_label', true) ?: 'Ingresar a la sesión virtual';
        $platform         = get_post_meta($event_id, '_eventosapp_virtual_platform', true) ?: 'Virtual';
        $platform_url     = esc_url_raw(get_post_meta($event_id, '_eventosapp_virtual_url', true));
        $dates_label      = eventosapp_virtual_landing_get_event_dates_label($event_id);
        $hora_inicio      = get_post_meta($event_id, '_eventosapp_hora_inicio', true);
        $hora_cierre      = get_post_meta($event_id, '_eventosapp_hora_cierre', true);
        $zona_horaria     = get_post_meta($event_id, '_eventosapp_zona_horaria', true);
        $access_state     = eventosapp_virtual_landing_access_is_enabled($event_id);
        $colors           = function_exists('eventosapp_virtual_landing_get_colors') ? eventosapp_virtual_landing_get_colors($event_id) : [];
        $colors           = array_merge([
            'page_bg'     => '#f4f7fb',
            'header_bg'   => '#0f172a',
            'card_bg'     => '#ffffff',
            'primary'     => '#2563eb',
            'button_bg'   => '#2563eb',
            'button_text' => '#ffffff',
            'text'        => '#111827',
            'muted'       => '#64748b',
            'border'      => '#e5e7eb',
            'badge_bg'    => '#eef2ff',
            'badge_text'  => '#3730a3',
        ], is_array($colors) ? $colors : []);

        $ticket_public_id = $ticket_id ? get_post_meta($ticket_id, 'eventosapp_ticketID', true) : '';
        $ticket_name      = $ticket_id ? trim(get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true) . ' ' . get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true)) : '';
        $ticket_email     = $ticket_id ? get_post_meta($ticket_id, '_eventosapp_asistente_email', true) : '';
        $ticket_cc        = $ticket_id ? get_post_meta($ticket_id, '_eventosapp_asistente_cc', true) : '';
        $ticket_company   = $ticket_id ? get_post_meta($ticket_id, '_eventosapp_asistente_empresa', true) : '';
        $ticket_localidad = $ticket_id ? get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true) : '';
        $ticket_modalidad = $ticket_id && function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label($ticket_id) : '';
        $virtual_checked  = $ticket_id && function_exists('eventosapp_ticket_has_checkin_type') ? eventosapp_ticket_has_checkin_type($ticket_id, 'virtual') : false;

        $ajax_url = $ticket_id ? add_query_arg([
            'action'     => 'eventosapp_register_virtual_checkin',
            'ticket_id'  => $ticket_id,
            'ticket_pub' => $ticket_public_id,
        ], admin_url('admin-ajax.php')) : '';

        $can_enter = $ticket_id && $platform_url && ! empty($access_state['enabled']);
        $button_classes = 'evapp-vl-button';
        if ( ! $can_enter ) $button_classes .= ' is-disabled';

        ob_start();
        ?>
        <div class="evapp-virtual-landing" style="--evapp-vl-page-bg:<?php echo esc_attr(eventosapp_virtual_landing_escape_css_color($colors['page_bg'], '#f4f7fb')); ?>;--evapp-vl-header-bg:<?php echo esc_attr(eventosapp_virtual_landing_escape_css_color($colors['header_bg'], '#0f172a')); ?>;--evapp-vl-card-bg:<?php echo esc_attr(eventosapp_virtual_landing_escape_css_color($colors['card_bg'], '#ffffff')); ?>;--evapp-vl-primary:<?php echo esc_attr(eventosapp_virtual_landing_escape_css_color($colors['primary'], '#2563eb')); ?>;--evapp-vl-button-bg:<?php echo esc_attr(eventosapp_virtual_landing_escape_css_color($colors['button_bg'], '#2563eb')); ?>;--evapp-vl-button-text:<?php echo esc_attr(eventosapp_virtual_landing_escape_css_color($colors['button_text'], '#ffffff')); ?>;--evapp-vl-text:<?php echo esc_attr(eventosapp_virtual_landing_escape_css_color($colors['text'], '#111827')); ?>;--evapp-vl-muted:<?php echo esc_attr(eventosapp_virtual_landing_escape_css_color($colors['muted'], '#64748b')); ?>;--evapp-vl-border:<?php echo esc_attr(eventosapp_virtual_landing_escape_css_color($colors['border'], '#e5e7eb')); ?>;--evapp-vl-badge-bg:<?php echo esc_attr(eventosapp_virtual_landing_escape_css_color($colors['badge_bg'], '#eef2ff')); ?>;--evapp-vl-badge-text:<?php echo esc_attr(eventosapp_virtual_landing_escape_css_color($colors['badge_text'], '#3730a3')); ?>;">
            <style>
                .evapp-virtual-landing{background:var(--evapp-vl-page-bg);color:var(--evapp-vl-text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:34px 18px;border-radius:18px;}
                .evapp-vl-shell{max-width:1120px;margin:0 auto;}
                .evapp-vl-hero{overflow:hidden;border-radius:24px;background:var(--evapp-vl-header-bg);box-shadow:0 20px 55px rgba(15,23,42,.12);border:1px solid var(--evapp-vl-border);}
                .evapp-vl-hero-img{width:100%;height:auto;display:block;max-height:280px;object-fit:cover;}
                .evapp-vl-hero-generated{min-height:220px;padding:34px;background:linear-gradient(135deg,var(--evapp-vl-header-bg),var(--evapp-vl-primary));display:flex;align-items:flex-end;}
                .evapp-vl-hero-generated h1{color:#fff;margin:0;font-size:clamp(30px,4vw,52px);line-height:1.05;max-width:820px;}
                .evapp-vl-content{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(320px,.75fr);gap:22px;margin-top:22px;}
                .evapp-vl-card{background:var(--evapp-vl-card-bg);border:1px solid var(--evapp-vl-border);border-radius:22px;padding:24px;box-shadow:0 18px 50px rgba(15,23,42,.08);}
                .evapp-vl-organizer{display:flex;gap:14px;align-items:center;margin-bottom:20px;}
                .evapp-vl-logo{width:64px;height:64px;border-radius:16px;object-fit:contain;background:#fff;border:1px solid var(--evapp-vl-border);padding:6px;}
                .evapp-vl-kicker{color:var(--evapp-vl-muted);font-size:14px;margin:0 0 2px;}
                .evapp-vl-organizer-name{font-size:18px;font-weight:800;margin:0;color:var(--evapp-vl-text);}
                .evapp-vl-card h2{font-size:30px;line-height:1.15;margin:0 0 10px;color:var(--evapp-vl-text);}
                .evapp-vl-card p{color:var(--evapp-vl-muted);font-size:16px;line-height:1.55;}
                .evapp-vl-details{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:18px;}
                .evapp-vl-detail{border:1px solid var(--evapp-vl-border);border-radius:16px;padding:14px;background:rgba(248,250,252,.72);}
                .evapp-vl-detail strong{display:block;color:var(--evapp-vl-text);font-size:13px;margin-bottom:5px;}
                .evapp-vl-detail span{color:var(--evapp-vl-muted);font-size:15px;}
                .evapp-vl-ticket-row{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid var(--evapp-vl-border);padding:10px 0;}
                .evapp-vl-ticket-row:last-child{border-bottom:0;}
                .evapp-vl-ticket-row strong{color:var(--evapp-vl-text);}
                .evapp-vl-ticket-row span{color:var(--evapp-vl-muted);text-align:right;}
                .evapp-vl-badge{display:inline-flex;align-items:center;border-radius:999px;background:var(--evapp-vl-badge-bg);color:var(--evapp-vl-badge-text);font-weight:800;font-size:13px;padding:7px 12px;margin:0 0 14px;}
                .evapp-vl-button{display:flex;align-items:center;justify-content:center;width:100%;min-height:54px;border-radius:16px;background:var(--evapp-vl-button-bg);color:var(--evapp-vl-button-text)!important;font-weight:900;text-decoration:none!important;font-size:17px;margin-top:18px;box-shadow:0 14px 28px rgba(37,99,235,.22);transition:transform .18s ease,opacity .18s ease;}
                .evapp-vl-button:hover{transform:translateY(-1px);opacity:.95;}
                .evapp-vl-button.is-disabled{opacity:.55;pointer-events:none;box-shadow:none;}
                .evapp-vl-status{border-radius:16px;background:var(--evapp-vl-badge-bg);color:var(--evapp-vl-badge-text);padding:12px 14px;font-weight:700;font-size:14px;margin-top:14px;}
                .evapp-vl-warning{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;border-radius:16px;padding:12px 14px;font-weight:700;font-size:14px;margin-top:14px;}
                @media(max-width:900px){.evapp-vl-content{grid-template-columns:1fr}.evapp-vl-details{grid-template-columns:1fr}.evapp-virtual-landing{padding:18px 10px}.evapp-vl-card{padding:18px}.evapp-vl-hero-generated{min-height:170px;padding:24px}}
            </style>

            <div class="evapp-vl-shell">
                <div class="evapp-vl-hero">
                    <?php if ( $header_url ): ?>
                        <img class="evapp-vl-hero-img" src="<?php echo esc_url($header_url); ?>" alt="<?php echo esc_attr($event_title); ?>">
                    <?php else: ?>
                        <div class="evapp-vl-hero-generated"><h1><?php echo esc_html($event_title); ?></h1></div>
                    <?php endif; ?>
                </div>

                <div class="evapp-vl-content">
                    <section class="evapp-vl-card">
                        <div class="evapp-vl-organizer">
                            <?php if ( $organizer_logo ): ?>
                                <img class="evapp-vl-logo" src="<?php echo esc_url($organizer_logo); ?>" alt="<?php echo esc_attr($organizer_name ?: 'Organizador'); ?>">
                            <?php endif; ?>
                            <div>
                                <p class="evapp-vl-kicker">Organizador</p>
                                <p class="evapp-vl-organizer-name"><?php echo esc_html($organizer_name ?: 'Organizador del evento'); ?></p>
                            </div>
                        </div>

                        <span class="evapp-vl-badge">Acceso virtual</span>
                        <h2><?php echo esc_html($intro_title); ?></h2>
                        <?php if ( $intro_text ): ?>
                            <p><?php echo wp_kses_post(nl2br($intro_text)); ?></p>
                        <?php else: ?>
                            <p>Desde esta landing podrás ingresar a la sesión virtual del evento. El ingreso quedará registrado como check-in virtual del ticket.</p>
                        <?php endif; ?>

                        <div class="evapp-vl-details">
                            <div class="evapp-vl-detail"><strong>Evento</strong><span><?php echo esc_html($event_title); ?></span></div>
                            <div class="evapp-vl-detail"><strong>Plataforma</strong><span><?php echo esc_html($platform); ?></span></div>
                            <div class="evapp-vl-detail"><strong>Fecha</strong><span><?php echo esc_html($dates_label); ?></span></div>
                            <div class="evapp-vl-detail"><strong>Hora</strong><span><?php echo esc_html(trim(($hora_inicio ?: '-') . ' — ' . ($hora_cierre ?: '-'))); ?><?php echo $zona_horaria ? ' (' . esc_html($zona_horaria) . ')' : ''; ?></span></div>
                        </div>
                    </section>

                    <aside class="evapp-vl-card">
                        <span class="evapp-vl-badge">Tu ticket</span>

                        <?php if ( $ticket_id ): ?>
                            <div class="evapp-vl-ticket-row"><strong>Nombre</strong><span><?php echo esc_html($ticket_name ?: '-'); ?></span></div>
                            <div class="evapp-vl-ticket-row"><strong>Correo</strong><span><?php echo esc_html($ticket_email ?: '-'); ?></span></div>
                            <?php if ( $ticket_cc ): ?><div class="evapp-vl-ticket-row"><strong>ID</strong><span><?php echo esc_html($ticket_cc); ?></span></div><?php endif; ?>
                            <?php if ( $ticket_company ): ?><div class="evapp-vl-ticket-row"><strong>Empresa</strong><span><?php echo esc_html($ticket_company); ?></span></div><?php endif; ?>
                            <?php if ( $ticket_localidad ): ?><div class="evapp-vl-ticket-row"><strong>Localidad</strong><span><?php echo esc_html($ticket_localidad); ?></span></div><?php endif; ?>
                            <?php if ( $ticket_modalidad ): ?><div class="evapp-vl-ticket-row"><strong>Modalidad</strong><span><?php echo esc_html($ticket_modalidad); ?></span></div><?php endif; ?>
                            <div class="evapp-vl-ticket-row"><strong>TicketID</strong><span><?php echo esc_html($ticket_public_id ?: '-'); ?></span></div>

                            <?php if ( $virtual_checked ): ?>
                                <div class="evapp-vl-status">Tu check-in virtual ya está registrado.</div>
                            <?php endif; ?>

                            <?php if ( ! $platform_url ): ?>
                                <div class="evapp-vl-warning">El enlace de la sesión virtual todavía no está configurado.</div>
                            <?php elseif ( empty($access_state['enabled']) ): ?>
                                <div class="evapp-vl-warning"><?php echo esc_html($access_state['message']); ?></div>
                            <?php else: ?>
                                <a id="evappVirtualEnterButton-<?php echo esc_attr($event_id . '-' . $ticket_id); ?>" class="<?php echo esc_attr($button_classes); ?>" href="<?php echo esc_url($platform_url); ?>" data-ajax-url="<?php echo esc_url($ajax_url); ?>" data-target-url="<?php echo esc_url($platform_url); ?>">
                                    <?php echo esc_html($button_label); ?>
                                </a>
                                <div class="evapp-vl-status" id="evappVirtualEnterStatus-<?php echo esc_attr($event_id . '-' . $ticket_id); ?>" style="display:none;"></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="evapp-vl-warning">No se pudo validar el ticket. Abre esta landing desde el botón recibido en tu correo para registrar correctamente el check-in virtual.</div>
                        <?php endif; ?>
                    </aside>
                </div>
            </div>

            <?php if ( $ticket_id && $platform_url && ! empty($access_state['enabled']) ): ?>
                <script>
                (function(){
                    var btn = document.getElementById('evappVirtualEnterButton-<?php echo esc_js($event_id . '-' . $ticket_id); ?>');
                    var statusBox = document.getElementById('evappVirtualEnterStatus-<?php echo esc_js($event_id . '-' . $ticket_id); ?>');
                    if (!btn) return;
                    btn.addEventListener('click', function(e){
                        e.preventDefault();
                        var ajaxUrl = btn.getAttribute('data-ajax-url');
                        var targetUrl = btn.getAttribute('data-target-url') || btn.getAttribute('href');
                        btn.classList.add('is-disabled');
                        btn.textContent = 'Registrando ingreso...';
                        if (statusBox) {
                            statusBox.style.display = 'block';
                            statusBox.textContent = 'Estamos registrando tu check-in virtual y abriendo la sesión.';
                        }
                        fetch(ajaxUrl, { credentials: 'same-origin' })
                            .then(function(){ window.location.href = targetUrl; })
                            .catch(function(){ window.location.href = targetUrl; });
                    });
                })();
                </script>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

add_shortcode('eventosapp_virtual_landing', function( $atts ) {
    return eventosapp_render_virtual_landing($atts);
});

add_action('template_redirect', function(){
    $slug = eventosapp_virtual_landing_current_slug();
    if ( ! $slug || ! is_404() ) {
        return;
    }

    status_header(200);
    nocache_headers();
    ?><!doctype html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <?php wp_head(); ?>
    </head>
    <body <?php body_class('eventosapp-virtual-landing-fallback'); ?>>
        <?php echo eventosapp_render_virtual_landing(); ?>
        <?php wp_footer(); ?>
    </body>
    </html><?php
    exit;
}, 1);

add_action('elementor/elements/categories_registered', function( $elements_manager ) {
    if ( ! method_exists( $elements_manager, 'add_category' ) ) {
        return;
    }

    $elements_manager->add_category(
        'eventosapp',
        [
            'title' => 'EventosApp',
            'icon'  => 'fa fa-plug',
        ]
    );
});

if ( ! function_exists('eventosapp_register_virtual_landing_elementor_widget') ) {
    function eventosapp_register_virtual_landing_elementor_widget( $widgets_manager = null ) {
        static $registered = false;

        if ( $registered ) {
            return;
        }

        if ( ! class_exists('\Elementor\Widget_Base') || ! class_exists('\Elementor\Controls_Manager') ) {
            return;
        }

        if ( ! $widgets_manager && class_exists('\Elementor\Plugin') && isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
            $widgets_manager = \Elementor\Plugin::$instance->widgets_manager;
        }

        if ( ! $widgets_manager ) {
            return;
        }

        if ( ! class_exists('EventosApp_Elementor_Virtual_Landing_Widget') ) {
            class EventosApp_Elementor_Virtual_Landing_Widget extends \Elementor\Widget_Base {
                public function get_name() {
                    return 'eventosapp_virtual_landing';
                }

                public function get_title() {
                    return 'EventosApp Landing Virtual';
                }

                public function get_icon() {
                    return 'eicon-video-camera';
                }

                public function get_categories() {
                    return [ 'eventosapp', 'general' ];
                }

                public function get_keywords() {
                    return [ 'eventosapp', 'virtual', 'landing', 'evento', 'sesion', 'checkin' ];
                }

                protected function register_controls() {
                    $this->start_controls_section(
                        'section_eventosapp_virtual_landing',
                        [ 'label' => 'EventosApp Landing Virtual' ]
                    );

                    $this->add_control(
                        'helper',
                        [
                            'type' => \Elementor\Controls_Manager::RAW_HTML,
                            'raw'  => 'Coloca este widget en la página base /virtual. La URL /virtual/nombre-del-evento cargará automáticamente la landing configurada en el CPT del evento. También puedes usar el shortcode [eventosapp_virtual_landing].',
                        ]
                    );

                    $this->end_controls_section();
                }

                protected function render() {
                    echo eventosapp_render_virtual_landing();
                }
            }
        }

        $widget_instance = new \EventosApp_Elementor_Virtual_Landing_Widget();

        if ( method_exists( $widgets_manager, 'register' ) ) {
            $widgets_manager->register( $widget_instance );
            $registered = true;
            return;
        }

        if ( method_exists( $widgets_manager, 'register_widget_type' ) ) {
            $widgets_manager->register_widget_type( $widget_instance );
            $registered = true;
        }
    }
}

add_action('elementor/widgets/register', 'eventosapp_register_virtual_landing_elementor_widget', 20);
add_action('elementor/widgets/widgets_registered', 'eventosapp_register_virtual_landing_elementor_widget', 20);
