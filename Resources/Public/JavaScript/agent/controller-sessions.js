/**
 * AgentController mixin: conversation session list, open/rename/delete, scope handling.
 */

import { lang, errorMessage, escapeHtml } from './format.js';
import { ajaxUrl, resolveBackendContext } from './context.js';
import { stripEphemeralWorkTraceMeta } from './render-helpers.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Persistent from '@typo3/backend/storage/persistent.js';

/** Last-viewed conversation uuid + scope key (same Persistent uc store as rail/panel width). */
const STORAGE_LAST_SESSION_KEY = 'nst3af.agent.lastSession';

/** One background warm per backend page load (embedMany can take minutes when stale). */
let toolIndexWarmRequested = false;

/**
 * Rebuild tool embeddings off the open path (keywords cover find_tools until ready).
 */
function warmToolIndexInBackground() {
  if (toolIndexWarmRequested) {
    return;
  }
  const url = ajaxUrl('nst3af_agent_index_warm');
  if (url === '') {
    return;
  }
  toolIndexWarmRequested = true;
  void new AjaxRequest(url).get().catch(() => {
    // Index warm is best-effort; find_tools falls back to keywords.
  });
}

export const sessionMethods = {
  /**
     * @param {unknown} title
     */
    applySessionTitle(title) {
      const clean = typeof title === 'string' ? title.trim() : '';
      if (clean === '' || !this.session) {
        return;
      }
      this.session = { ...this.session, title: clean };
      this.ensureSessionInList(this.session);
    },

  /**
     * Keep the Conversations rail in sync when a fresh chat gets its first saved row.
     *
     * @param {object|null|undefined} session
     */
    ensureSessionInList(session) {
      if (!session || typeof session !== 'object') {
        return;
      }
      const uuid = String(session.uuid ?? '');
      if (uuid === '') {
        return;
      }
      if (!Array.isArray(this.sessions)) {
        this.sessions = [];
      }
      const index = this.sessions.findIndex((row) => String(row.uuid ?? '') === uuid);
      if (index >= 0) {
        this.sessions[index] = { ...this.sessions[index], ...session };
      } else {
        this.sessions = [session, ...this.sessions];
      }
      this.renderSessions?.();
    },

  /**
     * After a turn saves (or retitles) a conversation, keep the open rail in sync with the server.
     */
    refreshSessionsRail() {
      if (this.sessionsRailOpen !== true || !(this.sessionsDrawer instanceof HTMLElement) || this.sessionsDrawer.hidden) {
        return;
      }
      void this.loadSessions();
    },

  /**
     * Scope key for DB-backed conversation rows (module + page).
     * @returns {string}
     */
    sessionScopeKey() {
      const ctx = resolveBackendContext();
      return `${ctx.module}:${ctx.pageId}`;
    },

  /**
     * Key used to decide whether a remembered conversation still applies at the current
     * backend location, given agentConversationScope (page | module | user).
     *
     * @param {string} [scope]
     * @returns {string}
     */
    rememberScopeKey(scope = 'page') {
      const ctx = resolveBackendContext();
      if (scope === 'user') {
        return 'user';
      }
      if (scope === 'module') {
        return `module:${ctx.module}`;
      }

      return `page:${ctx.module}:${ctx.pageId}`;
    },

  /**
     * Options for open(): pass sessionUuid only when the remembered entry matches the
     * current location under the scope mode stored with it.
     *
     * @returns {{sessionUuid?: string}}
     */
    lastSessionOpenOptions() {
      const stored = Persistent.get(STORAGE_LAST_SESSION_KEY);
      if (stored === undefined || stored === null || typeof stored !== 'object') {
        return {};
      }
      const uuid = String(stored.uuid ?? '').trim();
      const scopeKey = String(stored.scopeKey ?? '');
      const scope = stored.scope === 'module' || stored.scope === 'user' || stored.scope === 'page'
        ? stored.scope
        : 'page';
      if (scopeKey === '' || this.rememberScopeKey(scope) !== scopeKey) {
        return {};
      }
      // A new chat that has no message yet exists only in the browser: reopen it empty
      // instead of falling back to the latest saved conversation.
      if (uuid === '' && (stored.fresh === true || stored.fresh === 'true' || stored.fresh === 1 || stored.fresh === '1')) {
        return { fresh: true };
      }
      if (uuid === '') {
        return {};
      }

      return { sessionUuid: uuid };
    },

  /**
     * Persist the active conversation so the next open() resumes it (within scope).
     */
    rememberLastSession() {
      const uuid = this.activeSessionUuid();
      if (uuid === '') {
        return;
      }
      const scope = this.sessionListSettings.scope ?? 'page';
      void Persistent.set(STORAGE_LAST_SESSION_KEY, {
        uuid,
        scope,
        scopeKey: this.rememberScopeKey(scope),
      }).catch((error) => {
        console.warn('Agent last conversation could not be saved:', errorMessage(error));
      });
    },

  /**
     * Remember that the editor started a new chat that has no saved row yet.
     */
    rememberFreshSession() {
      const scope = this.sessionListSettings.scope ?? 'page';
      void Persistent.set(STORAGE_LAST_SESSION_KEY, {
        uuid: '',
        fresh: true,
        scope,
        scopeKey: this.rememberScopeKey(scope),
      }).catch((error) => {
        console.warn('Agent new conversation could not be remembered:', errorMessage(error));
      });
    },

  clearRememberedLastSession() {
      void Persistent.unset(STORAGE_LAST_SESSION_KEY).catch((error) => {
        console.warn('Agent last conversation preference could not be cleared:', errorMessage(error));
      });
    },

  /**
     * @param {string} uuid
     */
    clearRememberedLastSessionIf(uuid) {
      const stored = Persistent.get(STORAGE_LAST_SESSION_KEY);
      if (stored && typeof stored === 'object' && String(stored.uuid ?? '') === uuid) {
        this.clearRememberedLastSession();
      }
    },

  bindScopeRefresh() {
      const refresh = () => {
        void this.onBackendScopeChanged();
      };
      document.addEventListener('typo3:module-state-storage:update:web', refresh);
      document.addEventListener('typo3:module-state-storage:update-with-tree-identifier:web', refresh);
      document.addEventListener('typo3-module-load', refresh);
      document.addEventListener('typo3-module-loaded', refresh);
    },

  async onBackendScopeChanged() {
      if (!this.isOpen) {
        return;
      }
      const nextScope = this.sessionScopeKey();
      if (nextScope === this.loadedScopeKey) {
        return;
      }
      const scope = this.sessionListSettings.scope ?? 'page';
      const previousModule = String(this.loadedScopeKey).split(':')[0];
      const keepsConversation = this.session?.uuid
        && (scope === 'user' || (scope === 'module' && previousModule === resolveBackendContext().module));
      if (keepsConversation) {
        await this.reloadSessionForCurrentScope({ sessionUuid: this.session.uuid });
        this.contextNotice = this.describeContextChange();
        this.renderStream();
        return;
      }
      this.contextNotice = '';
      await this.reloadSessionForCurrentScope();
    },

  /**
     * @param {{sessionUuid?: string, fresh?: boolean}} [options]
     */
    async reloadSessionForCurrentScope(options = {}) {
      this.livePlan = null;
      this.isLoadingSession = true;
      this.panel?.setAttribute('aria-busy', 'true');
      this.renderLoadingSkeleton();
      await this.restoreSession(options);
      this.isLoadingSession = false;
      this.loadedScopeKey = this.sessionScopeKey();
      this.panel?.removeAttribute('aria-busy');
      this.renderContext();
      this.renderStream();
      // The active row in the Conversations rail follows the conversation that was just loaded.
      this.renderSessions?.();
    },

  /**
     * Loads a conversation: the given session, a fresh one, or the latest one of the
     * configured scope (agentConversationScope) for the current page / module.
     *
     * @param {{sessionUuid?: string, fresh?: boolean}} [options]
     */
    async restoreSession(options = {}) {
      const url = ajaxUrl('nst3af_agent_conversation');
      if (url === '') {
        return;
      }

      const backendContext = resolveBackendContext();
      const query = new URL(url, window.location.href);
      query.searchParams.set('pageId', String(backendContext.pageId));
      query.searchParams.set('module', backendContext.module);
      const requestedUuid = String(options.sessionUuid ?? '').trim();
      if (requestedUuid !== '') {
        query.searchParams.set('sessionUuid', requestedUuid);
      }
      if (options.fresh) {
        query.searchParams.set('fresh', '1');
      }

      try {
        const payload = await new AjaxRequest(query.toString()).get().then((r) => r.resolve());
        if (!payload?.ok) {
          return;
        }
        this.messages = Array.isArray(payload.messages) ? payload.messages : [];
        this.messages = this.messages.map((message) => ({
          ...message,
          meta: stripEphemeralWorkTraceMeta(message.meta ?? {}),
        }));
        this.context = payload.context ?? backendContext;
        this.starters = payload.starters ?? { executable: [], locked: [] };
        this.greeting = payload.greeting ?? null;
        this.session = payload.session ?? null;
        this.freshSession = options.fresh === true;
        this.sessionListSettings = { ...this.sessionListSettings, ...(payload.sessionList ?? {}) };
        this.providers = Array.isArray(payload.providers) ? payload.providers : [];
        this.hasUsableProvider = payload.hasUsableProvider !== false;
        this.continueAfterConfirm = payload.continueAfterConfirm !== false;
        this.credits = payload.credits ?? null;
        this.disclosureDismissed = payload.disclosureDismissed === true;
        this.loadedScopeKey = this.sessionScopeKey();

        // Stale remembered uuid: server returns session:null and does not fall back to
        // latest while a sessionUuid was requested — clear and retry without it.
        if (requestedUuid !== '' && !options.fresh && this.session === null) {
          this.clearRememberedLastSession();
          await this.restoreSession({ fresh: false });
          return;
        }
        if (options.fresh) {
          this.rememberFreshSession();
        } else {
          this.rememberLastSession();
        }
        warmToolIndexInBackground();
      } catch {
        this.context = backendContext;
        this.loadedScopeKey = this.sessionScopeKey();
      }
      this.renderHeaderControls();
      this.renderCredits();
    },

  async toggleSessions() {
      if (!(this.sessionsDrawer instanceof HTMLElement)) {
        return;
      }
      const opening = this.sessionsDrawer.hidden;
      this.setSessionsRailOpen(opening);
      if (!opening) {
        return;
      }
      this.sessionsFilter = this.sessionListSettings.scope === 'user' ? 'all' : (this.sessionListSettings.defaultFilter ?? 'current');
      if (this.sessionsSearch instanceof HTMLInputElement) {
        this.sessionsSearch.value = '';
      }
      await this.loadSessions();
    },

  /**
     * @param {boolean} [append]
     */
    async loadSessions(append = false) {
      const url = ajaxUrl('nst3af_agent_sessions');
      if (url === '') {
        return;
      }
      const backendContext = resolveBackendContext();
      const query = new URL(url, window.location.href);
      query.searchParams.set('filter', this.sessionsFilter);
      query.searchParams.set('pageId', String(backendContext.pageId));
      query.searchParams.set('module', backendContext.module);
      query.searchParams.set('limit', '20');
      query.searchParams.set('offset', String(append ? this.sessions.length : 0));
      try {
        const payload = await new AjaxRequest(query.toString()).get().then((r) => r.resolve());
        const rows = Array.isArray(payload?.sessions) ? payload.sessions : [];
        this.sessions = append ? [...this.sessions, ...rows] : rows;
        this.sessionsHasMore = payload?.hasMore === true;
      } catch (error) {
        this.sessions = append ? this.sessions : [];
        this.sessionsHasMore = false;
        console.warn('Agent sessions could not be loaded:', errorMessage(error));
      }
      this.renderSessions();
    },

  renderSessions() {
      if (!(this.sessionsList instanceof HTMLElement)) {
        return;
      }
      const scope = this.sessionListSettings.scope ?? 'page';
      if (this.sessionsFilterEl instanceof HTMLElement) {
        const currentLabel = scope === 'module'
          ? lang('agent.session.filterModule', 'This module')
          : lang('agent.session.filterPage', 'This page');
        this.sessionsFilterEl.hidden = scope === 'user';
        this.sessionsFilterEl.innerHTML = [['current', currentLabel], ['all', lang('agent.session.filterAll', 'All pages')]]
          .map(([value, label]) => `<button type="button" class="btn btn-default ${this.sessionsFilter === value ? 'active' : ''}" aria-pressed="${this.sessionsFilter === value}" data-nst3af-agent-sessions-filter-value="${value}">${escapeHtml(label)}</button>`)
          .join('');
      }

      const needle = this.sessionsSearch instanceof HTMLInputElement ? this.sessionsSearch.value.trim().toLowerCase() : '';
      const rows = this.sessions.filter((row) => needle === '' || String(row.title ?? '').toLowerCase().includes(needle));
      if (rows.length === 0) {
        this.sessionsList.innerHTML = `<li class="nst3af-agent-sessions__empty">${escapeHtml(lang('agent.session.empty', 'No conversations yet.'))}</li>`;
      } else {
        this.sessionsList.innerHTML = rows.map((row) => this.renderSessionItem(row)).join('');
      }

      const input = this.sessionsList.querySelector('[data-nst3af-agent-session-title]');
      if (input instanceof HTMLInputElement) {
        input.focus();
        input.select();
        input.addEventListener('keydown', (event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            void this.saveSessionTitle(input.dataset.nst3afAgentSessionTitle ?? '', input.value);
          } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.renamingUuid = '';
            this.renderSessions();
          }
        });
        input.addEventListener('blur', () => {
          if (this.renamingUuid !== '') {
            void this.saveSessionTitle(input.dataset.nst3afAgentSessionTitle ?? '', input.value);
          }
        });
      }

      if (this.sessionsFoot instanceof HTMLElement) {
        const more = this.sessionsHasMore
          ? `<button type="button" class="btn btn-link btn-sm" data-nst3af-agent-sessions-more>${escapeHtml(lang('agent.session.more', 'Show more'))}</button>`
          : '';
        this.sessionsFoot.innerHTML = `${more}<span>${escapeHtml(lang('agent.session.retention', 'Conversations without activity are deleted after %1$s days.', [String(this.sessionListSettings.retentionDays ?? 90)]))}</span>`;
      }
    },

  /**
     * @param {object} row
     * @returns {string}
     */
    renderSessionItem(row) {
      const uuid = escapeHtml(String(row.uuid ?? ''));
      const active = row.uuid === this.session?.uuid && !this.freshSession;
      const title = String(row.title ?? '') || lang('agent.session.untitled', 'Conversation');
      const where = [row.moduleLabel, row.pageId > 0 ? `${row.pageTitle ?? ''} [${row.pageId}]` : '']
        .filter((part) => String(part ?? '') !== '')
        .map((part) => escapeHtml(String(part)))
        .join(' · ');
      if (this.renamingUuid === row.uuid) {
        return `<li class="nst3af-agent-sessions__item is-editing">
          <input type="text" class="form-control form-control-sm" maxlength="255" value="${escapeHtml(title)}"
                 aria-label="${escapeHtml(lang('agent.session.rename', 'Rename'))}" data-nst3af-agent-session-title="${uuid}" />
        </li>`;
      }
      return `<li class="nst3af-agent-sessions__item${active ? ' is-active' : ''}">
        <button type="button" class="nst3af-agent-sessions__open" data-nst3af-agent-session-open="${uuid}"${active ? ' aria-current="true"' : ''}>
          <span class="nst3af-agent-sessions__title">${escapeHtml(title)}</span>
          <span class="nst3af-agent-sessions__meta">${where}${where !== '' ? ' · ' : ''}${escapeHtml(this.relativeTime(Number(row.lastActivity ?? 0)))}</span>
        </button>
        <span class="nst3af-agent-sessions__actions">
          <button type="button" class="btn btn-link btn-sm" data-nst3af-agent-session-rename="${uuid}"
                  title="${escapeHtml(lang('agent.session.rename', 'Rename'))}" aria-label="${escapeHtml(lang('agent.session.rename', 'Rename'))}">
            <typo3-backend-icon identifier="actions-rename" size="small"></typo3-backend-icon>
          </button>
          <button type="button" class="btn btn-link btn-sm" data-nst3af-agent-session-delete="${uuid}"
                  title="${escapeHtml(lang('agent.session.delete', 'Delete'))}" aria-label="${escapeHtml(lang('agent.session.delete', 'Delete'))}">
            <typo3-backend-icon identifier="actions-delete" size="small"></typo3-backend-icon>
          </button>
        </span>
      </li>`;
    },

  /**
     * @param {string} uuid
     */
    async openSession(uuid) {
      if (uuid === '' || this.isRunning) {
        return;
      }
      // Restore rail from an open info drawer first, then apply narrow-viewport collapse.
      this.closeInfoDrawer();
      this.closeRailAfterPickOnNarrowViewport();
      this.contextNotice = '';
      await this.reloadSessionForCurrentScope({ sessionUuid: uuid });
      this.input?.focus();
    },

  async startNewConversation() {
      if (this.isRunning) {
        return;
      }
      this.closeInfoDrawer();
      this.closeRailAfterPickOnNarrowViewport();
      this.contextNotice = '';
      await this.reloadSessionForCurrentScope({ fresh: true });
      this.announce(lang('agent.session.started', 'New conversation started.'));
      this.input?.focus();
    },

  /**
     * Below 750px the rail already behaves like an overlay picker (same breakpoint the
     * panel's own responsive width scale treats as "mobile takeover"), so picking a
     * conversation there collapses it afterward. On wider viewports the rail stays a
     * persistent list and is left alone. Not persisted: this is a transient narrow-viewport
     * affordance, not the editor expressing a new stored preference.
     */
    closeRailAfterPickOnNarrowViewport() {
      if (this.sessionsRailOpen && window.matchMedia('(max-width: 749px)').matches) {
        this.setSessionsRailOpen(false, { persist: false });
      }
    },

  /**
     * @param {string} uuid
     */
    async renameSession(uuid) {
      this.renamingUuid = uuid;
      this.renderSessions();
    },

  /**
     * @param {string} uuid
     * @param {string} title
     */
    async saveSessionTitle(uuid, title) {
      this.renamingUuid = '';
      const clean = title.replace(/\s+/g, ' ').trim();
      const row = this.sessions.find((entry) => entry.uuid === uuid);
      const url = ajaxUrl('nst3af_agent_session_rename');
      if (clean === '' || !row || clean === row.title || url === '') {
        this.renderSessions();
        return;
      }
      try {
        await new AjaxRequest(url).post({ sessionUuid: uuid, title: clean }).then((r) => r.resolve());
        row.title = clean;
        if (this.session?.uuid === uuid) {
          this.session.title = clean;
        }
      } catch (error) {
        console.warn('Agent session rename failed:', errorMessage(error));
      }
      this.renderSessions();
    },

  /**
     * Two clicks: the first arms the button, the second deletes.
     *
     * @param {string} uuid
     * @param {HTMLElement} button
     */
    async deleteSession(uuid, button) {
      if (button.dataset.armed !== '1') {
        button.dataset.armed = '1';
        button.classList.add('is-armed');
        button.textContent = lang('agent.session.deleteConfirm', 'Are you sure?');
        window.setTimeout(() => {
          if (button.isConnected) {
            button.dataset.armed = '';
            button.classList.remove('is-armed');
            button.innerHTML = '<typo3-backend-icon identifier="actions-delete" size="small"></typo3-backend-icon>';
          }
        }, 4000);
        return;
      }
      const url = ajaxUrl('nst3af_agent_session_delete');
      if (url === '') {
        return;
      }
      try {
        await new AjaxRequest(url).post({ sessionUuid: uuid }).then((r) => r.resolve());
      } catch (error) {
        console.warn('Agent session delete failed:', errorMessage(error));
        return;
      }
      this.sessions = this.sessions.filter((row) => row.uuid !== uuid);
      this.clearRememberedLastSessionIf(uuid);
      if (this.session?.uuid === uuid) {
        this.session = null;
        await this.reloadSessionForCurrentScope({ fresh: true });
      }
      this.renderSessions();
    },

  /**
     * Conversation the server records a card action in.
     *
     * @returns {string}
     */
    activeSessionUuid() {
      return this.session?.uuid && !this.freshSession ? String(this.session.uuid) : '';
    },
};
