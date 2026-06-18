<?php

namespace Tests\Unit;

use App\Data\Payments\CardPaymentRequest;
use App\Data\Payments\OnlineOrderRequest;
use App\Data\Payments\QrOrderRequest;
use App\Services\Payments\MercadoPagoGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoGatewayTest extends TestCase
{
    public function test_create_online_pix_order_sends_type_online_to_mercado_pago(): void
    {
        config([
            'mercadopago.access_token' => 'TEST-access-token',
            'mercadopago.api_base_url' => 'https://api.mercadopago.com',
        ]);

        Http::fake([
            'api.mercadopago.com/v1/orders' => Http::response([
                'id' => 'ORDONLINE01',
                'status' => 'action_required',
                'type' => 'online',
                'transactions' => [
                    'payments' => [
                        [
                            'id' => 'PAYONLINE01',
                            'status' => 'action_required',
                            'payment_method' => [
                                'id' => 'pix',
                                'qr_code' => '000201ONLINE',
                                'qr_code_base64' => 'b64online',
                            ],
                        ],
                    ],
                ],
            ], 201),
        ]);

        $gateway = new MercadoPagoGateway;

        $result = $gateway->createOnlinePixOrder(new OnlineOrderRequest(
            idempotencyKey: 'pedido-online-1',
            amount: 16.0,
            externalReference: 'pedido-online-1',
            payerEmail: 'test_user_123@testuser.com',
            payerName: 'Maria Silva',
            payerCpf: '52998224725',
            shipmentAddress: [
                'zip_code' => '80010000',
                'street_name' => 'Rua Teste',
                'street_number' => '100',
                'neighborhood' => 'Centro',
                'city' => 'CURITIBA',
                'state' => 'PR',
            ],
        ));

        $this->assertTrue($result->isPending());
        $this->assertSame('PAYONLINE01', $result->gatewayPaymentId);
        $this->assertSame('ORDONLINE01', $result->gatewayOrderId);
        $this->assertSame('000201ONLINE', $result->pix['copyPaste'] ?? null);
        $this->assertSame('b64online', $result->pix['qrCode'] ?? null);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Idempotency-Key', 'pedido-online-1')
                && $request['type'] === 'online'
                && $request['processing_mode'] === 'automatic'
                && isset($request['shipment']['address']['zip_code'])
                && $request['payer']['email'] === 'test_user_123@testuser.com'
                && $request['payer']['identification']['type'] === 'CPF'
                && $request['transactions']['payments'][0]['payment_method']['id'] === 'pix';
        });
    }

    public function test_create_online_pix_order_maps_402_to_validation_error(): void
    {
        config([
            'mercadopago.access_token' => 'TEST-access-token',
            'mercadopago.api_base_url' => 'https://api.mercadopago.com',
        ]);

        Http::fake([
            'api.mercadopago.com/v1/orders' => Http::response([
                'errors' => [
                    [
                        'code' => 'failed',
                        'message' => 'The following transactions failed',
                        'details' => ['PAY01TEST: invalid_email_for_sandbox'],
                    ],
                ],
            ], 402),
        ]);

        $gateway = new MercadoPagoGateway;

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->expectExceptionMessage('E-mail invalido para sandbox');

        $gateway->createOnlinePixOrder(new OnlineOrderRequest(
            idempotencyKey: 'pedido-online-402',
            amount: 10.0,
            externalReference: 'pedido-online-402',
            payerEmail: 'maria@testuser.com',
            shipmentAddress: [
                'zip_code' => '80010000',
                'street_name' => 'Rua Teste',
                'street_number' => '1',
                'neighborhood' => 'Centro',
                'city' => 'CURITIBA',
                'state' => 'PR',
            ],
        ));
    }

    public function test_create_qr_order_sends_dynamic_order_to_mercado_pago(): void
    {
        config([
            'mercadopago.access_token' => 'TEST-access-token',
            'mercadopago.api_base_url' => 'https://api.mercadopago.com',
            'mercadopago.qr_mode' => 'dynamic',
            'mercadopago.qr_expiration' => 'PT15M',
        ]);

        Http::fake([
            'api.mercadopago.com/v1/orders' => Http::response([
                'id' => 'ORD00001111',
                'status' => 'created',
                'type' => 'qr',
                'transactions' => [
                    'payments' => [
                        [
                            'id' => 'PAY01TEST',
                            'status' => 'created',
                            'status_detail' => 'ready_to_process',
                            'amount' => '16.00',
                        ],
                    ],
                ],
                'type_response' => [
                    'qr_data' => '00020101021243650016com.mercadolibre',
                ],
            ], 201),
        ]);

        $gateway = new MercadoPagoGateway;

        $result = $gateway->createQrOrder(new QrOrderRequest(
            idempotencyKey: 'pedido-test-qr-1',
            amount: 16.0,
            description: 'Pedido FichAqui',
            externalReference: 'pedido-test-qr-1',
        ));

        $this->assertTrue($result->isPending());
        $this->assertSame('PAY01TEST', $result->gatewayPaymentId);
        $this->assertSame('ORD00001111', $result->gatewayOrderId);
        $this->assertSame('00020101021243650016com.mercadolibre', $result->pix['copyPaste'] ?? null);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Idempotency-Key', 'pedido-test-qr-1')
                && $request['type'] === 'qr'
                && $request['total_amount'] === '16.00'
                && $request['config']['qr']['mode'] === 'dynamic';
        });
    }

    public function test_create_card_payment_sends_token_to_mercado_pago(): void
    {
        config([
            'mercadopago.access_token' => 'TEST-access-token',
            'mercadopago.api_base_url' => 'https://api.mercadopago.com',
        ]);

        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response([
                'id' => 12345,
                'status' => 'approved',
                'status_detail' => 'accredited',
            ], 201),
        ]);

        $gateway = new MercadoPagoGateway;

        $result = $gateway->createCardPayment(new CardPaymentRequest(
            idempotencyKey: 'pedido-test-1',
            amount: 16.0,
            description: 'Pedido FichAqui',
            payerEmail: 'maria@testuser.com',
            token: 'card-token-from-mp-js',
            installments: 1,
            paymentMethodId: 'visa',
        ));

        $this->assertTrue($result->isApproved());
        $this->assertSame('12345', $result->gatewayPaymentId);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Idempotency-Key', 'pedido-test-1')
                && $request['token'] === 'card-token-from-mp-js'
                && $request['transaction_amount'] === 16.0;
        });
    }
}
