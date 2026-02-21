<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'employee_id',
        'type',
        'date_from',
        'date_to',
        'days',
        'amount',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'days' => 'decimal:4',
            'amount' => 'decimal:2',
            'payload' => 'array',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
