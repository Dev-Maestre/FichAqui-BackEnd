<?php

namespace App\Support;

class AssetUrl
{
    public static function resolve(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return $path;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $base = rtrim((string) (config('assets.url') ?: config('app.url')), '/');

        return $base.'/'.ltrim($path, '/');
    }
}
