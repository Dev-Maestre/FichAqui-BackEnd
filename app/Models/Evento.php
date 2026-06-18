<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Evento extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'eventos';

    protected $fillable = [
        'id',
        'name',
        'description',
        'date',
        'start_time',
        'end_time',
        'location',
        'city_id',
        'cidade',
        'estado',
        'latitude',
        'longitude',
        'organizer_id',
        'banner',
        'status',
        'capacity',
        'primary_color',
        'code',
        'icon',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'capacity' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function isEstabelecimento(): bool
    {
        return $this->date === null;
    }

    public function barracas(): HasMany
    {
        return $this->hasMany(Barraca::class, 'evento_id');
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'evento_id');
    }
}
