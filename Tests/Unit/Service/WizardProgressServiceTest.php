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

use NITSAN\NsT3AF\Credits\Domain\Repository\RuntimeSettingsRepository;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use NITSAN\NsT3AF\Service\WizardProgressService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class WizardProgressServiceTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $previousTypo3ConfVars = null;

    protected function setUp(): void
    {
        $this->previousTypo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-key-' . str_repeat('x', 32);
    }

    protected function tearDown(): void
    {
        if ($this->previousTypo3ConfVars === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->previousTypo3ConfVars;
        }
    }

    public function testIsCompletedReadsRuntimeRow(): void
    {
        $service = $this->createService(['wizard_completed' => 1]);

        self::assertTrue($service->isCompleted());
    }

    public function testGetLastStepClampsOutOfRangeValues(): void
    {
        $service = $this->createService([
            'wizard_last_step' => 99,
            'wizard_max_step' => 0,
        ]);

        self::assertSame(8, $service->getLastStep());
        self::assertSame(8, $service->getMaxStep());
    }

    public function testSaveProgressClampsAndEnsuresMaxIsAtLeastLast(): void
    {
        $holder = new class {
            /** @var array<string, scalar|null> */
            public array $saved = [];
        };
        $service = $this->createService(
            ['wizard_last_step' => 1, 'wizard_max_step' => 1],
            function (array $fields) use ($holder): void {
                $holder->saved = $fields;
            },
        );

        $service->saveProgress(4, 2);

        self::assertSame([
            'wizard_last_step' => 4,
            'wizard_max_step' => 4,
        ], $holder->saved);
    }

    public function testMarkCompletedSetsFlagAndFinalStep(): void
    {
        $holder = new class {
            /** @var array<string, scalar|null> */
            public array $saved = [];
        };
        $service = $this->createService(
            [],
            function (array $fields) use ($holder): void {
                $holder->saved = $fields;
            },
        );

        $service->markCompleted();

        self::assertSame([
            'wizard_completed' => 1,
            'wizard_last_step' => 8,
            'wizard_max_step' => 8,
        ], $holder->saved);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function createService(array $row, ?callable $onUpdate = null): WizardProgressService
    {
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn($row);
        if ($onUpdate !== null) {
            $repository->method('updateSingleton')->willReturnCallback($onUpdate);
        }

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);

        $runtime = new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            $extensionConfiguration,
        );

        return new WizardProgressService($runtime);
    }
}
