/**
 * AgentController mixin: panel open/close, drawers, info/context notices, focus trap.
 */

import { hotkeyLabel, readHotkeyPref, STORAGE_OPEN_KEY } from './hotkeys.js';
import { lang, errorMessage, escapeHtml } from './format.js';
import { ajaxUrl } from './context.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

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
          this.closeDrawers();
        }
        if (target.closest('[data-nst3af-agent-new]')) {
          event.preventDefault();
          void this.startNewConversation();
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
          return;
        }

        if (event.key === 'Escape') {
          event.preventDefault();
          if (this.closeDrawers()) {
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
      this.contextNotice = '';
      this.closeDrawers();
      this.livePlan = null;
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
    },

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
     * @returns {boolean} true when a drawer was open
     */
    closeDrawers() {
      let wasOpen = false;
      [[this.sessionsDrawer, this.sessionsToggle], [this.infoDrawer, this.infoToggle]].forEach(([drawer, toggle]) => {
        if (drawer instanceof HTMLElement && !drawer.hidden) {
          drawer.hidden = true;
          wasOpen = true;
        }
        toggle?.setAttribute('aria-expanded', 'false');
      });
      this.renamingUuid = '';

      return wasOpen;
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
      const opening = this.infoDrawer.hidden;
      this.closeDrawers();
      if (!opening) {
        return;
      }
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
      const icons = { page: '📄', module: '🧩', language: '🌐', record: '✏️', folder: '📁', workspace: '🗂', brand: '🏷' };
      this.contextEl.innerHTML = dimChip + chips.map((chip) => {
        const key = String(chip.key ?? '');
        const icon = icons[key] ? `<span aria-hidden="true">${icons[key]}</span> ` : '';
        const hint = escapeHtml(String(chip.hint ?? ''));
        return `<span class="nst3af-agent-ctxchip nst3af-agent-ctxchip--${escapeHtml(key)}" title="${hint}">${icon}<span class="visually-hidden">${escapeHtml(String(chip.label ?? ''))}: </span>${escapeHtml(String(chip.value ?? ''))}</span>`;
      }).join('');
    },

  async dismissDisclosure() {
      this.disclosureDismissed = true;
      this.disclosure.hidden = true;
      await this.saveDisclosure();
    },

  /**
     * Stores the "AI disclosure dismissed" flag. Conversations are stored by the server only
     * (turns and card actions); the window never sends its messages.
     */
    async saveDisclosure() {
      const url = ajaxUrl('nst3af_agent_conversation_save');
      if (url === '') {
        return;
      }
      try {
        await new AjaxRequest(url).post({ disclosureDismissed: this.disclosureDismissed }).then((response) => response.resolve());
      } catch (error) {
        console.warn('Agent disclosure save failed:', errorMessage(error));
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
