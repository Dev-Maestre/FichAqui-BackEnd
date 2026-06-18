<?php

namespace App\Data\Payments;

readonly class GatewayPaymentResult
{
    /**
     * @param  array<string, mixed>|null  $pix
     */
    public function __construct(
        public string $gatewayPaymentId,
        public string $status,
        public ?string $statusDetail = null,
        public ?array $pix = null,
        public ?array $raw = null,
        public ?string $gatewayOrderId = null,
    ) {}

    public function isApproved(): bool
    {
        return in_array($this->status, ['approved', 'paid'], true);
    }

    public function isPending(): bool
    {
        return in_array($this->status, [
            'pending',
            'in_process',
            'authorized',
            'created',
            'ready_to_process',
            'action_required',
            'waiting_transfer',
        ], true);
    }
}
