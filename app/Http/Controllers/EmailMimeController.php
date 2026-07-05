<?php

namespace App\Http\Controllers;

use App\Models\Email;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class EmailMimeController extends Controller
{
    public function __invoke(Email $email): Response
    {
        abort_unless(
            Auth::user()?->workspaces()->whereKey($email->workspace_id)->exists(),
            404,
        );

        if (! $email->mime_path || ! Storage::disk($email->mime_disk)->exists($email->mime_path)) {
            return response($email->text ?? $email->html ?? '', 200, [
                'Content-Type' => 'message/rfc822',
                'Content-Disposition' => 'attachment; filename="'.$email->public_id.'.eml"',
            ]);
        }

        return Storage::disk($email->mime_disk)->download($email->mime_path, $email->public_id.'.eml', [
            'Content-Type' => 'message/rfc822',
        ]);
    }
}
