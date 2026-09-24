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

use NITSAN\NsT3AF\Access\Dto\AgentToolPolicy;
use NITSAN\NsT3AF\Access\Dto\LimitsConfig;
use NITSAN\NsT3AF\Agent\Service\AgentToolArgumentValidator;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Argument validation and the per-group tool policy.
 *
 * @internal
 */
final class AgentToolGuardsTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'uid' => ['type' => 'number', 'description' => ''],
            'published' => ['type' => 'boolean', 'description' => ''],
            'data' => ['type' => 'string', 'description' => ''],
            'fieldKeys' => ['type' => 'array', 'items' => ['type' => 'string']],
            'note' => ['type' => 'string', 'description' => ''],
        ],
        'required' => ['uid'],
    ];

    #[Test]
    public function commonModelMistakesAreCoerced(): void
    {
        $result = (new AgentToolArgumentValidator())->validate(self::SCHEMA, [
            'uid' => '12',
            'published' => 'false',
            'data' => ['header' => 'Willkommen'],
            'fieldKeys' => '["metaTitle"]',
            'note' => null,
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame(
            ['uid' => 12, 'published' => false, 'data' => '{"header":"Willkommen"}', 'fieldKeys' => ['metaTitle']],
            $result['arguments'],
        );
    }

    #[Test]
    public function missingRequiredArgumentIsReported(): void
    {
        $result = (new AgentToolArgumentValidator())->validate(self::SCHEMA, ['note' => 'x']);

        self::assertNotSame([], $result['errors']);
        self::assertStringContainsString('uid', implode(' ', $result['errors']));
    }

    #[Test]
    public function wrongTypeIsReportedWithThePath(): void
    {
        $result = (new AgentToolArgumentValidator())->validate(self::SCHEMA, ['uid' => 'about-us']);

        self::assertStringStartsWith('uid:', $result['errors'][0] ?? '');
    }

    #[Test]
    public function toolWithoutParametersAcceptsAnything(): void
    {
        $result = (new AgentToolArgumentValidator())->validate(['type' => 'object', 'properties' => []], ['x' => 1]);

        self::assertSame([], $result['errors']);
    }

    #[Test]
    public function readOnlyPolicyBlocksEverythingButReads(): void
    {
        $policy = new AgentToolPolicy(readOnly: true);

        self::assertFalse($policy->blocks('pages_get', ToolSeverity::Read));
        self::assertTrue($policy->blocks('write_table', ToolSeverity::Write));
        self::assertTrue($policy->blocks('content_delete', ToolSeverity::Destructive));
        self::assertTrue($policy->blocks('undeclared_tool', null));
    }

    #[Test]
    public function blockedToolsSupportWildcards(): void
    {
        $policy = new AgentToolPolicy(blockedTools: AgentToolPolicy::parseToolList('t3cs_*, File_Delete; bad name!'));

        self::assertSame(['t3cs_*', 'file_delete', 'bad'], $policy->blockedTools);
        self::assertTrue($policy->blocks('t3cs_sync_datasource', ToolSeverity::Write));
        self::assertTrue($policy->blocks('file_delete', ToolSeverity::Destructive));
        self::assertFalse($policy->blocks('file_list', ToolSeverity::Read));
        self::assertFalse(AgentToolPolicy::unrestricted()->blocks('content_delete', ToolSeverity::Destructive));
    }

    #[Test]
    public function limitsConfigKeepsTheAgentToolSettings(): void
    {
        $limits = LimitsConfig::fromArray(['agentReadOnly' => '1', 'blockedAgentTools' => ['T3CS_*', 'file_delete']]);

        self::assertTrue($limits->agentReadOnly);
        self::assertSame(['t3cs_*', 'file_delete'], $limits->blockedAgentTools);
        self::assertSame(
            ['agentReadOnly' => true, 'blockedAgentTools' => ['t3cs_*', 'file_delete']],
            array_intersect_key($limits->toArray(), ['agentReadOnly' => 1, 'blockedAgentTools' => 1]),
        );
    }
}
