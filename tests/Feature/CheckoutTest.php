<?php

namespace Tests\Feature;

use App\Models\Carteira;
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
        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
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
        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
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

        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
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

    public function test_checkout_with_wallet_debits_ledger(): void
    {
        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $initialBalance = (float) Carteira::query()->where('user_id', $maria->id)->value('balance');
        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $response = $this->postJson('/api/events/1/pedidos', [
            'items' => [
                [
                    'offeringId' => $offeringId,
                    'variantId' => 'carne',
                    'quantity' => 2,
                ],
            ],
            'paymentMethod' => 'wallet',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('total', 16)
            ->assertJsonPath('paymentStatus', 'paid')
            ->assertJsonCount(2, 'fichas');

        $pedidoId = $response->json('id');

        $this->assertEquals($initialBalance - 16, (float) Carteira::query()->where('user_id', $maria->id)->value('balance'));

        $this->assertDatabaseHas('carteira_movimentos', [
            'user_id' => $maria->id,
            'direction' => 'debito',
            'tipo' => 'compra',
            'amount' => 16,
            'saldo_apos' => $initialBalance - 16,
            'origem_tipo' => 'pedido',
            'origem_id' => $pedidoId,
            'idempotency_key' => "pedido:{$pedidoId}:debito",
        ]);
    }

    public function test_checkout_with_insufficient_wallet_balance_returns_422(): void
    {
        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        Carteira::query()->where('user_id', $maria->id)->update(['balance' => 5]);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                [
                    'offeringId' => $offeringId,
                    'variantId' => 'carne',
                    'quantity' => 2,
                ],
            ],
            'paymentMethod' => 'wallet',
        ])->assertStatus(422);

        $this->assertDatabaseCount('carteira_movimentos', 0);
    }
}
