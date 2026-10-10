<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The SPA runs on its own domain and talks to this API with a Bearer
    | token, so any origin may call the API. Credentials (cookies) are not
    | used, which is why "*" is safe here.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition', 'X-Total-Count'],

    'max_age' => 86400,

    'supports_credentials' => false,

];
