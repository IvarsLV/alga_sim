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
    ];

    protected function casts(): array
    {
        return [
            'sakdatums' => 'date',
        ];
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function leaveTransactions()
    {
        return $this->hasMany(LeaveTransaction::class);
    }
}
