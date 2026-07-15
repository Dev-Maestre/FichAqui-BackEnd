<?php

namespace Tests\Feature;

use App\Models\Evento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['filesystems.disks.r2.bucket' => null]);
    }

    public function test_organizer_can_upload_and_apply_event_image_locally(): void
    {
        Sanctum::actingAs($this->organizerUser());
        $evento = $this->seedEvent();

        $target = $this->postJson("/api/events/{$evento->id}/image/upload-url", [
            'contentType' => 'image/jpeg',
        ])->assertOk()->json();

        $this->assertSame('POST', $target['method']);
        $this->assertStringContainsString("/api/events/{$evento->id}/image", $target['uploadUrl']);

        $file = UploadedFile::fake()->create('cover.jpg', 100, 'image/jpeg');

        $this->post("/api/events/{$evento->id}/image", [
            'key' => $target['key'],
            'file' => $file,
        ])->assertOk();

        Storage::disk('public')->assertExists($target['key']);

        $this->postJson("/api/events/{$evento->id}/image/apply", [
            'key' => $target['key'],
        ])
            ->assertOk()
            ->assertJsonPath('banner', fn ($value) => is_string($value) && $value !== '')
            ->assertJsonPath('icon', fn ($value) => is_string($value) && $value !== '');

        $evento->refresh();
        $this->assertSame($target['key'], $evento->banner);
        $this->assertSame($target['key'], $evento->icon);
    }

    public function test_replacing_event_image_deletes_previous_object(): void
    {
        Sanctum::actingAs($this->organizerUser());
        $evento = $this->seedEvent();

        $first = $this->issueAndStoreImage($evento->id, 'first.jpg');
        $this->postJson("/api/events/{$evento->id}/image/apply", ['key' => $first])->assertOk();

        $second = $this->issueAndStoreImage($evento->id, 'second.jpg');
        $this->postJson("/api/events/{$evento->id}/image/apply", ['key' => $second])->assertOk();

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_removing_event_image_deletes_managed_object(): void
    {
        Sanctum::actingAs($this->organizerUser());
        $evento = $this->seedEvent();

        $key = $this->issueAndStoreImage($evento->id, 'cover.jpg');
        $this->postJson("/api/events/{$evento->id}/image/apply", ['key' => $key])->assertOk();

        $this->postJson("/api/events/{$evento->id}/image/apply", ['key' => ''])
            ->assertOk()
            ->assertJsonPath('banner', null)
            ->assertJsonPath('icon', null);

        Storage::disk('public')->assertMissing($key);
    }

    private function issueAndStoreImage(string $eventId, string $filename): string
    {
        $target = $this->postJson("/api/events/{$eventId}/image/upload-url", [
            'contentType' => 'image/jpeg',
        ])->assertOk()->json();

        $this->post("/api/events/{$eventId}/image", [
            'key' => $target['key'],
            'file' => UploadedFile::fake()->create($filename, 100, 'image/jpeg'),
        ])->assertOk();

        return $target['key'];
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
            'id' => 'event-image-test',
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
            'icon' => null,
        ]);
    }
}
