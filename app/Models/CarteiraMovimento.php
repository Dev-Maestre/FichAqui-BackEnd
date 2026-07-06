<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarteiraMovimento extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'carteira_movimentos';

    protected $fillable = [
        'id',
        'user_id',
        'direction',
        'tipo',
        'amount',
        'saldo_apos',
        'origem_tipo',
        'origem_id',
        'descricao',
        'idempotency_key',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'saldo_apos' => 'float',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
