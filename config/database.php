<?php

return [
    'default' => env('DB_CONNECTION', 'sqlsrv'),
    'connections' => [
        // Independiente: nunca hereda la base ni credenciales DB_* de la aplicación.
        'sqlsrv_testing' => [
            'driver' => 'sqlsrv',
            'host' => env('TEST_DB_HOST'),
            'port' => env('TEST_DB_PORT', ''),
            'database' => env('TEST_DB_DATABASE'),
            'username' => env('TEST_DB_USERNAME'),
            'password' => env('TEST_DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => env('TEST_DB_ENCRYPT', 'yes'),
            'trust_server_certificate' => env('TEST_DB_TRUST_SERVER_CERTIFICATE', 'no'),
        ],
        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', ''),
            'database' => env('DB_DATABASE', 'fresapos'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => env('DB_ENCRYPT', 'yes'),
            'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'no'),
        ],
    ],
    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];
