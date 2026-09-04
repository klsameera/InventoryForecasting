<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per attempt to pull data from the BuyAbans back office. Keeping
     * failures as rows rather than log lines is deliberate: a sync that
     * silently stopped succeeding is the failure mode that quietly rots every
     * forecast downstream, and it has to be visible in the UI.
     */
    public function up(): void
    {
        Schema::create('buyabans_sync_runs', function (Blueprint $table) {
            $table->id();
            // Named 'stage', not 'resource': JsonResource exposes a public
            // $resource property holding the wrapped model, so a column of
            // that name is shadowed inside any API Resource — $this->resource
            // silently returns the model rather than the column value.
            $table->string('stage');
            $table->string('status')->default('running');
            $table->unsignedBigInteger('records_fetched')->default(0);
            $table->unsignedBigInteger('records_written')->default(0);
            $table->unsignedInteger('pages')->default(0);
            $table->string('grain')->nullable();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->text('message')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['stage', 'status']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyabans_sync_runs');
    }
};
