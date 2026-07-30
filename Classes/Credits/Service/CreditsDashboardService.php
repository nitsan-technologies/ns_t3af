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

use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\CreditsReceiptEntryType;
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
    public function buildForProvidersPage(
        string $returnUrl,
        int $currentPage = 1,
        int $perPage = 20,
        string $entryTypeFilter = CreditsReceiptEntryType::ALL,
    ): array {
        if (!$this->creditModeResolver->isActive()) {
            return $this->assembler->emptyPrompt();
        }

        return $this->fetchAndAssemble($returnUrl, $currentPage, $perPage, $entryTypeFilter);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchAndAssemble(
        string $returnUrl,
        int $currentPage = 1,
        int $perPage = 20,
        string $entryTypeFilter = CreditsReceiptEntryType::ALL,
    ): array {
        $errors = [];
        $abortFetches = false;
        try {
            $this->syncDiscoveredLicenseKeysIfNeeded();
        } catch (CreditsApiException $exception) {
            $this->recordApiException($exception, $errors);
            $abortFetches = $this->shouldAbortFurtherFetches($exception);
        }

        $balance = [];
        $plan = [];
        $products = [];
        $features = [];
        $authRejected = false;

        if (!$abortFetches) {
            try {
                $balance = $this->balanceService->fetch();
                $this->pricingResolver->rememberFromPayload($balance);
            } catch (CreditsApiException $exception) {
                $authRejected = $this->recordApiException($exception, $errors);
                $abortFetches = $this->shouldAbortFurtherFetches($exception);
            } catch (\Throwable $exception) {
                $errors['balance:' . $exception->getMessage()] = $exception->getMessage();
            }
        }

        if (!$authRejected && !$abortFetches) {
            try {
                $plan = $this->currentPlanService->fetch();
            } catch (CreditsApiException $exception) {
                $authRejected = $this->recordApiException($exception, $errors);
                $abortFetches = $this->shouldAbortFurtherFetches($exception);
            } catch (\Throwable $exception) {
                $errors['plan:' . $exception->getMessage()] = $exception->getMessage();
            }
        }

        if (!$authRejected && !$abortFetches) {
            try {
                $products = $this->productCatalogService->fetch($returnUrl);
                $this->pricingResolver->rememberFromPayload($products);
            } catch (CreditsApiException $exception) {
                $authRejected = $this->recordApiException($exception, $errors);
                $abortFetches = $this->shouldAbortFurtherFetches($exception);
            } catch (\Throwable $exception) {
                $errors['products:' . $exception->getMessage()] = $exception->getMessage();
            }
        }

        if (!$authRejected && !$abortFetches) {
            try {
                $features = $this->featureCatalogService->fetch();
                $this->pricingResolver->rememberFromPayload($features);
            } catch (CreditsApiException $exception) {
                $this->recordApiException($exception, $errors);
            } catch (\Throwable $exception) {
                $errors['features:' . $exception->getMessage()] = $exception->getMessage();
            }
        }

        $perPage = max(1, $perPage);
        $entryTypeFilter = CreditsReceiptEntryType::normalizeFilter($entryTypeFilter);
        $totalCount = $this->localReceiptCache->countBillable($entryTypeFilter);
        $totalPages = max(1, (int) ceil($totalCount / $perPage));
        $currentPage = min(max(1, $currentPage), $totalPages);
        $offset = ($currentPage - 1) * $perPage;
        $receipts = $this->localReceiptCache->listBillable($perPage, $offset, $entryTypeFilter);
        $usedUnitsByFeatureKey = $this->localReceiptCache->sumCostUnitsByFeatureKey();
        $lifetimeUnits = (int) array_sum($usedUnitsByFeatureKey);
        $windowUnits = $this->localReceiptCache->sumCostUnitsSince(time() - (7 * 86400));

        // Same remote failure (e.g. rate_limited with different retry_after) — show one banner.
        $errors = array_values($errors);

        $dashboard = $this->assembler->assemble(
            $balance,
            $plan,
            $products,
            $features,
            $receipts,
            $errors,
            $returnUrl,
            $usedUnitsByFeatureKey,
            $lifetimeUnits,
            $windowUnits,
        );
        $dashboard['transactionsTotalCount'] = $totalCount;
        $dashboard['transactionsCurrentPage'] = $currentPage;
        $dashboard['transactionsPerPage'] = $perPage;
        $dashboard['transactionsEntryType'] = $entryTypeFilter;

        return $dashboard;
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
    private function recordApiException(CreditsApiException $exception, array &$errors): bool
    {
        $errors[$exception->errorCode] = $this->errorMessages->resolve($exception);

        return $this->tokenResolver->invalidateOnUnauthorized($exception);
    }

    private function shouldAbortFurtherFetches(CreditsApiException $exception): bool
    {
        return match ($exception->errorCode) {
            CreditsApiErrorCodes::RATE_LIMITED,
            CreditsApiErrorCodes::NETWORK_ERROR,
            CreditsApiErrorCodes::INVALID_RESPONSE,
            CreditsApiErrorCodes::INTERNAL_ERROR,
            CreditsApiErrorCodes::UPSTREAM_AI_ERROR,
            CreditsApiErrorCodes::UPSTREAM_AI_TIMEOUT => true,
            default => false,
        };
    }
}
