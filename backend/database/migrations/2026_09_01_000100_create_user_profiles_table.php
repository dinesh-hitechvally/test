<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            // One row per user, keyed by the user itself.
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('first_name', 60)->nullable();
            $table->string('last_name', 60)->nullable();
            $table->string('phone', 30)->nullable()->comment('Free format with country code, e.g. +977 98XXXXXXXX.');
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('occupation', 80)->nullable()->comment('What the person does, e.g. Software Developer.');
            $table->string('bio', 500)->nullable();
            $table->string('timezone', 64)->nullable()->comment('IANA name, e.g. Asia/Kathmandu; the app shows times in it.');
            $table->string('avatar', 60)->nullable()->comment('File name of the profile picture in storage/app/private/avatars (random, so its URL cannot be guessed).');
            $table->string('country', 80)->nullable();
            $table->string('province', 80)->nullable()->comment('Province / state.');
            $table->string('city', 80)->nullable();
            $table->string('street_address', 150)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
