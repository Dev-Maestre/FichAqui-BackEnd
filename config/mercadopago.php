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
    | pix_driver:
    |   online   => POST /v1/orders type:online (PIX in-app, padrao)
    |   payments => POST /v1/payments (legado)
    |   orders / qr_pos => POST /v1/orders type:qr + MP_QR_EXTERNAL_POS_ID (caixa fisico)
    |
    */

    'pix_driver' => env('MP_PIX_DRIVER', 'online'),

    'qr_mode' => env('MP_QR_MODE', 'dynamic'),

    'qr_expiration' => env('MP_QR_EXPIRATION', 'PT15M'),

    'qr_external_pos_id' => env('MP_QR_EXTERNAL_POS_ID'),

];
