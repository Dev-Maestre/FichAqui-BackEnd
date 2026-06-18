<?php

namespace Tests\Feature;

use App\Models\CartaoSalvo;
use App\Models\Oferta;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\FichaquiSeeder;
use Database\Seeders\OfferingSeeder;
use Database\Seeders\WalletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutTest extends TestCase
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

    public function test_checkout_with_offerings_generates_fichas(): void
    {
        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $response = $this->postJson('/api/events/1/pedidos', [
            'items' => [
                [
                    'offeringId' => $offeringId,
                    'variantId' => 'carne',
                    'quantity' => 2,
                ],
            ],
            'paymentMethod' => 'credit_card',
            'cardId' => 'card-1',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('total', 16)
            ->assertJsonPath('paymentStatus', 'paid')
            ->assertJsonCount(2, 'fichas')
            ->assertJsonStructure([
                'items' => [['name', 'quantity', 'stallName']],
                'fichas' => [['id', 'qrCode', 'status']],
            ]);

        $this->assertDatabaseCount('fichas', 2);
    }

    public function test_checkout_rejects_unavailable_variant(): void
    {
        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                [
                    'offeringId' => $offeringId,
                    'variantId' => 'inexistente',
                    'quantity' => 1,
                ],
            ],
            'paymentMethod' => 'credit_card',
            'cardId' => 'card-1',
        ])->assertStatus(422);
    }

    public function test_checkout_rejects_card_from_another_user(): void
    {
        $other = User::factory()->create();
        CartaoSalvo::query()->create([
            'id' => 'card-other',
            'user_id' => $other->id,
            'brand' => 'visa',
            'last_four' => '1111',
            'holder_name' => 'Outro',
            'is_default' => true,
        ]);

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                [
                    'offeringId' => $offeringId,
                    'variantId' => 'carne',
                    'quantity' => 1,
                ],
            ],
            'paymentMethod' => 'credit_card',
            'cardId' => 'card-other',
        ])->assertStatus(422);
    }
}
