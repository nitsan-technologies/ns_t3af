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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Custom\Fixtures;

use const JSON_THROW_ON_ERROR;

/**
 * Plain custom-tool fixture: no MCP interface, no #[McpTool] attribute, no DI tag.
 * Represents the class a developer would register via the MCP Tools backend UI.
 */
final class SampleCustomTool
{
    /**
     * @param string $country Destination ISO country code, e.g. "DE".
     * @param float  $weight  Parcel weight in kilograms.
     */
    public function execute(string $country, float $weight = 1.0): string
    {
        return json_encode([
            'country' => $country,
            'weight' => $weight,
            'cost' => round($weight * 4.5, 2),
        ], JSON_THROW_ON_ERROR);
    }
}
