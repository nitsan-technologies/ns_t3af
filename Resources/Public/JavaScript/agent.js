import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import DocumentService from '@typo3/core/document-service.js';
import { ModuleStateStorage } from '@typo3/backend/storage/module-state-storage.js';

const STORAGE_OPEN_KEY = 'nst3af.agent.open';
const STORAGE_PREFS_KEY = 'nst3af.agent.prefs';
/** Safe default — leaves Live Search on Ctrl/Cmd+K (CTO / Sanjay). */
const HOTKEY_DEFAULT = 'mod+shift+k';
/** Opt-in: takes Live Search chord. */
const HOTKEY_OPT_IN_K = 'mod+k';

/** @type {AgentController | null} */
let controller = null;

/**
 * @param {string} chord
 * @returns {string}
 */
function hotkeyLabel(chord) {
  console.log('chord:', chord);
  return chord === HOTKEY_OPT_IN_K ? 'Ctrl/Cmd+K' : 'Ctrl/Cmd+Shift+K';
}

/**
 * @param {object} meta
 * @returns {boolean}
 */
function hasTurnGuardWarning(meta) {
  const warning = meta?.turnGuardWarning;
  return warning != null && warning !== '' && String(warning) !== 'null';
}

/**
 * @returns {string}
 */
function readHotkeyPref() {
  try {
    const prefs = JSON.parse(localStorage.getItem(STORAGE_PREFS_KEY) ?? '{}');
    if (prefs?.hotkey === HOTKEY_OPT_IN_K || prefs?.hotkey === HOTKEY_DEFAULT) {
      return prefs.hotkey;
    }
  } catch {
    // ignore malformed prefs
  }
  return HOTKEY_DEFAULT;
}

/**
 * @param {KeyboardEvent} event
 * @param {string} chord
 * @returns {boolean}
 */
function matchesAgentHotkey(event, chord) {
  if (!(event.metaKey || event.ctrlKey) || event.altKey) {
    return false;
  }
  if (event.key.toLowerCase() !== 'k') {
    return false;
  }
  if (chord === HOTKEY_OPT_IN_K) {
    return !event.shiftKey;
  }
  return event.shiftKey;
}

/**
 * @param {string} route
 * @returns {string}
 */
function ajaxUrl(route) {
  const urls = typeof TYPO3 !== 'undefined' ? TYPO3.settings?.ajaxUrls : null;
  return urls?.[route] ?? '';
}

/**
 * @param {string} key
 * @param {string} fallback
 * @param {Array<string|number>} [replacements]
 * @returns {string}
 */
function lang(key, fallback, replacements = []) {
  let value = typeof TYPO3 !== 'undefined' && TYPO3.lang ? TYPO3.lang[key] : undefined;
  value = value ?? fallback;
  if (typeof value !== 'string') {
    value = String(value ?? fallback ?? '');
  }
  replacements.forEach((replacement, index) => {
    value = value.replace(`%${index + 1}$s`, String(replacement));
  });
  return value;
}

/**
 * @param {unknown} error
 * @returns {string}
 */
function errorMessage(error) {
  if (error instanceof Error) {
    return error.message;
  }
  if (typeof error === 'string') {
    return error;
  }
  if (error && typeof error === 'object' && 'message' in error) {
    return String(error.message);
  }
  return 'Something went wrong.';
}

/**
 * @param {unknown} value
 * @param {string} fallback
 * @returns {string}
 */
function messageContent(value, fallback = '') {
  if (typeof value === 'string') {
    return value;
  }
  if (value === null || value === undefined) {
    return fallback;
  }
  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }
  return fallback;
}

/**
 * Active backend module route (e.g. web_layout, nst3af_providers).
 * Agent chrome lives outside the module iframe — use ModuleMenu, not body.dataset.
 * @returns {string}
 */
