<?php

namespace App\Services;

use App\Models\Barraca;
use App\Models\CatalogoProduto;
use App\Models\Ficha;
use App\Models\OfertaVariante;
use App\Models\Pedido;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FichaGenerationService
{
    /**
     * @param  list<array{ofertaVariante: OfertaVariante, quantity: int}>  $lines
     * @return list<Ficha>
     */
    public function generateForPedido(Pedido $pedido, array $lines): array
    {
        $fichas = [];

        foreach ($lines as $line) {
            $variante = $line['ofertaVariante'];
            $variante->loadMissing(['oferta.barraca', 'oferta.catalogoProduto', 'variantTemplate']);

            $oferta = $variante->oferta;
            $produto = $oferta->catalogoProduto;
            $barraca = $oferta->barraca;
            $templateLabel = $variante->variantTemplate->label;
            $itemName = $produto->name.' ? '.$templateLabel;

            for ($i = 0; $i < $line['quantity']; $i++) {
                $fichas[] = Ficha::query()->create([
                    'id' => 'ficha-'.Str::lower((string) Str::ulid()),
                    'pedido_id' => $pedido->id,
                    'oferta_variante_id' => $variante->id,
                    'qr_code' => $this->uniqueQrCode(),
                    'status' => 'available',
                    'item_name' => $itemName,
                    'item_image' => $produto->image,
                    'barraca_id' => $barraca->id,
                    'barraca_name' => $barraca->name,
                ]);
            }
        }

        return $fichas;
    }

    /**
     * @param  Collection<int, Ficha>  $fichas
     */
    public function assertUniqueQrCodes(Collection $fichas): bool
    {
        return $fichas->pluck('qr_code')->unique()->count() === $fichas->count();
    }

    private function uniqueQrCode(): string
    {
        do {
            $code = 'QR-'.Str::upper(Str::random(12));
        } while (Ficha::query()->where('qr_code', $code)->exists());

        return $code;
    }
}
