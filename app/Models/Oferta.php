<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Oferta extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'evento_id',
        'barraca_id',
        'catalogo_produto_id',
        'available',
    ];

    protected function casts(): array
    {
        return [
            'available' => 'boolean',
        ];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class, 'evento_id');
    }

    public function barraca(): BelongsTo
    {
        return $this->belongsTo(Barraca::class, 'barraca_id');
    }

    public function catalogoProduto(): BelongsTo
    {
        return $this->belongsTo(CatalogoProduto::class, 'catalogo_produto_id');
    }

    public function variantes(): HasMany
    {
        return $this->hasMany(OfertaVariante::class, 'oferta_id');
    }

    public static function buildId(string $eventoId, string $barracaId, string $catalogoProdutoId): string
    {
        return "offering-{$eventoId}-{$barracaId}-{$catalogoProdutoId}";
    }
}
