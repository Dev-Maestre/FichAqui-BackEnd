<?php

namespace Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_bootstrap_returns_catalog_only(): void
    {
        $response = $this->getJson('/api/bootstrap')->assertOk();

        $response
            ->assertJsonStructure([
                'categories' => [['id', 'name', 'icon', 'color']],
                'catalogProducts' => [[
                    'id',
                    'name',
                    'description',
                    'category',
                    'image',
                    'variantTemplates' => [['id', 'label']],
                ]],
            ])
            ->assertJsonPath('categories.0.id', 'bebidas');

        $payload = $response->json();
        $this->assertArrayNotHasKey('events', $payload);
        $this->assertArrayNotHasKey('menuProducts', $payload);
        $this->assertArrayNotHasKey('orders', $payload);
    }

    public function test_catalog_returns_only_catalog_contract(): void
    {
        $response = $this->getJson('/api/catalog')->assertOk();

        $payload = $response->json();

        $this->assertArrayHasKey('categories', $payload);
        $this->assertArrayHasKey('catalogProducts', $payload);
        $this->assertArrayNotHasKey('events', $payload);
        $this->assertArrayNotHasKey('menuProducts', $payload);
    }

    public function test_catalog_product_matches_guide_shape(): void
    {
        $this->getJson('/api/catalog')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'pastel',
                'name' => 'Pastel',
                'category' => 'comidas',
            ])
            ->assertJsonFragment([
                'id' => 'carne',
                'label' => 'Carne',
            ]);
    }
}
