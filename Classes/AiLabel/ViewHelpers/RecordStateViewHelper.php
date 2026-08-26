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
use NITSAN\NsT3AF\AiLabel\Service\FrontendLabelStateFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Assigns or returns {@see FrontendLabelState} for a database record row.
 */
final class RecordStateViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('record', 'array', 'Record row', true);
        $this->registerArgument('table', 'string', 'Table name', false, 'tt_content');
        $this->registerArgument('as', 'string', 'Fluid variable name; empty returns the object', false, '');
    }

    public function render(): mixed
    {
        /** @var array<string, mixed> $record */
        $record = $this->arguments['record'] ?? [];
        $table = (string) $this->arguments['table'];
        $state = GeneralUtility::makeInstance(FrontendLabelStateFactory::class)->fromRecord($table, $record);

        return $this->assignOrReturn($state);
    }

    private function assignOrReturn(FrontendLabelState $state): mixed
    {
        $as = (string) ($this->arguments['as'] ?? '');
        if ($as === '') {
            return $state;
        }

        $variables = $this->renderingContext?->getVariableProvider();
        if ($variables === null) {
            return $state;
        }
        if ($variables->exists($as)) {
            $variables->remove($as);
        }
        $variables->add($as, $state);

        return '';
    }
}
