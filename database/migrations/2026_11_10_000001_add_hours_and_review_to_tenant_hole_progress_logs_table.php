<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_hole_progress_logs', function (Blueprint $table) {
            $table->decimal('hours_worked', 4, 2)->nullable()->after('shift');
            $table->string('status', 32)->default('pending_review')->after('hours_worked');
            $table->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_notes')->nullable()->after('reviewed_at');

            $table->index(['tenant_id', 'status'], 'th_progress_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_hole_progress_logs', function (Blueprint $table) {
            $table->dropIndex('th_progress_tenant_status_idx');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['hours_worked', 'status', 'reviewed_at', 'review_notes']);
        });
    }
};
