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

namespace NITSAN\NsT3AF\ViewHelpers;

use Stringable;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractConditionViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\InvalidArgumentValueException;

final class ContainsViewHelper extends AbstractConditionViewHelper
{
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('value', 'mixed', 'The value to check for (needle)', true);
        $this->registerArgument('subject', 'mixed', 'The string or array that might contain the value (haystack)', true);
    }

    public static function verdict(array $arguments, RenderingContextInterface $renderingContext): bool
    {
        if (is_scalar($arguments['subject'])) {
            return static::stringContains((string) $arguments['subject'], $arguments['value']);
        }

        return static::arrayContains($arguments['subject'], $arguments['value']);
    }

    private static function stringContains(string $subject, mixed $value): bool
    {
        if (!is_scalar($value) && !$value instanceof Stringable) {
            $givenType = get_debug_type($value);
            throw new InvalidArgumentValueException(
                'If the argument "subject" is a string, then "value" must be scalar, but it is of type "'
                . $givenType . '" in view helper "' . self::class . '".',
                1754978401,
            );
        }

        return str_contains($subject, (string) $value);
    }

    private static function arrayContains(mixed $subject, mixed $value): bool
    {
        if (!is_iterable($subject)) {
            $givenType = get_debug_type($subject);
            throw new InvalidArgumentValueException(
                'The argument "subject" must be either a scalar value or an array/iterator, but is of type "'
                . $givenType . '" in view helper "' . self::class . '".',
                1754978402,
            );
        }

        return in_array($value, iterator_to_array($subject), true);
    }
}
