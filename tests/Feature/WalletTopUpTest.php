<?php

namespace Tests\Feature;

use App\Models\CarteiraRecarga;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\FichaquiSeeder;
use Database\Seeders\WalletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletTopUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->seed(FichaquiSeeder::class);
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

    public function test_top_up_requires_authentication(): void
    {
        $this->postJson('/api/user/wallet/top-up', [
            'amount' => 50,
            'paymentMethod' => 'pix',
        ])->assertUnauthorized();
    }

    public function test_pix_top_up_without_mercado_pago_returns_422(): void
    {
        config(['mercadopago.access_token' => null]);

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $this->postJson('/api/user/wallet/top-up', [
            'amount' => 50,
            'paymentMethod' => 'pix',
        ])->assertStatus(422);
    }

    public function test_credit_card_top_up_is_not_supported_yet(): void
    {
        config($this->mercadoPagoTestConfig());

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $this->postJson('/api/user/wallet/top-up', [
            'amount' => 50,
            'paymentMethod' => 'credit_card',
        ])->assertStatus(422);
    }

    public function test_pix_pending_top_up_returns_qr_and_keeps_balance(): void
    {
        config($this->mercadoPagoTestConfig());

        Http::fake([
            'api.mercadopago.com/v1/orders*' => Http::response([
                'id' => 'ORD-WALLET-001',
                'status' => 'action_required',
                'type' => 'online',
                'transactions' => [
                    'payments' => [
                        [
                            'id' => 'PAY-WALLET-001',
                            'status' => 'action_required',
                            'payment_method' => [
                                'id' => 'pix',
                                'qr_code' => '000201PIXWALLET',
                                'qr_code_base64' => 'base64wallet',
                            ],
                        ],
                    ],
                ],
            ], 201),
        ]);

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $initialBalance = (float) Carteira::query()->where('user_id', $maria->id)->value('balance');

        $this->postJson('/api/user/wallet/top-up', [
            'amount' => 25,
            'paymentMethod' => 'pix',
        ])
            ->assertCreated()
            ->assertJsonPath('balance', $initialBalance)
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.method', 'pix')
            ->assertJsonPath('payment.paymentId', 'PAY-WALLET-001')
            ->assertJsonPath('payment.pix.copyPaste', '000201PIXWALLET');

        $this->assertDatabaseHas('carteira_recargas', [
            'user_id' => $maria->id,
            'amount' => 25,
            'payment_status' => 'pending',
            'gateway_payment_id' => 'PAY-WALLET-001',
        ]);

        $this->assertEquals(
            $initialBalance,
            (float) Carteira::query()->where('user_id', $maria->id)->value('balance')
        );
    }

    public function test_pix_top_up_poll_credits_wallet_when_approved(): void
    {
        config($this->mercadoPagoTestConfig());

        Http::fake([
            'api.mercadopago.com/v1/orders*' => Http::sequence()
                ->push([
                    'id' => 'ORD-WALLET-002',
                    'status' => 'action_required',
                    'type' => 'online',
                    'transactions' => [
                        'payments' => [
                            [
                                'id' => 'PAY-WALLET-002',
                                'status' => 'action_required',
                                'payment_method' => [
                                    'id' => 'pix',
                                    'qr_code' => '000201PIXWALLET2',
                                ],
                            ],
                        ],
                    ],
                ], 201)
                ->push([
                    'id' => 'ORD-WALLET-002',
                    'status' => 'paid',
                    'transactions' => [
                        'payments' => [
                            [
                                'id' => 'PAY-WALLET-002',
                                'status' => 'approved',
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $initialBalance = (float) Carteira::query()->where('user_id', $maria->id)->value('balance');

        $this->postJson('/api/user/wallet/top-up', [
            'amount' => 10,
            'paymentMethod' => 'pix',
        ])->assertCreated();

        $this->getJson('/api/payments/PAY-WALLET-002/status')
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->assertEquals(
            $initialBalance + 10,
            (float) Carteira::query()->where('user_id', $maria->id)->value('balance')
        );

        $this->assertDatabaseHas('carteira_recargas', [
            'gateway_payment_id' => 'PAY-WALLET-002',
            'payment_status' => 'paid',
        ]);

        $recarga = CarteiraRecarga::query()
            ->where('gateway_payment_id', 'PAY-WALLET-002')
            ->firstOrFail();

        $this->assertDatabaseHas('carteira_movimentos', [
            'user_id' => $maria->id,
            'direction' => 'credito',
            'tipo' => 'recarga',
            'amount' => 10,
            'saldo_apos' => $initialBalance + 10,
            'origem_tipo' => 'recarga',
            'origem_id' => $recarga->id,
            'idempotency_key' => "recarga:{$recarga->id}:credito",
        ]);
    }

    public function test_pix_pending_top_up_does_not_create_movimento(): void
    {
        config($this->mercadoPagoTestConfig());

        Http::fake([
            'api.mercadopago.com/v1/orders*' => Http::response([
                'id' => 'ORD-WALLET-003',
                'status' => 'action_required',
                'type' => 'online',
                'transactions' => [
                    'payments' => [
                        [
                            'id' => 'PAY-WALLET-003',
                            'status' => 'action_required',
                            'payment_method' => [
                                'id' => 'pix',
                                'qr_code' => '000201PIXWALLET3',
                            ],
                        ],
                    ],
                ],
            ], 201),
        ]);

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $this->postJson('/api/user/wallet/top-up', [
            'amount' => 15,
            'paymentMethod' => 'pix',
        ])->assertCreated();

        $this->assertDatabaseCount('carteira_movimentos', 0);
    }
}
