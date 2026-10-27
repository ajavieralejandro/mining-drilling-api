<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_hole_progress_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('operational_state_id')->constrained('tenant_hole_operational_states')->cascadeOnDelete();
            $table->string('origin_id', 64);
            $table->string('hole_id', 64);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('depth_from', 12, 2);
            $table->decimal('depth_to', 12, 2);
            $table->decimal('depth_current', 12, 2);
            $table->string('shift', 16)->nullable();
            $table->string('idempotency_key', 64);
            $table->char('payload_hash', 64);
            $table->json('crew_snapshot')->nullable();
            $table->timestamp('logged_at');
            $table->text('observations')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'th_progress_tenant_idempotency_uniq');
            $table->index(['tenant_id', 'origin_id', 'hole_id', 'logged_at'], 'th_progress_tenant_origin_hole_logged_idx');
            $table->index(['operational_state_id', 'logged_at'], 'th_progress_state_logged_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_hole_progress_logs');
    }
};
