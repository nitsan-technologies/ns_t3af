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

namespace NITSAN\NsT3AF\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Service\Backend\McpPromptTemplateRepository;

/** DB prompt templates plus code-registered {@see \Mcp\Capability\Attribute\McpPrompt} handlers. */
readonly class McpRegisteredPromptCounter
{
    /**
     * @param iterable<object> $codePrompts
     */
    public function __construct(
        private McpPromptTemplateRepository $promptTemplateRepository,
        private iterable $codePrompts,
    ) {}

    public function count(): int
    {
        $code = 0;
        foreach ($this->codePrompts as $_prompt) {
            ++$code;
        }

        return count($this->promptTemplateRepository->findVisible()) + $code;
    }
}
