<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Reinstalación limpia y automatización de páginas de EventosApp 1.5.0-rc.16.
 * Se carga antes del core para sustituir únicamente el instalador y el
 * programador de la cola central.
 */

/**
 * Instalación/reparación determinista de una definición.
 *
 * Si la página ya existe se conserva la página como entidad (ID, título, URL,
 * jerarquía y estado), pero se normaliza su contenido para que no pueda quedar
 * HTML, bloques, widgets o datos de Elementor compitiendo con EventosApp.
 */
if ( ! function_exists('eventosapp_installation_install_definition') ) {
    function eventosapp_installation_install_definition($definition_id, array $definition = [], array $stack = []) {
        $definition_id = sanitize_key((string)$definition_id);
        if ( $definition_id === '' ) {
            return new WP_Error('evapp_install_invalid_definition', 'La definición de página no es válida.');
        }

        if ( empty($definition) ) {
            $registry = eventosapp_installation_page_registry();
            if ( empty($registry[$definition_id]) || ! is_array($registry[$definition_id]) ) {
                return new WP_Error('evapp_install_unknown_definition', 'La página solicitada no existe en el inventario de instalación.');
            }
            $definition = $registry[$definition_id];
        }

        $shortcode  = sanitize_key($definition['shortcode'] ?? '');
        $structural = ! empty($definition['structural']);

        if ( ! $structural && ($shortcode === '' || ! shortcode_exists($shortcode)) ) {
            return new WP_Error(
                'evapp_shortcode_unavailable',
                'El shortcode [' . ($shortcode ?: 'desconocido') . '] no está registrado por el código cargado de EventosApp. No se modificó la página.'
            );
        }

        $parent_id = eventosapp_installation_resolve_parent_page_id($definition, $stack);
        if ( is_wp_error($parent_id) ) {
            return $parent_id;
        }

        $mapped_page_id = eventosapp_installation_get_mapped_page_id($definition);
        $mapped_page    = eventosapp_installation_get_valid_page($mapped_page_id);

        if ( $mapped_page instanceof WP_Post ) {
            $normalized = eventosapp_installation_normalize_managed_page($mapped_page, $definition);
            if ( is_wp_error($normalized) ) {
                return $normalized;
            }

            if ( ! eventosapp_installation_set_mapped_page_id($definition, $mapped_page->ID) ) {
                $current_id = eventosapp_installation_get_mapped_page_id($definition);
                if ( (int)$current_id !== (int)$mapped_page->ID ) {
                    return new WP_Error('evapp_install_map_failed', 'No fue posible guardar el mapeo de la página existente.');
                }
            }

            $changed = ! empty($normalized['changed']);
            return [
                'ok'      => true,
                'page_id' => absint($mapped_page->ID),
                'action'  => $changed ? 'reinstalled_clean' : 'verified',
                'changed' => $changed,
                'message' => $changed
                    ? ($structural
                        ? 'La estructura mapeada se normalizó y quedó sin contenido ajeno de editor.'
                        : 'La página mapeada se reinstaló en limpio: se eliminó contenido ajeno y estado de Elementor, y quedó únicamente el shortcode canónico.')
                    : 'La página ya estaba instalada, limpia y correctamente mapeada.',
            ];
        }

        $candidate = eventosapp_installation_find_existing_page($definition);
        if ( $candidate instanceof WP_Post ) {
            $normalized = eventosapp_installation_normalize_managed_page($candidate, $definition);
            if ( is_wp_error($normalized) ) {
                return $normalized;
            }

            if ( ! eventosapp_installation_set_mapped_page_id($definition, $candidate->ID) ) {
                $current_id = eventosapp_installation_get_mapped_page_id($definition);
                if ( (int)$current_id !== (int)$candidate->ID ) {
                    return new WP_Error('evapp_install_map_existing_failed', 'Se encontró una página de EventosApp, pero no fue posible mapearla después de limpiarla.');
                }
            }

            update_option('eventosapp_needs_flush', 1);

            return [
                'ok'      => true,
                'page_id' => absint($candidate->ID),
                'action'  => ! empty($normalized['changed']) ? 'cleaned_and_mapped' : 'mapped_existing',
                'changed' => true,
                'message' => ! empty($normalized['changed'])
                    ? 'Se encontró la página existente, se limpió por completo y se dejó únicamente su contenido canónico antes de mapearla.'
                    : 'Se encontró una página que ya estaba limpia y se mapeó sin duplicarla.',
            ];
        }

        $post_data = [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'post_title'     => sanitize_text_field($definition['title'] ?? $definition['label'] ?? 'EventosApp'),
            'post_name'      => sanitize_title($definition['slug'] ?? ''),
            'post_content'   => eventosapp_installation_expected_content($definition),
            'post_parent'    => absint($parent_id),
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ];

        $new_page_id = wp_insert_post($post_data, true);
        if ( is_wp_error($new_page_id) || ! $new_page_id ) {
            return is_wp_error($new_page_id)
                ? $new_page_id
                : new WP_Error('evapp_install_create_failed', 'WordPress no pudo crear la página.');
        }

        $new_page = get_post($new_page_id);
        if ( $new_page instanceof WP_Post ) {
            $normalized = eventosapp_installation_normalize_managed_page($new_page, $definition);
            if ( is_wp_error($normalized) ) {
                wp_delete_post($new_page_id, true);
                return $normalized;
            }
        }

        if ( ! eventosapp_installation_set_mapped_page_id($definition, $new_page_id) ) {
            $current_id = eventosapp_installation_get_mapped_page_id($definition);
            if ( (int)$current_id !== (int)$new_page_id ) {
                wp_delete_post($new_page_id, true);
                return new WP_Error('evapp_install_map_new_failed', 'La página se creó, pero no pudo mapearse. Se revirtió la creación para evitar una página huérfana.');
            }
        }

        eventosapp_installation_candidate_pages(true);
        update_option('eventosapp_needs_flush', 1);

        return [
            'ok'      => true,
            'page_id' => absint($new_page_id),
            'action'  => 'created',
            'changed' => true,
            'message' => $structural
                ? 'Estructura creada, publicada, normalizada y mapeada automáticamente.'
                : 'Página creada, publicada, limpia, con el shortcode canónico como único contenido y mapeada automáticamente.',
        ];
    }
}

