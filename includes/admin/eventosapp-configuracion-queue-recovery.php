<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Recuperación específica de la reinstalación de páginas de EventosApp.
 *
 * La cola central sigue siendo la autoridad de estado. Esta capa añade un
 * segundo camino de ejecución para el trabajo `eventosapp_install_pages` cuando
 * el loopback REST o WP-Cron del hosting no despiertan a tiempo, y permite
 * descartar la tarjeta de progreso terminada sin borrar el historial técnico de
 * Cola y Tareas.
 */

if ( ! function_exists('eventosapp_installation_queue_recovery_task') ) {
    function eventosapp_installation_queue_recovery_task($task_id = 0) {
        if ( ! function_exists('eventosapp_task_queue_get') ) {
            return null;
        }

        $task_id = absint($task_id ?: get_option('eventosapp_installation_task_id', 0));
        if ( ! $task_id ) {
            return null;
        }

        $task = eventosapp_task_queue_get($task_id);
        if ( ! is_array($task) || sanitize_key($task['task_type'] ?? '') !== 'eventosapp_install_pages' ) {
            return null;
        }

        return $task;
    }
}

if ( ! function_exists('eventosapp_installation_queue_recovery_is_active') ) {
    function eventosapp_installation_queue_recovery_is_active($task) {
        if ( ! is_array($task) ) {
            return false;
        }

        return in_array(
            sanitize_key($task['status'] ?? ''),
            ['queued', 'scheduled', 'running'],
            true
        );
    }
}

if ( ! function_exists('eventosapp_installation_queue_recovery_is_due') ) {
    function eventosapp_installation_queue_recovery_is_due($task) {
        if ( ! eventosapp_installation_queue_recovery_is_active($task) ) {
            return false;
        }

        $now = time();
        foreach (['scheduled_at', 'next_run_at'] as $field) {
            $value = isset($task[$field]) ? trim((string)$task[$field]) : '';
            if ( $value === '' ) {
                continue;
            }

            $timestamp = strtotime($value . ' UTC');
            if ( $timestamp && $timestamp > $now ) {
                return false;
            }
        }

        return true;
    }
}

if ( ! function_exists('eventosapp_installation_queue_recovery_tick') ) {
    /**
     * Ejecuta como máximo un lote por petición. El lock central evita duplicar
     * trabajo si el worker REST/cron sí alcanzó a arrancar al mismo tiempo.
     */
    function eventosapp_installation_queue_recovery_tick($task_id = 0) {
        static $attempted = [];

        $task = eventosapp_installation_queue_recovery_task($task_id);
        if ( ! is_array($task) ) {
            return false;
        }

        $task_id = absint($task['id'] ?? 0);
        if ( ! $task_id || isset($attempted[$task_id]) ) {
            return false;
        }
        $attempted[$task_id] = true;

        if ( ! eventosapp_installation_queue_recovery_is_due($task) ) {
            return false;
        }

        if (
            function_exists('eventosapp_task_queue_has_active_lock') &&
            eventosapp_task_queue_has_active_lock($task_id)
        ) {
            return false;
        }

        if ( ! function_exists('eventosapp_task_queue_process_task') ) {
            return false;
        }

        $result = eventosapp_task_queue_process_task($task_id);
        return ! is_wp_error($result);
    }
}

if ( ! function_exists('eventosapp_installation_queue_recovery_token') ) {
    function eventosapp_installation_queue_recovery_token($task_id) {
        return hash_hmac(
            'sha256',
            'eventosapp-installation-recovery-' . absint($task_id),
            wp_salt('auth')
        );
    }
}

if ( ! function_exists('eventosapp_installation_queue_recovery_verify_token') ) {
    function eventosapp_installation_queue_recovery_verify_token($task_id, $token) {
        $expected = eventosapp_installation_queue_recovery_token($task_id);
        return is_string($token) && hash_equals($expected, $token);
    }
}

if ( ! function_exists('eventosapp_installation_queue_recovery_next_delay') ) {
    function eventosapp_installation_queue_recovery_next_delay($task) {
        if ( ! is_array($task) ) {
            return 0;
        }

        $next_run = trim((string)($task['next_run_at'] ?? ''));
        if ( $next_run === '' ) {
            return 0;
        }

        $timestamp = strtotime($next_run . ' UTC');
        if ( ! $timestamp ) {
            return 0;
        }

        return min(15, max(0, $timestamp - time()));
    }
}

if ( ! function_exists('eventosapp_installation_queue_recovery_spawn_key') ) {
    function eventosapp_installation_queue_recovery_spawn_key($task_id) {
        return 'evapp_install_recovery_spawn_' . absint($task_id);
    }
}

