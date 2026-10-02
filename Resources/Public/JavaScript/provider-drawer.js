/**
 * Provider list drawer — slide-in editor + AJAX glue.
 *
 * Loaded via @nitsan/nst3af/provider-drawer.js (ESM).
 * Depends on TYPO3 backend's @typo3/core/ajax/ajax-request module for
 * session/CSRF-aware fetch.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import Severity from '@typo3/backend/severity.js';
import { Popover as BsPopover } from 'bootstrap';
import { bindFilterSearchInput, observeBrowserAutocomplete } from '@nitsan/nst3af/disable-browser-autocomplete.js';

/**
 * @param {ParentNode} root
 */
function disposeFieldHelpLinks(root) {
  root.querySelectorAll('.help-link').forEach((el) => {
    if (!(el instanceof HTMLElement)) {
      return;
    }
    BsPopover.getInstance(el)?.dispose();
  });
}

/**
 * Field help for AJAX-injected drawer markup.
 * Appended to document.body so overflow on .aiu-drawer__panel does not clip the popover.
 *
 * @param {ParentNode} root
 */
function initFieldHelpLinks(root) {
  root.querySelectorAll('.help-link').forEach((el) => {
    if (!(el instanceof HTMLElement)) {
      return;
    }
    BsPopover.getInstance(el)?.dispose();

    const title = '';
    const content = el.getAttribute('data-bs-content')
      || el.getAttribute('data-description')
      || '';
    if (content === '') {
      return;
    }

    new BsPopover(el, {
      container: document.body,
      placement: 'right',
      trigger: 'hover focus',
      html: false,
      title,
      content,
      customClass: 'aiu-field-help-popover',
    });
  });
}

const ROUTES = {
  test: TYPO3.settings.ajaxUrls['nst3af_provider_test'],
  setDefault: TYPO3.settings.ajaxUrls['nst3af_provider_set_default'],
  search: TYPO3.settings.ajaxUrls['nst3af_provider_search'],
  models: TYPO3.settings.ajaxUrls['nst3af_provider_models'],
};

const LL = {
  drawerLoadFailed: 'provider.js.error.drawerLoadFailed',
  saveFailed: 'provider.js.error.saveFailed',
  connectionFailed: 'provider.js.error.connectionFailed',
  testFailed: 'provider.js.error.testFailed',
  setDefaultFailed: 'provider.js.error.setDefaultFailed',
  messageUnknown: 'provider.js.message.unknown',
  notifyConnectionOk: 'provider.js.notify.connectionOk',
  modelsRefreshed: 'provider.js.notify.modelsRefreshed',
  modelsLoaded: 'provider.js.notify.modelsLoaded',
  noModelsTitle: 'provider.js.warn.noModels.title',
  noModelsBody: 'provider.js.warn.noModels.body',
  hintLiveFetchFailed: 'provider.js.hint.liveFetchFailed',
  hintNoModelsDiscovered: 'provider.js.hint.noModelsDiscovered',
  hintSource: 'provider.js.hint.source',
  hintLatency: 'provider.js.hint.latency',
  revealShow: 'provider.js.reveal.show',
  revealHide: 'provider.js.reveal.hide',
  embeddingConfirmTitle: 'provider.js.embeddingModel.confirmTitle',
  embeddingConfirmMessage: 'provider.js.embeddingModel.confirmMessage',
  embeddingConfirmOk: 'provider.js.embeddingModel.confirmOk',
  embeddingCapabilityBlockedTitle: 'provider.js.embeddingCapability.blockedTitle',
  embeddingCapabilityBlockedMessage: 'provider.js.embeddingCapability.blockedMessage',
};

/**
 * @param {string} key
 * @param {string} fallback
 */
function ll(key, fallback) {
  const v = typeof TYPO3 !== 'undefined' && TYPO3.lang ? TYPO3.lang[key] : undefined;
  return typeof v === 'string' && v !== '' ? v : fallback;
}

/**
 * @param {string} key
 * @param {string} fallback
 * @param {Array<string|number>} args
 */
function llFormat(key, fallback, ...args) {
  let s = ll(key, fallback);
  for (const a of args) {
    s = s.replace('%s', String(a));
  }
  return s;
}

/**
 * Warn before changing a stored embedding model (trained vectors may not match).
 *
 * @param {string} previous
 * @param {string} next
 * @param {() => void} onConfirm
 * @param {() => void} onCancel
 */
function confirmEmbeddingModelChange(previous, next, onConfirm, onCancel) {
  if (previous === '' || previous === next) {
    onConfirm();
    return;
  }
  Modal.confirm(
    ll(LL.embeddingConfirmTitle, 'Change embedding model?'),
    ll(
      LL.embeddingConfirmMessage,
      'Are you sure you want to change this model for your trained data? If you have already trained your data then you need to re-train it.',
    ),
    Severity.warning,
    [
      {
        text: (typeof TYPO3 !== 'undefined' && TYPO3.lang && TYPO3.lang['button.cancel']) || 'Cancel',
        active: true,
        btnClass: 'btn-default',
        trigger: () => {
          Modal.dismiss();
          onCancel();
        },
      },
      {
        text: ll(LL.embeddingConfirmOk, 'Change model'),
        btnClass: 'btn-warning',
        trigger: () => {
          Modal.dismiss();
          onConfirm();
        },
      },
    ],
  );
}

