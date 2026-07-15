<?php

namespace Tests\Feature;

use Database\Seeders\StagingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StagingDemoSeederPrerequisiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_staging_demo_seeder_fails_without_base_seed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Run `php artisan db:seed` before StagingDemoSeeder.');

        $this->seed(StagingDemoSeeder::class);
    }
}
