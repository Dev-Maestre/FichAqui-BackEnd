<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class MercadoPagoSandbox
{
    public static function assertPayerEmail(User $user): void
    {
        if (! config('mercadopago.sandbox')) {
            return;
        }

        if (str_ends_with(strtolower($user->email), '@testuser.com')) {
            return;
        }

        throw ValidationException::withMessages([
            'paymentMethod' => [
                'No sandbox do Mercado Pago, use um e-mail de comprador de teste (@testuser.com). '
                .'Crie em Credenciais de teste > Contas de teste no painel MP.',
            ],
        ]);
    }

    public static function shouldAttachCachedCustomerId(): bool
    {
        return ! config('mercadopago.sandbox');
    }
}
