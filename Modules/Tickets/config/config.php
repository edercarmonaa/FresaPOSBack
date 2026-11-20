<?php

return [
    'name' => 'Tickets',
    // Configuración no sensible; omitir campos no registrados. No usar APP_NAME como razón social.
    'establishment' => ['name' => null, 'branch' => null, 'address' => null, 'phone' => null, 'fiscal_id' => null, 'footer' => null],
    'print_fiscal_id' => false,
];
