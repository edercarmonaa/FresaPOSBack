<?php

// Lista cerrada de recursos para fixtures de QA. Nunca arranca Laravel.
$root = dirname(__DIR__, 4);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$files = [
    '/admin.html' => ['docs/ui-ux/qa/ui-03/fixtures/admin.html', 'text/html; charset=utf-8'],
    '/pos.html' => ['docs/ui-ux/qa/ui-03/fixtures/pos.html', 'text/html; charset=utf-8'],
    '/reports.html' => ['docs/ui-ux/qa/ui-03/fixtures/reports.html', 'text/html; charset=utf-8'],
    '/css/ui/tokens.css' => ['public/css/ui/tokens.css', 'text/css'],
    '/css/ui/components.css' => ['public/css/ui/components.css', 'text/css'],
    '/css/ui/layouts.css' => ['public/css/ui/layouts.css', 'text/css'],
    '/js/ui/theme.js' => ['public/js/ui/theme.js', 'application/javascript'],
    '/js/ui/layout.js' => ['public/js/ui/layout.js', 'application/javascript'],
    '/fonts/ui/InterVariable.woff2' => ['public/fonts/ui/InterVariable.woff2', 'font/woff2'],
    '/favicon.ico' => ['public/favicon.ico', 'image/x-icon'],
];
if (is_string($path) && preg_match('~^/livewire-[a-f0-9]+/livewire(?:\.min)?\.js$~', $path)) {
    $files[$path] = ['vendor/livewire/livewire/dist/livewire.js', 'application/javascript'];
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || ! isset($files[$path])) {
    http_response_code(404);
    exit;
}
header('Content-Type: '.$files[$path][1]);
header('X-Content-Type-Options: nosniff');
readfile($root.'/'.$files[$path][0]);
