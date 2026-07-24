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

namespace NITSAN\NsT3AF\Controller\Backend;

use NITSAN\NsT3AF\Api\CreditsPricing;
use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Service\BalanceService;
use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Credits\Service\CreditsApiErrorMessageResolver;
use NITSAN\NsT3AF\Credits\Service\CreditsDashboardService;
use NITSAN\NsT3AF\Credits\Service\CreditsEstimateService;
use NITSAN\NsT3AF\Credits\Service\CreditsPricingResolver;
use NITSAN\NsT3AF\Credits\Service\CreditsReturnUrlBuilder;
use NITSAN\NsT3AF\Credits\Service\CurrentPlanService;
use NITSAN\NsT3AF\Credits\Service\FeatureCatalogService;
use NITSAN\NsT3AF\Credits\Service\LicenseKeyResolver;
use NITSAN\NsT3AF\Credits\Service\ProductCatalogService;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Credits\Service\TokenResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Backend JSON endpoints for T3Planet Credits mode.
 *
 * @internal
 */
final class CreditModeController
{
    public function __construct(
        private readonly RuntimeSettingsService $runtimeSettings,
        private readonly CreditModeResolver $creditModeResolver,
        private readonly LicenseKeyResolver $licenseKeyResolver,
        private readonly TokenResolver $tokenResolver,
        private readonly BalanceService $balanceService,
        private readonly CurrentPlanService $currentPlanService,
        private readonly FeatureCatalogService $featureCatalogService,
        private readonly ProductCatalogService $productCatalogService,
        private readonly CreditsApiErrorMessageResolver $errorMessages,
        private readonly CreditsDashboardService $dashboardService,
        private readonly CreditsEstimateService $estimateService,
        private readonly CreditsPricingResolver $pricingResolver,
        private readonly CreditsReturnUrlBuilder $creditsReturnUrlBuilder,
    ) {}

    public function statusAction(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse([
            'featureAvailable' => $this->creditModeResolver->isPubliclyAvailable(),
            'creditMode' => $this->runtimeSettings->isCreditModeEnabled(),
            'active' => $this->creditModeResolver->isActive(),
            'pricing' => $this->serializePricing($this->pricingResolver->resolve()),
            'creditsBearerToken' => $this->runtimeSettings->getTokenPlain() ?? '',
            'licenseKeys' => $this->runtimeSettings->getLicenseKeys(),
            'selectedLicenseExtKey' => $this->runtimeSettings->getSelectedLicenseExtKey(),
            'licenses' => array_map(
                static fn($ctx): array => [
                    'licenseKey' => $ctx->licenseKey,
                    'extensionKey' => $ctx->extensionKey,
                    'orderId' => $ctx->orderId,
                    'expiresAt' => $ctx->expiresAt,
                    'isLifetime' => $ctx->isLifetime,
                ],
                $this->licenseKeyResolver->listAvailable(),
            ),
        ]);
    }

    public function toggleAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->parseRequestBody($request);
        $enabled = $this->parseBoolean($body['enabled'] ?? false);
        if ($enabled && !$this->creditModeResolver->isPubliclyAvailable()) {
            return new JsonResponse([
                'error_code' => 'credits_unavailable',
                'userMessage' => 'T3Planet Credits are coming soon.',
            ], 403);
        }
        $this->runtimeSettings->save(['credit_mode' => $enabled ? 1 : 0]);

        if ($enabled) {
            $licenseKeys = $this->licenseKeyResolver->buildLicenseKeysCommaSeparated();
            if ($licenseKeys !== '' && $this->runtimeSettings->getTokenPlain() !== null) {
                try {
                    $this->tokenResolver->syncLicensePool($licenseKeys);
                } catch (CreditsApiException) {
                    // Toggle still succeeds; user can Activate or reload dashboard to retry attach.
                }
            }
        }

