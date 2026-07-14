<?php

declare(strict_types=1);

/*
 * This file is part of the "AI Foundation for TYPO3" (ns_t3af) extension.
 *
 * (c) T3Planet / NITSAN Technologies <support@t3planet.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, either version 2 of the
 * License, or (at your option) any later version.
 *
 * For the full copyright and license information, please read the LICENSE
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Exception\CipherException;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Capability;

/**
 * Validates and persists drawer form submissions for the provider list view.
 *
 * Decouples {@see \NITSAN\NsT3AF\Controller\Backend\ProviderController}
 * from the encryption / repository / registry plumbing so the validation rules
 * (required identifier, unique identifier, known adapter type) are unit-testable
 * without HTTP plumbing.
 *
 * @internal
 */
final class ProviderFormService
{
    private const OPENAI_COMPATIBLE_ADAPTER_TYPE = Provider::ADAPTER_OPENAI_COMPATIBLE;

    /**
     * @var list<string>
     */
    private const ALLOWED_FIELDS = [
        'identifier',
        'title',
        'adapter_type',
        'endpoint_url',
        'api_key',
        'model_id',
        'embedding_model_id',
        'capabilities',
        'temperature',
        'system_prompt',
        'is_default',
        'priority',
        'be_groups',
        'is_enabled',
        'enabled_for_dashboard',
        'pricing_input_per_1m',
        'pricing_output_per_1m',
        'pricing_currency',
        'retention_days_override',
        'cost_center',
        'privacy_level',
        'no_rerouting',
    ];

    /**
     * Drawer toggles are HTML checkboxes: unchecked fields are omitted from POST.
     * On edit, treat missing keys as off (0) so disabling a toggle persists.
     *
     * @var list<string>
     */
    private const CHECKBOX_FIELDS = [
        'is_default',
        'is_enabled',
        'enabled_for_dashboard',
        'no_rerouting',
    ];

    public function __construct(
        private readonly ProviderRepositoryInterface $repository,
        private readonly AdapterRegistry $adapters,
        private readonly CredentialCipher $cipher,
    ) {}

