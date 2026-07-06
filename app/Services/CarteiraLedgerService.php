<?php

namespace App\Services;

use App\Models\Carteira;
use App\Models\CarteiraMovimento;
use App\Models\CarteiraRecarga;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CarteiraLedgerService
{
    public function creditarRecarga(CarteiraRecarga $recarga): CarteiraRecarga
    {
        if ($recarga->credited_at !== null) {
            return $recarga->fresh();
        }

        $this->registrarMovimento(
            userId: $recarga->user_id,
            direction: 'credito',
            tipo: 'recarga',
            amount: (float) $recarga->amount,
            origemTipo: 'recarga',
            origemId: $recarga->id,
            idempotencyKey: "recarga:{$recarga->id}:credito",
            descricao: 'Recarga via '.strtoupper($recarga->payment_method),
            metadata: [
                'paymentMethod' => $recarga->payment_method,
                'gatewayPaymentId' => $recarga->gateway_payment_id,
                'gatewayOrderId' => $recarga->gateway_order_id,
            ],
        );

        $recarga->update([
            'payment_status' => 'paid',
            'credited_at' => now(),
        ]);

        return $recarga->fresh();
    }

    public function debitarCompra(string $pedidoId, int $userId, float $amount, ?string $descricao = null): CarteiraMovimento
    {
        return $this->registrarMovimento(
            userId: $userId,
            direction: 'debito',
            tipo: 'compra',
            amount: $amount,
            origemTipo: 'pedido',
            origemId: $pedidoId,
            idempotencyKey: "pedido:{$pedidoId}:debito",
            descricao: $descricao ?? 'Compra com saldo da carteira',
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function registrarMovimento(
        int $userId,
        string $direction,
        string $tipo,
        float $amount,
        string $origemTipo,
        string $origemId,
        string $idempotencyKey,
        ?string $descricao = null,
        ?array $metadata = null,
    ): CarteiraMovimento {
        $existing = CarteiraMovimento::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $carteira = Carteira::query()->lockForUpdate()->firstOrCreate(
            ['user_id' => $userId],
            ['balance' => 0],
        );

        $amount = round($amount, 2);
        $currentBalance = round((float) $carteira->balance, 2);

        if ($direction === 'debito' && $currentBalance < $amount) {
            throw ValidationException::withMessages([
                'paymentMethod' => ['Saldo insuficiente na carteira.'],
            ]);
        }

        $newBalance = $direction === 'credito'
            ? round($currentBalance + $amount, 2)
            : round($currentBalance - $amount, 2);

        $movimento = CarteiraMovimento::query()->create([
            'id' => 'mov-'.Str::lower((string) Str::ulid()),
            'user_id' => $userId,
            'direction' => $direction,
            'tipo' => $tipo,
            'amount' => $amount,
            'saldo_apos' => $newBalance,
            'origem_tipo' => $origemTipo,
            'origem_id' => $origemId,
            'descricao' => $descricao,
            'idempotency_key' => $idempotencyKey,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);

        $carteira->update(['balance' => $newBalance]);

        return $movimento;
    }
}
