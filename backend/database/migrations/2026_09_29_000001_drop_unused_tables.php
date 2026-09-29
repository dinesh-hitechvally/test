<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * forecast_models, ipo_listings and news_articles were created early on but
 * never used — no model, no code, always empty. Their create migrations were
 * removed; this drops the tables from databases that already have them.
 * A fresh install never creates them, so this is a no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('forecast_models');
        Schema::dropIfExists('ipo_listings');
        Schema::dropIfExists('news_articles');
    }

    public function down(): void
    {
        // Nothing to restore — the tables were always empty and unused.
    }
};
