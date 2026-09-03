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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Service\AgentLowRiskFieldMatrix;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlanField;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentLowRiskFieldMatrixTest extends TestCase
{
    private AgentLowRiskFieldMatrix $matrix;

    protected function setUp(): void
    {
        $this->matrix = new AgentLowRiskFieldMatrix();
    }

    #[Test]
    public function recognizesSafePageSeoFields(): void
    {
        self::assertTrue($this->matrix->isSafeField('pages', 'description'));
        self::assertFalse($this->matrix->isSafeField('pages', 'slug'));
    }

    #[Test]
    public function filtersSafeFieldKeysFromPlan(): void
    {
        $plan = new ToolPlan('update', 'write_table', [
            new ToolPlanField(
                ToolPlanField::buildKey('pages', 1, 'description'),
                'pages',
                1,
                'description',
                'Old',
                'New',
            ),
            new ToolPlanField(
                ToolPlanField::buildKey('pages', 1, 'nav_title'),
                'pages',
                1,
                'nav_title',
                'Old nav',
                'New nav',
            ),
        ]);

        self::assertSame(
            ['pages:1:description'],
            $this->matrix->filterSafeFieldKeys($plan, ['pages:1:description', 'pages:1:nav_title']),
        );
        self::assertSame(1, $this->matrix->countSafeFields($plan));
    }
}
