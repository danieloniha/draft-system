<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A guest is someone who joined a session from a link with just a name: no email,
            // no password they know. They can play, but not host (see EnsureUserHasAccount).
            $table->boolean('is_guest')->default(false)->after('password');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Guests have no email, so they cannot survive the column going back to NOT NULL.
        DB::table('users')->where('is_guest', true)->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_guest');
        });
    }
};
