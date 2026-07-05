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
        Schema::table('api_keys', function (Blueprint $table) {
            $table->json('scopes')->nullable()->after('key_hash');
            $table->string('last_used_ip', 45)->nullable()->after('last_used_at');
            $table->text('last_used_user_agent')->nullable()->after('last_used_ip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn(['scopes', 'last_used_ip', 'last_used_user_agent']);
        });
    }
};
