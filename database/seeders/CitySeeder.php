<?php

namespace Database\Seeders;

use App\Models\Cidade;
use Illuminate\Database\Seeder;

class CitySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->cidades() as $cidade) {
            Cidade::query()->updateOrCreate(['id' => $cidade['id']], $cidade);
        }
    }

    /**
     * @return list<array{id: string, name: string, state: string}>
     */
    private function cidades(): array
    {
        return [
            ['id' => 'curitiba-pr', 'name' => 'Curitiba', 'state' => 'PR'],
            ['id' => 'londrina-pr', 'name' => 'Londrina', 'state' => 'PR'],
            ['id' => 'sao-paulo-sp', 'name' => 'Sao Paulo', 'state' => 'SP'],
        ];
    }
}
