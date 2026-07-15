<?php

namespace App\Services;

use App\Models\Barraca;
use App\Models\CartaoSalvo;
use App\Models\Carteira;
use App\Models\CarteiraRecarga;
use App\Models\CatalogoProduto;
use App\Models\Categoria;
use App\Models\Evento;
use App\Models\Ficha;
use App\Models\Oferta;
use App\Models\Pedido;
use App\Models\User;
use App\Support\AssetUrl;
use App\Support\EventImageSync;
use App\Support\ItemNameFormatter;
use App\Support\PrimaryRoleResolver;

use Illuminate\Support\Collection;

class FrontendPresenter
{
    public static function user(User $user): array
    {
        $eventId = null;

        if ($user->stall_id) {
            $eventId = Barraca::query()->where('id', $user->stall_id)->value('evento_id');
        }

        return [
            'id' => $user->external_id ?? (string) $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'phone' => $user->phone,
            'cpf' => $user->cpf,
            'birthDate' => $user->birth_date?->format('Y-m-d'),
            'role' => PrimaryRoleResolver::resolve($user->roles ?? []),
            'roles' => $user->roles ?? [],
            'organizerId' => $user->organizer_id,
            'stallId' => $user->stall_id,
            'eventId' => $eventId,
        ];
    }

    public static function evento(Evento $evento): array
    {
        $image = self::asset(EventImageSync::resolve($evento->banner, $evento->icon));

        return [
            'id' => $evento->id,
            'name' => $evento->name,
            'description' => $evento->description,
            'date' => $evento->date?->format('Y-m-d') ?? '',
            'startTime' => $evento->start_time ?? '',
            'endTime' => $evento->end_time ?? '',
            'location' => $evento->location,
            'cityId' => $evento->city_id,
            'cidade' => $evento->cidade,
            'estado' => $evento->estado,
            'latitude' => $evento->latitude !== null ? (float) $evento->latitude : null,
            'longitude' => $evento->longitude !== null ? (float) $evento->longitude : null,
            'organizerId' => $evento->organizer_id,
            'banner' => $image,
            'status' => $evento->status,
            'capacity' => $evento->capacity,
            'primaryColor' => $evento->primary_color,
            'code' => $evento->code,
            'icon' => $image,
            'isEstablishment' => $evento->isEstabelecimento(),
        ];
    }

    public static function categoria(Categoria $categoria): array
    {
        return [
            'id' => $categoria->id,
            'name' => $categoria->name,
            'icon' => $categoria->icon,
            'color' => $categoria->color,
        ];
    }

    public static function catalogProduct(CatalogoProduto $produto): array
    {
        $produto->loadMissing('variantTemplates');

        $payload = [
            'id' => $produto->id,
            'name' => $produto->name,
            'description' => $produto->description,
            'category' => $produto->categoria_id,
            'image' => self::asset($produto->image),
            'variantTemplates' => $produto->variantTemplates
                ->map(fn ($template) => [
                    'id' => $template->slug,
                    'label' => $template->label,
                ])
                ->values()
                ->all(),
        ];

        if ($produto->badge !== null) {
            $payload['badge'] = $produto->badge;
        }

        return $payload;
    }

