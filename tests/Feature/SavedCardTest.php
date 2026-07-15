<?php

namespace Tests\Feature;

use App\Models\CartaoSalvo;
use App\Models\Oferta;
use App\Models\User;
use App\Services\SavedCardService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\FichaquiSeeder;
use Database\Seeders\OfferingSeeder;
use Database\Seeders\WalletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SavedCardTest extends TestCase
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

    public function test_store_card_requires_mercado_pago(): void
    {
        config(['mercadopago.access_token' => null]);

        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $this->postJson('/api/user/wallet/cards', [
            'cardToken' => 'tok_test',
            'paymentMethodId' => 'visa',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cardToken']);
    }

    public function test_store_card_creates_customer_and_persists_card(): void
    {
        config([
            'mercadopago.access_token' => 'TEST-token',
            'mercadopago.sandbox' => true,
        ]);

        Http::fake([
            'api.mercadopago.com/v1/customers/search*' => Http::response(['results' => []]),
            'api.mercadopago.com/v1/customers' => Http::response(['id' => 'CUST-001'], 201),
            'api.mercadopago.com/v1/customers/CUST-001/cards' => Http::response([
                'id' => 'MP-CARD-001',
                'last_four_digits' => '1234',
                'payment_method' => ['id' => 'visa'],
                'cardholder' => ['name' => 'APRO'],
            ], 201),
        ]);

        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $this->postJson('/api/user/wallet/cards', [
            'cardToken' => 'tok_save_card',
            'paymentMethodId' => 'visa',
        ])
            ->assertCreated()
            ->assertJsonPath('brand', 'visa')
            ->assertJsonPath('lastFour', '1234')
            ->assertJsonPath('mercadoPagoCardId', 'MP-CARD-001');

        $maria->refresh();
        $this->assertSame('CUST-001', $maria->mercadopago_customer_id);

        $this->assertDatabaseHas('cartoes_salvos', [
            'user_id' => $maria->id,
            'brand' => 'visa',
            'last_four' => '1234',
            'gateway_token' => 'MP-CARD-001',
        ]);
    }

    public function test_store_card_rejects_duplicate_brand_and_last_four(): void
    {
        config([
            'mercadopago.access_token' => 'TEST-token',
            'mercadopago.sandbox' => true,
        ]);

        Http::fake([
            'api.mercadopago.com/v1/customers/search*' => Http::response([
                'results' => [['id' => 'CUST-001']],
            ]),
            'api.mercadopago.com/v1/customers/CUST-001/cards' => Http::response([
                'id' => 'MP-CARD-DUP',
                'last_four_digits' => '4242',
                'payment_method' => ['id' => 'visa'],
                'cardholder' => ['name' => 'APRO'],
            ], 201),
            'api.mercadopago.com/v1/customers/CUST-001/cards/MP-CARD-DUP' => Http::response(null, 200),
        ]);

        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $this->postJson('/api/user/wallet/cards', [
            'cardToken' => 'tok_dup',
            'paymentMethodId' => 'visa',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cardToken']);
    }

    public function test_store_card_rejects_when_limit_reached(): void
    {
        config([
            'mercadopago.access_token' => 'TEST-token',
            'mercadopago.sandbox' => true,
        ]);

        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();

        for ($i = 3; $i <= SavedCardService::MAX_SAVED_CARDS; $i++) {
            CartaoSalvo::query()->create([
                'id' => 'card-limit-'.$i,
                'user_id' => $maria->id,
                'brand' => 'elo',
                'last_four' => (string) (1000 + $i),
                'holder_name' => 'Maria',
                'is_default' => false,
                'gateway_token' => 'mp-'.$i,
            ]);
        }

        Sanctum::actingAs($maria);

        $this->postJson('/api/user/wallet/cards', [
            'cardToken' => 'tok_limit',
            'paymentMethodId' => 'visa',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cardToken']);
    }

    public function test_delete_card_removes_local_and_calls_mercado_pago(): void
    {
        config(['mercadopago.access_token' => 'TEST-token']);

        Http::fake([
            'api.mercadopago.com/v1/customers/CUST-001/cards/MP-CARD-001' => Http::response(null, 200),
        ]);

        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
        $maria->update(['mercadopago_customer_id' => 'CUST-001']);

        $card = CartaoSalvo::query()->create([
            'id' => 'card-delete-me',
            'user_id' => $maria->id,
            'brand' => 'visa',
            'last_four' => '9999',
            'holder_name' => 'Maria',
            'is_default' => false,
            'gateway_token' => 'MP-CARD-001',
        ]);

        Sanctum::actingAs($maria);

        $this->deleteJson('/api/user/wallet/cards/'.$card->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('cartoes_salvos', ['id' => 'card-delete-me']);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/v1/customers/CUST-001/cards/MP-CARD-001'));
    }

    public function test_checkout_with_saved_card_and_token_charges_via_orders_api(): void
    {
        config([
            'mercadopago.access_token' => 'TEST-token',
            'mercadopago.sandbox' => true,
        ]);

        Http::fake([
            'api.mercadopago.com/v1/orders*' => Http::response([
                'id' => 'ORD-SAVED-1',
                'status' => 'processed',
                'transactions' => [
                    'payments' => [
                        [
                            'id' => 'PAY-SAVED-1',
                            'status' => 'processed',
                            'payment_method' => [
                                'id' => 'visa',
                                'type' => 'credit_card',
                            ],
                        ],
                    ],
                ],
            ], 201),
        ]);

        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
        $maria->update(['mercadopago_customer_id' => 'CUST-001']);

        CartaoSalvo::query()->where('id', 'card-1')->update([
            'gateway_token' => 'MP-CARD-SAVED',
        ]);

        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 1],
            ],
            'paymentMethod' => 'credit_card',
            'cardId' => 'card-1',
            'cardToken' => 'tok_saved_checkout',
            'paymentMethodId' => 'visa',
        ])
            ->assertCreated()
            ->assertJsonPath('paymentStatus', 'paid');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.mercadopago.com/v1/orders'
            && ($request['transactions']['payments'][0]['payment_method']['token'] ?? null) === 'tok_saved_checkout'
            && ($request['payer']['id'] ?? null) === 'CUST-001');
    }

    public function test_checkout_save_card_imports_from_gateway_after_payment(): void
    {
        config([
            'mercadopago.access_token' => 'TEST-token',
            'mercadopago.sandbox' => true,
        ]);

        Http::fake([
            'api.mercadopago.com/v1/customers/search*' => Http::response(['results' => []]),
            'api.mercadopago.com/v1/customers' => Http::response(['id' => 'CUST-NEW'], 201),
            'api.mercadopago.com/v1/orders*' => Http::response([
                'id' => 'ORD-SAVE-1',
                'status' => 'processed',
                'transactions' => [
                    'payments' => [
                        [
                            'id' => 'PAY-SAVE-1',
                            'status' => 'processed',
                            'payment_method' => ['id' => 'visa', 'type' => 'credit_card'],
                        ],
                    ],
                ],
            ], 201),
            'api.mercadopago.com/v1/customers/CUST-NEW/cards' => Http::response([
                [
                    'id' => 'MP-CARD-NEW',
                    'last_four_digits' => '7777',
                    'payment_method' => ['id' => 'visa'],
                    'cardholder' => ['name' => 'APRO'],
                ],
            ]),
        ]);

        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
        CartaoSalvo::query()->where('user_id', $maria->id)->delete();

        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 1],
            ],
            'paymentMethod' => 'credit_card',
            'cardToken' => 'tok_new_save',
            'paymentMethodId' => 'visa',
            'cardholderName' => 'APRO',
            'cardholderCpf' => '12345678909',
            'saveCard' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('paymentStatus', 'paid');

        $this->assertDatabaseHas('cartoes_salvos', [
            'user_id' => $maria->id,
            'last_four' => '7777',
            'gateway_token' => 'MP-CARD-NEW',
            'is_default' => true,
        ]);
    }
}
