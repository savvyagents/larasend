<?php

namespace Larasend\Laravel;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class LarasendTransport extends AbstractTransport
{
    public function __construct(private LarasendClient $client)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Email) {
            $original = Email::create($original);
        }

        $this->client->send([
            'from' => $this->stringAddress($original->getFrom()[0]),
            'to' => array_map($this->stringAddress(...), $original->getTo()),
            'cc' => array_map($this->stringAddress(...), $original->getCc()),
            'bcc' => array_map($this->stringAddress(...), $original->getBcc()),
            'reply_to' => $original->getReplyTo() ? $this->stringAddress($original->getReplyTo()[0]) : null,
            'subject' => $original->getSubject() ?? '',
            'html' => $original->getHtmlBody(),
            'text' => $original->getTextBody(),
            'headers' => $this->customHeaders($original),
        ]);
    }

    public function __toString(): string
    {
        return 'larasend';
    }

    private function stringAddress(Address $address): string
    {
        return $address->getName()
            ? $address->getName().' <'.$address->getAddress().'>'
            : $address->getAddress();
    }

    /**
     * @return array<string, string>
     */
    private function customHeaders(Email $email): array
    {
        $reservedHeaders = [
            'bcc',
            'cc',
            'content-transfer-encoding',
            'content-type',
            'date',
            'from',
            'message-id',
            'mime-version',
            'reply-to',
            'sender',
            'subject',
            'to',
        ];

        return collect($email->getHeaders()->all())
            ->reject(fn ($header) => in_array(strtolower($header->getName()), $reservedHeaders, true))
            ->mapWithKeys(fn ($header) => [$header->getName() => $header->getBodyAsString()])
            ->all();
    }
}
