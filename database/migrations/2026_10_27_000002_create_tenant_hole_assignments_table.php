<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_hole_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('operational_state_id')->constrained('tenant_hole_operational_states')->cascadeOnDelete();
            $table->string('origin_id', 64);
            $table->string('hole_id', 64);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role_in_hole', 32);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_from')->nullable();
            $table->timestamp('assigned_to')->nullable();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'origin_id', 'hole_id', 'active'], 'th_assignments_tenant_origin_hole_active_idx');
            $table->index(['tenant_id', 'user_id', 'active'], 'th_assignments_tenant_user_active_idx');
            $table->index(['operational_state_id', 'active'], 'th_assignments_state_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_hole_assignments');
    }
};
