/**
 * AI Label module: bulk selection, folder search, in-module navigation, record drawer.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import { navigateFromForm, navigateInModule } from './module-navigation.js';

/** @type {boolean} */
let drawerInitialized = false;

/**
 * @param {string} href
 * @returns {boolean}
 */
function isAiLabelNavLink(href) {
  return href.includes('/module/t3af/dashboard/ai-label')
    || href.includes('redirect=t3af_dashboard.ai_label');
}

/**
 * Re-init after fetch-based partial refresh.
 *
 * @param {Element} root
 */
function reinitAiLabelListRoot(root) {
  initAiLabelListRoot(root);
}

if (typeof window !== 'undefined') {
  window.aiuReinitAiLabelListRoot = reinitAiLabelListRoot;
}

/**
 * @param {Element} root
 */
function initInModuleLinks(root) {
  root.querySelectorAll('a[href]').forEach((anchor) => {
    if (!(anchor instanceof HTMLAnchorElement)) {
      return;
    }
    if (anchor.href.includes('ai_label.export')) {
      return;
    }
    if (!isAiLabelNavLink(anchor.href)) {
      return;
    }
    if (anchor.dataset.aiuAilabelNavBound === '1') {
      return;
    }
    anchor.dataset.aiuAilabelNavBound = '1';
    anchor.addEventListener('click', (event) => {
      event.preventDefault();
      const navRoot = anchor.closest('[data-aiu-ailabel-list-root]') ?? root;
      navigateInModule(anchor.href, navRoot);
    });
  });
}

/**
 * @param {Element} root
 */
function initFilterForm(root) {
  const form = root.querySelector('#aiu-ailabel-filter');
  if (!(form instanceof HTMLFormElement)) {
    return;
  }
  if (form.dataset.aiuAilabelFilterBound === '1') {
    return;
  }
  form.dataset.aiuAilabelFilterBound = '1';

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    navigateFromForm(form, root);
  });
}

/**
 * @param {Element} root
 * @param {string} max
 * @returns {string|null}
 */
function buildPerPageUrl(root, max) {
  const refresh = root.querySelector('[data-aiu-ailabel-refresh]');
  if (!(refresh instanceof HTMLAnchorElement)) {
    return null;
  }
  const url = new URL(refresh.href, window.location.href);
  url.searchParams.set('max', max);
  url.searchParams.delete('page');
  return url.toString();
}

/**
 * @param {Element} root
 */
function initPerPageSelect(root) {
  const select = root.querySelector('[data-ailabel-per-page]');
  if (!(select instanceof HTMLSelectElement)) {
    return;
  }
  if (select.dataset.aiuAilabelPerPageBound === '1') {
    return;
  }
  select.dataset.aiuAilabelPerPageBound = '1';
  select.addEventListener('change', () => {
    const target = buildPerPageUrl(root, select.value);
    if (target) {
      navigateInModule(target, root);
    }
  });
}

/**
 * @param {Element} root
 */
function initSubNavLinks() {
  document.querySelectorAll('.aiu-ailabel-subnav a[href]').forEach((anchor) => {
    if (!(anchor instanceof HTMLAnchorElement)) {
      return;
    }
    if (!isAiLabelNavLink(anchor.href)) {
      return;
    }
    if (anchor.dataset.aiuAilabelNavBound === '1') {
      return;
    }
    anchor.dataset.aiuAilabelNavBound = '1';
    anchor.addEventListener('click', (event) => {
      event.preventDefault();
      navigateInModule(anchor.href, anchor.closest('[data-aiu-ailabel-list-root]'));
    });
  });
}

/**
 * @param {Element} root
 */
function initFolderTreeLinks() {
  document.querySelectorAll('.aiu-ailabel-folder-tree a[href]').forEach((anchor) => {
    if (!(anchor instanceof HTMLAnchorElement)) {
      return;
    }
    if (!isAiLabelNavLink(anchor.href)) {
      return;
    }
    if (anchor.dataset.aiuAilabelNavBound === '1') {
      return;
    }
    anchor.dataset.aiuAilabelNavBound = '1';
    anchor.addEventListener('click', (event) => {
      event.preventDefault();
      const listRoot = document.querySelector('[data-aiu-ailabel-list-root]');
      navigateInModule(anchor.href, listRoot ?? undefined);
    });
  });
}

/**
 * Expand / collapse nested folder branches (T3AI filelist-tree style).
 */
