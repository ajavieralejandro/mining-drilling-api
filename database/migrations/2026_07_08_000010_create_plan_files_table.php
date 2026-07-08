<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drilling_plan_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('version')->nullable();
            $table->text('description')->nullable();
            $table->string('file_path')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_files');
    }
};
