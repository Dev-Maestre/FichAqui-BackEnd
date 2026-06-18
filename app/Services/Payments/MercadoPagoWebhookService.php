<?php

namespace App\Services\Payments;

use App\Models\Pedido;
use App\Services\PaymentSyncService;
use Illuminate\Support\Facades\Log;

class MercadoPagoWebhookService
{
    /** @var list<string> */
    private const SYNC_TOPICS = [
        'payment',
        'order',
        'orders',
    ];

    /** @var list<string> */
    private const INFORMATIONAL_TOPICS = [
        'stop_delivery_op_wh',
        'topic_card_id_wh',
        'topic_merchant_order_wh',
        'merchant_order',
        'shipment',
        'shipments',
        'topic_shipments_wh',
    ];

    public function __construct(
        private readonly PaymentSyncService $paymentSyncService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, ?string $queryDataId = null): ?Pedido
    {
        $topic = $this->resolveTopic($payload);
        $referenceId = $this->resolveReferenceId($payload, $queryDataId);
        $action = is_string($payload['action'] ?? null) ? $payload['action'] : null;

        Log::channel('single')->info('payments.webhook_received', [
            'topic' => $topic,
            'action' => $action,
            'reference_id' => $referenceId,
            'notification_id' => $payload['id'] ?? null,
        ]);

        if ($referenceId === null || $referenceId === '') {
            return null;
        }

        if ($this->shouldSync($topic)) {
            return $this->paymentSyncService->syncByGatewayPaymentId($referenceId);
        }

        if ($this->isInformational($topic)) {
            Log::channel('single')->info('payments.webhook_informational', [
                'topic' => $topic,
                'action' => $action,
                'reference_id' => $referenceId,
            ]);

            return null;
        }

        Log::channel('single')->info('payments.webhook_ignored', [
            'topic' => $topic,
            'action' => $action,
            'reference_id' => $referenceId,
        ]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveTopic(array $payload): ?string
    {
        $topic = $payload['type'] ?? $payload['topic'] ?? null;

        if (is_string($topic) && $topic !== '') {
            return strtolower($topic);
        }

        $action = $payload['action'] ?? null;

        if (is_string($action) && str_contains($action, '.')) {
            return strtolower(explode('.', $action, 2)[0]);
        }

        return is_string($action) && $action !== '' ? strtolower($action) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveReferenceId(array $payload, ?string $queryDataId): ?string
    {
        $fromQuery = is_string($queryDataId) ? trim($queryDataId) : '';
        if ($fromQuery !== '') {
            return $fromQuery;
        }

        $nested = data_get($payload, 'data.id');

        if (is_string($nested) && $nested !== '') {
            return $nested;
        }

        if (is_numeric($nested)) {
            return (string) $nested;
        }

        return null;
    }

    private function shouldSync(?string $topic): bool
    {
        return $topic !== null && in_array($topic, self::SYNC_TOPICS, true);
    }

    private function isInformational(?string $topic): bool
    {
        return $topic !== null && in_array($topic, self::INFORMATIONAL_TOPICS, true);
    }
}
