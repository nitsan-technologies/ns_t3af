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

namespace NITSAN\NsT3AF\Backend\ToolbarItems;

use NITSAN\NsT3AF\Credits\Service\CreditOverviewLineService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * T3Planet Credits balance in the global backend toolbar (scaffold header).
 *
 * @internal
 */
final class CreditBalanceToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly CreditOverviewLineService $creditOverviewLine,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function checkAccess(): bool
    {
        return $this->creditOverviewLine->resolveBadge() !== null;
    }

    public function getItem(): string
    {
        $badge = $this->creditOverviewLine->resolveBadge();
        if ($badge === null) {
            return '';
        }

        $this->pageRenderer->addCssFile('EXT:ns_t3af/Resources/Public/Css/toolbar-credit.css');

        $view = $this->backendViewFactory->create($this->request, ['nitsan/ns-t3af']);
        $view->assign('badge', $badge);

        return $view->render('ToolbarItems/CreditBalanceToolbarItem');
    }

    public function hasDropDown(): bool
    {
        return false;
    }

    public function getDropDown(): string
    {
        return '';
    }

    /**
     * @return array<string, string>
     */
    public function getAdditionalAttributes(): array
    {
        return ['class' => 'toolbar-item-nst3af-credit-balance'];
    }

    public function getIndex(): int
    {
        return 22;
    }
}
