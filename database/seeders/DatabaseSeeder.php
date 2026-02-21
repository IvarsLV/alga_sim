<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@alga.lv',
        ]);

        $employee = \App\Models\Employee::create([
            'vards' => 'Jānis',
            'uzvards' => 'Bērziņš',
            'sakdatums' => '2020-01-01',
            'amats' => 'Būvdarbu vadītājs',
            'nodala' => 'Būvniecības nodaļa',
            'alga' => 1500.00,
        ]);

        \App\Models\VacationConfig::insert([
            [
                'tip' => 1,
                'name' => 'Ikgadējais atvaļinājums',
                'is_accruable' => true,
                'norm_days' => 20,
                'rules' => json_encode([
                    'measure_unit' => 'DD',
                    'financial_formula' => 'average_salary',
                    'shifts_working_year' => false,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tip' => 2,
                'name' => 'Bērna kopšanas atvaļinājums',
                'is_accruable' => false,
                'norm_days' => 0,
                'rules' => json_encode([
                    'measure_unit' => 'DD',
                    'financial_formula' => 'unpaid',
                    'shifts_working_year' => true,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tip' => 3,
                'name' => 'Mācību atvaļinājums',
                'is_accruable' => false,
                'norm_days' => 0,
                'rules' => json_encode([
                    'measure_unit' => 'DD',
                    'financial_formula' => 'base_salary',
                    'shifts_working_year' => false,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        \App\Models\Document::create([
            'employee_id' => $employee->id,
            'type' => 'hire',
            'date_from' => '2020-01-01',
            'date_to' => null,
            'days' => null,
            'amount' => null,
            'payload' => json_encode([]),
        ]);

        \App\Models\Document::create([
            'employee_id' => $employee->id,
            'type' => 'child_registration',
            'date_from' => '2020-05-10',
            'date_to' => null,
            'days' => null,
            'amount' => null,
            'payload' => json_encode(['child_dob' => '2020-05-10', 'is_disabled' => false]),
        ]);

        for ($i = 1; $i <= 6; $i++) {
            \App\Models\Document::create([
                'employee_id' => $employee->id,
                'type' => 'salary_calculation',
                'date_from' => now()->startOfMonth()->subMonths($i)->toDateString(),
                'date_to' => now()->endOfMonth()->subMonths($i)->toDateString(),
                'days' => 21,
                'amount' => 1500.00,
                'payload' => json_encode([]),
            ]);
        }
    }
}
