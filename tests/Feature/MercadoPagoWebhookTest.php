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

class MercadoPagoWebhookTest extends TestCase
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
     * @return array<string, string>
     */
    private function signedHeaders(string $secret, string $dataId, string $requestId = 'req-webhook-1'): array
    {
        $ts = '1704908010';
        $normalizedId = strtolower($dataId);
        $manifest = "id:{$normalizedId};request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, $secret);

        return [
            'x-signature' => "ts={$ts},v1={$v1}",
            'x-request-id' => $requestId,
        ];
    }

    public function test_webhook_mp_fulfills_pix_order_when_payment_approved(): void
    {
        config([
            'mercadopago.access_token' => 'TEST-token',
            'mercadopago.api_base_url' => 'https://api.mercadopago.com',
            'mercadopago.sandbox' => false,
            'mercadopago.webhook_secret' => null,
        ]);

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

        $maria = User::query()->where('external_id', 'user-apro')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 2],
            ],
            'paymentMethod' => 'pix',
        ])->assertCreated();

        $this->postJson('/webhook-mp?data.id=ORD55443322', [
            'id' => 12345,
            'live_mode' => false,
            'type' => 'orders',
            'action' => 'order.updated',
            'data' => ['id' => 'ORD55443322'],
        ])
            ->assertOk()
            ->assertJsonPath('received', true);

        $this->assertDatabaseCount('fichas', 2);
    }

    public function test_webhook_mp_rejects_invalid_signature_when_secret_configured(): void
    {
        config(['mercadopago.webhook_secret' => 'mp-secret-test']);

        $this->postJson('/webhook-mp?data.id=PAY123', [
            'type' => 'payment',
            'data' => ['id' => 'PAY123'],
        ], [
            'x-signature' => 'ts=1704908010,v1=invalid',
            'x-request-id' => 'req-webhook-1',
        ])->assertUnauthorized();
    }

    public function test_webhook_mp_accepts_valid_signature(): void
    {
        config([
            'mercadopago.webhook_secret' => 'mp-secret-test',
            'mercadopago.access_token' => null,
        ]);

        $headers = $this->signedHeaders('mp-secret-test', 'PAY123');

        $this->postJson('/webhook-mp?data.id=PAY123', [
            'type' => 'payment',
            'data' => ['id' => 'PAY123'],
        ], $headers)
            ->assertOk()
            ->assertJsonPath('received', true);
    }

    public function test_webhook_mp_acknowledges_informational_topics(): void
    {
        config(['mercadopago.webhook_secret' => null]);

        $this->postJson('/webhook-mp', [
            'type' => 'stop_delivery_op_wh',
            'data' => ['id' => 'FRAUD-1'],
        ])
            ->assertOk()
            ->assertJsonPath('received', true);

        $this->postJson('/webhook-mp', [
            'type' => 'topic_card_id_wh',
            'data' => ['id' => 'CARD-1'],
        ])
            ->assertOk()
            ->assertJsonPath('received', true);

        $this->postJson('/webhook-mp', [
            'type' => 'topic_merchant_order_wh',
            'data' => ['id' => 'MO-1'],
        ])
            ->assertOk()
            ->assertJsonPath('received', true);
    }

    public function test_legacy_api_webhook_route_still_works(): void
    {
        config(['mercadopago.webhook_secret' => null]);

        $this->postJson('/api/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => 'PAY-NOT-FOUND'],
        ])
            ->assertOk()
            ->assertJsonPath('received', true);
    }
}
