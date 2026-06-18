<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_payments_config_returns_disabled_when_public_key_missing(): void
    {
        config([
            'mercadopago.public_key' => null,
            'mercadopago.sandbox' => true,
            'mercadopago.locale' => 'pt-BR',
        ]);

        $this->getJson('/api/payments/config')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('publicKey', null)
            ->assertJsonPath('sandbox', true)
            ->assertJsonPath('locale', 'pt-BR');
    }

    public function test_payments_config_exposes_public_key_for_mercado_pago_js(): void
    {
        config([
            'mercadopago.public_key' => 'TEST-public-key-example',
            'mercadopago.sandbox' => true,
            'mercadopago.locale' => 'pt-BR',
        ]);

        $this->getJson('/api/payments/config')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('publicKey', 'TEST-public-key-example');
    }
}
