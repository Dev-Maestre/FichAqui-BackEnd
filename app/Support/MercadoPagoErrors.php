<?php

namespace App\Support;

class MercadoPagoErrors
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function messageFromPayload(?array $payload, string $fallback = 'Mercado Pago recusou o pagamento PIX.'): string
    {
        if (! is_array($payload)) {
            return $fallback;
        }

        $errors = $payload['errors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            $messages = collect($errors)
                ->flatMap(function ($error) {
                    if (! is_array($error)) {
                        return [];
                    }

                    $parts = [];

                    if (isset($error['message']) && is_string($error['message'])) {
                        $parts[] = $error['message'];
                    }

                    if (isset($error['details']) && is_array($error['details'])) {
                        foreach ($error['details'] as $detail) {
                            $parts[] = self::translateDetail((string) $detail);
                        }
                    }

                    return $parts !== [] ? [implode(' ', $parts)] : [];
                })
                ->filter()
                ->values();

            if ($messages->isNotEmpty()) {
                return 'Mercado Pago: '.$messages->implode(' | ');
            }
        }

        $paymentDetail = data_get($payload, 'transactions.payments.0.status_detail');

        if (is_string($paymentDetail) && $paymentDetail !== '') {
            return $fallback.' ('.self::translateDetail($paymentDetail).')';
        }

        $message = $payload['message'] ?? null;

        if (is_string($message) && $message !== '') {
            return 'Mercado Pago: '.$message;
        }

        return $fallback;
    }

    public static function translateDetail(string $detail): string
    {
        $code = strtolower($detail);

        if (str_contains($code, ':')) {
            $code = trim((string) preg_replace('/^[^:]+:\s*/', '', $code));
        }

        if (str_contains($code, 'invalid_email_for_sandbox')) {
            return 'E-mail invalido para sandbox: use um comprador de teste com @testuser.com (painel MP > Contas de teste).';
        }

        if (str_contains($code, 'invalid_identification')) {
            return 'CPF invalido para o Mercado Pago. Atualize o CPF do perfil ou use um CPF de teste valido.';
        }

        if (str_contains($code, 'invalid_user')) {
            return 'Usuario pagador invalido no Mercado Pago.';
        }

        return $detail;
    }
}
