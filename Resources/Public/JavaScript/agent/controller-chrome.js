/**
 * AgentController mixin: panel open/close, drawers, info/context notices, focus trap.
 */

import { hotkeyLabel, readHotkeyPref, STORAGE_OPEN_KEY, STORAGE_PREFS_KEY } from './hotkeys.js';
import { lang, errorMessage, escapeHtml } from './format.js';
import { ajaxUrl } from './context.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Persistent from '@typo3/backend/storage/persistent.js';

export const chromeMethods = {
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
    },

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
        if (target.closest('[data-nst3af-agent-stop]')) {
          event.preventDefault();
          this.stopTurn();
        }
        const clarifyOption = target.closest('[data-nst3af-agent-clarify-option]');
        if (clarifyOption instanceof HTMLButtonElement) {
          event.preventDefault();
          void this.answerClarification(clarifyOption.dataset.nst3afAgentClarifyOption ?? '');
        }
        if (target.closest('[data-nst3af-agent-execute-all]')) {
          event.preventDefault();
          void this.executeAll();
        }
        const resultLink = target.closest('[data-nst3af-agent-link]');
        if (resultLink instanceof HTMLAnchorElement && this.openResultLink(resultLink)) {
          event.preventDefault();
        }
        if (target.closest('[data-nst3af-agent-sessions-toggle]')) {
          event.preventDefault();
          void this.toggleSessions();
        }
        if (target.closest('[data-nst3af-agent-info-toggle]')) {
          event.preventDefault();
          this.toggleInfo();
        }
        if (target.closest('[data-nst3af-agent-drawer-close]')) {
          event.preventDefault();
          this.closeInfoDrawer();
        }
        if (target.closest('[data-nst3af-agent-new]')) {
          event.preventDefault();
          void this.startNewConversation();
        }
        if (target.closest('[data-nst3af-agent-fullscreen]')) {
          event.preventDefault();
          this.toggleFullscreen();
        }
        if (target.closest('[data-nst3af-agent-summarize]')) {
          event.preventDefault();
          void this.summarizeConversation();
        }
        const filterButton = target.closest('[data-nst3af-agent-sessions-filter-value]');
        if (filterButton instanceof HTMLElement) {
          event.preventDefault();
          this.sessionsFilter = filterButton.dataset.nst3afAgentSessionsFilterValue === 'all' ? 'all' : 'current';
          void this.loadSessions();
        }
        if (target.closest('[data-nst3af-agent-sessions-more]')) {
          event.preventDefault();
          void this.loadSessions(true);
        }
        const renameButton = target.closest('[data-nst3af-agent-session-rename]');
        if (renameButton instanceof HTMLElement) {
          event.preventDefault();
          void this.renameSession(renameButton.dataset.nst3afAgentSessionRename ?? '');
        } else {
          const deleteButton = target.closest('[data-nst3af-agent-session-delete]');
          if (deleteButton instanceof HTMLElement) {
            event.preventDefault();
            void this.deleteSession(deleteButton.dataset.nst3afAgentSessionDelete ?? '', deleteButton);
          } else {
            const openButton = target.closest('[data-nst3af-agent-session-open]');
            if (openButton instanceof HTMLElement) {
              event.preventDefault();
              void this.openSession(openButton.dataset.nst3afAgentSessionOpen ?? '');
            }
          }
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
        if (target.closest('[data-nst3af-agent-suggestions-apply]')) {
          event.preventDefault();
          this.applySuggestions(target.closest('[data-nst3af-agent-suggestions-apply]'), 'all');
        }
        if (target.closest('[data-nst3af-agent-suggestions-apply-safe]')) {
          event.preventDefault();
          this.applySuggestions(target.closest('[data-nst3af-agent-suggestions-apply-safe]'), 'safe');
        }
        if (target.closest('[data-nst3af-agent-suggestions-discard]')) {
          event.preventDefault();
          this.discardSuggestions(target.closest('[data-nst3af-agent-suggestions-discard]'));
        }
        if (target.closest('[data-nst3af-agent-suggestions-select]')) {
          this.selectSuggestionVariant(target.closest('[data-nst3af-agent-suggestions-select]'));
        }
        if (target.closest('[data-nst3af-agent-suggestions-edit-toggle]')) {
          this.enableSuggestionEdit(target.closest('[data-nst3af-agent-suggestions-edit-toggle]'));
        }
        if (target.closest('[data-nst3af-agent-undo]')) {
          event.preventDefault();
          this.undoChange(target.closest('[data-nst3af-agent-undo]'));
        }
        if (target.closest('[data-nst3af-agent-attach-toggle]')) {
          event.preventDefault();
          this.toggleAttachMenu();
        }
        if (target.closest('[data-nst3af-agent-attach-files]')) {
          event.preventDefault();
          this.openFilePicker();
        }
        if (target.closest('[data-nst3af-agent-attach-mcp-tools]')) {
          event.preventDefault();
          this.openMcpToolsPicker();
        }
        if (target.closest('[data-nst3af-agent-handoff-dismiss]')) {
          event.preventDefault();
          const card = target.closest('[data-nst3af-agent-handoff]');
          card?.remove();
        }
      });

      document.addEventListener('keydown', (event) => {
        if (!this.isOpen) {
          return;
        }

        if (event.key === 'Escape') {
          event.preventDefault();
          if (this.closeInfoDrawer()) {
            return;
          }
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

      window.addEventListener('resize', () => {
        this.positionAutocomplete?.();
      });
      this.stream?.addEventListener('scroll', () => {
        this.positionAutocomplete?.();
      }, { passive: true });

      this.providerSelect?.addEventListener('change', () => {
        if (this.providerSelect instanceof HTMLSelectElement) {
          this.selectedProvider = this.providerSelect.value || 'default';
        }
      });

      this.sessionsSearch?.addEventListener('input', () => {
        this.renderSessions();
      });

      document.addEventListener('input', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target?.closest?.('[data-nst3af-agent-suggestions-edit]')) {
          return;
        }
        this.updateSuggestionEdit(target.closest('[data-nst3af-agent-suggestions-edit]'));
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

      this.bindSessionsResize();
      this.bindPanelResize();
    },

  /**
     * Drag (or arrow-key) resize for the whole panel's width, same idiom as the sessions-rail
     * resize below. The panel is anchored to the right edge, so dragging the handle further
     * left widens it up to nearly the full viewport (small margin so the handle stays
     * grabable). Once the editor has resized manually, that width is remembered (Persistent)
     * and takes over from the responsive --nst3af-agent-width media-query bands; a window
     * resize re-clamps it if the viewport got too narrow —
     * same fallback idiom as TYPO3 core's own tree resizer
     * (fallbackNavigationSizeIfNeeded in resizable-navigation.js / content-navigation.js).
     */
    bindPanelResize() {
      const handle = this.root?.querySelector('[data-nst3af-agent-panel-resize]');
      if (!(handle instanceof HTMLElement) || !(this.panel instanceof HTMLElement)) {
        return;
      }

      const MIN_WIDTH = 400;
      // Keep a thin strip so the left-edge resize handle remains usable at max width.
      const SAFE_MARGIN = 16;
      const STEP = 24;

      const maxWidth = () => Math.max(MIN_WIDTH, window.innerWidth - SAFE_MARGIN);

      const applyWidth = (width, { persist = false } = {}) => {
        const clamped = Math.min(maxWidth(), Math.max(MIN_WIDTH, Math.round(width)));
        this.panel.style.setProperty('--nst3af-agent-width', `${clamped}px`);
        handle.setAttribute('aria-valuenow', String(clamped));
        handle.setAttribute('aria-valuemax', String(maxWidth()));
        if (persist) {
          void Persistent.set('nst3af.agent.panelWidth', clamped).catch((error) => {
            console.warn('Agent panel width could not be saved:', errorMessage(error));
          });
        }
        return clamped;
      };

      handle.setAttribute('aria-valuemin', String(MIN_WIDTH));

      const storedWidth = Number(Persistent.get('nst3af.agent.panelWidth'));
      if (Number.isFinite(storedWidth) && storedWidth > 0) {
        applyWidth(storedWidth);
      }

      const currentWidth = () => {
        const value = parseInt(this.panel.style.getPropertyValue('--nst3af-agent-width'), 10);
        return Number.isFinite(value) ? value : this.panel.getBoundingClientRect().width;
      };

      let startX = 0;
      let startWidth = 0;

      const onMove = (event) => {
        const clientX = event.touches ? event.touches[0].clientX : event.clientX;
        applyWidth(startWidth + (startX - clientX));
      };

      const onUp = () => {
        handle.classList.remove('is-resizing');
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        document.removeEventListener('touchmove', onMove);
        document.removeEventListener('touchend', onUp);
        applyWidth(currentWidth(), { persist: true });
      };

      const onDown = (event) => {
        if (typeof event.button === 'number' && event.button !== 0) {
          return;
        }
        startX = event.touches ? event.touches[0].clientX : event.clientX;
        startWidth = this.panel.getBoundingClientRect().width;
        handle.classList.add('is-resizing');
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
        document.addEventListener('touchmove', onMove, { passive: true });
        document.addEventListener('touchend', onUp);
        event.preventDefault();
      };

      handle.addEventListener('mousedown', onDown);
      handle.addEventListener('touchstart', onDown, { passive: false });
      handle.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft') {
          event.preventDefault();
          applyWidth(currentWidth() + STEP, { persist: true });
        } else if (event.key === 'ArrowRight') {
          event.preventDefault();
          applyWidth(currentWidth() - STEP, { persist: true });
        }
      });

      window.addEventListener('resize', () => {
        if (this.panel.style.getPropertyValue('--nst3af-agent-width') === '') {
          return;
        }
        applyWidth(currentWidth());
      });
    },

  /**
     * Drag (or arrow-key) resize for the sessions rail, same idiom as TYPO3 core's own
     * page/file-tree resizer. Width is a CSS custom property on the rail element itself,
     * remembered per editor via Persistent, applied once at bind time (before the rail is
     * ever shown) so there is no flash of the default width.
     */
    bindSessionsResize() {
      const handle = this.root?.querySelector('[data-nst3af-agent-sessions-resize]');
      if (!(handle instanceof HTMLElement) || !(this.sessionsDrawer instanceof HTMLElement)) {
        return;
      }

      const MIN_WIDTH = 200;
      const MAX_WIDTH = 420;
      const DEFAULT_WIDTH = 248;
      const STEP = 16;

      const applyWidth = (width, { persist = false } = {}) => {
        const clamped = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, Math.round(width)));
        this.sessionsDrawer.style.setProperty('--nst3af-agent-rail-width', `${clamped}px`);
        handle.setAttribute('aria-valuenow', String(clamped));
        if (persist) {
          void Persistent.set('nst3af.agent.sessionsRailWidth', clamped).catch((error) => {
            console.warn('Agent sessions rail width could not be saved:', errorMessage(error));
          });
        }
        return clamped;
      };

      handle.setAttribute('aria-valuemin', String(MIN_WIDTH));
      handle.setAttribute('aria-valuemax', String(MAX_WIDTH));

      const storedWidth = Number(Persistent.get('nst3af.agent.sessionsRailWidth'));
      applyWidth(Number.isFinite(storedWidth) && storedWidth > 0 ? storedWidth : DEFAULT_WIDTH);

      const currentWidth = () => {
        const value = parseInt(this.sessionsDrawer.style.getPropertyValue('--nst3af-agent-rail-width'), 10);
        return Number.isFinite(value) ? value : DEFAULT_WIDTH;
      };

      let startX = 0;
      let startWidth = DEFAULT_WIDTH;

      const onMove = (event) => {
        const clientX = event.touches ? event.touches[0].clientX : event.clientX;
        applyWidth(startWidth + (clientX - startX));
      };

      const onUp = () => {
        handle.classList.remove('is-resizing');
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        document.removeEventListener('touchmove', onMove);
        document.removeEventListener('touchend', onUp);
        applyWidth(currentWidth(), { persist: true });
      };

      const onDown = (event) => {
        if (typeof event.button === 'number' && event.button !== 0) {
          return;
        }
        startX = event.touches ? event.touches[0].clientX : event.clientX;
        startWidth = this.sessionsDrawer.getBoundingClientRect().width;
        handle.classList.add('is-resizing');
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
        document.addEventListener('touchmove', onMove, { passive: true });
        document.addEventListener('touchend', onUp);
        event.preventDefault();
      };

      handle.addEventListener('mousedown', onDown);
      handle.addEventListener('touchstart', onDown, { passive: false });
      handle.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft') {
          event.preventDefault();
          applyWidth(currentWidth() - STEP, { persist: true });
        } else if (event.key === 'ArrowRight') {
          event.preventDefault();
          applyWidth(currentWidth() + STEP, { persist: true });
        }
      });
    },

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
    },

  /**
     * @param {Element|null} opener
     */
    async open(opener) {
      if (this.isOpen) {
        return;
      }

      window.clearTimeout(this.closeTimer);

      this.hotkey = readHotkeyPref();
      this.applyHotkeyChrome();

      this.lastFocus = opener instanceof HTMLElement ? opener : document.activeElement;
      this.isOpen = true;
      this.root.hidden = false;
      this.backdrop.hidden = false;
      this.panel.hidden = false;
      this.panel.setAttribute('aria-hidden', 'false');
      // Force a layout flush so the browser commits the closed (translateX) state before the
      // --open class flips it, otherwise the two style changes get coalesced into one paint
      // and the slide-in transition never runs.
      void this.panel.offsetHeight;
      this.panel.classList.add('nst3af-agent-panel--open');

      this.messages = [];
      this.context = {};
      this.starters = { executable: [], locked: [] };
      this.contextNotice = '';
      this.closeInfoDrawer();
      this.livePlan = null;
      this.isLoadingSession = true;
      this.panel.setAttribute('aria-busy', 'true');
      this.renderLoadingSkeleton();

      // Resume the last-viewed conversation for this conversation-scope key when remembered;
      // otherwise the server opens the latest of the configured scope (see restoreSession).
      await this.restoreSession(this.lastSessionOpenOptions());
      this.isLoadingSession = false;
      this.loadedScopeKey = this.sessionScopeKey();
      this.panel.removeAttribute('aria-busy');

      this.renderContext();
      this.renderStream();
      this.restoreFullscreenPref();

      // Apply the remembered/default rail state now that sessionListSettings.enabled is known
      // from the server payload — the rail stayed in its template-default [hidden] state during
      // the load above, so there is no flash of the wrong state to correct.
      const effectiveRailOpen = this.sessionListSettings.enabled === true && this.sessionsRailOpen;
      this.setSessionsRailOpen(effectiveRailOpen, { persist: false });
      if (effectiveRailOpen) {
        this.sessionsFilter = this.sessionListSettings.scope === 'user' ? 'all' : (this.sessionListSettings.defaultFilter ?? 'current');
        if (this.sessionsSearch instanceof HTMLInputElement) {
          this.sessionsSearch.value = '';
        }
        await this.loadSessions();
      }

      this.announce(lang('agent.live.opened', 'AI Agent opened.'));
      this.trapFocus();
      this.input?.focus();
      this.resizeComposerInput?.();
      this.warnIfNoUsableProvider();

      try {
        localStorage.setItem(STORAGE_OPEN_KEY, '1');
      } catch {
        // ponytail: localStorage may be unavailable; open state is session-only.
      }
    },

  /**
     * Flash when the conversation payload reports no real tool-calling provider.
     * options() always includes a synthetic "default", so providers.length is useless here.
     */
    warnIfNoUsableProvider() {
      if (this.hasUsableProvider !== false) {
        return;
      }
      Notification.warning(
        lang('agent.provider.none.title', 'No AI provider configured'),
        lang('agent.provider.none.body', 'Configure an AI provider that can call tools before using the AI Agent.'),
      );
    },

  close() {
      if (!this.isOpen) {
        return;
      }

      this.isOpen = false;
      this.hideAutocomplete();
      this.panel.setAttribute('aria-hidden', 'true');
      this.panel.classList.remove('nst3af-agent-panel--open');

      // Defer the actual hide until the slide-out transition finishes, so the panel is visibly
      // seen sliding away instead of vanishing the instant the backdrop/root go [hidden].
      window.clearTimeout(this.closeTimer);
      this.closeTimer = window.setTimeout(() => {
        this.panel.hidden = true;
        this.backdrop.hidden = true;
        this.root.hidden = true;
      }, 260);

      this.announce(lang('agent.live.closed', 'AI Agent closed.'));

      if (this.lastFocus instanceof HTMLElement) {
        this.lastFocus.focus();
      }

      try {
        localStorage.setItem(STORAGE_OPEN_KEY, '0');
      } catch {
        // ignore
      }
    },

  /**
     * Provider select (locked once the conversation has an answer) and the list button.
     */
    renderHeaderControls() {
      if (this.sessionsToggle instanceof HTMLElement) {
        this.sessionsToggle.hidden = this.sessionListSettings.enabled !== true;
      }
      if (!(this.providerSelect instanceof HTMLSelectElement)) {
        return;
      }
      const locked = !this.freshSession && Boolean(this.session?.provider) && Number(this.session?.messageCount ?? 0) > 0;
      const options = [...this.providers];
      if (locked && !options.some((option) => option.value === this.session.provider)) {
        options.push({ value: this.session.provider, label: this.session.providerLabel || this.session.provider });
      }
      this.providerSelect.innerHTML = options.map((option) => (
        `<option value="${escapeHtml(String(option.value ?? ''))}">${escapeHtml(String(option.label ?? option.value ?? ''))}</option>`
      )).join('');
      const value = locked ? this.session.provider : this.selectedProvider;
      this.providerSelect.value = options.some((option) => option.value === value) ? value : 'default';
      this.selectedProvider = locked ? this.selectedProvider : this.providerSelect.value;
      this.providerSelect.disabled = locked;
      this.providerSelect.title = locked
        ? lang('agent.provider.locked', 'The AI provider is fixed for this conversation. Start a new conversation to change it.')
        : lang('agent.provider.label', 'AI provider');
      this.providerSelect.hidden = options.length <= 1;
    },

  /**
     * @returns {boolean} true when the info drawer was open
     */
    closeInfoDrawer() {
      let wasOpen = false;
      if (this.infoDrawer instanceof HTMLElement && !this.infoDrawer.hidden) {
        this.infoDrawer.hidden = true;
        wasOpen = true;
      }
      this.infoToggle?.setAttribute('aria-expanded', 'false');
      // Info collapses the rail for a11y; every close path (toggle, Escape, drawer ×,
      // session pick) must restore the pre-info rail state. Persist:false — a peek must
      // not rewrite the editor's stored preference via setSessionsRailOpen(false).
      if (wasOpen && this._railStateBeforeInfo !== null) {
        this.setSessionsRailOpen(Boolean(this._railStateBeforeInfo), { persist: false });
        this._railStateBeforeInfo = null;
      }

      return wasOpen;
    },

  /**
     * Sessions rail: a persistent side column, not a dismissible overlay. Visibility is the
     * editor's remembered choice (or "visible" by default), independent of the info drawer's
     * open/close lifecycle.
     *
     * @param {boolean} open
     * @param {{persist?: boolean}} [options]
     */
    setSessionsRailOpen(open, { persist = true } = {}) {
      this.sessionsRailOpen = open;
      if (this.sessionsDrawer instanceof HTMLElement) {
        this.sessionsDrawer.hidden = !open;
      }
      const resizeHandle = this.root?.querySelector('[data-nst3af-agent-sessions-resize]');
      if (resizeHandle instanceof HTMLElement) {
        resizeHandle.hidden = !open;
      }
      this.sessionsToggle?.setAttribute('aria-pressed', String(open));
      if (!open) {
        this.renamingUuid = '';
      }
      if (persist) {
        void Persistent.set('nst3af.agent.sessionsVisible', open).catch((error) => {
          console.warn('Agent sessions rail preference could not be saved:', errorMessage(error));
        });
      }
    },

  /**
     * Drawers start below the header, so the header buttons stay usable.
     *
     * @param {HTMLElement} drawer
     */
    positionDrawer(drawer) {
      const header = this.panel?.querySelector('.nst3af-agent-header');
      drawer.style.top = header instanceof HTMLElement ? `${header.offsetHeight}px` : '0';
    },

  toggleInfo() {
      if (!(this.infoDrawer instanceof HTMLElement)) {
        return;
      }
      // Closing restores the rail inside closeInfoDrawer() (shared with Escape / drawer ×).
      if (!this.infoDrawer.hidden) {
        this.closeInfoDrawer();
        return;
      }
      // The info drawer overlays the rail's space and the a11y focus trap has no occlusion
      // check, so the rail is collapsed while info is open and restored (not re-persisted)
      // once it closes, rather than left reachable-but-hidden underneath.
      this._railStateBeforeInfo = this.sessionsRailOpen;
      this.setSessionsRailOpen(false, { persist: false });
      this.renderInfo();
      this.positionDrawer(this.infoDrawer);
      this.infoDrawer.hidden = false;
      this.infoToggle?.setAttribute('aria-expanded', 'true');
    },

  /**
     * "How the AI Agent works", with a live "Where you are" section from the current context.
     */
    renderInfo() {
      if (!(this.infoBody instanceof HTMLElement)) {
        return;
      }
      const details = this.context.details ?? {};
      const section = (key, title, body) => `<section class="nst3af-agent-info__section" data-nst3af-agent-info-section="${key}">
        <h4>${escapeHtml(title)}</h4>${body}</section>`;
      const paragraph = (text) => `<p>${escapeHtml(text)}</p>`;
      const list = (items) => `<ul>${items.map((item) => `<li>${item}</li>`).join('')}</ul>`;

      const examples = (Array.isArray(this.starters.executable) ? this.starters.executable : [])
        .slice(0, 6)
        .map((tool) => escapeHtml(String(tool.label ?? tool.editorLabel ?? tool.name ?? '')))
        .filter((label) => label !== '');

      const where = [];
      if (details.module?.route) {
        where.push(`<strong>${escapeHtml(lang('agent.context.module', 'Module'))}:</strong> ${escapeHtml(String(details.module.label ?? details.module.route))}`);
      }
      if (details.page) {
        const slug = details.page.slug ? ` · ${escapeHtml(String(details.page.slug))}` : '';
        where.push(`<strong>${escapeHtml(lang('agent.context.page', 'Page'))}:</strong> ${escapeHtml(String(details.page.title ?? ''))} [${Number(details.page.uid ?? 0)}]${slug}`);
      } else {
        where.push(`<strong>${escapeHtml(lang('agent.context.page', 'Page'))}:</strong> ${escapeHtml(lang('agent.info.noPage', 'none selected'))}`);
      }
      if (details.language) {
        where.push(`<strong>${escapeHtml(lang('agent.context.language', 'Language'))}:</strong> ${escapeHtml(String(details.language.title ?? ''))}`);
      }
      if (details.record) {
        where.push(`<strong>${escapeHtml(lang('agent.context.record', 'Record'))}:</strong> ${escapeHtml(`${details.record.tableLabel ?? details.record.table} ${details.record.label ? `"${details.record.label}"` : ''} #${details.record.uid}`)}`);
      }
      if (details.folder) {
        where.push(`<strong>${escapeHtml(lang('agent.context.folder', 'Folder'))}:</strong> ${escapeHtml(String(details.folder.identifier ?? ''))}`);
      }
      const live = details.workspace?.live !== false;
      where.push(`<strong>${escapeHtml(lang('agent.context.workspace', 'Workspace'))}:</strong> ${escapeHtml(String(details.workspace?.title ?? ''))}`);

      const provider = this.session?.providerLabel
        || (this.providers.find((option) => option.value === (this.session?.provider || this.selectedProvider))?.label ?? '');

      this.infoBody.innerHTML = [
        section('can', lang('agent.info.canTitle', 'What it can do'),
          paragraph(lang('agent.info.canBody', 'Ask in your own words, in any language. The agent looks things up, prepares changes and runs the AI features of the installed extensions (SEO, translation, pages and content, alt texts, chatbot knowledge …).'))
          + (examples.length ? paragraph(lang('agent.info.canExamples', 'On this screen, for example:')) + list(examples) : '')),
        section('where', lang('agent.info.whereTitle', 'Where you are'),
          list(where) + paragraph(lang('agent.info.whereBody', 'The agent sees what you are working on. "This page" and "here" mean the page shown above, unless you name another one.'))),
        section('how', lang('agent.info.howTitle', 'How a task runs'),
          `<ol>${[
            lang('agent.info.howStep1', 'It looks up what it needs (pages, content, settings).'),
            lang('agent.info.howStep2', 'For a change it shows a preview with old and new values.'),
            lang('agent.info.howStep3', 'You confirm, edit or decline. Deleting needs a second click.'),
            lang('agent.info.howStep4', 'Only then is anything written.'),
          ].map((step) => `<li>${escapeHtml(step)}</li>`).join('')}</ol>`),
        section('mode', lang('agent.info.modeTitle', 'Live or draft'),
          paragraph(live
            ? lang('agent.info.modeLive', 'You are in the live workspace: confirmed changes are visible on the website right away.')
            : lang('agent.info.modeDraft', 'You are in the workspace "%1$s": confirmed changes stay a draft until they are published.', [String(details.workspace?.title ?? '')]))),
        section('conversations', lang('agent.info.conversationsTitle', 'Conversations'),
          paragraph(lang(`agent.info.conversations.${this.sessionListSettings.scope ?? 'page'}`, 'Each page has its own conversations.'))
          + paragraph(lang('agent.info.conversationsList', 'Find earlier conversations in the list; they are deleted after %1$s days without activity.', [String(this.sessionListSettings.retentionDays ?? 90)]))),
        section('models', lang('agent.info.modelsTitle', 'Models and credits'),
          paragraph(provider !== ''
            ? lang('agent.info.modelsBody', 'This conversation uses %1$s. The provider is fixed after the first answer; start a new conversation to use another one.', [provider])
            : lang('agent.info.modelsDefault', 'The default AI provider of this site is used.'))),
        section('privacy', lang('agent.info.privacyTitle', 'Data protection'),
          paragraph(lang('agent.info.privacyBody', 'Your messages and the content the agent reads are sent to the AI provider shown above. Your backend permissions and the AI Permissions of your group always apply.'))),
        section('attachments', lang('agent.info.attachmentsTitle', 'Attachments'),
          paragraph(lang('agent.info.attachmentsBody', 'Use + to add files, @ to point at a record and / to pick a tool directly.'))),
        section('tips', lang('agent.info.tipsTitle', 'Limits and tips'),
          paragraph(lang('agent.info.tipsBody', 'Be specific ("meta description for this page in German"). Long jobs for many pages go to the scheduler. Answers can be wrong: check previews before you confirm.'))),
      ].join('');
    },

  /**
     * @returns {string}
     */
    renderHomeNotice() {
      if (!this.session || this.freshSession || this.session.isHere !== false || this.messages.length === 0) {
        return '';
      }
      const home = this.session.pageId > 0
        ? `${this.session.pageTitle ?? ''} [${this.session.pageId}]`
        : String(this.session.moduleLabel ?? '');
      const page = this.context.details?.page;
      const here = page ? `${page.title ?? ''} [${page.uid ?? 0}]` : String(this.context.details?.module?.label ?? '');
      return `<div class="nst3af-agent-notice" role="note">${escapeHtml(lang('agent.session.otherPage', 'This conversation was started on %1$s. You are now on %2$s; new requests use the current page.', [home, here]))}</div>`;
    },

  /**
     * @returns {string}
     */
    renderContextNotice() {
      return this.contextNotice !== ''
        ? `<div class="nst3af-agent-notice nst3af-agent-notice--system" role="status">${escapeHtml(this.contextNotice)}</div>`
        : '';
    },

  /**
     * @returns {string}
     */
    describeContextChange() {
      const page = this.context.details?.page;
      if (page) {
        return lang('agent.session.nowOnPage', 'Now working on page %1$s.', [`${page.title ?? ''} [${page.uid ?? 0}]`]);
      }
      return lang('agent.session.nowInModule', 'Now working in %1$s.', [String(this.context.details?.module?.label ?? '')]);
    },

  /**
     * @param {number} timestamp seconds
     * @returns {string}
     */
    relativeTime(timestamp) {
      if (!timestamp) {
        return '';
      }
      const seconds = Math.round(timestamp - Date.now() / 1000);
      const units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];
      try {
        const format = new Intl.RelativeTimeFormat(document.documentElement.lang || undefined, { numeric: 'auto' });
        for (const [unit, size] of units) {
          if (Math.abs(seconds) >= size) {
            return format.format(Math.round(seconds / size), unit);
          }
        }
        return format.format(0, 'minute');
      } catch {
        return new Date(timestamp * 1000).toLocaleString();
      }
    },

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
    },

  renderContext() {
      if (!this.contextEl || this.isLoadingSession) {
        return;
      }

      const chips = Array.isArray(this.context.chips) ? this.context.chips : [];
      const dimChip = this.context.contextAware
        ? `<span class="nst3af-agent-ctxchip nst3af-agent-ctxchip--dim">${escapeHtml(lang('agent.context.aware', 'Knows what you are looking at'))}</span>`
        : '';
      const icons = {
        page: 'apps-pagetree-page',
        module: 'module-generic',
        language: 'actions-globe',
        record: 'actions-document-edit',
        folder: 'apps-filetree-folder-default',
        workspace: 'apps-toolbar-menu-workspace',
        brand: 'actions-tag',
      };
      this.contextEl.innerHTML = dimChip + chips.map((chip) => {
        const key = String(chip.key ?? '');
        const icon = icons[key] ? `<typo3-backend-icon identifier="${icons[key]}" size="small" aria-hidden="true"></typo3-backend-icon> ` : '';
        const hint = escapeHtml(String(chip.hint ?? ''));
        return `<span class="nst3af-agent-ctxchip nst3af-agent-ctxchip--${escapeHtml(key)}" title="${hint}">${icon}<span class="visually-hidden">${escapeHtml(String(chip.label ?? ''))}: </span>${escapeHtml(String(chip.value ?? ''))}</span>`;
      }).join('');
    },

  toggleFullscreen() {
      if (!(this.panel instanceof HTMLElement)) {
        return;
      }
      const next = !this.panel.classList.contains('nst3af-agent-panel--fullscreen');
      this.setFullscreen(next, { persist: true });
    },

  /**
     * @param {boolean} enabled
     * @param {{ persist?: boolean }} [options]
     */
    setFullscreen(enabled, options = {}) {
      if (!(this.panel instanceof HTMLElement)) {
        return;
      }
      this.panel.classList.toggle('nst3af-agent-panel--fullscreen', enabled);
      const button = this.root.querySelector('[data-nst3af-agent-fullscreen]');
      if (button instanceof HTMLElement) {
        button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        const label = enabled
          ? lang('agent.panel.fullscreenExit', 'Exit full screen')
          : lang('agent.panel.fullscreen', 'Full screen');
        button.setAttribute('title', label);
        button.setAttribute('aria-label', label);
      }
      if (options.persist !== false) {
        try {
          const prefs = JSON.parse(localStorage.getItem(STORAGE_PREFS_KEY) ?? '{}');
          prefs.fullscreen = enabled;
          localStorage.setItem(STORAGE_PREFS_KEY, JSON.stringify(prefs));
        } catch {
          // ignore
        }
      }
    },

  restoreFullscreenPref() {
      try {
        const prefs = JSON.parse(localStorage.getItem(STORAGE_PREFS_KEY) ?? '{}');
        if (prefs?.fullscreen === true) {
          this.setFullscreen(true, { persist: false });
        }
      } catch {
        // ignore
      }
    },

  trapFocus() {
      // Focus trap is enforced via handleFocusTrap on Tab.
    },

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
    },

  /**
     * @param {string} text
     */
    announce(text) {
      if (!this.stream) {
        return;
      }
      this.stream.setAttribute('aria-label', text);
    },
};
