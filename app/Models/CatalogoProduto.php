<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogoProduto extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'catalogo_produtos';

    protected $fillable = [
        'id',
        'categoria_id',
        'name',
        'description',
        'image',
        'badge',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function variantTemplates(): HasMany
    {
        return $this->hasMany(VariantTemplate::class, 'catalogo_produto_id');
    }
}
