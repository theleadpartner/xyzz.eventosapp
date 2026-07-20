<?php
/**
 * EventosApp - Widget Elementor del Dashboard
 *
 * Este archivo no duplica la lógica del shortcode. Renderiza el mismo motor
 * eventosapp_render_dashboard(), por lo que conserva evento activo, permisos,
 * URLs configuradas y módulos condicionados por evento.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists('eventosapp_dashboard_elementor_category') ) {
	function eventosapp_dashboard_elementor_category( $elements_manager ) {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) return;

		if ( method_exists( $elements_manager, 'get_categories' ) ) {
			$categories = $elements_manager->get_categories();
			if ( is_array( $categories ) && isset( $categories['eventosapp'] ) ) return;
		}

		$elements_manager->add_category( 'eventosapp', [
			'title' => 'EventosApp',
			'icon'  => 'fa fa-plug',
		] );
	}
}
add_action( 'elementor/elements/categories_registered', 'eventosapp_dashboard_elementor_category', 20 );

if ( ! function_exists('eventosapp_register_dashboard_elementor_widget') ) {
	function eventosapp_register_dashboard_elementor_widget( $widgets_manager = null ) {
		static $registered = false;
		if ( $registered || ! class_exists('\Elementor\Widget_Base') ) return;

		if ( ! class_exists('EventosApp_Dashboard_Elementor_Widget') ) {
			class EventosApp_Dashboard_Elementor_Widget extends \Elementor\Widget_Base {
				public function get_name() {
					return 'eventosapp_dashboard';
				}

				public function get_title() {
					return 'EventosApp - Dashboard';
				}

				public function get_icon() {
					return 'eicon-apps';
				}

				public function get_categories() {
					return [ 'eventosapp' ];
				}

				public function get_keywords() {
					return [ 'eventosapp', 'dashboard', 'panel', 'gestión', 'evento', 'checkin' ];
				}

				protected function register_controls() {
					$this->register_content_controls();
					$this->register_layout_controls();
					$this->register_theme_style_controls();
					$this->register_container_style_controls();
					$this->register_header_style_controls();
					$this->register_event_style_controls();
					$this->register_search_style_controls();
					$this->register_sections_style_controls();
					$this->register_cards_style_controls();
				}

				private function register_content_controls() {
					$this->start_controls_section( 'section_content', [
						'label' => 'Contenido',
						'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
					] );

					$this->add_control( 'show_header', [
						'label'        => 'Mostrar encabezado',
						'type'         => \Elementor\Controls_Manager::SWITCHER,
						'label_on'     => 'Sí',
						'label_off'    => 'No',
						'return_value' => 'yes',
						'default'      => 'yes',
					] );

					$this->add_control( 'eyebrow', [
						'label'       => 'Texto superior',
						'type'        => \Elementor\Controls_Manager::TEXT,
						'default'     => 'EventosApp',
						'label_block' => true,
						'condition'   => [ 'show_header' => 'yes' ],
					] );

					$this->add_control( 'title', [
						'label'       => 'Título',
						'type'        => \Elementor\Controls_Manager::TEXT,
						'default'     => 'Panel de gestión',
						'label_block' => true,
						'condition'   => [ 'show_header' => 'yes' ],
					] );

					$this->add_control( 'subtitle', [
						'label'       => 'Descripción',
						'type'        => \Elementor\Controls_Manager::TEXTAREA,
						'default'     => 'Accede rápidamente a las herramientas disponibles para el evento seleccionado.',
						'label_block' => true,
						'condition'   => [ 'show_header' => 'yes' ],
					] );

					$this->add_control( 'show_module_count', [
						'label'        => 'Mostrar cantidad de módulos',
						'type'         => \Elementor\Controls_Manager::SWITCHER,
						'label_on'     => 'Sí',
						'label_off'    => 'No',
						'return_value' => 'yes',
						'default'      => 'yes',
					] );

					$this->add_control( 'show_active_event', [
						'label'        => 'Mostrar barra del evento activo',
						'type'         => \Elementor\Controls_Manager::SWITCHER,
						'label_on'     => 'Sí',
						'label_off'    => 'No',
						'return_value' => 'yes',
						'default'      => 'yes',
						'description'  => 'La barra toma el evento activo real del usuario y conserva el botón Cambiar evento.',
					] );

					$this->add_control( 'show_descriptions', [
						'label'        => 'Mostrar descripción en tarjetas',
						'type'         => \Elementor\Controls_Manager::SWITCHER,
						'label_on'     => 'Sí',
						'label_off'    => 'No',
						'return_value' => 'yes',
						'default'      => 'yes',
					] );

					$this->add_control( 'show_search', [
						'label'   => 'Buscador de herramientas',
						'type'    => \Elementor\Controls_Manager::SELECT,
						'default' => 'auto',
						'options' => [
							'auto' => 'Automático según cantidad',
							'yes'  => 'Mostrar siempre',
							'no'   => 'Ocultar',
						],
					] );

					$this->add_control( 'search_min_modules', [
						'label'     => 'Mostrar buscador desde',
						'type'      => \Elementor\Controls_Manager::NUMBER,
						'default'   => 7,
						'min'       => 2,
						'max'       => 30,
						'step'      => 1,
						'condition' => [ 'show_search' => 'auto' ],
					] );

					$this->add_control( 'search_placeholder', [
						'label'       => 'Placeholder del buscador',
						'type'        => \Elementor\Controls_Manager::TEXT,
						'default'     => 'Buscar una herramienta…',
						'label_block' => true,
						'condition'   => [ 'show_search!' => 'no' ],
					] );

					$this->add_control( 'empty_search_text', [
						'label'       => 'Mensaje sin resultados',
						'type'        => \Elementor\Controls_Manager::TEXT,
						'default'     => 'No encontramos herramientas con ese término.',
						'label_block' => true,
						'condition'   => [ 'show_search!' => 'no' ],
					] );

					$this->add_control( 'show_section_titles', [
						'label'   => 'Agrupar herramientas',
						'type'    => \Elementor\Controls_Manager::SELECT,
						'default' => 'auto',
						'options' => [
							'auto' => 'Automático según cantidad',
							'yes'  => 'Mostrar categorías',
							'no'   => 'Una sola cuadrícula',
						],
						'description' => 'Las categorías son visuales. No cambian permisos, URLs ni módulos habilitados.',
					] );

					$this->end_controls_section();
				}

				private function register_layout_controls() {
					$this->start_controls_section( 'section_layout', [
						'label' => 'Distribución inteligente',
						'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
					] );

					$this->add_control( 'columns_desktop', [
						'label'   => 'Columnas en escritorio',
						'type'    => \Elementor\Controls_Manager::SELECT,
						'default' => '0',
						'options' => [ '0' => 'Automático', '1' => '1', '2' => '2', '3' => '3', '4' => '4' ],
					] );

					$this->add_control( 'columns_tablet', [
						'label'   => 'Columnas en tablet',
						'type'    => \Elementor\Controls_Manager::SELECT,
						'default' => '0',
						'options' => [ '0' => 'Automático', '1' => '1', '2' => '2', '3' => '3' ],
					] );

					$this->add_control( 'columns_mobile', [
						'label'   => 'Columnas en móvil',
						'type'    => \Elementor\Controls_Manager::SELECT,
						'default' => '1',
						'options' => [ '1' => '1', '2' => '2' ],
					] );

					$this->end_controls_section();
				}

				private function register_theme_style_controls() {
					$this->start_controls_section( 'section_style_theme', [
						'label' => 'Tema general',
						'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
					] );

					$this->add_control( 'primary_color', [
						'label' => 'Color principal',
						'type'  => \Elementor\Controls_Manager::COLOR,
						'default' => '#3279bd',
						'selectors' => [ '{{WRAPPER}} .evapp-dashboard' => '--evapp-primary:{{VALUE}};' ],
					] );

					$this->add_control( 'primary_dark_color', [
						'label' => 'Color principal oscuro',
						'type'  => \Elementor\Controls_Manager::COLOR,
						'default' => '#255f96',
						'selectors' => [ '{{WRAPPER}} .evapp-dashboard' => '--evapp-primary-dark:{{VALUE}};' ],
					] );

					$this->add_control( 'app_background_color', [
						'label' => 'Fondo del dashboard',
						'type'  => \Elementor\Controls_Manager::COLOR,
						'default' => '#f5f8fc',
						'selectors' => [ '{{WRAPPER}} .evapp-dashboard' => '--evapp-app-bg:{{VALUE}};' ],
					] );

					$this->add_control( 'surface_color', [
						'label' => 'Fondo de componentes',
						'type'  => \Elementor\Controls_Manager::COLOR,
						'default' => '#ffffff',
						'selectors' => [ '{{WRAPPER}} .evapp-dashboard' => '--evapp-surface:{{VALUE}};' ],
					] );

					$this->add_control( 'text_color', [
						'label' => 'Texto principal',
						'type'  => \Elementor\Controls_Manager::COLOR,
						'default' => '#182230',
						'selectors' => [ '{{WRAPPER}} .evapp-dashboard' => '--evapp-text:{{VALUE}};' ],
					] );

					$this->add_control( 'muted_color', [
						'label' => 'Texto secundario',
						'type'  => \Elementor\Controls_Manager::COLOR,
						'default' => '#64748b',
						'selectors' => [ '{{WRAPPER}} .evapp-dashboard' => '--evapp-muted:{{VALUE}};' ],
					] );

					$this->add_control( 'border_color', [
						'label' => 'Bordes',
						'type'  => \Elementor\Controls_Manager::COLOR,
						'default' => '#dfe7f1',
						'selectors' => [ '{{WRAPPER}} .evapp-dashboard' => '--evapp-border:{{VALUE}};' ],
					] );

					$this->end_controls_section();
				}

				private function register_container_style_controls() {
					$this->start_controls_section( 'section_style_container', [
						'label' => 'Contenedor',
						'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
					] );

					$this->add_responsive_control( 'container_padding', [
						'label' => 'Relleno',
						'type' => \Elementor\Controls_Manager::DIMENSIONS,
						'size_units' => [ 'px', 'em', 'rem', '%' ],
						'selectors' => [ '{{WRAPPER}} .evapp-dashboard-shell' => 'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
					] );

					$this->add_responsive_control( 'container_radius', [
						'label' => 'Radio de borde',
						'type' => \Elementor\Controls_Manager::DIMENSIONS,
						'size_units' => [ 'px', '%', 'em', 'rem' ],
						'selectors' => [ '{{WRAPPER}} .evapp-dashboard-shell' => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
					] );

					$this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
						'name' => 'container_border',
						'selector' => '{{WRAPPER}} .evapp-dashboard-shell',
					] );

					$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
						'name' => 'container_shadow',
						'selector' => '{{WRAPPER}} .evapp-dashboard-shell',
					] );

					$this->end_controls_section();
				}

				private function register_header_style_controls() {
					$this->start_controls_section( 'section_style_header', [
						'label' => 'Encabezado',
						'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
					] );

					$this->add_control( 'eyebrow_color', [
						'label' => 'Color texto superior',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [ '{{WRAPPER}} .evapp-dashboard-eyebrow' => 'color:{{VALUE}};' ],
					] );

					$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
						'name' => 'eyebrow_typography',
						'selector' => '{{WRAPPER}} .evapp-dashboard-eyebrow',
					] );

					$this->add_control( 'title_color', [
						'label' => 'Color del título',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [ '{{WRAPPER}} .evapp-dashboard-main-title' => 'color:{{VALUE}};' ],
					] );

					$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
						'name' => 'title_typography',
						'selector' => '{{WRAPPER}} .evapp-dashboard-main-title',
					] );

					$this->add_control( 'subtitle_color', [
						'label' => 'Color de descripción',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [ '{{WRAPPER}} .evapp-dashboard-subtitle' => 'color:{{VALUE}};' ],
					] );

					$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
						'name' => 'subtitle_typography',
						'selector' => '{{WRAPPER}} .evapp-dashboard-subtitle',
					] );

					$this->end_controls_section();
				}

				private function register_event_style_controls() {
					$this->start_controls_section( 'section_style_event', [
						'label' => 'Evento activo y selector',
						'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
					] );

					$this->add_control( 'event_background', [
						'label' => 'Fondo',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [
							'{{WRAPPER}} .evapp-event-context' => 'background:{{VALUE}};',
							'{{WRAPPER}} .evapp-selector-card' => 'background:{{VALUE}};',
						],
					] );

					$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
						'name' => 'event_shadow',
						'selector' => '{{WRAPPER}} .evapp-event-context, {{WRAPPER}} .evapp-selector-card',
					] );

					$this->add_responsive_control( 'event_radius', [
						'label' => 'Radio de borde',
						'type' => \Elementor\Controls_Manager::DIMENSIONS,
						'size_units' => [ 'px', '%', 'em', 'rem' ],
						'selectors' => [
							'{{WRAPPER}} .evapp-event-context' => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
							'{{WRAPPER}} .evapp-selector-card' => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
						],
					] );

					$this->add_control( 'event_button_heading', [
						'label' => 'Botones',
						'type' => \Elementor\Controls_Manager::HEADING,
						'separator' => 'before',
					] );

					$this->add_control( 'event_button_background', [
						'label' => 'Fondo del botón',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [ '{{WRAPPER}} .evapp-change-event, {{WRAPPER}} .evapp-primary-button' => 'background:{{VALUE}};border-color:{{VALUE}};' ],
					] );

					$this->add_control( 'event_button_color', [
						'label' => 'Texto del botón',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [ '{{WRAPPER}} .evapp-change-event, {{WRAPPER}} .evapp-primary-button' => 'color:{{VALUE}}!important;' ],
					] );

					$this->end_controls_section();
				}

				private function register_search_style_controls() {
					$this->start_controls_section( 'section_style_search', [
						'label' => 'Buscador',
						'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
					] );

					$this->add_control( 'search_background', [
						'label' => 'Fondo',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [ '{{WRAPPER}} .evapp-module-search' => 'background:{{VALUE}};' ],
					] );

					$this->add_control( 'search_text_color', [
						'label' => 'Color del texto',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [ '{{WRAPPER}} .evapp-module-search' => 'color:{{VALUE}};' ],
					] );

					$this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
						'name' => 'search_border',
						'selector' => '{{WRAPPER}} .evapp-module-search',
					] );

					$this->add_responsive_control( 'search_radius', [
						'label' => 'Radio de borde',
						'type' => \Elementor\Controls_Manager::DIMENSIONS,
						'size_units' => [ 'px', '%', 'em', 'rem' ],
						'selectors' => [ '{{WRAPPER}} .evapp-module-search' => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
					] );

					$this->end_controls_section();
				}

				private function register_sections_style_controls() {
					$this->start_controls_section( 'section_style_sections', [
						'label' => 'Categorías y espacios',
						'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
					] );

					$this->add_control( 'section_title_color', [
						'label' => 'Color del título',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [ '{{WRAPPER}} .evapp-section-title' => 'color:{{VALUE}};' ],
					] );

					$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
						'name' => 'section_title_typography',
						'selector' => '{{WRAPPER}} .evapp-section-title',
					] );

					$this->add_responsive_control( 'grid_gap', [
						'label' => 'Separación entre tarjetas',
						'type' => \Elementor\Controls_Manager::SLIDER,
						'size_units' => [ 'px', 'em', 'rem' ],
						'range' => [ 'px' => [ 'min' => 0, 'max' => 50 ] ],
						'selectors' => [ '{{WRAPPER}} .evapp-grid' => 'gap:{{SIZE}}{{UNIT}};' ],
					] );

					$this->add_responsive_control( 'section_gap', [
						'label' => 'Separación entre categorías',
						'type' => \Elementor\Controls_Manager::SLIDER,
						'size_units' => [ 'px', 'em', 'rem' ],
						'range' => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
						'selectors' => [ '{{WRAPPER}} .evapp-sections' => 'gap:{{SIZE}}{{UNIT}};' ],
					] );

					$this->end_controls_section();
				}

				private function register_cards_style_controls() {
					$this->start_controls_section( 'section_style_cards', [
						'label' => 'Tarjetas',
						'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
					] );

					$this->start_controls_tabs( 'card_style_tabs' );
					$this->start_controls_tab( 'card_normal_tab', [ 'label' => 'Normal' ] );

					$this->add_control( 'card_background', [
						'label' => 'Fondo',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [ '{{WRAPPER}} .evapp-card' => 'background:{{VALUE}};' ],
					] );

					$this->add_control( 'card_border_color', [
						'label' => 'Color del borde',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [ '{{WRAPPER}} .evapp-card' => 'border-color:{{VALUE}};' ],
					] );

					$this->add_control( 'card_title_color', [
						'label' => 'Color del título',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [ '{{WRAPPER}} .evapp-title' => 'color:{{VALUE}};' ],
					] );

					$this->add_control( 'card_description_color', [
						'label' => 'Color de descripción',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [ '{{WRAPPER}} .evapp-card-description' => 'color:{{VALUE}};' ],
					] );

					$this->end_controls_tab();
					$this->start_controls_tab( 'card_hover_tab', [ 'label' => 'Hover' ] );

					$this->add_control( 'card_hover_background', [
						'label' => 'Fondo',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [ '{{WRAPPER}} .evapp-card:hover' => 'background:{{VALUE}};' ],
					] );

					$this->add_control( 'card_hover_border_color', [
						'label' => 'Color del borde',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [ '{{WRAPPER}} .evapp-card:hover' => 'border-color:{{VALUE}};' ],
					] );

					$this->end_controls_tab();
					$this->end_controls_tabs();

					$this->add_responsive_control( 'card_padding', [
						'label' => 'Relleno',
						'type' => \Elementor\Controls_Manager::DIMENSIONS,
						'size_units' => [ 'px', 'em', 'rem', '%' ],
						'selectors' => [ '{{WRAPPER}} .evapp-card' => 'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
						'separator' => 'before',
					] );

					$this->add_responsive_control( 'card_min_height', [
						'label' => 'Alto mínimo',
						'type' => \Elementor\Controls_Manager::SLIDER,
						'size_units' => [ 'px', 'vh' ],
						'range' => [ 'px' => [ 'min' => 70, 'max' => 300 ] ],
						'selectors' => [ '{{WRAPPER}} .evapp-card' => 'min-height:{{SIZE}}{{UNIT}};' ],
					] );

					$this->add_responsive_control( 'card_radius', [
						'label' => 'Radio de borde',
						'type' => \Elementor\Controls_Manager::DIMENSIONS,
						'size_units' => [ 'px', '%', 'em', 'rem' ],
						'selectors' => [ '{{WRAPPER}} .evapp-card' => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
					] );

					$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
						'name' => 'card_shadow',
						'selector' => '{{WRAPPER}} .evapp-card',
					] );

					$this->add_control( 'card_title_heading', [
						'label' => 'Tipografía',
						'type' => \Elementor\Controls_Manager::HEADING,
						'separator' => 'before',
					] );

					$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
						'name' => 'card_title_typography',
						'label' => 'Título',
						'selector' => '{{WRAPPER}} .evapp-title',
					] );

					$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
						'name' => 'card_description_typography',
						'label' => 'Descripción',
						'selector' => '{{WRAPPER}} .evapp-card-description',
					] );

					$this->add_control( 'icon_heading', [
						'label' => 'Iconos',
						'type' => \Elementor\Controls_Manager::HEADING,
						'separator' => 'before',
					] );

					$this->add_control( 'icon_color', [
						'label' => 'Color del icono',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [ '{{WRAPPER}} .evapp-card-icon' => 'color:{{VALUE}};' ],
					] );

					$this->add_control( 'icon_background', [
						'label' => 'Fondo del icono',
						'type' => \Elementor\Controls_Manager::COLOR,
						'selectors' => [ '{{WRAPPER}} .evapp-card-icon' => 'background:{{VALUE}};' ],
					] );

					$this->add_responsive_control( 'icon_box_size', [
						'label' => 'Tamaño del contenedor',
						'type' => \Elementor\Controls_Manager::SLIDER,
						'size_units' => [ 'px', 'em', 'rem' ],
						'range' => [ 'px' => [ 'min' => 34, 'max' => 100 ] ],
						'selectors' => [ '{{WRAPPER}} .evapp-card-icon' => 'width:{{SIZE}}{{UNIT}};height:{{SIZE}}{{UNIT}};flex-basis:{{SIZE}}{{UNIT}};' ],
					] );

					$this->add_responsive_control( 'icon_size', [
						'label' => 'Tamaño del icono',
						'type' => \Elementor\Controls_Manager::SLIDER,
						'size_units' => [ 'px', 'em', 'rem' ],
						'range' => [ 'px' => [ 'min' => 16, 'max' => 60 ] ],
						'selectors' => [ '{{WRAPPER}} .evapp-ico' => 'width:{{SIZE}}{{UNIT}};height:{{SIZE}}{{UNIT}};' ],
					] );

					$this->end_controls_section();
				}

				protected function render() {
					if ( ! function_exists('eventosapp_render_dashboard') ) {
						echo '<div class="elementor-alert elementor-alert-danger">No se pudo cargar el motor del Dashboard de EventosApp.</div>';
						return;
					}

					$settings = $this->get_settings_for_display();
					$args = [
						'show_header'         => ( $settings['show_header'] ?? '' ) === 'yes' ? 'yes' : 'no',
						'eyebrow'             => $settings['eyebrow'] ?? 'EventosApp',
						'title'               => $settings['title'] ?? 'Panel de gestión',
						'subtitle'            => $settings['subtitle'] ?? '',
						'show_module_count'   => ( $settings['show_module_count'] ?? '' ) === 'yes' ? 'yes' : 'no',
						'show_active_event'   => ( $settings['show_active_event'] ?? '' ) === 'yes' ? 'yes' : 'no',
						'show_search'         => in_array( $settings['show_search'] ?? 'auto', [ 'auto', 'yes', 'no' ], true ) ? $settings['show_search'] : 'auto',
						'search_min_modules'  => absint( $settings['search_min_modules'] ?? 7 ),
						'search_placeholder'  => $settings['search_placeholder'] ?? 'Buscar una herramienta…',
						'empty_search_text'   => $settings['empty_search_text'] ?? 'No encontramos herramientas con ese término.',
						'show_section_titles' => in_array( $settings['show_section_titles'] ?? 'auto', [ 'auto', 'yes', 'no' ], true ) ? $settings['show_section_titles'] : 'auto',
						'show_descriptions'   => ( $settings['show_descriptions'] ?? '' ) === 'yes' ? 'yes' : 'no',
						'columns_desktop'     => absint( $settings['columns_desktop'] ?? 0 ),
						'columns_tablet'      => absint( $settings['columns_tablet'] ?? 0 ),
						'columns_mobile'      => max( 1, absint( $settings['columns_mobile'] ?? 1 ) ),
						'instance_id'         => 'evapp-dashboard-widget-' . $this->get_id(),
					];

					echo eventosapp_render_dashboard( $args );
				}
			}
		}

		$widget = new EventosApp_Dashboard_Elementor_Widget();
		if ( is_object( $widgets_manager ) && method_exists( $widgets_manager, 'register' ) ) {
			$widgets_manager->register( $widget );
			$registered = true;
			return;
		}

		if ( is_object( $widgets_manager ) && method_exists( $widgets_manager, 'register_widget_type' ) ) {
			$widgets_manager->register_widget_type( $widget );
			$registered = true;
			return;
		}

		if ( class_exists('\Elementor\Plugin') && isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
			$manager = \Elementor\Plugin::$instance->widgets_manager;
			if ( method_exists( $manager, 'register' ) ) {
				$manager->register( $widget );
				$registered = true;
			} elseif ( method_exists( $manager, 'register_widget_type' ) ) {
				$manager->register_widget_type( $widget );
				$registered = true;
			}
		}
	}
}
add_action( 'elementor/widgets/register', 'eventosapp_register_dashboard_elementor_widget', 20 );

// Compatibilidad con versiones antiguas de Elementor.
add_action( 'elementor/widgets/widgets_registered', function() {
	if ( class_exists('\Elementor\Plugin') && isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
		eventosapp_register_dashboard_elementor_widget( \Elementor\Plugin::$instance->widgets_manager );
	}
}, 20 );
