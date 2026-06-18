<?php

namespace App\Data\Payments;

readonly class OnlineOrderRequest
{
    /**
     * @param  array{zip_code: string, street_name: string, street_number: string, neighborhood: string, city: string, state: string, complement?: string|null}  $shipmentAddress
     */
    public function __construct(
        public string $idempotencyKey,
        public float $amount,
        public string $externalReference,
        public string $payerEmail,
        public ?string $payerName = null,
        public ?string $payerCpf = null,
        public array $shipmentAddress = [],
    ) {}
}
