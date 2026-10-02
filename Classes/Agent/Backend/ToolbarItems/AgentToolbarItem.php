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

namespace NITSAN\NsT3AF\Agent\Backend\ToolbarItems;

use NITSAN\NsT3AF\Agent\Service\AgentAvailabilityService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Toolbar button opening the AI Agent modal.
 *
 * @internal
 */
final class AgentToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly AgentAvailabilityService $agentAvailability,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function checkAccess(): bool
    {
        return $this->agentAvailability->shouldRenderUi();
    }

    public function getItem(): string
    {
        if (!$this->checkAccess()) {
            return '';
        }

        $this->pageRenderer->addCssFile('EXT:ns_t3af/Resources/Public/Css/module/agent.css');
        $this->pageRenderer->getJavaScriptRenderer()->addJavaScriptModuleInstruction(
            JavaScriptModuleInstruction::create('@nitsan/nst3af/agent.js')->invoke('boot'),
        );

        $view = $this->backendViewFactory->create($this->request, ['nitsan/ns-t3af']);

        return $view->render('Agent/ToolbarItem');
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
        return [
            'class' => 'toolbar-item-nst3af-agent',
            'data-nst3af-agent-toolbar' => '1',
        ];
    }

    public function getIndex(): int
    {
        return 5;
    }
}
