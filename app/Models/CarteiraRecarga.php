<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarteiraRecarga extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'carteira_recargas';

    protected $fillable = [
        'id',
        'user_id',
        'amount',
        'payment_method',
        'payment_status',
        'gateway_payment_id',
        'gateway_order_id',
        'pix_qr_code',
        'pix_copy_paste',
        'pix_expires_at',
        'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'pix_expires_at' => 'datetime',
            'credited_at' => 'datetime',
        ];
    }

    public function isPaymentConfirmed(): bool
    {
        return in_array($this->payment_status, ['paid', 'approved'], true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
