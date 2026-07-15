<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\FichaquiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FichaquiSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_fichaqui_seeder_updates_existing_user_by_external_id_when_email_differs(): void
    {
        User::factory()->create([
            'external_id' => 'user-apro',
            'email' => 'outro-email@example.com',
            'name' => 'Outro Nome',
            'mercadopago_customer_id' => 'CUST-STALE',
        ]);

        $this->seed(FichaquiSeeder::class);

        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseHas('users', [
            'external_id' => 'user-apro',
            'email' => 'test_user_5207637493757128652@testuser.com',
            'name' => 'APRO Silva',
            'mercadopago_customer_id' => null,
        ]);
    }

    public function test_fichaqui_seeder_is_idempotent(): void
    {
        $this->seed(FichaquiSeeder::class);
        $this->seed(FichaquiSeeder::class);

        $this->assertSame(3, User::query()->count());
    }
}
