<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Barraca;
use App\Models\Evento;
use App\Services\BarracaWriteService;
use App\Services\FrontendPresenter;
use App\Support\OrganizerAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StallController extends Controller
{
    public function __construct(
        private readonly BarracaWriteService $barracaWriteService,
    ) {}

    public function store(Request $request, string $eventId): JsonResponse
    {
        $user = $request->user();
        $evento = Evento::query()->findOrFail($eventId);

        OrganizerAuthorization::ensureOwns($user, $evento);

        $barraca = $this->barracaWriteService->create($evento, $request->all());

        return response()->json(FrontendPresenter::barraca($barraca), 201);
    }

    public function update(Request $request, string $eventId, string $stallId): JsonResponse
    {
        $user = $request->user();
        $evento = Evento::query()->findOrFail($eventId);
        $barraca = Barraca::query()->findOrFail($stallId);

        OrganizerAuthorization::ensureOwns($user, $evento);

        if ($barraca->evento_id !== $evento->id) {
            abort(404);
        }

        $updated = $this->barracaWriteService->update($barraca, $request->all());

        return response()->json(FrontendPresenter::barraca($updated));
    }
}
