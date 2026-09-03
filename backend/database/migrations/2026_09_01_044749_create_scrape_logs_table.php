<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 100);
            $table->enum('status', ['success', 'failed']);
            $table->unsignedInteger('records_processed')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_logs');
    }
};
