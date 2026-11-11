<?php

// Catálogo inicial de Fase 1; registrar una capacidad no implementa su caso de uso.
return [
    'permissions' => [
        'users.view', 'users.create', 'users.update', 'users.status', 'users.delete', 'users.roles.assign',
        'roles.view', 'roles.permissions.manage', 'users.password.reset',
        'catalog.products.view', 'catalog.products.create', 'catalog.products.update', 'catalog.products.deactivate',
        'catalog.products.restore', 'catalog.categories.manage',
        'sales.view', 'sales.create', 'sales.open.update', 'sales.cancel', 'sales.close', 'sales.reopen', 'sales.discounts.apply', 'sales.special.authorize',
        'cash.open', 'cash.view', 'cash.movements.create', 'cash.close', 'cash.closings.view', 'cash.administrative.execute',
        'tickets.view', 'tickets.print', 'tickets.settings.manage', 'reports.view', 'reports.export', 'audit.view',
        'notifications.view', 'notifications.resolve', 'notifications.critical.resolve', 'notifications.settings.manage',
        'devices.manage', 'settings.view', 'settings.update', 'system.view', 'system.administrative.execute',
    ],
    'labels' => ['admin' => 'Administrador', 'supervisor' => 'Supervisor', 'cashier' => 'Cajero', 'waiter' => 'Mesero'],
    'initial_roles' => [
        // admin recibe el catálogo explícito en el primer seeding, nunca un bypass.
        'admin' => null,
        'supervisor' => [
            'catalog.products.view', 'sales.view', 'sales.create', 'sales.open.update', 'sales.cancel', 'sales.close',
            'sales.discounts.apply', 'sales.special.authorize', 'cash.open', 'cash.view', 'cash.movements.create',
            'cash.close', 'cash.closings.view', 'tickets.view', 'tickets.print', 'reports.view',
            'notifications.view', 'notifications.resolve',
        ],
        'cashier' => [
            'catalog.products.view', 'sales.view', 'sales.create', 'sales.open.update', 'sales.close',
            'cash.open', 'cash.view', 'cash.movements.create', 'cash.close', 'cash.closings.view',
            'tickets.view', 'tickets.print', 'notifications.view',
        ],
        'waiter' => ['catalog.products.view', 'sales.view', 'sales.create', 'sales.open.update', 'tickets.view', 'notifications.view'],
    ],
    'protected_admin_permissions' => [
        'users.view', 'users.create', 'users.update', 'users.status', 'users.delete', 'users.roles.assign',
        'roles.view', 'roles.permissions.manage',
    ],
];
