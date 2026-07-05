# Larasend Laravel

Laravel mail transport and API client for [Larasend](https://emails.savvyagents.ai).

## Installation

Until this package is published to Packagist, install it from GitHub as a VCS repository.

Add the repository to your Laravel application's `composer.json`:

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

Then install the package:

```bash
composer require larasend/laravel:^0.1
```

## Configuration

Add the Larasend mailer to `config/mail.php`:

```php
'larasend' => [
    'transport' => 'larasend',
],
```

Configure your environment:

```env
MAIL_MAILER=larasend
MAIL_FROM_ADDRESS=receipts@mail.savvyagents.ai
MAIL_FROM_NAME="Savvy Agents"

LARASEND_ENDPOINT=https://emails.savvyagents.ai
LARASEND_API_KEY=ls_your_full_api_key
LARASEND_TIMEOUT=15
```

Clear cached configuration after changing mail settings:

```bash
php artisan config:clear
```

## Usage

Use Laravel mail as usual:

```php
use Illuminate\Support\Facades\Mail;

Mail::raw('Hello from Laravel through Larasend.', function ($message) {
    $message
        ->to('person@example.com')
        ->subject('Larasend test');
});
```

Or call the Larasend API client directly:

```php
use Larasend\Laravel\Facades\Larasend;

$response = Larasend::emails()->send([
    'from' => 'Savvy Agents <receipts@mail.savvyagents.ai>',
    'to' => ['person@example.com'],
    'subject' => 'Direct Larasend API test',
    'text' => 'Hello from Larasend.',
]);
```

## License

The Larasend Laravel package is open-sourced software licensed under the MIT license.
