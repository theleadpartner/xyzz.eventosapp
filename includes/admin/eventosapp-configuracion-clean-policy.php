<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Política canónica de páginas gestionadas por EventosApp 1.5.0-rc.20.
 * Se carga antes del core de Configuración para sustituir únicamente helpers
 * del instalador protegidos con function_exists().
 */

if ( ! function_exists('eventosapp_installation_expected_content') ) {
    function eventosapp_installation_expected_content(array $definition) {
        if ( ! empty($definition['structural']) ) {
            return '';
        }

        return trim((string)($definition['content'] ?? ''));
    }
}

if ( ! function_exists('eventosapp_installation_normalize_content') ) {
    /**
     * Normaliza únicamente diferencias inocuas de almacenamiento. El estado
     * canónico sigue exigiendo que no exista HTML, bloques ni contenido extra.
     */
    function eventosapp_installation_normalize_content($content) {
        $content = str_replace(["\r\n", "\r"], "\n", (string)$content);
        return trim($content);
    }
}

if ( ! function_exists('eventosapp_installation_content_is_canonical') ) {
    function eventosapp_installation_content_is_canonical($content, array $definition) {
        return eventosapp_installation_normalize_content($content)
            === eventosapp_installation_normalize_content(eventosapp_installation_expected_content($definition));
    }
}

if ( ! function_exists('eventosapp_installation_elementor_artifacts') ) {
    /**
     * Devuelve todos los metadatos de Elementor que pueden quedar asociados a
     * una página. Se usa durante la limpieza para retirar por completo el estado
     * del builder, incluidos caches generados.
     *
     * Se limita deliberadamente a metadatos de Elementor y al template de
     * página cuando es uno de Elementor. No elimina postmeta funcional de
     * WordPress, EventosApp ni de otros módulos.
     */
    function eventosapp_installation_elementor_artifacts($page_id) {
        $page_id = absint($page_id);
        if ( ! $page_id ) {
            return [];
        }

        $artifacts = [];
        $all_meta = get_post_meta($page_id);
        if ( is_array($all_meta) ) {
            foreach (array_keys($all_meta) as $meta_key) {
                $meta_key = (string)$meta_key;
                if ( strpos($meta_key, '_elementor_') === 0 ) {
                    $artifacts[] = $meta_key;
                }
            }
        }

        $page_template = (string)get_post_meta($page_id, '_wp_page_template', true);
        if ( $page_template !== '' && stripos($page_template, 'elementor') !== false ) {
            $artifacts[] = '_wp_page_template';
        }

        return array_values(array_unique($artifacts));
    }
}

if ( ! function_exists('eventosapp_installation_elementor_render_artifacts') ) {
    /**
     * Distingue residuos que todavía pueden cambiar el documento renderizado de
     * metadatos de cache que Elementor es capaz de regenerar al visitar la URL.
     *
     * La reinstalación continúa borrando TODOS los metadatos _elementor_*; el
     * evaluador, en cambio, no vuelve a marcar la página como dañada únicamente
     * porque Elementor haya recreado un cache vacío después de la limpieza.
     */
    function eventosapp_installation_elementor_render_artifacts($page_id) {
        $page_id = absint($page_id);
        if ( ! $page_id ) {
            return [];
        }

        $artifacts = [];
        $elementor_data = get_post_meta($page_id, '_elementor_data', true);
        if ( is_string($elementor_data) ) {
            $raw = trim($elementor_data);
            if ( $raw !== '' && ! in_array($raw, ['[]', '{}', 'null'], true) ) {
                $decoded = json_decode($raw, true);
                if ( json_last_error() !== JSON_ERROR_NONE || ! empty($decoded) ) {
                    $artifacts[] = '_elementor_data';
                }
            }
        } elseif ( is_array($elementor_data) && ! empty($elementor_data) ) {
            $artifacts[] = '_elementor_data';
        }

        $edit_mode = sanitize_key((string)get_post_meta($page_id, '_elementor_edit_mode', true));
        if ( $edit_mode === 'builder' ) {
            $artifacts[] = '_elementor_edit_mode';
        }

        $page_template = (string)get_post_meta($page_id, '_wp_page_template', true);
        if ( $page_template !== '' && stripos($page_template, 'elementor') !== false ) {
            $artifacts[] = '_wp_page_template';
        }

        return array_values(array_unique($artifacts));
    }
}

