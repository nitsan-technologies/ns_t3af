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

namespace NITSAN\NsT3AF\Mcp\Http;

use Mcp\Schema\Wire\McpHeader;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * CORS headers aligned with mcp/sdk {@see \Mcp\Server\Transport\Http\Middleware\CorsMiddleware} defaults.
 *
 * TYPO3 wraps MCP responses before the SDK transport runs; preflight OPTIONS must advertise
 * Mcp-Method, Mcp-Name, and Mcp-Param-* or browser clients fail on 2026-07-28.
 */
final class McpCorsHeaders
{
    /** @var list<string> */
    private const BASE_ALLOW_HEADERS = [
        'Accept',
        'Authorization',
        'Content-Type',
        'Last-Event-ID',
        StreamableHttpTransport::SESSION_HEADER,
        StreamableHttpTransport::PROTOCOL_VERSION_HEADER,
        McpHeader::METHOD,
        McpHeader::NAME,
    ];

    /** @var list<string> lowercased prefixes (no trailing *) */
    private const ALLOW_HEADER_PREFIXES = [
        'mcp-param-',
    ];

    public static function apply(ResponseInterface $response, ?ServerRequestInterface $request = null): ResponseInterface
    {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Expose-Headers', StreamableHttpTransport::SESSION_HEADER . ', WWW-Authenticate')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        if ($request !== null && self::isPreflight($request)) {
            $response = $response
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', implode(', ', self::resolveAllowedHeaders($request)));
        }

        return $response;
    }

    private static function isPreflight(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'OPTIONS'
            && $request->hasHeader('Access-Control-Request-Method');
    }

    /**
     * @return list<string>
     */
    private static function resolveAllowedHeaders(ServerRequestInterface $request): array
    {
        $headers = self::BASE_ALLOW_HEADERS;
        foreach (explode(',', $request->getHeaderLine('Access-Control-Request-Headers')) as $requested) {
            $requested = trim($requested);
            if ($requested === '') {
                continue;
            }
            $lower = strtolower($requested);
            foreach (self::ALLOW_HEADER_PREFIXES as $prefix) {
                if (str_starts_with($lower, $prefix)) {
                    $headers[] = $requested;
                    break;
                }
            }
        }

        return $headers;
    }
}
