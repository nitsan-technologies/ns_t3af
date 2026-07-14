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

namespace NITSAN\NsT3AF\Mcp\OAuth;

use const ENT_QUOTES;

use NITSAN\NsT3AF\Mcp\Service\McpPathProvider;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Renders the MCP OAuth authorization login page (backend user credentials).
 *
 * Visual design follows TYPO3 backend login (Rise background, orange highlight).
 * Respects instance-level backend login customization when configured.
 */
final readonly class OAuthAuthorizePageRenderer
{
    public function __construct(
        private McpPathProvider $pathProvider,
        private OAuthAuthorizeAssetProvider $assetProvider,
    ) {}

    /** @param array<string, mixed> $params */
    public function render(string $clientName, array $params, string $csrfToken, string $errorMessage = ''): string
    {
        $clientNameEscaped = htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8');
        $formAction = htmlspecialchars($this->pathProvider->getAuthorizePath(), ENT_QUOTES, 'UTF-8');
        $csrfTokenEscaped = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
        $stylesheetUri = htmlspecialchars($this->assetProvider->stylesheetUri(), ENT_QUOTES, 'UTF-8');
        $logoUri = htmlspecialchars($this->assetProvider->logoUri(), ENT_QUOTES, 'UTF-8');
        $themeStyles = $this->buildThemeStyles();
        $errorHtml = $this->buildErrorHtml($errorMessage);
        $hiddenFields = $this->buildHiddenFields($params);
        $footnote = $this->assetProvider->footnote();
        $footnoteHtml = $footnote !== ''
            ? '<p class="aiu-oauth-authorize__footnote">' . htmlspecialchars($footnote, ENT_QUOTES, 'UTF-8') . '</p>'
            : '';

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <meta name="robots" content="noindex, nofollow">
                <title>AI Foundation MCP — Authorization</title>
                <link rel="icon" href="{$logoUri}" type="image/svg+xml">
                <link rel="apple-touch-icon" href="{$logoUri}">
                <link rel="stylesheet" href="{$stylesheetUri}">
                <style>{$themeStyles}</style>
            </head>
            <body class="aiu-oauth-authorize">
                <div class="aiu-oauth-authorize__inner">
                    <div class="aiu-oauth-authorize__container">
                        <div class="aiu-oauth-authorize__wrap">
                            <div class="aiu-oauth-authorize__card">
                                <header class="aiu-oauth-authorize__header">
                                    <img class="aiu-oauth-authorize__logo" src="{$logoUri}" width="64" height="64" alt="AI Foundation">
                                    <h1 class="aiu-oauth-authorize__title">Authorize Application</h1>
                                </header>
                                <main class="aiu-oauth-authorize__body">
                                    <p class="aiu-oauth-authorize__lead">
                                        <span class="aiu-oauth-authorize__client">{$clientNameEscaped}</span>
                                        is requesting access to your TYPO3 backend account.
                                    </p>
                                    {$errorHtml}
                                    <form method="post" action="{$formAction}" autocomplete="off">
                                        {$hiddenFields}
                                        <input type="hidden" name="csrf_token" value="{$csrfTokenEscaped}">
                                        <div class="aiu-oauth-authorize__field">
                                            <label class="aiu-oauth-authorize__label" for="username">Username</label>
                                            <input class="aiu-oauth-authorize__input" type="text" id="username" name="username" required autofocus autocomplete="off">
                                        </div>
                                        <div class="aiu-oauth-authorize__field">
                                            <label class="aiu-oauth-authorize__label" for="password">Password</label>
                                            <input class="aiu-oauth-authorize__input" type="password" id="password" name="password" required autocomplete="off">
                                        </div>
                                        <button class="aiu-oauth-authorize__submit" type="submit">Authorize</button>
                                    </form>
                                </main>
                                <footer class="aiu-oauth-authorize__footer">
                                    AI Foundation MCP Server
                                </footer>
                            </div>
                        </div>
                    </div>
                    {$footnoteHtml}
                </div>
            </body>
            </html>
            HTML;
    }

    private function buildThemeStyles(): string
    {
        $backgroundUri = $this->assetProvider->backgroundUri();
        $highlight = $this->assetProvider->highlightColor();

        $css = '';
        if ($backgroundUri !== '') {
            $css .= '--aiu-oauth-bg-image: url("' . GeneralUtility::sanitizeCssVariableValue($backgroundUri) . '");';
        }

        if ($highlight !== '') {
            $safeHighlight = GeneralUtility::sanitizeCssVariableValue($highlight);
            $css .= '--aiu-oauth-highlight: ' . $safeHighlight . ';';
            $css .= '--aiu-oauth-highlight-hover: hsl(from ' . $safeHighlight . ' h s calc(l - 5));';
            $css .= '--aiu-oauth-highlight-focus: hsl(from ' . $safeHighlight . ' h s calc(l - 10));';
        }

        return $css !== '' ? '.aiu-oauth-authorize {' . $css . '}' : '';
    }

    /** @param array<string, mixed> $params */
    private function buildHiddenFields(array $params): string
    {
        $html = '';
        foreach (['client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state'] as $field) {
            $rawValue = $params[$field] ?? '';
            $value = htmlspecialchars(is_string($rawValue) ? $rawValue : '', ENT_QUOTES, 'UTF-8');
            $html .= sprintf('<input type="hidden" name="%s" value="%s">', $field, $value);
        }

        return $html;
    }

    private function buildErrorHtml(string $errorMessage): string
    {
        if ($errorMessage === '') {
            return '';
        }

        $escaped = htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <div class="aiu-oauth-authorize__alert" role="alert">
                <p class="aiu-oauth-authorize__alert-title">Authentication failed</p>
                <p class="aiu-oauth-authorize__alert-message">{$escaped}</p>
            </div>
            HTML;
    }
}
