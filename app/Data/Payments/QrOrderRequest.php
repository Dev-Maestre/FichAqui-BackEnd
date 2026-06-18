<?php

namespace App\Data\Payments;

readonly class QrOrderRequest
{
    /**
     * @param  list<array{title: string, unit_price: string, quantity: int, unit_measure?: string}>  $items
     */
    public function __construct(
        public string $idempotencyKey,
        public float $amount,
        public string $description,
        public string $externalReference,
        public array $items = [],
    ) {}
}
