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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Exception\InsufficientCreditsException;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Frontend-safe handling of "T3Planet credits exhausted" failures.
 *
 * Visitors cannot buy credits, so they get a neutral "temporarily unavailable" text; the
 * purchase wording stays in the backend (see {@see CreditsApiErrorMessageResolver}) and the
 * site admin is alerted through the API alert e-mail / sys_log.
 *
 * @api
 */
final class CreditsVisitorMessageResolver
{
    private const VISITOR_LABEL = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_credits.xlf:credits.visitor.unavailable';
    private const ADMIN_LABEL = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_credits.xlf:credits.api.error.insufficient_credits';
    private const ADMIN_PURCHASE_HINT = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_credits.xlf:credits.admin.purchase_hint';
    private const ADMIN_PURCHASE_FALLBACK = 'Administrators can buy more credits in the TYPO3 backend under AI Foundation > AI Providers > AI Credit Bundles.';
    private const ADMIN_FALLBACK = 'Your T3Planet AI credits have been exhausted. Please purchase additional credits to continue using AI features.';
    private const VISITOR_FALLBACK = 'AI features are temporarily unavailable. Please try again later.';

    /** Codes meaning "the site's credit plan cannot serve this request right now". */
    private const EXHAUSTED_CODES = [
        CreditsApiErrorCodes::INSUFFICIENT_CREDITS,
        CreditsApiErrorCodes::PLAN_EXPIRED,
        CreditsApiErrorCodes::DAILY_CAP_EXCEEDED,
    ];

    public static function isCreditsExhausted(\Throwable $throwable): bool
    {
        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof InsufficientCreditsException) {
                return true;
            }
            if ($current instanceof CreditsApiException && in_array($current->errorCode, self::EXHAUSTED_CODES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Neutral, translated message for site visitors (no codes, URLs or purchase hints).
     */
    public static function visitorMessage(?string $languageKey = null): string
    {
        try {
            $label = '';
            $languageService = $GLOBALS['LANG'] ?? null;
            if ($languageService === null) {
                $languageKey = ($languageKey !== null && $languageKey !== '') ? $languageKey : self::frontendLanguageKey();
                $languageService = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create($languageKey);
            }
            if ($languageService !== null) {
                $label = (string) $languageService->sL(self::VISITOR_LABEL);
            }
        } catch (\Throwable) {
            $label = '';
        }

        return $label !== '' && !str_starts_with($label, 'LLL:') ? $label : self::VISITOR_FALLBACK;
    }

    /**
     * English, admin-facing text for sys_log and the alert e-mail. Points to the backend module
     * where credits are bought instead of a public URL.
     */
    public static function adminMessage(\Throwable $throwable): string
    {
        try {
            $languageService = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
            $label = (string) $languageService->sL(self::ADMIN_LABEL);
            $hint = (string) $languageService->sL(self::ADMIN_PURCHASE_HINT);
        } catch (\Throwable) {
            $label = '';
            $hint = '';
        }
        $label = $label !== '' && !str_starts_with($label, 'LLL:') ? $label : self::ADMIN_FALLBACK;
        $hint = $hint !== '' && !str_starts_with($hint, 'LLL:') ? $hint : self::ADMIN_PURCHASE_FALLBACK;

        return $label . ' ' . $hint;
    }

    private static function frontendLanguageKey(): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $language = $request?->getAttribute('language');
        if ($language instanceof SiteLanguage) {
            return $language->getLocale()->getLanguageCode();
        }

        return 'default';
    }
}
