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
            // Nullable — most equities are Rs. 100 and callers fall back to
            // that assumption when this is unset. Mutual fund units (e.g.
            // Rs. 10) and other non-standard instruments need the real
            // value or face-value-based % calculations (like dividend
            // yield) come out nonsensical.
            $table->decimal('face_value', 10, 2)->nullable()->after('sector');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn('face_value');
        });
    }
};
