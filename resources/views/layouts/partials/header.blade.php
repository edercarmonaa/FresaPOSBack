<header class="ui-app-header" id="ui-app-header">
    <div class="ui-header-start">
        @if ($uiHasNavigation)
            <button type="button" class="ui-shell-button" data-ui-menu-toggle aria-controls="ui-navigation" aria-expanded="true" aria-label="Contraer menú"><x-ui.icon name="menu" /></button>
        @endif
        <a class="ui-brand" href="{{ url('/') }}">FresaPOS</a>
        <span class="ui-workspace-label">{{ ['pos' => 'Punto de venta', 'auth' => 'Acceso', 'admin' => 'Espacio de trabajo'][$uiContext] }}</span>
    </div>
    <div class="ui-header-actions">
        <label class="ui-header-theme"><span class="ui-sr-only">Apariencia</span><select class="ui-select" data-ui-theme-select><option value="auto">Automático</option><option value="light">Claro</option><option value="dark">Oscuro</option></select></label>
        <button type="button" class="ui-shell-button" data-ui-open-preferences aria-controls="ui-preferences" aria-haspopup="dialog" aria-label="Preferencias visuales"><x-ui.icon name="sun" /></button>
    </div>
</header>
