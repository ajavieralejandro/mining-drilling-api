<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drill_holes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drilling_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('drilling_platform_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('order_number')->nullable();
            $table->string('rec_id')->nullable();
            $table->string('hole_id')->nullable()->unique();
            $table->string('target')->nullable();
            $table->decimal('length', 12, 2);
            $table->decimal('current_depth', 12, 2)->default(0);
            $table->decimal('azimuth', 8, 2);
            $table->decimal('dip', 8, 2);
            $table->string('hole_type')->nullable();
            $table->string('status')->default('planned');
            $table->decimal('easting', 14, 4)->nullable();
            $table->decimal('northing', 14, 4)->nullable();
            $table->decimal('elevation', 12, 4)->nullable();
            $table->string('coordinate_system')->nullable();
            $table->text('observations')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drill_holes');
    }
};
