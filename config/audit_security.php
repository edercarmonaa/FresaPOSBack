<?php

return [
    'retention_days' => max(1, (int) env('AUDIT_SECURITY_RETENTION_DAYS', 365)),
];
