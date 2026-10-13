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

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ActionGroup;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Filelist\Event\ProcessFileListActionsEvent;

/**
 * File module row action deep link into the AI Label media tab.
 *
 * TYPO3 v14+ uses Buttons API ({@see ProcessFileListActionsEvent::setAction()});
 * v12/v13 still use HTML action-item arrays.
 */
final class ProcessFileListActionsListener
{
    private const ACTION_NAME = 'nst3af-open-ai-label';

    private const LABEL = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:ailabel.file_module.open';

    public function __construct(
        private readonly UriBuilder $uriBuilder,
        private readonly IconFactory $iconFactory,
    ) {}

    public function __invoke(ProcessFileListActionsEvent $event): void
    {
        if (!$event->isFile()) {
            return;
        }

        $file = $event->getResource();
        if (!$file instanceof File) {
            return;
        }

        $uid = (int) ($file->getMetaData()->get()['uid'] ?? 0);
        if ($uid <= 0) {
            return;
        }

        $url = (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_label.media', [
            'fileMetadataUid' => $uid,
            'folder' => dirname($file->getIdentifier()) . '/',
        ]);
        $title = $this->translate(self::LABEL);

        // v14+: Buttons API (breaking #107884). v12/v13: HTML action items.
        // Gate on major version — method_exists() is always true under T3 14 PHPStan.
        if ((new Typo3Version())->getMajorVersion() >= 14) {
            $button = GeneralUtility::makeInstance(ComponentFactory::class)
                ->createLinkButton()
                ->setHref($url)
                ->setTitle($title)
                ->setIcon($this->iconFactory->getIcon('actions-view', IconSize::SMALL));
            $event->setAction($button, self::ACTION_NAME, ActionGroup::secondary);

            return;
        }

        $actions = $event->getActionItems();
        $actions[self::ACTION_NAME] = sprintf(
            '<a href="%s" class="btn btn-default" title="%s">%s</a>',
            htmlspecialchars($url, ENT_QUOTES | ENT_HTML5),
            htmlspecialchars($title, ENT_QUOTES | ENT_HTML5),
            $this->renderSmallIcon('actions-view'),
        );
        $event->setActionItems($actions);
    }

    private function translate(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if ($languageService instanceof LanguageService) {
            return $languageService->sL($key);
        }

        return 'Open in AI Label';
    }

    private function renderSmallIcon(string $identifier): string
    {
        if (enum_exists(IconSize::class)) {
            return $this->iconFactory->getIcon($identifier, IconSize::SMALL)->render();
        }

        // TYPO3 12: getIcon() still accepts legacy string size.
        $icon = (new \ReflectionMethod($this->iconFactory, 'getIcon'))
            ->invoke($this->iconFactory, $identifier, 'small');

        return is_object($icon) && method_exists($icon, 'render') ? (string) $icon->render() : '';
    }
}
