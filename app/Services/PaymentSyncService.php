<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\Pedido;
use Illuminate\Support\Facades\Log;

class PaymentSyncService
{
    public function __construct(
        private readonly PaymentGateway $paymentGateway,
        private readonly PedidoFulfillmentService $fulfillmentService,
    ) {}

    public function syncByGatewayPaymentId(string $gatewayReferenceId): ?Pedido
    {
        $pedido = Pedido::query()
            ->where('gateway_payment_id', $gatewayReferenceId)
            ->orWhere('gateway_order_id', $gatewayReferenceId)
            ->first();

        if (! $pedido) {
            return null;
        }

        return $this->syncPedido($pedido);
    }

    public function syncPedido(Pedido $pedido): Pedido
    {
        if (! $this->paymentGateway->isConfigured()) {
            return $pedido;
        }

        $hasOrder = $pedido->gateway_order_id !== null && $pedido->gateway_order_id !== '';
        $hasPayment = $pedido->gateway_payment_id !== null && $pedido->gateway_payment_id !== '';

        if (! $hasOrder && ! $hasPayment) {
            return $pedido;
        }

        $result = $hasOrder
            ? $this->paymentGateway->getOrder($pedido->gateway_order_id)
            : $this->paymentGateway->getPayment($pedido->gateway_payment_id);

        if ($result->gatewayPaymentId !== '' && $result->gatewayPaymentId !== $pedido->gateway_payment_id) {
            $pedido->update(['gateway_payment_id' => $result->gatewayPaymentId]);
        }

        if ($result->isApproved()) {
            $pedido->update(['payment_status' => 'paid']);

            return $this->fulfillmentService->fulfillIfPaid($pedido);
        }

        if ($result->isPending()) {
            return $pedido->fresh(['itens', 'fichas']);
        }

        Log::channel('single')->info('payments.payment_failed', [
            'pedido_id' => $pedido->id,
            'gateway_order_id' => $pedido->gateway_order_id,
            'gateway_payment_id' => $pedido->gateway_payment_id,
            'status' => $result->status,
        ]);

        return $this->fulfillmentService->markPaymentFailed($pedido);
    }
}
