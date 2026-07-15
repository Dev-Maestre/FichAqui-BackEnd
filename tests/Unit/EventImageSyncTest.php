<?php

namespace Tests\Unit;

use App\Support\EventImageSync;
use PHPUnit\Framework\TestCase;

class EventImageSyncTest extends TestCase
{
    public function test_prefers_banner_when_both_present(): void
    {
        $this->assertSame(
            'https://example.com/banner.jpg',
            EventImageSync::resolve('https://example.com/banner.jpg', 'https://example.com/icon.jpg')
        );
    }

    public function test_falls_back_to_icon_when_banner_missing(): void
    {
        $this->assertSame(
            'https://example.com/icon.jpg',
            EventImageSync::resolve(null, 'https://example.com/icon.jpg')
        );
    }

    public function test_returns_null_when_both_missing(): void
    {
        $this->assertNull(EventImageSync::resolve('', null));
    }
}
