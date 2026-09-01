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

use NITSAN\NsT3AF\Agent\Service\AgentNlIntentResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentNlIntentResolverTest extends TestCase
{
    private AgentNlIntentResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AgentNlIntentResolver();
    }

    #[Test]
    public function optimizeSeoRoutesToGenerateFlow(): void
    {
        self::assertSame('generate_seo_metadata', $this->resolver->resolveStarterAction('Optimize SEO'));
        self::assertFalse($this->resolver->isSeoMetadataReadQuery('Optimize SEO'));
    }

    #[Test]
    public function seoFieldQuestionIsReadQuery(): void
    {
        self::assertTrue($this->resolver->isSeoMetadataReadQuery('What SEO fields does this page have'));
        self::assertSame('', $this->resolver->resolveStarterAction('What SEO fields does this page have'));
    }

    #[Test]
    public function invoiceQuestionExtractsNeedle(): void
    {
        self::assertTrue($this->resolver->looksLikePageContentQuery('Give me the Invoice Details'));
        self::assertSame(['invoice'], $this->resolver->extractContentSearchNeedles('Give me the Invoice Details'));
    }

    #[Test]
    public function shortContentTitlePhraseIsPageContentQuery(): void
    {
        self::assertTrue($this->resolver->looksLikePageContentQuery('Claude models'));
        self::assertContains('claude', $this->resolver->extractContentSearchNeedles('Claude models'));
        self::assertContains('claude models', $this->resolver->extractContentSearchNeedles('Claude models'));
    }

    #[Test]
    public function compoundStepsFollowMessageOrder(): void
    {
        $message = 'Get the Page 3. Translate it. Then optimise the SEO.';
        self::assertSame(
            [
                AgentNlIntentResolver::STEP_PAGE_INSPECT,
                AgentNlIntentResolver::STEP_TRANSLATE,
                AgentNlIntentResolver::STEP_SEO_OPTIMIZE,
            ],
            $this->resolver->resolveCompoundSteps($message),
        );
        self::assertTrue($this->resolver->isCompoundTranslateSeoFlow($this->resolver->resolveCompoundSteps($message)));
        self::assertSame('', $this->resolver->resolveStarterAction($message));
    }

    #[Test]
    public function compoundStepsReverseOrderWhenSeoComesFirst(): void
    {
        $message = 'Optimise the SEO for this page, then translate it.';
        self::assertSame(
            [
                AgentNlIntentResolver::STEP_SEO_OPTIMIZE,
                AgentNlIntentResolver::STEP_TRANSLATE,
            ],
            $this->resolver->resolveCompoundSteps($message),
        );
    }
}
