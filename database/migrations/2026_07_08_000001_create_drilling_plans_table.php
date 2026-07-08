<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drilling_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('mine');
            $table->string('level');
            $table->string('sector');
            $table->string('purpose');
            $table->decimal('planned_meters', 12, 2);
            $table->decimal('executed_meters', 12, 2)->default(0);
            $table->string('status')->default('planned');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drilling_plans');
    }
};
