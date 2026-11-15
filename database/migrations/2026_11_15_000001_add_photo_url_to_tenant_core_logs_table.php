<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_core_logs', function (Blueprint $table) {
            $table->string('photo_url', 500)->nullable()->after('geology_notes');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_core_logs', function (Blueprint $table) {
            $table->dropColumn('photo_url');
        });
    }
};
