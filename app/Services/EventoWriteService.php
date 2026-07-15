<?php

namespace App\Services;

use App\Models\Barraca;
use App\Models\Evento;
use App\Models\Oferta;
use App\Models\User;
use App\Services\EventImageStorageService;
use App\Support\CidadeResolver;
use App\Support\EventImageSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EventoWriteService
{
    /** @var list<string> */
    public const STATUSES = ['draft', 'published', 'active', 'finished', 'archived'];

    public function __construct(
        private readonly OfferingWriteService $offeringWriteService,
        private readonly EventImageStorageService $eventImageStorage,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{event: Evento, stalls: list<Barraca>, offerings: list<Oferta>}
     */
    public function create(array $input, User $user): array
    {
        $validated = validator($input, $this->createRules())->validate();
        $isEstablishment = $this->isEstablishmentPayload($validated);

        $this->assertTypeFieldsForCreate($validated, $isEstablishment);
        $this->assertCoordinatesForCreate($validated, $isEstablishment);

        $cityLabels = $this->resolveCityLabels($validated['cityId']);

        $eventId = 'event-'.Str::lower((string) Str::ulid());
        $primaryColor = $validated['primaryColor'] ?? '#d97706';

        $eventImage = EventImageSync::resolve($validated['banner'] ?? null, $validated['icon'] ?? null);

        $eventoAttrs = [
            'id' => $eventId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? '',
            'date' => $isEstablishment ? null : $validated['date'],
            'start_time' => $isEstablishment ? null : $validated['startTime'],
            'end_time' => $isEstablishment ? null : $validated['endTime'],
            'location' => $validated['location'],
            'city_id' => $validated['cityId'],
            'cidade' => $validated['cidade'] ?? $cityLabels['cidade'],
            'estado' => $validated['estado'] ?? $cityLabels['estado'],
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'organizer_id' => $user->organizer_id,
            'banner' => $eventImage,
            'status' => $validated['status'] ?? 'draft',
            'capacity' => $validated['capacity'] ?? 0,
            'primary_color' => $primaryColor,
            'code' => $validated['code'] ?? null,
            'icon' => $eventImage,
        ];

        return DB::transaction(function () use ($eventoAttrs, $eventId, $primaryColor) {
            $evento = Evento::query()->create($eventoAttrs);

            $stallId = "stall-{$eventId}-default";
            $barraca = Barraca::query()->create([
                'id' => $stallId,
                'evento_id' => $eventId,
                'name' => 'Barraca Principal',
                'category' => 'comidas',
                'responsible' => 'Organizador',
                'color' => $primaryColor,
                'status' => 'open',
                'stock' => 100,
            ]);

            $offerings = $this->offeringWriteService->replaceForStall($evento, $barraca, [
                [
                    'productId' => 'item-boas-vindas',
                    'available' => true,
                    'variants' => [
                        ['templateId' => 'unidade', 'price' => 5, 'available' => true],
                    ],
                ],
            ]);

            return [
                'event' => $evento,
                'stalls' => [$barraca],
                'offerings' => $offerings,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Evento $evento, array $input): Evento
    {
        if ($evento->status === 'archived') {
            return $this->restoreArchived($evento, $input);
        }

        $validated = validator($input, $this->updateRules())->validate();

        $this->assertTypeImmutable($evento, $validated);

        $attrs = $this->mapToModelAttributes($validated, $evento);

        $this->eventImageStorage->deleteIfReplaced($evento, $attrs);

        if ($attrs !== []) {
            $evento->update($attrs);
        }

        return $evento->fresh();
    }

    /**
     * @return array{cidade: string, estado: string}
     */
    private function resolveCityLabels(string $cityId): array
    {
        $labels = CidadeResolver::labelsForCityId($cityId);

        if ($labels === null) {
            throw ValidationException::withMessages([
                'cityId' => ['Cidade invalida.'],
            ]);
        }

        return $labels;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function mapToModelAttributes(array $input, Evento $evento): array
    {
        $map = [
            'name' => 'name',
            'description' => 'description',
            'date' => 'date',
            'startTime' => 'start_time',
            'endTime' => 'end_time',
            'location' => 'location',
            'cityId' => 'city_id',
            'cidade' => 'cidade',
            'estado' => 'estado',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'banner' => 'banner',
            'status' => 'status',
            'capacity' => 'capacity',
            'primaryColor' => 'primary_color',
            'code' => 'code',
            'icon' => 'icon',
        ];

        $attrs = [];

        foreach ($map as $from => $to) {
            if (! array_key_exists($from, $input)) {
                continue;
            }

            $value = $input[$from];

            if (in_array($from, ['date', 'startTime', 'endTime', 'banner', 'icon'], true) && ($value === '' || $value === null)) {
                $attrs[$to] = null;
            } else {
                $attrs[$to] = $value;
            }
        }

        if (array_key_exists('cityId', $input)) {
            $labels = $this->resolveCityLabels($input['cityId']);
            $attrs['cidade'] = $labels['cidade'];
            $attrs['estado'] = $labels['estado'];
        }

        if (array_key_exists('cidade', $input) && ! array_key_exists('cityId', $input)) {
            $attrs['cidade'] = $input['cidade'];
        }

        if (array_key_exists('estado', $input) && ! array_key_exists('cityId', $input)) {
            $attrs['estado'] = $input['estado'];
        }

        if (array_key_exists('latitude', $input) || array_key_exists('longitude', $input)) {
            $this->assertCoordinatesForEvento($evento, $attrs);
        }

        $this->applySyncedEventImage($attrs, $input, $evento);

        return $attrs;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $input
     */
    private function applySyncedEventImage(array &$attrs, array $input, ?Evento $evento = null): void
    {
        if (! array_key_exists('banner', $input) && ! array_key_exists('icon', $input)) {
            return;
        }

        $banner = array_key_exists('banner', $input) ? $input['banner'] : $evento?->banner;
        $icon = array_key_exists('icon', $input) ? $input['icon'] : $evento?->icon;
        $image = EventImageSync::resolve($banner, $icon);

        $attrs['banner'] = $image;
        $attrs['icon'] = $image;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertCoordinatesForCreate(array $input, bool $isEstablishment): void
    {
        if ($isEstablishment) {
            return;
        }

        if (empty($input['latitude'] ?? null) || empty($input['longitude'] ?? null)) {
            throw ValidationException::withMessages([
                'latitude' => ['Evento com data exige coordenadas (latitude e longitude).'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function assertCoordinatesForEvento(Evento $evento, array $attrs): void
    {
        if ($evento->isEstabelecimento()) {
            return;
        }

        $latitude = $attrs['latitude'] ?? $evento->latitude;
        $longitude = $attrs['longitude'] ?? $evento->longitude;

        if ($latitude === null || $longitude === null) {
            throw ValidationException::withMessages([
                'latitude' => ['Evento com data exige coordenadas (latitude e longitude).'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function restoreArchived(Evento $evento, array $input): Evento
    {
        $keys = array_keys($input);

        if ($keys !== ['status'] || ($input['status'] ?? null) !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['Registro arquivado so pode ser restaurado para rascunho.'],
            ]);
        }

        $evento->update(['status' => 'draft']);

        return $evento->fresh();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertTypeFieldsForCreate(array $input, bool $isEstablishment): void
    {
        if ($isEstablishment) {
            foreach (['date', 'startTime', 'endTime'] as $field) {
                if (! empty($input[$field] ?? null)) {
                    throw ValidationException::withMessages([
                        $field => ['Estabelecimento nao pode ter data ou horario.'],
                    ]);
                }
            }

            return;
        }

        foreach (['date', 'startTime', 'endTime'] as $field) {
            if (empty($input[$field] ?? null)) {
                throw ValidationException::withMessages([
                    $field => ['Evento exige data e horario de inicio e fim.'],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertTypeImmutable(Evento $evento, array $input): void
    {
        if ($evento->isEstabelecimento()) {
            foreach (['date', 'startTime', 'endTime'] as $field) {
                if (array_key_exists($field, $input) && ! empty($input[$field])) {
                    throw ValidationException::withMessages([
                        $field => ['Estabelecimento nao pode receber data ou horario.'],
                    ]);
                }
            }

            return;
        }

        if (array_key_exists('date', $input) && empty($input['date'])) {
            throw ValidationException::withMessages([
                'date' => ['Evento nao pode perder a data.'],
            ]);
        }

        if (array_key_exists('startTime', $input) && empty($input['startTime'])) {
            throw ValidationException::withMessages([
                'startTime' => ['Evento exige horario de inicio.'],
            ]);
        }

        if (array_key_exists('endTime', $input) && empty($input['endTime'])) {
            throw ValidationException::withMessages([
                'endTime' => ['Evento exige horario de fim.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function isEstablishmentPayload(array $input): bool
    {
        $date = $input['date'] ?? null;

        return $date === null || $date === '';
    }

    /** @return array<string, mixed> */
    private function createRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'cityId' => ['required', 'string', 'max:255'],
            'location' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'date' => ['sometimes', 'nullable', 'date'],
            'startTime' => ['sometimes', 'nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'endTime' => ['sometimes', 'nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'banner' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', self::STATUSES)],
            'capacity' => ['sometimes', 'integer', 'min:0'],
            'primaryColor' => ['sometimes', 'string', 'max:32'],
            'code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cidade' => ['sometimes', 'nullable', 'string', 'max:255'],
            'estado' => ['sometimes', 'nullable', 'string', 'max:2'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /** @return array<string, mixed> */
    private function updateRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'date' => ['sometimes', 'nullable', 'date'],
            'startTime' => ['sometimes', 'nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'endTime' => ['sometimes', 'nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'location' => ['sometimes', 'string', 'max:255'],
            'cityId' => ['sometimes', 'string', 'max:255'],
            'cidade' => ['sometimes', 'nullable', 'string', 'max:255'],
            'estado' => ['sometimes', 'nullable', 'string', 'max:2'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'banner' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', self::STATUSES)],
            'capacity' => ['sometimes', 'integer', 'min:0'],
            'primaryColor' => ['sometimes', 'string', 'max:32'],
            'code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
