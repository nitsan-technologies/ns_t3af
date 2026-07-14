import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import { observeBrowserAutocomplete } from './disable-browser-autocomplete.js';

const ROUTES = {
  sources: TYPO3.settings.ajaxUrls['nst3af_provider_import_sources'],
  execute: TYPO3.settings.ajaxUrls['nst3af_provider_import_execute'],
};

function appendQuery(url, params) {
  const query = params.toString();
  if (!query) {
    return url;
  }
  const separator = url.includes('?') ? '&' : '?';
  return `${url}${separator}${query}`;
}

class ProviderImport {
  constructor(root) {
    this.root = root;
    this.pageId = parseInt(root.dataset.aiuPageId || '0', 10) || 0;
    this.drawer = root.querySelector('[data-aiu-import-drawer]');
    this.panel = root.querySelector('[data-aiu-import-panel]');
    this.siteSelect = root.querySelector('[data-aiu-import-site]');
    this.list = root.querySelector('[data-aiu-import-provider-list]');
    this.submit = root.querySelector('[data-aiu-import-submit]');
    this.sites = this.parseSites(root.querySelector('[data-aiu-provider-import-trigger]'));
    this.bind();
  }

  parseSites(trigger) {
    if (!(trigger instanceof HTMLElement)) {
      return [];
    }
    try {
      const parsed = JSON.parse(trigger.dataset.aiuImportSites || '[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch (_error) {
      return [];
    }
  }

  bind() {
    const trigger = this.root.querySelector('[data-aiu-provider-import-trigger]');
    trigger?.addEventListener('click', () => this.open());
    this.root.querySelectorAll('[data-aiu-import-close]').forEach((btn) => {
      btn.addEventListener('click', () => this.close());
    });
    this.siteSelect?.addEventListener('change', () => this.loadProviders());
    this.submit?.addEventListener('click', () => this.executeImport());
    this.drawer?.addEventListener('click', (evt) => {
      if (evt.target === this.drawer) {
        this.close();
      }
    });
  }

  open() {
    if (!this.drawer || this.sites.length === 0) {
      Notification.info('Import', 'No other sites with providers are available to import from.');
      return;
    }
    if (this.siteSelect) {
      this.siteSelect.innerHTML = '';
      this.sites.forEach((site) => {
        const option = document.createElement('option');
        option.value = String(site.storagePid);
        option.textContent = `${site.siteTitle} (${site.providerCount})`;
        this.siteSelect.appendChild(option);
      });
    }
    this.drawer.setAttribute('aria-hidden', 'false');
    this.drawer.classList.remove('is-closing');
    this.drawer.classList.add('is-open');
    this.loadProviders();
  }

  close() {
    if (!this.drawer || this.drawer.classList.contains('is-closing')) {
      return;
    }
    this.drawer.classList.remove('is-open');
    this.drawer.classList.add('is-closing');

    const finish = () => {
      if (!this.drawer.classList.contains('is-closing')) {
        return;
      }
      this.drawer.classList.remove('is-closing');
      this.drawer.setAttribute('aria-hidden', 'true');
      if (this.list) {
        this.list.innerHTML = '';
      }
      if (this.submit) {
        this.submit.disabled = true;
      }
    };
    const panel = this.panel || this.drawer.querySelector('[data-aiu-import-panel]');
    if (!panel) {
      finish();
      return;
    }
    let done = false;
    const onEnd = () => {
      if (done) {
        return;
      }
      done = true;
      panel.removeEventListener('animationend', onEnd);
      finish();
    };
    panel.addEventListener('animationend', onEnd);
    window.setTimeout(onEnd, 350);
  }

  async loadProviders() {
    if (!ROUTES.sources || !this.siteSelect || !this.list) {
      return;
    }
    const sourceStoragePid = parseInt(this.siteSelect.value || '0', 10);
    if (sourceStoragePid <= 0) {
      return;
    }
    this.list.innerHTML = '<p class="text-variant">Loading…</p>';
    const params = new URLSearchParams({
      sourceStoragePid: String(sourceStoragePid),
      id: String(this.pageId),
    });
    try {
      const response = await new AjaxRequest(appendQuery(ROUTES.sources, params)).get();
      const json = await response.resolve();
      if (!json.ok || !Array.isArray(json.providers) || json.providers.length === 0) {
        this.list.innerHTML = '<p class="text-variant">No providers found for this site.</p>';
        if (this.submit) {
          this.submit.disabled = true;
        }
        return;
      }
      this.list.innerHTML = json.providers.map((row) => `
        <label class="form-check d-block mb-2">
          <input class="form-check-input" type="checkbox" value="${row.uid}" data-aiu-import-uid checked>
          <span class="form-check-label">
            <strong>${row.title}</strong>
            <span class="text-variant"> — ${row.identifier} · ${row.modelId || 'no model'}</span>
          </span>
        </label>
      `).join('');
      if (this.submit) {
        this.submit.disabled = false;
      }
    } catch (error) {
      this.list.innerHTML = '<p class="text-variant">Could not load providers.</p>';
      Notification.error('Import', error instanceof Error ? error.message : 'Could not load providers.');
    }
  }

  async executeImport() {
    if (!ROUTES.execute || !this.siteSelect || !this.list) {
      return;
    }
    const uids = Array.from(this.list.querySelectorAll('[data-aiu-import-uid]:checked'))
      .map((el) => parseInt(el.value, 10))
      .filter((uid) => uid > 0);
    if (uids.length === 0) {
      Notification.warning('Import', 'Select at least one provider.');
      return;
    }
    const sourceStoragePid = parseInt(this.siteSelect.value || '0', 10);
    if (this.submit) {
      this.submit.disabled = true;
    }
    try {
      const response = await new AjaxRequest(ROUTES.execute).post({
        sourceStoragePid,
        uids,
        id: this.pageId,
      });
      const json = await response.resolve();
      if (!json.ok) {
        Notification.error('Import failed', typeof json.message === 'string' ? json.message : 'Unknown error');
        return;
      }
      Notification.success(
        'Import complete',
        `${json.imported || 0} provider(s) imported.`,
      );
      window.location.reload();
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Import request failed.';
      Notification.error('Import failed', message);
    } finally {
      if (this.submit) {
        this.submit.disabled = false;
      }
    }
  }
}

document.querySelectorAll('[data-aiu-provider-list]').forEach((root) => {
  observeBrowserAutocomplete(root);
  new ProviderImport(root);
});
