<?php
/**
 * EventosApp – Autogestión del Asistente
 *
 * Shortcode: [eventosapp_self_checkin]
 *
 * Módulo táctil para que el asistente se identifique por cédula, confirme sus datos
 * y dispare la impresión de la escarapela. Al imprimir, el ticket queda marcado como
 * check-in presencial del día del evento sin revertir estados existentes.
 */

if ( ! defined('ABSPATH') ) exit;


if ( ! function_exists('eventosapp_self_checkin_prepare_badge_print_html') ) {
    /**
     * Ajusta el HTML de la escarapela para el módulo de autogestión.
     *
     * La función original eventosapp_get_badge_html_from_event() ya trae window.print().
     * Aquí no cambiamos el diseño ni la configuración de escarapela: solo reemplazamos
     * el disparador de impresión por uno más estable para kioskos, esperando DOM,
     * fuentes e imágenes/QR antes de enviar la orden al navegador.
     *
     * Importante: la impresión silenciosa real depende del navegador/dispositivo
     * configurado en modo kiosko/silent printing. JavaScript no puede aprobar el
     * diálogo nativo de impresión por seguridad del navegador.
     */
    function eventosapp_self_checkin_prepare_badge_print_html( $html ) {
        if ( ! is_string( $html ) || $html === '' ) {
            return $html;
        }

        $script = <<<'HTML'
<script>
(function(){
  var evscPrinted = false;

  function evscSendPrint(){
    if(evscPrinted) return;
    evscPrinted = true;
    setTimeout(function(){
      try { window.focus(); } catch(e) {}
      try { window.print(); } catch(e) {}
    }, 250);
  }

  function evscWaitImages(){
    var imgs = [];
    try { imgs = Array.prototype.slice.call(document.images || []); } catch(e) { imgs = []; }

    if(!imgs.length){
      evscSendPrint();
      return;
    }

    var pending = imgs.length;
    var done = function(){
      pending--;
      if(pending <= 0){
        evscSendPrint();
      }
    };

    imgs.forEach(function(img){
      if(img.complete){
        done();
      } else {
        img.addEventListener('load', done, {once:true});
        img.addEventListener('error', done, {once:true});
      }
    });

    setTimeout(evscSendPrint, 2500);
  }

  function evscReady(){
    if(document.fonts && document.fonts.ready){
      document.fonts.ready.then(evscWaitImages).catch(evscWaitImages);
    } else {
      evscWaitImages();
    }
  }

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', evscReady, {once:true});
  } else {
    evscReady();
  }

  window.addEventListener('afterprint', function(){
    setTimeout(function(){
      try {
        if(window.parent && window.parent !== window){
          window.parent.postMessage({type:'eventosapp_self_checkin_afterprint'}, '*');
        }
      } catch(e) {}
    }, 100);
  });
})();
</script>
HTML;

        $replaced = preg_replace('/<script>\s*window\.print\(\);\s*<\/script>/i', $script, $html, 1, $count);
        if ( $count > 0 && is_string( $replaced ) ) {
            return $replaced;
        }

        if ( stripos( $html, '</body>' ) !== false ) {
            return preg_replace('/<\/body>/i', $script . "\n</body>", $html, 1);
        }

        return $html . $script;
    }
}

if ( ! function_exists('eventosapp_self_checkin_digits_only') ) {
    function eventosapp_self_checkin_digits_only( $value ) {
        return preg_replace('/\D+/', '', (string) $value);
    }
}

if ( ! function_exists('eventosapp_self_checkin_current_user_label') ) {
    function eventosapp_self_checkin_current_user_label() {
        $user = wp_get_current_user();
        if ( $user && $user->exists() ) {
            return $user->display_name . ' (' . $user->user_email . ')';
        }
        return 'Autogestión';
    }
}

if ( ! function_exists('eventosapp_self_checkin_get_active_event_id') ) {
    function eventosapp_self_checkin_get_active_event_id( $requested_event_id = 0 ) {
        $candidates = [];

        $requested_event_id = absint( $requested_event_id );
        if ( $requested_event_id ) {
            $candidates[] = $requested_event_id;
        }

        /*
         * Permite que el widget de Elementor sea realmente dinámico.
         * Si no se define un evento fijo, toma el evento activo que el usuario
         * seleccionó en el dashboard de gestión. En algunos contextos de vista
         * previa de Elementor el valor puede llegar por request, por eso se
         * revisan estos nombres sin reemplazar la función central existente.
         */
        $request_keys = [
            'event_id',
            'evapp_event_id',
            'eventosapp_event_id',
            'eventosapp_active_event',
            'active_event_id',
        ];

        foreach ( $request_keys as $key ) {
            if ( isset( $_REQUEST[ $key ] ) ) {
                $maybe = absint( wp_unslash( $_REQUEST[ $key ] ) );
                if ( $maybe ) {
                    $candidates[] = $maybe;
                }
            }
        }

        if ( function_exists('eventosapp_get_active_event') ) {
            $active = absint( eventosapp_get_active_event() );
            if ( $active ) {
                $candidates[] = $active;
            }
        }

        $candidates = array_values( array_unique( array_filter( array_map( 'absint', $candidates ) ) ) );

        foreach ( $candidates as $event_id ) {
            if ( $event_id && get_post_type( $event_id ) === 'eventosapp_event' ) {
                return $event_id;
            }
        }

        return 0;
    }
}

if ( ! function_exists('eventosapp_self_checkin_user_can_event') ) {
    function eventosapp_self_checkin_user_can_event( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return false;
        }

        if ( ! is_user_logged_in() ) {
            return false;
        }

        if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('self_checkin') ) {
            return false;
        }

        if ( current_user_can('manage_options') ) {
            return true;
        }

        if ( function_exists('eventosapp_get_active_event') ) {
            $active_event = absint( eventosapp_get_active_event() );
            if ( ! $active_event || $active_event !== $event_id ) {
                return false;
            }
        }

        if ( function_exists('eventosapp_user_can_manage_event') && eventosapp_user_can_manage_event( $event_id ) ) {
            return true;
        }

        if ( function_exists('eventosapp_staff_access_user_can_select_event_in_dashboard') && eventosapp_staff_access_user_can_select_event_in_dashboard( $event_id, get_current_user_id() ) ) {
            return true;
        }

        if ( function_exists('eventosapp_support_user_can_select_event_in_dashboard') && eventosapp_support_user_can_select_event_in_dashboard( $event_id, get_current_user_id() ) ) {
            return true;
        }

        if ( function_exists('eventosapp_support_user_has_assignment_in_event') && eventosapp_support_user_has_assignment_in_event( $event_id, get_current_user_id() ) ) {
            return true;
        }

        return false;
    }
}

if ( ! function_exists('eventosapp_self_checkin_validate_cc') ) {
    function eventosapp_self_checkin_validate_cc( $cedula ) {
        $digits = eventosapp_self_checkin_digits_only( $cedula );

        if ( $digits === '' ) {
            return new WP_Error('empty_cc', 'Ingresa una cédula para buscar.');
        }

        $len = strlen( $digits );

        /*
         * Se amplía el rango máximo para no bloquear cédulas/documentos reales
         * guardados en tickets antiguos o importados con longitudes superiores a 12.
         * El campo sigue aceptando solo números.
         */
        if ( $len < 5 || $len > 30 ) {
            return new WP_Error('invalid_cc', 'La cédula debe contener entre 5 y 30 números.');
        }

        return $digits;
    }
}


if ( ! function_exists('eventosapp_self_checkin_sanitize_search_value') ) {
    function eventosapp_self_checkin_sanitize_search_value( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            $value = '';
        }

        $value = sanitize_text_field( wp_unslash( (string) $value ) );
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $value);
        if ( ! is_string( $value ) ) {
            $value = '';
        }
        $value = preg_replace('/\s+/u', ' ', trim( $value ));
        if ( ! is_string( $value ) ) {
            $value = '';
        }

        if ( function_exists('mb_substr') ) {
            return mb_substr( $value, 0, 80, 'UTF-8' );
        }

        return substr( $value, 0, 80 );
    }
}

if ( ! function_exists('eventosapp_self_checkin_validate_identifier') ) {
    function eventosapp_self_checkin_validate_identifier( $value ) {
        $identifier = eventosapp_self_checkin_sanitize_search_value( $value );

        if ( $identifier === '' ) {
            return new WP_Error('empty_identifier', 'Ingresa un dato para buscar.');
        }

        $compact = preg_replace('/\s+/u', '', $identifier);
        if ( ! is_string( $compact ) ) {
            $compact = '';
        }

        if ( preg_match('/^\d+$/', $compact) ) {
            return eventosapp_self_checkin_validate_cc( $compact );
        }

        $len = function_exists('mb_strlen') ? mb_strlen( $compact, 'UTF-8' ) : strlen( $compact );
        if ( $len < 2 || $len > 80 ) {
            return new WP_Error('invalid_identifier', 'Ingresa mínimo 2 letras o una cédula de 5 a 30 números.');
        }

        return $identifier;
    }
}

if ( ! function_exists('eventosapp_self_checkin_find_tickets_by_text') ) {
    function eventosapp_self_checkin_find_tickets_by_text( $search_value, $event_id, $limit = 10 ) {
        global $wpdb;

        $search_value = eventosapp_self_checkin_sanitize_search_value( $search_value );
        $event_id     = absint( $event_id );
        $limit        = max(1, min(20, absint( $limit )));

        if ( $search_value === '' || ! $event_id ) {
            return [];
        }

        $compact = preg_replace('/\s+/u', '', $search_value);
        if ( is_string( $compact ) && preg_match('/^\d+$/', $compact) ) {
            return eventosapp_self_checkin_find_tickets_by_cc( $compact, $event_id, $limit );
        }

        $cache_key = 'evapp_self_text_v1_' . md5( $event_id . '|' . $search_value . '|' . $limit );
        $cached = wp_cache_get( $cache_key, 'eventosapp_self_checkin' );
        if ( is_array( $cached ) ) {
            return array_map('intval', $cached);
        }

        $like = '%' . $wpdb->esc_like( $search_value ) . '%';
        $meta_keys = [
            '_eventosapp_asistente_nombre',
            '_eventosapp_asistente_apellido',
            '_eventosapp_asistente_email',
            '_eventosapp_asistente_telefono',
            '_eventosapp_asistente_empresa',
            '_eventosapp_asistente_cargo',
            '_eventosapp_asistente_cc',
            '_evapp_search_cc',
        ];
        $meta_keys = array_values( array_unique( array_filter( $meta_keys ) ) );
        $placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

        $sql = "
            SELECT DISTINCT p.ID
              FROM {$wpdb->posts} p
              INNER JOIN {$wpdb->postmeta} event_pm
                      ON event_pm.post_id = p.ID
                     AND event_pm.meta_key = %s
                     AND event_pm.meta_value = %s
              LEFT JOIN {$wpdb->postmeta} search_pm
                     ON search_pm.post_id = p.ID
                    AND search_pm.meta_key IN ({$placeholders})
             WHERE p.post_type = %s
               AND p.post_status NOT IN ('trash','auto-draft','inherit')
               AND (
                    p.post_title LIKE %s
                    OR search_pm.meta_value LIKE %s
               )
             ORDER BY p.ID DESC
             LIMIT %d
        ";

        $params = array_merge(
            [ '_eventosapp_ticket_evento_id', (string) $event_id ],
            $meta_keys,
            [ 'eventosapp_ticket', $like, $like, $limit ]
        );

        $ids = $wpdb->get_col( eventosapp_self_checkin_prepare( $sql, $params ) );
        $ids = array_slice( array_values( array_unique( array_map('intval', (array) $ids ) ) ), 0, $limit );

        wp_cache_set( $cache_key, $ids, 'eventosapp_self_checkin', 30 );

        return $ids;
    }
}

if ( ! function_exists('eventosapp_self_checkin_find_tickets_by_identifier') ) {
    function eventosapp_self_checkin_find_tickets_by_identifier( $identifier, $event_id, $limit = 10 ) {
        $identifier = eventosapp_self_checkin_sanitize_search_value( $identifier );
        $compact = preg_replace('/\s+/u', '', $identifier);

        if ( is_string( $compact ) && preg_match('/^\d+$/', $compact) ) {
            return eventosapp_self_checkin_find_tickets_by_cc( $compact, $event_id, $limit );
        }

        return eventosapp_self_checkin_find_tickets_by_text( $identifier, $event_id, $limit );
    }
}

