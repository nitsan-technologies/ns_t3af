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

namespace NITSAN\NsT3AF\AiLabel\ViewHelpers;

use NITSAN\NsT3AF\AiLabel\Dto\FrontendLabelState;
use NITSAN\NsT3AF\AiLabel\Service\FrontendLabelRenderer;
use NITSAN\NsT3AF\AiLabel\Service\FrontendLabelStateFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Server-side AI label badge (Art. 50(5): no JS injection).
 *
 * Pass either a record row or a FAL file. File labels use sys_file_metadata.
 */
final class LabelViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('record', 'array', 'Record row', false);
        $this->registerArgument('file', 'mixed', 'FAL File, FileReference, or ProcessedFile', false);
        $this->registerArgument('table', 'string', 'Table name when using record', false, 'tt_content');
    }

    public function render(): string
    {
        $state = $this->resolveState();
        if ($state === null || !$state->showLabel) {
            return '';
        }

        return GeneralUtility::makeInstance(FrontendLabelRenderer::class)
            ->renderFromState($state);
    }

    private function resolveState(): ?FrontendLabelState
    {
        $factory = GeneralUtility::makeInstance(FrontendLabelStateFactory::class);
        $file = $this->arguments['file'] ?? null;
        if ($file !== null) {
            return $factory->fromFile($file);
        }

        $record = $this->arguments['record'] ?? null;
        if (!is_array($record)) {
            return null;
        }

        return $factory->fromRecord((string) $this->arguments['table'], $record);
    }
}
