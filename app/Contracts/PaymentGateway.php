<?php

namespace App\Contracts;

use App\Data\Payments\CardPaymentRequest;
use App\Data\Payments\GatewayPaymentResult;
use App\Data\Payments\PixPaymentRequest;
use App\Data\Payments\QrOrderRequest;

interface PaymentGateway
{
    public function isConfigured(): bool;

    public function createQrOrder(QrOrderRequest $request): GatewayPaymentResult;

    public function createPixPayment(PixPaymentRequest $request): GatewayPaymentResult;

    public function createCardPayment(CardPaymentRequest $request): GatewayPaymentResult;

    public function getOrder(string $gatewayOrderId): GatewayPaymentResult;

    public function getPayment(string $gatewayPaymentId): GatewayPaymentResult;
}
