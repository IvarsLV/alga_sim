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
     * Calculate the current accrued vacation balance in days and return a detailed log.
     *
     * @param Employee $employee
     * @return array
     */
    /**
     * Calculate months worked using the ALGA ATVREZ_YMD algorithm.
     * Returns full completed months + proportional fractions of start/end months.
     * 
     * This mirrors the Firebird stored procedure ATVREZ_YMD + COUNT_APER logic:
     *   veselu_menesu_skaits = (y2*12 + m2) - (y1*12 + m1 + 1)
     *   dsakums = days_in_month(y1,m1) - d1 + 1  (remaining days of start month)
     *   dbeigas = d2  (elapsed days of end month)
     *   nepilni_menesi = dsakums/days_in_start_month + dbeigas/days_in_end_month
     *   total = veselu_menesu_skaits + nepilni_menesi
     */
    protected function calculateMonthsWorkedAtvrezYmd(Carbon $dateFrom, Carbon $dateTo): array
    {
        $y1 = (int) $dateFrom->year;
        $m1 = (int) $dateFrom->month;
        $d1 = (int) $dateFrom->day;
        $y2 = (int) $dateTo->year;
        $m2 = (int) $dateTo->month;
        $d2 = (int) $dateTo->day;

        $ym1 = $y1 * 12 + $m1 + 1; // first full month starts next month
        $ym2 = $y2 * 12 + $m2;     // last full month is the month before end month

        $veseluMenesuSkaits = $ym2 - $ym1;
        $skTmp = $veseluMenesuSkaits;
        if ($veseluMenesuSkaits < 0) {
            $veseluMenesuSkaits = 0;
        }

        $dsakums = 0;
        $dbeigas = 0;
        $nepilniMenesi = 0.0;

        if ($skTmp === -1) {
            // Both dates in the same month
            $dsakums = $d2 - $d1 + 1;
            if ($dsakums < 0) $dsakums = 0;
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $m1, $y1);
            $nepilniMenesi = $dsakums / $daysInMonth;
        } elseif ($skTmp > -1) {
            // Start month partial
            $daysInStartMonth = cal_days_in_month(CAL_GREGORIAN, $m1, $y1);
            $dsakums = $daysInStartMonth - $d1 + 1;
            $nepilniMenesi += $dsakums / $daysInStartMonth;

            // End month partial
            $daysInEndMonth = cal_days_in_month(CAL_GREGORIAN, $m2, $y2);
            $dbeigas = $d2;
            $nepilniMenesi += $dbeigas / $daysInEndMonth;
        }

        $totalMonths = $veseluMenesuSkaits + $nepilniMenesi;

        return [
            'totalMonths' => $totalMonths,
            'fullMonths' => $veseluMenesuSkaits,
            'partialMonths' => $nepilniMenesi,
            'dsakums' => $dsakums,
            'dbeigas' => $dbeigas,
        ];
    }

    public function getBalanceWithLog(Employee $employee): array
    {
        $log = [];

        // 1. Determine base date shifted by long unpaid leaves if any
        $shiftResult = $this->calculateShiftedBaseDate($employee);
        $baseDate = $shiftResult['date'];
        
        if (!$baseDate) {
            $log[] = "Nav atrasts 'Pieņemšana darbā' dokuments. Uzkrājums netiek rēķināts.";
            return ['balance' => 0.0, 'log' => $log];
        }

        $log[] = "Pieņemšana darbā: " . $baseDate->format('d.m.Y');

        if (!empty($shiftResult['log'])) {
            $log = array_merge($log, $shiftResult['log']);
        }

        // 2. Find the main accruable vacation config
        $accruableConfig = VacationConfig::where('is_accruable', true)->first();
        $yearlyNorm = $accruableConfig ? (float) $accruableConfig->norm_days : 20.0;
        $configName = $accruableConfig ? $accruableConfig->name : 'Ikgadējais apmaksātais atvaļinājums';
        
        $measureUnit = 'DD';
        if ($accruableConfig && $accruableConfig->rules) {
            $rules = is_string($accruableConfig->rules) ? json_decode($accruableConfig->rules, true) : $accruableConfig->rules;
            $measureUnit = $rules['measure_unit'] ?? 'DD';
        }
        
        if ($measureUnit === 'KD') {
            $yearlyNormKD = $yearlyNorm;
            $yearlyNormDD = $yearlyNorm * (20 / 28);
        } else {
            $yearlyNormDD = $yearlyNorm;
            $yearlyNormKD = $yearlyNorm * (28 / 20);
        }
        
        $monthlyRateDD = round($yearlyNormDD / 12, 5);
        $monthlyRateKD = round($yearlyNormKD / 12, 5);
        
        $log[] = "Bāzes norma: ".round($yearlyNormDD, 2)." DD / ".round($yearlyNormKD, 2)." KD gadā → ".round($monthlyRateDD, 4)." DD / ".round($monthlyRateKD, 4)." KD mēnesī ({$configName}).";

        // 3. Calculate the reference date = end of last completed month (like ALGA ATVREZ DATUMS parameter)
        $today = now();
        $referenceDate = $today->copy()->endOfMonth();
        // If today is not end of month, use end of previous month
        if ($today->day < $today->daysInMonth) {
            $referenceDate = $today->copy()->subMonth()->endOfMonth();
        }

        // 4. Calculate months worked using ATVREZ_YMD algorithm (from baseDate to referenceDate)
        $monthsResult = $this->calculateMonthsWorkedAtvrezYmd($baseDate, $referenceDate);
        $monthsWorked = $monthsResult['totalMonths'];
        
        $log[] = "Aprēķina datums (mēneša beigas): " . $referenceDate->format('d.m.Y');
        $log[] = "Nostrādāts periods: " . round($monthsWorked, 6) . " mēn. (" 
            . $monthsResult['fullMonths'] . " pilni + " 
            . round($monthsResult['partialMonths'], 6) . " nepilni).";

         // 5. Calculate accrual using base norm only (like ALGA: uzkrajums_menesi = dnorma/12)
        // ALGA: uzkrat_no_sakuma = COUNT_APER(uzkrat_no_datums, datums, atvalin_ilgums)
        $earnedBaseDD = round($monthsWorked * $monthlyRateDD, 5);
        $log[] = "Uzkrājums: ".round($monthlyRateDD, 4)." DD × " . round($monthsWorked, 6) . " mēn. = " . round($earnedBaseDD, 2) . " DD";

        $totalEarnedDD = $earnedBaseDD;

        // 6. Child extra days — shown as separate entitlement, NOT added to accrual rate
        $extraChildDaysDD = $this->childExtraVacationService->getExtraDays($employee);
        if ($extraChildDaysDD > 0) {
            $log[] = "[Bērni] Papildu {$extraChildDaysDD} DD/gadā (atsevišķa piešķīruma tiesības, nav ietverts uzkrājumā).";
        }

        // 6b. dienas_neuzkraj — subtract accrual for days when employee was on non-reserve leave
        // ALGA logic (BK_ATV_DIENAS): ONLY applies to vacation types with rezerve=0
        // (e.g. bezalgas atvaļinājums, NOT ikgadējais atvaļinājums which has rezerve=1)
        // In our model, this corresponds to configs with shifts_working_year=true
        $allNonReserveVacations = Document::where('employee_id', $employee->id)
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

        $dienasNeuzkrajKopa = 0.0;
        // Iterate through working year periods (from hire date to reference date)
        $periodStart = $baseDate->copy();
        $hireAnniversary = $baseDate->copy()->addYear()->subDay(); // end of first working year
        while ($hireAnniversary <= $periodStart) {
            $hireAnniversary = $hireAnniversary->addYear();
        }
        $periodEnd = $hireAnniversary;
        
        // Walk through yearly periods
        $loopBaseDate = $baseDate->copy();
        while ($loopBaseDate->lte($referenceDate)) {
            $currentPeriodEnd = $loopBaseDate->copy()->addYear()->subDay();
            if ($currentPeriodEnd->gt($referenceDate)) {
                $currentPeriodEnd = $referenceDate->copy();
            }
            
            foreach ($allNonReserveVacations as $doc) {
                $vacStart = Carbon::parse($doc->date_from);
                $vacEnd = Carbon::parse($doc->date_to);
                
                // Check if vacation overlaps with this working year period
                if ($vacEnd->lt($loopBaseDate) || $vacStart->gt($currentPeriodEnd)) {
                    continue;
                }
                
                // Clip to period boundaries
                $clippedStart = $vacStart->lt($loopBaseDate) ? $loopBaseDate->copy() : $vacStart->copy();
                $clippedEnd = $vacEnd->gt($currentPeriodEnd) ? $currentPeriodEnd->copy() : $vacEnd->copy();
                
                // Calculate proportional accrual for this vacation period using ATVREZ_YMD
                $vacMonthsResult = $this->calculateMonthsWorkedAtvrezYmd($clippedStart, $clippedEnd);
                $vacMonths = $vacMonthsResult['totalMonths'];
                $dienasNeuzkraj = round($vacMonths * $monthlyRateDD, 5);
                $dienasNeuzkrajKopa += $dienasNeuzkraj;
            }
            
            $loopBaseDate = $currentPeriodEnd->copy()->addDay();
        }

        if ($dienasNeuzkrajKopa > 0) {
            $totalEarnedDD = round($totalEarnedDD - $dienasNeuzkrajKopa, 5);
            $log[] = "Dienas, par kurām neuzkrāj (atvaļinājuma periods): -" . round($dienasNeuzkrajKopa, 2) . " DD";
        }

        $log[] = "Kopā uzkrāts: " . round($totalEarnedDD, 2) . " DD";

        // 7. Subtract used accruable days (count in DD only, like ALGA lmode_dd_vai_kd=5)
        $usedLeaveDocs = Document::where('employee_id', $employee->id)
            ->whereIn('type', ['vacation', 'unpaid_leave', 'study_leave'])
            ->get();

        $usedLog = [];
        $usedDaysDD = 0;
        foreach ($usedLeaveDocs as $doc) {
            $payload = is_string($doc->payload) ? json_decode($doc->payload, true) : $doc->payload;
            $configId = $payload['vacation_config_id'] ?? null;
            if ($configId) {
                $config = VacationConfig::find($configId);
                if ($config && $config->is_accruable) {
                    $start = \Carbon\Carbon::parse($doc->date_from);
                    $end = \Carbon\Carbon::parse($doc->date_to);
                    $kd = $start->diffInDays($end) + 1;
                    
                    $dd = 0;
                    $current = $start->copy();
                    while ($current->lte($end)) {
                        if (!$current->isWeekend()) $dd++;
                        $current->addDay();
                    }

                    $usedDaysDD += $dd;

                    $usedLog[] = "[-{$dd} DD / -{$kd} KD] - Izmantots '{$config->name}' (No {$doc->date_from} līdz {$doc->date_to}).";
                }
            }
        }

        if (count($usedLog) > 0) {
            $log = array_merge($log, $usedLog);
            $log[] = "Kopā izmantots: {$usedDaysDD} DD";
        }
        
        // 8. Final balance: DD first, then derive KD from DD (like ALGA: rezerve_kd = rezerve * 7/5)
        $finalBalanceDD = round($totalEarnedDD - $usedDaysDD, 2);
        $finalBalanceKD = round($finalBalanceDD * (7.0 / 5.0), 2);
        $log[] = "Atlikums: " . $finalBalanceDD . " DD / " . $finalBalanceKD . " KD (KD = DD × 7/5)";

        return [
            'balance' => $measureUnit === 'KD' ? $finalBalanceKD : $finalBalanceDD,
            'balanceDD' => $finalBalanceDD,
            'balanceKD' => $finalBalanceKD,
            'log' => $log,
            'unit' => $measureUnit
        ];
    }

    /**
     * Backward compatibility wrapper
     */
    public function getBalance(Employee $employee): float
    {
        return $this->getBalanceWithLog($employee)['balance'];
    }

    /**
     * Calculate if the base employment date must be shifted due to long unpaid leaves
     * Returns an array with 'date' and 'log'
     */
    protected function calculateShiftedBaseDate(Employee $employee): array
    {
        $log = [];
        $hireDoc = Document::where('employee_id', $employee->id)
            ->where('type', 'hire')
            ->orderBy('date_from', 'asc')
            ->first();

        if (!$hireDoc || !$hireDoc->date_from) {
            return ['date' => null, 'log' => []]; // No hire document found
        }

        $baseDate = Carbon::parse($hireDoc->date_from);

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
                $log[] = "[!] Bāzes datuma nobīde: Atvaļinājums > 4 ned. ({$doc->days} dienas). Nobīda par {$daysToShift} dienām. Jaunā bāze: " . $baseDate->format('d.m.Y');
            }
        }

        return ['date' => $baseDate, 'log' => $log];
    }
}
