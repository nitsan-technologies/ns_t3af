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

use NITSAN\NsT3AF\Agent\Service\AgentMessageParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentMessageParserTest extends TestCase
{
    private AgentMessageParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AgentMessageParser();
    }

    #[Test]
    public function extractSlashCommandRequiresLeadingSlash(): void
    {
        self::assertSame(
            ['name' => 'pages_get', 'arguments' => ['uid' => 49]],
            $this->parser->extractSlashCommand('/pages_get 49'),
        );
        self::assertSame(
            ['name' => '', 'arguments' => []],
            $this->parser->extractSlashCommand('please run /pages_get 49'),
        );
    }

    #[Test]
    public function extractSlashCommandIgnoresSlashesInFilePaths(): void
    {
        self::assertSame(
            ['name' => '', 'arguments' => []],
            $this->parser->extractSlashCommand('@file:1:user_upload/image.png explain this'),
        );
    }

    #[Test]
    public function stripComposerTokensRemovesAttachments(): void
    {
        self::assertSame(
            'explain this',
            $this->parser->stripComposerTokens('@file:1:user_upload/image.png explain this'),
        );
    }
}
