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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Service\RecordPayloadNormalizer;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the field handling that WriteTableTool had before it moved into the shared normalizer.
 *
 * @internal
 */
final class RecordPayloadNormalizerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalTca;

    private RecordPayloadNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTca = $GLOBALS['TCA'] ?? [];
        $GLOBALS['TCA']['tt_content'] = [
            'ctrl' => ['label' => 'header', 'tstamp' => 'tstamp'],
            'columns' => [
                'header' => ['config' => ['type' => 'input']],
                'tstamp' => ['config' => ['type' => 'passthrough']],
                'locked' => ['config' => ['type' => 'input', 'readOnly' => true]],
                'categories' => ['config' => ['type' => 'category']],
                'assets' => ['config' => ['type' => 'file']],
                'items' => ['config' => ['type' => 'inline', 'foreign_table' => 'tx_items', 'foreign_field' => 'parent']],
            ],
        ];

        $this->normalizer = new RecordPayloadNormalizer(new TcaSchemaService());
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->originalTca;
        parent::tearDown();
    }

    #[Test]
    public function filterWritableFieldsKeepsOnlyScalarAndRelationColumns(): void
    {
        $filtered = $this->normalizer->filterWritableFields('tt_content', [
            'header' => 'Hi',
            'categories' => '8,12',
            'locked' => 'x',
            'tstamp' => 1,
            'assets' => [['uid_local' => 5]],
            'items' => 3,
            'bogus' => 'y',
        ]);

        self::assertSame(['header' => 'Hi', 'categories' => '8,12'], $filtered);
    }

    #[Test]
    public function ignoredFieldsListsEveryDroppedKeyInOrder(): void
    {
        $payload = ['header' => 'Hi', 'bogus' => 1, 'locked' => 2];

        self::assertSame(
            ['bogus', 'locked'],
            $this->normalizer->ignoredFields($payload, ['header' => 'Hi']),
        );
    }

    #[Test]
    public function relationUidListsBecomeCommaSeparatedStrings(): void
    {
        $payload = $this->normalizer->normalizeRelationUidListFields('tt_content', [
            'categories' => [8, '12', 'abc', 3.5],
            'header' => [1, 2],
        ]);

        self::assertSame('8,12', $payload['categories']);
        self::assertSame([1, 2], $payload['header'], 'Fields that are not relation lists stay untouched.');
    }

    #[Test]
    public function relationUidScalarsAreCastToStrings(): void
    {
        self::assertSame(
            ['categories' => '126'],
            $this->normalizer->normalizeRelationUidListFields('tt_content', ['categories' => 126]),
        );
        self::assertSame(
            ['categories' => '8,12'],
            $this->normalizer->normalizeRelationUidListFields('tt_content', ['categories' => '8,12']),
        );
    }

    #[Test]
    public function fileFieldsAreExtractedWithOnlyKnownStringMetaKeys(): void
    {
        [$payload, $fileFields] = $this->normalizer->extractFileFields('tt_content', [
            'header' => 'Hi',
            'assets' => [
                ['uid_local' => 93, 'alternative' => 'Alt', 'crop' => 5, 'unknown' => 'x'],
                ['uid_local' => 0],
                ['uid_local' => '94', 'title' => 'T'],
            ],
        ]);

        self::assertSame(['header' => 'Hi'], $payload);
        self::assertSame(
            [
                'assets' => [
                    ['uid_local' => 93, 'alternative' => 'Alt'],
                    ['uid_local' => 94, 'title' => 'T'],
                ],
            ],
            $fileFields,
        );
    }

    #[Test]
    public function emptyFileFieldClearsTheAttachmentsAndIsRemovedFromThePayload(): void
    {
        [$payload, $fileFields] = $this->normalizer->extractFileFields('tt_content', ['assets' => []]);

        self::assertSame([], $payload);
        self::assertSame(['assets' => []], $fileFields);
    }

    #[Test]
    public function fileFieldThatIsNotAUidLocalListStaysInThePayload(): void
    {
        [$payload, $fileFields] = $this->normalizer->extractFileFields('tt_content', ['assets' => 3]);

        self::assertSame(['assets' => 3], $payload);
        self::assertSame([], $fileFields);
    }

    #[Test]
    public function noWritableFieldsMessageWithoutIgnoredFieldsIsPlain(): void
    {
        self::assertSame(
            'No valid writable fields provided.',
            $this->normalizer->noWritableFieldsMessage('tt_content', [], []),
        );
    }

    #[Test]
    public function noWritableFieldsMessageExplainsEachIgnoredField(): void
    {
        $message = $this->normalizer->noWritableFieldsMessage(
            'tt_content',
            ['items' => 3, 'assets' => 1],
            [],
        );

        self::assertStringStartsWith('No valid writable fields provided. ', $message);
        self::assertStringContainsString('items:', $message);
        self::assertStringContainsString('tx_items', $message);
        self::assertStringContainsString('assets:', $message);
        self::assertStringContainsString('file_reference_add', $message);
    }

    #[Test]
    public function ignoredFieldsContextCarriesNamesAndDetails(): void
    {
        $context = $this->normalizer->ignoredFieldsContext('tt_content', ['bogus']);

        self::assertSame(['bogus'], $context['ignoredFields']);
        self::assertSame('unknown_or_not_in_tca', $context['ignoredFieldDetails'][0]['reason']);
    }
}