if ( ! function_exists('eventosapp_self_checkin_prepare') ) {
    function eventosapp_self_checkin_prepare( $sql, $params = [] ) {
        global $wpdb;

        $params = (array) $params;
        array_unshift( $params, $sql );

        return call_user_func_array( [ $wpdb, 'prepare' ], $params );
    }
}

if ( ! function_exists('eventosapp_self_checkin_normalized_sql_expression') ) {
    function eventosapp_self_checkin_normalized_sql_expression( $column ) {
        $column = (string) $column;

        /*
         * Normalización compatible con MySQL sin depender de REGEXP_REPLACE:
         * elimina separadores frecuentes en documentos importados o digitados.
         */
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column}, CHAR(160), ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), ''), ' ', ''), '.', ''), '-', ''), ',', ''), '_', ''), '/', ''), '\\\\', ''), '+', ''), '#', '')";
    }
}

if ( ! function_exists('eventosapp_self_checkin_find_tickets_by_cc') ) {
    function eventosapp_self_checkin_find_tickets_by_cc( $cedula_digits, $event_id, $limit = 10 ) {
        global $wpdb;

        $cedula_digits = eventosapp_self_checkin_digits_only( $cedula_digits );
        $event_id      = absint( $event_id );
        $limit         = max(1, min(20, absint( $limit )));

        if ( ! $cedula_digits || ! $event_id ) {
            return [];
        }

        $cache_key = 'evapp_self_cc_v2_' . md5( $event_id . '|' . $cedula_digits . '|' . $limit );
        $cached = wp_cache_get( $cache_key, 'eventosapp_self_checkin' );
        if ( is_array( $cached ) ) {
            return array_map('intval', $cached);
        }

        $ids = [];

        $push_ids = function( $new_ids ) use ( &$ids, $limit ) {
            foreach ( (array) $new_ids as $new_id ) {
                $new_id = absint( $new_id );
                if ( $new_id && ! in_array( $new_id, $ids, true ) ) {
                    $ids[] = $new_id;
                }

                if ( count( $ids ) >= $limit ) {
                    break;
                }
            }
        };

        $run_simple_meta_query = function( $meta_key, $operator, $value, $query_limit = null ) use ( $wpdb, $event_id, $limit ) {
            $operator = strtoupper( trim( (string) $operator ) );
            if ( ! in_array( $operator, [ '=', 'LIKE' ], true ) ) {
                $operator = '=';
            }

            $query_limit = $query_limit ? absint( $query_limit ) : $limit;
            $query_limit = max(1, min(80, $query_limit));

            $sql = "
                SELECT DISTINCT p.ID
                  FROM {$wpdb->posts} p
                  INNER JOIN {$wpdb->postmeta} event_pm
                          ON event_pm.post_id = p.ID
                         AND event_pm.meta_key = %s
                         AND event_pm.meta_value = %s
                  INNER JOIN {$wpdb->postmeta} cc_pm
                          ON cc_pm.post_id = p.ID
                         AND cc_pm.meta_key = %s
                 WHERE p.post_type = %s
                   AND p.post_status NOT IN ('trash','auto-draft','inherit')
                   AND cc_pm.meta_value {$operator} %s
                 ORDER BY p.ID DESC
                 LIMIT %d
            ";

            return $wpdb->get_col( eventosapp_self_checkin_prepare( $sql, [
                '_eventosapp_ticket_evento_id',
                (string) $event_id,
                $meta_key,
                'eventosapp_ticket',
                $value,
                $query_limit,
            ] ) );
        };

        $run_normalized_raw_query = function( $operator, $value, $query_limit = null ) use ( $wpdb, $event_id, $limit ) {
            $operator = strtoupper( trim( (string) $operator ) );
            if ( ! in_array( $operator, [ '=', 'LIKE' ], true ) ) {
                $operator = '=';
            }

            $query_limit = $query_limit ? absint( $query_limit ) : $limit;
            $query_limit = max(1, min(80, $query_limit));
            $normalized_cc_sql = eventosapp_self_checkin_normalized_sql_expression( 'cc_raw.meta_value' );

            $sql = "
                SELECT DISTINCT p.ID
                  FROM {$wpdb->posts} p
                  INNER JOIN {$wpdb->postmeta} event_pm
                          ON event_pm.post_id = p.ID
                         AND event_pm.meta_key = %s
                         AND event_pm.meta_value = %s
                  INNER JOIN {$wpdb->postmeta} cc_raw
                          ON cc_raw.post_id = p.ID
                         AND cc_raw.meta_key = %s
                 WHERE p.post_type = %s
                   AND p.post_status NOT IN ('trash','auto-draft','inherit')
                   AND {$normalized_cc_sql} {$operator} %s
                 ORDER BY p.ID DESC
                 LIMIT %d
            ";

            return $wpdb->get_col( eventosapp_self_checkin_prepare( $sql, [
                '_eventosapp_ticket_evento_id',
                (string) $event_id,
                '_eventosapp_asistente_cc',
                'eventosapp_ticket',
                $value,
                $query_limit,
            ] ) );
        };

        /*
         * Orden de búsqueda:
         * 1) índice segmentado exacto;
         * 2) cédula original exacta;
         * 3) cédula normalizada exacta;
         * 4) índice/cédula normalizada por coincidencia parcial como respaldo.
         *
         * Esto corrige tickets creados antes del índice _evapp_search_cc y casos
         * donde el documento quedó guardado con separadores o con más de 12 dígitos.
         */
        $push_ids( $run_simple_meta_query( '_evapp_search_cc', '=', $cedula_digits, $limit ) );

        if ( count( $ids ) < $limit ) {
            $push_ids( $run_simple_meta_query( '_eventosapp_asistente_cc', '=', $cedula_digits, $limit ) );
        }

        if ( count( $ids ) < $limit ) {
            $push_ids( $run_normalized_raw_query( '=', $cedula_digits, $limit ) );
        }

        if ( count( $ids ) < $limit && strlen( $cedula_digits ) >= 6 ) {
            $like = '%' . $wpdb->esc_like( $cedula_digits ) . '%';
            $push_ids( $run_simple_meta_query( '_evapp_search_cc', 'LIKE', $like, 30 ) );
        }

        if ( count( $ids ) < $limit && strlen( $cedula_digits ) >= 6 ) {
            $like = '%' . $wpdb->esc_like( $cedula_digits ) . '%';
            $push_ids( $run_normalized_raw_query( 'LIKE', $like, 30 ) );
        }

        /*
         * Último respaldo en PHP: útil para documentos importados con caracteres
         * invisibles o separadores no contemplados por SQL. Solo recorre tickets
         * del evento activo y se detiene al completar el límite.
         */
        if ( count( $ids ) < $limit ) {
            $candidate_ids = get_posts([
                'post_type'              => 'eventosapp_ticket',
                'post_status'            => [ 'publish', 'private', 'draft', 'pending', 'future' ],
                'fields'                 => 'ids',
                'posts_per_page'         => 6000,
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'             => [
                    [
                        'key'     => '_eventosapp_ticket_evento_id',
                        'value'   => (string) $event_id,
                        'compare' => '=',
                    ],
                ],
            ]);

            $candidate_ids = array_map('intval', (array) $candidate_ids);
            if ( ! empty( $candidate_ids ) ) {
                update_meta_cache( 'post', $candidate_ids );
            }

            foreach ( $candidate_ids as $candidate_id ) {
                if ( in_array( $candidate_id, $ids, true ) ) {
                    continue;
                }

                $stored_cc_digits = eventosapp_self_checkin_digits_only( get_post_meta( $candidate_id, '_eventosapp_asistente_cc', true ) );

                if ( $stored_cc_digits === '' ) {
                    continue;
                }

                $is_exact = ( $stored_cc_digits === $cedula_digits );
                $is_safe_partial = strlen( $cedula_digits ) >= 8 && (
                    strpos( $stored_cc_digits, $cedula_digits ) !== false ||
                    strpos( $cedula_digits, $stored_cc_digits ) !== false
                );

                if ( $is_exact || $is_safe_partial ) {
                    $ids[] = $candidate_id;
                }

                if ( count( $ids ) >= $limit ) {
                    break;
                }
            }
        }

        $ids = array_slice( array_values( array_unique( array_map('intval', (array) $ids ) ) ), 0, $limit );
        wp_cache_set( $cache_key, $ids, 'eventosapp_self_checkin', 30 );

        return $ids;
    }
}

if ( ! function_exists('eventosapp_self_checkin_get_today_status') ) {
    function eventosapp_self_checkin_get_today_status( $ticket_id, $event_id ) {
        $ticket_id = absint( $ticket_id );
        $event_id  = absint( $event_id );

        $today = function_exists('eventosapp_get_today_in_event_tz')
            ? eventosapp_get_today_in_event_tz( $event_id )
            : current_time('Y-m-d');

        $status_arr = get_post_meta( $ticket_id, '_eventosapp_checkin_status', true );
        if ( is_string( $status_arr ) ) $status_arr = @unserialize( $status_arr );
        if ( ! is_array( $status_arr ) ) $status_arr = [];

        $today_status = isset( $status_arr[$today] ) && $status_arr[$today] === 'checked_in'
            ? 'checked_in'
            : 'not_checked_in';

        $today_allowed = function_exists('eventosapp_is_today_valid_for_event')
            ? eventosapp_is_today_valid_for_event( $event_id )
            : true;

        if ( ! $today_allowed ) {
            $today_status = 'not_checked_in';
        }

        return [
            'today'         => $today,
            'today_status'  => $today_status,
            'today_allowed' => (bool) $today_allowed,
        ];
    }
}

if ( ! function_exists('eventosapp_self_checkin_ticket_payload') ) {
    function eventosapp_self_checkin_ticket_payload( $ticket_id ) {
        $ticket_id = absint( $ticket_id );
        if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
            return null;
        }

        $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        if ( ! $event_id ) {
            return null;
        }

        $first_name = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true );
        $last_name  = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );
        $company    = get_post_meta( $ticket_id, '_eventosapp_asistente_empresa', true );
        $role       = get_post_meta( $ticket_id, '_eventosapp_asistente_cargo', true );
        $cc         = get_post_meta( $ticket_id, '_eventosapp_asistente_cc', true );
        $email      = get_post_meta( $ticket_id, '_eventosapp_asistente_email', true );
        $phone      = get_post_meta( $ticket_id, '_eventosapp_asistente_tel', true );
        $localidad  = get_post_meta( $ticket_id, '_eventosapp_asistente_localidad', true );
        $ticket_pub = get_post_meta( $ticket_id, 'eventosapp_ticketID', true );
        $modalidad  = function_exists('eventosapp_get_ticket_modalidad') ? eventosapp_get_ticket_modalidad( $ticket_id ) : ( get_post_meta( $ticket_id, '_eventosapp_ticket_modalidad', true ) ?: 'presencial' );
        $modalidad_label = function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label( $ticket_id ) : ucfirst( $modalidad );
        $status = eventosapp_self_checkin_get_today_status( $ticket_id, $event_id );

        return [
            'ticket_id'       => $ticket_id,
            'event_id'        => $event_id,
            'event_name'      => get_the_title( $event_id ),
            'first_name'      => $first_name,
            'last_name'       => $last_name,
            'full_name'       => trim( $first_name . ' ' . $last_name ),
            'company'         => $company,
            'designation'     => $role,
            'cc'              => $cc,
            'email'           => $email,
            'phone'           => $phone,
            'localidad'       => $localidad,
            'ticket_pub'      => $ticket_pub,
            'modalidad'       => $modalidad,
            'modalidad_label' => $modalidad_label,
            'today'           => $status['today'],
            'today_status'    => $status['today_status'],
            'today_allowed'   => $status['today_allowed'],
            'already_checked' => ( $status['today_status'] === 'checked_in' ),
        ];
    }
}

