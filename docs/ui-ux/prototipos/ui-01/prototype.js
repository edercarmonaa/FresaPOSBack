/* Solo prototipo documental. Sin fetch, Livewire, API, autenticación o cálculos monetarios. */
(() => {
    'use strict';
    const KEY = 'fresapos.ui01.visual.v1';
    const defaults = {theme:'auto',density:'standard',text:'normal',motion:'auto',sidebar:'expanded'};
    const allowed = {theme:['auto','light','dark'],density:['standard','compact','comfortable'],text:['normal','large'],motion:['auto','reduce'],sidebar:['expanded','collapsed']};
    const root = document.documentElement;
    const darkSystem = matchMedia('(prefers-color-scheme: dark)');
    const reducedSystem = matchMedia('(prefers-reduced-motion: reduce)');
    const mobile = matchMedia('(max-width: 1023px)');
    const screens = ['reportes','productos','pos','acceso','componentes'];
    let raw = {};
    try { raw = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch { /* Memoria local si storage falla. */ }
    let preferences = Object.fromEntries(Object.keys(defaults).map(key => [key, allowed[key].includes(raw[key]) ? raw[key] : defaults[key]]));
    let toastTimer;
    const toast = message => {
        const node = document.getElementById('prototype-toast');
        clearTimeout(toastTimer);
        node.textContent = message;
        node.hidden = false;
        toastTimer = setTimeout(() => {node.hidden = true;}, 4800);
    };
    const applyPreferences = (persist = false) => {
        root.dataset.theme = preferences.theme === 'auto' ? (darkSystem.matches ? 'dark' : 'light') : preferences.theme;
        root.dataset.density = preferences.density;
        root.dataset.text = preferences.text;
        root.dataset.sidebar = preferences.sidebar;
        root.dataset.motion = preferences.motion === 'reduce' || reducedSystem.matches ? 'reduced' : 'normal';
        document.querySelectorAll('[data-theme-select]').forEach(node => {node.value = preferences.theme;});
        document.getElementById('preference-density').value = preferences.density;
        document.getElementById('preference-text').value = preferences.text;
        document.getElementById('preference-motion').value = preferences.motion;
        const toggle = document.getElementById('sidebar-toggle');
        toggle.setAttribute('aria-expanded', String(preferences.sidebar === 'expanded'));
        toggle.setAttribute('aria-label', preferences.sidebar === 'expanded' ? 'Contraer menú lateral' : 'Expandir menú lateral');
        if (persist) { try {localStorage.setItem(KEY, JSON.stringify(preferences));} catch { /* No hay datos sensibles ni necesidad de storage para operar la maqueta. */ } }
    };
    document.querySelectorAll('[data-theme-select]').forEach(node => node.addEventListener('change', () => {
        preferences.theme = allowed.theme.includes(node.value) ? node.value : 'auto';
        applyPreferences(true);
    }));
    for (const key of ['density','text','motion']) {
        document.getElementById('preference-'+key).addEventListener('change', event => {
            preferences[key] = allowed[key].includes(event.target.value) ? event.target.value : defaults[key];
            applyPreferences(true);
        });
    }
    darkSystem.addEventListener('change', () => applyPreferences());
    reducedSystem.addEventListener('change', () => applyPreferences());
    document.getElementById('sidebar-toggle').addEventListener('click', () => {
        preferences.sidebar = preferences.sidebar === 'expanded' ? 'collapsed' : 'expanded';
        applyPreferences(true);
    });
    document.getElementById('reset-preferences').addEventListener('click', () => {
        preferences = {...defaults};
        try {localStorage.removeItem(KEY);} catch { /* Restablece en memoria. */ }
        applyPreferences(true);
        toast('Preferencias visuales restablecidas.');
    });
    applyPreferences(true);

    const modalReturnFocus = new WeakMap();
    const openDialog = id => {
        const dialog = document.getElementById(id);
        if (!(dialog instanceof HTMLDialogElement) || dialog.open) return;
        modalReturnFocus.set(dialog, document.activeElement);
        dialog.showModal();
        if (id === 'ticket-dialog') document.getElementById('show-ticket').setAttribute('aria-expanded','true');
    };
    document.querySelectorAll('dialog').forEach(dialog => {
        dialog.addEventListener('keydown', event => {
            if (event.key !== 'Tab') return;
            const items = [...dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]')]
                .filter(node => !node.disabled && node.tabIndex >= 0 && node.getClientRects().length);
            const first = items[0], last = items[items.length - 1];
            if (!first) {event.preventDefault();dialog.focus();return;}
            if (event.shiftKey && document.activeElement === first) {event.preventDefault();last.focus();}
            else if (!event.shiftKey && document.activeElement === last) {event.preventDefault();first.focus();}
        });
        dialog.addEventListener('close', () => {
            if (dialog.id === 'ticket-dialog') document.getElementById('show-ticket').setAttribute('aria-expanded','false');
            const target = modalReturnFocus.get(dialog);
            if (target instanceof HTMLElement && target.isConnected && target.getClientRects().length) target.focus();
        });
    });
    document.addEventListener('click', event => {
        const close = event.target.closest('[data-close]');
        if (close) {document.getElementById(close.dataset.close)?.close();return;}
        const open = event.target.closest('[data-open]');
        if (open) {openDialog(open.dataset.open);return;}
        const product = event.target.closest('[data-product]');
        if (product) {
            document.getElementById('product-preview-title').textContent = product.dataset.product;
            openDialog('product-preview');return;
        }
        const notice = event.target.closest('[data-notice]');
        if (notice) {event.preventDefault();toast(notice.dataset.notice);}
    });
    document.querySelectorAll('[data-selection-note]').forEach(node => node.addEventListener('change', () => toast('Selección visual de muestra. No se consultan ni modifican datos.')));
    document.querySelectorAll('form').forEach(form => form.addEventListener('submit', event => {event.preventDefault();toast('El prototipo no envía formularios.');}));

    const showScreen = (name, focus = false) => {
        const view = screens.includes(name) ? name : 'reportes';
        root.dataset.view = view;
        document.querySelectorAll('[data-screen]').forEach(section => {section.hidden = section.dataset.screen !== view;});
        document.body.classList.toggle('pos-view', view === 'pos');
        document.body.classList.toggle('auth-view', view === 'acceso');
        document.getElementById('prototype-view').value = view;
        document.getElementById('page-footer').hidden = view === 'acceso' || view === 'pos';
        document.getElementById('pos-back').hidden = view !== 'pos';
        document.getElementById('workspace-label').textContent = view === 'pos' ? 'Punto de venta · muestra' : view === 'componentes' ? 'Propuesta de componentes' : 'Área administrativa';
        document.querySelectorAll('[data-view]').forEach(link => {
            if (link.dataset.view === view && link.classList.contains('nav-link')) link.setAttribute('aria-current','page');
            else link.removeAttribute('aria-current');
        });
        modalReturnFocus.delete(document.getElementById('nav-drawer'));
        document.getElementById('nav-drawer').close();
        document.getElementById('ticket-dialog').close();
        document.title = 'FresaPOS · '+({reportes:'Reportes',productos:'Productos',pos:'Punto de venta',acceso:'Autenticación',componentes:'Componentes'}[view])+' · Prototipo UI-01';
        if (focus) {document.getElementById('prototype-content').focus({preventScroll:true});window.scrollTo({top:0,behavior:'instant'});}
    };
    document.querySelectorAll('[data-view]').forEach(link => link.addEventListener('click', event => {
        event.preventDefault();
        const view = link.dataset.view;
        history.replaceState(null,'','#'+view);
        showScreen(view,true);
    }));
    document.getElementById('prototype-view').addEventListener('change', event => {
        history.replaceState(null,'','#'+event.target.value);showScreen(event.target.value,true);
    });
    window.addEventListener('hashchange', () => showScreen(location.hash.slice(1),true));

    const setupTabs = (selector, attribute, activate) => {
        const tabs = [...document.querySelectorAll(selector)];
        const choose = tab => {
            tabs.forEach(item => {item.setAttribute('aria-selected',String(item === tab));item.tabIndex = item === tab ? 0 : -1;});
            activate(tab.dataset[attribute]);
        };
        tabs.forEach((tab,index) => {
            tab.tabIndex = tab.getAttribute('aria-selected') === 'true' ? 0 : -1;
            tab.addEventListener('click', () => choose(tab));
            tab.addEventListener('keydown', event => {
                let next;
                if (event.key === 'ArrowRight') next=(index+1)%tabs.length;
                else if (event.key === 'ArrowLeft') next=(index+tabs.length-1)%tabs.length;
                else if (event.key === 'Home') next=0;
                else if (event.key === 'End') next=tabs.length-1;
                else return;
                event.preventDefault();tabs[next].focus();choose(tabs[next]);
            });
        });
    };
    setupTabs('[data-catalog-tab]','catalogTab', state => {
        document.getElementById('catalog-current').hidden = state !== 'current';
        document.getElementById('catalog-trash').hidden = state !== 'trash';
    });
    setupTabs('[data-pos-mode]','posMode', state => {
        document.getElementById('pos-products').hidden = state !== 'counter';
        document.getElementById('pos-tables').hidden = state !== 'tables';
    });
    document.querySelectorAll('[data-category]').forEach(button => button.addEventListener('click', () => {
        document.querySelectorAll('[data-category]').forEach(item => item.setAttribute('aria-pressed',String(item === button)));
        toast('Estado visual de categoría. El catálogo de muestra no está conectado a filtros reales.');
    }));
    document.querySelectorAll('[data-catalog-tab]').forEach(tab => tab.setAttribute('aria-controls', 'catalog-'+tab.dataset.catalogTab));
    document.querySelectorAll('[data-pos-mode]').forEach(tab => tab.setAttribute('aria-controls', tab.dataset.posMode === 'counter' ? 'pos-products' : 'pos-tables'));
    const ticket = document.getElementById('ticket-card');
    const moveTicket = () => {
        document.getElementById('ticket-dialog').close();
        (mobile.matches ? document.getElementById('ticket-dialog') : document.getElementById('ticket-slot')).append(ticket);
    };
    document.getElementById('show-ticket').addEventListener('click', () => openDialog('ticket-dialog'));
    mobile.addEventListener('change',moveTicket);
    moveTicket();
    new ResizeObserver(entries => {root.style.setProperty('--prototype-bar-height', entries[0].target.getBoundingClientRect().height+'px');}).observe(document.getElementById('prototype-bar'));
    showScreen(location.hash.slice(1));
})();
