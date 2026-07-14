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

/**
 * Result of resolving which site root page stores AI Providers / Features for the current backend context.
 *
 * @internal
 */
final readonly class SiteStorageResolution
{
    private function __construct(
        public bool $resolved,
        public int $storagePid,
        public int $pageId,
        public string $siteIdentifier,
        public string $siteTitle,
        public string $reason,
    ) {}

    public static function resolved(
        int $storagePid,
        int $pageId,
        string $siteIdentifier,
        string $siteTitle,
    ): self {
        return new self(
            resolved: true,
            storagePid: $storagePid,
            pageId: $pageId,
            siteIdentifier: $siteIdentifier,
            siteTitle: $siteTitle,
            reason: '',
        );
    }

    public static function pageRequired(): self
    {
        return new self(
            resolved: false,
            storagePid: 0,
            pageId: 0,
            siteIdentifier: '',
            siteTitle: '',
            reason: 'page_required',
        );
    }

    public static function pageNotInSite(int $pageId): self
    {
        return new self(
            resolved: false,
            storagePid: 0,
            pageId: $pageId,
            siteIdentifier: '',
            siteTitle: '',
            reason: 'page_not_in_site',
        );
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }
}