/**
 * Restore embedding select/input to a previously committed model id.
 *
 * @param {HTMLSelectElement|null} select
 * @param {HTMLInputElement|null} input
 * @param {string} previous
 */
function restoreEmbeddingModelFields(select, input, previous) {
  if (input) {
    input.value = previous;
  }
  if (!select) {
    if (input) {
      input.hidden = false;
    }
    return;
  }
  if (previous === '') {
    select.value = '';
    if (input) {
      input.hidden = false;
    }
    return;
  }
  const match = Array.from(select.options).find((o) => o.value === previous);
  if (match) {
    select.value = previous;
    if (input) {
      input.hidden = true;
    }
    return;
  }
  select.value = '__custom__';
  if (input) {
    input.hidden = false;
  }
}

const OPENAI_COMPATIBLE_ADAPTER = 'nst3af.openai_compatible';
const OLLAMA_ADAPTER = 'symfony.ollama';
const AZURE_ADAPTER = 'symfony.azure';
const OPENRESPONSES_ADAPTER = 'symfony.openresponses';
const CAP_EMBEDDINGS = 'embeddings';
const CAP_CHAT = 'chat';
const CAP_COMPLETION = 'completion';

const DRAWER_FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

/**
 * @param {ParentNode} root
 * @returns {HTMLElement[]}
 */
function drawerFocusables(root) {
  return Array.from(root.querySelectorAll(DRAWER_FOCUSABLE))
    .filter((el) => el instanceof HTMLElement && !el.hasAttribute('disabled') && el.getClientRects().length > 0);
}

/**
 * @param {{capabilities?: string[]}} model
 */
function isEmbeddingOnlyModel(model) {
  const caps = model.capabilities || [];
  if (caps.length === 0) {
    return false;
  }

  return caps.includes(CAP_EMBEDDINGS)
    && !caps.includes(CAP_CHAT)
    && !caps.includes(CAP_COMPLETION);
}

/**
 * @param {Array<{id: string, label?: string, capabilities?: string[], source?: string}>} models
 */
function filterChatModels(models) {
  return models.filter((model) => !isEmbeddingOnlyModel(model));
}

/**
 * @param {Array<{id: string, label?: string, capabilities?: string[], source?: string}>} models
 */
function filterEmbeddingModels(models) {
  return models.filter((model) => (model.capabilities || []).includes(CAP_EMBEDDINGS));
}

/**
 * Append URLSearchParams to a URL that may already contain a query string.
 *
 * TYPO3 BE AJAX route URLs are pre-baked with a `?token=…` parameter, so
 * naive `${url}?${params}` produces `…?token=…?uid=…` and the second `?` is
 * treated as part of the previous value. This picks the correct separator.
 */
function appendQuery(url, params) {
  const query = params.toString();
  if (!query) {
    return url;
  }
  const separator = url.includes('?') ? '&' : '?';
  return `${url}${separator}${query}`;
}

class ProviderDrawer {
  constructor(root) {
    this.root = root;
    this.pageId = parseInt(root.dataset.aiuPageId || '0', 10) || 0;
    this.drawer = root.querySelector('[data-aiu-drawer]');
    this.panel = root.querySelector('[data-aiu-drawer-panel]');
    /** @type {HTMLElement|null} */
    this.triggerElement = null;
    /** @type {((event: KeyboardEvent) => void)|null} */
    this._drawerKeyHandler = null;
    this.bindTriggers();
    this.bindClose();
    this.bindRowActions();
    this.bindSearch();
  }

  bindTriggers() {
    if (this.root.dataset.aiuDrawerTriggersBound === '1') {
      return;
    }
    this.root.dataset.aiuDrawerTriggersBound = '1';

    this.root.addEventListener('click', async (evt) => {
      const target = evt.target;
      if (!(target instanceof Element)) {
        return;
      }
      const trigger = target.closest('[data-aiu-drawer-trigger]');
      if (!(trigger instanceof HTMLElement) || !this.root.contains(trigger)) {
        return;
      }

      const url = trigger.dataset.drawerUrl
        || (trigger instanceof HTMLAnchorElement ? trigger.getAttribute('href') : '');
      if (!url || url === '#' || url.endsWith('#')) {
        return;
      }

      evt.preventDefault();
      evt.stopPropagation();
      this.triggerElement = trigger;
      await this.openWithUrl(url);
    }, { capture: true });
  }

  bindClose() {
    this.root.addEventListener('click', (evt) => {
      const target = evt.target;
      if (!(target instanceof Element)) {
        return;
      }
      if (target.closest('[data-aiu-drawer-close]') || target === this.drawer) {
        this.close();
      }
    });
  }

