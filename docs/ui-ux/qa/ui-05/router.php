<?php

// Solo QA: GET de archivos explícitos, sin arranque de Laravel.
$root = dirname(__DIR__, 4);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$files = [];
foreach (['before', 'after'] as $phase) {
    foreach (['summary', 'sales', 'payments', 'cash-sessions', 'empty', 'error'] as $name) {
        $files['/'.$phase.'-'.$name.'.html'] = ['docs/ui-ux/qa/ui-05/fixtures/'.$phase.'-'.$name.'.html', 'text/html; charset=utf-8'];
    }
}
foreach (['tokens', 'components', 'layouts', 'reports'] as $name) {
    $files['/css/ui/'.$name.'.css'] = ['public/css/ui/'.$name.'.css', 'text/css'];
}
foreach (['theme', 'layout'] as $name) {
    $files['/js/ui/'.$name.'.js'] = ['public/js/ui/'.$name.'.js', 'application/javascript'];
}
$files['/fonts/ui/InterVariable.woff2'] = ['public/fonts/ui/InterVariable.woff2', 'font/woff2'];
$files['/favicon.ico'] = ['public/favicon.ico', 'image/x-icon'];
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
