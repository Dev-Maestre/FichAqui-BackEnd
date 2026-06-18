<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'icon',
        'color',
    ];

    public function catalogoProdutos(): HasMany
    {
        return $this->hasMany(CatalogoProduto::class, 'categoria_id');
    }
}
