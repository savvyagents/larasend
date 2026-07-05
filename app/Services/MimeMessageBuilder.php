<?php

namespace App\Services;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class MimeMessageBuilder
{
    /**
     * @param  array<string>  $to
     * @param  array<string>  $cc
     * @param  array<string>  $bcc
     * @param  array<string, string>  $headers
     * @param  array<int, array{filename: string, content: string, content_type?: string|null}>  $attachments
     */
    public function build(
        string $from,
        array $to,
        array $cc,
        array $bcc,
        ?string $replyTo,
        string $subject,
        ?string $html,
        ?string $text,
        array $headers = [],
        array $attachments = [],
    ): string {
        $email = (new Email)
            ->from($this->address($from))
            ->subject($subject);

        foreach ($to as $address) {
            $email->addTo($this->address($address));
        }

        foreach ($cc as $address) {
            $email->addCc($this->address($address));
        }

        foreach ($bcc as $address) {
            $email->addBcc($this->address($address));
        }

        if ($replyTo) {
            $email->replyTo($this->address($replyTo));
        }

        if ($text) {
            $email->text($text);
        }

        if ($html) {
            $email->html($html);
        }

        foreach ($headers as $name => $value) {
            $email->getHeaders()->addTextHeader((string) $name, (string) $value);
        }

        foreach ($attachments as $attachment) {
            $email->attach(
                base64_decode($attachment['content'], strict: true) ?: '',
                $attachment['filename'],
                $attachment['content_type'] ?? 'application/octet-stream',
            );
        }

        return $email->toString();
    }

    public function address(string $value): Address
    {
        if (preg_match('/^(?<name>.+?)\s*<(?<email>[^>]+)>$/', $value, $matches) === 1) {
            return new Address(trim($matches['email']), trim($matches['name'], " \t\n\r\0\x0B\""));
        }

        return new Address($value);
    }

    /**
     * @return array{email: string, name: string|null}
     */
    public function splitAddress(string $value): array
    {
        $address = $this->address($value);

        return [
            'email' => $address->getAddress(),
            'name' => $address->getName() ?: null,
        ];
    }
}
