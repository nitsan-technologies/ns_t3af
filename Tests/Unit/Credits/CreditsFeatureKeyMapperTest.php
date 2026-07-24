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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Tests\Unit\Credits;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Credits\CreditsApiEndpoint;
use NITSAN\NsT3AF\Credits\CreditsFeatureKeyCatalog;
use NITSAN\NsT3AF\Credits\Service\CreditsFeatureKeyMapper;
use NITSAN\NsT3AF\Tests\Unit\Support\ProviderTestStubs;
use PHPUnit\Framework\TestCase;

final class CreditsFeatureKeyMapperTest extends TestCase
{
    private CreditsFeatureKeyMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['creditsFeatureKeyAliases']);
        $this->mapper = new CreditsFeatureKeyMapper(ProviderTestStubs::creditsAliasProviders());
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['creditsFeatureKeyAliases']);
        parent::tearDown();
    }

    public function testPassesThroughCatalogKeys(): void
    {
        self::assertSame(
            CreditsFeatureKeyCatalog::CONTENT_TRANSLATION,
            $this->mapper->map(
                CreditsFeatureKeyCatalog::CONTENT_TRANSLATION,
                new AiOptions(extensionKey: 'ns_t3ai'),
                CreditsApiEndpoint::Charge,
            ),
        );
    }

    public function testMapsT3AiTranslationKeys(): void
    {
        self::assertSame(
            CreditsFeatureKeyCatalog::CONTENT_TRANSLATION,
            $this->mapper->map(
                'translation.openai',
                new AiOptions(extensionKey: 'ns_t3ai'),
                CreditsApiEndpoint::Charge,
            ),
        );
    }

    public function testMapsT3AiSeoKeys(): void
    {
        self::assertSame(
            CreditsFeatureKeyCatalog::SEO_META_DESCRIPTION,
            $this->mapper->map(
                'seo.meta_description',
                new AiOptions(extensionKey: 'ns_t3ai'),
                CreditsApiEndpoint::Charge,
            ),
        );
    }

    public function testMapsPageTreeToCatalogKey(): void
    {
        self::assertSame(
            CreditsFeatureKeyCatalog::PAGE_STRUCTURE_GENERATION,
            $this->mapper->map(
                'page.tree',
                new AiOptions(extensionKey: 'ns_t3ai'),
                CreditsApiEndpoint::Charge,
            ),
        );
    }

    public function testStreamEndpointAlwaysUsesStreamCatalogKey(): void
    {
        self::assertSame(
            CreditsFeatureKeyCatalog::STREAM,
            $this->mapper->map(
                'translation.openai',
                new AiOptions(extensionKey: 'ns_t3ai'),
                CreditsApiEndpoint::Stream,
            ),
        );
    }

    public function testEmbedEndpointAlwaysUsesEmbeddingCatalogKey(): void
    {
        self::assertSame(
            CreditsFeatureKeyCatalog::EMBEDDING,
            $this->mapper->map(
                'translation.openai',
                new AiOptions(extensionKey: 'ns_t3ai'),
                CreditsApiEndpoint::Embed,
            ),
        );
    }

    public function testExtensionExtConfAliasesAreApplied(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['creditsFeatureKeyAliases'] = [
            'my_extension' => [
                'custom.writer' => CreditsFeatureKeyCatalog::EASY_LANGUAGE,
            ],
        ];

        self::assertSame(
            CreditsFeatureKeyCatalog::EASY_LANGUAGE,
            $this->mapper->map(
                'custom.writer',
                new AiOptions(extensionKey: 'my_extension'),
                CreditsApiEndpoint::Charge,
            ),
        );
    }

    public function testUnknownKeyFallsBackToContentGeneration(): void
    {
        self::assertSame(
            CreditsFeatureKeyCatalog::CONTENT_GENERATION,
            $this->mapper->map(
                'totally.unknown.feature',
                new AiOptions(extensionKey: 'other_extension'),
                CreditsApiEndpoint::Charge,
            ),
        );
    }

    public function testSpeakEndpointAlwaysUsesTextToSpeechCatalogKey(): void
    {
        self::assertSame(
            CreditsFeatureKeyCatalog::TEXT_TO_SPEECH,
            $this->mapper->map(
                'media.tts',
                new AiOptions(extensionKey: 'ns_t3aa'),
                CreditsApiEndpoint::Speak,
            ),
        );
    }

    public function testImageEndpointAlwaysUsesImageGenerationCatalogKey(): void
    {
        self::assertSame(
            CreditsFeatureKeyCatalog::IMAGE_GENERATION,
            $this->mapper->map(
                'media.dalle',
                new AiOptions(extensionKey: 'ns_t3ai'),
                CreditsApiEndpoint::Image,
            ),
        );
    }

    public function testMapsMediaTtsToTextToSpeechForT3aa(): void
    {
        self::assertSame(
            CreditsFeatureKeyCatalog::TEXT_TO_SPEECH,
            $this->mapper->map(
                'media.tts',
                new AiOptions(extensionKey: 'ns_t3aa'),
                CreditsApiEndpoint::Charge,
            ),
        );
    }

    public function testMapsT3AaFileMetadataKeys(): void
    {
        self::assertSame(
            CreditsFeatureKeyCatalog::METADATA_ALT_TEXT,
            $this->mapper->map(
                'file.alt_text',
                new AiOptions(extensionKey: 'ns_t3aa'),
                CreditsApiEndpoint::Charge,
            ),
        );
        self::assertSame(
            CreditsFeatureKeyCatalog::METADATA_ALT_TEXT,
            $this->mapper->map(
                'file.alt_text.alttext_ai',
                new AiOptions(extensionKey: 'ns_t3aa'),
                CreditsApiEndpoint::Charge,
            ),
        );
        self::assertSame(
            CreditsFeatureKeyCatalog::METADATA_TITLE,
            $this->mapper->map(
                'file.meta_title_description',
                new AiOptions(extensionKey: 'ns_t3aa'),
                CreditsApiEndpoint::Charge,
            ),
        );
    }

    public function testMapsMediaTtsToTextToSpeechForT3ai(): void
    {
        self::assertSame(
            CreditsFeatureKeyCatalog::TEXT_TO_SPEECH,
            $this->mapper->map(
                'media.tts',
                new AiOptions(extensionKey: 'ns_t3ai'),
                CreditsApiEndpoint::Charge,
            ),
        );
    }
}
