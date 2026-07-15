<?php

namespace App\Services;

use App\Models\OfertaVariante;
use Illuminate\Validation\ValidationException;

class EstoqueService
{
    public function assertSufficient(OfertaVariante $variante, int $quantity, int $itemIndex): void
    {
        if ($quantity > $variante->stock) {
            throw ValidationException::withMessages([
                "items.{$itemIndex}.quantity" => [
                    $variante->stock === 0
                        ? 'Variante esgotada.'
                        : "Estoque insuficiente. Restam {$variante->stock} unidade(s).",
                ],
            ]);
        }
    }

    /**
     * @param  list<array{variante: OfertaVariante, quantity: int}>  $lines
     */
    public function consumeForLines(array $lines): void
    {
        foreach ($lines as $line) {
            $variante = OfertaVariante::query()
                ->lockForUpdate()
                ->findOrFail($line['variante']->id);

            if ($line['quantity'] > $variante->stock) {
                throw ValidationException::withMessages([
                    'items' => ['Estoque insuficiente para concluir o pedido.'],
                ]);
            }

            $variante->decrement('stock', $line['quantity']);
        }
    }
}
