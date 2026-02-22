<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create leave_transactions table (warehouse: приход/расход)
        Schema::create('leave_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('vacation_config_id')->constrained('vacation_configs')->cascadeOnDelete();
            $table->enum('transaction_type', ['accrual', 'usage', 'expiration']);
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->decimal('days_dd', 10, 5);         // working days (transaction amount)
            $table->decimal('remaining_dd', 10, 5)->default(0); // remaining from this accrual (FIFO)
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->text('description')->nullable();    // algorithm explanation
            $table->timestamps();

            $table->index(['employee_id', 'vacation_config_id', 'transaction_type']);
        });

        // 2. Remove money columns from documents (amount)
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('amount');
        });

        // 3. Remove alga from employees
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('alga');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_transactions');

        Schema::table('documents', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->nullable()->after('days');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('alga', 10, 2)->nullable()->after('nodala');
        });
    }
};
