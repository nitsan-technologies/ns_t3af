import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import DocumentService from '@typo3/core/document-service.js';
import Persistent from '@typo3/backend/storage/persistent.js';
import { preloadModuleStateStorage } from './agent/module-state.js';
// Defines the <typo3-backend-icon> custom element used by dynamically-rendered session-list
// action buttons (rename/delete) — same explicit-import pattern TYPO3 core itself uses in
// resizable-navigation.js before generating icon markup at runtime.
import '@typo3/backend/element/icon-element.js';
import { hotkeyLabel, readHotkeyPref, matchesAgentHotkey, STORAGE_PREFS_KEY } from './agent/hotkeys.js';
import { lang, hasTurnGuardWarning, errorMessage, errorText, messageContent, escapeHtml, formatWorkDuration, humanizeKey } from './agent/format.js';
import { ajaxUrl, resolveBackendContext } from './agent/context.js';
import { isSuggestionFieldSafe, renderImagePreviews, renderMediaPreview, resolveToolDisplayLabel, renderToolTrace, renderWorkTraceHtml, stripEphemeralWorkTraceMeta, ensureMessageRenderer, renderMessageBody } from './agent/render-helpers.js';
import { sessionMethods } from './agent/controller-sessions.js';
import { chromeMethods } from './agent/controller-chrome.js';
import { draftMethods } from './agent/controller-drafts.js';
import { streamMethods } from './agent/controller-stream.js';
import { turnMethods } from './agent/controller-turn.js';
import { autocompleteMethods } from './agent/controller-autocomplete.js';

/** @type {AgentController | null} */
let controller = null;
const OPEN_HOTKEY_BOUND = Symbol.for('nst3af.agent.openHotkey');
const IFRAME_WATCHED = Symbol.for('nst3af.agent.iframeWatch');

