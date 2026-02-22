<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Document;
use App\Models\VacationConfig;
use App\Models\LeaveTransaction;
use Carbon\Carbon;

class LeaveAccrualService
{
    protected ChildExtraVacationService $childExtraService;

    public function __construct(ChildExtraVacationService $childExtraService)
    {
        $this->childExtraService = $childExtraService;
    }

    /**
     * Recalculate all leave balances for an employee (idempotent).
     * Deletes existing transactions and rebuilds from scratch.
     *
     * @return array [config_id => ['config' => ..., 'accrued' => ..., 'used' => ..., 'balance' => ..., 'transactions' => [...], 'algorithm' => [...] ]]
     */
    public function calculateAll(Employee $employee): array
    {
        $referenceDate = $this->getReferenceDate();
        $baseDate = $this->getHireDate($employee);

        if (!$baseDate) {
            return [];
        }

        // Clear existing transactions for recalculation
        LeaveTransaction::where('employee_id', $employee->id)->delete();

        $configs = VacationConfig::all();
        $results = [];

        foreach ($configs as $config) {
            $results[$config->id] = $this->calculateForType($employee, $config, $baseDate, $referenceDate);
        }

        return $results;
    }

    /**
     * Calculate accrual/usage/balance for a specific leave type.
     */
    protected function calculateForType(Employee $employee, VacationConfig $config, Carbon $baseDate, Carbon $referenceDate): array
    {
        $rules = is_string($config->rules) ? json_decode($config->rules, true) : ($config->rules ?? []);
        $tip = $config->tip;

        $transactions = [];
        $algorithm = [];

        switch ($tip) {
            case 1: // Ikgadējais atvaļinājums
                [$transactions, $algorithm] = $this->accrueIkgadejais($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            case 2: // Bērna kopšanas atvaļinājums
                [$transactions, $algorithm] = $this->accrueBernaKopsana($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            case 3: // Mācību atvaļinājums
                [$transactions, $algorithm] = $this->accrueMacibu($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            case 4: // Bezalgas atvaļinājums
                [$transactions, $algorithm] = $this->accrueBezalgas($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            case 5: // Papildatvaļinājums par bērniem
                [$transactions, $algorithm] = $this->accruePapildBerniem($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            case 6: // Grūtniecības un dzemdību atvaļinājums
                [$transactions, $algorithm] = $this->accrueGrutnieciba($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            case 7: // Paternitātes atvaļinājums
                [$transactions, $algorithm] = $this->accruePaternitates($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            case 10: // Asins donora diena
                [$transactions, $algorithm] = $this->accrueDonoraDiena($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            case 11: // Radošais atvaļinājums
                [$transactions, $algorithm] = $this->accrueRadosais($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            default:
                $algorithm[] = "Nav definēts algoritms šim tipam (tip={$tip}).";
        }

        // Process usage (consumption) for this type
        $usageTransactions = $this->processUsage($employee, $config, $baseDate, $referenceDate);
        $transactions = array_merge($transactions, $usageTransactions);

        // Calculate totals
        $totalAccrued = collect($transactions)->where('transaction_type', 'accrual')->sum('days_dd');
        $totalUsed = abs(collect($transactions)->where('transaction_type', 'usage')->sum('days_dd'));
        $balance = round($totalAccrued - $totalUsed, 2);

        // Save to DB
        foreach ($transactions as $t) {
            LeaveTransaction::create(array_merge($t, [
                'employee_id' => $employee->id,
                'vacation_config_id' => $config->id,
            ]));
        }

        return [
            'config' => $config,
            'accrued' => round($totalAccrued, 2),
            'used' => round($totalUsed, 2),
            'balance' => $balance,
            'balance_kd' => round($balance * (7.0 / 5.0), 2),
            'transactions' => $transactions,
            'algorithm' => $algorithm,
        ];
    }

    // =========================================================================
    // TYPE 1: IKGADĒJAIS ATVAĻINĀJUMS (DL 149)
    // =========================================================================
    protected function accrueIkgadejais(Employee $employee, VacationConfig $config, Carbon $baseDate, Carbon $referenceDate, array $rules): array
    {
        $transactions = [];
        $algorithm = [];

        $yearlyNormDD = (float) ($config->norm_days ?: 20);
        $monthlyRate = round($yearlyNormDD / 12, 5);

        $algorithm[] = "📋 **Ikgadējais apmaksātais atvaļinājums** (DL 149. pants)";
        $algorithm[] = "Norma: {$yearlyNormDD} DD/gadā → " . round($monthlyRate, 4) . " DD/mēnesī (norma ÷ 12)";
        $algorithm[] = "Uzkrāj no darba sākuma datuma: " . $baseDate->format('d.m.Y');
        $algorithm[] = "Aprēķina datums: " . $referenceDate->format('d.m.Y');

        // Calculate shifted base date (for bezalgas/bērna kopšanas that shift working year)
        $effectiveBaseDate = $this->getEffectiveBaseDate($employee, $baseDate, $referenceDate);
        if (!$effectiveBaseDate->eq($baseDate)) {
            $algorithm[] = "⚠️ Darba gads nobīdīts uz: " . $effectiveBaseDate->format('d.m.Y') . " (bezalgas/bērna kopšanas >4 nedēļas)";
        }

        // Calculate months worked using ATVREZ_YMD
        $monthsResult = $this->calculateMonthsWorkedAtvrezYmd($effectiveBaseDate, $referenceDate);
        $monthsWorked = $monthsResult['totalMonths'];

        $algorithm[] = "Nostrādāts: " . round($monthsWorked, 4) . " mēn. ({$monthsResult['fullMonths']} pilni + " . round($monthsResult['partialMonths'], 4) . " nepilni)";

        // Base accrual
        $earnedDD = round($monthsWorked * $monthlyRate, 5);
        $algorithm[] = "Uzkrājums: " . round($monthlyRate, 4) . " × " . round($monthsWorked, 4) . " = " . round($earnedDD, 2) . " DD";

        // Deduct dienas_neuzkraj for shifts_working_year leave
        $neuzkraj = $this->calculateDienasNeuzkraj($employee, $effectiveBaseDate, $referenceDate, $monthlyRate);
        if ($neuzkraj > 0) {
            $earnedDD = round($earnedDD - $neuzkraj, 5);
            $algorithm[] = "Neuzkrāj (atvaļ. periodi ar darba gada nobīdi): -" . round($neuzkraj, 2) . " DD";
        }

        $algorithm[] = "**Kopā uzkrāts: " . round($earnedDD, 2) . " DD**";

        // Create accrual transaction per working year
        $loopDate = $effectiveBaseDate->copy();
        $remainingEarned = $earnedDD;

        while ($loopDate->lt($referenceDate) && $remainingEarned > 0) {
            $yearEnd = $loopDate->copy()->addYear()->subDay();
            if ($yearEnd->gt($referenceDate)) {
                $yearEnd = $referenceDate->copy();
            }

            $yrMonths = $this->calculateMonthsWorkedAtvrezYmd($loopDate, $yearEnd);
            $yrEarned = round($yrMonths['totalMonths'] * $monthlyRate, 5);
            if ($yrEarned > $remainingEarned) $yrEarned = $remainingEarned;

            $transactions[] = [
                'transaction_type' => 'accrual',
                'period_from' => $loopDate->toDateString(),
                'period_to' => $yearEnd->toDateString(),
                'days_dd' => round($yrEarned, 5),
                'remaining_dd' => round($yrEarned, 5),
                'document_id' => null,
                'description' => "Darba gads " . $loopDate->format('d.m.Y') . " - " . $yearEnd->format('d.m.Y') . ": " . round($yrEarned, 2) . " DD",
            ];

            $remainingEarned -= $yrEarned;
            $loopDate = $yearEnd->copy()->addDay();
        }

        return [$transactions, $algorithm];
    }

    // =========================================================================
    // TYPE 5: PAPILDATVAĻINĀJUMS PAR BĒRNIEM (DL 150-151)
    // =========================================================================
    protected function accruePapildBerniem(Employee $employee, VacationConfig $config, Carbon $baseDate, Carbon $referenceDate, array $rules): array
    {
        $transactions = [];
        $algorithm = [];

        $algorithm[] = "📋 **Papildatvaļinājums par bērniem** (DL 150.-151. pants)";

        $extraDays = $this->childExtraService->getExtraDays($employee);

        if ($extraDays === 0) {
            $algorithm[] = "Nav reģistrētu bērnu vai nav tiesību uz papildatvaļinājumu.";
            return [$transactions, $algorithm];
        }

        $algorithm[] = "Piešķirtās dienas: {$extraDays} DD/gadā";
        $algorithm[] = $extraDays === 3
            ? "Pamats: 3+ bērni vai bērns invalīds (DL 151. pants)"
            : "Pamats: 1-2 bērni līdz 14 gadu vecumam (DL 150. pants)";
        $algorithm[] = "Piešķir par katru kalendāro gadu, kurā darbinieks strādā.";

        // Grant per calendar year the employee has been working
        $startYear = max($baseDate->year, $referenceDate->copy()->subYears(5)->year);
        $endYear = $referenceDate->year;

        for ($year = $startYear; $year <= $endYear; $year++) {
            $yearStart = Carbon::createFromDate($year, 1, 1);
            $yearEnd = Carbon::createFromDate($year, 12, 31);

            // Employee must be working during this year
            if ($baseDate->gt($yearEnd)) continue;
            if ($baseDate->gt($yearStart)) $yearStart = $baseDate->copy();

            $transactions[] = [
                'transaction_type' => 'accrual',
                'period_from' => $yearStart->toDateString(),
                'period_to' => $yearEnd->toDateString(),
                'days_dd' => $extraDays,
                'remaining_dd' => $extraDays,
                'document_id' => null,
                'description' => "Papildatvaļinājums {$year}. gadam: {$extraDays} DD (DL 150-151)",
            ];
        }

        $totalAccrued = $extraDays * ($endYear - $startYear + 1);
        $algorithm[] = "**Kopā uzkrāts: {$totalAccrued} DD** (par gadiem {$startYear}-{$endYear})";

        return [$transactions, $algorithm];
    }

    // =========================================================================
    // TYPE 2: BĒRNA KOPŠANAS ATVAĻINĀJUMS (DL 156)
    // =========================================================================
    protected function accrueBernaKopsana(Employee $employee, VacationConfig $config, Carbon $baseDate, Carbon $referenceDate, array $rules): array
    {
        $transactions = [];
        $algorithm = [];

        $algorithm[] = "📋 **Bērna kopšanas atvaļinājums** (DL 156. pants)";
        $algorithm[] = "Piešķir sakarā ar bērna dzimšanu — līdz 1.5 gadam.";
        $algorithm[] = "Darba devējs neapmaksā (VSAA). Periods >4 nedēļas nobīda darba gadu.";
        $algorithm[] = "Nav uzkrājuma — piešķir pēc pieprasījuma ar dokumentu.";

        return [$transactions, $algorithm];
    }

    // =========================================================================
    // TYPE 3: MĀCĪBU ATVAĻINĀJUMS (DL 157)
    // =========================================================================
    protected function accrueMacibu(Employee $employee, VacationConfig $config, Carbon $baseDate, Carbon $referenceDate, array $rules): array
    {
        $transactions = [];
        $algorithm = [];

        $algorithm[] = "📋 **Mācību atvaļinājums** (DL 157. pants)";
        $algorithm[] = "Līdz 20 DD gadā mācību vajadzībām.";
        $algorithm[] = "Ja mācības saistītas ar darbu — saglabā darba algu.";
        $algorithm[] = "Izlaidumam/diplomdarba aizstāvēšanai — 20 apmaksātas DD.";
        $algorithm[] = "Limits tiek sekots pa kalendāra gadiem.";

        // Max 20 DD per calendar year as entitlement
        $startYear = max($baseDate->year, $referenceDate->copy()->subYears(2)->year);
        $endYear = $referenceDate->year;

        for ($year = $startYear; $year <= $endYear; $year++) {
            if ($baseDate->year > $year) continue;

            $transactions[] = [
                'transaction_type' => 'accrual',
                'period_from' => Carbon::createFromDate($year, 1, 1)->toDateString(),
                'period_to' => Carbon::createFromDate($year, 12, 31)->toDateString(),
                'days_dd' => 20,
                'remaining_dd' => 20,
                'document_id' => null,
                'description' => "Mācību atvaļinājuma limits {$year}. gadam: 20 DD (DL 157)",
            ];
        }

        return [$transactions, $algorithm];
    }

    // =========================================================================
    // TYPE 4: BEZALGAS ATVAĻINĀJUMS (DL 153)
    // =========================================================================
    protected function accrueBezalgas(Employee $employee, VacationConfig $config, Carbon $baseDate, Carbon $referenceDate, array $rules): array
    {
        $algorithm = [];

        $algorithm[] = "📋 **Bezalgas atvaļinājums** (DL 153. pants)";
        $algorithm[] = "Piešķir pēc darbinieka pieprasījuma — bez limita.";
        $algorithm[] = "Pirmās 4 nedēļas (20 DD) darba gadā — nenobīda darba gadu ikgadējā atvaļinājuma aprēķinam.";
        $algorithm[] = "Periods virs 4 nedēļām — nobīda darba gadu (shifts_working_year).";
        $algorithm[] = "Nav uzkrājuma — nav limita.";

        return [[], $algorithm];
    }

    // =========================================================================
    // TYPE 6: GRŪTNIECĪBAS UN DZEMDĪBU (DL 154)
    // =========================================================================
    protected function accrueGrutnieciba(Employee $employee, VacationConfig $config, Carbon $baseDate, Carbon $referenceDate, array $rules): array
    {
        $algorithm = [];

        $algorithm[] = "📋 **Grūtniecības un dzemdību atvaļinājums** (DL 154. pants)";
        $algorithm[] = "Pirmsdzemdību: 56 KD (vai 70 KD, ja uzsākta med. aprūpe līdz 12. nedēļai).";
        $algorithm[] = "Pēcdzemdību: 56 KD (vai 70 KD komplikāciju / daudzaugļu gadījumā).";
        $algorithm[] = "⚠️ Šis periods NENOBĪDA darba gadu — ieskaitās laikā, kas dod tiesības uz ikgadējo atvaļinājumu.";
        $algorithm[] = "Piešķir pēc B-lapas iesniegšanas, apmaksā VSAA.";

        return [[], $algorithm];
    }

    // =========================================================================
    // TYPE 7: PATERNITĀTES ATVAĻINĀJUMS (DL 155)
    // =========================================================================
    protected function accruePaternitates(Employee $employee, VacationConfig $config, Carbon $baseDate, Carbon $referenceDate, array $rules): array
    {
        $transactions = [];
        $algorithm = [];

        $algorithm[] = "📋 **Paternitātes atvaļinājums** (DL 155. pants)";
        $algorithm[] = "Bērna tēvam: 10 DD sakarā ar bērna dzimšanu.";
        $algorithm[] = "Jāizmanto 2 mēnešu laikā no bērna dzimšanas dienas.";
        $algorithm[] = "Apmaksā VSAA (nevis darba devējs).";

        // Check for child birth documents
        $childDocs = Document::where('employee_id', $employee->id)
            ->where('type', 'child_registration')
            ->get();

        foreach ($childDocs as $doc) {
            $payload = is_string($doc->payload) ? json_decode($doc->payload, true) : $doc->payload;
            $childDob = isset($payload['child_dob']) ? Carbon::parse($payload['child_dob']) : null;

            if ($childDob) {
                $deadline = $childDob->copy()->addMonths(2);
                $transactions[] = [
                    'transaction_type' => 'accrual',
                    'period_from' => $childDob->toDateString(),
                    'period_to' => $deadline->toDateString(),
                    'days_dd' => 10,
                    'remaining_dd' => 10,
                    'document_id' => $doc->id,
                    'description' => "Paternitātes atvaļinājums: 10 DD (bērns dz. " . $childDob->format('d.m.Y') . ", termiņš līdz " . $deadline->format('d.m.Y') . ")",
                ];
                $algorithm[] = "Bērns dz. " . $childDob->format('d.m.Y') . " → 10 DD, termiņš: " . $deadline->format('d.m.Y');
            }
        }

        return [$transactions, $algorithm];
    }

    // =========================================================================
    // TYPE 10: ASINS DONORA DIENA (DL 74 §6)
    // =========================================================================
    protected function accrueDonoraDiena(Employee $employee, VacationConfig $config, Carbon $baseDate, Carbon $referenceDate, array $rules): array
    {
        $transactions = [];
        $algorithm = [];

        $algorithm[] = "📋 **Asins donora diena** (DL 74. panta 6. daļa)";
        $algorithm[] = "Pēc asins ziedošanas darbiniekam piešķir 1 apmaksātu atpūtas dienu.";
        $algorithm[] = "Jāizmanto nākamajā darba dienā vai pēc vienošanās citā dienā.";
        $algorithm[] = "Piešķir uz dokumenta pamata (donora izziņa).";

        // Check for donor documents
        $donorDocs = Document::where('employee_id', $employee->id)
            ->where('type', 'donor_day')
            ->get();

        foreach ($donorDocs as $doc) {
            $transactions[] = [
                'transaction_type' => 'accrual',
                'period_from' => $doc->date_from ? $doc->date_from->toDateString() : now()->toDateString(),
                'period_to' => $doc->date_from ? $doc->date_from->copy()->addDays(30)->toDateString() : now()->addDays(30)->toDateString(),
                'days_dd' => 1,
                'remaining_dd' => 1,
                'document_id' => $doc->id,
                'description' => "Donora diena: 1 DD (ziedošana " . ($doc->date_from ? $doc->date_from->format('d.m.Y') : '?') . ")",
            ];
            $algorithm[] = "Ziedošana " . ($doc->date_from ? $doc->date_from->format('d.m.Y') : '?') . " → 1 DD";
        }

        return [$transactions, $algorithm];
    }

    // =========================================================================
    // TYPE 11: RADOŠAIS ATVAĻINĀJUMS (DL / Kolektīvais līgums)
    // =========================================================================
    protected function accrueRadosais(Employee $employee, VacationConfig $config, Carbon $baseDate, Carbon $referenceDate, array $rules): array
    {
        $algorithm = [];

        $algorithm[] = "📋 **Radošais atvaļinājums**";
        $algorithm[] = "Piešķir saskaņā ar DL vai kolektīvo līgumu.";
        $algorithm[] = "Parasti pētniekiem, zinātniekiem, autoriem.";
        $algorithm[] = "Nav automātiska uzkrājuma — piešķir pēc vienošanās.";

        return [[], $algorithm];
    }

    // =========================================================================
    // USAGE / CONSUMPTION (FIFO)
    // =========================================================================
    protected function processUsage(Employee $employee, VacationConfig $config, Carbon $baseDate, Carbon $referenceDate): array
    {
        $usageTransactions = [];

        $usedDocs = Document::where('employee_id', $employee->id)
            ->whereIn('type', ['vacation', 'unpaid_leave', 'study_leave', 'donor_day'])
            ->orderBy('date_from', 'asc')
            ->get();

        foreach ($usedDocs as $doc) {
            $payload = is_string($doc->payload) ? json_decode($doc->payload, true) : $doc->payload;
            $configId = $payload['vacation_config_id'] ?? null;

            if ($configId != $config->id) continue;

            $start = Carbon::parse($doc->date_from);
            $end = Carbon::parse($doc->date_to);

            // Count working days
            $dd = 0;
            $current = $start->copy();
            while ($current->lte($end)) {
                if (!$current->isWeekend()) $dd++;
                $current->addDay();
            }

            if ($dd <= 0) continue;

            $kd = $start->diffInDays($end) + 1;

            $usageTransactions[] = [
                'transaction_type' => 'usage',
                'period_from' => $start->toDateString(),
                'period_to' => $end->toDateString(),
                'days_dd' => -$dd,
                'remaining_dd' => 0,
                'document_id' => $doc->id,
                'description' => "Izmantots {$dd} DD / {$kd} KD ({$config->name}, " . $start->format('d.m.Y') . " - " . $end->format('d.m.Y') . ")",
            ];
        }

        return $usageTransactions;
    }

    // =========================================================================
    // FIFO: Apply usage to accrual remaining_dd after all transactions saved
    // =========================================================================
    public function applyFifo(Employee $employee, int $configId): void
    {
        // Reset all accrual remaining_dd to full
        LeaveTransaction::where('employee_id', $employee->id)
            ->where('vacation_config_id', $configId)
            ->where('transaction_type', 'accrual')
            ->get()
            ->each(function ($t) {
                $t->remaining_dd = $t->days_dd;
                $t->save();
            });

        // Get total usage
        $totalUsed = abs(
            LeaveTransaction::where('employee_id', $employee->id)
                ->where('vacation_config_id', $configId)
                ->where('transaction_type', 'usage')
                ->sum('days_dd')
        );

        if ($totalUsed <= 0) return;

        // Apply FIFO
        $accruals = LeaveTransaction::where('employee_id', $employee->id)
            ->where('vacation_config_id', $configId)
            ->where('transaction_type', 'accrual')
            ->orderBy('period_from', 'asc')
            ->get();

        $remaining = $totalUsed;
        foreach ($accruals as $accrual) {
            if ($remaining <= 0) break;

            $consume = min($remaining, (float) $accrual->days_dd);
            $accrual->remaining_dd = round((float) $accrual->days_dd - $consume, 5);
            $accrual->save();
            $remaining -= $consume;
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    protected function getReferenceDate(): Carbon
    {
        $today = now();
        if ($today->day < $today->daysInMonth) {
            return $today->copy()->subMonth()->endOfMonth();
        }
        return $today->copy()->endOfMonth();
    }

    protected function getHireDate(Employee $employee): ?Carbon
    {
        $hireDoc = Document::where('employee_id', $employee->id)
            ->where('type', 'hire')
            ->orderBy('date_from', 'asc')
            ->first();

        if ($hireDoc && $hireDoc->date_from) {
            return Carbon::parse($hireDoc->date_from);
        }

        return $employee->sakdatums ? Carbon::parse($employee->sakdatums) : null;
    }

    /**
     * Calculate effective base date accounting for working year shifts.
     * Bezalgas and bērna kopšanas >4 weeks shift the working year.
     */
    protected function getEffectiveBaseDate(Employee $employee, Carbon $baseDate, Carbon $referenceDate): Carbon
    {
        $shiftDays = 0;

        $shiftingDocs = Document::where('employee_id', $employee->id)
            ->whereIn('type', ['vacation', 'unpaid_leave'])
            ->get();

        foreach ($shiftingDocs as $doc) {
            $payload = is_string($doc->payload) ? json_decode($doc->payload, true) : $doc->payload;
            $configId = $payload['vacation_config_id'] ?? null;
            if (!$configId) continue;

            $config = VacationConfig::find($configId);
            if (!$config) continue;

            $rules = is_string($config->rules) ? json_decode($config->rules, true) : ($config->rules ?? []);
            if (!($rules['shifts_working_year'] ?? false)) continue;

            $start = Carbon::parse($doc->date_from);
            $end = Carbon::parse($doc->date_to);
            $totalKD = $start->diffInDays($end) + 1;
            $thresholdDays = ($rules['shifts_working_year_threshold_weeks'] ?? 4) * 7; // 4 weeks = 28 days

            if ($totalKD > $thresholdDays) {
                $shiftDays += ($totalKD - $thresholdDays);
            }
        }

        return $shiftDays > 0 ? $baseDate->copy()->addDays($shiftDays) : $baseDate->copy();
    }

    /**
     * Deduct accrual for periods spent on shifts_working_year leave.
     * Mirrors ALGA BK_ATV_DIENAS logic.
     */
    protected function calculateDienasNeuzkraj(Employee $employee, Carbon $baseDate, Carbon $referenceDate, float $monthlyRate): float
    {
        $totalDeduction = 0.0;

        $shiftingDocs = Document::where('employee_id', $employee->id)
            ->whereIn('type', ['vacation', 'unpaid_leave', 'study_leave'])
            ->get()
            ->filter(function ($doc) {
                $payload = is_string($doc->payload) ? json_decode($doc->payload, true) : $doc->payload;
                $configId = $payload['vacation_config_id'] ?? null;
                if (!$configId) return false;
                $config = VacationConfig::find($configId);
                if (!$config) return false;
                $rules = is_string($config->rules) ? json_decode($config->rules, true) : ($config->rules ?? []);
                return $rules['shifts_working_year'] ?? false;
            });

        foreach ($shiftingDocs as $doc) {
            $vacStart = Carbon::parse($doc->date_from);
            $vacEnd = Carbon::parse($doc->date_to);

            if ($vacEnd->lt($baseDate) || $vacStart->gt($referenceDate)) continue;

            $clippedStart = $vacStart->lt($baseDate) ? $baseDate->copy() : $vacStart->copy();
            $clippedEnd = $vacEnd->gt($referenceDate) ? $referenceDate->copy() : $vacEnd->copy();

            $vacMonths = $this->calculateMonthsWorkedAtvrezYmd($clippedStart, $clippedEnd);
            $totalDeduction += round($vacMonths['totalMonths'] * $monthlyRate, 5);
        }

        return $totalDeduction;
    }

    /**
     * ATVREZ_YMD algorithm from ALGA.
     * Calculates full and partial months between two dates.
     */
    protected function calculateMonthsWorkedAtvrezYmd(Carbon $dateFrom, Carbon $dateTo): array
    {
        $y1 = (int) $dateFrom->year;
        $m1 = (int) $dateFrom->month;
        $d1 = (int) $dateFrom->day;
        $y2 = (int) $dateTo->year;
        $m2 = (int) $dateTo->month;
        $d2 = (int) $dateTo->day;

        $ym1 = $y1 * 12 + $m1 + 1;
        $ym2 = $y2 * 12 + $m2;

        $veseluMenesuSkaits = $ym2 - $ym1;
        $skTmp = $veseluMenesuSkaits;
        if ($veseluMenesuSkaits < 0) {
            $veseluMenesuSkaits = 0;
        }

        $dsakums = 0;
        $dbeigas = 0;
        $nepilniMenesi = 0.0;

        if ($skTmp === -1) {
            $dsakums = $d2 - $d1 + 1;
            if ($dsakums < 0) $dsakums = 0;
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $m1, $y1);
            $nepilniMenesi = $dsakums / $daysInMonth;
        } elseif ($skTmp > -1) {
            $daysInStartMonth = cal_days_in_month(CAL_GREGORIAN, $m1, $y1);
            $dsakums = $daysInStartMonth - $d1 + 1;
            $nepilniMenesi += $dsakums / $daysInStartMonth;

            $daysInEndMonth = cal_days_in_month(CAL_GREGORIAN, $m2, $y2);
            $dbeigas = $d2;
            $nepilniMenesi += $dbeigas / $daysInEndMonth;
        }

        $totalMonths = $veseluMenesuSkaits + $nepilniMenesi;

        return [
            'totalMonths' => $totalMonths,
            'fullMonths' => $veseluMenesuSkaits,
            'partialMonths' => $nepilniMenesi,
        ];
    }
}
