<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogoProduto;
use App\Models\Categoria;
use App\Services\FrontendPresenter;
use Illuminate\Http\JsonResponse;

class BootstrapController extends Controller
{
    public function bootstrap(): JsonResponse
    {
        return response()->json($this->catalogPayload());
    }

    public function catalog(): JsonResponse
    {
        return response()->json($this->catalogPayload());
    }

    /**
     * @return array{categories: list<array<string, mixed>>, catalogProducts: list<array<string, mixed>>}
     */
    private function catalogPayload(): array
    {
        $categorias = Categoria::query()->orderBy('name')->get();
        $produtos = CatalogoProduto::query()
            ->with('variantTemplates')
            ->orderBy('name')
            ->get();

        return [
            'categories' => $categorias
                ->map(fn (Categoria $categoria) => FrontendPresenter::categoria($categoria))
                ->values()
                ->all(),
            'catalogProducts' => $produtos
                ->map(fn (CatalogoProduto $produto) => FrontendPresenter::catalogProduct($produto))
                ->values()
                ->all(),
        ];
    }
}
