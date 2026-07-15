<?php

namespace App\Support;

class EventImageSync
{
    public static function resolve(?string $banner, ?string $icon): ?string
    {
        $banner = trim((string) $banner);
        $icon = trim((string) $icon);

        if ($banner !== '') {
            return $banner;
        }

        if ($icon !== '') {
            return $icon;
        }

        return null;
    }
}
