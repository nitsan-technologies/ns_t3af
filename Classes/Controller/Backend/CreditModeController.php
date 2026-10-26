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

namespace NITSAN\NsT3AF\Controller\Backend;

use NITSAN\NsT3AF\Access\RecordAccessGate;
use NITSAN\NsT3AF\Api\CreditsPricing;
use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Service\BalanceService;
use NITSAN\NsT3AF\Credits\Service\CreditModeRequestParser;
use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Credits\Service\CreditsApiErrorMessageResolver;
use NITSAN\NsT3AF\Credits\Service\CreditsDashboardService;
use NITSAN\NsT3AF\Credits\Service\CreditsEstimateService;
use NITSAN\NsT3AF\Credits\Service\CreditsPricingResolver;
use NITSAN\NsT3AF\Credits\Service\CreditsReturnUrlBuilder;
use NITSAN\NsT3AF\Credits\Service\CurrentPlanService;
use NITSAN\NsT3AF\Credits\Service\FeatureCatalogService;
use NITSAN\NsT3AF\Credits\Service\ProductCatalogService;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Credits\Service\TokenResolver;
use NITSAN\NsT3AF\Service\CredentialCipher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Backend JSON endpoints for T3Planet Credits mode.
 *
 * @internal
 */
final class CreditModeController
{
    private const PROVIDER_TABLE = 'tx_nst3af_provider';

    public function __construct(
        private readonly RuntimeSettingsService $runtimeSettings,
        private readonly CreditModeResolver $creditModeResolver,
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
        private readonly RecordAccessGate $recordAccessGate,
        private readonly CredentialCipher $credentialCipher,
    ) {}

    public function statusAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyUnlessCreditsManager();
        if ($denied !== null) {
            return $denied;
        }

