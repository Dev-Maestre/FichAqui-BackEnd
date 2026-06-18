<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ficha extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'pedido_id',
        'oferta_variante_id',
        'qr_code',
        'status',
        'item_name',
        'item_image',
        'barraca_id',
        'barraca_name',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function ofertaVariante(): BelongsTo
    {
        return $this->belongsTo(OfertaVariante::class, 'oferta_variante_id');
    }

    public function barraca(): BelongsTo
    {
        return $this->belongsTo(Barraca::class, 'barraca_id');
    }

    public function scopeAvailableForUser(Builder $query, int $userId): Builder
    {
        return $query
            ->where('status', 'available')
            ->whereHas('pedido', fn (Builder $pedidoQuery) => $pedidoQuery->where('user_id', $userId));
    }
}
