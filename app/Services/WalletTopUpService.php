<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Data\Payments\GatewayPaymentResult;
use App\Data\Payments\OnlineOrderRequest;
use App\Data\Payments\PixPaymentRequest;
use App\Data\Payments\QrOrderRequest;
use App\Models\Carteira;
use App\Models\CarteiraRecarga;
use App\Models\User;
use App\Support\MercadoPagoErrors;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletTopUpService
{
    /** @var list<string> */
    public const PAYMENT_METHODS = ['pix'];

    public function __construct(
        private readonly PaymentGateway $paymentGateway,
        private readonly CarteiraLedgerService $carteiraLedgerService,
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
        ])->validate();

        $this->assertGatewayProfile($user);

        $amount = round((float) $validated['amount'], 2);
        $recargaId = 'recarga-'.Str::lower((string) Str::ulid());

        return DB::transaction(function () use ($user, $validated, $amount, $recargaId) {
            $carteira = Carteira::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0],
            );

            $payment = match ($validated['paymentMethod']) {
                'pix' => $this->processPix($user, $amount, $recargaId),
                default => throw ValidationException::withMessages([
                    'paymentMethod' => ['Metodo de pagamento invalido.'],
                ]),
            };

            $recarga = CarteiraRecarga::query()->create([
                'id' => $recargaId,
                'user_id' => $user->id,
                'amount' => $amount,
                'payment_method' => $validated['paymentMethod'],
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

        $this->assertSandboxPayerEmail($user);

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

    private function assertSandboxPayerEmail(User $user): void
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
}
