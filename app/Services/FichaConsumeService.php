<?php

namespace App\Services;

use App\Models\Ficha;
use App\Models\Pedido;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FichaConsumeService
{
    public function consume(Ficha $ficha, string $status = 'delivered'): Ficha
    {
        if ($status !== 'delivered') {
            throw ValidationException::withMessages([
                'status' => ['Somente a transicao para delivered e suportada.'],
            ]);
        }

        return DB::transaction(function () use ($ficha, $status) {
            $locked = Ficha::query()
                ->whereKey($ficha->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'delivered') {
                return $locked;
            }

            $locked->update(['status' => $status]);

            $pedido = Pedido::query()
                ->whereKey($locked->pedido_id)
                ->lockForUpdate()
                ->firstOrFail();

            $pendingCount = Ficha::query()
                ->where('pedido_id', $pedido->id)
                ->where('status', '!=', 'delivered')
                ->count();

            if ($pendingCount === 0) {
                $pedido->update(['status' => 'delivered']);
            }

            return $locked->fresh();
        });
    }
}
