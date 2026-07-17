<?php
/**
 * EventosApp - Preconfiguraciones de eventos y ubicaciones reutilizables.
 *
 * Funciones principales:
 * - Guarda una fotografía controlada de la configuración de un evento como preconfiguración.
 * - Asigna cada preconfiguración a un cliente/empresa.
 * - Permite aplicar una preconfiguración desde el editor de eventos sin copiar datos operativos.
 * - Administra ubicaciones con dirección y coordenadas.
 * - Agrega autocompletado por coincidencia de texto al campo Dirección del Evento.
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'EventosApp_Event_Presets_And_Venues' ) ) {
    final class EventosApp_Event_Presets_And_Venues {

        const PRESET_POST_TYPE = 'eventosapp_event_preset';
        const VENUE_POST_TYPE  = 'eventosapp_venue';
        const SNAPSHOT_VERSION = 1;
        const ADMIN_NONCE      = 'eventosapp_event_presets_admin';
        const EVENT_NONCE      = 'eventosapp_event_presets_event';

        /**
         * Inicializa el módulo sin reemplazar hooks existentes del plugin.
         */
        public static function init() {
            add_action( 'init', [ __CLASS__, 'register_post_types' ], 12 );
            add_action( 'admin_menu', [ __CLASS__, 'register_submenus' ], 20 );
            add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_boxes' ], 30 );

            add_action( 'save_post_' . self::PRESET_POST_TYPE, [ __CLASS__, 'save_preset_post' ], 20 );
            add_action( 'save_post_' . self::VENUE_POST_TYPE, [ __CLASS__, 'save_venue_post' ], 20 );
            add_action( 'save_post_eventosapp_event', [ __CLASS__, 'handle_event_save' ], 9999 );

            add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_event_editor_assets' ], 60 );
            add_action( 'admin_notices', [ __CLASS__, 'render_admin_notice' ] );

            add_action( 'wp_ajax_eventosapp_search_saved_venues', [ __CLASS__, 'ajax_search_saved_venues' ] );
            add_action( 'wp_ajax_eventosapp_save_event_venue', [ __CLASS__, 'ajax_save_event_venue' ] );

            add_filter( 'parent_file', [ __CLASS__, 'set_admin_parent_file' ], 20 );
            add_filter( 'manage_' . self::PRESET_POST_TYPE . '_posts_columns', [ __CLASS__, 'preset_columns' ] );
            add_action( 'manage_' . self::PRESET_POST_TYPE . '_posts_custom_column', [ __CLASS__, 'render_preset_column' ], 10, 2 );
            add_filter( 'manage_' . self::VENUE_POST_TYPE . '_posts_columns', [ __CLASS__, 'venue_columns' ] );
            add_action( 'manage_' . self::VENUE_POST_TYPE . '_posts_custom_column', [ __CLASS__, 'render_venue_column' ], 10, 2 );
        }

        /**
         * Registra los CPT administrativos utilizados por el módulo.
         */
        public static function register_post_types() {
            register_post_type( self::PRESET_POST_TYPE, [
                'labels' => [
                    'name'               => 'Preconfiguraciones',
                    'singular_name'      => 'Preconfiguración',
                    'menu_name'          => 'Preconfiguraciones',
                    'all_items'          => 'Preconfiguraciones',
                    'edit_item'          => 'Editar Preconfiguración',
                    'view_item'          => 'Ver Preconfiguración',
                    'search_items'       => 'Buscar Preconfiguraciones',
                    'not_found'          => 'No se encontraron preconfiguraciones',
                    'not_found_in_trash' => 'No se encontraron preconfiguraciones en la papelera',
                ],
                'public'              => false,
                'publicly_queryable'  => false,
                'exclude_from_search' => true,
                'show_ui'             => true,
                'show_in_menu'        => false,
                'show_in_rest'        => false,
                'supports'            => [ 'title' ],
                'has_archive'         => false,
                'rewrite'             => false,
                'map_meta_cap'        => true,
                'capability_type'     => 'post',
                // Las nuevas preconfiguraciones se crean desde un evento ya configurado.
                'capabilities'        => [
                    'create_posts' => 'do_not_allow',
                ],
            ] );

            register_post_type( self::VENUE_POST_TYPE, [
                'labels' => [
                    'name'               => 'Ubicaciones',
                    'singular_name'      => 'Ubicación',
                    'menu_name'          => 'Ubicaciones',
                    'add_new'            => 'Agregar Nueva',
                    'add_new_item'       => 'Agregar Nueva Ubicación',
                    'edit_item'          => 'Editar Ubicación',
                    'new_item'           => 'Nueva Ubicación',
                    'view_item'          => 'Ver Ubicación',
                    'search_items'       => 'Buscar Ubicaciones',
                    'not_found'          => 'No se encontraron ubicaciones',
                    'not_found_in_trash' => 'No se encontraron ubicaciones en la papelera',
                ],
                'public'              => false,
                'publicly_queryable'  => false,
                'exclude_from_search' => true,
                'show_ui'             => true,
                'show_in_menu'        => false,
                'show_in_rest'        => false,
                'supports'            => [ 'title' ],
                'has_archive'         => false,
                'rewrite'             => false,
                'map_meta_cap'        => true,
                'capability_type'     => 'post',
            ] );
        }

        /**
         * Agrega los enlaces reales al menú principal de EventosApp.
         */
        public static function register_submenus() {
            add_submenu_page(
                'eventosapp_dashboard',
                'Preconfiguraciones de Eventos',
                'Preconfiguraciones',
                'manage_options',
                'edit.php?post_type=' . self::PRESET_POST_TYPE
            );

            add_submenu_page(
                'eventosapp_dashboard',
                'Ubicaciones de Eventos',
                'Ubicaciones',
                'manage_options',
                'edit.php?post_type=' . self::VENUE_POST_TYPE
            );
        }

        /**
         * Mantiene resaltado el menú de EventosApp al editar los CPT del módulo.
         */
        public static function set_admin_parent_file( $parent_file ) {
            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
            if ( $screen && in_array( $screen->post_type, [ self::PRESET_POST_TYPE, self::VENUE_POST_TYPE ], true ) ) {
                return 'eventosapp_dashboard';
            }
            return $parent_file;
        }

        /**
         * Registra metaboxes del evento, preconfiguración, ubicación y cliente.
         */
        public static function register_meta_boxes() {
            add_meta_box(
                'eventosapp_event_preset_tools',
                'Preconfiguración del Evento',
                [ __CLASS__, 'render_event_preset_metabox' ],
                'eventosapp_event',
                'normal',
                'high'
            );

            add_meta_box(
                'eventosapp_event_preset_details',
                'Datos de la Preconfiguración',
                [ __CLASS__, 'render_preset_details_metabox' ],
                self::PRESET_POST_TYPE,
                'normal',
                'high'
            );

            add_meta_box(
                'eventosapp_venue_details',
                'Dirección y Coordenadas',
                [ __CLASS__, 'render_venue_details_metabox' ],
                self::VENUE_POST_TYPE,
                'normal',
                'high'
            );

            add_meta_box(
                'eventosapp_cliente_presets_venues',
                'Preconfiguraciones y Ubicaciones Asociadas',
                [ __CLASS__, 'render_client_links_metabox' ],
                'eventosapp_cliente',
                'normal',
                'default'
            );
        }

        /**
         * Obtiene clientes publicados ordenados por nombre.
         */
        private static function get_clients() {
            return get_posts( [
                'post_type'      => 'eventosapp_cliente',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ] );
        }

        /**
         * Nombre legible del cliente.
         */
        private static function get_client_name( $client_id ) {
            $client_id = absint( $client_id );
            if ( ! $client_id || get_post_type( $client_id ) !== 'eventosapp_cliente' ) {
                return '';
            }

            $name = get_post_meta( $client_id, '_cliente_nombre_empresa', true );
            if ( ! $name ) {
                $name = get_the_title( $client_id );
            }
            return sanitize_text_field( (string) $name );
        }

        /**
         * Render del selector de clientes reutilizado en varios metaboxes.
         */
        private static function render_client_select( $name, $selected, $id = '', $allow_global = false ) {
            $clients = self::get_clients();
            $id_attr = $id ? ' id="' . esc_attr( $id ) . '"' : '';
            echo '<select name="' . esc_attr( $name ) . '"' . $id_attr . ' class="widefat">';
            echo '<option value="">' . ( $allow_global ? '— Ubicación general / sin cliente —' : '— Seleccionar cliente —' ) . '</option>';
            foreach ( $clients as $client_id ) {
                $client_name = self::get_client_name( $client_id );
                if ( $client_name === '' ) {
                    continue;
                }
                echo '<option value="' . esc_attr( $client_id ) . '" ' . selected( absint( $selected ), $client_id, false ) . '>' . esc_html( $client_name ) . '</option>';
            }
            echo '</select>';
        }

        /**
         * Metabox principal dentro del editor de eventos.
         */
        public static function render_event_preset_metabox( $post ) {
            $event_id         = absint( $post->ID );
            $current_client   = absint( get_post_meta( $event_id, '_eventosapp_cliente_id', true ) );
            $last_preset_id   = absint( get_post_meta( $event_id, '_eventosapp_preset_applied_id', true ) );
            $last_applied_at  = get_post_meta( $event_id, '_eventosapp_preset_applied_at', true );
            $presets          = get_posts( [
                'post_type'      => self::PRESET_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ] );

            wp_nonce_field( self::EVENT_NONCE, 'eventosapp_event_presets_nonce' );
            ?>
            <style>
                .evapp-preset-box{border:1px solid #c3c4c7;border-radius:10px;background:#fff;padding:14px}
                .evapp-preset-intro{margin:0 0 14px;color:#50575e;line-height:1.5}
                .evapp-preset-grid{display:grid;grid-template-columns:minmax(220px,1fr) minmax(260px,1.3fr);gap:14px}
                .evapp-preset-field{min-width:0}
                .evapp-preset-field label{display:block;font-weight:700;margin-bottom:5px;color:#1d2327}
                .evapp-preset-field input,.evapp-preset-field select{width:100%;max-width:100%}
                .evapp-preset-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px;padding-top:12px;border-top:1px solid #e5e5e5}
                .evapp-preset-note{margin:12px 0 0;padding:10px 12px;border-radius:8px;background:#f0f6fc;border:1px solid #c5d9ed;color:#0a4b78;font-size:12px;line-height:1.5}
                .evapp-preset-status{margin:0 0 12px;padding:9px 11px;border-radius:8px;background:#f6f7f7;color:#50575e;font-size:12px}
                @media(max-width:782px){.evapp-preset-grid{grid-template-columns:1fr}.evapp-preset-actions .button{width:100%;text-align:center}}
            </style>

            <div class="evapp-preset-box">
                <p class="evapp-preset-intro">
                    Guarda la configuración reutilizable de este evento para una empresa cliente o carga una configuración existente. La preconfiguración conserva ajustes, diseños, campos, localidades y reglas, pero no copia el título, las fechas, enlaces virtuales únicos, slugs generados, personal operativo, tickets ni estadísticas.
                </p>

                <?php if ( $last_preset_id && get_post_type( $last_preset_id ) === self::PRESET_POST_TYPE ) : ?>
                    <p class="evapp-preset-status">
                        Última preconfiguración aplicada:
                        <strong><?php echo esc_html( get_the_title( $last_preset_id ) ); ?></strong>
                        <?php if ( $last_applied_at ) : ?>
                            · <?php echo esc_html( $last_applied_at ); ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <div class="evapp-preset-grid">
                    <div class="evapp-preset-field">
                        <label for="eventosapp_preset_client_id">Cliente / empresa</label>
                        <?php self::render_client_select( 'eventosapp_preset_client_id', $current_client, 'eventosapp_preset_client_id', false ); ?>
                    </div>

                    <div class="evapp-preset-field">
                        <label for="eventosapp_selected_preset_id">Preconfiguración disponible</label>
                        <select name="eventosapp_selected_preset_id" id="eventosapp_selected_preset_id" class="widefat">
                            <option value="">— Seleccionar preconfiguración —</option>
                            <?php foreach ( $presets as $preset_id ) :
                                $preset_client = absint( get_post_meta( $preset_id, '_eventosapp_preset_client_id', true ) );
                                $count         = absint( get_post_meta( $preset_id, '_eventosapp_preset_meta_count', true ) );
                                ?>
                                <option
                                    value="<?php echo esc_attr( $preset_id ); ?>"
                                    data-client-id="<?php echo esc_attr( $preset_client ); ?>"
                                >
                                    <?php echo esc_html( get_the_title( $preset_id ) ); ?><?php echo $count ? ' · ' . esc_html( $count ) . ' ajustes' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="evapp-preset-field">
                        <label for="eventosapp_new_preset_name">Nombre para guardar una nueva preconfiguración</label>
                        <input type="text" id="eventosapp_new_preset_name" name="eventosapp_new_preset_name" value="" placeholder="Ej: Evento corporativo estándar 2026">
                    </div>
                </div>

                <div class="evapp-preset-actions">
                    <button type="submit" class="button button-primary" name="eventosapp_apply_preset" value="1" id="eventosapp_apply_preset">Aplicar preconfiguración y guardar</button>
                    <button type="submit" class="button" name="eventosapp_create_preset" value="1" id="eventosapp_create_preset">Guardar este evento como nueva preconfiguración</button>
                    <button type="submit" class="button" name="eventosapp_update_preset" value="1" id="eventosapp_update_preset">Actualizar la preconfiguración seleccionada</button>
                    <a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::PRESET_POST_TYPE ) ); ?>">Administrar preconfiguraciones</a>
                </div>

                <p class="evapp-preset-note">
                    Las acciones anteriores guardan primero todos los metaboxes del evento y luego crean, actualizan o aplican la preconfiguración. Así se evita capturar valores antiguos o incompletos.
                </p>
            </div>
            <?php
        }

        /**
         * Metabox informativo/administrativo de una preconfiguración.
         */
        public static function render_preset_details_metabox( $post ) {
            $client_id      = absint( get_post_meta( $post->ID, '_eventosapp_preset_client_id', true ) );
            $source_event   = absint( get_post_meta( $post->ID, '_eventosapp_preset_source_event_id', true ) );
            $captured_at    = get_post_meta( $post->ID, '_eventosapp_preset_captured_at', true );
            $meta_count     = absint( get_post_meta( $post->ID, '_eventosapp_preset_meta_count', true ) );
            $configuration  = get_post_meta( $post->ID, '_eventosapp_preset_configuration', true );
            $meta_keys      = is_array( $configuration ) && ! empty( $configuration['meta'] ) && is_array( $configuration['meta'] )
                ? array_keys( $configuration['meta'] )
                : [];

            wp_nonce_field( 'eventosapp_save_preset_post', 'eventosapp_preset_post_nonce' );
            ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="eventosapp_preset_post_client_id">Cliente / empresa</label></th>
                    <td>
                        <?php self::render_client_select( 'eventosapp_preset_post_client_id', $client_id, 'eventosapp_preset_post_client_id', false ); ?>
                        <p class="description">La preconfiguración solo se mostrará como opción principal para este cliente.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Evento de origen</th>
                    <td>
                        <?php if ( $source_event && get_post_type( $source_event ) === 'eventosapp_event' ) : ?>
                            <a href="<?php echo esc_url( get_edit_post_link( $source_event ) ); ?>"><?php echo esc_html( get_the_title( $source_event ) ); ?></a>
                        <?php else : ?>
                            <span class="description">El evento de origen ya no está disponible.</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Última captura</th>
                    <td><?php echo $captured_at ? esc_html( $captured_at ) : 'Sin captura'; ?></td>
                </tr>
                <tr>
                    <th scope="row">Ajustes guardados</th>
                    <td>
                        <strong><?php echo esc_html( $meta_count ); ?></strong>
                        <?php if ( $meta_keys ) : ?>
                            <details style="margin-top:8px">
                                <summary>Ver claves incluidas</summary>
                                <code style="display:block;margin-top:8px;white-space:normal;line-height:1.65"><?php echo esc_html( implode( ', ', $meta_keys ) ); ?></code>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <p class="description" style="padding:10px 12px;background:#f0f6fc;border-left:4px solid #2271b1">
                Para volver a capturar la configuración, abre un evento, selecciona esta preconfiguración y usa <strong>Actualizar la preconfiguración seleccionada</strong>.
            </p>
            <?php
        }

        /**
         * Metabox de dirección y coordenadas de una ubicación reutilizable.
         */
        public static function render_venue_details_metabox( $post ) {
            $address     = get_post_meta( $post->ID, '_eventosapp_venue_address', true );
            $coordinates = get_post_meta( $post->ID, '_eventosapp_venue_coordinates', true );
            $client_id   = absint( get_post_meta( $post->ID, '_eventosapp_venue_client_id', true ) );
            $notes       = get_post_meta( $post->ID, '_eventosapp_venue_notes', true );

            wp_nonce_field( 'eventosapp_save_venue_post', 'eventosapp_venue_post_nonce' );
            ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="eventosapp_venue_address">Dirección completa</label></th>
                    <td>
                        <input type="text" class="large-text" id="eventosapp_venue_address" name="eventosapp_venue_address" value="<?php echo esc_attr( $address ); ?>" placeholder="Ej: Centro Comercial Blue Gardens, Cra. 53 #100-50, Barranquilla">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="eventosapp_venue_coordinates">Coordenadas Google Maps</label></th>
                    <td>
                        <input type="text" class="large-text" id="eventosapp_venue_coordinates" name="eventosapp_venue_coordinates" value="<?php echo esc_attr( $coordinates ); ?>" placeholder="Ej: 11.011674,-74.831248">
                        <p class="description">Formato obligatorio: latitud,longitud. La latitud debe estar entre -90 y 90 y la longitud entre -180 y 180.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="eventosapp_venue_client_id">Cliente asociado</label></th>
                    <td>
                        <?php self::render_client_select( 'eventosapp_venue_client_id', $client_id, 'eventosapp_venue_client_id', true ); ?>
                        <p class="description">Las ubicaciones generales también aparecen en eventos de cualquier cliente.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="eventosapp_venue_notes">Notas internas</label></th>
                    <td><textarea class="large-text" rows="4" id="eventosapp_venue_notes" name="eventosapp_venue_notes"><?php echo esc_textarea( $notes ); ?></textarea></td>
                </tr>
            </table>
            <?php
        }

        /**
         * Lista recursos relacionados directamente en la ficha del cliente.
         */
        public static function render_client_links_metabox( $post ) {
            $client_id = absint( $post->ID );
            $presets   = get_posts( [
                'post_type'      => self::PRESET_POST_TYPE,
                'post_status'    => [ 'publish', 'draft' ],
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'meta_key'       => '_eventosapp_preset_client_id',
                'meta_value'     => $client_id,
                'no_found_rows'  => true,
            ] );
            $venues    = get_posts( [
                'post_type'      => self::VENUE_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'meta_key'       => '_eventosapp_venue_client_id',
                'meta_value'     => $client_id,
                'no_found_rows'  => true,
            ] );
            ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">
                <div style="border:1px solid #dcdcde;border-radius:8px;padding:12px;background:#fff">
                    <h3 style="margin-top:0">Preconfiguraciones</h3>
                    <?php if ( $presets ) : ?>
                        <ul style="margin-bottom:0">
                            <?php foreach ( $presets as $preset_id ) : ?>
                                <li><a href="<?php echo esc_url( get_edit_post_link( $preset_id ) ); ?>"><?php echo esc_html( get_the_title( $preset_id ) ); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else : ?>
                        <p class="description">Aún no hay preconfiguraciones asociadas.</p>
                    <?php endif; ?>
                </div>
                <div style="border:1px solid #dcdcde;border-radius:8px;padding:12px;background:#fff">
                    <h3 style="margin-top:0">Ubicaciones</h3>
                    <?php if ( $venues ) : ?>
                        <ul style="margin-bottom:0">
                            <?php foreach ( $venues as $venue_id ) : ?>
                                <li><a href="<?php echo esc_url( get_edit_post_link( $venue_id ) ); ?>"><?php echo esc_html( get_the_title( $venue_id ) ); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else : ?>
                        <p class="description">Aún no hay ubicaciones asociadas.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }

        /**
         * Guarda únicamente la relación editable de la preconfiguración con el cliente.
         */
        public static function save_preset_post( $post_id ) {
            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }
            if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
                return;
            }
            if ( empty( $_POST['eventosapp_preset_post_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eventosapp_preset_post_nonce'] ) ), 'eventosapp_save_preset_post' ) ) {
                return;
            }

            $client_id = absint( $_POST['eventosapp_preset_post_client_id'] ?? 0 );
            if ( $client_id && get_post_type( $client_id ) === 'eventosapp_cliente' ) {
                update_post_meta( $post_id, '_eventosapp_preset_client_id', $client_id );
                return;
            }

            self::set_notice( 'error', 'La preconfiguración debe quedar asignada a un cliente válido.' );
        }

        /**
         * Guarda los datos del CPT Ubicación.
         */
        public static function save_venue_post( $post_id ) {
            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }
            if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
                return;
            }
            if ( empty( $_POST['eventosapp_venue_post_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eventosapp_venue_post_nonce'] ) ), 'eventosapp_save_venue_post' ) ) {
                return;
            }

            $address_raw = sanitize_text_field( wp_unslash( $_POST['eventosapp_venue_address'] ?? '' ) );
            $coords_raw  = sanitize_text_field( wp_unslash( $_POST['eventosapp_venue_coordinates'] ?? '' ) );
            $coordinates = self::normalize_coordinates( $coords_raw );
            $client_id   = absint( $_POST['eventosapp_venue_client_id'] ?? 0 );

            if ( $coords_raw !== '' && $coordinates === '' ) {
                self::set_notice( 'error', 'Las coordenadas no se guardaron porque el formato o el rango no es válido. Usa latitud,longitud.' );
            } else {
                update_post_meta( $post_id, '_eventosapp_venue_coordinates', $coordinates );
            }

            update_post_meta( $post_id, '_eventosapp_venue_address', $address_raw );
            update_post_meta( $post_id, '_eventosapp_venue_client_id', ( $client_id && get_post_type( $client_id ) === 'eventosapp_cliente' ) ? $client_id : 0 );
            update_post_meta( $post_id, '_eventosapp_venue_notes', sanitize_textarea_field( wp_unslash( $_POST['eventosapp_venue_notes'] ?? '' ) ) );

            if ( trim( get_the_title( $post_id ) ) === '' && $address_raw !== '' ) {
                remove_action( 'save_post_' . self::VENUE_POST_TYPE, [ __CLASS__, 'save_venue_post' ], 20 );
                wp_update_post( [
                    'ID'         => $post_id,
                    'post_title' => wp_trim_words( $address_raw, 8, '' ),
                ] );
                add_action( 'save_post_' . self::VENUE_POST_TYPE, [ __CLASS__, 'save_venue_post' ], 20 );
            }
        }

        /**
         * Maneja la vinculación de ubicación y las acciones de preconfiguración.
         * Se ejecuta después de los demás guardados del evento para capturar el estado final.
         */
        public static function handle_event_save( $post_id ) {
            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }
            if ( wp_is_post_revision( $post_id ) || get_post_type( $post_id ) !== 'eventosapp_event' ) {
                return;
            }
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return;
            }
            if ( empty( $_POST['eventosapp_event_presets_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eventosapp_event_presets_nonce'] ) ), self::EVENT_NONCE ) ) {
                return;
            }

            self::save_event_venue_link( $post_id );

            $client_id = absint( $_POST['eventosapp_preset_client_id'] ?? get_post_meta( $post_id, '_eventosapp_cliente_id', true ) );
            $preset_id = absint( $_POST['eventosapp_selected_preset_id'] ?? 0 );

            if ( isset( $_POST['eventosapp_apply_preset'] ) ) {
                $result = self::apply_preset_to_event( $preset_id, $post_id );
                self::set_notice( $result['ok'] ? 'success' : 'error', $result['message'] );
                return;
            }

            if ( isset( $_POST['eventosapp_create_preset'] ) ) {
                $name = sanitize_text_field( wp_unslash( $_POST['eventosapp_new_preset_name'] ?? '' ) );
                $result = self::capture_event_as_preset( $post_id, $client_id, $name, 0 );
                self::set_notice( $result['ok'] ? 'success' : 'error', $result['message'] );
                return;
            }

            if ( isset( $_POST['eventosapp_update_preset'] ) ) {
                $name = sanitize_text_field( wp_unslash( $_POST['eventosapp_new_preset_name'] ?? '' ) );
                $result = self::capture_event_as_preset( $post_id, $client_id, $name, $preset_id );
                self::set_notice( $result['ok'] ? 'success' : 'error', $result['message'] );
            }
        }

        /**
         * Vincula el evento a una ubicación solo si dirección y coordenadas siguen coincidiendo.
         */
        private static function save_event_venue_link( $event_id ) {
            // El campo lo agrega el asistente JS. Si por cualquier motivo no fue enviado,
            // se conserva la vinculación existente en lugar de borrarla accidentalmente.
            if ( ! array_key_exists( 'eventosapp_venue_id', $_POST ) ) {
                return;
            }

            $venue_id = absint( $_POST['eventosapp_venue_id'] ?? 0 );
            if ( ! $venue_id || get_post_type( $venue_id ) !== self::VENUE_POST_TYPE ) {
                delete_post_meta( $event_id, '_eventosapp_venue_id' );
                return;
            }

            $posted_address = sanitize_text_field( wp_unslash( $_POST['eventosapp_direccion'] ?? '' ) );
            $posted_coords  = self::normalize_coordinates( sanitize_text_field( wp_unslash( $_POST['eventosapp_coordenadas'] ?? '' ) ) );
            $venue_address  = sanitize_text_field( get_post_meta( $venue_id, '_eventosapp_venue_address', true ) );
            $venue_coords   = self::normalize_coordinates( get_post_meta( $venue_id, '_eventosapp_venue_coordinates', true ) );

            if ( self::normalize_search_text( $posted_address ) !== self::normalize_search_text( $venue_address ) || $posted_coords !== $venue_coords ) {
                delete_post_meta( $event_id, '_eventosapp_venue_id' );
                return;
            }

            update_post_meta( $event_id, '_eventosapp_venue_id', $venue_id );
        }

        /**
         * Crea o actualiza una preconfiguración a partir de un evento ya guardado.
         */
        private static function capture_event_as_preset( $event_id, $client_id, $name = '', $preset_id = 0 ) {
            $event_id  = absint( $event_id );
            $client_id = absint( $client_id );
            $preset_id = absint( $preset_id );

            if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
                return [ 'ok' => false, 'message' => 'No se pudo identificar el evento que se desea guardar.' ];
            }
            if ( ! $client_id || get_post_type( $client_id ) !== 'eventosapp_cliente' ) {
                return [ 'ok' => false, 'message' => 'Selecciona un cliente válido antes de guardar la preconfiguración.' ];
            }

            if ( $preset_id ) {
                if ( get_post_type( $preset_id ) !== self::PRESET_POST_TYPE || ! current_user_can( 'edit_post', $preset_id ) ) {
                    return [ 'ok' => false, 'message' => 'La preconfiguración seleccionada no es válida o no puede editarse.' ];
                }
                if ( $name === '' ) {
                    $name = get_the_title( $preset_id );
                }
            } elseif ( $name === '' ) {
                return [ 'ok' => false, 'message' => 'Escribe un nombre para la nueva preconfiguración.' ];
            }

            $snapshot = self::build_event_snapshot( $event_id, $client_id );
            if ( empty( $snapshot['meta'] ) ) {
                return [ 'ok' => false, 'message' => 'No se encontraron ajustes reutilizables para guardar.' ];
            }

            if ( $preset_id ) {
                wp_update_post( [
                    'ID'         => $preset_id,
                    'post_title' => $name,
                    'post_status'=> 'publish',
                ] );
            } else {
                $preset_id = wp_insert_post( [
                    'post_type'   => self::PRESET_POST_TYPE,
                    'post_status' => 'publish',
                    'post_title'  => $name,
                    'post_author' => get_current_user_id(),
                ], true );

                if ( is_wp_error( $preset_id ) ) {
                    return [ 'ok' => false, 'message' => 'No se pudo crear la preconfiguración: ' . $preset_id->get_error_message() ];
                }
            }

            update_post_meta( $preset_id, '_eventosapp_preset_client_id', $client_id );
            update_post_meta( $preset_id, '_eventosapp_preset_source_event_id', $event_id );
            update_post_meta( $preset_id, '_eventosapp_preset_configuration', $snapshot );
            update_post_meta( $preset_id, '_eventosapp_preset_captured_at', current_time( 'mysql' ) );
            update_post_meta( $preset_id, '_eventosapp_preset_meta_count', count( $snapshot['meta'] ) );
            update_post_meta( $preset_id, '_eventosapp_preset_version', self::SNAPSHOT_VERSION );

            return [
                'ok'        => true,
                'preset_id' => absint( $preset_id ),
                'message'   => 'La preconfiguración “' . $name . '” se guardó con ' . count( $snapshot['meta'] ) . ' ajustes reutilizables.',
            ];
        }

        /**
         * Construye una fotografía controlada de los metadatos configurables del evento.
         */
        private static function build_event_snapshot( $event_id, $client_id ) {
            $all_meta = get_post_meta( $event_id );
            $snapshot = [];

            foreach ( array_keys( $all_meta ) as $meta_key ) {
                if ( ! self::is_copyable_meta_key( $meta_key, $event_id ) ) {
                    continue;
                }

                $value = get_post_meta( $event_id, $meta_key, true );
                if ( strlen( maybe_serialize( $value ) ) > 2 * MB_IN_BYTES ) {
                    continue;
                }

                $snapshot[ $meta_key ] = self::prepare_snapshot_value( $meta_key, $value, $event_id );
            }

            // La asignación al cliente elegida en el metabox prevalece sobre el evento de origen.
            $snapshot['_eventosapp_usar_cliente'] = '1';
            $snapshot['_eventosapp_cliente_id']   = absint( $client_id );
            $client_name = self::get_client_name( $client_id );
            if ( $client_name !== '' ) {
                $snapshot['_eventosapp_organizador'] = $client_name;
            }
            $client_email = sanitize_email( get_post_meta( $client_id, '_cliente_email', true ) );
            $client_phone = sanitize_text_field( get_post_meta( $client_id, '_cliente_telefono', true ) );
            if ( $client_email !== '' ) {
                $snapshot['_eventosapp_organizador_email'] = $client_email;
            }
            if ( $client_phone !== '' ) {
                $snapshot['_eventosapp_organizador_tel'] = $client_phone;
            }

            return [
                'version'         => self::SNAPSHOT_VERSION,
                'source_event_id' => absint( $event_id ),
                'client_id'       => absint( $client_id ),
                'captured_at'     => current_time( 'mysql' ),
                'meta'            => $snapshot,
            ];
        }

        /**
         * Lista exacta de metas principales que son seguras y útiles para reutilizar.
         */
        private static function exact_copyable_meta_keys() {
            return [
                '_thumbnail_id',
                '_eventosapp_hora_inicio',
                '_eventosapp_hora_cierre',
                '_eventosapp_zona_horaria',
                '_eventosapp_event_modalidad',
                '_eventosapp_direccion',
                '_eventosapp_coordenadas',
                '_eventosapp_venue_id',
                '_eventosapp_virtual_platform',
                '_eventosapp_virtual_access_use_landing',
                '_eventosapp_usar_cliente',
                '_eventosapp_cliente_id',
                '_eventosapp_organizador',
                '_eventosapp_organizador_email',
                '_eventosapp_organizador_tel',
                '_eventosapp_localidades',
                '_eventosapp_networking_global_auth_fields',
                '_eventosapp_extra_fields',
                '_eventosapp_wallet_custom_enable',
                '_eventosapp_wallet_logo_url',
                '_eventosapp_wallet_hero_img_url',
                '_eventosapp_wallet_hex_color',
                '_eventosapp_ticket_pdf',
                '_eventosapp_ticket_ics',
                '_eventosapp_ticket_wallet_android',
                '_eventosapp_ticket_wallet_apple',
                '_eventosapp_ticket_variants_enabled',
                '_eventosapp_ticket_variants_config',
                '_eventosapp_ticket_whatsapp_enabled',
                '_eventosapp_ticket_verify_email',
                '_eventosapp_ticket_auto_email_public',
                '_eventosapp_ticket_auto_email_manual',
                '_eventosapp_ticket_use_preprinted_qr',
                '_eventosapp_ticket_use_preprinted_qr_networking',
                '_eventosapp_ticket_double_auth_enabled',
                '_eventosapp_ticket_control_pago',
                '_eventosapp_ticket_vincular_asistente',
                '_eventosapp_ticket_acompanantes_checkin',
            ];
        }

        /**
         * Prefijos de módulos que guardan configuración, no información operativa.
         */
        private static function copyable_meta_prefixes() {
            return [
                '_eventosapp_self_checkin_',
                '_eventosapp_networking_global_auth_',
                '_eventosapp_wallet_',
                '_eventosapp_apple_',
                '_eventosapp_email_',
                '_eventosapp_reminder_',
                '_eventosapp_priv_',
                '_eventosapp_api_ac_',
                '_eventosapp_ac_',
                '_eventosapp_whatsapp_',
                '_eventosapp_ticket_variants_',
                '_eventosapp_webhook_',
                '_eventosapp_embed_',
                '_evapp_embed_',
                '_evapp_ac_',
                '_eventosapp_registration_',
                '_eventosapp_double_auth_',
                '_eventosapp_doble_auth_',
                '_eventosapp_ticket_double_auth_',
                '_eventosapp_notificacion_',
                '_eventosapp_notification_',
                '_eventosapp_form_',
                'eventosapp_badge_',
                'eventosapp_field_order_',
            ];
        }

        /**
         * Metas que nunca deben viajar de un evento a otro.
         */
        private static function excluded_meta_keys() {
            return [
                '_edit_lock',
                '_edit_last',
                '_eventosapp_fecha_unica',
                '_eventosapp_fecha_inicio',
                '_eventosapp_fecha_fin',
                '_eventosapp_fechas_noco',
                '_eventosapp_tipo_fecha',
                '_eventosapp_virtual_url',
                '_eventosapp_virtual_access_datetime',
                '_eventosapp_virtual_landing_path',
                '_eventosapp_wallet_class_id',
                '_eventosapp_wallet_google_object_id',
                '_eventosapp_wallet_google_class_id_effective',
                '_eventosapp_reminder_dispatch_stats',
                '_eventosapp_preset_applied_id',
                '_eventosapp_preset_applied_at',
            ];
        }

        /**
         * Decide si una meta puede guardarse dentro de la preconfiguración.
         */
        private static function is_copyable_meta_key( $meta_key, $event_id ) {
            $meta_key = (string) $meta_key;

            $filtered = apply_filters( 'eventosapp_event_preset_copy_meta_key', null, $meta_key, $event_id );
            if ( $filtered === true || $filtered === false ) {
                return $filtered;
            }

            if ( in_array( $meta_key, self::exact_copyable_meta_keys(), true ) ) {
                return true;
            }
            if ( in_array( $meta_key, self::excluded_meta_keys(), true ) ) {
                return false;
            }
            if ( strpos( $meta_key, '_wp_' ) === 0 ) {
                return false;
            }

            $lower = strtolower( $meta_key );
            $blocked_fragments = [
                '_log', '_stats', '_stat_', '_count', '_counter', '_click', '_last_', '_queue',
                '_batch', '_cache', '_lock', '_token', '_secret', '_nonce', '_page_id', '_post_id',
                '_created_at', '_updated_at', '_sent_', '_usage', '_processed', '_health', '_cron',
                '_job', '_run_id', 'staff', 'co_gestor', 'cogestor', 'co-gestor', 'operador',
                'operator', 'manager', 'permission', 'permiso', 'user_id', 'usuario_id', 'assignee',
                'assigned', 'asignado', 'expositor', 'support', 'asistencia', 'checklist', 'galeria',
                'ticket_ids', 'qr_codes', 'landing_path',
            ];
            foreach ( $blocked_fragments as $fragment ) {
                if ( strpos( $lower, $fragment ) !== false ) {
                    return false;
                }
            }

            foreach ( self::copyable_meta_prefixes() as $prefix ) {
                if ( strpos( $meta_key, $prefix ) === 0 ) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Elimina identificadores generados que no pueden reutilizarse en otro evento.
         */
        private static function prepare_snapshot_value( $meta_key, $value, $event_id ) {
            if ( $meta_key === '_eventosapp_ticket_variants_config' && is_array( $value ) ) {
                $value = self::remove_generated_variant_values( $value );
            }

            return apply_filters( 'eventosapp_event_preset_prepare_meta_value', $value, $meta_key, $event_id );
        }

        /**
         * Recorre la configuración de variantes y quita IDs generados para el evento de origen.
         */
        private static function remove_generated_variant_values( $value ) {
            if ( ! is_array( $value ) ) {
                return $value;
            }

            $blocked_keys = [
                'google_wallet_class_id',
                'google_wallet_class_auto',
                'google_wallet_class_source',
                'google_wallet_class_ids_autofilled_at',
                'updated_at',
            ];

            foreach ( array_keys( $value ) as $key ) {
                if ( in_array( (string) $key, $blocked_keys, true ) ) {
                    unset( $value[ $key ] );
                    continue;
                }
                if ( is_array( $value[ $key ] ) ) {
                    $value[ $key ] = self::remove_generated_variant_values( $value[ $key ] );
                }
            }

            return $value;
        }

        /**
         * Aplica una preconfiguración existente sobre un evento.
         */
        private static function apply_preset_to_event( $preset_id, $event_id ) {
            $preset_id = absint( $preset_id );
            $event_id  = absint( $event_id );

            if ( ! $preset_id || get_post_type( $preset_id ) !== self::PRESET_POST_TYPE ) {
                return [ 'ok' => false, 'message' => 'Selecciona una preconfiguración válida.' ];
            }
            if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
                return [ 'ok' => false, 'message' => 'No se pudo identificar el evento de destino.' ];
            }

            $configuration = get_post_meta( $preset_id, '_eventosapp_preset_configuration', true );
            if ( ! is_array( $configuration ) ) {
                return [ 'ok' => false, 'message' => 'La preconfiguración seleccionada no contiene datos válidos.' ];
            }

            $meta = isset( $configuration['meta'] ) && is_array( $configuration['meta'] )
                ? $configuration['meta']
                : $configuration;

            if ( empty( $meta ) ) {
                return [ 'ok' => false, 'message' => 'La preconfiguración seleccionada está vacía.' ];
            }

            $applied = 0;
            foreach ( $meta as $meta_key => $value ) {
                if ( ! is_string( $meta_key ) || ! self::is_copyable_meta_key( $meta_key, $event_id ) ) {
                    continue;
                }
                update_post_meta( $event_id, $meta_key, $value );
                $applied++;
            }

            $client_id = absint( get_post_meta( $preset_id, '_eventosapp_preset_client_id', true ) );
            if ( $client_id && get_post_type( $client_id ) === 'eventosapp_cliente' ) {
                update_post_meta( $event_id, '_eventosapp_usar_cliente', '1' );
                update_post_meta( $event_id, '_eventosapp_cliente_id', $client_id );
                $client_name = self::get_client_name( $client_id );
                if ( $client_name !== '' ) {
                    update_post_meta( $event_id, '_eventosapp_organizador', $client_name );
                }

                $client_email = sanitize_email( get_post_meta( $client_id, '_cliente_email', true ) );
                $client_phone = sanitize_text_field( get_post_meta( $client_id, '_cliente_telefono', true ) );
                if ( $client_email !== '' ) {
                    update_post_meta( $event_id, '_eventosapp_organizador_email', $client_email );
                }
                if ( $client_phone !== '' ) {
                    update_post_meta( $event_id, '_eventosapp_organizador_tel', $client_phone );
                }
            }

            $venue_id = absint( get_post_meta( $event_id, '_eventosapp_venue_id', true ) );
            if ( $venue_id && get_post_type( $venue_id ) === self::VENUE_POST_TYPE ) {
                update_post_meta( $event_id, '_eventosapp_direccion', get_post_meta( $venue_id, '_eventosapp_venue_address', true ) );
                update_post_meta( $event_id, '_eventosapp_coordenadas', get_post_meta( $venue_id, '_eventosapp_venue_coordinates', true ) );
            }

            if ( function_exists( 'eventosapp_ticket_variants_persist_auto_google_class_ids_for_event' ) ) {
                eventosapp_ticket_variants_persist_auto_google_class_ids_for_event( $event_id, 'preset_apply' );
            }

            update_post_meta( $event_id, '_eventosapp_preset_applied_id', $preset_id );
            update_post_meta( $event_id, '_eventosapp_preset_applied_at', current_time( 'mysql' ) );

            do_action( 'eventosapp_event_preset_applied', $event_id, $preset_id, $meta );

            return [
                'ok'      => true,
                'message' => 'Se aplicó “' . get_the_title( $preset_id ) . '” con ' . $applied . ' ajustes. Revisa y completa las fechas, enlaces únicos y datos específicos del nuevo evento.',
            ];
        }

        /**
         * Convierte coordenadas a un formato único y valida sus rangos.
         */
        private static function normalize_coordinates( $value ) {
            if ( is_array( $value ) || is_object( $value ) ) {
                return '';
            }

            $value = trim( sanitize_text_field( (string) $value ) );
            if ( $value === '' ) {
                return '';
            }

            if ( ! preg_match( '/^\s*(-?\d{1,3}(?:\.\d+)?)\s*[,;]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $value, $matches ) ) {
                return '';
            }

            $lat = (float) $matches[1];
            $lng = (float) $matches[2];
            if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
                return '';
            }

            $format = static function( $number ) {
                $formatted = number_format( (float) $number, 8, '.', '' );
                return rtrim( rtrim( $formatted, '0' ), '.' );
            };

            return $format( $lat ) . ',' . $format( $lng );
        }

        /**
         * Normalización simple para comparar direcciones sin tildes ni diferencias de mayúsculas.
         */
        private static function normalize_search_text( $value ) {
            $value = remove_accents( sanitize_text_field( (string) $value ) );
            $value = strtolower( trim( preg_replace( '/\s+/u', ' ', $value ) ) );
            return $value;
        }

        /**
         * Carga JS/CSS únicamente en el editor de eventos.
         */
        public static function enqueue_event_editor_assets( $hook_suffix ) {
            if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
                return;
            }

            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
            if ( ! $screen || $screen->post_type !== 'eventosapp_event' ) {
                return;
            }

            $event_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
            if ( ! $event_id && isset( $GLOBALS['post']->ID ) ) {
                $event_id = absint( $GLOBALS['post']->ID );
            }

            wp_register_style( 'eventosapp-event-presets-admin', false, [], '1.0.0' );
            wp_enqueue_style( 'eventosapp-event-presets-admin' );
            wp_add_inline_style( 'eventosapp-event-presets-admin', self::admin_css() );

            wp_register_script( 'eventosapp-event-presets-admin', false, [ 'jquery' ], '1.0.0', true );
            wp_enqueue_script( 'eventosapp-event-presets-admin' );

            $settings = [
                'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
                'nonce'            => wp_create_nonce( self::ADMIN_NONCE ),
                'currentVenueId'   => $event_id ? absint( get_post_meta( $event_id, '_eventosapp_venue_id', true ) ) : 0,
                'currentClientId'  => $event_id ? absint( get_post_meta( $event_id, '_eventosapp_cliente_id', true ) ) : 0,
                'venuesAdminUrl'   => admin_url( 'edit.php?post_type=' . self::VENUE_POST_TYPE ),
                'strings'          => [
                    'searching'       => 'Buscando ubicaciones…',
                    'noResults'       => 'No hay ubicaciones guardadas que coincidan.',
                    'minChars'        => 'Escribe al menos 2 caracteres para buscar.',
                    'save'            => 'Guardar como ubicación reutilizable',
                    'update'          => 'Actualizar ubicación guardada',
                    'saving'          => 'Guardando…',
                    'confirmApply'    => 'Se guardará el evento y luego se aplicará la preconfiguración seleccionada. Las fechas y datos únicos no se copiarán. ¿Continuar?',
                    'confirmUpdate'   => 'Se reemplazará la configuración guardada en la preconfiguración seleccionada por el estado actual de este evento. ¿Continuar?',
                ],
            ];

            wp_add_inline_script( 'eventosapp-event-presets-admin', 'window.EventosAppEventPresets = ' . wp_json_encode( $settings ) . ';', 'before' );
            wp_add_inline_script( 'eventosapp-event-presets-admin', self::admin_js() );
        }

        /**
         * CSS del asistente de ubicaciones y controles de preconfiguración.
         */
        private static function admin_css() {
            return <<<'CSS'
.evapp-venue-assistant{position:relative;margin-top:10px;padding:12px;border:1px solid #bfdbfe;border-radius:10px;background:#f8fbff}
.evapp-venue-assistant__header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:9px}
.evapp-venue-assistant__title{margin:0;font-size:13px;font-weight:700;color:#1e3a8a}
.evapp-venue-assistant__help{margin:3px 0 0;color:#475569;font-size:12px;line-height:1.4}
.evapp-venue-assistant__status{display:inline-flex;align-items:center;min-height:24px;padding:3px 8px;border-radius:999px;background:#e2e8f0;color:#334155;font-size:11px;font-weight:700;white-space:nowrap}
.evapp-venue-assistant__status.is-selected{background:#dcfce7;color:#166534}
.evapp-venue-assistant__save{display:grid;grid-template-columns:minmax(180px,1fr) auto auto;gap:8px;align-items:center;margin-top:10px}
.evapp-venue-assistant__save input{width:100%}
.evapp-venue-suggestions{position:absolute;left:12px;right:12px;top:100%;z-index:10010;display:none;max-height:320px;overflow:auto;margin-top:4px;border:1px solid #8c8f94;border-radius:8px;background:#fff;box-shadow:0 10px 24px rgba(0,0,0,.16)}
.evapp-venue-suggestions.is-open{display:block}
.evapp-venue-suggestion{display:block;width:100%;padding:10px 12px;border:0;border-bottom:1px solid #eee;background:#fff;text-align:left;cursor:pointer}
.evapp-venue-suggestion:last-child{border-bottom:0}
.evapp-venue-suggestion:hover,.evapp-venue-suggestion:focus,.evapp-venue-suggestion.is-active{background:#f0f6fc;outline:0}
.evapp-venue-suggestion strong{display:block;color:#1d2327;font-size:13px}
.evapp-venue-suggestion span{display:block;margin-top:2px;color:#50575e;font-size:12px;line-height:1.35}
.evapp-venue-message{padding:11px 12px;color:#646970;font-size:12px}
.evapp-venue-feedback{display:none;margin:8px 0 0;padding:8px 10px;border-radius:7px;font-size:12px}
.evapp-venue-feedback.is-success{display:block;background:#dcfce7;color:#166534}
.evapp-venue-feedback.is-error{display:block;background:#fee2e2;color:#991b1b}
@media(max-width:782px){.evapp-venue-assistant__header{flex-direction:column}.evapp-venue-assistant__save{grid-template-columns:1fr}.evapp-venue-assistant__save .button{width:100%;text-align:center}}
CSS;
        }

        /**
         * JS encapsulado: filtrado de presets, autocompletado y guardado de ubicaciones.
         */
        private static function admin_js() {
            return <<<'JS'
(function($){
    'use strict';

    var cfg = window.EventosAppEventPresets || {};
    var strings = cfg.strings || {};

    function filterPresetsByClient(){
        var clientId = String($('#eventosapp_preset_client_id').val() || '');
        var $select = $('#eventosapp_selected_preset_id');
        if (!$select.length) return;

        $select.find('option').each(function(){
            var $option = $(this);
            var optionClient = String($option.data('client-id') || '');
            var visible = !$option.val() || !clientId || optionClient === clientId;
            $option.prop('hidden', !visible).prop('disabled', !visible);
        });

        var $selected = $select.find('option:selected');
        if ($selected.length && $selected.prop('disabled')) {
            $select.val('');
        }
    }

    $('#eventosapp_preset_client_id').on('change', filterPresetsByClient);
    filterPresetsByClient();

    $(document).on('change', '#evapp_cliente_select', function(){
        var clientId = String($(this).val() || '');
        if (clientId) {
            $('#eventosapp_preset_client_id').val(clientId).trigger('change');
        }
    });

    $('#eventosapp_apply_preset').on('click', function(event){
        if (!$('#eventosapp_selected_preset_id').val()) {
            event.preventDefault();
            window.alert('Selecciona una preconfiguración antes de aplicarla.');
            return;
        }
        if (strings.confirmApply && !window.confirm(strings.confirmApply)) {
            event.preventDefault();
        }
    });

    $('#eventosapp_create_preset').on('click', function(event){
        if (!$('#eventosapp_preset_client_id').val()) {
            event.preventDefault();
            window.alert('Selecciona el cliente al que se asignará la preconfiguración.');
            return;
        }
        if (!$.trim($('#eventosapp_new_preset_name').val())) {
            event.preventDefault();
            window.alert('Escribe un nombre para la nueva preconfiguración.');
        }
    });

    $('#eventosapp_update_preset').on('click', function(event){
        if (!$('#eventosapp_selected_preset_id').val()) {
            event.preventDefault();
            window.alert('Selecciona la preconfiguración que deseas actualizar.');
            return;
        }
        if (strings.confirmUpdate && !window.confirm(strings.confirmUpdate)) {
            event.preventDefault();
        }
    });

    var $address = $('input[name="eventosapp_direccion"]').first();
    var $coords  = $('input[name="eventosapp_coordenadas"]').first();
    if (!$address.length || !$coords.length) return;

    $address.attr({autocomplete:'off','aria-autocomplete':'list','aria-expanded':'false'});
    var currentVenueId = parseInt(cfg.currentVenueId || 0, 10) || 0;
    var selectedAddress = $.trim($address.val());
    var selectedCoords = $.trim($coords.val());
    var activeIndex = -1;
    var results = [];
    var request = null;
    var timer = null;

    var $box = $('<div class="evapp-venue-assistant"></div>');
    var $header = $('<div class="evapp-venue-assistant__header"></div>');
    var $heading = $('<div><p class="evapp-venue-assistant__title">Ubicaciones guardadas</p><p class="evapp-venue-assistant__help">Escribe en el campo Dirección del Evento. Las coincidencias guardadas aparecerán automáticamente y al seleccionarlas se completarán también las coordenadas.</p></div>');
    var $status = $('<span class="evapp-venue-assistant__status"></span>');
    var $hidden = $('<input type="hidden" name="eventosapp_venue_id">').val(currentVenueId || '');
    var $suggestions = $('<div class="evapp-venue-suggestions" role="listbox"></div>');
    var $saveRow = $('<div class="evapp-venue-assistant__save"></div>');
    var $name = $('<input type="text" id="eventosapp_venue_name_inline" placeholder="Nombre corto, ej: Blue Gardens">');
    var $save = $('<button type="button" class="button button-secondary"></button>');
    var $admin = $('<a class="button" target="_blank" rel="noopener">Administrar ubicaciones</a>').attr('href', cfg.venuesAdminUrl || '#');
    var $feedback = $('<div class="evapp-venue-feedback" role="status"></div>');

    $header.append($heading, $status);
    $saveRow.append($name, $save, $admin);
    $box.append($header, $hidden, $saveRow, $feedback, $suggestions);

    var $muted = $coords.nextAll('.muted').first();
    if ($muted.length) $muted.after($box); else $coords.after($box);

    function updateStatus(){
        if (currentVenueId) {
            $status.text('Ubicación vinculada #' + currentVenueId).addClass('is-selected');
            $save.text(strings.update || 'Actualizar ubicación guardada');
        } else {
            $status.text('Texto libre').removeClass('is-selected');
            $save.text(strings.save || 'Guardar como ubicación reutilizable');
        }
    }
    updateStatus();

    function getClientId(){
        var useClient = $('#evapp_usar_cliente_cb').length ? $('#evapp_usar_cliente_cb').is(':checked') : true;
        var detailClient = useClient ? parseInt($('#evapp_cliente_select').val() || 0, 10) : 0;
        var presetClient = parseInt($('#eventosapp_preset_client_id').val() || 0, 10);
        return detailClient || presetClient || parseInt(cfg.currentClientId || 0, 10) || 0;
    }

    function closeSuggestions(){
        $suggestions.removeClass('is-open').empty();
        $address.attr('aria-expanded','false');
        activeIndex = -1;
        results = [];
    }

    function setFeedback(type, message){
        $feedback.removeClass('is-success is-error').addClass(type === 'success' ? 'is-success' : 'is-error').text(message || '');
    }

    function renderMessage(message){
        $suggestions.empty().append($('<div class="evapp-venue-message"></div>').text(message)).addClass('is-open');
        $address.attr('aria-expanded','true');
    }

    function renderResults(items){
        results = $.isArray(items) ? items : [];
        activeIndex = -1;
        $suggestions.empty();
        if (!results.length) {
            renderMessage(strings.noResults || 'No hay coincidencias.');
            return;
        }
        $.each(results, function(index, item){
            var $button = $('<button type="button" class="evapp-venue-suggestion" role="option"></button>').attr('data-index', index);
            $button.append($('<strong></strong>').text(item.name || item.address || 'Ubicación'));
            $button.append($('<span></span>').text(item.address || ''));
            var details = [];
            if (item.coordinates) details.push(item.coordinates);
            if (item.client_name) details.push(item.client_name);
            if (details.length) $button.append($('<span></span>').text(details.join(' · ')));
            $suggestions.append($button);
        });
        $suggestions.addClass('is-open');
        $address.attr('aria-expanded','true');
    }

    function chooseResult(item){
        if (!item) return;
        currentVenueId = parseInt(item.id || 0, 10) || 0;
        selectedAddress = item.address || '';
        selectedCoords = item.coordinates || '';
        $address.val(selectedAddress).trigger('change');
        $coords.val(selectedCoords).trigger('change');
        $hidden.val(currentVenueId || '');
        $name.val(item.name || '');
        updateStatus();
        setFeedback('success', 'Se cargó la dirección y sus coordenadas. Guarda o actualiza el evento para conservar la selección.');
        closeSuggestions();
    }

    function searchVenues(term){
        if (request && request.readyState !== 4) request.abort();
        renderMessage(strings.searching || 'Buscando…');
        request = $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'eventosapp_search_saved_venues',
                nonce: cfg.nonce,
                term: term,
                client_id: getClientId()
            }
        }).done(function(response){
            if (response && response.success) renderResults(response.data && response.data.items ? response.data.items : []);
            else renderMessage((response && response.data && response.data.message) || strings.noResults || 'No hay coincidencias.');
        }).fail(function(xhr, status){
            if (status !== 'abort') renderMessage('No se pudo consultar el listado de ubicaciones.');
        });
    }

    $address.on('input', function(){
        var term = $.trim($(this).val());
        if (term !== selectedAddress) {
            currentVenueId = 0;
            $hidden.val('');
            updateStatus();
        }
        window.clearTimeout(timer);
        if (term.length < 2) {
            if (term.length) renderMessage(strings.minChars || 'Escribe al menos 2 caracteres.'); else closeSuggestions();
            return;
        }
        timer = window.setTimeout(function(){ searchVenues(term); }, 220);
    });

    $coords.on('input', function(){
        if ($.trim($(this).val()) !== selectedCoords && currentVenueId) {
            currentVenueId = 0;
            $hidden.val('');
            updateStatus();
        }
    });

    $address.on('keydown', function(event){
        var $items = $suggestions.find('.evapp-venue-suggestion');
        if (!$items.length || !$suggestions.hasClass('is-open')) return;
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            activeIndex = Math.min(activeIndex + 1, $items.length - 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
        } else if (event.key === 'Enter' && activeIndex >= 0) {
            event.preventDefault();
            chooseResult(results[activeIndex]);
            return;
        } else if (event.key === 'Escape') {
            closeSuggestions();
            return;
        } else {
            return;
        }
        $items.removeClass('is-active').eq(activeIndex).addClass('is-active');
    });

    $suggestions.on('click', '.evapp-venue-suggestion', function(){
        chooseResult(results[parseInt($(this).attr('data-index'), 10)]);
    });

    $(document).on('mousedown', function(event){
        if (!$(event.target).closest($box).length && !$(event.target).is($address)) closeSuggestions();
    });

    $save.on('click', function(){
        var address = $.trim($address.val());
        var coordinates = $.trim($coords.val());
        var name = $.trim($name.val());
        if (!address || !coordinates) {
            setFeedback('error', 'Completa la dirección y las coordenadas antes de guardar la ubicación.');
            return;
        }
        $save.prop('disabled', true).text(strings.saving || 'Guardando…');
        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'eventosapp_save_event_venue',
                nonce: cfg.nonce,
                venue_id: currentVenueId,
                name: name,
                address: address,
                coordinates: coordinates,
                client_id: getClientId()
            }
        }).done(function(response){
            if (!response || !response.success) {
                setFeedback('error', (response && response.data && response.data.message) || 'No se pudo guardar la ubicación.');
                return;
            }
            var data = response.data || {};
            currentVenueId = parseInt(data.id || 0, 10) || 0;
            selectedAddress = data.address || address;
            selectedCoords = data.coordinates || coordinates;
            $hidden.val(currentVenueId || '');
            $address.val(selectedAddress);
            $coords.val(selectedCoords);
            $name.val(data.name || name);
            updateStatus();
            setFeedback('success', data.message || 'Ubicación guardada.');
        }).fail(function(){
            setFeedback('error', 'No se pudo conectar con WordPress para guardar la ubicación.');
        }).always(function(){
            $save.prop('disabled', false);
            updateStatus();
        });
    });
})(jQuery);
JS;
        }

        /**
         * AJAX: busca ubicaciones por título o dirección y prioriza las del cliente actual.
         */
        public static function ajax_search_saved_venues() {
            check_ajax_referer( self::ADMIN_NONCE, 'nonce' );
            if ( ! current_user_can( 'edit_posts' ) ) {
                wp_send_json_error( [ 'message' => 'No tienes permisos para consultar ubicaciones.' ], 403 );
            }

            $term      = sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) );
            $client_id = absint( $_POST['client_id'] ?? 0 );
            $term_length = function_exists( 'mb_strlen' ) ? mb_strlen( $term ) : strlen( $term );
            if ( $term_length < 2 ) {
                wp_send_json_success( [ 'items' => [] ] );
            }

            global $wpdb;
            $like = '%' . $wpdb->esc_like( $term ) . '%';
            $sql = "
                SELECT DISTINCT p.ID,
                    CASE
                        WHEN client_meta.meta_value = %d THEN 0
                        WHEN client_meta.meta_value IS NULL OR client_meta.meta_value = '' OR client_meta.meta_value = '0' THEN 1
                        ELSE 2
                    END AS client_priority
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} address_meta
                    ON address_meta.post_id = p.ID AND address_meta.meta_key = '_eventosapp_venue_address'
                LEFT JOIN {$wpdb->postmeta} client_meta
                    ON client_meta.post_id = p.ID AND client_meta.meta_key = '_eventosapp_venue_client_id'
                WHERE p.post_type = %s
                    AND p.post_status = 'publish'
                    AND (p.post_title LIKE %s OR address_meta.meta_value LIKE %s)
                ORDER BY client_priority ASC, p.post_title ASC
                LIMIT 20
            ";
            $ids = $wpdb->get_col( $wpdb->prepare( $sql, $client_id, self::VENUE_POST_TYPE, $like, $like ) );

            $items = [];
            foreach ( $ids as $venue_id ) {
                $venue_id    = absint( $venue_id );
                $venue_client= absint( get_post_meta( $venue_id, '_eventosapp_venue_client_id', true ) );
                $items[] = [
                    'id'          => $venue_id,
                    'name'        => get_the_title( $venue_id ),
                    'address'     => get_post_meta( $venue_id, '_eventosapp_venue_address', true ),
                    'coordinates' => get_post_meta( $venue_id, '_eventosapp_venue_coordinates', true ),
                    'client_id'   => $venue_client,
                    'client_name' => self::get_client_name( $venue_client ),
                ];
            }

            wp_send_json_success( [ 'items' => $items ] );
        }

        /**
         * AJAX: crea o actualiza una ubicación desde el editor del evento.
         */
        public static function ajax_save_event_venue() {
            check_ajax_referer( self::ADMIN_NONCE, 'nonce' );
            if ( ! current_user_can( 'edit_posts' ) ) {
                wp_send_json_error( [ 'message' => 'No tienes permisos para guardar ubicaciones.' ], 403 );
            }

            $venue_id   = absint( $_POST['venue_id'] ?? 0 );
            $name       = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
            $address    = sanitize_text_field( wp_unslash( $_POST['address'] ?? '' ) );
            $coords_raw = sanitize_text_field( wp_unslash( $_POST['coordinates'] ?? '' ) );
            $coordinates= self::normalize_coordinates( $coords_raw );
            $client_id  = absint( $_POST['client_id'] ?? 0 );

            if ( $address === '' ) {
                wp_send_json_error( [ 'message' => 'La dirección es obligatoria.' ], 400 );
            }
            if ( $coordinates === '' ) {
                wp_send_json_error( [ 'message' => 'Las coordenadas no son válidas. Usa el formato latitud,longitud.' ], 400 );
            }
            if ( $client_id && get_post_type( $client_id ) !== 'eventosapp_cliente' ) {
                $client_id = 0;
            }
            if ( $name === '' ) {
                $name = wp_trim_words( $address, 8, '' );
            }

            $is_update = false;
            $reused_duplicate = false;
            if ( $venue_id ) {
                if ( get_post_type( $venue_id ) !== self::VENUE_POST_TYPE || ! current_user_can( 'edit_post', $venue_id ) ) {
                    wp_send_json_error( [ 'message' => 'La ubicación seleccionada no puede actualizarse.' ], 403 );
                }
                $is_update = true;
                $updated = wp_update_post( [
                    'ID'         => $venue_id,
                    'post_title' => $name,
                    'post_status'=> 'publish',
                ], true );
                if ( is_wp_error( $updated ) ) {
                    wp_send_json_error( [ 'message' => $updated->get_error_message() ], 500 );
                }
            } else {
                $duplicate = get_posts( [
                    'post_type'      => self::VENUE_POST_TYPE,
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'meta_query'     => [
                        'relation' => 'AND',
                        [
                            'key'     => '_eventosapp_venue_address',
                            'value'   => $address,
                            'compare' => '=',
                        ],
                        [
                            'key'     => '_eventosapp_venue_coordinates',
                            'value'   => $coordinates,
                            'compare' => '=',
                        ],
                        [
                            'key'     => '_eventosapp_venue_client_id',
                            'value'   => $client_id,
                            'compare' => '=',
                            'type'    => 'NUMERIC',
                        ],
                    ],
                ] );

                if ( $duplicate ) {
                    $venue_id = absint( $duplicate[0] );
                    $reused_duplicate = true;
                } else {
                    $venue_id = wp_insert_post( [
                        'post_type'   => self::VENUE_POST_TYPE,
                        'post_status' => 'publish',
                        'post_title'  => $name,
                        'post_author' => get_current_user_id(),
                    ], true );
                    if ( is_wp_error( $venue_id ) ) {
                        wp_send_json_error( [ 'message' => 'No se pudo crear la ubicación: ' . $venue_id->get_error_message() ], 500 );
                    }
                }
            }

            update_post_meta( $venue_id, '_eventosapp_venue_address', $address );
            update_post_meta( $venue_id, '_eventosapp_venue_coordinates', $coordinates );
            update_post_meta( $venue_id, '_eventosapp_venue_client_id', $client_id );

            wp_send_json_success( [
                'id'          => absint( $venue_id ),
                'name'        => get_the_title( $venue_id ),
                'address'     => $address,
                'coordinates' => $coordinates,
                'client_id'   => $client_id,
                'client_name' => self::get_client_name( $client_id ),
                'message'     => $is_update
                    ? 'La ubicación guardada fue actualizada.'
                    : ( $reused_duplicate ? 'La ubicación ya existía y quedó vinculada al evento.' : 'La ubicación quedó guardada para reutilizarla en otros eventos.' ),
            ] );
        }

        /**
         * Columnas del listado de preconfiguraciones.
         */
        public static function preset_columns( $columns ) {
            return [
                'cb'            => $columns['cb'] ?? '<input type="checkbox">',
                'title'         => 'Preconfiguración',
                'preset_client' => 'Cliente',
                'preset_source' => 'Evento de origen',
                'preset_count'  => 'Ajustes',
                'date'          => 'Fecha',
            ];
        }

        public static function render_preset_column( $column, $post_id ) {
            if ( $column === 'preset_client' ) {
                $client_id = absint( get_post_meta( $post_id, '_eventosapp_preset_client_id', true ) );
                echo esc_html( self::get_client_name( $client_id ) ?: 'Sin cliente' );
            } elseif ( $column === 'preset_source' ) {
                $event_id = absint( get_post_meta( $post_id, '_eventosapp_preset_source_event_id', true ) );
                if ( $event_id && get_post_type( $event_id ) === 'eventosapp_event' ) {
                    echo '<a href="' . esc_url( get_edit_post_link( $event_id ) ) . '">' . esc_html( get_the_title( $event_id ) ) . '</a>';
                } else {
                    echo '—';
                }
            } elseif ( $column === 'preset_count' ) {
                echo esc_html( absint( get_post_meta( $post_id, '_eventosapp_preset_meta_count', true ) ) );
            }
        }

        /**
         * Columnas del listado de ubicaciones.
         */
        public static function venue_columns( $columns ) {
            return [
                'cb'          => $columns['cb'] ?? '<input type="checkbox">',
                'title'       => 'Ubicación',
                'venue_client'=> 'Cliente',
                'venue_address'=> 'Dirección',
                'venue_coords'=> 'Coordenadas',
                'date'        => 'Fecha',
            ];
        }

        public static function render_venue_column( $column, $post_id ) {
            if ( $column === 'venue_client' ) {
                $client_id = absint( get_post_meta( $post_id, '_eventosapp_venue_client_id', true ) );
                echo esc_html( self::get_client_name( $client_id ) ?: 'General' );
            } elseif ( $column === 'venue_address' ) {
                echo esc_html( get_post_meta( $post_id, '_eventosapp_venue_address', true ) );
            } elseif ( $column === 'venue_coords' ) {
                echo '<code>' . esc_html( get_post_meta( $post_id, '_eventosapp_venue_coordinates', true ) ) . '</code>';
            }
        }

        /**
         * Notificaciones persistentes entre el guardado y la redirección de WordPress.
         */
        private static function set_notice( $type, $message ) {
            $type = in_array( $type, [ 'success', 'error', 'warning', 'info' ], true ) ? $type : 'info';
            set_transient( 'eventosapp_event_presets_notice_' . get_current_user_id(), [
                'type'    => $type,
                'message' => sanitize_text_field( $message ),
            ], MINUTE_IN_SECONDS );
        }

        public static function render_admin_notice() {
            $key    = 'eventosapp_event_presets_notice_' . get_current_user_id();
            $notice = get_transient( $key );
            if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
                return;
            }
            delete_transient( $key );

            $type = in_array( $notice['type'] ?? '', [ 'success', 'error', 'warning', 'info' ], true ) ? $notice['type'] : 'info';
            echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
        }
    }

    EventosApp_Event_Presets_And_Venues::init();
}