function initFolderTreeToggles() {
  document.querySelectorAll('[data-ailabel-folder-toggle]').forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }
    if (button.dataset.aiuAilabelToggleBound === '1') {
      return;
    }
    button.dataset.aiuAilabelToggleBound = '1';
    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const branch = button.closest('[data-ailabel-folder-branch]');
      const children = branch?.querySelector(':scope > [data-ailabel-folder-children]');
      if (!(children instanceof HTMLElement) || !(branch instanceof HTMLElement)) {
        return;
      }
      const expand = children.hasAttribute('hidden');
      children.toggleAttribute('hidden', !expand);
      button.classList.toggle('is-expanded', expand);
      button.setAttribute('aria-expanded', expand ? 'true' : 'false');
      branch.dataset.ailabelFolderExpanded = expand ? '1' : '0';
    });
  });
}

export default class AiLabelModule {
  constructor() {
    this.drawer = document.querySelector('[data-ailabel-drawer]');
    this.panel = document.querySelector('[data-ailabel-drawer-panel]');
    this.triggerElement = null;
    this._drawerKeyHandler = null;
  }

  /**
   * @param {Element} root
   */
  initSelection(root) {
    const tables = root.querySelectorAll('[data-ailabel-table]');
    tables.forEach((table) => {
      const selectAll = table.querySelector('[data-ailabel-select-all]');
      const rowBoxes = table.querySelectorAll('[data-ailabel-row-checkbox]');
      const countEl = root.querySelector('[data-ailabel-selected-count]');
      const bulkBtns = root.querySelectorAll('[data-ailabel-bulk-btn]');

      const sync = () => {
        const checked = table.querySelectorAll('[data-ailabel-row-checkbox]:checked').length;
        if (countEl) {
          countEl.textContent = countEl.textContent.replace(/^\d+/, String(checked));
        }
        bulkBtns.forEach((btn) => {
          btn.classList.toggle('is-visible', checked > 0);
          btn.classList.toggle('d-none', checked === 0);
        });
        if (selectAll instanceof HTMLInputElement) {
          selectAll.indeterminate = checked > 0 && checked < rowBoxes.length;
          selectAll.checked = checked > 0 && checked === rowBoxes.length;
        }
      };

      selectAll?.addEventListener('change', () => {
        if (!(selectAll instanceof HTMLInputElement)) {
          return;
        }
        rowBoxes.forEach((box) => {
          if (box instanceof HTMLInputElement) {
            box.checked = selectAll.checked;
          }
        });
        sync();
      });

      rowBoxes.forEach((box) => box.addEventListener('change', sync));
      sync();
    });
  }

  initFolderSearch() {
    const input = document.querySelector('[data-ailabel-folder-search]');
    const branches = document.querySelectorAll('[data-ailabel-folder-branch]');
    if (!input || branches.length === 0) {
      return;
    }
    if (input.dataset.aiuAilabelSearchBound === '1') {
      return;
    }
    input.dataset.aiuAilabelSearchBound = '1';

    input.addEventListener('input', () => {
      const query = input.value.trim().toLowerCase();
      branches.forEach((branch) => {
        if (!(branch instanceof HTMLElement)) {
          return;
        }
        const labelEl = branch.querySelector(':scope > .aiu-ailabel-folder-tree__row [data-ailabel-folder-label]');
        const label = (labelEl?.getAttribute('data-ailabel-folder-label') ?? '').toLowerCase();
        const selfMatch = query === '' || label.includes(query);
        branch.hidden = false;
        branch.dataset.ailabelSearchMatch = selfMatch ? '1' : '0';
      });

      if (query === '') {
        branches.forEach((branch) => {
          if (!(branch instanceof HTMLElement)) {
            return;
          }
          const children = branch.querySelector(':scope > [data-ailabel-folder-children]');
          const toggle = branch.querySelector(':scope > .aiu-ailabel-folder-tree__row [data-ailabel-folder-toggle]');
          const preferExpanded = branch.dataset.ailabelFolderExpanded === '1';
          if (children instanceof HTMLElement) {
            children.toggleAttribute('hidden', !preferExpanded);
          }
          if (toggle instanceof HTMLElement) {
            toggle.classList.toggle('is-expanded', preferExpanded);
            toggle.setAttribute('aria-expanded', preferExpanded ? 'true' : 'false');
          }
        });
        return;
      }

      branches.forEach((branch) => {
        if (!(branch instanceof HTMLElement)) {
          return;
        }
        const nestedMatch = Array.from(branch.querySelectorAll('[data-ailabel-folder-branch]'))
          .some((child) => child instanceof HTMLElement && child.dataset.ailabelSearchMatch === '1');
        const show = branch.dataset.ailabelSearchMatch === '1' || nestedMatch;
        branch.hidden = !show;
        if (!show) {
          return;
        }
        const children = branch.querySelector(':scope > [data-ailabel-folder-children]');
        const toggle = branch.querySelector(':scope > .aiu-ailabel-folder-tree__row [data-ailabel-folder-toggle]');
        if (nestedMatch && children instanceof HTMLElement) {
          children.removeAttribute('hidden');
          if (toggle instanceof HTMLElement) {
            toggle.classList.add('is-expanded');
            toggle.setAttribute('aria-expanded', 'true');
          }
        }
      });
    });
  }

