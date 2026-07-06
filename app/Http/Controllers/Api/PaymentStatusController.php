<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CarteiraRecarga;
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
        $userId = $request->user()->id;

        $pedido = Pedido::query()
            ->where('user_id', $userId)
            ->where(function ($query) use ($paymentId) {
                $query->where('gateway_payment_id', $paymentId)
                    ->orWhere('gateway_order_id', $paymentId);
            })
            ->first();

        if ($pedido) {
            $pedido = $this->paymentSyncService->syncPedido($pedido);

            return response()->json(FrontendPresenter::paymentStatus($pedido));
        }

        $recarga = CarteiraRecarga::query()
            ->where('user_id', $userId)
            ->where(function ($query) use ($paymentId) {
                $query->where('gateway_payment_id', $paymentId)
                    ->orWhere('gateway_order_id', $paymentId)
                    ->orWhere('id', $paymentId);
            })
            ->firstOrFail();

        $recarga = $this->paymentSyncService->syncRecarga($recarga);

        return response()->json(FrontendPresenter::walletTopUpPaymentStatus($recarga));
    }
}
