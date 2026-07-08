<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drilling_platforms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drilling_plan_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->decimal('easting', 14, 4)->nullable();
            $table->decimal('northing', 14, 4)->nullable();
            $table->decimal('elevation', 12, 4)->nullable();
            $table->string('gallery')->nullable();
            $table->string('level')->nullable();
            $table->string('status')->default('planned');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drilling_platforms');
    }
};
