<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ml_model_versions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('version');
            $table->string('model_type');
            $table->date('training_start_date')->nullable();
            $table->date('training_end_date')->nullable();
            $table->unsignedInteger('training_rows')->default(0);
            $table->json('accuracy_metrics')->nullable();
            $table->string('feature_schema_version')->nullable();
            $table->string('model_path')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['name', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ml_model_versions');
    }
};
