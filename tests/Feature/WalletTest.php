<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\FichaquiSeeder;
use Database\Seeders\WalletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->seed(FichaquiSeeder::class);
        $this->seed(WalletSeeder::class);
    }

    public function test_wallet_returns_balance_and_saved_cards(): void
    {
        $maria = User::query()->where('email', 'maria@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $this->getJson('/api/user/wallet')
            ->assertOk()
            ->assertJsonPath('balance', 46)
            ->assertJsonPath('savedCards.0.id', 'card-1')
            ->assertJsonPath('savedCards.0.brand', 'visa')
            ->assertJsonPath('savedCards.0.isDefault', true);
    }

    public function test_wallet_requires_authentication(): void
    {
        $this->getJson('/api/user/wallet')->assertUnauthorized();
    }
}
