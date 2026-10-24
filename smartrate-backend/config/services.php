<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mock OTA Receiver
    |--------------------------------------------------------------------------
    |
    | The dispatcher pushes rate/inventory updates to the external OTA
    | endpoints. Locally the mock OTA servers run in a second `artisan
    | serve` process so the loopback never deadlocks the single-threaded
    | PHP built-in web server (Windows limitation).
    |
    */

    'ota' => [
        'base_url' => env('OTA_RECEIVER_BASE_URL', env('APP_URL', 'http://127.0.0.1:8000')),
    ],

];
