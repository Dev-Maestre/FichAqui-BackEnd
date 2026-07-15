<?php

namespace Tests\Feature;

use App\Models\Barraca;
use App\Models\Evento;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->seed(CitySeeder::class);
    }

    public function test_organizer_can_create_event_with_defaults(): void
    {
        $user = $this->organizerUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/events', [
            'name' => 'Festa de Sao Joao',
            'cityId' => 'curitiba-pr',
            'location' => 'Paroquia Central',
            'date' => '2026-06-24',
            'startTime' => '18:00',
            'endTime' => '23:00',
            'latitude' => -25.4284,
            'longitude' => -49.2733,
        ]);

        $response->assertCreated()
            ->assertJsonPath('event.name', 'Festa de Sao Joao')
            ->assertJsonPath('event.organizerId', 'org-test')
            ->assertJsonPath('event.status', 'draft')
            ->assertJsonPath('event.isEstablishment', false)
            ->assertJsonCount(1, 'stalls')
            ->assertJsonCount(1, 'offerings')
            ->assertJsonPath('stalls.0.name', 'Barraca Principal')
            ->assertJsonPath('offerings.0.productId', 'item-boas-vindas');

        $eventId = $response->json('event.id');
        $this->assertStringStartsWith('event-', $eventId);
        $this->assertDatabaseHas('eventos', ['id' => $eventId, 'organizer_id' => 'org-test']);
        $this->assertDatabaseHas('barracas', ['evento_id' => $eventId]);
        $this->assertDatabaseHas('ofertas', [
            'evento_id' => $eventId,
            'catalogo_produto_id' => 'item-boas-vindas',
        ]);
    }

    public function test_organizer_can_create_establishment_without_date(): void
    {
        $user = $this->organizerUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/events', [
            'name' => 'Cantina Paroquial',
            'cityId' => 'curitiba-pr',
            'location' => 'Salao paroquial',
        ]);

        $response->assertCreated()
            ->assertJsonPath('event.name', 'Cantina Paroquial')
            ->assertJsonPath('event.date', '')
            ->assertJsonPath('event.isEstablishment', true);

        $this->assertNull(Evento::query()->find($response->json('event.id'))?->date);
    }

    public function test_client_without_organizer_role_cannot_create_event(): void
    {
        $user = User::factory()->create([
            'roles' => ['client'],
            'organizer_id' => 'org-test',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/events', [
            'name' => 'Bloqueado',
            'cityId' => 'curitiba-pr',
            'location' => 'Local',
            'date' => '2026-06-24',
            'startTime' => '18:00',
            'endTime' => '23:00',
        ])->assertForbidden();
    }

    public function test_organizer_can_patch_own_event(): void
    {
        $user = $this->organizerUser();
        $evento = $this->seedEvent(['organizer_id' => 'org-test', 'status' => 'draft']);
        Sanctum::actingAs($user);

        $this->patchJson("/api/events/{$evento->id}", [
            'status' => 'published',
            'name' => 'Nome atualizado',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'published')
            ->assertJsonPath('name', 'Nome atualizado');
    }

    public function test_organizer_cannot_patch_event_owned_by_another(): void
    {
        $user = $this->organizerUser();
        $evento = $this->seedEvent(['organizer_id' => 'org-other']);
        Sanctum::actingAs($user);

        $this->patchJson("/api/events/{$evento->id}", ['name' => 'Hack'])
            ->assertForbidden();
    }

    public function test_evento_cannot_lose_date_on_patch(): void
    {
        $user = $this->organizerUser();
        $evento = $this->seedEvent([
            'organizer_id' => 'org-test',
            'date' => '2026-06-24',
            'start_time' => '18:00',
            'end_time' => '23:00',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson("/api/events/{$evento->id}", ['date' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);
    }

    public function test_establishment_cannot_receive_date_on_patch(): void
    {
        $user = $this->organizerUser();
        $evento = $this->seedEvent([
            'organizer_id' => 'org-test',
            'date' => null,
            'start_time' => null,
            'end_time' => null,
        ]);
        Sanctum::actingAs($user);

        $this->patchJson("/api/events/{$evento->id}", ['date' => '2026-06-24'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);
    }

    public function test_archived_event_is_hidden_from_public_list(): void
    {
        $this->seedEvent(['id' => 'event-public', 'status' => 'published']);
        $this->seedEvent(['id' => 'event-archived', 'status' => 'archived']);

        $response = $this->getJson('/api/events');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains('event-public'));
        $this->assertFalse($ids->contains('event-archived'));
    }

    public function test_owner_sees_archived_in_organizer_list(): void
    {
        $user = $this->organizerUser();
        $this->seedEvent(['id' => 'event-archived', 'organizer_id' => 'org-test', 'status' => 'archived']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/events?organizer_id=org-test');

        $response->assertOk();
        $this->assertTrue(collect($response->json())->pluck('id')->contains('event-archived'));
    }

    public function test_archived_event_returns_404_for_public_show(): void
    {
        $evento = $this->seedEvent(['status' => 'archived']);

        $this->getJson("/api/events/{$evento->id}")->assertNotFound();
    }

    public function test_owner_can_show_archived_event(): void
    {
        $user = $this->organizerUser();
        $evento = $this->seedEvent(['organizer_id' => 'org-test', 'status' => 'archived']);
        Sanctum::actingAs($user);

        $this->getJson("/api/events/{$evento->id}")
            ->assertOk()
            ->assertJsonPath('status', 'archived');
    }

    public function test_archived_event_only_allows_restore_to_draft(): void
    {
        $user = $this->organizerUser();
        $evento = $this->seedEvent(['organizer_id' => 'org-test', 'status' => 'archived']);
        Sanctum::actingAs($user);

        $this->patchJson("/api/events/{$evento->id}", ['name' => 'Novo nome'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->patchJson("/api/events/{$evento->id}", ['status' => 'published'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->patchJson("/api/events/{$evento->id}", ['status' => 'draft'])
            ->assertOk()
            ->assertJsonPath('status', 'draft');
    }

    public function test_organizer_can_archive_event(): void
    {
        $user = $this->organizerUser();
        $evento = $this->seedEvent(['organizer_id' => 'org-test', 'status' => 'published']);
        Sanctum::actingAs($user);

        $this->patchJson("/api/events/{$evento->id}", ['status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('status', 'archived');
    }

    private function organizerUser(): User
    {
        return User::factory()->create([
            'roles' => ['client', 'organizer'],
            'organizer_id' => 'org-test',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedEvent(array $overrides = []): Evento
    {
        $evento = Evento::query()->create(array_merge([
            'id' => 'event-'.fake()->unique()->numerify('#####'),
            'name' => 'Evento teste',
            'description' => 'Descricao',
            'date' => '2026-06-24',
            'start_time' => '18:00',
            'end_time' => '23:00',
            'location' => 'Local',
            'city_id' => 'curitiba-pr',
            'organizer_id' => 'org-test',
            'banner' => null,
            'status' => 'draft',
            'capacity' => 100,
            'primary_color' => '#d97706',
            'code' => null,
            'icon' => '??',
        ], $overrides));

        $stallId = "stall-{$evento->id}-default";
        Barraca::query()->create([
            'id' => $stallId,
            'evento_id' => $evento->id,
            'name' => 'Barraca',
            'category' => 'comidas',
            'responsible' => 'Resp',
            'color' => '#d97706',
            'status' => 'open',
        ]);

        return $evento;
    }
}
