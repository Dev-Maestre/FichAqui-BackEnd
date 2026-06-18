<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Barraca extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'barracas';

    protected $fillable = [
        'id',
        'evento_id',
        'name',
        'category',
        'responsible',
        'color',
        'status',
        'stock',
    ];

    protected function casts(): array
    {
        return [
            'stock' => 'integer',
        ];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class, 'evento_id');
    }

    public function ofertas(): HasMany
    {
        return $this->hasMany(Oferta::class, 'barraca_id');
    }
}
