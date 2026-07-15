<?php

namespace Database\Seeders;

use App\Models\Carteira;
use App\Models\CarteiraRecarga;
use App\Models\Ficha;
use App\Models\OfertaVariante;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\User;
use App\Services\CarteiraLedgerService;
use App\Services\EstoqueService;
use App\Support\ItemNameFormatter;
use Illuminate\Database\Seeder;
use RuntimeException;

class StagingDemoSeeder extends Seeder
{
    private const EVENT_ID = '1';

    private const OFFERING_ID = 'offering-1-stall-1-pastel';

    private const VARIANT_SLUG = 'carne';

    private const UNIT_PRICE = 8.0;

    private const DEMO_CREDIT_AMOUNT = 80.0;

    public function run(): void
    {
        $maria = $this->requireMaria();
        $variante = $this->requirePastelCarneVariante();
        $line = $this->buildLine($variante);

        $ledger = app(CarteiraLedgerService::class);

        $this->seedInitialWalletCredit($maria, $ledger);

        $this->seedWalletPedido(
            ledger: $ledger,
            maria: $maria,
            line: $line,
            pedidoId: 'pedido-demo-wallet',
            number: '9001',
            total: 16.0,
            quantity: 2,
            pedidoStatus: 'available',
            fichas: [
                ['id' => 'ficha-demo-wallet-1', 'qr' => 'QR-DEMO-PASTEL-01', 'status' => 'available'],
                ['id' => 'ficha-demo-wallet-2', 'qr' => 'QR-DEMO-PASTEL-02', 'status' => 'available'],
            ],
        );

        $this->seedWalletPedido(
            ledger: $ledger,
            maria: $maria,
            line: $line,
            pedidoId: 'pedido-demo-parcial',
            number: '9002',
            total: 16.0,
            quantity: 2,
            pedidoStatus: 'available',
            fichas: [
                ['id' => 'ficha-demo-parcial-1', 'qr' => 'QR-DEMO-PASTEL-03', 'status' => 'available'],
                ['id' => 'ficha-demo-parcial-2', 'qr' => 'QR-DEMO-PASTEL-04', 'status' => 'delivered'],
            ],
        );

        $this->seedWalletPedido(
            ledger: $ledger,
            maria: $maria,
            line: $line,
            pedidoId: 'pedido-demo-entregue',
            number: '9003',
            total: 16.0,
            quantity: 2,
            pedidoStatus: 'delivered',
            fichas: [
                ['id' => 'ficha-demo-entregue-1', 'qr' => 'QR-DEMO-PASTEL-06', 'status' => 'delivered'],
                ['id' => 'ficha-demo-entregue-2', 'qr' => 'QR-DEMO-PASTEL-07', 'status' => 'delivered'],
            ],
        );

        $this->seedPaidCardPedido($maria, $line);
        $this->seedPendingPixPedido($maria, $line);
        $this->seedFailedCardPedido($maria, $line);
        $this->seedPendingPixTopUp($maria);

        $this->printSummary($maria);
    }

    private function requireMaria(): User
    {
        $maria = User::query()
            ->where('external_id', 'user-maria')
            ->first();

        if ($maria === null) {
            throw new RuntimeException(
                'Missing demo user user-maria. Run `php artisan db:seed` before StagingDemoSeeder.',
            );
        }

        return $maria;
    }

    private function requirePastelCarneVariante(): OfertaVariante
    {
        $variante = OfertaVariante::query()
            ->with(['oferta.barraca', 'oferta.catalogoProduto', 'variantTemplate'])
            ->find(OfertaVariante::buildId(self::OFFERING_ID, self::VARIANT_SLUG));

        if ($variante === null || $variante->oferta?->evento_id !== self::EVENT_ID) {
            throw new RuntimeException(
                'Missing offering pastel/carne on event 1. Run `php artisan db:seed` before StagingDemoSeeder.',
            );
        }

        return $variante;
    }

    /**
     * @return array{variante: OfertaVariante, quantity: int, unitPrice: float, itemName: string, stallName: string, category: string, image: string}
     */
    private function buildLine(OfertaVariante $variante, int $quantity = 2): array
    {
        $variante->loadMissing(['oferta.barraca', 'oferta.catalogoProduto.variantTemplates', 'oferta.variantes', 'variantTemplate']);
        $oferta = $variante->oferta;
        $produto = $oferta->catalogoProduto;
        $templateLabel = $variante->variantTemplate->label;
        $availableVariantCount = $oferta->variantes->filter(fn (OfertaVariante $entry) => $entry->available)->count();
        $templateCount = $produto->variantTemplates->count();

        return [
            'variante' => $variante,
            'quantity' => $quantity,
            'unitPrice' => (float) $variante->price,
            'itemName' => ItemNameFormatter::format(
                $produto->name,
                $templateLabel,
                $availableVariantCount,
                $templateCount,
            ),
            'stallName' => $oferta->barraca->name,
            'category' => $produto->categoria_id,
            'image' => $produto->image,
        ];
    }

