<?php

namespace Database\Seeders;

use App\Models\CartaoSalvo;
use App\Models\Carteira;
use App\Models\CarteiraMovimento;
use App\Models\CarteiraRecarga;
use App\Models\User;
use App\Services\CarteiraLedgerService;
use Illuminate\Database\Seeder;

class WalletSeeder extends Seeder
{
    private const SEED_CREDIT_AMOUNT = 80.00;

    private const SEED_DEBIT_AMOUNT = 34.00;

    public function run(): void
    {
        $maria = User::query()
            ->where('external_id', 'user-apro')
            ->first();
        if (! $maria) {
            return;
        }

        $carteira = Carteira::query()->firstOrCreate(
            ['user_id' => $maria->id],
            ['balance' => 0],
        );

        CartaoSalvo::query()
            ->where('user_id', $maria->id)
            ->where('id', '!=', 'card-1')
            ->delete();

        CartaoSalvo::query()->updateOrCreate(
            ['id' => 'card-1'],
            [
                'user_id' => $maria->id,
                'brand' => 'visa',
                'last_four' => '5682',
                'holder_name' => 'APRO Silva',
                'is_default' => true,
            ]
        );

        $seedCreditKey = 'recarga:recarga-seed-inicial:credito';
        if (CarteiraMovimento::query()->where('idempotency_key', $seedCreditKey)->exists()) {
            return;
        }

        $hasOtherMovements = CarteiraMovimento::query()
            ->where('user_id', $maria->id)
            ->exists();

        if (! $hasOtherMovements) {
            $carteira->update(['balance' => 0]);
        }

        $ledger = app(CarteiraLedgerService::class);

        $recarga = CarteiraRecarga::query()->updateOrCreate(
            ['id' => 'recarga-seed-inicial'],
            [
                'user_id' => $maria->id,
                'amount' => self::SEED_CREDIT_AMOUNT,
                'payment_method' => 'pix',
                'payment_status' => 'pending',
                'gateway_payment_id' => 'seed-recarga-inicial',
                'gateway_order_id' => 'seed-order-recarga-inicial',
            ],
        );

        $ledger->creditarRecarga($recarga);
        $ledger->debitarCompra(
            pedidoId: 'pedido-seed-wallet',
            userId: $maria->id,
            amount: self::SEED_DEBIT_AMOUNT,
        );
    }
}
