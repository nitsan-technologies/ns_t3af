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

use NITSAN\NsT3AF\Domain\Model\BrandContextProfile;

/**
 * Builds the full `{brand_context}` block for system-prompt injection.
 *
 * @internal
 */
final class BrandContextAssembler
{
    public function __construct(
        private readonly BrandContextPlaceholderService $placeholders,
    ) {}

    public function assemble(BrandContextProfile $profile): string
    {
        $map = $this->placeholders->buildMap($profile);
        $lines = ['=== BRAND CONTEXT ==='];

        if ($map['{brand_name}'] !== '') {
            $lines[] = 'Brand: ' . $map['{brand_name}'];
        }
        if ($profile->industry !== '') {
            $lines[] = 'Industry: ' . $profile->industry;
        }
        if ($profile->tagline !== '') {
            $lines[] = 'Tagline: ' . $profile->tagline;
        }
        if ($profile->description !== '') {
            $lines[] = 'Description: ' . $profile->description;
        }
        if ($map['{brand_voice}'] !== '') {
            $lines[] = 'Voice: ' . $map['{brand_voice}'];
        }
        if ($map['{target_audience}'] !== '') {
            $lines[] = 'Audience: ' . $map['{target_audience}'];
        }
        if ($map['{content_rules}'] !== '') {
            $lines[] = 'Content rules:' . "\n" . $map['{content_rules}'];
        }
        if ($map['{keywords}'] !== '') {
            $lines[] = 'Keywords: ' . $map['{keywords}'];
        }
        if ($map['{forbidden_words}'] !== '') {
            $lines[] = 'Forbidden words: ' . $map['{forbidden_words}'];
        }
        if ($map['{competitors}'] !== '') {
            $lines[] = 'Competitors: ' . $map['{competitors}'];
        }
        if ($map['{compliance_notes}'] !== '') {
            $lines[] = 'Compliance: ' . $map['{compliance_notes}'];
        }
        if ($profile->sampleContent !== '') {
            $lines[] = 'Sample content: ' . $profile->sampleContent;
        }
        if ($profile->documentExtract !== '') {
            $lines[] = 'Document context: ' . $profile->documentExtract;
        }

        if (count($lines) === 1) {
            return '';
        }

        return implode("\n", $lines);
    }
}
