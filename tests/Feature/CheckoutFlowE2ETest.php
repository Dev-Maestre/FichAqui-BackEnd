<?php

namespace Tests\Feature;

use App\Models\Oferta;
use App\Models\Pedido;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutFlowE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_offerings_checkout_and_consume_flow(): void
    {
        $this->seed(DatabaseSeeder::class);

        $bootstrap = $this->getJson('/api/bootstrap')->assertOk();

        $payload = $bootstrap->json();
        $this->assertArrayHasKey('categories', $payload);
        $this->assertArrayHasKey('catalogProducts', $payload);
        $this->assertArrayNotHasKey('events', $payload);
        $this->assertArrayNotHasKey('menuProducts', $payload);
        $this->assertArrayNotHasKey('orders', $payload);

        $offerings = $this->getJson('/api/events/1/offerings')
            ->assertOk()
            ->assertJsonFragment(['productId' => 'pastel']);

        $offeringId = collect($offerings->json())->firstWhere('productId', 'pastel')['id'];

        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $checkout = $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 2],
            ],
            'paymentMethod' => 'credit_card',
            'cardId' => 'card-1',
        ])->assertCreated();

        $pedidoId = $checkout->json('id');
        $fichas = $checkout->json('fichas');

        $this->assertCount(2, $fichas);

        Sanctum::actingAs(User::query()->where('email', 'atendente@email.com')->firstOrFail());

        foreach ($fichas as $ficha) {
            $this->postJson("/api/fichas/{$ficha['id']}/consume")->assertOk();
        }

        $this->assertSame('delivered', Pedido::query()->findOrFail($pedidoId)->status);

        Sanctum::actingAs(User::query()->where('email', 'raul@paroquia.com')->firstOrFail());

        $this->getJson('/api/events/1/pedidos')
            ->assertOk()
            ->assertJsonPath('0.status', 'delivered')
            ->assertJsonPath('0.fichaCounts.delivered', 2);
    }
}