        return new JsonResponse([
            'featureAvailable' => $this->creditModeResolver->isPubliclyAvailable(),
            'creditMode' => $this->runtimeSettings->isCreditModeEnabled(),
            'active' => $this->creditModeResolver->isActive(),
            'needsContact' => $this->tokenResolver->needsContactForActivation(),
            'pricing' => $this->serializePricing($this->pricingResolver->resolve()),
            'creditsBearerToken' => $this->bearerTokenForResponse(includePlain: false),
            'licenseKeys' => $this->runtimeSettings->getLicenseKeys(),
            'selectedLicenseExtKey' => $this->runtimeSettings->getSelectedLicenseExtKey(),
            'licenses' => [],
        ]);
    }

    public function toggleAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyUnlessCreditsManager();
        if ($denied !== null) {
            return $denied;
        }

        $body = $this->parseRequestBody($request);
        $enabled = CreditModeRequestParser::enabledFromBody($body);
        if ($enabled === null) {
            return new JsonResponse([
                'status' => false,
                'error_code' => CreditsApiErrorCodes::REQUIRED_FIELD_MISSING,
                'userMessage' => 'Send enabled as true or false.',
            ], 400);
        }
        if ($enabled && !$this->creditModeResolver->isPubliclyAvailable()) {
            return new JsonResponse([
                'error_code' => 'credits_unavailable',
                'userMessage' => 'T3Planet Credits are coming soon.',
            ], 403);
        }
        $this->runtimeSettings->save(['credit_mode' => $enabled ? 1 : 0]);

        if ($enabled && $this->runtimeSettings->getTokenPlain() === null) {
            try {
                $this->tokenResolver->activateTrialToken();
            } catch (CreditsApiException) {
                // Toggle still succeeds; user can Activate to retry mint.
            }
        }

        return new JsonResponse([
            'creditMode' => $this->runtimeSettings->isCreditModeEnabled(),
            'active' => $this->creditModeResolver->isActive(),
        ]);
    }

    public function saveLicenseAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyUnlessCreditsManager();
        if ($denied !== null) {
            return $denied;
        }

        return new JsonResponse([
            'licenseKeys' => '',
            'selectedLicenseExtKey' => 'ns_t3af',
        ]);
    }

    public function activateAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyUnlessCreditsManager();
        if ($denied !== null) {
            return $denied;
        }

        if (!$this->creditModeResolver->isPubliclyAvailable()) {
            return new JsonResponse([
                'error_code' => 'credits_unavailable',
                'userMessage' => 'T3Planet Credits are coming soon.',
            ], 403);
        }

        try {
            $body = $this->parseRequestBody($request);
            $contactOverride = null;
            if ($this->tokenResolver->needsContactForActivation()) {
                $contact = TokenResolver::normalizeContact([
                    'name' => (string) ($body['name'] ?? ''),
                    'email' => (string) ($body['email'] ?? ''),
                ]);
                if (!isset($contact['name'], $contact['email'])) {
                    return new JsonResponse([
                        'status' => false,
                        'error_code' => CreditsApiErrorCodes::CONTACT_REQUIRED,
                        'userMessage' => 'Name and a valid email are required to activate free credits.',
                    ], 400);
                }
                $contactOverride = $contact;
            }

            $this->runtimeSettings->save(['credit_mode' => 1]);
            $activation = $this->tokenResolver->activateTrialToken($contactOverride);
            $balance = $this->fetchBalanceOrResync();
            $this->pricingResolver->rememberFromPayload($balance);

            return new JsonResponse([
                'status' => true,
                'creditMode' => true,
                'active' => $this->creditModeResolver->isActive(),
                'needsContact' => false,
                'adoptedExistingAccount' => ($activation['action'] ?? '') === 'adopted',
                'creditsBearerToken' => $this->bearerTokenForResponse(includePlain: true),
                'balance' => $balance,
                'pricing' => $this->serializePricing($this->pricingResolver->resolve()),
            ]);
        } catch (CreditsApiException $exception) {
            return $this->errorResponse($exception);
        } catch (\Throwable $exception) {
            return $this->unexpectedErrorResponse($exception);
        }
    }

    /**
     * Redeem a claim code emailed after a website purchase, or after account recovery.
     */
    public function claimAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyUnlessCreditsManager();
        if ($denied !== null) {
            return $denied;
        }

        if (!$this->creditModeResolver->isPubliclyAvailable()) {
            return new JsonResponse([
                'error_code' => 'credits_unavailable',
                'userMessage' => 'T3Planet Credits are coming soon.',
            ], 403);
        }

        try {
            $body = $this->parseRequestBody($request);
            $claimCode = CreditModeRequestParser::claimCodeFromBody($body);
            if ($claimCode === null) {
                return new JsonResponse([
                    'status' => false,
                    'error_code' => CreditsApiErrorCodes::REQUIRED_FIELD_MISSING,
                    'userMessage' => 'Claim code must be a single text value.',
                ], 400);
            }
            if ($claimCode === '') {
                return new JsonResponse([
                    'status' => false,
                    'error_code' => CreditsApiErrorCodes::REQUIRED_FIELD_MISSING,
                    'userMessage' => 'Paste the claim code from your activation email.',
                ], 400);
            }

            $this->runtimeSettings->save(['credit_mode' => 1]);
            $claim = $this->tokenResolver->claimWithCode($claimCode);
            $balance = $this->fetchBalanceOrResync();
            $this->pricingResolver->rememberFromPayload($balance);

            return new JsonResponse([
                'status' => true,
                'creditMode' => true,
                'active' => $this->creditModeResolver->isActive(),
                'needsContact' => false,
                'action' => $claim['action'],
                'mergedIntoExistingAccount' => $claim['merged'] ?? false,
                'creditsBearerToken' => $this->bearerTokenForResponse(includePlain: true),
                'balance' => $balance,
                'pricing' => $this->serializePricing($this->pricingResolver->resolve()),
            ]);
        } catch (CreditsApiException $exception) {
            return $this->errorResponse($exception);
        } catch (\Throwable $exception) {
            return $this->unexpectedErrorResponse($exception);
        }
    }

    /**
     * Ask for a fresh claim code to be emailed to the account's registered address.
     *
     * Always reports the same thing: the server deliberately does not reveal whether an account
     * exists for the address, and echoing a difference here would undo that.
     */
    public function requestClaimCodeAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyUnlessCreditsManager();
        if ($denied !== null) {
            return $denied;
        }

        if (!$this->creditModeResolver->isPubliclyAvailable()) {
            return new JsonResponse([
                'error_code' => 'credits_unavailable',
                'userMessage' => 'T3Planet Credits are coming soon.',
            ], 403);
        }

        try {
            $body = $this->parseRequestBody($request);
            $email = trim((string) ($body['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return new JsonResponse([
                    'status' => false,
                    'error_code' => CreditsApiErrorCodes::REQUIRED_FIELD_MISSING,
                    'userMessage' => 'Enter the email address your AI Credits account is registered to.',
                ], 400);
            }

            $this->tokenResolver->requestClaimCode($email);

            return new JsonResponse([
                'status' => true,
                'userMessage' => 'If that address has an AI Credits account, a claim code is on its way to it.',
            ]);
        } catch (CreditsApiException $exception) {
            return $this->errorResponse($exception);
        } catch (\Throwable $exception) {
            return $this->unexpectedErrorResponse($exception);
        }
    }

    public function refreshTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyUnlessCreditsManager();
        if ($denied !== null) {
            return $denied;
        }

        if (!$this->creditModeResolver->isActive()) {
            return new JsonResponse([
                'status' => false,
                'error_code' => 'not_active',
                'userMessage' => 'Activate T3Planet Credits before refreshing the token.',
            ], 400);
        }

        try {
            $token = $this->tokenResolver->refreshBearerToken();
            $this->fetchBalanceOrResync();

            return new JsonResponse([
                'status' => true,
                'creditsBearerToken' => $token,
            ]);
        } catch (CreditsApiException $exception) {
            if ($this->isRecoverableTokenAuthFailure($exception)) {
                try {
                    $token = $this->tokenResolver->resyncFromServer();
                    $this->fetchBalanceOrResync();

                    return new JsonResponse([
                        'status' => true,
                        'creditsBearerToken' => $token,
                        'resynced' => true,
                    ]);
                } catch (CreditsApiException $resyncException) {
                    return $this->errorResponse($resyncException);
                } catch (\Throwable $resyncThrowable) {
                    return $this->unexpectedErrorResponse($resyncThrowable);
                }
            }

            return $this->errorResponse($exception);
        } catch (\Throwable $exception) {
            return $this->unexpectedErrorResponse($exception);
        }
    }

    public function dashboardAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyUnlessCreditsManager();
        if ($denied !== null) {
            return $denied;
        }

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
        $denied = $this->denyUnlessCreditsManager();
        if ($denied !== null) {
            return $denied;
        }

        return $this->wrapApiCall(fn(): array => $this->balanceService->fetch());
    }

    public function currentPlanAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyUnlessCreditsManager();
        if ($denied !== null) {
            return $denied;
        }

        return $this->wrapApiCall(fn(): array => $this->currentPlanService->fetch());
    }

    public function featuresAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyUnlessCreditsManager();
        if ($denied !== null) {
            return $denied;
        }

        return $this->wrapApiCall(fn(): array => $this->featureCatalogService->fetch());
    }

    public function productsAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyUnlessCreditsManager();
        if ($denied !== null) {
            return $denied;
        }

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
        $denied = $this->denyUnlessCreditsManager();
        if ($denied !== null) {
            return $denied;
        }

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
                'estimated_credit_units' => $estimate->estimatedCreditUnits,
                'estimated_credits' => $estimate->estimatedCredits,
                'canonical_feature_key' => $estimate->canonicalFeatureKey,
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
     * @return array<string, mixed>
     */
    private function fetchBalanceOrResync(): array
    {
        try {
            return $this->balanceService->fetch();
        } catch (CreditsApiException $exception) {
            if (!$this->isRecoverableTokenAuthFailure($exception)) {
                throw $exception;
            }

            $this->tokenResolver->resyncFromServer();

            return $this->balanceService->fetch();
        }
    }

    private function isRecoverableTokenAuthFailure(CreditsApiException $exception): bool
    {
        return in_array($exception->errorCode, [
            CreditsApiErrorCodes::TOKEN_INVALID,
            CreditsApiErrorCodes::TOKEN_MISSING,
            // Stored bearer cannot bind: the server IP is owned by another account; re-issue adopts it.
            CreditsApiErrorCodes::TOKEN_IP_CONFLICT,
        ], true);
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
            CreditsApiErrorCodes::INTERNAL_ERROR,
            [],
            $exception,
        );

        return new JsonResponse(
            $this->errorMessages->buildErrorPayload($apiException),
            500,
        );
    }

    private function denyUnlessCreditsManager(): ?JsonResponse
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            return new JsonResponse([
                'status' => false,
                'error_code' => 'access_denied',
                'userMessage' => 'You do not have permission to manage T3Planet Credits.',
            ], 403);
        }
        if ($this->recordAccessGate->canModifyTable($user, self::PROVIDER_TABLE)) {
            return null;
        }

        return new JsonResponse([
            'status' => false,
            'error_code' => 'access_denied',
            'userMessage' => 'You do not have permission to manage T3Planet Credits.',
        ], 403);
    }

    private function bearerTokenForResponse(bool $includePlain): string
    {
        $plain = $this->runtimeSettings->getTokenPlain() ?? '';
        if ($plain === '') {
            return '';
        }

        return $includePlain ? $plain : $this->credentialCipher->mask($plain);
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

    /**
     * @return array<string, mixed>
     */
    private function serializePricing(CreditsPricing $pricing): array
    {
        return $pricing->toArray();
    }
}
