<?php

declare(strict_types=1);

namespace NITSAN\NsT3AF\Tests\Unit\Agent\Service;

use NITSAN\NsT3AF\Agent\Service\PageCreateBefore;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PageCreateBeforeTest extends UnitTestCase
{
    #[Test]
    public function createBeforeNamesThePageAndKeepsAUid(): void
    {
        self::assertTrue(PageCreateBefore::isCreateBefore('Create NB directly before C'));
        self::assertSame(['title' => 'A', 'uid' => 0], PageCreateBefore::target('Create page NB directly before A (A is already first)'));
        self::assertSame(['title' => 'C', 'uid' => 111], PageCreateBefore::target('Create NB directly before C [111]'));
        self::assertSame(['title' => 'C', 'uid' => 0], PageCreateBefore::target('Create a page before C named NB'));
    }

    #[Test]
    public function afterAndMoveAreNotCreateBefore(): void
    {
        self::assertFalse(PageCreateBefore::isCreateBefore('Create NA directly after A'));
        self::assertFalse(PageCreateBefore::isCreateBefore('Move C to directly after X'));
        self::assertFalse(PageCreateBefore::isCreateBefore('Create a subpage of Parent'));
    }
}
