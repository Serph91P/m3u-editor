<?php

namespace App\Services;

use App\Models\MediaServerIntegration;
use App\Traits\GuardsEmbyCompanionOrigin;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Drives the Emby companion's managed library directory contract (V1).
 *
 * Emby's `POST /Library/VirtualFolders` rejects a path that does not exist on
 * the server, and m3u-editor never touches the companion's filesystem itself.
 * Before a managed library is created, the companion therefore has to prepare
 * the library root folder below its confirmed managed root; after Emby accepted
 * the library, the folder is committed into the companion's ownership registry
 * so later reconciles may provision the mapping subfolders beneath it.
 *
 * The caller supplies semantic identity only (library name and collection
 * type). The companion derives and returns the actual folder, which is accepted
 * only when it is a safe direct child of the confirmed managed root.
 */
class EmbyManagedLibraryProvisioningService
{
    use GuardsEmbyCompanionOrigin;

    private const int CONTRACT_VERSION = 1;

    /**
     * Stable namespace for the deterministic operation ID so a retried publish
     * for the same integration, collection type, and name resumes the folder
     * the companion already prepared instead of colliding with it.
     */
    private const string OPERATION_NAMESPACE = '2b1d9f4e-6c57-4a0e-9f0b-7c1c5e2a8d13';

    private const string ORIGIN_BLOCKED_MESSAGE = 'Emby managed library preparation was blocked by the integration security policy.';

    private const string CONNECTION_FAILED_MESSAGE = 'Emby managed library preparation could not connect. Check that Emby is reachable, then retry.';

    private const string UNSUPPORTED_MESSAGE = 'The Emby companion does not support managed library preparation. Update the companion, then retry.';

    private const string REQUEST_REJECTED_MESSAGE = 'Emby rejected the managed library preparation. Check the administrator credential and permissions, then retry.';

    private const string BINDING_CONFLICT_MESSAGE = 'Emby reported a managed library binding conflict. Reconnect the integration, then retry.';

    private const string NOT_READY_MESSAGE = 'Emby managed setup is not ready. Run the managed setup, then retry.';

    private const string INVALID_RESPONSE_MESSAGE = 'Emby returned an invalid managed library response. Check the companion configuration, then retry.';

    private const string COLLISION_MESSAGE = 'A managed library folder for this name already exists on the Emby server. Choose a different library name, then retry.';

    private const string FILESYSTEM_MESSAGE = 'The Emby companion could not create the managed library folder. Check the managed root permissions, then retry.';

    private const string PREPARE_FAILED_MESSAGE = 'The Emby companion could not prepare the managed library folder. Check the companion configuration, then retry.';

    private const string COMMIT_FAILED_MESSAGE = 'Emby created the managed library, but the companion could not confirm its folder. Retry to complete the publication.';

    /**
     * Ask the companion to create the library root folder for a new managed library.
     *
     * `supported` is false only when the companion does not expose the contract
     * at all (an older companion), so callers can decide how to degrade.
     *
     * @return array{success: bool, supported: bool, path: string|null, operation_id: string|null, message: string}
     */
    public function prepare(MediaServerIntegration $integration, string $name, string $collectionType): array
    {
        if (! $integration->isEmby() || ! $this->originIsAllowed($integration)) {
            return $this->failure(self::ORIGIN_BLOCKED_MESSAGE);
        }

        $root = $integration->emby_managed_setup_root;
        if (! is_string($root) || ! MediaServerIntegration::isSafeWritablePath($root)) {
            return $this->failure(self::NOT_READY_MESSAGE);
        }

        $operationId = $this->operationId($integration, $name, $collectionType);

        try {
            $response = $this->companionRequest($integration)
                ->put('/M3uEditor/Managed/Libraries/V1/Prepare', [
                    'IntegrationId' => $integration->id,
                    'OperationId' => $operationId,
                    'Name' => $name,
                    'CollectionType' => $collectionType,
                ]);
        } catch (Throwable) {
            return $this->failure(self::CONNECTION_FAILED_MESSAGE);
        }

        if (! $this->responseOriginIsValid($response, $integration)) {
            return $this->failure(self::ORIGIN_BLOCKED_MESSAGE);
        }

        if ($response->status() === 404) {
            return $this->failure(self::UNSUPPORTED_MESSAGE, supported: false);
        }

        if (! $response->successful()) {
            return $this->failure(self::REQUEST_REJECTED_MESSAGE);
        }

        $data = $response->json();
        $envelope = $this->validateEnvelope($data, $integration, $operationId);
        if ($envelope !== null) {
            return $this->failure($envelope);
        }

        if (($data['Success'] ?? null) !== true) {
            return $this->failure($this->prepareErrorMessage($data['ErrorClass'] ?? null));
        }

        if (! in_array($data['State'] ?? null, ['prepared', 'committed'], true)) {
            return $this->failure(self::INVALID_RESPONSE_MESSAGE);
        }

        $path = $data['PreparedPath'] ?? null;
        if (! is_string($path)
            || ! MediaServerIntegration::isSafeWritablePath($path)
            || ! MediaServerIntegration::isDirectChildOfWritableRoot($path, $root)) {
            return $this->failure(self::INVALID_RESPONSE_MESSAGE);
        }

        return [
            'success' => true,
            'supported' => true,
            'path' => $path,
            'operation_id' => $operationId,
            'message' => 'Prepared',
        ];
    }

