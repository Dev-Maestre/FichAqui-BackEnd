<?php

namespace App\Support;

use App\Models\Evento;
use App\Models\Ficha;
use App\Models\User;

class FichaAuthorization
{
    public static function ensureCanConsume(User $user, Ficha $ficha): void
    {
        $ficha->loadMissing('pedido.evento');

        $evento = $ficha->pedido?->evento;
        if (! $evento) {
            abort(404);
        }

        if (in_array('organizer', $user->roles ?? [], true)
            && $user->organizer_id === $evento->organizer_id) {
            return;
        }

        if (in_array('stall_manager', $user->roles ?? [], true)) {
            if ($user->stall_id && $ficha->barraca_id !== $user->stall_id) {
                abort(403, 'Atendente so pode consumir fichas da sua barraca.');
            }

            return;
        }

        abort(403, 'Acesso restrito a organizadores ou atendentes de barraca.');
    }

    public static function ensureCanLookup(User $user, Evento $evento): void
    {
        if (in_array('organizer', $user->roles ?? [], true)
            && $user->organizer_id === $evento->organizer_id) {
            return;
        }

        if (in_array('stall_manager', $user->roles ?? [], true)) {
            if ($user->stall_id) {
                $ownsStall = $evento->barracas()->where('id', $user->stall_id)->exists();
                if (! $ownsStall) {
                    abort(403, 'Atendente so pode consultar fichas do seu evento.');
                }
            }

            return;
        }

        abort(403, 'Acesso restrito a organizadores ou atendentes de barraca.');
    }
}
