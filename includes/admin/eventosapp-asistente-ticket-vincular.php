<?php
/**
 * Vinculación automática de Tickets con CPT Asistentes
 *
 * Activa / desactiva la asociación desde el metabox
 * "Funciones Extra del Ticket" del CPT eventosapp_event.
 *
 * Meta clave del evento  : _eventosapp_ticket_vincular_asistente  ('1' / '0')
 * Meta clave asistente   : _asistente_tickets_asociados  (array de ticket IDs)
 * Meta clave changelog   : _asistente_ticket_changelog   (array de entradas)
 *
 * @package EventosApp
 * @file    includes/admin/eventosapp-asistente-ticket-vincular.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// 1. HOOK: Vincular ticket con asistente al guardar un ticket
//    Prioridad 40 → corre después del QR Manager (20) y el PDF (30)
// ============================================================

add_action( 'save_post_eventosapp_ticket', 'evapp_vincular_ticket_a_asistente', 40, 3 );

function evapp_vincular_ticket_a_asistente( $ticket_id, $post, $update ) {

    // Protecciones estándar
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $ticket_id ) ) return;
    if ( $post->post_type !== 'eventosapp_ticket' ) return;

    // ── Obtener el evento asociado al ticket ─────────────────────────────
    $evento_id = (int) get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true );
    if ( ! $evento_id ) return;

    // ── Verificar que la función está activa para este evento ─────────────
    $vincular_activo = get_post_meta( $evento_id, '_eventosapp_ticket_vincular_asistente', true );
    if ( $vincular_activo !== '1' ) return;

    // ── Obtener cédula del ticket ─────────────────────────────────────────
    $cedula = sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_cc', true ) );
    if ( ! $cedula ) return;

    // ── Buscar o crear asistente ──────────────────────────────────────────
    $asistente_id = function_exists( 'eventosapp_find_asistente_by_cedula' )
        ? eventosapp_find_asistente_by_cedula( $cedula )
        : evapp_find_asistente_by_cedula_local( $cedula );

    if ( ! $asistente_id ) {
        // Crear nuevo asistente
        $asistente_id = evapp_crear_asistente_desde_ticket( $ticket_id, $cedula );
        if ( ! $asistente_id || is_wp_error( $asistente_id ) ) return;
    }

    // ── Comparar datos actuales del asistente vs datos del ticket ─────────
    $campos_map = evapp_get_campos_map();
    $datos_anteriores = [];
    $datos_nuevos     = [];

    foreach ( $campos_map as $ticket_meta => $asistente_meta ) {
        $val_ticket    = sanitize_text_field( get_post_meta( $ticket_id, $ticket_meta, true ) );
        $val_asistente = get_post_meta( $asistente_id, $asistente_meta, true );

        if ( $val_ticket !== '' && $val_ticket !== $val_asistente ) {
            $datos_anteriores[ $asistente_meta ] = $val_asistente;
            $datos_nuevos[ $asistente_meta ]     = $val_ticket;
        }
    }

    // ── Actualizar datos del asistente con los del ticket ─────────────────
    foreach ( $datos_nuevos as $meta_key => $meta_val ) {
        update_post_meta( $asistente_id, $meta_key, $meta_val );
    }

    // ── Sincronizar el post_title del asistente ───────────────────────────
    $nombres   = get_post_meta( $asistente_id, '_asistente_nombres',   true );
    $apellidos = get_post_meta( $asistente_id, '_asistente_apellidos', true );
    $titulo    = trim( $nombres . ' ' . $apellidos );

    if ( $titulo ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            [
                'post_title' => $titulo,
                'post_name'  => wp_unique_post_slug(
                    sanitize_title( $titulo ),
                    $asistente_id,
                    get_post_status( $asistente_id ),
                    'eventosapp_asistente',
                    0
                ),
            ],
            [ 'ID' => $asistente_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        clean_post_cache( $asistente_id );
    }

    // ── Asociar el ticket al asistente (lista de tickets) ─────────────────
    $tickets_asociados = get_post_meta( $asistente_id, '_asistente_tickets_asociados', true );
    if ( ! is_array( $tickets_asociados ) ) $tickets_asociados = [];

    if ( ! in_array( $ticket_id, $tickets_asociados, true ) ) {
        $tickets_asociados[] = $ticket_id;
        update_post_meta( $asistente_id, '_asistente_tickets_asociados', $tickets_asociados );
    }

    // ── Guardar también el ID del asistente en el ticket ──────────────────
    update_post_meta( $ticket_id, '_eventosapp_ticket_asistente_cpt_id', $asistente_id );

    // ── Registrar el changelog solo si hubo cambios ───────────────────────
    if ( ! empty( $datos_nuevos ) ) {
        evapp_registrar_changelog_asistente(
            $asistente_id,
            $ticket_id,
            $evento_id,
            $datos_anteriores,
            $datos_nuevos
        );
    }
}

// ============================================================
// 2. FUNCIÓN: Buscar asistente por cédula (fallback local)
// ============================================================

if ( ! function_exists( 'evapp_find_asistente_by_cedula_local' ) ) {
    function evapp_find_asistente_by_cedula_local( $cedula ) {
        if ( ! $cedula ) return false;
        $q = new WP_Query( [
            'post_type'      => 'eventosapp_asistente',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [ [
                'key'     => '_asistente_cedula',
                'value'   => sanitize_text_field( $cedula ),
                'compare' => '=',
            ] ],
            'fields' => 'ids',
        ] );
        return ! empty( $q->posts ) ? (int) $q->posts[0] : false;
    }
}

// ============================================================
// 3. FUNCIÓN: Crear un nuevo CPT Asistente desde los datos del ticket
// ============================================================

if ( ! function_exists( 'evapp_crear_asistente_desde_ticket' ) ) {
    function evapp_crear_asistente_desde_ticket( $ticket_id, $cedula ) {
        $nombres   = sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_nombre',   true ) );
        $apellidos = sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true ) );
        $titulo    = trim( $nombres . ' ' . $apellidos );
        if ( ! $titulo ) $titulo = 'Asistente ' . $cedula;

        $nuevo_id = wp_insert_post( [
            'post_type'   => 'eventosapp_asistente',
            'post_status' => 'publish',
            'post_title'  => $titulo,
        ] );

        if ( is_wp_error( $nuevo_id ) ) return false;

        // Poblar todos los campos desde el ticket
        foreach ( evapp_get_campos_map() as $ticket_meta => $asistente_meta ) {
            $val = sanitize_text_field( get_post_meta( $ticket_id, $ticket_meta, true ) );
            if ( $val !== '' ) {
                update_post_meta( $nuevo_id, $asistente_meta, $val );
            }
        }

        // Asegurar cédula aunque no haya meta en ticket
        update_post_meta( $nuevo_id, '_asistente_cedula', $cedula );

        return $nuevo_id;
    }
}

// ============================================================
// 4. FUNCIÓN: Mapa de correspondencia meta ticket → meta asistente
// ============================================================

if ( ! function_exists( 'evapp_get_campos_map' ) ) {
    function evapp_get_campos_map() {
        return [
            '_eventosapp_asistente_nombre'   => '_asistente_nombres',
            '_eventosapp_asistente_apellido' => '_asistente_apellidos',
            '_eventosapp_asistente_cc'       => '_asistente_cedula',
            '_eventosapp_asistente_email'    => '_asistente_email',
            '_eventosapp_asistente_tel'      => '_asistente_telefono',
            '_eventosapp_asistente_empresa'  => '_asistente_empresa',
            '_eventosapp_asistente_cargo'    => '_asistente_cargo',
            '_eventosapp_asistente_ciudad'   => '_asistente_ciudad',
            '_eventosapp_asistente_pais'     => '_asistente_pais',
        ];
    }
}

// ============================================================
// 5. FUNCIÓN: Registrar changelog en el asistente
// ============================================================

if ( ! function_exists( 'evapp_registrar_changelog_asistente' ) ) {
    function evapp_registrar_changelog_asistente( $asistente_id, $ticket_id, $evento_id, $datos_anteriores, $datos_nuevos ) {

        $changelog = get_post_meta( $asistente_id, '_asistente_ticket_changelog', true );
        if ( ! is_array( $changelog ) ) $changelog = [];

        // Labels legibles para los meta keys del asistente
        $labels = [
            '_asistente_nombres'   => 'Nombres',
            '_asistente_apellidos' => 'Apellidos',
            '_asistente_cedula'    => 'Cédula',
            '_asistente_email'     => 'Email',
            '_asistente_telefono'  => 'Teléfono',
            '_asistente_empresa'   => 'Empresa',
            '_asistente_cargo'     => 'Cargo',
            '_asistente_ciudad'    => 'Ciudad',
            '_asistente_pais'      => 'País',
        ];

        $campos_actualizados = [];
        foreach ( $datos_nuevos as $meta_key => $val_nuevo ) {
            $campos_actualizados[] = [
                'campo'    => isset( $labels[ $meta_key ] ) ? $labels[ $meta_key ] : $meta_key,
                'anterior' => isset( $datos_anteriores[ $meta_key ] ) ? $datos_anteriores[ $meta_key ] : '',
                'nuevo'    => $val_nuevo,
            ];
        }

        try {
            $now = new DateTime( 'now', wp_timezone() );
        } catch ( Exception $e ) {
            $now = new DateTime( 'now' );
        }

        $entrada = [
            'timestamp'           => $now->format( 'Y-m-d H:i:s' ),
            'ticket_id'           => $ticket_id,
            'evento_id'           => $evento_id,
            'evento_nombre'       => get_the_title( $evento_id ),
            'campos_actualizados' => $campos_actualizados,
        ];

        // Insertar al inicio (más reciente primero)
        array_unshift( $changelog, $entrada );

        update_post_meta( $asistente_id, '_asistente_ticket_changelog', $changelog );
    }
}

// ============================================================
// 6. HELPER: Obtener fecha legible del evento
// ============================================================

if ( ! function_exists( 'evapp_get_event_date_label' ) ) {
    function evapp_get_event_date_label( $evento_id ) {
        $tipo = get_post_meta( $evento_id, '_eventosapp_tipo_fecha', true );
        if ( $tipo === 'unica' ) {
            $f = get_post_meta( $evento_id, '_eventosapp_fecha_unica', true );
            return $f ? date_i18n( 'd/m/Y', strtotime( $f ) ) : '—';
        } elseif ( $tipo === 'consecutiva' ) {
            $ini = get_post_meta( $evento_id, '_eventosapp_fecha_inicio', true );
            $fin = get_post_meta( $evento_id, '_eventosapp_fecha_fin',    true );
            if ( $ini && $fin ) return date_i18n( 'd/m/Y', strtotime( $ini ) ) . ' → ' . date_i18n( 'd/m/Y', strtotime( $fin ) );
            return $ini ? date_i18n( 'd/m/Y', strtotime( $ini ) ) : '—';
        } elseif ( $tipo === 'noconsecutiva' ) {
            $fechas = get_post_meta( $evento_id, '_eventosapp_fechas_noco', true );
            if ( is_string( $fechas ) ) $fechas = @unserialize( $fechas );
            if ( is_array( $fechas ) && $fechas ) {
                return implode( ', ', array_map( function( $f ) { return date_i18n( 'd/m/Y', strtotime( $f ) ); }, $fechas ) );
            }
        }
        return '—';
    }
}

// ============================================================
// 7. HELPER: Obtener estado de checkin del ticket (simplificado)
// ============================================================

if ( ! function_exists( 'evapp_get_checkin_status_label' ) ) {
    function evapp_get_checkin_status_label( $ticket_id ) {
        $status_arr = get_post_meta( $ticket_id, '_eventosapp_checkin_status', true );
        if ( is_string( $status_arr ) ) $status_arr = @unserialize( $status_arr );
        if ( ! is_array( $status_arr ) || empty( $status_arr ) ) {
            return '<span style="color:#dc2626;">✗ No</span>';
        }
        foreach ( $status_arr as $day => $status ) {
            if ( $status === 'checked_in' ) {
                return '<span style="color:#16a34a;">✓ Sí</span>';
            }
        }
        return '<span style="color:#dc2626;">✗ No</span>';
    }
}

// ============================================================
// 8. METABOX: Tickets asociados (en CPT eventosapp_asistente)
// ============================================================

add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'evapp_asistente_tickets_asociados',
        '🎫 Tickets Asociados',
        'evapp_render_metabox_tickets_asociados',
        'eventosapp_asistente',
        'normal',
        'high'
    );

    add_meta_box(
        'evapp_asistente_changelog',
        '📋 Historial de Actualizaciones (vía Tickets)',
        'evapp_render_metabox_asistente_changelog',
        'eventosapp_asistente',
        'normal',
        'default'
    );
} );

// ── Render: Tickets asociados ─────────────────────────────────────────────────

function evapp_render_metabox_tickets_asociados( $post ) {
    $tickets_ids = get_post_meta( $post->ID, '_asistente_tickets_asociados', true );
    if ( ! is_array( $tickets_ids ) ) $tickets_ids = [];

    if ( empty( $tickets_ids ) ) {
        echo '<p style="color:#888; padding:8px 0;">Este asistente no tiene tickets asociados aún.</p>';
        return;
    }

    // Eliminar IDs inválidos
    $tickets_ids = array_filter( $tickets_ids, function( $id ) {
        $p = get_post( $id );
        return $p && $p->post_type === 'eventosapp_ticket';
    } );

    if ( empty( $tickets_ids ) ) {
        echo '<p style="color:#888; padding:8px 0;">No se encontraron tickets válidos asociados.</p>';
        return;
    }
    ?>
    <style>
    .evapp-tickets-table { width:100%; border-collapse:collapse; font-size:13px; }
    .evapp-tickets-table th { background:#f0f6fc; padding:8px 10px; text-align:left; border-bottom:2px solid #ddd; color:#1e3a5f; }
    .evapp-tickets-table td { padding:8px 10px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
    .evapp-tickets-table tr:hover td { background:#fafbff; }
    .evapp-ticket-id { font-family:monospace; font-size:12px; color:#555; }
    .evapp-btn-evento { display:inline-block; padding:4px 10px; background:#0073aa; color:#fff !important; border-radius:4px; font-size:12px; text-decoration:none; }
    .evapp-btn-evento:hover { background:#005d8a; }
    </style>
    <table class="evapp-tickets-table">
        <thead>
            <tr>
                <th>Ticket ID</th>
                <th>Evento</th>
                <th>Fecha del Evento</th>
                <th>Check-In</th>
                <th>Ir al Evento</th>
            </tr>
        </thead>
        <tbody>
        <?php
        // Ordenar: más reciente primero (mayor ID primero)
        rsort( $tickets_ids );

        foreach ( $tickets_ids as $t_id ) :
            $ticket_post = get_post( $t_id );
            if ( ! $ticket_post ) continue;

            $ticket_uid  = get_post_meta( $t_id, 'eventosapp_ticketID', true ) ?: "#{$t_id}";
            $evento_id   = (int) get_post_meta( $t_id, '_eventosapp_ticket_evento_id', true );
            $evento_nombre = $evento_id ? get_the_title( $evento_id ) : '—';
            $fecha_label   = $evento_id ? evapp_get_event_date_label( $evento_id )    : '—';
            $checkin_label = evapp_get_checkin_status_label( $t_id );
            $evento_edit_url = $evento_id ? get_edit_post_link( $evento_id ) : '';
        ?>
            <tr>
                <td class="evapp-ticket-id"><?php echo esc_html( $ticket_uid ); ?></td>
                <td><?php echo esc_html( $evento_nombre ); ?></td>
                <td><?php echo esc_html( $fecha_label ); ?></td>
                <td><?php echo $checkin_label; ?></td>
                <td>
                    <?php if ( $evento_edit_url ) : ?>
                        <a class="evapp-btn-evento" href="<?php echo esc_url( $evento_edit_url ); ?>" target="_blank">Ver Evento →</a>
                    <?php else : ?>
                        <span style="color:#aaa;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="font-size:12px;color:#888;margin-top:10px;">Total de tickets asociados: <strong><?php echo count( $tickets_ids ); ?></strong></p>
    <?php
}

// ── Render: Changelog de actualizaciones ─────────────────────────────────────

function evapp_render_metabox_asistente_changelog( $post ) {
    $changelog = get_post_meta( $post->ID, '_asistente_ticket_changelog', true );
    if ( ! is_array( $changelog ) || empty( $changelog ) ) {
        echo '<p style="color:#888; padding:8px 0;">No hay historial de actualizaciones registradas mediante tickets.</p>';
        return;
    }
    ?>
    <style>
    .evapp-cl-wrap { max-height:420px; overflow-y:auto; padding-right:4px; }
    .evapp-cl-entry { border:1px solid #ddd; border-radius:5px; margin-bottom:12px; overflow:hidden; font-size:13px; }
    .evapp-cl-header { background:#f0f6fc; padding:8px 12px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #ddd; flex-wrap:wrap; gap:4px; }
    .evapp-cl-header strong { font-size:13px; color:#1e3a5f; }
    .evapp-cl-header .evapp-cl-meta { font-size:12px; color:#555; }
    .evapp-cl-body { padding:10px 12px; }
    .evapp-cl-table { width:100%; border-collapse:collapse; font-size:12px; }
    .evapp-cl-table th { text-align:left; padding:4px 8px; background:#f9f9f9; border-bottom:1px solid #eee; color:#333; font-weight:600; width:22%; }
    .evapp-cl-table td { padding:4px 8px; border-bottom:1px solid #f0f0f0; word-break:break-all; }
    .evapp-cl-table td.before { color:#c0392b; text-decoration:line-through; width:35%; }
    .evapp-cl-table td.after  { color:#16a34a; font-weight:500; width:35%; }
    </style>
    <div class="evapp-cl-wrap">
    <?php foreach ( $changelog as $idx => $entrada ) :
        if ( ! is_array( $entrada ) ) continue;
        $ts             = esc_html( $entrada['timestamp']     ?? '—' );
        $evento_nombre  = esc_html( $entrada['evento_nombre'] ?? '—' );
        $ticket_id      = isset( $entrada['ticket_id'] ) ? (int) $entrada['ticket_id'] : 0;
        $ticket_uid     = $ticket_id ? get_post_meta( $ticket_id, 'eventosapp_ticketID', true ) : '';
        $ticket_label   = $ticket_uid ?: ( $ticket_id ? "#{$ticket_id}" : '—' );
        $campos         = isset( $entrada['campos_actualizados'] ) && is_array( $entrada['campos_actualizados'] )
                            ? $entrada['campos_actualizados'] : [];
        $num            = count( $changelog ) - $idx;
    ?>
    <div class="evapp-cl-entry">
        <div class="evapp-cl-header">
            <strong>Actualización #<?php echo $num; ?></strong>
            <span class="evapp-cl-meta">📅 <?php echo $ts; ?></span>
            <span class="evapp-cl-meta">🎟 Ticket: <em><?php echo esc_html( $ticket_label ); ?></em></span>
            <span class="evapp-cl-meta">🎪 <?php echo $evento_nombre; ?></span>
        </div>
        <div class="evapp-cl-body">
        <?php if ( empty( $campos ) ) : ?>
            <em style="color:#888;">Sin cambios de datos registrados.</em>
        <?php else : ?>
            <table class="evapp-cl-table">
                <thead>
                    <tr>
                        <th>Campo</th>
                        <th>Valor anterior</th>
                        <th>Valor nuevo</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $campos as $campo ) :
                    $antes  = isset( $campo['anterior'] ) && $campo['anterior'] !== '' ? esc_html( $campo['anterior'] ) : '<em style="color:#aaa;">vacío</em>';
                    $despues = isset( $campo['nuevo'] )   && $campo['nuevo']   !== '' ? esc_html( $campo['nuevo'] )   : '<em style="color:#aaa;">vacío</em>';
                ?>
                    <tr>
                        <th><?php echo esc_html( $campo['campo'] ?? '—' ); ?></th>
                        <td class="before"><?php echo $antes; ?></td>
                        <td class="after"><?php echo $despues; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <p style="font-size:12px;color:#888;margin-top:8px;">Total de entradas: <strong><?php echo count( $changelog ); ?></strong></p>
    <?php
}