if ( ! function_exists('eventosapp_self_checkin_mark_ticket') ) {
    function eventosapp_self_checkin_mark_ticket( $ticket_id ) {
        $ticket_id = absint( $ticket_id );
        if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
            return new WP_Error('invalid_ticket', 'Ticket inválido.');
        }

        $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return new WP_Error('invalid_event', 'Evento inválido.');
        }

        if ( ! eventosapp_self_checkin_user_can_event( $event_id ) ) {
            return new WP_Error('forbidden', 'Sin permisos para este evento.');
        }

        $today = function_exists('eventosapp_get_today_in_event_tz')
            ? eventosapp_get_today_in_event_tz( $event_id )
            : current_time('Y-m-d');

        $days = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days( $event_id ) : [];
        if ( empty( $days ) || ! in_array( $today, $days, true ) ) {
            return new WP_Error('invalid_day', 'El check-in solo está permitido en las fechas del evento. Hoy no corresponde.');
        }

        $status_arr = get_post_meta( $ticket_id, '_eventosapp_checkin_status', true );
        if ( is_string( $status_arr ) ) $status_arr = @unserialize( $status_arr );
        if ( ! is_array( $status_arr ) ) $status_arr = [];

        $already = isset( $status_arr[$today] ) && $status_arr[$today] === 'checked_in';

        if ( ! $already ) {
            $status_arr[$today] = 'checked_in';
            update_post_meta( $ticket_id, '_eventosapp_checkin_status', $status_arr );

            try {
                $tz = new DateTimeZone( get_post_meta( $event_id, '_eventosapp_zona_horaria', true ) ?: wp_timezone_string() );
            } catch ( Exception $e ) {
                $tz = wp_timezone();
            }
            $now = new DateTime( 'now', $tz );

            $log = get_post_meta( $ticket_id, '_eventosapp_checkin_log', true );
            if ( is_string( $log ) ) $log = @unserialize( $log );
            if ( ! is_array( $log ) ) $log = [];

            $log[] = [
                'fecha'         => $now->format('Y-m-d'),
                'hora'          => $now->format('H:i:s'),
                'dia'           => $today,
                'status'        => 'checked_in',
                'status_label'  => 'Check-in por autogestión',
                'checkin_type'  => 'presencial',
                'modalidad'     => function_exists('eventosapp_get_ticket_modalidad') ? eventosapp_get_ticket_modalidad( $ticket_id ) : get_post_meta( $ticket_id, '_eventosapp_ticket_modalidad', true ),
                'usuario'       => eventosapp_self_checkin_current_user_label(),
                'origen'        => 'self-checkin',
                'qr_type'       => 'self_checkin',
                'qr_type_label' => 'Autogestión',
            ];
            update_post_meta( $ticket_id, '_eventosapp_checkin_log', $log );

            if ( function_exists('eventosapp_update_qr_usage_stats') ) {
                eventosapp_update_qr_usage_stats( $event_id, 'self_checkin' );
            }
        }

        return [
            'event_id' => $event_id,
            'today'    => $today,
            'already'  => $already,
        ];
    }
}


if ( ! function_exists('eventosapp_self_checkin_shell_single_quote') ) {
    function eventosapp_self_checkin_shell_single_quote( $value ) {
        return "'" . str_replace( "'", "'\\''", (string) $value ) . "'";
    }
}

if ( ! function_exists('eventosapp_self_checkin_get_current_page_url') ) {
    function eventosapp_self_checkin_get_current_page_url( $event_id = 0 ) {
        $event_id = absint( $event_id );

        if ( function_exists('eventosapp_get_self_checkin_url') ) {
            $configured_url = eventosapp_get_self_checkin_url();
            if ( $configured_url && $configured_url !== '#' ) {
                return esc_url_raw( $configured_url );
            }
        }

        $queried_id = get_queried_object_id();
        if ( $queried_id ) {
            $permalink = get_permalink( $queried_id );
            if ( $permalink ) {
                return esc_url_raw( $permalink );
            }
        }

        $scheme = is_ssl() ? 'https' : 'http';
        $host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url('/'), PHP_URL_HOST );
        $uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
        $url    = $scheme . '://' . $host . $uri;

        return esc_url_raw( remove_query_arg( [ '_wpnonce', 'action', 'platform', 'evsc_url' ], $url ) );
    }
}

if ( ! function_exists('eventosapp_self_checkin_validate_local_launcher_url') ) {
    function eventosapp_self_checkin_validate_local_launcher_url( $url ) {
        $url = esc_url_raw( trim( (string) $url ) );

        if ( ! $url ) {
            $url = function_exists('eventosapp_get_self_checkin_url') ? eventosapp_get_self_checkin_url() : home_url('/');
            $url = esc_url_raw( $url );
        }

        $home_host = wp_parse_url( home_url('/'), PHP_URL_HOST );
        $url_host  = wp_parse_url( $url, PHP_URL_HOST );

        if ( ! $home_host || ! $url_host || strtolower( $home_host ) !== strtolower( $url_host ) ) {
            $url = function_exists('eventosapp_get_self_checkin_url') ? eventosapp_get_self_checkin_url() : home_url('/');
            $url = esc_url_raw( $url );
        }

        return $url;
    }
}

if ( ! function_exists('eventosapp_self_checkin_get_launcher_download_url') ) {
    function eventosapp_self_checkin_get_launcher_download_url( $platform, $page_url ) {
        $platform = sanitize_key( $platform );
        $page_url = eventosapp_self_checkin_validate_local_launcher_url( $page_url );

        $url = add_query_arg([
            'action'   => 'eventosapp_self_checkin_download_launcher',
            'platform' => $platform,
            'evsc_url' => rawurlencode( $page_url ),
        ], admin_url('admin-post.php'));

        return wp_nonce_url( $url, 'eventosapp_self_checkin_download_launcher_' . $platform );
    }
}

if ( ! function_exists('eventosapp_self_checkin_mac_launcher_content') ) {
    function eventosapp_self_checkin_mac_launcher_content( $page_url ) {
        $page_url = eventosapp_self_checkin_validate_local_launcher_url( $page_url );
        $quoted_url = eventosapp_self_checkin_shell_single_quote( $page_url );

        return "#!/bin/bash\n"
            . "# EventosApp - Lanzador de Kiosko de Autogestion\n"
            . "# Abre Google Chrome en modo kiosko con impresion silenciosa.\n\n"
            . "KIOSK_URL={$quoted_url}\n"
            . "CHROME_BIN=\"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome\"\n\n"
            . "if [ ! -x \"$CHROME_BIN\" ]; then\n"
            . "  echo \"Google Chrome no se encontro en /Applications. Instala Google Chrome o ajusta la ruta del lanzador.\"\n"
            . "  if command -v osascript >/dev/null 2>&1; then\n"
            . "    osascript -e 'display alert \"EventosApp Kiosko\" message \"Google Chrome no se encontro en /Applications. Instala Google Chrome o ajusta la ruta del lanzador.\"' >/dev/null 2>&1\n"
            . "  fi\n"
            . "  exit 1\n"
            . "fi\n\n"
            . "\"$CHROME_BIN\" \\\n"
            . "  --user-data-dir=\"$HOME/Chrome-EventosApp-Kiosko\" \\\n"
            . "  --kiosk \\\n"
            . "  --kiosk-printing \\\n"
            . "  \"$KIOSK_URL\" >/dev/null 2>&1 &\n\n"
            . "exit 0\n";
    }
}

if ( ! function_exists('eventosapp_self_checkin_windows_launcher_content') ) {
    function eventosapp_self_checkin_windows_launcher_content( $page_url ) {
        $page_url = eventosapp_self_checkin_validate_local_launcher_url( $page_url );
        $safe_url = str_replace( [ "\r", "\n", '"' ], [ '', '', '' ], $page_url );

        return "@echo off\r\n"
            . "setlocal\r\n"
            . "REM EventosApp - Lanzador de Kiosko de Autogestion\r\n"
            . "REM Abre Google Chrome en modo kiosko con impresion silenciosa.\r\n\r\n"
            . "set \"KIOSK_URL={$safe_url}\"\r\n"
            . "set \"CHROME_EXE=%ProgramFiles%\\Google\\Chrome\\Application\\chrome.exe\"\r\n"
            . "if not exist \"%CHROME_EXE%\" set \"CHROME_EXE=%ProgramFiles(x86)%\\Google\\Chrome\\Application\\chrome.exe\"\r\n\r\n"
            . "if not exist \"%CHROME_EXE%\" (\r\n"
            . "  echo Google Chrome no se encontro en este equipo.\r\n"
            . "  echo Instala Google Chrome o ajusta la ruta dentro de este archivo.\r\n"
            . "  pause\r\n"
            . "  exit /b 1\r\n"
            . ")\r\n\r\n"
            . "start \"\" \"%CHROME_EXE%\" --user-data-dir=\"%LOCALAPPDATA%\\EventosAppKiosko\" --kiosk --kiosk-printing \"%KIOSK_URL%\"\r\n"
            . "exit /b 0\r\n";
    }
}

if ( ! function_exists('eventosapp_self_checkin_messages') ) {
    function eventosapp_self_checkin_messages() {
        return [
            'cc_required' => 'Ingresa una cédula de 5 a 30 números o mínimo 2 letras para buscar.',
            'searching'   => 'Buscando asistente…',
            'no_results'  => 'No se encontró ningún asistente con ese dato en este evento.',
            'select_one'  => 'Selecciona un resultado para continuar.',
            'confirming'  => 'Confirmando información…',
            'printing'    => 'Marcando check-in e imprimiendo escarapela…',
            'net_error'   => 'Error de conexión. Intenta nuevamente.',
        ];
    }
}


