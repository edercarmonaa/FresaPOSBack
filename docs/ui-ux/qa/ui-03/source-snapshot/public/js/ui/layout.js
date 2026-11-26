/* UI-03: foco y navegación visual; no intercepta formularios, enlaces o Livewire. */
(() => {
    'use strict';
    const root = document.documentElement;
    const mobile = matchMedia('(max-width: 1023px)');
    let navigationReturn;
    let preferencesReturn;
    const inertBeforeNavigation = new Map();
    const visible = node => !!node.getClientRects().length && getComputedStyle(node).visibility !== 'hidden';
    const items = container => [...container.querySelectorAll('a[href],button,input,select,textarea,[tabindex]')].filter(node => !node.disabled && node.tabIndex >= 0 && visible(node));
    const containTab = (event, container) => {
        if (event.key !== 'Tab') return;
        const nodes = items(container), first = nodes[0], last = nodes[nodes.length - 1];
        if (!first) {event.preventDefault();return;}
        if (event.shiftKey && document.activeElement === first) {event.preventDefault();last.focus();}
        else if (!event.shiftKey && document.activeElement === last) {event.preventDefault();first.focus();}
    };
    const sync = () => {
        const toggle = document.querySelector('[data-ui-menu-toggle]');
        if (!toggle) return;
        const expanded = mobile.matches ? root.dataset.uiNavOpen === 'true' : root.dataset.uiSidebar !== 'collapsed';
        toggle.setAttribute('aria-expanded', String(expanded));
        toggle.setAttribute('aria-label', mobile.matches ? (expanded ? 'Cerrar menú' : 'Abrir menú') : (expanded ? 'Contraer menú' : 'Expandir menú'));
    };
    const closeNavigation = (returnFocus = true) => {
        root.dataset.uiNavOpen = 'false';
        const nav = document.getElementById('ui-navigation');
        nav?.removeAttribute('role');nav?.removeAttribute('aria-modal');
        document.querySelector('.ui-nav-backdrop')?.setAttribute('hidden','');
        inertBeforeNavigation.forEach((wasInert, node) => { node.inert = wasInert; });
        inertBeforeNavigation.clear();
        sync();
        if (returnFocus && navigationReturn?.isConnected && visible(navigationReturn)) navigationReturn.focus();
    };
    const openNavigation = () => {
        const nav = document.getElementById('ui-navigation');
        if (!nav) return;
        navigationReturn = document.activeElement;
        root.dataset.uiNavOpen = 'true';nav.setAttribute('role','dialog');nav.setAttribute('aria-modal','true');
        document.querySelector('.ui-nav-backdrop')?.removeAttribute('hidden');
        for (const id of ['ui-main','ui-app-header']) {
            const node = document.getElementById(id);
            if (node) {inertBeforeNavigation.set(node, node.inert);node.inert = true;}
        }
        sync();items(nav)[0]?.focus();
    };
    document.addEventListener('click', event => {
        if (!(event.target instanceof Element)) return;
        if (event.target.closest('[data-ui-menu-toggle]')) {
            if (mobile.matches) root.dataset.uiNavOpen === 'true' ? closeNavigation() : openNavigation();
            else window.FresaUIPreferences.set('sidebar', root.dataset.uiSidebar === 'collapsed' ? 'expanded' : 'collapsed');
        }
        if (event.target.closest('[data-ui-nav-close]')) closeNavigation();
        if (event.target.closest('[data-ui-open-preferences]')) {
            const dialog = document.getElementById('ui-preferences');
            if (dialog && !dialog.open) {preferencesReturn = document.activeElement;dialog.showModal();}
        }
        if (event.target.closest('[data-ui-close-preferences]')) document.getElementById('ui-preferences')?.close();
        if (event.target.closest('[data-ui-reset-preferences]')) window.FresaUIPreferences.reset();
    });
    document.addEventListener('keydown', event => {
        const nav = document.getElementById('ui-navigation'), dialog = document.getElementById('ui-preferences');
        if (dialog?.open) containTab(event, dialog);
        else if (mobile.matches && root.dataset.uiNavOpen === 'true' && nav) {
            if (event.key === 'Escape') {event.preventDefault();closeNavigation();}
            else containTab(event, nav);
        }
    });
    document.getElementById('ui-preferences')?.addEventListener('close', () => {
        if (preferencesReturn?.isConnected && visible(preferencesReturn)) preferencesReturn.focus();
    });
    mobile.addEventListener('change', () => closeNavigation(false));
    document.addEventListener('ui:preferences', sync);
    document.addEventListener('livewire:navigated', () => closeNavigation(false));
    sync();
})();