if ( ! function_exists('eventosapp_installation_page_has_known_shortcode') ) {
    function eventosapp_installation_page_has_known_shortcode($page, array $definition) {
        if ( ! $page instanceof WP_Post ) {
            return false;
        }

        if ( ! empty($definition['structural']) ) {
            return true;
        }

        $shortcodes = [];
        $canonical = sanitize_key((string)($definition['shortcode'] ?? ''));
        if ( $canonical !== '' ) {
            $shortcodes[] = $canonical;
        }

        $legacy = isset($definition['legacy_shortcodes']) && is_array($definition['legacy_shortcodes'])
            ? $definition['legacy_shortcodes']
            : [];
        foreach ($legacy as $legacy_shortcode) {
            $legacy_shortcode = sanitize_key((string)$legacy_shortcode);
            if ( $legacy_shortcode !== '' ) {
                $shortcodes[] = $legacy_shortcode;
            }
        }

        $elementor_data = get_post_meta($page->ID, '_elementor_data', true);
        $elementor_data = is_string($elementor_data) ? $elementor_data : '';

        foreach (array_values(array_unique($shortcodes)) as $shortcode) {
            if ( has_shortcode((string)$page->post_content, $shortcode) ) {
                return true;
            }

            // Elementor puede guardar el widget Shortcode únicamente en
            // _elementor_data y dejar post_content vacío. Detectarlo aquí evita
            // crear una página duplicada cuando el mapeo se perdió pero la
            // página histórica todavía existe.
            if ( $elementor_data !== '' ) {
                $pattern = '/\[' . preg_quote($shortcode, '/') . '(?=[\s\]\/])/';
                if ( preg_match($pattern, $elementor_data) ) {
                    return true;
                }
            }
        }

        return false;
    }
}

if ( ! function_exists('eventosapp_installation_page_is_clean') ) {
    function eventosapp_installation_page_is_clean($page, array $definition) {
        if ( ! $page instanceof WP_Post ) {
            return false;
        }

        if ( ! eventosapp_installation_content_is_canonical($page->post_content, $definition) ) {
            return false;
        }

        return empty(eventosapp_installation_elementor_render_artifacts($page->ID));
    }
}

if ( ! function_exists('eventosapp_installation_normalize_managed_page') ) {
    /**
     * Normaliza únicamente una página que el inventario de instalación ya
     * identificó como administrada por EventosApp.
     *
     * Elimina todo contenido adicional de post_content y deja el contenido
     * canónico de la definición. Además limpia los metadatos de Elementor que
     * podrían seguir renderizando/identificando un documento del builder.
     */
    function eventosapp_installation_normalize_managed_page(WP_Post $page, array $definition) {
        $page_id = absint($page->ID);
        if ( ! $page_id || $page->post_type !== 'page' ) {
            return new WP_Error('evapp_install_normalize_invalid_page', 'La página que se intentó normalizar no es válida.');
        }

        $expected = eventosapp_installation_expected_content($definition);
        $content_changed = ! eventosapp_installation_content_is_canonical($page->post_content, $definition)
            || (string)$page->post_content !== $expected;
        $artifacts = eventosapp_installation_elementor_artifacts($page_id);
        $removed_meta = [];

        if ( $content_changed ) {
            $updated = wp_update_post([
                'ID'           => $page_id,
                'post_content' => $expected,
            ], true);

            if ( is_wp_error($updated) ) {
                return $updated;
            }
        }

        foreach ($artifacts as $meta_key) {
            delete_post_meta($page_id, $meta_key);
            if ( metadata_exists('post', $page_id, $meta_key) ) {
                return new WP_Error(
                    'evapp_install_elementor_cleanup_failed',
                    'No fue posible limpiar completamente el estado de Elementor de la página #' . $page_id . '.'
                );
            }
            $removed_meta[] = $meta_key;
        }

        clean_post_cache($page_id);
        $fresh_page = get_post($page_id);
        if ( ! $fresh_page instanceof WP_Post || ! eventosapp_installation_page_is_clean($fresh_page, $definition) ) {
            return new WP_Error(
                'evapp_install_normalize_verification_failed',
                'La página #' . $page_id . ' no quedó en el estado canónico esperado después de la limpieza.'
            );
        }

        return [
            'page_id'            => $page_id,
            'changed'            => $content_changed || ! empty($removed_meta),
            'content_changed'    => $content_changed,
            'removed_meta'       => $removed_meta,
            'removed_meta_count' => count($removed_meta),
        ];
    }
}

/**
 * Sustituye la detección histórica de candidatos. Una página no necesita estar
 * limpia para ser detectada: basta con que ya contenga el shortcode canónico o
 * uno de sus aliases históricos. La limpieza ocurre antes de mapearla.
 */
