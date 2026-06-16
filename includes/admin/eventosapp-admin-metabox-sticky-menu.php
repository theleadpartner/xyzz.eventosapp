<?php
/**
 * EventosApp - Menú sticky de metaboxes para eventos.
 *
 * Agrega un navegador interno en el editor del CPT eventosapp_event para listar
 * los metaboxes disponibles, buscarlos y desplazarse automáticamente al metabox
 * seleccionado sin modificar ni reemplazar los metaboxes existentes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'EventosApp_Admin_Metabox_Sticky_Menu' ) ) {
    class EventosApp_Admin_Metabox_Sticky_Menu {
        const POST_TYPE = 'eventosapp_event';
        const VERSION   = '1.0.1';

        /**
         * Registra los hooks del módulo.
         */
        public static function init() {
            add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 30 );
        }

        /**
         * Valida que estemos únicamente en la pantalla de creación/edición del evento.
         *
         * @param string $hook_suffix Hook de la pantalla actual del administrador.
         * @return bool
         */
        private static function should_load( $hook_suffix ) {
            if ( ! is_admin() ) {
                return false;
            }

            if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
                return false;
            }

            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
            if ( ! $screen || empty( $screen->post_type ) || self::POST_TYPE !== $screen->post_type ) {
                return false;
            }

            if ( ! current_user_can( 'edit_posts' ) ) {
                return false;
            }

            return true;
        }

        /**
         * Carga CSS y JS inline solo en el editor del CPT eventosapp_event.
         * No usa archivos externos para mantener esta mejora encapsulada en un único include.
         *
         * @param string $hook_suffix Hook de la pantalla actual del administrador.
         */
        public static function enqueue_assets( $hook_suffix ) {
            if ( ! self::should_load( $hook_suffix ) ) {
                return;
            }

            wp_register_style( 'eventosapp-admin-metabox-sticky-menu', false, [], self::VERSION );
            wp_enqueue_style( 'eventosapp-admin-metabox-sticky-menu' );
            wp_add_inline_style( 'eventosapp-admin-metabox-sticky-menu', self::get_css() );

            wp_register_script( 'eventosapp-admin-metabox-sticky-menu', false, [], self::VERSION, true );
            wp_enqueue_script( 'eventosapp-admin-metabox-sticky-menu' );

            $settings = [
                'title'             => 'Metaboxes del evento',
                'searchPlaceholder' => 'Buscar metabox...',
                'emptyText'         => 'No se encontraron metaboxes visibles en esta pantalla.',
                'noResultsText'     => 'No hay metaboxes que coincidan con la búsqueda.',
                'showText'          => 'Mostrar',
                'hideText'          => 'Ocultar',
                'countSingular'     => 'metabox visible',
                'countPlural'       => 'metaboxes visibles',
                'normalLabel'       => 'Principal',
                'advancedLabel'     => 'Avanzado',
                'sideLabel'         => 'Lateral',
                'otherLabel'        => 'Otro',
            ];

            wp_add_inline_script(
                'eventosapp-admin-metabox-sticky-menu',
                'window.EventosAppMetaboxStickyMenu = ' . wp_json_encode( $settings ) . ';',
                'before'
            );
            wp_add_inline_script( 'eventosapp-admin-metabox-sticky-menu', self::get_js() );
        }

        /**
         * CSS del navegador sticky.
         *
         * @return string
         */
        private static function get_css() {
            return <<<'CSS'
.eventosapp-metabox-sticky-menu {
    position: sticky;
    top: 42px;
    z-index: 999;
    margin: 14px 0 18px;
    padding: 12px;
    border: 1px solid #c3c4c7;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
}

.folded .eventosapp-metabox-sticky-menu {
    top: 8px;
}

.eventosapp-metabox-sticky-menu.is-collapsed .eventosapp-metabox-sticky-menu__body {
    display: none;
}

.eventosapp-metabox-sticky-menu__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.eventosapp-metabox-sticky-menu__title-wrap {
    min-width: 0;
}

.eventosapp-metabox-sticky-menu__title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    font-size: 14px;
    line-height: 1.35;
    font-weight: 700;
    color: #1d2327;
}

.eventosapp-metabox-sticky-menu__title .dashicons {
    width: 18px;
    height: 18px;
    font-size: 18px;
    color: #2271b1;
}

.eventosapp-metabox-sticky-menu__count {
    display: block;
    margin-top: 2px;
    font-size: 12px;
    color: #646970;
}

.eventosapp-metabox-sticky-menu__toggle.button {
    min-height: 30px;
    line-height: 28px;
    white-space: nowrap;
}

