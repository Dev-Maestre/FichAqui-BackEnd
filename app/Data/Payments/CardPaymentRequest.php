<?php

namespace App\Data\Payments;

readonly class CardPaymentRequest
{
    public function __construct(
        public string $idempotencyKey,
        public float $amount,
        public string $description,
        public string $payerEmail,
        public string $token,
        public int $installments = 1,
        public string $paymentMethodId = 'visa',
    ) {}
}
