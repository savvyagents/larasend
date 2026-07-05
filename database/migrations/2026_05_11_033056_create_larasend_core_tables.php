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
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('workspace_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('default_environment')->default('prod');
            $table->timestamps();

            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('status')->default('pending');
            $table->json('dns_records')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'domain']);
        });

        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('environment')->default('prod');
            $table->string('ses_region')->default('us-east-1');
            $table->string('ses_configuration_set')->nullable();
            $table->string('default_from_name')->nullable();
            $table->string('default_from_email')->nullable();
            $table->text('aws_access_key_id')->nullable();
            $table->text('aws_secret_access_key')->nullable();
            $table->string('webhook_token')->unique();
            $table->unsignedInteger('retention_days')->default(90);
            $table->unsignedInteger('monthly_quota')->nullable();
            $table->unsignedInteger('max_send_rate')->nullable();
            $table->timestamp('last_quota_checked_at')->nullable();
            $table->json('last_quota')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'environment']);
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('prefix', 16)->index();
            $table->string('key_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('subject');
            $table->longText('html')->nullable();
            $table->longText('text')->nullable();
            $table->json('variables')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'slug']);
        });

        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment')->default('prod')->index();
            $table->string('status')->default('queued')->index();
            $table->string('ses_message_id')->nullable()->index();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('subject');
            $table->longText('html')->nullable();
            $table->longText('text')->nullable();
            $table->string('mime_disk')->default('local');
            $table->string('mime_path')->nullable();
            $table->unsignedBigInteger('mime_size')->nullable();
            $table->json('headers')->nullable();
            $table->json('tags')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'environment', 'created_at']);
            $table->index(['project_id', 'status', 'created_at']);
        });

        Schema::create('email_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('email');
            $table->string('name')->nullable();
            $table->timestamps();

            $table->index(['email', 'created_at']);
        });

        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('disposition')->default('attachment');
            $table->timestamps();
        });

        Schema::create('email_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('event_type')->index();
            $table->string('ses_message_id')->nullable()->index();
            $table->string('recipient')->nullable()->index();
            $table->string('url')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('ip_address')->nullable();
            $table->json('payload');
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('ses');
            $table->string('message_type')->nullable();
            $table->string('status')->default('received');
            $table->json('payload');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
        Schema::dropIfExists('email_events');
        Schema::dropIfExists('email_attachments');
        Schema::dropIfExists('email_recipients');
        Schema::dropIfExists('emails');
        Schema::dropIfExists('templates');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('sources');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');
    }
};