if ( ! function_exists('eventosapp_installation_queue_recovery_spawn_worker') ) {
    /**
     * Fallback por admin-ajax. Usa un timeout de conexión razonable para evitar
     * el problema de loopbacks que no llegan a salir del socket con 10 ms.
     * Un transient corto impide crear cadenas paralelas del mismo worker.
     */
    function eventosapp_installation_queue_recovery_spawn_worker($task_id, $delay = 0) {
        $task_id = absint($task_id);
        if ( ! $task_id ) {
            return false;
        }

        $task = eventosapp_installation_queue_recovery_task($task_id);
        if ( ! eventosapp_installation_queue_recovery_is_active($task) ) {
            return false;
        }

        $delay = min(15, max(0, absint($delay)));
        $spawn_key = eventosapp_installation_queue_recovery_spawn_key($task_id);
        if ( get_transient($spawn_key) ) {
            return false;
        }
        set_transient($spawn_key, 1, max(15, $delay + 15));

        $response = wp_remote_post(admin_url('admin-ajax.php'), [
            'timeout'     => 2,
            'blocking'    => false,
            'redirection' => 0,
            'sslverify'   => apply_filters('https_local_ssl_verify', false),
            'body'        => [
                'action'  => 'eventosapp_installation_queue_recovery_worker',
                'task_id' => $task_id,
                'token'   => eventosapp_installation_queue_recovery_token($task_id),
                'delay'   => $delay,
            ],
        ]);

        if ( is_wp_error($response) ) {
            delete_transient($spawn_key);
            if ( function_exists('eventosapp_task_queue_add_log') ) {
                eventosapp_task_queue_add_log(
                    $task_id,
                    'warning',
                    'El worker alterno de instalación no pudo iniciar el loopback; se conserva WP-Cron y la recuperación por panel.',
                    ['error' => $response->get_error_message()]
                );
            }
        }

        return ! is_wp_error($response);
    }
}

if ( ! function_exists('eventosapp_installation_queue_recovery_worker') ) {
    function eventosapp_installation_queue_recovery_worker() {
        $task_id = absint($_POST['task_id'] ?? 0);
        $token   = sanitize_text_field((string)wp_unslash($_POST['token'] ?? ''));
        $delay   = min(15, max(0, absint($_POST['delay'] ?? 0)));

        if ( ! $task_id || ! eventosapp_installation_queue_recovery_verify_token($task_id, $token) ) {
            wp_send_json_error(['message' => 'Firma de worker inválida.'], 403);
        }

        if ( $delay > 0 ) {
            sleep($delay);
        }

        eventosapp_installation_queue_recovery_tick($task_id);
        $task = eventosapp_installation_queue_recovery_task($task_id);

        // Libera el turno del fallback solo después de terminar este intento.
        delete_transient(eventosapp_installation_queue_recovery_spawn_key($task_id));

        if ( eventosapp_installation_queue_recovery_is_active($task) ) {
            eventosapp_installation_queue_recovery_spawn_worker(
                $task_id,
                eventosapp_installation_queue_recovery_next_delay($task)
            );
        }

        wp_send_json_success([
            'task_id' => $task_id,
            'status'  => is_array($task) ? sanitize_key($task['status'] ?? '') : '',
        ]);
    }
}
add_action('wp_ajax_eventosapp_installation_queue_recovery_worker', 'eventosapp_installation_queue_recovery_worker');
add_action('wp_ajax_nopriv_eventosapp_installation_queue_recovery_worker', 'eventosapp_installation_queue_recovery_worker');

/**
 * Cada tarea nueva recibe inmediatamente el worker alterno. El kick original de
 * la cola permanece intacto y actúa en paralelo como primera vía de ejecución.
 */
add_action('eventosapp_task_queue_created', function($task_id, $task) {
    if ( ! is_array($task) || sanitize_key($task['task_type'] ?? '') !== 'eventosapp_install_pages' ) {
        return;
    }

    eventosapp_installation_queue_recovery_spawn_worker(absint($task_id), 0);
}, 20, 2);

/**
 * Si el hosting bloquea todos los loopbacks, la propia pantalla de Configuración
 * y el detalle de Cola y Tareas hacen avanzar un lote al abrirse.
 */
