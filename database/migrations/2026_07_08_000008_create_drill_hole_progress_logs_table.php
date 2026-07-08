<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drill_hole_progress_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drill_hole_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('depth_from', 12, 2);
            $table->decimal('depth_to', 12, 2);
            $table->decimal('depth_current', 12, 2);
            $table->string('shift')->nullable();
            $table->dateTime('logged_at');
            $table->text('observations')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drill_hole_progress_logs');
    }
};
