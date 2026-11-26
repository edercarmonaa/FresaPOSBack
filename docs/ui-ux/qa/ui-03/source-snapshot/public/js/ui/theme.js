/* FresaPOS UI: preferencias de tema exclusivamente visuales; sin API ni sesión. */
(() => {
    'use strict';
    const root = document.documentElement;
    const key = 'fresapos.ui.visual.v1';
    const defaults = {theme:'auto', density:'standard', text:'normal', motion:'auto', sidebar:'expanded'};
    const allowed = {theme:['auto','light','dark'], density:['standard','compact','comfortable'], text:['normal','large'], motion:['auto','reduce'], sidebar:['expanded','collapsed']};
    const system = matchMedia('(prefers-color-scheme: dark)');
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    let stored = {};
    try {
        stored = JSON.parse(localStorage.getItem(key) || '{}') || {};
    } catch { /* El tema automático funciona sin almacenamiento. */ }
    let preferences = Object.fromEntries(Object.keys(defaults).map(name => [name, allowed[name].includes(stored[name]) ? stored[name] : defaults[name]]));
    const apply = (persist = false) => {
        root.dataset.uiTheme = preferences.theme === 'auto' ? (system.matches ? 'dark' : 'light') : preferences.theme;
        root.dataset.uiThemeMode = preferences.theme;
        root.dataset.uiDensity = preferences.density;
        root.dataset.uiText = preferences.text;
        root.dataset.uiSidebar = preferences.sidebar;
        root.dataset.uiMotion = preferences.motion === 'reduce' || reduced.matches ? 'reduced' : 'normal';
        document.querySelectorAll('[data-ui-theme-select]').forEach(select => { select.value = preferences.theme; });
        document.querySelectorAll('[data-ui-preference]').forEach(select => { select.value = preferences[select.dataset.uiPreference]; });
        if (persist) {
            try { localStorage.setItem(key, JSON.stringify(preferences)); } catch { /* Preferencia en memoria. */ }
        }
        document.dispatchEvent(new CustomEvent('ui:preferences'));
    };
    const set = (name, value) => {
        if (!Object.hasOwn(allowed, name)) return;
        preferences[name] = allowed[name].includes(value) ? value : defaults[name];
        apply(true);
    };
    window.FresaUITheme = Object.freeze({get: () => preferences.theme, set: value => set('theme', value), reset: () => set('theme', 'auto')});
    window.FresaUIPreferences = Object.freeze({get: () => ({...preferences}), set, reset: () => {preferences = {...defaults};apply(true);}});
    system.addEventListener('change', () => apply());
    reduced.addEventListener('change', () => apply());
    // Cargar en head, antes del CSS: el primer cálculo de estilos recibe el tema.
    apply(true);
    document.addEventListener('DOMContentLoaded', () => {
        apply();
        document.addEventListener('change', event => {
            if (event.target.matches('[data-ui-theme-select]')) set('theme', event.target.value);
            if (event.target.matches('[data-ui-preference]')) set(event.target.dataset.uiPreference, event.target.value);
        });
    }, {once: true});
})();
