<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Document;
use Carbon\Carbon;

class AverageSalaryService
{
    /**
     * Calculate the average daily salary for the last 6 months.
     * Formula: Sum of Gross Salary (amount) / Sum of worked days (days) in those 6 months.
     *
     * @param Employee $employee
     * @param Carbon|null $targetDate The date from which to look backward (defaults to today)
     * @return float
     */
    public function calculateDailyAverage(Employee $employee, ?Carbon $targetDate = null): float
    {
        $targetDate = $targetDate ?? now();
        // Typically in Latvia, average is calculated from the preceding 6 full months.
        $sixMonthsAgo = $targetDate->copy()->subMonths(6)->startOfMonth();
        $endOfLastMonth = $targetDate->copy()->subMonth()->endOfMonth();

        $salaries = Document::where('employee_id', $employee->id)
            ->where('type', 'salary_calculation')
            ->whereBetween('date_from', [$sixMonthsAgo, $endOfLastMonth])
            ->get();

        $totalAmount = $salaries->sum('amount');
        $totalDays = $salaries->sum('days');

        if ($totalDays == 0) {
            // Fallback to the current base salary divided by an average of 21 working days.
            return $employee->alga ? round($employee->alga / 21, 4) : 0.0;
        }

        return round($totalAmount / $totalDays, 4);
    }
}
