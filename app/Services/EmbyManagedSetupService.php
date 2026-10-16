<?php

namespace App\Services;

use App\Models\MediaServerIntegration;
use App\Traits\GuardsEmbyCompanionOrigin;
use Throwable;

class EmbyManagedSetupService
{
    use GuardsEmbyCompanionOrigin;

    private const int CONTRACT_VERSION = 1;

    private const string ORIGIN_BLOCKED_MESSAGE = 'Emby managed setup was blocked by the integration security policy.';

    private const string CONNECTION_FAILED_MESSAGE = 'Emby managed setup could not connect. Check that Emby is reachable, then retry.';

    private const string ENDPOINT_NOT_FOUND_MESSAGE = 'The Emby managed setup endpoint was not found. Check the companion installation, then retry.';

    private const string REQUEST_REJECTED_MESSAGE = 'Emby rejected the managed setup request. Check the administrator credential and permissions, then retry.';

    private const string BINDING_CONFLICT_MESSAGE = 'Emby reported a managed setup binding conflict. Reconnect the integration, then retry.';

    private const string NOT_READY_MESSAGE = 'Emby is not ready for managed setup. Check the companion configuration, then retry.';

    private const string INVALID_RESPONSE_MESSAGE = 'Emby returned an invalid managed setup response. Check the companion configuration, then retry.';

    private const string UNSUPPORTED_VERSION_MESSAGE = 'The Emby companion does not support managed setup version 1. Update the companion, then retry.';

    /** @return array{success: bool, message: string} */
    public function setup(MediaServerIntegration $integration): array
    {
        if (! $integration->isEmby() || ! $this->originIsAllowed($integration)) {
            return $this->failure(self::ORIGIN_BLOCKED_MESSAGE);
        }

        try {
            $response = $this->companionRequest($integration)
                ->put('/M3uEditor/Managed/Setup/V1', [
                    'IntegrationId' => $integration->id,
                ]);
        } catch (Throwable) {
            return $this->failure(self::CONNECTION_FAILED_MESSAGE);
        }

        if (! $this->responseOriginIsValid($response, $integration)) {
            return $this->failure(self::ORIGIN_BLOCKED_MESSAGE);
        }

        if ($response->status() === 404) {
            return $this->failure(self::ENDPOINT_NOT_FOUND_MESSAGE);
        }

        if (! $response->successful()) {
            return $this->failure(self::REQUEST_REJECTED_MESSAGE);
        }

        $data = $response->json();

        if (! is_array($data)) {
            return $this->failure(self::INVALID_RESPONSE_MESSAGE);
        }

        if (($data['Ready'] ?? null) === false) {
            return $this->failure(self::NOT_READY_MESSAGE);
        }

        if (($data['Ready'] ?? null) !== true) {
            return $this->failure(self::INVALID_RESPONSE_MESSAGE);
        }

        if (($data['CapabilityVersion'] ?? null) !== self::CONTRACT_VERSION) {
            return $this->failure(self::UNSUPPORTED_VERSION_MESSAGE);
        }

        if (($data['IntegrationId'] ?? null) !== $integration->id) {
            return $this->failure(self::BINDING_CONFLICT_MESSAGE);
        }

        $root = $data['ConfirmedRoot'] ?? null;

        if (! is_string($root) || ! MediaServerIntegration::isSafeWritablePath($root)) {
            return $this->failure(self::INVALID_RESPONSE_MESSAGE);
        }

        $integration->updateQuietly([
            'emby_managed_setup_binding_id' => $data['IntegrationId'],
            'emby_managed_setup_root' => $root,
            'emby_managed_setup_capability_version' => $data['CapabilityVersion'],
            'emby_managed_setup_contract_version' => self::CONTRACT_VERSION,
            'emby_publisher_capabilities_updated_at' => now(),
        ]);

        return [
            'success' => true,
            'message' => 'Ready',
        ];
    }

    /** @return array{success: false, message: string} */
    private function failure(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
        ];
    }
}