if ( ! function_exists('eventosapp_installation_find_existing_page') ) {
    function eventosapp_installation_find_existing_page(array $definition) {
        $structural = ! empty($definition['structural']);

        if ( $structural ) {
            $slug = sanitize_title($definition['slug'] ?? '');
            if ( $slug !== '' ) {
                $page = get_page_by_path($slug, OBJECT, 'page');
                if ( $page instanceof WP_Post && in_array($page->post_status, ['publish', 'private'], true) ) {
                    return $page;
                }
            }
            return null;
        }

        foreach (eventosapp_installation_candidate_pages() as $page) {
            if ( eventosapp_installation_page_has_known_shortcode($page, $definition) ) {
                return $page;
            }
        }

        return null;
    }
}

/**
 * Estado de salud reforzado: "Correcto" significa ahora estado canónico, no
 * simplemente que el shortcode aparezca en algún punto del contenido.
 */
if ( ! function_exists('eventosapp_installation_definition_status') ) {
    function eventosapp_installation_definition_status($definition_id, array $definition) {
        $definition_id        = sanitize_key((string)$definition_id);
        $shortcode            = sanitize_key($definition['shortcode'] ?? '');
        $structural           = ! empty($definition['structural']);
        $shortcode_registered = $structural || ($shortcode !== '' && shortcode_exists($shortcode));
        $mapped_page_id       = eventosapp_installation_get_mapped_page_id($definition);
        $mapped_page          = eventosapp_installation_get_valid_page($mapped_page_id);
        $mapped_has_shortcode = $mapped_page instanceof WP_Post
            ? eventosapp_installation_page_has_known_shortcode($mapped_page, $definition)
            : false;
        $mapped_is_clean      = $mapped_page instanceof WP_Post
            ? eventosapp_installation_page_is_clean($mapped_page, $definition)
            : false;

        $candidate_page = (!$mapped_page && $shortcode_registered)
            ? eventosapp_installation_find_existing_page($definition)
            : null;
        $candidate_is_clean = $candidate_page instanceof WP_Post
            ? eventosapp_installation_page_is_clean($candidate_page, $definition)
            : false;

        $state = 'missing';
        $issue = 'missing';

        if ( ! $shortcode_registered ) {
            $state = 'shortcode_unavailable';
            $issue = 'shortcode_unavailable';
        } elseif ( $mapped_is_clean ) {
            $state = 'ok';
            $issue = 'none';
        } elseif ( $mapped_page instanceof WP_Post ) {
            $state = 'mapped_without_shortcode';
            $issue = $mapped_has_shortcode ? 'needs_cleanup' : 'missing_shortcode';
        } elseif ( $candidate_page instanceof WP_Post ) {
            $state = 'needs_mapping';
            $issue = $candidate_is_clean ? 'needs_mapping' : 'needs_mapping_cleanup';
        }

        $display_page = $mapped_page instanceof WP_Post ? $mapped_page : $candidate_page;

        return [
            'id'                   => $definition_id,
            'state'                => $state,
            'issue'                => $issue,
            'healthy'              => $state === 'ok',
            'structural'           => $structural,
            'shortcode'            => $shortcode,
            'shortcode_registered' => (bool)$shortcode_registered,
            'mapped_page_id'       => $mapped_page instanceof WP_Post ? absint($mapped_page->ID) : 0,
            'mapped_page'          => $mapped_page,
            'mapped_has_content'   => (bool)$mapped_has_shortcode,
            'mapped_is_clean'      => (bool)$mapped_is_clean,
            'candidate_page_id'    => $candidate_page instanceof WP_Post ? absint($candidate_page->ID) : 0,
            'candidate_page'       => $candidate_page,
            'candidate_is_clean'   => (bool)$candidate_is_clean,
            'elementor_artifacts'  => $display_page instanceof WP_Post
                ? eventosapp_installation_elementor_render_artifacts($display_page->ID)
                : [],
        ];
    }
}

/**
 * Compatibilidad con cualquier llamada histórica a este helper: ya no añade el
 * shortcode al final. Reinstala la página en estado canónico.
 */
if ( ! function_exists('eventosapp_installation_append_shortcode_safely') ) {
    function eventosapp_installation_append_shortcode_safely(WP_Post $page, array $definition) {
        $result = eventosapp_installation_normalize_managed_page($page, $definition);
        return is_wp_error($result) ? $result : absint($result['page_id'] ?? $page->ID);
    }
}
