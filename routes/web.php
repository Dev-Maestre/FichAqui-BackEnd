<?php

use App\Http\Controllers\Api\MercadoPagoWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'FichAqui API',
        'health' => url('/api/health'),
        'docs' => url('/docs'),
        'openapi' => url('/openapi.yaml'),
    ]);
});

Route::view('/docs', 'swagger');

Route::post('/webhook-mp', MercadoPagoWebhookController::class);
