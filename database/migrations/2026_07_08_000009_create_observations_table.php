<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drill_hole_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->text('body');
            $table->string('risk_level')->nullable();
            $table->string('risk_status')->nullable();
            $table->decimal('depth_detected', 12, 2)->nullable();
            $table->decimal('critical_distance', 12, 2)->nullable();
            $table->text('recommended_action')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observations');
    }
};
