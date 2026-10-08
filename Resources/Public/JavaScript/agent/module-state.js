/**
 * Lazy access to TYPO3 ModuleStateStorage with iframe URL fallback.
 *
 * A static import of @typo3/backend/storage/module-state-storage.js can fail
 * intermittently (stale _assets hash, parallel module loads). That must not
 * prevent the whole agent bundle from evaluating.
 */

/** @type {import('@typo3/backend/storage/module-state-storage.js').ModuleStateStorage | null} */
let storageClass = null;

/** @type {Promise<void> | null} */
let loadPromise = null;

/**
 * Start loading core ModuleStateStorage (idempotent). Call from agent boot().
 * @returns {Promise<void>}
 */
export function preloadModuleStateStorage() {
  if (loadPromise !== null) {
    return loadPromise;
  }
  loadPromise = import('@typo3/backend/storage/module-state-storage.js')
    .then((mod) => {
      storageClass = mod.ModuleStateStorage ?? null;
    })
    .catch((error) => {
      console.warn(
        'AI Agent: ModuleStateStorage could not be loaded; page context falls back to the module iframe URL.',
        error,
      );
    });

  return loadPromise;
}

/**
 * @param {URL|null} iframeUrl
 * @returns {number}
 */
export function pageIdFromIframeUrl(iframeUrl) {
  if (!iframeUrl) {
    return 0;
  }
  const raw = iframeUrl.searchParams.get('id') ?? '';
  const id = Number.parseInt(raw, 10);
  return Number.isFinite(id) && id > 0 ? id : 0;
}

/**
 * Active page uid from ModuleStateStorage when available, else iframe ?id=.
 *
 * @param {URL|null} [iframeUrl]
 * @returns {number}
 */
export function currentWebPageId(iframeUrl = null) {
  if (storageClass !== null) {
    try {
      const state = storageClass.current('web');
      const pageId = Number.parseInt(state?.identifier || '0', 10);
      if (Number.isFinite(pageId) && pageId > 0) {
        return pageId;
      }
    } catch {
      // Same-origin / timing — fall through.
    }
  }

  return pageIdFromIframeUrl(iframeUrl);
}

/**
 * Select a page in the page tree. TYPO3 v13 ModuleStateStorage.update takes the
 * module and the page uid; the tree listens for that update and expands to it.
 *
 * @param {number} pageId
 * @returns {Promise<void>}
 */
export async function selectWebPage(pageId) {
  const id = Number.parseInt(String(pageId), 10);
  if (!Number.isFinite(id) || id <= 0) {
    return;
  }
  await preloadModuleStateStorage();
  if (storageClass === null || typeof storageClass.update !== 'function') {
    return;
  }
  try {
    storageClass.update('web', String(id));
  } catch {
    // Storage can be missing in a frame that has no sessionStorage.
  }
}
