<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Data\Payments\GatewaySavedCard;
use App\Models\CartaoSalvo;
use App\Models\User;
use Illuminate\Support\Str;
use App\Support\MercadoPagoSandbox;
use Illuminate\Validation\ValidationException;

class SavedCardService
{
    public const MAX_SAVED_CARDS = 5;

    public function __construct(
        private readonly PaymentGateway $paymentGateway,
    ) {}

    public function ensureMercadoPagoCustomer(User $user): string
    {
        MercadoPagoSandbox::assertPayerEmail($user);

        if (is_string($user->mercadopago_customer_id) && $user->mercadopago_customer_id !== '') {
            return $user->mercadopago_customer_id;
        }

        if (! $this->paymentGateway->isConfigured()) {
            throw ValidationException::withMessages([
                'cardToken' => ['Mercado Pago nao configurado no servidor (MP_ACCESS_TOKEN).'],
            ]);
        }

        $customerId = $this->paymentGateway->ensureCustomer(
            $user->email,
            $user->name,
            $user->cpf,
        );

        $user->forceFill(['mercadopago_customer_id' => $customerId])->save();

        return $customerId;
    }

    public function addFromToken(User $user, string $cardToken, string $paymentMethodId): CartaoSalvo
    {
        $this->assertCanAddCard($user);

        $customerId = $this->ensureMercadoPagoCustomer($user);

        $gatewayCard = $this->paymentGateway->addCustomerCard($customerId, $cardToken);

        if ($this->hasDuplicate($user, $gatewayCard->brand, $gatewayCard->lastFour)) {
            $this->paymentGateway->deleteCustomerCard($customerId, $gatewayCard->id);
            throw ValidationException::withMessages([
                'cardToken' => ['Este cartao ja esta cadastrado.'],
            ]);
        }

        return $this->persistGatewayCard($user, $gatewayCard);
    }

    public function delete(User $user, string $cardId): void
    {
        $cartao = CartaoSalvo::query()
            ->where('id', $cardId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if (
            $this->paymentGateway->isConfigured()
            && is_string($cartao->gateway_token)
            && $cartao->gateway_token !== ''
            && is_string($user->mercadopago_customer_id)
            && $user->mercadopago_customer_id !== ''
        ) {
            $this->paymentGateway->deleteCustomerCard($user->mercadopago_customer_id, $cartao->gateway_token);
        }

        $wasDefault = $cartao->is_default;
        $cartao->delete();

        if ($wasDefault) {
            $this->promoteOldestAsDefault($user->id);
        }
    }

    public function importNewCardsFromGateway(User $user): void
    {
        if (! $this->paymentGateway->isConfigured()) {
            return;
        }

        if (! is_string($user->mercadopago_customer_id) || $user->mercadopago_customer_id === '') {
            return;
        }

        if ($this->savedCardCount($user) >= self::MAX_SAVED_CARDS) {
            return;
        }

        $gatewayCards = $this->paymentGateway->listCustomerCards($user->mercadopago_customer_id);

        foreach ($gatewayCards as $gatewayCard) {
            if ($this->savedCardCount($user) >= self::MAX_SAVED_CARDS) {
                break;
            }

            if ($this->hasDuplicate($user, $gatewayCard->brand, $gatewayCard->lastFour)) {
                continue;
            }

            if (CartaoSalvo::query()
                ->where('user_id', $user->id)
                ->where('gateway_token', $gatewayCard->id)
                ->exists()) {
                continue;
            }

            $this->persistGatewayCard($user, $gatewayCard);
        }
    }

    public function findOwnedCard(User $user, string $cardId): CartaoSalvo
    {
        $cartao = CartaoSalvo::query()
            ->where('id', $cardId)
            ->where('user_id', $user->id)
            ->first();

        if ($cartao === null) {
            throw ValidationException::withMessages([
                'cardId' => ['Cartao nao pertence ao usuario.'],
            ]);
        }

        return $cartao;
    }

    public function maybeSaveAfterPayment(User $user, bool $saveCard, bool $usedSavedCard): void
    {
        if (! $saveCard || $usedSavedCard) {
            return;
        }

        $this->ensureMercadoPagoCustomer($user->fresh());
        $this->importNewCardsFromGateway($user->fresh());
    }

    private function persistGatewayCard(User $user, GatewaySavedCard $gatewayCard): CartaoSalvo
    {
        $isFirst = ! CartaoSalvo::query()->where('user_id', $user->id)->exists();

        return CartaoSalvo::query()->create([
            'id' => 'card-'.Str::lower((string) Str::ulid()),
            'user_id' => $user->id,
            'brand' => $gatewayCard->brand,
            'last_four' => $gatewayCard->lastFour,
            'holder_name' => $gatewayCard->holderName,
            'is_default' => $isFirst,
            'gateway_token' => $gatewayCard->id,
        ]);
    }

    private function assertCanAddCard(User $user): void
    {
        if (! $this->paymentGateway->isConfigured()) {
            throw ValidationException::withMessages([
                'cardToken' => ['Mercado Pago nao configurado no servidor (MP_ACCESS_TOKEN).'],
            ]);
        }

        if ($this->savedCardCount($user) >= self::MAX_SAVED_CARDS) {
            throw ValidationException::withMessages([
                'cardToken' => ['Limite de '.self::MAX_SAVED_CARDS.' cartoes salvos atingido.'],
            ]);
        }
    }

    private function hasDuplicate(User $user, string $brand, string $lastFour): bool
    {
        return CartaoSalvo::query()
            ->where('user_id', $user->id)
            ->where('brand', $brand)
            ->where('last_four', $lastFour)
            ->exists();
    }

    private function savedCardCount(User $user): int
    {
        return CartaoSalvo::query()->where('user_id', $user->id)->count();
    }

    private function promoteOldestAsDefault(int $userId): void
    {
        $next = CartaoSalvo::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->first();

        if ($next === null) {
            return;
        }

        CartaoSalvo::query()
            ->where('user_id', $userId)
            ->update(['is_default' => false]);

        $next->update(['is_default' => true]);
    }
}
