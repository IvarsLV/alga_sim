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
     */
    public function calculateAll(Employee $employee): array
    {
        $referenceDate = $this->getReferenceDate();
        $baseDate = $this->getHireDate($employee);

        if (!$baseDate) {
            return [];
        }

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

        // Payment status label
        $paymentStatus = $rules['payment_status'] ?? 'apmaksāts';
        $paymentLabels = [
            'apmaksāts' => '💰 Apmaksāts (darba devējs)',
            'neapmaksāts' => '🚫 Neapmaksāts',
            'VSAA' => '🏛️ Apmaksā VSAA',
        ];
        $algorithm[] = ($paymentLabels[$paymentStatus] ?? $paymentStatus);

        switch ($tip) {
            case 1:
                [$transactions, $algo] = $this->accrueIkgadejais($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            case 2:
                [$transactions, $algo] = $this->accrueBernaKopsana($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            case 3:
                [$transactions, $algo] = $this->accrueMacibu($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            case 4:
                [$transactions, $algo] = $this->accrueBezalgas($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            case 5:
                [$transactions, $algo] = $this->accruePapildBerniem($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            case 6:
                [$transactions, $algo] = $this->accrueGrutnieciba($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            case 7:
                [$transactions, $algo] = $this->accruePaternitates($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            case 10:
                [$transactions, $algo] = $this->accrueDonoraDiena($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            case 11:
                [$transactions, $algo] = $this->accrueRadosais($employee, $config, $baseDate, $referenceDate, $rules);
                break;
            default:
                $algo = ["Nav definēts algoritms šim tipam (tip={$tip})."];
        }
        $algorithm = array_merge($algorithm, $algo);

        // Process usage (consumption) for this type
        $usageTransactions = $this->processUsage($employee, $config, $baseDate, $referenceDate);
        $transactions = array_merge($transactions, $usageTransactions);

        // Apply expiration BEFORE totals
        $expirationTransactions = $this->applyExpiration($transactions, $config, $rules, $referenceDate, $algorithm);
        $transactions = array_merge($transactions, $expirationTransactions);

        // Calculate totals (accrual - expired - used)
        $totalAccrued = collect($transactions)->where('transaction_type', 'accrual')->sum('days_dd');
        $totalExpired = abs(collect($transactions)->where('transaction_type', 'expiration')->sum('days_dd'));
        $totalUsed = abs(collect($transactions)->where('transaction_type', 'usage')->sum('days_dd'));
        $balance = round($totalAccrued - $totalExpired - $totalUsed, 2);

        // Save to DB
        foreach ($transactions as $t) {
            LeaveTransaction::create(array_merge($t, [
                'employee_id' => $employee->id,
                'vacation_config_id' => $config->id,
            ]));
        }

        // Apply FIFO — updates remaining_dd on saved accruals in DB
        $this->applyFifoWithDetails($employee, $config->id, $transactions);

        // Re-read transactions from DB to get updated remaining_dd
        $savedTransactions = LeaveTransaction::where('employee_id', $employee->id)
            ->where('vacation_config_id', $config->id)
            ->orderBy('period_from', 'asc')
            ->orderBy('transaction_type', 'asc')
            ->get()
            ->map(fn($t) => $t->toArray())
            ->values()
            ->toArray();

        return [
            'config' => $config,
            'accrued' => round($totalAccrued, 2),
            'expired' => round($totalExpired, 2),
            'used' => round($totalUsed, 2),
            'balance' => $balance,
            'balance_kd' => round($balance * (7.0 / 5.0), 2),
            'transactions' => $savedTransactions,
            'algorithm' => $algorithm,
            'payment_status' => $paymentStatus,
        ];
    }

    // =========================================================================
    // EXPIRATION LOGIC
    // =========================================================================

    /**
     * Apply expiration rules based on leave type.
     * Returns expiration transactions and modifies algorithm log.
     */
    protected function applyExpiration(array $accrualTransactions, VacationConfig $config, array $rules, Carbon $referenceDate, array &$algorithm): array
    {
        $expirations = [];
        $tip = $config->tip;
        $carryOverYears = $rules['carry_over_years'] ?? null;
        $expiresEndOfPeriod = $rules['expires_end_of_period'] ?? false;
        $usageDeadlineMonths = $rules['usage_deadline_months'] ?? null;
        $usageDeadlineDays = $rules['usage_deadline_days'] ?? null;

        foreach ($accrualTransactions as $t) {
            if ($t['transaction_type'] !== 'accrual') continue;
            if ((float) $t['days_dd'] <= 0) continue;

            $periodTo = Carbon::parse($t['period_to']);
            $expired = false;
            $reason = '';

            switch ($tip) {
                case 1: // Ikgadējais — carry over max 1 year (DL 149§13)
                    // Working year periods older than 2 years → expire
                    $carryLimit = $carryOverYears ?? 1;
                    $expiryDate = $periodTo->copy()->addYears($carryLimit);
                    if ($referenceDate->gt($expiryDate)) {
                        $expired = true;
                        $reason = "Termiņš: pārnests {$carryLimit} gadu laikā (DL 149§13). Beidzās: " . $expiryDate->format('d.m.Y');
                    }
                    break;

                case 3: // Mācību — 20 DD per calendar year, no carry-over
                case 5: // Papild. bērniem — must use before next annual leave, expire at year end
                    $yearEnd = Carbon::parse($t['period_to']);
                    if ($referenceDate->gt($yearEnd)) {
                        $expired = true;
                        $reason = $tip === 3
                            ? "Mācību atvaļinājuma limits beidzās (DL 157). Nav pārnešanas."
                            : "Papildatvaļinājums jāizmanto līdz nākamā ikgadējā atvaļinājuma laikam (DL 151). Termiņš beidzās.";
                    }
                    break;

                case 7: // Paternitātes — 2 months from child birth
                    if ($usageDeadlineMonths) {
                        if ($referenceDate->gt($periodTo)) {
                            $expired = true;
                            $reason = "Termiņš beidzās: " . $periodTo->format('d.m.Y') . " (2 mēneši no dzimšanas, DL 155)";
                        }
                    }
                    break;

                case 10: // Donora diena — use next working day or by agreement
                    $deadlineDays = $usageDeadlineDays ?? 30;
                    $donorDeadline = Carbon::parse($t['period_from'])->addDays($deadlineDays);
                    if ($referenceDate->gt($donorDeadline)) {
                        $expired = true;
                        $reason = "Donora diena jāizmanto {$deadlineDays} dienu laikā (DL 74§6)";
                    }
                    break;
            }

            if ($expired) {
                $expirations[] = [
                    'transaction_type' => 'expiration',
                    'period_from' => $t['period_from'],
                    'period_to' => $t['period_to'],
                    'days_dd' => -abs((float) $t['days_dd']),
                    'remaining_dd' => 0,
                    'document_id' => null,
                    'description' => "⏰ Noilgums: " . abs((float) $t['days_dd']) . " DD (" . $reason . ")",
                ];
                $algorithm[] = "⏰ Noilgums: " . round(abs((float) $t['days_dd']), 2) . " DD par periodu " .
                    Carbon::parse($t['period_from'])->format('d.m.Y') . "–" . Carbon::parse($t['period_to'])->format('d.m.Y');
            }
        }

        if (!empty($expirations)) {
            $totalExpired = abs(array_sum(array_column($expirations, 'days_dd')));
            $algorithm[] = "⚠️ **Kopā noilgušas: " . round($totalExpired, 2) . " DD**";
        }

        // Add expiration info to algorithm
        if ($tip === 1) {
            $carryLimit = $carryOverYears ?? 1;
            $algorithm[] = "📅 Pārnešanas termiņš: {$carryLimit} gads (DL 149§13). Neizmantotās dienas pēc šī termiņa sadzēšas.";
        } elseif ($tip === 3) {
            $algorithm[] = "📅 Limits: 20 DD par kalendāro gadu. Neizmantotās dienas **nepārnesās** uz nākamo gadu.";
        } elseif ($tip === 5) {
            $algorithm[] = "📅 Jāizmanto līdz nākamā ikgadējā atvaļinājuma piešķiršanai. Neizmantotās dienas noilgst gada beigās.";
        } elseif ($tip === 7) {
            $algorithm[] = "📅 Termiņš: 2 mēneši no bērna dzimšanas. Pēc tam tiesības zūd.";
        } elseif ($tip === 10) {
            $deadlineDays = $usageDeadlineDays ?? 30;
            $algorithm[] = "📅 Jāizmanto {$deadlineDays} dienu laikā pēc ziedošanas. Nekopjas.";
        }

        return $expirations;
    }

    // =========================================================================
    // FIFO WITH BATCH DETAILS
    // =========================================================================

    /**
     * Apply FIFO and return batch consumption details.
     * Returns: [['from' => 'DD.MM.YYYY', 'to' => 'DD.MM.YYYY', 'consumed' => X], ...]
     */
    public function applyFifoWithDetails(Employee $employee, int $configId, array $transactions): array
    {
        $accruals = LeaveTransaction::where('employee_id', $employee->id)
            ->where('vacation_config_id', $configId)
            ->where('transaction_type', 'accrual')
            ->orderBy('period_from', 'asc')
            ->get();

        // Get total expired for this type
        $totalExpired = abs(
            LeaveTransaction::where('employee_id', $employee->id)
                ->where('vacation_config_id', $configId)
                ->where('transaction_type', 'expiration')
                ->sum('days_dd')
        );

        // Get total used
        $totalUsed = abs(
            LeaveTransaction::where('employee_id', $employee->id)
                ->where('vacation_config_id', $configId)
                ->where('transaction_type', 'usage')
                ->sum('days_dd')
        );

        $totalToConsume = $totalExpired + $totalUsed;

        if ($totalToConsume <= 0) {
            // Reset remaining to full
            foreach ($accruals as $accrual) {
                $accrual->remaining_dd = $accrual->days_dd;
                $accrual->save();
            }
            return [];
        }

        // Apply FIFO: oldest accruals consumed first
        $remaining = $totalToConsume;
        $fifoDetails = [];

        foreach ($accruals as $accrual) {
            if ($remaining <= 0) {
                $accrual->remaining_dd = $accrual->days_dd;
                $accrual->save();
                continue;
            }

            $available = (float) $accrual->days_dd;
            $consume = min($remaining, $available);

            $accrual->remaining_dd = round($available - $consume, 5);
            $accrual->save();

            if ($consume > 0) {
                $fifoDetails[] = [
                    'period_from' => $accrual->period_from,
                    'period_to' => $accrual->period_to,
                    'batch_total' => round($available, 2),
                    'consumed' => round($consume, 2),
                    'remaining' => round($available - $consume, 2),
                    'label' => "No perioda " .
                        Carbon::parse($accrual->period_from)->format('d.m.Y') . "–" .
                        Carbon::parse($accrual->period_to)->format('d.m.Y') .
                        ": izlietots " . round($consume, 2) . " DD, atlikums " . round($available - $consume, 2) . " DD",
                ];
            }

            $remaining -= $consume;
        }

        return $fifoDetails;
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

        $effectiveBaseDate = $this->getEffectiveBaseDate($employee, $baseDate, $referenceDate);
        if (!$effectiveBaseDate->eq($baseDate)) {
            $algorithm[] = "⚠️ Darba gads nobīdīts uz: " . $effectiveBaseDate->format('d.m.Y') . " (bezalgas/bērna kopšanas >4 nedēļas)";
        }

        $monthsResult = $this->calculateMonthsWorkedAtvrezYmd($effectiveBaseDate, $referenceDate);
        $monthsWorked = $monthsResult['totalMonths'];

        $algorithm[] = "Nostrādāts: " . round($monthsWorked, 4) . " mēn. ({$monthsResult['fullMonths']} pilni + " . round($monthsResult['partialMonths'], 4) . " nepilni)";

        $earnedDD = round($monthsWorked * $monthlyRate, 5);
        $algorithm[] = "Uzkrājums: " . round($monthlyRate, 4) . " × " . round($monthsWorked, 4) . " = " . round($earnedDD, 2) . " DD";

        $neuzkraj = $this->calculateDienasNeuzkraj($employee, $effectiveBaseDate, $referenceDate, $monthlyRate);
        if ($neuzkraj > 0) {
            $earnedDD = round($earnedDD - $neuzkraj, 5);
            $algorithm[] = "Neuzkrāj (atvaļ. periodi ar darba gada nobīdi): -" . round($neuzkraj, 2) . " DD";
        }

        $algorithm[] = "**Kopā uzkrāts: " . round($earnedDD, 2) . " DD**";
        $algorithm[] = "📅 Pārnešana: max " . ($rules['carry_over_years'] ?? 1) . " gads (DL 149§13)";

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
                'description' => "Darba gads " . $loopDate->format('d.m.Y') . " – " . $yearEnd->format('d.m.Y') . ": " . round($yrEarned, 2) . " DD",
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
        $algorithm[] = "⚠️ Jāizmanto līdz nākamā ikgadējā atvaļinājuma piešķiršanai, citādi noilgst (DL 151).";

        // Only generate for current calendar year (old years expire)
        $startYear = max($baseDate->year, $referenceDate->copy()->subYears(2)->year);
        $endYear = $referenceDate->year;

        for ($year = $startYear; $year <= $endYear; $year++) {
            $yearStart = Carbon::createFromDate($year, 1, 1);
            $yearEnd = Carbon::createFromDate($year, 12, 31);

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
        $algorithm[] = "Periods >4 nedēļas nobīda darba gadu.";
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
        $algorithm[] = "Ja nav saistītas — neapmaksāts (vai pēc vienošanās).";
        $algorithm[] = "Izlaidumam/diplomdarba aizstāvēšanai — 20 apmaksātas DD.";
        $algorithm[] = "⚠️ Neizmantotais limits **nepārnesās** uz nākamo gadu.";

        // Only create for current calendar year and previous (old ones expire via applyExpiration)
        $startYear = max($baseDate->year, $referenceDate->copy()->subYears(1)->year);
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
        $algorithm[] = "Piešķir pēc B-lapas iesniegšanas.";

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

        $childDocs = Document::where('employee_id', $employee->id)
            ->where('type', 'child_registration')
            ->get();

        foreach ($childDocs as $doc) {
            $payload = is_string($doc->payload) ? json_decode($doc->payload, true) : $doc->payload;
            $childDob = isset($payload['child_dob']) ? Carbon::parse($payload['child_dob']) : null;

            if ($childDob) {
                // Skip events that happened before employment
                $deadline = $childDob->copy()->addMonths(2);
                if ($deadline->lt($baseDate)) {
                    $algorithm[] = "Bērns dz. " . $childDob->format('d.m.Y') . " — termiņš beidzās pirms darba attiecībām, netiek piešķirts.";
                    continue;
                }
                $isExpired = $referenceDate->gt($deadline);
                $statusLabel = $isExpired ? " ⏰ NOILDZIS" : " ✅ Aktīvs";

                $transactions[] = [
                    'transaction_type' => 'accrual',
                    'period_from' => $childDob->toDateString(),
                    'period_to' => $deadline->toDateString(),
                    'days_dd' => 10,
                    'remaining_dd' => 10,
                    'document_id' => $doc->id,
                    'description' => "Paternitātes atvaļinājums: 10 DD (bērns dz. " . $childDob->format('d.m.Y') . ", termiņš līdz " . $deadline->format('d.m.Y') . ")" . $statusLabel,
                ];
                $algorithm[] = "Bērns dz. " . $childDob->format('d.m.Y') . " → 10 DD, termiņš: " . $deadline->format('d.m.Y') . $statusLabel;
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

        $deadlineDays = $rules['usage_deadline_days'] ?? 30;

        $algorithm[] = "📋 **Asins donora diena** (DL 74. panta 6. daļa)";
        $algorithm[] = "Pēc asins ziedošanas darbiniekam piešķir 1 apmaksātu atpūtas dienu.";
        $algorithm[] = "Jāizmanto nākamajā darba dienā vai pēc vienošanās citā dienā.";
        $algorithm[] = "Piešķir uz dokumenta pamata (donora izziņa).";
        $algorithm[] = "⚠️ Termiņš: {$deadlineDays} dienas. Nekopjas.";

        $donorDocs = Document::where('employee_id', $employee->id)
            ->where('type', 'donor_day')
            ->get();

        foreach ($donorDocs as $doc) {
            $donationDate = $doc->date_from ? $doc->date_from->copy() : now();
            $deadline = $donationDate->copy()->addDays($deadlineDays);
            $isExpired = $referenceDate->gt($deadline);

            $transactions[] = [
                'transaction_type' => 'accrual',
                'period_from' => $donationDate->toDateString(),
                'period_to' => $deadline->toDateString(),
                'days_dd' => 1,
                'remaining_dd' => 1,
                'document_id' => $doc->id,
                'description' => "Donora diena: 1 DD (ziedošana " . $donationDate->format('d.m.Y') . ", termiņš līdz " . $deadline->format('d.m.Y') . ")" . ($isExpired ? " ⏰ NOILDZIS" : ""),
            ];
            $algorithm[] = "Ziedošana " . $donationDate->format('d.m.Y') . " → 1 DD" . ($isExpired ? " ⏰ NOILDZIS" : "");
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
                'description' => "Izmantots {$dd} DD / {$kd} KD ({$config->name}, " . $start->format('d.m.Y') . " – " . $end->format('d.m.Y') . ")",
            ];
        }

        return $usageTransactions;
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
            $thresholdDays = ($rules['shifts_working_year_threshold_weeks'] ?? 4) * 7;

            if ($totalKD > $thresholdDays) {
                $shiftDays += ($totalKD - $thresholdDays);
            }
        }

        return $shiftDays > 0 ? $baseDate->copy()->addDays($shiftDays) : $baseDate->copy();
    }

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
