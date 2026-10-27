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
