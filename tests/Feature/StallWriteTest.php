<?php

namespace Tests\Feature;

use App\Models\Barraca;
use App\Models\Evento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StallWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_can_create_stall_on_existing_event(): void
    {
        $evento = $this->seedEvent();
        Sanctum::actingAs($this->organizerUser());

        $this->postJson("/api/events/{$evento->id}/stalls", [
            'name' => 'Barraca de Doces',
            'category' => 'doces',
            'responsible' => 'Ana Costa',
            'color' => '#ec4899',
            'stock' => 80,
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Barraca de Doces')
            ->assertJsonPath('eventId', $evento->id);

        $this->assertDatabaseHas('barracas', [
            'evento_id' => $evento->id,
            'name' => 'Barraca de Doces',
            'status' => 'open',
        ]);
    }

    public function test_organizer_can_close_stall_and_list_still_returns_it(): void
    {
        $evento = $this->seedEvent();
        $barraca = Barraca::query()->create([
            'id' => 'stall-close-test',
            'evento_id' => $evento->id,
            'name' => 'Barraca Teste',
            'category' => 'comidas',
            'responsible' => 'Joao',
            'color' => '#000000',
            'status' => 'open',
            'stock' => 10,
        ]);

        Sanctum::actingAs($this->organizerUser());

        $this->patchJson("/api/events/{$evento->id}/stalls/{$barraca->id}", [
            'status' => 'closed',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'closed');

        $this->getJson("/api/events/{$evento->id}/stalls")
            ->assertOk()
            ->assertJsonFragment(['id' => 'stall-close-test', 'status' => 'closed']);
    }

    public function test_non_organizer_cannot_create_stall(): void
    {
        $evento = $this->seedEvent();
        Sanctum::actingAs(User::factory()->create(['roles' => ['client']]));

        $this->postJson("/api/events/{$evento->id}/stalls", [
            'name' => 'Barraca',
            'category' => 'comidas',
            'responsible' => 'X',
            'color' => '#fff',
        ])->assertForbidden();
    }

    private function organizerUser(): User
    {
        return User::factory()->create([
            'roles' => ['client', 'organizer'],
            'organizer_id' => 'org-test',
        ]);
    }

    private function seedEvent(): Evento
    {
        return Evento::query()->create([
            'id' => 'event-stall-test',
            'name' => 'Festa Teste',
            'description' => 'Desc',
            'date' => '2026-06-24',
            'start_time' => '18:00',
            'end_time' => '23:00',
            'location' => 'Praca',
            'city_id' => 'curitiba-pr',
            'organizer_id' => 'org-test',
            'status' => 'published',
            'capacity' => 100,
            'primary_color' => '#d97706',
        ]);
    }
}
