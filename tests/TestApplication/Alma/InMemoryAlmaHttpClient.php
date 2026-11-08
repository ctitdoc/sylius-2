<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\TestApplication\Alma;

use Alma\Client\Application\ClientConfiguration;
use Alma\Client\Application\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The HTTP client the real endpoints of alma-php-client talk to, answered in memory by InMemoryAlma:
 * the boundary where Alma's backend is doubled, so that every line of the client — endpoints,
 * entities, their typing — runs as in production. Routes mirror Alma's API as the client calls it.
 */
final class InMemoryAlmaHttpClient implements ClientInterface
{
    public function __construct(
        private readonly InMemoryAlma $alma,
        private readonly ClientConfiguration $config,
    ) {
    }

    /** What AbstractEndpoint reads to sign its requests. */
    public function getConfig(): ClientConfiguration
    {
        return $this->config;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!str_starts_with($this->authorization($request), 'Alma-Auth ') || \strlen($this->authorization($request)) < 18) {
            return $this->json(401, ['message' => 'Credentials invalid or not supplied']);
        }
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        parse_str($request->getUri()->getQuery(), $query);
        $body = json_decode((string) $request->getBody(), true);
        $body = \is_array($body) ? $body : [];

        if ('GET' === $method && \in_array($path, ['/v1/me', '/v1/me/extended-data'], true)) {
            return $this->json(200, $this->alma->merchant());
        }
        if ('GET' === $method && '/v1/me/fee-plans' === $path) {
            $only = \is_string($query['only'] ?? null) ? $query['only'] : 'all';

            return $this->json(200, $this->alma->feePlans($only, 'true' === ($query['deferred'] ?? 'false')));
        }
        if ('POST' === $method && '/v1/me/business-events' === $path) {
            return new Response(204);
        }
        if ('POST' === $method && '/v2/payments/eligibility' === $path) {
            $amount = \is_scalar($body['purchase_amount'] ?? null) ? (int) $body['purchase_amount'] : 0;
            /** @var list<array<string, mixed>> $queries */
            $queries = \is_array($body['queries'] ?? null) ? array_values($body['queries']) : [];

            return $this->json(200, $this->alma->eligibility($amount, $queries));
        }
        if ('POST' === $method && '/v1/payments' === $path) {
            $payment = $this->alma->createPayment($body);

            return null === $payment ? $this->json(400, ['message' => 'Purchase amount not eligible for this fee plan']) : $this->json(201, $payment);
        }
        if (1 === preg_match('#^/v1/payments/([^/]+)$#', $path, $m) && 'GET' === $method) {
            $payment = $this->alma->payment($m[1]);

            return null === $payment ? $this->json(404, ['message' => 'Payment not found']) : $this->json(200, $payment);
        }
        if (1 === preg_match('#^/v1/payments/([^/]+)/cancel$#', $path, $m) && 'PUT' === $method) {
            return $this->alma->cancel($m[1]) ? new Response(204) : $this->json(404, ['message' => 'Payment not found']);
        }
        if (1 === preg_match('#^/v1/payments/([^/]+)/refund$#', $path, $m) && 'POST' === $method) {
            $payment = $this->alma->refund($m[1], $body);

            return null === $payment ? $this->json(400, ['message' => 'Refund amount exceeds the refundable amount']) : $this->json(200, $payment);
        }
        if (1 === preg_match('#^/v1/payments/([^/]+)/potential-fraud$#', $path, $m) && 'POST' === $method) {
            return $this->alma->flagPotentialFraud($m[1], $body) ? new Response(204) : $this->json(404, ['message' => 'Payment not found']);
        }

        return $this->json(404, ['message' => sprintf('No route %s %s in the Alma double', $method, $path)]);
    }

    /**
     * Read case-insensitively from getHeaders(): the client's own Request keeps the headers given
     * to its constructor under their original name but looks them up lowercased, so that
     * getHeaderLine('Authorization') is empty there (CurlClient reads getHeaders(), hence the
     * API never sees the difference).
     */
    private function authorization(RequestInterface $request): string
    {
        foreach ($request->getHeaders() as $name => $values) {
            if ('authorization' === strtolower((string) $name)) {
                return implode(', ', $values);
            }
        }

        return '';
    }

    /** @param array<mixed> $data */
    private function json(int $status, array $data): Response
    {
        return new Response($status, Utils::streamFor(json_encode($data, \JSON_THROW_ON_ERROR)), ['Content-Type' => ['application/json']]);
    }
}
