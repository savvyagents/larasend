<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateLarasendApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();

        if (! $plainTextToken) {
            return response()->json(['message' => 'Missing Larasend API key.'], 401);
        }

        $apiKey = ApiKey::query()
            ->with(['project.workspace', 'source'])
            ->where('key_hash', hash('sha256', $plainTextToken))
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $apiKey) {
            return response()->json(['message' => 'Invalid Larasend API key.'], 401);
        }

        $requiredScope = $request->isMethod('post') ? 'send' : 'read:activity';

        if (! $apiKey->allows($requiredScope)) {
            return response()->json(['message' => "This Larasend API key is missing the {$requiredScope} scope."], 403);
        }

        $apiKey->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
            'last_used_user_agent' => $request->userAgent(),
        ])->save();

        $request->attributes->set('larasend_api_key', $apiKey);
        $request->attributes->set('larasend_project', $apiKey->project);
        $request->attributes->set('larasend_workspace', $apiKey->project->workspace);
        $request->attributes->set('larasend_source', $apiKey->source);

        return $next($request);
    }
}
