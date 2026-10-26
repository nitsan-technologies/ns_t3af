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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service\RecordsApply;

use NITSAN\NsT3AF\Mcp\Service\RecordsApply\InlineForeignFields;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class InlineForeignFieldsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalTca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTca = $GLOBALS['TCA'] ?? [];
        $GLOBALS['TCA'] = [
            'pages' => [
                'columns' => [
                    'media' => [
                        'config' => [
                            'type' => 'file',
                            'foreign_table' => 'sys_file_reference',
                            'foreign_field' => 'uid_foreign',
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->originalTca;
        parent::tearDown();
    }

    #[Test]
    public function fileFieldsContributeTheChildTablesForeignField(): void
    {
        $fields = (new InlineForeignFields())->ofChildTable('sys_file_reference');

        self::assertSame(['uid_foreign'], $fields);
    }
}
