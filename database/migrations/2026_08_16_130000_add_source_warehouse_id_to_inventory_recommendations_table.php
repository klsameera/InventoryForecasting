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
        Schema::table('inventory_recommendations', function (Blueprint $table) {
            $table->foreignId('source_warehouse_id')->nullable()->after('warehouse_id')
                ->constrained('warehouses')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_recommendations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_warehouse_id');
        });
    }
};
