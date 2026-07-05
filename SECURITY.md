# Security Policy

Larasend is self-hosted software that stores email content, delivery metadata, and AWS credentials on your own infrastructure. We take reports about its security seriously.

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Email **vijay@savvyagents.ai** with:

- A description of the vulnerability and its potential impact.
- Steps to reproduce, or a proof of concept.
- The version/commit you tested against.

You should get an acknowledgement within 3 business days. We'll work with you on a fix and coordinate a disclosure timeline before any public write-up.

## Supported Versions

Larasend is pre-1.0. Security fixes land on the `main` branch and the latest tagged release. There is no long-term support for older tags yet.

## Scope

In scope:

- The Larasend application (`app/`, `routes/`, `resources/js/`).
- The `larasend/laravel` mail transport package (`packages/larasend-laravel/`).
- The Docker images published from this repository.

Out of scope:

- Vulnerabilities in Amazon SES, AWS IAM, or other third-party services Larasend integrates with.
- Issues that require access to a compromised host, database, or `.env` file.
- Denial of service via unauthenticated volumetric traffic against a self-hosted deployment (that's an infrastructure/hosting concern).

## A Note on Compliance

Larasend is designed to let you keep email content and metadata inside infrastructure you control, which is why some teams adopt it as part of a HIPAA-conscious or otherwise compliance-sensitive stack. Larasend itself does not carry a compliance certification, and self-hosting it does not automatically make your deployment compliant — you're responsible for your own BAAs (including with AWS for SES), infrastructure hardening, access controls, and audit requirements.

## Current Security Practices

- AWS credentials on the `Source` model are encrypted at rest using Laravel's encrypted casts.
- API keys are stored as SHA-256 hashes, never in plaintext, and are shown once at creation.
- API keys support scopes and expiration; use both to limit blast radius.
- SES webhook URLs use a per-source, unguessable token. Treat the webhook URL itself as a secret.
- Prefer an AWS instance role or task role over long-lived access keys in production.
