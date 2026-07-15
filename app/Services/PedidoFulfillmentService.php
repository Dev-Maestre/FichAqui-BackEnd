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
                ->with(['itens', 'fichas'])
                ->findOrFail($pedido->id);

            if ($pedido->fichas->isNotEmpty()) {
                return $pedido;
            }

            if (! $pedido->isPaymentConfirmed()) {
                return $pedido;
            }

            $lines = [];

            foreach ($pedido->itens as $item) {
                $variante = OfertaVariante::query()
                    ->with(['oferta.barraca', 'oferta.catalogoProduto', 'variantTemplate'])
                    ->find($item->oferta_variante_id);

                if (! $variante) {
                    continue;
                }

                $lines[] = [
                    'ofertaVariante' => $variante,
                    'quantity' => $item->quantity,
                ];
            }

            if ($lines !== []) {
                $this->estoqueService->consumeForLines($lines);
                $this->fichaGenerationService->generateForPedido($pedido, $lines);
            }

            $pedido->update(['status' => 'available']);

            return $pedido->fresh(['itens', 'fichas']);
        });
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
