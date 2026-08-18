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

use NITSAN\NsT3AF\AiLabel\Service\ComplianceStringsService;
use NITSAN\NsT3AF\AiLabel\Service\EuIconManifestService;
use PHPUnit\Framework\TestCase;

/**
 * Stage 0 cross-version smoke checks (no TYPO3 bootstrap required).
 */
final class CompatibilitySmokeTest extends TestCase
{
    private string $extRoot;

    protected function setUp(): void
    {
        $this->extRoot = dirname(__DIR__, 3);
    }

    public function testStage0BaselineArtifactsExist(): void
    {
        self::assertFileExists($this->extRoot . '/Configuration/TypoScript/setup.typoscript');
        self::assertFileExists($this->extRoot . '/Configuration/Sets/NsT3afLabel/config.yaml');
        self::assertFileExists($this->extRoot . '/Configuration/Sets/NsT3afLabel/setup.typoscript');
        self::assertFileExists($this->extRoot . '/Configuration/TCA/Overrides/sys_template.php');
        self::assertFileExists($this->extRoot . '/Resources/Private/Partials/FluidStyledContent/DropIn/After/All.html');
        self::assertFileExists($this->extRoot . '/Resources/Private/Partials/FluidStyledContent/Media/Rendering/Image.html');
        self::assertStringContainsString(
            'ail:label file="{file}"',
            (string) file_get_contents($this->extRoot . '/Resources/Private/Partials/FluidStyledContent/Media/Rendering/Image.html'),
        );
        self::assertFileExists($this->extRoot . '/Build/version-matrix.json');
        self::assertFileExists($this->extRoot . '/Configuration/BuildInputs/compliance-strings.json');
        self::assertDirectoryExists($this->extRoot . '/Resources/Public/Icons/EuAiLabel');
    }

    public function testTypoScriptIsNotRegisteredViaAddTypoScriptImport(): void
    {
        $localconf = (string) file_get_contents($this->extRoot . '/ext_localconf.php');
        self::assertStringNotContainsString('addTypoScript(', $localconf);
        self::assertStringContainsString('AI Foundation labels', (string) file_get_contents(
            $this->extRoot . '/Configuration/TCA/Overrides/sys_template.php',
        ));
        self::assertStringContainsString(
            'nitsan/ns-t3af-label',
            (string) file_get_contents($this->extRoot . '/Configuration/Sets/NsT3afLabel/config.yaml'),
        );
    }

    public function testDualRegistrationHooksDocumentedInVersionMatrix(): void
    {
        $matrix = json_decode(
            (string) file_get_contents($this->extRoot . '/Build/version-matrix.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertContains('dual cache registration for nst3af_ailabel_undo', $matrix['checks']);
        self::assertContains('event.listener tags in Services.yaml', $matrix['checks']);
        self::assertContains('static TypoScript template and site set', $matrix['checks']);
    }

    public function testBuildInputsAndIconsVerify(): void
    {
        self::assertSame('2026-08-02', (new ComplianceStringsService())->applicationDate());
        self::assertTrue((new EuIconManifestService())->verify());
    }
}