  initUndoButtons(root) {
    root.querySelectorAll('[data-ailabel-undo]').forEach((button) => {
      button.addEventListener('click', () => {
        button.setAttribute('disabled', 'disabled');
      });
    });
  }

  initDrawerOnce() {
    if (drawerInitialized || !this.drawer || !this.panel) {
      return;
    }
    drawerInitialized = true;

    document.addEventListener('click', async (evt) => {
      const target = evt.target;
      if (!(target instanceof Element)) {
        return;
      }

      const trigger = target.closest('[data-ailabel-drawer-trigger]');
      if (trigger instanceof HTMLElement) {
        const url = trigger.dataset.drawerUrl || '';
        if (!url || url === '#') {
          return;
        }
        evt.preventDefault();
        evt.stopPropagation();
        this.triggerElement = trigger;
        await this.openDrawer(url);
        return;
      }

      if (this.drawer?.classList.contains('is-open')) {
        if (target.closest('[data-ailabel-drawer-close]') || target === this.drawer) {
          evt.preventDefault();
          this.closeDrawer();
        }
      }
    }, { capture: true });
  }

  async openDrawer(url) {
    try {
      const html = await new AjaxRequest(url).get().then((r) => r.resolve('text/html'));
      this.panel.innerHTML = html;
      this.drawer.setAttribute('aria-hidden', 'false');
      this.drawer.classList.remove('is-closing');
      this.drawer.classList.add('is-open');
      this.activateDrawerFocus();
    } catch (err) {
      Notification.error('Drawer load failed', String(err));
    }
  }

  activateDrawerFocus() {
    if (!(this.panel instanceof HTMLElement)) {
      return;
    }
    this.deactivateDrawerFocus();
    if (!this.panel.hasAttribute('tabindex')) {
      this.panel.setAttribute('tabindex', '-1');
    }
    this.panel.focus();
    this._drawerKeyHandler = (event) => {
      if (event instanceof KeyboardEvent && event.key === 'Escape') {
        event.preventDefault();
        this.closeDrawer();
      }
    };
    document.addEventListener('keydown', this._drawerKeyHandler);
  }

  deactivateDrawerFocus() {
    if (this._drawerKeyHandler) {
      document.removeEventListener('keydown', this._drawerKeyHandler);
      this._drawerKeyHandler = null;
    }
  }

  closeDrawer() {
    if (!this.drawer || this.drawer.classList.contains('is-closing')) {
      return;
    }
    this.deactivateDrawerFocus();
    this.drawer.classList.remove('is-open');
    this.drawer.classList.add('is-closing');

    const finish = () => {
      this.drawer.classList.remove('is-closing');
      this.drawer.setAttribute('aria-hidden', 'true');
      const trigger = this.triggerElement;
      this.triggerElement = null;
      if (trigger instanceof HTMLElement && document.contains(trigger)) {
        trigger.focus();
      }
    };

    window.setTimeout(finish, 250);
  }
}

/**
 * @param {Element} root
 */
function initAiLabelListRoot(root) {
  const module = new AiLabelModule();
  module.initSelection(root);
  module.initUndoButtons(root);
  initFilterForm(root);
  initInModuleLinks(root);
  initPerPageSelect(root);
}

function bootAiLabelModule() {
  if (!document.querySelector('.aiu-ailabel')) {
    return;
  }

  const module = new AiLabelModule();
  document.querySelectorAll('[data-aiu-ailabel-list-root]').forEach((root) => {
    initAiLabelListRoot(root);
  });

  initSubNavLinks();
  initFolderTreeLinks();
  initFolderTreeToggles();
  module.initFolderSearch();
  module.initDrawerOnce();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootAiLabelModule);
} else {
  bootAiLabelModule();
}
