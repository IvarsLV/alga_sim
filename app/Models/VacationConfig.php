<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VacationConfig extends Model
{
    protected $fillable = [
        'tip',
        'name',
        'is_accruable',
        'norm_days',
        'rules',
    ];

    protected function casts(): array
    {
        return [
            'is_accruable' => 'boolean',
            'norm_days' => 'decimal:4',
            'rules' => 'array',
        ];
    }
}
