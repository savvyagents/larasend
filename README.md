# Larasend

Larasend is a self-hosted transactional email platform for Laravel teams. It gives you a clean HTTP API, dashboard, activity log, API keys, delivery events, suppressions, and a Laravel mail transport while sending through your own Amazon SES account.

Larasend is built with Laravel, Inertia, Vue, PostgreSQL, Redis, and Docker.

## Why Larasend?

- Use Laravel Mail with a Larasend driver instead of wiring every app directly to SES.
- Keep email activity, previews, headers, metrics, retries, API keys, and suppressions in one place.
- Send through Amazon SES with your own verified domains, configuration sets, and IAM controls.
- Self-host the control plane so your email logs and metadata stay in infrastructure you control.
- Run production with Docker Compose, or hack on the app locally like a normal Laravel project.

## Features

- Simple `POST /api/emails` API with API key authentication.
- Laravel mail transport package for drop-in `MAIL_MAILER=larasend` usage.
- Project-scoped API keys with scopes, expiration, rotation, and last-used metadata.
- Activity dashboard with status filters, search, grouped timeline, inspector preview, headers, metrics, and resend.
- SES identity setup with DKIM record guidance.
- SES event ingestion for delivery, bounce, complaint, open, click, and suppression events.
- Suppression list tracking for bounces and complaints.
- Workspace members and project permissions.
- Source health, SES quota sync, and delivery guardrails for verified domains, suppressions, and complaint rate.
- Docker image and production-ready queue worker setup.

## How It Works

1. Your Laravel app sends mail through the Larasend Laravel transport or directly through the HTTP API.
2. Larasend validates the request, stores the MIME payload and metadata, and queues delivery.
3. The Larasend queue worker sends the raw email through Amazon SES.
4. SES publishes delivery/bounce/complaint/open/click events back to Larasend through the SES webhook.
5. Larasend updates the activity dashboard, metrics, suppressions, and webhook deliveries.

Larasend accepts API sends even when SES quota sync is stale or temporarily unavailable. SES remains the final authority for provider-side send rejection.

## Requirements

- Docker and Docker Compose for production-style installs.
- PHP 8.4+ and Node.js 22+ for local development.
- PostgreSQL 17+.
- Redis 7+ if you use the bundled compose stack.
- An Amazon SES account with a verified sending domain.

## Quick Start With Docker

Create a deployment directory and download the production files:

```bash
mkdir larasend
cd larasend
curl -fsSL https://raw.githubusercontent.com/savvyagents/larasend/main/docker-compose.yml -o docker-compose.yml
curl -fsSL https://raw.githubusercontent.com/savvyagents/larasend/main/.env.example -o .env
```

Edit `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://mail.example.com
DB_PASSWORD=change-me
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

Generate an application key:

```bash
docker compose pull
docker compose run --rm --entrypoint php app artisan key:generate --show
```

Paste the generated `base64:...` value into `APP_KEY` in `.env`, then start Larasend:

```bash
docker compose up -d
```

Open `APP_URL`, create the first user, and follow onboarding.

## Amazon SES Setup

Larasend can use stored AWS credentials or, in production, an attached EC2 instance role.

The IAM principal used by Larasend needs SES permissions for:

```text
ses:SendRawEmail
ses:CreateEmailIdentity
ses:GetEmailIdentity
ses:GetAccount
ses:GetSendQuota
```

Recommended setup:

1. Create or choose an SES verified domain, for example `mail.example.com`.
2. In Larasend, add the sending domain and copy the DKIM records.
3. Publish the DNS records in Route 53 or your DNS provider.
4. Configure an SES configuration set if you want event publishing.
5. Point SES/SNS events to the Larasend webhook URL shown in the setup screen.
6. Send a test email from Larasend and confirm delivery activity appears.

## Sending Email Over HTTP

Create an API key in Larasend, then send:

```bash
curl -X POST https://mail.example.com/api/emails \
  -H "Authorization: Bearer ls_your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "from": "Acme <notifications@mail.example.com>",
    "to": ["person@example.com"],
    "subject": "Hello from Larasend",
    "html": "<h1>Hello</h1><p>This was sent through Larasend.</p>",
    "text": "Hello. This was sent through Larasend.",
    "tags": {
      "app": "billing"
    }
  }'
```

Supported fields include:

- `from`, `to`, `cc`, `bcc`, `reply_to`
- `subject`, `html`, `text`
- `template_id`, `variables`
- `attachments` with base64 content
- `headers` for allowed custom headers
- `tags` for searchable metadata

## Laravel Mail Driver

Until the Laravel package is published to Packagist, install it from GitHub.

Add the package repository to your Laravel app's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/vijaythecoder/larasend-laravel"
        }
    ]
}
```

Install the package:

```bash
composer require larasend/laravel:^0.1
```

Add the mailer to `config/mail.php`:

```php
'larasend' => [
    'transport' => 'larasend',
],
```

Configure your app:

```env
MAIL_MAILER=larasend
MAIL_FROM_ADDRESS=notifications@mail.example.com
MAIL_FROM_NAME="Acme"

LARASEND_ENDPOINT=https://mail.example.com
LARASEND_API_KEY=ls_your_api_key
LARASEND_TIMEOUT=15
```

Clear cached config:

```bash
php artisan config:clear
```

Now use Laravel Mail normally:

```php
use Illuminate\Support\Facades\Mail;

Mail::raw('Hello from Laravel through Larasend.', function ($message) {
    $message
        ->to('person@example.com')
        ->subject('Larasend test');
});
```

You can also use the client directly:

```php
use Larasend\Laravel\Facades\Larasend;

$email = Larasend::emails()->send([
    'from' => 'Acme <notifications@mail.example.com>',
    'to' => ['person@example.com'],
    'subject' => 'Direct API send',
    'text' => 'Hello from Larasend.',
]);
```

## Local Development

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
```

In another terminal, run a queue worker:

```bash
php artisan queue:work
```

Or run the bundled development command:

```bash
composer run dev
```

## Building The Docker Image

To build the app locally:

```bash
docker compose -f docker-compose.yml -f docker-compose.build.yml up --build -d
```

The GitHub workflow publishes images to GitHub Container Registry on pushes to `main` and version tags.

## Verification

Run the same checks used before shipping:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
npm run types:check
npm run lint:check
npm run build
```

## Security Notes

- Larasend stores AWS credentials encrypted when you choose stored credentials.
- For production on AWS, prefer an instance role or task role over long-lived access keys.
- API keys are only shown once at creation.
- Use project scopes and expiration dates for application API keys.
- Do not expose the SES webhook token publicly beyond the generated webhook URL.

Please report security issues privately before opening a public issue.

## Roadmap

- Packagist release for the Laravel driver.
- More provider adapters beyond Amazon SES.
- Deeper per-domain health and deliverability reporting.
- First-class deploy workflow for updating self-hosted Docker installations.
- More template authoring and preview tools.

## Contributing

Issues and pull requests are welcome. Before opening a PR, run the verification commands above and keep changes focused.

This project follows Laravel conventions closely: controllers stay thin, business logic lives in services/actions, tests use Pest, and frontend pages are built with Inertia and Vue.

## License

Larasend is open-sourced software licensed under the MIT license.
