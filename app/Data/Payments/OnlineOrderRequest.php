<?php

namespace App\Data\Payments;

readonly class OnlineOrderRequest
{
    public function __construct(
        public string $idempotencyKey,
        public float $amount,
        public string $externalReference,
        public string $payerEmail,
        public ?string $payerName = null,
    ) {}
}
