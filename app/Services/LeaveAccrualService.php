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
     * FULLY DRIVEN BY VacationConfig.rules — no hardcoded tip numbers.
     */
    protected function calculateForType(Employee $employee, VacationConfig $config, Carbon $baseDate, Carbon $referenceDate): array
    {
        $rules = $config->rules;
        while (is_string($rules)) {
            $decoded = json_decode($rules, true);
            if (json_last_error() !== JSON_ERROR_NONE) break;
            if ($rules === $decoded) break;
            $rules = $decoded;
        }
        $rules = is_array($rules) ? $rules : [];

        $transactions = [];
        $algorithm = [];

        // Payment status from rules
        $paymentStatus = $rules['payment_status'] ?? 'apmaksāts';
        $paymentLabels = [
            'apmaksāts' => '💰 Apmaksāts (darba devējs)',
            'neapmaksāts' => '🚫 Neapmaksāts',
            'VSAA' => '🏛️ Apmaksā VSAA',
        ];
        $algorithm[] = ($paymentLabels[$paymentStatus] ?? $paymentStatus);

        // Financial formula label
        $formulaLabels = [
            'average_salary' => 'Vidējā izpeļņa',
            'base_salary' => 'Pamatalga',
            'unpaid' => 'Neapmaksāts',
        ];
        $formula = $rules['financial_formula'] ?? 'unpaid';
        $algorithm[] = "📄 Formula: " . ($formulaLabels[$formula] ?? $formula);

        // Dispatch on accrual_method from rules
        $accrualMethod = $rules['accrual_method'] ?? 'on_request';
        $accrualResults = match ($accrualMethod) {
            'monthly'    => $this->accrueMonthly($employee, $config, $baseDate, $referenceDate, $rules),
            'yearly'     => $this->accrueYearly($employee, $config, $baseDate, $referenceDate, $rules),
            'per_event'  => $this->accruePerEvent($employee, $config, $baseDate, $referenceDate, $rules),
            'on_request' => $this->accrueOnRequest($employee, $config, $baseDate, $referenceDate, $rules),
            default      => [[], ["Nav definēts algoritms metodei: {$accrualMethod}"]],
        };
        
        $transactions = $accrualResults[0] ?? [];
        $algo = $accrualResults[1] ?? [];
        $algorithm = array_merge($algorithm, $algo);

        // Process usage (consumption) for this type
        $usageTransactions = $this->processUsage($employee, $config, $baseDate, $referenceDate);
        $transactions = array_merge($transactions, $usageTransactions);

        // ----- MOCK FIFO IN-MEMORY TO CALCULATE remaining_dd FOR EXPIRATION -----
        $tempUsed = abs(collect($transactions)->where('transaction_type', 'usage')->sum('days_dd'));
        $tempOut = abs(collect($transactions)->where('transaction_type', 'transferred_out')->sum('days_dd'));
        $tempConsume = $tempUsed + $tempOut;

        // Sort chronologically for FIFO
        usort($transactions, function($a, $b) {
            return strcmp($a['period_from'], $b['period_from']);
        });

        foreach ($transactions as &$t) {
            if (in_array($t['transaction_type'], ['accrual', 'transferred_in'])) {
                $available = (float) $t['days_dd'];
                $consume = min($tempConsume, $available);
                $t['remaining_dd'] = $available - $consume;
                $tempConsume -= $consume;
            }
        }
        unset($t);
        // --------------------------------------------------------------------------

        // Apply expiration BEFORE totals (generic, rules-driven)
        $expirationTransactions = $this->applyExpiration($transactions, $config, $rules, $referenceDate, $algorithm);
        $transactions = array_merge($transactions, $expirationTransactions);

        // Calculate totals
        $totalAccrued = collect($transactions)->whereIn('transaction_type', ['accrual', 'transferred_in'])->sum('days_dd');
        $totalExpired = abs(collect($transactions)->where('transaction_type', 'expiration')->sum('days_dd'));
        $totalUsed = abs(collect($transactions)->where('transaction_type', 'usage')->sum('days_dd'));
        $totalOut = abs(collect($transactions)->where('transaction_type', 'transferred_out')->sum('days_dd'));
        
        $balance = round($totalAccrued - $totalExpired - $totalUsed - $totalOut, 2);

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
    // GENERIC EXPIRATION (rules-driven, no switch on tip)
    // =========================================================================

    protected function applyExpiration(array $accrualTransactions, VacationConfig $config, array $rules, Carbon $referenceDate, array &$algorithm): array
    {
        $expirations = [];

        $carryOverYears = $rules['carry_over_years'] ?? null;
        $expiresEndOfPeriod = $rules['expires_end_of_period'] ?? false;
        $expiresByAddingToAnnual = $rules['expires_by_adding_to_annual'] ?? false;
        $usageDeadlineDays = $rules['usage_deadline_days'] ?? null;
        $usageDeadlineMonths = $rules['usage_deadline_months'] ?? null;
        $periodType = $rules['period_type'] ?? 'working_year';

        $accruals = collect($accrualTransactions)->whereIn('transaction_type', ['accrual', 'transferred_in']);

        foreach ($accruals as $t) {
            if (($t['remaining_dd'] ?? 0) <= 0) continue; // Skip if fully consumed by mock FIFO

            $expired = false;
            $reason = '';
            $periodEnd = Carbon::parse($t['period_to']);

            // Rule 1: carry_over_years — period older than N years expires
            if ($carryOverYears !== null) {
                $expiryDate = $periodEnd->copy()->addYears($carryOverYears);
                if ($referenceDate->gt($expiryDate)) {
                    $expired = true;
                    $reason = "Pārnešanas termiņš ({$carryOverYears}g.) beidzies";
                }
            }

            // Rule 2: expires_end_of_period — expires at end of accrual period
            if (!$expired && $expiresEndOfPeriod) {
                if ($periodType === 'calendar_year') {
                    $yearEnd = Carbon::createFromDate(Carbon::parse($t['period_from'])->year, 12, 31);
                    if ($referenceDate->gt($yearEnd)) {
                        $expired = true;
                        $reason = "Periods beidzies (kalendārā gada beigas)";
                    }
                } else {
                    if ($referenceDate->gt($periodEnd)) {
                        $expired = true;
                        $reason = "Periods beidzies";
                    }
                }
            }

            // Rule 3: usage_deadline_days — per-event deadline in days
            if (!$expired && $usageDeadlineDays !== null) {
                $deadline = Carbon::parse($t['period_from'])->addDays($usageDeadlineDays);
                if ($referenceDate->gt($deadline)) {
                    $expired = true;
                    $reason = "Termiņš ({$usageDeadlineDays} dienas) beidzies";
                }
            }

            // Rule 4: usage_deadline_months — per-event deadline in months
            if (!$expired && $usageDeadlineMonths !== null) {
                $deadline = Carbon::parse($t['period_from'])->addMonths($usageDeadlineMonths);
                if ($referenceDate->gt($deadline)) {
                    $expired = true;
                    $reason = "Termiņš ({$usageDeadlineMonths} mēn.) beidzies";
                }
            }

            if ($expired) {
                $expiredDays = abs((float) $t['remaining_dd']); // Expire ONLY the unused portion!

                if ($expiresByAddingToAnnual) {
                    $expirations[] = [
                        'transaction_type' => 'transferred_out',
                        'period_from' => $t['period_from'],
                        'period_to' => $t['period_to'],
                        'days_dd' => -$expiredDays,
                        'remaining_dd' => 0,
                        'document_id' => null,
                        'description' => "🔄 Pievienots ikgadējam: " . $expiredDays . " DD (" . $reason . ")",
                    ];
                    
                    // We also inject a 'transferred_in' transaction for Tip=1 (Ikgadējais)
                    $expirations[] = [
                        'transaction_type' => 'transferred_in',
                        'target_tip' => 1,
                        'period_from' => $t['period_from'],
                        'period_to' => $t['period_to'],
                        'days_dd' => $expiredDays,
                        'remaining_dd' => $expiredDays,
                        'document_id' => null,
                        'description' => "🔄 Pārnests no " . $config->name . " (" . Carbon::parse($t['period_from'])->format('d.m.Y') . ")",
                    ];

                    $algorithm[] = "🔄 Pārnests uz ikgadējo: " . round($expiredDays, 2) . " DD par periodu " .
                        Carbon::parse($t['period_from'])->format('d.m.Y') . "–" . Carbon::parse($t['period_to'])->format('d.m.Y');
                } else {
                    $expirations[] = [
                        'transaction_type' => 'expiration',
                        'period_from' => $t['period_from'],
                        'period_to' => $t['period_to'],
                        'days_dd' => -$expiredDays,
                        'remaining_dd' => 0,
                        'document_id' => null,
                        'description' => "⏰ Noilgums: " . $expiredDays . " DD (" . $reason . ")",
                    ];
                    $algorithm[] = "⏰ Noilgums: " . round($expiredDays, 2) . " DD par periodu " .
                        Carbon::parse($t['period_from'])->format('d.m.Y') . "–" . Carbon::parse($t['period_to'])->format('d.m.Y');
                }
            }
        }

        if (!empty($expirations)) {
            $totalExpired = abs(array_sum(array_column($expirations, 'days_dd')));
            $algorithm[] = "⚠️ **Kopā noilgušas: " . round($totalExpired, 2) . " DD**";
        }

        // Add expiration rule info to algorithm
        if ($carryOverYears !== null) {
            $algorithm[] = "📅 Pārnešanas termiņš: {$carryOverYears} gads. Neizmantotās dienas pēc šī termiņa noilgst.";
        } elseif ($expiresEndOfPeriod) {
            $algorithm[] = "📅 Neizmantotās dienas noilgst perioda beigās. Nav pārnešanas.";
        } elseif ($usageDeadlineDays !== null) {
            $algorithm[] = "📅 Jāizmanto {$usageDeadlineDays} dienu laikā. Nekopjas.";
        } elseif ($usageDeadlineMonths !== null) {
            $algorithm[] = "📅 Jāizmanto {$usageDeadlineMonths} mēnešu laikā. Nekopjas.";
        }

        return $expirations;
    }

    // =========================================================================
    // FIFO WITH BATCH DETAILS
    // =========================================================================

    public function applyFifoWithDetails(Employee $employee, int $configId, array $transactions): array
    {
        $accruals = LeaveTransaction::where('employee_id', $employee->id)
            ->where('vacation_config_id', $configId)
            ->where('transaction_type', 'accrual')
            ->orderBy('period_from', 'asc')
            ->get();

        $totalExpired = abs(
            LeaveTransaction::where('employee_id', $employee->id)
                ->where('vacation_config_id', $configId)
                ->where('transaction_type', 'expiration')
                ->sum('days_dd')
        );

        $totalUsed = abs(
            LeaveTransaction::where('employee_id', $employee->id)
                ->where('vacation_config_id', $configId)
                ->where('transaction_type', 'usage')
                ->sum('days_dd')
        );

        $totalOut = abs(
            LeaveTransaction::where('employee_id', $employee->id)
                ->where('vacation_config_id', $configId)
                ->where('transaction_type', 'transferred_out')
                ->sum('days_dd')
        );

        $totalToConsume = $totalExpired + $totalUsed + $totalOut;

        if ($totalToConsume <= 0) {
            foreach ($accruals as $accrual) {
                $accrual->remaining_dd = $accrual->days_dd;
                $accrual->save();
            }
            return [];
        }

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
                ];
            }

            $remaining -= $consume;
        }

        return $fifoDetails;
    }

    // =========================================================================
    // METHOD 1: MONTHLY ACCRUAL (norm_days / 12 per month)
    // Used by: Ikgadējais atvaļinājums
    // =========================================================================

    protected function accrueMonthly(Employee $employee, VacationConfig $config, Carbon $baseDate, Carbon $referenceDate, array $rules): array
    {
        $transactions = [];
        $algorithm = [];

        $yearlyNormDD = (float) ($config->norm_days ?: 20);
        $monthlyRate = round($yearlyNormDD / 12, 5);
        $periodType = $rules['period_type'] ?? 'working_year';
        $lawRef = $rules['law_reference'] ?? '';

        $algorithm[] = "📋 **{$config->name}** ({$lawRef})";
        $algorithm[] = "Metode: ikmēneša uzkrāšana";
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

        // ----- BEGIN ADD TRANSFERS IN -----
        // Let's find Donor Day config
        $donorConfig = \App\Models\VacationConfig::where('tip', 10)->first();
        
        // Let's find any documents (like Donor Days) that have 'donor_action' === 'add_to_annual'
        // or 'add_to_annual_immediately' === true
        $bonusDocs = \App\Models\Document::where('employee_id', $employee->id)
            ->whereNotNull('payload')
            ->get()
            ->filter(function($doc) use ($donorConfig) {
                $payload = is_string($doc->payload) ? json_decode($doc->payload, true) : $doc->payload;
                
                // Must belong to Donor Day config to give this bonus
                $docConfigId = $payload['vacation_config_id'] ?? null;
                if ($donorConfig && $docConfigId != $donorConfig->id) return false;

                return ($payload['donor_action'] ?? null) === 'add_to_annual' || 
                       ($payload['add_to_annual_immediately'] ?? false) === true;
            });

        $transferredInTotal = 0.0;
        foreach ($bonusDocs as $doc) {
            $eventDate = $doc->date_from ? Carbon::parse($doc->date_from) : null;
            if (!$eventDate) continue;

            $transferredInTotal += 1.0; // 1 Bonus Day
            
            // Create a virtual transaction for this tip
            $transactions[] = [
                'transaction_type' => 'transferred_in',
                'period_from' => $eventDate->toDateString(),
                'period_to' => clone $referenceDate, // Never naturally expires
                'days_dd' => 1.0,
                'remaining_dd' => 1.0,
                'document_id' => $doc->id,
                'description' => "🔄 Pievienots no Asins donora dienas (notikums " . $eventDate->format('d.m.Y') . ")",
            ];
        }

        if ($transferredInTotal > 0) {
            $earnedDD = round($earnedDD + $transferredInTotal, 5);
            $algorithm[] = "🔄 Pārnesti no Donora dienām: +" . round($transferredInTotal, 2) . " DD";
            $algorithm[] = "**Galējais kopā uzkrāts: " . round($earnedDD, 2) . " DD**";
        }
        // ----- END ADD TRANSFERS IN -----

        if (isset($rules['carry_over_years'])) {
            $algorithm[] = "📅 Pārnešana: max " . $rules['carry_over_years'] . " gads";
        }

        // Create accrual transactions per period
        if ($periodType === 'working_year') {
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
        } else {
            // calendar_year periods
            $startYear = $effectiveBaseDate->year;
            $endYear = $referenceDate->year;

            for ($year = $startYear; $year <= $endYear; $year++) {
                $yearStart = Carbon::createFromDate($year, 1, 1);
                $yearEnd = Carbon::createFromDate($year, 12, 31);

                if ($effectiveBaseDate->gt($yearEnd)) continue;
                if ($effectiveBaseDate->gt($yearStart)) $yearStart = $effectiveBaseDate->copy();
                if ($yearEnd->gt($referenceDate)) $yearEnd = $referenceDate->copy();

                $yrMonths = $this->calculateMonthsWorkedAtvrezYmd($yearStart, $yearEnd);
                $yrEarned = round($yrMonths['totalMonths'] * $monthlyRate, 5);

                $transactions[] = [
                    'transaction_type' => 'accrual',
                    'period_from' => $yearStart->toDateString(),
                    'period_to' => Carbon::createFromDate($year, 12, 31)->toDateString(),
                    'days_dd' => round($yrEarned, 5),
                    'remaining_dd' => round($yrEarned, 5),
                    'document_id' => null,
                    'description' => "{$config->name} {$year}. gadam: " . round($yrEarned, 2) . " DD",
                ];
            }
        }

        return [$transactions, $algorithm];
    }

    // =========================================================================
    // METHOD 2: YEARLY ACCRUAL (fixed amount per calendar year)
    // Used by: Mācību atvaļinājums, Papildatvaļinājums par bērniem
    // =========================================================================

    protected function accrueYearly(Employee $employee, VacationConfig $config, Carbon $baseDate, Carbon $referenceDate, array $rules): array
    {
        $transactions = [];
        $algorithm = [];

        $lawRef = $rules['law_reference'] ?? '';
        $maxPerYear = $rules['max_per_year_dd'] ?? (float) ($config->norm_days ?: 0);
        $periodType = $rules['period_type'] ?? 'calendar_year';

        // For papild bērniem — get days from child service
        $yearlyDays = $maxPerYear;
        $childBased = ($rules['child_based'] ?? false);
        if ($childBased) {
            $yearlyDays = $this->childExtraService->getExtraDays($employee);
            if ($yearlyDays === 0) {
                $algorithm[] = "📋 **{$config->name}** ({$lawRef})";
                $algorithm[] = "Nav reģistrētu bērnu vai nav tiesību uz papildatvaļinājumu.";
                return [$transactions, $algorithm];
            }
        }

        $algorithm[] = "📋 **{$config->name}** ({$lawRef})";
        $algorithm[] = "Metode: ikgadēja piešķiršana";
        $algorithm[] = "Piešķirtās dienas: {$yearlyDays} DD/gadā";

        if ($childBased) {
            $algorithm[] = $yearlyDays === 3
                ? "Pamats: 3+ bērni vai bērns invalīds"
                : "Pamats: 1-2 bērni līdz 14 gadu vecumam";
            $algorithm[] = "Piešķir par katru kalendāro gadu, kurā darbinieks strādā.";
        }

        if ($rules['expires_end_of_period'] ?? false) {
            $algorithm[] = "⚠️ Neizmantotais limits **nepārnesās** uz nākamo periodu.";
        }

        // Generate for recent calendar years (older ones will expire via applyExpiration)
        $lookbackYears = ($rules['carry_over_years'] ?? 1) + 1;
        $startYear = max($baseDate->year, $referenceDate->copy()->subYears($lookbackYears)->year);
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
                'days_dd' => $yearlyDays,
                'remaining_dd' => $yearlyDays,
                'document_id' => null,
                'description' => "{$config->name} {$year}. gadam: {$yearlyDays} DD ({$lawRef})",
            ];
        }

        return [$transactions, $algorithm];
    }

    // =========================================================================
    // METHOD 3: PER-EVENT ACCRUAL (triggered by document)
    // Used by: Paternitātes, Donora diena
    // =========================================================================

    protected function accruePerEvent(Employee $employee, VacationConfig $config, Carbon $baseDate, Carbon $referenceDate, array $rules): array
    {
        $transactions = [];
        $algorithm = [];

        $lawRef = $rules['law_reference'] ?? '';
        $eventSource = $rules['event_source'] ?? null;
        $eventDays = $rules['event_days'] ?? 1;
        $requiresHireDateCheck = $rules['requires_hire_date_check'] ?? false;
        $usageDeadlineDays = $rules['usage_deadline_days'] ?? null;
        $usageDeadlineMonths = $rules['usage_deadline_months'] ?? null;
        $addToAnnualImmediately = $rules['add_to_annual_immediately'] ?? false;

        $algorithm[] = "📋 **{$config->name}** ({$lawRef})";
        $algorithm[] = "Metode: piešķir pēc notikuma (dokumenta)";
        $algorithm[] = "Dienas par notikumu: {$eventDays} DD";

        if ($addToAnnualImmediately) {
            $algorithm[] = "🔄 Nolikums: Šīs dienas tiek automātiski pieskaitītas ikgadējam atvaļinājumam.";
        } elseif ($usageDeadlineDays) {
            $algorithm[] = "⚠️ Termiņš: {$usageDeadlineDays} dienas. Nekopjas.";
        } elseif ($usageDeadlineMonths) {
            $algorithm[] = "⚠️ Jāizmanto {$usageDeadlineMonths} mēnešu laikā.";
        }

        if (!$eventSource) {
            $algorithm[] = "⚠️ Nav norādīts notikuma avots (event_source). Nav ko aprēķināt.";
            return [$transactions, $algorithm];
        }

        $algorithm[] = "Notikuma avots: {$eventSource}";

        if ($eventSource === 'child_registration') {
            $docs = Document::where('employee_id', $employee->id)
                ->where('type', 'child_registration')
                ->get();
        } else {
            // Find all documents that are linked to this specific Vacation Config
            // This allows us to use a unified 'vacation' document type on the frontend
            $docs = Document::where('employee_id', $employee->id)
                ->get()
                ->filter(function($doc) use ($config) {
                    $payload = is_string($doc->payload) ? json_decode($doc->payload, true) : $doc->payload;
                    return ($payload['vacation_config_id'] ?? null) == $config->id;
                });
        }

        foreach ($docs as $doc) {
            $payload = is_string($doc->payload) ? json_decode($doc->payload, true) : $doc->payload;

            // Determine the event date
            $eventDate = null;
            if ($eventSource === 'child_registration') {
                $eventDate = isset($payload['child_dob']) ? Carbon::parse($payload['child_dob']) : null;
            } else {
                $eventDate = $doc->date_from ? Carbon::parse($doc->date_from) : null;
            }

            if (!$eventDate) continue;

            // Calculate deadline
            $deadline = $eventDate->copy();
            if ($usageDeadlineMonths) {
                $deadline = $eventDate->copy()->addMonths($usageDeadlineMonths);
            } elseif ($usageDeadlineDays) {
                $deadline = $eventDate->copy()->addDays($usageDeadlineDays);
            } else {
                $deadline = $eventDate->copy()->addYear(); // Default 1 year
            }

            // Skip events where deadline is before hire date
            if ($requiresHireDateCheck && $deadline->lt($baseDate)) {
                $algorithm[] = "Notikums " . $eventDate->format('d.m.Y') . " — termiņš beidzās pirms darba attiecībām, netiek piešķirts.";
                continue;
            }

            $isExpired = $referenceDate->gt($deadline);
            $statusLabel = $isExpired ? " ⏰ NOILDZIS" : " ✅ Aktīvs";

            $docDonorAction = $payload['donor_action'] ?? null;
            $docAddToAnnual = isset($payload['add_to_annual_immediately']) ? $payload['add_to_annual_immediately'] : $addToAnnualImmediately;

            if ($docDonorAction === 'use_now') {
                // We accrue 2 days: 1 to cover the day off, 1 as the bonus day.
                $useNowDays = 2;
                $transactions[] = [
                    'transaction_type' => 'accrual',
                    'period_from' => $eventDate->toDateString(),
                    'period_to' => $deadline->toDateString(),
                    'days_dd' => $useNowDays,
                    'remaining_dd' => $useNowDays,
                    'document_id' => $doc->id,
                    'description' => "{$config->name}: {$useNowDays} DD (izmantots uzreiz, notikums " . $eventDate->format('d.m.Y') . ")" . $statusLabel,
                ];
                $algorithm[] = "Notikums " . $eventDate->format('d.m.Y') . " → {$useNowDays} DD (izmantots uzreiz, 1 diena kompensē prombūtni, 1 paliek atlikumā)";
            } elseif ($docAddToAnnual) {
                // Accrue 1 day to Donor balance (will be consumed normally if the document duration covers working days).
                // Ikgadējais (tip 1) will independently pull 1 extra day when calculating its own balance.
                $transactions[] = [
                    'transaction_type' => 'accrual',
                    'period_from' => $eventDate->toDateString(),
                    'period_to' => $deadline->toDateString(),
                    'days_dd' => $eventDays,
                    'remaining_dd' => $eventDays,
                    'document_id' => $doc->id,
                    'description' => "{$config->name}: {$eventDays} DD (notikums " . $eventDate->format('d.m.Y') . ")" . $statusLabel,
                ];
                $algorithm[] = "Notikums " . $eventDate->format('d.m.Y') . " → 1 DD uzkrāta donora bilancē, un 1 DD automātiski pārcelta uz ikgadējo atvaļinājumu.";
            } else {
                $transactions[] = [
                    'transaction_type' => 'accrual',
                    'period_from' => $eventDate->toDateString(),
                    'period_to' => $deadline->toDateString(),
                    'days_dd' => $eventDays,
                    'remaining_dd' => $eventDays,
                    'document_id' => $doc->id,
                    'description' => "{$config->name}: {$eventDays} DD (notikums " . $eventDate->format('d.m.Y') . ", termiņš līdz " . $deadline->format('d.m.Y') . ")" . $statusLabel,
                ];
                $algorithm[] = "Notikums " . $eventDate->format('d.m.Y') . " → {$eventDays} DD, termiņš: " . $deadline->format('d.m.Y') . $statusLabel;
            }
        }

        return [$transactions, $algorithm];
    }

    // =========================================================================
    // METHOD 4: ON-REQUEST (no automatic accrual)
    // Used by: Bezalgas, Bērna kopšana, Grūtniecība, Radošais
    // =========================================================================

    protected function accrueOnRequest(Employee $employee, VacationConfig $config, Carbon $baseDate, Carbon $referenceDate, array $rules): array
    {
        $algorithm = [];

        $lawRef = $rules['law_reference'] ?? '';
        $description = $config->description ?: '';

        $algorithm[] = "📋 **{$config->name}** ({$lawRef})";
        $algorithm[] = "Metode: piešķir pēc pieprasījuma";
        $algorithm[] = "Nav automātiska uzkrājuma — piešķir pēc darbinieka/darba devēja vienošanās.";

        if ($rules['shifts_working_year'] ?? false) {
            $threshold = $rules['shifts_working_year_threshold_weeks'] ?? 4;
            $algorithm[] = "⚠️ Periods >{$threshold} nedēļas nobīda darba gadu ikgadējā atvaļinājuma aprēķinam.";
        }

        if ($description) {
            $algorithm[] = "ℹ️ {$description}";
        }

        return [[], $algorithm];
    }

    // =========================================================================
    // USAGE / CONSUMPTION
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

            $isUnpaid = !empty($payload['is_unpaid']) ? ' (Neapmaksāts)' : '';

            $usageTransactions[] = [
                'transaction_type' => 'usage',
                'period_from' => $start->toDateString(),
                'period_to' => $end->toDateString(),
                'days_dd' => -$dd,
                'remaining_dd' => 0,
                'document_id' => $doc->id,
                'description' => "Izmantots {$dd} DD / {$kd} KD ({$config->name}{$isUnpaid}, " . $start->format('d.m.Y') . " – " . $end->format('d.m.Y') . ")",
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
            ->whereIn('type', ['vacation', 'unpaid_leave', 'study_leave'])
            ->get();

        foreach ($shiftingDocs as $doc) {
            $payload = is_string($doc->payload) ? json_decode($doc->payload, true) : $doc->payload;
            $configId = $payload['vacation_config_id'] ?? null;
            if (!$configId) continue;

            $config = VacationConfig::find($configId);
            if (!$config) continue;

        $rules = $config->rules;
        while (is_string($rules)) {
            $decoded = json_decode($rules, true);
            if (json_last_error() !== JSON_ERROR_NONE) break;
            if ($rules === $decoded) break;
            $rules = $decoded;
        }
        $rules = is_array($rules) ? $rules : [];
            
            $shiftsFromRule = $rules['shifts_working_year'] ?? false;
            $isUnpaidStudyLeave = ($doc->type === 'study_leave' && !empty($payload['is_unpaid']));

            if (!$shiftsFromRule && !$isUnpaidStudyLeave) continue;

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
                $rules = $config->rules;
                while (is_string($rules)) {
                    $decoded = json_decode($rules, true);
                    if (json_last_error() !== JSON_ERROR_NONE) break;
                    if ($rules === $decoded) break;
                    $rules = $decoded;
                }
                $rules = is_array($rules) ? $rules : [];
                
                $shiftsFromRule = $rules['shifts_working_year'] ?? false;
                $isUnpaidStudyLeave = ($doc->type === 'study_leave' && !empty($payload['is_unpaid']));
                
                return $shiftsFromRule || $isUnpaidStudyLeave;
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
