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
                'description' => 'Saskaņā ar Darba likuma 149. pantu, ikvienam darbiniekam ir tiesības uz ikgadējo apmaksāto atvaļinājumu. Atvaļinājuma laiks ir četras kalendāra nedēļas (20 darba dienas), neskaitot svētku dienas. Atvaļinājumu apmaksā, aprēķinot vidējo izpeļņu par pēdējiem 6 mēnešiem.',
                'is_accruable' => true,
                'norm_days' => 20,
                'rules' => json_encode(['measure_unit' => 'DD', 'financial_formula' => 'average_salary', 'shifts_working_year' => false]),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tip' => 2,
                'name' => 'Bērna kopšanas atvaļinājums',
                'description' => 'Darba likuma 156. pants. Piešķir sakarā ar bērna dzimšanu vai adopciju uz laiku līdz pusotram gadam. Darba devējs šo atvaļinājumu neapmaksā (apmaksā VSAA). Ja atvaļinājums pārsniedz 4 nedēļas, tas pagarinās darba gadu (nobīda bāzes datumu) ikgadējā atvaļinājuma aprēķinam (DL 152. panta otrā daļa).',
                'is_accruable' => false,
                'norm_days' => 0,
                'rules' => json_encode(['measure_unit' => 'DD', 'financial_formula' => 'unpaid', 'shifts_working_year' => true]),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tip' => 3,
                'name' => 'Mācību atvaļinājums',
                'description' => 'Darba likuma 157. pants. Darbiniekam var piešķirt mācību atvaļinājumu līdz 20 darba dienām gadā. Ja mācības ir saistītas ar darbu, jāsaglabā darba alga. Izlaiduma/diplomdarba aizstāvēšanai piešķir 20 apmaksātas dienas.',
                'is_accruable' => false,
                'norm_days' => 0,
                'rules' => json_encode(['measure_unit' => 'DD', 'financial_formula' => 'base_salary', 'shifts_working_year' => false]),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tip' => 4,
                'name' => 'Bezalgas atvaļinājums',
                'description' => 'Darba likuma 153. pants. Pēc darbinieka pieprasījuma var piešķirt atvaļinājumu bez darba un vidējās izpeļņas saglabāšanas. Laiks, kas pārsniedz 4 nedēļas viena darba gada laikā, neietilpst laikā, kas dod tiesības uz ikgadējo atvaļinājumu.',
                'is_accruable' => false,
                'norm_days' => 0,
                'rules' => json_encode(['measure_unit' => 'DD', 'financial_formula' => 'unpaid', 'shifts_working_year' => true]),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tip' => 5,
                'name' => 'Papildatvaļinājums par bērniem',
                'description' => 'Darba likuma 150. pants un 151. pants. Darbiniekiem ar 1-2 bērniem (līdz 14 g.) – 1 apmaksāta diena. Ar 3+ bērniem vai bērnu invalīdu (līdz 18 g.) – 3 apmaksātas dienas. Apmaksā pēc vidējās izpeļņas.',
                'is_accruable' => false,
                'norm_days' => 0,
                'rules' => json_encode(['measure_unit' => 'DD', 'financial_formula' => 'average_salary', 'shifts_working_year' => false]),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tip' => 6,
                'name' => 'Grūtniecības un dzemdību atvaļinājums',
                'description' => 'Darba likuma 154. pants. Grūtniecības (56 vai 70 dienas) un dzemdību (56 vai 70 dienas) atvaļinājums. Tiek izsniegta B lapa, kuru apmaksā valsts (VSAA). Šis laiks (pirmsdzemdību/pēcdzemdību) dod tiesības uz ikgadējo atvaļinājumu, tāpēc bāzes gadu nenobīda.',
                'is_accruable' => false,
                'norm_days' => 0,
                'rules' => json_encode(['measure_unit' => 'DD', 'financial_formula' => 'unpaid', 'shifts_working_year' => false]),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tip' => 7,
                'name' => 'Paternitātes atvaļinājums',
                'description' => 'Darba likuma 155. pants. Bērna tēvam ir tiesības uz 10 darba dienu ilgu atvaļinājumu. Atvaļinājumu apmaksā VSAA (nevis darba devējs).',
                'is_accruable' => false,
                'norm_days' => 0,
                'rules' => json_encode(['measure_unit' => 'DD', 'financial_formula' => 'unpaid', 'shifts_working_year' => false]),
                'created_at' => now(), 'updated_at' => now(),
            ],

            [
                'tip' => 10,
                'name' => 'Asins donora diena',
                'description' => 'Darba likuma 74. pants (6. daļa). Pēc asins ziedošanas darbiniekam piešķir apmaksātu atpūtas dienu nākamo reizi. Darba devējam jāapmaksā vidējā izpeļņa par šo brīvdienu.',
                'is_accruable' => false,
                'norm_days' => 0,
                'rules' => json_encode(['measure_unit' => 'DD', 'financial_formula' => 'average_salary', 'shifts_working_year' => false]),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tip' => 11,
                'name' => 'Radošais atvaļinājums',
                'description' => 'Saskaņā ar DL vai kolektīvo līgumu pētniekiem/zinātniekiem, autoriem grāmatu un disku izdošanai u.c. Parasti šādu periodu var neapmaksāt vai apmaksāt atbilstoši vienošanās noteikumiem.',
                'is_accruable' => false,
                'norm_days' => 0,
                'rules' => json_encode(['measure_unit' => 'DD', 'financial_formula' => 'unpaid', 'shifts_working_year' => false]),
                'created_at' => now(), 'updated_at' => now(),
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
