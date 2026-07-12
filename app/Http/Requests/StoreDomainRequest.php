<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $identity = trim($this->string('domain')->toString());

        if (str_contains($identity, '@')) {
            $identity = trim(Str::afterLast($identity, '@'), " \t\n\r\0\x0B<>.,;");
        }

        $this->merge([
            'domain' => Str::lower($identity),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:255', 'regex:/^(?!-)[A-Za-z0-9.-]+(?<!-)$/'],
        ];
    }
}
