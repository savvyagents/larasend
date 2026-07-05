<?php

namespace App\Services;

class DnsRecordVerifier
{
    /**
     * @param  array<string, string>  $record
     */
    public function matches(array $record): bool
    {
        $type = strtoupper((string) ($record['type'] ?? ''));
        $host = (string) ($record['name'] ?? '');
        $expected = $this->normalize((string) ($record['value'] ?? ''));

        if ($host === '' || $expected === '') {
            return false;
        }

        $answers = dns_get_record($host, $this->dnsTypeFor($type));

        if ($answers === false || $answers === []) {
            return false;
        }

        foreach ($answers as $answer) {
            $actual = $this->actualValue($type, $answer);

            if ($actual !== '' && $this->valuesMatch($actual, $expected)) {
                return true;
            }
        }

        return false;
    }

    private function dnsTypeFor(string $type): int
    {
        return match ($type) {
            'CNAME' => DNS_CNAME,
            'MX' => DNS_MX,
            default => DNS_TXT,
        };
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function actualValue(string $type, array $answer): string
    {
        return match ($type) {
            'CNAME' => $this->normalize((string) ($answer['target'] ?? '')),
            'MX' => $this->normalize(trim((string) ($answer['pri'] ?? '').' '.(string) ($answer['target'] ?? ''))),
            default => $this->normalize((string) ($answer['txt'] ?? '')),
        };
    }

    private function normalize(string $value): string
    {
        return rtrim(trim(strtolower($value)), '.');
    }

    private function valuesMatch(string $actual, string $expected): bool
    {
        if ($actual === $expected) {
            return true;
        }

        return str_contains($actual, $expected) || str_contains($expected, $actual);
    }
}