    /**
     * Validate `$input` and persist (insert or update).
     *
     * @param array<string, mixed> $input Raw request payload (POST body, AJAX JSON, …).
     * @return ProviderFormResult Either `errors` (validation failed, nothing stored) or `uid` (success).
     */
    public function save(int $uid, array $input, int $storagePid): ProviderFormResult
    {
        $errors = [];

        if ($storagePid <= 0) {
            return ProviderFormResult::errors(['_storage' => 'Select a page from the page tree first.']);
        }

        if (array_key_exists('adapter_type', $input)) {
            $rawAdapter = $input['adapter_type'];
            $input['adapter_type'] = Provider::normalizeAdapterType(
                is_scalar($rawAdapter) ? trim((string) $rawAdapter) : '',
            );
        }

        $identifier = $this->stringValue($input, 'identifier');
        if ($identifier === '') {
            $errors['identifier'] = 'Identifier is required.';
        } elseif (!preg_match('#^[A-Za-z0-9._-]{1,64}$#', $identifier)) {
            $errors['identifier'] = 'Identifier may only contain letters, digits, dot, underscore and hyphen (max 64).';
        } else {
            $existing = $this->repository->findByIdentifier($identifier, $storagePid);
            if ($existing instanceof Provider && $existing->uid !== $uid) {
                $errors['identifier'] = sprintf('Identifier "%s" is already in use.', $identifier);
            }
        }

        $adapterType = $this->stringValue($input, 'adapter_type');
        if ($adapterType === '') {
            $errors['adapter_type'] = 'Adapter type is required.';
        } elseif (!$this->adapters->has($adapterType)) {
            $errors['adapter_type'] = sprintf('Adapter type "%s" is not registered.', $adapterType);
        }

        $title = $this->stringValue($input, 'title');
        if ($title === '') {
            $errors['title'] = 'Display name is required.';
        }

        $endpointUrl = $this->stringValue($input, 'endpoint_url');
        if (Provider::adapterRequiresEndpoint($adapterType) && $this->adapters->has($adapterType)) {
            if ($endpointUrl === '') {
                $endpointUrl = trim($this->adapters->get($adapterType)->getDefaultEndpoint());
            }
            if ($endpointUrl === '') {
                $errors['endpoint_url'] = $adapterType === Provider::ADAPTER_SYMFONY_OLLAMA
                    ? 'Ollama base URL is required (e.g. http://host.docker.internal:11434 in DDEV).'
                    : 'API base URL is required for Custom / Other.';
            } elseif (filter_var($endpointUrl, FILTER_VALIDATE_URL) === false) {
                $errors['endpoint_url'] = 'Enter a valid URL for the API base.';
            } elseif ($endpointUrl !== $this->stringValue($input, 'endpoint_url')) {
                $input['endpoint_url'] = $endpointUrl;
            }
        }

        $pricingInput = $this->floatValue($input, 'pricing_input_per_1m');
        if ($pricingInput < 0.0) {
            $errors['pricing_input_per_1m'] = 'Input price must be zero or greater.';
        }
        $pricingOutput = $this->floatValue($input, 'pricing_output_per_1m');
        if ($pricingOutput < 0.0) {
            $errors['pricing_output_per_1m'] = 'Output price must be zero or greater.';
        }

        $currency = strtoupper($this->stringValue($input, 'pricing_currency'));
        if ($currency !== '' && !preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['pricing_currency'] = 'Currency must be a 3-letter ISO code.';
        }

        $apiKeyError = $this->validateApiKey($uid, $adapterType, $input);
        if ($apiKeyError !== '') {
            $errors['api_key'] = $apiKeyError;
        }

        if ($errors !== []) {
            return ProviderFormResult::errors($errors);
        }

        try {
            $payload = $this->buildPayload($input, $uid);
        } catch (CipherException $e) {
            return ProviderFormResult::errors(['api_key' => $e->getMessage()]);
        }
        if ($uid === 0) {
            $payload['pid'] = $storagePid;
        }
        $persistedUid = $this->repository->save($uid, $payload);
        if (($payload['is_default'] ?? 0) === 1) {
            $this->repository->setDefault($persistedUid, $storagePid);
        }

        return ProviderFormResult::success($persistedUid);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, int|float|string|null>
     */
    private function buildPayload(array $input, int $uid): array
    {
        if ($uid > 0) {
            $input = $this->normalizeCheckboxFieldsForEdit($input);
        }

        $payload = [];
        foreach (self::ALLOWED_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $payload[$field] = $this->coerceField($field, $input[$field]);
        }
        if (isset($payload['api_key']) && is_string($payload['api_key']) && $payload['api_key'] !== '' && !$this->cipher->isEncrypted($payload['api_key'])) {
            $payload['api_key'] = $this->cipher->encrypt($payload['api_key']);
        } elseif (($payload['api_key'] ?? '') === '' && $uid > 0) {
            // Empty input on edit = keep existing ciphertext untouched.
            unset($payload['api_key']);
        }

        if ($uid === 0) {
            $payload['last_status'] = Provider::LAST_STATUS_UNKNOWN;
            $payload['last_status_message'] = Provider::LAST_STATUS_UNKNOWN;
            $payload['last_status_at'] = 0;
        }

        $embeddingModelId = trim((string) ($payload['embedding_model_id'] ?? ''));
        if ($embeddingModelId !== '') {
            $caps = $payload['capabilities'] ?? '';
            if (is_string($caps)) {
                $capList = Capability::fromCsv($caps);
            } elseif (is_array($caps)) {
                $capList = array_map('strval', array_values($caps));
            } else {
                $capList = [];
            }
            if (!in_array(Capability::EMBEDDINGS, $capList, true)) {
                $capList[] = Capability::EMBEDDINGS;
                $payload['capabilities'] = Capability::toCsv($capList);
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeCheckboxFieldsForEdit(array $input): array
    {
        foreach (self::CHECKBOX_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                $input[$field] = 0;
            }
        }

        return $input;
    }

    private function coerceField(string $field, mixed $value): int|float|string|null
    {
        return match ($field) {
            'is_default' => ((bool) $value) ? 1 : 0,
            'priority' => (int) $value,
            'retention_days_override' => max(0, (int) $value),
            'temperature' => (float) $value,
            'pricing_input_per_1m' => max(0.0, (float) $value),
            'pricing_output_per_1m' => max(0.0, (float) $value),
            'is_enabled', 'enabled_for_dashboard' => ((bool) $value) ? 1 : 0,
            'no_rerouting' => ((bool) $value) ? 1 : 0,
            'privacy_level' => \NITSAN\NsT3AF\Governance\PrivacyLevel::fromString((string) $value)->value,
            'pricing_currency' => strtoupper(trim((string) $value)) !== '' ? strtoupper(trim((string) $value)) : 'USD',
            'capabilities' => is_array($value) ? Capability::toCsv(array_map('strval', array_values($value))) : (string) $value,
            'be_groups' => is_array($value)
                ? implode(',', array_map(static fn($v): int => (int) $v, $value))
                : (string) $value,
            default => is_scalar($value) || $value === null ? (string) $value : '',
        };
    }

    /**
     * @param array<string, mixed> $input
     */
    private function validateApiKey(int $uid, string $adapterType, array $input): string
    {
        if (!Provider::adapterRequiresApiKey($adapterType)) {
            return '';
        }

        if ($this->stringValue($input, 'api_key') !== '') {
            return '';
        }

        if ($uid > 0) {
            $existing = $this->repository->findByUid($uid);
            if ($existing instanceof Provider && trim($existing->apiKeyCipher) !== '') {
                return '';
            }
        }

        return 'API key is required.';
    }

    /**
     * @param array<string, mixed> $input
     */
    private function stringValue(array $input, string $key): string
    {
        $raw = $input[$key] ?? '';

        return is_scalar($raw) ? trim((string) $raw) : '';
    }

    /**
     * @param array<string, mixed> $input
     */
    private function floatValue(array $input, string $key): float
    {
        $raw = $input[$key] ?? 0;
        if (!is_numeric($raw)) {
            return 0.0;
        }

        return (float) $raw;
    }
}