    /**
     * Settle companion ownership of a prepared folder after Emby accepted the library.
     *
     * @return array{success: bool, message: string}
     */
    public function commit(MediaServerIntegration $integration, string $operationId): array
    {
        if (! $integration->isEmby() || ! $this->originIsAllowed($integration)) {
            return $this->commitFailure();
        }

        try {
            $response = $this->companionRequest($integration)
                ->post('/M3uEditor/Managed/Libraries/V1/Commit', [
                    'IntegrationId' => $integration->id,
                    'OperationId' => $operationId,
                ]);
        } catch (Throwable) {
            return $this->commitFailure();
        }

        if (! $this->responseOriginIsValid($response, $integration) || ! $response->successful()) {
            return $this->commitFailure();
        }

        $data = $response->json();
        if ($this->validateEnvelope($data, $integration, $operationId) !== null
            || ($data['Success'] ?? null) !== true
            || ($data['State'] ?? null) !== 'committed') {
            return $this->commitFailure();
        }

        return [
            'success' => true,
            'message' => 'Committed',
        ];
    }

    /**
     * Deterministic per-library operation ID: a retried publish resumes the same
     * companion operation (the companion answers `duplicate: true`) instead of
     * colliding with the folder it already prepared for that name.
     */
    private function operationId(MediaServerIntegration $integration, string $name, string $collectionType): string
    {
        return Uuid::uuid5(
            self::OPERATION_NAMESPACE,
            implode("\0", [(string) $integration->id, $collectionType, $name]),
        )->toString();
    }

    /**
     * Validate the parts of the operation envelope that are shared by success
     * and failure responses. Returns the sanitized failure message, or null when
     * the envelope belongs to this integration and operation.
     */
    private function validateEnvelope(mixed $data, MediaServerIntegration $integration, string $operationId): ?string
    {
        if (! is_array($data)) {
            return self::INVALID_RESPONSE_MESSAGE;
        }

        if (($data['CapabilityVersion'] ?? null) !== self::CONTRACT_VERSION) {
            return self::UNSUPPORTED_MESSAGE;
        }

        if (($data['IntegrationId'] ?? null) !== $integration->id) {
            return self::BINDING_CONFLICT_MESSAGE;
        }

        $responseOperationId = $data['OperationId'] ?? null;
        if (! is_string($responseOperationId) || strtolower($responseOperationId) !== $operationId) {
            return self::INVALID_RESPONSE_MESSAGE;
        }

        return null;
    }

    private function prepareErrorMessage(mixed $errorClass): string
    {
        return match ($errorClass) {
            'collision', 'conflict' => self::COLLISION_MESSAGE,
            'filesystem' => self::FILESYSTEM_MESSAGE,
            default => self::PREPARE_FAILED_MESSAGE,
        };
    }

    /** @return array{success: false, supported: bool, path: null, operation_id: null, message: string} */
    private function failure(string $message, bool $supported = true): array
    {
        return [
            'success' => false,
            'supported' => $supported,
            'path' => null,
            'operation_id' => null,
            'message' => $message,
        ];
    }

    /** @return array{success: false, message: string} */
    private function commitFailure(): array
    {
        return [
            'success' => false,
            'message' => self::COMMIT_FAILED_MESSAGE,
        ];
    }
}