/**
 * La cola conserva el mismo task_type para no romper tareas históricas, pero su
 * etiqueta deja explícito que cada pasada valida y normaliza las páginas.
 */
if ( ! function_exists('eventosapp_installation_register_task_queue_adapter') ) {
    function eventosapp_installation_register_task_queue_adapter() {
        if ( ! function_exists('eventosapp_task_queue_register_adapter') ) {
            return false;
        }

        return eventosapp_task_queue_register_adapter('eventosapp_install_pages', [
            'label'          => 'Reinstalación limpia de páginas de EventosApp',
            'channel'        => 'system',
            'group'          => 'massive',
            'batch_size'     => 4,
            'min_batch_size' => 1,
            'max_batch_size' => 8,
            'process_batch'  => 'eventosapp_installation_task_queue_process_batch',
        ]);
    }
}

if ( ! function_exists('eventosapp_installation_schedule_background_job') ) {
    function eventosapp_installation_schedule_background_job() {
        $current = eventosapp_installation_get_background_job();
        if ( eventosapp_installation_job_is_active($current) ) {
            return new WP_Error('evapp_install_job_active', 'Ya existe una reinstalación automática en curso o pausada.');
        }

        if (
            ! function_exists('eventosapp_task_queue_register_adapter') ||
            ! function_exists('eventosapp_task_queue_create') ||
            ! function_exists('eventosapp_task_queue_get')
        ) {
            return new WP_Error(
                'evapp_install_queue_missing',
                'La cola central de EventosApp no está disponible. Revisa includes/functions/eventosapp-task-queue-core.php antes de iniciar una reinstalación masiva.'
            );
        }

        eventosapp_installation_register_task_queue_adapter();
        if ( function_exists('eventosapp_task_queue_maybe_install') ) {
            eventosapp_task_queue_maybe_install();
        }

        $registry = eventosapp_installation_page_registry();
        $task_id  = eventosapp_task_queue_create([
            'task_type'   => 'eventosapp_install_pages',
            'task_group'  => 'massive',
            'channel'     => 'system',
            'title'       => 'Reinstalación limpia de páginas de EventosApp',
            'status'      => 'queued',
            'priority'    => 40,
            'total_items' => count($registry),
            'batch_size'  => 4,
            'payload'     => [
                'definition_ids' => array_keys($registry),
                'source'         => 'eventosapp_configuracion',
                'mode'           => 'clean_reinstall',
                'content_policy' => 'canonical_shortcode_only',
                'requested_at'   => current_time('mysql', true),
            ],
            'created_by'  => get_current_user_id(),
        ]);

        if ( is_wp_error($task_id) ) {
            return $task_id;
        }

        update_option('eventosapp_installation_task_id', absint($task_id), false);
        return eventosapp_installation_get_background_job();
    }
}

if ( ! function_exists('eventosapp_handle_install_all_pages') ) {
    function eventosapp_handle_install_all_pages() {
        if ( ! current_user_can('manage_options') ) {
            wp_die('No tienes permisos para reinstalar páginas de EventosApp.', '', ['response' => 403]);
        }

        check_admin_referer('eventosapp_install_all_pages', 'eventosapp_install_all_pages_nonce');

        $job = eventosapp_installation_schedule_background_job();
        if ( is_wp_error($job) ) {
            eventosapp_installation_redirect_with_notice('error', implode(' ', $job->get_error_messages()));
        }

        eventosapp_installation_redirect_with_notice(
            'success',
            'La reinstalación limpia fue enviada a la Cola y Tareas de EventosApp. Cada página será validada, limpiada de contenido ajeno/Elementor y normalizada a su shortcode canónico en segundo plano.'
        );
    }
}
