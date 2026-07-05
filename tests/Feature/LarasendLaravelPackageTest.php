<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Larasend\Laravel\Facades\Larasend;

it('sends email through the Larasend API client', function () {
    config()->set('larasend.api_key', 'ls_test_key');
    config()->set('larasend.endpoint', 'https://emails.savvyagents.ai');

    Http::fake([
        'emails.savvyagents.ai/api/emails' => Http::response([
            'id' => 'email_test',
            'object' => 'email',
        ], 202),
    ]);

    $response = Larasend::emails()->send([
        'from' => 'Savvy Agents <receipts@mail.savvyagents.ai>',
        'to' => ['person@example.com'],
        'subject' => 'Larasend test',
        'text' => 'Hello from Larasend.',
    ]);

    expect($response)->toBe([
        'id' => 'email_test',
        'object' => 'email',
    ]);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ls_test_key')
        && $request->url() === 'https://emails.savvyagents.ai/api/emails'
        && $request['to'] === ['person@example.com']);
});

it('sends Laravel mail through the Larasend mailer transport', function () {
    config()->set('mail.default', 'larasend');
    config()->set('mail.from.address', 'receipts@mail.savvyagents.ai');
    config()->set('mail.from.name', 'Savvy Agents');
    config()->set('larasend.api_key', 'ls_test_key');
    config()->set('larasend.endpoint', 'https://emails.savvyagents.ai');

    Http::fake([
        'emails.savvyagents.ai/api/emails' => Http::response([
            'id' => 'email_transport_test',
            'object' => 'email',
        ], 202),
    ]);

    Mail::raw('Hello from a local Laravel app.', function ($message) {
        $message
            ->to('person@example.com')
            ->subject('Larasend local Laravel test')
            ->getHeaders()
            ->addTextHeader('X-App-Test', 'larasend');
    });

    Http::assertSent(function ($request) {
        $payload = $request->data();

        return $request->url() === 'https://emails.savvyagents.ai/api/emails'
            && $payload['from'] === 'Savvy Agents <receipts@mail.savvyagents.ai>'
            && $payload['to'] === ['person@example.com']
            && $payload['subject'] === 'Larasend local Laravel test'
            && $payload['text'] === 'Hello from a local Laravel app.'
            && $payload['headers'] === ['X-App-Test' => 'larasend'];
    });
});
