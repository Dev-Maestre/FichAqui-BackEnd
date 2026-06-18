<?php

namespace App\Support;

use App\Models\Cidade;

class CidadeResolver
{
    /**
     * @return array{cidade: string, estado: string}|null
     */
    public static function labelsForCityId(string $cityId): ?array
    {
        $cidade = Cidade::query()->find($cityId);

        if (! $cidade) {
            return null;
        }

        return [
            'cidade' => $cidade->name,
            'estado' => $cidade->state,
        ];
    }
}
