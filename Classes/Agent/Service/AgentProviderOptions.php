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
 * In T3Planet Credits mode there is only the credits option.
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
    ) {}

    /**
     * @return list<array{value: string, label: string}>
     */
    public function options(int $pageId, ?BackendUserAuthentication $user): array
    {
        if ($this->creditModeResolver->isActive()) {
            return [['value' => self::DEFAULT, 'label' => $this->translator->translate('agent.provider.credits')]];
        }

        $storagePid = $this->storagePid($pageId);
        $default = $storagePid !== null ? $this->providers->findDefault($storagePid) : null;
        $options = [[
            'value' => self::DEFAULT,
            'label' => $default instanceof Provider
                ? $this->translator->translate('agent.provider.defaultNamed', [$this->summary($default)])
                : $this->translator->translate('agent.provider.default'),
        ]];

        if ($storagePid === null) {
            return $options;
        }
        foreach ($this->providers->findAllByStoragePid($storagePid) as $provider) {
            if ($this->isUsable($provider, $pageId, $user)) {
                $options[] = ['value' => $provider->identifier, 'label' => $this->summary($provider)];
            }
        }

        return $options;
    }

    /**
     * Whether the editor may run the agent with this provider ("default" / empty always).
     */
    public function isAllowed(string $identifier, int $pageId, ?BackendUserAuthentication $user): bool
    {
        if ($identifier === '' || $identifier === self::DEFAULT) {
            return true;
        }
        if ($this->creditModeResolver->isActive()) {
            return $identifier === CreditsProviderIdentifier::IDENTIFIER;
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
        $storagePid = $this->storagePid($pageId);
        $provider = $storagePid !== null ? $this->providers->findByIdentifier($identifier, $storagePid) : null;

        return $provider instanceof Provider ? $this->summary($provider) : $identifier;
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
        $adapterType = Provider::normalizeAdapterType($provider->adapterType);
        $adapter = $this->providerCatalog->adapterDisplayLabel($adapterType);

        return sprintf('%s (%s, %s)', $provider->title, $adapter !== '' ? $adapter : $adapterType, $provider->modelId);
    }

    private function storagePid(int $pageId): ?int
    {
        return $this->siteStorageContext->resolveStoragePidFromPageId($pageId)
            ?? $this->siteStorageContext->resolveFirstRootStoragePid();
    }
}
