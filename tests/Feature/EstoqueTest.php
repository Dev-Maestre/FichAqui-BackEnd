<?php

namespace Tests\Feature;

use App\Models\Oferta;
use App\Models\OfertaVariante;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\FichaquiSeeder;
use Database\Seeders\OfferingSeeder;
use Database\Seeders\WalletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EstoqueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->seed(FichaquiSeeder::class);
        $this->seed(OfferingSeeder::class);
        $this->seed(WalletSeeder::class);
    }

    public function test_checkout_rejects_quantity_above_stock(): void
    {
        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');
        $variante = OfertaVariante::query()->findOrFail(
            OfertaVariante::buildId($offeringId, 'carne')
        );
        $variante->update(['stock' => 2]);

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                [
                    'offeringId' => $offeringId,
                    'variantId' => 'carne',
                    'quantity' => 5,
                ],
            ],
            'paymentMethod' => 'credit_card',
            'cardId' => 'card-1',
        ])->assertStatus(422);
    }

    public function test_paid_checkout_decrements_variant_stock(): void
    {
        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');
        $varianteId = OfertaVariante::buildId($offeringId, 'carne');
        OfertaVariante::query()->whereKey($varianteId)->update(['stock' => 10]);

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                [
                    'offeringId' => $offeringId,
                    'variantId' => 'carne',
                    'quantity' => 3,
                ],
            ],
            'paymentMethod' => 'credit_card',
            'cardId' => 'card-1',
        ])->assertCreated();

        $this->assertDatabaseHas('oferta_variantes', [
            'id' => $varianteId,
            'stock' => 7,
        ]);
    }

    public function test_put_rejects_active_variant_without_price(): void
    {
        Sanctum::actingAs($this->organizerUser());

        $this->putJson('/api/events/1/stalls/stall-1/offerings', [
            [
                'productId' => 'pastel',
                'available' => true,
                'variants' => [
                    ['templateId' => 'carne', 'price' => 0, 'available' => true, 'stock' => 10],
                ],
            ],
        ])->assertStatus(422);
    }

    public function test_put_allows_active_variant_with_zero_stock(): void
    {
        Sanctum::actingAs($this->organizerUser());

        $this->putJson('/api/events/1/stalls/stall-1/offerings', [
            [
                'productId' => 'pastel',
                'available' => true,
                'variants' => [
                    ['templateId' => 'carne', 'price' => 9.5, 'available' => true, 'stock' => 0],
                ],
            ],
        ])->assertOk();
    }

    private function organizerUser(): User
    {
        return User::factory()->create([
            'roles' => ['client', 'organizer'],
            'organizer_id' => 'org-paroquia',
        ]);
    }
}
