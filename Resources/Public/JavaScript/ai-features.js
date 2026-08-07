import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import { bindFilterSearchInput, observeBrowserAutocomplete } from './disable-browser-autocomplete.js';

const getPageId = (root) => {
  const id = parseInt(root?.dataset?.aiuPageId || '0', 10);
  return Number.isFinite(id) && id > 0 ? id : 0;
};

const INIT_FLAG = 'aiuFeaturesInit';

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
const drawerFocusables = (root) => Array.from(root.querySelectorAll(DRAWER_FOCUSABLE))
  .filter((el) => el instanceof HTMLElement && !el.hasAttribute('disabled') && el.getClientRects().length > 0);

/**
 * Legacy fallback when the Fluid root does not expose data-aiu-features-managed-extensions.
 * Prefer the server-provided list (ExtensionSettingsRegistry) for third-party extensions.
 */
const EXT_CONF_EXTENSION_KEYS_FALLBACK = ['ns_t3af'];

/**
 * @param {HTMLElement} root
 * @returns {Set<string>}
 */
const parseManagedExtensionKeys = (root) => {
  const raw = root.getAttribute('data-aiu-features-managed-extensions');
  if (raw) {
    try {
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed)) {
        return new Set(parsed.map((key) => String(key).trim()).filter(Boolean));
      }
    } catch (_error) {
      // Fall through to legacy list.
    }
  }

  return new Set(EXT_CONF_EXTENSION_KEYS_FALLBACK);
};

/** Fallback when Fluid does not output settingsScope on the card. */
const EXT_CONF_SCOPE_BY_ID = {
  'universe-auth-api-translation': 'universe-auth-api-translation',
  'universe-xai': 'xai',
  'ai-feature-toggles': 'feature configurations',
  'aa-feature-toggles': 't3aa-feature-toggles',
  'aa-ai-audio': 'ai audio',
  'aa-ai-filemeta': 'ai filemeta',
  'ai-chat_assistance': 'seo',
  'ai-seo': 'seo',
  'ai-pages': 'page',
  'ai-content': 'content',
  'ai-translation': 'translation',
  'ai-media': 't3ai-media',
  't3cs-ai-engine': 'ai engine',
  't3cs-rate-limiting': 'rate limiting',
  't3cs-training': 'training',
  't3cs-feature-configurations': 'feature configurations',
};

const LL = {
  metaCountFiltered: 'aiFeatures.js.metaCountFiltered',
  t3csAdapterUnavailableTitle: 'aiFeatures.js.t3csAdapterUnavailableTitle',
  t3csHfLlmCompatibilityTitle: 'aiFeatures.js.t3csHfLlmCompatibilityTitle',
  t3csHfLlmCompatibility: 'aiFeatures.js.t3csHfLlmCompatibility',
  secretKeyRevealShow: 'aiFeatures.js.secretKey.revealShow',
  secretKeyRevealHide: 'aiFeatures.js.secretKey.revealHide',
};

const T3CS_AI_ENGINE_SCOPE = 'ai engine';
const T3CS_PROVIDER_OVERRIDE_FIELDS = ['defaultModel', 'defaultEmbeddingsModel'];
const T3AI_PROVIDER_OVERRIDE_FIELDS = [
  'defaultProviderForSeo',
  'defaultProviderForPages',
  'defaultProviderForContent',
  'defaultProviderForTranslation',
];
const SCOPES_WITH_PROVIDER_OVERRIDES = new Set([
  T3CS_AI_ENGINE_SCOPE,
  'seo',
  'page',
  'content',
  'translation',
]);

const T3AI_SCOPE_TO_FIELD = {
  seo: 'defaultProviderForSeo',
  page: 'defaultProviderForPages',
  content: 'defaultProviderForContent',
  translation: 'defaultProviderForTranslation',
};

/**
 * @returns {string[]}
 */
