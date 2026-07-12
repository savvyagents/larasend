<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->foreignId('thread_id')->nullable()->after('template_id')->constrained()->nullOnDelete();
        });

        Schema::table('inbound_emails', function (Blueprint $table) {
            $table->foreignId('thread_id')->nullable()->after('source_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropConstrainedForeignId('thread_id');
        });

        Schema::table('inbound_emails', function (Blueprint $table) {
            $table->dropConstrainedForeignId('thread_id');
        });
    }
};
