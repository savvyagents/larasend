<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that carry a source_id foreign key and need reassigning before
     * a duplicate source row can be safely deleted.
     *
     * @var array<int, string>
     */
    private array $dependentTables = [
        'api_keys',
        'emails',
        'email_events',
        'webhook_logs',
        'suppressions',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->mergeDuplicateSources();

        Schema::table('sources', function (Blueprint $table) {
            $table->unique(['project_id', 'environment']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'environment']);
        });
    }

    /**
     * Two different source-resolution conventions in the app could
     * previously create more than one source row for the same
     * (project_id, environment) pair, leaving API keys, sent mail, and
     * webhook history split across rows with no way for the dashboard to
     * reveal it. For every duplicate group, keep the most recently
     * quota-synced row, reassign every dependent row onto it, and delete
     * the rest.
     */
    private function mergeDuplicateSources(): void
    {
        $duplicateGroups = DB::table('sources')
            ->select('project_id', 'environment')
            ->groupBy('project_id', 'environment')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $sources = DB::table('sources')
                ->where('project_id', $group->project_id)
                ->where('environment', $group->environment)
                ->orderByRaw('last_quota_checked_at IS NULL')
                ->orderByDesc('last_quota_checked_at')
                ->orderByDesc('id')
                ->get();

            $keeper = $sources->first();
            $duplicateIds = $sources->skip(1)->pluck('id')->all();

            if ($duplicateIds === []) {
                continue;
            }

            foreach ($this->dependentTables as $table) {
                DB::table($table)
                    ->whereIn('source_id', $duplicateIds)
                    ->update(['source_id' => $keeper->id]);
            }

            DB::table('sources')->whereIn('id', $duplicateIds)->delete();
        }
    }
};
