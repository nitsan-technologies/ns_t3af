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

namespace NITSAN\NsT3AF\Tests\Functional\Settings;

use NITSAN\NsT3AF\Settings\ExtensionSettingsRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * TC-02: ExtensionSettingsRepository CRUD round-trip.
 */
final class ExtensionSettingsRepositoryFunctionalTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'frontend',
        'workspaces',
        'scheduler',
    ];

    protected array $testExtensionsToLoad = [
        'ns_t3af',
    ];

    #[Test]
    public function insertUpdateAndFindSettingsJsonRoundTrip(): void
    {
        /** @var ExtensionSettingsRepository $repository */
        $repository = $this->get(ExtensionSettingsRepository::class);

        self::assertNull($repository->findByExtensionKey('demo_ext'));

        $repository->insert('demo_ext', 0);
        $row = $repository->findByExtensionKey('demo_ext');
        self::assertIsArray($row);
        self::assertSame('demo_ext', $row['extension_key'] ?? null);
        self::assertSame('{}', $row['settings_json'] ?? null);

        $repository->updateSettingsJson('demo_ext', '{"enabled":true}', 0);
        $updated = $repository->findByExtensionKey('demo_ext');
        self::assertSame('{"enabled":true}', $updated['settings_json'] ?? null);

        $all = $repository->findAllByExtensionKey('demo_ext');
        self::assertCount(1, $all);
    }
}