if ( ! function_exists('eventosapp_self_checkin_default_css') ) {
    /**
     * CSS base del módulo de autogestión.
     *
     * Se centraliza en una función porque Elementor renderiza la vista previa mediante
     * AJAX y, en ese contexto, los estilos encolados durante render() pueden llegar
     * tarde al iframe del editor. Este mismo CSS se usa para wp_enqueue_style() y como
     * respaldo inline dentro del HTML del widget.
     */
    function eventosapp_self_checkin_default_css() {
        return <<<'CSS'
.evsc-wrap{box-sizing:border-box;width:100%;max-width:760px;margin:0 auto;padding:24px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:28px;box-shadow:0 12px 34px rgba(15,23,42,.08);font-family:Arial,Helvetica,sans-serif;color:#0f172a;text-align:center}
.evsc-wrap *,.evsc-wrap *::before,.evsc-wrap *::after,.evsc-admin-note *,.evsc-admin-note *::before,.evsc-admin-note *::after{box-sizing:border-box}
.evsc-logo-wrap{display:flex;justify-content:center;align-items:center;margin:0 0 18px;text-align:center}
.evsc-logo{display:block;width:auto;height:auto;max-width:220px;max-height:120px;object-fit:contain}
.evsc-kicker{margin:0 0 6px;color:#2563eb;font-size:18px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;text-align:center}
.evsc-title{margin:0;color:#0f172a;font-size:38px;line-height:1.05;font-weight:900;text-align:center}
.evsc-subtitle{margin:10px 0 22px;color:#475569;font-size:18px;line-height:1.35;text-align:center}
.evsc-event{display:flex;align-items:center;justify-content:center;gap:8px;width:max-content;max-width:100%;margin:0 auto 18px;padding:10px 14px;border-radius:999px;background:#e0f2fe;color:#075985;font-size:17px;font-weight:800;line-height:1.2;text-align:center}
.evsc-panel{width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:24px;padding:22px;box-shadow:0 8px 22px rgba(15,23,42,.06)}
.evsc-search{display:flex;flex-direction:column;gap:16px;align-items:stretch;width:100%}
.evsc-field{width:100%}
.evsc-field label{display:block;margin:0 0 8px;color:#334155;font-size:18px;font-weight:800;text-align:center}
.evsc-input{display:block;width:100%;box-sizing:border-box;border:2px solid #cbd5e1;border-radius:20px;background:#fff;color:#0f172a;font-size:34px;font-weight:900;letter-spacing:.04em;text-align:center;padding:18px 20px;min-height:82px;outline:none;box-shadow:inset 0 2px 4px rgba(15,23,42,.04)}
.evsc-input:focus{border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.14)}
.evsc-input::placeholder{color:#64748b;opacity:.82}
.evsc-btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;width:100%;min-height:72px;border:0;border-radius:20px;padding:18px 26px;font-size:22px;font-weight:900;line-height:1.1;text-decoration:none!important;cursor:pointer;transition:transform .12s ease,filter .15s ease,background-color .15s ease,opacity .15s ease,color .15s ease;touch-action:manipulation;appearance:none;-webkit-appearance:none}
.evsc-btn:hover{filter:brightness(.96);transform:translateY(-1px)}
.evsc-btn:active{transform:translateY(0)}
.evsc-btn[disabled]{opacity:.45;cursor:not-allowed;transform:none;filter:none}
.evsc-btn-primary{background:#2563eb;color:#fff!important}
.evsc-btn-success{background:#16a34a;color:#fff!important}
.evsc-btn-dark{background:#0f172a;color:#fff!important}
.evsc-btn-light{background:#e2e8f0;color:#0f172a!important}
.evsc-actions{display:flex;flex-direction:column;gap:14px;align-items:stretch;justify-content:flex-start;width:100%;margin-top:18px}
.evsc-status{margin-top:14px;padding:14px 16px;border-radius:16px;background:#f1f5f9;color:#334155;font-size:18px;font-weight:700;text-align:center;display:none}
.evsc-status.is-visible{display:block}
.evsc-status.ok{background:#dcfce7;color:#166534}
.evsc-status.err{background:#fee2e2;color:#991b1b}
.evsc-results{margin-top:18px;display:grid;gap:12px;width:100%}
.evsc-result{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:18px;align-items:center;background:#fff;border:2px solid #e2e8f0;border-radius:20px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.05);cursor:pointer;text-align:left;width:100%;color:inherit;font-family:inherit}
.evsc-result:hover{border-color:#93c5fd}
.evsc-result.is-selected{border-color:#2563eb;background:#eff6ff;box-shadow:0 0 0 4px rgba(37,99,235,.12)}
.evsc-result-name{display:block;font-size:26px;line-height:1.1;font-weight:900;color:#0f172a}
.evsc-result-meta{display:block;margin-top:8px;color:#475569;font-size:17px;line-height:1.35}
.evsc-result-chips{display:block;margin-top:8px}
.evsc-select-label{width:auto;min-height:48px;border-radius:999px;padding:12px 16px;font-size:15px;white-space:nowrap}
.evsc-chip{display:inline-flex;align-items:center;border-radius:999px;padding:6px 10px;font-size:14px;font-weight:900;background:#e2e8f0;color:#334155;margin:3px 6px 3px 0}
.evsc-chip-ok{background:#dcfce7;color:#166534}
.evsc-chip-warn{background:#fef3c7;color:#92400e}
.evsc-confirm{display:none;width:100%;max-width:760px;margin:20px auto 0;background:#0f172a;color:#fff;border-radius:24px;padding:22px;box-shadow:0 14px 32px rgba(15,23,42,.22);text-align:left}
.evsc-confirm.is-visible{display:block}
.evsc-confirm h3{margin:0 0 12px;color:#fff;font-size:32px;line-height:1.1;font-weight:900;text-align:center}
.evsc-confirm-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:16px}
.evsc-data{background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.16);border-radius:18px;padding:14px}
.evsc-data-label{display:block;color:#bfdbfe;font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.evsc-data-value{display:block;color:#fff;font-size:22px;font-weight:900;word-break:break-word}
.evsc-print-frame{position:fixed;left:-10000px;top:0;width:1px;height:1px;border:0;opacity:0;pointer-events:none}
.evsc-alert{max-width:900px;margin:16px auto;padding:14px 16px;border-radius:14px;font-size:17px;font-weight:700;font-family:Arial,Helvetica,sans-serif}
.evsc-alert-warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}
.evsc-alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.evsc-admin-note{position:relative;max-width:760px;margin:16px auto 18px;padding:16px 52px 16px 16px;border-radius:16px;background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.45;font-weight:700}
.evsc-admin-note strong{color:#7c2d12}
.evsc-launcher-close{position:absolute;top:10px;right:10px;width:32px;height:32px;border:0;border-radius:999px;background:rgba(124,45,18,.12);color:#7c2d12;font-size:20px;line-height:1;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;touch-action:manipulation}
.evsc-launcher-box{margin-top:12px;padding:12px;border-radius:14px;background:#fff;border:1px solid #fed7aa;color:#7c2d12}
.evsc-launcher-title{display:block;margin:0 0 6px;font-size:14px;font-weight:900;color:#7c2d12;text-transform:uppercase;letter-spacing:.03em}
.evsc-launcher-text{display:block;margin:0 0 10px;font-size:13px;line-height:1.35;color:#9a3412;font-weight:700}
.evsc-launcher-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.evsc-launcher-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:999px;background:#0f172a;color:#fff!important;text-decoration:none!important;padding:10px 14px;font-size:13px;font-weight:900;line-height:1;touch-action:manipulation;transition:transform .12s ease,filter .15s ease,background-color .15s ease,color .15s ease}
.evsc-launcher-btn:hover{filter:brightness(.96);transform:translateY(-1px)}
html.evsc-kiosk-lock,body.evsc-kiosk-lock{overscroll-behavior:none;overscroll-behavior-x:none;background:#f8fafc}
body.evsc-kiosk-lock{touch-action:pan-y;-webkit-user-select:none;user-select:none;-webkit-tap-highlight-color:transparent}
body.evsc-kiosk-lock input,body.evsc-kiosk-lock textarea,body.evsc-kiosk-lock select,body.evsc-kiosk-lock [contenteditable="true"]{touch-action:manipulation;-webkit-user-select:text;user-select:text}
body.evsc-kiosk-lock a,body.evsc-kiosk-lock button{touch-action:manipulation}
.evsc-kiosk-hint{display:none;margin:0 0 16px;padding:12px 14px;border-radius:14px;background:#ecfdf5;color:#065f46;border:1px solid #bbf7d0;font-size:14px;line-height:1.4;font-weight:800;text-align:center}
.evsc-kiosk-hint.is-visible{display:block}
.evsc-fullscreen-trigger-wrap{display:flex;width:100%;max-width:760px;margin:0 auto 16px;align-items:center;justify-content:center;font-family:Arial,Helvetica,sans-serif}
.evsc-fullscreen-trigger-wrap.align-left{justify-content:flex-start}.evsc-fullscreen-trigger-wrap.align-right{justify-content:flex-end}.evsc-fullscreen-trigger-wrap.align-stretch .evsc-fullscreen-trigger{width:100%}
.evsc-fullscreen-trigger{display:inline-flex;align-items:center;justify-content:center;gap:10px;border:0;border-radius:999px;background:#047857;color:#fff!important;text-decoration:none!important;padding:16px 24px;min-height:58px;font-size:18px;font-weight:900;line-height:1;cursor:pointer;touch-action:manipulation;appearance:none;-webkit-appearance:none;box-shadow:0 10px 24px rgba(4,120,87,.2);transition:transform .12s ease,filter .15s ease,background-color .15s ease,color .15s ease,opacity .15s ease}
.evsc-fullscreen-trigger:hover{filter:brightness(.96);transform:translateY(-1px)}.evsc-fullscreen-trigger:active{transform:translateY(0)}.evsc-fullscreen-trigger.evsc-is-hidden,body.evsc-is-fullscreen .evsc-fullscreen-trigger-wrap[data-hide-on-fullscreen="1"]{display:none!important}body.evsc-is-fullscreen .evsc-launcher-module{display:none!important}
.evsc-keyboard{width:100%;margin:18px 0 4px;padding:14px;border-radius:22px;background:#f1f5f9;border:1px solid #dbe4ee;box-shadow:inset 0 1px 0 rgba(255,255,255,.72)}
.evsc-keyboard-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 12px;flex-wrap:wrap}.evsc-keyboard-title{display:block;color:#334155;font-size:15px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}.evsc-keyboard-toggle{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.evsc-keyboard-mode{border:0;border-radius:999px;background:#e2e8f0;color:#0f172a;padding:9px 14px;font-size:14px;font-weight:900;cursor:pointer;touch-action:manipulation}.evsc-keyboard-mode.is-active{background:#2563eb;color:#fff}
.evsc-keyboard-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.evsc-keyboard-grid.evsc-keyboard-letters{grid-template-columns:repeat(7,minmax(0,1fr));display:none}.evsc-keyboard[data-mode="letters"] .evsc-keyboard-numbers{display:none}.evsc-keyboard[data-mode="letters"] .evsc-keyboard-letters{display:grid}
.evsc-key{display:inline-flex;align-items:center;justify-content:center;min-height:58px;border:0;border-radius:16px;background:#fff;color:#0f172a;font-size:22px;font-weight:900;line-height:1;box-shadow:0 3px 10px rgba(15,23,42,.08);cursor:pointer;touch-action:manipulation;appearance:none;-webkit-appearance:none}.evsc-key:hover{filter:brightness(.97)}.evsc-key:active{transform:translateY(1px)}.evsc-key-action{background:#dbeafe;color:#1d4ed8;font-size:16px}.evsc-key-wide{grid-column:span 2}
@media(max-width:800px){.evsc-wrap{max-width:100%;padding:16px;border-radius:20px}.evsc-title{font-size:30px}.evsc-logo{max-width:180px;max-height:90px}.evsc-panel{padding:16px}.evsc-input{font-size:27px;min-height:76px}.evsc-result{grid-template-columns:1fr}.evsc-result-name{font-size:23px}.evsc-confirm-grid{grid-template-columns:1fr}.evsc-launcher-actions{align-items:stretch}.evsc-launcher-btn{width:100%}.evsc-admin-note{padding-right:48px}.evsc-fullscreen-trigger-wrap{max-width:100%;align-items:stretch}.evsc-fullscreen-trigger{width:100%;min-height:64px}.evsc-keyboard{padding:12px;border-radius:18px}.evsc-keyboard-grid.evsc-keyboard-letters{grid-template-columns:repeat(5,minmax(0,1fr))}.evsc-key{min-height:54px;font-size:20px}.evsc-key-action{font-size:15px}}
CSS;
    }
}

if ( ! function_exists('eventosapp_self_checkin_inline_style_fallback') ) {
    /**
     * Imprime el CSS base dentro del HTML del widget una sola vez por request.
     * Esto corrige la vista previa de Elementor, donde los estilos encolados desde
     * render() no siempre son inyectados en el iframe del editor.
     */
    function eventosapp_self_checkin_inline_style_fallback() {
        static $printed = false;
        if ( $printed ) {
            return '';
        }
        $printed = true;
        return '<style id="eventosapp-self-checkin-inline-fallback">' . eventosapp_self_checkin_default_css() . '</style>';
    }
}

if ( ! function_exists('eventosapp_self_checkin_inline_js') ) {
    function eventosapp_self_checkin_inline_js() {
        return <<<'JS'
(function(){
  if(window.EvSelfCheckinCoreLoaded || window.EvSelfCheckinCoreBooting){
    return;
  }
  window.EvSelfCheckinCoreBooting = true;

  function evscBoot(){
    if(!window.jQuery){
      window.setTimeout(evscBoot, 50);
      return;
    }

    window.jQuery(function($){
      if(window.EvSelfCheckinCoreLoaded){
        return;
      }
      window.EvSelfCheckinCoreLoaded = true;
      window.EvSelfCheckinCoreBooting = false;
  var globalConfig = window.EvSelfCheckin || {};
  var kioskGuardsInstalled = false;
  var kioskHistoryArmed = false;
  var kioskLastTouch = {x:0, y:0, edge:false};

  function evscIsEditableTarget(target){
    if(!target) return false;
    var tag = String(target.tagName || '').toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable === true;
  }

  function evscFullscreenElement(){
    return document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement || null;
  }

  function evscUpdateFullscreenState(){
    var isFullscreen = !!evscFullscreenElement();
    $('body').toggleClass('evsc-is-fullscreen', isFullscreen);
    $('.evsc-js-fullscreen').toggleClass('evsc-is-hidden', isFullscreen).attr('aria-hidden', isFullscreen ? 'true' : 'false');
    $('.evsc-launcher-module').attr('aria-hidden', isFullscreen ? 'true' : 'false');
  }

  function evscTryFullscreen(){
    var docEl = document.documentElement;
    if(!docEl || evscFullscreenElement()){
      evscUpdateFullscreenState();
      return null;
    }

    var requestFn = docEl.requestFullscreen || docEl.webkitRequestFullscreen || docEl.msRequestFullscreen;
    if(!requestFn){
      evscUpdateFullscreenState();
      return null;
    }

    try {
      var request = requestFn.call(docEl, {navigationUI:'hide'});
      if(request && request.then){
        request.then(evscUpdateFullscreenState).catch(function(){ evscUpdateFullscreenState(); });
      } else {
        setTimeout(evscUpdateFullscreenState, 120);
      }
      return request || null;
    } catch(e) {
      evscUpdateFullscreenState();
      return null;
    }
  }

  function evscExitFullscreen(){
    if(!evscFullscreenElement()){
      evscUpdateFullscreenState();
      return null;
    }

    var exitFn = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
    if(!exitFn){
      evscUpdateFullscreenState();
      return null;
    }

    try {
      var result = exitFn.call(document);
      if(result && result.then){
        result.then(evscUpdateFullscreenState).catch(function(){ evscUpdateFullscreenState(); });
      } else {
        setTimeout(evscUpdateFullscreenState, 120);
      }
      return result || null;
    } catch(e) {
      evscUpdateFullscreenState();
      return null;
    }
  }

  function evscBindPress($base, selector, callback){
    if(!$base || !$base.length || typeof callback !== 'function'){
      return;
    }

    var events = 'pointerup.evscPress touchend.evscPress click.evscPress';
    var delegate = selector || null;
    var handler = function(e){
      var now = Date.now ? Date.now() : (new Date()).getTime();
      var $target = $(this);
      var original = e.originalEvent || {};
      var lastHandled = parseInt($target.data('evscPressHandledAt'), 10) || 0;

      if(e.type === 'pointerup'){
        if(typeof original.button !== 'undefined' && original.button !== 0){
          return;
        }
        if(typeof original.isPrimary !== 'undefined' && original.isPrimary === false){
          return;
        }
      }

      if(e.type === 'click' && lastHandled && (now - lastHandled) < 650){
        e.preventDefault();
        e.stopPropagation();
        return;
      }

      if((e.type === 'pointerup' || e.type === 'touchend') && lastHandled && (now - lastHandled) < 120){
        e.preventDefault();
        e.stopPropagation();
        return;
      }

      $target.data('evscPressHandledAt', now);
      e.preventDefault();
      callback.call(this, e);
    };

    if(delegate){
      $base.on(events, delegate, handler);
    } else {
      $base.on(events, handler);
    }
  }

  function evscArmHistoryTrap(){
    if(kioskHistoryArmed){
      return;
    }
    kioskHistoryArmed = true;

    try {
      window.history.replaceState({evscKiosk:true}, document.title, window.location.href);
      window.history.pushState({evscKiosk:true}, document.title, window.location.href);
    } catch(e) {}

    window.addEventListener('popstate', function(){
      try {
        window.history.pushState({evscKiosk:true}, document.title, window.location.href);
      } catch(e) {}
    });
  }

  function evscInstallKioskGuards(){
    if(kioskGuardsInstalled || !$('.evsc-wrap[data-kiosk-lock="1"]').length){
      return;
    }
    kioskGuardsInstalled = true;

    $('html, body').addClass('evsc-kiosk-lock');
    evscArmHistoryTrap();

    try {
      if(window.navigator && window.navigator.maxTouchPoints > 0){
        $('.evsc-kiosk-hint').addClass('is-visible');
      }
    } catch(e) {}

    document.addEventListener('contextmenu', function(e){
      e.preventDefault();
    }, true);

    document.addEventListener('dragstart', function(e){
      e.preventDefault();
    }, true);

    document.addEventListener('selectstart', function(e){
      if(!evscIsEditableTarget(e.target)){
        e.preventDefault();
      }
    }, true);

    document.addEventListener('keydown', function(e){
      var key = String(e.key || '').toLowerCase();
      var blockNavigationKey = (e.altKey && (key === 'arrowleft' || key === 'arrowright')) || key === 'browserback' || key === 'browserforward';
      var blockBackspaceNavigation = key === 'backspace' && !evscIsEditableTarget(e.target);
      if(blockNavigationKey || blockBackspaceNavigation){
        e.preventDefault();
        e.stopPropagation();
      }
    }, true);

    document.addEventListener('touchstart', function(e){
      if(!e.touches || e.touches.length !== 1){
        if(e.touches && e.touches.length > 1){
          e.preventDefault();
        }
        return;
      }
      var touch = e.touches[0];
      var width = window.innerWidth || document.documentElement.clientWidth || 0;
      kioskLastTouch.x = touch.clientX;
      kioskLastTouch.y = touch.clientY;
      kioskLastTouch.edge = touch.clientX <= 32 || (width > 0 && touch.clientX >= width - 32);
    }, {capture:true, passive:false});

    document.addEventListener('touchmove', function(e){
      if(!e.touches || !e.touches.length){
        return;
      }
      if(e.touches.length > 1){
        e.preventDefault();
        return;
      }
      var touch = e.touches[0];
      var dx = touch.clientX - kioskLastTouch.x;
      var dy = touch.clientY - kioskLastTouch.y;
      var absX = Math.abs(dx);
      var absY = Math.abs(dy);

      if(kioskLastTouch.edge && absX > 22 && absX > absY * 1.2){
        e.preventDefault();
      }
    }, {capture:true, passive:false});

    document.addEventListener('wheel', function(e){
      if(Math.abs(e.deltaX || 0) > Math.abs(e.deltaY || 0) && Math.abs(e.deltaX || 0) > 8){
        e.preventDefault();
      }
    }, {capture:true, passive:false});

    window.addEventListener('pageshow', function(){
      evscArmHistoryTrap();
    });

  }

  evscBindPress($(document), '.evsc-js-fullscreen', function(){
    evscTryFullscreen();
  });

  document.addEventListener('fullscreenchange', evscUpdateFullscreenState);
  document.addEventListener('webkitfullscreenchange', evscUpdateFullscreenState);
  document.addEventListener('MSFullscreenChange', evscUpdateFullscreenState);

  window.addEventListener('message', function(evt){
    try {
      if(evt && evt.data && evt.data.type === 'eventosapp_self_checkin_afterprint'){
        $('.evsc-print-frame').remove();
      }
    } catch(e) {}
  });

  function escHtml(value){
    if(value === null || typeof value === 'undefined') return '';
    return $('<div>').text(String(value)).html();
  }

  function evscLauncherStorageKey($module){
    var eventId = String($module.data('event-id') || '0');
    var path = '';
    try { path = String(window.location.pathname || '') + String(window.location.search || ''); } catch(e) { path = 'current'; }
    return 'eventosapp_self_checkin_launcher_closed_' + eventId + '_' + path;
  }

  function evscSessionGet(key){
    try { return window.sessionStorage ? window.sessionStorage.getItem(key) : null; } catch(e) { return null; }
  }

  function evscSessionSet(key, value){
    try { if(window.sessionStorage){ window.sessionStorage.setItem(key, value); } } catch(e) {}
  }

  function evscInitLauncherDismiss($scope){
    $scope.filter('.evsc-launcher-module').add($scope.find('.evsc-launcher-module')).each(function(){
      var $module = $(this);
      if($module.data('evscLauncherReady')) return;
      $module.data('evscLauncherReady', true);

      var key = evscLauncherStorageKey($module);
      if(evscSessionGet(key) === '1'){
        $module.hide();
        return;
      }

      $module.on('click', '.evsc-launcher-close', function(e){
        e.preventDefault();
        evscSessionSet(key, '1');
        $module.stop(true, true).slideUp(160);
      });
    });
  }

  function initSelfCheckin($root){
    if(!$root.length || $root.data('evscReady')) return;
    $root.data('evscReady', true);

    var messages = globalConfig.messages || {};
    var eventId = parseInt($root.data('event-id'), 10) || 0;
    var eventName = String($root.data('event-name') || '');
    var ajaxUrl = String($root.data('ajax-url') || globalConfig.ajax_url || '');
    var searchNonce = String($root.data('search-nonce') || globalConfig.search_nonce || '');
    var confirmNonce = String($root.data('confirm-nonce') || globalConfig.confirm_nonce || '');
    var printNonce = String($root.data('print-nonce') || globalConfig.print_nonce || '');
    var $input = $root.find('.evsc-js-cc').first();
    var $searchBtn = $root.find('.evsc-js-search').first();
    var $confirmBtn = $root.find('.evsc-js-confirm').first();
    var $clearBtn = $root.find('.evsc-js-clear').first();
    var $results = $root.find('.evsc-js-results').first();
    var $status = $root.find('.evsc-js-status').first();
    var $confirm = $root.find('.evsc-js-confirm-box').first();
    var $confirmData = $root.find('.evsc-js-confirm-data').first();
    var $printBtn = $root.find('.evsc-js-print').first();
    var $keyboard = $root.find('.evsc-js-keyboard').first();
    var touchKeyboardEnabled = $keyboard.length > 0;
    var selectedTicket = null;
    var confirmedTicket = null;
    var currentRows = [];

    function showStatus(message, type){
      $status.removeClass('ok err is-visible');
      if(!message){ $status.hide().text(''); return; }
      $status.addClass('is-visible').addClass(type === 'ok' ? 'ok' : (type === 'err' ? 'err' : '')).text(message).show();
    }

    function normalizeIdentifier(){
      var value = String($input.val() || '').toUpperCase();
      value = value.replace(/[^0-9A-ZÁÉÍÓÚÜÑ\s]/g, '');
      value = value.replace(/\s+/g, ' ');
      if(value.length > 80){
        value = value.substring(0, 80);
      }
      $input.val(value);
      return $.trim(value);
    }

    function validIdentifier(){
      var identifier = normalizeIdentifier();
      var compact = identifier.replace(/\s+/g, '');
      if(/^\d+$/.test(compact)){
        return compact.length >= 5 && compact.length <= 30;
      }
      return compact.length >= 2 && compact.length <= 80;
    }

    function isFullscreenExitCode(value){
      return $.trim(String(value || '')) === '000000';
    }

    function setCaretToEnd(){
      try {
        var el = $input.get(0);
        if(el && typeof el.setSelectionRange === 'function'){
          var len = String(el.value || '').length;
          el.setSelectionRange(len, len);
        }
      } catch(e) {}
    }

    function focusInput(){
      try {
        var el = $input.get(0);
        if(el && typeof el.focus === 'function'){
          el.focus({preventScroll:true});
        }
      } catch(e) {
        try { $input.focus(); } catch(err) {}
      }
      setCaretToEnd();
    }

    function keyboardInsert(value){
      value = (value === null || typeof value === 'undefined') ? '' : String(value);
      value = value.toUpperCase().replace(/[^0-9A-ZÁÉÍÓÚÜÑ]/g, '');
      if(!value){
        return;
      }

      var el = $input.get(0);
      var current = String($input.val() || '');
      var start = current.length;
      var end = current.length;

      try {
        if(el && typeof el.selectionStart === 'number' && typeof el.selectionEnd === 'number'){
          start = el.selectionStart;
          end = el.selectionEnd;
        }
      } catch(e) {}

      var next = current.substring(0, start) + value + current.substring(end);
      if(next.length > 80){
        next = next.substring(0, 80);
      }

      $input.val(next);
      normalizeIdentifier();
      resetSelection(false);
      showStatus('', '');
      focusInput();

      try {
        var pos = Math.min(start + value.length, String($input.val() || '').length);
        if(el && typeof el.setSelectionRange === 'function'){
          el.setSelectionRange(pos, pos);
        }
      } catch(e) {}
    }

    function keyboardBackspace(){
      var el = $input.get(0);
      var current = String($input.val() || '');
      var start = current.length;
      var end = current.length;

      try {
        if(el && typeof el.selectionStart === 'number' && typeof el.selectionEnd === 'number'){
          start = el.selectionStart;
          end = el.selectionEnd;
        }
      } catch(e) {}

      if(start === end && start > 0){
        start--;
      }

      $input.val(current.substring(0, start) + current.substring(end));
      normalizeIdentifier();
      resetSelection(false);
      showStatus('', '');
      focusInput();

      try {
        if(el && typeof el.setSelectionRange === 'function'){
          el.setSelectionRange(start, start);
        }
      } catch(e) {}
    }

    function keyboardClear(){
      $input.val('');
      normalizeIdentifier();
      resetSelection(false);
      showStatus('', '');
      focusInput();
    }

    function initTouchKeyboard(){
      if(!touchKeyboardEnabled){
        return;
      }

      $input.attr('inputmode', 'none').attr('virtualkeyboardpolicy', 'manual').attr('data-evsc-touch-keyboard', '1');

      $input.on('pointerdown touchstart', function(evt){
        try {
          var original = evt.originalEvent || evt;
          var isTouch = (original && original.type === 'touchstart') || (original && original.pointerType === 'touch');
          if(isTouch && window.navigator && window.navigator.maxTouchPoints > 0){
            $input.prop('readonly', true);
            window.setTimeout(function(){
              $input.prop('readonly', false);
            }, 180);
          }
        } catch(e) {
          try { $input.prop('readonly', false); } catch(err) {}
        }
      });

      $input.on('blur', function(){
        try { $input.prop('readonly', false); } catch(e) {}
      });

      evscBindPress($keyboard, '.evsc-js-keyboard-mode', function(){
        var mode = String($(this).attr('data-mode') || 'numbers');
        if(mode !== 'letters'){
          mode = 'numbers';
        }
        $keyboard.attr('data-mode', mode);
        $keyboard.find('.evsc-js-keyboard-mode').removeClass('is-active').attr('aria-pressed', 'false');
        $(this).addClass('is-active').attr('aria-pressed', 'true');
        focusInput();
      });

      evscBindPress($keyboard, '.evsc-key', function(){
        var $key = $(this);
        var action = String($key.attr('data-action') || '');
        if(action === 'backspace'){
          keyboardBackspace();
          return;
        }
        if(action === 'clear'){
          keyboardClear();
          return;
        }
        keyboardInsert($key.attr('data-key'));
      });
    }

    function resetSelection(keepResults){
      selectedTicket = null;
      confirmedTicket = null;
      $confirmBtn.prop('disabled', true);
      $printBtn.prop('disabled', true);
      $confirm.removeClass('is-visible');
      $confirmData.empty();
      if(!keepResults){
        currentRows = [];
        $results.empty();
      }
    }

    function chip(text, cls){
      return '<span class="evsc-chip '+(cls||'')+'">'+escHtml(text)+'</span>';
    }

    function renderResults(rows){
      resetSelection(true);
      currentRows = $.isArray(rows) ? rows : [];

      if(!currentRows.length){
        $results.html('<div class="evsc-status is-visible err">'+escHtml(messages.no_results || 'No se encontraron resultados.')+'</div>');
        return;
      }

      var html = '';
      $.each(currentRows, function(i, it){
        var checked = it.already_checked === true || it.today_status === 'checked_in';
        html += '<button type="button" class="evsc-result evsc-js-result" data-ticket-id="'+escHtml(it.ticket_id)+'">'
             +    '<span>'
             +      '<span class="evsc-result-name">'+escHtml(it.full_name || 'Asistente sin nombre')+'</span>'
             +      '<span class="evsc-result-meta">'
             +        'Cédula: <strong>'+escHtml(it.cc || '—')+'</strong><br>'
             +        'Localidad: <strong>'+escHtml(it.localidad || '—')+'</strong> · Ticket: <strong>'+escHtml(it.ticket_pub || '—')+'</strong><br>'
             +        'Empresa: <strong>'+escHtml(it.company || '—')+'</strong>'
             +      '</span>'
             +      '<span class="evsc-result-chips">'
             +        chip(it.modalidad_label || 'Presencial', '')
             +        (checked ? chip('Check-in ya registrado', 'evsc-chip-ok') : chip('Pendiente de check-in', 'evsc-chip-warn'))
             +      '</span>'
             +    '</span>'
             +    '<span class="evsc-btn evsc-btn-light evsc-select-label">Seleccionar</span>'
             +  '</button>';
      });
      $results.html(html);
    }

    function findRow(ticketId){
      ticketId = parseInt(ticketId, 10);
      for(var i=0; i<currentRows.length; i++){
        if(parseInt(currentRows[i].ticket_id, 10) === ticketId) return currentRows[i];
      }
      return null;
    }

    function renderConfirm(it){
      confirmedTicket = it;
      $confirmData.html(
        '<div class="evsc-data"><span class="evsc-data-label">Nombre</span><span class="evsc-data-value">'+escHtml(it.full_name || '—')+'</span></div>'+
        '<div class="evsc-data"><span class="evsc-data-label">Cédula</span><span class="evsc-data-value">'+escHtml(it.cc || '—')+'</span></div>'+
        '<div class="evsc-data"><span class="evsc-data-label">Localidad</span><span class="evsc-data-value">'+escHtml(it.localidad || '—')+'</span></div>'+
        '<div class="evsc-data"><span class="evsc-data-label">Empresa</span><span class="evsc-data-value">'+escHtml(it.company || '—')+'</span></div>'+
        '<div class="evsc-data"><span class="evsc-data-label">Cargo</span><span class="evsc-data-value">'+escHtml(it.designation || '—')+'</span></div>'+
        '<div class="evsc-data"><span class="evsc-data-label">Ticket</span><span class="evsc-data-value">'+escHtml(it.ticket_pub || '—')+'</span></div>'+
        '<div class="evsc-data"><span class="evsc-data-label">Evento</span><span class="evsc-data-value">'+escHtml(it.event_name || eventName || '—')+'</span></div>'+
        '<div class="evsc-data"><span class="evsc-data-label">Estado</span><span class="evsc-data-value">'+(it.already_checked ? 'Check-in registrado' : 'Listo para imprimir')+'</span></div>'
      );
      $confirm.addClass('is-visible');
      $printBtn.prop('disabled', false);
      $('html, body').animate({scrollTop: $confirm.offset().top - 20}, 220);
    }

    initTouchKeyboard();

    $input.on('input', function(){
      normalizeIdentifier();
      resetSelection(false);
      showStatus('', '');
    });

    $input.on('keydown', function(e){
      var key = String(e.key || '');

      if(key === 'Enter'){
        e.preventDefault();
        $searchBtn.trigger('click');
        return;
      }

      if(touchKeyboardEnabled && $input.prop('readonly')){
        if(key === 'Backspace'){
          e.preventDefault();
          keyboardBackspace();
          return;
        }
        if(key === 'Delete' || key === 'Escape'){
          e.preventDefault();
          keyboardClear();
          return;
        }
        if(/^[0-9A-Za-zÁÉÍÓÚÜÑáéíóúüñ]$/.test(key)){
          e.preventDefault();
          keyboardInsert(key);
        }
      }
    });

    evscBindPress($searchBtn, null, function(){
      var searchValue = normalizeIdentifier();

      if(isFullscreenExitCode(searchValue)){
        evscExitFullscreen();
        $input.val('');
        resetSelection(false);
        showStatus('Modo pantalla completa cerrado.', 'ok');
        setTimeout(function(){ focusInput(); }, 160);
        return;
      }

      if(!validIdentifier()){
        showStatus(messages.cc_required || 'Ingresa un dato válido para buscar.', 'err');
        resetSelection(false);
        return;
      }

      if(!ajaxUrl || !searchNonce){
        showStatus('No fue posible iniciar la búsqueda. Recarga la página e intenta nuevamente.', 'err');
        return;
      }

      $searchBtn.prop('disabled', true).text('Buscando…');
      showStatus(messages.searching || 'Buscando asistente…', '');
      resetSelection(false);

      $.post(ajaxUrl, {
        action: 'eventosapp_self_checkin_search',
        security: searchNonce,
        event_id: eventId,
        cedula: searchValue
      }, function(resp){
        if(resp && resp.success){
          showStatus('', '');
          renderResults(resp.data && resp.data.results ? resp.data.results : []);
        } else {
          var msg = resp && resp.data && resp.data.message ? resp.data.message : (messages.no_results || 'No se encontraron resultados.');
          showStatus(msg, 'err');
        }
      }, 'json').fail(function(){
        showStatus(messages.net_error || 'Error de conexión. Intenta nuevamente.', 'err');
      }).always(function(){
        $searchBtn.prop('disabled', false).text($searchBtn.data('label') || 'Buscar');
      });
    });

    evscBindPress($results, '.evsc-js-result', function(){
      var ticketId = $(this).data('ticket-id');
      selectedTicket = findRow(ticketId);
      $results.find('.evsc-js-result').removeClass('is-selected');
      $(this).addClass('is-selected');
      $confirmBtn.prop('disabled', !selectedTicket);
      $confirm.removeClass('is-visible');
      $printBtn.prop('disabled', true);
      showStatus(selectedTicket ? 'Resultado seleccionado. Presiona Confirmar para revisar los datos.' : (messages.select_one || 'Selecciona un resultado.'), selectedTicket ? 'ok' : 'err');
    });

    evscBindPress($confirmBtn, null, function(){
      if(!selectedTicket){
        showStatus(messages.select_one || 'Selecciona un resultado.', 'err');
        return;
      }

      if(!ajaxUrl || !confirmNonce){
        showStatus('No fue posible confirmar la información. Recarga la página e intenta nuevamente.', 'err');
        return;
      }

      $confirmBtn.prop('disabled', true).text('Confirmando…');
      showStatus(messages.confirming || 'Confirmando información…', '');

      $.post(ajaxUrl, {
        action: 'eventosapp_self_checkin_confirm',
        security: confirmNonce,
        event_id: eventId,
        ticket_id: selectedTicket.ticket_id
      }, function(resp){
        if(resp && resp.success && resp.data && resp.data.ticket){
          showStatus('Datos confirmados. Ya puedes imprimir la escarapela.', 'ok');
          renderConfirm(resp.data.ticket);
        } else {
          var msg = resp && resp.data && resp.data.message ? resp.data.message : 'No fue posible confirmar el asistente.';
          showStatus(msg, 'err');
        }
      }, 'json').fail(function(){
        showStatus(messages.net_error || 'Error de conexión. Intenta nuevamente.', 'err');
      }).always(function(){
        $confirmBtn.prop('disabled', !selectedTicket).text($confirmBtn.data('label') || 'Confirmar');
      });
    });

    evscBindPress($printBtn, null, function(){
      if(!confirmedTicket){
        showStatus('Primero confirma la información del asistente.', 'err');
        return;
      }

      if(!ajaxUrl || !printNonce){
        showStatus('No fue posible iniciar la impresión. Recarga la página e intenta nuevamente.', 'err');
        return;
      }

      $printBtn.prop('disabled', true).text('Imprimiendo…');
      showStatus(messages.printing || 'Marcando check-in e imprimiendo escarapela…', '');

      $.post(ajaxUrl, {
        action: 'eventosapp_self_checkin_print',
        security: printNonce,
        event_id: eventId,
        ticket_id: confirmedTicket.ticket_id
      }, function(resp){
        if(resp && resp.success && resp.data && resp.data.print_url){
          showStatus(resp.data.already ? 'El check-in ya estaba registrado. Se enviará la escarapela a impresión.' : 'Check-in registrado. Se enviará la escarapela a impresión.', 'ok');
          var $frame = $('<iframe class="evsc-print-frame" aria-hidden="true"></iframe>');
          $('body').append($frame);
          $frame.attr('src', resp.data.print_url);
          setTimeout(function(){ $frame.remove(); }, 20000);
          confirmedTicket.already_checked = true;
        } else {
          var msg = resp && resp.data && resp.data.message ? resp.data.message : 'No fue posible imprimir la escarapela.';
          showStatus(msg, 'err');
        }
      }, 'json').fail(function(xhr){
        var msg = messages.net_error || 'Error de conexión. Intenta nuevamente.';
        try { if(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){ msg = xhr.responseJSON.data.message; } } catch(e){}
        showStatus(msg, 'err');
      }).always(function(){
        $printBtn.prop('disabled', false).text($printBtn.data('label') || 'Imprimir escarapela');
      });
    });

    evscBindPress($clearBtn, null, function(){
      $input.val('').focus();
      resetSelection(false);
      showStatus('', '');
    });
  }

  function evscRunInit(scope){
    var $scope = scope && scope.jquery ? scope : $(scope || document);
    evscInstallKioskGuards();
    evscInitLauncherDismiss($scope);
    evscUpdateFullscreenState();

    $scope.filter('.evsc-wrap').add($scope.find('.evsc-wrap')).each(function(){
      initSelfCheckin($(this));
    });
  }

  window.EvSelfCheckinInit = evscRunInit;
  evscRunInit(document);

  if(window.elementorFrontend && window.elementorFrontend.hooks){
    try {
      window.elementorFrontend.hooks.addAction('frontend/element_ready/eventosapp_self_checkin_ui.default', function($scope){
        evscRunInit($scope);
      });
      window.elementorFrontend.hooks.addAction('frontend/element_ready/eventosapp_self_checkin_fullscreen.default', function($scope){
        evscRunInit($scope);
      });
      window.elementorFrontend.hooks.addAction('frontend/element_ready/eventosapp_self_checkin_launcher.default', function($scope){
        evscRunInit($scope);
      });
    } catch(e) {}
  }

  $(window).on('elementor/frontend/init', function(){
    if(window.elementorFrontend && window.elementorFrontend.hooks){
      try {
        window.elementorFrontend.hooks.addAction('frontend/element_ready/eventosapp_self_checkin_ui.default', function($scope){
          evscRunInit($scope);
        });
        window.elementorFrontend.hooks.addAction('frontend/element_ready/eventosapp_self_checkin_fullscreen.default', function($scope){
          evscRunInit($scope);
        });
        window.elementorFrontend.hooks.addAction('frontend/element_ready/eventosapp_self_checkin_launcher.default', function($scope){
          evscRunInit($scope);
        });
      } catch(e) {}
    }
  });

  $(document).on('elementor/popup/show evsc:refresh', function(){
    evscRunInit(document);
  });
    });
  }

  evscBoot();
})();
JS;
    }
}

if ( ! function_exists('eventosapp_self_checkin_inline_script_fallback') ) {
    /**
     * Imprime el JS del módulo dentro del HTML como respaldo para Elementor/WP Rocket.
     * El script tiene guard interno, por lo que no duplica eventos si también se imprime desde wp_enqueue_script().
     */
    function eventosapp_self_checkin_inline_script_fallback() {
        static $printed = false;
        if ( $printed ) {
            return '';
        }
        $printed = true;
        return '<script id="eventosapp-self-checkin-inline-js-fallback">' . eventosapp_self_checkin_inline_js() . '</script>';
    }
}

if ( ! function_exists('eventosapp_self_checkin_enqueue_assets') ) {
    function eventosapp_self_checkin_enqueue_assets() {
        static $script_added = false;

        wp_enqueue_script('jquery');

        if ( ! wp_script_is('eventosapp-self-checkin', 'registered') ) {
            wp_register_script('eventosapp-self-checkin', false, ['jquery'], null, true);
        }

        if ( ! $script_added ) {
            wp_localize_script('eventosapp-self-checkin', 'EvSelfCheckin', [
                'ajax_url'      => admin_url('admin-ajax.php'),
                'search_nonce'  => wp_create_nonce('eventosapp_self_checkin_search'),
                'confirm_nonce' => wp_create_nonce('eventosapp_self_checkin_confirm'),
                'print_nonce'   => wp_create_nonce('eventosapp_self_checkin_print'),
                'badge_nonce'   => wp_create_nonce('eventosapp_self_checkin_badge'),
                'messages'      => eventosapp_self_checkin_messages(),
            ]);

            $js = eventosapp_self_checkin_inline_js();
            wp_add_inline_script('eventosapp-self-checkin', $js);
            $script_added = true;
        }

        wp_enqueue_script('eventosapp-self-checkin');

        if ( ! wp_style_is('eventosapp-self-checkin', 'registered') ) {
            $css = eventosapp_self_checkin_default_css();
            wp_register_style('eventosapp-self-checkin', false, [], null);
            wp_add_inline_style('eventosapp-self-checkin', $css);
        }

        wp_enqueue_style('eventosapp-self-checkin');
    }
}


if ( ! function_exists('eventosapp_self_checkin_render_fullscreen_button') ) {
    function eventosapp_self_checkin_render_fullscreen_button( $args = [] ) {
        $defaults = [
            'label'              => 'Activar pantalla completa',
            'align'              => 'center',
            'hide_on_fullscreen' => true,
            'extra_class'        => '',
        ];
        $args = wp_parse_args( (array) $args, $defaults );

        eventosapp_self_checkin_enqueue_assets();

        $align = sanitize_key( $args['align'] );
        if ( ! in_array( $align, [ 'left', 'center', 'right', 'stretch' ], true ) ) {
            $align = 'center';
        }

        $classes = trim( 'evsc-fullscreen-trigger-wrap align-' . $align . ' ' . sanitize_html_class( $args['extra_class'] ) );

        ob_start();
        echo eventosapp_self_checkin_inline_style_fallback();
        ?>
        <div class="<?php echo esc_attr( $classes ); ?>" data-hide-on-fullscreen="<?php echo ! empty( $args['hide_on_fullscreen'] ) ? '1' : '0'; ?>">
            <button type="button" class="evsc-fullscreen-trigger evsc-js-fullscreen" data-label="<?php echo esc_attr( $args['label'] ); ?>">
                <?php echo esc_html( $args['label'] ); ?>
            </button>
        </div>
        <?php echo eventosapp_self_checkin_inline_script_fallback(); ?>
        <?php
        return ob_get_clean();
    }
}

if ( ! function_exists('eventosapp_self_checkin_render_launcher_block') ) {
    function eventosapp_self_checkin_render_launcher_block( $event_id = 0, $args = [] ) {
        $event_id = absint( $event_id );
        $defaults = [
            'show_for_admin_only' => true,
            'show_launcher_box'   => true,
            'intro_text'          => 'Modo kiosko / impresión silenciosa:',
            'description'         => 'por seguridad del navegador, WordPress no puede ejecutar Terminal, CMD ni activar banderas nativas de Chrome directamente desde un botón web. Para evitar errores del asistente, descarga el lanzador del sistema operativo del equipo del kiosko y ejecútalo desde ese equipo; abrirá esta página en Chrome con <code>--kiosk</code> y <code>--kiosk-printing</code>.',
            'launcher_title'      => 'Lanzadores del equipo kiosko',
            'launcher_text'       => 'Disponibles solo para administradores. Deben descargarse y ejecutarse una vez desde el equipo físico que tendrá la impresora predeterminada.',
            'mac_label'           => 'Descargar lanzador Mac',
            'windows_label'       => 'Descargar lanzador Windows',
            'show_close_button'  => true,
            'close_label'        => 'Cerrar aviso de kiosko',
        ];
        $args = wp_parse_args( (array) $args, $defaults );

        if ( ! empty( $args['show_for_admin_only'] ) && ! current_user_can('manage_options') ) {
            return '';
        }

        eventosapp_self_checkin_enqueue_assets();

        $launcher_page_url = eventosapp_self_checkin_get_current_page_url( $event_id );
        $launcher_mac_url  = eventosapp_self_checkin_get_launcher_download_url( 'mac', $launcher_page_url );
        $launcher_win_url  = eventosapp_self_checkin_get_launcher_download_url( 'windows', $launcher_page_url );

        ob_start();
        echo eventosapp_self_checkin_inline_style_fallback();
        ?>
        <div class="evsc-admin-note evsc-launcher-module" data-event-id="<?php echo esc_attr( $event_id ); ?>">
            <?php if ( ! empty( $args['show_close_button'] ) ) : ?>
                <button type="button" class="evsc-launcher-close" aria-label="<?php echo esc_attr( $args['close_label'] ); ?>">×</button>
            <?php endif; ?>
            <strong><?php echo esc_html( $args['intro_text'] ); ?></strong>
            <?php echo wp_kses_post( ' ' . $args['description'] ); ?>
            <?php if ( ! empty( $args['show_launcher_box'] ) ) : ?>
                <div class="evsc-launcher-box">
                    <span class="evsc-launcher-title"><?php echo esc_html( $args['launcher_title'] ); ?></span>
                    <span class="evsc-launcher-text"><?php echo esc_html( $args['launcher_text'] ); ?></span>
                    <div class="evsc-launcher-actions">
                        <a class="evsc-launcher-btn" href="<?php echo esc_url( $launcher_mac_url ); ?>"><?php echo esc_html( $args['mac_label'] ); ?></a>
                        <a class="evsc-launcher-btn" href="<?php echo esc_url( $launcher_win_url ); ?>"><?php echo esc_html( $args['windows_label'] ); ?></a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php echo eventosapp_self_checkin_inline_script_fallback(); ?>
        <?php
        return ob_get_clean();
    }
}

if ( ! function_exists('eventosapp_self_checkin_render_main_ui') ) {
    function eventosapp_self_checkin_render_main_ui( $event_id = 0, $args = [] ) {
        $event_id = eventosapp_self_checkin_get_active_event_id( $event_id );

        if ( ! $event_id ) {
            $dashboard_url = function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/');
            return eventosapp_self_checkin_inline_style_fallback() . '<div class="evsc-alert evsc-alert-warn">Debes escoger un <strong>evento</strong> para activar la autogestión. Ve al <a href="'.esc_url($dashboard_url).'">dashboard</a>, selecciónalo y vuelve aquí.</div>';
        }

        if ( ! eventosapp_self_checkin_user_can_event( $event_id ) ) {
            return eventosapp_self_checkin_inline_style_fallback() . '<div class="evsc-alert evsc-alert-error">No tienes permisos para usar la autogestión en este evento.</div>';
        }

        $defaults = [
            'show_event_badge'       => true,
            'logo_url'               => '',
            'logo_alt'               => '',
            'show_kicker'            => true,
            'show_title'             => true,
            'show_subtitle'          => true,
            'show_kiosk_hint'        => true,
            'enable_kiosk_lock'      => true,
            'show_touch_keyboard'    => true,
            'touch_keyboard_title'   => 'Teclado táctil',
            'touch_keyboard_numbers_label' => 'Números',
            'touch_keyboard_letters_label' => 'Letras',
            'touch_keyboard_backspace_label' => 'Borrar',
            'touch_keyboard_clear_label' => 'Limpiar',
            'kicker'                 => 'Autogestión',
            'title'                  => 'Identificación del asistente',
            'subtitle'               => 'Ingresa la cédula, selecciona el resultado correcto, confirma la información e imprime la escarapela.',
            'field_label'            => 'Cédula de ciudadanía',
            'placeholder'            => 'Ej: 1234567890',
            'search_label'           => 'Buscar',
            'clear_label'            => 'Limpiar',
            'confirm_label'          => 'Confirmar',
            'confirm_heading'        => 'Confirma tu información',
            'print_label'            => 'Imprimir escarapela',
            'kiosk_hint_text'        => 'Modo kiosko asistido activo: los gestos de atrás/adelante quedan bloqueados dentro de la página.',
            'fullscreen_label'       => 'Activar pantalla completa',
        ];
        $args = wp_parse_args( (array) $args, $defaults );

        eventosapp_self_checkin_enqueue_assets();

        $uid = function_exists('wp_unique_id') ? wp_unique_id('evsc-') : ( 'evsc-' . uniqid() );

        ob_start();
        echo eventosapp_self_checkin_inline_style_fallback();
        ?>
        <div id="<?php echo esc_attr( $uid ); ?>" class="evsc-wrap" data-event-id="<?php echo esc_attr( $event_id ); ?>" data-event-name="<?php echo esc_attr( get_the_title( $event_id ) ); ?>" data-kiosk-lock="<?php echo ! empty( $args['enable_kiosk_lock'] ) ? '1' : '0'; ?>" data-ajax-url="<?php echo esc_url( admin_url('admin-ajax.php') ); ?>" data-search-nonce="<?php echo esc_attr( wp_create_nonce('eventosapp_self_checkin_search') ); ?>" data-confirm-nonce="<?php echo esc_attr( wp_create_nonce('eventosapp_self_checkin_confirm') ); ?>" data-print-nonce="<?php echo esc_attr( wp_create_nonce('eventosapp_self_checkin_print') ); ?>">
            <?php if ( ! empty( $args['logo_url'] ) ) : ?>
                <div class="evsc-logo-wrap">
                    <img class="evsc-logo" src="<?php echo esc_url( $args['logo_url'] ); ?>" alt="<?php echo esc_attr( $args['logo_alt'] ?: get_the_title( $event_id ) ); ?>">
                </div>
            <?php endif; ?>
            <?php if ( ! empty( $args['show_event_badge'] ) ) : ?>
                <div class="evsc-event">Evento activo: <?php echo esc_html( get_the_title( $event_id ) ); ?></div>
            <?php endif; ?>
            <?php if ( ! empty( $args['show_kicker'] ) && $args['kicker'] !== '' ) : ?>
                <p class="evsc-kicker"><?php echo esc_html( $args['kicker'] ); ?></p>
            <?php endif; ?>
            <?php if ( ! empty( $args['show_title'] ) && $args['title'] !== '' ) : ?>
                <h2 class="evsc-title"><?php echo esc_html( $args['title'] ); ?></h2>
            <?php endif; ?>
            <?php if ( ! empty( $args['show_subtitle'] ) && $args['subtitle'] !== '' ) : ?>
                <p class="evsc-subtitle"><?php echo esc_html( $args['subtitle'] ); ?></p>
            <?php endif; ?>

            <?php if ( ! empty( $args['show_kiosk_hint'] ) ) : ?>
                <div class="evsc-kiosk-hint">
                    <?php echo esc_html( $args['kiosk_hint_text'] ); ?>
                </div>
            <?php endif; ?>

            <div class="evsc-panel">
                <div class="evsc-search">
                    <div class="evsc-field">
                        <label for="<?php echo esc_attr( $uid ); ?>-cc"><?php echo esc_html( $args['field_label'] ); ?></label>
                        <input id="<?php echo esc_attr( $uid ); ?>-cc" class="evsc-input evsc-js-cc" type="text" inputmode="none" maxlength="80" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>" aria-label="<?php echo esc_attr( $args['field_label'] ); ?>">
                    </div>
                    <button type="button" class="evsc-btn evsc-btn-primary evsc-js-search" data-label="<?php echo esc_attr( $args['search_label'] ); ?>"><?php echo esc_html( $args['search_label'] ); ?></button>
                </div>

                <?php if ( ! empty( $args['show_touch_keyboard'] ) ) : ?>
                    <div class="evsc-keyboard evsc-js-keyboard" data-mode="numbers" aria-label="<?php echo esc_attr( $args['touch_keyboard_title'] ); ?>">
                        <div class="evsc-keyboard-head">
                            <span class="evsc-keyboard-title"><?php echo esc_html( $args['touch_keyboard_title'] ); ?></span>
                            <div class="evsc-keyboard-toggle" role="group" aria-label="Modo de teclado">
                                <button type="button" class="evsc-keyboard-mode evsc-js-keyboard-mode is-active" data-mode="numbers" aria-pressed="true"><?php echo esc_html( $args['touch_keyboard_numbers_label'] ); ?></button>
                                <button type="button" class="evsc-keyboard-mode evsc-js-keyboard-mode" data-mode="letters" aria-pressed="false"><?php echo esc_html( $args['touch_keyboard_letters_label'] ); ?></button>
                            </div>
                        </div>
                        <div class="evsc-keyboard-grid evsc-keyboard-numbers">
                            <?php foreach ( [ '1', '2', '3', '4', '5', '6', '7', '8', '9' ] as $key ) : ?>
                                <button type="button" class="evsc-key" data-key="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $key ); ?></button>
                            <?php endforeach; ?>
                            <button type="button" class="evsc-key evsc-key-action" data-action="backspace"><?php echo esc_html( $args['touch_keyboard_backspace_label'] ); ?></button>
                            <button type="button" class="evsc-key" data-key="0">0</button>
                            <button type="button" class="evsc-key evsc-key-action" data-action="clear"><?php echo esc_html( $args['touch_keyboard_clear_label'] ); ?></button>
                        </div>
                        <div class="evsc-keyboard-grid evsc-keyboard-letters">
                            <?php foreach ( [ 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'Ñ', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z' ] as $key ) : ?>
                                <button type="button" class="evsc-key" data-key="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $key ); ?></button>
                            <?php endforeach; ?>
                            <button type="button" class="evsc-key evsc-key-action evsc-key-wide" data-action="backspace"><?php echo esc_html( $args['touch_keyboard_backspace_label'] ); ?></button>
                            <button type="button" class="evsc-key evsc-key-action evsc-key-wide" data-action="clear"><?php echo esc_html( $args['touch_keyboard_clear_label'] ); ?></button>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="evsc-status evsc-js-status" role="status" aria-live="polite"></div>
                <div class="evsc-results evsc-js-results" aria-live="polite"></div>

                <div class="evsc-actions">
                    <button type="button" class="evsc-btn evsc-btn-light evsc-js-clear"><?php echo esc_html( $args['clear_label'] ); ?></button>
                    <button type="button" class="evsc-btn evsc-btn-success evsc-js-confirm" data-label="<?php echo esc_attr( $args['confirm_label'] ); ?>" disabled><?php echo esc_html( $args['confirm_label'] ); ?></button>
                </div>
            </div>

            <div class="evsc-confirm evsc-js-confirm-box" aria-live="polite">
                <h3><?php echo esc_html( $args['confirm_heading'] ); ?></h3>
                <div class="evsc-confirm-grid evsc-js-confirm-data"></div>
                <div class="evsc-actions">
                    <button type="button" class="evsc-btn evsc-btn-success evsc-js-print" data-label="<?php echo esc_attr( $args['print_label'] ); ?>" disabled><?php echo esc_html( $args['print_label'] ); ?></button>
                </div>
            </div>
        </div>
        <?php echo eventosapp_self_checkin_inline_script_fallback(); ?>
        <?php
        return ob_get_clean();
    }
}

add_shortcode('eventosapp_self_checkin', function( $atts ) {
    if ( function_exists('eventosapp_require_feature') ) {
        eventosapp_require_feature('self_checkin');
    }

    $atts = shortcode_atts([
        'event_id' => 0,
    ], $atts, 'eventosapp_self_checkin');

    $event_id = eventosapp_self_checkin_get_active_event_id( $atts['event_id'] );

    ob_start();
    if ( function_exists('eventosapp_active_event_bar') ) {
        eventosapp_active_event_bar();
    }
    if ( $event_id ) {
        echo eventosapp_self_checkin_render_launcher_block( $event_id, [ 'show_for_admin_only' => true ] );
    }
    echo eventosapp_self_checkin_render_main_ui( $event_id );
    return ob_get_clean();
});


add_shortcode('eventosapp_self_checkin_fullscreen_button', function( $atts ) {
    $atts = shortcode_atts([
        'label'              => 'Activar pantalla completa',
        'align'              => 'center',
        'hide_on_fullscreen' => '1',
    ], $atts, 'eventosapp_self_checkin_fullscreen_button');

    return eventosapp_self_checkin_render_fullscreen_button([
        'label'              => $atts['label'],
        'align'              => $atts['align'],
        'hide_on_fullscreen' => $atts['hide_on_fullscreen'] !== '0',
    ]);
});

add_action('wp_ajax_eventosapp_self_checkin_search', function() {
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('self_checkin') ) {
        wp_send_json_error(['message' => 'Permisos insuficientes.'], 403);
    }

    check_ajax_referer('eventosapp_self_checkin_search', 'security');

    $event_id = eventosapp_self_checkin_get_active_event_id( $_POST['event_id'] ?? 0 );
    if ( ! $event_id || ! eventosapp_self_checkin_user_can_event( $event_id ) ) {
        wp_send_json_error(['message' => 'No tienes permisos para este evento.'], 403);
    }

    $identifier = eventosapp_self_checkin_validate_identifier( $_POST['cedula'] ?? '' );
    if ( is_wp_error( $identifier ) ) {
        wp_send_json_error(['message' => $identifier->get_error_message()], 400);
    }

    $ticket_ids = eventosapp_self_checkin_find_tickets_by_identifier( $identifier, $event_id, 10 );
    if ( ! empty( $ticket_ids ) ) {
        update_meta_cache('post', $ticket_ids);
    }

    $results = [];
    foreach ( $ticket_ids as $ticket_id ) {
        $payload = eventosapp_self_checkin_ticket_payload( $ticket_id );
        if ( ! $payload || absint( $payload['event_id'] ) !== absint( $event_id ) ) {
            continue;
        }
        $results[] = $payload;
    }

    wp_send_json_success([
        'results' => $results,
    ]);
});

add_action('wp_ajax_eventosapp_self_checkin_confirm', function() {
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('self_checkin') ) {
        wp_send_json_error(['message' => 'Permisos insuficientes.'], 403);
    }

    check_ajax_referer('eventosapp_self_checkin_confirm', 'security');

    $ticket_id = absint( $_POST['ticket_id'] ?? 0 );
    $event_id  = eventosapp_self_checkin_get_active_event_id( $_POST['event_id'] ?? 0 );

    if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
        wp_send_json_error(['message' => 'Ticket inválido.'], 400);
    }

    $ticket_event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
    if ( ! $event_id || $ticket_event_id !== absint( $event_id ) ) {
        wp_send_json_error(['message' => 'El ticket no corresponde al evento activo.'], 403);
    }

    if ( ! eventosapp_self_checkin_user_can_event( $event_id ) ) {
        wp_send_json_error(['message' => 'No tienes permisos para este evento.'], 403);
    }

    $payload = eventosapp_self_checkin_ticket_payload( $ticket_id );
    if ( ! $payload ) {
        wp_send_json_error(['message' => 'No fue posible leer la información del asistente.'], 400);
    }

    wp_send_json_success([
        'ticket' => $payload,
    ]);
});

add_action('wp_ajax_eventosapp_self_checkin_print', function() {
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('self_checkin') ) {
        wp_send_json_error(['message' => 'Permisos insuficientes.'], 403);
    }

    check_ajax_referer('eventosapp_self_checkin_print', 'security');

    $ticket_id = absint( $_POST['ticket_id'] ?? 0 );
    $event_id  = eventosapp_self_checkin_get_active_event_id( $_POST['event_id'] ?? 0 );

    if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
        wp_send_json_error(['message' => 'Ticket inválido.'], 400);
    }

    $ticket_event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
    if ( ! $event_id || $ticket_event_id !== absint( $event_id ) ) {
        wp_send_json_error(['message' => 'El ticket no corresponde al evento activo.'], 403);
    }

    $marked = eventosapp_self_checkin_mark_ticket( $ticket_id );
    if ( is_wp_error( $marked ) ) {
        $code = $marked->get_error_code() === 'invalid_day' ? 400 : 403;
        wp_send_json_error(['message' => $marked->get_error_message()], $code);
    }

    $print_url = add_query_arg([
        'action'    => 'eventosapp_self_checkin_badge',
        'nonce'     => wp_create_nonce('eventosapp_self_checkin_badge_' . $ticket_id),
        'ticket_id' => $ticket_id,
        'event_id'  => $ticket_event_id,
    ], admin_url('admin-ajax.php'));

    wp_send_json_success([
        'message'   => 'Check-in registrado. Imprimiendo escarapela.',
        'print_url' => esc_url_raw( $print_url ),
        'already'   => ! empty( $marked['already'] ),
        'ticket'    => eventosapp_self_checkin_ticket_payload( $ticket_id ),
    ]);
});

add_action('wp_ajax_eventosapp_self_checkin_badge', function() {
    $ticket_id = absint( $_GET['ticket_id'] ?? 0 );
    $event_id  = absint( $_GET['event_id'] ?? 0 );
    $nonce     = sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) );

    if ( ! $ticket_id || ! wp_verify_nonce( $nonce, 'eventosapp_self_checkin_badge_' . $ticket_id ) ) {
        wp_die('Nonce inválido.', '', 403);
    }

    if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
        wp_die('Ticket inválido.', '', 400);
    }

    $ticket_event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
    if ( ! $event_id || $event_id !== $ticket_event_id ) {
        $event_id = $ticket_event_id;
    }

    if ( ! eventosapp_self_checkin_user_can_event( $event_id ) ) {
        wp_die('Sin permisos.', '', 403);
    }

    if ( ! function_exists('eventosapp_get_badge_html_from_event') ) {
        wp_die('La función de escarapela no está disponible.', '', 500);
    }

    $badge_html = eventosapp_get_badge_html_from_event( $event_id, $ticket_id );
    echo eventosapp_self_checkin_prepare_badge_print_html( $badge_html );
    exit;
});

add_action('admin_post_eventosapp_self_checkin_download_launcher', function() {
    if ( ! is_user_logged_in() || ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos para descargar este lanzador.', '', 403);
    }

    $platform = sanitize_key( $_GET['platform'] ?? '' );
    if ( ! in_array( $platform, [ 'mac', 'windows' ], true ) ) {
        wp_die('Plataforma inválida.', '', 400);
    }

    check_admin_referer( 'eventosapp_self_checkin_download_launcher_' . $platform );

    $raw_url = isset( $_GET['evsc_url'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['evsc_url'] ) ) ) : '';
    $page_url = eventosapp_self_checkin_validate_local_launcher_url( $raw_url );

    if ( $platform === 'mac' ) {
        $filename = 'EventosApp-Kiosko-Autogestion.command';
        $content  = eventosapp_self_checkin_mac_launcher_content( $page_url );
    } else {
        $filename = 'EventosApp-Kiosko-Autogestion.cmd';
        $content  = eventosapp_self_checkin_windows_launcher_content( $page_url );
    }

    nocache_headers();
    header('Content-Type: application/octet-stream; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen( $content ));
    echo $content;
    exit;
});
