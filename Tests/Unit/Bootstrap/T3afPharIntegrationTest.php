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

namespace NITSAN\NsT3AF\Tests\Unit\Bootstrap;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Classic-mode (non-Composer) integration guarantees for the bundled
 * t3af.phar in Resources/Private/Libs/.
 *
 * These reproduce the three production failures that occurred when the host
 * site loaded the phar instead of installing symfony/ai-* via Composer:
 *
 *  1. "Symfony AI Platform runtime is not installed" — bridge factories must be
 *     resolvable at their phar-scoped FQN.
 *  2. "Cannot assign …\Uuid\UuidV7 to property UserMessage::$id of type
 *     …\Uuid\AbstractUid" — the scoped Symfony Uid must be internally consistent
 *     (regression guard against re-introducing a host→vendor Uuid alias).
 *  3. "Class … not found" (DataCollector / PHPUnit Constraint / …) — the phar
 *     must be self-contained: `php t3af.phar providers` must exit cleanly.
 *
 * The phar runs in a child process (cmd tests) or an isolated PHP process
 * (#[RunInSeparateProcess]) so its autoloader never leaks into the rest of the
 * unit suite.
 *
 * @internal
 */
final class T3afPharIntegrationTest extends TestCase
{
    private const VENDOR_PREFIX = 'NITSAN\T3af\\Vendor\\';

    private static function pharPath(): string
    {
        return dirname(__DIR__, 3) . '/Resources/Private/Libs/t3af.phar';
    }

    protected function setUp(): void
    {
        if (!extension_loaded('phar')) {
            self::markTestSkipped('phar extension not loaded');
        }
        if (!is_file(self::pharPath())) {
            self::markTestSkipped('t3af.phar not present (run build/build.sh in the phar builder)');
        }
    }

    /**
     * #3 — the phar boots and every bundled bridge factory is found by its own
     * runtime. A self-containment regression (missing parent class) makes the
     * CLI exit non-zero or print a fatal; either fails this test.
     */
    public function testProvidersCommandReportsEveryBridgeAvailable(): void
    {
        [$status, $output] = $this->runPhar('providers');

        self::assertSame(0, $status, "providers command failed:\n" . $output);
        foreach (['symfony.openai', 'symfony.ollama', 'symfony.anthropic', 'symfony.gemini', 'symfony.mistral', 'symfony.hugging-face'] as $type) {
            self::assertStringContainsString($type, $output);
        }
        // A bridge whose factory could not be resolved renders "NO" in the last column.
        self::assertStringNotContainsString('NO', $output, "a bridge factory was not resolvable:\n" . $output);
    }

    /**
     * #3 — version command is the cheapest end-to-end boot smoke.
     */
    public function testVersionCommandExitsCleanly(): void
    {
        [$status, $output] = $this->runPhar('version');

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('t3af.phar', $output);
    }

    /**
     * #1 — the exact phar-scoped factory FQN published by PlatformRegistry must
     * exist and construct a Platform. This is what SymfonyAiBridgeAdapter relies
     * on in classic mode.
     */
    #[RunInSeparateProcess]
    public function testScopedBridgeFactoriesExistAndBuild(): void
    {
        require 'phar://' . self::pharPath() . '/vendor/autoload.php';

        $registryClass = '\\NITSAN\T3af\\Runtime\\PlatformRegistry';
        self::assertTrue(class_exists($registryClass), 'PlatformRegistry missing from phar');

        $bridges = $registryClass::listBridges();
        self::assertNotEmpty($bridges);

        foreach ($bridges as $bridge) {
            $factory = self::resolveFactoryClass($bridge['factoryClass']);
            self::assertTrue(
                class_exists($factory),
                sprintf('factory %s (%s) not resolvable in phar', $factory, $bridge['type']),
            );

            $platform = self::createTestPlatform($factory, $bridge['type']);
            self::assertIsObject($platform, $bridge['type'] . ' factory did not return a platform');
        }
    }

    /**
     * Symfony AI 0.9 renamed PlatformFactory → Factory; tolerate legacy registry FQNs.
     *
     * @param class-string $factoryClass
     *
     * @return class-string
     */
    private static function resolveFactoryClass(string $factoryClass): string
    {
        if (class_exists($factoryClass)) {
            return $factoryClass;
        }

        $renamed = str_replace('\\PlatformFactory', '\\Factory', $factoryClass);

        return class_exists($renamed) ? $renamed : $factoryClass;
    }

    /**
     * @param class-string $factory
     */
    private static function createTestPlatform(string $factory, string $type): object
    {
        if (method_exists($factory, 'createPlatform')) {
            if ($type === 'symfony.ollama') {
                return $factory::createPlatform('http://localhost:11434');
            }

            return $factory::createPlatform('sk-test-dummy-key');
        }

        if (method_exists($factory, 'createProvider')) {
            return $factory::createProvider('sk-test-dummy-key');
        }

        return $factory::create('sk-test-dummy-key');
    }

    /**
     * #2 — regression guard for the UuidV7 TypeError. Constructing a scoped
     * UserMessage assigns Uuid::v7() to a property typed AbstractUid. Symfony Uid
     * is deliberately NOT scoped in the phar (see scoper.inc.php), so the id must
     * be an *un-scoped* Symfony\Component\Uid\AbstractUid. If Uid were re-scoped
     * (or a host→vendor alias re-introduced) this throws a TypeError instead.
     */
    #[RunInSeparateProcess]
    public function testUserMessageIdIsAnUnscopedUid(): void
    {
        require 'phar://' . self::pharPath() . '/vendor/autoload.php';

        $userMessage = self::VENDOR_PREFIX . 'Symfony\\AI\\Platform\\Message\\UserMessage';
        $text = self::VENDOR_PREFIX . 'Symfony\\AI\\Platform\\Message\\Content\\Text';

        self::assertTrue(class_exists($userMessage), 'scoped UserMessage missing from phar');
        self::assertTrue(class_exists($text), 'scoped Text content missing from phar');

        $message = self::instantiatePharScopedClass(
            $userMessage,
            self::instantiatePharScopedClass($text, 'hello'),
        );

        $idProperty = new \ReflectionProperty($userMessage, 'id');
        $id = $idProperty->getValue($message);

        self::assertInstanceOf(
            \Symfony\Component\Uid\AbstractUid::class,
            $id,
            'UserMessage::$id is not an un-scoped AbstractUid — Symfony Uid got scoped or aliased',
        );
    }

    /**
     * Regression guard for the MCP "must be compatible" fatal: the host's own
     * DatabaseSessionStore implements the phar's (scoped) SessionStoreInterface
     * while type-hinting un-scoped Symfony\Component\Uid\Uuid. That only works if
     * Symfony Uid is un-scoped in the phar, so the interface's Uuid parameter and
     * the host's Uuid parameter are the *same* class. We reproduce a host-style
     * implementor here; class declaration fails fatally on any signature mismatch.
     */
    #[RunInSeparateProcess]
    public function testHostStoreCanImplementScopedSessionStoreWithUnscopedUuid(): void
    {
        require 'phar://' . self::pharPath() . '/vendor/autoload.php';

        $interface = self::VENDOR_PREFIX . 'Mcp\\Server\\Session\\SessionStoreInterface';
        if (!interface_exists($interface)) {
            self::markTestSkipped('MCP SessionStoreInterface not bundled in this phar');
        }

        $impl = <<<PHP
            namespace NITSAN\\NsT3AF\\Tests\\Unit\\Bootstrap\\Fixture;

            use Symfony\\Component\\Uid\\Uuid;

            final class HostSessionStore implements \\{$interface}
            {
                public function exists(Uuid \$id): bool { return false; }
                public function read(Uuid \$id): string|false { return false; }
                public function write(Uuid \$id, string \$data): bool { return true; }
                public function destroy(Uuid \$id): bool { return true; }
                public function gc(): array { return []; }
            }
            PHP;

        eval($impl);

        self::assertTrue(
            class_exists('NITSAN\\NsT3AF\\Tests\\Unit\\Bootstrap\\Fixture\\HostSessionStore'),
            'host store with un-scoped Uuid hints is incompatible with the scoped SessionStoreInterface',
        );
    }

    /**
     * MCP SDK ships scoped inside the phar; the attribute class must load so the
     * host MCP tooling can reference it (directly or via the scoped→public alias).
     */
    #[RunInSeparateProcess]
    public function testScopedMcpClassesLoad(): void
    {
        require 'phar://' . self::pharPath() . '/vendor/autoload.php';

        self::assertTrue(
            class_exists(self::VENDOR_PREFIX . 'Mcp\\Capability\\Attribute\\McpTool'),
            'scoped MCP McpTool attribute missing from phar',
        );
    }

    /**
     * Classes under {@see self::VENDOR_PREFIX} exist only inside t3af.phar at runtime.
     *
     * @param non-empty-string $className
     * @param mixed ...$constructorArgs
     */
    private static function instantiatePharScopedClass(string $className, mixed ...$constructorArgs): object
    {
        return (new \ReflectionClass($className))->newInstanceArgs($constructorArgs);
    }

    /**
     * @return array{0:int,1:string} exit status and combined output
     */
    private function runPhar(string $command): array
    {
        // PHP_BINARY may contain spaces (Herd: ".../Application Support/...") — always quote it.
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::pharPath()) . ' ' . escapeshellarg($command) . ' 2>&1';
        $output = (string) shell_exec($cmd . '; echo "__EXIT__$?"');

        $status = 0;
        if (preg_match('/__EXIT__(\d+)\s*$/', $output, $m)) {
            $status = (int) $m[1];
            $output = (string) preg_replace('/__EXIT__\d+\s*$/', '', $output);
        }

        return [$status, $output];
    }
}
