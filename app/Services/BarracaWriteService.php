<?php

namespace App\Services;

use App\Models\Barraca;
use App\Models\Evento;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BarracaWriteService
{
    /** @var list<string> */
    public const STATUSES = ['open', 'closed'];

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(Evento $evento, array $input): Barraca
    {
        $validated = validator($input, $this->createRules())->validate();

        return Barraca::query()->create([
            'id' => $validated['id'] ?? 'stall-'.Str::lower((string) Str::ulid()),
            'evento_id' => $evento->id,
            'name' => $validated['name'],
            'category' => $validated['category'],
            'responsible' => $validated['responsible'],
            'color' => $validated['color'],
            'status' => $validated['status'] ?? 'open',
            'stock' => $validated['stock'] ?? 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Barraca $barraca, array $input): Barraca
    {
        $validated = validator($input, $this->updateRules())->validate();

        if ($validated === []) {
            throw ValidationException::withMessages([
                'body' => ['Nenhum campo valido para atualizacao.'],
            ]);
        }

        $attrs = [];

        foreach ([
            'name' => 'name',
            'category' => 'category',
            'responsible' => 'responsible',
            'color' => 'color',
            'status' => 'status',
            'stock' => 'stock',
        ] as $from => $to) {
            if (array_key_exists($from, $validated)) {
                $attrs[$to] = $validated[$from];
            }
        }

        $barraca->update($attrs);

        return $barraca->fresh();
    }

    /** @return array<string, mixed> */
    private function createRules(): array
    {
        return [
            'id' => ['sometimes', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:64'],
            'responsible' => ['required', 'string', 'max:255'],
            'color' => ['required', 'string', 'max:32'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', self::STATUSES)],
            'stock' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, mixed> */
    private function updateRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:64'],
            'responsible' => ['sometimes', 'string', 'max:255'],
            'color' => ['sometimes', 'string', 'max:32'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', self::STATUSES)],
            'stock' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
