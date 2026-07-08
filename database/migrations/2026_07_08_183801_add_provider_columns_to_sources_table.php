<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->string('provider')->default('ses')->after('environment');
            $table->text('cloudflare_api_token')->nullable()->after('aws_session_token');
            $table->string('cloudflare_account_id')->nullable()->after('cloudflare_api_token');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['provider', 'cloudflare_api_token', 'cloudflare_account_id']);
        });
    }
};
