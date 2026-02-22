<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $configs = [
            1 => [
                'measure_unit' => 'DD',
                'accrual_method' => 'monthly',
                'accrual_start' => 'from_hire',
                'period_type' => 'working_year',
                'shifts_working_year' => false,
                'payment_status' => 'apmaksāts',
                'financial_formula' => 'average_salary',
                'law_reference' => 'DL 149',
            ],
            2 => [
                'measure_unit' => 'DD',
                'accrual_method' => 'on_request',
                'accrual_start' => 'from_document',
                'period_type' => 'working_year',
                'shifts_working_year' => true,
                'shifts_working_year_threshold_weeks' => 0,
                'payment_status' => 'VSAA',
                'financial_formula' => 'unpaid',
                'law_reference' => 'DL 156',
            ],
            3 => [
                'measure_unit' => 'DD',
                'accrual_method' => 'yearly',
                'accrual_start' => 'from_hire',
                'period_type' => 'calendar_year',
                'shifts_working_year' => false,
                'max_per_year_dd' => 20,
                'expires_end_of_period' => true,
                'payment_status' => 'apmaksāts',
                'financial_formula' => 'base_salary',
                'law_reference' => 'DL 157',
            ],
            4 => [
                'measure_unit' => 'DD',
                'accrual_method' => 'on_request',
                'accrual_start' => 'immediate',
                'period_type' => 'working_year',
                'shifts_working_year' => true,
                'shifts_working_year_threshold_weeks' => 4,
                'payment_status' => 'neapmaksāts',
                'financial_formula' => 'unpaid',
                'law_reference' => 'DL 153',
            ],
            5 => [
                'measure_unit' => 'DD',
                'accrual_method' => 'yearly',
                'accrual_start' => 'from_hire',
                'period_type' => 'calendar_year',
                'shifts_working_year' => false,
                'expires_end_of_period' => true,
                'child_based' => true,
                'payment_status' => 'apmaksāts',
                'financial_formula' => 'average_salary',
                'law_reference' => 'DL 150-151',
            ],
            6 => [
                'measure_unit' => 'KD',
                'accrual_method' => 'on_request',
                'accrual_start' => 'from_document',
                'period_type' => 'working_year',
                'shifts_working_year' => false,
                'payment_status' => 'VSAA',
                'financial_formula' => 'unpaid',
                'law_reference' => 'DL 154',
            ],
            7 => [
                'measure_unit' => 'DD',
                'accrual_method' => 'per_event',
                'event_source' => 'child_registration',
                'event_days' => 10,
                'requires_hire_date_check' => true,
                'accrual_start' => 'from_document',
                'period_type' => 'working_year',
                'shifts_working_year' => false,
                'usage_deadline_months' => 6,
                'payment_status' => 'VSAA',
                'financial_formula' => 'unpaid',
                'law_reference' => 'DL 155',
            ],
            8 => [
                'measure_unit' => 'DD',
                'accrual_method' => 'per_event',
                'event_source' => 'donor_day',
                'event_days' => 1,
                'accrual_start' => 'from_document',
                'period_type' => 'working_year',
                'shifts_working_year' => false,
                'add_to_annual_immediately' => true,
                'requires_document' => true,
                'payment_status' => 'apmaksāts',
                'financial_formula' => 'average_salary',
                'law_reference' => 'DL 74',
            ],
            9 => [
                'measure_unit' => 'DD',
                'accrual_method' => 'on_request',
                'accrual_start' => 'immediate',
                'period_type' => 'working_year',
                'shifts_working_year' => false,
                'payment_status' => 'neapmaksāts',
                'financial_formula' => 'unpaid',
                'law_reference' => 'DL / Kolektīvais',
            ]
        ];

        foreach (DB::table('vacation_configs')->get() as $config) {
            if (isset($configs[$config->tip])) {
                $rulesJson = json_encode($configs[$config->tip]);
                DB::table('vacation_configs')
                    ->where('id', $config->id)
                    ->update(['rules' => $rulesJson]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No down needed
    }
};
