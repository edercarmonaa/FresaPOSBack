<!doctype html>
<html lang="es" class="ui-root"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>UI-06 · Catálogo documental</title>
<script src="/js/ui/theme.js"></script><link rel="preload" href="/fonts/ui/InterVariable.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/css/ui/tokens.css"><link rel="stylesheet" href="/css/ui/components.css"><link rel="stylesheet" href="/css/ui/layouts.css">
@stack('styles')
    <style>.qa-shell{max-width:1180px;margin:auto;padding:32px;display:grid;gap:24px}.qa-top{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px}.qa-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:24px}.qa-stack{display:grid;gap:20px}.qa-heading{font-size:var(--ui-font-xl);font-weight:650;margin-bottom:20px}.qa-demo-form{display:grid;gap:20px}.qa-shell>section{min-width:0}.qa-shell .ui-table{min-width:720px}@media(max-width:767px){.qa-shell{padding:20px}}@media(max-width:359px){.qa-shell{padding:16px}}</style>
</head><body class="ui-page">@yield('content')@stack('scripts')</body></html>
