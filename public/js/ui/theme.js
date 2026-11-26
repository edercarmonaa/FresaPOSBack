/* FresaPOS UI: preferencias de tema exclusivamente visuales; sin API ni sesión. */
(() => {
    'use strict';
    const root = document.documentElement;
    const key = 'fresapos.ui.visual.v1';
    const modes = ['auto', 'light', 'dark'];
    const system = matchMedia('(prefers-color-scheme: dark)');
    let mode = 'auto';
    try {
        const stored = JSON.parse(localStorage.getItem(key) || '{}');
        if (stored && modes.includes(stored.theme)) mode = stored.theme;
    } catch { /* El tema automático funciona sin almacenamiento. */ }
    const apply = (persist = false) => {
        root.dataset.uiTheme = mode === 'auto' ? (system.matches ? 'dark' : 'light') : mode;
        root.dataset.uiThemeMode = mode;
        document.querySelectorAll('[data-ui-theme-select]').forEach(select => { select.value = mode; });
        if (persist) {
            try { localStorage.setItem(key, JSON.stringify({theme: mode})); } catch { /* Preferencia en memoria. */ }
        }
    };
    const set = value => { mode = modes.includes(value) ? value : 'auto'; apply(true); };
    window.FresaUITheme = Object.freeze({get: () => mode, set, reset: () => set('auto')});
    system.addEventListener('change', () => apply());
    // Cargar en head, antes del CSS: el primer cálculo de estilos recibe el tema.
    apply(true);
    document.addEventListener('DOMContentLoaded', () => {
        apply();
        document.querySelectorAll('[data-ui-theme-select]').forEach(select => select.addEventListener('change', () => set(select.value)));
    }, {once: true});
})();
