<?php

namespace App\Services\Payments;

class PaymentConfigService
{
    /**
     * @return array{enabled: bool, publicKey: ?string, sandbox: bool, locale: string}
     */
    public function clientConfig(): array
    {
        $publicKey = config('mercadopago.public_key');
        $publicKey = is_string($publicKey) && $publicKey !== '' ? $publicKey : null;

        return [
            'enabled' => $publicKey !== null,
            'publicKey' => $publicKey,
            'sandbox' => (bool) config('mercadopago.sandbox', true),
            'locale' => (string) config('mercadopago.locale', 'pt-BR'),
        ];
    }
}
