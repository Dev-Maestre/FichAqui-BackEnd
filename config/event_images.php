<?php

return [

    'max_kilobytes' => (int) env('EVENT_IMAGE_MAX_KB', 5120),

    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ],

    'upload_url_ttl_minutes' => (int) env('EVENT_IMAGE_UPLOAD_TTL', 15),

];
