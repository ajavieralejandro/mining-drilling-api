<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-04. connector_commands.payload_json and result_json become encrypted
 * text (the model casts them with encrypted:array). Operational data already
 * stored is not re-encrypted: it is purged, as it should have been. Only
 * source_config.* commands keep their data, which holds no operational rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_commands', function (Blueprint $table) {
            $table->text('payload_json')->change();
            $table->text('result_json')->nullable()->change();
        });

        $emptyPayload = Crypt::encryptString(json_encode([]));

        DB::table('connector_commands')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($emptyPayload) {
                foreach ($rows as $row) {
                    if (! str_starts_with((string) $row->op, 'source_config.')) {
                        DB::table('connector_commands')->where('id', $row->id)->update([
                            'payload_json' => $emptyPayload,
                            'result_json' => null,
                        ]);

                        continue;
                    }

                    DB::table('connector_commands')->where('id', $row->id)->update([
                        'payload_json' => Crypt::encryptString($this->normalize($row->payload_json) ?? '[]'),
                        'result_json' => ($result = $this->normalize($row->result_json)) === null
                            ? null
                            : Crypt::encryptString($result),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('connector_commands')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('connector_commands')->where('id', $row->id)->update([
                        'payload_json' => $this->decrypt($row->payload_json) ?? '[]',
                        'result_json' => $this->decrypt($row->result_json),
                    ]);
                }
            });

        Schema::table('connector_commands', function (Blueprint $table) {
            $table->json('payload_json')->change();
            $table->json('result_json')->nullable()->change();
        });
    }

    private function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        return is_string($value) ? $value : json_encode($value);
    }

    private function decrypt(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return $value;
        }
    }
};
