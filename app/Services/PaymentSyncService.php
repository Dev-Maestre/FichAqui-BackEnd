<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\CarteiraRecarga;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentSyncService
{
    public function __construct(
        private readonly PaymentGateway $paymentGateway,
        private readonly PedidoFulfillmentService $fulfillmentService,
        private readonly CarteiraLedgerService $carteiraLedgerService,
        private readonly SavedCardService $savedCardService,
    ) {}

    public function syncByGatewayPaymentId(string $gatewayReferenceId): Pedido|CarteiraRecarga|null
    {
        $pedido = Pedido::query()
            ->where('gateway_payment_id', $gatewayReferenceId)
            ->orWhere('gateway_order_id', $gatewayReferenceId)
            ->first();

        if ($pedido) {
            return $this->syncPedido($pedido);
        }

        $recarga = CarteiraRecarga::query()
            ->where('gateway_payment_id', $gatewayReferenceId)
            ->orWhere('gateway_order_id', $gatewayReferenceId)
            ->first();

        if ($recarga) {
            return $this->syncRecarga($recarga);
        }

        return null;
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
            $fulfilled = DB::transaction(function () use ($pedido) {
                $pedido = Pedido::query()->lockForUpdate()->findOrFail($pedido->id);

                if ($pedido->payment_status !== 'paid') {
                    $pedido->update(['payment_status' => 'paid']);
                }

                return $this->fulfillmentService->fulfillIfPaid($pedido);
            });

            if ($fulfilled->save_card) {
                $user = User::query()->findOrFail($fulfilled->user_id);
                $this->savedCardService->maybeSaveAfterPayment(
                    $user,
                    true,
                    $fulfilled->card_id !== null && $fulfilled->card_id !== '',
                );
                $fulfilled->update(['save_card' => false]);
            }

            return $fulfilled->fresh(['itens', 'fichas']);
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

    public function syncRecarga(CarteiraRecarga $recarga): CarteiraRecarga
    {
        if ($recarga->credited_at !== null) {
            return $recarga;
        }

        if (! $this->paymentGateway->isConfigured()) {
            return $recarga;
        }

        $hasOrder = $recarga->gateway_order_id !== null && $recarga->gateway_order_id !== '';
        $hasPayment = $recarga->gateway_payment_id !== null && $recarga->gateway_payment_id !== '';

        if (! $hasOrder && ! $hasPayment) {
            return $recarga;
        }

        $result = $hasOrder
            ? $this->paymentGateway->getOrder($recarga->gateway_order_id)
            : $this->paymentGateway->getPayment($recarga->gateway_payment_id);

        if ($result->gatewayPaymentId !== '' && $result->gatewayPaymentId !== $recarga->gateway_payment_id) {
            $recarga->update(['gateway_payment_id' => $result->gatewayPaymentId]);
        }

        if ($result->isApproved()) {
            return DB::transaction(function () use ($recarga) {
                $recarga = CarteiraRecarga::query()->lockForUpdate()->findOrFail($recarga->id);

                if ($recarga->credited_at !== null) {
                    return $recarga;
                }

                $recarga->update(['payment_status' => 'paid']);

                $credited = $this->carteiraLedgerService->creditarRecarga($recarga);

                if ($credited->save_card) {
                    $user = User::query()->findOrFail($credited->user_id);
                    $this->savedCardService->maybeSaveAfterPayment($user, true, false);
                    $credited->update(['save_card' => false]);
                }

                return $credited;
            });
        }

        if ($result->isPending()) {
            return $recarga->fresh();
        }

        Log::channel('single')->info('payments.wallet_topup_failed', [
            'recarga_id' => $recarga->id,
            'gateway_order_id' => $recarga->gateway_order_id,
            'gateway_payment_id' => $recarga->gateway_payment_id,
            'status' => $result->status,
        ]);

        $recarga->update(['payment_status' => 'failed']);

        return $recarga->fresh();
    }
}
