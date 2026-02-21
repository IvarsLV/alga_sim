<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Document;
use App\Models\VacationConfig;
use Carbon\Carbon;

class VacationBalanceService
{
    protected ChildExtraVacationService $childExtraVacationService;

    public function __construct(ChildExtraVacationService $childExtraVacationService)
    {
        $this->childExtraVacationService = $childExtraVacationService;
    }

    /**
     * Calculate the current accrued vacation balance in days.
     *
     * @param Employee $employee
     * @return float
     */
    public function getBalance(Employee $employee): float
    {
        // 1. Determine base date shifted by long unpaid leaves if any
        $baseDate = $this->calculateShiftedBaseDate($employee);
        
        // 2. Calculate months worked since base date
        $monthsWorked = $baseDate->diffInMonths(now());

        // 3. Find the main accruable vacation config
        $accruableConfig = VacationConfig::where('is_accruable', true)->first();
        $yearlyNorm = $accruableConfig ? (float) $accruableConfig->norm_days : 20.0;
        
        $monthlyRate = $yearlyNorm / 12;
        
        $earnedBaseDays = $monthsWorked * $monthlyRate;

        // 4. Add extra days for children (added per full working year)
        $extraChildDays = $this->childExtraVacationService->getExtraDays($employee);
        $fullYearsWorked = floor($monthsWorked / 12);
        
        $totalEarned = $earnedBaseDays + ($extraChildDays * $fullYearsWorked);

        // 5. Subtract used accruable days
        $usedDays = Document::where('employee_id', $employee->id)
            ->whereIn('type', ['vacation', 'unpaid_leave', 'study_leave']) // Any leave document type
            ->get()
            ->filter(function($doc) {
                $payload = is_string($doc->payload) ? json_decode($doc->payload, true) : $doc->payload;
                $configId = $payload['vacation_config_id'] ?? null;
                if ($configId) {
                    $config = VacationConfig::find($configId);
                    return $config && $config->is_accruable;
                }
                return false;
            })
            ->sum('days');

        return round($totalEarned - $usedDays, 4);
    }

    /**
     * Calculate if the base employment date must be shifted due to long unpaid leaves
     */
    protected function calculateShiftedBaseDate(Employee $employee): Carbon
    {
        $baseDate = Carbon::parse($employee->sakdatums);

        $shiftDocs = Document::where('employee_id', $employee->id)
            ->whereIn('type', ['vacation', 'unpaid_leave', 'study_leave'])
            ->get()
            ->filter(function($doc) {
                $payload = is_string($doc->payload) ? json_decode($doc->payload, true) : $doc->payload;
                $configId = $payload['vacation_config_id'] ?? null;
                if ($configId) {
                    $config = VacationConfig::find($configId);
                    if ($config) {
                        $rules = is_string($config->rules) ? json_decode($config->rules, true) : $config->rules;
                        return $rules['shifts_working_year'] ?? false;
                    }
                }
                return false;
            });

        foreach ($shiftDocs as $doc) {
            // Latvian rule: Unpaid leave > 4 weeks (28 days) shifts the year
            if ($doc->days > 28) {
                $daysToShift = $doc->days - 28;
                $baseDate->addDays($daysToShift);
            }
        }

        return $baseDate;
    }
}
