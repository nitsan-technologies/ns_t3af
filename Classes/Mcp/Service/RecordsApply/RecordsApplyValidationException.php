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

namespace NITSAN\NsT3AF\Mcp\Service\RecordsApply;

/**
 * The request was refused before anything was written.
 *
 * Carries one entry per problem. Entries name tables, record ids and fields, never field values,
 * because the message ends up in logs and in the client conversation.
 */
final class RecordsApplyValidationException extends \RuntimeException
{
    /**
     * @param list<array<string, mixed>> $problems
     */
    public function __construct(private readonly array $problems, private readonly int $moreProblems = 0)
    {
        parent::__construct(
            sprintf('records_apply request rejected: %d problem(s), nothing was written.', count($problems) + $moreProblems),
            1790500001,
        );
    }

    /** @return list<array<string, mixed>> */
    public function getProblems(): array
    {
        return $this->problems;
    }

    /** Problems left out of the list to keep the answer short. */
    public function getMoreProblems(): int
    {
        return $this->moreProblems;
    }
}
