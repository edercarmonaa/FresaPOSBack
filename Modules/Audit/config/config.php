<?php

return [
    'name' => 'Audit',
    'security_retention_days' => max(1, (int) env('AUDIT_SECURITY_RETENTION_DAYS', 365)),
];
