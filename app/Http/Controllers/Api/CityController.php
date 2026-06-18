<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cidade;
use Illuminate\Http\JsonResponse;

class CityController extends Controller
{
    public function index(): JsonResponse
    {
        $cidades = Cidade::query()->orderBy('name')->get();

        return response()->json(
            $cidades->map(fn (Cidade $cidade) => [
                'id' => $cidade->id,
                'name' => $cidade->name,
                'state' => $cidade->state,
            ])
        );
    }
}
