<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoPagoWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentSyncService $paymentSyncService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $type = $request->input('type') ?? $request->input('action');
        $referenceId = data_get($request->all(), 'data.id')
            ?? $request->input('data.id')
            ?? $request->input('id');

        Log::channel('single')->info('payments.webhook_received', [
            'type' => $type,
            'reference_id' => $referenceId,
        ]);

        if ($referenceId === null || $referenceId === '') {
            return response()->json(['received' => true]);
        }

        $this->paymentSyncService->syncByGatewayPaymentId((string) $referenceId);

        return response()->json(['received' => true]);
    }
}
