<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Ajuste textual de la pantalla existente. No duplica el renderizador ni cambia
 * formularios/nonces; únicamente expresa la nueva semántica de limpieza y usa
 * el campo `issue` devuelto por el diagnóstico reforzado para cada fila.
 */
if ( ! function_exists('eventosapp_installation_clean_reinstall_admin_ui') ) {
    function eventosapp_installation_clean_reinstall_admin_ui() {
        if ( ! current_user_can('manage_options') ) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ( $page !== 'eventosapp_configuracion' ) {
            return;
        }

        $states = [];
        foreach (eventosapp_installation_page_registry() as $definition_id => $definition) {
            $status = eventosapp_installation_definition_status($definition_id, $definition);
            $states[sanitize_key($definition_id)] = [
                'issue' => sanitize_key($status['issue'] ?? ''),
                'elementor_artifacts' => array_values(array_map('sanitize_key', (array)($status['elementor_artifacts'] ?? []))),
            ];
        }
        ?>
        <script id="eventosapp-clean-reinstall-admin-ui">
        (function(){
            var shell = document.getElementById('eventosapp-installation');
            if (!shell) return;

            var states = <?php echo wp_json_encode($states); ?> || {};
            var intro = shell.querySelector('.evapp-install-head p');
            if (intro) {
                intro.textContent = 'Valida shortcode, mapeo y estado canónico de cada página. Una página correcta debe contener únicamente el shortcode de EventosApp y no conservar contenido, widgets ni datos de Elementor. La reparación y la reinstalación masiva limpian esos elementos solo dentro de las páginas controladas por este inventario.';
            }

            var bulkButton = shell.querySelector('form input[name="action"][value="eventosapp_install_all_pages"]');
            if (bulkButton && bulkButton.form) {
                var submit = bulkButton.form.querySelector('button[type="submit"]');
                if (submit) {
                    submit.textContent = submit.disabled
                        ? 'Reinstalación limpia en curso…'
                        : 'Reinstalar / normalizar todas en segundo plano';
                }
            }

            var jobTitle = document.getElementById('evapp-install-job-title');
            if (jobTitle) {
                jobTitle.textContent = jobTitle.textContent
                    .replace('Instalación automática', 'Reinstalación limpia')
                    .replace('instalación automática', 'reinstalación limpia')
                    .replace('Última instalación', 'Última reinstalación');
            }

            shell.querySelectorAll('.evapp-install-table tbody tr').forEach(function(row){
                var definitionInput = row.querySelector('input[name="definition_id"]');
                if (!definitionInput) return;

                var definitionId = definitionInput.value || '';
                var info = states[definitionId] || {};
                var issue = info.issue || '';
                var cells = row.querySelectorAll('td');
                var badge = cells.length > 5 ? cells[5].querySelector('.evapp-install-badge') : null;
                var button = cells.length > 6 ? cells[6].querySelector('button[type="submit"]') : null;

                if (issue === 'needs_cleanup') {
                    if (badge) badge.textContent = 'Mapeada · requiere limpieza';
                    if (button) button.textContent = 'Reinstalar en limpio';
                } else if (issue === 'missing_shortcode') {
                    if (badge) badge.textContent = 'Mapeada · falta shortcode';
                    if (button) button.textContent = 'Instalar en limpio';
                } else if (issue === 'needs_mapping_cleanup') {
                    if (badge) badge.textContent = 'Página detectada · limpiar y mapear';
                    if (button) button.textContent = 'Limpiar y mapear';
                } else if (issue === 'needs_mapping') {
                    if (badge) badge.textContent = 'Página limpia · falta mapear';
                    if (button) button.textContent = 'Mapear automáticamente';
                }

                if (info.elementor_artifacts && info.elementor_artifacts.length && badge) {
                    badge.setAttribute('title', 'Elementor detectado: ' + info.elementor_artifacts.join(', '));
                }
            });
        })();
        </script>
        <?php
    }
}
add_action('admin_footer', 'eventosapp_installation_clean_reinstall_admin_ui', 1000);
