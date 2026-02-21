<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\VacationConfig;

class VacationPayCalculator
{
    protected AverageSalaryService $averageSalaryService;

    public function __construct(AverageSalaryService $averageSalaryService)
    {
        $this->averageSalaryService = $averageSalaryService;
    }

    /**
     * Calculate vacation pay based on dynamic policy formulas from VacationConfig.
     *
     * @param Employee $employee
     * @param VacationConfig $config
     * @param float $requestedDays
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @return float
     */
    public function calculateAmount(Employee $employee, VacationConfig $config, float $requestedDays, ?string $dateFrom = null, ?string $dateTo = null): float
    {
        $rules = is_string($config->rules) ? json_decode($config->rules, true) : $config->rules;
        $formula = $rules['financial_formula'] ?? 'unpaid';
        $measureUnit = $rules['measure_unit'] ?? 'DD';

        $payableDays = $requestedDays;

        if ($measureUnit === 'KD' && $dateFrom && $dateTo) {
            $start = \Carbon\Carbon::parse($dateFrom);
            $end = \Carbon\Carbon::parse($dateTo);
            
            if ($start->lte($end)) {
                $payableDays = 0;
                $current = $start->copy();
                while ($current->lte($end)) {
                    if (!$current->isWeekend()) {
                        $payableDays++;
                    }
                    $current->addDay();
                }
            }
        }

        if ($formula === 'average_salary') {
            $dailyAverage = $this->averageSalaryService->calculateDailyAverage($employee);
            return round($dailyAverage * $payableDays, 2);
        }

        if ($formula === 'base_salary') {
            // Saglabāta mēnešalga - simplified: daily base = base / average 21 days
            $dailyBase = $employee->alga ? ($employee->alga / 21) : 0;
            return round($dailyBase * $payableDays, 2);
        }

        // 'unpaid'
        return 0.00;
    }
}
