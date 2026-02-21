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
        Schema::create('vacation_configs', function (Blueprint $table) {
            $table->id();
            $table->integer('tip');
            $table->string('name');
            $table->boolean('is_accruable')->default(false);
            $table->decimal('norm_days', 8, 4)->nullable();
            $table->json('rules')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vacation_configs');
    }
};
