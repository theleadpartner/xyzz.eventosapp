<?php
// includes/admin/eventosapp-event-checklist.php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Checklist de evento — Configuración (metabox), frontend (shortcode) y notificaciones.
 *
 * Meta por evento (config):
 *  - _evchk_montaje_dt           : 'Y-m-d H:i' (hora local del evento)
 *  - _evchk_logistica_cant       : int
 *  - _evchk_need_evid_manual     : '1'|'0'
 *  - _evchk_need_evid_qr         : '1'|'0'
 *  - _evchk_need_evid_multi      : '1'|'0'
 *  - _evchk_tipo_correcto        : 'escarapela'|'manilla_colores'|'manilla_qr'
 *  - _evchk_deadline_dt          : 'Y-m-d H:i' (hora local del evento)
 *  - _evchk_aprobador_email      : email
 *
 * Estado del checklist por usuario coordinador:
 *  - _evchk_submissions : array [ user_id => array {
 *        'completed'           => bool,
 *        'completed_at'        => int (UTC),
 *        'montaje_choice'      => 'Y-m-d H:i',
 *        'montaje_ok'          => bool,
 *        'logistica_nombres'   => string[],
 *        'evid_manual_id'      => int|0,
 *        'evid_qr_id'          => int|0,
 *        'evid_multi_id'       => int|0,
 *        'tipo_choice'         => string,
 *        'tipo_ok'             => bool,
 *        'message_to_approver' => string,
 *    } ]
 */

// ============================================================
// HELPERS DE FECHA / CONTEXTO
// ============================================================

if ( ! function_exists( 'eventosapp_get_event_days' ) ) {
    function eventosapp_get_event_days( $event_id ) {
        $tipo = get_post_meta( $event_id, '_eventosapp_tipo_fecha', true ) ?: 'unica';

        if ( $tipo === 'unica' ) {
            $d = get_post_meta( $event_id, '_eventosapp_fecha_unica', true );
            return $d ? [ $d ] : [];
        }

        if ( $tipo === 'consecutiva' ) {
            $ini = get_post_meta( $event_id, '_eventosapp_fecha_inicio', true );
            $fin = get_post_meta( $event_id, '_eventosapp_fecha_fin', true );
            if ( ! $ini || ! $fin ) return [];

            $out  = [];
            $t    = strtotime( $ini );
            $tfin = strtotime( $fin );
            if ( $t === false || $tfin === false ) return [];

            for ( $x = $t; $x <= $tfin; $x += DAY_IN_SECONDS ) {
                $out[] = gmdate( 'Y-m-d', $x );
            }
            return $out;
        }

        $fechas = get_post_meta( $event_id, '_eventosapp_fechas_noco', true );
        return ( is_array( $fechas ) && $fechas )
            ? array_values( array_unique( array_map( 'strval', $fechas ) ) )
            : [];
    }
}

if ( ! function_exists( 'eventosapp_event_first_day' ) ) {
    function eventosapp_event_first_day( $event_id ) {
        $days = (array) eventosapp_get_event_days( $event_id );
        if ( ! $days ) return '';
        sort( $days );
        return (string) reset( $days );
    }
}

if ( ! function_exists( 'eventosapp_event_timezone' ) ) {
    function eventosapp_event_timezone( $event_id ) {
        if ( function_exists( 'eventosapp_get_event_timezone_object' ) ) {
            return eventosapp_get_event_timezone_object( $event_id );
        }

        $tzid = get_post_meta( $event_id, '_eventosapp_zona_horaria', true );
        if ( ! $tzid ) {
            $tzid = wp_timezone_string() ?: 'UTC';
        }

        try {
            return new DateTimeZone( $tzid );
        } catch ( Exception $e ) {
            return wp_timezone();
        }
    }
}

if ( ! function_exists( 'evchk_default_montaje_dt' ) ) {
    function evchk_default_montaje_dt( $event_id ) {
        $first = eventosapp_event_first_day( $event_id );
        if ( ! $first ) {
            try {
                $now   = new DateTime( 'now', eventosapp_event_timezone( $event_id ) );
                $first = $now->format( 'Y-m-d' );
            } catch ( Exception $e ) {
                $first = wp_date( 'Y-m-d' );
            }
        }
        return $first . ' 06:30';
    }
}

if ( ! function_exists( 'evchk_default_deadline_dt' ) ) {
    function evchk_default_deadline_dt( $event_id ) {
        $first = eventosapp_event_first_day( $event_id );
        if ( ! $first ) {
            try {
                $now   = new DateTime( 'now', eventosapp_event_timezone( $event_id ) );
                $first = $now->format( 'Y-m-d' );
            } catch ( Exception $e ) {
                $first = wp_date( 'Y-m-d' );
            }
        }

        try {
            $dt = new DateTime( $first . ' 18:00:00', eventosapp_event_timezone( $event_id ) );
            $dt->modify( '-1 day' );
            return $dt->format( 'Y-m-d H:i' );
        } catch ( Exception $e ) {
            return $first . ' 18:00';
        }
    }
}

if ( ! function_exists( 'evchk_normalize_local_datetime' ) ) {
    /**
     * Normaliza datetime-local sin convertir la hora del evento a UTC.
     * Si el valor es inválido o vacío se utiliza el fallback indicado.
     */
    function evchk_normalize_local_datetime( $raw_value, $event_id, $fallback = '' ) {
        if ( is_array( $raw_value ) || is_object( $raw_value ) ) {
            return $fallback;
        }

        if ( function_exists( 'eventosapp_normalize_datetime_local_value' ) ) {
            $value = eventosapp_normalize_datetime_local_value( $raw_value );
        } else {
            $value = trim( sanitize_text_field( wp_unslash( (string) $raw_value ) ) );
        }

        $value = str_replace( 'T', ' ', trim( (string) $value ) );
        if ( $value === '' ) {
            return $fallback;
        }

        try {
            $dt = DateTime::createFromFormat( '!Y-m-d H:i', $value, eventosapp_event_timezone( $event_id ) );
            if ( ! $dt instanceof DateTime ) {
                return $fallback;
            }

            $errors = DateTime::getLastErrors();
            if ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) {
                return $fallback;
            }

            return $dt->format( 'Y-m-d H:i' );
        } catch ( Exception $e ) {
            return $fallback;
        }
    }
}

if ( ! function_exists( 'evchk_datetime_object' ) ) {
    function evchk_datetime_object( $event_id, $value ) {
        $value = str_replace( 'T', ' ', trim( (string) $value ) );
        if ( $value === '' ) return null;

        try {
            $dt = DateTime::createFromFormat( '!Y-m-d H:i', $value, eventosapp_event_timezone( $event_id ) );
            if ( ! $dt instanceof DateTime ) return null;

            $errors = DateTime::getLastErrors();
            if ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) {
                return null;
            }

            return $dt;
        } catch ( Exception $e ) {
            return null;
        }
    }
}

if ( ! function_exists( 'evchk_format_datetime' ) ) {
    function evchk_format_datetime( $event_id, $value, $format = 'D, d M Y · H:i' ) {
        $dt = evchk_datetime_object( $event_id, $value );
        if ( ! $dt ) return (string) $value;

        return wp_date( $format, $dt->getTimestamp(), eventosapp_event_timezone( $event_id ) );
    }
}