  bindRowActions() {
    this.root.addEventListener('click', async (evt) => {
      const target = evt.target;
      if (!(target instanceof Element)) {
        return;
      }
      const testBtn = target.closest('[data-aiu-test-uid]');
      if (testBtn instanceof HTMLElement) {
        evt.preventDefault();
        await this.runTest(parseInt(testBtn.dataset.aiuTestUid || '0', 10), testBtn);
        return;
      }
      const defaultUid = target.closest('[data-aiu-default-uid]')?.dataset.aiuDefaultUid;
      if (defaultUid) {
        evt.preventDefault();
        await this.makeDefault(parseInt(defaultUid, 10));
        return;
      }
    });
  }

  bindSearch() {
    const input = this.root.querySelector('[data-aiu-provider-search]');
    if (!(input instanceof HTMLInputElement)) {
      return;
    }
    bindFilterSearchInput(input, (needle) => {
      const query = needle.toLowerCase();
      this.root.querySelectorAll('[data-aiu-provider-row]').forEach((row) => {
        const haystack = [
          row.dataset.identifier,
          row.dataset.title,
          row.dataset.model,
        ].filter(Boolean).join(' ').toLowerCase();
        row.hidden = query.length > 0 && !haystack.includes(query);
      });
    });
  }

  async openWithUrl(url) {
    if (!this.drawer || !this.panel) {
      if (this.root.hasAttribute('data-aiu-dashboard-provider-drawer')) {
        Notification.error(
          ll(LL.drawerLoadFailed, 'Drawer load failed'),
          ll(LL.drawerLoadFailed, 'Drawer load failed'),
        );
        return;
      }
      window.location.href = url;
      return;
    }
    try {
      if (!(this.triggerElement instanceof HTMLElement)) {
        const active = document.activeElement;
        this.triggerElement = active instanceof HTMLElement ? active : null;
      }
      const html = await new AjaxRequest(url).get().then((r) => r.resolve('text/html'));
      this.applyDrawerHtml(html);
      this.drawer.setAttribute('aria-hidden', 'false');
      this.drawer.classList.remove('is-closing');
      this.drawer.classList.add('is-open');
      this.drawer.classList.add('aiu-drawer--open');
      this.activateDrawerFocus();
    } catch (err) {
      Notification.error(ll(LL.drawerLoadFailed, 'Drawer load failed'), String(err));
    }
  }

  /**
   * Inject drawer markup into the panel. Prefers the form node so ModuleTemplate
   * chrome from a full-page response does not nest inside the slide-over.
   *
   * @param {string} html
   */
  applyDrawerHtml(html) {
    if (!(this.panel instanceof HTMLElement)) {
      return;
    }
    disposeFieldHelpLinks(this.panel);
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const formEl = doc.querySelector('[data-aiu-drawer-form]');
    if (formEl instanceof HTMLElement) {
      this.panel.replaceChildren(document.importNode(formEl, true));
    } else {
      this.panel.innerHTML = html;
    }
    this.bindForm();
    initFieldHelpLinks(this.panel);
  }

