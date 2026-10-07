<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('website', 60)->unique()->comment('The website that supplies data: nepalstock.com, sharesansar.com, merolagani.com (see config/services.php source_block.websites).');
            $table->boolean('is_blocked')->default(false)->comment('True while this website is refusing us (a network web filter, an HTTP 403 / 429, an interrupted certificate). Every request to it is skipped until blocked_until.');
            $table->string('reason', 500)->nullable()->comment('What the website answered the last time it failed, e.g. "HTTP 403: Web Page Blocked".');
            $table->unsignedSmallInteger('http_status')->nullable()->comment('HTTP status of that failure; null for a connection or certificate failure.');
            $table->unsignedSmallInteger('consecutive_failures')->default(0)->comment('Failures in a row since the last success; an outage (not a refusal) blocks the website once this reaches the configured limit.');
            $table->unsignedInteger('times_blocked')->default(0)->comment('How many times this website has been blocked, all time.');
            $table->timestamp('blocked_at')->nullable()->comment('When the current block started.');
            $table->timestamp('blocked_until')->nullable()->comment('Requests are skipped until then; the first request after it is the retry.');
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_blocks');
    }
};
