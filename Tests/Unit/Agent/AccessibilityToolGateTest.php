<?php

declare(strict_types=1);

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Service\AgentRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AccessibilityToolGateTest extends TestCase
{
    #[Test]
    public function aScanRequestIsNotGated(): void
    {
        self::assertNotContains('t3aa_accessibility_scan_run', AgentRunner::toolsNotAskedFor('Run an accessibility scan of this page'));
        self::assertNotContains('t3aa_accessibility_scan_run', AgentRunner::toolsNotAskedFor('Barrierefreiheits-Scan für diese Seite starten'));
        self::assertNotContains('t3aa_accessibility_scan_run', AgentRunner::toolsNotAskedFor('Scan this page'));
        self::assertFalse(AgentRunner::isAccessibilityScanRequest('Check this page for accessibility issues'));
        self::assertFalse(AgentRunner::isAccessibilityScanRequest('Prüfe diese Seite auf Barrierefreiheit'));
    }

    #[Test]
    public function issuesAndFixesDoNotOfferAScanCard(): void
    {
        self::assertContains('t3aa_accessibility_scan_run', AgentRunner::toolsNotAskedFor('What accessibility issues are on this page?'));
        self::assertContains('t3aa_accessibility_scan_run', AgentRunner::toolsNotAskedFor('Suggest fixes for the critical accessibility issue'));
        self::assertContains('t3aa_accessibility_scan_run', AgentRunner::toolsNotAskedFor('How do I fix the accessibility issues on this page?'));
        self::assertContains('t3aa_accessibility_scan_run', AgentRunner::toolsNotAskedFor('Show the last accessibility scan result'));
        self::assertFalse(AgentRunner::isAccessibilityScanRequest('Ergebnis des letzten Barrierefreiheits-Scans'));
    }

    #[Test]
    public function aFixRequestHidesTheIssuesList(): void
    {
        self::assertTrue(AgentRunner::isAccessibilityFixRequest('Suggest fixes for the critical accessibility issue'));
        self::assertTrue(AgentRunner::isAccessibilityFixRequest('How do I fix the accessibility issues on this page?'));
        self::assertFalse(AgentRunner::isAccessibilityFixRequest('What accessibility issues are on this page?'));
        self::assertContains('t3aa_accessibility_issues', AgentRunner::toolsNotAskedFor('Suggest fixes for the critical accessibility issue'));
        self::assertContains('t3aa_accessibility_issues', AgentRunner::toolsNotAskedFor('How do I fix the accessibility issues on this page?'));
        self::assertNotContains('t3aa_accessibility_issues', AgentRunner::toolsNotAskedFor('What accessibility issues are on this page?'));
    }

    #[Test]
    public function checkIssuesAndFixesAndPageSpeedDoNotOpenAScanCard(): void
    {
        $query = 'Check accessibility issues of page 15 and suggest fixes and tell me page speed';
        self::assertFalse(AgentRunner::isAccessibilityScanRequest($query));
        self::assertTrue(AgentRunner::isAccessibilityIssuesRequest($query));
        self::assertTrue(AgentRunner::isAccessibilityFixRequest($query));
        self::assertTrue(AgentRunner::isPageSpeedRequest($query));
        self::assertContains('t3aa_accessibility_scan_run', AgentRunner::toolsNotAskedFor($query));
        self::assertNotContains('t3aa_accessibility_issues', AgentRunner::toolsNotAskedFor($query));
        self::assertContains('t3aa_accessibility_scan_run', AgentRunner::toolsNotAskedFor('Check this page for accessibility issues'));
    }
}
