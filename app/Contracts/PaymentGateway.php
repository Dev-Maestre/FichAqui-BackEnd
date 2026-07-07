<?php

namespace App\Contracts;

use App\Data\Payments\CardOnlineOrderRequest;
use App\Data\Payments\CardPaymentRequest;
use App\Data\Payments\GatewayPaymentResult;
use App\Data\Payments\GatewaySavedCard;
use App\Data\Payments\OnlineOrderRequest;
use App\Data\Payments\PixPaymentRequest;
use App\Data\Payments\QrOrderRequest;

interface PaymentGateway
{
    public function isConfigured(): bool;

    public function createOnlinePixOrder(OnlineOrderRequest $request): GatewayPaymentResult;

    public function createOnlineCardOrder(CardOnlineOrderRequest $request): GatewayPaymentResult;

    public function createQrOrder(QrOrderRequest $request): GatewayPaymentResult;

    public function createPixPayment(PixPaymentRequest $request): GatewayPaymentResult;

    public function createCardPayment(CardPaymentRequest $request): GatewayPaymentResult;

    public function getOrder(string $gatewayOrderId): GatewayPaymentResult;

    public function getPayment(string $gatewayPaymentId): GatewayPaymentResult;

    public function ensureCustomer(string $email, ?string $name = null, ?string $cpf = null): string;

    public function addCustomerCard(string $customerId, string $cardToken): GatewaySavedCard;

    public function listCustomerCards(string $customerId): array;

    public function deleteCustomerCard(string $customerId, string $cardId): void;
}
