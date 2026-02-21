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

        // 3. Find the main accruable vacation config
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
        
        $monthlyRateDD = $yearlyNormDD / 12;
        $monthlyRateKD = $yearlyNormKD / 12;
        
        $log[] = "Bāzes norma: ".round($yearlyNormDD, 2)." DD / ".round($yearlyNormKD, 2)." KD gadā → ".round($monthlyRateDD, 4)." DD / ".round($monthlyRateKD, 4)." KD mēnesī ({$configName}).";

        // 2. Calculate months worked since base date
        $monthsWorked = $baseDate->diffInMonths(now());
        $log[] = "Nostrādāti pilni mēneši: " . $monthsWorked;

        // 4. Check child extra days (DL 150./151. pants)
        // Extra days are part of the YEARLY NORM, not a separate accumulating bonus
        $extraChildDaysDD = $this->childExtraVacationService->getExtraDays($employee);
        
        // Add child extra days to the yearly norm → they accrue monthly like base vacation
        $effectiveYearlyNormDD = $yearlyNormDD + $extraChildDaysDD;
        $effectiveYearlyNormKD = $yearlyNormKD + $extraChildDaysDD; // child days counted same in KD
        $effectiveMonthlyRateDD = $effectiveYearlyNormDD / 12;
        $effectiveMonthlyRateKD = $effectiveYearlyNormKD / 12;

        if ($extraChildDaysDD > 0) {
            $log[] = "[Bērni] Papildu {$extraChildDaysDD} DD/gadā → efektīvā norma: {$effectiveYearlyNormDD} DD/gadā (" . round($effectiveMonthlyRateDD, 4) . " DD/mēn.).";
        }

        $earnedBaseDD = $monthsWorked * $effectiveMonthlyRateDD;
        $earnedBaseKD = $monthsWorked * $effectiveMonthlyRateKD;
        $log[] = "Uzkrājums: ".round($effectiveMonthlyRateDD, 4)." DD × {$monthsWorked} mēn. = " . round($earnedBaseDD, 2) . " DD (".round($earnedBaseKD, 2)." KD)";

        $totalEarnedDD = $earnedBaseDD;
        $totalEarnedKD = $earnedBaseKD;
        $log[] = "Kopā uzkrāts: " . round($totalEarnedDD, 2) . " DD / " . round($totalEarnedKD, 2) . " KD";

        // 5. Subtract used accruable days
        $usedLeaveDocs = Document::where('employee_id', $employee->id)
            ->whereIn('type', ['vacation', 'unpaid_leave', 'study_leave'])
            ->get();

        $usedLog = [];
        $usedDaysDD = 0;
        $usedDaysKD = 0;
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
                    $usedDaysKD += $kd;

                    $usedLog[] = "[-{$dd} DD / -{$kd} KD] - Izmantots '{$config->name}' (No {$doc->date_from} līdz {$doc->date_to}).";
                }
            }
        }

        if (count($usedLog) > 0) {
            $log = array_merge($log, $usedLog);
            $log[] = "Kopā izmantots: {$usedDaysDD} DD / {$usedDaysKD} KD";
        }
        
        $finalBalanceDD = round($totalEarnedDD - $usedDaysDD, 4);
        $finalBalanceKD = round($totalEarnedKD - $usedDaysKD, 4);
        $log[] = "Atlikums: " . round($finalBalanceDD, 2) . " DD / " . round($finalBalanceKD, 2) . " KD";

        return [
            'balance' => $measureUnit === 'KD' ? $finalBalanceKD : $finalBalanceDD,
            'balanceDD' => round($finalBalanceDD, 2),
            'balanceKD' => round($finalBalanceKD, 2),
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
