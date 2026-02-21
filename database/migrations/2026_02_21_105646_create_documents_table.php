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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->enum('type', ['hire', 'salary_calculation', 'vacation', 'child_registration', 'study_leave', 'unpaid_leave', 'donor_day']);
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->decimal('days', 8, 4)->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
