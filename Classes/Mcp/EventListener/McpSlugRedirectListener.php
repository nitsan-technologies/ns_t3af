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

namespace NITSAN\NsT3AF\Mcp\EventListener;

use NITSAN\NsT3AF\Mcp\Service\McpSlugRedirectGuard;
use TYPO3\CMS\Redirects\RedirectUpdate\RedirectSourceCollection;

/**
 * During MCP records_apply / write_table: suppress auto-redirects for pages with no site,
 * and remember created redirects for the tool reply.
 */
final class McpSlugRedirectListener
{
    private const EVENT_CHANGE_ITEM_CREATED = 'TYPO3\\CMS\\Redirects\\Event\\SlugRedirectChangeItemCreatedEvent';

    private const EVENT_REDIRECT_PERSISTED = 'TYPO3\\CMS\\Redirects\\Event\\AfterAutoCreateRedirectHasBeenPersistedEvent';

    public function __construct(
        private readonly McpSlugRedirectGuard $guard,
    ) {}

    public function clearSourcesForSkippedPages(object $event): void
    {
        $eventClass = self::EVENT_CHANGE_ITEM_CREATED;
        if (!$this->guard->isActive() || !class_exists($eventClass) || !$event instanceof $eventClass) {
            return;
        }

        $item = $event->getSlugRedirectChangeItem();
        if (!$this->guard->shouldSkipPage($item->getPageId())
            && !$this->guard->shouldSkipPage($item->getDefaultLanguagePageId())
        ) {
            return;
        }

        if (!class_exists(RedirectSourceCollection::class)) {
            return;
        }

        $event->setSlugRedirectChangeItem(
            $item->withSourcesCollection(new RedirectSourceCollection()),
        );
    }

    public function rememberCreatedRedirect(object $event): void
    {
        $eventClass = self::EVENT_REDIRECT_PERSISTED;
        if (!$this->guard->isActive() || !class_exists($eventClass) || !$event instanceof $eventClass) {
            return;
        }

        $this->guard->rememberCreated($event->getRedirectRecord());
    }
}
