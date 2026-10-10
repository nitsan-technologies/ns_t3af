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

namespace NITSAN\NsT3AF\Tests\Unit\Agent\PremiumCatalog;

use NITSAN\NsT3AF\Access\ExtensionAvailability;
use NITSAN\NsT3AF\Agent\PremiumCatalog\PremiumCatalogProvider;
use NITSAN\NsT3AF\Tests\Unit\Access\Support\LoadedExtensionsTestTrait;
use PHPUnit\Framework\TestCase;

final class PremiumCatalogProviderTest extends TestCase
{
    use LoadedExtensionsTestTrait;

    protected function tearDown(): void
    {
        $this->resetLoadedExtensions();
        parent::tearDown();
    }

    public function testFindsTranslationCapability(): void
    {
        $this->resetLoadedExtensions();
        $provider = new PremiumCatalogProvider(new ExtensionAvailability());

        $match = $provider->findMatch('translate this page to German');

        self::assertNotNull($match);
        self::assertSame('ns_t3ai', $match->extensionKey);
    }

    public function testAWordInsideAnotherWordIsNotACapabilityRequest(): void
    {
        // "rag" sits inside "paragraphs" and "average"; an essay request must reach the model.
        $this->resetLoadedExtensions();
        $provider = new PremiumCatalogProvider(new ExtensionAvailability());

        self::assertNull($provider->findMatch('Write a long essay of ten paragraphs about the average rainfall'));
        self::assertNotNull($provider->findMatch('translated pages please'));
        self::assertNotNull($provider->findMatch('add the chatbots to the site'));
    }

    public function testFindsAccessibilityCapability(): void
    {
        $this->resetLoadedExtensions();
        $provider = new PremiumCatalogProvider(new ExtensionAvailability());

        $match = $provider->findMatch('fix the alt text on this image');

        self::assertNotNull($match);
        self::assertSame('ns_t3aa', $match->extensionKey);
    }

    public function testAnInstalledExtensionThatMatchesSuppressesTheUpsell(): void
    {
        // "generate an image … alt text" matched ns_t3ai ("generate image") and ns_t3aa ("alt text")
        // equally. ns_t3aa was returned because loaded entries were skipped outright, so a
        // purchased T3AI image request was answered with an AI Accessibility upsell.
        $this->mockLoadedExtensions(['ns_t3ai']);
        $provider = new PremiumCatalogProvider(new ExtensionAvailability());

        self::assertNull($provider->findMatch(
            "Generate an image of a modern house with solar panels on the roof and give it the alt text 'House with solar panels'.",
        ));
        self::assertNull($provider->findStandaloneMatch(
            "Create a hero image of a wind turbine at sunset with alt text 'Wind turbine at sunset'.",
        ));
    }

    public function testAnInstalledExtensionDoesNotSuppressAnUnrelatedUpsell(): void
    {
        // ns_t3ai installed must not silence a real ns_t3aa-only request: it scores 0 here.
        $this->mockLoadedExtensions(['ns_t3ai']);
        $provider = new PremiumCatalogProvider(new ExtensionAvailability());

        $match = $provider->findMatch('generate a voice over for this page');

        self::assertNotNull($match);
        self::assertSame('ns_t3aa', $match->extensionKey);
    }

    public function testFindsContentSourceCapabilityViaSearchExtension(): void
    {
        // ns_t3cs is the shared backend for ns_t3as/ns_t3ac and is never sold on its own —
        // a crawl/datasource query should surface one of the real products that installs it.
        $this->resetLoadedExtensions();
        $provider = new PremiumCatalogProvider(new ExtensionAvailability());

        $match = $provider->findMatch('crawl this site and build a datasource');

        self::assertNotNull($match);
        self::assertContains($match->extensionKey, ['ns_t3as', 'ns_t3ac']);
    }

    public function testFindsAiSearchViaSpecificPhrases(): void
    {
        $this->resetLoadedExtensions();
        $provider = new PremiumCatalogProvider(new ExtensionAvailability());

        $match = $provider->findMatch('set up semantic search for the site');

        self::assertNotNull($match);
        self::assertSame('ns_t3as', $match->extensionKey);
    }

