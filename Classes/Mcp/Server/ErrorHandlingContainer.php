<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


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

namespace NITSAN\NsT3AF\Mcp\Server;

use NITSAN\NsT3AF\Mcp\Logging\AuditLogger;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolLogService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Decorating container that wraps tool instances with centralized error handling.
 * Converts uncaught \Throwable exceptions to ToolCallException,
 * removing the need for try/catch boilerplate in every tool class.
 */
readonly class ErrorHandlingContainer implements ContainerInterface
{
    /** @param array<class-string, 'tool'> $handlerTypes */
    public function __construct(
        private ContainerInterface $inner,
        private LoggerInterface $logger,
        private AuditLogger $auditLogger,
        private McpToolLogService $toolLogService,
        private array $handlerTypes,
    ) {}

    public function get(string $id): mixed
    {
        $instance = $this->inner->get($id);

        $type = $this->handlerTypes[$id] ?? null;
        if ($type === null || !is_object($instance)) {
            return $instance;
        }

        return new ErrorHandlingProxy($instance, $this->logger, $this->auditLogger, $this->toolLogService, $type);
    }

    public function has(string $id): bool
    {
        return $this->inner->has($id);
    }
}
