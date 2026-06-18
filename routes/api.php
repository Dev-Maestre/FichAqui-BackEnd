<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\FichaController;
use App\Http\Controllers\Api\MercadoPagoWebhookController;
use App\Http\Controllers\Api\OfferingController;
use App\Http\Controllers\Api\PaymentConfigController;
use App\Http\Controllers\Api\PaymentStatusController;
use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RelatorioController;
use App\Http\Controllers\Api\StallController;
use App\Http\Controllers\Api\User\UserFichaController;
use App\Http\Controllers\Api\User\UserPedidoController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'FichAqui API',
]));

Route::get('/bootstrap', [BootstrapController::class, 'bootstrap']);
Route::get('/catalog', [BootstrapController::class, 'catalog']);
Route::get('/cities', [CityController::class, 'index']);
Route::get('/payments/config', [PaymentConfigController::class, 'show']);

Route::post('/webhooks/mercadopago', MercadoPagoWebhookController::class);

Route::post('/auth/login', [AuthController::class, 'login']);

Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{eventId}', [EventController::class, 'show']);
Route::get('/events/{eventId}/stalls', [EventController::class, 'stalls']);
Route::get('/events/{eventId}/offerings', [OfferingController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::patch('/user/profile', [ProfileController::class, 'update']);
    Route::post('/events', [EventController::class, 'store']);
    Route::patch('/events/{eventId}', [EventController::class, 'update']);
    Route::get('/events/{eventId}/relatorios', [RelatorioController::class, 'show']);
    Route::post('/events/{eventId}/stalls', [StallController::class, 'store']);
    Route::patch('/events/{eventId}/stalls/{stallId}', [StallController::class, 'update']);
    Route::put('/events/{eventId}/stalls/{stallId}/offerings', [OfferingController::class, 'replaceForStall']);
    Route::get('/user/wallet', [WalletController::class, 'show']);
    Route::get('/user/pedidos', [UserPedidoController::class, 'index']);
    Route::get('/user/fichas', [UserFichaController::class, 'index']);
    Route::get('/events/{eventId}/pedidos', [PedidoController::class, 'index']);
    Route::post('/events/{eventId}/pedidos', [PedidoController::class, 'store']);
    Route::get('/payments/{paymentId}/status', [PaymentStatusController::class, 'show']);
    Route::get('/fichas', [FichaController::class, 'show']);
    Route::patch('/fichas/{fichaId}/status', [FichaController::class, 'updateStatus']);
    Route::post('/fichas/{fichaId}/consume', [FichaController::class, 'consume']);
});
