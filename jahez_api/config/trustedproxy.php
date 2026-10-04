<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated IP addresses or CIDR ranges of the reverse proxies or load
    | balancers in front of the application, or "*" to trust the immediate
    | caller. Read by Laravel's TrustProxies middleware so client IPs (used for
    | guest rate limiting and logs) come from X-Forwarded-For. Empty means no
    | proxy is trusted and forwarded headers are ignored, which is the safe
    | default when the application is reached directly.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
