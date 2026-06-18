<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\MercadoPagoWebhookService;
use App\Support\MercadoPagoWebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoPagoWebhookController extends Controller
{
    public function __construct(
        private readonly MercadoPagoWebhookService $webhookService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('mercadopago.webhook_secret');
        $secret = is_string($secret) ? trim($secret) : '';

        if ($secret !== '' && ! MercadoPagoWebhookSignature::isValid($request, $secret)) {
            Log::channel('single')->warning('payments.webhook_invalid_signature', [
                'request_id' => $request->header('x-request-id'),
                'data_id' => $request->query('data.id'),
            ]);

            return response()->json(['message' => 'Assinatura invalida.'], 401);
        }

        if ($secret === '') {
            Log::channel('single')->warning('payments.webhook_secret_missing');
        }

        $queryDataId = $request->query('data.id');
        $queryDataId = is_string($queryDataId) ? $queryDataId : null;

        $this->webhookService->handle($request->all(), $queryDataId);

        return response()->json(['received' => true]);
    }
}
