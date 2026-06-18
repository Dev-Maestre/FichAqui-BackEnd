<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mercado Pago credentials
    |--------------------------------------------------------------------------
    |
    | Public Key: exposta ao front via GET /api/payments/config (MercadoPago.js).
    | Access Token: apenas no servidor; nunca enviar ao client.
    |
    */

    'public_key' => env('MP_PUBLIC_KEY'),

    'access_token' => env('MP_ACCESS_TOKEN'),

    'webhook_secret' => env('MP_WEBHOOK_SECRET'),

    'webhook_url' => env('MP_WEBHOOK_URL'),

    'sandbox' => env('MP_SANDBOX', true),

    'locale' => env('MP_LOCALE', 'pt-BR'),

    'api_base_url' => env('MP_API_BASE_URL', 'https://api.mercadopago.com'),

    /*
    |--------------------------------------------------------------------------
    | PIX via Orders API (QR Code)
    |--------------------------------------------------------------------------
    |
    | pix_driver: "orders" (POST /v1/orders, QR dinamico) ou "payments" (legado)
    | qr_mode: dynamic | static | hybrid (ver documentacao MP)
    | qr_expiration: duracao ISO 8601 (min 30s, max 3600h; padrao MP 15min)
    |
    */

    'pix_driver' => env('MP_PIX_DRIVER', 'orders'),

    'qr_mode' => env('MP_QR_MODE', 'dynamic'),

    'qr_expiration' => env('MP_QR_EXPIRATION', 'PT15M'),

    'qr_external_pos_id' => env('MP_QR_EXTERNAL_POS_ID'),

];
