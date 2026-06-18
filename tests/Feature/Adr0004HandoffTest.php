<?php

namespace Tests\Feature;

use App\Models\Oferta;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CitySeeder;
use Database\Seeders\FichaquiSeeder;
use Database\Seeders\OfferingSeeder;
use Database\Seeders\WalletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Adr0004HandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
        $this->seed(CatalogSeeder::class);
        $this->seed(FichaquiSeeder::class);
        $this->seed(OfferingSeeder::class);
        $this->seed(WalletSeeder::class);
    }

    public function test_unauthenticated_wallet_returns_401(): void
    {
        $this->getJson('/api/user/wallet')->assertUnauthorized();
    }

    public function test_cities_endpoint_returns_catalog(): void
    {
        $this->getJson('/api/cities')
            ->assertOk()
            ->assertJsonFragment(['id' => 'curitiba-pr', 'name' => 'Curitiba', 'state' => 'PR']);
    }

    public function test_pix_without_mercado_pago_returns_422(): void
    {
        config(['mercadopago.access_token' => null]);

        $maria = User::query()->where('email', 'maria@email.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 1],
            ],
            'paymentMethod' => 'pix',
        ])->assertStatus(422);
    }

    public function test_pix_pending_checkout_has_no_fichas(): void
    {
        config([
            'mercadopago.access_token' => 'TEST-token',
            'mercadopago.api_base_url' => 'https://api.mercadopago.com',
            'mercadopago.pix_driver' => 'orders',
        ]);

        Http::fake([
            'api.mercadopago.com/v1/orders*' => Http::response([
                'id' => 'ORD99887766',
                'status' => 'created',
                'type' => 'qr',
                'transactions' => [
                    'payments' => [
                        [
                            'id' => 'PAY99887766',
                            'status' => 'created',
                            'status_detail' => 'ready_to_process',
                            'amount' => '32.00',
                        ],
                    ],
                ],
                'type_response' => [
                    'qr_data' => '00020101021243650016com.mercadolibre',
                ],
            ], 201),
        ]);

        $maria = User::query()->where('email', 'maria@email.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 2],
            ],
            'paymentMethod' => 'pix',
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'pending_payment')
            ->assertJsonPath('paymentStatus', 'pending')
            ->assertJsonPath('paymentId', 'PAY99887766')
            ->assertJsonPath('gatewayOrderId', 'ORD99887766')
            ->assertJsonPath('pixCopyPaste', '00020101021243650016com.mercadolibre')
            ->assertJsonCount(0, 'fichas');

        $this->assertDatabaseCount('fichas', 0);
    }

    public function test_payment_poll_fulfills_fichas_when_approved(): void
    {
        config([
            'mercadopago.access_token' => 'TEST-token',
            'mercadopago.api_base_url' => 'https://api.mercadopago.com',
            'mercadopago.pix_driver' => 'orders',
        ]);

        Http::fake([
            'api.mercadopago.com/v1/orders*' => Http::sequence()
                ->push([
                    'id' => 'ORD55443322',
                    'status' => 'created',
                    'type' => 'qr',
                    'transactions' => [
                        'payments' => [
                            [
                                'id' => 'PAY55443322',
                                'status' => 'created',
                                'status_detail' => 'ready_to_process',
                                'amount' => '32.00',
                            ],
                        ],
                    ],
                    'type_response' => [
                        'qr_data' => '000201PIX',
                    ],
                ], 201)
                ->push([
                    'id' => 'ORD55443322',
                    'status' => 'processed',
                    'type' => 'qr',
                    'transactions' => [
                        'payments' => [
                            [
                                'id' => 'PAY55443322',
                                'status' => 'approved',
                                'status_detail' => 'accredited',
                                'amount' => '32.00',
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $maria = User::query()->where('email', 'maria@email.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 2],
            ],
            'paymentMethod' => 'pix',
        ])->assertCreated();

        $this->getJson('/api/payments/PAY55443322/status')
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('orderStatus', 'available')
            ->assertJsonCount(2, 'fichas');
    }

    public function test_auth_me_includes_profile_and_stall_scope(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'atendente@email.com')->firstOrFail());

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('stallId', 'stall-1')
            ->assertJsonPath('eventId', '1');
    }

    public function test_checkout_without_cpf_for_gateway_returns_422(): void
    {
        $user = User::factory()->create([
            'roles' => ['client'],
            'cpf' => null,
        ]);
        Sanctum::actingAs($user);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 1],
            ],
            'paymentMethod' => 'pix',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cpf']);
    }
}
