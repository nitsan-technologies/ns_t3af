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

use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Logging\AuditLogger;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolLogService;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/** @internal */
final class ErrorHandlingProxy
{
    public function __construct(
        private readonly object $inner,
        private readonly LoggerInterface $logger,
        private readonly AuditLogger $auditLogger,
        private readonly McpToolLogService $toolLogService,
        private readonly string $type,
    ) {}

    /** @param list<mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        $startTime = hrtime(true);

        try {
            $result = $this->inner->$name(...$arguments);
            $executionTimeMs = (int) ((hrtime(true) - $startTime) / 1000000);
            $handlerName = $this->getHandlerName();
            $this->auditLogger->logSuccess($handlerName, $this->type, $arguments, $executionTimeMs);
            $this->toolLogService->logSuccess($this->inner, $this->type, $arguments, $executionTimeMs);

            return $result;
        } catch (ToolCallException $e) {
            $executionTimeMs = (int) ((hrtime(true) - $startTime) / 1000000);
            $handlerName = $this->getHandlerName();
            $this->auditLogger->logFailure($handlerName, $this->type, $arguments, $executionTimeMs, $e->getMessage());
            $this->toolLogService->logFailure($this->inner, $this->type, $arguments, $executionTimeMs, $e->getMessage());

            throw $e;
        } catch (\Throwable $e) {
            $executionTimeMs = (int) ((hrtime(true) - $startTime) / 1000000);
            $handlerName = $this->getHandlerName();
            $this->auditLogger->logFailure($handlerName, $this->type, $arguments, $executionTimeMs, $e->getMessage());
            $this->toolLogService->logFailure($this->inner, $this->type, $arguments, $executionTimeMs, $e->getMessage());
            $this->logger->error($handlerName . ' failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    private function getHandlerName(): string
    {
        return (new ReflectionClass($this->inner))->getShortName();
    }
}
