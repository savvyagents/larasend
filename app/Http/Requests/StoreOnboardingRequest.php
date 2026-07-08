<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'workspace_name' => ['nullable', 'string', 'max:80'],
            'project_name' => ['nullable', 'string', 'max:80'],
            'project_slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'credential_mode' => ['required', Rule::in(['instance_role', 'aws_keys', 'cloudflare_token', 'configure_later'])],
            'source_name' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'string', 'max:50'],
            'ses_region' => ['required_unless:credential_mode,cloudflare_token', 'nullable', 'string', 'max:50'],
            'ses_configuration_set' => ['nullable', 'string', 'max:255'],
            'cloudflare_account_id' => ['nullable', 'string', 'max:64'],
            'cloudflare_api_token' => ['required_if:credential_mode,cloudflare_token', 'nullable', 'string', 'max:255'],
            'default_from_name' => ['nullable', 'string', 'max:255'],
            'default_from_email' => ['nullable', 'email:rfc', 'max:255'],
            'aws_access_key_id' => ['required_if:credential_mode,aws_keys', 'nullable', 'string', 'max:255'],
            'aws_secret_access_key' => ['required_if:credential_mode,aws_keys', 'nullable', 'string', 'max:255'],
            'aws_session_token' => ['nullable', 'string'],
            'sending_domain' => ['nullable', 'string', 'max:255', 'regex:/^(?!-)[A-Za-z0-9.-]+(?<!-)$/'],
            'create_api_key' => ['boolean'],
            'api_key_name' => ['nullable', 'string', 'max:255'],
            'webhook_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
