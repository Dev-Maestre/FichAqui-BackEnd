<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pedido extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'pedidos';

    protected $fillable = [
        'id',
        'evento_id',
        'user_id',
        'number',
        'total',
        'status',
        'qr_code',
        'payment_method',
        'card_id',
        'payment_status',
        'gateway_payment_id',
        'gateway_order_id',
        'pix_qr_code',
        'pix_copy_paste',
        'pix_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'float',
            'pix_expires_at' => 'datetime',
        ];
    }

    public function isPaymentConfirmed(): bool
    {
        return in_array($this->payment_status, ['paid', 'approved'], true);
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class, 'evento_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PedidoItem::class, 'pedido_id');
    }

    public function fichas(): HasMany
    {
        return $this->hasMany(Ficha::class, 'pedido_id');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
