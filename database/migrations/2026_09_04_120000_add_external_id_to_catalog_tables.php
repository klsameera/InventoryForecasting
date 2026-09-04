<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The BuyAbans product id, on the two catalog tables that had no stable
     * external key of their own.
     *
     * Everything else the sync writes could be keyed on something already
     * unique and meaningful — a category's `code`, a warehouse's
     * `location_code`, a SKU's own code. Products and variants could not:
     * `products` has no code at all, and `product_variants` is identified only
     * by a name that is not unique. Without a key, a re-sync cannot tell an
     * existing record from a new one, and either duplicates the catalog or
     * silently rewrites the wrong row.
     *
     * Nullable because a record may predate the integration; unique so a second
     * sync of the same back-office product can only ever update one row.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('external_id')->nullable()->unique()->after('id');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedBigInteger('external_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn('external_id');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn('external_id');
        });
    }
};
