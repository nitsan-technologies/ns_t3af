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

namespace NITSAN\NsT3AF\EventListener;

use NITSAN\NsT3AF\Agent\Service\AgentAvailabilityService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Controller\Event\AfterBackendPageRenderEvent;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Injects the AI Agent mount point and assets into every backend page.
 *
 * @internal
 */
final readonly class AfterBackendPageRenderListener
{
    public function __construct(
        private AgentAvailabilityService $agentAvailability,
        private BackendViewFactory $backendViewFactory,
        private PageRenderer $pageRenderer,
    ) {}

    #[AsEventListener(event: AfterBackendPageRenderEvent::class)]
    public function __invoke(AfterBackendPageRenderEvent $event): void
    {
        if (!$this->agentAvailability->shouldRenderUi()) {
            return;
        }

        $request = $this->resolveRequest();
        if (!$request instanceof ServerRequestInterface) {
            return;
        }

        $this->pageRenderer->addCssFile('EXT:ns_t3af/Resources/Public/Css/module/agent.css');
        $this->pageRenderer->getJavaScriptRenderer()->addJavaScriptModuleInstruction(
            JavaScriptModuleInstruction::create('@nitsan/nst3af/agent.js')->invoke('boot'),
        );

        $view = $this->backendViewFactory->create($request, ['nitsan/ns-t3af']);
        $mountPoint = $view->render('Agent/MountPoint');

        $event->setContent($event->getContent() . $mountPoint);
    }

    private function resolveRequest(): ?ServerRequestInterface
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;

        return $request instanceof ServerRequestInterface ? $request : null;
    }
}
