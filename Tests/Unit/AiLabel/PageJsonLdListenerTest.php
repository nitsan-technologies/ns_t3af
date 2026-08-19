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

namespace NITSAN\NsT3AF\Tests\Unit\AiLabel;

use NITSAN\NsT3AF\AiLabel\EventListener\PageJsonLdListener;
use PHPUnit\Framework\TestCase;

final class PageJsonLdListenerTest extends TestCase
{
    public function testInsertsScriptBeforeBodyEnd(): void
    {
        $html = '<!DOCTYPE html><html><body><p>News</p></body></html>';
        $script = '<script type="application/ld+json">{"@type":"WebPage"}</script>';

        $out = PageJsonLdListener::insertIntoDocument($html, $script);

        self::assertStringContainsString('</script></body></html>', $out);
        self::assertStringEndsWith('</html>', $out);
        self::assertStringNotContainsString('</html>' . $script, $out);
    }

    public function testFallsBackToHtmlEndWhenBodyMissing(): void
    {
        $html = '<html><p>x</p></html>';
        $script = '<script type="application/ld+json">{}</script>';

        $out = PageJsonLdListener::insertIntoDocument($html, $script);

        self::assertStringContainsString('</script></html>', $out);
    }

    public function testAppendsWhenNoClosingTags(): void
    {
        $html = '<p>fragment</p>';
        $script = '<script type="application/ld+json">{}</script>';

        self::assertSame($html . $script, PageJsonLdListener::insertIntoDocument($html, $script));
    }
}
