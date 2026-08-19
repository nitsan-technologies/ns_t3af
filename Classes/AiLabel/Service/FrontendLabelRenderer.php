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
use NITSAN\NsT3AF\AiLabel\Domain\ReasonCode;
use NITSAN\NsT3AF\AiLabel\Dto\FrontendLabelState;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Visitor-facing EU icon + text badge markup.
 */
final class FrontendLabelRenderer
{
    public function __construct(
        private readonly AiLabelRecordEvaluator $evaluator,
        private readonly RivalsRendererGuard $rivalsRendererGuard,
        private readonly AiLabelSettingsService $settingsService,
        private readonly FrontendLabelTextService $labelTextService,
    ) {}

    /**
     * @param array<string, mixed> $record
     */
    public function renderMediaBadge(
        Involvement $involvement,
        LabellingMode $mode,
        bool $confirmed,
        int $creationDate,
        string $table = 'tt_content',
        int $uid = 0,
        array $record = [],
    ): string {
        if ($record !== [] && $this->rivalsRendererGuard->shouldStandDown($table, $record)) {
            return '';
        }

        $recordForDecision = $record;
        $recordForDecision['tx_nst3af_ailabel_involvement'] = $involvement->value;
        $recordForDecision['tx_nst3af_ailabel_labelling_mode'] = $mode->value;
        $recordForDecision['tx_nst3af_ailabel_confirmed_at'] = $confirmed
            ? (int) ($record['tx_nst3af_ailabel_confirmed_at'] ?? 1)
            : 0;
        $recordForDecision['crdate'] = $creationDate;
        $decision = $this->evaluator->decide($table, $recordForDecision);
        if (!$decision->showLabel) {
            return '';
        }

        return $this->buildBadge($involvement, $decision->reasonCode);
    }

    public function renderFromState(FrontendLabelState $state): string
    {
        if (!$state->showLabel) {
            return '';
        }

        if ($state->record !== [] && $this->rivalsRendererGuard->shouldStandDown($state->table, $state->record)) {
            return '';
        }

        return $this->buildBadge($state->involvement, $state->reasonCode);
    }

    /**
     * Backend drawer preview: badge markup only (no rule engine / rivals guard).
     */
    public function renderBadgeMarkup(Involvement $involvement, bool $onDarkBackground = false): string
    {
        if (!in_array($involvement, [Involvement::AiGenerated, Involvement::AiModified, Involvement::OriginUnknown, Involvement::Suggestion], true)) {
            return '';
        }

        return $this->buildBadge($involvement, onDarkBackground: $onDarkBackground);
    }

    private function buildBadge(
        Involvement $involvement,
        ?ReasonCode $reasonCode = null,
        bool $onDarkBackground = false,
    ): string {
        $moduleSettings = $this->settingsService->all();
        $sizeClass = match ((string) ($moduleSettings['labelSize'] ?? 'medium')) {
            'small' => 'nst3af-ailabel--size-small',
            'large' => 'nst3af-ailabel--size-large',
            default => 'nst3af-ailabel--size-medium',
        };
        $iconOnly = ((string) ($moduleSettings['labelWording'] ?? 'show_site_language')) === 'icon_only';
        $iconUrl = $this->publicIconUrl($this->resolveIconPath($involvement, $onDarkBackground));
        $detail = '';
        if (((string) ($moduleSettings['secondInfoLayer'] ?? 'off')) === 'on' && $reasonCode !== null) {
            $detail = $this->labelTextService->forInvolvement($involvement) . ' (' . $reasonCode->value . ')';
        }

        return $this->buildMarkup(
            $iconUrl,
            $this->labelTextService->forInvolvement($involvement),
            $sizeClass,
            $iconOnly,
            $detail,
        );
    }

    private function publicIconUrl(string $iconFile): string
    {
        return $this->encodeWebPath(PathUtility::getPublicResourceWebPath($iconFile));
    }

    private function encodeWebPath(string $path): string
    {
        return implode('/', array_map(
            static fn(string $segment): string => $segment === '' ? '' : rawurlencode($segment),
            explode('/', $path),
        ));
    }

    private function buildMarkup(
        string $iconUrl,
        string $labelText,
        string $sizeClass,
        bool $iconOnly,
        string $detail = '',
    ): string {
        $iconUrl = htmlspecialchars($iconUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $alt = htmlspecialchars($labelText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $classes = 'nst3af-ailabel ' . $sizeClass;
        if ($iconOnly) {
            $classes .= ' nst3af-ailabel--icon-only';
        }

        $html = '<aside class="' . $classes . '" role="note" aria-label="' . $alt . '">'
            . '<img src="' . $iconUrl . '" alt="' . $alt . '" class="nst3af-ailabel__icon" loading="lazy" decoding="async" />';

        if (!$iconOnly) {
            $html .= '<span class="nst3af-ailabel__text">' . $alt . '</span>';
        }

        $html .= '</aside>';
        if ($detail === '') {
            return $html;
        }

        $detailEscaped = htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<details class="nst3af-ailabel-details"><summary>' . $html
            . '</summary><p class="nst3af-ailabel-details__body">' . $detailEscaped . '</p></details>';
    }

    private function resolveIconPath(Involvement $involvement, bool $white = false): string
    {
        $tone = $white ? 'white' : 'black';
        $name = match ($involvement) {
            Involvement::AiModified => 'LABEL_AI MODIFIED_' . $tone . '.svg',
            Involvement::AiGenerated => 'LABEL_AI GENERATED_' . $tone . '.svg',
            default => 'LABEL_AI_' . $tone . '.svg',
        };

        return 'EXT:ns_t3af/Resources/Public/Icons/EuAiLabel/' . $name;
    }
}
