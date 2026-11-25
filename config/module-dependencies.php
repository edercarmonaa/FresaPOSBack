<?php

// Lista de permisos; no son dependencias obligatorias ni orden de arranque.
return [
    'Core' => [],
    'Auth' => ['Core', 'Users'],
    'Users' => ['Core'],
    'Catalog' => ['Core', 'Users'],
    'Sales' => ['Core', 'Users', 'Catalog', 'Cash', 'Auth'],
    'Cash' => ['Core', 'Users'],
    'Tickets' => ['Core', 'Sales', 'Users', 'Auth'],
    'Reports' => ['Core', 'Sales', 'Cash', 'Users', 'Auth'],
    'Audit' => ['Core'],
    'Notifications' => ['Core'],
    'Devices' => ['Core'],
    'Settings' => ['Core'],
    'System' => ['Core'],
];
