<?php

namespace App\Services;

use App\Models\Barraca;
use App\Models\CatalogoProduto;
use App\Models\Evento;
use App\Models\Oferta;
use App\Models\OfertaVariante;
use App\Models\VariantTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfferingWriteService
{
    /**
     * @param  list<array<string, mixed>>  $offeringsInput
     * @return list<Oferta>
     */
    public function replaceForStall(Evento $evento, Barraca $barraca, array $offeringsInput): array
    {
        if ($barraca->evento_id !== $evento->id) {
            throw ValidationException::withMessages([
                'stallId' => ['Barraca nao pertence a este evento.'],
            ]);
        }

        $validated = collect($offeringsInput)
            ->map(fn (array $offering) => $this->validateOfferingPayload($offering))
            ->all();

        return DB::transaction(function () use ($evento, $barraca, $validated) {
            Oferta::query()
                ->where('barraca_id', $barraca->id)
                ->delete();

            $created = [];

            foreach ($validated as $offeringData) {
                $created[] = $this->createOffering($evento, $barraca, $offeringData);
            }

            return $created;
        });
    }

    /**
     * @param  array<string, mixed>  $offering
     * @return array{productId: string, available: bool, variants: list<array{templateId: string, price: float, available: bool, stock: int, badge: ?string}>}
     */
    private function validateOfferingPayload(array $offering): array
    {
        $validated = validator($offering, [
            'productId' => ['required', 'string'],
            'available' => ['sometimes', 'boolean'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.templateId' => ['required', 'string'],
            'variants.*.price' => ['required', 'numeric', 'min:0'],
            'variants.*.stock' => ['required', 'integer', 'min:0'],
            'variants.*.available' => ['sometimes', 'boolean'],
            'variants.*.badge' => ['sometimes', 'nullable', 'string', 'max:64'],
        ])->validate();

        if (! CatalogoProduto::query()->whereKey($validated['productId'])->exists()) {
            throw ValidationException::withMessages([
                'productId' => ["Produto de catalogo '{$validated['productId']}' nao existe."],
            ]);
        }

        foreach ($validated['variants'] as $index => $variant) {
            $template = VariantTemplate::query()
                ->where('catalogo_produto_id', $validated['productId'])
                ->where('slug', $variant['templateId'])
                ->first();

            if (! $template) {
                throw ValidationException::withMessages([
                    "variants.{$index}.templateId" => [
                        "Template '{$variant['templateId']}' nao existe para o produto '{$validated['productId']}'.",
                    ],
                ]);
            }

            $available = $variant['available'] ?? true;
            $price = (float) $variant['price'];

            if ($available && $price <= 0) {
                throw ValidationException::withMessages([
                    "variants.{$index}.available" => [
                        'Informe preco maior que zero antes de ativar a variante.',
                    ],
                ]);
            }
        }

        return [
            'productId' => $validated['productId'],
            'available' => $validated['available'] ?? true,
            'variants' => array_map(
                fn (array $variant) => [
                    'templateId' => $variant['templateId'],
                    'price' => (float) $variant['price'],
                    'stock' => (int) $variant['stock'],
                    'available' => $variant['available'] ?? true,
                    'badge' => $variant['badge'] ?? null,
                ],
                $validated['variants'],
            ),
        ];
    }

    /**
     * @param  array{productId: string, available: bool, variants: list<array{templateId: string, price: float, available: bool, stock: int, badge: ?string}>}  $offeringData
     */
    private function createOffering(Evento $evento, Barraca $barraca, array $offeringData): Oferta
    {
        $ofertaId = Oferta::buildId($evento->id, $barraca->id, $offeringData['productId']);

        $oferta = Oferta::query()->create([
            'id' => $ofertaId,
            'evento_id' => $evento->id,
            'barraca_id' => $barraca->id,
            'catalogo_produto_id' => $offeringData['productId'],
            'available' => $offeringData['available'],
        ]);

        foreach ($offeringData['variants'] as $variantData) {
            $template = VariantTemplate::query()
                ->where('catalogo_produto_id', $offeringData['productId'])
                ->where('slug', $variantData['templateId'])
                ->firstOrFail();

            OfertaVariante::query()->create([
                'id' => OfertaVariante::buildId($ofertaId, $variantData['templateId']),
                'oferta_id' => $ofertaId,
                'variant_template_id' => $template->id,
                'price' => $variantData['price'],
                'available' => $variantData['available'],
                'stock' => $variantData['stock'],
                'badge' => $variantData['badge'],
            ]);
        }

        return $oferta->load('variantes.variantTemplate');
    }

    /**
     * @return Collection<int, Oferta>
     */
    public function listForEvent(string $eventoId): Collection
    {
        return Oferta::query()
            ->with(['variantes.variantTemplate'])
            ->where('evento_id', $eventoId)
            ->orderBy('catalogo_produto_id')
            ->get();
    }
}
