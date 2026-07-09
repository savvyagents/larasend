<?php

namespace App\Http\Requests;

use App\Enums\SourceProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'string', 'max:50'],
            'provider' => ['sometimes', 'required', Rule::enum(SourceProvider::class)],
            'ses_region' => ['nullable', 'string', 'max:50', 'required_unless:provider,cloudflare'],
            'ses_configuration_set' => ['nullable', 'string', 'max:255'],
            'cloudflare_account_id' => ['nullable', 'string', 'max:64'],
            'cloudflare_api_token' => ['nullable', 'string', 'max:255'],
            'default_from_name' => ['nullable', 'string', 'max:255'],
            'default_from_email' => ['required', 'email:rfc', 'max:255'],
            'aws_access_key_id' => ['nullable', 'string', 'max:255'],
            'aws_secret_access_key' => ['nullable', 'string', 'max:255'],
            'aws_session_token' => ['nullable', 'string'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
