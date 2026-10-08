/**
 * AgentController mixin: message stream rendering, plan panel, progress indicator, summarize/clarify.
 */

import { lang, hasTurnGuardWarning, errorText, escapeHtml, formatWorkDuration } from './format.js';
import { ajaxUrl } from './context.js';
import { resolveToolDisplayLabel, renderMessageBody, renderImagePreviews } from './render-helpers.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

export const streamMethods = {
  /**
     * Credits badge in the header; at zero the composer is locked with a clear message.
     */
    renderCredits() {
      const credits = this.credits;
      if (this.creditsBadge instanceof HTMLElement) {
        if (!credits) {
          this.creditsBadge.hidden = true;
        } else {
          this.creditsBadge.hidden = false;
          this.creditsBadge.className = `nst3af-agent-credits nst3af-agent-credits--${escapeHtml(String(credits.level ?? 'ok'))}`;
          this.creditsBadge.textContent = credits.empty
            ? lang('agent.credits.badgeEmpty', '0 credits')
            : lang('agent.credits.badge', '%1$s credits left', [String(credits.remaining ?? '')]);
          this.creditsBadge.title = credits.empty
            ? lang('agent.credits.empty', 'Your T3Planet credits are used up.')
            : (credits.level === 'critical' || credits.level === 'low'
              ? lang('agent.credits.low', 'Only %1$s credits left.', [String(credits.remaining ?? '')])
              : String(credits.label ?? ''));
        }
      }
      const empty = credits?.empty === true;
      if (this.input instanceof HTMLTextAreaElement) {
        this.input.disabled = empty;
        this.input.placeholder = empty
          ? lang('agent.credits.emptyInput', 'Your T3Planet credits are used up. Top up credits to continue.')
          : lang('agent.composer.placeholder', 'Ask a question or describe a change. Enter to send.');
      }
      this.root.querySelector('[data-nst3af-agent-send]')?.toggleAttribute('disabled', empty);
    },

  /**
     * Same rule as the server (AgentConversationSummarizer::canSummarize): a stored conversation
     * with at least four visible messages since the last summary.
     *
     * @returns {boolean}
     */
    canSummarize() {
      if (this.activeSessionUuid() === '') {
        return false;
      }
      let since = 0;
      this.messages.forEach((message) => {
        if (message.meta?.type === 'summary') {
          since = 0;
        } else if (message.meta?.hidden !== true) {
          since += 1;
        }
      });
      return since >= 4;
    },

  updateSummarizeButton() {
      if (this.summarizeButton instanceof HTMLButtonElement) {
        this.summarizeButton.hidden = !this.canSummarize();
        this.summarizeButton.disabled = this.isRunning || this.credits?.empty === true;
      }
    },

  async summarizeConversation() {
      const url = ajaxUrl('nst3af_agent_summarize');
      if (url === '' || this.isRunning || !this.canSummarize()) {
        return;
      }
      this.isRunning = true;
      this.updateSummarizeButton();
      this.showProgress(true, lang('agent.summary.working', 'Summarizing the conversation…'));
      try {
        const payload = await new AjaxRequest(url).post({ sessionUuid: this.activeSessionUuid() }).then((r) => r.resolve());
        if (!payload?.ok || !payload.message) {
          throw new Error(payload?.message ?? lang('agent.summary.failed', 'The conversation could not be summarized: %1$s', ['']));
        }
        this.messages.push(payload.message);
        if (payload.credits !== undefined) {
          this.credits = payload.credits;
          this.renderCredits();
        }
      } catch (error) {
        this.messages.push({ role: 'assistant', content: await errorText(error), meta: { type: 'error' } });
      } finally {
        this.isRunning = false;
        this.showProgress(false);
        this.renderStream();
      }
    },

  /**
     * @param {object} message
     * @returns {string}
     */
    renderSummaryMessage(message) {
      return `<div class="nst3af-agent-summary" role="note">
        <div class="nst3af-agent-summary__title">${escapeHtml(lang('agent.summary.title', 'Summary of the conversation so far'))}</div>
        <div class="nst3af-agent-summary__body">${renderMessageBody(String(message.content ?? ''))}</div>
        <p class="nst3af-agent-summary__note">${escapeHtml(lang('agent.summary.note', 'From here on the agent works with this summary instead of the older messages.'))}</p>
      </div>`;
    },

  /**
     * @param {object} message
     * @param {number} index
     * @returns {string}
     */
    renderClarificationMessage(message, index) {
      const options = Array.isArray(message.meta?.options) ? message.meta.options : [];
      const answered = this.messages.slice(index + 1).some((next) => next.role === 'user' && next.meta?.hidden !== true);
      const buttons = options.length
        ? `<div class="nst3af-agent-clarify__options">${options.map((option) => (
          `<button type="button" class="btn btn-default btn-sm" data-nst3af-agent-clarify-option="${escapeHtml(String(option))}"${answered ? ' disabled' : ''}>${escapeHtml(String(option))}</button>`
        )).join('')}</div>`
        : '';
      return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant nst3af-agent-clarify">
        <div class="nst3af-agent-msg__who">AI Agent</div>
        <div class="nst3af-agent-msg__body">${renderMessageBody(String(message.content ?? ''))}</div>
        ${buttons}
      </div>`;
    },

  /**
     * @param {string} option
     */
    async answerClarification(option) {
      if (!this.input || this.isRunning || option === '') {
        return;
      }
      this.input.value = option;
      await this.submitTurn();
    },

  /**
     * "Open page", "Edit", "View" after a change.
     *
     * @param {Array<{record: string, links: Array<{kind: string, label: string, href: string}>}>} groups
     * @returns {string}
     */
    renderResultLinks(groups) {
      if (!Array.isArray(groups) || groups.length === 0) {
        return '';
      }
      return `<div class="nst3af-agent-links">${groups.map((group) => `
        <div class="nst3af-agent-links__group">
          <span class="nst3af-agent-links__record">${escapeHtml(String(group.record ?? ''))}</span>
          ${(Array.isArray(group.links) ? group.links : []).map((link) => (
            `<a class="btn btn-default btn-sm" href="${escapeHtml(String(link.href ?? '#'))}" data-nst3af-agent-link="${escapeHtml(String(link.kind ?? 'module'))}"${link.kind === 'frontend' ? ' target="_blank" rel="noopener"' : ''}>${escapeHtml(String(link.label ?? ''))}</a>`
          )).join('')}
        </div>`).join('')}</div>`;
    },

  /**
     * Backend links open in the content area (the agent panel closes so the page is visible), frontend links in a new tab.
     *
     * @param {HTMLAnchorElement} link
     * @returns {boolean} true when handled
     */
    openResultLink(link) {
      if (link.dataset.nst3afAgentLink !== 'module') {
        return false;
      }
      try {
        const container = window.top?.TYPO3?.Backend?.ContentContainer;
        if (container && typeof container.setUrl === 'function') {
          container.setUrl(link.href);
          // The agent panel covers the content area: close it so the opened page is in front.
          // The conversation is saved and comes back when the panel is reopened.
          this.close();
          return true;
        }
      } catch {
        // Same-origin only; fall back to the default navigation.
      }
      return false;
    },

  /**
     * Send ↔ Stop + plan spinner. Called from the isRunning accessor (defined in agent.js —
     * Object.assign cannot copy getters/setters).
     */
    applyRunningChrome() {
      const running = this._isRunning === true;
      this.planPanel?.classList.toggle('nst3af-agent-plan--running', running);
      const send = this.root?.querySelector('[data-nst3af-agent-send]');
      const stop = this.root?.querySelector('[data-nst3af-agent-stop]');
      if (send instanceof HTMLElement) {
        send.hidden = running;
      }
      if (stop instanceof HTMLElement) {
        stop.hidden = !running;
      }
    },

  /**
     * The plan the agent is working through: the live one while a turn runs, otherwise the newest
     * plan saved with the conversation.
     *
     * @returns {Array<{title: string, status: string}>}
     */
    currentPlan() {
      if (Array.isArray(this.livePlan)) {
        return this.livePlan;
      }
      for (let i = this.messages.length - 1; i >= 0; i--) {
        const plan = this.messages[i]?.meta?.plan;
        if (Array.isArray(plan)) {
          return plan;
        }
      }
      return [];
    },

  /**
     * "Progress" checklist above the input: done steps checked, the current step marked, the rest open.
     */
    renderPlan() {
      if (!this.planPanel) {
        return;
      }
      const steps = this.currentPlan();
      if (steps.length === 0) {
        this.planPanel.hidden = true;
        this.planPanel.innerHTML = '';
        return;
      }
      const done = steps.filter((step) => step.status === 'completed').length;
      const open = this.planOpen ?? done < steps.length;
      const items = steps.map((step) => {
        const status = ['completed', 'in_progress', 'failed'].includes(step.status) ? step.status : 'pending';
        const mark = status === 'completed'
          ? '<typo3-backend-icon identifier="actions-check" size="small"></typo3-backend-icon>'
          : (status === 'failed' ? '<typo3-backend-icon identifier="actions-close" size="small"></typo3-backend-icon>' : '');
        const current = status === 'in_progress' ? ' aria-current="step"' : '';
        return `<li class="nst3af-agent-plan__step nst3af-agent-plan__step--${status}"${current}>`
          + `<span class="nst3af-agent-plan__mark" aria-hidden="true">${mark}</span>`
          + `<span class="nst3af-agent-plan__title">${escapeHtml(String(step.title ?? ''))}</span></li>`;
      }).join('');
      this.planPanel.hidden = false;
      this.planPanel.innerHTML = `<details class="nst3af-agent-plan__details"${open ? ' open' : ''}>`
        + `<summary class="nst3af-agent-plan__summary"><span>${escapeHtml(lang('agent.plan.title', 'Progress'))}</span>`
        + `<span class="nst3af-agent-plan__count">${done}/${steps.length}</span></summary>`
        + `<ol class="nst3af-agent-plan__list">${items}</ol></details>`;
    },

  renderStream() {
      if (!this.stream || this.isLoadingSession) {
        return;
      }
      this.renderPlan();

      this.stream.removeAttribute('aria-busy');
      const currentPageId = Number(this.context.pageId ?? 0);
      // A read step is shown as one status line when the model answered after it in the same turn.
      const summarizedLater = new Array(this.messages.length).fill(false);
      let replyFollows = false;
      for (let i = this.messages.length - 1; i >= 0; i--) {
        const entry = this.messages[i];
        if (entry.role === 'user') {
          replyFollows = false;
        } else if (entry.meta?.type === 'nl_reply') {
          replyFollows = true;
        }
        summarizedLater[i] = replyFollows;
      }
      const html = this.messages.map((message, index) => {
        if (message.role === 'user' && message.meta?.hidden === true) {
          return '';
        }
        if (message.role === 'user') {
          const written = message.meta?.context ?? null;
          const writtenPageId = Number(written?.pageId ?? 0);
          const caption = writtenPageId > 0 && writtenPageId !== currentPageId
            ? `<div class="nst3af-agent-msg__where">${escapeHtml(lang('agent.session.writtenOn', 'on %1$s', [`${written.pageTitle ?? ''} [${writtenPageId}]`]))}</div>`
            : '';
          return `<div class="nst3af-agent-msg nst3af-agent-msg--user">${caption}${renderMessageBody(String(message.content ?? ''))}</div>`;
        }
        const meta = message.meta ?? {};
        if (meta.type === 'inline_draft' && meta.draft) {
          return this.renderInlineDraftMessage(message, index);
        }
        if (meta.type === 'suggestions') {
          return this.renderSuggestionsMessage(message, index);
        }
        if (meta.type === 'readback_result') {
          return this.renderReadbackMessage(message);
        }
        if (meta.type === 'summary') {
          return this.renderSummaryMessage(message);
        }
        if (meta.type === 'clarification') {
          return this.renderClarificationMessage(message, index);
        }
        if (meta.type === 'tool_result') {
          const isStep = summarizedLater[index] && meta.success !== false && meta.fromDraftApply !== true
            && String(meta.severity ?? 'read') === 'read';
          if (isStep) {
            return `<details class="nst3af-agent-step"><summary><typo3-backend-icon identifier="actions-check" size="small" aria-hidden="true"></typo3-backend-icon> ${escapeHtml(resolveToolDisplayLabel(meta))}</summary>${this.renderToolResultMessage(message)}</details>`;
          }
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
        const previewHtml = renderImagePreviews(meta.previews);
        return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant"><div class="nst3af-agent-msg__who">AI Agent</div><div class="nst3af-agent-msg__body">${renderMessageBody(String(message.content ?? ''))}</div>${previewHtml}${extra}</div>`;
      }).join('');

      const pending = this.pendingExecutableDrafts();
      const executeAllBar = pending.length >= 2 && !this.isRunning
        ? `<div class="nst3af-agent-execute-all"><button type="button" class="btn btn-primary btn-sm" data-nst3af-agent-execute-all>${escapeHtml(lang('agent.draft.executeAll', 'Apply all (%1$s)', [String(pending.length)]))}</button></div>`
        : '';
      this.stream.innerHTML = this.renderHomeNotice() + html + executeAllBar + this.renderContextNotice();
      this.stream.classList.toggle('nst3af-agent-stream--empty', this.messages.length === 0 && !this.isRunning);
      this.updateSummarizeButton();
      if (this.messages.length === 0) {
        this.renderGreeting();
      } else if (!this.isRunning) {
        this.renderStarters(this.starters);
      }
      if (this.isRunning) {
        this.showProgress(true, this._progressLabel || '');
      }
      this.stream.scrollTop = this.messages.length === 0 ? 0 : this.stream.scrollHeight;
    },

  renderGreeting() {
      if (!this.stream || this.messages.length > 0) {
        return;
      }

      const existing = this.stream.querySelector('[data-nst3af-agent-greeting]');
      existing?.remove();

      const wrap = document.createElement('div');
      wrap.dataset.nst3afAgentGreeting = '1';
      wrap.className = 'nst3af-agent-empty';

      const mark = document.createElement('div');
      mark.className = 'nst3af-agent-empty__mark';
      mark.setAttribute('aria-hidden', 'true');
      mark.innerHTML = '<typo3-backend-icon identifier="ns-t3af-agent-wand" size="medium"></typo3-backend-icon>';
      wrap.appendChild(mark);

      const title = document.createElement('h2');
      title.className = 'nst3af-agent-empty__title';
      title.textContent = lang('agent.greeting.title', 'Start a conversation');
      wrap.appendChild(title);

      const lead = document.createElement('p');
      lead.className = 'nst3af-agent-empty__lead';
      lead.textContent = lang(
        'agent.greeting.lead',
        'Ask about this page or pick a starting point. The agent drafts changes, and nothing is saved until you approve.',
      );
      wrap.appendChild(lead);

      const executable = Array.isArray(this.starters.executable) ? this.starters.executable : [];
      const locked = Array.isArray(this.starters.locked) ? this.starters.locked : [];

      if (executable.length > 0) {
        const grid = document.createElement('div');
        grid.className = 'nst3af-agent-starters nst3af-agent-starters--grid';
        grid.setAttribute('role', 'list');
        executable.forEach((tool) => {
          const btn = this.createStarterButton(tool, false, { variant: 'card' });
          btn.setAttribute('role', 'listitem');
          grid.appendChild(btn);
        });
        wrap.appendChild(grid);
      }

      if (locked.length > 0) {
        const lockedWrap = document.createElement('div');
        lockedWrap.className = 'nst3af-agent-starters nst3af-agent-starters--locked';
        const label = document.createElement('div');
        label.className = 'nst3af-agent-starter-group-label';
        label.textContent = lang('agent.starters.locked', 'Needs another extension');
        lockedWrap.appendChild(label);
        locked.forEach((tool) => lockedWrap.appendChild(this.createStarterButton(tool, true, { variant: 'card' })));
        wrap.appendChild(lockedWrap);
      }

      const tip = document.createElement('p');
      tip.className = 'nst3af-agent-empty__tip';
      tip.innerHTML = lang(
        'agent.greeting.tip',
        'Type %1$s for tools or %2$s to reference a record.',
        ['<kbd>/</kbd>', '<kbd>@</kbd>'],
      );
      wrap.appendChild(tip);

      this.stream.appendChild(wrap);
      this.stream.scrollTop = 0;
    },

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
    },

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
          ${settingsHref === '#' ? '' : `<a class="btn btn-default btn-sm" href="${settingsHref}" data-nst3af-agent-settings-upsell${this.settingsAllowed === true ? '' : ' hidden'}>${settingsLabel}</a>`}
          <span class="visually-hidden">${toolName} ${ownerLabel}</span>
        </div>
      </div>`;
    },

  /**
     * Live-update "Working…" duration labels while a draft/tool apply is in flight.
     */
    startWorkTraceTimer() {
      this.clearWorkTraceTimer();
      this.workTraceTimer = window.setInterval(() => {
        const startedAt = this.findActiveApplyStartedAt();
        if (!startedAt || !this.stream) {
          return;
        }

        const elapsed = Date.now() - startedAt;
        const label = lang('agent.work.workingFor', 'Working… %1$s').replace('%1$s', formatWorkDuration(elapsed));
        this.stream.querySelectorAll('[data-nst3af-work-trace-active="1"] [data-nst3af-work-trace-timer]').forEach((node) => {
          node.textContent = label;
        });
      }, 1000);
    },

  clearWorkTraceTimer() {
      if (this.workTraceTimer) {
        window.clearInterval(this.workTraceTimer);
        this.workTraceTimer = 0;
      }
    },

  /**
     * @returns {number}
     */
    findActiveApplyStartedAt() {
      for (let index = this.messages.length - 1; index >= 0; index -= 1) {
        const draft = this.messages[index]?.meta?.draft;
        if (draft?.applying === true && draft.applyStartedAt) {
          return Number(draft.applyStartedAt);
        }
      }

      return 0;
    },

  /**
     * @param {boolean} running
     * @param {string} [label]
     */
    showProgress(running, label = '') {
      if (!this.stream) {
        return;
      }

      const existing = this.stream.querySelector('[data-nst3af-agent-progress]');
      existing?.remove();

      if (!running) {
        this._progressLabel = '';
        this.stopThinkingRotation();
        this.clearProgressClock();
        return;
      }

      const text = label !== '' ? label : lang('agent.live.running', 'Assistant is working…');
      this._progressLabel = text;
      const node = document.createElement('div');
      node.dataset.nst3afAgentProgress = '1';
      node.className = 'nst3af-agent-progress';
      node.setAttribute('role', 'status');
      node.setAttribute('aria-live', 'polite');
      node.innerHTML = '<span class="nst3af-agent-progress__spark" aria-hidden="true"></span>'
        + '<span class="nst3af-agent-progress__label" data-nst3af-agent-progress-label>' + escapeHtml(text) + '</span>'
        + '<span class="nst3af-agent-progress__time" data-nst3af-agent-progress-time></span>';
      this.stream.appendChild(node);
      this.stream.scrollTop = this.stream.scrollHeight;
      this.announce(text);
      this.startProgressClock();
    },

  /**
     * @param {string} label
     */
    updateProgressLabel(label) {
      this._progressLabel = label;
      if (!this.stream) {
        return;
      }
      let node = this.stream.querySelector('[data-nst3af-agent-progress]');
      if (!(node instanceof HTMLElement)) {
        this.showProgress(true, label);
        return;
      }
      const labelNode = node.querySelector('[data-nst3af-agent-progress-label]');
      if (labelNode instanceof HTMLElement) {
        labelNode.textContent = label;
      }
      this.announce(label);
    },

  /**
     * A handful of short, varied phrases for the stretches where we have no specific tool name yet
     * (the model composing its next step, or the toolbox looking up which tools apply). Cycling a
     * few of these beats freezing on a single "Thinking…" for a long turn, while staying out of the
     * way the moment a real, more useful tool label is available.
     *
     * @returns {string[]}
     */
    thinkingWords() {
      if (!this._thinkingWordsCache) {
        this._thinkingWordsCache = [
          lang('agent.live.thinking', 'Thinking…'),
          lang('agent.live.thinking2', 'Reasoning…'),
          lang('agent.live.thinking3', 'Working it out…'),
          lang('agent.live.thinking4', 'Piecing it together…'),
          lang('agent.live.thinking5', 'One moment…'),
        ];
      }

      return this._thinkingWordsCache;
    },

  /**
     * Starts (or continues) rotating the filler words above every ~2.6s. Safe to call repeatedly:
     * a run already in progress is left alone so the word doesn't restart on every SSE tick.
     */
    startThinkingRotation() {
      const words = this.thinkingWords();
      if (this.progressRotateIndex < 0) {
        this.progressRotateIndex = 0;
        this.updateProgressLabel(words[0]);
      }
      if (this.progressRotateTimer) {
        return;
      }
      this.progressRotateTimer = window.setInterval(() => {
        this.progressRotateIndex = (this.progressRotateIndex + 1) % words.length;
        this.updateProgressLabel(words[this.progressRotateIndex]);
      }, 2600);
    },

  stopThinkingRotation() {
      if (this.progressRotateTimer) {
        window.clearInterval(this.progressRotateTimer);
        this.progressRotateTimer = 0;
      }
      this.progressRotateIndex = -1;
    },

  /**
     * Elapsed-time readout next to the progress label ("(4s)"), shown once a step has run a few
     * seconds so quick steps stay quiet and only genuinely slow ones get the extra reassurance.
     */
    startProgressClock() {
      this.progressStartedAt = Date.now();
      this.clearProgressClock();
      this.progressClockTimer = window.setInterval(() => this.tickProgressClock(), 1000);
    },

  clearProgressClock() {
      if (this.progressClockTimer) {
        window.clearInterval(this.progressClockTimer);
        this.progressClockTimer = 0;
      }
      this.progressStartedAt = 0;
    },

  tickProgressClock() {
      if (!this.stream || !this.progressStartedAt) {
        return;
      }
      const node = this.stream.querySelector('[data-nst3af-agent-progress-time]');
      if (!(node instanceof HTMLElement)) {
        return;
      }
      const elapsed = Date.now() - this.progressStartedAt;
      node.textContent = elapsed >= 3000 ? `(${formatWorkDuration(elapsed)})` : '';
    },
};
