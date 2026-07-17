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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Service\SetupChecklistPresenter;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Package\Package;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\FluidViewAdapter;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContext;
use TYPO3Fluid\Fluid\View\TemplateView;

final class SetupChecklistPresenterTest extends TestCase
{
    private static bool $typo3Bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$typo3Bootstrapped) {
            require dirname(__DIR__, 3)
                . '/.Build/vendor/typo3/testing-framework/Resources/Core/Build/UnitTestsBootstrap.php';
            $this->registerNsT3AFPackage();
            self::$typo3Bootstrapped = true;
        }
    }

    public function testConfigureExtbaseViewPartialsAddsAiUniversePartialRootOnFluidViewAdapter(): void
    {
        $renderingContext = new RenderingContext();
        $renderingContext->getTemplatePaths()->setPartialRootPaths(['/tmp/example-partials']);
        $fluidView = new TemplateView($renderingContext);
        $adapter = new FluidViewAdapter($fluidView);

        $this->createPresenter()->configureExtbaseViewPartials($adapter);

        $partialRoots = $fluidView->getRenderingContext()->getTemplatePaths()->getPartialRootPaths();
        $expected = GeneralUtility::getFileAbsFileName('EXT:ns_t3af/Resources/Private/Partials/');

        self::assertNotSame('', $expected);
        self::assertContains('/tmp/example-partials', $partialRoots);
        self::assertContains($expected, $partialRoots);
    }

    public function testConfigureExtbaseViewPartialsAddsAiUniversePartialRootOnTemplateView(): void
    {
        $renderingContext = new RenderingContext();
        $renderingContext->getTemplatePaths()->setPartialRootPaths([]);
        $fluidView = new TemplateView($renderingContext);

        $this->createPresenter()->configureExtbaseViewPartials($fluidView);

        $partialRoots = $fluidView->getRenderingContext()->getTemplatePaths()->getPartialRootPaths();
        $expected = GeneralUtility::getFileAbsFileName('EXT:ns_t3af/Resources/Private/Partials/');

        self::assertContains($expected, $partialRoots);
    }

    public function testConfigureExtbaseViewPartialsAddsAiUniversePartialRootOnV12StyleAdapter(): void
    {
        $renderingContext = new RenderingContext();
        $renderingContext->getTemplatePaths()->setPartialRootPaths([]);
        $fluidView = new TemplateView($renderingContext);
        $adapter = new V12FluidViewAdapterStub($fluidView);

        $this->createPresenter()->configureExtbaseViewPartials($adapter);

        $partialRoots = $fluidView->getRenderingContext()->getTemplatePaths()->getPartialRootPaths();
        $expected = GeneralUtility::getFileAbsFileName('EXT:ns_t3af/Resources/Private/Partials/');

        self::assertContains($expected, $partialRoots);
    }

    private function createPresenter(): SetupChecklistPresenter
    {
        $reflection = new \ReflectionClass(SetupChecklistPresenter::class);

        return $reflection->newInstanceWithoutConstructor();
    }

    private function registerNsT3AFPackage(): void
    {
        $property = (new \ReflectionClass(ExtensionManagementUtility::class))->getProperty('packageManager');
        $packageManager = $property->getValue();

        $extensionRoot = dirname(__DIR__, 3) . '/';
        $package = new Package($packageManager, 'ns_t3af', $extensionRoot);
        $packageManager->registerPackage($package);
        $packageManager->activatePackage('ns_t3af');
    }
}

/**
 * Minimal stub of TYPO3 v12 Core\View\FluidViewAdapter (assign/render only, no getRenderingContext).
 */
final class V12FluidViewAdapterStub
{
    public function __construct(
        protected object $view,
    ) {}

    public function assign(string $key, mixed $value): self
    {
        $this->view->assign($key, $value);

        return $this;
    }

    public function render(string $templateFileName = ''): string
    {
        return $this->view->render($templateFileName);
    }
}