function resolveModuleRoute() {
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
 * @returns {{ pageId: number, module: string }}
 */
function resolveBackendContext() {
  const state = ModuleStateStorage.current('web');
  const pageId = Number.parseInt(state?.identifier || '0', 10);

  return {
    pageId: Number.isFinite(pageId) && pageId > 0 ? pageId : 0,
    module: resolveModuleRoute(),
  };
}

/**
 * @param {Array<object>} trace
 * @returns {string}
 */
function renderToolTrace(trace) {
  if (!Array.isArray(trace) || trace.length === 0) {
    return '';
  }

  const steps = trace.map((entry, index) => {
    const stepLabel = escapeHtml(String(entry.step ?? `step-${index + 1}`));
    const status = escapeHtml(String(entry.status ?? ''));
    const latency = entry.latencyMs != null && Number.isFinite(Number(entry.latencyMs))
      ? `${Number(entry.latencyMs)} ms`
      : '';
    let requestJson = '';
    let responseJson = '';
    try {
      requestJson = JSON.stringify(entry.request ?? null, null, 2);
    } catch {
      requestJson = String(entry.request ?? '');
    }
    try {
      responseJson = JSON.stringify(entry.response ?? null, null, 2);
    } catch {
      responseJson = String(entry.response ?? '');
    }

    return `<details class="nst3af-agent-trace__step">
      <summary>
        <span class="nst3af-agent-trace__step-label">${stepLabel}</span>
        <span class="nst3af-agent-trace__status">${status}</span>
        ${latency ? `<span class="nst3af-agent-trace__latency">${escapeHtml(latency)}</span>` : ''}
      </summary>
      <div class="nst3af-agent-trace__blocks">
        <details class="nst3af-agent-trace__block">
          <summary>${escapeHtml(lang('agent.trace.request', 'Request'))}</summary>
          <pre><code>${escapeHtml(requestJson)}</code></pre>
        </details>
        <details class="nst3af-agent-trace__block">
          <summary>${escapeHtml(lang('agent.trace.response', 'Response'))}</summary>
          <pre><code>${escapeHtml(responseJson)}</code></pre>
        </details>
      </div>
    </details>`;
  }).join('');

  return `<details class="nst3af-agent-trace">
    <summary>${escapeHtml(lang('agent.trace.title', 'Tool trace'))}</summary>
    <div class="nst3af-agent-trace__steps">${steps}</div>
  </details>`;
}

/**
 * @param {string} text
 * @returns {string}
 */
function escapeHtml(text) {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

/**
 * @param {string} line
 * @returns {string}
 */
function renderPlainInline(line) {
  return escapeHtml(line).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
}

/**
 * @param {string} text
 * @returns {string}
 */
function renderPlainProse(text) {
  const lines = text.split('\n');
  const out = [];
  let inList = false;

  for (const line of lines) {
    const trimmed = line.trim();
    if (/^---+$/.test(trimmed)) {
      if (inList) {
        out.push('</ul>');
        inList = false;
      }
      out.push('<hr>');
      continue;
    }

    const bullet = line.match(/^- (.+)$/);
    if (bullet) {
      if (!inList) {
        out.push('<ul>');
        inList = true;
      }
      out.push(`<li>${renderPlainInline(bullet[1])}</li>`);
      continue;
    }

    if (inList) {
      out.push('</ul>');
      inList = false;
    }

    if (line === '') {
      out.push('<br>');
      continue;
    }

    out.push(`${renderPlainInline(line)}<br>`);
  }

  if (inList) {
    out.push('</ul>');
  }

  return out.join('');
}

/**
 * @param {string} content
 * @returns {Array<{type: 'prose' | 'code', text: string}>}
 */
function splitMessageSegments(content) {
  /** @type {Array<{type: 'prose' | 'code', text: string}>} */
  const segments = [];
  const re = /```(\w*)\n([\s\S]*?)```/g;
  let lastIndex = 0;
  let match = re.exec(content);

  while (match !== null) {
    if (match.index > lastIndex) {
      segments.push({ type: 'prose', text: content.slice(lastIndex, match.index) });
    }
    segments.push({ type: 'code', text: match[2] ?? '' });
    lastIndex = match.index + match[0].length;
    match = re.exec(content);
  }

  if (lastIndex < content.length) {
    segments.push({ type: 'prose', text: content.slice(lastIndex) });
  }

  if (segments.length === 0) {
    segments.push({ type: 'prose', text: content });
  }

  return segments;
}

/**
 * @param {string} content
 * @param {(text: string) => string} renderProse
 * @returns {string}
 */
function renderSegmentedMessageBody(content, renderProse) {
  return splitMessageSegments(content).map((segment) => {
    if (segment.type === 'code') {
      return `<pre><code>${escapeHtml(segment.text.trim())}</code></pre>`;
    }
    return renderProse(segment.text);
  }).join('');
}

/**
 * @param {string} content
 * @returns {string}
 */
function renderPlainMessageBody(content) {
  return renderSegmentedMessageBody(content, renderPlainProse);
}

/** @type {(content: string) => string} */
let renderMessageBodyImpl = renderPlainMessageBody;

/** @type {Promise<void> | null} */
let messageRendererReady = null;

/**
 * Prefer core marked + DOMPurify (TYPO3 >=13.4.5, all v14). Fallback keeps working on v12.4.
 * @returns {Promise<void>}
 */
function ensureMessageRenderer() {
  if (messageRendererReady === null) {
    messageRendererReady = (async () => {
      try {
        const [{ marked }, dompurifyModule] = await Promise.all([
          import('marked'),
          import('dompurify'),
        ]);
        const DOMPurify = dompurifyModule.default ?? dompurifyModule;
        marked.setOptions({ gfm: true, breaks: false });
        const sanitizeConfig = {
          ALLOWED_TAGS: ['blockquote', 'br', 'code', 'em', 'hr', 'kbd', 'li', 'ol', 'p', 'pre', 'strong', 'ul'],
          ALLOWED_ATTR: [],
        };

        renderMessageBodyImpl = (content) => renderSegmentedMessageBody(content, (text) => {
          const trimmed = text.trim();
          if (trimmed === '') {
            return '';
          }
          const parsed = marked.parse(text, { async: false });
          return DOMPurify.sanitize(parsed, sanitizeConfig);
        });
      } catch {
        // ponytail: TYPO3 <13.4.5 has no core marked/dompurify import map — plain subset above
      }
    })();
  }

  return messageRendererReady;
}

/**
 * @param {string} content
 * @returns {string}
 */
function renderMessageBody(content) {
  return renderMessageBodyImpl(content);
}

class AgentController {
  /**
   * @param {HTMLElement} root
   */
  constructor(root) {
    this.root = root;
    this.backdrop = root.querySelector('[data-nst3af-agent-backdrop]');
    this.panel = root.querySelector('[data-nst3af-agent-panel]');
    this.stream = root.querySelector('[data-nst3af-agent-stream]');
    this.contextEl = root.querySelector('[data-nst3af-agent-context]');
    this.disclosure = root.querySelector('[data-nst3af-agent-disclosure]');
    this.input = root.querySelector('[data-nst3af-agent-input]');
    this.composer = root.querySelector('[data-nst3af-agent-composer]');
    this.attachMenu = root.querySelector('[data-nst3af-agent-attach-menu]');
    this.fileInput = root.querySelector('[data-nst3af-agent-file-input]');
    this.autocomplete = root.querySelector('[data-nst3af-agent-autocomplete]');
    this.settingsLink = root.querySelector('[data-nst3af-agent-settings]');
    this.lastFocus = null;
    this.messages = [];
    this.context = {};
    this.starters = { executable: [], locked: [] };
    this.isOpen = false;
    this.isRunning = false;
    this.autocompleteMode = null;
    this.settingsHref = '#';
    this.disclosureShown = false;
    this.disclosureDismissed = false;
    this.greeting = null;
    this.isLoadingSession = false;
    this.loadedScopeKey = '';
    this.focusableSelector = 'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])';
    this.autocompleteIndex = -1;
    this.hotkey = readHotkeyPref();

    void ensureMessageRenderer().then(() => {
      if (this.stream && this.isOpen && !this.isLoadingSession) {
        this.renderStream();
      }
    });

    this.bindScopeRefresh();

    this.bindEvents();
    this.applyHotkeyChrome();
    this.loadSettingsLink();
  }

  /**
   * Scope key for DB-backed conversation rows (module + page).
   * @returns {string}
   */
  sessionScopeKey() {
    const ctx = resolveBackendContext();
    return `${ctx.module}:${ctx.pageId}`;
  }

  bindScopeRefresh() {
    const refresh = () => {
      void this.onBackendScopeChanged();
    };
    document.addEventListener('typo3:module-state-storage:update:web', refresh);
    document.addEventListener('typo3:module-state-storage:update-with-tree-identifier:web', refresh);
    document.addEventListener('typo3-module-load', refresh);
    document.addEventListener('typo3-module-loaded', refresh);
  }

  async onBackendScopeChanged() {
    if (!this.isOpen) {
      return;
    }
    const nextScope = this.sessionScopeKey();
    if (nextScope === this.loadedScopeKey) {
      return;
    }
    await this.reloadSessionForCurrentScope();
  }

  async reloadSessionForCurrentScope() {
    this.isLoadingSession = true;
    this.panel?.setAttribute('aria-busy', 'true');
    this.renderLoadingSkeleton();
    await this.restoreSession();
    this.isLoadingSession = false;
    this.loadedScopeKey = this.sessionScopeKey();
    this.panel?.removeAttribute('aria-busy');
    this.renderContext();
    this.renderStream();
  }

  /**
   * Sync toolbar title + footer kbd with the active chord.
   */
  applyHotkeyChrome() {
    const label = hotkeyLabel(this.hotkey);
    const title = lang('agent.toolbar.titlePrefix', 'AI Agent') + ' (' + label + ')';
    document.querySelectorAll('[data-nst3af-agent-open]').forEach((node) => {
      if (node instanceof HTMLElement) {
        node.setAttribute('title', title);
        node.setAttribute('aria-label', title);
      }
    });
    document.querySelectorAll('[data-nst3af-agent-hotkey-kbd]').forEach((node) => {
      node.textContent = label;
    });
  }

  bindEvents() {
    document.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) {
        return;
      }
      if (target.closest('[data-nst3af-agent-open]')) {
        event.preventDefault();
        this.open(target.closest('[data-nst3af-agent-open]'));
      }
      if (target.closest('[data-nst3af-agent-close]')) {
        event.preventDefault();
        this.close();
      }
      if (target.closest('[data-nst3af-agent-send]')) {
        event.preventDefault();
        this.submitTurn();
      }
      if (target.closest('[data-nst3af-agent-backdrop]')) {
        this.close();
      }
      if (target.closest('[data-nst3af-agent-starter]')) {
        const btn = target.closest('[data-nst3af-agent-starter]');
        if (!(btn instanceof HTMLButtonElement)) {
          return;
        }
        event.preventDefault();
        this.runStarter(btn);
      }
      if (target.closest('[data-nst3af-agent-ac-item]')) {
        const item = target.closest('[data-nst3af-agent-ac-item]');
        if (!(item instanceof HTMLButtonElement)) {
          return;
        }
        event.preventDefault();
        this.pickAutocomplete(item);
      }
      if (target.closest('[data-nst3af-agent-draft-toggle]')) {
        event.preventDefault();
        this.toggleDraftField(target.closest('[data-nst3af-agent-draft-toggle]'));
      }
      if (target.closest('[data-nst3af-agent-draft-apply]')) {
        event.preventDefault();
        this.applyDraft(target.closest('[data-nst3af-agent-draft-apply]'), 'all');
      }
      if (target.closest('[data-nst3af-agent-draft-apply-safe]')) {
        event.preventDefault();
        this.applyDraft(target.closest('[data-nst3af-agent-draft-apply-safe]'), 'safe');
      }
      if (target.closest('[data-nst3af-agent-draft-discard]')) {
        event.preventDefault();
        this.discardDraft(target.closest('[data-nst3af-agent-draft-discard]'));
      }
      if (target.closest('[data-nst3af-agent-undo]')) {
        event.preventDefault();
        this.undoChange(target.closest('[data-nst3af-agent-undo]'));
      }
      if (target.closest('[data-nst3af-agent-disclosure-dismiss]')) {
        event.preventDefault();
        this.dismissDisclosure();
      }
      if (target.closest('[data-nst3af-agent-attach-toggle]')) {
        event.preventDefault();
        this.toggleAttachMenu();
      }
      if (target.closest('[data-nst3af-agent-attach-files]')) {
        event.preventDefault();
        this.openFilePicker();
      }
      if (target.closest('[data-nst3af-agent-handoff-dismiss]')) {
        event.preventDefault();
        const card = target.closest('[data-nst3af-agent-handoff]');
        card?.remove();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (!this.isOpen) {
        if (matchesAgentHotkey(event, this.hotkey)) {
          const tag = (event.target instanceof HTMLElement ? event.target.tagName : '').toLowerCase();
          if (tag === 'input' || tag === 'textarea' || (event.target instanceof HTMLElement && event.target.isContentEditable)) {
            return;
          }
          event.preventDefault();
          this.open(document.querySelector('[data-nst3af-agent-open]'));
        }
        return;
      }

      if (event.key === 'Escape') {
        event.preventDefault();
        this.close();
        return;
      }

      if (this.autocomplete && !this.autocomplete.hidden) {
        this.handleAutocompleteKeydown(event);
        return;
      }

      this.handleFocusTrap(event);
    });

    this.input?.addEventListener('input', () => {
      this.handleComposerInput();
    });

    this.input?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        this.submitTurn();
      }
    });

    this.fileInput?.addEventListener('change', () => {
      const files = this.fileInput instanceof HTMLInputElement ? [...(this.fileInput.files ?? [])] : [];
      if (files.length > 0) {
        void this.uploadFiles(files);
      }
    });

    this.composer?.addEventListener('dragover', (event) => {
      event.preventDefault();
      this.composer?.classList.add('nst3af-agent-composer--dragover');
    });
    this.composer?.addEventListener('dragleave', () => {
      this.composer?.classList.remove('nst3af-agent-composer--dragover');
    });
    this.composer?.addEventListener('drop', (event) => {
      event.preventDefault();
      this.composer?.classList.remove('nst3af-agent-composer--dragover');
      const transfer = event.dataTransfer;
      if (!transfer) {
        return;
      }
      const files = [...transfer.files];
      if (files.length > 0) {
        void this.uploadFiles(files);
      }
    });

    document.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target || !this.attachMenu || this.attachMenu.hidden) {
        return;
      }
      if (!target.closest('[data-nst3af-agent-attach-toggle]') && !target.closest('[data-nst3af-agent-attach-menu]')) {
        this.hideAttachMenu();
      }
    });
  }

  async loadSettingsLink() {
    const url = ajaxUrl('nst3af_agent_settings_link');
    if (url === '' || !(this.settingsLink instanceof HTMLAnchorElement)) {
      return;
    }

    try {
      const response = await new AjaxRequest(url).get().then((r) => r.resolve());
      if (response?.ok && response.href) {
        this.settingsHref = response.href;
        this.settingsLink.href = response.href;
      }
    } catch {
      // Settings link stays as placeholder until route is reachable.
    }
  }

  /**
   * @param {Element|null} opener
   */
  async open(opener) {
    if (this.isOpen) {
      return;
    }

    this.hotkey = readHotkeyPref();
    this.applyHotkeyChrome();

    this.lastFocus = opener instanceof HTMLElement ? opener : document.activeElement;
    this.isOpen = true;
    this.root.hidden = false;
    this.backdrop.hidden = false;
    this.panel.hidden = false;
    this.panel.setAttribute('aria-hidden', 'false');

    this.messages = [];
    this.context = {};
    this.starters = { executable: [], locked: [] };
    this.isLoadingSession = true;
    this.panel.setAttribute('aria-busy', 'true');
    this.renderLoadingSkeleton();

    await this.restoreSession();
    this.isLoadingSession = false;
    this.loadedScopeKey = this.sessionScopeKey();
    this.panel.removeAttribute('aria-busy');

    this.renderContext();
    this.renderStream();

    if (!this.disclosureDismissed) {
      this.disclosure.hidden = false;
    } else {
      this.disclosure.hidden = true;
    }

    this.announce(lang('agent.live.opened', 'AI Agent opened.'));
    this.trapFocus();
    this.input?.focus();

    try {
      localStorage.setItem(STORAGE_OPEN_KEY, '1');
    } catch {
      // ponytail: localStorage may be unavailable; open state is session-only.
    }
  }

  close() {
    if (!this.isOpen) {
      return;
    }

    this.isOpen = false;
    this.hideAutocomplete();
    this.panel.setAttribute('aria-hidden', 'true');
    this.panel.hidden = true;
    this.backdrop.hidden = true;
    this.root.hidden = true;

    // Persist before hide so reopen restores the same transcript + meta.
    void this.persistSession();

    this.announce(lang('agent.live.closed', 'AI Agent closed.'));

    if (this.lastFocus instanceof HTMLElement) {
      this.lastFocus.focus();
    }

    try {
      localStorage.setItem(STORAGE_OPEN_KEY, '0');
    } catch {
      // ignore
    }
  }

  async restoreSession() {
    const url = ajaxUrl('nst3af_agent_conversation');
    if (url === '') {
      return;
    }

    const backendContext = resolveBackendContext();
    const query = new URL(url, window.location.href);
    query.searchParams.set('pageId', String(backendContext.pageId));
    query.searchParams.set('module', backendContext.module);

    try {
      const payload = await new AjaxRequest(query.toString()).get().then((r) => r.resolve());
      if (!payload?.ok) {
        return;
      }
      this.messages = Array.isArray(payload.messages) ? payload.messages : [];
      this.context = payload.context ?? backendContext;
      this.starters = payload.starters ?? { executable: [], locked: [] };
      this.greeting = payload.greeting ?? null;
      this.disclosureDismissed = payload.disclosureDismissed === true;
      if (this.disclosureDismissed) {
        this.disclosure.hidden = true;
      }
      this.loadedScopeKey = this.sessionScopeKey();
    } catch {
      this.context = backendContext;
      this.loadedScopeKey = this.sessionScopeKey();
    }
  }

  renderLoadingSkeleton() {
    if (this.contextEl) {
      this.contextEl.innerHTML = [
        '<span class="nst3af-agent-skeleton nst3af-agent-skeleton--chip" aria-hidden="true"></span>',
        '<span class="nst3af-agent-skeleton nst3af-agent-skeleton--chip" aria-hidden="true"></span>',
        '<span class="nst3af-agent-skeleton nst3af-agent-skeleton--chip" aria-hidden="true"></span>',
      ].join('');
    }
    if (this.stream) {
      this.stream.innerHTML = [
        '<div class="nst3af-agent-skeleton nst3af-agent-skeleton--line" aria-hidden="true"></div>',
        '<div class="nst3af-agent-skeleton nst3af-agent-skeleton--line nst3af-agent-skeleton--medium" aria-hidden="true"></div>',
        '<div class="nst3af-agent-skeleton nst3af-agent-skeleton--line nst3af-agent-skeleton--short" aria-hidden="true"></div>',
      ].join('');
      this.stream.setAttribute('aria-busy', 'true');
    }
  }

  renderContext() {
    if (!this.contextEl || this.isLoadingSession) {
      return;
    }

    const chips = Array.isArray(this.context.chips) ? this.context.chips : [];
    const dimChip = this.context.contextAware
      ? `<span class="nst3af-agent-ctxchip nst3af-agent-ctxchip--dim">${escapeHtml(lang('agent.context.aware', 'Knows what you are looking at'))}</span>`
      : '';
    this.contextEl.innerHTML = dimChip + chips.map((chip) => (
      `<span class="nst3af-agent-ctxchip">${escapeHtml(String(chip.label ?? ''))}: ${escapeHtml(String(chip.value ?? ''))}</span>`
    )).join('');
  }

  async dismissDisclosure() {
    this.disclosureDismissed = true;
    this.disclosure.hidden = true;
    await this.persistSession(true);
  }

  renderStream() {
    if (!this.stream || this.isLoadingSession) {
      return;
    }

    this.stream.removeAttribute('aria-busy');
    const html = this.messages.map((message, index) => {
      if (message.role === 'user') {
        return `<div class="nst3af-agent-msg nst3af-agent-msg--user">${renderMessageBody(String(message.content ?? ''))}</div>`;
      }
      const meta = message.meta ?? {};
      if (meta.type === 'inline_draft' && meta.draft) {
        return this.renderInlineDraftMessage(message, index);
      }
      if (meta.type === 'readback_result') {
        return this.renderReadbackMessage(message);
      }
      if (meta.type === 'tool_result') {
        return this.renderToolResultMessage(message);
      }
      if (meta.type === 'locked') {
        return this.renderUpsellMessage(message);
      }
      let extra = '';
      if (hasTurnGuardWarning(meta)) {
        extra += `<div class="nst3af-agent-msg__warn" role="status">${escapeHtml(String(meta.turnGuardWarning))}</div>`;
      }
      if (meta.schedulerHandoff?.scheduleHref || meta.schedulerHandoff?.href) {
        extra += this.renderHandoffCard(meta.schedulerHandoff);
      }
      return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant"><div class="nst3af-agent-msg__who">AI Agent</div><div class="nst3af-agent-msg__body">${renderMessageBody(String(message.content ?? ''))}</div>${extra}</div>`;
    }).join('');

    this.stream.innerHTML = html;
    if (this.messages.length === 0) {
      this.renderGreeting();
    } else {
      this.renderStarters(this.starters);
    }
    this.stream.scrollTop = this.stream.scrollHeight;
  }

  renderGreeting() {
    if (!this.stream || this.messages.length > 0) {
      return;
    }

    const existing = this.stream.querySelector('[data-nst3af-agent-greeting]');
    existing?.remove();

    const g = this.greeting ?? {};
    const parts = [];
    if (g.page) {
      parts.push(`<strong>${escapeHtml(lang('agent.greeting.page', 'Page'))}:</strong> ${escapeHtml(String(g.page))}`);
    }
    if (g.module) {
      parts.push(`<strong>${escapeHtml(lang('agent.greeting.module', 'Module'))}:</strong> ${escapeHtml(String(g.module))}`);
    }
    if (g.language) {
      parts.push(`<strong>${escapeHtml(lang('agent.greeting.language', 'Language'))}:</strong> ${escapeHtml(String(g.language))}`);
    }
    if (g.brand) {
      parts.push(`<strong>${escapeHtml(lang('agent.greeting.brand', 'Brand'))}:</strong> ${escapeHtml(String(g.brand))}`);
    }

    const contextHtml = parts.length
      ? `<div class="nst3af-agent-greeting__context">${parts.join('<br>')}</div>`
      : '';

    const wrap = document.createElement('div');
    wrap.dataset.nst3afAgentGreeting = '1';
    wrap.className = 'nst3af-agent-msg nst3af-agent-msg--assistant nst3af-agent-greeting';
    wrap.innerHTML = `<div class="nst3af-agent-msg__who">AI Agent</div>
      <p class="nst3af-agent-greeting__lead">${escapeHtml(lang('agent.greeting.lead', 'I can read this screen, draft changes, and run tools — you approve before anything is written.'))}</p>
      ${contextHtml}`;

    const starters = document.createElement('div');
    starters.className = 'nst3af-agent-starters';
    const executable = Array.isArray(this.starters.executable) ? this.starters.executable : [];
    const locked = Array.isArray(this.starters.locked) ? this.starters.locked : [];

    if (executable.length > 0) {
      const label = document.createElement('div');
      label.className = 'nst3af-agent-starter-group-label';
      label.textContent = lang('agent.starters.executable', 'Suggested actions');
      starters.appendChild(label);
      executable.forEach((tool) => starters.appendChild(this.createStarterButton(tool, false)));
    }
    if (locked.length > 0) {
      const label = document.createElement('div');
      label.className = 'nst3af-agent-starter-group-label';
      label.textContent = lang('agent.starters.locked', 'Needs another extension');
      starters.appendChild(label);
      locked.forEach((tool) => starters.appendChild(this.createStarterButton(tool, true)));
    }

    if (starters.childNodes.length > 0) {
      wrap.appendChild(starters);
    }

    this.stream.appendChild(wrap);
    this.stream.scrollTop = this.stream.scrollHeight;
  }

  /**
   * @param {object} handoff
   * @returns {string}
   */
  renderHandoffCard(handoff) {
    const href = String(handoff.scheduleHref ?? handoff.href ?? '');
    if (href === '') {
      return '';
    }
    const title = escapeHtml(String(handoff.title ?? lang('agent.scheduler.handoffTitle', 'Schedule for later?')));
    const body = escapeHtml(String(handoff.body ?? handoff.note ?? ''));
    const label = escapeHtml(String(handoff.label ?? lang('agent.scheduler.handoffLabel', 'Open Scheduler & CLI')));
    const dismiss = escapeHtml(String(handoff.dismissLabel ?? lang('agent.scheduler.handoffDismiss', 'Not now')));
    return `<div class="nst3af-agent-handoff" data-nst3af-agent-handoff>
      <div class="nst3af-agent-handoff__title">${title}</div>
      <p class="nst3af-agent-handoff__body">${body}</p>
      <div class="nst3af-agent-handoff__actions">
        <a class="btn btn-primary btn-sm" href="${escapeHtml(href)}">${label}</a>
        <button type="button" class="btn btn-default btn-sm" data-nst3af-agent-handoff-dismiss>${dismiss}</button>
      </div>
    </div>`;
  }

  /**
   * @param {object} message
   * @returns {string}
   */
  renderUpsellMessage(message) {
    const meta = message.meta ?? {};
    const settingsHref = escapeHtml(String(meta.settingsHref ?? this.settingsHref));
    const settingsLabel = escapeHtml(String(meta.settingsLabel ?? lang('agent.modal.settings', 'Settings')));
    const ownerLabel = escapeHtml(String(meta.ownerLabel ?? meta.owner ?? ''));
    const toolName = escapeHtml(String(meta.tool ?? ''));
    return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant">
      <div class="nst3af-agent-msg__who">AI Agent</div>
      <div class="nst3af-agent-upsell">
        <div class="nst3af-agent-upsell__title">${escapeHtml(lang('agent.upsell.title', 'Extension required'))}</div>
        <p class="nst3af-agent-upsell__body">${escapeHtml(String(message.content ?? '')).replace(/\n\n/g, '</p><p class="nst3af-agent-upsell__body">')}</p>
        <a class="btn btn-default btn-sm" href="${settingsHref}">${settingsLabel}</a>
        <span class="visually-hidden">${toolName} ${ownerLabel}</span>
      </div>
    </div>`;
  }

  /**
   * @param {object} message
   * @returns {string}
   */
  renderToolResultMessage(message) {
    const meta = message.meta ?? {};
    const success = meta.success !== false;
    const toolLabel = escapeHtml(String(meta.toolCallLabel ?? meta.tool ?? ''));
    const severityLabel = escapeHtml(String(meta.severityLabel ?? meta.severity ?? 'read').toUpperCase());
    const autoRan = meta.autoRan === true;
    const facts = Array.isArray(meta.facts) ? meta.facts : [];
    const factsHtml = facts.length
      ? `<dl class="nst3af-agent-facts">${facts.map((fact) => (
        `<div class="nst3af-agent-facts__row"><dt>${escapeHtml(String(fact.label ?? ''))}</dt><dd>${escapeHtml(String(fact.value ?? ''))}</dd></div>`
      )).join('')}</dl>`
      : '';

    let detailsHtml = '';
    if (meta.details !== undefined && meta.details !== null) {
      let detailsJson = '';
      try {
        detailsJson = JSON.stringify(meta.details, null, 2);
      } catch {
        detailsJson = String(meta.details);
      }
      if (detailsJson !== '' && detailsJson !== 'null') {
        detailsHtml = `<details class="nst3af-agent-details"><summary>${escapeHtml(lang('agent.result.details', 'Details'))}</summary><pre><code>${escapeHtml(detailsJson)}</code></pre></details>`;
      }
    }

    const traceHtml = renderToolTrace(meta.trace);

    let extra = '';
    if (hasTurnGuardWarning(meta)) {
      extra += `<div class="nst3af-agent-msg__warn" role="status">${escapeHtml(String(meta.turnGuardWarning))}</div>`;
    }

    const autoHtml = autoRan
      ? `<span class="nst3af-agent-tcall__auto">${escapeHtml(lang('agent.toolCall.autoRan', 'ran automatically'))}</span>`
      : '';

    const statusClass = success ? '' : ' nst3af-agent-msg--error';
    return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant${statusClass}">
      <div class="nst3af-agent-msg__who">AI Agent</div>
      <div class="nst3af-agent-tcall">
        <div class="nst3af-agent-tcall__head">
          <span class="nst3af-agent-sev-dot nst3af-agent-sev-dot--read" aria-hidden="true"></span>
          <span class="nst3af-agent-tcall__badge">${severityLabel}</span>
          <strong>${toolLabel}</strong>
          ${autoHtml}
        </div>
        <div class="nst3af-agent-msg__body">${renderMessageBody(String(message.content ?? ''))}</div>
        ${factsHtml}
        ${traceHtml}
        ${detailsHtml}
        ${extra}
      </div>
    </div>`;
  }

  /**
   * @param {object} message
   * @param {number} messageIndex
   * @returns {string}
   */
  renderInlineDraftMessage(message, messageIndex) {
    const draft = message.meta?.draft ?? {};
    if (draft.kind === 'tool_confirmation') {
      return this.renderToolConfirmationDraft(message, messageIndex, draft);
    }

    const severity = String(draft.severity ?? 'write');
    const isDestructive = severity === 'destructive';
    const armed = draft.destructiveArmed === true;
    const applied = draft.applied === true;
    const discarded = draft.discarded === true;
    const fields = Array.isArray(draft.fields) ? draft.fields : [];
    const keptCount = fields.filter((f) => f.kept !== false).length;

    if (discarded) {
      return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant"><div class="nst3af-agent-msg__who">AI Agent</div><div class="nst3af-agent-msg__body">${escapeHtml(lang('agent.draft.discarded', 'Draft discarded. Nothing was written.'))}</div></div>`;
    }

    if (applied) {
      const appliedLabel = lang('agent.draft.applied', 'Applied %1$s of %2$s fields.')
        .replace('%1$s', String(keptCount))
        .replace('%2$s', String(fields.length));
      return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant"><div class="nst3af-agent-msg__who">AI Agent</div><div class="nst3af-agent-msg__body">${escapeHtml(appliedLabel)}</div></div>`;
    }

    const rows = fields.map((field) => {
      const kept = field.kept !== false;
      const dropClass = kept ? '' : ' nst3af-agent-draft__fld--dropped';
      const label = `${escapeHtml(String(field.table ?? ''))}:${Number(field.uid ?? 0)} · ${escapeHtml(String(field.field ?? ''))}`;
      const keepLabel = kept ? '✓' : '✕';
      const keepTitle = kept ? lang('agent.draft.drop', 'Drop') : lang('agent.draft.keep', 'Keep');
      return `<div class="nst3af-agent-draft__fld${dropClass}" data-field-key="${escapeHtml(String(field.key ?? ''))}">
        <div class="nst3af-agent-draft__fld-label">${label}</div>
        <div class="nst3af-agent-draft__fld-current">${escapeHtml(String(field.current ?? ''))}</div>
        <div class="nst3af-agent-draft__fld-proposed">${escapeHtml(String(field.proposed ?? ''))}</div>
        <div class="nst3af-agent-draft__mini">
          <button type="button" class="btn btn-default btn-sm" data-nst3af-agent-draft-toggle="1" data-message-index="${messageIndex}" data-field-key="${escapeHtml(String(field.key ?? ''))}" aria-pressed="${kept ? 'true' : 'false'}" title="${escapeHtml(keepTitle)}">${keepLabel}</button>
        </div>
      </div>`;
    }).join('');

    const applyLabel = isDestructive
      ? (armed ? lang('agent.draft.confirmSecond', 'Apply (2 of 2)') : lang('agent.draft.confirmFirst', 'Confirm (1 of 2)'))
      : lang('agent.draft.apply', 'Apply');
    const safeFieldCount = Number(draft.safeFieldCount ?? fields.filter((field) => field.safe === true).length);
    const safeApplyBtn = safeFieldCount > 0 && !isDestructive
      ? `<button type="button" class="btn btn-default btn-sm" data-nst3af-agent-draft-apply-safe="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(String(draft.draftId ?? ''))}">${escapeHtml(lang('agent.draft.applySafe', 'Apply safe fields'))}</button>`
      : '';

    const severityClass = isDestructive ? ' nst3af-agent-draft--destructive' : ' nst3af-agent-draft--write';
    const armedClass = armed ? ' nst3af-agent-draft--armed' : '';

    return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant">
      <div class="nst3af-agent-msg__who">AI Agent</div>
      <div class="nst3af-agent-draft${severityClass}${armedClass}" data-nst3af-agent-draft="1" data-draft-id="${escapeHtml(String(draft.draftId ?? ''))}" data-message-index="${messageIndex}" data-severity="${escapeHtml(severity)}">
        <div class="nst3af-agent-draft__header">
          <span class="nst3af-agent-sev-dot nst3af-agent-sev-dot--${escapeHtml(severity)}" aria-hidden="true"></span>
          <span class="nst3af-agent-draft__title">${escapeHtml(String(draft.tool ?? ''))}</span>
          <span class="nst3af-agent-draft__badge">${escapeHtml(lang('agent.draft.elicitation', 'MCP elicitation'))}</span>
        </div>
        <p class="nst3af-agent-draft__lead">${renderMessageBody(String(message.content ?? ''))}</p>
        <div class="nst3af-agent-draft__fields">${rows}</div>
        <div class="nst3af-agent-draft__actions">
          <button type="button" class="btn btn-primary btn-sm" data-nst3af-agent-draft-apply="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(String(draft.draftId ?? ''))}">${escapeHtml(applyLabel)}</button>
          ${safeApplyBtn}
          <button type="button" class="btn btn-default btn-sm" data-nst3af-agent-draft-discard="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(String(draft.draftId ?? ''))}">${escapeHtml(lang('agent.draft.discard', 'Discard'))}</button>
        </div>
      </div>
    </div>`;
  }

  /**
   * @param {object} message
   * @param {number} messageIndex
   * @param {object} draft
   * @returns {string}
   */
  renderToolConfirmationDraft(message, messageIndex, draft) {
    const severity = String(draft.severity ?? 'write');
    const isDestructive = severity === 'destructive';
    const armed = draft.destructiveArmed === true;
    const applied = draft.applied === true;
    const discarded = draft.discarded === true;
    const toolName = String(draft.tool ?? '');
    const summary = String(draft.summary ?? message.content ?? '');
    const args = Array.isArray(draft.arguments) ? draft.arguments : [];

    if (discarded) {
      return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant"><div class="nst3af-agent-msg__who">AI Agent</div><div class="nst3af-agent-msg__body">${escapeHtml(lang('agent.draft.discarded', 'Draft discarded. Nothing was written.'))}</div></div>`;
    }

    if (applied) {
      return '';
    }

    const argRows = args.map((entry) => {
      const key = escapeHtml(String(entry.key ?? ''));
      const value = escapeHtml(String(entry.value ?? ''));
      return `<div class="nst3af-agent-tool-confirm__arg"><dt>${key}</dt><dd>${value}</dd></div>`;
    }).join('');

    const argsBlock = argRows !== ''
      ? `<div class="nst3af-agent-tool-confirm__args"><div class="nst3af-agent-tool-confirm__args-title">${escapeHtml(lang('agent.draft.toolArguments', 'Parameters'))}</div>${argRows}</div>`
      : '';

    const applyLabel = isDestructive
      ? (armed ? lang('agent.draft.confirmSecond', 'Apply (2 of 2)') : lang('agent.draft.confirmFirst', 'Confirm (1 of 2)'))
      : lang('agent.draft.runTool', 'Run tool');

    const severityClass = isDestructive ? ' nst3af-agent-draft--destructive' : ' nst3af-agent-draft--write';
    const armedClass = armed ? ' nst3af-agent-draft--armed' : '';

    return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant">
      <div class="nst3af-agent-msg__who">AI Agent</div>
      <div class="nst3af-agent-draft nst3af-agent-tool-confirm${severityClass}${armedClass}" data-nst3af-agent-draft="1" data-draft-id="${escapeHtml(String(draft.draftId ?? ''))}" data-message-index="${messageIndex}" data-severity="${escapeHtml(severity)}">
        <div class="nst3af-agent-draft__header">
          <span class="nst3af-agent-sev-dot nst3af-agent-sev-dot--${escapeHtml(severity)}" aria-hidden="true"></span>
          <span class="nst3af-agent-draft__title">${escapeHtml(toolName)}</span>
          <span class="nst3af-agent-draft__badge">${escapeHtml(lang('agent.draft.toolConfirmation', 'Tool confirmation'))}</span>
        </div>
        <p class="nst3af-agent-tool-confirm__summary">${escapeHtml(summary)}</p>
        ${argsBlock}
        <div class="nst3af-agent-draft__actions">
          <button type="button" class="btn btn-primary btn-sm" data-nst3af-agent-draft-apply="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(String(draft.draftId ?? ''))}">${escapeHtml(applyLabel)}</button>
          <button type="button" class="btn btn-default btn-sm" data-nst3af-agent-draft-discard="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(String(draft.draftId ?? ''))}">${escapeHtml(lang('agent.draft.discard', 'Discard'))}</button>
        </div>
      </div>
    </div>`;
  }

  /**
   * @param {object} message
   * @returns {string}
   */
  renderReadbackMessage(message) {
    const meta = message.meta ?? {};
    const readback = Array.isArray(meta.readback) ? meta.readback : [];
    const rows = readback.map((entry) => {
      const values = entry.values ?? {};
      const cells = Object.entries(values).map(([key, value]) => (
        `<tr><td>${escapeHtml(key)}</td><td>${escapeHtml(String(value ?? ''))}</td></tr>`
      )).join('');
      return `<div class="nst3af-agent-readback__record"><strong>${escapeHtml(String(entry.table ?? ''))}:${Number(entry.uid ?? 0)}</strong><table>${cells}</table></div>`;
    }).join('');

    const undoBtn = meta.changeId
      ? `<button type="button" class="btn btn-default btn-sm" data-nst3af-agent-undo="1" data-change-id="${escapeHtml(String(meta.changeId))}">${escapeHtml(lang('agent.draft.undo', 'Undo'))}</button>`
      : '';

    const handoffHtml = meta.schedulerHandoff
      ? this.renderHandoffCard(meta.schedulerHandoff)
      : '';

    return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant">
      <div class="nst3af-agent-msg__who">AI Agent</div>
      <div class="nst3af-agent-applied">
        <div class="nst3af-agent-applied__title"><span aria-hidden="true">✓</span> ${escapeHtml(lang('agent.applied.title', 'Changes applied'))}</div>
        <p>${escapeHtml(String(message.content ?? ''))}</p>
        <p class="nst3af-agent-readback__note">${escapeHtml(lang('agent.draft.readback', 'Written through DataHandler, then read back from the database. Workspace and publishing are untouched.'))}</p>
        ${rows}
        ${undoBtn}
        ${handoffHtml}
      </div>
    </div>`;
  }

  /**
   * @param {Element|null} button
   */
  toggleDraftField(button) {
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }
    const messageIndex = Number.parseInt(button.dataset.messageIndex ?? '-1', 10);
    const fieldKey = button.dataset.fieldKey ?? '';
    const message = this.messages[messageIndex];
    if (!message?.meta?.draft?.fields || fieldKey === '') {
      return;
    }

    message.meta.draft.fields = message.meta.draft.fields.map((field) => (
      field.key === fieldKey ? { ...field, kept: field.kept === false } : field
    ));
    this.renderStream();
    this.persistSession();
  }

  /**
   * @param {Element|null} button
   * @param {'all'|'safe'} [applyMode]
   */
  async applyDraft(button, applyMode = 'all') {
    if (!(button instanceof HTMLButtonElement) || this.isRunning) {
      return;
    }

    const messageIndex = Number.parseInt(button.dataset.messageIndex ?? '-1', 10);
    const draftId = button.dataset.draftId ?? '';
    const message = this.messages[messageIndex];
    const draft = message?.meta?.draft;
    if (!draft || draftId === '') {
      return;
    }

    const severity = String(draft.severity ?? 'write');
    const isDestructive = severity === 'destructive';
    const armed = draft.destructiveArmed === true;

    if (isDestructive && !armed) {
      const url = ajaxUrl('nst3af_agent_confirm_destructive');
      if (url === '') {
        return;
      }
      this.isRunning = true;
      try {
        const payload = await new AjaxRequest(url).post({ draftId }).then((r) => r.resolve());
        if (!payload?.ok) {
          throw new Error(payload?.message ?? 'Confirm failed');
        }
        draft.destructiveArmed = true;
        this.renderStream();
        await this.persistSession();
      } catch (error) {
        const text = errorMessage(error);
        this.messages.push({ role: 'assistant', content: text, meta: { type: 'error' } });
        this.renderStream();
      } finally {
        this.isRunning = false;
      }
      return;
    }

    const keptFieldKeys = draft.kind === 'tool_confirmation'
      ? []
      : (Array.isArray(draft.fields) ? draft.fields : [])
        .filter((field) => field.kept !== false)
        .map((field) => String(field.key ?? ''));
    const safeKeptFieldKeys = applyMode === 'safe'
      ? keptFieldKeys.filter((key) => {
        const field = (Array.isArray(draft.fields) ? draft.fields : []).find((entry) => String(entry.key ?? '') === key);
        return field?.safe === true;
      })
      : keptFieldKeys;

    const url = ajaxUrl('nst3af_agent_apply_draft');
    if (url === '') {
      return;
    }

    this.isRunning = true;
    try {
      const payload = await new AjaxRequest(url).post({
        draftId,
        keptFieldKeys: safeKeptFieldKeys,
        applyMode,
        workspaceId: Number(this.context?.workspaceId ?? 0),
        correlationId: message.meta?.correlationId ?? '',
      }).then((r) => r.resolve());
      if (!payload?.ok) {
        throw new Error(payload?.message ?? 'Apply failed');
      }

      draft.applied = true;
      const result = payload.result ?? {};

      if (draft.kind === 'tool_confirmation') {
        const presented = result.presentation ?? {};
        message.content = messageContent(presented.content, messageContent(payload.message, lang('agent.draft.toolApplied', 'Tool ran successfully.')));
        message.meta = {
          type: 'tool_result',
          tool: String(result.tool ?? draft.tool ?? ''),
          toolCallLabel: String(draft.tool ?? result.tool ?? ''),
          success: presented.success !== false,
          severity: String(draft.severity ?? 'write'),
          severityLabel: 'Write',
          facts: Array.isArray(presented.facts) ? presented.facts : [],
          details: presented.details ?? null,
          autoRan: false,
          correlationId: result.correlationId ?? message.meta?.correlationId ?? '',
          schedulerHandoff: payload.schedulerHandoff ?? null,
        };
        this.renderStream();
        await this.persistSession();
        return;
      }

      const appliedCount = String(result.appliedCount ?? 0);
      const totalCount = String(result.totalCount ?? 0);
      this.messages.push({
        role: 'assistant',
        content: messageContent(
          payload.message,
          lang('agent.draft.applied', 'Applied %1$s of %2$s fields.', [appliedCount, totalCount]),
        ),
        meta: {
          type: 'readback_result',
          readback: result.readback ?? [],
          changeId: result.changeId ?? '',
          correlationId: result.correlationId ?? '',
          schedulerHandoff: payload.schedulerHandoff ?? null,
        },
      });
      this.renderStream();
      await this.persistSession();
    } catch (error) {
      const text = errorMessage(error);
      this.messages.push({ role: 'assistant', content: text, meta: { type: 'error' } });
      this.renderStream();
    } finally {
      this.isRunning = false;
    }
  }

  /**
   * @param {Element|null} button
   */
  async discardDraft(button) {
    if (!(button instanceof HTMLButtonElement) || this.isRunning) {
      return;
    }

    const messageIndex = Number.parseInt(button.dataset.messageIndex ?? '-1', 10);
    const draftId = button.dataset.draftId ?? '';
    const message = this.messages[messageIndex];
    if (!message?.meta?.draft || draftId === '') {
      return;
    }

    const url = ajaxUrl('nst3af_agent_discard_draft');
    if (url !== '') {
      await new AjaxRequest(url).post({ draftId });
    }

    message.meta.draft.discarded = true;
    message.content = lang('agent.draft.discarded', 'Draft discarded. Nothing was written.');
    this.renderStream();
    await this.persistSession();
  }

  toggleAttachMenu() {
    if (!(this.attachMenu instanceof HTMLElement)) {
      return;
    }
    const open = this.attachMenu.hidden;
    this.attachMenu.hidden = !open;
    const toggle = this.root.querySelector('[data-nst3af-agent-attach-toggle]');
    if (toggle instanceof HTMLElement) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
  }

  hideAttachMenu() {
    if (!(this.attachMenu instanceof HTMLElement)) {
      return;
    }
    this.attachMenu.hidden = true;
    const toggle = this.root.querySelector('[data-nst3af-agent-attach-toggle]');
    if (toggle instanceof HTMLElement) {
      toggle.setAttribute('aria-expanded', 'false');
    }
  }

  openFilePicker() {
    this.hideAttachMenu();
    if (this.fileInput instanceof HTMLInputElement) {
      this.fileInput.value = '';
      this.fileInput.click();
    }
  }

  /**
   * @param {File[]} files
   */
  async uploadFiles(files) {
    if (!this.input || this.isRunning || files.length === 0) {
      return;
    }

    const url = ajaxUrl('nst3af_agent_upload');
    if (url === '') {
      return;
    }

    this.isRunning = true;
    this.showProgress(true);
    this.hideAttachMenu();

    const tokens = [];
    try {
      for (const file of files) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('directoryPath', '/user_upload/');

        const response = await fetch(url, {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!payload?.ok) {
          throw new Error(payload?.message ?? lang('agent.upload.failed', 'Upload failed.'));
        }
        if (payload.attachment) {
          tokens.push(String(payload.attachment));
        }
      }

      if (tokens.length > 0) {
        const prefix = this.input.value.trim() === '' ? '' : `${this.input.value.trim()} `;
        this.input.value = `${prefix}${tokens.join(' ')} `;
        this.input.focus();
      }
    } catch (error) {
      const text = errorMessage(error);
      this.messages.push({ role: 'assistant', content: text, meta: { type: 'error' } });
      this.renderStream();
    } finally {
      this.isRunning = false;
      this.showProgress(false);
      if (this.fileInput instanceof HTMLInputElement) {
        this.fileInput.value = '';
      }
    }
  }

  /**
   * @param {Element|null} button
   */
  async undoChange(button) {
    if (!(button instanceof HTMLButtonElement) || this.isRunning) {
      return;
    }

    const changeId = button.dataset.changeId ?? '';
    if (changeId === '') {
      return;
    }

    const url = ajaxUrl('nst3af_agent_undo_change');
    if (url === '') {
      return;
    }

    this.isRunning = true;
    try {
      const payload = await new AjaxRequest(url).post({ changeId }).then((r) => r.resolve());
      if (!payload?.ok) {
        throw new Error(payload?.message ?? 'Undo failed');
      }
      this.messages.push({
        role: 'assistant',
        content: messageContent(payload.message, lang('agent.draft.undone', 'Change undone.')),
        meta: { type: 'info' },
      });
      this.renderStream();
      await this.persistSession();
    } catch (error) {
      const text = errorMessage(error);
      this.messages.push({ role: 'assistant', content: text, meta: { type: 'error' } });
      this.renderStream();
    } finally {
      this.isRunning = false;
    }
  }

  /**
   * @param {{ executable?: Array<object>, locked?: Array<object> }} starters
   */
  renderStarters(starters) {
    if (!this.stream || this.messages.length === 0) {
      return;
    }

    const existing = this.stream.querySelector('[data-nst3af-agent-starters]');
    existing?.remove();
    this.stream.querySelector('[data-nst3af-agent-greeting]')?.remove();

    const executable = Array.isArray(starters.executable) ? starters.executable : [];
    const locked = Array.isArray(starters.locked) ? starters.locked : [];
    if (executable.length === 0 && locked.length === 0) {
      return;
    }

    const wrap = document.createElement('div');
    wrap.dataset.nst3afAgentStarters = '1';
    wrap.className = 'nst3af-agent-starters-wrap';

    const container = document.createElement('div');
    container.className = 'nst3af-agent-starters';

    if (executable.length > 0) {
      const label = document.createElement('div');
      label.className = 'nst3af-agent-starter-group-label';
      label.textContent = lang('agent.starters.executable', 'Suggested actions');
      container.appendChild(label);
      executable.forEach((tool) => container.appendChild(this.createStarterButton(tool, false)));
    }

    if (locked.length > 0) {
      const label = document.createElement('div');
      label.className = 'nst3af-agent-starter-group-label';
      label.textContent = lang('agent.starters.locked', 'Needs another extension');
      container.appendChild(label);
      locked.forEach((tool) => container.appendChild(this.createStarterButton(tool, true)));
    }

    wrap.appendChild(container);
    this.stream.appendChild(wrap);
    this.stream.scrollTop = this.stream.scrollHeight;
  }

  /**
   * @param {object} tool
   * @param {boolean} locked
   * @returns {HTMLButtonElement}
   */
  createStarterButton(tool, locked) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `nst3af-agent-starter${locked ? ' nst3af-agent-starter--locked' : ''}`;
    btn.dataset.nst3afAgentStarter = '1';
    btn.dataset.tool = String(tool.name ?? '');
    btn.dataset.action = String(tool.action ?? '');
    btn.dataset.label = String(tool.label ?? tool.name ?? '');
    if (tool.arguments && typeof tool.arguments === 'object') {
      btn.dataset.arguments = JSON.stringify(tool.arguments);
    }
    btn.dataset.locked = locked ? '1' : '0';
    btn.setAttribute('aria-label', this.buildToolAriaLabel(tool, locked));

    if (locked) {
      const lock = document.createElement('span');
      lock.className = 'nst3af-agent-starter__lock';
      lock.setAttribute('aria-hidden', 'true');
      lock.textContent = '🔒';
      btn.appendChild(lock);
    }

    const dot = document.createElement('span');
    dot.className = `nst3af-agent-sev-dot nst3af-agent-sev-dot--${String(tool.severity ?? 'read')}`;
    dot.setAttribute('aria-hidden', 'true');
    btn.appendChild(dot);

    const label = document.createElement('span');
    label.className = 'nst3af-agent-starter__label';
    label.textContent = String(tool.label ?? tool.name ?? '');
    btn.appendChild(label);

    const severity = document.createElement('span');
    severity.className = 'visually-hidden';
    severity.textContent = this.severityLabel(tool.severity);
    btn.appendChild(severity);

    if (locked && tool.ownerLabel) {
      const owner = document.createElement('span');
      owner.className = 'nst3af-agent-starter__owner';
      owner.textContent = String(tool.ownerLabel);
      btn.appendChild(owner);
    }

    return btn;
  }

  /**
   * @param {HTMLButtonElement} btn
   */
  async runStarter(btn) {
    const tool = btn.dataset.tool ?? '';
    const action = btn.dataset.action ?? '';
    if (tool === '' && action === '') {
      return;
    }
    let toolArguments = {};
    try {
      toolArguments = JSON.parse(btn.dataset.arguments ?? '{}');
    } catch {
      toolArguments = {};
    }
    if (this.input) {
      this.input.value = action !== '' ? `/${action}` : `/${tool} `;
    }
    await this.submitTurn(tool, toolArguments, action);
  }

  async submitTurn(explicitTool = '', toolArguments = {}, starterAction = '') {
    if (!this.input || this.isRunning) {
      return;
    }

    const message = this.input.value.trim();
    if (message === '') {
      return;
    }

    this.isRunning = true;
    this.showProgress(true);
    this.input.value = '';
    this.hideAutocomplete();

    const backendContext = resolveBackendContext();
    const body = {
      message,
      tool: starterAction !== '' ? '' : explicitTool,
      action: starterAction,
      arguments: toolArguments,
      context: {
        ...backendContext,
        ...this.context,
      },
    };

    const preferStream = explicitTool === '' && starterAction === '';

    try {
      this.messages.push({ role: 'user', content: message, meta: {} });
      let payload = null;
      let streamed = false;
      if (preferStream) {
        const streamResult = await this.submitTurnStreaming(body);
        if (streamResult !== null) {
          payload = streamResult.payload;
          streamed = true;
        }
      }
      if (payload === null) {
        const url = ajaxUrl('nst3af_agent_turn');
        payload = await new AjaxRequest(url).post(body).then((r) => r.resolve());
      }
      if (!payload?.ok) {
        throw new Error(payload?.message ?? 'Turn failed');
      }

      if (!streamed) {
        const replies = Array.isArray(payload.messages) ? payload.messages : [];
        replies.forEach((reply) => {
          const meta = reply.meta ?? {};
          if (hasTurnGuardWarning(meta) && reply.content) {
            reply.content = String(meta.turnGuardWarning) + '\n\n' + String(reply.content);
          }
          this.messages.push(reply);
        });
      }
      this.context = payload.context ?? this.context;

      this.renderContext();
      this.renderStream();
      if (payload.starters) {
        this.starters = payload.starters;
      }
      if (payload.greeting) {
        this.greeting = payload.greeting;
      }

      await this.persistSession();
    } catch (error) {
      const text = errorMessage(error);
      this.messages.push({ role: 'assistant', content: text, meta: { type: 'error' } });
      this.renderStream();
    } finally {
      this.isRunning = false;
      this.showProgress(false);
    }
  }

  /**
   * @param {object} body
   * @returns {Promise<{payload: object}|null>}
   */
  async submitTurnStreaming(body) {
    const streamUrl = ajaxUrl('nst3af_agent_turn_stream');
    if (streamUrl === '') {
      return null;
    }

    let response;
    try {
      response = await fetch(streamUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'text/event-stream',
        },
        body: JSON.stringify(body),
        credentials: 'same-origin',
      });
    } catch {
      return null;
    }

    if (!response.ok || !response.body) {
      return null;
    }

    const contentType = String(response.headers.get('content-type') ?? '');
    if (!contentType.includes('text/event-stream')) {
      return null;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let donePayload = null;
    let streamingMessage = null;
    let streamAssistantCount = 0;

    const pushAssistantReply = (reply) => {
      const meta = reply.meta ?? {};
      if (hasTurnGuardWarning(meta) && reply.content) {
        reply.content = String(meta.turnGuardWarning) + '\n\n' + String(reply.content);
      }
      this.messages.push(reply);
    };

    const flushEvent = (eventName, dataText) => {
      if (dataText === '') {
        return;
      }
      let data;
      try {
        data = JSON.parse(dataText);
      } catch {
        return;
      }

      if (eventName === 'delta' && data.content) {
        if (streamingMessage === null) {
          streamingMessage = { role: 'assistant', content: '', meta: { type: 'nl_reply', streaming: true } };
          this.messages.push(streamingMessage);
        }
        streamingMessage.content += String(data.content);
        this.renderStream();
        return;
      }

      if (eventName === 'message' && data.message) {
        const reply = data.message;
        streamAssistantCount += 1;
        const meta = reply.meta ?? {};
        if (hasTurnGuardWarning(meta) && reply.content) {
          reply.content = String(meta.turnGuardWarning) + '\n\n' + String(reply.content);
        }
        if (streamingMessage !== null && reply.meta?.type === 'nl_reply') {
          streamingMessage.content = reply.content ?? streamingMessage.content;
          streamingMessage.meta = { ...reply.meta, streaming: false };
          streamingMessage = null;
        } else {
          pushAssistantReply(reply);
        }
        this.renderStream();
        return;
      }

      if (eventName === 'done') {
        donePayload = data;
        // Fast-path turns (read prefetch, attachments) may only ship replies on done.
        if (streamAssistantCount === 0 && Array.isArray(data.messages)) {
          data.messages.forEach((reply) => pushAssistantReply(reply));
          streamAssistantCount += data.messages.length;
          this.renderStream();
        }
      }

      if (eventName === 'error') {
        throw new Error(data.message ?? 'Stream failed');
      }
    };

    while (true) {
      const { value, done } = await reader.read();
      if (done) {
        break;
      }
      buffer += decoder.decode(value, { stream: true });
      const chunks = buffer.split('\n\n');
      buffer = chunks.pop() ?? '';
      for (const chunk of chunks) {
        const lines = chunk.split('\n');
        let eventName = 'message';
        const dataLines = [];
        for (const line of lines) {
          if (line.startsWith('event:')) {
            eventName = line.slice(6).trim();
          } else if (line.startsWith('data:')) {
            dataLines.push(line.slice(5).trim());
          }
        }
        flushEvent(eventName, dataLines.join('\n'));
      }
    }

    if (streamingMessage !== null) {
      streamingMessage.meta = { ...(streamingMessage.meta ?? {}), streaming: false };
    }

    return donePayload !== null ? { payload: donePayload } : null;
  }

  async persistSession(includeDisclosure = false) {
    const url = ajaxUrl('nst3af_agent_conversation_save');
    if (url === '') {
      return;
    }

    const body = {
      messages: this.messages,
      context: this.context,
    };
    if (includeDisclosure || this.disclosureDismissed) {
      body.disclosureDismissed = this.disclosureDismissed;
    }

    try {
      const payload = await new AjaxRequest(url).post(body).then((response) => response.resolve());
      if (payload?.ok === false) {
        console.warn('Agent conversation save failed:', payload?.message ?? payload);
      }
    } catch (error) {
      // ponytail: best-effort — undo/apply must not surface [object Object] when save fails
      console.warn('Agent conversation save failed:', errorMessage(error));
    }
  }

  handleComposerInput() {
    if (!this.input) {
      return;
    }

    const value = this.input.value;
    const slash = value.match(/\/(\S*)$/);
    const at = value.match(/@(\S*)$/);

    if (slash) {
      this.autocompleteMode = 'tools';
      this.loadAutocomplete('tools', slash[1] ?? '');
      return;
    }

    if (at) {
      this.autocompleteMode = 'records';
      this.loadAutocomplete('records', at[1] ?? '');
      return;
    }

    this.hideAutocomplete();
  }

  /**
   * @param {'tools'|'records'} mode
   * @param {string} query
   */
  async loadAutocomplete(mode, query) {
    if (!this.autocomplete) {
      return;
    }

    const route = mode === 'tools' ? 'nst3af_agent_tools' : 'nst3af_agent_records';
    const base = ajaxUrl(route);
    if (base === '') {
      return;
    }

    const url = new URL(base, window.location.href);
    url.searchParams.set('q', query);
    url.searchParams.set('pageId', String(resolveBackendContext().pageId));

    try {
      const payload = await new AjaxRequest(url.toString()).get().then((r) => r.resolve());
      if (!payload?.ok) {
        this.hideAutocomplete();
        return;
      }

      if (mode === 'tools') {
        this.renderToolAutocomplete(payload.tools ?? {});
      } else {
        this.renderRecordAutocomplete(payload.records ?? []);
      }
    } catch {
      this.hideAutocomplete();
    }
  }

  /**
   * @param {{ executable?: Array<object>, locked?: Array<object> }} catalog
   */
  renderToolAutocomplete(catalog) {
    if (!this.autocomplete) {
      return;
    }

    const executable = Array.isArray(catalog.executable) ? catalog.executable : [];
    const locked = Array.isArray(catalog.locked) ? catalog.locked : [];
    const sections = [];

    if (executable.length > 0) {
      sections.push(`<div class="nst3af-agent-autocomplete__heading">${escapeHtml(lang('agent.starters.executable', 'Suggested actions'))}</div>`);
      sections.push(executable.map((tool) => this.renderToolItem(tool, false)).join(''));
    }
    if (locked.length > 0) {
      sections.push(`<div class="nst3af-agent-autocomplete__heading">${escapeHtml(lang('agent.starters.locked', 'Needs another extension'))}</div>`);
      sections.push(locked.map((tool) => this.renderToolItem(tool, true)).join(''));
    }

    this.autocomplete.innerHTML = sections.join('');
    this.autocomplete.hidden = sections.length === 0;
  }

  /**
   * @param {object} tool
   * @param {boolean} locked
   */
  renderToolItem(tool, locked) {
    const severityText = this.severityLabel(tool.severity);
    const aria = this.buildToolAriaLabel(tool, locked);
    return `<button type="button" class="nst3af-agent-autocomplete__item${locked ? ' nst3af-agent-autocomplete__item--locked' : ''}" data-nst3af-agent-ac-item="1" data-locked="${locked ? '1' : '0'}" data-insert="/${escapeHtml(String(tool.name ?? ''))} " aria-label="${escapeHtml(aria)}" role="option"><span class="nst3af-agent-sev-dot nst3af-agent-sev-dot--${escapeHtml(String(tool.severity ?? 'read'))}" aria-hidden="true"></span><span><span class="nst3af-agent-autocomplete__item-title">${escapeHtml(String(tool.name ?? ''))}</span><span class="nst3af-agent-autocomplete__item-desc">${escapeHtml(String(tool.description ?? ''))} · ${escapeHtml(String(tool.ownerLabel ?? ''))} · ${escapeHtml(severityText)}</span></span></button>`;
  }

  /**
   * @param {Array<object>} records
   */
  renderRecordAutocomplete(records) {
    if (!this.autocomplete) {
      return;
    }

    if (!Array.isArray(records) || records.length === 0) {
      this.hideAutocomplete();
      return;
    }

    const heading = `<div class="nst3af-agent-autocomplete__heading">${escapeHtml(lang('agent.context.record', 'Record'))}</div>`;
    const items = records.map((record) => (
      `<button type="button" class="nst3af-agent-autocomplete__item" data-nst3af-agent-ac-item="1" data-insert="@${escapeHtml(String(record.table ?? ''))}:${Number(record.uid ?? 0)} " data-record-table="${escapeHtml(String(record.table ?? ''))}" data-record-uid="${Number(record.uid ?? 0)}"><span><span class="nst3af-agent-autocomplete__item-title">${escapeHtml(String(record.label ?? ''))}</span><span class="nst3af-agent-autocomplete__item-desc">${escapeHtml(String(record.table ?? ''))}:${Number(record.uid ?? 0)}</span></span></button>`
    )).join('');

    this.autocomplete.innerHTML = heading + items;
    this.autocomplete.hidden = false;
  }

  /**
   * @param {HTMLButtonElement} item
   */
  pickAutocomplete(item) {
    if (!this.input) {
      return;
    }

    const locked = item.dataset.locked === '1';
    const insert = item.dataset.insert ?? '';
    if (insert === '') {
      return;
    }

    const value = this.input.value;
    const mode = this.autocompleteMode;
    const pattern = mode === 'records' ? /@(\S*)$/ : /\/(\S*)$/;
    this.input.value = value.replace(pattern, insert);

    if (item.dataset.recordTable && item.dataset.recordUid) {
      this.context = {
        ...this.context,
        record: {
          table: item.dataset.recordTable,
          uid: Number.parseInt(item.dataset.recordUid, 10),
        },
      };
      this.renderContext();
    }

    this.hideAutocomplete();

    if (locked && mode === 'tools') {
      const toolName = insert.replace(/^\//, '').trim();
      this.submitTurn(toolName);
      return;
    }

    this.input.focus();
  }

  hideAutocomplete() {
    if (!this.autocomplete) {
      return;
    }
    this.autocomplete.hidden = true;
    this.autocomplete.innerHTML = '';
    this.autocompleteMode = null;
    this.autocompleteIndex = -1;
  }

  /**
   * @param {string|undefined} severity
   * @returns {string}
   */
  severityLabel(severity) {
    const key = `agent.severity.${String(severity ?? 'read')}`;
    const fallbacks = {
      read: 'Read-only',
      write: 'Write',
      destructive: 'Destructive',
      unclassified: 'Unclassified',
    };
    return lang(key, fallbacks[String(severity ?? 'read')] ?? fallbacks.read);
  }

  /**
   * @param {object} tool
   * @param {boolean} locked
   * @returns {string}
   */
  buildToolAriaLabel(tool, locked) {
    const parts = [
      String(tool.name ?? ''),
      this.severityLabel(tool.severity),
    ];
    if (locked) {
      parts.push(lang('agent.starters.locked', 'Needs another extension'));
      if (tool.ownerLabel) {
        parts.push(String(tool.ownerLabel));
      }
    }
    return parts.join(', ');
  }

  /**
   * @param {KeyboardEvent} event
   */
  handleAutocompleteKeydown(event) {
    if (!this.autocomplete) {
      return;
    }

    const items = [...this.autocomplete.querySelectorAll('[data-nst3af-agent-ac-item]')];
    if (items.length === 0) {
      return;
    }

    if (event.key === 'ArrowDown') {
      event.preventDefault();
      this.autocompleteIndex = Math.min(this.autocompleteIndex + 1, items.length - 1);
      this.highlightAutocompleteItem(items);
      return;
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault();
      this.autocompleteIndex = Math.max(this.autocompleteIndex - 1, 0);
      this.highlightAutocompleteItem(items);
      return;
    }

    if (event.key === 'Enter' && this.autocompleteIndex >= 0) {
      event.preventDefault();
      const item = items[this.autocompleteIndex];
      if (item instanceof HTMLButtonElement) {
        this.pickAutocomplete(item);
      }
    }
  }

  /**
   * @param {HTMLButtonElement[]} items
   */
  highlightAutocompleteItem(items) {
    items.forEach((item, index) => {
      const active = index === this.autocompleteIndex;
      item.classList.toggle('is-active', active);
      item.setAttribute('aria-selected', active ? 'true' : 'false');
      if (active) {
        item.scrollIntoView({ block: 'nearest' });
      }
    });
  }

  trapFocus() {
    // Focus trap is enforced via handleFocusTrap on Tab.
  }

  /**
   * @param {KeyboardEvent} event
   */
  handleFocusTrap(event) {
    if (event.key !== 'Tab' || !(this.panel instanceof HTMLElement)) {
      return;
    }

    const focusables = [...this.panel.querySelectorAll(this.focusableSelector)]
      .filter((el) => el instanceof HTMLElement && !el.hasAttribute('disabled') && el.tabIndex !== -1);
    if (focusables.length === 0) {
      return;
    }

    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    const active = document.activeElement;

    if (event.shiftKey && active === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }

  /**
   * @param {boolean} running
   */
  showProgress(running) {
    if (!this.stream) {
      return;
    }

    const existing = this.stream.querySelector('[data-nst3af-agent-progress]');
    existing?.remove();

    if (!running) {
      return;
    }

    const node = document.createElement('div');
    node.dataset.nst3afAgentProgress = '1';
    node.className = 'nst3af-agent-progress';
    node.innerHTML = '<span class="nst3af-agent-progress__spinner" aria-hidden="true"></span><span>' + escapeHtml(lang('agent.live.running', 'Assistant is working…')) + '</span>';
    this.stream.appendChild(node);
    this.stream.scrollTop = this.stream.scrollHeight;
    this.announce(lang('agent.live.running', 'Assistant is working…'));
  }

  /**
   * @param {string} text
   */
  announce(text) {
    if (!this.stream) {
      return;
    }
    this.stream.setAttribute('aria-label', text);
  }
}

/**
 * Backend shell (topbar/toolbar) renders outside the module iframe on TYPO3 v14+.
 * @returns {Document}
 */
function getBackendDocument() {
  try {
    const topDoc = window.top?.document;
    if (topDoc?.querySelector('.t3js-scaffold-topbar, .scaffold-topbar')) {
      return topDoc;
    }
  } catch {
    // Same-origin only; fall back to current document.
  }

  return document;
}

function scheduleMountLaunchBar() {
  const backendDoc = getBackendDocument();
  const attempt = () => mountLaunchBar();
  attempt();
  if (backendDoc.querySelector('.nst3af-agent-launchbar--topbar')) {
    return;
  }

  const observed = new Set();
  const observer = new MutationObserver(() => attempt());
  const watch = (node) => {
    if (node instanceof HTMLElement && !observed.has(node)) {
      observed.add(node);
      observer.observe(node, { childList: true, subtree: true });
    }
  };

  watch(backendDoc.body);
  watch(backendDoc.querySelector('.t3js-scaffold-toolbar, .scaffold-toolbar'));
  watch(backendDoc.querySelector('.t3js-scaffold-topbar, .scaffold-topbar'));

  window.setTimeout(() => observer.disconnect(), 15000);
}

function mountLaunchBar(retry = 0) {
  if (getBackendDocument().querySelector('.nst3af-agent-launchbar--topbar')) {
    getBackendDocument().querySelector('.toolbar-item-nst3af-agent')?.classList.add('toolbar-item-nst3af-agent--hidden');
    return;
  }

  const backendDoc = getBackendDocument();
  const launchBar = backendDoc.querySelector('[data-nst3af-agent-launchbar]');
  if (!(launchBar instanceof HTMLElement)) {
    if (retry < 30) {
      window.setTimeout(() => mountLaunchBar(retry + 1), 100);
    }
    return;
  }

  const topbar = backendDoc.querySelector('.t3js-scaffold-topbar .topbar, .scaffold-topbar .topbar');
  if (!(topbar instanceof HTMLElement)) {
    if (retry < 30) {
      window.setTimeout(() => mountLaunchBar(retry + 1), 100);
    }
    return;
  }

  const clone = launchBar.cloneNode(true);
  if (!(clone instanceof HTMLElement)) {
    return;
  }
  clone.classList.add('nst3af-agent-launchbar--topbar');
  clone.addEventListener('click', (event) => {
    event.preventDefault();
    controller?.open(clone);
  });

  let slot = topbar.querySelector('.nst3af-agent-launchbar-slot');
  if (!(slot instanceof HTMLElement)) {
    slot = document.createElement('div');
    slot.className = 'nst3af-agent-launchbar-slot';
    const anchor = topbar.querySelector('.topbar-button-search, .t3js-topbar-button-search');
    if (anchor instanceof HTMLElement) {
      topbar.insertBefore(slot, anchor);
    } else {
      topbar.appendChild(slot);
    }
  }
  slot.replaceChildren(clone);

  const toolbarItem = launchBar.closest('[data-nst3af-agent-toolbar], .toolbar-item-nst3af-agent, li');
  if (toolbarItem instanceof HTMLElement) {
    toolbarItem.classList.add('toolbar-item-nst3af-agent--hidden');
  }
}

function initialize() {
  if (controller !== null) {
    return;
  }

  const root = document.querySelector('[data-nst3af-agent-root]');
  if (!(root instanceof HTMLElement)) {
    return;
  }

  controller = new AgentController(root);
  scheduleMountLaunchBar();

  window.addEventListener('storage', (event) => {
    if (event.key !== STORAGE_PREFS_KEY || controller === null) {
      return;
    }
    controller.hotkey = readHotkeyPref();
    controller.applyHotkeyChrome();
  });
}

export function boot() {
  void ensureMessageRenderer();
  DocumentService.ready().then(initialize);
}

export default { boot };
