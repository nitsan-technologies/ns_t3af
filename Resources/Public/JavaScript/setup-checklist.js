/**
 * Collapsible AI Foundation setup checklist card (dashboard + child extension modules).
 */
import { disableBrowserAutocomplete } from './disable-browser-autocomplete.js';

/**
 * @param {ParentNode} root
 * @param {string} sel
 * @returns {HTMLElement | null}
 */
function qs(root, sel) {
  const el = root.querySelector(sel);
  return el instanceof HTMLElement ? el : null;
}

/**
 * @param {ParentNode} [scope]
 */
export function initChecklist(scope = document) {
  scope.querySelectorAll('[data-aiu-checklist]').forEach((root) => {
    if (!(root instanceof HTMLElement)) {
      return;
    }
    const toggle = qs(root, '[data-aiu-checklist-toggle]');
    const panel = qs(root, '[data-aiu-checklist-panel]');
    const chevron = root.querySelector('.aiu-checklist__chevron-wrap');
    if (!toggle || !panel) {
      return;
    }
    toggle.addEventListener('click', () => {
      const expanded = toggle.getAttribute('aria-expanded') === 'true';
      const next = !expanded;
      toggle.setAttribute('aria-expanded', next ? 'true' : 'false');
      panel.hidden = !next;
      root.classList.toggle('is-open', next);
      chevron?.classList.toggle('is-open', next);
    });
  });
}

function boot() {
  disableBrowserAutocomplete(document);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initChecklist(), { once: true });
  } else {
    initChecklist();
  }
}

boot();
