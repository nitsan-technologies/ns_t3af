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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Mcp\Server;

use const JSON_THROW_ON_ERROR;

use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Logging\AuditLogger;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolLogService;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyAudit;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/** @internal */
final class ErrorHandlingProxy
{
    private const UNEXPECTED_ERROR_MESSAGE = 'The operation failed due to an unexpected server error. See the TYPO3 log for details.';

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
            // Soft {"error": "..."} success payloads must become isError:true for agents.
            $errorMessage = McpPlaygroundService::errorMessageOf($result);
            if ($errorMessage !== null) {
                $code = self::errorCodeOf($result) ?? 1790700001;
                // Throw so the catch below logs once and MCP marks isError.
                throw new ToolCallException(self::errorPayload($errorMessage, $code), $code);
            }
            $this->auditLogger->logSuccess($handlerName, $this->type, $arguments, $executionTimeMs);
            $this->toolLogService->logSuccess($this->inner, $this->type, $arguments, $executionTimeMs);

            return $result;
        } catch (ToolCallException $e) {
            $executionTimeMs = (int) ((hrtime(true) - $startTime) / 1000000);
            $handlerName = $this->getHandlerName();
            // A refused batch is already in the log, written by the batch engine without field values.
            if (!in_array($e->getCode(), RecordsApplyAudit::AUDITED_ERROR_CODES, true)) {
                $this->auditLogger->logFailure($handlerName, $this->type, $arguments, $executionTimeMs, $e->getMessage());
            }
            $this->toolLogService->logFailure($this->inner, $this->type, $arguments, $executionTimeMs, $e->getMessage());

            throw self::ensureCodedToolCallException($e);
        } catch (\InvalidArgumentException $e) {
            $executionTimeMs = (int) ((hrtime(true) - $startTime) / 1000000);
            $handlerName = $this->getHandlerName();
            $this->auditLogger->logFailure($handlerName, $this->type, $arguments, $executionTimeMs, $e->getMessage());
            $this->toolLogService->logFailure($this->inner, $this->type, $arguments, $executionTimeMs, $e->getMessage());

            $code = (int) $e->getCode();
            if ($code === 0) {
                $code = 1790700000;
            }

            throw self::ensureCodedToolCallException(new ToolCallException(self::errorPayload($e->getMessage(), $code), $code, $e));
        } catch (\Throwable $e) {
            $executionTimeMs = (int) ((hrtime(true) - $startTime) / 1000000);
            $handlerName = $this->getHandlerName();
            $this->auditLogger->logFailure($handlerName, $this->type, $arguments, $executionTimeMs, $e->getMessage());
            $this->toolLogService->logFailure($this->inner, $this->type, $arguments, $executionTimeMs, $e->getMessage());
            $this->logger->error($handlerName . ' failed', ['exception' => $e]);

            $code = (int) $e->getCode();
            if ($code === 0) {
                $code = 1790700000;
            }

            throw new ToolCallException(self::errorPayload(self::UNEXPECTED_ERROR_MESSAGE, $code), $code, $e);
        }
    }

    private static function ensureCodedToolCallException(ToolCallException $exception): ToolCallException
    {
        $code = (int) $exception->getCode();
        if ($code === 0) {
            $code = 1790700000;
        }
        $message = $exception->getMessage();
        $decoded = json_decode($message, true);
        if (is_array($decoded) && isset($decoded['code']) && is_numeric($decoded['code'])) {
            return $exception;
        }
        if (is_array($decoded)) {
            $decoded['code'] = $code;

            return new ToolCallException(json_encode($decoded, JSON_THROW_ON_ERROR), $code, $exception);
        }

        return new ToolCallException(self::errorPayload($message, $code), $code, $exception);
    }

    /** @param mixed $result */
    private static function errorCodeOf(mixed $result): ?int
    {
        if (is_string($result)) {
            $decoded = json_decode($result, true);
            $result = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($result) || !isset($result['code']) || !is_numeric($result['code'])) {
            return null;
        }

        return (int) $result['code'];
    }

    private static function errorPayload(string $message, int $code): string
    {
        $decoded = json_decode($message, true);
        if (is_array($decoded)) {
            $decoded['code'] = $code;
            if (!isset($decoded['error']) || !is_string($decoded['error']) || $decoded['error'] === '') {
                // Structured batch payloads (ok/written/…) keep their shape; only bare maps get error=.
                if (!isset($decoded['ok']) && !isset($decoded['written'])) {
                    $decoded['error'] = $message;
                }
            }

            return json_encode($decoded, JSON_THROW_ON_ERROR);
        }

        return json_encode(['error' => $message, 'code' => $code], JSON_THROW_ON_ERROR);
    }

    private function getHandlerName(): string
    {
        return (new ReflectionClass($this->inner))->getShortName();
    }
}
