<?php
/**
 * EventosApp – Widgets Elementor para Autogestión del Asistente
 *
 * Widgets:
 * - EventosApp Autogestión: Búsqueda e Impresión
 * - EventosApp Autogestión: Botón Pantalla Completa
 * - EventosApp Autogestión: Kiosko / Impresión Silenciosa
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('eventosapp_self_checkin_elementor_category') ) {
    function eventosapp_self_checkin_elementor_category( $elements_manager ) {
        if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
            return;
        }

        $elements_manager->add_category(
            'eventosapp',
            [
                'title' => 'EventosApp',
                'icon'  => 'fa fa-plug',
            ]
        );
    }
}
add_action('elementor/elements/categories_registered', 'eventosapp_self_checkin_elementor_category');

if ( ! function_exists('eventosapp_self_checkin_elementor_common_dimension_units') ) {
    function eventosapp_self_checkin_elementor_common_dimension_units() {
        return [ 'px', '%', 'em', 'rem', 'vw' ];
    }
}


if ( ! function_exists('eventosapp_self_checkin_elementor_bootstrap_module') ) {
    /**
     * Asegura que las funciones base del módulo de autogestión estén disponibles
     * también cuando Elementor renderiza el widget desde su editor/AJAX.
     */
    function eventosapp_self_checkin_elementor_bootstrap_module() {
        if ( function_exists('eventosapp_self_checkin_render_main_ui') && function_exists('eventosapp_self_checkin_render_launcher_block') && function_exists('eventosapp_self_checkin_render_fullscreen_button') ) {
            return true;
        }

        $candidates = [];
        $current_dir = defined('__DIR__') ? __DIR__ : dirname(__FILE__);

        $candidates[] = $current_dir . '/eventosapp-self-checkin.php';
        $candidates[] = dirname( $current_dir ) . '/frontend/eventosapp-self-checkin.php';
        $candidates[] = dirname( dirname( $current_dir ) ) . '/includes/frontend/eventosapp-self-checkin.php';
        $candidates[] = dirname( dirname( $current_dir ) ) . '/eventosapp-self-checkin.php';

        if ( defined('EVENTOSAPP_PLUGIN_DIR') ) {
            $candidates[] = trailingslashit( EVENTOSAPP_PLUGIN_DIR ) . 'includes/frontend/eventosapp-self-checkin.php';
            $candidates[] = trailingslashit( EVENTOSAPP_PLUGIN_DIR ) . 'eventosapp-self-checkin.php';
        }

        if ( function_exists('plugin_dir_path') ) {
            $plugin_root = dirname( dirname( $current_dir ) );
            $candidates[] = trailingslashit( $plugin_root ) . 'includes/frontend/eventosapp-self-checkin.php';
            $candidates[] = trailingslashit( $plugin_root ) . 'eventosapp-self-checkin.php';
        }

        $candidates = array_values( array_unique( array_filter( $candidates ) ) );
        foreach ( $candidates as $file ) {
            if ( is_string( $file ) && file_exists( $file ) && is_readable( $file ) ) {
                require_once $file;
                if ( function_exists('eventosapp_self_checkin_render_main_ui') && function_exists('eventosapp_self_checkin_render_launcher_block') && function_exists('eventosapp_self_checkin_render_fullscreen_button') ) {
                    return true;
                }
            }
        }

        return function_exists('eventosapp_self_checkin_render_main_ui') && function_exists('eventosapp_self_checkin_render_launcher_block') && function_exists('eventosapp_self_checkin_render_fullscreen_button');
    }
}

if ( ! function_exists('eventosapp_self_checkin_elementor_get_event_options') ) {
    function eventosapp_self_checkin_elementor_get_event_options() {
        $options = [
            0 => 'Evento activo del dashboard',
        ];

        if ( ! function_exists('get_posts') ) {
            return $options;
        }

        $events = get_posts([
            'post_type'      => 'eventosapp_event',
            'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private' ],
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ]);

        foreach ( (array) $events as $event_id ) {
            $event_id = absint( $event_id );
            if ( $event_id ) {
                $options[ $event_id ] = get_the_title( $event_id ) . ' (#' . $event_id . ')';
            }
        }

        return $options;
    }
}

if ( ! function_exists('eventosapp_self_checkin_elementor_resolve_event_id') ) {
    function eventosapp_self_checkin_elementor_resolve_event_id( $settings ) {
        eventosapp_self_checkin_elementor_bootstrap_module();

        $settings = is_array( $settings ) ? $settings : [];
        $use_active_event = ! isset( $settings['use_active_event'] ) || $settings['use_active_event'] === 'yes';

        if ( $use_active_event && function_exists('eventosapp_self_checkin_get_active_event_id') ) {
            return absint( eventosapp_self_checkin_get_active_event_id( 0 ) );
        }

        $event_id = isset( $settings['event_id'] ) ? absint( $settings['event_id'] ) : 0;
        if ( $event_id ) {
            return $event_id;
        }

        if ( function_exists('eventosapp_self_checkin_get_active_event_id') ) {
            return absint( eventosapp_self_checkin_get_active_event_id( 0 ) );
        }

        return 0;
    }
}

