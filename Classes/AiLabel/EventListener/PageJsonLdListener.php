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

namespace NITSAN\NsT3AF\AiLabel\EventListener;

use NITSAN\NsT3AF\AiLabel\Domain\Involvement;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelSettingsService;
use NITSAN\NsT3AF\AiLabel\Service\ConfirmationService;
use NITSAN\NsT3AF\AiLabel\Service\TextRuleEngine;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

/**
 * Page JSON-LD for AI-labelled public-interest text (Art. 50(2) surface).
 */
final class PageJsonLdListener
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TextRuleEngine $textRuleEngine,
        private readonly ConfirmationService $confirmationService,
        private readonly AiLabelSettingsService $settingsService,
    ) {}

    public function __invoke(AfterCacheableContentIsGeneratedEvent $event): void
    {
        if ((string) ($this->settingsService->all()['machineReadable'] ?? 'iptc') !== 'iptc_jsonld') {
            return;
        }

        $request = $event->getRequest();
        $pageId = (int) ($request->getAttribute('routing')?->getPageId() ?? 0);
        if ($pageId <= 0) {
            return;
        }

        $page = $this->connectionPool->getConnectionForTable('pages')
            ->select(['*'], 'pages', ['uid' => $pageId])
            ->fetchAssociative();

        if (!is_array($page)) {
            return;
        }

        $involvement = Involvement::tryFrom((string) ($page['tx_nst3af_ailabel_involvement'] ?? ''))
            ?? Involvement::NotReviewed;
        $confirmed = $this->confirmationService->isConfirmed('pages', $pageId);
        $decision = $this->textRuleEngine->decide(
            $involvement,
            (bool) ($page['tx_nst3af_ailabel_public_interest'] ?? false),
            (bool) ($page['tx_nst3af_ailabel_human_review'] ?? false),
            (string) ($page['tx_nst3af_ailabel_responsible_person'] ?? ''),
            $confirmed,
        );
        if (!$decision->showLabel) {
            return;
        }

        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => (string) ($page['title'] ?? ''),
            'additionalProperty' => [
                '@type' => 'PropertyValue',
                'name' => 'aiContentDeclaration',
                'value' => 'AI-generated or AI-modified content',
            ],
        ];

        $script = '<script type="application/ld+json">'
            . json_encode($jsonLd, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            . '</script>';
        $this->appendContent($event, $script);
    }

    /**
     * Place JSON-LD inside the document (before </body>, else </html>).
     * Appending after the full HTML string puts the script after </html>.
     */
    public static function insertIntoDocument(string $html, string $script): string
    {
        foreach (['</body>', '</html>'] as $needle) {
            $pos = strripos($html, $needle);
            if ($pos !== false) {
                return substr($html, 0, $pos) . $script . substr($html, $pos);
            }
        }

        return $html . $script;
    }

    /**
     * v13: $event->getController()->content. v14: $event->getContent()/setContent().
     */
    private function appendContent(object $event, string $script): void
    {
        if (method_exists($event, 'getContent') && method_exists($event, 'setContent')) {
            $event->setContent(self::insertIntoDocument((string) $event->getContent(), $script));

            return;
        }

        if (method_exists($event, 'getController')) {
            $controller = $event->getController();
            if (is_object($controller) && isset($controller->content) && is_string($controller->content)) {
                $controller->content = self::insertIntoDocument($controller->content, $script);
            }
        }
    }
}
