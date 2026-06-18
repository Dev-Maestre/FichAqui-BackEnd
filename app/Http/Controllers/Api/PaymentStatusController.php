<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Services\FrontendPresenter;
use App\Services\PaymentSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentStatusController extends Controller
{
    public function __construct(
        private readonly PaymentSyncService $paymentSyncService,
    ) {}

    public function show(Request $request, string $paymentId): JsonResponse
    {
        $pedido = Pedido::query()
            ->where('user_id', $request->user()->id)
            ->where(function ($query) use ($paymentId) {
                $query->where('gateway_payment_id', $paymentId)
                    ->orWhere('gateway_order_id', $paymentId);
            })
            ->firstOrFail();

        $pedido = $this->paymentSyncService->syncPedido($pedido);

        return response()->json(FrontendPresenter::paymentStatus($pedido));
    }
}
