<!doctype html>
<html lang="es" class="ui-root">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:; connect-src 'none'; form-action 'none'; base-uri 'none'; object-src 'none'">
    <title>FresaPOS · Catálogo Design System UI-02</title>
    <link rel="icon" href="data:,">
    <script src="/js/ui/theme.js"></script>
    <link rel="preload" href="/fonts/ui/InterVariable.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/css/ui/tokens.css">
    <link rel="stylesheet" href="/css/ui/components.css">
    <link rel="stylesheet" href="/catalog.css">
    <script src="/catalog.js" defer></script>
</head>
<body class="ui-page">
    <a href="#catalog-content" class="ui-link catalog-skip">Saltar al contenido</a>
    <header class="catalog-header">
        <div><span class="catalog-tag">CATÁLOGO UI-02</span><strong>FresaPOS</strong><span>Componentes reales · Sin operaciones de negocio</span></div>
        <label class="catalog-theme" for="theme"><x-ui.icon name="sun" /><span>Apariencia</span><select class="ui-select" id="theme" data-ui-theme-select><option value="auto">Automático</option><option value="light">Claro</option><option value="dark">Oscuro</option></select></label>
    </header>
    <main id="catalog-content" class="catalog-main" tabindex="-1">
        <div class="catalog-intro"><div><p class="catalog-eyebrow">BASE VISUAL 1.0 · PENDIENTE DE ACEPTACIÓN</p><h1 class="ui-heading">Una base común para FresaPOS</h1><p class="ui-muted">Tokens y componentes Blade preparados para integrar por etapas. Las pantallas operativas todavía conservan su presentación actual.</p></div><x-ui.badge tone="info">Recursos locales · Sin CDN</x-ui.badge></div>
        <nav class="catalog-links" aria-label="Secciones del catálogo"><a class="ui-link" href="#identity">Identidad</a><a class="ui-link" href="#actions">Acciones</a><a class="ui-link" href="#fields">Campos</a><a class="ui-link" href="#states">Estados</a><a class="ui-link" href="#tables">Tablas</a></nav>
        <section id="identity" class="catalog-section"><h2 class="ui-section-title">Identidad y jerarquía</h2><div class="catalog-grid">
            <x-ui.card title="Color con un propósito"><div class="catalog-swatches"><div><span class="catalog-swatch catalog-swatch--navy"></span>Marino<small>#0F172A</small></div><div><span class="catalog-swatch catalog-swatch--brand"></span>Corporativo<small>#2563EB</small></div><div><span class="catalog-swatch catalog-swatch--primary"></span>Acción<small>Según tema</small></div><div><span class="catalog-swatch catalog-swatch--surface"></span>Superficie<small>Según tema</small></div></div><p class="ui-muted">Primitivos → semánticos → componentes. Los roles mantienen su significado al cambiar de tema.</p></x-ui.card>
            <x-ui.card title="Inter local · Lectura clara"><p class="catalog-type-title">La información, en su lugar.</p><p>Texto general 14 px. Jerarquía contenida, pesos moderados y alternativas del sistema.</p><p><span class="ui-money">0123456789</span> · <span class="ui-money">$1,234.0000</span></p><p class="ui-muted">Cadena tipográfica de muestra; no corresponde a datos del sistema.</p><div class="catalog-icon-row"><x-ui.icon name="menu" /><x-ui.icon name="search" /><x-ui.icon name="plus" /><x-ui.icon name="check" /><x-ui.icon name="info" /><x-ui.icon name="warning" /><x-ui.icon name="close" /></div></x-ui.card>
        </div></section>
        <section id="actions" class="catalog-section"><h2 class="ui-section-title">Acciones y estados</h2><x-ui.card><div class="catalog-actions">
            <x-ui.button data-demo="Muestra de acción principal; no se envía ninguna operación."><x-ui.icon name="plus" />Principal</x-ui.button>
            <x-ui.button variant="secondary" data-demo="Muestra de acción secundaria.">Secundaria</x-ui.button>
            <x-ui.button variant="danger" data-demo="Muestra visual crítica. La integración conservará las confirmaciones y permisos existentes."><x-ui.icon name="warning" />Acción crítica · muestra</x-ui.button>
            <x-ui.button disabled>No disponible</x-ui.button>
            <x-ui.button variant="secondary" aria-label="Buscar, muestra de botón de icono" data-demo="Botón con nombre accesible."><x-ui.icon name="search" /></x-ui.button>
        </div><p class="ui-muted">Botones de ejemplo: solo muestran una explicación. No guardan, confirman ni ejecutan acciones reales. El foco se puede recorrer con Tab.</p><div class="catalog-actions"><x-ui.badge>Neutral · muestra</x-ui.badge><x-ui.badge tone="success"><x-ui.icon name="check" />Activo · muestra</x-ui.badge><x-ui.badge tone="warning">Pendiente · muestra</x-ui.badge><x-ui.badge tone="error">Restringido · muestra</x-ui.badge><x-ui.badge tone="info">Información · muestra</x-ui.badge></div></x-ui.card></section>
        <section id="fields" class="catalog-section"><h2 class="ui-section-title">Campos con contexto</h2><x-ui.card><div class="catalog-grid">
            <x-ui.field input-id="sample-name" label="Nombre" hint="Campo de muestra, de solo lectura." :required="true"><x-ui.input id="sample-name" name="sample_name" value="Producto de muestra" readonly required aria-describedby="sample-name-help" /></x-ui.field>
            <x-ui.field input-id="sample-category" label="Categoría"><select class="ui-select" id="sample-category" name="sample_category"><option>Panadería · muestra</option><option>Bebidas · muestra</option></select></x-ui.field>
            <x-ui.field input-id="sample-error" label="Campo con error · muestra" error="Ejemplo de mensaje asociado al campo."><x-ui.input id="sample-error" value="Muestra inválida" readonly :invalid="true" aria-describedby="sample-error-error" /></x-ui.field>
            <x-ui.field input-id="sample-disabled" label="No disponible"><x-ui.input id="sample-disabled" value="Campo deshabilitado" disabled /></x-ui.field>
            <x-ui.field input-id="sample-note" label="Observación" hint="La estructura conserva etiquetas e IDs explícitos."><textarea class="ui-textarea" id="sample-note" readonly aria-describedby="sample-note-help">Observación de muestra.</textarea></x-ui.field>
            <x-ui.field input-id="sample-loading" label="Estado de procesamiento · muestra"><x-ui.button id="sample-loading" disabled aria-busy="true">Procesando · variante estática</x-ui.button><p class="ui-help">No indica que haya una operación en curso.</p></x-ui.field>
        </div></x-ui.card></section>
        <section id="states" class="catalog-section"><h2 class="ui-section-title">Mensajes que orientan</h2><div class="catalog-grid">
            <x-ui.alert tone="success" title="Variante de éxito">Muestra visual. La aplicación conservará sus mensajes reales.</x-ui.alert>
            <x-ui.alert tone="warning" title="Variante de advertencia">Explicar el contexto y la acción necesaria sin depender solo del color.</x-ui.alert>
            <x-ui.alert tone="error" title="Variante de error">Mantener los errores relevantes junto a la operación o campo.</x-ui.alert>
            <x-ui.alert title="Variante informativa">Los roles vivos se agregan únicamente cuando el flujo real lo necesita.</x-ui.alert>
        </div></section>
        <section id="tables" class="catalog-section"><div class="catalog-table-heading"><h2 class="ui-section-title">Tablas legibles</h2><label for="density">Densidad <select class="ui-select" id="density"><option value="standard">Estándar</option><option value="compact">Compacta</option><option value="comfortable">Amplia</option></select></label></div><p class="ui-muted">Tabla estática de muestra. En pantallas pequeñas puede desplazarse horizontalmente, también con teclado.</p><x-ui.table caption="Elementos del catálogo visual, sin datos operativos"><thead><tr><th scope="col">Componente</th><th scope="col">Uso</th><th scope="col">Estado</th><th scope="col" class="ui-numeric">Tamaño de referencia</th></tr></thead><tbody><tr><td>Botón principal</td><td>Acción explícita</td><td><x-ui.badge tone="info">Disponible en la base</x-ui.badge></td><td class="ui-numeric">44 px</td></tr><tr><td>Campo de texto</td><td>Entrada etiquetada</td><td><x-ui.badge tone="info">Disponible en la base</x-ui.badge></td><td class="ui-numeric">44 px</td></tr><tr><td>Mensajes</td><td>Contexto de operación</td><td><x-ui.badge>Según contenido</x-ui.badge></td><td class="ui-numeric">Adaptable</td></tr></tbody></x-ui.table><div class="ui-empty catalog-empty"><x-ui.icon name="info" /><h3>Estado vacío · muestra</h3><p>La explicación y las acciones deben corresponder a la información real.</p></div></section>
        <div id="catalog-notice" class="ui-alert ui-alert--info catalog-notice" role="status" hidden></div>
        <footer class="catalog-footer">FresaPOS · UI-02 · Catálogo de componentes renderizados con Blade. No es una pantalla funcional ni una ruta de Laravel.</footer>
    </main>
</body>
</html>
