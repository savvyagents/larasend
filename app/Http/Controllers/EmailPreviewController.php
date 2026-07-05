<?php

namespace App\Http\Controllers;

use App\Models\Email;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EmailPreviewController extends Controller
{
    public function __invoke(Email $email): Response
    {
        abort_unless(
            Auth::user()?->workspaces()->whereKey($email->workspace_id)->exists(),
            404,
        );

        $subject = e($email->subject ?: 'Email preview');
        $from = e(trim(($email->from_name ? $email->from_name.' ' : '').'<'.$email->from_email.'>'));
        $recipients = e($email->recipients()->where('type', 'to')->pluck('email')->join(', '));
        $body = $email->html ?: $this->textPreview($email->text ?? '');

        return response($this->previewDocument($subject, $from, $recipients, $body), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data: https: http:; frame-src 'self' about:; base-uri 'none'; form-action 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function textPreview(string $text): string
    {
        return '<pre style="white-space:pre-wrap;font:14px/1.55 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;color:#18181b;">'.e($text).'</pre>';
    }

    private function previewDocument(string $subject, string $from, string $recipients, string $body): string
    {
        $srcdoc = e($this->emailDocument($body));

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$subject}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4f1ea; color: #18181b; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        header { position: sticky; top: 0; z-index: 1; display: grid; gap: 4px; border-bottom: 1px solid #dedbd3; background: rgba(251, 250, 247, 0.94); padding: 14px 18px; backdrop-filter: blur(14px); }
        .subject { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 14px; font-weight: 700; }
        .meta { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 11px; color: #71717a; }
        main { min-height: calc(100vh - 76px); padding: 18px; }
        iframe { display: block; width: min(100%, 1040px); min-height: calc(100vh - 112px); margin: 0 auto; border: 1px solid #dedbd3; border-radius: 8px; background: #fff; box-shadow: 0 18px 48px rgba(24, 24, 27, 0.08); }
        @media (max-width: 720px) {
            header { padding: 12px; }
            main { padding: 0; }
            iframe { min-height: calc(100vh - 68px); border-width: 0; border-radius: 0; }
        }
    </style>
</head>
<body>
    <header>
        <div class="subject">{$subject}</div>
        <div class="meta">From {$from}</div>
        <div class="meta">To {$recipients}</div>
    </header>
    <main>
        <iframe sandbox srcdoc="{$srcdoc}" title="{$subject}"></iframe>
    </main>
</body>
</html>
HTML;
    }

    private function emailDocument(string $body): string
    {
        if (Str::contains(Str::lower($body), ['<html', '<!doctype'])) {
            return $body;
        }

        return <<<HTML
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>
{$body}
</body>
</html>
HTML;
    }
}
