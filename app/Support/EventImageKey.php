<?php

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

class EventImageKey
{
    /** @var array<string, string> */
    private const EXTENSION_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public static function extensionForMime(string $contentType): string
    {
        $normalized = strtolower(trim(explode(';', $contentType)[0]));

        return self::EXTENSION_BY_MIME[$normalized]
            ?? throw new InvalidArgumentException('Tipo de imagem não suportado.');
    }

    public static function generate(string $eventId, string $extension): string
    {
        $safeExtension = strtolower(ltrim($extension, '.'));

        return 'events/'.$eventId.'/'.Str::lower((string) Str::ulid()).'.'.$safeExtension;
    }

    public static function isManaged(?string $key): bool
    {
        if ($key === null || $key === '') {
            return false;
        }

        return (bool) preg_match('#^events/[^/]+/[a-z0-9]{26}\.[a-z]{3,4}$#', $key);
    }

    public static function belongsToEvent(string $key, string $eventId): bool
    {
        return str_starts_with($key, 'events/'.$eventId.'/');
    }
}
