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

use NITSAN\NsT3AF\Service\EnvironmentRequirementService;
use PHPUnit\Framework\TestCase;

final class EnvironmentRequirementServiceTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $previousTypo3ConfVars = null;

    /** @var array{repro: string|false, ddev: string|false, context: string|false} */
    private array $previousEnv = ['repro' => false, 'ddev' => false, 'context' => false];

    protected function setUp(): void
    {
        $this->previousTypo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $this->previousEnv = [
            'repro' => getenv(EnvironmentRequirementService::REPRO_ENV),
            'ddev' => getenv('DDEV_PROJECT'),
            'context' => getenv('TYPO3_CONTEXT'),
        ];
        $this->clearReproEnv();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-key-' . str_repeat('x', 32);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv(EnvironmentRequirementService::REPRO_ENV, $this->previousEnv['repro']);
        $this->restoreEnv('DDEV_PROJECT', $this->previousEnv['ddev']);
        $this->restoreEnv('TYPO3_CONTEXT', $this->previousEnv['context']);

        if ($this->previousTypo3ConfVars === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->previousTypo3ConfVars;
        }
    }

    public function testHealthyEnvironmentHasNoFailingChecklistItems(): void
    {
        if (!extension_loaded('sodium') || !extension_loaded('curl')) {
            self::markTestSkipped('sodium and curl must be loaded for the healthy-path assertion');
        }

        $service = new EnvironmentRequirementService();
        self::assertTrue($service->isReady());
        self::assertSame([], $service->failingChecklistItems());
    }

    public function testMissingEncryptionKeyProducesChecklistError(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '';
        $service = new EnvironmentRequirementService();

        self::assertFalse($service->hasEncryptionKey());
        self::assertFalse($service->isCipherReady());

        $items = $service->failingChecklistItems();
        $keys = array_column($items, 'titleKey');
        self::assertContains('checklist.env.encryptionKey.title', $keys);
        self::assertSame('t3af_dashboard.providers', $items[0]['actionRoute'] ?? null);
    }

    public function testSodiumDetectionMatchesPhpExtension(): void
    {
        $service = new EnvironmentRequirementService();
        self::assertSame(
            extension_loaded('sodium') && \defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES'),
            $service->hasSodium(),
        );
    }

    public function testReproEnvIgnoredOutsideDdevOrDevelopment(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('sodium must be loaded');
        }

        putenv(EnvironmentRequirementService::REPRO_ENV . '=1');
        $_ENV[EnvironmentRequirementService::REPRO_ENV] = '1';
        putenv('DDEV_PROJECT');
        unset($_ENV['DDEV_PROJECT']);
        putenv('TYPO3_CONTEXT=Production');
        $_ENV['TYPO3_CONTEXT'] = 'Production';

        self::assertTrue((new EnvironmentRequirementService())->hasSodium());
    }

    public function testReproEnvForcesMissingSodiumUnderDdev(): void
    {
        putenv(EnvironmentRequirementService::REPRO_ENV . '=1');
        $_ENV[EnvironmentRequirementService::REPRO_ENV] = '1';
        putenv('DDEV_PROJECT=phpunit');
        $_ENV['DDEV_PROJECT'] = 'phpunit';

        $service = new EnvironmentRequirementService();
        self::assertFalse($service->hasSodium());

        $items = $service->failingChecklistItems();
        self::assertSame('checklist.env.sodium.title', $items[0]['titleKey'] ?? null);
        self::assertSame('error', $items[0]['status'] ?? null);
    }

    private function clearReproEnv(): void
    {
        putenv(EnvironmentRequirementService::REPRO_ENV);
        unset($_ENV[EnvironmentRequirementService::REPRO_ENV]);
        putenv('DDEV_PROJECT');
        unset($_ENV['DDEV_PROJECT']);
        putenv('TYPO3_CONTEXT');
        unset($_ENV['TYPO3_CONTEXT']);
    }

    private function restoreEnv(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);
            unset($_ENV[$name]);
            return;
        }
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}
