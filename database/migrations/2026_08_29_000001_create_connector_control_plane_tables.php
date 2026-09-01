<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('connectors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('status', 32)->default('pending_auth');
            $table->string('version')->nullable();
            $table->json('capabilities')->nullable();
            $table->string('connector_token_hash', 64)->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('connector_enrollment_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignUlid('created_connector_id')->nullable()->constrained('connectors')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'consumed_at']);
        });

        Schema::create('connector_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('connector_id')->constrained('connectors')->cascadeOnDelete();
            $table->string('status', 32)->default('active');
            $table->timestamp('started_at');
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['connector_id', 'status']);
        });

        Schema::create('connector_commands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('request_id', 64)->unique();
            $table->string('correlation_id', 64);
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('connector_id')->constrained('connectors')->cascadeOnDelete();
            $table->string('op');
            $table->json('payload_json');
            $table->string('status', 32)->default('pending');
            $table->json('result_json')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('deadline_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index('correlation_id');
            $table->index(['connector_id', 'status']);
        });

        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('request_id', 64)->nullable()->index();
            $table->string('correlation_id', 64)->nullable()->index();
            $table->string('actor_type', 32);
            $table->string('actor_id')->nullable();
            $table->foreignUlid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUlid('connector_id')->nullable()->constrained('connectors')->nullOnDelete();
            $table->string('op')->nullable();
            $table->string('event');
            $table->json('result_summary')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
        Schema::dropIfExists('connector_commands');
        Schema::dropIfExists('connector_sessions');
        Schema::dropIfExists('connector_enrollment_tokens');
        Schema::dropIfExists('connectors');
        Schema::dropIfExists('tenants');
    }
};
