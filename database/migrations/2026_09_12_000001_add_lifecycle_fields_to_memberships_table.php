<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the trail fields the identity/lifecycle audit (2026-09-12) found
 * missing on `memberships`, and enforces "at most one active membership per
 * user" as close to the data as this stack reliably allows.
 *
 * Production targets PostgreSQL; tests/local dev run on SQLite (see
 * .env.example vs phpunit.xml). Both engines support the same partial
 * unique index syntax (`CREATE UNIQUE INDEX ... WHERE status = 'active'`),
 * so one raw statement covers both — this is NOT portable to MySQL, which
 * this project does not target. On any other driver the index is skipped
 * and the invariant relies solely on the application-layer lock in
 * MembershipLifecycleService — documented, not silently assumed.
 */
return new class extends Migration
{
    private const string INDEX_NAME = 'memberships_one_active_per_user';

    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->string('corporate_email')->nullable()->after('role');
            $table->string('external_hr_id')->nullable()->after('corporate_email');
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('ended_at')->nullable()->after('started_at');
            $table->foreignId('deactivated_by')->nullable()->after('ended_at')
                ->constrained('users')->nullOnDelete();
            $table->string('deactivation_reason', 500)->nullable()->after('deactivated_by');

            $table->index('corporate_email');
            $table->index('external_hr_id');
        });

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEX_NAME.
                " ON memberships (user_id) WHERE status = 'active'"
            );
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX_NAME);
        }

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropIndex(['corporate_email']);
            $table->dropIndex(['external_hr_id']);
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn([
                'corporate_email',
                'external_hr_id',
                'started_at',
                'ended_at',
                'deactivation_reason',
            ]);
        });
    }
};
