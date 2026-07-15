<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\MercadoPagoSandbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MercadoPagoSandboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_non_testuser_email_in_sandbox(): void
    {
        config(['mercadopago.sandbox' => true]);

        $user = User::factory()->create(['email' => 'real@gmail.com']);

        $this->expectException(ValidationException::class);

        MercadoPagoSandbox::assertPayerEmail($user);
    }

    public function test_skips_cached_customer_id_in_sandbox(): void
    {
        config(['mercadopago.sandbox' => true]);

        $this->assertFalse(MercadoPagoSandbox::shouldAttachCachedCustomerId());
    }

    public function test_allows_cached_customer_id_outside_sandbox(): void
    {
        config(['mercadopago.sandbox' => false]);

        $this->assertTrue(MercadoPagoSandbox::shouldAttachCachedCustomerId());
    }
}