    public function testIgnoresBareSearchThatBelongsToCoreTools(): void
    {
        // Bare "search"/"suche" must not upsell AI Search — core pages_search / content_search
        // own ordinary "search for page X" requests.
        $this->resetLoadedExtensions();
        $provider = new PremiumCatalogProvider(new ExtensionAvailability());

        self::assertNull($provider->findMatch('search the page "TR Recursive Parent"'));
        self::assertNull($provider->findMatch('Search for page Home'));
        self::assertNull($provider->findStandaloneMatch('Search for page Home'));
        self::assertNull($provider->findMatch('suche die Seite Start'));
    }

    public function testUsesGermanProductUrlForGermanBackendLanguage(): void
    {
        $this->resetLoadedExtensions();
        $provider = new PremiumCatalogProvider(new ExtensionAvailability());

        $match = $provider->findMatch('translate this page to German');

        self::assertNotNull($match);
        self::assertSame('https://t3planet.de/t3ai-typo3-erweiterung', $match->infoUrl('de'));
        self::assertSame('https://t3planet.de/en/t3ai-typo3-extension', $match->infoUrl('en'));
    }

    public function testReturnsNullForUnrelatedQuery(): void
    {
        $this->resetLoadedExtensions();
        $provider = new PremiumCatalogProvider(new ExtensionAvailability());

        self::assertNull($provider->findMatch('what is the capital of France'));
    }

    public function testReturnsNullOnceTheMatchingExtensionIsLoaded(): void
    {
        $this->mockLoadedExtensions(['ns_t3ai']);
        $provider = new PremiumCatalogProvider(new ExtensionAvailability());

        self::assertNull($provider->findMatch('translate this page to German'));
    }

    public function testStandaloneMatchFindsASingleCleanRequest(): void
    {
        $this->resetLoadedExtensions();
        $provider = new PremiumCatalogProvider(new ExtensionAvailability());

        $match = $provider->findStandaloneMatch('Translate this page to German.');

        self::assertNotNull($match);
        self::assertSame('ns_t3ai', $match->extensionKey);
    }

    public function testStandaloneMatchIsNullForASequencedCompoundRequest(): void
    {
        $this->resetLoadedExtensions();
        $provider = new PremiumCatalogProvider(new ExtensionAvailability());

        self::assertNull($provider->findStandaloneMatch('Create this page, then translate it to German'));
    }

    public function testStandaloneMatchIsNullForAGermanSequencedCompoundRequest(): void
    {
        $this->resetLoadedExtensions();
        $provider = new PremiumCatalogProvider(new ExtensionAvailability());

        self::assertNull($provider->findStandaloneMatch('Lege die Seite an, dann übersetze sie ins Deutsche'));
    }

    public function testStandaloneMatchIsNullForMultipleSentences(): void
    {
        $this->resetLoadedExtensions();
        $provider = new PremiumCatalogProvider(new ExtensionAvailability());

        self::assertNull($provider->findStandaloneMatch('Translate this page to German. Also check the images.'));
    }

    public function testStandaloneMatchIsNullWhenTheAltTextComesWithAnAttachedFile(): void
    {
        $this->resetLoadedExtensions();
        $provider = new PremiumCatalogProvider(new ExtensionAvailability());

        self::assertNull($provider->findStandaloneMatch('Create a text & media content element with the heading Frontend Check and a short sentence, attach the existing image sys_file uid 145 and set a sensible alt text for the image'));
        self::assertNull($provider->findStandaloneMatch('Attach the existing image sys_file uid 145 to the content element uid 250 and set a sensible alt text for the image'));
        self::assertNull($provider->findStandaloneMatch('Hänge das Bild mit der Datei-UID 145 an das Element 250 an und setze einen Alttext'));
        self::assertSame('ns_t3aa', $provider->findStandaloneMatch('Generate alt text for all images on this page')?->extensionKey);
    }
}
