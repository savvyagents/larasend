<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SendEmailRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'string', 'max:255'],
            'to' => ['required', 'array', 'min:1', 'max:1000'],
            'to.*' => ['required', 'string', 'max:255'],
            'cc' => ['sometimes', 'array', 'max:1000'],
            'cc.*' => ['required', 'string', 'max:255'],
            'bcc' => ['sometimes', 'array', 'max:1000'],
            'bcc.*' => ['required', 'string', 'max:255'],
            'reply_to' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subject' => ['required_without:template_id', 'string', 'max:998'],
            'html' => ['required_without_all:text,template_id', 'nullable', 'string'],
            'text' => ['required_without_all:html,template_id', 'nullable', 'string'],
            'template_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'variables' => ['sometimes', 'array'],
            'attachments' => ['sometimes', 'array', 'max:25'],
            'attachments.*.filename' => ['required_with:attachments', 'string', 'max:255'],
            'attachments.*.content' => ['required_with:attachments', 'string'],
            'attachments.*.content_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'headers' => ['sometimes', 'array'],
            'headers.*' => ['string', 'max:998'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:255'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (['from', 'reply_to'] as $field) {
                    $value = $this->input($field);

                    if (is_string($value) && $value !== '' && ! $this->isValidMailbox($value)) {
                        $validator->errors()->add($field, 'The '.$field.' field must be a valid email address.');
                    }
                }

                foreach (['to', 'cc', 'bcc'] as $field) {
                    foreach ((array) $this->input($field, []) as $index => $value) {
                        if (! is_string($value) || ! $this->isValidMailbox($value)) {
                            $validator->errors()->add("{$field}.{$index}", 'The recipient must be a valid email address.');
                        }
                    }
                }

                foreach (array_keys((array) $this->input('headers', [])) as $name) {
                    if (! is_string($name) || ! $this->isAllowedHeader($name)) {
                        $validator->errors()->add('headers', "The {$name} header cannot be set manually.");
                    }
                }

                $totalAttachmentBytes = 0;

                foreach ((array) $this->input('attachments', []) as $index => $attachment) {
                    $content = is_array($attachment) ? ($attachment['content'] ?? null) : null;
                    $decoded = is_string($content) ? base64_decode($content, strict: true) : false;

                    if ($decoded === false) {
                        $validator->errors()->add("attachments.{$index}.content", 'Attachment content must be valid base64.');

                        continue;
                    }

                    $totalAttachmentBytes += strlen($decoded);
                }

                if ($totalAttachmentBytes > 30 * 1024 * 1024) {
                    $validator->errors()->add('attachments', 'Attachments may not exceed 30 MB per email.');
                }
            },
        ];
    }

    private function isValidMailbox(string $value): bool
    {
        if (preg_match('/^(?<name>.+?)\s*<(?<email>[^>]+)>$/', $value, $matches) === 1) {
            $value = $matches['email'];
        }

        return filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false;
    }

    private function isAllowedHeader(string $name): bool
    {
        $reserved = [
            'bcc',
            'cc',
            'content-transfer-encoding',
            'content-type',
            'date',
            'dkim-signature',
            'from',
            'message-id',
            'mime-version',
            'received',
            'reply-to',
            'return-path',
            'sender',
            'subject',
            'to',
        ];

        return ! in_array(strtolower($name), $reserved, true);
    }
}
