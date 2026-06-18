<?php

namespace App\Data\Payments;

readonly class OnlineOrderRequest
{
    /**
     * @param  array{zip_code: string, street_name: string, street_number: string, neighborhood: string, city: string, state: string, complement?: string|null}  $shipmentAddress
     * @param  list<array{title: string, unit_price: string, quantity: int, unit_measure?: string}>  $items
     */
    public function __construct(
        public string $idempotencyKey,
        public float $amount,
        public string $externalReference,
        public string $payerEmail,
        public ?string $payerName = null,
        public ?string $payerCpf = null,
        public array $shipmentAddress = [],
        public string $description = 'Pedido FichAqui',
        public array $items = [],
    ) {}
}
