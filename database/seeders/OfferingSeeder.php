<?php

namespace Database\Seeders;

use App\Models\Oferta;
use App\Models\OfertaVariante;
use App\Models\VariantTemplate;
use Illuminate\Database\Seeder;

class OfferingSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->offerings() as $offering) {
            $this->seedOffering($offering);
        }
    }

    /**
     * @param  array{eventoId: string, barracaId: string, productId: string, available: bool, variants: list<array{templateId: string, price: float, available: bool, badge?: ?string}>}  $data
     */
    private function seedOffering(array $data): void
    {
        $ofertaId = Oferta::buildId($data['eventoId'], $data['barracaId'], $data['productId']);

        $oferta = Oferta::query()->updateOrCreate(
            ['id' => $ofertaId],
            [
                'evento_id' => $data['eventoId'],
                'barraca_id' => $data['barracaId'],
                'catalogo_produto_id' => $data['productId'],
                'available' => $data['available'],
            ]
        );

        foreach ($data['variants'] as $variant) {
            $template = VariantTemplate::query()
                ->where('catalogo_produto_id', $data['productId'])
                ->where('slug', $variant['templateId'])
                ->first();

            if (! $template) {
                continue;
            }

            OfertaVariante::query()->updateOrCreate(
                ['id' => OfertaVariante::buildId($oferta->id, $variant['templateId'])],
                [
                    'oferta_id' => $oferta->id,
                    'variant_template_id' => $template->id,
                    'price' => $variant['price'],
                    'available' => $variant['available'],
                    'badge' => $variant['badge'] ?? null,
                ]
            );
        }
    }

    /**
     * @return list<array{eventoId: string, barracaId: string, productId: string, available: bool, variants: list<array{templateId: string, price: float, available: bool, badge?: ?string}>}>
     */
    private function offerings(): array
    {
        return [
            [
                'eventoId' => '1',
                'barracaId' => 'stall-1',
                'productId' => 'pastel',
                'available' => true,
                'variants' => [
                    ['templateId' => 'carne', 'price' => 8.0, 'available' => true, 'badge' => 'Mais vendido'],
                    ['templateId' => 'queijo', 'price' => 7.0, 'available' => true],
                ],
            ],
            [
                'eventoId' => '1',
                'barracaId' => 'stall-2',
                'productId' => 'milho-verde',
                'available' => true,
                'variants' => [
                    ['templateId' => 'unidade', 'price' => 6.0, 'available' => true],
                ],
            ],
            [
                'eventoId' => '1',
                'barracaId' => 'stall-5',
                'productId' => 'pescaria',
                'available' => true,
                'variants' => [
                    ['templateId' => 'jogada', 'price' => 5.0, 'available' => true],
                ],
            ],
            [
                'eventoId' => '2',
                'barracaId' => 'stall-n1',
                'productId' => 'panetone',
                'available' => true,
                'variants' => [
                    ['templateId' => 'fatia', 'price' => 8.0, 'available' => true],
                ],
            ],
            [
                'eventoId' => 'estabelecimento-paroquia',
                'barracaId' => 'stall-est-1',
                'productId' => 'item-boas-vindas',
                'available' => true,
                'variants' => [
                    ['templateId' => 'unidade', 'price' => 5.0, 'available' => true],
                ],
            ],
        ];
    }
}
