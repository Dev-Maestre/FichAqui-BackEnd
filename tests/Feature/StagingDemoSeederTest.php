<?php

namespace Tests\Feature;

use App\Models\Carteira;
use App\Models\Ficha;
use App\Models\Pedido;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CitySeeder;
use Database\Seeders\FichaquiSeeder;
use Database\Seeders\OfferingSeeder;
use Database\Seeders\StagingDemoSeeder;
use Database\Seeders\WalletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StagingDemoSeederTest extends TestCase
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

    public function test_staging_demo_seeder_is_idempotent(): void
    {
        $this->seed(StagingDemoSeeder::class);
        $this->seed(StagingDemoSeeder::class);

        $this->assertSame(6, Pedido::query()->where('id', 'like', 'pedido-demo-%')->count());
        $this->assertSame(7, Ficha::query()->where('id', 'like', 'ficha-demo-%')->count());
    }

    public function test_staging_demo_seeder_creates_expected_demo_state(): void
    {
        $this->seed(StagingDemoSeeder::class);

        $this->assertDatabaseHas('pedidos', [
            'id' => 'pedido-demo-wallet',
            'status' => 'available',
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
        ]);

        $this->assertDatabaseHas('pedidos', [
            'id' => 'pedido-demo-parcial',
            'status' => 'available',
        ]);

        $this->assertDatabaseHas('fichas', [
            'id' => 'ficha-demo-parcial-1',
            'qr_code' => 'QR-DEMO-PASTEL-03',
            'status' => 'available',
        ]);

        $this->assertDatabaseHas('fichas', [
            'id' => 'ficha-demo-parcial-2',
            'qr_code' => 'QR-DEMO-PASTEL-04',
            'status' => 'delivered',
        ]);

        $this->assertDatabaseHas('pedidos', [
            'id' => 'pedido-demo-pix',
            'status' => 'pending_payment',
            'payment_status' => 'pending',
        ]);

        $this->assertDatabaseHas('carteira_recargas', [
            'id' => 'recarga-demo-pix',
            'payment_status' => 'pending',
        ]);

        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        $balance = Carteira::query()->where('user_id', $maria->id)->value('balance');

        $this->assertSame(78.0, (float) $balance);
    }

    public function test_demo_qr_lookup_and_admin_counts_work(): void
    {
        $this->seed(StagingDemoSeeder::class);

        Sanctum::actingAs(User::query()->where('email', 'atendente@email.com')->firstOrFail());

        $this->getJson('/api/fichas?qr=QR-DEMO-PASTEL-01')
            ->assertOk()
            ->assertJsonPath('qrCode', 'QR-DEMO-PASTEL-01')
            ->assertJsonPath('status', 'available');

        Sanctum::actingAs(User::query()->where('email', 'raul@paroquia.com')->firstOrFail());

        $this->getJson('/api/events/1/pedidos')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'pedido-demo-parcial',
                'fichaCounts' => ['available' => 1, 'delivered' => 1],
            ]);
    }
}
