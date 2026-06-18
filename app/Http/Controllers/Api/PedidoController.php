<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\Pedido;
use App\Services\FrontendPresenter;
use App\Services\PedidoCheckoutService;
use App\Support\OrganizerAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PedidoController extends Controller
{
    public function __construct(
        private readonly PedidoCheckoutService $checkoutService,
    ) {}

    public function index(Request $request, string $eventId): JsonResponse
    {
        $evento = Evento::query()->findOrFail($eventId);
        OrganizerAuthorization::ensureOwns($request->user(), $evento);

        $pedidos = Pedido::query()
            ->with(['itens', 'fichas'])
            ->where('evento_id', $eventId)
            ->latest()
            ->get();

        return response()->json(
            $pedidos->map(fn (Pedido $pedido) => FrontendPresenter::pedidoAdmin($pedido))
        );
    }

    public function store(Request $request, string $eventId): JsonResponse
    {
        $evento = Evento::query()->findOrFail($eventId);
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $pedido = $this->checkoutService->checkout($user, $evento, $request->all());

        return response()->json(
            FrontendPresenter::pedidoCheckout($pedido),
            201,
        );
    }
}
