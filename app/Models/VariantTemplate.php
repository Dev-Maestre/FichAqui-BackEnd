<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VariantTemplate extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'catalogo_produto_id',
        'slug',
        'label',
    ];

    public function catalogoProduto(): BelongsTo
    {
        return $this->belongsTo(CatalogoProduto::class, 'catalogo_produto_id');
    }
}