        return new JsonResponse([
            'creditMode' => $this->runtimeSettings->isCreditModeEnabled(),
            'active' => $this->creditModeResolver->isActive(),
        ]);
    }

    public function saveLicenseAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->parseRequestBody($request);
        $selected = trim((string) ($body['selected_license_ext_key'] ?? ''));
        $licenseKeys = trim((string) ($body['license_keys'] ?? ''));
        if ($licenseKeys === '') {
            $licenseKeys = $this->licenseKeyResolver->buildLicenseKeysCommaSeparated();
        }

        if ($selected !== '') {
            $this->runtimeSettings->save(['selected_license_ext_key' => $selected]);
        }

        if ($this->runtimeSettings->getTokenPlain() !== null) {
            try {
                $this->tokenResolver->syncLicensePool($licenseKeys);
            } catch (CreditsApiException) {
                // Selection saved; attach can be retried via Activate or dashboard reload.
            }
        } else {
            $this->runtimeSettings->save(['license_keys' => $licenseKeys]);
        }

        return new JsonResponse([
            'licenseKeys' => $this->runtimeSettings->getLicenseKeys(),
            'selectedLicenseExtKey' => $this->runtimeSettings->getSelectedLicenseExtKey(),
        ]);
    }

    public function activateAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->creditModeResolver->isPubliclyAvailable()) {
            return new JsonResponse([
                'error_code' => 'credits_unavailable',
                'userMessage' => 'T3Planet Credits are coming soon.',
            ], 403);
        }

        try {
            $licenseKeys = $this->licenseKeyResolver->buildLicenseKeysCommaSeparated();
            if ($licenseKeys === '') {
                return $this->errorResponse(new CreditsApiException(
                    CreditsApiErrorCodes::NO_LICENSES,
                    400,
                    'No T3Planet license keys found',
                ));
            }
            $this->runtimeSettings->save(['credit_mode' => 1]);
            $this->tokenResolver->syncLicensePool($licenseKeys);
            $balance = $this->balanceService->fetch();
            $this->pricingResolver->rememberFromPayload($balance);

            return new JsonResponse([
                'status' => true,
                'creditMode' => true,
                'active' => $this->creditModeResolver->isActive(),
                'creditsBearerToken' => $this->runtimeSettings->getTokenPlain() ?? '',
                'balance' => $balance,
                'pricing' => $this->serializePricing($this->pricingResolver->resolve()),
            ]);
        } catch (CreditsApiException $exception) {
            return $this->errorResponse($exception);
        } catch (\Throwable $exception) {
            return $this->unexpectedErrorResponse($exception);
        }
    }

    public function dashboardAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->creditModeResolver->isActive()) {
            return new JsonResponse([
                'status' => false,
                'error_code' => 'not_active',
                'userMessage' => 'Activate T3Planet Credits to load your dashboard.',
            ], 400);
        }

        try {
            $returnUrl = $this->creditsReturnUrlBuilder->normalize(
                (string) ($request->getQueryParams()['return'] ?? ''),
            );

            return new JsonResponse([
                'status' => true,
                'dashboard' => $this->dashboardService->fetchAndAssemble($returnUrl),
            ]);
        } catch (CreditsApiException $exception) {
            return $this->errorResponse($exception);
        } catch (\Throwable $exception) {
            return $this->unexpectedErrorResponse($exception);
        }
    }

    public function balanceAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->wrapApiCall(fn(): array => $this->balanceService->fetch());
    }

    public function currentPlanAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->wrapApiCall(fn(): array => $this->currentPlanService->fetch());
    }

    public function featuresAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->wrapApiCall(fn(): array => $this->featureCatalogService->fetch());
    }

    public function productsAction(ServerRequestInterface $request): ResponseInterface
    {
        $redirectTo = $this->creditsReturnUrlBuilder->normalize(
            trim((string) ($request->getQueryParams()['redirect_to'] ?? $request->getQueryParams()['return'] ?? '')),
        );

        return $this->wrapApiCall(function () use ($redirectTo): array {
            $payload = $this->productCatalogService->fetch($redirectTo);
            $this->pricingResolver->rememberFromPayload($payload);

            return $payload;
        });
    }

    public function estimateAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->parseRequestBody($request);
        $metaJson = $body['meta_json'] ?? [];
        if (!is_array($metaJson)) {
            $metaJson = [];
        }
        $endpoint = ($body['endpoint'] ?? 'charge') === 'embed' ? 'embed' : 'charge';

        try {
            $estimate = $this->estimateService->estimate(
                (string) ($body['feature_key'] ?? ''),
                $metaJson,
                $endpoint,
            );

            return new JsonResponse([
                'status' => true,
                'feature_key' => $estimate->featureKey,
                'endpoint' => $estimate->endpoint,
                'estimated_tokens' => $estimate->estimatedTokens,
                'estimated_credit_units' => $estimate->estimatedCreditUnits,
                'estimated_credits' => $estimate->estimatedCredits,
                'billable_tokens' => $estimate->billableTokens,
                'estimate_label' => $estimate->label(),
                'default_model' => $estimate->defaultModel,
                'default_backend' => $estimate->defaultBackend,
                'pricing' => $this->serializePricing($estimate->pricing),
            ]);
        } catch (CreditsApiException $exception) {
            return $this->errorResponse($exception);
        } catch (\Throwable $exception) {
            return $this->unexpectedErrorResponse($exception);
        }
    }

    /**
     * @param callable(): array<string, mixed> $call
     */
    private function wrapApiCall(callable $call): JsonResponse
    {
        try {
            return new JsonResponse($call());
        } catch (CreditsApiException $exception) {
            return $this->errorResponse($exception);
        } catch (\Throwable $exception) {
            return $this->unexpectedErrorResponse($exception);
        }
    }

    private function errorResponse(CreditsApiException $exception): JsonResponse
    {
        return new JsonResponse(
            $this->errorMessages->buildErrorPayload($exception),
            CreditsApiErrorCodes::httpStatus($exception->errorCode, $exception->httpStatus),
        );
    }

    private function unexpectedErrorResponse(\Throwable $exception): JsonResponse
    {
        $apiException = new CreditsApiException(
            CreditsApiErrorCodes::INTERNAL_ERROR,
            500,
            $exception->getMessage(),
            [],
            $exception,
        );

        return new JsonResponse(
            $this->errorMessages->buildErrorPayload($apiException),
            500,
        );
    }

    /**
     * TYPO3 AjaxRequest.post() sends application/x-www-form-urlencoded via getParsedBody().
     * Raw JSON body is supported as a fallback.
     *
     * @return array<string, mixed>
     */
    private function parseRequestBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }

        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function parseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePricing(CreditsPricing $pricing): array
    {
        return $pricing->toArray();
    }
}
