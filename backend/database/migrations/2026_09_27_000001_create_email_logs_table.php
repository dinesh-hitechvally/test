<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            // sending → sent | logged (the "log" mailer: written to the log file, not delivered) | failed
            $table->string('status', 20)->index();
            $table->string('mailer', 50);
            $table->string('message_id')->nullable();
            $table->string('from_address')->nullable();
            $table->json('to');
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('subject')->nullable();
            // What sent it: a notification or mailable class, or "raw" for Mail::raw().
            $table->string('source')->nullable();
            // The app user the first recipient belongs to, if any.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index('attempted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
