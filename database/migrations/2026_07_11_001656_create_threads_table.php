<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('threads')) {
            return;
        }

        Schema::create('threads', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('subject')->nullable();
            $table->string('subject_key')->index();
            $table->json('participants')->nullable();
            $table->string('last_direction')->default('inbound');
            $table->text('last_snippet')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamp('last_activity_at')->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'archived_at', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threads');
    }
};
