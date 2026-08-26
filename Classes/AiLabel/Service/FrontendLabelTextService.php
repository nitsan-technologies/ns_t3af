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

namespace NITSAN\NsT3AF\AiLabel\Service;

use NITSAN\NsT3AF\AiLabel\Domain\Involvement;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Visitor-facing AI label copy in the current (or default site) language.
 */
final class FrontendLabelTextService
{
    private const XLF = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang.xlf:ailabel.frontend.';

    public function __construct(
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly Context $context,
        private readonly SiteFinder $siteFinder,
    ) {}

    public function forInvolvement(Involvement $involvement): string
    {
        $labelKey = self::XLF . $involvement->value;
        $languageService = $this->languageService();
        $translated = trim($languageService->sL($labelKey));
        if ($translated !== '' && $translated !== $labelKey) {
            return $translated;
        }

        return $this->fallback($involvement);
    }

    private function languageService(): LanguageService
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $language = $request->getAttribute('language');
            if ($language instanceof SiteLanguage) {
                return $this->languageServiceFactory->createFromSiteLanguage($language);
            }

            if (ApplicationType::fromRequest($request)->isBackend()) {
                $defaultLanguage = $this->resolveDefaultSiteLanguage();
                if ($defaultLanguage instanceof SiteLanguage) {
                    return $this->languageServiceFactory->createFromSiteLanguage($defaultLanguage);
                }
            }

            $site = $request->getAttribute('site');
            if ($site instanceof SiteInterface) {
                try {
                    $languageId = (int) $this->context->getPropertyFromAspect('language', 'id', 0);

                    return $this->languageServiceFactory->createFromSiteLanguage(
                        $site->getLanguageById($languageId),
                    );
                } catch (\Throwable) {
                    return $this->languageServiceFactory->createFromSiteLanguage($site->getDefaultLanguage());
                }
            }
        }

        $defaultLanguage = $this->resolveDefaultSiteLanguage();
        if ($defaultLanguage instanceof SiteLanguage) {
            return $this->languageServiceFactory->createFromSiteLanguage($defaultLanguage);
        }

        return $this->languageServiceFactory->create('default');
    }

    private function resolveDefaultSiteLanguage(): ?SiteLanguage
    {
        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                return $site->getDefaultLanguage();
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function fallback(Involvement $involvement): string
    {
        return match ($involvement) {
            Involvement::AiModified => 'AI modified',
            Involvement::AiGenerated => 'AI generated',
            Involvement::OriginUnknown => 'AI involvement unknown',
            Involvement::Suggestion => 'AI suggestion',
            default => 'AI',
        };
    }
}
