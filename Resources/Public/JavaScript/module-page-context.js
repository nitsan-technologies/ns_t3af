/**
 * Keeps the AI Foundation module URL in sync with the backend page tree selection.
 *
 * TYPO3 restores the highlighted page from ModuleStateStorage but does not add
 * `id` to the module URL until the user clicks the page again. Site-scoped tabs
 * (AI Providers, AI Features, AI Prompts, AI Context) need that query param.
 *
 * The id is propagated WITHOUT a navigation during page load: tab links are
 * stamped in place and the URL is corrected via `history.replaceState`. A real
 * reload only happens for a site-scoped view that actually needs the id
 * server-side, and even then it is deferred until assets have finished loading
 * so in-flight CSS/JS requests are never aborted (which previously caused
 * styles/scripts to go missing until a hard reload).
 */

import { ModuleStateStorage } from '@typo3/backend/storage/module-state-storage.js';

const MODULE_STATE_TYPE = 'web';
const PAGE_ID_PARAM = 'id';
const MODULE_SHELL = '.aiu-module-shell';
const REQUIRES_PAGE_ATTR = 'data-aiu-requires-page-id';

/**
 * @returns {number}
 */
function getPageIdFromUrl() {
  const url = new URL(window.location.href);
  const id = Number.parseInt(url.searchParams.get(PAGE_ID_PARAM) || '0', 10);

  return Number.isFinite(id) && id > 0 ? id : 0;
}

/**
 * @returns {number}
 */
function getPageIdFromTreeState() {
  const state = ModuleStateStorage.current(MODULE_STATE_TYPE);
  const id = Number.parseInt(state?.identifier || '0', 10);

  return Number.isFinite(id) && id > 0 ? id : 0;
}

/**
 * @param {number} pageId
 */
function appendPageIdToNavLinks(pageId) {
  const pageIdString = String(pageId);
  document.querySelectorAll('.aiu-module-nav-tabs a.nav-link[href]').forEach((link) => {
    if (!(link instanceof HTMLAnchorElement)) {
      return;
    }

    try {
      const href = new URL(link.href, window.location.href);
      if (href.searchParams.has(PAGE_ID_PARAM)) {
        return;
      }
      href.searchParams.set(PAGE_ID_PARAM, pageIdString);
      link.href = href.toString();
    } catch {
      // Ignore malformed href values.
    }
  });
}

/**
 * The server marks the shell when the active tab is site-scoped but no page id
 * was resolved from the request — only then is a reload required to render the
 * tab content.
 *
 * @returns {boolean}
 */
function currentViewRequiresPageId() {
  const shell = document.querySelector(MODULE_SHELL);

  return shell instanceof HTMLElement && shell.getAttribute(REQUIRES_PAGE_ATTR) === '1';
}

/**
 * Reflect the page id in the current URL without navigating, so same-tab form
 * actions and the backend router keep the context.
 *
 * @param {number} pageId
 */
function rewriteUrlWithPageId(pageId) {
  if (typeof window.history?.replaceState !== 'function') {
    return;
  }

  const url = new URL(window.location.href);
  url.searchParams.set(PAGE_ID_PARAM, String(pageId));
  window.history.replaceState(window.history.state, '', url.toString());
}

/**
 * Reload onto the same route with the page id, deferred until the document has
 * finished loading so the navigation does not cancel pending CSS/JS requests.
 *
 * @param {number} pageId
 */
function reloadWithPageId(pageId) {
  const url = new URL(window.location.href);
  url.searchParams.set(PAGE_ID_PARAM, String(pageId));
  const target = url.toString();

  const navigate = () => window.location.replace(target);
  if (document.readyState === 'complete') {
    navigate();
  } else {
    window.addEventListener('load', navigate, { once: true });
  }
}

function syncPageContextFromTree() {
  const urlPageId = getPageIdFromUrl();
  const treePageId = getPageIdFromTreeState();
  const effectivePageId = urlPageId > 0 ? urlPageId : treePageId;

  // Keep the page id on every in-module tab link so tab switches carry it.
  if (effectivePageId > 0) {
    appendPageIdToNavLinks(effectivePageId);
  }

  // URL already has the id, or there is no tree selection to sync from.
  if (urlPageId > 0 || treePageId <= 0) {
    return;
  }

  // URL is missing the id. A site-scoped tab needs it server-side, so reload
  // (safely, after assets load). Everything else just gets the URL corrected
  // in place to avoid an asset-aborting navigation during load.
  if (currentViewRequiresPageId()) {
    reloadWithPageId(treePageId);
    return;
  }

  rewriteUrlWithPageId(treePageId);
}

syncPageContextFromTree();
