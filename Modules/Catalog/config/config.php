<?php

return [
    'name' => 'Catalog',
    'images' => [
        'disk' => 'public',
        'journal_disk' => 'local',
        'max_bytes' => 5 * 1024 * 1024,
        'max_pixels' => 8000000,
        'max_input_dimension' => 12000,
        'max_dimension' => 1200,
        'quality' => 82,
        'max_processing_bytes' => 192 * 1024 * 1024,
        'recovery_age_hours' => 24,
    ],
];
