<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Barraca;
use App\Models\Evento;
use App\Models\Oferta;
use App\Services\EventoWriteService;
use App\Services\FrontendPresenter;
use App\Support\OrganizerAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function __construct(
        private readonly EventoWriteService $eventoWriteService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Evento::query()->orderBy('name');

        if (! OrganizerAuthorization::isOwnerListRequest($request)) {
            $query->where('status', '!=', 'archived');
        }

        if ($cityId = $request->string('city_id')->toString()) {
            $query->where('city_id', $cityId);
        }

        if ($request->boolean('public_only')) {
            $query->whereIn('status', ['published', 'active']);
        }

        if ($organizerId = $request->string('organizer_id')->toString()) {
            $query->where('organizer_id', $organizerId);
        }

        if ($request->boolean('events_only')) {
            $query->whereNotNull('date');
        }

        if ($request->boolean('establishments_only')) {
            $query->whereNull('date');
        }

        return response()->json(
            $query->get()->map(fn (Evento $evento) => FrontendPresenter::evento($evento))
        );
    }

    public function show(Request $request, string $eventId): JsonResponse
    {
        $evento = Evento::query()->findOrFail($eventId);

        if ($evento->status === 'archived' && ! OrganizerAuthorization::viewerOwns($request->user(), $evento)) {
            abort(404);
        }

        return response()->json(FrontendPresenter::evento($evento));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        OrganizerAuthorization::ensureCanWrite($user);

        $created = $this->eventoWriteService->create($request->all(), $user);

        return response()->json([
            'event' => FrontendPresenter::evento($created['event']),
            'stalls' => collect($created['stalls'])
                ->map(fn (Barraca $barraca) => FrontendPresenter::barraca($barraca))
                ->values()
                ->all(),
            'offerings' => collect($created['offerings'])
                ->map(fn (Oferta $oferta) => FrontendPresenter::offering($oferta))
                ->values()
                ->all(),
        ], 201);
    }

    public function update(Request $request, string $eventId): JsonResponse
    {
        $user = $request->user();
        $evento = Evento::query()->findOrFail($eventId);

        OrganizerAuthorization::ensureOwns($user, $evento);

        $updated = $this->eventoWriteService->update($evento, $request->all());

        return response()->json(FrontendPresenter::evento($updated));
    }

    public function stalls(Request $request, string $eventId): JsonResponse
    {
        $evento = Evento::query()->findOrFail($eventId);

        if ($evento->status === 'archived' && ! OrganizerAuthorization::viewerOwns($request->user(), $evento)) {
            abort(404);
        }

        $barracas = Barraca::query()
            ->where('evento_id', $eventId)
            ->orderBy('name')
            ->get();

        return response()->json($barracas->map(fn (Barraca $barraca) => FrontendPresenter::barraca($barraca)));
    }
}
