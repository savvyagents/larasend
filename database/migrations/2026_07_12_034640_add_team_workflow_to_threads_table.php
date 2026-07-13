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
        Schema::table('threads', function (Blueprint $table) {
            $table->string('status')->default('open')->after('snoozed_until')->index();
            $table->string('priority')->default('normal')->after('status')->index();
            $table->foreignId('assigned_to_user_id')->nullable()->after('priority')->constrained('users')->nullOnDelete();
            $table->json('tags')->nullable()->after('assigned_to_user_id');
            $table->index(['project_id', 'status', 'assigned_to_user_id', 'last_activity_at'], 'threads_team_queue_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropIndex('threads_team_queue_index');
            $table->dropConstrainedForeignId('assigned_to_user_id');
            $table->dropColumn(['status', 'priority', 'tags']);
        });
    }
};
