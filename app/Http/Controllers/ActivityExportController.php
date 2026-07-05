<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\ProjectContext;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityExportController extends Controller
{
    public function __invoke(Request $request, ProjectContext $context): StreamedResponse
    {
        $projectSlug = $request->route('project');
        $project = $context->projectFor(
            Auth::user(),
            is_string($projectSlug) ? $projectSlug : null,
        );
        $section = (string) $request->query('section', 'activity');
        $emails = $this->activityEmailsQuery($project, $request, $section)->get();

        return response()->streamDownload(function () use ($emails) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Message ID', 'Status', 'Recipient', 'Subject', 'Template', 'Sent at', 'SES message ID']);

            foreach ($emails as $email) {
                $recipient = $email->recipients->firstWhere('type', 'to');
                fputcsv($handle, [
                    $email->public_id,
                    $email->status,
                    $recipient?->email,
                    $email->subject,
                    $email->template?->slug ?? '',
                    $email->sent_at?->toIso8601String() ?: $email->created_at->toIso8601String(),
                    $email->ses_message_id,
                ]);
            }

            fclose($handle);
        }, 'larasend-activity.csv', ['Content-Type' => 'text/csv']);
    }

    private function activityEmailsQuery(Project $project, Request $request, string $section): HasMany
    {
        $query = $project->emails()
            ->with(['recipients', 'template'])
            ->latest();

        match ($section) {
            'sent' => $query->whereIn('status', ['sent', 'delivered', 'opened', 'clicked']),
            'bounces' => $query->where('status', 'bounced'),
            'complaints' => $query->where('status', 'complained'),
            default => null,
        };

        $range = $request->string('range', '14d')->toString();
        $since = match ($range) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDays(14),
        };

        $query->where('created_at', '>=', $since);

        $search = trim($request->string('q')->toString());

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->whereLike('public_id', "%{$search}%")
                    ->orWhereLike('ses_message_id', "%{$search}%")
                    ->orWhereLike('subject', "%{$search}%")
                    ->orWhereLike('from_email', "%{$search}%")
                    ->orWhereHas('recipients', function ($query) use ($search) {
                        $query->whereLike('email', "%{$search}%")
                            ->orWhereLike('name', "%{$search}%");
                    });
            });
        }

        return $query;
    }
}
