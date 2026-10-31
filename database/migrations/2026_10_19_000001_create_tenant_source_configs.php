<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_source_policies', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')->primary()->constrained('tenants')->cascadeOnDelete();
            $table->boolean('allow_loopback')->default(false);
            $table->boolean('allow_private')->default(true);
            $table->json('allowed_hosts')->nullable();
            $table->json('allowed_ports')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_source_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('connector_id')->constrained('connectors')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('state', 32);
            $table->string('display_name');
            $table->string('engine', 32);
            $table->string('host');
            $table->unsignedSmallInteger('port');
            $table->string('database_name');
            $table->string('schema_name')->nullable();
            $table->string('username');
            $table->text('password_ciphertext');
            $table->text('ca_ciphertext')->nullable();
            $table->string('tls_mode', 32);
            $table->string('installation_location')->nullable();
            $table->char('fingerprint', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('tested_at')->nullable();
            $table->string('test_error_code')->nullable();
            $table->unsignedInteger('test_latency_ms')->nullable();
            $table->boolean('test_tls_verified')->nullable();
            $table->boolean('test_read_only')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['connector_id', 'version']);
            $table->index(['connector_id', 'state']);
        });

        Schema::table('connectors', function (Blueprint $table) {
            $table->json('reported_source')->nullable();
            $table->timestamp('reported_source_at')->nullable();
            $table->json('last_hole_query')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('connectors', function (Blueprint $table) {
            $table->dropColumn(['reported_source', 'reported_source_at', 'last_hole_query']);
        });
        Schema::dropIfExists('tenant_source_configs');
        Schema::dropIfExists('tenant_source_policies');
    }
};
