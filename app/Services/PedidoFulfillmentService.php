<?php

namespace App\Services;

use App\Models\OfertaVariante;
use App\Models\Pedido;
use Illuminate\Support\Facades\DB;

class PedidoFulfillmentService
{
    public function __construct(
        private readonly FichaGenerationService $fichaGenerationService,
        private readonly EstoqueService $estoqueService,
    ) {}

    /**
     * Gera fichas quando o pagamento está confirmado. Idempotente.
     */
    public function fulfillIfPaid(Pedido $pedido): Pedido
    {
        return DB::transaction(function () use ($pedido) {
            $pedido = Pedido::query()
                ->lockForUpdate()
                ->with([
                    'itens.ofertaVariante.oferta.barraca',
                    'itens.ofertaVariante.oferta.catalogoProduto.variantTemplates',
                    'itens.ofertaVariante.oferta.variantes',
                    'itens.ofertaVariante.variantTemplate',
                    'fichas',
                ])
                ->findOrFail($pedido->id);

            if (! $pedido->isPaymentConfirmed()) {
                return $pedido;
            }

            if ($pedido->fichas->isNotEmpty()) {
                if ($pedido->status !== 'available' && $pedido->status !== 'delivered') {
                    $pedido->update(['status' => 'available']);
                }

                return $pedido->fresh(['itens', 'fichas']);
            }

            $lines = $this->resolveFulfillmentLines($pedido);

            if ($lines === []) {
                return $pedido;
            }

            $this->estoqueService->consumeForLines($lines);
            $this->fichaGenerationService->generateForPedido($pedido, $lines);
            $pedido->update(['status' => 'available']);

            return $pedido->fresh(['itens', 'fichas']);
        });
    }

    /**
     * @return list<array{ofertaVariante: OfertaVariante, quantity: int}>
     */
    private function resolveFulfillmentLines(Pedido $pedido): array
    {
        $lines = [];

        foreach ($pedido->itens as $item) {
            $variante = $item->ofertaVariante;

            if (! $variante) {
                continue;
            }

            $lines[] = [
                'ofertaVariante' => $variante,
                'quantity' => $item->quantity,
            ];
        }

        return $lines;
    }

    public function markPaymentFailed(Pedido $pedido): Pedido
    {
        $pedido->update([
            'payment_status' => 'failed',
            'status' => 'payment_failed',
        ]);

        return $pedido->fresh(['itens', 'fichas']);
    }
}
