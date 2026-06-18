<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthMeTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_exposes_primary_role_for_consumer(): void
    {
        $user = User::factory()->create([
            'roles' => ['client'],
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('role', 'consumer')
            ->assertJsonPath('roles', ['client']);
    }

    public function test_me_exposes_highest_privilege_role_for_multi_role_user(): void
    {
        $user = User::factory()->create([
            'roles' => ['client', 'organizer'],
            'organizer_id' => 'org-paroquia',
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('role', 'organizer')
            ->assertJsonPath('organizerId', 'org-paroquia');
    }

    public function test_login_response_includes_primary_role(): void
    {
        User::factory()->create([
            'email' => 'maria@email.com',
            'roles' => ['client'],
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'maria@email.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.role', 'consumer');
    }
}