const providerOverrideFieldsForScope = (scope) => {
  if (scope === T3CS_AI_ENGINE_SCOPE) {
    return T3CS_PROVIDER_OVERRIDE_FIELDS;
  }
  const field = T3AI_SCOPE_TO_FIELD[scope];
  return field ? [field] : [];
};

/**
 * @param {string} key
 * @param {string} fallback
 */
function ll(key, fallback) {
  const value = typeof TYPO3 !== 'undefined' && TYPO3.lang ? TYPO3.lang[key] : undefined;
  return typeof value === 'string' && value !== '' ? value : fallback;
}

/**
 * @param {string} key
 * @param {string} fallback
 * @param {Array<string|number>} args
 */
function llFormat(key, fallback, ...args) {
  let text = ll(key, fallback);
  args.forEach((arg, index) => {
    text = text.replace(`%${index + 1}$s`, String(arg));
    text = text.replace('%s', String(arg));
  });
  return text;
}

function parseFeatures(root) {
  const raw = root.getAttribute('data-aiu-features-json') || '[]';
  try {
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch (_error) {
    return [];
  }
}

function normalize(text) {
  return String(text || '').toLowerCase();
}

/**
 * @param {HTMLElement} formRoot
 */
function bindSecretFieldReveal(formRoot) {
  formRoot.querySelectorAll('[data-aiu-toggle-secret-reveal]').forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }
    button.addEventListener('click', () => {
      const inline = button.closest('.aiu-field__inline');
      const input = inline?.querySelector('[data-aiu-secret-key-input]');
      if (!(input instanceof HTMLInputElement)) {
        return;
      }
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      button.textContent = show
        ? ll(LL.secretKeyRevealHide, 'Hide')
        : ll(LL.secretKeyRevealShow, 'Show');
    });
  });
}

/**
 * @param {HTMLElement} formRoot
 */
