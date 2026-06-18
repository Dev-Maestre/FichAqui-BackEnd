<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\Ficha;
use App\Models\Pedido;
use App\Services\FrontendPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserPedidoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $includeFichas = $request->boolean('include_fichas');

        $query = Pedido::query()
            ->forUser($user->id)
            ->with('itens')
            ->latest();

        if ($includeFichas) {
            $query->with('fichas');
        }

        $pedidos = $query->get();

        return response()->json(
            $pedidos->map(
                fn (Pedido $pedido) => FrontendPresenter::pedido($pedido, summaryItems: true, withFichas: $includeFichas)
            )->values()->all()
        );
    }
}
