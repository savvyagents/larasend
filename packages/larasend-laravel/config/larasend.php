<?php

return [
    'api_key' => env('LARASEND_API_KEY'),
    'endpoint' => env('LARASEND_ENDPOINT', 'https://api.larasend.test'),
    'timeout' => env('LARASEND_TIMEOUT', 15),
];
