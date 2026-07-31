<?php
/**
 * EventosApp - Widget Elementor de Métricas
 *
 * El widget reutiliza eventosapp_render_metrics() y no duplica la consulta de
 * datos, los endpoints AJAX, la exportación ni las métricas personalizadas.
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('eventosapp_metrics_elementor_category') ) {
    function eventosapp_metrics_elementor_category($elements_manager) {
        if ( ! is_object($elements_manager) || ! method_exists($elements_manager, 'add_category') ) return;

        if (method_exists($elements_manager, 'get_categories')) {
            $categories = $elements_manager->get_categories();
            if (is_array($categories) && isset($categories['eventosapp'])) return;
        }

        $elements_manager->add_category('eventosapp', [
            'title' => 'EventosApp',
            'icon'  => 'fa fa-plug',
        ]);
    }
}
add_action('elementor/elements/categories_registered', 'eventosapp_metrics_elementor_category', 21);

if ( ! function_exists('eventosapp_register_metrics_elementor_widget') ) {
    function eventosapp_register_metrics_elementor_widget($widgets_manager = null) {
        static $registered = false;
        if ($registered || ! class_exists('\\Elementor\\Widget_Base')) return;

        if ( ! class_exists('EventosApp_Metrics_Elementor_Widget') ) {
            class EventosApp_Metrics_Elementor_Widget extends \Elementor\Widget_Base {
                public function get_name() {
                    return 'eventosapp_metrics';
                }

                public function get_title() {
                    return 'EventosApp - Métricas';
                }

                public function get_icon() {
                    return 'eicon-analytics';
                }

                public function get_categories() {
                    return ['eventosapp'];
                }

                public function get_keywords() {
                    return ['eventosapp', 'métricas', 'estadísticas', 'asistencia', 'check-in', 'dashboard', 'gráficos'];
                }

                protected function register_controls() {
                    $this->register_content_controls();
                    $this->register_theme_controls();
                    $this->register_container_controls();
                    $this->register_header_controls();
                    $this->register_button_controls();
                    $this->register_event_controls();
                    $this->register_filter_controls();
                    $this->register_kpi_controls();
                    $this->register_card_controls();
                    $this->register_table_controls();
                }

                private function register_content_controls() {
                    $this->start_controls_section('section_content', [
                        'label' => 'Contenido',
                        'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                    ]);

                    $this->add_control('show_header', [
                        'label'        => 'Mostrar encabezado',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                    ]);

                    $this->add_control('eyebrow', [
                        'label'       => 'Texto superior',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => 'EventosApp',
                        'label_block' => true,
                        'condition'   => ['show_header' => 'yes'],
                    ]);

                    $this->add_control('title', [
                        'label'       => 'Título',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => 'Métricas en tiempo real',
                        'label_block' => true,
                        'condition'   => ['show_header' => 'yes'],
                    ]);

                    $this->add_control('subtitle', [
                        'label'       => 'Descripción',
                        'type'        => \Elementor\Controls_Manager::TEXTAREA,
                        'default'     => 'Consulta la asistencia, los horarios de ingreso, las localidades y los medios de check-in del evento activo.',
                        'label_block' => true,
                        'condition'   => ['show_header' => 'yes'],
                    ]);

                    $this->add_control('show_event_context', [
                        'label'        => 'Mostrar evento activo',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                    ]);

                    $this->add_control('actions_heading', [
                        'label'     => 'Acciones del encabezado',
                        'type'      => \Elementor\Controls_Manager::HEADING,
                        'separator' => 'before',
                        'condition' => ['show_header' => 'yes'],
                    ]);

                    $this->add_control('show_back_button', [
                        'label'        => 'Botón volver al dashboard',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                        'condition'    => ['show_header' => 'yes'],
                    ]);

                    $this->add_control('back_button_label', [
                        'label'       => 'Texto del botón volver',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => 'Volver al dashboard',
                        'label_block' => true,
                        'condition'   => [
                            'show_header'      => 'yes',
                            'show_back_button' => 'yes',
                        ],
                    ]);

                    $this->add_control('dashboard_url', [
                        'label'         => 'URL personalizada del dashboard',
                        'type'          => \Elementor\Controls_Manager::URL,
                        'placeholder'   => 'Se usa la URL configurada por EventosApp',
                        'show_external' => false,
                        'dynamic'       => ['active' => true],
                        'condition'     => [
                            'show_header'      => 'yes',
                            'show_back_button' => 'yes',
                        ],
                        'description' => 'Déjalo vacío para usar eventosapp_get_dashboard_url() y conservar la configuración actual del plugin.',
                    ]);

                    $this->add_control('show_export_button', [
                        'label'        => 'Botón descargar Excel',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                        'condition'    => ['show_header' => 'yes'],
                    ]);

                    $this->add_control('export_button_label', [
                        'label'       => 'Texto del botón Excel',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => 'Descargar base (Excel)',
                        'label_block' => true,
                        'condition'   => [
                            'show_header'        => 'yes',
                            'show_export_button' => 'yes',
                        ],
                    ]);

                    $this->add_control('filters_heading', [
                        'label'     => 'Filtros',
                        'type'      => \Elementor\Controls_Manager::HEADING,
                        'separator' => 'before',
                    ]);

                    $this->add_control('show_filters', [
                        'label'        => 'Mostrar filtros',
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => 'Sí',
                        'label_off'    => 'No',
                        'return_value' => 'yes',
                        'default'      => 'yes',
                    ]);

                    $this->add_control('filter_title', [
                        'label'       => 'Título de filtros',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => 'Filtros de análisis',
                        'label_block' => true,
                        'condition'   => ['show_filters' => 'yes'],
                    ]);

                    $this->add_control('filter_description', [
                        'label'       => 'Descripción de filtros',
                        'type'        => \Elementor\Controls_Manager::TEXTAREA,
                        'default'     => 'Define el periodo y el tipo de check-in que deseas revisar.',
                        'condition'   => ['show_filters' => 'yes'],
                    ]);

                    $this->add_control('apply_button_label', [
                        'label'       => 'Texto aplicar filtros',
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'default'     => 'Aplicar filtros',
                        'label_block' => true,
                        'condition'   => ['show_filters' => 'yes'],
                    ]);

                    $this->add_control('refresh_interval', [
                        'label'       => 'Actualización automática (segundos)',
                        'type'        => \Elementor\Controls_Manager::NUMBER,
                        'default'     => 15,
                        'min'         => 0,
                        'max'         => 300,
                        'step'        => 5,
                        'description' => 'Usa 0 para desactivar la actualización automática. El botón Aplicar filtros seguirá funcionando.',
                    ]);

                    $this->end_controls_section();
                }

                private function register_theme_controls() {
                    $this->start_controls_section('section_style_theme', [
                        'label' => 'Tema general',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $controls = [
                        'primary_color'      => ['Color principal', '#3279bd', '--evapp-primary'],
                        'primary_dark_color' => ['Color principal oscuro', '#255f96', '--evapp-primary-dark'],
                        'primary_soft_color' => ['Color principal suave', '#eaf4ff', '--evapp-primary-soft'],
                        'app_background'     => ['Fondo general', '#f5f8fc', '--evapp-app-bg'],
                        'surface_color'      => ['Fondo de componentes', '#ffffff', '--evapp-surface'],
                        'text_color'         => ['Texto principal', '#182230', '--evapp-text'],
                        'muted_color'        => ['Texto secundario', '#64748b', '--evapp-muted'],
                        'border_color'       => ['Bordes', '#dfe7f1', '--evapp-border'],
                        'success_color'          => ['Indicadores positivos', '#15803d', '--evapp-success'],
                        'warning_color'          => ['Indicadores pendientes', '#b45309', '--evapp-warning'],
                        'analytics_background'   => ['Fondo de gráficos y tablas', '#0b1020', '--evapp-analytics-bg'],
                        'analytics_surface'      => ['Encabezados y totales oscuros', '#0f1835', '--evapp-analytics-bg-soft'],
                        'analytics_alt_surface'  => ['Filas alternas oscuras', '#0c1733', '--evapp-analytics-bg-alt'],
                        'analytics_hover_surface'=> ['Fondo hover y grupos', '#111d3d', '--evapp-analytics-bg-hover'],
                        'analytics_text'         => ['Texto de métricas', '#eaf1ff', '--evapp-analytics-text'],
                        'analytics_title'        => ['Títulos de métricas', '#cfe0ff', '--evapp-analytics-title'],
                        'analytics_muted'        => ['Texto secundario de métricas', '#a9b6d3', '--evapp-analytics-muted'],
                        'analytics_border'       => ['Bordes de métricas', '#263554', '--evapp-analytics-border'],
                        'analytics_grid'         => ['Líneas de los gráficos', 'rgba(148,163,184,0.18)', '--evapp-analytics-grid'],
                    ];

                    foreach ($controls as $key => $definition) {
                        $this->add_control($key, [
                            'label'     => $definition[0],
                            'type'      => \Elementor\Controls_Manager::COLOR,
                            'default'   => $definition[1],
                            'selectors' => [
                                '{{WRAPPER}} .evapp-metrics' => $definition[2] . ':{{VALUE}};',
                            ],
                        ]);
                    }

                    $this->end_controls_section();
                }

                private function register_container_controls() {
                    $this->start_controls_section('section_style_container', [
                        'label' => 'Contenedor',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_responsive_control('container_padding', [
                        'label'      => 'Relleno',
                        'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => ['px', 'em', 'rem', '%'],
                        'selectors'  => [
                            '{{WRAPPER}} .evapp-metrics-shell' => 'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                        ],
                    ]);

                    $this->add_responsive_control('container_radius', [
                        'label'      => 'Radio de borde',
                        'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => ['px', '%', 'em', 'rem'],
                        'selectors'  => [
                            '{{WRAPPER}} .evapp-metrics-shell' => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                        ],
                    ]);

                    $this->add_group_control(\Elementor\Group_Control_Border::get_type(), [
                        'name'     => 'container_border',
                        'selector' => '{{WRAPPER}} .evapp-metrics-shell',
                    ]);

                    $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
                        'name'     => 'container_shadow',
                        'selector' => '{{WRAPPER}} .evapp-metrics-shell',
                    ]);

                    $this->end_controls_section();
                }

                private function register_header_controls() {
                    $this->start_controls_section('section_style_header', [
                        'label' => 'Encabezado',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_control('eyebrow_color', [
                        'label'     => 'Color texto superior',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-metrics-eyebrow' => 'color:{{VALUE}};'],
                    ]);
                    $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'eyebrow_typography',
                        'selector' => '{{WRAPPER}} .evapp-metrics-eyebrow',
                    ]);

                    $this->add_control('title_color', [
                        'label'     => 'Color del título',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-metrics-title' => 'color:{{VALUE}};'],
                    ]);
                    $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'title_typography',
                        'selector' => '{{WRAPPER}} .evapp-metrics-title',
                    ]);

                    $this->add_control('subtitle_color', [
                        'label'     => 'Color de descripción',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-metrics-subtitle' => 'color:{{VALUE}};'],
                    ]);
                    $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'subtitle_typography',
                        'selector' => '{{WRAPPER}} .evapp-metrics-subtitle',
                    ]);

                    $this->end_controls_section();
                }

                private function register_button_controls() {
                    $this->start_controls_section('section_style_buttons', [
                        'label' => 'Botones',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'button_typography',
                        'selector' => '{{WRAPPER}} .evapp-metrics-button',
                    ]);

                    $this->add_responsive_control('button_radius', [
                        'label'      => 'Radio de borde',
                        'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => ['px', '%', 'em', 'rem'],
                        'selectors'  => [
                            '{{WRAPPER}} .evapp-metrics-button' => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                        ],
                    ]);

                    $this->start_controls_tabs('button_style_tabs');
                    $this->start_controls_tab('button_normal_tab', ['label' => 'Normal']);
                    $this->add_control('secondary_button_bg', [
                        'label'     => 'Fondo secundario',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-metrics-button:not(.is-primary)' => 'background:{{VALUE}};'],
                    ]);
                    $this->add_control('secondary_button_color', [
                        'label'     => 'Texto secundario',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-metrics-button:not(.is-primary)' => 'color:{{VALUE}}!important;'],
                    ]);
                    $this->add_control('primary_button_bg', [
                        'label'     => 'Fondo principal',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-metrics-button.is-primary' => 'background:{{VALUE}};border-color:{{VALUE}};'],
                    ]);
                    $this->add_control('primary_button_color', [
                        'label'     => 'Texto principal',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-metrics-button.is-primary' => 'color:{{VALUE}}!important;'],
                    ]);
                    $this->end_controls_tab();

                    $this->start_controls_tab('button_hover_tab', ['label' => 'Hover']);
                    $this->add_control('secondary_button_hover_bg', [
                        'label'     => 'Fondo secundario',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-metrics-button:not(.is-primary):hover' => 'background:{{VALUE}};'],
                    ]);
                    $this->add_control('secondary_button_hover_color', [
                        'label'     => 'Texto secundario',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-metrics-button:not(.is-primary):hover' => 'color:{{VALUE}}!important;'],
                    ]);
                    $this->add_control('primary_button_hover_bg', [
                        'label'     => 'Fondo principal',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-metrics-button.is-primary:hover' => 'background:{{VALUE}};border-color:{{VALUE}};'],
                    ]);
                    $this->add_control('primary_button_hover_color', [
                        'label'     => 'Texto principal',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-metrics-button.is-primary:hover' => 'color:{{VALUE}}!important;'],
                    ]);
                    $this->end_controls_tab();
                    $this->end_controls_tabs();

                    $this->end_controls_section();
                }

                private function register_event_controls() {
                    $this->start_controls_section('section_style_event', [
                        'label' => 'Evento activo',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_control('event_background', [
                        'label'     => 'Fondo',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-metrics-event' => 'background:{{VALUE}};'],
                    ]);
                    $this->add_control('event_name_color', [
                        'label'     => 'Color del evento',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-metrics-event-name' => 'color:{{VALUE}};'],
                    ]);
                    $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'event_name_typography',
                        'selector' => '{{WRAPPER}} .evapp-metrics-event-name',
                    ]);
                    $this->add_responsive_control('event_radius', [
                        'label'      => 'Radio de borde',
                        'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => ['px', '%', 'em', 'rem'],
                        'selectors'  => [
                            '{{WRAPPER}} .evapp-metrics-event' => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                        ],
                    ]);
                    $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
                        'name'     => 'event_shadow',
                        'selector' => '{{WRAPPER}} .evapp-metrics-event',
                    ]);

                    $this->end_controls_section();
                }

                private function register_filter_controls() {
                    $this->start_controls_section('section_style_filters', [
                        'label' => 'Filtros',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_control('filter_background', [
                        'label'     => 'Fondo del panel',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-filter-panel' => 'background:{{VALUE}};'],
                    ]);
                    $this->add_control('filter_title_color', [
                        'label'     => 'Color del título',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-filter-title' => 'color:{{VALUE}};'],
                    ]);
                    $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'filter_title_typography',
                        'selector' => '{{WRAPPER}} .evapp-filter-title',
                    ]);
                    $this->add_control('field_background', [
                        'label'     => 'Fondo de campos',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-filters input, {{WRAPPER}} .evapp-filters select' => 'background-color:{{VALUE}};'],
                    ]);
                    $this->add_control('field_text_color', [
                        'label'     => 'Texto de campos',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-filters input, {{WRAPPER}} .evapp-filters select' => 'color:{{VALUE}};'],
                    ]);
                    $this->add_responsive_control('filter_gap', [
                        'label'      => 'Separación entre campos',
                        'type'       => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => ['px', 'em', 'rem'],
                        'range'      => ['px' => ['min' => 0, 'max' => 40]],
                        'selectors'  => ['{{WRAPPER}} .evapp-filters' => 'gap:{{SIZE}}{{UNIT}};'],
                    ]);
                    $this->add_responsive_control('field_radius', [
                        'label'      => 'Radio de campos',
                        'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => ['px', '%', 'em', 'rem'],
                        'selectors'  => [
                            '{{WRAPPER}} .evapp-filters input, {{WRAPPER}} .evapp-filters select' => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                        ],
                    ]);
                    $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
                        'name'     => 'filter_shadow',
                        'selector' => '{{WRAPPER}} .evapp-filter-panel',
                    ]);

                    $this->end_controls_section();
                }

                private function register_kpi_controls() {
                    $this->start_controls_section('section_style_kpis', [
                        'label' => 'Indicadores KPI',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_control('kpi_background', [
                        'label'     => 'Fondo',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-kpi-card' => 'background:{{VALUE}};'],
                    ]);
                    $this->add_control('kpi_value_color', [
                        'label'     => 'Color del valor',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-kpi-value' => 'color:{{VALUE}};'],
                    ]);
                    $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'kpi_value_typography',
                        'label'    => 'Valor',
                        'selector' => '{{WRAPPER}} .evapp-kpi-value',
                    ]);
                    $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'kpi_label_typography',
                        'label'    => 'Etiqueta',
                        'selector' => '{{WRAPPER}} .evapp-kpi-label',
                    ]);
                    $this->add_responsive_control('kpi_gap', [
                        'label'      => 'Separación',
                        'type'       => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => ['px', 'em', 'rem'],
                        'range'      => ['px' => ['min' => 0, 'max' => 40]],
                        'selectors'  => ['{{WRAPPER}} .evapp-kpi-grid' => 'gap:{{SIZE}}{{UNIT}};'],
                    ]);
                    $this->add_responsive_control('kpi_padding', [
                        'label'      => 'Relleno',
                        'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => ['px', 'em', 'rem', '%'],
                        'selectors'  => [
                            '{{WRAPPER}} .evapp-kpi-card' => 'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                        ],
                    ]);
                    $this->add_responsive_control('kpi_radius', [
                        'label'      => 'Radio de borde',
                        'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => ['px', '%', 'em', 'rem'],
                        'selectors'  => [
                            '{{WRAPPER}} .evapp-kpi-card' => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                        ],
                    ]);
                    $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
                        'name'     => 'kpi_shadow',
                        'selector' => '{{WRAPPER}} .evapp-kpi-card',
                    ]);

                    $this->end_controls_section();
                }

                private function register_card_controls() {
                    $this->start_controls_section('section_style_cards', [
                        'label' => 'Gráficos y tarjetas',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_control('card_background', [
                        'label'       => 'Fondo personalizado',
                        'type'        => \Elementor\Controls_Manager::COLOR,
                        'selectors'   => ['{{WRAPPER}} .evapp-metric-card' => 'background:{{VALUE}};'],
                        'description' => 'Déjalo vacío para utilizar el fondo oscuro definido en Tema general.',
                    ]);
                    $this->add_control('card_border_color', [
                        'label'       => 'Borde personalizado',
                        'type'        => \Elementor\Controls_Manager::COLOR,
                        'selectors'   => ['{{WRAPPER}} .evapp-metric-card' => 'border-color:{{VALUE}};'],
                        'description' => 'Déjalo vacío para utilizar el borde oscuro definido en Tema general.',
                    ]);
                    $this->add_control('card_title_color', [
                        'label'     => 'Color del título',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => ['{{WRAPPER}} .evapp-metric-card h3' => 'color:{{VALUE}};'],
                    ]);
                    $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'card_title_typography',
                        'selector' => '{{WRAPPER}} .evapp-metric-card h3',
                    ]);
                    $this->add_control('card_hint_color', [
                        'label'     => 'Texto secundario',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [
                            '{{WRAPPER}} .evapp-card-kicker, {{WRAPPER}} .evapp-hint, {{WRAPPER}} .evapp-footnote' => 'color:{{VALUE}};',
                        ],
                    ]);
                    $this->add_responsive_control('cards_gap', [
                        'label'      => 'Separación entre tarjetas',
                        'type'       => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => ['px', 'em', 'rem'],
                        'range'      => ['px' => ['min' => 0, 'max' => 50]],
                        'selectors'  => [
                            '{{WRAPPER}} .evapp-metrics' => '--evapp-grid-gap:{{SIZE}}{{UNIT}};',
                        ],
                    ]);
                    $this->add_responsive_control('card_padding', [
                        'label'      => 'Relleno',
                        'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => ['px', 'em', 'rem', '%'],
                        'selectors'  => [
                            '{{WRAPPER}} .evapp-metric-card' => 'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                        ],
                    ]);
                    $this->add_responsive_control('card_radius', [
                        'label'      => 'Radio de borde',
                        'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => ['px', '%', 'em', 'rem'],
                        'selectors'  => [
                            '{{WRAPPER}} .evapp-metric-card' => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                        ],
                    ]);
                    $this->add_responsive_control('chart_height', [
                        'label'      => 'Alto de gráficos',
                        'type'       => \Elementor\Controls_Manager::SLIDER,
                        'size_units' => ['px', 'vh'],
                        'range'      => [
                            'px' => ['min' => 220, 'max' => 700],
                            'vh' => ['min' => 20, 'max' => 80],
                        ],
                        'selectors' => [
                            '{{WRAPPER}} .evapp-metrics' => '--evapp-chart-height:{{SIZE}}{{UNIT}};',
                        ],
                    ]);
                    $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
                        'name'     => 'card_shadow',
                        'selector' => '{{WRAPPER}} .evapp-metric-card',
                    ]);

                    $this->end_controls_section();
                }

                private function register_table_controls() {
                    $this->start_controls_section('section_style_tables', [
                        'label' => 'Tablas',
                        'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                    ]);

                    $this->add_control('table_header_background', [
                        'label'     => 'Fondo del encabezado',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [
                            '{{WRAPPER}} .evapp-table thead th, {{WRAPPER}} .evapp-qr-table thead th, {{WRAPPER}} .evapp-custom-table thead th' => 'background:{{VALUE}};',
                        ],
                    ]);
                    $this->add_control('table_header_color', [
                        'label'     => 'Texto del encabezado',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [
                            '{{WRAPPER}} .evapp-table thead th, {{WRAPPER}} .evapp-qr-table thead th, {{WRAPPER}} .evapp-custom-table thead th' => 'color:{{VALUE}};',
                        ],
                    ]);
                    $this->add_control('table_body_color', [
                        'label'     => 'Texto de filas',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [
                            '{{WRAPPER}} .evapp-table td, {{WRAPPER}} .evapp-qr-table td, {{WRAPPER}} .evapp-custom-table td' => 'color:{{VALUE}};',
                        ],
                    ]);
                    $this->add_control('table_body_background', [
                        'label'     => 'Fondo de filas',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [
                            '{{WRAPPER}} .evapp-table table, {{WRAPPER}} .evapp-qr-table table, {{WRAPPER}} .evapp-custom-table, {{WRAPPER}} .evapp-table tbody tr, {{WRAPPER}} .evapp-qr-table tbody tr, {{WRAPPER}} .evapp-custom-table tbody tr' => 'background:{{VALUE}};',
                        ],
                    ]);
                    $this->add_control('table_alt_background', [
                        'label'     => 'Fondo de filas alternas',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [
                            '{{WRAPPER}} .evapp-table tbody tr:nth-child(even), {{WRAPPER}} .evapp-qr-table tbody tr:nth-child(even), {{WRAPPER}} .evapp-custom-table tbody tr:nth-child(even)' => 'background:{{VALUE}};',
                        ],
                    ]);
                    $this->add_control('table_hover_background', [
                        'label'     => 'Fondo al pasar el cursor',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [
                            '{{WRAPPER}} .evapp-table tbody tr:hover, {{WRAPPER}} .evapp-qr-table tbody tr:hover, {{WRAPPER}} .evapp-custom-table tbody tr:hover' => 'background:{{VALUE}};',
                        ],
                    ]);
                    $this->add_control('table_border_color', [
                        'label'     => 'Color de divisiones',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [
                            '{{WRAPPER}} .evapp-table-scroll, {{WRAPPER}} .evapp-custom-table-wrap' => 'border-color:{{VALUE}};',
                            '{{WRAPPER}} .evapp-table th, {{WRAPPER}} .evapp-table td, {{WRAPPER}} .evapp-qr-table th, {{WRAPPER}} .evapp-qr-table td, {{WRAPPER}} .evapp-custom-table th, {{WRAPPER}} .evapp-custom-table td' => 'border-color:{{VALUE}};',
                        ],
                    ]);
                    $this->add_control('table_total_background', [
                        'label'     => 'Fondo de fila total',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [
                            '{{WRAPPER}} .evapp-table .evapp-total td, {{WRAPPER}} .evapp-qr-table .evapp-total td, {{WRAPPER}} .evapp-custom-table tbody tr.evapp-custom-total-row td' => 'background:{{VALUE}};',
                        ],
                    ]);
                    $this->add_control('table_total_color', [
                        'label'     => 'Texto de fila total',
                        'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [
                            '{{WRAPPER}} .evapp-table .evapp-total td, {{WRAPPER}} .evapp-qr-table .evapp-total td, {{WRAPPER}} .evapp-custom-table tbody tr.evapp-custom-total-row td' => 'color:{{VALUE}};',
                        ],
                    ]);
                    $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
                        'name'     => 'table_typography',
                        'selector' => '{{WRAPPER}} .evapp-table th, {{WRAPPER}} .evapp-table td, {{WRAPPER}} .evapp-qr-table th, {{WRAPPER}} .evapp-qr-table td, {{WRAPPER}} .evapp-custom-table th, {{WRAPPER}} .evapp-custom-table td',
                    ]);
                    $this->add_responsive_control('table_radius', [
                        'label'      => 'Radio del contenedor',
                        'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                        'size_units' => ['px', '%', 'em', 'rem'],
                        'selectors'  => [
                            '{{WRAPPER}} .evapp-table-scroll, {{WRAPPER}} .evapp-custom-table-wrap' => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                        ],
                    ]);

                    $this->end_controls_section();
                }

                protected function render() {
                    if ( ! function_exists('eventosapp_render_metrics') ) {
                        echo '<div class="elementor-alert elementor-alert-danger">No se pudo cargar el motor de Métricas de EventosApp.</div>';
                        return;
                    }

                    $settings = $this->get_settings_for_display();
                    $dashboard_url = '';
                    if (isset($settings['dashboard_url']) && is_array($settings['dashboard_url']) && ! empty($settings['dashboard_url']['url'])) {
                        $dashboard_url = $settings['dashboard_url']['url'];
                    }

                    $refresh_seconds = isset($settings['refresh_interval']) ? max(0, absint($settings['refresh_interval'])) : 15;
                    $args = [
                        'show_header'         => ($settings['show_header'] ?? '') === 'yes' ? 'yes' : 'no',
                        'eyebrow'             => $settings['eyebrow'] ?? 'EventosApp',
                        'title'               => $settings['title'] ?? 'Métricas en tiempo real',
                        'subtitle'            => $settings['subtitle'] ?? '',
                        'show_event_context'  => ($settings['show_event_context'] ?? '') === 'yes' ? 'yes' : 'no',
                        'show_back_button'    => ($settings['show_back_button'] ?? '') === 'yes' ? 'yes' : 'no',
                        'back_button_label'   => $settings['back_button_label'] ?? 'Volver al dashboard',
                        'dashboard_url'       => $dashboard_url,
                        'show_export_button'  => ($settings['show_export_button'] ?? '') === 'yes' ? 'yes' : 'no',
                        'export_button_label' => $settings['export_button_label'] ?? 'Descargar base (Excel)',
                        'show_filters'        => ($settings['show_filters'] ?? '') === 'yes' ? 'yes' : 'no',
                        'filter_title'        => $settings['filter_title'] ?? 'Filtros de análisis',
                        'filter_description'  => $settings['filter_description'] ?? '',
                        'apply_button_label'  => $settings['apply_button_label'] ?? 'Aplicar filtros',
                        'refresh_interval'    => $refresh_seconds * 1000,
                        'instance_id'         => 'evapp-metrics-widget-' . $this->get_id(),
                    ];

                    echo eventosapp_render_metrics($args);
                }
            }
        }

        $widget = new EventosApp_Metrics_Elementor_Widget();
        if (is_object($widgets_manager) && method_exists($widgets_manager, 'register')) {
            $widgets_manager->register($widget);
            $registered = true;
            return;
        }

        if (is_object($widgets_manager) && method_exists($widgets_manager, 'register_widget_type')) {
            $widgets_manager->register_widget_type($widget);
            $registered = true;
            return;
        }

        if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::$instance->widgets_manager)) {
            $manager = \Elementor\Plugin::$instance->widgets_manager;
            if (method_exists($manager, 'register')) {
                $manager->register($widget);
                $registered = true;
            } elseif (method_exists($manager, 'register_widget_type')) {
                $manager->register_widget_type($widget);
                $registered = true;
            }
        }
    }
}
add_action('elementor/widgets/register', 'eventosapp_register_metrics_elementor_widget', 21);

add_action('elementor/widgets/widgets_registered', function() {
    if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::$instance->widgets_manager)) {
        eventosapp_register_metrics_elementor_widget(\Elementor\Plugin::$instance->widgets_manager);
    }
}, 21);
