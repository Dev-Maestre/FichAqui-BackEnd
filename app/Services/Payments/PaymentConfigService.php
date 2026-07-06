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

        $accessToken = config('mercadopago.access_token');
        $gatewayReady = is_string($accessToken) && $accessToken !== '';

        return [
            'enabled' => $publicKey !== null,
            'publicKey' => $publicKey,
            'sandbox' => (bool) config('mercadopago.sandbox', true),
            'locale' => (string) config('mercadopago.locale', 'pt-BR'),
            'cardEnabled' => $publicKey !== null,
            'pixEnabled' => $gatewayReady,
            'topUpEnabled' => $gatewayReady,
        ];
    }
}
