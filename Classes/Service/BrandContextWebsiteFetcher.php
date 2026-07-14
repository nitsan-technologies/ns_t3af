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

namespace NITSAN\NsT3AF\Service;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Fetches public website HTML and strips it to plain text for brand research.
 *
 * Adapted from the T3AI content service fetch + strip pattern.
 *
 * @internal
 */
final class BrandContextWebsiteFetcher
{
    private const MAX_CONTENT_CHARS = 12000;
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
    ) {}

    /**
     * @return array{content: string, fetched: bool, notice: ?string}
     */
    public function fetchText(string $url): array
    {
        $normalizedUrl = $this->normalizeUrl($url);
        if ($normalizedUrl === null) {
            return [
                'content' => '',
                'fetched' => false,
                'notice' => 'Invalid website URL. Use http:// or https://.',
            ];
        }

        try {
            $request = $this->requestFactory->createRequest('GET', $normalizedUrl)
                ->withHeader('User-Agent', 'AI-Universe-BrandContext/1.0 (+https://t3planet.de)')
                ->withHeader('Accept', 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8');

            $response = $this->client->sendRequest($request);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return [
                    'content' => '',
                    'fetched' => false,
                    'notice' => 'Could not fetch the website. Paste homepage content or fill fields manually.',
                ];
            }

            $html = $response->getBody()->getContents();
            $text = $this->stripPageContent($html);
            $text = $this->normalizeWhitespace($text);
            if ($text === '') {
                return [
                    'content' => '',
                    'fetched' => false,
                    'notice' => 'The website returned no readable text. Fill fields manually.',
                ];
            }

            return [
                'content' => $this->truncate($text, self::MAX_CONTENT_CHARS),
                'fetched' => true,
                'notice' => null,
            ];
        } catch (\Throwable) {
            return [
                'content' => '',
                'fetched' => false,
                'notice' => 'Could not fetch the website. Paste homepage content or fill fields manually.',
            ];
        }
    }

    public function stripPageContent(string $pageContent): string
    {
        if (preg_match('~<body[^>]*>(.*?)</body>~si', $pageContent, $body)) {
            $pageContent = $body[0];
        }
        $pageContent = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $pageContent) ?? $pageContent;
        $pageContent = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $pageContent) ?? $pageContent;
        $pageContent = preg_replace('#<footer(.*?)>(.*?)</footer>#is', '', $pageContent) ?? $pageContent;
        $pageContent = preg_replace('#<nav(.*?)>(.*?)</nav>#is', '', $pageContent) ?? $pageContent;

        return strip_tags($pageContent);
    }

    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function truncate(string $value, int $maxLength): string
    {
        if ($maxLength <= 0 || strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength);
    }
}