if ( ! function_exists( 'evchk_format_event_day' ) ) {
    function evchk_format_event_day( $event_id, $day ) {
        if ( ! is_string( $day ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
            return (string) $day;
        }

        try {
            $dt = DateTime::createFromFormat( '!Y-m-d', $day, eventosapp_event_timezone( $event_id ) );
            if ( ! $dt ) return $day;
            return wp_date( 'd M Y', $dt->getTimestamp(), eventosapp_event_timezone( $event_id ) );
        } catch ( Exception $e ) {
            return $day;
        }
    }
}

// ============================================================
// HELPERS DE RUTAS / ESTADO / SEGURIDAD
// ============================================================

if ( ! function_exists( 'evchk_get_dashboard_url' ) ) {
    function evchk_get_dashboard_url() {
        $url = function_exists( 'eventosapp_get_dashboard_url' )
            ? eventosapp_get_dashboard_url()
            : home_url( '/' );

        return remove_query_arg( [ 'evapp', 'evapp_err', 'set' ], $url );
    }
}

if ( ! function_exists( 'evchk_get_checklist_url' ) ) {
    function evchk_get_checklist_url() {
        return function_exists( 'eventosapp_get_checklist_url' )
            ? eventosapp_get_checklist_url()
            : evchk_get_dashboard_url();
    }
}

if ( ! function_exists( 'evchk_tipo_options' ) ) {
    function evchk_tipo_options() {
        return [
            'escarapela'      => 'Escarapela',
            'manilla_colores' => 'Manilla de Colores',
            'manilla_qr'      => 'Manilla con QR',
        ];
    }
}

if ( ! function_exists( 'evchk_normalize_tipo' ) ) {
    function evchk_normalize_tipo( $value, $fallback = 'escarapela' ) {
        $value   = sanitize_key( (string) $value );
        $allowed = evchk_tipo_options();
        return isset( $allowed[ $value ] ) ? $value : $fallback;
    }
}

if ( ! function_exists( 'evchk_user_is_assigned_coordinator' ) ) {
    /**
     * Conserva la regla histórica del módulo: coordinador + asignación temporal vigente.
     */
    function evchk_user_is_assigned_coordinator( $event_id, $user_id ) {
        $event_id = absint( $event_id );
        $user_id  = absint( $user_id );
        $u        = get_userdata( $user_id );

        if ( ! $event_id || ! $u ) return false;
        if ( ! in_array( 'coordinador', (array) $u->roles, true ) ) return false;

        $co = get_post_meta( $event_id, '_evapp_temp_authors', true );
        if ( ! is_array( $co ) || ! $co ) return false;

        $now = time();
        foreach ( $co as $row ) {
            if ( absint( $row['user_id'] ?? 0 ) !== $user_id ) continue;

            $until = isset( $row['until'] ) ? (int) $row['until'] : 0;
            if ( ! $until || $until >= $now ) {
                return true;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'evchk_get_attachment_info' ) ) {
    function evchk_get_attachment_info( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        if ( ! $attachment_id ) {
            return [ 'id' => 0, 'url' => '', 'name' => '' ];
        }

        $url  = wp_get_attachment_url( $attachment_id );
        $file = get_attached_file( $attachment_id );
        $name = $file ? wp_basename( $file ) : get_the_title( $attachment_id );

        return [
            'id'   => $attachment_id,
            'url'  => $url ? $url : '',
            'name' => $name ? $name : ( 'Archivo #' . $attachment_id ),
        ];
    }
}

if ( ! function_exists( 'evchk_stable_montaje_options' ) ) {
    /**
     * Genera siempre 3 opciones estables: la real + dos distractores cercanos.
     * Evita que las respuestas cambien de posición/valor en cada recarga.
     */
    function evchk_stable_montaje_options( $event_id, $montaje_dt ) {
        $dt_real = evchk_datetime_object( $event_id, $montaje_dt );
        if ( ! $dt_real ) return [ (string) $montaje_dt ];

        $seed  = abs( crc32( absint( $event_id ) . '|' . $montaje_dt ) );
        $minus = 10 + ( $seed % 16 );
        $plus  = 12 + ( (int) floor( $seed / 16 ) % 20 );

        $before = clone $dt_real;
        $after  = clone $dt_real;
        $before->modify( '-' . $minus . ' minutes' );
        $after->modify( '+' . $plus . ' minutes' );

        $opts = [
            $dt_real->format( 'Y-m-d H:i' ),
            $before->format( 'Y-m-d H:i' ),
            $after->format( 'Y-m-d H:i' ),
        ];

        usort( $opts, static function( $a, $b ) use ( $event_id ) {
            return strcmp(
                md5( absint( $event_id ) . '|' . $a ),
                md5( absint( $event_id ) . '|' . $b )
            );
        } );

        return array_values( array_unique( $opts ) );
    }
}

if ( ! function_exists( 'evchk_logistica_is_ready' ) ) {
    function evchk_logistica_is_ready( $names, $required_count ) {
        $required_count = max( 0, (int) $required_count );
        if ( $required_count === 0 ) return true;
        if ( ! is_array( $names ) ) return false;

        $filled = 0;
        for ( $i = 0; $i < $required_count; $i++ ) {
            if ( isset( $names[ $i ] ) && trim( (string) $names[ $i ] ) !== '' ) {
                $filled++;
            }
        }
        return $filled === $required_count;
    }
}

if ( ! function_exists( 'evchk_submission_progress' ) ) {
    function evchk_submission_progress( $mine, $log_cant, $need_manual, $need_qr, $need_multi ) {
        $mine  = is_array( $mine ) ? $mine : [];
        $total = 3 + ( $need_manual ? 1 : 0 ) + ( $need_qr ? 1 : 0 ) + ( $need_multi ? 1 : 0 );
        $ready = 0;

        if ( ! empty( $mine['montaje_ok'] ) ) $ready++;
        if ( evchk_logistica_is_ready( $mine['logistica_nombres'] ?? [], $log_cant ) ) $ready++;
        if ( ! empty( $mine['tipo_ok'] ) ) $ready++;
        if ( $need_manual && ! empty( $mine['evid_manual_id'] ) ) $ready++;
        if ( $need_qr && ! empty( $mine['evid_qr_id'] ) ) $ready++;
        if ( $need_multi && ! empty( $mine['evid_multi_id'] ) ) $ready++;

        return [
            'total'   => max( 1, $total ),
            'ready'   => $ready,
            'percent' => (int) round( ( $ready / max( 1, $total ) ) * 100 ),
        ];
    }
}

if ( ! function_exists( 'evchk_notice_html' ) ) {
    function evchk_notice_html( $type, $message ) {
        $type = in_array( $type, [ 'success', 'warning', 'error', 'info' ], true ) ? $type : 'info';
        $icon = $type === 'success'
            ? '<path d="M20 6 9 17l-5-5"></path>'
            : ( $type === 'error'
                ? '<circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 17h.01"></path>'
                : '<circle cx="12" cy="12" r="9"></circle><path d="M12 11v5M12 8h.01"></path>' );

        return '<div class="evchk-notice evchk-notice-' . esc_attr( $type ) . '" role="' . ( $type === 'error' ? 'alert' : 'status' ) . '">'
            . '<span class="evchk-notice-icon" aria-hidden="true"><svg viewBox="0 0 24 24">' . $icon . '</svg></span>'
            . '<div class="evchk-notice-copy">' . wp_kses_post( $message ) . '</div>'
            . '</div>';
    }
}

// ============================================================
// METABOX: CONFIGURACIÓN DEL CHECKLIST
// ============================================================

add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'evapp_event_checklist_cfg',
        'Checklist del Evento (Configuración)',
        'evchk_render_metabox',
        'eventosapp_event',
        'normal',
        'high'
    );
} );

