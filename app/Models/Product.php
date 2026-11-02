<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['embedding', 'search_text'];

    protected $casts = [
        'price' => 'decimal:2',
    ];
}
