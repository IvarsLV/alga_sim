<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (User::count() > 0) {
            return;
        }

        User::create([
            'name' => 'Admin User',
            'email' => 'admin@alga.lv',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $employee = \App\Models\Employee::create([
            'vards' => 'Jānis',
            'uzvards' => 'Bērziņš',
            'sakdatums' => '2025-01-01',
            'amats' => 'Projektu vadītājs',
            'nodala' => 'Administrācija',
        ]);

        \App\Models\VacationConfig::insert([
            [
                'tip' => 1,
                'name' => 'Ikgadējais atvaļinājums',
                'description' => 'DL 149. pants. Ikvienam darbiniekam — 4 kalendāra nedēļas (20 DD). Uzkrāj katru mēnesi no darba sākuma datuma. Formula: norma÷12 × nostrādātie mēneši (ATVREZ_YMD algoritms).',
                'is_accruable' => true,
                'norm_days' => 20,
                'rules' => json_encode([
                    'measure_unit' => 'DD',
                    'accrual_method' => 'monthly',
                    'accrual_start' => 'from_hire',
                    'period_type' => 'working_year',
                    'shifts_working_year' => false,
                    'law_reference' => 'DL 149',
                ]),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tip' => 2,
                'name' => 'Bērna kopšanas atvaļinājums',
                'description' => 'DL 156. pants. Līdz 1.5 gadam sakarā ar bērna dzimšanu. VSAA apmaksā. Periods >4 nedēļas nobīda darba gadu.',
                'is_accruable' => false,
                'norm_days' => 0,
                'rules' => json_encode([
                    'measure_unit' => 'DD',
                    'accrual_method' => 'per_event',
                    'accrual_start' => 'from_document',
                    'period_type' => 'working_year',
                    'shifts_working_year' => true,
                    'shifts_working_year_threshold_weeks' => 4,
                    'law_reference' => 'DL 156',
                ]),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tip' => 3,
                'name' => 'Mācību atvaļinājums',
                'description' => 'DL 157. pants. Līdz 20 DD gadā. Ja mācības saistītas ar darbu — saglabā algu. Izlaidumam — 20 apmaksātas DD.',
                'is_accruable' => false,
                'norm_days' => 20,
                'rules' => json_encode([
                    'measure_unit' => 'DD',
                    'accrual_method' => 'yearly',
                    'accrual_start' => 'from_hire',
                    'period_type' => 'calendar_year',
                    'shifts_working_year' => false,
                    'max_per_year_dd' => 20,
                    'law_reference' => 'DL 157',
                ]),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tip' => 4,
                'name' => 'Bezalgas atvaļinājums',
                'description' => 'DL 153. pants. Pēc darbinieka pieprasījuma. Pirmās 4 nedēļas darba gadā nenobīda darba gadu, pārējais nobīda.',
                'is_accruable' => false,
                'norm_days' => 0,
                'rules' => json_encode([
                    'measure_unit' => 'DD',
                    'accrual_method' => 'per_request',
                    'accrual_start' => 'immediate',
                    'period_type' => 'working_year',
                    'shifts_working_year' => true,
                    'shifts_working_year_threshold_weeks' => 4,
                    'law_reference' => 'DL 153',
                ]),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tip' => 5,
                'name' => 'Papildatvaļinājums par bērniem',
                'description' => 'DL 150.-151. pants. 1-2 bērni (<14g.) → 1 DD/gadā. 3+ bērni vai invalīds (<18g.) → 3 DD/gadā. Piešķir par kalendāro gadu.',
                'is_accruable' => false,
                'norm_days' => 0,
                'rules' => json_encode([
                    'measure_unit' => 'DD',
                    'accrual_method' => 'yearly',
                    'accrual_start' => 'from_hire',
                    'period_type' => 'calendar_year',
                    'shifts_working_year' => false,
                    'law_reference' => 'DL 150-151',
                ]),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tip' => 6,
                'name' => 'Grūtniecības un dzemdību atvaļinājums',
                'description' => 'DL 154. pants. 56/70 + 56/70 KD. VSAA apmaksā. NENOBĪDA darba gadu.',
                'is_accruable' => false,
                'norm_days' => 0,
                'rules' => json_encode([
                    'measure_unit' => 'KD',
                    'accrual_method' => 'per_event',
                    'accrual_start' => 'from_document',
                    'period_type' => 'working_year',
                    'shifts_working_year' => false,
                    'law_reference' => 'DL 154',
                ]),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tip' => 7,
                'name' => 'Paternitātes atvaļinājums',
                'description' => 'DL 155. pants. Bērna tēvam — 10 DD. Jāizmanto 2 mēnešu laikā no dzimšanas. VSAA apmaksā.',
                'is_accruable' => false,
                'norm_days' => 10,
                'rules' => json_encode([
                    'measure_unit' => 'DD',
                    'accrual_method' => 'per_event',
                    'accrual_start' => 'from_document',
                    'period_type' => 'working_year',
                    'shifts_working_year' => false,
                    'usage_deadline_months' => 2,
                    'law_reference' => 'DL 155',
                ]),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tip' => 10,
                'name' => 'Asins donora diena',
                'description' => 'DL 74. panta 6. daļa. 1 apmaksāta atpūtas diena pēc asins ziedošanas. Uz donora izziņas pamata.',
                'is_accruable' => false,
                'norm_days' => 1,
                'rules' => json_encode([
                    'measure_unit' => 'DD',
                    'accrual_method' => 'per_event',
                    'accrual_start' => 'from_document',
                    'period_type' => 'calendar_year',
                    'shifts_working_year' => false,
                    'usage_deadline_days' => 30,
                    'requires_document' => true,
                    'law_reference' => 'DL 74 §6',
                ]),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tip' => 11,
                'name' => 'Radošais atvaļinājums',
                'description' => 'DL vai kolektīvais līgums. Pētniekiem, zinātniekiem, autoriem. Pēc vienošanās noteikumiem.',
                'is_accruable' => false,
                'norm_days' => 0,
                'rules' => json_encode([
                    'measure_unit' => 'DD',
                    'accrual_method' => 'per_request',
                    'accrual_start' => 'immediate',
                    'period_type' => 'calendar_year',
                    'shifts_working_year' => false,
                    'law_reference' => 'DL / Kolektīvais līgums',
                ]),
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        // Hire document
        \App\Models\Document::create([
            'employee_id' => $employee->id,
            'type' => 'hire',
            'date_from' => '2025-01-01',
            'date_to' => null,
            'days' => null,
            'payload' => json_encode([]),
        ]);

        // Child registration
        \App\Models\Document::create([
            'employee_id' => $employee->id,
            'type' => 'child_registration',
            'date_from' => '2020-05-10',
            'date_to' => null,
            'days' => null,
            'payload' => json_encode(['child_dob' => '2020-05-10', 'is_disabled' => false]),
        ]);

        // Example vacation usage (ikgadējais)
        \App\Models\Document::create([
            'employee_id' => $employee->id,
            'type' => 'vacation',
            'date_from' => '2025-11-03',
            'date_to' => '2025-11-05',
            'days' => 3,
            'payload' => json_encode(['vacation_config_id' => 1]),
        ]);
    }
}
