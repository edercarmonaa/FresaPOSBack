<?php

return [
    'web_idle_minutes' => (int) env('AUTH_WEB_IDLE_MINUTES', 30),
    'api_token_minutes' => (int) env('AUTH_API_TOKEN_MINUTES', 480),
    'max_web_sessions' => (int) env('AUTH_MAX_WEB_SESSIONS', 5),
    'max_api_sessions' => (int) env('AUTH_MAX_API_SESSIONS', 5),
    'failed_attempts' => (int) env('AUTH_FAILED_ATTEMPTS', 5),
    'lock_seconds' => (int) env('AUTH_LOCK_SECONDS', 300),
];
