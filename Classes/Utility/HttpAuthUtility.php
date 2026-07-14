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

namespace NITSAN\NsT3AF\Utility;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * HttpAuthUtility
 *
 * Utility class for handling HTTP authentication (Basic Auth) in AI requests
 */
class HttpAuthUtility
{
    private array $extConf;
    private string $extensionKey;
    private ClientInterface $client;
    private ServerRequestFactory $serverRequestFactory;

    public function __construct(
        string $extensionKey = 'ns_t3af',
        ?ClientInterface $client = null,
        ?ServerRequestFactory $serverRequestFactory = null,
    ) {
        $this->extensionKey = $extensionKey;
        $this->extConf = AiUniverseUtilityHelper::getExtensionConf($this->extensionKey);
        $this->client = $client ?? GeneralUtility::makeInstance(ClientInterface::class);
        $this->serverRequestFactory = $serverRequestFactory ?? GeneralUtility::makeInstance(ServerRequestFactory::class);
    }

    /**
     * Fetch content from URL with optional Basic Auth retry
     *
     * @param string $url URL to fetch
     * @return string Fetched content
     * @throws \Exception
     */
    public function fetchContentFromUrl(string $url): string
    {
        $request = $this->serverRequestFactory->createServerRequest('GET', $url);
        $response = $this->client->sendRequest($request);
        $fetchedContent = $response->getBody()->getContents();

        // If URL is not accessible and basic auth is enabled, retry with basic authentication
        if ($response->getStatusCode() == 401 || $response->getStatusCode() == 403) {
            if ($this->isBasicAuthEnabled()) {
                $authString = base64_encode(
                    $this->extConf['basicAuthUsername'] . ':' . $this->extConf['basicAuthPassword'],
                );
                $requestWithAuth = $this->serverRequestFactory->createServerRequest('GET', $url)
                    ->withHeader('Authorization', 'Basic ' . $authString);

                $responseWithAuth = $this->client->sendRequest($requestWithAuth);
                if ($responseWithAuth->getStatusCode() == 200) {
                    $fetchedContent = $responseWithAuth->getBody()->getContents();
                }
            }
        }

        if (empty($fetchedContent) && $response->getStatusCode() !== 200) {
            throw new \Exception('Failed to fetch content from URL: ' . $url);
        }

        return $fetchedContent;
    }

    /**
     * Add Basic Auth header to a request if enabled
     *
     * @param ServerRequestInterface $request
     * @return ServerRequestInterface
     */
    public function addAuthHeader(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($this->isBasicAuthEnabled()) {
            $authString = base64_encode(
                $this->extConf['basicAuthUsername'] . ':' . $this->extConf['basicAuthPassword'],
            );
            return $request->withHeader('Authorization', 'Basic ' . $authString);
        }

        return $request;
    }

    /**
     * Check if Basic Auth is enabled
     *
     * @return bool
     */
    public function isBasicAuthEnabled(): bool
    {
        return !empty($this->extConf['basicAuthEnabled'])
            && !empty($this->extConf['basicAuthUsername'])
            && !empty($this->extConf['basicAuthPassword']);
    }

    /**
     * Get Basic Auth credentials
     *
     * @return array|null Returns ['username' => ..., 'password' => ...] or null if not enabled
     */
    public function getBasicAuthCredentials(): ?array
    {
        if ($this->isBasicAuthEnabled()) {
            return [
                'username' => $this->extConf['basicAuthUsername'],
                'password' => $this->extConf['basicAuthPassword'],
            ];
        }

        return null;
    }
}
