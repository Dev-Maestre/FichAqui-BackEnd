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
            'external_id' => 'user-maria',
            'email' => 'outro-email@example.com',
            'name' => 'Outro Nome',
        ]);

        $this->seed(FichaquiSeeder::class);

        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseHas('users', [
            'external_id' => 'user-maria',
            'email' => 'maria@testuser.com',
            'name' => 'Maria Silva',
        ]);
    }

    public function test_fichaqui_seeder_is_idempotent(): void
    {
        $this->seed(FichaquiSeeder::class);
        $this->seed(FichaquiSeeder::class);

        $this->assertSame(3, User::query()->count());
    }
}
