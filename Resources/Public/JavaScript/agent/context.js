/**
 * AI Agent backend context resolution (module route, page/file context, language ids).
 */

import { currentWebPageId } from './module-state.js';

/**
 * @param {string} route
 * @returns {string}
 */
export function ajaxUrl(route) {
  const urls = typeof TYPO3 !== 'undefined' ? TYPO3.settings?.ajaxUrls : null;
  return urls?.[route] ?? '';
}

/**
 * Active backend module route (e.g. web_layout, nst3af_providers).
 * Agent chrome lives outside the module iframe — use ModuleMenu, not body.dataset.
 * @returns {string}
 */
export function resolveModuleRoute() {
  try {
    const app = window.top?.TYPO3?.ModuleMenu?.App;
    if (app && typeof app.getCurrentModule === 'function') {
      const current = app.getCurrentModule();
      if (typeof current === 'string' && current.trim() !== '') {
        return current.trim();
      }
    }
  } catch {
    // Same-origin only.
  }

  const backendDoc = getBackendDocument();
  const activeItem = backendDoc.querySelector('[data-modulemenu-identifier].modulemenu-action-active');
  if (activeItem instanceof HTMLElement && activeItem.dataset.modulemenuIdentifier) {
    return String(activeItem.dataset.modulemenuIdentifier);
  }

  try {
    const iframe = backendDoc.querySelector('#typo3-contentIframe, [data-scaffold-content-iframe], iframe[name="list_frame"]');
    const iframeDoc = iframe?.contentDocument;
    const moduleEl = iframeDoc?.querySelector('.module[data-module-id]');
    if (moduleEl instanceof HTMLElement && moduleEl.dataset.moduleId) {
      return String(moduleEl.dataset.moduleId);
    }
  } catch {
    // iframe may be cross-origin or not ready.
  }

  return document.body?.dataset?.module ?? '';
}

/**
 * Read the active module iframe URL (Page / List) when same-origin.
 * @returns {URL|null}
 */
export function resolveContentIframeUrl() {
  try {
    const backendDoc = getBackendDocument();
    const iframe = backendDoc.querySelector('#typo3-contentIframe, [data-scaffold-content-iframe], iframe[name="list_frame"]');
    if (iframe instanceof HTMLIFrameElement && iframe.contentWindow) {
      return new URL(iframe.contentWindow.location.href);
    }
  } catch {
    // iframe may be cross-origin or not ready.
  }

  try {
    return new URL(window.location.href);
  } catch {
    return null;
  }
}

/**
 * Language column ids selected in the Page module (e.g. languages[0]=0&languages[1]=2).
 * @returns {number[]}
 */
export function resolveSelectedLanguageIds() {
  const url = resolveContentIframeUrl();
  if (!url) {
    return [];
  }

  const ids = [];
  url.searchParams.forEach((value, key) => {
    const match = key.match(/^languages\[(\d+)\]$/);
    if (!match) {
      return;
    }
    const id = Number.parseInt(value, 10);
    if (Number.isFinite(id)) {
      ids.push(id);
    }
  });

  if (ids.length > 0) {
    return ids;
  }

  url.searchParams.getAll('languages').forEach((value) => {
    const id = Number.parseInt(value, 10);
    if (Number.isFinite(id)) {
      ids.push(id);
    }
  });

  return ids;
}

/**
 * Pick the translation target from Page module language columns (first non-default).
 * @param {number[]} languageIds
 * @returns {number}
 */
export function resolveTargetLanguageId(languageIds) {
  const targets = languageIds.filter((id) => id > 0);
  return targets.length > 0 ? targets[0] : 0;
}

/**
 * @param {string} module
 * @returns {boolean}
 */
export function isFileModuleRoute(module) {
  const normalized = String(module ?? '').toLowerCase();

  return normalized === 'media_management' || normalized.startsWith('file');
}

/**
 * Parse FAL list module URL id param (e.g. 1:/user_upload/).
 * @returns {{ storageUid: number, folderIdentifier: string }}
 */
export function resolveFileListContext() {
  const url = resolveContentIframeUrl();
  if (!url) {
    return { storageUid: 0, folderIdentifier: '' };
  }

  const rawId = url.searchParams.get('id') ?? '';
  if (rawId === '') {
    return { storageUid: 0, folderIdentifier: '' };
  }

  const id = decodeURIComponent(rawId);
  const colon = id.indexOf(':');
  if (colon <= 0) {
    return { storageUid: 0, folderIdentifier: '' };
  }

  const storageUid = Number.parseInt(id.slice(0, colon), 10);
  let folderIdentifier = id.slice(colon + 1).trim();
  if (folderIdentifier !== '' && !folderIdentifier.startsWith('/')) {
    folderIdentifier = `/${folderIdentifier}`;
  }

  return {
    storageUid: Number.isFinite(storageUid) && storageUid > 0 ? storageUid : 0,
    folderIdentifier,
  };
}

/**
 * @returns {{ pageId: number, module: string, languageId: number, storageUid: number, folderIdentifier: string }}
 */
export function resolveBackendContext() {
  const module = resolveModuleRoute();
  const inFileModule = isFileModuleRoute(module);
  const fileList = inFileModule ? resolveFileListContext() : { storageUid: 0, folderIdentifier: '' };

  const iframeUrl = resolveContentIframeUrl();
  let pageId = currentWebPageId(iframeUrl);
  if (inFileModule) {
    pageId = 0;
  }

  const languageId = resolveTargetLanguageId(resolveSelectedLanguageIds());

  return {
    pageId,
    module,
    languageId,
    storageUid: fileList.storageUid,
    folderIdentifier: fileList.folderIdentifier,
  };
}
