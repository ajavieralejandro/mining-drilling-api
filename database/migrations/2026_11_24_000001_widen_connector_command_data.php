<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL TEXT holds 64 KB and encryption enlarges the data, so a large
 * connector result would not fit. Existing values are kept as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_commands', function (Blueprint $table) {
            $table->longText('payload_json')->change();
            $table->longText('result_json')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Intentionally empty: shrinking the columns could reject or cut
        // encrypted data that only fits in LONGTEXT.
    }
};