function bindMediaPaletteTabs(formRoot) {
  const palette = formRoot.querySelector('[data-aiu-features-media-palette]');
  if (!(palette instanceof HTMLElement)) {
    return;
  }

  const tabs = Array.from(palette.querySelectorAll('[data-aiu-features-palette-tab]'));
  const panels = Array.from(palette.querySelectorAll('[data-aiu-features-palette-panel]'));
  if (tabs.length === 0 || panels.length === 0) {
    return;
  }

  const activate = (paletteId) => {
    tabs.forEach((tab) => {
      const isActive = tab.getAttribute('data-aiu-features-palette-tab') === paletteId;
      tab.classList.toggle('is-active', isActive);
      tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    panels.forEach((panel) => {
      const isActive = panel.getAttribute('data-aiu-features-palette-panel') === paletteId;
      panel.classList.toggle('is-active', isActive);
      panel.toggleAttribute('hidden', !isActive);
    });
  };

  tabs.forEach((tab) => {
    if (!(tab instanceof HTMLButtonElement)) {
      return;
    }
    tab.addEventListener('click', () => {
      activate(String(tab.getAttribute('data-aiu-features-palette-tab') || ''));
    });
  });
}

function createFlash(root, message, level = 'success') {
  const existing = root.querySelector('[data-aiu-features-flash]');
  if (existing) {
    existing.remove();
  }

  const flash = document.createElement('div');
  flash.className = level === 'success' ? 'aiu-flash aiu-flash--success' : 'aiu-flash aiu-flash--info';
  flash.setAttribute('data-aiu-features-flash', '1');
  flash.textContent = message;
  root.prepend(flash);

  window.setTimeout(() => {
    flash.remove();
  }, 3200);
}

function getAjaxUrl(routeKey, root) {
  if (root instanceof HTMLElement) {
    if (routeKey === 'nst3af_feature_settings_get') {
      const fromRoot = root.getAttribute('data-aiu-feature-settings-get-uri') || '';
      if (fromRoot !== '') {
        return fromRoot;
      }
    }
    if (routeKey === 'nst3af_feature_settings_save') {
      const fromRoot = root.getAttribute('data-aiu-feature-settings-save-uri') || '';
      if (fromRoot !== '') {
        return fromRoot;
      }
    }
  }
  const ajaxUrls = (window.TYPO3 && window.TYPO3.settings && window.TYPO3.settings.ajaxUrls) || {};
  return ajaxUrls[routeKey] || '';
}

/**
 * @param {HTMLElement} card
 */
function resolveConfigExtensionKey(card) {
  const configKey = String(card.getAttribute('data-aiu-feature-config-extkey') || '').trim();
  if (configKey !== '') {
    return configKey;
  }
  return String(card.getAttribute('data-aiu-feature-extkey') || '').trim();
}

function resolveSettingsScope(card, featuresById) {
  const fromAttr = String(card.getAttribute('data-aiu-feature-settings-scope') || '').trim();
  if (fromAttr !== '') {
    return fromAttr;
  }
  const id = String(card.getAttribute('data-aiu-feature-id') || '');
  const fromCatalog = featuresById.get(id);
  if (fromCatalog && fromCatalog.settingsScope) {
    return String(fromCatalog.settingsScope);
  }
  return EXT_CONF_SCOPE_BY_ID[id] || '';
}

function initAiFeatures(root) {
  if (!(root instanceof HTMLElement)) {
    return;
  }
  if (root.dataset[INIT_FLAG] === '1') {
    return;
  }
  root.dataset[INIT_FLAG] = '1';

  const isReadOnly = root.dataset.canModifyExtensionSettings === '0';

  const applyReadOnlyToForm = (formRoot) => {
    if (!isReadOnly || !(formRoot instanceof HTMLElement)) {
      return;
    }
    formRoot.querySelectorAll('input, select, textarea, button').forEach((element) => {
      if (
        element instanceof HTMLInputElement
        || element instanceof HTMLSelectElement
        || element instanceof HTMLTextAreaElement
        || element instanceof HTMLButtonElement
      ) {
        element.disabled = true;
      }
    });
  };

  const allFeatures = parseFeatures(root);
  const featuresById = new Map(allFeatures.map((item) => [String(item.id || ''), item]));
  const cards = Array.from(root.querySelectorAll('[data-aiu-features-card]'));
  const extensionSelect = root.querySelector('[data-aiu-features-extension]');
  const searchInput = root.querySelector('[data-aiu-features-search]');
  const emptyState = root.querySelector('[data-aiu-features-empty]');
  const drawer = root.querySelector('[data-aiu-features-drawer]');
  const drawerTitle = root.querySelector('[data-aiu-feature-drawer-title]');
  const drawerSubtitle = root.querySelector('[data-aiu-feature-drawer-subtitle]');
  const drawerClose = root.querySelector('[data-aiu-features-drawer-close]');
  const drawerCancel = root.querySelector('[data-aiu-features-drawer-cancel]');
  const drawerSave = root.querySelector('[data-aiu-features-drawer-save]');
  const localConfig = root.querySelector('[data-aiu-features-local-config]');
  const extConfConfig = root.querySelector('[data-aiu-features-ext-conf-config]');
  const extConfForm = root.querySelector('[data-aiu-features-ext-conf-form]');
  const extConfLoading = root.querySelector('[data-aiu-features-ext-conf-loading]');

  const managedExtensionKeys = parseManagedExtensionKeys(root);
  const total = cards.length;
  const state = { search: '', extKey: 'all', activeScope: '', activeExtension: '' };
  /** @type {HTMLElement|null} */
  let drawerTrigger = null;
  /** @type {((event: KeyboardEvent) => void)|null} */
  let drawerKeyHandler = null;
  const metaCountNode = root.querySelector('[data-aiu-features-meta-count]');

  const isFiltered = () => state.search !== '' || state.extKey !== 'all';

  const updateMetaCount = (visible) => {
    if (metaCountNode instanceof HTMLElement) {
      metaCountNode.textContent = isFiltered()
        ? llFormat(LL.metaCountFiltered, '%1$s / %2$s', visible, total)
        : String(total);
    }
  };

  const deactivateDrawerFocus = () => {
    if (drawerKeyHandler) {
      document.removeEventListener('keydown', drawerKeyHandler);
      drawerKeyHandler = null;
    }
  };

  /**
   * A11Y-01: move focus into the dialog, trap Tab, close on Escape, restore on close.
   */
  const activateDrawerFocus = () => {
    if (!(drawer instanceof HTMLElement)) {
      return;
    }
    deactivateDrawerFocus();
    const panel = drawer.querySelector('.aiu-drawer__panel');
    if (!(panel instanceof HTMLElement)) {
      return;
    }
    if (!panel.hasAttribute('tabindex')) {
      panel.setAttribute('tabindex', '-1');
    }
    const focusables = drawerFocusables(panel);
    const initial = focusables[0] ?? panel;
    window.requestAnimationFrame(() => {
      initial.focus();
    });

    drawerKeyHandler = (event) => {
      if (!(event instanceof KeyboardEvent) || !drawer.classList.contains('is-open')) {
        return;
      }
      if (event.key === 'Escape') {
        event.preventDefault();
        setDrawerOpen(false);
        setDrawerLocalMode();
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
    document.addEventListener('keydown', drawerKeyHandler);
  };

  const setDrawerOpen = (open) => {
    if (!(drawer instanceof HTMLElement)) {
      return;
    }
    if (open) {
      drawer.classList.remove('is-closing');
      drawer.classList.add('is-open');
      drawer.setAttribute('aria-hidden', 'false');
      activateDrawerFocus();
      return;
    }
    if (drawer.classList.contains('is-closing')) {
      return;
    }
    deactivateDrawerFocus();
    drawer.classList.remove('is-open');
    drawer.classList.add('is-closing');

    const panel = drawer.querySelector('.aiu-drawer__panel');
    const finish = () => {
      if (!drawer.classList.contains('is-closing')) {
        return;
      }
      drawer.classList.remove('is-closing');
      drawer.setAttribute('aria-hidden', 'true');
      const trigger = drawerTrigger;
      drawerTrigger = null;
      if (trigger instanceof HTMLElement && document.contains(trigger)) {
        trigger.focus();
      }
    };
    if (!(panel instanceof HTMLElement)) {
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
  };

  const setDrawerLocalMode = () => {
    state.activeScope = '';
    state.activeExtension = '';
    if (drawerSave instanceof HTMLButtonElement) {
      drawerSave.disabled = false;
      drawerSave.removeAttribute('aria-disabled');
      drawerSave.classList.remove('disabled');
    }
    if (extConfForm instanceof HTMLFormElement) {
      extConfForm.innerHTML = '';
    }
    if (extConfConfig instanceof HTMLElement) {
      extConfConfig.classList.add('is-hidden');
      extConfConfig.setAttribute('hidden', 'hidden');
    }
    if (extConfLoading instanceof HTMLElement) {
      extConfLoading.classList.add('is-hidden');
      extConfLoading.setAttribute('hidden', 'hidden');
    }
    if (localConfig instanceof HTMLElement) {
      localConfig.classList.remove('is-hidden');
      localConfig.removeAttribute('hidden');
    }
  };

  const setDrawerExtConfMode = () => {
    if (localConfig instanceof HTMLElement) {
      localConfig.classList.add('is-hidden');
      localConfig.setAttribute('hidden', 'hidden');
    }
    if (extConfConfig instanceof HTMLElement) {
      extConfConfig.classList.remove('is-hidden');
      extConfConfig.removeAttribute('hidden');
    }
  };

  const isT3CsProviderOverrideUnavailable = (select) => {
    if (!(select instanceof HTMLSelectElement)) {
      return false;
    }
    const option = select.selectedOptions[0];
    if (!(option instanceof HTMLOptionElement)) {
      return false;
    }
    if (option.value === 'default') {
      return false;
    }
    return option.dataset.adapterAvailable === '0';
  };

  const selectedOption = (select) => {
    if (!(select instanceof HTMLSelectElement)) {
      return null;
    }
    const option = select.selectedOptions[0];
    return option instanceof HTMLOptionElement ? option : null;
  };

  const hasUnavailableProviderSelection = () => {
    if (!(extConfForm instanceof HTMLFormElement) || !SCOPES_WITH_PROVIDER_OVERRIDES.has(state.activeScope)) {
      return false;
    }
    return providerOverrideFieldsForScope(state.activeScope).some((fieldName) => {
      const select = extConfForm.querySelector(`select[name="${fieldName}"]`);
      return isT3CsProviderOverrideUnavailable(select);
    });
  };

  const hasT3CsHfCompatibilityConflict = () => {
    if (state.activeScope !== T3CS_AI_ENGINE_SCOPE || !(extConfForm instanceof HTMLFormElement)) {
      return false;
    }
    const embeddingSelect = extConfForm.querySelector('select[name="defaultEmbeddingsModel"]');
    const llmSelect = extConfForm.querySelector('select[name="defaultModel"]');
    if (!(embeddingSelect instanceof HTMLSelectElement) || !(llmSelect instanceof HTMLSelectElement)) {
      return false;
    }
    const embeddingOption = selectedOption(embeddingSelect);
    const llmOption = selectedOption(llmSelect);
    const hfEmbeddings = embeddingOption?.dataset.hfEmbeddings === '1';
    const llmSupported = llmOption?.dataset.hfLlmSupported === '1';
    return hfEmbeddings && !llmSupported;
  };

  const syncProviderOverrideSaveState = () => {
    if (!(drawerSave instanceof HTMLButtonElement)) {
      return;
    }
    const blocked = hasUnavailableProviderSelection() || hasT3CsHfCompatibilityConflict();
    drawerSave.disabled = blocked;
    drawerSave.setAttribute('aria-disabled', blocked ? 'true' : 'false');
    drawerSave.classList.toggle('disabled', blocked);
  };

  const notifyT3CsProviderOverrideIfUnavailable = (select, showToast = true) => {
    if (!(select instanceof HTMLSelectElement) || !isT3CsProviderOverrideUnavailable(select)) {
      return;
    }
    if (!showToast) {
      return;
    }
    const option = select.selectedOptions[0];
    const message = option?.dataset.unavailableMessage
      || ll(
        'aiFeatures.js.t3csAdapterUnavailable',
        'This provider adapter is not installed. Install the matching Symfony AI platform package first.',
      );
    const title = ll(LL.t3csAdapterUnavailableTitle, 'Provider adapter not installed');
    Notification.warning(title, message);
  };

  const ensureT3CsHfCompatibilityNode = (llmSelect) => {
    if (!(llmSelect instanceof HTMLSelectElement)) {
      return null;
    }
    const field = llmSelect.closest('[data-aiu-features-ext-conf-field]') || llmSelect.closest('.mb-3');
    if (!(field instanceof HTMLElement)) {
      return null;
    }
    let node = field.querySelector('[data-aiu-t3cs-hf-compatibility]');
    if (node instanceof HTMLElement) {
      return node;
    }
    node = document.createElement('p');
    node.className = 'form-text text-variant';
    node.setAttribute('data-aiu-t3cs-hf-compatibility', '1');
    node.hidden = true;
    field.appendChild(node);
    return node;
  };

  const syncT3CsHfCompatibilityWarning = (showToast = false) => {
    if (state.activeScope !== T3CS_AI_ENGINE_SCOPE || !(extConfForm instanceof HTMLFormElement)) {
      return;
    }
    const embeddingSelect = extConfForm.querySelector('select[name="defaultEmbeddingsModel"]');
    const llmSelect = extConfForm.querySelector('select[name="defaultModel"]');
    if (!(embeddingSelect instanceof HTMLSelectElement) || !(llmSelect instanceof HTMLSelectElement)) {
      return;
    }

    const warningNode = ensureT3CsHfCompatibilityNode(llmSelect);
    if (!(warningNode instanceof HTMLElement)) {
      return;
    }

    const embeddingOption = selectedOption(embeddingSelect);
    const llmOption = selectedOption(llmSelect);
    const hfEmbeddings = embeddingOption?.dataset.hfEmbeddings === '1';
    const llmSupported = llmOption?.dataset.hfLlmSupported === '1';
    const shouldWarn = hfEmbeddings && !llmSupported;
    const message = ll(
      LL.t3csHfLlmCompatibility,
      'With HuggingFace embedding we only support OpenAI and Mistral.',
    );

    warningNode.textContent = message;
    warningNode.hidden = !shouldWarn;
    if (shouldWarn && showToast) {
      Notification.warning(
        ll(LL.t3csHfLlmCompatibilityTitle, 'Embedding compatibility'),
        message,
      );
    }
    syncProviderOverrideSaveState();
  };

  const notifyT3CsHfCompatibilityConflictIfNeeded = (showToast = true) => {
    if (!hasT3CsHfCompatibilityConflict()) {
      return;
    }
    const message = ll(
      LL.t3csHfLlmCompatibility,
      'With HuggingFace embedding we only support OpenAI and Mistral.',
    );
    if (showToast) {
      Notification.warning(
        ll(LL.t3csHfLlmCompatibilityTitle, 'Embedding compatibility'),
        message,
      );
    }
  };

  const bindProviderOverrideWarnings = (scope) => {
    if (!SCOPES_WITH_PROVIDER_OVERRIDES.has(scope) || !(extConfForm instanceof HTMLFormElement)) {
      if (drawerSave instanceof HTMLButtonElement) {
        drawerSave.disabled = false;
        drawerSave.removeAttribute('aria-disabled');
        drawerSave.classList.remove('disabled');
      }
      return;
    }
    const fieldNames = providerOverrideFieldsForScope(scope);
    fieldNames.forEach((fieldName) => {
      const select = extConfForm.querySelector(`select[name="${fieldName}"]`);
      if (!(select instanceof HTMLSelectElement)) {
        return;
      }
      if (select.dataset.t3csProviderWarningBound === '1') {
        return;
      }
      select.dataset.t3csProviderWarningBound = '1';
      select.addEventListener('change', () => {
        notifyT3CsProviderOverrideIfUnavailable(select, true);
        syncT3CsHfCompatibilityWarning(true);
        syncProviderOverrideSaveState();
      });
    });
    syncProviderOverrideSaveState();
    syncT3CsHfCompatibilityWarning(false);
    fieldNames.forEach((fieldName) => {
      const select = extConfForm.querySelector(`select[name="${fieldName}"]`);
      if (isT3CsProviderOverrideUnavailable(select)) {
        notifyT3CsProviderOverrideIfUnavailable(select, true);
      }
    });
  };

  const restoreMediaPaletteTab = (formRoot, tabId) => {
    if (!tabId || !(formRoot instanceof HTMLElement)) {
      return;
    }
    const tab = formRoot.querySelector(`[data-aiu-features-palette-tab="${tabId}"]`);
    if (tab instanceof HTMLButtonElement) {
      tab.click();
    }
  };

  const loadExtConfSettings = async (scope, extensionKey, options = {}) => {
    const getUrl = getAjaxUrl('nst3af_feature_settings_get', root);
    if (getUrl === '' || !(extConfForm instanceof HTMLFormElement)) {
      createFlash(root, 'Feature settings route is unavailable. Flush caches and reload the backend.', 'info');
      setDrawerLocalMode();
      return;
    }

    state.activeScope = scope;
    state.activeExtension = extensionKey;
    setDrawerExtConfMode();
    const preservePaletteTab = options.preservePaletteTab === true
      ? extConfForm.querySelector('[data-aiu-features-palette-tab].is-active')?.getAttribute('data-aiu-features-palette-tab') || ''
      : '';
    extConfForm.innerHTML = '';
    if (extConfLoading instanceof HTMLElement) {
      extConfLoading.classList.remove('is-hidden');
      extConfLoading.removeAttribute('hidden');
    }

    try {
      const response = await new AjaxRequest(getUrl)
        .withQueryArguments({ scope, extension: extensionKey, id: getPageId(root) })
        .get();
      const payload = await response.resolve();
      if (!payload.success || !payload.html) {
        createFlash(root, payload.message || 'Could not load settings.', 'info');
        setDrawerLocalMode();
        return;
      }
      extConfForm.innerHTML = payload.html;
      bindMediaPaletteTabs(extConfForm);
      bindSecretFieldReveal(extConfForm);
      bindProviderOverrideWarnings(scope);
      restoreMediaPaletteTab(extConfForm, preservePaletteTab);
      applyReadOnlyToForm(extConfForm);
    } catch (_error) {
      createFlash(root, 'Could not load settings.', 'info');
      setDrawerLocalMode();
    } finally {
      if (extConfLoading instanceof HTMLElement) {
        extConfLoading.classList.add('is-hidden');
        extConfLoading.setAttribute('hidden', 'hidden');
      }
    }
  };

  const saveExtConfSettings = async () => {
    if (isReadOnly) {
      return false;
    }
    const saveUrl = getAjaxUrl('nst3af_feature_settings_save', root);
    if (
      saveUrl === ''
      || !(extConfForm instanceof HTMLFormElement)
      || state.activeScope === ''
      || state.activeExtension === ''
    ) {
      return false;
    }

    if (!extConfForm.checkValidity()) {
      extConfForm.reportValidity();
      return false;
    }

    if (SCOPES_WITH_PROVIDER_OVERRIDES.has(state.activeScope) && hasUnavailableProviderSelection()) {
      syncProviderOverrideSaveState();
      providerOverrideFieldsForScope(state.activeScope).forEach((fieldName) => {
        const select = extConfForm.querySelector(`select[name="${fieldName}"]`);
        notifyT3CsProviderOverrideIfUnavailable(select, true);
      });
      return false;
    }

    if (hasT3CsHfCompatibilityConflict()) {
      syncProviderOverrideSaveState();
      notifyT3CsHfCompatibilityConflictIfNeeded(true);
      return false;
    }

    const formData = new FormData(extConfForm);
    formData.set('scope', state.activeScope);
    formData.set('extension', state.activeExtension);
    const pageId = getPageId(root);
    if (pageId > 0) {
      formData.set('id', String(pageId));
    }

    try {
      const response = await new AjaxRequest(saveUrl).post(formData);
      const payload = await response.resolve();
      if (payload.success) {
        if (payload.title && payload.message) {
          Notification.success(payload.title, payload.message);
        } else {
          createFlash(root, payload.message || 'Settings saved.');
        }
        return true;
      }
      Notification.error(payload.title || 'Error', payload.message || 'Could not save settings.');
      return false;
    } catch (_error) {
      Notification.error('Error', 'Could not save settings.');
      return false;
    }
  };

  const applyFilters = () => {
    const query = normalize(state.search);
    let visible = 0;

    cards.forEach((card) => {
      const id = String(card.getAttribute('data-aiu-feature-id') || '');
      const extKey = normalize(card.getAttribute('data-aiu-feature-extkey') || '');
      const name = normalize(card.getAttribute('data-aiu-feature-name') || '');
      const subtitle = normalize(card.getAttribute('data-aiu-feature-subtitle') || '');
      const description = normalize(card.getAttribute('data-aiu-feature-description') || '');
      const tags = (featuresById.get(id)?.tags || []).map(normalize).join(' ');

      const matchesExt = state.extKey === 'all' || extKey === normalize(state.extKey);
      const matchesQuery = query === ''
        || name.includes(query)
        || subtitle.includes(query)
        || description.includes(query)
        || extKey.includes(query)
        || tags.includes(query);

      const show = matchesExt && matchesQuery;
      card.classList.toggle('is-hidden', !show);
      card.toggleAttribute('hidden', !show);
      if (show) {
        visible += 1;
      }
    });

    if (emptyState instanceof HTMLElement) {
      emptyState.classList.toggle('is-hidden', visible !== 0);
      emptyState.toggleAttribute('hidden', visible !== 0);
    }

    updateMetaCount(visible);
  };

  if (extensionSelect instanceof HTMLSelectElement) {
    extensionSelect.addEventListener('change', () => {
      state.extKey = String(extensionSelect.value || 'all');
      applyFilters();
    });
  }

  if (searchInput instanceof HTMLInputElement) {
    bindFilterSearchInput(searchInput, (value) => {
      state.search = value;
      applyFilters();
    });
  }

  const openFeatureCard = async (card) => {
    drawerTrigger = card instanceof HTMLElement ? card : null;
    const displayExtKey = String(card.getAttribute('data-aiu-feature-extkey') || '');
    const configExtKey = resolveConfigExtensionKey(card);
    const settingsScope = resolveSettingsScope(card, featuresById);

    if (drawerTitle instanceof HTMLElement) {
      drawerTitle.textContent = String(card.getAttribute('data-aiu-feature-name') || 'AI Feature');
    }
    if (drawerSubtitle instanceof HTMLElement) {
      const subtitle = String(card.getAttribute('data-aiu-feature-subtitle') || '');
      drawerSubtitle.textContent = subtitle !== '' ? `${subtitle} · ${displayExtKey}` : displayExtKey;
    }

    setDrawerOpen(true);

    if (managedExtensionKeys.has(configExtKey) && settingsScope !== '') {
      await loadExtConfSettings(settingsScope, configExtKey);
      return;
    }
    setDrawerLocalMode();
  };

  cards.forEach((card) => {
    card.addEventListener('click', () => {
      openFeatureCard(card);
    });
    card.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openFeatureCard(card);
      }
    });
  });

  if (drawerClose instanceof HTMLElement) {
    drawerClose.addEventListener('click', () => {
      setDrawerOpen(false);
      setDrawerLocalMode();
    });
  }
  if (drawerCancel instanceof HTMLElement) {
    drawerCancel.addEventListener('click', () => {
      setDrawerOpen(false);
      setDrawerLocalMode();
    });
  }
  if (drawer instanceof HTMLElement) {
    drawer.addEventListener('click', (event) => {
      if (event.target === drawer) {
        setDrawerOpen(false);
        setDrawerLocalMode();
      }
    });
  }

  if (drawerSave instanceof HTMLElement) {
    drawerSave.addEventListener('click', async () => {
      if (state.activeScope !== '') {
        const saved = await saveExtConfSettings();
        if (saved) {
          const scope = state.activeScope;
          const extensionKey = state.activeExtension;
          await loadExtConfSettings(scope, extensionKey, { preservePaletteTab: true });
        }
        return;
      }
      setDrawerOpen(false);
      createFlash(root, 'Feature configuration saved (preview mode).');
    });
  }

  applyFilters();
}

function boot() {
  document.querySelectorAll('[data-aiu-features-root]').forEach((root) => {
    observeBrowserAutocomplete(root);
    initAiFeatures(root);
  });
}

boot();
document.addEventListener('typo3-module-loaded', boot);
