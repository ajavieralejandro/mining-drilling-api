<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drilling_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('drilling_platform_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_to')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_availability');
    }
};
