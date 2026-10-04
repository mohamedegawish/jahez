<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Rate Limit
    |--------------------------------------------------------------------------
    |
    | Requests allowed per minute for the default "api" rate limiter, keyed by
    | the authenticated user or, for guests, the client IP address. This is a
    | provisional technical default, not a measured capacity figure.
    |
    */

    'rate_limit_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 60),

    /*
    |--------------------------------------------------------------------------
    | Front-end URL
    |--------------------------------------------------------------------------
    |
    | Base URL of the client application. Password reset and invitation emails
    | link to {frontend_url}/reset-password?token=...&email=..., a page the
    | client implements and which posts to POST /api/v1/auth/reset-password.
    |
    */

    'frontend_url' => (string) env('FRONTEND_URL', 'http://localhost:3000'),

];
