<?php

namespace Tests\Feature;

use App\Models\Oferta;
use App\Models\Pedido;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\FichaquiSeeder;
use Database\Seeders\OfferingSeeder;
use Database\Seeders\WalletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FichaConsumeTest extends TestCase
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

    private function checkoutPayload(string $offeringId): array
    {
        return [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 2],
            ],
            'paymentMethod' => 'credit_card',
            'cardId' => 'card-1',
        ];
    }

    public function test_consume_one_ficha_keeps_pedido_available(): void
    {
        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $checkout = $this->postJson('/api/events/1/pedidos', $this->checkoutPayload($offeringId))
            ->assertCreated();

        $pedidoId = $checkout->json('id');
        $fichaId = $checkout->json('fichas.0.id');

        Sanctum::actingAs(User::query()->where('email', 'atendente@email.com')->firstOrFail());

        $this->patchJson("/api/fichas/{$fichaId}/status", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('status', 'delivered');

        $this->assertSame('available', Pedido::query()->findOrFail($pedidoId)->status);
    }

    public function test_consume_all_fichas_marks_pedido_delivered(): void
    {
        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $checkout = $this->postJson('/api/events/1/pedidos', $this->checkoutPayload($offeringId))
            ->assertCreated();

        $pedidoId = $checkout->json('id');
        $fichas = $checkout->json('fichas');

        Sanctum::actingAs(User::query()->where('email', 'raul@paroquia.com')->firstOrFail());

        foreach ($fichas as $ficha) {
            $this->postJson("/api/fichas/{$ficha['id']}/consume")->assertOk();
        }

        $this->assertSame('delivered', Pedido::query()->findOrFail($pedidoId)->status);
    }

    public function test_consume_is_idempotent(): void
    {
        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $checkout = $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 1],
            ],
            'paymentMethod' => 'credit_card',
            'cardId' => 'card-1',
        ])->assertCreated();

        $fichaId = $checkout->json('fichas.0.id');

        Sanctum::actingAs(User::query()->where('email', 'atendente@email.com')->firstOrFail());

        $this->postJson("/api/fichas/{$fichaId}/consume")->assertOk();
        $this->postJson("/api/fichas/{$fichaId}/consume")
            ->assertOk()
            ->assertJsonPath('status', 'delivered');
    }

    public function test_lookup_by_qr_returns_ficha(): void
    {
        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $checkout = $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 1],
            ],
            'paymentMethod' => 'credit_card',
            'cardId' => 'card-1',
        ])->assertCreated();

        $qrCode = $checkout->json('fichas.0.qrCode');

        Sanctum::actingAs(User::query()->where('email', 'atendente@email.com')->firstOrFail());

        $this->getJson('/api/fichas?qr='.$qrCode)
            ->assertOk()
            ->assertJsonPath('qrCode', $qrCode);
    }

    public function test_unknown_qr_returns_404(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'atendente@email.com')->firstOrFail());

        $this->getJson('/api/fichas?qr=QR-INEXISTENTE')->assertNotFound();
    }

    public function test_consumer_cannot_consume_ficha(): void
    {
        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $checkout = $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 1],
            ],
            'paymentMethod' => 'credit_card',
            'cardId' => 'card-1',
        ])->assertCreated();

        $fichaId = $checkout->json('fichas.0.id');

        $this->postJson("/api/fichas/{$fichaId}/consume")->assertForbidden();
    }

    public function test_atendente_cannot_consume_ficha_from_other_stall(): void
    {
        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-2', 'milho-verde');

        $checkout = $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'unidade', 'quantity' => 1],
            ],
            'paymentMethod' => 'credit_card',
            'cardId' => 'card-1',
        ])->assertCreated();

        $fichaId = $checkout->json('fichas.0.id');

        Sanctum::actingAs(User::query()->where('email', 'atendente@email.com')->firstOrFail());

        $this->postJson("/api/fichas/{$fichaId}/consume")->assertForbidden();
    }
}
