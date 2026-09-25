<?php

declare(strict_types=1);

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Context\AgentContextResolver;
use NITSAN\NsT3AF\Domain\Repository\BrandContextProfileRepositoryInterface;
use NITSAN\NsT3AF\Service\BrandContextProfileOverrideReaderInterface;
use NITSAN\NsT3AF\Service\BrandContextResolver;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * @internal
 */
final class AgentContextResolverTest extends TestCase
{
    #[Test]
    public function keepsDefaultLanguageWhenClientSendsZero(): void
    {
        $context = $this->resolver()->resolve([
            'module' => 't3af_dashboard',
            'pageId' => 1,
            'languageId' => 0,
        ]);

        self::assertSame(0, $context->languageId);
    }

    #[Test]
    public function keepsExplicitNonDefaultLanguageFromClient(): void
    {
        $context = $this->resolver()->resolve([
            'module' => 'web_layout',
            'pageId' => 1,
            'languageId' => 3,
        ]);

        self::assertSame(3, $context->languageId);
    }

    private function resolver(): AgentContextResolver
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);
        $siteFinder->method('getSiteByPageId')->willThrowException(new \TYPO3\CMS\Core\Exception\SiteNotFoundException('none', 1));

        $brand = new BrandContextResolver(
            $this->createMock(BrandContextProfileRepositoryInterface::class),
            new SiteStorageContext($siteFinder),
            $this->createMock(BrandContextProfileOverrideReaderInterface::class),
        );

        return new AgentContextResolver($brand);
    }
}
