<?php

namespace App\Services;

use App\Support\EventImageKey;
use App\Support\EventImageSync;
use App\Models\Evento;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class EventImageStorageService
{
    public function usesObjectStorage(): bool
    {
        return filled(config('filesystems.disks.r2.bucket'));
    }

    /**
     * @return array{key: string, method: string, uploadUrl: string, headers: array<string, string>}
     */
    public function issueUploadTarget(string $eventId, string $contentType): array
    {
        $this->assertAllowedContentType($contentType);

        $extension = EventImageKey::extensionForMime($contentType);
        $key = EventImageKey::generate($eventId, $extension);

        if ($this->usesObjectStorage()) {
            $ttl = now()->addMinutes((int) config('event_images.upload_url_ttl_minutes', 15));
            $result = $this->objectDisk()->temporaryUploadUrl($key, $ttl, [
                'ContentType' => $contentType,
            ]);

            return [
                'key' => $key,
                'method' => 'PUT',
                'uploadUrl' => $result['url'],
                'headers' => $result['headers'] ?? ['Content-Type' => $contentType],
            ];
        }

        return [
            'key' => $key,
            'method' => 'POST',
            'uploadUrl' => url("/api/events/{$eventId}/image"),
            'headers' => [],
        ];
    }

    public function storeLocalUpload(string $eventId, string $key, $file): void
    {
        if ($this->usesObjectStorage()) {
            throw new InvalidArgumentException('Upload local indisponivel quando R2 esta configurado.');
        }

        if (! EventImageKey::belongsToEvent($key, $eventId)) {
            abort(422, 'Chave de imagem invalida para este evento.');
        }

        $this->publicDisk()->putFileAs(dirname($key), $file, basename($key), ['visibility' => 'public']);
    }

    public function deleteManaged(?string $key): void
    {
        if (! EventImageKey::isManaged($key)) {
            return;
        }

        $this->disk()->delete($key);
    }

    public function deleteIfReplaced(Evento $evento, array $attrs): void
    {
        if (! array_key_exists('banner', $attrs) && ! array_key_exists('icon', $attrs)) {
            return;
        }

        $oldKey = EventImageSync::resolve($evento->banner, $evento->icon);
        $newKey = EventImageSync::resolve(
            array_key_exists('banner', $attrs) ? $attrs['banner'] : $evento->banner,
            array_key_exists('icon', $attrs) ? $attrs['icon'] : $evento->icon,
        );

        if ($oldKey && $oldKey !== $newKey) {
            $this->deleteManaged($oldKey);
        }
    }

    private function assertAllowedContentType(string $contentType): void
    {
        $normalized = strtolower(trim(explode(';', $contentType)[0]));
        $allowed = config('event_images.allowed_mimes', []);

        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException('Tipo de imagem não suportado.');
        }
    }

    private function disk(): Filesystem
    {
        return $this->usesObjectStorage()
            ? $this->objectDisk()
            : $this->publicDisk();
    }

    private function objectDisk(): Filesystem
    {
        return Storage::disk('r2');
    }

    private function publicDisk(): Filesystem
    {
        return Storage::disk('public');
    }
}
