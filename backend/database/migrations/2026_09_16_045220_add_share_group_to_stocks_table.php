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
        Schema::table('stocks', function (Blueprint $table) {
            // NEPSE's own listing tier (e.g. "A", "B", "N") — captured as a
            // byproduct of the dividend-application fetch, the only NEPSE
            // endpoint seen to expose it. Nullable and patchy by design: many
            // stocks (esp. those with no dividend history) will never have
            // this populated, so nothing should hard-require it.
            $table->string('share_group', 10)->nullable()->after('sector');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn('share_group');
        });
    }
};
