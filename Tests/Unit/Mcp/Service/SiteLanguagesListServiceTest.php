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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Service\SiteLanguagesListService;
use NITSAN\NsT3AF\Mcp\Support\SitePageResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * @internal
 */
final class SiteLanguagesListServiceTest extends TestCase
{
    #[Test]
    public function listForPageThrowsWhenPageCannotBeResolved(): void
    {
        $pageResolver = $this->createMock(SitePageResolver::class);
        $pageResolver->method('resolve')->willThrowException(
            new \RuntimeException('Either pageId or pageUrl must be provided.'),
        );

        $service = new SiteLanguagesListService(
            $pageResolver,
            $this->createMock(SiteFinder::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Either pageId or pageUrl must be provided.');

        $service->listForPage(null, '');
    }
}
