<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Data\Payments\CardOnlineOrderRequest;
use App\Data\Payments\GatewayPaymentResult;
use App\Data\Payments\OnlineOrderRequest;
use App\Data\Payments\PixPaymentRequest;
use App\Data\Payments\QrOrderRequest;
use App\Models\CartaoSalvo;
use App\Models\Evento;
use App\Models\Oferta;
use App\Models\OfertaVariante;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\User;
use App\Support\Cpf;
use App\Support\MercadoPagoErrors;
use App\Support\MercadoPagoSandbox;
use Illuminate\Http\Client\RequestException;
use App\Support\ItemNameFormatter;
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
        private readonly CarteiraLedgerService $carteiraLedgerService,
        private readonly SavedCardService $savedCardService,
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
            'paymentMethodType' => ['nullable', 'string', 'in:credit_card,debit_card'],
            'cardholderName' => ['nullable', 'string', 'max:120'],
            'cardholderCpf' => ['nullable', 'string', 'max:14'],
            'installments' => ['nullable', 'integer', 'min:1'],
            'saveCard' => ['nullable', 'boolean'],
        ])->validate();

        $this->assertCreditCardPayload($validated);
        $this->assertGatewayProfile($user, $validated['paymentMethod']);

        $lines = $this->resolveLines($evento, $validated['items']);
        $total = $lines->sum(fn (array $line) => $line['unitPrice'] * $line['quantity']);
        $pedidoId = 'pedido-'.Str::lower((string) Str::ulid());
        $cardPaymentType = $this->resolveCardPaymentType($validated['paymentMethodType'] ?? null);
        $installments = $this->resolveInstallments((int) ($validated['installments'] ?? 1), $cardPaymentType);
        $saveCard = (bool) ($validated['saveCard'] ?? false);
        $usedSavedCard = ! empty($validated['cardId']);

        return DB::transaction(function () use ($user, $evento, $validated, $lines, $total, $pedidoId, $cardPaymentType, $installments, $saveCard, $usedSavedCard) {
            $payment = $this->processPayment(
                $user,
                $evento,
                $validated['paymentMethod'],
                $validated['cardId'] ?? null,
                $validated['cardToken'] ?? null,
                $validated['paymentMethodId'] ?? null,
                $cardPaymentType,
                $installments,
                $total,
                $pedidoId,
                $lines,
                $validated['cardholderName'] ?? null,
                $validated['cardholderCpf'] ?? null,
                $saveCard,
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
                'save_card' => $saveCard && ! $usedSavedCard,
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

                if ($saveCard && ! $usedSavedCard) {
                    $this->savedCardService->maybeSaveAfterPayment($user, true, false);
                }
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
                ->with(['variantes.variantTemplate', 'barraca', 'catalogoProduto.variantTemplates'])
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
            $availableVariantCount = $oferta->variantes->filter(fn (OfertaVariante $v) => $v->available)->count();
            $templateCount = $produto->variantTemplates->count();
            $itemName = ItemNameFormatter::format(
                $produto->name,
                $templateLabel,
                $availableVariantCount,
                $templateCount,
            );

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
        string $paymentMethodType,
        int $installments,
        float $total,
        string $idempotencyKey,
        Collection $lines,
        ?string $cardholderName = null,
        ?string $cardholderCpf = null,
        bool $saveCard = false,
    ): array {
        return match ($method) {
            'credit_card' => $this->processCreditCard(
                $user,
                $evento,
                $cardId,
                $cardToken,
                $paymentMethodId,
                $paymentMethodType,
                $installments,
                $total,
                $idempotencyKey,
                $lines,
                $cardholderName,
                $cardholderCpf,
                $saveCard,
            ),
            'wallet' => ['paymentStatus' => $this->processWallet($user, $total, $idempotencyKey)],
            'pix' => $this->processPix($user, $evento, $total, $idempotencyKey, $lines),
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
     * @param  Collection<int, array{variante: OfertaVariante, quantity: int, unitPrice: float, itemName: string, stallName: string, category: string, image: string}>  $lines
     * @return array{paymentStatus: string, gatewayPaymentId?: string|null, gatewayOrderId?: string|null}
     */
    private function processCreditCard(
        User $user,
        Evento $evento,
        ?string $cardId,
        ?string $cardToken,
        ?string $paymentMethodId,
        string $paymentMethodType,
        int $installments,
        float $total,
        string $idempotencyKey,
        Collection $lines,
        ?string $cardholderName = null,
        ?string $cardholderCpf = null,
        bool $saveCard = false,
    ): array {
        if ($cardToken !== null && $cardToken !== '') {
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
                    amount: $total,
                    externalReference: $this->sanitizeExternalReference($idempotencyKey),
                    payerEmail: $user->email,
                    token: $cardToken,
                    paymentMethodId: $paymentMethodId ?? 'visa',
                    installments: $installments,
                    paymentMethodType: $paymentMethodType,
                    payerName: $resolvedHolderName !== '' ? $resolvedHolderName : $user->name,
                    payerCpf: $resolvedCpf ?? $user->cpf,
                    mercadoPagoCustomerId: $mercadoPagoCustomerId,
                    description: 'Pedido FichAqui - '.$evento->name,
                    items: $this->buildMercadoPagoItems($lines),
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

        if ($this->paymentGateway->isConfigured()) {
            throw ValidationException::withMessages([
                'cardToken' => ['Informe cardToken (Mercado Pago.js).'],
            ]);
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
    private function processPix(User $user, Evento $evento, float $total, string $idempotencyKey, Collection $lines): array
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
                    amount: $total,
                    description: 'Pedido FichAqui',
                    externalReference: $this->sanitizeExternalReference($idempotencyKey),
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
                    description: 'Pedido FichAqui - '.$evento->name,
                    items: $this->buildMercadoPagoItems($lines),
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
     * @param  Collection<int, array{variante: OfertaVariante, quantity: int, unitPrice: float, itemName: string, stallName: string, category: string, image: string}>  $lines
     * @return list<array{title: string, unit_price: string, quantity: int}>
     */
    private function buildMercadoPagoItems(Collection $lines): array
    {
        return $lines
            ->map(fn (array $line) => [
                'title' => Str::limit((string) $line['itemName'], 150, ''),
                'unit_price' => number_format((float) $line['unitPrice'], 2, '.', ''),
                'quantity' => (int) $line['quantity'],
            ])
            ->values()
            ->take(10)
            ->all();
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
            'street_number' => '1',
            'neighborhood' => Str::limit($location ?? (string) ($defaults['neighborhood'] ?? 'Centro'), 60, ''),
            'city' => Str::upper(Str::limit($evento->cidade ?: (string) ($defaults['city'] ?? 'CURITIBA'), 60, '')),
            'state' => Str::upper(Str::limit($evento->estado ?: (string) ($defaults['state'] ?? 'PR'), 2, '')),
            'complement' => Str::limit($evento->name ?? (string) ($defaults['complement'] ?? ''), 60, ''),
        ];
    }

    private function formatMercadoPagoError(RequestException $exception): string
    {
        return MercadoPagoErrors::messageFromPayload($exception->response?->json());
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

    private function processWallet(User $user, float $total, string $pedidoId): string
    {
        $this->carteiraLedgerService->debitarCompra(
            pedidoId: $pedidoId,
            userId: $user->id,
            amount: $total,
        );

        return 'paid';
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
