<?php

namespace App\Support;

use App\Models\Evento;
use App\Models\User;
use Illuminate\Http\Request;

class OrganizerAuthorization
{
    public static function ensureCanWrite(User $user): void
    {
        if (! in_array('organizer', $user->roles ?? [], true)) {
            abort(403, 'Acesso restrito a organizadores.');
        }

        if (! $user->organizer_id) {
            abort(403, 'Organizador sem organizer_id configurado.');
        }
    }

    public static function ensureOwns(User $user, Evento $evento): void
    {
        self::ensureCanWrite($user);

        if ($user->organizer_id !== $evento->organizer_id) {
            abort(403, 'Voce nao e o organizador deste evento.');
        }
    }

    public static function viewerOwns(?User $user, Evento $evento): bool
    {
        if (! $user) {
            return false;
        }

        return in_array('organizer', $user->roles ?? [], true)
            && $user->organizer_id === $evento->organizer_id;
    }

    public static function isOwnerListRequest(Request $request): bool
    {
        $organizerId = $request->string('organizer_id')->toString();
        $user = $request->user();

        if ($organizerId === '' || ! $user) {
            return false;
        }

        return in_array('organizer', $user->roles ?? [], true)
            && $user->organizer_id === $organizerId;
    }
}
