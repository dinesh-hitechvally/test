<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_models', function (Blueprint $table) {
            // Majority-class accuracy on the same held-out test set — the
            // honest bar the model actually needs to clear to be worth using.
            $table->decimal('baseline_accuracy', 6, 4)->after('accuracy');
        });
    }

    public function down(): void
    {
        Schema::table('ml_models', function (Blueprint $table) {
            $table->dropColumn('baseline_accuracy');
        });
    }
};
