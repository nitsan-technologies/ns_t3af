/**
 * Credits toolbar dropdown navigation (System Information toolbar pattern).
 *
 * @see vendor/typo3/cms-backend/Resources/Public/JavaScript/toolbar/system-information-menu.js
 * @see vendor/typo3/cms-backend/Resources/Private/Templates/ToolbarItems/SystemInformationDropDown.html
 */

import RegularEvent from '@typo3/core/event/regular-event.js';
import TriggerRequest from '@typo3/backend/event/trigger-request.js';
import viewport from '@typo3/backend/viewport.js';
import DocumentService from '@typo3/core/document-service.js';
import { ModuleStateStorage } from '@typo3/backend/storage/module-state-storage.js';

const MODULE_STATE_TYPE = 'web';
const PAGE_ID_PARAM = 'id';
const LINK_SELECTOR = 'a[data-aiu-credits-module-url]';

/**
 * @param {string} urlString
 * @returns {string}
 */
function buildTargetUrl(urlString) {
  const url = new URL(urlString, window.location.href);
  const state = ModuleStateStorage.current(MODULE_STATE_TYPE);
  const pageId = Number.parseInt(state?.identifier || '0', 10);
  if (Number.isFinite(pageId) && pageId > 0) {
    url.searchParams.set(PAGE_ID_PARAM, String(pageId));
  }

  return `${url.pathname}${url.search}${url.hash}`;
}

/**
 * @param {Event} event
 * @param {HTMLAnchorElement} link
 */
function handleProvidersLinkClick(event, link) {
  const moduleUrl = link.dataset.aiuCreditsModuleUrl || '';
  const moduleName = link.dataset.aiuCreditsModule || 't3af_dashboard';
  if (moduleUrl === '') {
    return;
  }

  event.preventDefault();
  event.stopPropagation();

  viewport.ContentContainer.setUrl(
    buildTargetUrl(moduleUrl),
    new TriggerRequest('typo3.aiuCreditsOpenProviders'),
    moduleName,
  );
}

function initialize() {
  new RegularEvent('click', handleProvidersLinkClick).delegateTo(document, LINK_SELECTOR);

  if (typeof viewport?.Topbar?.Toolbar?.registerEvent === 'function') {
    viewport.Topbar.Toolbar.registerEvent(() => {
      // Topbar HTML was replaced; event delegation on document still applies.
    });
  }
}

DocumentService.ready().then(initialize);
