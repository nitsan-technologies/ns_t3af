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

namespace NITSAN\NsT3AF\Mcp\OAuth;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Resolves public URIs for OAuth authorize page static assets.
 */
class OAuthAuthorizeAssetProvider
{
    public const DEFAULT_BACKGROUND = 'EXT:ns_t3af/Resources/Public/Images/oauth-login-background.jpg';
    public const STYLESHEET = 'EXT:ns_t3af/Resources/Public/Css/oauth-authorize.css';
    public const LOGO = 'EXT:ns_t3af/Resources/Public/Icons/HeaderLogo.svg';
    public const DEFAULT_HIGHLIGHT = '#ff8700';

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function stylesheetUri(): string
    {
        return $this->publicUriForExtensionResource(self::STYLESHEET);
    }

    public function logoUri(): string
    {
        return $this->publicUriForExtensionResource(self::LOGO);
    }

    public function backgroundUri(): string
    {
        $configuredPath = trim((string) ($this->getBackendLoginConfig()['loginBackgroundImage'] ?? ''));
        if ($configuredPath !== '') {
            $configuredUri = $this->publicUriForFileReference($configuredPath);
            if ($configuredUri !== '') {
                return $configuredUri;
            }
        }

        return $this->publicUriForExtensionResource(self::DEFAULT_BACKGROUND);
    }

    public function highlightColor(): string
    {
        $configured = trim((string) ($this->getBackendLoginConfig()['loginHighlightColor'] ?? ''));

        return $configured !== '' ? $configured : self::DEFAULT_HIGHLIGHT;
    }

    public function footnote(): string
    {
        return trim(strip_tags((string) ($this->getBackendLoginConfig()['loginFootnote'] ?? '')));
    }

    public function publicUriForExtensionResource(string $extPath): string
    {
        return $this->publicUriForFileReference($extPath);
    }

    public function publicUriForFileReference(string $filename): string
    {
        if (preg_match('/^(https?:)?\/\//', $filename) === 1) {
            return $filename;
        }

        $absoluteFilename = GeneralUtility::getFileAbsFileName(ltrim($filename, '/'));
        if ($absoluteFilename === '' || !is_file($absoluteFilename)) {
            return '';
        }

        return PathUtility::getAbsoluteWebPath($absoluteFilename);
    }

    /** @return array<string, mixed> */
    private function getBackendLoginConfig(): array
    {
        return (array) $this->extensionConfiguration->get('backend');
    }
}
