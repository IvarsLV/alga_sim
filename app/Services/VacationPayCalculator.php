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
     * @return float
     */
    public function calculateAmount(Employee $employee, VacationConfig $config, float $requestedDays): float
    {
        $rules = is_string($config->rules) ? json_decode($config->rules, true) : $config->rules;
        $formula = $rules['financial_formula'] ?? 'unpaid';

        if ($formula === 'average_salary') {
            $dailyAverage = $this->averageSalaryService->calculateDailyAverage($employee);
            return round($dailyAverage * $requestedDays, 2);
        }

        if ($formula === 'base_salary') {
            // Saglabāta mēnešalga - simplified: daily base = base / average 21 days
            $dailyBase = $employee->alga ? ($employee->alga / 21) : 0;
            return round($dailyBase * $requestedDays, 2);
        }

        // 'unpaid'
        return 0.00;
    }
}