if ( ! function_exists('eventosapp_self_checkin_register_elementor_widgets') ) {
    function eventosapp_self_checkin_register_elementor_widgets( $widgets_manager = null ) {
        static $registered = false;

        if ( $registered || ! class_exists('\Elementor\Widget_Base') ) {
            return;
        }

        if ( ! class_exists('EventosApp_Self_Checkin_UI_Elementor_Widget') ) {
            class EventosApp_Self_Checkin_UI_Elementor_Widget extends \Elementor\Widget_Base {
                public function get_name() {
                    return 'eventosapp_self_checkin_ui';
                }

                public function get_title() {
                    return 'EventosApp Autogestión - Búsqueda e Impresión';
                }

                public function get_icon() {
                    return 'eicon-search-results';
                }

                public function get_categories() {
                    return [ 'eventosapp' ];
                }

                public function get_keywords() {
                    return [ 'eventosapp', 'autogestion', 'checkin', 'kiosko', 'escarapela' ];
                }

                public function get_style_depends() {
                    eventosapp_self_checkin_elementor_bootstrap_module();
                    if ( function_exists('eventosapp_self_checkin_enqueue_assets') ) {
                        eventosapp_self_checkin_enqueue_assets();
                    }
                    return [ 'eventosapp-self-checkin' ];
                }

                public function get_script_depends() {
                    eventosapp_self_checkin_elementor_bootstrap_module();
                    if ( function_exists('eventosapp_self_checkin_enqueue_assets') ) {
                        eventosapp_self_checkin_enqueue_assets();
                    }
                    return [ 'eventosapp-self-checkin' ];
                }

                protected function register_controls() {
                    $this->start_controls_section('section_content', [
                        'label' => 'Contenido',
                        'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                    ]);

                    $this->add_control('use_active_event', [
                        'label'        => 'Usar evento activo del dashboard',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                        'description'  => 'Activado: el widget carga dinámicamente el evento que el usuario escogió en el dashboard de gestión.',
                    ]);

                    $this->add_control('event_id', [
                        'label'       => 'Evento fijo de respaldo',
                        'type'        => \Elementor\Controls_Manager::SELECT2,
                        'default'     => 0,
                        'options'     => eventosapp_self_checkin_elementor_get_event_options(),
                        'label_block' => true,
                        'description' => 'Úsalo solo si necesitas dejar este widget amarrado a un evento específico. Para operación normal debe quedar activo el evento del dashboard.',
                        'condition'   => [ 'use_active_event!' => 'yes' ],
                    ]);

                    $this->add_control('show_event_badge', [
                        'label'        => 'Mostrar etiqueta de evento activo',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                    ]);

                    $this->add_control('logo', [
                        'label'       => 'Logo superior',
                        'type'        => \Elementor\Controls_Manager::MEDIA,
                        'media_types' => [ 'image' ],
                        'description' => 'Sube el logo que se mostrará arriba del widget de búsqueda e impresión.',
                    ]);

                    $this->add_control('logo_alt', [
                        'label'       => 'Texto alternativo del logo',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => '',
                        'label_block' => true,
                        'condition'   => [ 'logo[url]!' => '' ],
                    ]);

                    $this->add_control('show_kicker', [
                        'label'        => 'Mostrar texto superior',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                    ]);

                    $this->add_control('kicker', [
                        'label'       => 'Texto superior',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => 'Autogestión',
                        'label_block' => true,
                    ]);

                    $this->add_control('show_title', [
                        'label'        => 'Mostrar título',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                    ]);

                    $this->add_control('title', [
                        'label'       => 'Título',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => 'Identificación del asistente',
                        'label_block' => true,
                    ]);

                    $this->add_control('show_subtitle', [
                        'label'        => 'Mostrar subtítulo',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                    ]);

                    $this->add_control('subtitle', [
                        'label'       => 'Subtítulo',
                        'type'        => \Elementor\Controls_Manager::TEXTAREA,
                        'default'     => 'Ingresa la cédula, selecciona el resultado correcto, confirma la información e imprime la escarapela.',
                        'label_block' => true,
                    ]);

                    $this->add_control('field_label', [
                        'label'       => 'Etiqueta del campo',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => 'Cédula de ciudadanía',
                        'label_block' => true,
                    ]);

                    $this->add_control('placeholder', [
                        'label'       => 'Placeholder del campo',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => 'Ej: 1234567890',
                        'label_block' => true,
                    ]);

                    $this->add_control('search_label', [
                        'label'   => 'Texto botón Buscar',
                        'type'    => \Elementor\Controls_Manager::TEXT,
                        'default' => 'Buscar',
                    ]);

                    $this->add_control('clear_label', [
                        'label'   => 'Texto botón Limpiar',
                        'type'    => \Elementor\Controls_Manager::TEXT,
                        'default' => 'Limpiar',
                    ]);

                    $this->add_control('confirm_label', [
                        'label'   => 'Texto botón Confirmar',
                        'type'    => \Elementor\Controls_Manager::TEXT,
                        'default' => 'Confirmar',
                    ]);

                    $this->add_control('confirm_heading', [
                        'label'       => 'Título de confirmación',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => 'Confirma tu información',
                        'label_block' => true,
                    ]);

                    $this->add_control('print_label', [
                        'label'       => 'Texto botón imprimir',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => 'Imprimir escarapela',
                        'label_block' => true,
                    ]);

                    $this->add_control('show_kiosk_hint', [
                        'label'        => 'Mostrar aviso de pantalla completa',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                    ]);

                    $this->add_control('enable_kiosk_lock', [
                        'label'        => 'Bloqueo táctil asistido',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Activo',
                        'label_off'    => 'Inactivo',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                        'description'  => 'Mantiene el bloqueo de gestos atrás/adelante dentro del módulo.',
                    ]);

                    $this->add_control('kiosk_hint_text', [
                        'label'       => 'Texto aviso kiosko',
                        'type'        => \Elementor\Controls_Manager::TEXTAREA,
                        'default'     => 'Modo kiosko asistido activo: los gestos de atrás/adelante quedan bloqueados dentro de la página.',
                        'label_block' => true,
                    ]);

                    $this->add_control('fullscreen_label', [
                        'label'   => 'Texto botón pantalla completa',
                        'type'    => \Elementor\Controls_Manager::TEXT,
                        'default' => 'Activar pantalla completa',
                        'description' => 'Se conserva por compatibilidad. El botón manual ahora se agrega con el widget separado “EventosApp Autogestión - Pantalla Completa”.',
                    ]);

                    $this->add_control('heading_touch_keyboard', [
                        'label' => 'Teclado táctil',
                        'type'  => \Elementor\Controls_Manager::HEADING,
                        'separator' => 'before',
                    ]);

                    $this->add_control('show_touch_keyboard', [
                        'label'        => 'Mostrar teclado táctil',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                    ]);

                    $this->add_control('touch_keyboard_title', [
                        'label'       => 'Título teclado',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => 'Teclado táctil',
                        'label_block' => true,
                        'condition'   => [ 'show_touch_keyboard' => 'yes' ],
                    ]);

                    $this->add_control('touch_keyboard_numbers_label', [
                        'label'     => 'Etiqueta modo números',
                        'type'      => \Elementor\Controls_Manager::TEXT,
                        'default'   => 'Números',
                        'condition' => [ 'show_touch_keyboard' => 'yes' ],
                    ]);

                    $this->add_control('touch_keyboard_letters_label', [
                        'label'     => 'Etiqueta modo letras',
                        'type'      => \Elementor\Controls_Manager::TEXT,
                        'default'   => 'Letras',
                        'condition' => [ 'show_touch_keyboard' => 'yes' ],
                    ]);

                    $this->add_control('touch_keyboard_backspace_label', [
                        'label'     => 'Etiqueta borrar',
                        'type'      => \Elementor\Controls_Manager::TEXT,
                        'default'   => 'Borrar',
                        'condition' => [ 'show_touch_keyboard' => 'yes' ],
                    ]);

                    $this->add_control('touch_keyboard_clear_label', [
                        'label'     => 'Etiqueta limpiar teclado',
                        'type'      => \Elementor\Controls_Manager::TEXT,
                        'default'   => 'Limpiar',
                        'condition' => [ 'show_touch_keyboard' => 'yes' ],
                    ]);

                    $this->end_controls_section();

                    $this->register_container_style_controls();
                    $this->register_logo_style_controls();
                    $this->register_header_style_controls();
                    $this->register_event_badge_style_controls();
                    $this->register_panel_style_controls();
                    $this->register_input_style_controls();
                    $this->register_keyboard_style_controls();
                    $this->register_button_style_controls();
                    $this->register_result_style_controls();
                    $this->register_confirm_style_controls();
                    $this->register_kiosk_hint_style_controls();
                }

                private function register_container_style_controls() {
                    $this->start_controls_section('section_style_container', [
                        'label' => 'Contenedor principal',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_responsive_control('wrap_max_width', [
                        'label' => 'Ancho máximo',
                        'type'  => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => [ 'px', '%', 'vw' ],
                        'range' => [
                            'px' => [ 'min' => 320, 'max' => 1800 ],
                            '%'  => [ 'min' => 20, 'max' => 100 ],
                            'vw' => [ 'min' => 20, 'max' => 100 ],
                        ],
                        'selectors' => [
                            '{{WRAPPER}} .evsc-wrap' => 'max-width: {{SIZE}}{{UNIT}};',
                        ],
                    ]);

                    $this->add_control('wrap_background', [
                        'label' => 'Fondo',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [
                            '{{WRAPPER}} .evsc-wrap' => 'background-color: {{VALUE}};',
                        ],
                    ]);

                    $this->add_responsive_control('wrap_padding', [
                        'label' => 'Relleno',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [
                            '{{WRAPPER}} .evsc-wrap' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                        ],
                    ]);

                    $this->add_responsive_control('wrap_margin', [
                        'label' => 'Margen',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [
                            '{{WRAPPER}} .evsc-wrap' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                        ],
                    ]);

                    $this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
                        'name'     => 'wrap_border',
                        'selector' => '{{WRAPPER}} .evsc-wrap',
                    ]);

                    $this->add_responsive_control('wrap_border_radius', [
                        'label' => 'Radio de borde',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => [ 'px', '%', 'em', 'rem' ],
                        'selectors' => [
                            '{{WRAPPER}} .evsc-wrap' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                        ],
                    ]);

                    $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
                        'name'     => 'wrap_shadow',
                        'selector' => '{{WRAPPER}} .evsc-wrap',
                    ]);

                    $this->end_controls_section();
                }

                private function register_logo_style_controls() {
                    $this->start_controls_section('section_style_logo', [
                        'label' => 'Logo superior',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_responsive_control('logo_width', [
                        'label' => 'Ancho máximo',
                        'type'  => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => [ 'px', '%', 'vw' ],
                        'range' => [
                            'px' => [ 'min' => 40, 'max' => 600 ],
                            '%'  => [ 'min' => 10, 'max' => 100 ],
                            'vw' => [ 'min' => 10, 'max' => 100 ],
                        ],
                        'selectors' => [
                            '{{WRAPPER}} .evsc-logo' => 'max-width: {{SIZE}}{{UNIT}};',
                        ],
                    ]);

                    $this->add_responsive_control('logo_max_height', [
                        'label' => 'Altura máxima',
                        'type'  => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => [ 'px', 'vh' ],
                        'range' => [ 'px' => [ 'min' => 30, 'max' => 300 ] ],
                        'selectors' => [
                            '{{WRAPPER}} .evsc-logo' => 'max-height: {{SIZE}}{{UNIT}};',
                        ],
                    ]);

                    $this->add_responsive_control('logo_align', [
                        'label' => 'Alineación',
                        'type'  => \Elementor\Controls_Manager::CHOOSE,
                        'options' => [
                            'flex-start' => [ 'title' => 'Izquierda', 'icon' => 'eicon-text-align-left' ],
                            'center'     => [ 'title' => 'Centro', 'icon' => 'eicon-text-align-center' ],
                            'flex-end'   => [ 'title' => 'Derecha', 'icon' => 'eicon-text-align-right' ],
                        ],
                        'default' => 'center',
                        'selectors' => [
                            '{{WRAPPER}} .evsc-logo-wrap' => 'justify-content: {{VALUE}};',
                        ],
                    ]);

                    $this->add_responsive_control('logo_margin', [
                        'label' => 'Margen',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [
                            '{{WRAPPER}} .evsc-logo-wrap' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                        ],
                    ]);

                    $this->end_controls_section();
                }

                private function register_header_style_controls() {
                    $this->start_controls_section('section_style_header', [
                        'label' => 'Textos superiores',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_control('kicker_color', [
                        'label' => 'Color texto superior',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-kicker' => 'color: {{VALUE}};' ],
                    ]);

                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'kicker_typography',
                        'selector' => '{{WRAPPER}} .evsc-kicker',
                    ]);

                    $this->add_responsive_control('kicker_margin', [
                        'label' => 'Margen texto superior',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-kicker' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);

                    $this->add_control('title_color', [
                        'label' => 'Color título',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-title' => 'color: {{VALUE}};' ],
                    ]);

                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'title_typography',
                        'selector' => '{{WRAPPER}} .evsc-title',
                    ]);

                    $this->add_responsive_control('title_margin', [
                        'label' => 'Margen título',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-title' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);

                    $this->add_control('subtitle_color', [
                        'label' => 'Color subtítulo',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-subtitle' => 'color: {{VALUE}};' ],
                    ]);

                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'subtitle_typography',
                        'selector' => '{{WRAPPER}} .evsc-subtitle',
                    ]);

                    $this->add_responsive_control('subtitle_margin', [
                        'label' => 'Margen subtítulo',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-subtitle' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);

                    $this->end_controls_section();
                }

                private function register_event_badge_style_controls() {
                    $this->start_controls_section('section_style_event_badge', [
                        'label' => 'Etiqueta evento activo',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_control('event_badge_color', [
                        'label' => 'Color texto',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-event' => 'color: {{VALUE}};' ],
                    ]);

                    $this->add_control('event_badge_background', [
                        'label' => 'Fondo',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-event' => 'background-color: {{VALUE}};' ],
                    ]);

                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'event_badge_typography',
                        'selector' => '{{WRAPPER}} .evsc-event',
                    ]);

                    $this->add_responsive_control('event_badge_padding', [
                        'label' => 'Relleno',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-event' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);

                    $this->add_responsive_control('event_badge_radius', [
                        'label' => 'Radio',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => [ 'px', '%', 'em', 'rem' ],
                        'selectors' => [ '{{WRAPPER}} .evsc-event' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);

                    $this->add_responsive_control('event_badge_margin', [
                        'label' => 'Margen',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-event' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);

                    $this->end_controls_section();
                }

                private function register_panel_style_controls() {
                    $this->start_controls_section('section_style_panel', [
                        'label' => 'Panel de búsqueda',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_control('panel_background', [
                        'label' => 'Fondo panel',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-panel' => 'background-color: {{VALUE}};' ],
                    ]);

                    $this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
                        'name'     => 'panel_border',
                        'selector' => '{{WRAPPER}} .evsc-panel',
                    ]);

                    $this->add_responsive_control('panel_radius', [
                        'label' => 'Radio panel',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => [ 'px', '%', 'em', 'rem' ],
                        'selectors' => [ '{{WRAPPER}} .evsc-panel' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);

                    $this->add_responsive_control('panel_padding', [
                        'label' => 'Relleno panel',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-panel' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);

                    $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
                        'name'     => 'panel_shadow',
                        'selector' => '{{WRAPPER}} .evsc-panel',
                    ]);

                    $this->add_responsive_control('search_gap', [
                        'label' => 'Separación campo/botón',
                        'type'  => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => [ 'px', 'em', 'rem' ],
                        'range' => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
                        'selectors' => [ '{{WRAPPER}} .evsc-search' => 'gap: {{SIZE}}{{UNIT}};' ],
                    ]);

                    $this->add_responsive_control('search_button_width', [
                        'label' => 'Ancho botón buscar',
                        'type'  => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => [ 'px', '%', 'vw' ],
                        'range' => [
                            'px' => [ 'min' => 120, 'max' => 760 ],
                            '%'  => [ 'min' => 20, 'max' => 100 ],
                            'vw' => [ 'min' => 20, 'max' => 100 ],
                        ],
                        'selectors' => [ '{{WRAPPER}} .evsc-search .evsc-js-search' => 'width: {{SIZE}}{{UNIT}}; align-self:center;' ],
                    ]);

                    $this->end_controls_section();
                }

                private function register_input_style_controls() {
                    $this->start_controls_section('section_style_input', [
                        'label' => 'Campo de cédula',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_control('label_color', [
                        'label' => 'Color etiqueta',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-field label' => 'color: {{VALUE}};' ],
                    ]);

                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'label_typography',
                        'selector' => '{{WRAPPER}} .evsc-field label',
                    ]);

                    $this->add_responsive_control('label_margin', [
                        'label' => 'Margen etiqueta',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-field label' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);

                    $this->add_control('input_color', [
                        'label' => 'Color texto campo',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-input' => 'color: {{VALUE}};' ],
                    ]);

                    $this->add_control('input_background', [
                        'label' => 'Fondo campo',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-input' => 'background-color: {{VALUE}};' ],
                    ]);

                    $this->add_control('input_placeholder_color', [
                        'label' => 'Color placeholder',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-input::placeholder' => 'color: {{VALUE}}; opacity:1;' ],
                    ]);

                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'input_typography',
                        'selector' => '{{WRAPPER}} .evsc-input',
                    ]);

                    $this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
                        'name'     => 'input_border',
                        'selector' => '{{WRAPPER}} .evsc-input',
                    ]);

                    $this->add_control('input_focus_border_color', [
                        'label' => 'Color borde enfocado',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-input:focus' => 'border-color: {{VALUE}};' ],
                    ]);

                    $this->add_responsive_control('input_radius', [
                        'label' => 'Radio campo',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => [ 'px', '%', 'em', 'rem' ],
                        'selectors' => [ '{{WRAPPER}} .evsc-input' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);

                    $this->add_responsive_control('input_padding', [
                        'label' => 'Relleno campo',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-input' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);

                    $this->add_responsive_control('input_min_height', [
                        'label' => 'Altura mínima campo',
                        'type'  => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => [ 'px', 'em', 'rem' ],
                        'range' => [ 'px' => [ 'min' => 40, 'max' => 180 ] ],
                        'selectors' => [ '{{WRAPPER}} .evsc-input' => 'min-height: {{SIZE}}{{UNIT}};' ],
                    ]);

                    $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
                        'name'     => 'input_shadow',
                        'selector' => '{{WRAPPER}} .evsc-input',
                    ]);

                    $this->end_controls_section();
                }


                private function register_keyboard_style_controls() {
                    $this->start_controls_section('section_style_keyboard', [
                        'label' => 'Teclado táctil',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_control('keyboard_background', [
                        'label' => 'Fondo teclado',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-keyboard' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
                        'name'     => 'keyboard_border',
                        'selector' => '{{WRAPPER}} .evsc-keyboard',
                    ]);
                    $this->add_responsive_control('keyboard_radius', [
                        'label' => 'Radio teclado',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => [ 'px', '%', 'em', 'rem' ],
                        'selectors' => [ '{{WRAPPER}} .evsc-keyboard' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_responsive_control('keyboard_padding', [
                        'label' => 'Relleno teclado',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-keyboard' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_responsive_control('keyboard_gap', [
                        'label' => 'Separación teclas',
                        'type'  => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => [ 'px', 'em', 'rem' ],
                        'range' => [ 'px' => [ 'min' => 0, 'max' => 40 ] ],
                        'selectors' => [ '{{WRAPPER}} .evsc-keyboard-grid' => 'gap: {{SIZE}}{{UNIT}};' ],
                    ]);
                    $this->add_control('keyboard_title_color', [
                        'label' => 'Color título',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-keyboard-title' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'keyboard_title_typography',
                        'selector' => '{{WRAPPER}} .evsc-keyboard-title',
                    ]);

                    $this->add_control('heading_keyboard_modes', [
                        'label' => 'Selector números / letras',
                        'type'  => \Elementor\Controls_Manager::HEADING,
                        'separator' => 'before',
                    ]);
                    $this->add_control('keyboard_mode_color', [
                        'label' => 'Texto modo',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-keyboard-mode' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_control('keyboard_mode_background', [
                        'label' => 'Fondo modo',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-keyboard-mode' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->add_control('keyboard_mode_active_color', [
                        'label' => 'Texto modo activo',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-keyboard-mode.is-active' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_control('keyboard_mode_active_background', [
                        'label' => 'Fondo modo activo',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-keyboard-mode.is-active' => 'background-color: {{VALUE}};' ],
                    ]);

                    $this->add_control('heading_keyboard_keys', [
                        'label' => 'Teclas',
                        'type'  => \Elementor\Controls_Manager::HEADING,
                        'separator' => 'before',
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'keyboard_key_typography',
                        'selector' => '{{WRAPPER}} .evsc-key',
                    ]);
                    $this->add_control('keyboard_key_color', [
                        'label' => 'Texto tecla',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-key' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_control('keyboard_key_background', [
                        'label' => 'Fondo tecla',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-key' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->add_control('keyboard_action_key_color', [
                        'label' => 'Texto tecla acción',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-key-action' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_control('keyboard_action_key_background', [
                        'label' => 'Fondo tecla acción',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-key-action' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->add_responsive_control('keyboard_key_min_height', [
                        'label' => 'Alto mínimo tecla',
                        'type'  => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => [ 'px', 'em', 'rem' ],
                        'range' => [ 'px' => [ 'min' => 34, 'max' => 120 ] ],
                        'selectors' => [ '{{WRAPPER}} .evsc-key' => 'min-height: {{SIZE}}{{UNIT}};' ],
                    ]);
                    $this->add_responsive_control('keyboard_key_radius', [
                        'label' => 'Radio tecla',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => [ 'px', '%', 'em', 'rem' ],
                        'selectors' => [ '{{WRAPPER}} .evsc-key' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
                        'name'     => 'keyboard_key_shadow',
                        'selector' => '{{WRAPPER}} .evsc-key',
                    ]);

                    $this->end_controls_section();
                }

                private function register_button_style_controls() {
                    $this->start_controls_section('section_style_buttons', [
                        'label' => 'Botones',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'buttons_typography',
                        'selector' => '{{WRAPPER}} .evsc-btn',
                    ]);

                    $this->add_responsive_control('buttons_padding', [
                        'label' => 'Relleno general',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-btn' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);

                    $this->add_responsive_control('buttons_radius', [
                        'label' => 'Radio general',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => [ 'px', '%', 'em', 'rem' ],
                        'selectors' => [ '{{WRAPPER}} .evsc-btn' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);

                    $this->add_responsive_control('buttons_min_height', [
                        'label' => 'Altura mínima general',
                        'type'  => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => [ 'px', 'em', 'rem' ],
                        'range' => [ 'px' => [ 'min' => 36, 'max' => 180 ] ],
                        'selectors' => [ '{{WRAPPER}} .evsc-btn' => 'min-height: {{SIZE}}{{UNIT}};' ],
                    ]);

                    $this->add_control('heading_search_button', [
                        'label' => 'Botón Buscar',
                        'type'  => \Elementor\Controls_Manager::HEADING,
                        'separator' => 'before',
                    ]);

                    $this->start_controls_tabs('search_button_tabs');
                    $this->start_controls_tab('search_button_normal', [ 'label' => 'Normal' ]);
                    $this->add_control('search_button_color', [
                        'label' => 'Texto',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-btn-primary' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_control('search_button_background', [
                        'label' => 'Fondo',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-btn-primary' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->end_controls_tab();
                    $this->start_controls_tab('search_button_hover', [ 'label' => 'Hover' ]);
                    $this->add_control('search_button_hover_color', [
                        'label' => 'Texto hover',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-btn-primary:hover' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_control('search_button_hover_background', [
                        'label' => 'Fondo hover',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-btn-primary:hover' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->end_controls_tab();
                    $this->end_controls_tabs();

                    $this->add_control('heading_light_button', [
                        'label' => 'Botones claros / selección',
                        'type'  => \Elementor\Controls_Manager::HEADING,
                        'separator' => 'before',
                    ]);

                    $this->add_control('light_button_color', [
                        'label' => 'Texto',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-btn-light' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_control('light_button_background', [
                        'label' => 'Fondo',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-btn-light' => 'background-color: {{VALUE}};' ],
                    ]);

                    $this->add_control('heading_success_button', [
                        'label' => 'Botones confirmar / imprimir',
                        'type'  => \Elementor\Controls_Manager::HEADING,
                        'separator' => 'before',
                    ]);

                    $this->start_controls_tabs('success_button_tabs');
                    $this->start_controls_tab('success_button_normal', [ 'label' => 'Normal' ]);
                    $this->add_control('success_button_color', [
                        'label' => 'Texto',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-btn-success' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_control('success_button_background', [
                        'label' => 'Fondo',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-btn-success' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->end_controls_tab();
                    $this->start_controls_tab('success_button_hover', [ 'label' => 'Hover' ]);
                    $this->add_control('success_button_hover_color', [
                        'label' => 'Texto hover',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-btn-success:hover' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_control('success_button_hover_background', [
                        'label' => 'Fondo hover',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-btn-success:hover' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->end_controls_tab();
                    $this->end_controls_tabs();

                    $this->add_responsive_control('actions_gap', [
                        'label' => 'Separación entre botones',
                        'type'  => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => [ 'px', 'em', 'rem' ],
                        'range' => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
                        'selectors' => [ '{{WRAPPER}} .evsc-actions' => 'gap: {{SIZE}}{{UNIT}};' ],
                    ]);

                    $this->end_controls_section();
                }

                private function register_result_style_controls() {
                    $this->start_controls_section('section_style_results', [
                        'label' => 'Mensajes y resultados',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_control('status_background', [
                        'label' => 'Fondo mensaje normal',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-status' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->add_control('status_color', [
                        'label' => 'Texto mensaje normal',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-status' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_control('status_ok_background', [
                        'label' => 'Fondo mensaje OK',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-status.ok' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->add_control('status_ok_color', [
                        'label' => 'Texto mensaje OK',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-status.ok' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_control('status_error_background', [
                        'label' => 'Fondo mensaje error',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-status.err' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->add_control('status_error_color', [
                        'label' => 'Texto mensaje error',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-status.err' => 'color: {{VALUE}};' ],
                    ]);

                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'status_typography',
                        'selector' => '{{WRAPPER}} .evsc-status',
                    ]);

                    $this->add_control('heading_result_card', [
                        'label' => 'Tarjeta de resultado',
                        'type'  => \Elementor\Controls_Manager::HEADING,
                        'separator' => 'before',
                    ]);

                    $this->add_control('result_background', [
                        'label' => 'Fondo tarjeta',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-result' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->add_control('result_selected_background', [
                        'label' => 'Fondo seleccionado',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-result.is-selected' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
                        'name'     => 'result_border',
                        'selector' => '{{WRAPPER}} .evsc-result',
                    ]);
                    $this->add_control('result_selected_border_color', [
                        'label' => 'Borde seleccionado',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-result.is-selected' => 'border-color: {{VALUE}};' ],
                    ]);
                    $this->add_responsive_control('result_radius', [
                        'label' => 'Radio tarjeta',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => [ 'px', '%', 'em', 'rem' ],
                        'selectors' => [ '{{WRAPPER}} .evsc-result' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_responsive_control('result_padding', [
                        'label' => 'Relleno tarjeta',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-result' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
                        'name'     => 'result_shadow',
                        'selector' => '{{WRAPPER}} .evsc-result',
                    ]);

                    $this->add_control('result_name_color', [
                        'label' => 'Color nombre',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-result-name' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'result_name_typography',
                        'selector' => '{{WRAPPER}} .evsc-result-name',
                    ]);
                    $this->add_control('result_meta_color', [
                        'label' => 'Color metadatos',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-result-meta' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'result_meta_typography',
                        'selector' => '{{WRAPPER}} .evsc-result-meta',
                    ]);

                    $this->add_control('chip_background', [
                        'label' => 'Fondo viñetas',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-chip' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->add_control('chip_color', [
                        'label' => 'Texto viñetas',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-chip' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'chip_typography',
                        'selector' => '{{WRAPPER}} .evsc-chip',
                    ]);

                    $this->end_controls_section();
                }

                private function register_confirm_style_controls() {
                    $this->start_controls_section('section_style_confirm', [
                        'label' => 'Bloque de confirmación',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_control('confirm_background', [
                        'label' => 'Fondo bloque',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-confirm' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->add_responsive_control('confirm_padding', [
                        'label' => 'Relleno bloque',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-confirm' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_responsive_control('confirm_radius', [
                        'label' => 'Radio bloque',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => [ 'px', '%', 'em', 'rem' ],
                        'selectors' => [ '{{WRAPPER}} .evsc-confirm' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
                        'name'     => 'confirm_shadow',
                        'selector' => '{{WRAPPER}} .evsc-confirm',
                    ]);
                    $this->add_control('confirm_heading_color', [
                        'label' => 'Color título',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-confirm h3' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'confirm_heading_typography',
                        'selector' => '{{WRAPPER}} .evsc-confirm h3',
                    ]);
                    $this->add_responsive_control('confirm_grid_gap', [
                        'label' => 'Separación datos',
                        'type'  => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => [ 'px', 'em', 'rem' ],
                        'range' => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
                        'selectors' => [ '{{WRAPPER}} .evsc-confirm-grid' => 'gap: {{SIZE}}{{UNIT}};' ],
                    ]);
                    $this->add_control('data_background', [
                        'label' => 'Fondo dato',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-data' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
                        'name'     => 'data_border',
                        'selector' => '{{WRAPPER}} .evsc-data',
                    ]);
                    $this->add_responsive_control('data_radius', [
                        'label' => 'Radio dato',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => [ 'px', '%', 'em', 'rem' ],
                        'selectors' => [ '{{WRAPPER}} .evsc-data' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_responsive_control('data_padding', [
                        'label' => 'Relleno dato',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-data' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_control('data_label_color', [
                        'label' => 'Color etiqueta dato',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-data-label' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'data_label_typography',
                        'selector' => '{{WRAPPER}} .evsc-data-label',
                    ]);
                    $this->add_control('data_value_color', [
                        'label' => 'Color valor dato',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-data-value' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'data_value_typography',
                        'selector' => '{{WRAPPER}} .evsc-data-value',
                    ]);

                    $this->end_controls_section();
                }

                private function register_kiosk_hint_style_controls() {
                    $this->start_controls_section('section_style_kiosk_hint', [
                        'label' => 'Aviso pantalla completa',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_control('kiosk_hint_background', [
                        'label' => 'Fondo aviso',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-kiosk-hint' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->add_control('kiosk_hint_color', [
                        'label' => 'Texto aviso',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-kiosk-hint' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'kiosk_hint_typography',
                        'selector' => '{{WRAPPER}} .evsc-kiosk-hint',
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
                        'name'     => 'kiosk_hint_border',
                        'selector' => '{{WRAPPER}} .evsc-kiosk-hint',
                    ]);
                    $this->add_responsive_control('kiosk_hint_radius', [
                        'label' => 'Radio aviso',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => [ 'px', '%', 'em', 'rem' ],
                        'selectors' => [ '{{WRAPPER}} .evsc-kiosk-hint' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_responsive_control('kiosk_hint_padding', [
                        'label' => 'Relleno aviso',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-kiosk-hint' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->end_controls_section();
                }

                protected function render() {
                    if ( ! eventosapp_self_checkin_elementor_bootstrap_module() || ! function_exists('eventosapp_self_checkin_render_main_ui') ) {
                        echo '<div class="evsc-alert evsc-alert-error">El módulo de autogestión no está cargado. Verifica que el archivo <code>includes/frontend/eventosapp-self-checkin.php</code> esté instalado.</div>';
                        return;
                    }

                    $settings = $this->get_settings_for_display();
                    $event_id = eventosapp_self_checkin_elementor_resolve_event_id( $settings );

                    echo eventosapp_self_checkin_render_main_ui( $event_id, [
                        'show_event_badge'  => ( $settings['show_event_badge'] ?? 'yes' ) === 'yes',
                        'show_kicker'       => ( $settings['show_kicker'] ?? 'yes' ) === 'yes',
                        'show_title'        => ( $settings['show_title'] ?? 'yes' ) === 'yes',
                        'show_subtitle'     => ( $settings['show_subtitle'] ?? 'yes' ) === 'yes',
                        'show_kiosk_hint'   => ( $settings['show_kiosk_hint'] ?? 'yes' ) === 'yes',
                        'enable_kiosk_lock' => ( $settings['enable_kiosk_lock'] ?? 'yes' ) === 'yes',
                        'show_touch_keyboard' => ( $settings['show_touch_keyboard'] ?? 'yes' ) === 'yes',
                        'touch_keyboard_title' => $settings['touch_keyboard_title'] ?? 'Teclado táctil',
                        'touch_keyboard_numbers_label' => $settings['touch_keyboard_numbers_label'] ?? 'Números',
                        'touch_keyboard_letters_label' => $settings['touch_keyboard_letters_label'] ?? 'Letras',
                        'touch_keyboard_backspace_label' => $settings['touch_keyboard_backspace_label'] ?? 'Borrar',
                        'touch_keyboard_clear_label' => $settings['touch_keyboard_clear_label'] ?? 'Limpiar',
                        'kicker'            => $settings['kicker'] ?? 'Autogestión',
                        'title'             => $settings['title'] ?? 'Identificación del asistente',
                        'subtitle'          => $settings['subtitle'] ?? '',
                        'field_label'       => $settings['field_label'] ?? 'Cédula de ciudadanía',
                        'placeholder'       => $settings['placeholder'] ?? 'Ej: 1234567890',
                        'search_label'      => $settings['search_label'] ?? 'Buscar',
                        'clear_label'       => $settings['clear_label'] ?? 'Limpiar',
                        'confirm_label'     => $settings['confirm_label'] ?? 'Confirmar',
                        'confirm_heading'   => $settings['confirm_heading'] ?? 'Confirma tu información',
                        'print_label'       => $settings['print_label'] ?? 'Imprimir escarapela',
                        'kiosk_hint_text'   => $settings['kiosk_hint_text'] ?? '',
                        'fullscreen_label'  => $settings['fullscreen_label'] ?? 'Activar pantalla completa',
                        'logo_url'          => ! empty( $settings['logo']['url'] ) ? $settings['logo']['url'] : '',
                        'logo_alt'          => $settings['logo_alt'] ?? '',
                    ] );
                }
            }
        }


        if ( ! class_exists('EventosApp_Self_Checkin_Fullscreen_Elementor_Widget') ) {
            class EventosApp_Self_Checkin_Fullscreen_Elementor_Widget extends \Elementor\Widget_Base {
                public function get_name() {
                    return 'eventosapp_self_checkin_fullscreen';
                }

                public function get_title() {
                    return 'EventosApp Autogestión - Pantalla Completa';
                }

                public function get_icon() {
                    return 'eicon-frame-expand';
                }

                public function get_categories() {
                    return [ 'eventosapp' ];
                }

                public function get_keywords() {
                    return [ 'eventosapp', 'autogestion', 'pantalla completa', 'fullscreen', 'kiosko' ];
                }

                public function get_style_depends() {
                    eventosapp_self_checkin_elementor_bootstrap_module();
                    if ( function_exists('eventosapp_self_checkin_enqueue_assets') ) {
                        eventosapp_self_checkin_enqueue_assets();
                    }
                    return [ 'eventosapp-self-checkin' ];
                }

                public function get_script_depends() {
                    eventosapp_self_checkin_elementor_bootstrap_module();
                    if ( function_exists('eventosapp_self_checkin_enqueue_assets') ) {
                        eventosapp_self_checkin_enqueue_assets();
                    }
                    return [ 'eventosapp-self-checkin' ];
                }

                protected function register_controls() {
                    $this->start_controls_section('section_content', [
                        'label' => 'Contenido',
                        'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                    ]);

                    $this->add_control('label', [
                        'label'       => 'Texto del botón',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => 'Activar pantalla completa',
                        'label_block' => true,
                    ]);

                    $this->add_responsive_control('align', [
                        'label' => 'Alineación',
                        'type'  => \Elementor\Controls_Manager::CHOOSE,
                        'options' => [
                            'left'    => [ 'title' => 'Izquierda', 'icon' => 'eicon-text-align-left' ],
                            'center'  => [ 'title' => 'Centro', 'icon' => 'eicon-text-align-center' ],
                            'right'   => [ 'title' => 'Derecha', 'icon' => 'eicon-text-align-right' ],
                            'stretch' => [ 'title' => 'Completo', 'icon' => 'eicon-h-align-stretch' ],
                        ],
                        'default' => 'center',
                    ]);

                    $this->add_control('hide_on_fullscreen', [
                        'label'        => 'Ocultar al entrar en pantalla completa',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                    ]);

                    $this->end_controls_section();

                    $this->start_controls_section('section_style_button', [
                        'label' => 'Botón',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'button_typography',
                        'selector' => '{{WRAPPER}} .evsc-fullscreen-trigger',
                    ]);

                    $this->start_controls_tabs('button_tabs');
                    $this->start_controls_tab('button_normal', [ 'label' => 'Normal' ]);
                    $this->add_control('button_color', [
                        'label' => 'Texto',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-fullscreen-trigger' => 'color: {{VALUE}} !important;' ],
                    ]);
                    $this->add_control('button_background', [
                        'label' => 'Fondo',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-fullscreen-trigger' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->end_controls_tab();

                    $this->start_controls_tab('button_hover', [ 'label' => 'Hover' ]);
                    $this->add_control('button_hover_color', [
                        'label' => 'Texto hover',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-fullscreen-trigger:hover' => 'color: {{VALUE}} !important;' ],
                    ]);
                    $this->add_control('button_hover_background', [
                        'label' => 'Fondo hover',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-fullscreen-trigger:hover' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->end_controls_tab();
                    $this->end_controls_tabs();

                    $this->add_responsive_control('button_padding', [
                        'label' => 'Relleno',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'separator' => 'before',
                        'selectors' => [ '{{WRAPPER}} .evsc-fullscreen-trigger' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_responsive_control('button_min_height', [
                        'label' => 'Alto mínimo',
                        'type'  => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => [ 'px', 'em', 'rem' ],
                        'range' => [ 'px' => [ 'min' => 34, 'max' => 140 ] ],
                        'selectors' => [ '{{WRAPPER}} .evsc-fullscreen-trigger' => 'min-height: {{SIZE}}{{UNIT}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
                        'name'     => 'button_border',
                        'selector' => '{{WRAPPER}} .evsc-fullscreen-trigger',
                    ]);
                    $this->add_responsive_control('button_radius', [
                        'label' => 'Radio',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => [ 'px', '%', 'em', 'rem' ],
                        'selectors' => [ '{{WRAPPER}} .evsc-fullscreen-trigger' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
                        'name'     => 'button_shadow',
                        'selector' => '{{WRAPPER}} .evsc-fullscreen-trigger',
                    ]);

                    $this->end_controls_section();
                }

                protected function render() {
                    if ( ! eventosapp_self_checkin_elementor_bootstrap_module() || ! function_exists('eventosapp_self_checkin_render_fullscreen_button') ) {
                        echo '<div class="evsc-alert evsc-alert-error">El módulo de autogestión no está cargado. Verifica que el archivo <code>includes/frontend/eventosapp-self-checkin.php</code> esté instalado.</div>';
                        return;
                    }

                    $settings = $this->get_settings_for_display();
                    echo eventosapp_self_checkin_render_fullscreen_button([
                        'label'              => $settings['label'] ?? 'Activar pantalla completa',
                        'align'              => $settings['align'] ?? 'center',
                        'hide_on_fullscreen' => ( $settings['hide_on_fullscreen'] ?? 'yes' ) === 'yes',
                    ]);
                }
            }
        }

        if ( ! class_exists('EventosApp_Self_Checkin_Launcher_Elementor_Widget') ) {
            class EventosApp_Self_Checkin_Launcher_Elementor_Widget extends \Elementor\Widget_Base {
                public function get_name() {
                    return 'eventosapp_self_checkin_launcher';
                }

                public function get_title() {
                    return 'EventosApp Autogestión - Kiosko / Impresión Silenciosa';
                }

                public function get_icon() {
                    return 'eicon-download-button';
                }

                public function get_categories() {
                    return [ 'eventosapp' ];
                }

                public function get_keywords() {
                    return [ 'eventosapp', 'kiosko', 'launcher', 'impresion', 'silenciosa', 'chrome' ];
                }

                public function get_style_depends() {
                    eventosapp_self_checkin_elementor_bootstrap_module();
                    if ( function_exists('eventosapp_self_checkin_enqueue_assets') ) {
                        eventosapp_self_checkin_enqueue_assets();
                    }
                    return [ 'eventosapp-self-checkin' ];
                }

                public function get_script_depends() {
                    eventosapp_self_checkin_elementor_bootstrap_module();
                    if ( function_exists('eventosapp_self_checkin_enqueue_assets') ) {
                        eventosapp_self_checkin_enqueue_assets();
                    }
                    return [ 'eventosapp-self-checkin' ];
                }

                protected function register_controls() {
                    $this->start_controls_section('section_content', [
                        'label' => 'Contenido',
                        'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                    ]);

                    $this->add_control('use_active_event', [
                        'label'        => 'Usar evento activo del dashboard',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                        'description'  => 'Activado: los lanzadores se generan con la URL del módulo y el evento activo de gestión.',
                    ]);

                    $this->add_control('event_id', [
                        'label'       => 'Evento fijo de respaldo',
                        'type'        => \Elementor\Controls_Manager::SELECT2,
                        'default'     => 0,
                        'options'     => eventosapp_self_checkin_elementor_get_event_options(),
                        'label_block' => true,
                        'description' => 'Úsalo solo si necesitas generar lanzadores para un evento específico. Para operación normal debe quedar activo el evento del dashboard.',
                        'condition'   => [ 'use_active_event!' => 'yes' ],
                    ]);

                    $this->add_control('show_for_admin_only', [
                        'label'        => 'Mostrar solo a administradores',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                    ]);

                    $this->add_control('show_launcher_box', [
                        'label'        => 'Mostrar caja de descargas',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                    ]);

                    $this->add_control('intro_text', [
                        'label'       => 'Texto destacado',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => 'Modo kiosko / impresión silenciosa:',
                        'label_block' => true,
                    ]);

                    $this->add_control('description', [
                        'label'       => 'Descripción',
                        'type'        => \Elementor\Controls_Manager::TEXTAREA,
                        'default'     => 'por seguridad del navegador, WordPress no puede ejecutar Terminal, CMD ni activar banderas nativas de Chrome directamente desde un botón web. Para evitar errores del asistente, descarga el lanzador del sistema operativo del equipo del kiosko y ejecútalo desde ese equipo; abrirá esta página en Chrome con <code>--kiosk</code> y <code>--kiosk-printing</code>.',
                        'label_block' => true,
                    ]);

                    $this->add_control('launcher_title', [
                        'label'       => 'Título de caja',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => 'Lanzadores del equipo kiosko',
                        'label_block' => true,
                    ]);

                    $this->add_control('launcher_text', [
                        'label'       => 'Texto de caja',
                        'type'        => \Elementor\Controls_Manager::TEXTAREA,
                        'default'     => 'Disponibles solo para administradores. Deben descargarse y ejecutarse una vez desde el equipo físico que tendrá la impresora predeterminada.',
                        'label_block' => true,
                    ]);

                    $this->add_control('mac_label', [
                        'label'   => 'Texto botón Mac',
                        'type'    => \Elementor\Controls_Manager::TEXT,
                        'default' => 'Descargar lanzador Mac',
                    ]);

                    $this->add_control('windows_label', [
                        'label'   => 'Texto botón Windows',
                        'type'    => \Elementor\Controls_Manager::TEXT,
                        'default' => 'Descargar lanzador Windows',
                    ]);

                    $this->add_control('show_close_button', [
                        'label'        => 'Permitir cerrar este bloque',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                        'description'  => 'Al cerrarlo, se mantiene oculto aunque se recargue la ventana durante la sesión del navegador.',
                    ]);

                    $this->end_controls_section();

                    $this->register_launcher_style_controls();
                }

                private function register_launcher_style_controls() {
                    $this->start_controls_section('section_style_launcher', [
                        'label' => 'Bloque instrucciones',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_control('note_background', [
                        'label' => 'Fondo bloque',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-admin-note' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->add_control('note_color', [
                        'label' => 'Texto bloque',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-admin-note' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_control('note_strong_color', [
                        'label' => 'Texto destacado',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-admin-note strong' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'note_typography',
                        'selector' => '{{WRAPPER}} .evsc-admin-note',
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
                        'name'     => 'note_border',
                        'selector' => '{{WRAPPER}} .evsc-admin-note',
                    ]);
                    $this->add_responsive_control('note_radius', [
                        'label' => 'Radio bloque',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => [ 'px', '%', 'em', 'rem' ],
                        'selectors' => [ '{{WRAPPER}} .evsc-admin-note' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_responsive_control('note_padding', [
                        'label' => 'Relleno bloque',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-admin-note' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_responsive_control('note_margin', [
                        'label' => 'Margen bloque',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-admin-note' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
                        'name'     => 'note_shadow',
                        'selector' => '{{WRAPPER}} .evsc-admin-note',
                    ]);

                    $this->add_control('heading_launcher_box', [
                        'label' => 'Caja de descargas',
                        'type'  => \Elementor\Controls_Manager::HEADING,
                        'separator' => 'before',
                    ]);
                    $this->add_control('launcher_box_background', [
                        'label' => 'Fondo caja',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-launcher-box' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->add_control('launcher_box_color', [
                        'label' => 'Texto caja',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-launcher-box' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
                        'name'     => 'launcher_box_border',
                        'selector' => '{{WRAPPER}} .evsc-launcher-box',
                    ]);
                    $this->add_responsive_control('launcher_box_radius', [
                        'label' => 'Radio caja',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => [ 'px', '%', 'em', 'rem' ],
                        'selectors' => [ '{{WRAPPER}} .evsc-launcher-box' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_responsive_control('launcher_box_padding', [
                        'label' => 'Relleno caja',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-launcher-box' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);

                    $this->add_control('launcher_title_color', [
                        'label' => 'Color título caja',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-launcher-title' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'launcher_title_typography',
                        'selector' => '{{WRAPPER}} .evsc-launcher-title',
                    ]);
                    $this->add_control('launcher_text_color', [
                        'label' => 'Color texto caja',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-launcher-text' => 'color: {{VALUE}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'launcher_text_typography',
                        'selector' => '{{WRAPPER}} .evsc-launcher-text',
                    ]);
                    $this->add_responsive_control('launcher_actions_gap', [
                        'label' => 'Separación botones',
                        'type'  => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => [ 'px', 'em', 'rem' ],
                        'range' => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
                        'selectors' => [ '{{WRAPPER}} .evsc-launcher-actions' => 'gap: {{SIZE}}{{UNIT}};' ],
                    ]);
                    $this->add_responsive_control('launcher_actions_align', [
                        'label' => 'Alineación botones',
                        'type'  => \Elementor\Controls_Manager::CHOOSE,
                        'options' => [
                            'flex-start' => [ 'title' => 'Izquierda', 'icon' => 'eicon-text-align-left' ],
                            'center'     => [ 'title' => 'Centro', 'icon' => 'eicon-text-align-center' ],
                            'flex-end'   => [ 'title' => 'Derecha', 'icon' => 'eicon-text-align-right' ],
                        ],
                        'selectors' => [ '{{WRAPPER}} .evsc-launcher-actions' => 'justify-content: {{VALUE}};' ],
                    ]);

                    $this->add_control('heading_launcher_buttons', [
                        'label' => 'Botones de descarga',
                        'type'  => \Elementor\Controls_Manager::HEADING,
                        'separator' => 'before',
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'launcher_button_typography',
                        'selector' => '{{WRAPPER}} .evsc-launcher-btn',
                    ]);
                    $this->start_controls_tabs('launcher_button_tabs');
                    $this->start_controls_tab('launcher_button_normal', [ 'label' => 'Normal' ]);
                    $this->add_control('launcher_button_color', [
                        'label' => 'Texto',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-launcher-btn' => 'color: {{VALUE}} !important;' ],
                    ]);
                    $this->add_control('launcher_button_background', [
                        'label' => 'Fondo',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-launcher-btn' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->end_controls_tab();
                    $this->start_controls_tab('launcher_button_hover', [ 'label' => 'Hover' ]);
                    $this->add_control('launcher_button_hover_color', [
                        'label' => 'Texto hover',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-launcher-btn:hover' => 'color: {{VALUE}} !important;' ],
                    ]);
                    $this->add_control('launcher_button_hover_background', [
                        'label' => 'Fondo hover',
                        'type'  => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .evsc-launcher-btn:hover' => 'background-color: {{VALUE}};' ],
                    ]);
                    $this->end_controls_tab();
                    $this->end_controls_tabs();
                    $this->add_responsive_control('launcher_button_padding', [
                        'label' => 'Relleno botón',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => eventosapp_self_checkin_elementor_common_dimension_units(),
                        'selectors' => [ '{{WRAPPER}} .evsc-launcher-btn' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_responsive_control('launcher_button_radius', [
                        'label' => 'Radio botón',
                        'type'  => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => [ 'px', '%', 'em', 'rem' ],
                        'selectors' => [ '{{WRAPPER}} .evsc-launcher-btn' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
                    ]);
                    $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
                        'name'     => 'launcher_button_shadow',
                        'selector' => '{{WRAPPER}} .evsc-launcher-btn',
                    ]);

                    $this->end_controls_section();
                }

                protected function render() {
                    if ( ! eventosapp_self_checkin_elementor_bootstrap_module() || ! function_exists('eventosapp_self_checkin_render_launcher_block') ) {
                        echo '<div class="evsc-alert evsc-alert-error">El módulo de autogestión no está cargado. Verifica que el archivo <code>includes/frontend/eventosapp-self-checkin.php</code> esté instalado.</div>';
                        return;
                    }

                    $settings = $this->get_settings_for_display();
                    $event_id = eventosapp_self_checkin_elementor_resolve_event_id( $settings );

                    echo eventosapp_self_checkin_render_launcher_block( $event_id, [
                        'show_for_admin_only' => ( $settings['show_for_admin_only'] ?? 'yes' ) === 'yes',
                        'show_launcher_box'   => ( $settings['show_launcher_box'] ?? 'yes' ) === 'yes',
                        'intro_text'          => $settings['intro_text'] ?? 'Modo kiosko / impresión silenciosa:',
                        'description'         => $settings['description'] ?? '',
                        'launcher_title'      => $settings['launcher_title'] ?? 'Lanzadores del equipo kiosko',
                        'launcher_text'       => $settings['launcher_text'] ?? '',
                        'mac_label'           => $settings['mac_label'] ?? 'Descargar lanzador Mac',
                        'windows_label'       => $settings['windows_label'] ?? 'Descargar lanzador Windows',
                        'show_close_button'  => ( $settings['show_close_button'] ?? 'yes' ) === 'yes',
                    ] );
                }
            }
        }

        if ( is_object( $widgets_manager ) && method_exists( $widgets_manager, 'register' ) ) {
            $widgets_manager->register( new EventosApp_Self_Checkin_UI_Elementor_Widget() );
            $widgets_manager->register( new EventosApp_Self_Checkin_Fullscreen_Elementor_Widget() );
            $widgets_manager->register( new EventosApp_Self_Checkin_Launcher_Elementor_Widget() );
            $registered = true;
            return;
        }

        if ( class_exists('\Elementor\Plugin') && isset( \Elementor\Plugin::instance()->widgets_manager ) ) {
            $legacy_manager = \Elementor\Plugin::instance()->widgets_manager;
            if ( method_exists( $legacy_manager, 'register_widget_type' ) ) {
                $legacy_manager->register_widget_type( new EventosApp_Self_Checkin_UI_Elementor_Widget() );
                $legacy_manager->register_widget_type( new EventosApp_Self_Checkin_Fullscreen_Elementor_Widget() );
                $legacy_manager->register_widget_type( new EventosApp_Self_Checkin_Launcher_Elementor_Widget() );
                $registered = true;
            }
        }
    }
}

add_action('elementor/widgets/register', 'eventosapp_self_checkin_register_elementor_widgets');
add_action('elementor/widgets/widgets_registered', 'eventosapp_self_checkin_register_elementor_widgets');
