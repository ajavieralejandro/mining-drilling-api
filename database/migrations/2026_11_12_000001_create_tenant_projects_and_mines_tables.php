<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_projects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 64)->nullable();
            $table->string('name', 160);
            $table->string('status', 32)->default('active');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code'], 'tenant_projects_tenant_code_uniq');
            $table->index(['tenant_id', 'status'], 'tenant_projects_tenant_status_idx');
        });

        Schema::create('tenant_mines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained('tenant_projects')->cascadeOnDelete();
            $table->string('code', 64)->nullable();
            $table->string('name', 160);
            $table->string('location', 160)->nullable();
            $table->string('status', 32)->default('active');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code'], 'tenant_mines_tenant_code_uniq');
            $table->index(['tenant_id', 'project_id'], 'tenant_mines_tenant_project_idx');
            $table->index(['tenant_id', 'status'], 'tenant_mines_tenant_status_idx');
        });

        Schema::create('tenant_mine_holes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('mine_id')->constrained('tenant_mines')->cascadeOnDelete();
            $table->string('origin_id', 64)->nullable();
            $table->string('hole_id', 64);
            $table->string('hole_code', 64)->nullable();
            $table->foreignId('drill_hole_id')->nullable()->constrained('drill_holes')->nullOnDelete();
            $table->string('status', 32)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'hole_id'], 'tenant_mine_holes_tenant_hole_uniq');
            $table->index(['tenant_id', 'mine_id'], 'tenant_mine_holes_tenant_mine_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_mine_holes');
        Schema::dropIfExists('tenant_mines');
        Schema::dropIfExists('tenant_projects');
    }
};
