<?php

declare(strict_types=1);

/*
 * This file is part of the "AI Foundation for TYPO3" (ns_t3af) extension.
 *
 * (c) T3Planet / NITSAN Technologies <support@t3planet.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Service\AgentMissingArgumentHint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentMissingArgumentHintTest extends TestCase
{
    #[Test]
    public function appendsRequiredParameterAndExample(): void
    {
        $message = AgentMissingArgumentHint::append(
            't3cs_training_summary',
            new TrainingSummaryHintFixture(),
            'Missing required argument: rootPageId',
        );

        self::assertSame(
            'Missing required argument: rootPageId. Required: rootPageId. Example: /t3cs_training_summary {"rootPageId":1}',
            $message,
        );
    }

    #[Test]
    public function leavesOtherErrorsUnchanged(): void
    {
        self::assertSame(
            'Data source not found.',
            AgentMissingArgumentHint::append('t3cs_training_summary', new TrainingSummaryHintFixture(), 'Data source not found.'),
        );
    }
}

final class TrainingSummaryHintFixture
{
    public function execute(int $rootPageId, int $dataId = 0): string
    {
        return '';
    }
}
