/**
 * AI Label module: bulk selection, folder search, per-page reload, record drawer.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';

export default class AiLabelModule {
  constructor(root = document) {
    this.root = root.querySelector('.aiu-module-content') ?? root;
    this.drawer = document.querySelector('[data-ailabel-drawer]');
    this.panel = document.querySelector('[data-ailabel-drawer-panel]');
    this.triggerElement = null;
    this._drawerKeyHandler = null;
    this.initSelection();
    this.initFolderSearch();
    this.initPerPage();
    this.initUndoButtons();
    this.initDrawer();
  }

  initSelection() {
    const tables = this.root.querySelectorAll('[data-ailabel-table]');
    tables.forEach((table) => {
      const selectAll = table.querySelector('[data-ailabel-select-all]');
      const rowBoxes = table.querySelectorAll('[data-ailabel-row-checkbox]');
      const countEl = this.root.querySelector('[data-ailabel-selected-count]');
      const bulkBtns = this.root.querySelectorAll('[data-ailabel-bulk-btn]');

      const sync = () => {
        const checked = table.querySelectorAll('[data-ailabel-row-checkbox]:checked').length;
        if (countEl) {
          countEl.textContent = countEl.textContent.replace(/^\d+/, String(checked));
        }
        bulkBtns.forEach((btn) => {
          btn.classList.toggle('is-visible', checked > 0);
          btn.classList.toggle('d-none', checked === 0);
        });
        if (selectAll) {
          selectAll.indeterminate = checked > 0 && checked < rowBoxes.length;
          selectAll.checked = checked > 0 && checked === rowBoxes.length;
        }
      };

      selectAll?.addEventListener('change', () => {
        rowBoxes.forEach((box) => {
          box.checked = selectAll.checked;
        });
        sync();
      });

      rowBoxes.forEach((box) => box.addEventListener('change', sync));
      sync();
    });
  }

  initFolderSearch() {
    const input = this.root.querySelector('[data-ailabel-folder-search]');
    const nodes = this.root.querySelectorAll('[data-ailabel-folder-label]');
    if (!input || nodes.length === 0) {
      return;
    }

    input.addEventListener('input', () => {
      const query = input.value.trim().toLowerCase();
      nodes.forEach((node) => {
        const link = node.closest('.aiu-ailabel-folder-tree__node');
        if (!link) {
          return;
        }
        const label = (node.getAttribute('data-ailabel-folder-label') ?? '').toLowerCase();
        link.hidden = query !== '' && !label.includes(query);
      });
    });
  }

  initPerPage() {
    const select = this.root.querySelector('[data-ailabel-per-page]');
    if (!select) {
      return;
    }

    select.addEventListener('change', () => {
      const url = new URL(window.location.href);
      url.searchParams.set('max', select.value);
      url.searchParams.delete('page');
      window.location.assign(url.toString());
    });
  }

  initUndoButtons() {
    this.root.querySelectorAll('[data-ailabel-undo]').forEach((button) => {
      button.addEventListener('click', () => {
        button.setAttribute('disabled', 'disabled');
      });
    });
  }

  initDrawer() {
    if (!this.drawer || !this.panel) {
      return;
    }

    document.addEventListener('click', async (evt) => {
      const target = evt.target;
      if (!(target instanceof Element)) {
        return;
      }

      const trigger = target.closest('[data-ailabel-drawer-trigger]');
      if (trigger instanceof HTMLElement && this.root.contains(trigger)) {
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

function bootAiLabelModule() {
  if (document.querySelector('.aiu-ailabel')) {
    new AiLabelModule();
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootAiLabelModule);
} else {
  bootAiLabelModule();
}
