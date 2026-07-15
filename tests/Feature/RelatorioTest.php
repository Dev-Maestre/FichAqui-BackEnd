<?php

namespace Tests\Feature;

use App\Models\Oferta;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\FichaquiSeeder;
use Database\Seeders\OfferingSeeder;
use Database\Seeders\WalletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RelatorioTest extends TestCase
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

    public function test_organizer_can_fetch_event_relatorios(): void
    {
        $this->createPaidPedido();

        $organizer = User::query()->where('email', 'raul@paroquia.com')->firstOrFail();
        Sanctum::actingAs($organizer);

        $this->getJson('/api/events/1/relatorios')
            ->assertOk()
            ->assertJsonStructure([
                'totalRevenue',
                'orderCount',
                'averageTicket',
                'salesByHour',
                'salesByCategory',
                'topProducts',
                'salesByStall' => [[
                    'stallId',
                    'name',
                    'color',
                    'status',
                    'revenue',
                    'orderCount',
                    'percentage',
                ]],
            ])
            ->assertJsonPath('orderCount', 1)
            ->assertJsonPath('salesByStall.0.orderCount', 1);
    }

    public function test_organizer_can_fetch_today_resumo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 15:30:00', 'America/Sao_Paulo'));

        $pedido = $this->createPaidPedido();

        $organizer = User::query()->where('email', 'raul@paroquia.com')->firstOrFail();
        Sanctum::actingAs($organizer);

        $this->getJson('/api/events/1/resumo')
            ->assertOk()
            ->assertJsonStructure([
                'orderCount',
                'totalRevenue',
                'consumerCount',
                'pendingOrderCount',
                'salesByStall' => [[
                    'stallId',
                    'name',
                    'color',
                    'status',
                    'revenue',
                    'orderCount',
                    'fichasIssued',
                    'fichasDelivered',
                ]],
            ])
            ->assertJsonPath('orderCount', 1)
            ->assertJsonPath('consumerCount', 1)
            ->assertJsonPath('salesByStall.0.fichasIssued', 2)
            ->assertJsonPath('salesByStall.0.fichasDelivered', 0);

        Pedido::query()->whereKey($pedido->id)->update([
            'created_at' => Carbon::now('America/Sao_Paulo')->subDay(),
        ]);

        $this->getJson('/api/events/1/resumo')
            ->assertOk()
            ->assertJsonPath('orderCount', 0)
            ->assertJsonPath('totalRevenue', 0);

        Carbon::setTestNow();
    }

    public function test_resumo_counts_pending_pix_orders_separately(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 18:00:00', 'America/Sao_Paulo'));

        Pedido::query()->create([
            'id' => 'pedido-pix-pending',
            'evento_id' => '1',
            'user_id' => User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->value('id'),
            'number' => 99,
            'total' => 25.0,
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'payment_method' => 'pix',
        ]);

        $organizer = User::query()->where('email', 'raul@paroquia.com')->firstOrFail();
        Sanctum::actingAs($organizer);

        $this->getJson('/api/events/1/resumo')
            ->assertOk()
            ->assertJsonPath('pendingOrderCount', 1)
            ->assertJsonPath('orderCount', 0)
            ->assertJsonPath('totalRevenue', 0);

        Carbon::setTestNow();
    }

    public function test_multi_stall_pedido_counts_in_each_stall(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 20:00:00', 'America/Sao_Paulo'));

        $consumer = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
        Sanctum::actingAs($consumer);

        $pastelOffering = Oferta::buildId('1', 'stall-1', 'pastel');
        $milhoOffering = Oferta::buildId('1', 'stall-2', 'milho-verde');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $pastelOffering, 'variantId' => 'carne', 'quantity' => 1],
                ['offeringId' => $milhoOffering, 'variantId' => 'unidade', 'quantity' => 1],
            ],
            'paymentMethod' => 'credit_card',
            'cardId' => 'card-1',
        ])->assertCreated();

        $organizer = User::query()->where('email', 'raul@paroquia.com')->firstOrFail();
        Sanctum::actingAs($organizer);

        $response = $this->getJson('/api/events/1/resumo')->assertOk();
        $stalls = collect($response->json('salesByStall'))->keyBy('stallId');

        $this->assertSame(1, $stalls->get('stall-1')['orderCount']);
        $this->assertSame(1, $stalls->get('stall-2')['orderCount']);
        $this->assertGreaterThan(0, $stalls->get('stall-1')['revenue']);
        $this->assertGreaterThan(0, $stalls->get('stall-2')['revenue']);

        Carbon::setTestNow();
    }

    public function test_consumer_cannot_fetch_relatorios(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail());

        $this->getJson('/api/events/1/relatorios')->assertForbidden();
        $this->getJson('/api/events/1/resumo')->assertForbidden();
    }

    private function createPaidPedido(): Pedido
    {
        $consumer = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
        Sanctum::actingAs($consumer);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $response = $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 2],
            ],
            'paymentMethod' => 'credit_card',
            'cardId' => 'card-1',
        ])->assertCreated();

        return Pedido::query()->findOrFail($response->json('id'));
    }
}
