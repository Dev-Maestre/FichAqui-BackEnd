<?php

namespace App\Data\Payments;

readonly class CardOnlineOrderRequest
{
    /**
     * @param  list<array{title: string, unit_price: string, quantity: int}>  $items
     */
    public function __construct(
        public string $idempotencyKey,
        public float $amount,
        public string $externalReference,
        public string $payerEmail,
        public string $token,
        public string $paymentMethodId,
        public int $installments = 1,
        public string $paymentMethodType = 'credit_card',
        public ?string $payerName = null,
        public ?string $payerCpf = null,
        public string $description = 'Pedido FichAqui',
        public array $items = [],
    ) {}
}
