<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $donor = \App\Models\VacationConfig::where('tip', 10)->first();
        if ($donor) {
            $rules = is_string($donor->rules) ? json_decode($donor->rules, true) : $donor->rules;
            if (is_array($rules)) {
                unset($rules['usage_deadline_days']);
                $rules['usage_deadline_months'] = 12;
                $donor->rules = $rules;
                $donor->save();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $donor = \App\Models\VacationConfig::where('tip', 10)->first();
        if ($donor) {
            $rules = is_string($donor->rules) ? json_decode($donor->rules, true) : $donor->rules;
            if (is_array($rules)) {
                unset($rules['usage_deadline_months']);
                $rules['usage_deadline_days'] = 30; // Assuming 30 was the original value
                $donor->rules = $rules;
                $donor->save();
            }
        }
    }
};
