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

namespace NITSAN\NsT3AF\Exception;

/**
 * Thrown when an {@see \NITSAN\NsT3AF\Provider\Contract\AdapterInterface}
 * cannot fulfil a runtime request — typically because the underlying provider
 * SDK (Symfony AI Platform bridge, custom client, …) is not installed, or its
 * factory cannot be resolved for the configured vendor.
 *
 * Adapters MUST NOT let this exception propagate out of
 * {@see \NITSAN\NsT3AF\Provider\Contract\AdapterInterface::testConnection()};
 * they are required to wrap the failure in a
 * {@see \NITSAN\NsT3AF\Provider\Contract\VerifyResult::failure()} instead.
 */
final class AdapterRuntimeException extends \RuntimeException implements AiUniverseException {}
