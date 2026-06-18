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

    'webhook_url' => env('MP_WEBHOOK_URL', 'https://fichaqui.baiacubo.tech/webhook-mp'),

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

    'pix_expiration' => env('MP_PIX_EXPIRATION', 'PT15M'),

    /*
    |--------------------------------------------------------------------------
    | Endereco de entrega (obrigatorio para PIX online na Orders API)
    |--------------------------------------------------------------------------
    */

    'pix_shipment' => [
        'zip_code' => env('MP_PIX_SHIPMENT_ZIP', '80010000'),
        'street_name' => env('MP_PIX_SHIPMENT_STREET', 'Local do evento'),
        'street_number' => env('MP_PIX_SHIPMENT_NUMBER', '1'),
        'neighborhood' => env('MP_PIX_SHIPMENT_NEIGHBORHOOD', 'Centro'),
        'city' => env('MP_PIX_SHIPMENT_CITY', 'CURITIBA'),
        'state' => env('MP_PIX_SHIPMENT_STATE', 'PR'),
        'complement' => env('MP_PIX_SHIPMENT_COMPLEMENT', ''),
    ],

];
