<?php

namespace App\Services\Arr;

use App\Models\ArrIntegration;
use App\Services\Arr\Contracts\ArrIntegrationInterface;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Base class for Sonarr/Radarr services — handles the shared X-Api-Key client
 * and common request/response plumbing. Subclasses implement the per-platform
 * endpoints and payload shapes.
 */
abstract class BaseArrService implements ArrIntegrationInterface
{
    /**
     * Webhook events ArrWebhookController handles. Each arr has only one of
     * the two "added" events.
     */
    private const WEBHOOK_EVENTS = ['onGrab', 'onDownload', 'onUpgrade', 'onMovieAdded', 'onSeriesAdd', 'onManualInteractionRequired'];

    public function __construct(protected ArrIntegration $integration) {}

    public function getIntegration(): ArrIntegration
    {
        return $this->integration;
    }

    /**
     * Configured HTTP client (X-Api-Key auth, /api/v3 base path).
     */
    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->integration->base_url.'/api/v3')
            ->timeout(30)
            ->retry(2, 1000)
            ->acceptJson()
            ->withHeaders([
                'X-Api-Key' => $this->integration->api_key,
            ]);
    }

    /**
     * Short-lived client for queue polling — avoids a dead server hanging the page for 60s.
     */
    protected function queueClient(): PendingRequest
    {
        return Http::baseUrl($this->integration->base_url.'/api/v3')
            ->timeout(5)
            ->acceptJson()
            ->withHeaders([
                'X-Api-Key' => $this->integration->api_key,
            ]);
    }

    /**
     * Wrap a request in a try/catch and return a uniform {ok, data, error} shape.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return array{ok: bool, data?: T, error?: string}
     */
    protected function safeCall(callable $callback, string $op): array
    {
        try {
            $data = $callback();

            return ['ok' => true, 'data' => $data];
        } catch (RequestException $e) {
            $body = $e->response->body();
            $decoded = json_decode($body, true);
            $errors = $decoded['errors'] ?? null;

            Log::warning("ArrService: {$op} failed", [
                'integration_id' => $this->integration->id,
                'type' => $this->integration->type,
                'status' => $e->response->status(),
                'errors' => $errors,
                'body' => $body,
            ]);

            $message = $errors
                ? implode(' ', array_map(
                    fn ($field, $msgs) => "{$field}: ".implode(', ', (array) $msgs),
                    array_keys($errors),
                    $errors
                ))
                : ($decoded['title'] ?? $e->getMessage());

            return ['ok' => false, 'error' => $message];
        } catch (Exception $e) {
            Log::warning("ArrService: {$op} failed", [
                'integration_id' => $this->integration->id,
                'type' => $this->integration->type,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function testWebhook(string $url): array
    {
        return $this->webhookCall(fn (PendingRequest $client): Response => $client->post(
            '/notification/test',
            $this->webhookResource($client, $url, $this->findWebhook($client, $url)),
        ));
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function registerWebhook(string $url): array
    {
        return $this->webhookCall(function (PendingRequest $client) use ($url): Response {
            $existing = $this->findWebhook($client, $url);

            return $existing
                ? $client->put('/notification/'.$existing['id'], $this->webhookResource($client, $url, $existing))
                : $client->post('/notification', $this->webhookResource($client, $url));
        });
    }

    /**
     * The arr's Webhook connection for this webhook URL, matched by path
     * (which holds the secret) so one saved with an older host still counts.
     *
     * @return array<string, mixed>|null
     */
    private function findWebhook(PendingRequest $client, string $url): ?array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return collect($client->get('/notification')->throw()->json() ?? [])
            ->first(fn (array $connection): bool => ($connection['implementation'] ?? null) === 'Webhook'
                && str_ends_with((string) (collect($connection['fields'] ?? [])->firstWhere('name', 'url')['value'] ?? ''), $path));
    }

    /**
     * A Webhook connection (the arr's own template, or `$existing`) pointed
     * at `$url` with the events we handle turned on. Arrs need unique
     * connection names, so a new one is named after its secret.
     *
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    private function webhookResource(PendingRequest $client, string $url, ?array $existing = null): array
    {
        $resource = $existing ?? [
            ...collect($client->get('/notification/schema')->throw()->json() ?? [])->firstWhere('implementation', 'Webhook')
                ?? throw new Exception('This server does not support webhook connections.'),
            'name' => 'm3u editor ('.substr(basename((string) parse_url($url, PHP_URL_PATH)), 0, 8).')',
        ];

        $resource['fields'] = collect($resource['fields'] ?? [])
            ->map(fn (array $field): array => $field['name'] === 'url' ? [...$field, 'value' => $url] : $field)
            ->all();

        foreach (self::WEBHOOK_EVENTS as $event) {
            if (array_key_exists($event, $resource)) {
                $resource[$event] = true;
            }
        }

        return $resource;
    }

    /**
     * Run one webhook request and return the arr's own error on failure.
     * One attempt only: the arr sends its test event on every try.
     *
     * @param  callable(PendingRequest): Response  $request
     * @return array{ok: bool, error?: string}
     */
    private function webhookCall(callable $request): array
    {
        try {
            $response = $request($this->client()->retry(1, 0, throw: false));
        } catch (Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($response->successful()) {
            return ['ok' => true];
        }

        $error = collect($response->json() ?? [])->pluck('errorMessage')->filter()->implode(' ');

        return ['ok' => false, 'error' => $error !== '' ? $error : 'HTTP '.$response->status()];
    }
}
