<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ficha;
use App\Services\FichaConsumeService;
use App\Services\FrontendPresenter;
use App\Support\FichaAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FichaController extends Controller
{
    public function __construct(
        private readonly FichaConsumeService $consumeService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr' => ['required', 'string'],
        ]);

        $ficha = Ficha::query()
            ->with('pedido.evento')
            ->where('qr_code', $validated['qr'])
            ->firstOrFail();

        FichaAuthorization::ensureCanConsume($request->user(), $ficha);

        return response()->json(FrontendPresenter::ficha($ficha));
    }

    public function updateStatus(Request $request, string $fichaId): JsonResponse
    {
        $ficha = $this->resolveFicha($request, $fichaId);

        FichaAuthorization::ensureCanConsume($request->user(), $ficha);

        $validated = $request->validate([
            'status' => ['required', 'in:delivered'],
        ]);

        $updated = $this->consumeService->consume($ficha, $validated['status']);

        return response()->json(FrontendPresenter::ficha($updated));
    }

    public function consume(Request $request, string $fichaId): JsonResponse
    {
        $ficha = $this->resolveFicha($request, $fichaId);

        FichaAuthorization::ensureCanConsume($request->user(), $ficha);

        $updated = $this->consumeService->consume($ficha);

        return response()->json(FrontendPresenter::ficha($updated));
    }

    private function resolveFicha(Request $request, string $fichaId): Ficha
    {
        if ($qr = $request->query('qr')) {
            return Ficha::query()
                ->with('pedido.evento')
                ->where('qr_code', $qr)
                ->firstOrFail();
        }

        return Ficha::query()
            ->with('pedido.evento')
            ->findOrFail($fichaId);
    }
}
