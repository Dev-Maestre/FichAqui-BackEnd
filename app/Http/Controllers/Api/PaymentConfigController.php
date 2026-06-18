<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentConfigService;
use Illuminate\Http\JsonResponse;

class PaymentConfigController extends Controller
{
    public function __construct(
        private readonly PaymentConfigService $paymentConfigService,
    ) {}

    /**
     * Configuracao publica para inicializar MercadoPago.js no front-end.
     *
     * @see https://www.mercadopago.com.br/developers/pt/docs/sdks-library/client-side/mp-js-v2
     */
    public function show(): JsonResponse
    {
        return response()->json($this->paymentConfigService->clientConfig());
    }
}
