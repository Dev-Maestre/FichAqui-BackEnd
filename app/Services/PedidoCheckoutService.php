<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Data\Payments\CardPaymentRequest;
use App\Data\Payments\GatewayPaymentResult;
use App\Data\Payments\OnlineOrderRequest;
use App\Data\Payments\PixPaymentRequest;
use App\Data\Payments\QrOrderRequest;
use App\Models\CartaoSalvo;
use App\Models\Carteira;
use App\Models\Evento;
use App\Models\Oferta;
use App\Models\OfertaVariante;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PedidoCheckoutService
{
    /** @var list<string> */
    public const PAYMENT_METHODS = ['credit_card', 'pix', 'wallet'];

    public function __construct(
        private readonly FichaGenerationService $fichaGenerationService,
        private readonly PaymentGateway $paymentGateway,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function checkout(User $user, Evento $evento, array $input): Pedido
    {
        $validated = validator($input, [
            'items' => ['required', 'array', 'min:1'],
            'items.*.offeringId' => ['required', 'string'],
            'items.*.variantId' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'paymentMethod' => ['required', 'string', 'in:'.implode(',', self::PAYMENT_METHODS)],
            'cardId' => ['nullable', 'string'],
            'cardToken' => ['nullable', 'string'],
            'paymentMethodId' => ['nullable', 'string'],
            'installments' => ['nullable', 'integer', 'min:1'],
            'saveCard' => ['nullable', 'boolean'],
        ])->validate();

        $this->assertCreditCardPayload($validated);
        $this->assertGatewayProfile($user, $validated['paymentMethod']);

        $lines = $this->resolveLines($evento, $validated['items']);
        $total = $lines->sum(fn (array $line) => $line['unitPrice'] * $line['quantity']);
        $pedidoId = 'pedido-'.Str::lower((string) Str::ulid());

        return DB::transaction(function () use ($user, $evento, $validated, $lines, $total, $pedidoId) {
            $payment = $this->processPayment(
                $user,
                $evento,
                $validated['paymentMethod'],
                $validated['cardId'] ?? null,
                $validated['cardToken'] ?? null,
                $validated['paymentMethodId'] ?? null,
                (int) ($validated['installments'] ?? 1),
                $total,
                $pedidoId,
            );

            $orderStatus = $payment['paymentStatus'] === 'paid' ? 'available' : 'pending_payment';
            if ($payment['paymentStatus'] === 'failed') {
                $orderStatus = 'payment_failed';
            }

            $pedido = Pedido::query()->create([
                'id' => $pedidoId,
                'evento_id' => $evento->id,
                'user_id' => $user->id,
                'number' => (string) random_int(1000, 9999),
                'total' => $total,
                'status' => $orderStatus,
                'qr_code' => 'QR-PEDIDO-'.Str::upper(Str::random(8)),
                'payment_method' => $validated['paymentMethod'],
                'card_id' => $validated['cardId'] ?? null,
                'payment_status' => $payment['paymentStatus'],
                'gateway_payment_id' => $payment['gatewayPaymentId'] ?? null,
                'gateway_order_id' => $payment['gatewayOrderId'] ?? null,
                'pix_qr_code' => $payment['pixQrCode'] ?? null,
                'pix_copy_paste' => $payment['pixCopyPaste'] ?? null,
                'pix_expires_at' => $payment['pixExpiresAt'] ?? null,
            ]);

            $fichaLines = [];

            foreach ($lines as $line) {
                PedidoItem::query()->create([
                    'pedido_id' => $pedido->id,
                    'oferta_variante_id' => $line['variante']->id,
                    'quantity' => $line['quantity'],
                    'item_snapshot' => [
                        'name' => $line['itemName'],
                        'quantity' => $line['quantity'],
                        'stallName' => $line['stallName'],
                        'category' => $line['category'],
                        'image' => $line['image'],
                        'unitPrice' => $line['unitPrice'],
                    ],
                ]);

                $fichaLines[] = [
                    'ofertaVariante' => $line['variante'],
                    'quantity' => $line['quantity'],
                ];
            }

            if ($payment['paymentStatus'] === 'paid') {
                $this->fichaGenerationService->generateForPedido($pedido, $fichaLines);
            }

            return $pedido->fresh(['itens', 'fichas']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return Collection<int, array{variante: OfertaVariante, quantity: int, unitPrice: float, itemName: string, stallName: string, category: string, image: string}>
     */
    private function resolveLines(Evento $evento, array $items): Collection
    {
        return collect($items)->map(function (array $item, int $index) use ($evento) {
            $oferta = Oferta::query()
                ->with(['variantes.variantTemplate', 'barraca', 'catalogoProduto'])
                ->find($item['offeringId']);

            if (! $oferta || $oferta->evento_id !== $evento->id) {
                throw ValidationException::withMessages([
                    "items.{$index}.offeringId" => ['Oferta invalida para este evento.'],
                ]);
            }

            if (! $oferta->available) {
                throw ValidationException::withMessages([
                    "items.{$index}.offeringId" => ['Oferta indisponivel.'],
                ]);
            }

            $variante = $oferta->variantes->first(
                fn (OfertaVariante $v) => $v->variantTemplate->slug === $item['variantId']
            );

            if (! $variante || ! $variante->available) {
                throw ValidationException::withMessages([
                    "items.{$index}.variantId" => ['Variante indisponivel.'],
                ]);
            }

            $produto = $oferta->catalogoProduto;
            $templateLabel = $variante->variantTemplate->label;
            $itemName = $produto->name.' ? '.$templateLabel;

            return [
                'variante' => $variante,
                'quantity' => (int) $item['quantity'],
                'unitPrice' => (float) $variante->price,
                'itemName' => $itemName,
                'stallName' => $oferta->barraca->name,
                'category' => $produto->categoria_id,
                'image' => $produto->image,
            ];
        });
    }

    /**
     * @return array{paymentStatus: string, gatewayPaymentId?: string|null, pixQrCode?: string|null, pixCopyPaste?: string|null, pixExpiresAt?: string|null}
     */
    private function processPayment(
        User $user,
        Evento $evento,
        string $method,
        ?string $cardId,
        ?string $cardToken,
        ?string $paymentMethodId,
        int $installments,
        float $total,
        string $idempotencyKey,
    ): array {
        return match ($method) {
            'credit_card' => $this->processCreditCard(
                $user,
                $cardId,
                $cardToken,
                $paymentMethodId,
                $installments,
                $total,
                $idempotencyKey,
            ),
            'wallet' => ['paymentStatus' => $this->processWallet($user, $total)],
            'pix' => $this->processPix($user, $evento, $total, $idempotencyKey),
            default => throw ValidationException::withMessages([
                'paymentMethod' => ['Metodo de pagamento invalido.'],
            ]),
        };
    }

    private function assertGatewayProfile(User $user, string $method): void
    {
        if ($method === 'wallet') {
            return;
        }

        if (empty($user->cpf)) {
            throw ValidationException::withMessages([
                'cpf' => ['CPF obrigatorio antes do primeiro checkout via gateway (cartao ou PIX).'],
            ]);
        }
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
    }

    /**
     * @return array{paymentStatus: string, gatewayPaymentId?: string|null}
     */
    private function processCreditCard(
        User $user,
        ?string $cardId,
        ?string $cardToken,
        ?string $paymentMethodId,
        int $installments,
        float $total,
        string $idempotencyKey,
    ): array {
        if ($cardToken !== null && $cardToken !== '') {
            if (! $this->paymentGateway->isConfigured()) {
                throw ValidationException::withMessages([
                    'cardToken' => ['Mercado Pago nao configurado no servidor (MP_ACCESS_TOKEN).'],
                ]);
            }

            $result = $this->paymentGateway->createCardPayment(new CardPaymentRequest(
                idempotencyKey: $idempotencyKey,
                amount: $total,
                description: 'Pedido FichAqui',
                payerEmail: $user->email,
                token: $cardToken,
                installments: $installments,
                paymentMethodId: $paymentMethodId ?? 'visa',
            ));

            return $this->mapGatewayResult($result);
        }

        if ($cardId === null || $cardId === '') {
            throw ValidationException::withMessages([
                'cardId' => ['Cartao obrigatorio para pagamento com cartao.'],
            ]);
        }

        $ownsCard = CartaoSalvo::query()
            ->where('id', $cardId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $ownsCard) {
            abort(422, 'Cartao nao pertence ao usuario.');
        }

        return ['paymentStatus' => 'paid'];
    }

    /**
     * @return array{paymentStatus: string, gatewayPaymentId?: string|null, gatewayOrderId?: string|null, pixQrCode?: string|null, pixCopyPaste?: string|null, pixExpiresAt?: string|null}
     */
    private function processPix(User $user, Evento $evento, float $total, string $idempotencyKey): array
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
                    amount: $total,
                    description: 'Pedido FichAqui',
                    externalReference: $idempotencyKey,
                )),
                'payments' => $this->paymentGateway->createPixPayment(new PixPaymentRequest(
                    idempotencyKey: $idempotencyKey,
                    amount: $total,
                    description: 'Pedido FichAqui',
                    payerEmail: $user->email,
                )),
                default => $this->paymentGateway->createOnlinePixOrder(new OnlineOrderRequest(
                    idempotencyKey: $idempotencyKey,
                    amount: $total,
                    externalReference: $this->sanitizeExternalReference($idempotencyKey),
                    payerEmail: $user->email,
                    payerName: $user->name,
                    payerCpf: $user->cpf,
                    shipmentAddress: $this->shipmentAddressForEvento($evento),
                )),
            };
        } catch (RequestException $exception) {
            throw ValidationException::withMessages([
                'paymentMethod' => [$this->formatMercadoPagoError($exception)],
            ]);
        }

        return $this->mapGatewayResult($result, includePix: true);
    }

    private function sanitizeExternalReference(string $reference): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_-]/', '', $reference) ?? $reference;

        return Str::limit($sanitized !== '' ? $sanitized : $reference, 64, '');
    }

    /**
     * @return array{zip_code: string, street_name: string, street_number: string, neighborhood: string, city: string, state: string, complement?: string}
     */
    private function shipmentAddressForEvento(Evento $evento): array
    {
        $defaults = config('mercadopago.pix_shipment', []);
        $location = is_string($evento->location) && $evento->location !== '' ? $evento->location : null;

        return [
            'zip_code' => (string) ($defaults['zip_code'] ?? '80010000'),
            'street_name' => Str::limit($location ?? $evento->name ?? (string) ($defaults['street_name'] ?? 'Local do evento'), 80, ''),
            'street_number' => (string) ($defaults['street_number'] ?? 'S/N'),
            'neighborhood' => Str::limit($location ?? (string) ($defaults['neighborhood'] ?? 'Centro'), 60, ''),
            'city' => Str::upper(Str::limit($evento->cidade ?: (string) ($defaults['city'] ?? 'CURITIBA'), 60, '')),
            'state' => Str::upper(Str::limit($evento->estado ?: (string) ($defaults['state'] ?? 'PR'), 2, '')),
            'complement' => Str::limit($evento->name ?? (string) ($defaults['complement'] ?? ''), 60, ''),
        ];
    }

    private function formatMercadoPagoError(RequestException $exception): string
    {
        $response = $exception->response;
        $payload = $response?->json();

        if (is_array($payload)) {
            $errors = $payload['errors'] ?? null;

            if (is_array($errors) && $errors !== []) {
                $messages = collect($errors)
                    ->flatMap(function ($error) {
                        if (! is_array($error)) {
                            return [];
                        }

                        $parts = array_filter([
                            $error['message'] ?? null,
                            isset($error['details']) && is_array($error['details'])
                                ? implode('; ', array_map('strval', $error['details']))
                                : null,
                        ]);

                        return $parts !== [] ? [implode(': ', $parts)] : [];
                    })
                    ->filter()
                    ->values();

                if ($messages->isNotEmpty()) {
                    return 'Mercado Pago: '.$messages->implode(' | ');
                }
            }
        }

        return 'Mercado Pago recusou o pagamento PIX. Verifique credenciais, e-mail de teste (@testuser.com) e dados do pedido.';
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

    private function processWallet(User $user, float $total): string
    {
        $carteira = Carteira::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0],
        );

        if ((float) $carteira->balance < $total) {
            throw ValidationException::withMessages([
                'paymentMethod' => ['Saldo insuficiente na carteira.'],
            ]);
        }

        $carteira->update(['balance' => (float) $carteira->balance - $total]);

        return 'paid';
    }
}
