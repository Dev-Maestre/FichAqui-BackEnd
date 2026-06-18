<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Data\Payments\CardPaymentRequest;
use App\Data\Payments\GatewayPaymentResult;
use App\Data\Payments\OnlineOrderRequest;
use App\Data\Payments\PixPaymentRequest;
use App\Data\Payments\QrOrderRequest;
use App\Support\Cpf;
use App\Support\MercadoPagoErrors;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MercadoPagoGateway implements PaymentGateway
{
    public function isConfigured(): bool
    {
        return $this->accessToken() !== null && $this->accessToken() !== '';
    }

    public function createOnlinePixOrder(OnlineOrderRequest $request): GatewayPaymentResult
    {
        $this->ensureConfigured();

        $amount = $this->formatAmount($request->amount);
        $firstName = Str::before(trim($request->payerName ?? 'Consumidor'), ' ');
        $lastName = trim(Str::after(trim($request->payerName ?? ''), ' '));

        $payer = [
            'email' => $request->payerEmail,
            'first_name' => $firstName !== '' ? $firstName : 'Consumidor',
            'entity_type' => 'individual',
        ];

        if ($lastName !== '' && $lastName !== $firstName) {
            $payer['last_name'] = $lastName;
        }

        $cpf = Cpf::digits($request->payerCpf);

        if ($cpf !== null && Cpf::isValid($cpf)) {
            $payer['identification'] = [
                'type' => 'CPF',
                'number' => $cpf,
            ];
        }

        $shipmentAddress = $this->normalizeShipmentAddress(
            $request->shipmentAddress !== []
                ? $request->shipmentAddress
                : config('mercadopago.pix_shipment', [])
        );

        $body = [
            'type' => 'online',
            'external_reference' => Str::limit($request->externalReference, 64, ''),
            'description' => Str::limit($request->description, 150, ''),
            'total_amount' => $amount,
            'processing_mode' => 'automatic',
            'payer' => $payer,
            'shipment' => [
                'address' => $shipmentAddress,
            ],
            'transactions' => [
                'payments' => [
                    [
                        'amount' => $amount,
                        'expiration_time' => (string) config('mercadopago.pix_expiration', 'PT15M'),
                        'payment_method' => [
                            'id' => 'pix',
                            'type' => 'bank_transfer',
                        ],
                    ],
                ],
            ],
        ];

        if ($request->items !== []) {
            $body['items'] = array_slice($request->items, 0, 10);
        }

        return $this->postOrder($body, $request->idempotencyKey);
    }

    public function createQrOrder(QrOrderRequest $request): GatewayPaymentResult
    {
        $this->ensureConfigured();

        $amount = $this->formatAmount($request->amount);

        $body = [
            'type' => 'qr',
            'total_amount' => $amount,
            'description' => Str::limit($request->description, 150, ''),
            'external_reference' => Str::limit($request->externalReference, 64, ''),
            'expiration_time' => (string) config('mercadopago.qr_expiration', 'PT15M'),
            'config' => [
                'qr' => [
                    'mode' => (string) config('mercadopago.qr_mode', 'dynamic'),
                ],
            ],
            'transactions' => [
                'payments' => [
                    ['amount' => $amount],
                ],
            ],
        ];

        if ($posId = config('mercadopago.qr_external_pos_id')) {
            $body['config']['qr']['external_pos_id'] = (string) $posId;
        }

        if ($request->items !== []) {
            $body['items'] = $request->items;
        }

        return $this->postOrder($body, $request->idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function postOrder(array $body, string $idempotencyKey): GatewayPaymentResult
    {
        $response = $this->client($idempotencyKey)->post('/v1/orders', $body);

        $payload = $this->parseJsonResponse($response);

        return $this->mapOrderResponse($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonResponse(Response $response): array
    {
        if ($response->status() === 402 || $response->failed()) {
            throw ValidationException::withMessages([
                'paymentMethod' => [
                    MercadoPagoErrors::messageFromPayload($response->json()),
                ],
            ]);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'paymentMethod' => ['Resposta invalida do Mercado Pago.'],
            ]);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, string>
     */
    private function normalizeShipmentAddress(array $address): array
    {
        $zip = preg_replace('/\D+/', '', (string) ($address['zip_code'] ?? '80010000')) ?: '80010000';
        $zip = str_pad(substr($zip, 0, 8), 8, '0');

        $streetNumber = trim((string) ($address['street_number'] ?? '1'));
        if ($streetNumber === '' || ! preg_match('/^\d+$/', $streetNumber)) {
            $streetNumber = '1';
        }

        $state = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($address['state'] ?? 'PR')) ?: 'PR', 0, 2));

        $normalized = [
            'zip_code' => $zip,
            'street_name' => Str::limit((string) ($address['street_name'] ?? 'Local do evento'), 80, ''),
            'street_number' => $streetNumber,
            'neighborhood' => Str::limit((string) ($address['neighborhood'] ?? 'Centro'), 60, ''),
            'city' => strtoupper(Str::limit((string) ($address['city'] ?? 'CURITIBA'), 60, '')),
            'state' => $state !== '' ? $state : 'PR',
        ];

        $complement = trim((string) ($address['complement'] ?? ''));

        if ($complement !== '') {
            $normalized['complement'] = Str::limit($complement, 60, '');
        }

        return $normalized;
    }

    public function createPixPayment(PixPaymentRequest $request): GatewayPaymentResult
    {
        $this->ensureConfigured();

        $response = $this->client($request->idempotencyKey)->post('/v1/payments', [
            'transaction_amount' => round($request->amount, 2),
            'description' => $request->description,
            'payment_method_id' => 'pix',
            'payer' => [
                'email' => $request->payerEmail,
            ],
        ]);

        $payload = $this->parseJsonResponse($response);

        return $this->mapPaymentResponse($payload);
    }

    public function createCardPayment(CardPaymentRequest $request): GatewayPaymentResult
    {
        $this->ensureConfigured();

        $response = $this->client($request->idempotencyKey)->post('/v1/payments', [
            'transaction_amount' => round($request->amount, 2),
            'token' => $request->token,
            'description' => $request->description,
            'installments' => $request->installments,
            'payment_method_id' => $request->paymentMethodId,
            'payer' => [
                'email' => $request->payerEmail,
            ],
        ]);

        $response->throw();

        return $this->mapPaymentResponse($response->json());
    }

    public function getOrder(string $gatewayOrderId): GatewayPaymentResult
    {
        $this->ensureConfigured();

        $response = $this->client()->get('/v1/orders/'.urlencode($gatewayOrderId));
        $response->throw();

        return $this->mapOrderResponse($response->json());
    }

    public function getPayment(string $gatewayPaymentId): GatewayPaymentResult
    {
        $this->ensureConfigured();

        $response = $this->client()->get('/v1/payments/'.urlencode($gatewayPaymentId));
        $response->throw();

        return $this->mapPaymentResponse($response->json());
    }

    private function client(?string $idempotencyKey = null): PendingRequest
    {
        $client = Http::baseUrl(rtrim((string) config('mercadopago.api_base_url'), '/'))
            ->acceptJson()
            ->withToken((string) $this->accessToken());

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $client = $client->withHeaders([
                'X-Idempotency-Key' => $idempotencyKey,
            ]);
        }

        return $client;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function mapOrderResponse(?array $payload): GatewayPaymentResult
    {
        if ($payload === null) {
            throw new RuntimeException('Resposta vazia do Mercado Pago (orders).');
        }

        $payment = data_get($payload, 'transactions.payments.0', []);
        $paymentId = is_array($payment) ? (string) ($payment['id'] ?? '') : '';
        $paymentStatus = is_array($payment) ? (string) ($payment['status'] ?? 'created') : 'created';
        $paymentDetail = is_array($payment) ? ($payment['status_detail'] ?? null) : null;

        $orderStatus = (string) ($payload['status'] ?? 'created');
        $normalizedStatus = $this->normalizeOrderPaymentStatus($orderStatus, $paymentStatus);

        $pix = $this->extractPixFromOrderPayload($payload);

        return new GatewayPaymentResult(
            gatewayPaymentId: $paymentId !== '' ? $paymentId : (string) ($payload['id'] ?? ''),
            status: $normalizedStatus,
            statusDetail: is_string($paymentDetail) ? $paymentDetail : $orderStatus,
            pix: $pix,
            raw: $payload,
            gatewayOrderId: (string) ($payload['id'] ?? ''),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{qrCode: string|null, copyPaste: string, expiresAt: string|null}|null
     */
    private function extractPixFromOrderPayload(array $payload): ?array
    {
        $paymentMethod = data_get($payload, 'transactions.payments.0.payment_method', []);

        if (is_array($paymentMethod)) {
            $copyPaste = $paymentMethod['qr_code'] ?? null;

            if (is_string($copyPaste) && $copyPaste !== '') {
                $qrCode = $paymentMethod['qr_code_base64'] ?? null;

                return [
                    'qrCode' => is_string($qrCode) && $qrCode !== '' ? $qrCode : null,
                    'copyPaste' => $copyPaste,
                    'expiresAt' => $this->expirationFromOrder($payload),
                ];
            }
        }

        $qrData = data_get($payload, 'type_response.qr_data');

        if (is_string($qrData) && $qrData !== '') {
            return [
                'qrCode' => null,
                'copyPaste' => $qrData,
                'expiresAt' => $this->expirationFromOrder($payload),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function mapPaymentResponse(?array $payload): GatewayPaymentResult
    {
        if ($payload === null) {
            throw new RuntimeException('Resposta vazia do Mercado Pago (payments).');
        }

        $transactionData = data_get($payload, 'point_of_interaction.transaction_data', []);

        $pix = null;
        if (is_array($transactionData) && $transactionData !== []) {
            $pix = [
                'qrCode' => $transactionData['qr_code_base64'] ?? null,
                'copyPaste' => $transactionData['qr_code'] ?? null,
                'expiresAt' => data_get($payload, 'date_of_expiration'),
            ];
        }

        return new GatewayPaymentResult(
            gatewayPaymentId: (string) ($payload['id'] ?? ''),
            status: (string) ($payload['status'] ?? 'pending'),
            statusDetail: isset($payload['status_detail']) ? (string) $payload['status_detail'] : null,
            pix: $pix,
            raw: $payload,
        );
    }

    private function normalizeOrderPaymentStatus(string $orderStatus, string $paymentStatus): string
    {
        if ($paymentStatus === 'approved') {
            return 'approved';
        }

        if (in_array($orderStatus, ['canceled', 'cancelled', 'expired'], true)) {
            return 'rejected';
        }

        if (in_array($paymentStatus, ['rejected', 'cancelled', 'canceled'], true)) {
            return 'rejected';
        }

        if (in_array($paymentStatus, ['created', 'ready_to_process', 'in_process', 'pending', 'action_required', 'waiting_transfer'], true)) {
            return 'pending';
        }

        if (in_array($orderStatus, ['action_required', 'waiting_transfer', 'created'], true)
            && ! in_array($paymentStatus, ['rejected', 'cancelled', 'canceled', 'approved'], true)) {
            return 'pending';
        }

        if ($orderStatus === 'processed' && $paymentStatus === 'approved') {
            return 'approved';
        }

        return $paymentStatus !== '' ? $paymentStatus : 'pending';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function expirationFromOrder(array $payload): ?string
    {
        $created = $payload['created_date'] ?? null;
        $duration = $payload['expiration_time'] ?? null;

        if (! is_string($created) || ! is_string($duration) || ! str_starts_with($duration, 'PT')) {
            return null;
        }

        try {
            $expires = new \DateTimeImmutable($created);
            $interval = new \DateInterval($duration);

            return $expires->add($interval)->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    private function formatAmount(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }

    private function accessToken(): ?string
    {
        $token = config('mercadopago.access_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Mercado Pago nao configurado (MP_ACCESS_TOKEN ausente).');
        }
    }
}
