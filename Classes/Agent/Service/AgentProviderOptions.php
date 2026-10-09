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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Agent\Service;

use NITSAN\NsT3AF\Api\AiToolCallingServiceInterface;
use NITSAN\NsT3AF\Credits\CreditsProviderIdentifier;
use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Credits\Service\T3PlanetCreditsModelsService;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use NITSAN\NsT3AF\Service\WizardProviderCatalog;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The AI provider select of the agent window (same idea as the provider select in T3AI / T3AA).
 *
 * Lists "Default" plus every enabled provider of the site that can call tools, that the
 * editor's backend groups may use (provider be_groups and the AI Permissions allowlist).
 * In T3Planet Credits mode the options are Credits model aliases from {@code /v1/models}.
 *
 * @internal
 */
final readonly class AgentProviderOptions
{
    public const DEFAULT = 'default';

    public function __construct(
        private ProviderRepositoryInterface $providers,
        private SiteStorageContext $siteStorageContext,
        private AiToolCallingServiceInterface $toolCallingService,
        private CreditModeResolver $creditModeResolver,
        private WizardProviderCatalog $providerCatalog,
        private AgentGovernanceGuard $governanceGuard,
        private AgentTranslator $translator,
        private T3PlanetCreditsModelsService $creditsModels,
    ) {}

    /**
     * @return list<array{value: string, label: string}>
     */
    public function options(int $pageId, ?BackendUserAuthentication $user): array
    {
        if ($this->creditModeResolver->isActive()) {
            return $this->creditsOptions();
        }

        $storagePid = $this->storagePid($pageId);
        $default = $storagePid !== null ? $this->providers->findDefault($storagePid) : null;
        if ($default instanceof Provider && !$this->isUsable($default, $pageId, $user)) {
            // The default provider is not for this editor: "Default" runs with the fallback, so name that one.
            $default = null;
        }
        if (!$default instanceof Provider && $storagePid !== null) {
            // No default configured: "Default" stands for the highest-priority usable provider.
            $default = $this->fallbackProvider($storagePid, $pageId, $user);
        }
        $options = [[
            'value' => self::DEFAULT,
            'label' => $default instanceof Provider
                ? $this->translator->translate('agent.provider.defaultNamed', [$this->summary($default)])
                : $this->translator->translate('agent.provider.default'),
        ]];

        if ($storagePid === null) {
            return $options;
        }
        $byIdentifier = [];
        foreach ($this->providers->findAllByStoragePid($storagePid) as $provider) {
            if ($this->isUsable($provider, $pageId, $user)) {
                $options[] = ['value' => $provider->identifier, 'label' => $this->summary($provider)];
                $byIdentifier[$provider->identifier] = $provider;
            }
        }

        if (count($byIdentifier) <= 1) {
            // One usable provider: "Default" already is that provider, so there is nothing to choose and the
            // menu stays hidden (it needs at least two entries).
            return [$options[0]];
        }

        return $this->disambiguate($options, $byIdentifier);
    }

    /**
     * The concrete provider a "default" choice runs with. Without a default provider (two active
     * providers, none marked default) "default" resolves to nothing and the turn cannot start, so
     * it maps to the highest-priority provider the editor may use. Other identifiers pass through.
     */
    public function resolveIdentifier(string $identifier, int $pageId, ?BackendUserAuthentication $user): string
    {
        if (($identifier !== '' && $identifier !== self::DEFAULT) || $this->creditModeResolver->isActive()) {
            return $identifier;
        }

        $storagePid = $this->storagePid($pageId);
        if ($storagePid === null) {
            return $identifier;
        }
        $default = $this->providers->findDefault($storagePid);
        if ($default instanceof Provider && $this->isUsable($default, $pageId, $user)) {
            return $identifier;
        }
        $fallback = $this->fallbackProvider($storagePid, $pageId, $user);

        return $fallback instanceof Provider ? $fallback->identifier : $identifier;
    }

    private function fallbackProvider(int $storagePid, int $pageId, ?BackendUserAuthentication $user): ?Provider
    {
        // findAllByStoragePid() orders by is_default, priority, title.
        foreach ($this->providers->findAllByStoragePid($storagePid) as $provider) {
            if ($this->isUsable($provider, $pageId, $user)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Whether a real tool-calling provider (or Credits model) is available.
     *
     * {@see options()} always includes a synthetic "default" row for the select, so the
     * client cannot infer this from an empty list.
     */
    public function hasUsableProvider(int $pageId, ?BackendUserAuthentication $user): bool
    {
        if ($this->creditModeResolver->isActive()) {
            return $this->creditsModels->listModels(true) !== [];
        }

        $storagePid = $this->storagePid($pageId);
        if ($storagePid === null) {
            return false;
        }

        $default = $this->providers->findDefault($storagePid);
        if ($default instanceof Provider && $this->isUsable($default, $pageId, $user)) {
            return true;
        }

        foreach ($this->providers->findAllByStoragePid($storagePid) as $provider) {
            if ($this->isUsable($provider, $pageId, $user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any enabled provider is configured at all (it may still be unable to call tools).
     * Tells "nothing configured yet" apart from "the configured provider cannot run tools".
     */
    public function hasConfiguredProvider(int $pageId): bool
    {
        if ($this->creditModeResolver->isActive()) {
            return true;
        }

        $storagePid = $this->storagePid($pageId);
        if ($storagePid === null) {
            return false;
        }
        foreach ($this->providers->findAllByStoragePid($storagePid) as $provider) {
            if ($provider->isEnabled) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the editor may run the agent with this provider ("default" / empty unless the allowlist leaves no provider).
     */
    public function isAllowed(string $identifier, int $pageId, ?BackendUserAuthentication $user): bool
    {
        if ($identifier === '' || $identifier === self::DEFAULT) {
            // An allowlist that leaves no usable provider also closes "Default".
            return $this->creditModeResolver->isActive()
                || $this->governanceGuard->allowedProviders($user) === null
                || $this->hasUsableProvider($pageId, $user);
        }
        if ($this->creditModeResolver->isActive()) {
            if ($identifier === CreditsProviderIdentifier::IDENTIFIER) {
                return true;
            }

            return $this->creditsModels->isKnownAlias($identifier);
        }
        $storagePid = $this->storagePid($pageId);
        $provider = $storagePid !== null ? $this->providers->findByIdentifier($identifier, $storagePid) : null;

        return $provider instanceof Provider && $this->isUsable($provider, $pageId, $user);
    }

    public function label(string $identifier, int $pageId): string
    {
        if ($identifier === '' || $identifier === self::DEFAULT) {
            return '';
        }
        if ($this->creditModeResolver->isActive()) {
            foreach ($this->creditsModels->listModels() as $model) {
                if ($model['id'] === $identifier) {
                    return $model['label'];
                }
            }

            return $identifier;
        }
        $storagePid = $this->storagePid($pageId);
        $provider = $storagePid !== null ? $this->providers->findByIdentifier($identifier, $storagePid) : null;

        return $provider instanceof Provider ? $this->summary($provider) : $identifier;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function creditsOptions(): array
    {
        $models = $this->creditsModels->listModels(true);
        if ($models === []) {
            return [['value' => self::DEFAULT, 'label' => $this->translator->translate('agent.provider.credits')]];
        }

        $options = [];
        $defaultAlias = $this->creditsModels->resolveDefaultAlias();
        foreach ($models as $model) {
            $label = $model['label'] !== '' ? $model['label'] : $model['id'];
            if ($model['id'] === $defaultAlias) {
                $options[] = [
                    'value' => self::DEFAULT,
                    'label' => $this->translator->translate('agent.provider.creditsNamed', [$label]),
                ];
                continue;
            }
            $options[] = ['value' => $model['id'], 'label' => $label];
        }

        if (($options[0]['value'] ?? '') !== self::DEFAULT) {
            array_unshift($options, [
                'value' => self::DEFAULT,
                'label' => $this->translator->translate('agent.provider.credits'),
            ]);
        }

        return $options;
    }

    private function isUsable(Provider $provider, int $pageId, ?BackendUserAuthentication $user): bool
    {
        if (!$provider->isEnabled) {
            return false;
        }
        if ($user !== null && !$user->isAdmin()) {
            if ($provider->beGroups !== [] && array_intersect($provider->beGroups, array_map('intval', $user->userGroupsUID)) === []) {
                return false;
            }
            $allowed = $this->governanceGuard->allowedProviders($user);
            if ($allowed !== null && !in_array($provider->identifier, $allowed, true)) {
                return false;
            }
        }

        return $this->toolCallingService->supportsToolCalling($provider->identifier, $pageId > 0 ? $pageId : null);
    }

    private function summary(Provider $provider): string
    {
        $title = trim($provider->title);
        if ($title !== '') {
            return $title;
        }

        $adapterType = Provider::normalizeAdapterType($provider->adapterType);
        $adapter = $this->providerCatalog->adapterDisplayLabel($adapterType);

        return trim(sprintf('%s %s', $adapter !== '' ? $adapter : $adapterType, $provider->modelId));
    }

    /**
     * Editors see the provider's name ("OpenAI"). Two providers with the same name are told apart by
     * their model, so the list never shows two identical rows.
     *
     * @param list<array{value: string, label: string}> $options
     * @param array<string, Provider> $providersByIdentifier
     * @return list<array{value: string, label: string}>
     */
    private function disambiguate(array $options, array $providersByIdentifier): array
    {
        $counts = array_count_values(array_column($options, 'label'));
        foreach ($options as $index => $option) {
            $provider = $providersByIdentifier[$option['value']] ?? null;
            if (($counts[$option['label']] ?? 0) > 1 && $provider instanceof Provider && trim($provider->modelId) !== '') {
                $options[$index]['label'] = sprintf('%s (%s)', $option['label'], $provider->modelId);
            }
        }

        return $options;
    }

    private function storagePid(int $pageId): ?int
    {
        return $this->siteStorageContext->resolveStoragePidFromPageId($pageId)
            ?? $this->siteStorageContext->resolveFirstRootStoragePid();
    }
}
