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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;

/**
 * Loads and assembles all T3Planet Credits dashboard data for the Providers UI.
 *
 * @internal
 */
final class CreditsDashboardService
{
    public function __construct(
        private readonly CreditModeResolver $creditModeResolver,
        private readonly LicenseKeyResolver $licenseKeyResolver,
        private readonly BalanceService $balanceService,
        private readonly CurrentPlanService $currentPlanService,
        private readonly ProductCatalogService $productCatalogService,
        private readonly FeatureCatalogService $featureCatalogService,
        private readonly LocalReceiptCache $localReceiptCache,
        private readonly CreditsDashboardAssembler $assembler,
        private readonly CreditsApiErrorMessageResolver $errorMessages,
        private readonly TokenResolver $tokenResolver,
        private readonly CreditsPricingResolver $pricingResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildForProvidersPage(string $returnUrl): array
    {
        if (!$this->creditModeResolver->isActive()) {
            return $this->assembler->emptyPrompt();
        }

        return $this->fetchAndAssemble($returnUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchAndAssemble(string $returnUrl): array
    {
        $errors = [];
        try {
            $this->syncDiscoveredLicenseKeysIfNeeded();
        } catch (CreditsApiException $exception) {
            $errors['attach'] = $this->errorMessages->resolve($exception);
        }

        $balance = [];
        $plan = [];
        $products = [];
        $features = [];
        $authRejected = false;

        try {
            $balance = $this->balanceService->fetch();
            $this->pricingResolver->rememberFromPayload($balance);
        } catch (CreditsApiException $exception) {
            $authRejected = $this->recordApiException($exception, $errors, 'balance');
        } catch (\Throwable $exception) {
            $errors['balance'] = $exception->getMessage();
        }

        if (!$authRejected) {
            try {
                $plan = $this->currentPlanService->fetch();
            } catch (CreditsApiException $exception) {
                $authRejected = $this->recordApiException($exception, $errors, 'plan');
            } catch (\Throwable $exception) {
                $errors['plan'] = $exception->getMessage();
            }
        }

        if (!$authRejected) {
            try {
                $products = $this->productCatalogService->fetch($returnUrl);
                $this->pricingResolver->rememberFromPayload($products);
            } catch (CreditsApiException $exception) {
                $authRejected = $this->recordApiException($exception, $errors, 'products');
            } catch (\Throwable $exception) {
                $errors['products'] = $exception->getMessage();
            }
        }

        if (!$authRejected) {
            try {
                $features = $this->featureCatalogService->fetch();
                $this->pricingResolver->rememberFromPayload($features);
            } catch (CreditsApiException $exception) {
                $this->recordApiException($exception, $errors, 'features');
            } catch (\Throwable $exception) {
                $errors['features'] = $exception->getMessage();
            }
        }

        $receipts = $this->localReceiptCache->listRecent(20);

        // Same failure (e.g. network_error) hits Balance, Plan, Products, Features — show one banner.
        $errors = array_values(array_unique($errors));

        return $this->assembler->assemble(
            $balance,
            $plan,
            $products,
            $features,
            $receipts,
            $errors,
            $returnUrl,
        );
    }

    private function syncDiscoveredLicenseKeysIfNeeded(): void
    {
        if (!$this->creditModeResolver->isActive()) {
            return;
        }

        $discovered = $this->licenseKeyResolver->buildLicenseKeysCommaSeparated();
        if ($discovered === '') {
            return;
        }

        try {
            $this->tokenResolver->syncLicensePool($discovered);
        } catch (CreditsApiException $exception) {
            if (!$this->tokenResolver->invalidateOnUnauthorized($exception)) {
                // Surface attach failures (domain_mismatch, license_invalid, …) on the dashboard.
                throw $exception;
            }
        }
    }

    /**
     * @param array<string, string> $errors
     */
    private function recordApiException(CreditsApiException $exception, array &$errors, string $section): bool
    {
        $errors[$section] = $this->errorMessages->resolve($exception);

        return $this->tokenResolver->invalidateOnUnauthorized($exception);
    }
}
