<?php

namespace App\Traits;

use App\Models\MediaServerIntegration;
use App\Support\PrivateNetworkGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared transport guard for the Emby companion's administrator-authenticated
 * managed publishing routes (setup and library provisioning).
 *
 * The saved administrator credential is only ever sent to the exact origin the
 * integration was configured with, over TLS or a private/Docker network, and a
 * response is only trusted when it was not redirected to a foreign origin.
 */
trait GuardsEmbyCompanionOrigin
{
    protected function companionRequest(MediaServerIntegration $integration): PendingRequest
    {
        return Http::baseUrl($integration->base_url)
            ->connectTimeout(5)
            ->timeout(15)
            ->withoutRedirecting()
            ->withHeaders([
                'X-Emby-Token' => $integration->api_key,
                'Accept' => 'application/json',
            ]);
    }

    protected function originIsAllowed(MediaServerIntegration $integration): bool
    {
        $host = trim((string) $integration->host, '[]');
        if (! $this->hostIsValid($host)) {
            return false;
        }

        if ($integration->ssl) {
            return true;
        }

        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            return ! str_contains($host, '.')
                && preg_match('/^(?:\d+|0x[0-9a-f]+)$/i', $host) !== 1;
        }

        return PrivateNetworkGuard::ipIsPrivate($host);
    }

    protected function hostIsValid(string $host): bool
    {
        if ($host === '' || preg_match('/[\x00-\x20\x7F@\/?#]/', $host) === 1) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    protected function responseOriginIsValid(Response $response, MediaServerIntegration $integration): bool
    {
        $effectiveUrl = $response->handlerStats()['url'] ?? null;

        return $effectiveUrl === null || $this->originsMatch($integration->base_url, $effectiveUrl);
    }

    protected function originsMatch(string $expectedUrl, string $effectiveUrl): bool
    {
        $expected = parse_url($expectedUrl);
        $effective = parse_url($effectiveUrl);
        if (! is_array($expected) || ! is_array($effective)) {
            return false;
        }

        $defaultPort = fn (string $scheme): int => $scheme === 'https' ? 443 : 80;
        $expectedScheme = strtolower((string) ($expected['scheme'] ?? ''));
        $effectiveScheme = strtolower((string) ($effective['scheme'] ?? ''));

        return $expectedScheme === $effectiveScheme
            && strtolower((string) ($expected['host'] ?? '')) === strtolower((string) ($effective['host'] ?? ''))
            && ($expected['port'] ?? $defaultPort($expectedScheme)) === ($effective['port'] ?? $defaultPort($effectiveScheme));
    }
}