    private function seedInitialWalletCredit(User $maria, CarteiraLedgerService $ledger): void
    {
        $recarga = CarteiraRecarga::query()->updateOrCreate(
            ['id' => 'recarga-demo-inicial'],
            [
                'user_id' => $maria->id,
                'amount' => self::DEMO_CREDIT_AMOUNT,
                'payment_method' => 'pix',
                'payment_status' => 'pending',
                'gateway_payment_id' => 'demo-recarga-inicial',
                'gateway_order_id' => 'demo-order-recarga-inicial',
            ],
        );

        $ledger->creditarRecarga($recarga);
    }

    /**
     * @param  array{variante: OfertaVariante, quantity: int, unitPrice: float, itemName: string, stallName: string, category: string, image: string}  $line
     * @param  list<array{id: string, qr: string, status: string}>  $fichas
     */
    private function seedWalletPedido(
        CarteiraLedgerService $ledger,
        User $maria,
        array $line,
        string $pedidoId,
        string $number,
        float $total,
        int $quantity,
        string $pedidoStatus,
        array $fichas,
    ): void {
        $line['quantity'] = $quantity;

        Pedido::query()->updateOrCreate(
            ['id' => $pedidoId],
            [
                'evento_id' => self::EVENT_ID,
                'user_id' => $maria->id,
                'number' => $number,
                'total' => $total,
                'status' => $pedidoStatus,
                'qr_code' => 'QR-PEDIDO-'.strtoupper(str_replace('pedido-', '', $pedidoId)),
                'payment_method' => 'wallet',
                'payment_status' => 'paid',
            ],
        );

        $this->upsertPedidoItem($pedidoId, $line);

        $ledger->debitarCompra($pedidoId, $maria->id, $total);

        $this->seedFichasWithStock($pedidoId, $line, $fichas);
    }

    /**
     * @param  array{variante: OfertaVariante, quantity: int, unitPrice: float, itemName: string, stallName: string, category: string, image: string}  $line
     */
    private function seedPaidCardPedido(User $maria, array $line): void
    {
        $line = $this->buildLine($line['variante'], 1);

        Pedido::query()->updateOrCreate(
            ['id' => 'pedido-demo-card'],
            [
                'evento_id' => self::EVENT_ID,
                'user_id' => $maria->id,
                'number' => '9004',
                'total' => self::UNIT_PRICE,
                'status' => 'available',
                'qr_code' => 'QR-PEDIDO-DEMO-CARD',
                'payment_method' => 'credit_card',
                'card_id' => 'card-1',
                'payment_status' => 'paid',
                'gateway_payment_id' => 'demo-card-paid',
                'gateway_order_id' => 'demo-order-card-paid',
            ],
        );

        $this->upsertPedidoItem('pedido-demo-card', $line);

        $this->seedFichasWithStock('pedido-demo-card', $line, [
            ['id' => 'ficha-demo-card-1', 'qr' => 'QR-DEMO-PASTEL-05', 'status' => 'available'],
        ]);
    }

    /**
     * @param  array{variante: OfertaVariante, quantity: int, unitPrice: float, itemName: string, stallName: string, category: string, image: string}  $line
     */
    private function seedPendingPixPedido(User $maria, array $line): void
    {
        $line = $this->buildLine($line['variante'], 1);

        Pedido::query()->updateOrCreate(
            ['id' => 'pedido-demo-pix'],
            [
                'evento_id' => self::EVENT_ID,
                'user_id' => $maria->id,
                'number' => '9005',
                'total' => self::UNIT_PRICE,
                'status' => 'pending_payment',
                'qr_code' => 'QR-PEDIDO-DEMO-PIX',
                'payment_method' => 'pix',
                'payment_status' => 'pending',
                'gateway_payment_id' => 'demo-pix-pending',
                'gateway_order_id' => 'demo-order-pix-pending',
                'pix_qr_code' => 'https://demo.fichaqui.test/pix/pedido-demo-pix',
                'pix_copy_paste' => '00020126580014BR.GOV.BCB.PIX0136demo-pedido-pix',
                'pix_expires_at' => now()->addHour(),
            ],
        );

        $this->upsertPedidoItem('pedido-demo-pix', $line);
    }

    /**
     * @param  array{variante: OfertaVariante, quantity: int, unitPrice: float, itemName: string, stallName: string, category: string, image: string}  $line
     */
    private function seedFailedCardPedido(User $maria, array $line): void
    {
        $line = $this->buildLine($line['variante'], 1);

        Pedido::query()->updateOrCreate(
            ['id' => 'pedido-demo-recusado'],
            [
                'evento_id' => self::EVENT_ID,
                'user_id' => $maria->id,
                'number' => '9006',
                'total' => self::UNIT_PRICE,
                'status' => 'payment_failed',
                'qr_code' => 'QR-PEDIDO-DEMO-RECUSADO',
                'payment_method' => 'credit_card',
                'card_id' => 'card-1',
                'payment_status' => 'failed',
                'gateway_payment_id' => 'demo-card-failed',
                'gateway_order_id' => 'demo-order-card-failed',
            ],
        );

        $this->upsertPedidoItem('pedido-demo-recusado', $line);
    }