if ( ! function_exists( 'evchk_render_metabox' ) ) {
    function evchk_render_metabox( $post ) {
        wp_nonce_field( 'evchk_save', 'evchk_nonce' );
        $eid = absint( $post->ID );

        $montaje_dt  = get_post_meta( $eid, '_evchk_montaje_dt', true ) ?: evchk_default_montaje_dt( $eid );
        $log_cant    = (int) get_post_meta( $eid, '_evchk_logistica_cant', true );
        $need_manual = get_post_meta( $eid, '_evchk_need_evid_manual', true ) === '1';
        $need_qr     = get_post_meta( $eid, '_evchk_need_evid_qr', true ) === '1';
        $need_multi  = get_post_meta( $eid, '_evchk_need_evid_multi', true ) === '1';
        $tipo_ok     = evchk_normalize_tipo( get_post_meta( $eid, '_evchk_tipo_correcto', true ) ?: 'escarapela' );
        $deadline_dt = get_post_meta( $eid, '_evchk_deadline_dt', true ) ?: evchk_default_deadline_dt( $eid );
        $approver    = get_post_meta( $eid, '_evchk_aprobador_email', true ) ?: '';
        $lugar       = get_post_meta( $eid, '_eventosapp_direccion', true ) ?: '';
        $tz          = eventosapp_event_timezone( $eid );
        $tipo_opts   = evchk_tipo_options();
        ?>
        <style id="evchk-admin-metabox-css">
        .evchk-admin{
            --evchk-primary:#3279bd;
            --evchk-primary-dark:#255f96;
            --evchk-primary-soft:#eaf4ff;
            --evchk-border:#dfe7f1;
            --evchk-text:#182230;
            --evchk-muted:#64748b;
            --evchk-bg:#f7f9fc;
            color:var(--evchk-text);
        }
        .evchk-admin,.evchk-admin *{box-sizing:border-box}
        .evchk-admin-intro{display:flex;align-items:flex-start;gap:12px;margin:0 0 16px;padding:14px 16px;border:1px solid #cfe3f6;border-radius:14px;background:var(--evchk-primary-soft)}
        .evchk-admin-intro-icon{width:38px;height:38px;flex:0 0 38px;display:grid;place-items:center;color:#fff;background:linear-gradient(145deg,var(--evchk-primary),var(--evchk-primary-dark));border-radius:12px}
        .evchk-admin-intro-icon svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
        .evchk-admin-intro strong{display:block;margin:1px 0 3px;font-size:14px}
        .evchk-admin-intro p{margin:0;color:var(--evchk-muted);font-size:12px;line-height:1.5}
        .evchk-admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
        .evchk-admin-card{min-width:0;padding:16px;border:1px solid var(--evchk-border);border-radius:14px;background:#fff;box-shadow:0 5px 16px rgba(31,52,73,.035)}
        .evchk-admin-card h4{margin:0 0 4px;color:var(--evchk-text);font-size:14px}
        .evchk-admin-card-desc{margin:0 0 14px;color:var(--evchk-muted);font-size:12px;line-height:1.45}
        .evchk-admin-field{margin:0 0 14px}.evchk-admin-field:last-child{margin-bottom:0}
        .evchk-admin-field>label,.evchk-admin-label{display:block;margin:0 0 6px;color:var(--evchk-text);font-size:12px;font-weight:700}
        .evchk-admin input[type="text"],.evchk-admin input[type="email"],.evchk-admin input[type="number"],.evchk-admin input[type="datetime-local"],.evchk-admin select{width:100%;max-width:none;min-height:40px;margin:0;padding:7px 10px;border:1px solid #cbd5e1;border-radius:9px;box-shadow:none}
        .evchk-admin input:focus,.evchk-admin select:focus{border-color:var(--evchk-primary);box-shadow:0 0 0 3px rgba(50,121,189,.12);outline:0}
        .evchk-admin input[readonly]{color:#64748b;background:#f8fafc}
        .evchk-admin-help{display:block;margin-top:6px;color:var(--evchk-muted);font-size:11px;line-height:1.45}
        .evchk-admin-checks{display:grid;gap:8px}
        .evchk-admin-check{display:flex;align-items:flex-start;gap:9px;margin:0;padding:10px 11px;border:1px solid var(--evchk-border);border-radius:10px;background:var(--evchk-bg);font-size:12px;line-height:1.4;cursor:pointer}
        .evchk-admin-check input{margin:1px 0 0;accent-color:var(--evchk-primary)}
        @media(max-width:1050px){.evchk-admin-grid{grid-template-columns:1fr}}
        @media(max-width:782px){.evchk-admin-card{padding:14px}.evchk-admin input[type="text"],.evchk-admin input[type="email"],.evchk-admin input[type="number"],.evchk-admin input[type="datetime-local"],.evchk-admin select{font-size:16px}}
        </style>

        <div class="evchk-admin">
            <div class="evchk-admin-intro">
                <span class="evchk-admin-intro-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2"></rect><path d="M8 8h8M8 12h8M8 16h5"></path></svg>
                </span>
                <div>
                    <strong>Configuración operativa del checklist</strong>
                    <p>Define las respuestas correctas, las evidencias requeridas y el plazo del coordinador. Zona horaria usada: <?php echo esc_html( $tz->getName() ); ?>.</p>
                </div>
            </div>

            <div class="evchk-admin-grid">
                <section class="evchk-admin-card">
                    <h4>Datos generales</h4>
                    <p class="evchk-admin-card-desc">Información que el coordinador deberá validar antes del evento.</p>

                    <div class="evchk-admin-field">
                        <label for="evchk-admin-lugar">Lugar (referencia)</label>
                        <input id="evchk-admin-lugar" type="text" value="<?php echo esc_attr( $lugar ); ?>" readonly>
                    </div>

                    <div class="evchk-admin-field">
                        <label for="evchk-admin-montaje">Fecha y hora de montaje</label>
                        <input id="evchk-admin-montaje" type="datetime-local" name="evchk_montaje_dt" value="<?php echo esc_attr( str_replace( ' ', 'T', $montaje_dt ) ); ?>">
                        <span class="evchk-admin-help">Valor sugerido: <?php echo esc_html( evchk_format_datetime( $eid, evchk_default_montaje_dt( $eid ) ) ); ?>.</span>
                    </div>

                    <div class="evchk-admin-field">
                        <label for="evchk-admin-logistica">Cantidad de personal de logística</label>
                        <input id="evchk-admin-logistica" type="number" min="0" step="1" name="evchk_logistica_cant" value="<?php echo esc_attr( $log_cant ); ?>">
                    </div>
                </section>

                <section class="evchk-admin-card">
                    <h4>Evidencias y validaciones</h4>
                    <p class="evchk-admin-card-desc">Activa únicamente las evidencias que deban comprobarse para este evento.</p>

                    <div class="evchk-admin-checks">
                        <label class="evchk-admin-check"><input type="checkbox" name="evchk_need_evid_manual" value="1" <?php checked( $need_manual ); ?>><span>Pedir evidencia de <strong>Check-in Manual</strong></span></label>
                        <label class="evchk-admin-check"><input type="checkbox" name="evchk_need_evid_qr" value="1" <?php checked( $need_qr ); ?>><span>Pedir evidencia de <strong>Check-in por QR</strong></span></label>
                        <label class="evchk-admin-check"><input type="checkbox" name="evchk_need_evid_multi" value="1" <?php checked( $need_multi ); ?>><span>Pedir evidencia de <strong>Check-in de varias sesiones</strong></span></label>
                    </div>

                    <div class="evchk-admin-field" style="margin-top:14px;">
                        <label for="evchk-admin-tipo">Tipo correcto del evento</label>
                        <select id="evchk-admin-tipo" name="evchk_tipo_correcto">
                            <?php foreach ( $tipo_opts as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $tipo_ok, $value ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </section>

                <section class="evchk-admin-card">
                    <h4>Control de tiempo</h4>
                    <p class="evchk-admin-card-desc">Al vencer el plazo se ejecutará la notificación programada si nadie ha completado el checklist.</p>

                    <div class="evchk-admin-field">
                        <label for="evchk-admin-deadline">Fecha y hora máxima para elaborar el checklist</label>
                        <input id="evchk-admin-deadline" type="datetime-local" name="evchk_deadline_dt" value="<?php echo esc_attr( str_replace( ' ', 'T', $deadline_dt ) ); ?>">
                        <span class="evchk-admin-help">Valor sugerido: <?php echo esc_html( evchk_format_datetime( $eid, evchk_default_deadline_dt( $eid ) ) ); ?>.</span>
                    </div>
                </section>

                <section class="evchk-admin-card">
                    <h4>Aprobación</h4>
                    <p class="evchk-admin-card-desc">Destino de los avisos de finalización y de incumplimiento del plazo.</p>

                    <div class="evchk-admin-field">
                        <label for="evchk-admin-approver">Correo del aprobador</label>
                        <input id="evchk-admin-approver" type="email" name="evchk_aprobador_email" value="<?php echo esc_attr( $approver ); ?>" autocomplete="email">
                        <span class="evchk-admin-help">Se enviará el detalle cuando el coordinador termine el checklist y un aviso si vence el plazo sin completarse.</span>
                    </div>
                </section>
            </div>
        </div>
        <?php
    }
}

add_action( 'save_post_eventosapp_event', function( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( empty( $_POST['evchk_nonce'] ) ) return;

    $nonce = sanitize_text_field( wp_unslash( $_POST['evchk_nonce'] ) );
    if ( ! wp_verify_nonce( $nonce, 'evchk_save' ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $montaje_dt = isset( $_POST['evchk_montaje_dt'] )
        ? evchk_normalize_local_datetime( $_POST['evchk_montaje_dt'], $post_id, evchk_default_montaje_dt( $post_id ) )
        : evchk_default_montaje_dt( $post_id );

    $deadline_dt = isset( $_POST['evchk_deadline_dt'] )
        ? evchk_normalize_local_datetime( $_POST['evchk_deadline_dt'], $post_id, evchk_default_deadline_dt( $post_id ) )
        : evchk_default_deadline_dt( $post_id );

    $log_cant    = isset( $_POST['evchk_logistica_cant'] ) ? max( 0, (int) wp_unslash( $_POST['evchk_logistica_cant'] ) ) : 0;
    $need_manual = ! empty( $_POST['evchk_need_evid_manual'] ) ? '1' : '0';
    $need_qr     = ! empty( $_POST['evchk_need_evid_qr'] ) ? '1' : '0';
    $need_multi  = ! empty( $_POST['evchk_need_evid_multi'] ) ? '1' : '0';
    $tipo_ok     = isset( $_POST['evchk_tipo_correcto'] ) ? evchk_normalize_tipo( wp_unslash( $_POST['evchk_tipo_correcto'] ) ) : 'escarapela';
    $approver    = isset( $_POST['evchk_aprobador_email'] ) ? sanitize_email( wp_unslash( $_POST['evchk_aprobador_email'] ) ) : '';

    update_post_meta( $post_id, '_evchk_montaje_dt', $montaje_dt );
    update_post_meta( $post_id, '_evchk_logistica_cant', $log_cant );
    update_post_meta( $post_id, '_evchk_need_evid_manual', $need_manual );
    update_post_meta( $post_id, '_evchk_need_evid_qr', $need_qr );
    update_post_meta( $post_id, '_evchk_need_evid_multi', $need_multi );
    update_post_meta( $post_id, '_evchk_tipo_correcto', $tipo_ok );
    update_post_meta( $post_id, '_evchk_deadline_dt', $deadline_dt );
    update_post_meta( $post_id, '_evchk_aprobador_email', $approver );

    evchk_schedule_deadline_check( $post_id, $deadline_dt );
}, 40 );

// ============================================================
// PROGRAMACIÓN DEL AVISO DE DEADLINE
// ============================================================

if ( ! function_exists( 'evchk_schedule_deadline_check' ) ) {
    function evchk_schedule_deadline_check( $event_id, $deadline_local ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) return;

        $hook = 'evchk_deadline_check';
        $args = [ $event_id ];

        // Limpiar todas las programaciones anteriores de este evento, no solo la primera.
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            wp_clear_scheduled_hook( $hook, $args );
        } else {
            while ( $ts_existing = wp_next_scheduled( $hook, $args ) ) {
                wp_unschedule_event( $ts_existing, $hook, $args );
            }
        }

        $dt = evchk_datetime_object( $event_id, $deadline_local );
        if ( ! $dt ) return;

        $utc_ts = $dt->getTimestamp();
        if ( $utc_ts > time() - 60 ) {
            wp_schedule_single_event( $utc_ts, $hook, $args );
        }
    }
}

add_action( 'evchk_deadline_check', function( $event_id ) {
    $event_id = absint( $event_id );
    if ( ! $event_id ) return;

    $subs      = get_post_meta( $event_id, '_evchk_submissions', true );
    $completed = false;

    if ( is_array( $subs ) ) {
        foreach ( $subs as $row ) {
            if ( is_array( $row ) && ! empty( $row['completed'] ) ) {
                $completed = true;
                break;
            }
        }
    }

    if ( $completed ) return;

    $to = sanitize_email( get_post_meta( $event_id, '_evchk_aprobador_email', true ) );
    if ( ! $to || ! is_email( $to ) ) return;

    $event_name = get_the_title( $event_id );
    $subject    = 'Checklist NO realizado a tiempo — ' . $event_name;
    $body       = '<p>El checklist del evento <strong>' . esc_html( $event_name ) . '</strong> no fue completado antes de la hora límite.</p>';
    $body      .= '<p><a href="' . esc_url( evchk_get_checklist_url() ) . '">Ver página del checklist</a></p>';

    wp_mail( $to, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
} );

// ============================================================
// UPLOAD DE EVIDENCIAS
// ============================================================

if ( ! function_exists( 'evchk_handle_evidence_upload' ) ) {
    /**
     * Carga una evidencia preservando el adjunto previo si el nuevo archivo falla.
     */
    function evchk_handle_evidence_upload( $field_name, $existing_id = 0, &$error_message = '' ) {
        $existing_id   = absint( $existing_id );
        $error_message = '';

        if ( empty( $_FILES[ $field_name ] ) || empty( $_FILES[ $field_name ]['name'] ) ) {
            return $existing_id;
        }

        $upload_error = isset( $_FILES[ $field_name ]['error'] ) ? (int) $_FILES[ $field_name ]['error'] : UPLOAD_ERR_OK;
        if ( $upload_error !== UPLOAD_ERR_OK ) {
            $error_message = 'No se pudo cargar la evidencia seleccionada. Verifica el tamaño del archivo e intenta nuevamente.';
            return $existing_id;
        }

        $attachment_id = media_handle_upload( $field_name, 0 );
        if ( is_wp_error( $attachment_id ) ) {
            $error_message = $attachment_id->get_error_message();
            return $existing_id;
        }

        $attachment_id = absint( $attachment_id );
        $mime           = get_post_mime_type( $attachment_id );
        if ( ! $mime || strpos( $mime, 'image/' ) !== 0 ) {
            wp_delete_attachment( $attachment_id, true );
            $error_message = 'La evidencia debe ser una imagen válida.';
            return $existing_id;
        }

        return $attachment_id;
    }
}

// ============================================================
// ESTILOS FRONTEND
// ============================================================

if ( ! function_exists( 'evchk_enqueue_front_style' ) ) {
    function evchk_enqueue_front_style() {
        static $added = false;

        if ( ! wp_style_is( 'eventosapp-event-checklist', 'registered' ) ) {
            wp_register_style( 'eventosapp-event-checklist', false, [], null );
        }

        if ( ! $added ) {
            $added = true;
            $css = <<<'CSS'
.evchk-app{
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
.evchk-app *,
.evchk-app *::before,
.evchk-app *::after{box-sizing:border-box}
.evchk-app a{text-decoration:none}
.evchk-app svg{display:block;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.evchk-app .screen-reader-text{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.evchk-shell{width:100%;padding:clamp(18px,3vw,36px);background:var(--evapp-app-bg);border:1px solid var(--evapp-border);border-radius:var(--evapp-radius-lg);box-shadow:0 18px 50px rgba(31,52,73,.08);container-type:inline-size;container-name:evchk-shell}
.evchk-header{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:start;gap:24px;margin-bottom:22px}
.evchk-heading{min-width:0}
.evchk-eyebrow{margin:0 0 6px;color:var(--evapp-primary);font-size:12px;font-weight:800;letter-spacing:.11em;text-transform:uppercase}
.evchk-main-title{margin:0;color:var(--evapp-text);font-size:clamp(27px,4vw,42px);font-weight:850;line-height:1.08;letter-spacing:-.035em}
.evchk-subtitle{max-width:790px;margin:10px 0 0;color:var(--evapp-muted);font-size:15px;line-height:1.6}
.evchk-header-actions{min-width:0;justify-self:end}
.evchk-btn{min-height:44px;display:inline-flex;align-items:center;justify-content:center;gap:9px;margin:0;padding:10px 15px;border:1px solid transparent;border-radius:12px;font:inherit;font-size:14px;font-weight:750;line-height:1.15;text-align:center;cursor:pointer;transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease,background .16s ease,color .16s ease,opacity .16s ease;-webkit-tap-highlight-color:transparent}
.evchk-btn svg{width:18px;height:18px;flex:0 0 18px}
.evchk-btn:hover:not(:disabled){transform:translateY(-1px)}
.evchk-btn:focus-visible{outline:3px solid rgba(50,121,189,.22);outline-offset:2px}
.evchk-btn:disabled{opacity:.52;cursor:not-allowed;transform:none!important;box-shadow:none!important}
.evchk-btn-secondary{color:var(--evapp-text)!important;background:var(--evapp-surface);border-color:var(--evapp-border);box-shadow:0 5px 15px rgba(31,65,99,.05);white-space:nowrap}
.evchk-btn-secondary:hover{color:var(--evapp-primary-dark)!important;border-color:#c7d7e8;box-shadow:0 8px 20px rgba(31,65,99,.09)}
.evchk-btn-primary{color:#fff!important;background:var(--evapp-primary);border-color:var(--evapp-primary);box-shadow:0 9px 20px rgba(50,121,189,.18)}
.evchk-btn-primary:hover{color:#fff!important;background:var(--evapp-primary-dark);border-color:var(--evapp-primary-dark);box-shadow:0 12px 24px rgba(50,121,189,.24)}
.evchk-event-context{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:16px;margin-bottom:18px;padding:16px 18px;background:var(--evapp-surface);border:1px solid var(--evapp-border);border-radius:var(--evapp-radius);box-shadow:0 8px 24px rgba(31,52,73,.045)}
.evchk-event-main{min-width:0;width:100%;display:flex;align-items:center;gap:13px}
.evchk-event-icon{width:44px;height:44px;flex:0 0 44px;display:grid;place-items:center;color:var(--evapp-primary);background:var(--evapp-primary-soft);border-radius:13px}
.evchk-event-icon svg{width:22px;height:22px}
.evchk-event-copy{min-width:0}
.evchk-event-kicker{display:block;margin-bottom:3px;color:var(--evapp-muted);font-size:11px;font-weight:800;letter-spacing:.09em;text-transform:uppercase}
.evchk-event-name{display:block;overflow:hidden;color:var(--evapp-text);font-size:15px;font-weight:800;line-height:1.3;text-overflow:ellipsis;white-space:nowrap}
.evchk-event-detail{display:block;margin-top:3px;color:var(--evapp-muted);font-size:12px;line-height:1.35;overflow-wrap:anywhere}
.evchk-event-meta{min-width:0;display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:8px}
.evchk-chip{min-height:30px;display:inline-flex;align-items:center;gap:7px;padding:6px 10px;border:1px solid var(--evapp-border);border-radius:999px;background:#fff;color:var(--evapp-muted);font-size:12px;font-weight:750;white-space:nowrap}
.evchk-chip::before{width:7px;height:7px;border-radius:50%;background:#94a3b8;content:""}
.evchk-notice{display:flex;align-items:flex-start;gap:11px;margin:0 0 18px;padding:14px 16px;border:1px solid var(--evapp-border);border-radius:14px;background:#fff;font-size:13px;line-height:1.5}
.evchk-notice-icon{width:28px;height:28px;flex:0 0 28px;display:grid;place-items:center;border-radius:9px}
.evchk-notice-icon svg{width:17px;height:17px}
.evchk-notice-copy{min-width:0;padding-top:3px}
.evchk-notice-success{color:#0f5132;border-color:#b7e4c7;background:var(--evapp-success-soft)}
.evchk-notice-success .evchk-notice-icon{color:#fff;background:var(--evapp-success)}
.evchk-notice-warning{color:#7c4a03;border-color:#f1dfad;background:var(--evapp-warning-soft)}
.evchk-notice-warning .evchk-notice-icon{color:#fff;background:#d69e2e}
.evchk-notice-error{color:#8b1e17;border-color:#f2b8b5;background:var(--evapp-danger-soft)}
.evchk-notice-error .evchk-notice-icon{color:#fff;background:var(--evapp-danger)}
.evchk-notice-info{color:var(--evapp-primary-dark);border-color:#cfe3f6;background:var(--evapp-primary-soft)}
.evchk-notice-info .evchk-notice-icon{color:#fff;background:var(--evapp-primary)}
.evchk-overview{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(260px,.8fr);align-items:stretch;gap:14px;margin-bottom:18px}
.evchk-summary-card,.evchk-deadline-card,.evchk-task,.evchk-submit-card,.evchk-empty-card{background:var(--evapp-surface);border:1px solid var(--evapp-border);border-radius:var(--evapp-radius);box-shadow:0 8px 26px rgba(31,52,73,.05)}
.evchk-summary-card{padding:18px}
.evchk-summary-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:13px}
.evchk-summary-title{margin:0;color:var(--evapp-text);font-size:16px;font-weight:820;line-height:1.3}
.evchk-summary-copy{margin:4px 0 0;color:var(--evapp-muted);font-size:12px;line-height:1.45}
.evchk-progress-number{flex:0 0 auto;color:var(--evapp-primary-dark);font-size:14px;font-weight:850}
.evchk-progress-track{height:10px;overflow:hidden;background:#e8eef5;border-radius:999px}
.evchk-progress-bar{display:block;width:0;height:100%;background:linear-gradient(90deg,var(--evapp-primary),#5ca4df);border-radius:inherit;transition:width .2s ease}
.evchk-progress-meta{display:flex;justify-content:space-between;gap:12px;margin-top:8px;color:var(--evapp-muted);font-size:11px;font-weight:700}
.evchk-deadline-card{position:relative;display:flex;align-items:center;gap:13px;padding:18px;overflow:hidden}
.evchk-deadline-card::before{position:absolute;inset:0 auto 0 0;width:4px;background:#d69e2e;content:""}
.evchk-deadline-card.is-over{border-color:#f2b8b5;background:var(--evapp-danger-soft)}
.evchk-deadline-card.is-over::before{background:var(--evapp-danger)}
.evchk-deadline-icon{width:42px;height:42px;flex:0 0 42px;display:grid;place-items:center;color:var(--evapp-warning);background:var(--evapp-warning-soft);border-radius:12px}
.evchk-deadline-card.is-over .evchk-deadline-icon{color:var(--evapp-danger);background:#ffe0dd}
.evchk-deadline-icon svg{width:21px;height:21px}
.evchk-deadline-copy{min-width:0}
.evchk-deadline-label{display:block;color:var(--evapp-muted);font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.evchk-deadline-date{display:block;margin-top:2px;color:var(--evapp-text);font-size:13px;font-weight:800;line-height:1.35}
.evchk-countdown{display:block;margin-top:4px;color:var(--evapp-warning);font-size:15px;font-weight:850;font-variant-numeric:tabular-nums}
.evchk-deadline-card.is-over .evchk-countdown{color:var(--evapp-danger)}
.evchk-form{display:grid;gap:14px}
.evchk-task{padding:20px}
.evchk-task-head{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:start;gap:16px;margin-bottom:15px}
.evchk-task-heading{min-width:0;display:flex;align-items:flex-start;gap:12px}
.evchk-task-icon{width:40px;height:40px;flex:0 0 40px;display:grid;place-items:center;color:var(--evapp-primary);background:var(--evapp-primary-soft);border-radius:12px}
.evchk-task-icon svg{width:20px;height:20px}
.evchk-task-title{margin:0;color:var(--evapp-text);font-size:16px;font-weight:820;line-height:1.3}
.evchk-task-desc{margin:4px 0 0;color:var(--evapp-muted);font-size:12px;line-height:1.5}
.evchk-status-pill{min-height:29px;display:inline-flex;align-items:center;gap:7px;flex:0 0 auto;padding:6px 10px;border:1px solid #f1dfad;border-radius:999px;color:#8a5707;background:var(--evapp-warning-soft);font-size:11px;font-weight:800;white-space:nowrap}
.evchk-status-pill::before{width:7px;height:7px;border-radius:50%;background:#d69e2e;content:""}
.evchk-status-pill.is-ready{color:var(--evapp-success);border-color:#cfeadf;background:var(--evapp-success-soft)}
.evchk-status-pill.is-ready::before{background:var(--evapp-success)}
.evchk-app .evchk-options,.evchk-app .evchk-type-options{display:grid!important;grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))!important;grid-auto-flow:row!important;align-items:stretch;justify-content:stretch;gap:10px;width:100%;min-width:0}
.evchk-app .evchk-options>.evchk-option,.evchk-app .evchk-type-options>.evchk-option{width:100%!important;max-width:none!important;min-width:0;margin:0!important;grid-column:auto!important;grid-row:auto!important;justify-self:stretch!important;align-self:stretch!important}
.evchk-app .evchk-option>span{min-width:0;overflow-wrap:anywhere}
.evchk-option{position:relative;display:flex;align-items:center;gap:10px;min-height:58px;margin:0;padding:12px 13px;border:1px solid var(--evapp-border);border-radius:13px;background:#fbfdff;color:var(--evapp-text);font-size:13px;font-weight:720;line-height:1.35;cursor:pointer;transition:border-color .15s ease,box-shadow .15s ease,background .15s ease}
.evchk-option:hover{border-color:#c4dbee;background:#fff}
.evchk-option:has(input:checked){border-color:var(--evapp-primary);background:var(--evapp-primary-soft);box-shadow:0 0 0 3px rgba(50,121,189,.09)}
.evchk-option input[type="radio"]{width:18px;height:18px;flex:0 0 18px;margin:0;accent-color:var(--evapp-primary)}
.evchk-option input:focus-visible{outline:2px solid var(--evapp-primary);outline-offset:2px}
.evchk-option.is-disabled{cursor:not-allowed;opacity:.68}
.evchk-field-error{margin-top:11px;padding:9px 11px;border:1px solid #f2b8b5;border-radius:10px;color:#8b1e17;background:var(--evapp-danger-soft);font-size:12px;font-weight:750;line-height:1.4}
.evchk-field-error[hidden]{display:none!important}
.evchk-app .evchk-log-grid{display:grid!important;grid-template-columns:repeat(auto-fit,minmax(min(260px,100%),1fr))!important;align-items:start;gap:10px;width:100%;min-width:0}
.evchk-field{min-width:0}
.evchk-label{display:block;margin:0 0 6px;color:var(--evapp-text);font-size:12px;font-weight:800}
.evchk-input,.evchk-textarea,.evchk-file{width:100%;margin:0;color:var(--evapp-text);background:#fff;border:1px solid var(--evapp-border);border-radius:12px;box-shadow:none;font:inherit;outline:none;transition:border-color .16s ease,box-shadow .16s ease,background .16s ease}
.evchk-input{min-height:46px;padding:10px 12px;font-size:14px}
.evchk-textarea{min-height:110px;padding:11px 12px;font-size:14px;resize:vertical}
.evchk-input:focus,.evchk-textarea:focus,.evchk-file:focus-within{border-color:var(--evapp-primary);box-shadow:0 0 0 4px rgba(50,121,189,.11)}
.evchk-input:disabled,.evchk-textarea:disabled{color:#64748b;background:#f8fafc}
.evchk-empty-note{margin:0;padding:12px 13px;border:1px dashed var(--evapp-border);border-radius:12px;color:var(--evapp-muted);background:#fbfdff;font-size:12px;line-height:1.5}
.evchk-file-wrap{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;padding:12px;border:1px dashed #c8d5e3;border-radius:13px;background:#fbfdff}
.evchk-file{min-height:44px;padding:7px 8px;font-size:12px}
.evchk-file::file-selector-button{min-height:30px;margin-right:9px;padding:6px 10px;border:0;border-radius:8px;color:var(--evapp-primary-dark);background:var(--evapp-primary-soft);font:inherit;font-size:11px;font-weight:800;cursor:pointer}
.evchk-evidence-current{min-width:0;display:flex;align-items:center;gap:8px;color:var(--evapp-success);font-size:11px;font-weight:750;overflow-wrap:anywhere}
.evchk-evidence-current svg{width:16px;height:16px;flex:0 0 16px}
.evchk-evidence-current a{color:inherit!important;text-decoration:underline}
.evchk-file-selected{margin-top:8px;color:var(--evapp-muted);font-size:11px;line-height:1.4;overflow-wrap:anywhere}
.evchk-submit-card{padding:20px}
.evchk-submit-head{display:flex;align-items:flex-start;gap:12px;margin-bottom:14px}
.evchk-submit-icon{width:40px;height:40px;flex:0 0 40px;display:grid;place-items:center;color:var(--evapp-primary);background:var(--evapp-primary-soft);border-radius:12px}
.evchk-submit-icon svg{width:20px;height:20px}
.evchk-submit-title{margin:0;color:var(--evapp-text);font-size:16px;font-weight:820}
.evchk-submit-desc{margin:4px 0 0;color:var(--evapp-muted);font-size:12px;line-height:1.5}
.evchk-submit-actions{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:14px;margin-top:14px;padding-top:14px;border-top:1px solid var(--evapp-border)}
.evchk-submit-note{min-width:0;max-width:none;color:var(--evapp-muted);font-size:11px;line-height:1.45;overflow-wrap:anywhere}
.evchk-submit-buttons{display:flex;align-items:center;justify-content:flex-end;gap:9px;min-width:0;justify-self:end}
.evchk-empty-card{padding:24px;text-align:center}
.evchk-empty-icon{width:52px;height:52px;display:grid;place-items:center;margin:0 auto 12px;color:var(--evapp-primary);background:var(--evapp-primary-soft);border-radius:16px}
.evchk-empty-icon svg{width:25px;height:25px}
.evchk-empty-title{margin:0;color:var(--evapp-text);font-size:17px;font-weight:820}
.evchk-empty-copy{max-width:580px;margin:7px auto 15px;color:var(--evapp-muted);font-size:13px;line-height:1.5}
/* Respuesta al ancho REAL del módulo: evita depender únicamente del viewport de Elementor. */
@container evchk-shell (max-width:820px){.evchk-event-context{grid-template-columns:1fr;align-items:start}.evchk-event-meta{width:100%;justify-content:flex-start}.evchk-overview{grid-template-columns:1fr}.evchk-submit-actions{grid-template-columns:1fr;align-items:stretch}.evchk-submit-buttons{width:100%;justify-self:stretch;justify-content:flex-start}}
@container evchk-shell (max-width:760px) and (min-width:621px){.evchk-app .evchk-options>.evchk-option:last-child:nth-child(odd),.evchk-app .evchk-type-options>.evchk-option:last-child:nth-child(odd){grid-column:1/-1!important}}
@container evchk-shell (max-width:620px){.evchk-header{grid-template-columns:1fr;margin-bottom:18px}.evchk-header-actions{justify-self:stretch}.evchk-header-actions .evchk-btn{width:100%}.evchk-app .evchk-options,.evchk-app .evchk-type-options,.evchk-app .evchk-log-grid{grid-template-columns:1fr!important}.evchk-task-head{grid-template-columns:1fr}.evchk-status-pill{justify-self:start}.evchk-file-wrap{grid-template-columns:1fr}.evchk-submit-buttons{display:grid;grid-template-columns:1fr}.evchk-submit-buttons .evchk-btn{width:100%}.evchk-summary-head{display:grid;grid-template-columns:minmax(0,1fr) auto}.evchk-progress-meta{flex-wrap:wrap}}
@container evchk-shell (max-width:480px){.evchk-main-title{font-size:clamp(28px,9vw,36px)}.evchk-event-name{white-space:normal}.evchk-chip{white-space:normal}.evchk-summary-head{grid-template-columns:1fr}.evchk-progress-number{justify-self:start}.evchk-task{padding:16px}.evchk-event-context{padding:14px}}
/* Fallback para navegadores sin container queries. */
@media(max-width:900px){.evchk-overview{grid-template-columns:1fr}.evchk-event-context{grid-template-columns:1fr;align-items:start}.evchk-event-meta{width:100%;justify-content:flex-start}.evchk-submit-actions{grid-template-columns:1fr;align-items:stretch}.evchk-submit-buttons{width:100%;justify-self:stretch;justify-content:flex-start}}
@media(max-width:767px){.evchk-shell{padding:16px;border-radius:20px}.evchk-header{grid-template-columns:1fr;margin-bottom:18px}.evchk-header-actions{justify-self:stretch}.evchk-header-actions .evchk-btn{width:100%}.evchk-event-context{padding:14px}.evchk-event-meta .evchk-btn{width:100%}.evchk-task{padding:16px}.evchk-task-head{grid-template-columns:1fr}.evchk-status-pill{justify-self:start}.evchk-file-wrap{grid-template-columns:1fr}.evchk-app .evchk-options,.evchk-app .evchk-type-options,.evchk-app .evchk-log-grid{grid-template-columns:1fr!important}.evchk-submit-buttons{display:grid;grid-template-columns:1fr}.evchk-submit-buttons .evchk-btn{width:100%}}
@media(max-width:480px){.evchk-main-title{font-size:clamp(28px,9vw,36px)}.evchk-event-name{white-space:normal}.evchk-chip{white-space:normal}.evchk-summary-head{display:grid;grid-template-columns:1fr}.evchk-progress-number{justify-self:start}}
@media(prefers-reduced-motion:reduce){.evchk-app *,.evchk-app *::before,.evchk-app *::after{scroll-behavior:auto!important;transition-duration:.01ms!important;animation-duration:.01ms!important;animation-iteration-count:1!important}}
CSS;
            wp_add_inline_style( 'eventosapp-event-checklist', $css );
        }

        wp_enqueue_style( 'eventosapp-event-checklist' );
    }
}

// ============================================================
// SHORTCODE FRONTEND
// ============================================================

add_shortcode( 'eventosapp_event_checklist', function() {
    evchk_enqueue_front_style();

    if ( ! function_exists( 'eventosapp_require_feature' ) ) {
        return '<div class="evchk-app"><div class="evchk-shell">' . evchk_notice_html( 'error', 'No se pudo validar el acceso al checklist porque falta el helper <code>eventosapp_require_feature()</code>.' ) . '</div></div>';
    }

    eventosapp_require_feature( 'checklist' );

    $dashboard_url = evchk_get_dashboard_url();
    $event_id      = function_exists( 'eventosapp_get_active_event' ) ? absint( eventosapp_get_active_event() ) : 0;

    if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
        return '<div class="evchk-app"><div class="evchk-shell">'
            . '<header class="evchk-header"><div class="evchk-heading"><p class="evchk-eyebrow">EVENTOSAPP</p><h1 class="evchk-main-title">Checklist de Evento</h1><p class="evchk-subtitle">Verifica las tareas operativas antes de iniciar el evento.</p></div><div class="evchk-header-actions"><a class="evchk-btn evchk-btn-secondary" href="' . esc_url( $dashboard_url ) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg><span>Volver al dashboard</span></a></div></header>'
            . '<div class="evchk-empty-card"><div class="evchk-empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M7 3v4M17 3v4M3 9h18"></path></svg></div><h2 class="evchk-empty-title">Selecciona un evento</h2><p class="evchk-empty-copy">Primero debes escoger el evento activo desde el dashboard para poder consultar y diligenciar su checklist.</p><a class="evchk-btn evchk-btn-primary" href="' . esc_url( $dashboard_url ) . '">Ir al dashboard</a></div>'
            . '</div></div>';
    }

    $current_user = wp_get_current_user();
    $is_admin     = current_user_can( 'manage_options' );
    $is_coord     = evchk_user_is_assigned_coordinator( $event_id, $current_user->ID );
    $read_only    = ! $is_coord && ! $is_admin;

    $name     = get_the_title( $event_id ) ?: ( 'Evento #' . $event_id );
    $lugar    = get_post_meta( $event_id, '_eventosapp_direccion', true ) ?: 'Sin lugar configurado';
    $fechas   = (array) eventosapp_get_event_days( $event_id );
    $tz       = eventosapp_event_timezone( $event_id );
    $tz_label = $tz->getName();

    $event_modalidad_label = function_exists( 'eventosapp_get_event_modalidad_label' )
        ? eventosapp_get_event_modalidad_label( $event_id )
        : '';

    $montaje_dt  = get_post_meta( $event_id, '_evchk_montaje_dt', true ) ?: evchk_default_montaje_dt( $event_id );
    $log_cant    = max( 0, (int) get_post_meta( $event_id, '_evchk_logistica_cant', true ) );
    $need_manual = get_post_meta( $event_id, '_evchk_need_evid_manual', true ) === '1';
    $need_qr     = get_post_meta( $event_id, '_evchk_need_evid_qr', true ) === '1';
    $need_multi  = get_post_meta( $event_id, '_evchk_need_evid_multi', true ) === '1';
    $tipo_ok     = evchk_normalize_tipo( get_post_meta( $event_id, '_evchk_tipo_correcto', true ) ?: 'escarapela' );
    $deadline_dt = get_post_meta( $event_id, '_evchk_deadline_dt', true ) ?: evchk_default_deadline_dt( $event_id );
    $approver    = sanitize_email( get_post_meta( $event_id, '_evchk_aprobador_email', true ) ?: '' );
    $tipo_opts   = evchk_tipo_options();

    $subs = get_post_meta( $event_id, '_evchk_submissions', true );
    if ( ! is_array( $subs ) ) $subs = [];

    $mine = isset( $subs[ $current_user->ID ] ) && is_array( $subs[ $current_user->ID ] )
        ? $subs[ $current_user->ID ]
        : [];

    $msg = '';

    // ------------------------------------------------------------
    // Procesar guardado / finalización
    // ------------------------------------------------------------
    $posted_action = isset( $_POST['evchk_action'] ) ? sanitize_key( wp_unslash( $_POST['evchk_action'] ) ) : '';

    if ( ! $read_only && $posted_action === 'submit' ) {
        $front_nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

        if ( ! $front_nonce || ! wp_verify_nonce( $front_nonce, 'evchk_front_' . $event_id ) ) {
            $msg = evchk_notice_html( 'error', 'La sesión de seguridad expiró. Recarga la página e intenta nuevamente.' );
        } else {
            $ok_montaje = false;
            $ok_log     = false;
            $ok_tipo    = false;
            $names      = [];

            $sel_montaje = isset( $_POST['evchk_montaje_option'] )
                ? sanitize_text_field( wp_unslash( $_POST['evchk_montaje_option'] ) )
                : '';
            $ok_montaje = $sel_montaje !== '' && hash_equals( (string) $montaje_dt, (string) $sel_montaje );

            if ( $log_cant > 0 ) {
                for ( $i = 0; $i < $log_cant; $i++ ) {
                    $key         = 'evchk_log_name_' . $i;
                    $names[ $i ] = isset( $_POST[ $key ] )
                        ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) )
                        : '';
                }
                $ok_log = evchk_logistica_is_ready( $names, $log_cant );
            } else {
                $ok_log = true;
            }

            $tipo_choice = isset( $_POST['evchk_tipo'] )
                ? evchk_normalize_tipo( wp_unslash( $_POST['evchk_tipo'] ), '' )
                : '';
            $ok_tipo = $tipo_choice !== '' && hash_equals( (string) $tipo_ok, (string) $tipo_choice );

            $att_manual = isset( $mine['evid_manual_id'] ) ? absint( $mine['evid_manual_id'] ) : 0;
            $att_qr     = isset( $mine['evid_qr_id'] ) ? absint( $mine['evid_qr_id'] ) : 0;
            $att_multi  = isset( $mine['evid_multi_id'] ) ? absint( $mine['evid_multi_id'] ) : 0;

            $upload_errors = [];
            $has_new_upload =
                ( $need_manual && ! empty( $_FILES['evchk_evid_manual']['name'] ) ) ||
                ( $need_qr && ! empty( $_FILES['evchk_evid_qr']['name'] ) ) ||
                ( $need_multi && ! empty( $_FILES['evchk_evid_multi']['name'] ) );

            if ( $has_new_upload ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                if ( $need_manual && ! empty( $_FILES['evchk_evid_manual']['name'] ) ) {
                    $error      = '';
                    $att_manual = evchk_handle_evidence_upload( 'evchk_evid_manual', $att_manual, $error );
                    if ( $error !== '' ) $upload_errors[] = 'Check-in Manual: ' . $error;
                }

                if ( $need_qr && ! empty( $_FILES['evchk_evid_qr']['name'] ) ) {
                    $error  = '';
                    $att_qr = evchk_handle_evidence_upload( 'evchk_evid_qr', $att_qr, $error );
                    if ( $error !== '' ) $upload_errors[] = 'Check-in por QR: ' . $error;
                }

                if ( $need_multi && ! empty( $_FILES['evchk_evid_multi']['name'] ) ) {
                    $error     = '';
                    $att_multi = evchk_handle_evidence_upload( 'evchk_evid_multi', $att_multi, $error );
                    if ( $error !== '' ) $upload_errors[] = 'Varias sesiones: ' . $error;
                }
            }

            $need_manual_ok = $need_manual ? ( $att_manual > 0 ) : true;
            $need_qr_ok     = $need_qr ? ( $att_qr > 0 ) : true;
            $need_multi_ok  = $need_multi ? ( $att_multi > 0 ) : true;
            $uploads_ok     = empty( $upload_errors );

            $all_ok = $ok_montaje && $ok_log && $ok_tipo && $need_manual_ok && $need_qr_ok && $need_multi_ok && $uploads_ok;
            $finish = ! empty( $_POST['evchk_finish'] );

            $subs[ $current_user->ID ] = [
                'completed'           => $all_ok && $finish,
                'completed_at'        => ( $all_ok && $finish ) ? time() : 0,
                'montaje_choice'      => $sel_montaje,
                'montaje_ok'          => $ok_montaje,
                'logistica_nombres'   => $names,
                'evid_manual_id'      => $att_manual,
                'evid_qr_id'          => $att_qr,
                'evid_multi_id'       => $att_multi,
                'tipo_choice'         => $tipo_choice,
                'tipo_ok'             => $ok_tipo,
                'message_to_approver' => isset( $_POST['evchk_msg'] ) ? wp_kses_post( wp_unslash( $_POST['evchk_msg'] ) ) : '',
            ];

            update_post_meta( $event_id, '_evchk_submissions', $subs );
            $mine = $subs[ $current_user->ID ];

            if ( $all_ok && $finish ) {
                if ( $approver && is_email( $approver ) ) {
                    evchk_send_completion_email( $event_id, $current_user->ID, $approver, $mine );
                    $msg = evchk_notice_html( 'success', '<strong>Checklist completado.</strong> El detalle fue enviado al aprobador.' );
                } else {
                    $msg = evchk_notice_html( 'success', '<strong>Checklist completado.</strong> No hay un correo de aprobador configurado para este evento.' );
                }
            } else {
                $warning = '<strong>Avance guardado.</strong> Aún hay tareas pendientes o respuestas incorrectas.';
                if ( ! empty( $upload_errors ) ) {
                    $warning .= '<br>' . esc_html( implode( ' · ', $upload_errors ) );
                }
                $msg = evchk_notice_html( 'warning', $warning );
            }
        }
    }

    // ------------------------------------------------------------
    // Datos visuales posteriores al guardado
    // ------------------------------------------------------------
    $opts     = evchk_stable_montaje_options( $event_id, $montaje_dt );
    $progress = evchk_submission_progress( $mine, $log_cant, $need_manual, $need_qr, $need_multi );

    $deadline_obj     = evchk_datetime_object( $event_id, $deadline_dt );
    $deadline_iso     = $deadline_obj ? $deadline_obj->format( 'c' ) : '';
    $deadline_is_over = $deadline_obj ? ( $deadline_obj->getTimestamp() <= time() ) : false;

    $formatted_days = [];
    foreach ( $fechas as $day ) {
        $formatted_days[] = evchk_format_event_day( $event_id, (string) $day );
    }

    $change_event_url = add_query_arg( [ 'evapp' => 'change_event' ], $dashboard_url );
    $instance_id      = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'evchk-' ) : uniqid( 'evchk-', false );
    $instance_id      = sanitize_html_class( $instance_id );
    $completed        = ! empty( $mine['completed'] );

    $completed_at_label = '';
    if ( $completed && ! empty( $mine['completed_at'] ) ) {
        $completed_at_label = wp_date( 'd M Y · H:i', (int) $mine['completed_at'], $tz );
    }

    $log_ready = evchk_logistica_is_ready( $mine['logistica_nombres'] ?? [], $log_cant );

    $evidence_tasks = [];
    if ( $need_manual ) {
        $evidence_tasks[] = [
            'key'        => 'manual',
            'input'      => 'evchk_evid_manual',
            'title'      => 'Evidencia: Check-in Manual',
            'desc'       => 'Carga una imagen que confirme que el flujo de check-in manual está listo para operar.',
            'attachment' => absint( $mine['evid_manual_id'] ?? 0 ),
        ];
    }
    if ( $need_qr ) {
        $evidence_tasks[] = [
            'key'        => 'qr',
            'input'      => 'evchk_evid_qr',
            'title'      => 'Evidencia: Check-in por QR',
            'desc'       => 'Carga una imagen que confirme la correcta preparación del flujo de lectura QR.',
            'attachment' => absint( $mine['evid_qr_id'] ?? 0 ),
        ];
    }
    if ( $need_multi ) {
        $evidence_tasks[] = [
            'key'        => 'multi',
            'input'      => 'evchk_evid_multi',
            'title'      => 'Evidencia: Check-in de varias sesiones',
            'desc'       => 'Carga una imagen de la configuración o prueba del control de acceso por sesiones.',
            'attachment' => absint( $mine['evid_multi_id'] ?? 0 ),
        ];
    }

    ob_start();
    ?>
    <div
        id="<?php echo esc_attr( $instance_id ); ?>"
        class="evchk-app"
        data-read-only="<?php echo $read_only ? '1' : '0'; ?>"
        data-correct-montaje="<?php echo esc_attr( $montaje_dt ); ?>"
        data-correct-tipo="<?php echo esc_attr( $tipo_ok ); ?>"
        data-deadline="<?php echo esc_attr( $deadline_iso ); ?>"
    >
        <div class="evchk-shell">
            <header class="evchk-header">
                <div class="evchk-heading">
                    <p class="evchk-eyebrow">EVENTOSAPP</p>
                    <h1 class="evchk-main-title">Checklist de Evento</h1>
                    <p class="evchk-subtitle">Confirma los puntos operativos previos, registra las evidencias requeridas y envía el checklist al aprobador.</p>
                </div>

                <div class="evchk-header-actions">
                    <a href="<?php echo esc_url( $dashboard_url ); ?>" class="evchk-btn evchk-btn-secondary" aria-label="Volver al dashboard">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg>
                        <span>Volver al dashboard</span>
                    </a>
                </div>
            </header>

            <section class="evchk-event-context" aria-label="Evento activo">
                <div class="evchk-event-main">
                    <div class="evchk-event-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M7 3v4M17 3v4M3 9h18"></path></svg>
                    </div>
                    <div class="evchk-event-copy">
                        <span class="evchk-event-kicker">Evento activo</span>
                        <strong class="evchk-event-name"><?php echo esc_html( $name ); ?></strong>
                        <span class="evchk-event-detail"><?php echo esc_html( $lugar ); ?></span>
                    </div>
                </div>

                <div class="evchk-event-meta">
                    <?php if ( $event_modalidad_label ) : ?>
                        <span class="evchk-chip"><?php echo esc_html( $event_modalidad_label ); ?></span>
                    <?php endif; ?>
                    <?php if ( $formatted_days ) : ?>
                        <span class="evchk-chip"><?php echo esc_html( implode( ', ', $formatted_days ) ); ?></span>
                    <?php endif; ?>
                    <a class="evchk-btn evchk-btn-primary" href="<?php echo esc_url( $change_event_url ); ?>">Cambiar evento</a>
                </div>
            </section>

            <?php echo $msg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado por helper seguro. ?>

            <?php if ( $completed ) : ?>
                <?php
                $completed_message = '<strong>Checklist completado correctamente.</strong>';
                if ( $completed_at_label ) {
                    $completed_message .= ' Última finalización registrada: ' . esc_html( $completed_at_label ) . ' (' . esc_html( $tz_label ) . ').';
                }
                echo evchk_notice_html( 'success', $completed_message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            <?php endif; ?>

            <?php if ( $read_only ) : ?>
                <?php echo evchk_notice_html( 'error', '<strong>Modo solo lectura.</strong> Tu usuario puede consultar este módulo, pero no está autorizado como coordinador asignado para diligenciar este checklist.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endif; ?>

            <div class="evchk-overview">
                <section class="evchk-summary-card" aria-labelledby="<?php echo esc_attr( $instance_id ); ?>-progress-title">
                    <div class="evchk-summary-head">
                        <div>
                            <h2 id="<?php echo esc_attr( $instance_id ); ?>-progress-title" class="evchk-summary-title">Progreso del checklist</h2>
                            <p class="evchk-summary-copy">Completa todas las tareas para habilitar el envío final al aprobador.</p>
                        </div>
                        <span class="evchk-progress-number" data-evchk-progress-number><?php echo esc_html( $progress['percent'] . '%' ); ?></span>
                    </div>
                    <div class="evchk-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $progress['percent'] ); ?>" data-evchk-progress-track>
                        <span class="evchk-progress-bar" data-evchk-progress-bar style="width:<?php echo esc_attr( $progress['percent'] ); ?>%;"></span>
                    </div>
                    <div class="evchk-progress-meta">
                        <span data-evchk-progress-copy><?php echo esc_html( $progress['ready'] . ' de ' . $progress['total'] . ' tareas listas' ); ?></span>
                        <span><?php echo $completed ? 'Completado' : 'En progreso'; ?></span>
                    </div>
                </section>

                <section class="evchk-deadline-card <?php echo $deadline_is_over ? 'is-over' : ''; ?>" data-evchk-deadline-card>
                    <div class="evchk-deadline-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>
                    </div>
                    <div class="evchk-deadline-copy">
                        <span class="evchk-deadline-label">Fecha y hora límite</span>
                        <strong class="evchk-deadline-date"><?php echo esc_html( evchk_format_datetime( $event_id, $deadline_dt ) ); ?></strong>
                        <span class="evchk-countdown" data-evchk-countdown aria-live="polite"><?php echo $deadline_is_over ? 'VENCIDO' : 'Calculando…'; ?></span>
                    </div>
                </section>
            </div>

            <form class="evchk-form" method="post" enctype="multipart/form-data" data-evchk-form>
                <?php wp_nonce_field( 'evchk_front_' . $event_id ); ?>
                <input type="hidden" name="evchk_action" value="submit">

                <section class="evchk-task" data-evchk-task="montaje">
                    <div class="evchk-task-head">
                        <div class="evchk-task-heading">
                            <div class="evchk-task-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg></div>
                            <div>
                                <h2 class="evchk-task-title">Fecha y hora de montaje</h2>
                                <p class="evchk-task-desc">Selecciona la opción que corresponde a la hora de montaje configurada para el evento.</p>
                            </div>
                        </div>
                        <span class="evchk-status-pill <?php echo ! empty( $mine['montaje_ok'] ) ? 'is-ready' : ''; ?>" data-evchk-pill="montaje"><?php echo ! empty( $mine['montaje_ok'] ) ? 'Tarea lista' : 'Pendiente'; ?></span>
                    </div>

                    <input class="screen-reader-text" type="checkbox" disabled data-evchk-status="montaje" <?php checked( ! empty( $mine['montaje_ok'] ) ); ?>>

                    <div class="evchk-options">
                        <?php foreach ( $opts as $opt ) : ?>
                            <label class="evchk-option <?php echo $read_only ? 'is-disabled' : ''; ?>">
                                <input type="radio" name="evchk_montaje_option" value="<?php echo esc_attr( $opt ); ?>" <?php checked( ( $mine['montaje_choice'] ?? '' ) === $opt ); ?> <?php disabled( $read_only ); ?>>
                                <span><?php echo esc_html( evchk_format_datetime( $event_id, $opt ) ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="evchk-field-error" data-evchk-error="montaje" <?php echo ( ! empty( $mine['montaje_choice'] ) && empty( $mine['montaje_ok'] ) ) ? '' : 'hidden'; ?>>Respuesta incorrecta. Revisa la información operativa del evento.</div>
                </section>

                <section class="evchk-task" data-evchk-task="logistica">
                    <div class="evchk-task-head">
                        <div class="evchk-task-heading">
                            <div class="evchk-task-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="8" cy="8" r="3"></circle><circle cx="17" cy="8" r="2.5"></circle><path d="M3 20c.8-3.2 2.5-5 5-5s4.2 1.8 5 5M13 19c.6-2.5 1.9-4 4-4s3.4 1.5 4 4"></path></svg></div>
                            <div>
                                <h2 class="evchk-task-title">Personal de logística</h2>
                                <p class="evchk-task-desc"><?php echo $log_cant > 0 ? esc_html( 'Registra los nombres del equipo de logística requerido (' . $log_cant . ').' ) : 'Este evento no requiere nombres de logística en el checklist.'; ?></p>
                            </div>
                        </div>
                        <span class="evchk-status-pill <?php echo $log_ready ? 'is-ready' : ''; ?>" data-evchk-pill="logistica"><?php echo $log_ready ? 'Tarea lista' : 'Pendiente'; ?></span>
                    </div>

                    <input class="screen-reader-text" type="checkbox" disabled data-evchk-status="logistica" <?php checked( $log_ready ); ?>>

                    <?php if ( $log_cant > 0 ) : ?>
                        <div class="evchk-log-grid">
                            <?php for ( $i = 0; $i < $log_cant; $i++ ) : ?>
                                <div class="evchk-field">
                                    <label class="evchk-label" for="<?php echo esc_attr( $instance_id . '-log-' . $i ); ?>">Logístico #<?php echo esc_html( $i + 1 ); ?></label>
                                    <input id="<?php echo esc_attr( $instance_id . '-log-' . $i ); ?>" class="evchk-input" type="text" name="evchk_log_name_<?php echo esc_attr( $i ); ?>" value="<?php echo esc_attr( $mine['logistica_nombres'][ $i ] ?? '' ); ?>" placeholder="Nombre completo" autocomplete="off" data-evchk-log-name <?php disabled( $read_only ); ?>>
                                </div>
                            <?php endfor; ?>
                        </div>
                    <?php else : ?>
                        <p class="evchk-empty-note">No se configuró una cantidad de personal de logística. Esta tarea se considera cumplida automáticamente.</p>
                    <?php endif; ?>
                </section>

                <?php foreach ( $evidence_tasks as $evidence ) : ?>
                    <?php
                    $info      = evchk_get_attachment_info( $evidence['attachment'] );
                    $is_ready  = $evidence['attachment'] > 0;
                    $status_id = 'evid_' . $evidence['key'];
                    ?>
                    <section class="evchk-task" data-evchk-task="<?php echo esc_attr( $status_id ); ?>">
                        <div class="evchk-task-head">
                            <div class="evchk-task-heading">
                                <div class="evchk-task-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"></rect><circle cx="9" cy="10" r="2"></circle><path d="m5 18 5-5 3 3 2-2 4 4"></path></svg></div>
                                <div>
                                    <h2 class="evchk-task-title"><?php echo esc_html( $evidence['title'] ); ?></h2>
                                    <p class="evchk-task-desc"><?php echo esc_html( $evidence['desc'] ); ?></p>
                                </div>
                            </div>
                            <span class="evchk-status-pill <?php echo $is_ready ? 'is-ready' : ''; ?>" data-evchk-pill="<?php echo esc_attr( $status_id ); ?>"><?php echo $is_ready ? 'Tarea lista' : 'Pendiente'; ?></span>
                        </div>

                        <input class="screen-reader-text" type="checkbox" disabled data-evchk-status="<?php echo esc_attr( $status_id ); ?>" <?php checked( $is_ready ); ?>>

                        <div class="evchk-file-wrap">
                            <input class="evchk-file" type="file" name="<?php echo esc_attr( $evidence['input'] ); ?>" accept="image/*" data-evchk-file data-status-key="<?php echo esc_attr( $status_id ); ?>" data-has-existing="<?php echo $is_ready ? '1' : '0'; ?>" <?php disabled( $read_only ); ?>>

                            <?php if ( $is_ready ) : ?>
                                <span class="evchk-evidence-current">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>
                                    <?php if ( $info['url'] ) : ?>
                                        <a href="<?php echo esc_url( $info['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $info['name'] ); ?></a>
                                    <?php else : ?>
                                        <span><?php echo esc_html( $info['name'] ); ?></span>
                                    <?php endif; ?>
                                </span>
                            <?php else : ?>
                                <span class="evchk-evidence-current" data-evchk-empty-evidence>Sin evidencia cargada</span>
                            <?php endif; ?>
                        </div>
                        <div class="evchk-file-selected" data-evchk-file-selected></div>
                    </section>
                <?php endforeach; ?>

                <section class="evchk-task" data-evchk-task="tipo">
                    <div class="evchk-task-head">
                        <div class="evchk-task-heading">
                            <div class="evchk-task-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 9V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4Z"></path><rect x="9" y="9" width="6" height="6" rx="1"></rect></svg></div>
                            <div>
                                <h2 class="evchk-task-title">Tipo de identificación del evento</h2>
                                <p class="evchk-task-desc">Selecciona la opción operativa configurada para identificar a los asistentes.</p>
                            </div>
                        </div>
                        <span class="evchk-status-pill <?php echo ! empty( $mine['tipo_ok'] ) ? 'is-ready' : ''; ?>" data-evchk-pill="tipo"><?php echo ! empty( $mine['tipo_ok'] ) ? 'Tarea lista' : 'Pendiente'; ?></span>
                    </div>

                    <input class="screen-reader-text" type="checkbox" disabled data-evchk-status="tipo" <?php checked( ! empty( $mine['tipo_ok'] ) ); ?>>

                    <div class="evchk-type-options">
                        <?php foreach ( $tipo_opts as $value => $label ) : ?>
                            <label class="evchk-option <?php echo $read_only ? 'is-disabled' : ''; ?>">
                                <input type="radio" name="evchk_tipo" value="<?php echo esc_attr( $value ); ?>" <?php checked( ( $mine['tipo_choice'] ?? '' ) === $value ); ?> <?php disabled( $read_only ); ?>>
                                <span><?php echo esc_html( $label ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="evchk-field-error" data-evchk-error="tipo" <?php echo ( ! empty( $mine['tipo_choice'] ) && empty( $mine['tipo_ok'] ) ) ? '' : 'hidden'; ?>>Respuesta incorrecta. Verifica el tipo configurado para este evento.</div>
                </section>

                <section class="evchk-submit-card">
                    <div class="evchk-submit-head">
                        <div class="evchk-submit-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"></path></svg></div>
                        <div>
                            <h2 class="evchk-submit-title">Guardar y enviar al aprobador</h2>
                            <p class="evchk-submit-desc">Puedes guardar el avance en cualquier momento. El envío final se habilita cuando todas las tareas estén completas.</p>
                        </div>
                    </div>

                    <div class="evchk-field">
                        <label class="evchk-label" for="<?php echo esc_attr( $instance_id . '-msg' ); ?>">Mensaje para el aprobador <span style="font-weight:500;color:var(--evapp-muted);">(opcional)</span></label>
                        <textarea id="<?php echo esc_attr( $instance_id . '-msg' ); ?>" class="evchk-textarea" name="evchk_msg" rows="4" placeholder="Agrega una observación relevante para el aprobador…" <?php disabled( $read_only ); ?>><?php echo isset( $mine['message_to_approver'] ) ? esc_textarea( $mine['message_to_approver'] ) : ''; ?></textarea>
                    </div>

                    <div class="evchk-submit-actions">
                        <div class="evchk-submit-note">
                            <?php if ( $approver && is_email( $approver ) ) : ?>
                                El correo de aprobación se enviará a <strong><?php echo esc_html( $approver ); ?></strong>.
                            <?php else : ?>
                                No hay un correo de aprobador configurado; el checklist podrá finalizarse, pero no se enviará correo de finalización.
                            <?php endif; ?>
                        </div>

                        <div class="evchk-submit-buttons">
                            <button type="submit" class="evchk-btn evchk-btn-secondary" name="evchk_save_progress" value="1" <?php disabled( $read_only ); ?>>Guardar avance</button>
                            <button type="submit" class="evchk-btn evchk-btn-primary" name="evchk_finish" value="1" data-evchk-finish <?php disabled( $read_only || $progress['ready'] < $progress['total'] ); ?>>Terminado y enviar</button>
                        </div>
                    </div>
                </section>
            </form>
        </div>
    </div>

    <script>
    (function(){
      'use strict';

      var root = document.getElementById(<?php echo wp_json_encode( $instance_id ); ?>);
      if (!root) return;

      var readOnly = root.getAttribute('data-read-only') === '1';
      var correctMontaje = root.getAttribute('data-correct-montaje') || '';
      var correctTipo = root.getAttribute('data-correct-tipo') || '';
      var finishButton = root.querySelector('[data-evchk-finish]');
      var progressBar = root.querySelector('[data-evchk-progress-bar]');
      var progressTrack = root.querySelector('[data-evchk-progress-track]');
      var progressNumber = root.querySelector('[data-evchk-progress-number]');
      var progressCopy = root.querySelector('[data-evchk-progress-copy]');

      function statusInput(key){
        return root.querySelector('[data-evchk-status="' + key + '"]');
      }

      function statusPill(key){
        return root.querySelector('[data-evchk-pill="' + key + '"]');
      }

      function setStatus(key, ready){
        var input = statusInput(key);
        var pill = statusPill(key);
        ready = !!ready;

        if (input) input.checked = ready;
        if (pill) {
          pill.classList.toggle('is-ready', ready);
          pill.textContent = ready ? 'Tarea lista' : 'Pendiente';
        }
        refreshProgress();
      }

      function getStatuses(){
        return Array.prototype.slice.call(root.querySelectorAll('[data-evchk-status]'));
      }

      function refreshProgress(){
        var statuses = getStatuses();
        var total = statuses.length;
        var ready = statuses.filter(function(el){ return el.checked; }).length;
        var percent = total ? Math.round((ready / total) * 100) : 0;

        if (progressBar) progressBar.style.width = percent + '%';
        if (progressTrack) progressTrack.setAttribute('aria-valuenow', String(percent));
        if (progressNumber) progressNumber.textContent = percent + '%';
        if (progressCopy) progressCopy.textContent = ready + ' de ' + total + ' tareas listas';
        if (finishButton) finishButton.disabled = readOnly || ready !== total;
      }

      function setError(key, show){
        var error = root.querySelector('[data-evchk-error="' + key + '"]');
        if (!error) return;
        error.hidden = !show;
      }

      Array.prototype.forEach.call(root.querySelectorAll('input[name="evchk_montaje_option"]'), function(radio){
        radio.addEventListener('change', function(){
          var ok = this.value === correctMontaje;
          setStatus('montaje', ok);
          setError('montaje', !ok);
        });
      });

      var logInputs = Array.prototype.slice.call(root.querySelectorAll('[data-evchk-log-name]'));
      function checkLogistica(){
        if (!logInputs.length) {
          setStatus('logistica', true);
          return;
        }
        var ok = logInputs.every(function(input){ return input.value.trim() !== ''; });
        setStatus('logistica', ok);
      }
      logInputs.forEach(function(input){ input.addEventListener('input', checkLogistica); });

      Array.prototype.forEach.call(root.querySelectorAll('input[name="evchk_tipo"]'), function(radio){
        radio.addEventListener('change', function(){
          var ok = this.value === correctTipo;
          setStatus('tipo', ok);
          setError('tipo', !ok);
        });
      });

      Array.prototype.forEach.call(root.querySelectorAll('[data-evchk-file]'), function(input){
        input.addEventListener('change', function(){
          var key = this.getAttribute('data-status-key');
          var hasExisting = this.getAttribute('data-has-existing') === '1';
          var hasNewFile = !!(this.files && this.files.length);
          var task = this.closest('[data-evchk-task]');
          var label = task ? task.querySelector('[data-evchk-file-selected]') : null;

          setStatus(key, hasExisting || hasNewFile);

          if (label) {
            label.textContent = hasNewFile ? ('Archivo seleccionado: ' + this.files[0].name) : '';
          }
        });
      });

      // Countdown: una actualización por segundo es suficiente y evita trabajo continuo del navegador.
      var deadlineCard = root.querySelector('[data-evchk-deadline-card]');
      var countdown = root.querySelector('[data-evchk-countdown]');
      var deadlineISO = root.getAttribute('data-deadline') || '';
      var countdownTimer = null;

      function pad(n){ return n < 10 ? '0' + n : String(n); }

      function updateCountdown(){
        if (!countdown || !deadlineISO) {
          if (countdown) countdown.textContent = '—';
          return false;
        }

        var deadline = new Date(deadlineISO).getTime();
        if (!isFinite(deadline)) {
          countdown.textContent = '—';
          return false;
        }

        var diff = deadline - Date.now();
        if (diff <= 0) {
          countdown.textContent = 'VENCIDO';
          if (deadlineCard) deadlineCard.classList.add('is-over');
          return false;
        }

        if (deadlineCard) deadlineCard.classList.remove('is-over');

        var seconds = Math.floor(diff / 1000);
        var days = Math.floor(seconds / 86400);
        seconds -= days * 86400;
        var hours = Math.floor(seconds / 3600);
        seconds -= hours * 3600;
        var minutes = Math.floor(seconds / 60);
        seconds -= minutes * 60;

        countdown.textContent = (days > 0 ? days + 'd ' : '') + pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
        return true;
      }

      function startCountdown(){
        if (countdownTimer) window.clearInterval(countdownTimer);
        var active = updateCountdown();
        if (active) {
          countdownTimer = window.setInterval(function(){
            if (!updateCountdown()) {
              window.clearInterval(countdownTimer);
              countdownTimer = null;
            }
          }, 1000);
        }
      }

      document.addEventListener('visibilitychange', function(){
        if (!document.hidden) startCountdown();
      });

      if (!readOnly) checkLogistica();
      refreshProgress();
      startCountdown();
    })();
    </script>
    <?php

    return ob_get_clean();
} );

// ============================================================
// EMAIL DE FINALIZACIÓN
// ============================================================

if ( ! function_exists( 'evchk_send_completion_email' ) ) {
    function evchk_send_completion_email( $event_id, $user_id, $to, $data ) {
        $event_id = absint( $event_id );
        $user_id  = absint( $user_id );
        $to       = sanitize_email( $to );
        $data     = is_array( $data ) ? $data : [];

        if ( ! $event_id || ! $to || ! is_email( $to ) ) return false;

        $u          = get_userdata( $user_id );
        $event_name = get_the_title( $event_id );
        $subject    = 'Checklist completado — ' . $event_name;
        $tipo_opts  = evchk_tipo_options();

        $rows = [];
        $rows[] = [
            'Coordinador',
            $u ? esc_html( $u->display_name . ' (' . $u->user_email . ')' ) : esc_html( '#' . $user_id ),
        ];

        $montaje_value = ! empty( $data['montaje_choice'] )
            ? esc_html( evchk_format_datetime( $event_id, $data['montaje_choice'] ) ) . ( ! empty( $data['montaje_ok'] ) ? ' <strong style="color:#15803d;">(OK)</strong>' : ' <strong style="color:#b42318;">(INCORRECTA)</strong>' )
            : '—';
        $rows[] = [ 'Fecha/Hora de montaje', $montaje_value ];

        $names = isset( $data['logistica_nombres'] ) && is_array( $data['logistica_nombres'] )
            ? array_values( array_filter( array_map( 'trim', $data['logistica_nombres'] ) ) )
            : [];
        $rows[] = [ 'Logística (nombres)', $names ? esc_html( implode( ', ', $names ) ) : '—' ];

        $evidences = [
            'evid_manual_id' => 'Evidencia Check-in Manual',
            'evid_qr_id'     => 'Evidencia Check-in QR',
            'evid_multi_id'  => 'Evidencia Multi-sesión',
        ];

        foreach ( $evidences as $key => $label ) {
            if ( empty( $data[ $key ] ) ) continue;
            $url = wp_get_attachment_url( absint( $data[ $key ] ) );
            if ( $url ) {
                $rows[] = [ $label, '<a href="' . esc_url( $url ) . '">Ver evidencia</a>' ];
            }
        }

        if ( ! empty( $data['tipo_choice'] ) ) {
            $choice      = sanitize_key( $data['tipo_choice'] );
            $choice_name = $tipo_opts[ $choice ] ?? $choice;
            $rows[]      = [
                'Tipo de evento elegido',
                esc_html( $choice_name ) . ( ! empty( $data['tipo_ok'] ) ? ' <strong style="color:#15803d;">(OK)</strong>' : ' <strong style="color:#b42318;">(INCORRECTO)</strong>' ),
            ];
        }

        if ( ! empty( $data['message_to_approver'] ) ) {
            $rows[] = [ 'Mensaje del coordinador', wp_kses_post( $data['message_to_approver'] ) ];
        }

        $table = '<table cellpadding="0" cellspacing="0" role="presentation" style="width:100%;max-width:720px;border-collapse:collapse;border:1px solid #dfe7f1;border-radius:12px;overflow:hidden;">';
        foreach ( $rows as $row ) {
            $table .= '<tr>'
                . '<td style="width:34%;padding:10px 12px;background:#f5f8fc;border-bottom:1px solid #dfe7f1;color:#182230;font-weight:700;vertical-align:top;">' . esc_html( $row[0] ) . '</td>'
                . '<td style="padding:10px 12px;border-bottom:1px solid #dfe7f1;color:#334155;vertical-align:top;">' . $row[1] . '</td>'
                . '</tr>';
        }
        $table .= '</table>';

        $body  = '<div style="font-family:Arial,sans-serif;color:#182230;line-height:1.55;">';
        $body .= '<h2 style="margin:0 0 10px;color:#182230;">Checklist completado</h2>';
        $body .= '<p>El coordinador ha completado el checklist del evento <strong>' . esc_html( $event_name ) . '</strong>.</p>';
        $body .= $table;
        $body .= '<p style="margin-top:18px;"><a href="' . esc_url( evchk_get_checklist_url() ) . '" style="display:inline-block;padding:10px 14px;background:#3279bd;color:#ffffff;text-decoration:none;border-radius:9px;font-weight:700;">Ver checklist</a></p>';
        $body .= '</div>';

        return wp_mail( $to, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
    }
}
