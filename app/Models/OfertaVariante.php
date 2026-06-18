<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfertaVariante extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'oferta_variantes';

    protected $fillable = [
        'id',
        'oferta_id',
        'variant_template_id',
        'price',
        'available',
        'badge',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'available' => 'boolean',
        ];
    }

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(Oferta::class, 'oferta_id');
    }

    public function variantTemplate(): BelongsTo
    {
        return $this->belongsTo(VariantTemplate::class, 'variant_template_id');
    }

    public function fichas(): HasMany
    {
        return $this->hasMany(Ficha::class, 'oferta_variante_id');
    }

    public static function buildId(string $ofertaId, string $templateSlug): string
    {
        return "{$ofertaId}-{$templateSlug}";
    }
}
