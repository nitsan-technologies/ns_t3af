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

use NITSAN\NsT3AF\Agent\Service\AgentToolEditorLabelService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentToolEditorLabelServiceTest extends TestCase
{
    use AgentTranslatorTrait;

    protected function tearDown(): void
    {
        $this->releaseAgentTranslator();
    }

    #[Test]
    public function resolveHumanizesPrefixedToolNames(): void
    {
        $service = new AgentToolEditorLabelService($this->createAgentTranslator());

        // Tools with agent.tool.label.<name> use it; unknown tools are humanized.
        self::assertSame(
            'Find images without alt text',
            $service->resolveByName('t3aa_list_files_missing_alt_text'),
        );
        self::assertSame(
            'Write all SEO texts',
            $service->resolveByName('t3ai_generate_all_seo', 'Generate the SEO title for a page.'),
        );
        self::assertSame(
            'Rebuild faq index',
            $service->resolveByName('t3cs_rebuild_faq_index'),
        );
    }

    #[Test]
    public function resolveUsesEditorFriendlyDescription(): void
    {
        $service = new AgentToolEditorLabelService($this->createAgentTranslator());

        self::assertSame(
            'Generate the SEO title for a page',
            $service->resolveByName('t3cs_rebuild_faq_index', 'Generate the SEO title for a page.'),
        );
    }

    #[Test]
    public function resolveSkipsTechnicalDescriptions(): void
    {
        $service = new AgentToolEditorLabelService($this->createAgentTranslator());

        self::assertSame(
            'Rebuild faq index',
            $service->resolveByName(
                't3cs_rebuild_faq_index',
                'Find image files in sys_file_metadata where alt text is empty.',
            ),
        );
    }
}