add_action('admin_init', function() {
    if ( wp_doing_ajax() || ! current_user_can('manage_options') ) {
        return;
    }

    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ( ! in_array($page, ['eventosapp_configuracion', 'eventosapp_task_queue'], true) ) {
        return;
    }

    $task = eventosapp_installation_queue_recovery_task();
    if ( ! is_array($task) ) {
        return;
    }

    eventosapp_installation_queue_recovery_tick(absint($task['id'] ?? 0));
    $fresh = eventosapp_installation_queue_recovery_task(absint($task['id'] ?? 0));
    if ( eventosapp_installation_queue_recovery_is_active($fresh) ) {
        eventosapp_installation_queue_recovery_spawn_worker(
            absint($fresh['id'] ?? 0),
            eventosapp_installation_queue_recovery_next_delay($fresh)
        );
    }
}, 100);

/**
 * El polling que ya existe en Configuración deja de ser únicamente de lectura:
 * antes de responder intenta un lote seguro. Así una tarea atascada comienza a
 * avanzar sin que el administrador tenga que relanzarla.
 */
add_action('wp_ajax_eventosapp_installation_job_status', function() {
    if ( ! current_user_can('manage_options') ) {
        return;
    }

    $task = eventosapp_installation_queue_recovery_task();
    if ( ! is_array($task) ) {
        return;
    }

    eventosapp_installation_queue_recovery_tick(absint($task['id'] ?? 0));
    $fresh = eventosapp_installation_queue_recovery_task(absint($task['id'] ?? 0));
    if ( eventosapp_installation_queue_recovery_is_active($fresh) ) {
        eventosapp_installation_queue_recovery_spawn_worker(
            absint($fresh['id'] ?? 0),
            eventosapp_installation_queue_recovery_next_delay($fresh)
        );
    }
}, 1);

/**
 * Descartar la tarjeta no elimina la tarea ni sus logs: solo borra el puntero a
 * la última reinstalación mostrado en Configuración. El historial continúa en
 * Cola y Tareas hasta que el administrador decida archivarlo o eliminarlo allí.
 */
add_action('wp_ajax_eventosapp_installation_dismiss_job_notice', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(['message' => 'No autorizado.'], 403);
    }

    check_ajax_referer('eventosapp_installation_dismiss_job_notice', 'nonce');

    $job = function_exists('eventosapp_installation_get_background_job')
        ? eventosapp_installation_get_background_job()
        : [];

    if ( function_exists('eventosapp_installation_job_is_active') && eventosapp_installation_job_is_active((array)$job) ) {
        wp_send_json_error(['message' => 'La tarea todavía está activa y no puede ocultarse.'], 409);
    }

    delete_option('eventosapp_installation_task_id');
    wp_send_json_success(['message' => 'Notificación eliminada.']);
});

if ( ! function_exists('eventosapp_installation_render_dismiss_job_notice_control') ) {
    function eventosapp_installation_render_dismiss_job_notice_control() {
        if ( ! current_user_can('manage_options') ) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ( $page !== 'eventosapp_configuracion' ) {
            return;
        }

        $job = function_exists('eventosapp_installation_get_background_job')
            ? eventosapp_installation_get_background_job()
            : [];
        if ( empty($job) || (function_exists('eventosapp_installation_job_is_active') && eventosapp_installation_job_is_active((array)$job)) ) {
            return;
        }

        $nonce = wp_create_nonce('eventosapp_installation_dismiss_job_notice');
        ?>
        <script id="eventosapp-installation-dismiss-job-notice">
        (function(){
            var box = document.getElementById('evapp-install-job');
            if (!box || box.querySelector('[data-evapp-dismiss-install-job]')) return;

            var row = box.querySelector('.evapp-install-job-row');
            if (!row) return;

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'button button-secondary';
            button.setAttribute('data-evapp-dismiss-install-job', '1');
            button.textContent = 'Borrar notificación';
            row.appendChild(button);

            button.addEventListener('click', function(){
                if (button.disabled) return;
                button.disabled = true;
                var previous = button.textContent;
                button.textContent = 'Borrando…';

                var body = new URLSearchParams();
                body.append('action', 'eventosapp_installation_dismiss_job_notice');
                body.append('nonce', <?php echo wp_json_encode($nonce); ?>);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString()
                })
                .then(function(response){ return response.json(); })
                .then(function(payload){
                    if (!payload || !payload.success) {
                        throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'No fue posible borrar la notificación.');
                    }
                    box.remove();
                })
                .catch(function(error){
                    button.disabled = false;
                    button.textContent = previous;
                    window.alert(error && error.message ? error.message : 'No fue posible borrar la notificación.');
                });
            });
        })();
        </script>
        <?php
    }
}
add_action('admin_footer', 'eventosapp_installation_render_dismiss_job_notice_control', 1002);
