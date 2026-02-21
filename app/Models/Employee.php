<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = [
        'vards',
        'uzvards',
        'sakdatums',
        'amats',
        'nodala',
        'alga',
    ];

    protected function casts(): array
    {
        return [
            'sakdatums' => 'date',
            'alga' => 'decimal:2',
        ];
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }
}
