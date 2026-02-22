<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveTransaction extends Model
{
    protected $fillable = [
        'employee_id',
        'vacation_config_id',
        'transaction_type',
        'period_from',
        'period_to',
        'days_dd',
        'remaining_dd',
        'document_id',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'days_dd' => 'decimal:5',
            'remaining_dd' => 'decimal:5',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function vacationConfig()
    {
        return $this->belongsTo(VacationConfig::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
