<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Services\EventImageStorageService;
use App\Services\EventoWriteService;
use App\Services\FrontendPresenter;
use App\Support\EventImageKey;
use App\Support\OrganizerAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EventImageController extends Controller
{
    public function __construct(
        private readonly EventImageStorageService $imageStorage,
        private readonly EventoWriteService $eventoWriteService,
    ) {}

    public function issueUploadUrl(Request $request, string $eventId): JsonResponse
    {
        $evento = $this->resolveOwnedEvent($request, $eventId);

        $validated = $request->validate([
            'contentType' => ['required', 'string', 'max:255'],
        ]);

        try {
            $target = $this->imageStorage->issueUploadTarget($evento->id, $validated['contentType']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'contentType' => [$exception->getMessage()],
            ]);
        }

        return response()->json($target);
    }

    public function store(Request $request, string $eventId): JsonResponse
    {
        if ($this->imageStorage->usesObjectStorage()) {
            abort(404);
        }

        $this->resolveOwnedEvent($request, $eventId);

        $maxKb = (int) config('event_images.max_kilobytes', 5120);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimes:jpeg,jpg,png,webp,gif'],
        ]);

        if (! EventImageKey::belongsToEvent($validated['key'], $eventId)) {
            throw ValidationException::withMessages([
                'key' => ['Chave de imagem invalida para este evento.'],
            ]);
        }

        $this->imageStorage->storeLocalUpload(
            $eventId,
            $validated['key'],
            $request->file('file'),
        );

        return response()->json(['ok' => true]);
    }

    public function apply(Request $request, string $eventId): JsonResponse
    {
        $evento = $this->resolveOwnedEvent($request, $eventId);

        $validated = $request->validate([
            'key' => ['nullable', 'string', 'max:255'],
        ]);

        $key = isset($validated['key']) ? trim((string) $validated['key']) : null;

        if ($key !== null && $key !== '' && ! EventImageKey::belongsToEvent($key, $eventId)) {
            throw ValidationException::withMessages([
                'key' => ['Chave de imagem invalida para este evento.'],
            ]);
        }

        $payload = [
            'banner' => $key === '' ? null : $key,
            'icon' => $key === '' ? null : $key,
        ];

        $updated = $this->eventoWriteService->update($evento, $payload);

        return response()->json(FrontendPresenter::evento($updated));
    }

    private function resolveOwnedEvent(Request $request, string $eventId): Evento
    {
        $evento = Evento::query()->findOrFail($eventId);
        OrganizerAuthorization::ensureOwns($request->user(), $evento);

        return $evento;
    }
}
