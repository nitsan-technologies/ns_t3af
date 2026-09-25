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

use NITSAN\NsT3AF\Agent\Service\AgentEvalRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentEvalRunnerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/nst3af-agent-eval-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    #[Test]
    public function recordWritesFixtureAndReplayPasses(): void
    {
        $runner = new AgentEvalRunner();
        $path = $runner->record([
            'id' => 'demo-case',
            'userMessage' => 'meta title please',
            'mocked' => [
                'tool' => 't3ai_generate_all_seo',
                'routingSource' => 'embeddings',
                'fieldKeys' => ['metaTitle', 'keywords'],
                'variantsComplete' => true,
            ],
        ], $this->tempDir);

        self::assertFileExists($path);
        $results = $runner->replay($this->tempDir);
        self::assertCount(1, $results);
        self::assertTrue($results[0]['ok']);
        self::assertSame([], $results[0]['errors']);
    }

    #[Test]
    public function assertCaseFailsOnFieldKeyMismatch(): void
    {
        $runner = new AgentEvalRunner();
        $errors = $runner->assertCase([
            'id' => 'bad',
            'mocked' => [
                'tool' => 't3ai_generate_all_seo',
                'routingSource' => 'embeddings',
                'fieldKeys' => ['metaTitle'],
            ],
            'expect' => [
                'tool' => 't3ai_generate_all_seo',
                'routingSource' => 'embeddings',
                'fieldKeys' => ['metaTitle', 'keywords'],
            ],
        ]);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('fieldKeys', $errors[0]);
    }

    #[Test]
    public function shippedFixturesPassReplay(): void
    {
        $runner = new AgentEvalRunner();
        $results = $runner->replay($runner->defaultFixtureDirectory());
        self::assertNotSame([], $results);
        foreach ($results as $result) {
            self::assertTrue($result['ok'], $result['id'] . ': ' . implode('; ', $result['errors']));
        }
    }
}
