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
use NITSAN\NsT3AF\AiLabel\Domain\LabellingMode;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * R7 frontend renderer with external EU icon reference.
 */
final class FrontendLabelRenderer
{
    public function __construct(
        private readonly MediaRuleEngine $mediaRuleEngine,
        private readonly RivalsRendererGuard $rivalsRendererGuard,
    ) {}

    public function renderMediaBadge(
        Involvement $involvement,
        LabellingMode $mode,
        bool $confirmed,
        int $creationDate,
        string $table = 'tt_content',
        int $uid = 0,
        /** @var array<string, mixed> $record */
        array $record = [],
    ): string {
        if ($record !== [] && $this->rivalsRendererGuard->shouldStandDown($table, $record)) {
            return '';
        }

        $decision = $this->mediaRuleEngine->decide($involvement, $mode, $confirmed, $creationDate);
        if (!$decision->showLabel) {
            return '';
        }

        $iconFile = $this->resolveIconPath($involvement);
        $labelText = $this->labelText($involvement);
        $iconUrl = htmlspecialchars(PathUtility::getAbsoluteWebPath($iconFile) . basename($iconFile), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $alt = htmlspecialchars($labelText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<aside class="nst3af-ailabel" role="note" aria-label="' . $alt . '" style="display:inline-flex;align-items:center;gap:6px;margin-top:8px">'
            . '<img src="' . $iconUrl . '" width="32" height="32" alt="' . $alt . '" class="nst3af-ailabel__icon" />'
            . '<span class="nst3af-ailabel__text" style="background-color:#000;color:#fff;padding:2px 6px;border-radius:4px;font-size:12px;line-height:1.4">'
            . $alt . '</span></aside>';
    }

    private function resolveIconPath(Involvement $involvement): string
    {
        $name = match ($involvement) {
            Involvement::AiModified => 'LABEL_AI MODIFIED_black.svg',
            Involvement::AiGenerated => 'LABEL_AI GENERATED_black.svg',
            default => 'LABEL_AI_black.svg',
        };

        return 'EXT:ns_t3af/Resources/Public/Icons/EuAiLabel/' . $name;
    }

    private function labelText(Involvement $involvement): string
    {
        return match ($involvement) {
            Involvement::AiModified => 'AI modified',
            Involvement::AiGenerated => 'AI generated',
            default => 'AI',
        };
    }
}
