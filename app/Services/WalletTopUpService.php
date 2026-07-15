<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Data\Payments\CardOnlineOrderRequest;
use App\Data\Payments\GatewayPaymentResult;
use App\Data\Payments\OnlineOrderRequest;
use App\Data\Payments\PixPaymentRequest;
use App\Data\Payments\QrOrderRequest;
use App\Models\Carteira;
use App\Models\CarteiraRecarga;
use App\Models\User;
use App\Support\Cpf;
use App\Support\MercadoPagoErrors;
use App\Support\MercadoPagoSandbox;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletTopUpService
{
    /** @var list<string> */
    public const PAYMENT_METHODS = ['pix', 'credit_card'];

    public function __construct(
        private readonly PaymentGateway $paymentGateway,
        private readonly CarteiraLedgerService $carteiraLedgerService,
        private readonly SavedCardService $savedCardService,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{carteira: Carteira, recarga: CarteiraRecarga}
     */
    public function topUp(User $user, array $input): array
    {
        $validated = validator($input, [
            'amount' => ['required', 'numeric', 'min:1', 'max:10000'],
            'paymentMethod' => ['required', 'string', 'in:'.implode(',', self::PAYMENT_METHODS)],
            'cardId' => ['nullable', 'string'],
            'cardToken' => ['nullable', 'string'],
            'paymentMethodId' => ['nullable', 'string'],
            'paymentMethodType' => ['nullable', 'string', 'in:credit_card,debit_card'],
            'cardholderName' => ['nullable', 'string', 'max:120'],
            'cardholderCpf' => ['nullable', 'string', 'max:14'],
            'installments' => ['nullable', 'integer', 'min:1'],
            'saveCard' => ['nullable', 'boolean'],
        ])->validate();

        $this->assertCreditCardPayload($validated);
        $this->assertGatewayProfile($user);

        $amount = round((float) $validated['amount'], 2);
        $recargaId = 'recarga-'.Str::lower((string) Str::ulid());
        $saveCard = (bool) ($validated['saveCard'] ?? false);
        $usedSavedCard = ! empty($validated['cardId']);
        $cardPaymentType = $this->resolveCardPaymentType($validated['paymentMethodType'] ?? null);
        $installments = $this->resolveInstallments((int) ($validated['installments'] ?? 1), $cardPaymentType);

        return DB::transaction(function () use ($user, $validated, $amount, $recargaId, $saveCard, $usedSavedCard, $cardPaymentType, $installments) {
            $carteira = Carteira::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0],
            );

            $payment = match ($validated['paymentMethod']) {
                'pix' => $this->processPix($user, $amount, $recargaId),
                'credit_card' => $this->processCreditCard(
                    $user,
                    $validated['cardId'] ?? null,
                    $validated['cardToken'] ?? null,
                    $validated['paymentMethodId'] ?? null,
                    $cardPaymentType,
                    $installments,
                    $amount,
                    $recargaId,
                    $validated['cardholderName'] ?? null,
                    $validated['cardholderCpf'] ?? null,
                    $saveCard,
                ),
                default => throw ValidationException::withMessages([
                    'paymentMethod' => ['Metodo de pagamento invalido.'],
                ]),
            };

            $recarga = CarteiraRecarga::query()->create([
                'id' => $recargaId,
                'user_id' => $user->id,
                'amount' => $amount,
                'payment_method' => $validated['paymentMethod'],
                'save_card' => $saveCard && ! $usedSavedCard && $validated['paymentMethod'] === 'credit_card',
                'payment_status' => $payment['paymentStatus'],
                'gateway_payment_id' => $payment['gatewayPaymentId'] ?? null,
                'gateway_order_id' => $payment['gatewayOrderId'] ?? null,
                'pix_qr_code' => $payment['pixQrCode'] ?? null,
                'pix_copy_paste' => $payment['pixCopyPaste'] ?? null,
                'pix_expires_at' => $payment['pixExpiresAt'] ?? null,
            ]);

            if ($payment['paymentStatus'] === 'paid') {
                $this->carteiraLedgerService->creditarRecarga($recarga);
                $carteira->refresh();

                if ($saveCard && ! $usedSavedCard && $validated['paymentMethod'] === 'credit_card') {
                    $this->savedCardService->maybeSaveAfterPayment($user, true, false);
                }
            }

            return [
                'carteira' => $carteira,
                'recarga' => $recarga->fresh(),
            ];
        });
    }

    private function assertGatewayProfile(User $user): void
    {
        if (empty($user->cpf)) {
            throw ValidationException::withMessages([
                'cpf' => ['CPF obrigatorio antes do primeiro checkout via gateway (cartao ou PIX).'],
            ]);
        }
    }

    /**
     * @return array{paymentStatus: string, gatewayPaymentId?: string|null, gatewayOrderId?: string|null, pixQrCode?: string|null, pixCopyPaste?: string|null, pixExpiresAt?: string|null}
     */
    private function processPix(User $user, float $amount, string $idempotencyKey): array
    {
        if (! $this->paymentGateway->isConfigured()) {
            throw ValidationException::withMessages([
                'paymentMethod' => ['Mercado Pago nao configurado no servidor (MP_ACCESS_TOKEN).'],
            ]);
        }

        MercadoPagoSandbox::assertPayerEmail($user);

        $driver = $this->resolvePixDriver();

        try {
            $result = match ($driver) {
                'qr_pos', 'orders' => $this->paymentGateway->createQrOrder(new QrOrderRequest(
                    idempotencyKey: $idempotencyKey,
                    amount: $amount,
                    description: 'Recarga Carteira FichAqui',
                    externalReference: $this->sanitizeExternalReference($idempotencyKey),
                )),
                'payments' => $this->paymentGateway->createPixPayment(new PixPaymentRequest(
                    idempotencyKey: $idempotencyKey,
                    amount: $amount,
                    description: 'Recarga Carteira FichAqui',
                    payerEmail: $user->email,
                )),
                default => $this->paymentGateway->createOnlinePixOrder(new OnlineOrderRequest(
                    idempotencyKey: $idempotencyKey,
                    amount: $amount,
                    externalReference: $this->sanitizeExternalReference($idempotencyKey),
                    payerEmail: $user->email,
                    payerName: $user->name,
                    payerCpf: $user->cpf,
                    shipmentAddress: $this->defaultShipmentAddress(),
                    description: 'Recarga Carteira FichAqui',
                    items: [
                        [
                            'title' => 'Creditos FichAqui',
                            'unit_price' => number_format($amount, 2, '.', ''),
                            'quantity' => 1,
                        ],
                    ],
                )),
            };
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RequestException $exception) {
            throw ValidationException::withMessages([
                'paymentMethod' => [MercadoPagoErrors::messageFromPayload($exception->response?->json())],
            ]);
        }

        if ($result->isPending() && $result->pix === null) {
            $result = $this->awaitPixQrCode($result);
        }

        return $this->mapGatewayResult($result, includePix: true);
    }

    private function awaitPixQrCode(GatewayPaymentResult $result): GatewayPaymentResult
    {
        $maxAttempts = 4;
        $delayMicroseconds = 400_000;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            usleep($delayMicroseconds);

            $refreshed = match (true) {
                $result->gatewayOrderId !== null && $result->gatewayOrderId !== '' => $this->paymentGateway->getOrder($result->gatewayOrderId),
                $result->gatewayPaymentId !== null && $result->gatewayPaymentId !== '' => $this->paymentGateway->getPayment($result->gatewayPaymentId),
                default => null,
            };

            if ($refreshed === null) {
                break;
            }

            $result = $refreshed;

            if ($result->pix !== null || ! $result->isPending()) {
                break;
            }
        }

        return $result;
    }

    /**
     * @return array{zip_code: string, street_name: string, street_number: string, neighborhood: string, city: string, state: string, complement?: string}
     */
    private function defaultShipmentAddress(): array
    {
        $defaults = config('mercadopago.pix_shipment', []);

        return [
            'zip_code' => (string) ($defaults['zip_code'] ?? '80010000'),
            'street_name' => (string) ($defaults['street_name'] ?? 'FichAqui'),
            'street_number' => '1',
            'neighborhood' => (string) ($defaults['neighborhood'] ?? 'Centro'),
            'city' => Str::upper((string) ($defaults['city'] ?? 'CURITIBA')),
            'state' => Str::upper((string) ($defaults['state'] ?? 'PR')),
            'complement' => 'Recarga carteira',
        ];
    }

    private function sanitizeExternalReference(string $reference): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_-]/', '', $reference) ?? $reference;

        return Str::limit($sanitized !== '' ? $sanitized : $reference, 64, '');
    }

    private function resolvePixDriver(): string
    {
        $driver = (string) config('mercadopago.pix_driver', 'online');

        if (in_array($driver, ['qr_pos', 'orders'], true)) {
            $posId = config('mercadopago.qr_external_pos_id');

            return (is_string($posId) && $posId !== '') ? 'qr_pos' : 'online';
        }

        if ($driver === 'payments') {
            return 'payments';
        }

        return 'online';
    }

    /**
     * @return array{paymentStatus: string, gatewayPaymentId?: string|null, gatewayOrderId?: string|null, pixQrCode?: string|null, pixCopyPaste?: string|null, pixExpiresAt?: string|null}
     */
    private function mapGatewayResult(GatewayPaymentResult $result, bool $includePix = false): array
    {
        if ($result->isApproved()) {
            return [
                'paymentStatus' => 'paid',
                'gatewayPaymentId' => $result->gatewayPaymentId,
                'gatewayOrderId' => $result->gatewayOrderId,
            ];
        }

        if ($result->isPending()) {
            $payload = [
                'paymentStatus' => 'pending',
                'gatewayPaymentId' => $result->gatewayPaymentId,
                'gatewayOrderId' => $result->gatewayOrderId,
            ];

            if ($includePix && $result->pix !== null) {
                $payload['pixQrCode'] = $result->pix['qrCode'] ?? null;
                $payload['pixCopyPaste'] = $result->pix['copyPaste'] ?? null;
                $payload['pixExpiresAt'] = $result->pix['expiresAt'] ?? null;
            }

            return $payload;
        }

        throw ValidationException::withMessages([
            'paymentMethod' => ['Pagamento recusado pelo gateway: '.($result->statusDetail ?? $result->status)],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertCreditCardPayload(array $validated): void
    {
        if ($validated['paymentMethod'] !== 'credit_card') {
            return;
        }

        $hasCardId = ! empty($validated['cardId']);
        $hasCardToken = ! empty($validated['cardToken']);

        if (! $hasCardId && ! $hasCardToken) {
            throw ValidationException::withMessages([
                'cardToken' => ['Informe cardId (cartao salvo) ou cardToken (Mercado Pago.js).'],
            ]);
        }

        if ($hasCardToken) {
            $name = trim((string) ($validated['cardholderName'] ?? ''));
            $cpf = Cpf::digits($validated['cardholderCpf'] ?? null);
            $hasSavedCard = ! empty($validated['cardId']);

            if (! $hasSavedCard && $name === '') {
                throw ValidationException::withMessages([
                    'cardholderName' => ['Informe o nome do titular do cartao.'],
                ]);
            }

            if (! $hasSavedCard && ($cpf === null || ! Cpf::isValid($cpf))) {
                throw ValidationException::withMessages([
                    'cardholderCpf' => ['Informe um CPF valido do titular do cartao.'],
                ]);
            }
        }

        if ($hasCardId && ! $hasCardToken && $this->paymentGateway->isConfigured()) {
            throw ValidationException::withMessages([
                'cardToken' => ['Informe cardToken gerado a partir do cartao salvo.'],
            ]);
        }
    }

    /**
     * @return array{paymentStatus: string, gatewayPaymentId?: string|null, gatewayOrderId?: string|null}
     */
    private function processCreditCard(
        User $user,
        ?string $cardId,
        ?string $cardToken,
        ?string $paymentMethodId,
        string $paymentMethodType,
        int $installments,
        float $amount,
        string $idempotencyKey,
        ?string $cardholderName = null,
        ?string $cardholderCpf = null,
        bool $saveCard = false,
    ): array {
        if ($cardToken === null || $cardToken === '') {
            throw ValidationException::withMessages([
                'cardToken' => ['Informe cardToken (Mercado Pago.js).'],
            ]);
        }

        if (! $this->paymentGateway->isConfigured()) {
            throw ValidationException::withMessages([
                'cardToken' => ['Mercado Pago nao configurado no servidor (MP_ACCESS_TOKEN).'],
            ]);
        }

        $resolvedHolderName = trim((string) ($cardholderName ?? ''));
        $resolvedCpf = $cardholderCpf;

        if ($cardId !== null && $cardId !== '') {
            $cartao = $this->savedCardService->findOwnedCard($user, $cardId);

            if ($resolvedHolderName === '') {
                $resolvedHolderName = $cartao->holder_name;
            }

            if ($resolvedCpf === null || trim((string) $resolvedCpf) === '') {
                $resolvedCpf = $user->cpf;
            }
        }

        MercadoPagoSandbox::assertPayerEmail($user);

        $mercadoPagoCustomerId = null;

        if ($saveCard || ($cardId !== null && $cardId !== '')) {
            $mercadoPagoCustomerId = $this->savedCardService->ensureMercadoPagoCustomer($user);
        } elseif (
            MercadoPagoSandbox::shouldAttachCachedCustomerId()
            && is_string($user->mercadopago_customer_id)
            && $user->mercadopago_customer_id !== ''
        ) {
            $mercadoPagoCustomerId = $user->mercadopago_customer_id;
        }

        try {
            $result = $this->paymentGateway->createOnlineCardOrder(new CardOnlineOrderRequest(
                idempotencyKey: $idempotencyKey,
                amount: $amount,
                externalReference: $this->sanitizeExternalReference($idempotencyKey),
                payerEmail: $user->email,
                token: $cardToken,
                paymentMethodId: $paymentMethodId ?? 'visa',
                installments: $installments,
                paymentMethodType: $paymentMethodType,
                payerName: $resolvedHolderName !== '' ? $resolvedHolderName : $user->name,
                payerCpf: $resolvedCpf ?? $user->cpf,
                mercadoPagoCustomerId: $mercadoPagoCustomerId,
                description: 'Recarga Carteira FichAqui',
                items: [
                    [
                        'title' => 'Creditos FichAqui',
                        'unit_price' => number_format($amount, 2, '.', ''),
                        'quantity' => 1,
                    ],
                ],
            ));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RequestException $exception) {
            throw ValidationException::withMessages([
                'paymentMethod' => [MercadoPagoErrors::messageFromPayload($exception->response?->json())],
            ]);
        }

        return $this->mapGatewayResult($result);
    }

    private function resolveCardPaymentType(?string $type): string
    {
        return in_array($type, ['credit_card', 'debit_card'], true) ? $type : 'credit_card';
    }

    private function resolveInstallments(int $installments, string $paymentMethodType): int
    {
        if ($paymentMethodType === 'debit_card') {
            if ($installments !== 1) {
                throw ValidationException::withMessages([
                    'installments' => ['Cartao de debito aceita apenas pagamento a vista.'],
                ]);
            }

            return 1;
        }

        return max(1, $installments);
    }
}
