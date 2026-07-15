<?php

namespace Tests\Feature;

use App\Models\Barraca;
use App\Models\Evento;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\FichaquiSeeder;
use Database\Seeders\OfferingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OfferingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->seed(FichaquiSeeder::class);
        $this->seed(OfferingSeeder::class);
    }

    public function test_public_can_list_offerings_for_event(): void
    {
        $response = $this->getJson('/api/events/1/offerings')->assertOk();

        $response
            ->assertJsonStructure([[
                'id',
                'eventId',
                'stallId',
                'productId',
                'available',
                'variants' => [['templateId', 'price', 'available', 'stock']],
            ]])
            ->assertJsonFragment([
                'productId' => 'pastel',
                'templateId' => 'carne',
            ]);
    }

    public function test_organizer_can_replace_stall_offerings(): void
    {
        $user = $this->organizerUser();
        Sanctum::actingAs($user);

        $payload = [
            [
                'productId' => 'pastel',
                'available' => true,
                'variants' => [
                    ['templateId' => 'carne', 'price' => 9.5, 'available' => true, 'stock' => 80],
                    ['templateId' => 'queijo', 'price' => 8.5, 'available' => false, 'stock' => 0],
                ],
            ],
        ];

        $this->putJson('/api/events/1/stalls/stall-1/offerings', $payload)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.productId', 'pastel')
            ->assertJsonPath('0.variants.0.price', 9.5);

        $this->assertDatabaseHas('ofertas', [
            'id' => 'offering-1-stall-1-pastel',
            'catalogo_produto_id' => 'pastel',
        ]);
        $this->assertDatabaseMissing('ofertas', [
            'barraca_id' => 'stall-1',
            'catalogo_produto_id' => 'ingresso',
        ]);
    }

    public function test_put_rejects_unknown_catalog_product(): void
    {
        Sanctum::actingAs($this->organizerUser());

        $this->putJson('/api/events/1/stalls/stall-1/offerings', [
            [
                'productId' => 'produto-inexistente',
                'variants' => [
                    ['templateId' => 'carne', 'price' => 5, 'available' => true, 'stock' => 10],
                ],
            ],
        ])->assertStatus(422);
    }

    public function test_client_cannot_replace_offerings(): void
    {
        Sanctum::actingAs(User::factory()->create(['roles' => ['client']]));

        $this->putJson('/api/events/1/stalls/stall-1/offerings', [])->assertForbidden();
    }

    private function organizerUser(): User
    {
        return User::factory()->create([
            'roles' => ['client', 'organizer'],
            'organizer_id' => 'org-paroquia',
        ]);
    }
}
