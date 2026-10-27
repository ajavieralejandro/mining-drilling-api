<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_hole_operational_states', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('origin_id', 64);
            $table->string('hole_id', 64);
            $table->string('status', 32)->default('in_progress');
            $table->decimal('initial_depth', 12, 2)->nullable();
            $table->decimal('current_depth', 12, 2)->nullable();
            $table->boolean('depth_initialized')->default(false);
            $table->timestamp('depth_initialized_at')->nullable();
            $table->foreignId('depth_initialized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'origin_id', 'hole_id'], 'th_operational_state_tenant_origin_hole_uniq');
            $table->index(['tenant_id', 'status'], 'th_operational_state_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_hole_operational_states');
    }
};