    public static function wallet(Carteira $carteira, Collection $cartoes): array
    {
        return [
            'balance' => (float) $carteira->balance,
            'savedCards' => $cartoes
                ->map(fn (CartaoSalvo $cartao) => self::savedCard($cartao))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{balance: float, payment: array<string, mixed>}
     */
    public static function walletTopUpResult(Carteira $carteira, CarteiraRecarga $recarga): array
    {
        return [
            'balance' => (float) $carteira->balance,
            'payment' => self::walletTopUpPayment($recarga),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function walletTopUpPaymentStatus(CarteiraRecarga $recarga): array
    {
        return self::walletTopUpPayment($recarga);
    }

    /**
     * @return array<string, mixed>
     */
    public static function walletTopUpPayment(CarteiraRecarga $recarga): array
    {
        $payload = [
            'paymentId' => $recarga->gateway_payment_id ?? $recarga->gateway_order_id ?? $recarga->id,
            'gatewayOrderId' => $recarga->gateway_order_id,
            'status' => self::normalizePaymentStatusForFrontend($recarga->payment_status),
            'method' => $recarga->payment_method,
            'topUpId' => $recarga->id,
            'amount' => (float) $recarga->amount,
        ];

        if ($recarga->payment_method === 'pix') {
            $payload['pix'] = [
                'qrCode' => $recarga->pix_qr_code,
                'copyPaste' => $recarga->pix_copy_paste,
                'expiresAt' => $recarga->pix_expires_at?->toIso8601String(),
            ];
            $payload['pixQrCode'] = $recarga->pix_qr_code;
            $payload['pixCopyPaste'] = $recarga->pix_copy_paste;
            $payload['pixExpiresAt'] = $recarga->pix_expires_at?->toIso8601String();
        }

        return $payload;
    }

    private static function normalizePaymentStatusForFrontend(string $status): string
    {
        return match ($status) {
            'paid', 'approved' => 'approved',
            'failed', 'rejected', 'payment_failed' => 'rejected',
            default => 'pending',
        };
    }

    public static function savedCard(CartaoSalvo $cartao): array
    {
        $payload = [
            'id' => $cartao->id,
            'brand' => $cartao->brand,
            'lastFour' => $cartao->last_four,
            'holderName' => $cartao->holder_name,
            'isDefault' => $cartao->is_default,
        ];

        if (is_string($cartao->gateway_token) && $cartao->gateway_token !== '') {
            $payload['mercadoPagoCardId'] = $cartao->gateway_token;
        }

        return $payload;
    }

    public static function barraca(\App\Models\Barraca $barraca): array
    {
        return [
            'id' => $barraca->id,
            'eventId' => $barraca->evento_id,
            'name' => $barraca->name,
            'category' => $barraca->category,
            'responsible' => $barraca->responsible,
            'color' => $barraca->color,
            'status' => $barraca->status,
        ];
    }

    public static function offering(Oferta $oferta): array
    {
        $oferta->loadMissing(['variantes.variantTemplate']);

        $variants = $oferta->variantes->map(function ($variante) {
            $payload = [
                'templateId' => $variante->variantTemplate->slug,
                'price' => (float) $variante->price,
                'available' => $variante->available,
                'stock' => (int) $variante->stock,
            ];

            if ($variante->badge !== null) {
                $payload['badge'] = $variante->badge;
            }

            return $payload;
        })->values()->all();

        return [
            'id' => $oferta->id,
            'eventId' => $oferta->evento_id,
            'stallId' => $oferta->barraca_id,
            'productId' => $oferta->catalogo_produto_id,
            'available' => $oferta->available,
            'variants' => $variants,
        ];
    }

    public static function ficha(Ficha $ficha): array
    {
        return [
            'id' => $ficha->id,
            'orderId' => $ficha->pedido_id,
            'itemName' => ItemNameFormatter::normalizeLegacy($ficha->item_name),
            'itemImage' => self::asset($ficha->item_image),
            'stallId' => $ficha->barraca_id,
            'stallName' => $ficha->barraca_name,
            'qrCode' => $ficha->qr_code,
            'status' => $ficha->status,
        ];
    }

    public static function pedidoAdmin(Pedido $pedido): array
    {
        $pedido->loadMissing(['itens', 'fichas']);

        $fichaCounts = [
            'available' => $pedido->fichas->where('status', 'available')->count(),
            'delivered' => $pedido->fichas->where('status', 'delivered')->count(),
        ];

        return array_merge(
            self::pedido($pedido, summaryItems: true),
            ['fichaCounts' => $fichaCounts],
        );
    }

    public static function pedidoCheckout(Pedido $pedido): array
    {
        return array_merge(
            self::pedido($pedido, summaryItems: true, withFichas: true),
            self::paymentFields($pedido),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function paymentStatus(Pedido $pedido): array
    {
        $pedido->loadMissing('fichas');

        $payload = [
            'paymentId' => $pedido->gateway_payment_id,
            'gatewayOrderId' => $pedido->gateway_order_id,
            'status' => $pedido->payment_status,
            'method' => $pedido->payment_method,
            'orderId' => $pedido->id,
            'orderStatus' => $pedido->status,
            'fichas' => $pedido->fichas
                ->map(fn (Ficha $ficha) => self::ficha($ficha))
                ->values()
                ->all(),
        ];

        if ($pedido->payment_method === 'pix') {
            $payload['pix'] = [
                'qrCode' => $pedido->pix_qr_code,
                'copyPaste' => $pedido->pix_copy_paste,
                'expiresAt' => $pedido->pix_expires_at?->toIso8601String(),
            ];
        }

        return $payload;
    }

    public static function pedido(
        Pedido $pedido,
        bool $summaryItems = false,
        bool $withFichas = false,
    ): array {
        $pedido->loadMissing('itens');

        if ($withFichas) {
            $pedido->loadMissing('fichas');
        }

        $payload = [
            'id' => $pedido->id,
            'eventId' => $pedido->evento_id,
            'number' => $pedido->number,
            'items' => $summaryItems
                ? self::pedidoSummaryItems($pedido)
                : $pedido->itens->map(fn ($item) => [
                    'item' => $item->item_snapshot,
                    'quantity' => $item->quantity,
                ])->values()->all(),
            'total' => (float) $pedido->total,
            'status' => $pedido->status,
            'createdAt' => $pedido->created_at?->toIso8601String(),
            'qrCode' => $pedido->qr_code,
        ];

        if ($withFichas && $pedido->relationLoaded('fichas')) {
            $payload['fichas'] = $pedido->fichas
                ->map(fn (Ficha $ficha) => self::ficha($ficha))
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function paymentFields(Pedido $pedido): array
    {
        $fields = [
            'paymentMethod' => $pedido->payment_method,
            'paymentStatus' => $pedido->payment_status,
            'paymentId' => $pedido->gateway_payment_id,
            'gatewayOrderId' => $pedido->gateway_order_id,
        ];

        if ($pedido->payment_method === 'pix') {
            $fields['pixQrCode'] = $pedido->pix_qr_code;
            $fields['pixCopyPaste'] = $pedido->pix_copy_paste;
            $fields['pixExpiresAt'] = $pedido->pix_expires_at?->toIso8601String();
        }

        return $fields;
    }

    /**
     * @return list<array{name: string, quantity: int, stallName: string}>
     */
    private static function pedidoSummaryItems(Pedido $pedido): array
    {
        return $pedido->itens->map(function ($item) {
            $snapshot = $item->item_snapshot ?? [];

            if (isset($snapshot['name'], $snapshot['stallName'])) {
                return [
                    'name' => ItemNameFormatter::normalizeLegacy((string) $snapshot['name']),
                    'quantity' => $item->quantity,
                    'stallName' => $snapshot['stallName'],
                ];
            }

            $legacyItem = $snapshot['item'] ?? $snapshot;
            $name = is_array($legacyItem) ? ($legacyItem['name'] ?? 'Item') : 'Item';
            $stallName = is_array($legacyItem) ? ($legacyItem['stallId'] ?? '') : '';

            return [
                'name' => ItemNameFormatter::normalizeLegacy($name),
                'quantity' => $item->quantity,
                'stallName' => $stallName,
            ];
        })->values()->all();
    }

    public static function asset(?string $path): ?string
    {
        return AssetUrl::resolve($path);
    }
}
