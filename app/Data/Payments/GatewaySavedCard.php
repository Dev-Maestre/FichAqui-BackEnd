<?php

namespace App\Data\Payments;

readonly class GatewaySavedCard
{
    public function __construct(
        public string $id,
        public string $brand,
        public string $lastFour,
        public string $holderName,
    ) {}
}
