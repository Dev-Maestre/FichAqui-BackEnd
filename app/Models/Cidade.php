<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cidade extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'cidades';

    protected $fillable = [
        'id',
        'name',
        'state',
    ];
}
