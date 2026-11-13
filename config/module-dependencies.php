<?php

// Lista de permisos; no son dependencias obligatorias ni orden de arranque.
return [
    'Core' => [],
    'Auth' => ['Core', 'Users'],
    'Users' => ['Core'],
    'Catalog' => ['Core', 'Users'],
    'Sales' => ['Core', 'Users', 'Catalog', 'Cash'],
    'Cash' => ['Core'],
    'Tickets' => ['Core'],
    'Reports' => ['Core'],
    'Audit' => ['Core'],
    'Notifications' => ['Core'],
    'Devices' => ['Core'],
    'Settings' => ['Core'],
    'System' => ['Core'],
];
