<?php

namespace Tests\Feature;

use App\Models\Ficha;
use App\Models\Pedido;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\FichaquiSeeder;
use Database\Seeders\OfferingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->seed(FichaquiSeeder::class);
        $this->seed(OfferingSeeder::class);
    }

    public function test_user_sees_only_own_pedidos(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Pedido::query()->create([
            'id' => 'pedido-a',
            'evento_id' => '1',
            'user_id' => $userA->id,
            'number' => '1001',
            'total' => 10,
            'status' => 'available',
            'qr_code' => 'QR-A',
        ]);

        Pedido::query()->create([
            'id' => 'pedido-b',
            'evento_id' => '1',
            'user_id' => $userB->id,
            'number' => '1002',
            'total' => 12,
            'status' => 'available',
            'qr_code' => 'QR-B',
        ]);

        Sanctum::actingAs($userA);

        $this->getJson('/api/user/pedidos')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', 'pedido-a');
    }

    public function test_user_fichas_returns_only_available_for_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $pedidoA = Pedido::query()->create([
            'id' => 'pedido-ficha-a',
            'evento_id' => '1',
            'user_id' => $userA->id,
            'number' => '2001',
            'total' => 8,
            'status' => 'available',
            'qr_code' => 'QR-FA',
        ]);

        $pedidoB = Pedido::query()->create([
            'id' => 'pedido-ficha-b',
            'evento_id' => '1',
            'user_id' => $userB->id,
            'number' => '2002',
            'total' => 8,
            'status' => 'available',
            'qr_code' => 'QR-FB',
        ]);

        Ficha::query()->create([
            'id' => 'ficha-available-a',
            'pedido_id' => $pedidoA->id,
            'qr_code' => 'QR-FICHA-A1',
            'status' => 'available',
            'item_name' => 'Pastel',
            'item_image' => 'pastel',
            'barraca_id' => 'stall-1',
            'barraca_name' => 'Barraca do Pastel',
        ]);

        Ficha::query()->create([
            'id' => 'ficha-delivered-a',
            'pedido_id' => $pedidoA->id,
            'qr_code' => 'QR-FICHA-A2',
            'status' => 'delivered',
            'item_name' => 'Pastel',
            'item_image' => 'pastel',
            'barraca_id' => 'stall-1',
            'barraca_name' => 'Barraca do Pastel',
        ]);

        Ficha::query()->create([
            'id' => 'ficha-available-b',
            'pedido_id' => $pedidoB->id,
            'qr_code' => 'QR-FICHA-B1',
            'status' => 'available',
            'item_name' => 'Milho',
            'item_image' => 'milho',
            'barraca_id' => 'stall-2',
            'barraca_name' => 'Barraca do Milho',
        ]);

        Sanctum::actingAs($userA);

        $this->getJson('/api/user/fichas')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', 'ficha-available-a');
    }
}
