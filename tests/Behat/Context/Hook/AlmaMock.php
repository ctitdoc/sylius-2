<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Behat\Context\Hook;

/** What a scenario does in Alma's place, through the mock's control routes, outside the client. */
final class AlmaMock
{
    public static function url(): string
    {
        $url = rtrim((string) ($_SERVER['ALMA_API_URL'] ?? $_ENV['ALMA_API_URL'] ?? getenv('ALMA_API_URL') ?: ''), '/');
        if ('' === $url) {
            throw new \RuntimeException('ALMA_API_URL names the Alma mock server (compose: http://alma-mock:8000, CI: http://127.0.0.1:8000)');
        }

        return $url;
    }

    /** @return array<mixed> */
    public static function control(string $method, string $path): array
    {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 5]]);
        $body = @file_get_contents(self::url() . $path, false, $context);
        if (false === $body) {
            throw new \RuntimeException(sprintf('The Alma mock does not answer %s %s', $method, $path));
        }
        $decoded = '' === $body ? [] : json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @return list<array<string, mixed>> the payments the mock holds */
    public static function payments(): array
    {
        /** @var list<array<string, mixed>> $payments */
        $payments = self::control('GET', '/')['payments'] ?? [];

        return $payments;
    }
}
