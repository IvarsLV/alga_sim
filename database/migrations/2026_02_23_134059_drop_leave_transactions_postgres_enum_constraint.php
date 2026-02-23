<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE leave_transactions DROP CONSTRAINT IF EXISTS leave_transactions_transaction_type_check;');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Constraint was dropped permanently to allow string values like 'transferred_in'
    }
};
