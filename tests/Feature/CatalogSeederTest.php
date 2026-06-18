<?php

namespace Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_seeder_is_idempotent(): void
    {
        $this->seed(CatalogSeeder::class);
        $firstCount = $this->catalogCounts();

        $this->seed(CatalogSeeder::class);
        $secondCount = $this->catalogCounts();

        $this->assertSame($firstCount, $secondCount);
        $this->assertGreaterThan(0, $firstCount['categories']);
        $this->assertGreaterThan(0, $firstCount['products']);
        $this->assertGreaterThan(0, $firstCount['templates']);
    }

    public function test_catalog_seeder_populates_expected_categories(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->assertDatabaseHas('categorias', ['id' => 'comidas', 'name' => 'Comidas']);
        $this->assertDatabaseHas('catalogo_produtos', ['id' => 'pastel', 'categoria_id' => 'comidas']);
        $this->assertDatabaseHas('variant_templates', [
            'id' => 'pastel-carne',
            'slug' => 'carne',
            'label' => 'Carne',
        ]);
    }

    /**
     * @return array{categories: int, products: int, templates: int}
     */
    private function catalogCounts(): array
    {
        return [
            'categories' => (int) \App\Models\Categoria::query()->count(),
            'products' => (int) \App\Models\CatalogoProduto::query()->count(),
            'templates' => (int) \App\Models\VariantTemplate::query()->count(),
        ];
    }
}
