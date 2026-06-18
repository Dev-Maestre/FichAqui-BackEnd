<?php

namespace App\Data\Payments;

readonly class PixPaymentRequest
{
    public function __construct(
        public string $idempotencyKey,
        public float $amount,
        public string $description,
        public string $payerEmail,
    ) {}
}