  /**
   * POST the drawer form without leaving the Providers list. Validation errors
   * re-render inside the open panel; success follows the server redirect.
   *
   * @param {HTMLFormElement} form
   */
  async saveForm(form) {
    const action = form.getAttribute('action') || form.action;
    if (!action) {
      form.submit();
      return;
    }

    const submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
    submitButtons.forEach((button) => {
      if (button instanceof HTMLButtonElement || button instanceof HTMLInputElement) {
        button.disabled = true;
      }
    });

    try {
      const response = await fetch(action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const errorForm = doc.querySelector('[data-aiu-drawer-form]');
      const listRoot = doc.querySelector('[data-aiu-provider-list]');

      // Success: controller redirects to the list (flash=saved). Stay in-module.
      if (response.redirected || (listRoot && !errorForm)) {
        window.location.assign(response.url || action);
        return;
      }

      if (errorForm instanceof HTMLElement) {
        this.applyDrawerHtml(html);
        this.drawer?.setAttribute('aria-hidden', 'false');
        this.drawer?.classList.remove('is-closing');
        this.drawer?.classList.add('is-open');
        this.drawer?.classList.add('aiu-drawer--open');
        this.activateDrawerFocus();
        const firstError = this.panel?.querySelector('.aiu-error');
        if (firstError instanceof HTMLElement) {
          firstError.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        return;
      }

      window.location.assign(response.url || action);
    } catch (err) {
      submitButtons.forEach((button) => {
        if (button instanceof HTMLButtonElement || button instanceof HTMLInputElement) {
          button.disabled = false;
        }
      });
      Notification.error(ll(LL.saveFailed, 'Save failed'), String(err));
    }
  }

  /**
   * A11Y-01: move focus into the dialog, trap Tab, close on Escape, restore on close.
   */
  activateDrawerFocus() {
    if (!(this.drawer instanceof HTMLElement) || !(this.panel instanceof HTMLElement)) {
      return;
    }
    this.deactivateDrawerFocus();

    const panel = this.panel;
    if (!panel.hasAttribute('tabindex')) {
      panel.setAttribute('tabindex', '-1');
    }

    const focusables = drawerFocusables(panel);
    const initial = focusables[0] ?? panel;
    window.requestAnimationFrame(() => {
      initial.focus();
    });

    this._drawerKeyHandler = (event) => {
      if (!(event instanceof KeyboardEvent) || !this.drawer?.classList.contains('is-open')) {
        return;
      }
      if (event.key === 'Escape') {
        event.preventDefault();
        this.close();
        return;
      }
      if (event.key !== 'Tab') {
        return;
      }
      const items = drawerFocusables(panel);
      if (items.length === 0) {
        event.preventDefault();
        panel.focus();
        return;
      }
      const first = items[0];
      const last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
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

  close() {
    if (!this.drawer || this.drawer.classList.contains('is-closing')) {
      return;
    }
    this.deactivateDrawerFocus();
    if (this.panel) {
      disposeFieldHelpLinks(this.panel);
    }
    this.drawer.classList.remove('is-open');
    this.drawer.classList.remove('aiu-drawer--open');
    this.drawer.classList.add('is-closing');

    const finish = () => {
      if (!this.drawer.classList.contains('is-closing')) {
        return;
      }
      this.drawer.classList.remove('is-closing');
      this.drawer.setAttribute('aria-hidden', 'true');
      const trigger = this.triggerElement;
      this.triggerElement = null;
      if (trigger instanceof HTMLElement && document.contains(trigger)) {
        trigger.focus();
      }
    };
    const panel = this.panel || this.drawer.querySelector('[data-aiu-drawer-panel]');
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

  bindForm() {
    const form = this.panel?.querySelector('[data-aiu-drawer-form]');
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    const adapterSelect = form.querySelector('[data-aiu-adapter-select]');
    const endpointInput = form.querySelector('[data-aiu-endpoint-input]');
    const apiKeyInput = form.querySelector('[data-aiu-api-key-input]');
    const tempInput = form.querySelector('[data-aiu-temp-input]');
    const tempOutput = form.querySelector('[data-aiu-temp-output]');
    const revealBtn = form.querySelector('[data-aiu-toggle-reveal]');
    const modelSelect = form.querySelector('[data-aiu-model-select]');
    const modelInput = form.querySelector('[data-aiu-model-input]');
    const modelHint = form.querySelector('[data-aiu-model-hint]');
    const embeddingModelSelect = form.querySelector('[data-aiu-embedding-model-select]');
    const embeddingModelInput = form.querySelector('[data-aiu-embedding-model-input]');
    const embeddingModelHint = form.querySelector('[data-aiu-embedding-model-hint]');
    const modelRefresh = form.querySelector('[data-aiu-model-refresh]');
    const uidInput = form.querySelector('input[name="uid"]');

    const syncChipActiveFromSelect = () => {
      const val = adapterSelect?.value || '';
      form.querySelectorAll('[data-aiu-adapter-chip]').forEach((chip) => {
        const type = chip.getAttribute('data-adapter-type');
        const on = type !== null && type === val && val !== '';
        chip.classList.toggle('aiu-chip--active', on);
        chip.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    };

    const syncAdapterConnectionUi = () => {
      const adapterType = adapterSelect?.value || '';
      const selectedOpt = adapterSelect?.selectedOptions?.[0];
      const isCustom = adapterType === OPENAI_COMPATIBLE_ADAPTER;
      const isOllama = adapterType === OLLAMA_ADAPTER;
      const isAzure = adapterType === AZURE_ADAPTER;
      const isOpenResponses = adapterType === OPENRESPONSES_ADAPTER;
      const showEndpoint = selectedOpt?.dataset.requiresEndpoint === '1'
        || isCustom
        || isOllama
        || isAzure
        || isOpenResponses;
      const endpointField = form.querySelector('[data-aiu-endpoint-field]');
      if (endpointField) {
        endpointField.hidden = !showEndpoint;
      }
      if (endpointInput) {
        endpointInput.disabled = !showEndpoint;
        endpointInput.required = showEndpoint;
        if (!showEndpoint) {
          // Prevent hidden URL fields from blocking submit with browser native validation.
          endpointInput.value = '';
        }
        if (isOllama && !endpointInput.value) {
          const defaultEndpoint = selectedOpt?.dataset.endpoint || '';
          if (defaultEndpoint) {
            endpointInput.placeholder = defaultEndpoint;
          }
        }
        if (isAzure) {
          endpointInput.placeholder = 'https://myresource.openai.azure.com';
        }
        if (isOpenResponses) {
          endpointInput.placeholder = 'https://api.example.com';
        }
      }
      const optionalNote = form.querySelector('[data-aiu-endpoint-optional-note]');
      if (optionalNote) {
        optionalNote.hidden = showEndpoint;
      }
      const customHint = form.querySelector('[data-aiu-endpoint-required-hint]');
      if (customHint) {
        customHint.hidden = !isCustom;
      }
      const ollamaHint = form.querySelector('[data-aiu-endpoint-ollama-hint]');
      if (ollamaHint) {
        ollamaHint.hidden = !isOllama;
      }
      const azureHint = form.querySelector('[data-aiu-endpoint-azure-hint]');
      if (azureHint) {
        azureHint.hidden = !isAzure;
      }
      const openResponsesHint = form.querySelector('[data-aiu-endpoint-openresponses-hint]');
      if (openResponsesHint) {
        openResponsesHint.hidden = !isOpenResponses;
      }
      const apiVersionField = form.querySelector('[data-aiu-api-version-field]');
      if (apiVersionField) {
        apiVersionField.hidden = !isAzure;
      }
      const apiKeyField = form.querySelector('[data-aiu-api-key-field]');
      if (apiKeyField) {
        apiKeyField.hidden = isOllama;
      }
      if (apiKeyInput) {
        const isEdit = parseInt(uidInput?.value || '0', 10) > 0;
        const hasStoredApiKey = form.dataset.aiuHasStoredApiKey === '1';
        apiKeyInput.required = !isOllama && !(isEdit && hasStoredApiKey);
        if (isOllama) {
          apiKeyInput.value = '';
        }
      }
      // Azure: update model field labels to deployment-centric terminology.
      const modelFieldLabelText = form.querySelector('[data-aiu-model-field-label] [data-aiu-field-label-text]');
      if (modelFieldLabelText instanceof HTMLElement) {
        modelFieldLabelText.textContent = isAzure
          ? 'Chat deployment'
          : (modelFieldLabelText.dataset.defaultLabel || modelFieldLabelText.textContent);
        if (!modelFieldLabelText.dataset.defaultLabel && !isAzure) {
          modelFieldLabelText.dataset.defaultLabel = modelFieldLabelText.textContent;
        }
      }
      const embeddingModelFieldLabelText = form.querySelector('[data-aiu-embedding-model-field-label] [data-aiu-field-label-text]');
      if (embeddingModelFieldLabelText instanceof HTMLElement) {
        embeddingModelFieldLabelText.textContent = isAzure
          ? 'Embedding deployment'
          : (embeddingModelFieldLabelText.dataset.defaultLabel || embeddingModelFieldLabelText.textContent);
        if (!embeddingModelFieldLabelText.dataset.defaultLabel && !isAzure) {
          embeddingModelFieldLabelText.dataset.defaultLabel = embeddingModelFieldLabelText.textContent;
        }
      }
    };

    this.modelCache = [];

    form.querySelectorAll('[data-aiu-adapter-chip]').forEach((chip) => {
      chip.addEventListener('click', () => {
        const type = chip.getAttribute('data-adapter-type');
        if (!type || !adapterSelect) {
          return;
        }
        adapterSelect.value = type;
        adapterSelect.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });

    if (tempInput && tempOutput) {
      tempInput.addEventListener('input', () => {
        tempOutput.textContent = tempInput.value;
      });
    }
    if (adapterSelect && endpointInput) {
      adapterSelect.addEventListener('change', () => {
        if (!endpointInput.value) {
          const opt = adapterSelect.selectedOptions[0];
          const defaultEndpoint = opt?.dataset.endpoint || '';
          if (defaultEndpoint) {
            endpointInput.placeholder = defaultEndpoint;
          }
        }
        // Model ids are vendor-specific — clear so a Mistral id is not kept as OpenAI Custom.
        if (modelInput) {
          modelInput.value = '';
          modelInput.hidden = false;
          modelInput.required = false;
        }
        if (modelSelect) {
          modelSelect.value = '';
        }
        if (embeddingModelInput) {
          embeddingModelInput.value = '';
          embeddingModelInput.hidden = false;
          embeddingModelInput.dataset.aiuCommittedEmbedding = '';
        }
        if (embeddingModelSelect) {
          embeddingModelSelect.value = '';
        }
        syncChipActiveFromSelect();
        syncAdapterConnectionUi();
        this.loadModels({
          adapterSelect,
          endpointInput,
          apiKeyInput,
          uidInput,
          modelSelect,
          modelInput,
          modelHint,
          embeddingModelSelect,
          embeddingModelInput,
          embeddingModelHint,
        });
      });
    }
    if (modelSelect) {
      modelSelect.addEventListener('change', () => {
        const val = modelSelect.value;
        if (val === '__custom__') {
          modelInput.hidden = false;
          modelInput.required = true;
          modelInput.focus();
          return;
        }
        if (val === '') {
          modelInput.value = '';
          this.applyCapabilities(form, []);
          return;
        }
        modelInput.value = val;
        modelInput.hidden = true;
        const info = this.modelCache.find((m) => m.id === val);
        if (info) {
          this.applyCapabilities(form, info.capabilities || []);
          if (modelHint) {
            modelHint.hidden = false;
            modelHint.textContent = llFormat(LL.hintSource, 'Source: %s', info.source);
          }
        }
      });
    }
    if (embeddingModelSelect && embeddingModelInput) {
      const readCommittedEmbedding = () =>
        (embeddingModelInput.dataset.aiuCommittedEmbedding ?? embeddingModelInput.value ?? '').trim();
      const writeCommittedEmbedding = (value) => {
        embeddingModelInput.dataset.aiuCommittedEmbedding = value;
      };
      writeCommittedEmbedding((embeddingModelInput.value || '').trim());

      const applyEmbeddingModelSelection = (val) => {
        if (val === '__custom__') {
          embeddingModelInput.hidden = false;
          embeddingModelInput.focus();
          return;
        }
        if (val === '') {
          embeddingModelInput.value = '';
          embeddingModelInput.hidden = false;
          writeCommittedEmbedding('');
          this.setEmbeddingsCapability(form, false);
          return;
        }
        embeddingModelInput.value = val;
        embeddingModelInput.hidden = true;
        writeCommittedEmbedding(val);
        this.setEmbeddingsCapability(form, true);
        const info = this.modelCache.find((m) => m.id === val);
        if (info && embeddingModelHint) {
          embeddingModelHint.hidden = false;
          embeddingModelHint.textContent = llFormat(LL.hintSource, 'Source: %s', info.source);
        }
      };

      embeddingModelSelect.addEventListener('change', () => {
        const val = embeddingModelSelect.value;
        const previous = readCommittedEmbedding();
        const next = val === '__custom__' ? embeddingModelInput.value.trim() : val.trim();

        // Opening the free-text path is not a model change yet.
        if (val === '__custom__') {
          applyEmbeddingModelSelection(val);
          return;
        }

        confirmEmbeddingModelChange(
          previous,
          next,
          () => applyEmbeddingModelSelection(val),
          () => restoreEmbeddingModelFields(embeddingModelSelect, embeddingModelInput, previous),
        );
      });
      embeddingModelInput.addEventListener('change', () => {
        const next = embeddingModelInput.value.trim();
        const previous = readCommittedEmbedding();
        confirmEmbeddingModelChange(
          previous,
          next,
          () => {
            writeCommittedEmbedding(next);
            this.setEmbeddingsCapability(form, next !== '');
          },
          () => {
            restoreEmbeddingModelFields(embeddingModelSelect, embeddingModelInput, previous);
          },
        );
      });
      embeddingModelInput.addEventListener('input', () => {
        // Live capability tick while typing; commit still goes through change+confirm.
        this.setEmbeddingsCapability(form, embeddingModelInput.value.trim() !== '');
      });
    }
    const embeddingsCheckbox = form.querySelector('input[name="capabilities[]"][value="embeddings"]');
    if (embeddingsCheckbox instanceof HTMLInputElement) {
      embeddingsCheckbox.addEventListener('change', () => {
        const modelId = (embeddingModelInput?.value || '').trim();
        if (embeddingsCheckbox.checked || modelId === '') {
          return;
        }
        embeddingsCheckbox.checked = true;
        Notification.warning(
          ll(LL.embeddingCapabilityBlockedTitle, 'Cannot turn off embeddings'),
          ll(
            LL.embeddingCapabilityBlockedMessage,
            'An embedding model is selected. Clear the embedding model before you turn off the embeddings capability.',
          ),
        );
      });
    }
    if (modelRefresh) {
      modelRefresh.addEventListener('click', async (evt) => {
        evt.preventDefault();
        evt.stopPropagation();
        modelRefresh.classList.add('is-loading');
        modelRefresh.disabled = true;
        try {
          const count = await this.loadModels({
            form,
            adapterSelect,
            endpointInput,
            apiKeyInput,
            uidInput,
            modelSelect,
            modelInput,
            modelHint,
            embeddingModelSelect,
            embeddingModelInput,
            embeddingModelHint,
            refresh: true,
          });
          if (count > 0) {
            Notification.success(
              ll(LL.modelsRefreshed, 'Models refreshed'),
              llFormat(LL.modelsLoaded, '%s model(s) loaded.', count),
            );
          } else {
            Notification.warning(
              ll(LL.noModelsTitle, 'No models'),
              ll(
                LL.noModelsBody,
                'Live discovery returned 0 models. Check endpoint + API key, or enter the ID manually.',
              ),
            );
          }
        } finally {
          modelRefresh.classList.remove('is-loading');
          modelRefresh.disabled = false;
        }
      });
    }
    if (revealBtn) {
      const keyInput = form.querySelector('[data-aiu-api-key-input]');
      revealBtn.addEventListener('click', () => {
        if (!keyInput) {
          return;
        }
        const show = keyInput.type === 'password';
        keyInput.type = show ? 'text' : 'password';
        revealBtn.textContent = show
          ? ll(LL.revealHide, 'Hide')
          : ll(LL.revealShow, 'Show');
      });
    }

    this.bindApiKeyKeepBlankOnEdit(form, apiKeyInput);

    // Auto-load on open when adapter already selected (edit path).
    if (adapterSelect && adapterSelect.value) {
      syncChipActiveFromSelect();
      syncAdapterConnectionUi();
      this.loadModels({
        adapterSelect,
        endpointInput,
        apiKeyInput,
        uidInput,
        modelSelect,
        modelInput,
        modelHint,
        embeddingModelSelect,
        embeddingModelInput,
        embeddingModelHint,
      });
    }

    syncAdapterConnectionUi();

    // After api-key autofill clear: AJAX save keeps validation errors in the drawer.
    form.addEventListener('submit', (evt) => {
      evt.preventDefault();
      evt.stopPropagation();
      void this.saveForm(form);
    });
  }

  /**
   * Edit forms keep api_key blank to preserve the stored cipher. Browser password
   * managers often autofill the field anyway (yellow background), which POSTs a
   * non-empty key and wrongly resets last_status to unknown. Clear autofill unless
   * the admin intentionally typed/pasted a replacement key.
   *
   * @param {HTMLFormElement} form
   * @param {HTMLInputElement|null|undefined} apiKeyInput
   */
  bindApiKeyKeepBlankOnEdit(form, apiKeyInput) {
    if (!(apiKeyInput instanceof HTMLInputElement)) {
      return;
    }
    if (apiKeyInput.dataset.aiuApiKeyKeepBlank !== '1' && form.dataset.aiuHasStoredApiKey !== '1') {
      return;
    }

    const markDirty = () => {
      apiKeyInput.dataset.aiuApiKeyDirty = '1';
    };
    const clearUnlessDirty = () => {
      if (apiKeyInput.dataset.aiuApiKeyDirty === '1') {
        return;
      }
      apiKeyInput.value = '';
    };

    apiKeyInput.addEventListener('focus', () => {
      apiKeyInput.removeAttribute('readonly');
    });
    apiKeyInput.addEventListener('keydown', markDirty);
    apiKeyInput.addEventListener('paste', markDirty);
    apiKeyInput.addEventListener('beforeinput', markDirty);

    clearUnlessDirty();
    window.setTimeout(clearUnlessDirty, 50);
    window.setTimeout(clearUnlessDirty, 400);

    form.addEventListener('submit', () => {
      clearUnlessDirty();
    });
  }

  async loadModels({
    adapterSelect,
    endpointInput,
    apiKeyInput,
    uidInput,
    modelSelect,
    modelInput,
    modelHint,
    embeddingModelSelect = null,
    embeddingModelInput = null,
    embeddingModelHint = null,
    refresh = false,
  }) {
    if (!ROUTES.models || !modelSelect || !modelInput) {
      return 0;
    }
    const adapterType = adapterSelect?.value || '';
    if (!adapterType) {
      modelSelect.hidden = true;
      modelInput.hidden = false;
      return 0;
    }
    const params = new URLSearchParams();
    const uid = parseInt(uidInput?.value || '0', 10);
    if (uid > 0) {
      params.set('uid', String(uid));
    }
    params.set('adapterType', adapterType);
    const endpointValue = (endpointInput?.value || '').trim();
    if (endpointValue !== '') {
      params.set('endpoint', endpointValue);
    } else {
      const defaultEndpoint = (adapterSelect?.selectedOptions[0]?.dataset.endpoint || '').trim();
      if (defaultEndpoint !== '') {
        params.set('endpoint', defaultEndpoint);
      }
    }
    if (apiKeyInput?.value) {
      params.set('apiKey', apiKeyInput.value);
    }
    if (refresh) {
      params.set('refresh', '1');
    }

    const url = appendQuery(ROUTES.models, params);
    try {
      const response = await new AjaxRequest(url).get();
      const json = await response.resolve();
      const models = Array.isArray(json.models) ? json.models : [];
      this.modelCache = models;
      this.renderModels(modelSelect, modelInput, modelHint, filterChatModels(models));
      if (embeddingModelSelect && embeddingModelInput) {
        this.renderModels(
          embeddingModelSelect,
          embeddingModelInput,
          embeddingModelHint,
          filterEmbeddingModels(models),
        );
      }
      return models.length;
    } catch (err) {
      modelSelect.hidden = true;
      modelInput.hidden = false;
      if (embeddingModelSelect && embeddingModelInput) {
        embeddingModelSelect.hidden = true;
        embeddingModelInput.hidden = false;
      }
      if (modelHint) {
        modelHint.hidden = false;
        modelHint.textContent = ll(
          LL.hintLiveFetchFailed,
          'Live model fetch failed. Enter ID manually.',
        );
      }
      return 0;
    }
  }

  renderModels(select, input, hint, models) {
    while (select.options.length > 2) {
      select.remove(2);
    }
    if (!models.length) {
      select.hidden = true;
      input.hidden = false;
      if (hint) {
        hint.hidden = false;
        hint.textContent = ll(
          LL.hintNoModelsDiscovered,
          'No models discovered. Enter ID manually.',
        );
      }
      return;
    }
    const currentValue = input.value;
    let matched = false;
    for (const m of models) {
      const opt = document.createElement('option');
      opt.value = m.id;
      opt.textContent = m.label && m.label !== m.id ? `${m.id} — ${m.label}` : m.id;
      if (m.id === currentValue) {
        opt.selected = true;
        matched = true;
      }
      select.appendChild(opt);
    }
    select.hidden = false;
    if (matched) {
      input.hidden = true;
      if (hint) {
        hint.hidden = true;
      }
    } else if (currentValue) {
      // Existing custom value — keep free-text visible, mark __custom__.
      const customOpt = Array.from(select.options).find((o) => o.value === '__custom__');
      if (customOpt) {
        customOpt.selected = true;
      }
      input.hidden = false;
    } else {
      input.hidden = true;
    }
  }

  applyCapabilities(form, caps) {
    const embeddingModel = (form.querySelector('[data-aiu-embedding-model-input]')?.value || '').trim();
    const checkboxes = form.querySelectorAll('input[name="capabilities[]"]');
    checkboxes.forEach((cb) => {
      if (cb.value === CAP_EMBEDDINGS && embeddingModel !== '') {
        cb.checked = true;
        return;
      }
      cb.checked = caps.includes(cb.value);
    });
  }

  setEmbeddingsCapability(form, enabled) {
    const checkbox = form.querySelector('input[name="capabilities[]"][value="embeddings"]');
    if (checkbox) {
      checkbox.checked = enabled;
    }
  }

  async runTest(uid, button) {
    if (!ROUTES.test || !(button instanceof HTMLElement)) {
      return;
    }
    if (button.classList.contains('is-testing')) {
      return;
    }

    const row = button.closest('[data-aiu-provider-row]');
    const statusCell = row?.querySelector('[data-aiu-provider-status]');
    const startedAt = Date.now();
    const minAnimMs = 900;

    button.setAttribute('disabled', 'disabled');
    button.classList.remove('is-connected', 'is-failed');
    button.classList.add('is-testing');
    button.setAttribute('aria-busy', 'true');
    // Force style recalc so animation starts immediately.
    void button.offsetWidth;

    const finishTesting = async () => {
      const elapsed = Date.now() - startedAt;
      if (elapsed < minAnimMs) {
        await new Promise((resolve) => {
          window.setTimeout(resolve, minAnimMs - elapsed);
        });
      }
      button.classList.remove('is-testing');
      button.removeAttribute('aria-busy');
      button.removeAttribute('disabled');
    };

    try {
      const response = await new AjaxRequest(ROUTES.test).post(this.withPageId({ uid }));
      const json = await response.resolve();
      await finishTesting();

      if (json.ok) {
        button.classList.add('is-connected');
        button.dataset.aiuStatus = 'connected';
        if (statusCell) {
          statusCell.innerHTML = '<span class="badge badge-success">Connected</span>';
        }
        Notification.success(
          ll(LL.notifyConnectionOk, 'Connection OK'),
          json.message ?? llFormat(LL.hintLatency, 'Latency %sms', json.latencyMs),
        );
      } else {
        button.classList.add('is-failed');
        button.dataset.aiuStatus = 'disconnected';
        if (statusCell) {
          statusCell.innerHTML = '<span class="badge badge-danger">Disconnected</span>';
        }
        Notification.error(
          ll(LL.connectionFailed, 'Connection failed'),
          json.message ?? ll(LL.messageUnknown, 'unknown'),
        );
      }
    } catch (err) {
      await finishTesting();
      button.classList.add('is-failed');
      button.dataset.aiuStatus = 'disconnected';
      Notification.error(ll(LL.testFailed, 'Test failed'), String(err));
    }
  }

  async makeDefault(uid) {
    if (!ROUTES.setDefault) {
      return;
    }
    try {
      await new AjaxRequest(ROUTES.setDefault).post(this.withPageId({ uid }));
      window.location.reload();
    } catch (err) {
      Notification.error(ll(LL.setDefaultFailed, 'Set default failed'), String(err));
    }
  }

  withPageId(payload = {}) {
    if (this.pageId > 0) {
      return { ...payload, id: this.pageId };
    }
    return payload;
  }
}

/** @type {WeakMap<Element, ProviderDrawer>} */
const drawersByRoot = new WeakMap();

/**
 * @param {Element} root
 */
export function mountProviderList(root) {
  if (!(root instanceof Element)) {
    return;
  }

  let drawer = drawersByRoot.get(root);
  if (!drawer) {
    observeBrowserAutocomplete(root);
    drawer = new ProviderDrawer(root);
    drawersByRoot.set(root, drawer);
  }

  openProviderEditFromQuery(drawer, root);
}

document.querySelectorAll('[data-aiu-provider-list]').forEach((root) => {
  mountProviderList(root);
});

document.addEventListener('typo3-module-loaded', () => {
  document.querySelectorAll('[data-aiu-provider-list]').forEach((root) => {
    mountProviderList(root);
  });
});

/**
 * @param {ProviderDrawer} drawer
 * @param {Element} root
 */
function openProviderEditFromQuery(drawer, root) {
  const editUid = new URLSearchParams(window.location.search).get('editUid');
  if (!editUid) {
    return;
  }

  const trigger = root.querySelector(
    `[data-aiu-provider-row][data-uid="${CSS.escape(editUid)}"] [data-aiu-drawer-trigger]`,
  );
  if (!(trigger instanceof HTMLAnchorElement)) {
    return;
  }

  requestAnimationFrame(() => {
    drawer.triggerElement = trigger;
    drawer.openWithUrl(trigger.href);
  });
}
