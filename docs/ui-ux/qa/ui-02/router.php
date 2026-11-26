<?php

// Servidor documental: lista cerrada de rutas; nunca expone la raíz del repositorio.
$root = dirname(__DIR__, 4);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$files = [
    '/' => ['docs/ui-ux/catalogo/ui-02/index.html', 'text/html; charset=utf-8'],
    '/catalog.css' => ['docs/ui-ux/catalogo/ui-02/catalog.css', 'text/css'],
    '/catalog.js' => ['docs/ui-ux/catalogo/ui-02/catalog.js', 'application/javascript'],
    '/css/ui/tokens.css' => ['public/css/ui/tokens.css', 'text/css'],
    '/css/ui/components.css' => ['public/css/ui/components.css', 'text/css'],
    '/js/ui/theme.js' => ['public/js/ui/theme.js', 'application/javascript'],
    '/fonts/ui/InterVariable.woff2' => ['public/fonts/ui/InterVariable.woff2', 'font/woff2'],
];
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || ! isset($files[$path])) {
    http_response_code(404);
    exit;
}
header('Content-Type: '.$files[$path][1]);
header('X-Content-Type-Options: nosniff');
readfile($root.'/'.$files[$path][0]);
