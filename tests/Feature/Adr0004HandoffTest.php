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

    /**
     * @return array<string, mixed>
     */
    private function mercadoPagoTestConfig(array $overrides = []): array
    {
        return array_merge([
            'mercadopago.access_token' => 'TEST-token',
            'mercadopago.api_base_url' => 'https://api.mercadopago.com',
            'mercadopago.sandbox' => false,
        ], $overrides);
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

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
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
        config($this->mercadoPagoTestConfig([
            'mercadopago.pix_driver' => 'orders',
            'mercadopago.qr_external_pos_id' => 'STALL_POS_1',
        ]));

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

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
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

    public function test_pix_pending_checkout_uses_online_driver_by_default(): void
    {
        config($this->mercadoPagoTestConfig());

        Http::fake([
            'api.mercadopago.com/v1/orders*' => Http::response([
                'id' => 'ORD11223344',
                'status' => 'action_required',
                'type' => 'online',
                'transactions' => [
                    'payments' => [
                        [
                            'id' => 'PAY11223344',
                            'status' => 'action_required',
                            'payment_method' => [
                                'id' => 'pix',
                                'qr_code' => '000201PIXONLINE',
                                'qr_code_base64' => 'base64online',
                            ],
                        ],
                    ],
                ],
            ], 201),
        ]);

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 1],
            ],
            'paymentMethod' => 'pix',
        ])
            ->assertCreated()
            ->assertJsonPath('paymentStatus', 'pending')
            ->assertJsonPath('paymentId', 'PAY11223344')
            ->assertJsonPath('gatewayOrderId', 'ORD11223344')
            ->assertJsonPath('pixCopyPaste', '000201PIXONLINE')
            ->assertJsonPath('pixQrCode', 'base64online');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.mercadopago.com/v1/orders'
            && ($request['type'] ?? null) === 'online'
            && ($request['transactions']['payments'][0]['payment_method']['id'] ?? null) === 'pix');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/payments'));
    }

    public function test_pix_processing_status_polls_until_qr_is_available(): void
    {
        config($this->mercadoPagoTestConfig());

        Http::fake([
            'api.mercadopago.com/v1/orders*' => Http::sequence()
                ->push([
                    'id' => 'ORD55667788',
                    'status' => 'processing',
                    'type' => 'online',
                    'transactions' => [
                        'payments' => [
                            [
                                'id' => 'PAY55667788',
                                'status' => 'processing',
                                'status_detail' => 'in_process',
                            ],
                        ],
                    ],
                ], 201)
                ->push([
                    'id' => 'ORD55667788',
                    'status' => 'action_required',
                    'type' => 'online',
                    'transactions' => [
                        'payments' => [
                            [
                                'id' => 'PAY55667788',
                                'status' => 'action_required',
                                'payment_method' => [
                                    'id' => 'pix',
                                    'qr_code' => '000201PIXRETRY',
                                    'qr_code_base64' => 'base64retry',
                                ],
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 1],
            ],
            'paymentMethod' => 'pix',
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'pending_payment')
            ->assertJsonPath('paymentStatus', 'pending')
            ->assertJsonPath('pixCopyPaste', '000201PIXRETRY')
            ->assertJsonPath('pixQrCode', 'base64retry');

        Http::assertSentCount(2);
    }

    public function test_pix_orders_without_pos_falls_back_to_online(): void
    {
        config($this->mercadoPagoTestConfig([
            'mercadopago.pix_driver' => 'orders',
            'mercadopago.qr_external_pos_id' => null,
        ]));

        Http::fake([
            'api.mercadopago.com/v1/orders*' => Http::response([
                'id' => 'ORD55667788',
                'status' => 'action_required',
                'type' => 'online',
                'transactions' => [
                    'payments' => [
                        [
                            'id' => 'PAY55667788',
                            'status' => 'action_required',
                            'payment_method' => [
                                'id' => 'pix',
                                'qr_code' => '000201FALLBACK',
                            ],
                        ],
                    ],
                ],
            ], 201),
        ]);

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 1],
            ],
            'paymentMethod' => 'pix',
        ])
            ->assertCreated()
            ->assertJsonPath('pixCopyPaste', '000201FALLBACK');

        Http::assertSent(fn ($request) => ($request['type'] ?? null) === 'online');
    }

    public function test_pix_payments_driver_still_supported(): void
    {
        config($this->mercadoPagoTestConfig([
            'mercadopago.pix_driver' => 'payments',
        ]));

        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response([
                'id' => 99887766,
                'status' => 'pending',
                'point_of_interaction' => [
                    'transaction_data' => [
                        'qr_code' => '000201LEGACY',
                        'qr_code_base64' => 'legacyb64',
                    ],
                ],
            ], 201),
        ]);

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 1],
            ],
            'paymentMethod' => 'pix',
        ])
            ->assertCreated()
            ->assertJsonPath('pixCopyPaste', '000201LEGACY');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.mercadopago.com/v1/payments');
    }

    public function test_pix_sandbox_rejects_non_testuser_email(): void
    {
        config($this->mercadoPagoTestConfig([
            'mercadopago.sandbox' => true,
        ]));

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        $maria->update(['email' => 'maria@email.com']);
        Sanctum::actingAs($maria->fresh());

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 1],
            ],
            'paymentMethod' => 'pix',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['paymentMethod'])
            ->assertJsonFragment(['paymentMethod' => [
                'No sandbox do Mercado Pago, use um e-mail de comprador de teste (@testuser.com). '
                .'Crie em Credenciais de teste > Contas de teste no painel MP.',
            ]]);

        Http::assertNothingSent();
    }

    public function test_payment_poll_fulfills_fichas_when_online_order_approved(): void
    {
        config($this->mercadoPagoTestConfig());

        Http::fake([
            'api.mercadopago.com/v1/orders*' => Http::sequence()
                ->push([
                    'id' => 'ORD55443322',
                    'status' => 'action_required',
                    'type' => 'online',
                    'transactions' => [
                        'payments' => [
                            [
                                'id' => 'PAY55443322',
                                'status' => 'action_required',
                                'payment_method' => [
                                    'id' => 'pix',
                                    'qr_code' => '000201PIX',
                                ],
                            ],
                        ],
                    ],
                ], 201)
                ->push([
                    'id' => 'ORD55443322',
                    'status' => 'processed',
                    'type' => 'online',
                    'transactions' => [
                        'payments' => [
                            [
                                'id' => 'PAY55443322',
                                'status' => 'approved',
                                'status_detail' => 'accredited',
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
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

    public function test_card_token_approved_checkout_generates_fichas(): void
    {
        config($this->mercadoPagoTestConfig());

        Http::fake([
            'api.mercadopago.com/v1/orders*' => Http::response([
                'id' => 'ORDCARD99',
                'status' => 'processed',
                'status_detail' => 'accredited',
                'type' => 'online',
                'transactions' => [
                    'payments' => [
                        [
                            'id' => 'PAYCARD99',
                            'status' => 'processed',
                            'status_detail' => 'accredited',
                            'payment_method' => [
                                'id' => 'visa',
                                'type' => 'credit_card',
                                'installments' => 1,
                            ],
                        ],
                    ],
                ],
            ], 201),
        ]);

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 2],
            ],
            'paymentMethod' => 'credit_card',
            'cardToken' => 'tok_test_card',
            'paymentMethodId' => 'visa',
            'cardholderName' => 'APRO',
            'cardholderCpf' => '12345678909',
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'available')
            ->assertJsonPath('paymentStatus', 'paid')
            ->assertJsonPath('paymentId', 'PAYCARD99')
            ->assertJsonPath('gatewayOrderId', 'ORDCARD99')
            ->assertJsonCount(2, 'fichas');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.mercadopago.com/v1/orders'
            && ($request['transactions']['payments'][0]['payment_method']['type'] ?? null) === 'credit_card'
            && ($request['transactions']['payments'][0]['payment_method']['token'] ?? null) === 'tok_test_card'
            && ($request['payer']['first_name'] ?? null) === 'APRO'
            && ($request['payer']['identification']['number'] ?? null) === '12345678909'
            && ! isset($request['shipment']));
    }

    public function test_card_token_pending_checkout_has_no_fichas(): void
    {
        config($this->mercadoPagoTestConfig());

        Http::fake([
            'api.mercadopago.com/v1/orders*' => Http::response([
                'id' => 'ORDCARD88',
                'status' => 'processing',
                'type' => 'online',
                'transactions' => [
                    'payments' => [
                        [
                            'id' => 'PAYCARD88',
                            'status' => 'in_process',
                            'payment_method' => [
                                'id' => 'master',
                                'type' => 'credit_card',
                            ],
                        ],
                    ],
                ],
            ], 201),
        ]);

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 1],
            ],
            'paymentMethod' => 'credit_card',
            'cardToken' => 'tok_test_pending',
            'paymentMethodId' => 'master',
            'cardholderName' => 'APRO',
            'cardholderCpf' => '12345678909',
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'pending_payment')
            ->assertJsonPath('paymentStatus', 'pending')
            ->assertJsonPath('paymentId', 'PAYCARD88')
            ->assertJsonPath('gatewayOrderId', 'ORDCARD88')
            ->assertJsonCount(0, 'fichas');

        $this->assertDatabaseCount('fichas', 0);
    }

    public function test_card_id_with_mercado_pago_configured_returns_422(): void
    {
        config($this->mercadoPagoTestConfig());

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 1],
            ],
            'paymentMethod' => 'credit_card',
            'cardId' => 'card-1',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cardToken']);

        Http::assertNothingSent();
    }

    public function test_card_token_rejected_returns_422_without_pedido(): void
    {
        config($this->mercadoPagoTestConfig());

        Http::fake([
            'api.mercadopago.com/v1/orders*' => Http::response([
                'id' => 'ORDCARD77',
                'status' => 'failed',
                'type' => 'online',
                'transactions' => [
                    'payments' => [
                        [
                            'id' => 'PAYCARD77',
                            'status' => 'rejected',
                            'status_detail' => 'cc_rejected_insufficient_amount',
                        ],
                    ],
                ],
            ], 201),
        ]);

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 1],
            ],
            'paymentMethod' => 'credit_card',
            'cardToken' => 'tok_test_rejected',
            'paymentMethodId' => 'visa',
            'cardholderName' => 'APRO',
            'cardholderCpf' => '12345678909',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['paymentMethod']);

        $this->assertDatabaseCount('pedidos', 0);
    }
}