    private function seedPendingPixTopUp(User $maria): void
    {
        CarteiraRecarga::query()->updateOrCreate(
            ['id' => 'recarga-demo-pix'],
            [
                'user_id' => $maria->id,
                'amount' => 25.00,
                'payment_method' => 'pix',
                'payment_status' => 'pending',
                'gateway_payment_id' => 'demo-topup-pix',
                'gateway_order_id' => 'demo-order-topup-pix',
                'pix_qr_code' => 'https://demo.fichaqui.test/pix/recarga-demo-pix',
                'pix_copy_paste' => '00020126580014BR.GOV.BCB.PIX0136demo-recarga-pix',
                'pix_expires_at' => now()->addHour(),
                'credited_at' => null,
            ],
        );
    }

    /**
     * @param  array{variante: OfertaVariante, quantity: int, unitPrice: float, itemName: string, stallName: string, category: string, image: string}  $line
     */
    private function upsertPedidoItem(string $pedidoId, array $line): void
    {
        PedidoItem::query()->updateOrCreate(
            ['pedido_id' => $pedidoId],
            [
                'oferta_variante_id' => $line['variante']->id,
                'quantity' => $line['quantity'],
                'item_snapshot' => [
                    'name' => $line['itemName'],
                    'quantity' => $line['quantity'],
                    'stallName' => $line['stallName'],
                    'category' => $line['category'],
                    'image' => $line['image'],
                    'unitPrice' => $line['unitPrice'],
                ],
            ],
        );
    }

    /**
     * @param  array{variante: OfertaVariante, quantity: int, unitPrice: float, itemName: string, stallName: string, category: string, image: string}  $line
     * @param  list<array{id: string, qr: string, status: string}>  $fichas
     */
    private function seedFichasWithStock(string $pedidoId, array $line, array $fichas): void
    {
        $alreadySeeded = Ficha::query()->where('pedido_id', $pedidoId)->exists();

        if (! $alreadySeeded) {
            app(EstoqueService::class)->consumeForLines([
                [
                    'ofertaVariante' => $line['variante'],
                    'quantity' => $line['quantity'],
                ],
            ]);
        }

        $this->upsertFichas($pedidoId, $line['variante'], $fichas);
    }

    /**
     * @param  list<array{id: string, qr: string, status: string}>  $fichas
     */
    private function upsertFichas(string $pedidoId, OfertaVariante $variante, array $fichas): void
    {
        $variante->loadMissing(['oferta.barraca', 'oferta.catalogoProduto.variantTemplates', 'oferta.variantes', 'variantTemplate']);

        $produto = $variante->oferta->catalogoProduto;
        $barraca = $variante->oferta->barraca;
        $oferta = $variante->oferta;
        $availableVariantCount = $oferta->variantes->filter(fn (OfertaVariante $entry) => $entry->available)->count();
        $templateCount = $produto->variantTemplates->count();
        $itemName = ItemNameFormatter::format(
            $produto->name,
            $variante->variantTemplate->label,
            $availableVariantCount,
            $templateCount,
        );

        foreach ($fichas as $ficha) {
            Ficha::query()->updateOrCreate(
                ['id' => $ficha['id']],
                [
                    'pedido_id' => $pedidoId,
                    'oferta_variante_id' => $variante->id,
                    'qr_code' => $ficha['qr'],
                    'status' => $ficha['status'],
                    'item_name' => $itemName,
                    'item_image' => $produto->image,
                    'barraca_id' => $barraca->id,
                    'barraca_name' => $barraca->name,
                ],
            );
        }
    }

    private function printSummary(User $maria): void
    {
        if ($this->command === null) {
            return;
        }

        $balance = Carteira::query()->where('user_id', $maria->id)->value('balance');
        $availableFichas = Ficha::query()->availableForUser($maria->id)->count();

        $this->command->newLine();
        $this->command->info('✓ Staging demo data seeded (idempotent)');
        $this->command->newLine();
        $this->command->line('Accounts:');
        $this->command->line('  test_user_5207637493757128652@testuser.com  / 123456  (cliente)');
        $this->command->line('  raul@paroquia.com        / 123456  (organizador)');
        $this->command->line('  atendente@email.com      / 123456  (atendente, stall-1)');
        $this->command->newLine();
        $this->command->line('Scan these QRs (login as atendente):');
        $this->command->line('  QR-DEMO-PASTEL-01  →  pedido-demo-wallet');
        $this->command->line('  QR-DEMO-PASTEL-03  →  pedido-demo-parcial (available)');
        $this->command->line('  QR-DEMO-PASTEL-05  →  pedido-demo-card');
        $this->command->newLine();
        $this->command->line('Maria wallet balance: R$ '.number_format((float) $balance, 2, ',', '.'));
        $this->command->line("Maria available fichas: {$availableFichas}");
        $this->command->newLine();
        $this->command->line('Docs: docs/staging-demo-seeder.md');
        $this->command->newLine();
        $this->command->warn('Nunca rode este seeder em producao com dados reais.');
    }
}