class AgentController {
  /**
   * @param {HTMLElement} root
   */
  constructor(root) {
    this.root = root;
    this.backdrop = root.querySelector('[data-nst3af-agent-backdrop]');
    this.panel = root.querySelector('[data-nst3af-agent-panel]');
    this.stream = root.querySelector('[data-nst3af-agent-stream]');
    this.planPanel = root.querySelector('[data-nst3af-agent-plan]');
    // Plan sent live by the running turn; null = use the newest plan saved in the messages.
    this.livePlan = null;
    // null = automatic (open while steps remain), true/false = the editor's choice
    this.planOpen = null;
    this.planPanel?.addEventListener('click', (event) => {
      const summary = event.target instanceof Element ? event.target.closest('summary') : null;
      const details = summary?.parentElement;
      if (details instanceof HTMLDetailsElement) {
        // The click toggles it right after this handler, so the new state is the opposite.
        this.planOpen = !details.open;
      }
    });
    this.contextEl = root.querySelector('[data-nst3af-agent-context]');
    this.disclosure = null;
    this.input = root.querySelector('[data-nst3af-agent-input]');
    this.composer = root.querySelector('[data-nst3af-agent-composer]');
    this.attachMenu = root.querySelector('[data-nst3af-agent-attach-menu]');
    this.fileInput = root.querySelector('[data-nst3af-agent-file-input]');
    this.autocomplete = root.querySelector('[data-nst3af-agent-autocomplete]');
    this.settingsLink = root.querySelector('[data-nst3af-agent-settings]');
    this.providerSelect = root.querySelector('[data-nst3af-agent-provider]');
    this.sessionsToggle = root.querySelector('[data-nst3af-agent-sessions-toggle]');
    this.summarizeButton = root.querySelector('[data-nst3af-agent-summarize]');
    this.sessionsDrawer = root.querySelector('[data-nst3af-agent-sessions]');
    this.sessionsList = root.querySelector('[data-nst3af-agent-sessions-list]');
    this.sessionsFilterEl = root.querySelector('[data-nst3af-agent-sessions-filter]');
    this.sessionsSearch = root.querySelector('[data-nst3af-agent-sessions-search]');
    this.sessionsFoot = root.querySelector('[data-nst3af-agent-sessions-foot]');
    this.infoToggle = root.querySelector('[data-nst3af-agent-info-toggle]');
    this.infoDrawer = root.querySelector('[data-nst3af-agent-info]');
    this.infoBody = root.querySelector('[data-nst3af-agent-info-body]');
    /** Active conversation summary from the server (null until the first message is stored). */
    this.session = null;
    /** true after "New conversation": the next turn starts a new conversation. */
    this.freshSession = false;
    this.sessionListSettings = { enabled: false, defaultFilter: 'current', scope: 'page', retentionDays: 90 };
    this.sessions = [];
    this.sessionsFilter = 'current';
    this.sessionsHasMore = false;
    /**
     * Conversations rail visible/collapsed choice, read once here (not per open()) so it's
     * known before the panel is ever painted. Persistent.get() is not reliably async (a
     * synchronous blocking XHR on a cold cache, a plain sync read once warm) so resolving it
     * eagerly at construction avoids stalling the "open panel" click. Missing key -> visible.
     */
    const storedSessionsVisible = Persistent.get('nst3af.agent.sessionsVisible');
    this.sessionsRailOpen = storedSessionsVisible === undefined ? true : storedSessionsVisible === true;
    /** Timer id for the deferred hide after the close-slide transition finishes. */
    this.closeTimer = 0;
    /** Rail visibility to restore once the info drawer (which borrows the rail's space) closes. */
    this._railStateBeforeInfo = null;
    this.providers = [];
    this.selectedProvider = 'default';
    /** One-line notice after the editor navigated while the conversation continues. */
    this.contextNotice = '';
    /** uuid of the conversation being renamed in the list. */
    this.renamingUuid = '';
    /** Run one more turn after the editor confirms / declines a card (agentContinueAfterConfirm). */
    this.continueAfterConfirm = true;
    /** @type {{outcome: string, label: string, result: string}|null} */
    this.pendingContinuation = null;
    /** true while "Execute all" applies several cards (one continuation at the end). */
    this.executingAll = false;
    /** T3Planet Credits status, null outside credits mode. */
    this.credits = null;
    this.creditsBadge = root.querySelector('[data-nst3af-agent-credits]');
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
    this.workTraceTimer = 0;
    /** Rotates the small set of "thinking" filler words while no specific tool label is available. */
    this.progressRotateTimer = 0;
    this.progressRotateIndex = -1;
    /** Elapsed-time readout next to the progress label, shown once a step runs a few seconds. */
    this.progressClockTimer = 0;
    this.progressStartedAt = 0;

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
}

Object.assign(
  AgentController.prototype,
  sessionMethods,
  chromeMethods,
  draftMethods,
  streamMethods,
  turnMethods,
  autocompleteMethods,
);

// Object.assign copies accessor *values*, not getters/setters. Reinstall isRunning so
// Send↔Stop chrome and the plan spinner actually update when a turn starts/ends.
Object.defineProperty(AgentController.prototype, 'isRunning', {
  configurable: true,
  enumerable: true,
  get() {
    return this._isRunning === true;
  },
  set(value) {
    this._isRunning = value === true;
    this.applyRunningChrome?.();
  },
});

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

/**
 * Open/close (toggle) shortcut for TYPO3 13–14: bind keydown (capture) on the scaffold and every
 * same-origin iframe. Live Search uses an exact Hotkeys combo, so
 * stopImmediatePropagation is a no-op there except still opening Agent from the
 * iframe.
 *
 * @param {KeyboardEvent} event
 */
function handleOpenHotkey(event) {
  if (controller === null) {
    return;
  }
  if (!matchesAgentHotkey(event, readHotkeyPref())) {
    return;
  }
  event.preventDefault();
  event.stopImmediatePropagation();
  if (controller.isOpen) {
    // Same chord again closes the panel (toggle).
    controller.close();
    return;
  }
  controller.open(getBackendDocument().querySelector('[data-nst3af-agent-open]'));
}

/**
 * @param {Document|null|undefined} doc
 */
function bindOpenHotkeyOnDocument(doc) {
  if (!doc || doc[OPEN_HOTKEY_BOUND]) {
    return;
  }
  try {
    doc[OPEN_HOTKEY_BOUND] = true;
  } catch {
    return;
  }
  doc.addEventListener('keydown', handleOpenHotkey, true);
}

/**
 * @param {HTMLIFrameElement} iframe
 */
function watchIframeElement(iframe) {
  if (iframe[IFRAME_WATCHED]) {
    return;
  }
  iframe[IFRAME_WATCHED] = true;
  iframe.addEventListener('load', () => {
    try {
      bindOpenHotkeyOnDocument(iframe.contentDocument);
    } catch {
      // Cross-origin module frame.
    }
  });
  try {
    bindOpenHotkeyOnDocument(iframe.contentDocument);
  } catch {
    // Cross-origin module frame.
  }
}

function bindOpenHotkeyAcrossFrames() {
  const backendDoc = getBackendDocument();
  bindOpenHotkeyOnDocument(backendDoc);
  if (document !== backendDoc) {
    bindOpenHotkeyOnDocument(document);
  }

  backendDoc.querySelectorAll('iframe').forEach((node) => {
    if (node instanceof HTMLIFrameElement) {
      watchIframeElement(node);
    }
  });

  const backendWin = backendDoc.defaultView ?? window;
  try {
    for (let i = 0; i < backendWin.frames.length; i++) {
      bindOpenHotkeyOnDocument(backendWin.frames[i].document);
    }
  } catch {
    // A frame may be cross-origin.
  }
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

  // .scaffold-header is the real full-width row (spans both grid columns, position:relative
  // via CSS) that TYPO3 core lays .scaffold-topbar (logo, search) and .scaffold-toolbar
  // (module icon list) out in as flex siblings. The slot is absolutely centered against it
  // (see agent.css), so DOM order inside .scaffold-header doesn't matter — any child works.
  const scaffoldHeader = topbar.closest('.scaffold-header')
    ?? backendDoc.querySelector('.scaffold-header, .t3js-scaffold-header');

  let slot = (scaffoldHeader ?? topbar).querySelector('.nst3af-agent-launchbar-slot');
  if (!(slot instanceof HTMLElement)) {
    slot = backendDoc.createElement('div');
    slot.className = 'nst3af-agent-launchbar-slot';
    if (scaffoldHeader instanceof HTMLElement) {
      scaffoldHeader.appendChild(slot);
    } else {
      const anchor = topbar.querySelector('.topbar-button-search, .t3js-topbar-button-search');
      if (anchor instanceof HTMLElement && anchor.parentElement) {
        anchor.parentElement.insertBefore(slot, anchor);
      } else {
        topbar.appendChild(slot);
      }
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
  bindOpenHotkeyAcrossFrames();
  const backendDoc = getBackendDocument();
  backendDoc.addEventListener('typo3-iframe-loaded', bindOpenHotkeyAcrossFrames);
  backendDoc.addEventListener('typo3-module-loaded', bindOpenHotkeyAcrossFrames);
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
  void preloadModuleStateStorage();
  void ensureMessageRenderer();
  DocumentService.ready().then(initialize);
}

export default { boot };
