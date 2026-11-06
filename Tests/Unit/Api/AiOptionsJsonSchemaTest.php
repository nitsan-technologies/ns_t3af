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

namespace NITSAN\NsT3AF\Tests\Unit\Api;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AiOptionsJsonSchemaTest extends TestCase
{
    #[Test]
    public function jsonSchemaDefaultsToNullAndIsAdditive(): void
    {
        $options = new AiOptions(temperature: 0.2);

        self::assertNull($options->jsonSchema);
        self::assertSame(0.2, $options->temperature);
    }

    #[Test]
    public function jsonSchemaCanBeSet(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['verdict' => ['type' => 'string']],
            'required' => ['verdict'],
            'additionalProperties' => false,
        ];
        $options = new AiOptions(jsonSchema: $schema);

        self::assertSame($schema, $options->jsonSchema);
    }

    #[Test]
    public function aiResponseStructuredDefaultsToNull(): void
    {
        $response = new AiResponse(content: 'ok', modelId: 'm', providerIdentifier: 'p');

        self::assertNull($response->structured);
    }

    #[Test]
    public function aiResponseAcceptsStructuredPayload(): void
    {
        $response = new AiResponse(
            content: '{"verdict":"pass"}',
            modelId: 'm',
            providerIdentifier: 'p',
            structured: ['verdict' => 'pass'],
        );

        self::assertSame(['verdict' => 'pass'], $response->structured);
    }
}
