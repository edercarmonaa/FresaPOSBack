<?php

return [
    'routes' => false,
    'stateful' => [],
    // API móvil exclusivamente bearer; Web usa la sesión nativa y CSRF.
    'guard' => [],
    'expiration' => null, // Cada token emitido tiene expires_at explícito.
    'token_prefix' => '',
];
