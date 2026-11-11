<?php

return [
    'web_idle_minutes' => (int) env('AUTH_WEB_IDLE_MINUTES', 30),
    'api_token_minutes' => (int) env('AUTH_API_TOKEN_MINUTES', 480),
    'max_web_sessions' => (int) env('AUTH_MAX_WEB_SESSIONS', 5),
    'max_api_sessions' => (int) env('AUTH_MAX_API_SESSIONS', 5),
    'login_attempts_per_minute' => 5,
    'pin_attempts_per_minute' => 5,
];
