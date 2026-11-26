<?php

// Recursos enumerados de QA; GET únicamente, sin iniciar Laravel.
$root = dirname(__DIR__, 4);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$files = ['/' => ['docs/ui-ux/catalogo/ui-06/index.html', 'text/html; charset=utf-8']];
foreach (['tokens', 'components', 'layouts', 'admin'] as $name) {
    $files['/css/ui/'.$name.'.css'] = ['public/css/ui/'.$name.'.css', 'text/css'];
}
foreach (['theme', 'admin'] as $name) {
    $files['/js/ui/'.$name.'.js'] = ['public/js/ui/'.$name.'.js', 'application/javascript'];
}
$files['/fonts/ui/InterVariable.woff2'] = ['public/fonts/ui/InterVariable.woff2', 'font/woff2'];
$files['/favicon.ico'] = ['public/favicon.ico', 'image/x-icon'];
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || ! isset($files[$path])) {
    http_response_code(404);
    exit;
}
header('Content-Type: '.$files[$path][1]);
header('X-Content-Type-Options: nosniff');
readfile($root.'/'.$files[$path][0]);
