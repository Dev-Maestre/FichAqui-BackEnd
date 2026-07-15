<?php

namespace Tests\Feature;

use App\Models\Oferta;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\FichaquiSeeder;
use Database\Seeders\OfferingSeeder;
use Database\Seeders\WalletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPedidosTest extends TestCase
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

    public function test_organizer_can_list_event_pedidos_with_admin_dto(): void
    {
        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $offeringId = Oferta::buildId('1', 'stall-1', 'pastel');

        $this->postJson('/api/events/1/pedidos', [
            'items' => [
                ['offeringId' => $offeringId, 'variantId' => 'carne', 'quantity' => 2],
            ],
            'paymentMethod' => 'credit_card',
            'cardId' => 'card-1',
        ])->assertCreated();

        $organizer = User::query()->where('email', 'raul@paroquia.com')->firstOrFail();
        Sanctum::actingAs($organizer);

        $this->getJson('/api/events/1/pedidos')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonStructure([[
                'id',
                'number',
                'status',
                'total',
                'items' => [['name', 'quantity', 'stallName']],
                'fichaCounts' => ['available', 'delivered'],
            ]])
            ->assertJsonPath('0.fichaCounts.available', 2)
            ->assertJsonPath('0.fichaCounts.delivered', 0);
    }

    public function test_unauthenticated_cannot_list_event_pedidos(): void
    {
        $this->getJson('/api/events/1/pedidos')->assertUnauthorized();
    }

    public function test_consumer_cannot_list_event_pedidos(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail());

        $this->getJson('/api/events/1/pedidos')->assertForbidden();
    }
}
