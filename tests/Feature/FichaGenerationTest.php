<?php

namespace Tests\Feature;

use App\Models\Barraca;
use App\Models\CatalogoProduto;
use App\Models\Evento;
use App\Models\Oferta;
use App\Models\OfertaVariante;
use App\Models\Pedido;
use App\Models\VariantTemplate;
use App\Services\FichaGenerationService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FichaGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_quantity_three_generates_three_fichas_with_unique_qr_codes(): void
    {
        $this->seed(CatalogSeeder::class);

        $evento = Evento::query()->create([
            'id' => 'event-ficha',
            'name' => 'Festa',
            'description' => 'Desc',
            'date' => '2026-06-24',
            'start_time' => '18:00',
            'end_time' => '23:00',
            'location' => 'Praca',
            'city_id' => 'curitiba-pr',
            'organizer_id' => 'org-test',
            'status' => 'published',
            'capacity' => 100,
            'primary_color' => '#d97706',
        ]);

        $barraca = Barraca::query()->create([
            'id' => 'stall-ficha',
            'evento_id' => $evento->id,
            'name' => 'Barraca do Pastel',
            'category' => 'comidas',
            'responsible' => 'Maria',
            'color' => '#ef4444',
            'status' => 'open',
            'stock' => 50,
        ]);

        CatalogoProduto::query()->findOrFail('pastel');

        $oferta = Oferta::query()->create([
            'id' => Oferta::buildId($evento->id, $barraca->id, 'pastel'),
            'evento_id' => $evento->id,
            'barraca_id' => $barraca->id,
            'catalogo_produto_id' => 'pastel',
            'available' => true,
        ]);

        $template = VariantTemplate::query()
            ->where('catalogo_produto_id', 'pastel')
            ->where('slug', 'carne')
            ->firstOrFail();

        $variante = OfertaVariante::query()->create([
            'id' => OfertaVariante::buildId($oferta->id, 'carne'),
            'oferta_id' => $oferta->id,
            'variant_template_id' => $template->id,
            'price' => 8,
            'available' => true,
        ]);

        $pedido = Pedido::query()->create([
            'id' => 'pedido-ficha-test',
            'evento_id' => $evento->id,
            'number' => '9999',
            'total' => 24,
            'status' => 'available',
            'qr_code' => 'QR-PEDIDO-TEST',
        ]);

        $service = app(FichaGenerationService::class);
        $fichas = $service->generateForPedido($pedido, [
            ['ofertaVariante' => $variante, 'quantity' => 3],
        ]);

        $this->assertCount(3, $fichas);
        $this->assertTrue($service->assertUniqueQrCodes(collect($fichas)));
        $this->assertDatabaseCount('fichas', 3);

        foreach ($fichas as $ficha) {
            $this->assertSame('available', $ficha->status);
            $this->assertSame('Barraca do Pastel', $ficha->barraca_name);
            $this->assertStringContainsString('Pastel', $ficha->item_name);
        }
    }
}
