<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartaoSalvo extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'cartoes_salvos';

    protected $fillable = [
        'id',
        'user_id',
        'brand',
        'last_four',
        'holder_name',
        'is_default',
        'gateway_token',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