.eventosapp-metabox-sticky-menu__body {
    margin-top: 10px;
}

.eventosapp-metabox-sticky-menu__search-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
}

.eventosapp-metabox-sticky-menu__search {
    width: 100%;
    max-width: 520px;
    min-height: 34px;
}

.eventosapp-metabox-sticky-menu__clear.button {
    min-height: 34px;
    white-space: nowrap;
}

.eventosapp-metabox-sticky-menu__list {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    max-height: 34vh;
    overflow: auto;
    padding: 2px 2px 4px;
    overscroll-behavior: contain;
}

.eventosapp-metabox-sticky-menu__item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    max-width: 320px;
    min-height: 30px;
    padding: 4px 9px;
    border: 1px solid #c3c4c7;
    border-radius: 999px;
    background: #f6f7f7;
    color: #1d2327;
    cursor: pointer;
    font-size: 12px;
    line-height: 1.3;
    text-align: left;
    transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease, color .15s ease;
}

.eventosapp-metabox-sticky-menu__item:hover,
.eventosapp-metabox-sticky-menu__item:focus {
    border-color: #2271b1;
    background: #f0f6fc;
    color: #0a4b78;
    box-shadow: 0 0 0 1px #2271b1;
    outline: none;
}

.eventosapp-metabox-sticky-menu__item-title {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.eventosapp-metabox-sticky-menu__item-context {
    flex: 0 0 auto;
    padding: 1px 6px;
    border-radius: 999px;
    background: #e5e5e5;
    color: #50575e;
    font-size: 10px;
    line-height: 1.5;
    text-transform: uppercase;
    letter-spacing: .02em;
}

.eventosapp-metabox-sticky-menu__message {
    margin: 0;
    padding: 8px 10px;
    border-radius: 7px;
    background: #f6f7f7;
    color: #646970;
    font-size: 12px;
}

.eventosapp-metabox-sticky-menu__message[hidden] {
    display: none !important;
}

.postbox.eventosapp-metabox-sticky-menu__filtered-out {
    display: none !important;
}

.postbox.eventosapp-metabox-sticky-menu__highlight {
    animation: eventosappMetaboxPulse 1.6s ease-out 1;
    box-shadow: 0 0 0 2px #2271b1, 0 0 0 6px rgba(34, 113, 177, 0.16);
}

@keyframes eventosappMetaboxPulse {
    0% {
        box-shadow: 0 0 0 3px #2271b1, 0 0 0 10px rgba(34, 113, 177, 0.20);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(34, 113, 177, 0), 0 0 0 0 rgba(34, 113, 177, 0);
    }
}

@media screen and (max-width: 782px) {
    .eventosapp-metabox-sticky-menu {
        top: 58px;
        padding: 10px;
        border-radius: 8px;
    }

    .eventosapp-metabox-sticky-menu__header,
    .eventosapp-metabox-sticky-menu__search-row {
        align-items: stretch;
        flex-direction: column;
    }

    .eventosapp-metabox-sticky-menu__toggle.button,
    .eventosapp-metabox-sticky-menu__clear.button {
        width: 100%;
        text-align: center;
    }

    .eventosapp-metabox-sticky-menu__search {
        max-width: none;
    }

    .eventosapp-metabox-sticky-menu__list {
        display: block;
        max-height: 42vh;
    }

    .eventosapp-metabox-sticky-menu__item {
        justify-content: space-between;
        width: 100%;
        max-width: none;
        margin-bottom: 6px;
        border-radius: 8px;
    }
}
CSS;
        }

        /**
         * JavaScript del navegador sticky.
         *
         * @return string
         */
        private static function get_js() {
            return <<<'JS'
(function () {
    'use strict';

    var cfg = window.EventosAppMetaboxStickyMenu || {};
    var FILTERED_OUT_CLASS = 'eventosapp-metabox-sticky-menu__filtered-out';
    var state = {
        root: null,
        list: null,
        count: null,
        search: null,
        clear: null,
        noResults: null,
        empty: null,
        toggle: null,
        refreshTimer: null,
        observer: null,
        lastRenderedSignature: '',
        totalBoxes: 0
    };

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
            return;
        }
        callback();
    }

    function createElement(tag, attrs, text) {
        var el = document.createElement(tag);
        attrs = attrs || {};
        Object.keys(attrs).forEach(function (key) {
            if (key === 'className') {
                el.className = attrs[key];
            } else if (key === 'dataset') {
                Object.keys(attrs[key]).forEach(function (dataKey) {
                    el.dataset[dataKey] = attrs[key][dataKey];
                });
            } else {
                el.setAttribute(key, attrs[key]);
            }
        });
        if (typeof text === 'string') {
            el.textContent = text;
        }
        return el;
    }

    function getInsertionTarget() {
        return document.getElementById('poststuff') || document.getElementById('wpbody-content') || document.body;
    }

    function insertRoot(root) {
        var target = getInsertionTarget();
        if (!target || !root) {
            return;
        }

        if (target.id === 'poststuff') {
            target.insertBefore(root, target.firstChild);
            return;
        }

        var firstHeading = target.querySelector('.wrap h1, .wrap .wp-heading-inline, h1');
        if (firstHeading && firstHeading.parentNode) {
            firstHeading.parentNode.insertBefore(root, firstHeading.nextSibling);
            return;
        }

        target.insertBefore(root, target.firstChild);
    }

    function buildRoot() {
        if (state.root) {
            return state.root;
        }

        var root = createElement('div', {
            className: 'eventosapp-metabox-sticky-menu',
            id: 'eventosapp-metabox-sticky-menu',
            'aria-label': cfg.title || 'Metaboxes del evento'
        });

        var header = createElement('div', { className: 'eventosapp-metabox-sticky-menu__header' });
        var titleWrap = createElement('div', { className: 'eventosapp-metabox-sticky-menu__title-wrap' });
        var title = createElement('p', { className: 'eventosapp-metabox-sticky-menu__title' });
        var icon = createElement('span', { className: 'dashicons dashicons-index-card', 'aria-hidden': 'true' });
        var titleText = createElement('span', {}, cfg.title || 'Metaboxes del evento');
        var count = createElement('span', { className: 'eventosapp-metabox-sticky-menu__count' }, '');
        var toggle = createElement('button', { type: 'button', className: 'button eventosapp-metabox-sticky-menu__toggle', 'aria-expanded': 'true' }, cfg.hideText || 'Ocultar');

        title.appendChild(icon);
        title.appendChild(titleText);
        titleWrap.appendChild(title);
        titleWrap.appendChild(count);
        header.appendChild(titleWrap);
        header.appendChild(toggle);

        var body = createElement('div', { className: 'eventosapp-metabox-sticky-menu__body' });
        var searchRow = createElement('div', { className: 'eventosapp-metabox-sticky-menu__search-row' });
        var search = createElement('input', {
            type: 'search',
            className: 'regular-text eventosapp-metabox-sticky-menu__search',
            placeholder: cfg.searchPlaceholder || 'Buscar metabox...',
            'aria-label': cfg.searchPlaceholder || 'Buscar metabox...'
        });
        var clear = createElement('button', { type: 'button', className: 'button eventosapp-metabox-sticky-menu__clear' }, 'Limpiar');
        var list = createElement('div', { className: 'eventosapp-metabox-sticky-menu__list', role: 'list' });
        var empty = createElement('p', { className: 'eventosapp-metabox-sticky-menu__message' }, cfg.emptyText || 'No se encontraron metaboxes visibles en esta pantalla.');
        var noResults = createElement('p', { className: 'eventosapp-metabox-sticky-menu__message', hidden: 'hidden' }, cfg.noResultsText || 'No hay metaboxes que coincidan con la búsqueda.');

        searchRow.appendChild(search);
        searchRow.appendChild(clear);
        body.appendChild(searchRow);
        body.appendChild(list);
        body.appendChild(empty);
        body.appendChild(noResults);
        root.appendChild(header);
        root.appendChild(body);

        state.root = root;
        state.list = list;
        state.count = count;
        state.search = search;
        state.clear = clear;
        state.noResults = noResults;
        state.empty = empty;
        state.toggle = toggle;

        search.addEventListener('input', applyFilter);
        search.addEventListener('keydown', function (event) {
            var key = event.key || event.keyCode;
            if (key !== 'Enter' && key !== 13) {
                return;
            }

            event.preventDefault();
            applyFilter();
            activateFirstVisibleResult();
        });
        clear.addEventListener('click', function () {
            search.value = '';
            search.focus();
            applyFilter();
        });
        toggle.addEventListener('click', function () {
            var isCollapsed = root.classList.toggle('is-collapsed');
            toggle.textContent = isCollapsed ? (cfg.showText || 'Mostrar') : (cfg.hideText || 'Ocultar');
            toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
        });

        insertRoot(root);
        return root;
    }

    function normalizeText(value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    }

    function textMatchesSearch(haystack, query) {
        var normalizedHaystack = normalizeText(haystack);
        var normalizedQuery = normalizeText(query);

        if (!normalizedQuery) {
            return true;
        }

        return normalizedQuery.split(/\s+/).every(function (term) {
            return term && normalizedHaystack.indexOf(term) !== -1;
        });
    }

    function cleanTitleFromElement(titleElement) {
        if (!titleElement) {
            return '';
        }

        var clone = titleElement.cloneNode(true);
        Array.prototype.slice.call(clone.querySelectorAll('button, .screen-reader-text, .toggle-indicator, .handle-order-higher, .handle-order-lower')).forEach(function (node) {
            node.remove();
        });
        return clone.textContent.replace(/\s+/g, ' ').trim();
    }

    function getMetaboxTitle(box) {
        var selectors = [
            '.postbox-header h2',
            '.postbox-header h3',
            'h2.hndle',
            'h3.hndle',
            '.hndle',
            '.stuffbox h3'
        ];

        for (var i = 0; i < selectors.length; i++) {
            var title = cleanTitleFromElement(box.querySelector(selectors[i]));
            if (title) {
                return title;
            }
        }

        return box.id ? box.id.replace(/[_-]+/g, ' ') : 'Metabox';
    }

    function getMetaboxContext(box) {
        if (box.closest('#side-sortables')) {
            return cfg.sideLabel || 'Lateral';
        }
        if (box.closest('#advanced-sortables')) {
            return cfg.advancedLabel || 'Avanzado';
        }
        if (box.closest('#normal-sortables')) {
            return cfg.normalLabel || 'Principal';
        }
        return cfg.otherLabel || 'Otro';
    }

    function isVisibleBox(box) {
        if (!box || !box.id || box.id === 'eventosapp-metabox-sticky-menu') {
            return false;
        }

        if (!box.classList.contains('postbox')) {
            return false;
        }

        if (box.classList.contains(FILTERED_OUT_CLASS)) {
            return true;
        }

        var style = window.getComputedStyle(box);
        if (style.display === 'none' || style.visibility === 'hidden') {
            return false;
        }

        if (box.offsetParent === null && style.position !== 'fixed') {
            return false;
        }

        return true;
    }

    function collectMetaboxes() {
        var selectors = [
            '#normal-sortables > .postbox',
            '#advanced-sortables > .postbox',
            '#side-sortables > .postbox',
            '.edit-post-meta-boxes-area .postbox',
            '#poststuff .postbox'
        ];
        var seen = {};
        var boxes = [];

        selectors.forEach(function (selector) {
            Array.prototype.slice.call(document.querySelectorAll(selector)).forEach(function (box) {
                if (!isVisibleBox(box) || seen[box.id]) {
                    return;
                }
                seen[box.id] = true;
                boxes.push({
                    id: box.id,
                    title: getMetaboxTitle(box),
                    context: getMetaboxContext(box)
                });
            });
        });

        return boxes;
    }

    function getSignature(boxes) {
        return boxes.map(function (box) {
            return box.id + ':' + box.title + ':' + box.context;
        }).join('|');
    }

    function renderList() {
        buildRoot();

        var boxes = collectMetaboxes();
        var signature = getSignature(boxes);
        if (signature === state.lastRenderedSignature) {
            applyFilter();
            return;
        }
        state.lastRenderedSignature = signature;

        state.list.innerHTML = '';
        state.empty.hidden = boxes.length > 0;
        state.noResults.hidden = true;

        state.totalBoxes = boxes.length;
        updateCount(boxes.length, boxes.length, false);

        boxes.forEach(function (box) {
            var item = createElement('button', {
                type: 'button',
                className: 'eventosapp-metabox-sticky-menu__item',
                role: 'listitem',
                dataset: {
                    target: box.id,
                    search: normalizeText(box.title + ' ' + box.context + ' ' + box.id)
                },
                title: box.title
            });

            var title = createElement('span', { className: 'eventosapp-metabox-sticky-menu__item-title' }, box.title);
            var context = createElement('span', { className: 'eventosapp-metabox-sticky-menu__item-context' }, box.context);

            item.appendChild(title);
            item.appendChild(context);
            item.addEventListener('click', function () {
                scrollToMetabox(box.id);
            });

            state.list.appendChild(item);
        });

        applyFilter();
    }

    function updateCount(visibleCount, totalCount, isFiltered) {
        if (!state.count) {
            return;
        }

        var countLabel = visibleCount === 1 ? (cfg.countSingular || 'metabox visible') : (cfg.countPlural || 'metaboxes visibles');
        if (isFiltered) {
            state.count.textContent = visibleCount + ' de ' + totalCount + ' ' + countLabel;
            return;
        }

        state.count.textContent = totalCount + ' ' + (totalCount === 1 ? (cfg.countSingular || 'metabox visible') : (cfg.countPlural || 'metaboxes visibles'));
    }

    function setMetaboxFilterState(targetId, shouldHide) {
        var box = document.getElementById(targetId);
        if (!box || !box.classList.contains('postbox')) {
            return;
        }

        box.classList.toggle(FILTERED_OUT_CLASS, shouldHide);
        if (shouldHide) {
            box.setAttribute('aria-hidden', 'true');
        } else {
            box.removeAttribute('aria-hidden');
        }
    }

    function getVisibleMenuItems() {
        if (!state.list) {
            return [];
        }

        return Array.prototype.slice.call(state.list.querySelectorAll('.eventosapp-metabox-sticky-menu__item')).filter(function (item) {
            return !item.hidden;
        });
    }

    function activateFirstVisibleResult() {
        var firstVisibleItem = getVisibleMenuItems()[0];
        if (!firstVisibleItem || !firstVisibleItem.dataset || !firstVisibleItem.dataset.target) {
            return;
        }

        scrollToMetabox(firstVisibleItem.dataset.target);
    }

    function applyFilter() {
        if (!state.list || !state.search) {
            return;
        }

        var query = normalizeText(state.search.value);
        var hasQuery = query.length > 0;
        var items = Array.prototype.slice.call(state.list.querySelectorAll('.eventosapp-metabox-sticky-menu__item'));
        var visibleCount = 0;

        items.forEach(function (item) {
            var matches = textMatchesSearch(item.dataset.search || '', query);
            var shouldHide = hasQuery && !matches;

            item.hidden = shouldHide;
            setMetaboxFilterState(item.dataset.target, shouldHide);

            if (!shouldHide) {
                visibleCount++;
            }
        });

        updateCount(visibleCount, items.length, hasQuery);
        state.noResults.hidden = !hasQuery || visibleCount > 0 || items.length === 0;
    }

    function openMetaboxIfClosed(box) {
        if (!box || !box.classList.contains('closed')) {
            return;
        }

        var toggle = box.querySelector('.postbox-header .handlediv, button.handlediv, .handlediv');
        if (toggle && typeof toggle.click === 'function') {
            toggle.click();
        }

        box.classList.remove('closed');
        Array.prototype.slice.call(box.querySelectorAll('.inside')).forEach(function (inside) {
            inside.style.display = '';
        });
    }

    function getStickyOffset() {
        var adminBar = document.getElementById('wpadminbar');
        var adminBarHeight = adminBar ? adminBar.offsetHeight : 0;
        var menuHeight = state.root ? state.root.offsetHeight : 0;
        return adminBarHeight + menuHeight + 18;
    }

    function scrollToMetabox(id) {
        var box = document.getElementById(id);
        if (!box) {
            renderList();
            box = document.getElementById(id);
        }
        if (!box) {
            return;
        }

        openMetaboxIfClosed(box);

        var targetTop = box.getBoundingClientRect().top + window.pageYOffset - getStickyOffset();
        window.scrollTo({
            top: Math.max(0, targetTop),
            behavior: 'smooth'
        });

        box.classList.remove('eventosapp-metabox-sticky-menu__highlight');
        window.setTimeout(function () {
            box.classList.add('eventosapp-metabox-sticky-menu__highlight');
        }, 160);
        window.setTimeout(function () {
            box.classList.remove('eventosapp-metabox-sticky-menu__highlight');
        }, 1900);
    }

    function scheduleRefresh() {
        window.clearTimeout(state.refreshTimer);
        state.refreshTimer = window.setTimeout(renderList, 180);
    }

    function observeMetaboxChanges() {
        if (!window.MutationObserver) {
            return;
        }

        var target = document.getElementById('poststuff') || document.getElementById('wpbody-content');
        if (!target) {
            return;
        }

        state.observer = new MutationObserver(function (mutations) {
            var shouldRefresh = mutations.some(function (mutation) {
                if (mutation.target && state.root && state.root.contains(mutation.target)) {
                    return false;
                }
                return mutation.addedNodes.length || mutation.removedNodes.length || mutation.attributeName === 'style' || mutation.attributeName === 'class';
            });

            if (shouldRefresh) {
                scheduleRefresh();
            }
        });

        state.observer.observe(target, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'style']
        });
    }

    ready(function () {
        buildRoot();
        renderList();
        observeMetaboxChanges();
        window.setTimeout(renderList, 500);
        window.setTimeout(renderList, 1200);
    });
})();
JS;
        }
    }

    EventosApp_Admin_Metabox_Sticky_Menu::init();
}
