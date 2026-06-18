<?php

namespace App\Support;

use Illuminate\Http\Request;

class MercadoPagoWebhookSignature
{
    /**
     * Valida x-signature conforme documentacao MP (HMAC-SHA256).
     *
     * @see https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks
     */
    public static function isValid(Request $request, string $secret): bool
    {
        $signatureHeader = (string) $request->header('x-signature', '');
        $requestId = (string) $request->header('x-request-id', '');

        if ($signatureHeader === '' || $secret === '') {
            return false;
        }

        $ts = self::extractPart($signatureHeader, 'ts');
        $v1 = self::extractPart($signatureHeader, 'v1');

        if ($ts === null || $v1 === null) {
            return false;
        }

        $dataId = self::normalizeDataId(
            (string) ($request->query('data.id') ?? $request->query('data_id') ?? '')
        );

        $parts = [];

        if ($dataId !== '') {
            $parts[] = 'id:'.$dataId;
        }

        if ($requestId !== '') {
            $parts[] = 'request-id:'.$requestId;
        }

        $parts[] = 'ts:'.$ts;

        $manifest = implode(';', $parts).';';
        $expected = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expected, $v1);
    }

    private static function extractPart(string $header, string $key): ?string
    {
        foreach (explode(',', $header) as $segment) {
            $segment = trim($segment);

            if (! str_starts_with($segment, $key.'=')) {
                continue;
            }

            $value = trim(substr($segment, strlen($key) + 1));

            return $value !== '' ? $value : null;
        }

        return null;
    }

    private static function normalizeDataId(string $dataId): string
    {
        $dataId = trim($dataId);

        if ($dataId === '') {
            return '';
        }

        if (preg_match('/^[A-Z0-9]+$/', $dataId) === 1) {
            return strtolower($dataId);
        }

        return $dataId;
    }
}
