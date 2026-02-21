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
    /**
     * Calculate average daily salary and return it with a detailed log.
     *
     * @param Employee $employee
     * @param Carbon|null $targetDate 
     * @return array
     */
    public function calculateDailyAverageWithLog(Employee $employee, ?Carbon $targetDate = null): array
    {
        $log = [];
        $targetDate = $targetDate ?? now();
        
        $sixMonthsAgo = $targetDate->copy()->subMonths(6)->startOfMonth();
        $endOfLastMonth = $targetDate->copy()->subMonth()->endOfMonth();

        $salaries = Document::where('employee_id', $employee->id)
            ->where('type', 'salary_calculation')
            ->whereBetween('date_from', [$sixMonthsAgo, $endOfLastMonth])
            // Using order by to have chronological log
            ->orderBy('date_from', 'asc')
            ->get();

        $monthsFound = $salaries->isEmpty() ? 6 : $salaries->count();
        $log[] = "Analizē pēdējos {$monthsFound} pilnos kalendāra mēnešus (līdz {$endOfLastMonth->format('d.m.Y')}).";

        if ($salaries->isEmpty()) {
            $log[] = "Šajā periodā nav reģistrētu 'Algas aprēķins' dokumentu.";
            $log[] = "Vidējā izpeļņa = 0.00 EUR/dienā.";
            return ['average' => 0.0, 'log' => $log];
        }

        $totalAmount = 0;
        $totalDays = 0;

        foreach ($salaries as $sal) {
            $totalAmount += $sal->amount;
            $totalDays += $sal->days;
            $averageMonth = round($sal->amount / $sal->days, 4);
            $log[] = "{$sal->date_from->format('m.Y')}: {$sal->amount} EUR / {$sal->days} darba dienas = ".number_format($averageMonth, 4, '.', '')." EUR/dienā";
        }

        if ($totalDays == 0) {
            $log[] = "Reģistrēto dienu skaits ir 0, nevar dalīt.";
            $log[] = "Vidējā izpeļņa = 0.00 EUR/dienā.";
            return ['average' => 0.0, 'log' => $log];
        }

        $average = round($totalAmount / $totalDays, 4);
        $log[] = "Kopā: ".number_format($totalAmount, 2, '.', '')." EUR / {$totalDays} darba dienas = ".number_format($average, 4, '.', '')." EUR/dienā (vidējā dienas izpeļņa)";

        return [
            'average' => $average,
            'log' => $log,
        ];
    }

    /**
     * Backward compatibility wrapper
     */
    public function calculateDailyAverage(Employee $employee, ?Carbon $targetDate = null): float
    {
        return $this->calculateDailyAverageWithLog($employee, $targetDate)['average'];
    }
}
