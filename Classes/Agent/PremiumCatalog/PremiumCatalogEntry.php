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

namespace NITSAN\NsT3AF\Agent\PremiumCatalog;

/**
 * One premium T3Planet extension the Agent knows about but that may not be installed on this
 * site — static marketing metadata, independent of whether the extension's own PHP classes exist.
 *
 * @see PremiumCatalogProvider
 */
final readonly class PremiumCatalogEntry
{
    /**
     * @param list<string> $searchTerms lowercase keywords (EN + DE) that identify this capability in free text
     */
    public function __construct(
        public string $extensionKey,
        public string $label,
        public string $tagline,
        public array $searchTerms,
        public string $infoUrlEn,
        public string $infoUrlDe,
    ) {}

    /** The product page in the editor's backend language ('de' or else English). */
    public function infoUrl(string $backendIsoCode): string
    {
        return $backendIsoCode === 'de' ? $this->infoUrlDe : $this->infoUrlEn;
    }
}
