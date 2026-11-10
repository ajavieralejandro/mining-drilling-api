<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_core_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('origin_id', 64);
            $table->string('hole_id', 64);
            $table->string('box_number', 64)->nullable();
            $table->decimal('run_from', 12, 2);
            $table->decimal('run_to', 12, 2);
            $table->decimal('drill_length', 12, 2);
            $table->decimal('recovered_meters', 12, 2);
            $table->decimal('rqd_meters', 12, 2);
            $table->decimal('rqd_percentage', 5, 2);
            $table->decimal('core_loss_meters', 12, 2)->default(0);
            $table->foreignId('logged_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('logged_at');
            $table->text('geology_notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'origin_id', 'hole_id', 'run_from'], 'th_core_logs_tenant_hole_run_idx');
            $table->index(['tenant_id', 'logged_at'], 'th_core_logs_tenant_logged_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_core_logs');
    }
};
