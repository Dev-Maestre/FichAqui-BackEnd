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
        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $this->getJson('/api/user/wallet')
            ->assertOk()
            ->assertJsonPath('balance', 46)
            ->assertJsonPath('savedCards.0.id', 'card-1')
            ->assertJsonPath('savedCards.0.brand', 'visa')
            ->assertJsonPath('savedCards.0.lastFour', '5682')
            ->assertJsonPath('savedCards.0.holderName', 'APRO Silva')
            ->assertJsonPath('savedCards.0.isDefault', true)
            ->assertJsonCount(1, 'savedCards');
    }

    public function test_wallet_requires_authentication(): void
    {
        $this->getJson('/api/user/wallet')->assertUnauthorized();
    }

    public function test_wallet_transactions_returns_ledger_movements_newest_first(): void
    {
        $maria = User::query()->where('email', 'test_user_5207637493757128652@testuser.com')->firstOrFail();
        Sanctum::actingAs($maria);

        $this->getJson('/api/user/wallet/transactions')
            ->assertOk()
            ->assertJsonCount(2, 'transactions')
            ->assertJsonPath('transactions.0.direction', 'debit')
            ->assertJsonPath('transactions.0.type', 'compra')
            ->assertJsonPath('transactions.0.amount', 34)
            ->assertJsonPath('transactions.0.originType', 'pedido')
            ->assertJsonPath('transactions.0.originId', 'pedido-seed-wallet')
            ->assertJsonPath('transactions.1.direction', 'credit')
            ->assertJsonPath('transactions.1.type', 'recarga')
            ->assertJsonPath('transactions.1.amount', 80)
            ->assertJsonPath('transactions.1.originType', 'recarga')
            ->assertJsonPath('transactions.1.originId', 'recarga-seed-inicial');
    }

    public function test_wallet_transactions_requires_authentication(): void
    {
        $this->getJson('/api/user/wallet/transactions')->assertUnauthorized();
    }
}
