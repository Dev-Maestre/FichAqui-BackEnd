<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Barraca;
use App\Models\Evento;
use App\Services\FrontendPresenter;
use App\Services\OfferingWriteService;
use App\Support\OrganizerAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfferingController extends Controller
{
    public function __construct(
        private readonly OfferingWriteService $offeringWriteService,
    ) {}

    public function index(Request $request, string $eventId): JsonResponse
    {
        $evento = Evento::query()->findOrFail($eventId);

        if ($evento->status === 'archived' && ! OrganizerAuthorization::viewerOwns($request->user(), $evento)) {
            abort(404);
        }

        $ofertas = $this->offeringWriteService->listForEvent($eventId);

        return response()->json(
            $ofertas->map(fn ($oferta) => FrontendPresenter::offering($oferta))->values()->all()
        );
    }

    public function replaceForStall(Request $request, string $eventId, string $stallId): JsonResponse
    {
        $user = $request->user();
        $evento = Evento::query()->findOrFail($eventId);
        $barraca = Barraca::query()->findOrFail($stallId);

        OrganizerAuthorization::ensureOwns($user, $evento);

        /** @var mixed $payload */
        $payload = $request->json()->all();
        $offeringsInput = is_array($payload) && array_is_list($payload)
            ? $payload
            : (is_array($payload) ? ($payload['offerings'] ?? null) : null);

        if (! is_array($offeringsInput)) {
            abort(422, 'Corpo deve ser um array de ofertas.');
        }

        $ofertas = $this->offeringWriteService->replaceForStall(
            $evento,
            $barraca,
            $offeringsInput,
        );

        return response()->json(
            collect($ofertas)
                ->map(fn ($oferta) => FrontendPresenter::offering($oferta))
                ->values()
                ->all()
        );
    }
}
