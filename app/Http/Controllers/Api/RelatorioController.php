<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Services\RelatorioService;
use App\Support\OrganizerAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RelatorioController extends Controller
{
    public function __construct(
        private readonly RelatorioService $relatorioService,
    ) {}

    public function show(Request $request, string $eventId): JsonResponse
    {
        $evento = Evento::query()->findOrFail($eventId);
        OrganizerAuthorization::ensureOwns($request->user(), $evento);

        return response()->json($this->relatorioService->forEvento($evento));
    }

    public function resumo(Request $request, string $eventId): JsonResponse
    {
        $evento = Evento::query()->findOrFail($eventId);
        OrganizerAuthorization::ensureOwns($request->user(), $evento);

        return response()->json($this->relatorioService->resumoForEvento($evento));
    }
}
