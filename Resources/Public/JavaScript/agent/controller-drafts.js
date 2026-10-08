/**
 * AgentController mixin: draft and suggestion apply/discard, tool-confirmation, readback.
 */

import { lang, hasTurnGuardWarning, errorText, messageContent, escapeHtml, humanizeKey } from './format.js';
import { ajaxUrl } from './context.js';
import { isSuggestionFieldSafe, renderImagePreviews, renderMediaPreview, resolveToolDisplayLabel, renderToolTrace, renderWorkTraceHtml, renderMessageBody } from './render-helpers.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

export const draftMethods = {
  /**
     * Where a confirmed change goes: live website or the current draft workspace.
     *
     * @param {boolean} destructive
     * @returns {string}
     */
    renderDraftTarget(destructive) {
      const workspace = this.context.details?.workspace ?? null;
      const live = workspace === null || workspace.live !== false;
      const text = live
        ? lang('agent.draft.targetLive', 'On confirm this goes live on the website.')
        : lang('agent.draft.targetWorkspace', 'On confirm this is saved as a draft in the workspace „%1$s“.', [String(workspace.title ?? '')]);
      const warn = destructive ? ` ${lang('agent.draft.targetDestructive', 'This cannot be undone.')}` : '';
      return `<p class="nst3af-agent-draft__target nst3af-agent-draft__target--${live ? 'live' : 'draft'}">${escapeHtml(text + warn)}</p>`;
    },

  /**
     * Remembers that the turn continues after this confirm / decline (cards of a natural-language turn only).
     *
     * @param {object} message
     * @param {string} outcome applied|declined
     * @param {string} result
     * @param {string} [label]
     */
    queueContinuation(message, outcome, result, label = '') {
      const meta = message?.meta ?? {};
      if (!this.continueAfterConfirm || meta.fromRunner !== true) {
        return;
      }
      const next = {
        outcome,
        label: label || resolveToolDisplayLabel(meta.draft ?? meta),
        result: String(result ?? '').slice(0, 600),
      };
      const previous = this.pendingContinuation;
      // "Execute all" confirms several cards; the model hears about all of them in one continuation.
      if (previous !== null && previous.outcome === outcome) {
        next.label = `${previous.label}, ${next.label}`.slice(0, 120);
        next.result = [previous.result, next.result].filter((part) => part !== '').join(' | ').slice(0, 600);
      }
      this.pendingContinuation = next;
    },

  async flushContinuation() {
      if (this.pendingContinuation === null || this.executingAll || this.isRunning) {
        return;
      }
      const continuation = this.pendingContinuation;
      this.pendingContinuation = null;
      await this.submitTurn('', {}, '', { continuation });
    },

  /**
     * Indexes of cards that still wait for the editor and can run without a second click.
     *
     * @returns {number[]}
     */
    pendingExecutableDrafts() {
      const indexes = [];
      const seenCreates = new Set();
      this.messages.forEach((message, index) => {
        const draft = message.meta?.draft;
        if (message.meta?.type === 'inline_draft' && draft && !draft.applied && !draft.discarded && !draft.applying
          && String(draft.severity ?? 'write') !== 'destructive') {
          if (draft.action === 'create') {
            const fingerprint = createDraftFingerprint(draft);
            if (seenCreates.has(fingerprint)) {
              return;
            }
            seenCreates.add(fingerprint);
          }
          if (keptFieldsLackProposedValue(draft)) {
            return;
          }
          indexes.push(index);
        }
      });
      return indexes;
    },

  /**
     * "Execute all": runs every pending non-destructive card in order, then continues once.
     */
    async executeAll() {
      if (this.isRunning) {
        return;
      }
      this.executingAll = true;
      try {
        for (const index of this.pendingExecutableDrafts()) {
          const draft = this.messages[index]?.meta?.draft;
          if (!draft) {
            continue;
          }
          const button = document.createElement('button');
          button.dataset.messageIndex = String(index);
          button.dataset.draftId = String(draft.draftId ?? '');
          await this.applyDraft(button, 'all');
        }
      } finally {
        this.executingAll = false;
      }
      await this.flushContinuation();
    },

  /**
     * @param {object} message
     * @returns {string}
     */
    renderToolResultMessage(message) {
      const meta = message.meta ?? {};
      const workDurationMs = Number(meta.workDurationMs ?? 0);
      const showWorkHeader = meta.fromDraftApply === true || workDurationMs > 0;

      const workHeaderHtml = showWorkHeader
        ? renderWorkTraceHtml({
          working: false,
          durationMs: workDurationMs,
          open: false,
        })
        : '';

      const success = meta.success !== false;
      const displayLabel = resolveToolDisplayLabel(meta);
      const toolLabel = escapeHtml(displayLabel);
      const technicalTool = String(meta.tool ?? '').trim();
      const autoRan = meta.autoRan === true;
      const facts = Array.isArray(meta.facts) ? meta.facts : [];
      const hasEditorContent = String(message.content ?? '').trim() !== '';
      const factsHtml = !hasEditorContent && facts.length
        ? `<dl class="nst3af-agent-facts">${facts.map((fact) => (
          `<div class="nst3af-agent-facts__row"><dt>${escapeHtml(String(fact.label ?? ''))}</dt><dd>${escapeHtml(String(fact.value ?? ''))}</dd></div>`
        )).join('')}</dl>`
        : '';

      // Technical output (tool name, trace, raw data) stays behind "Technical details".
      let detailsJson = '';
      if (meta.details !== undefined && meta.details !== null) {
        try {
          detailsJson = JSON.stringify(meta.details, null, 2);
        } catch {
          detailsJson = String(meta.details);
        }
      }
      const traceInner = renderToolTrace(meta.trace);
      let detailsHtml = '';
      if (traceInner !== '' || (detailsJson !== '' && detailsJson !== 'null') || technicalTool !== '') {
        detailsHtml = `<details class="nst3af-agent-details"><summary>${escapeHtml(lang('agent.result.technical', 'Technical details'))}</summary>
          ${technicalTool !== '' ? `<p class="nst3af-agent-details__tool"><code>${escapeHtml(technicalTool)}</code></p>` : ''}
          ${traceInner}
          ${detailsJson !== '' && detailsJson !== 'null' ? `<pre><code>${escapeHtml(detailsJson)}</code></pre>` : ''}
        </details>`;
      }
      const traceHtml = this.renderResultLinks(meta.links);

      let extra = '';
      if (hasTurnGuardWarning(meta)) {
        extra += `<div class="nst3af-agent-msg__warn" role="status">${escapeHtml(String(meta.turnGuardWarning))}</div>`;
      }

      const autoHtml = autoRan
        ? `<span class="nst3af-agent-tcall__auto">${escapeHtml(lang('agent.toolCall.autoRan', 'completed'))}</span>`
        : '';

      const statusClass = success ? '' : ' nst3af-agent-msg--error';
      const openAttr = success ? '' : ' open';
      return workHeaderHtml + `<div class="nst3af-agent-msg nst3af-agent-msg--assistant${statusClass}">
        <div class="nst3af-agent-msg__who">AI Agent</div>
        <details class="nst3af-agent-tcall"${openAttr}>
          <summary class="nst3af-agent-tcall__head">
            <span class="nst3af-agent-sev-dot nst3af-agent-sev-dot--${escapeHtml(String(meta.severity ?? 'read'))}" aria-hidden="true"></span>
            <strong>${toolLabel}</strong>
            ${autoHtml}
          </summary>
          <div class="nst3af-agent-tcall__body">
            <div class="nst3af-agent-msg__body">${renderMessageBody(String(message.content ?? ''))}</div>
            ${success ? renderMediaPreview(meta.details, meta.previews) : ''}
            ${factsHtml}
            ${traceHtml}
            ${extra}
            ${detailsHtml}
          </div>
        </details>
      </div>`;
    },

  /**
     * @param {object} message
     * @param {number} messageIndex
     * @returns {string}
     */
    renderSuggestionsMessage(message, messageIndex) {
      const meta = message.meta ?? {};
      if (meta.discarded === true) {
        return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant"><div class="nst3af-agent-msg__who">AI Agent</div><div class="nst3af-agent-msg__body">${escapeHtml(lang('agent.draft.discarded', 'Draft discarded. Nothing was written.'))}</div></div>`;
      }
      if (meta.applied === true) {
        const appliedLabel = lang('agent.suggestions.applied', 'Applied %1$s suggestion field(s).')
          .replace('%1$s', String(meta.appliedCount ?? 0));
        return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant"><div class="nst3af-agent-msg__who">AI Agent</div><div class="nst3af-agent-msg__body">${escapeHtml(appliedLabel)}</div></div>`;
      }

      const suggestions = meta.suggestions && typeof meta.suggestions === 'object' ? meta.suggestions : {};
      const fields = Array.isArray(suggestions.fields) ? suggestions.fields : [];
      const variants = Array.isArray(suggestions.variants) ? suggestions.variants : [];
      const draftId = String(meta.draftId ?? '');
      const toolLabel = resolveToolDisplayLabel({
        editorLabel: meta.editorLabel,
        tool: meta.tool,
      });
      const callCount = Math.max(1, Number(meta.callCount ?? suggestions.callCount ?? 1));
      const creditHint = lang('agent.suggestions.creditHint', 'This preview used %1$s AI call(s). Regenerating will use credits again.')
        .replace('%1$s', String(callCount));

      if (!meta.selections || typeof meta.selections !== 'object') {
        meta.selections = {};
        fields.forEach((field) => {
          const key = String(field.key ?? '');
          if (key !== '') {
            meta.selections[key] = 0;
          }
        });
      }
      if (!meta.edits || typeof meta.edits !== 'object') {
        meta.edits = {};
      }

      const safeFieldCount = fields.filter((field) => {
        const key = String(field.key ?? '');
        const table = String(suggestions.target?.table ?? '');
        return isSuggestionFieldSafe(table, key);
      }).length;

      const fieldRows = fields.map((field) => {
        const key = String(field.key ?? '');
        const label = String(field.label ?? key);
        const current = String(field.current ?? '');
        const selectedIndex = Number(meta.selections[key] ?? 0);
        const editValue = Object.prototype.hasOwnProperty.call(meta.edits, key)
          ? String(meta.edits[key] ?? '')
          : null;
        const variantOptions = variants.map((variant, index) => {
          const values = variant?.values && typeof variant.values === 'object' ? variant.values : {};
          const text = String(values[key] ?? '');
          const variantLabel = String(variant.label ?? `Variant ${index + 1}`);
          const checked = selectedIndex === index && editValue === null ? ' checked' : '';
          return `<label class="nst3af-agent-suggestions__option">
            <input type="radio" name="nst3af-sug-${messageIndex}-${escapeHtml(key)}" value="${index}" data-nst3af-agent-suggestions-select="1" data-message-index="${messageIndex}" data-field-key="${escapeHtml(key)}" data-variant-index="${index}"${checked}>
            <span class="nst3af-agent-suggestions__option-label">${escapeHtml(variantLabel)}</span>
            <span class="nst3af-agent-suggestions__option-text">${escapeHtml(text)}</span>
          </label>`;
        }).join('');

        const editChecked = editValue !== null ? ' checked' : '';
        const editBox = `<label class="nst3af-agent-suggestions__option nst3af-agent-suggestions__option--edit">
          <input type="radio" name="nst3af-sug-${messageIndex}-${escapeHtml(key)}" value="edit" data-nst3af-agent-suggestions-edit-toggle="1" data-message-index="${messageIndex}" data-field-key="${escapeHtml(key)}"${editChecked}>
          <span class="nst3af-agent-suggestions__option-label">${escapeHtml(lang('agent.suggestions.custom', 'Custom'))}</span>
          <textarea class="form-control form-control-sm" rows="2" data-nst3af-agent-suggestions-edit="1" data-message-index="${messageIndex}" data-field-key="${escapeHtml(key)}" placeholder="${escapeHtml(lang('agent.suggestions.editPlaceholder', 'Edit before applying…'))}">${escapeHtml(editValue ?? '')}</textarea>
        </label>`;

        return `<div class="nst3af-agent-suggestions__field" data-field-key="${escapeHtml(key)}">
          <div class="nst3af-agent-suggestions__field-head">
            <strong>${escapeHtml(label)}</strong>
            <span class="nst3af-agent-suggestions__current">${escapeHtml(lang('agent.suggestions.current', 'Current'))}: ${escapeHtml(current || '—')}</span>
          </div>
          <div class="nst3af-agent-suggestions__options">${variantOptions}${editBox}</div>
        </div>`;
      }).join('');

      const applyingClass = meta.applying === true ? ' nst3af-agent-suggestions--running' : '';
      const safeBtn = safeFieldCount > 0
        ? `<button type="button" class="btn btn-default btn-sm" data-nst3af-agent-suggestions-apply-safe="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(draftId)}">${escapeHtml(lang('agent.draft.applySafe', 'Apply safe fields'))}</button>`
        : '';

      return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant">
        <div class="nst3af-agent-msg__who">AI Agent</div>
        <div class="nst3af-agent-suggestions${applyingClass}" data-nst3af-agent-suggestions="1" data-draft-id="${escapeHtml(draftId)}" data-message-index="${messageIndex}">
          <div class="nst3af-agent-suggestions__header">
            <div class="nst3af-agent-suggestions__title">${escapeHtml(lang('agent.suggestions.title', 'Suggestions'))}: ${escapeHtml(toolLabel)}</div>
          </div>
          <p class="nst3af-agent-suggestions__lead">${escapeHtml(String(message.content ?? ''))}</p>
          ${renderImagePreviews(meta.previews)}
          <p class="nst3af-agent-suggestions__credits" role="note">${escapeHtml(creditHint)}</p>
          <div class="nst3af-agent-suggestions__fields">${fieldRows}</div>
          <div class="nst3af-agent-suggestions__actions">
            <button type="button" class="btn btn-primary btn-sm" data-nst3af-agent-suggestions-apply="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(draftId)}">${escapeHtml(lang('agent.suggestions.apply', 'Apply selected'))}</button>
            ${safeBtn}
            <button type="button" class="btn btn-default btn-sm" data-nst3af-agent-suggestions-discard="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(draftId)}">${escapeHtml(lang('agent.draft.discard', 'Discard'))}</button>
          </div>
        </div>
      </div>`;
    },

  /**
     * A specific card title ("Rename QA Mounted") instead of the generic tool name
     * ("Change a record") when the card changes one existing record.
     *
     * @param {object} draft
     * @returns {string}
     */
    draftCardTitle(draft) {
      const fallback = resolveToolDisplayLabel(draft);
      const fields = Array.isArray(draft.fields) ? draft.fields : [];
      if (draft.kind === 'tool_confirmation' || fields.length === 0) {
        return fallback;
      }
      const first = fields[0];
      const sameRecord = fields.every((f) => f.table === first.table && Number(f.uid ?? 0) === Number(first.uid ?? 0));
      const recordLabel = String(first.recordLabel ?? '').trim();
      const renameField = fields.length === 1 && String(first.field ?? '') === '_rename';
      if (!sameRecord || recordLabel === '' || (Number(first.uid ?? 0) <= 0 && !renameField)) {
        return fallback;
      }
      const renameOnly = fields.length === 1 && ['title', 'header', 'name', '_rename'].includes(String(first.field ?? ''));
      return renameOnly
        ? lang('agent.draft.titleRename', 'Rename %1$s', [recordLabel])
        : lang('agent.draft.titleChange', 'Change %1$s', [recordLabel]);
    },

  /**
     * Reload what the editor is looking at (and the page tree) after the agent changed or restored
     * something, so the page behind the panel shows the new state.
     */
    refreshBackendContent() {
      try {
        const top = window.top;
        top?.TYPO3?.Backend?.ContentContainer?.refresh?.();
        top?.document?.dispatchEvent(new CustomEvent('typo3:pagetree:refresh'));
      } catch {
        // Cross-origin or no backend shell: nothing to refresh.
      }
    },

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
        // The "Changes applied" card right below says the same.
        const next = this.messages[messageIndex + 1];
        if (next?.meta?.type === 'readback_result') {
          return '';
        }
        const appliedLabel = lang('agent.draft.applied', 'Applied %1$s of %2$s fields.')
          .replace('%1$s', String(keptCount))
          .replace('%2$s', String(fields.length));
        return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant"><div class="nst3af-agent-msg__who">AI Agent</div><div class="nst3af-agent-msg__body">${escapeHtml(appliedLabel)}</div></div>`;
      }

      if (draft.applying === true) {
        const toolLabel = resolveToolDisplayLabel(draft);
        const severity = String(draft.severity ?? 'write');

        return renderWorkTraceHtml({
          working: true,
          toolName: toolLabel,
          summary: lang('agent.draft.proposed', 'Review this change before anything is written.'),
          open: true,
        }) + `<span class="visually-hidden">${escapeHtml(severity)}</span>`;
      }

      const rows = fields.map((field) => {
        const kept = field.kept !== false;
        const dropClass = kept ? '' : ' nst3af-agent-draft__fld--dropped';
        const recordLabel = String(field.recordLabel ?? '') || `${field.table ?? ''}:${Number(field.uid ?? 0)}`;
        const label = `${escapeHtml(recordLabel)} · <strong>${escapeHtml(String(field.fieldLabel ?? '') || String(field.field ?? ''))}</strong>`;
        const keepLabel = kept
          ? '<typo3-backend-icon identifier="actions-check" size="small"></typo3-backend-icon>'
          : '<typo3-backend-icon identifier="actions-close" size="small"></typo3-backend-icon>';
        const keepTitle = kept ? lang('agent.draft.drop', 'Leave out') : lang('agent.draft.keep', 'Include');
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
        : lang('agent.draft.execute', 'Apply');
      // Every change was left out: Apply would only fail, so it is disabled with a hint.
      const nothingKept = fields.length > 0 && keptCount === 0;
      const blankProposed = keptFieldsLackProposedValue(draft);
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
            <span class="nst3af-agent-draft__title">${escapeHtml(this.draftCardTitle(draft))}</span>
            <span class="nst3af-agent-draft__badge">${escapeHtml(lang('agent.draft.previewBadge', 'Preview'))}</span>
          </div>
          <p class="nst3af-agent-draft__lead">${renderMessageBody(String(message.content ?? ''))}</p>
          ${renderImagePreviews(message.meta?.previews)}
          <div class="nst3af-agent-draft__cols" aria-hidden="true"><span></span><span>${escapeHtml(lang('agent.draft.colCurrent', 'Now'))}</span><span>${escapeHtml(lang('agent.draft.colProposed', 'New'))}</span><span></span></div>
          <div class="nst3af-agent-draft__fields">${rows}</div>
          ${this.renderDraftTarget(isDestructive)}
          ${nothingKept ? `<p class="nst3af-agent-draft__hint" role="status">${escapeHtml(lang('agent.draft.nothingKept', 'Nothing is selected. Include at least one change or cancel.'))}</p>` : ''}
          <div class="nst3af-agent-draft__actions">
            <button type="button" class="btn btn-primary btn-sm" data-nst3af-agent-draft-apply="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(String(draft.draftId ?? ''))}"${nothingKept || blankProposed ? ' disabled' : ''}>${escapeHtml(applyLabel)}</button>
            ${safeApplyBtn}
            <button type="button" class="btn btn-default btn-sm" data-nst3af-agent-draft-discard="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(String(draft.draftId ?? ''))}">${escapeHtml(lang('agent.draft.decline', 'Cancel'))}</button>
          </div>
        </div>
      </div>`;
    },

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
      const displayLabel = resolveToolDisplayLabel(draft);
      const summary = String(draft.summary ?? message.content ?? '');
      const args = Array.isArray(draft.arguments) ? draft.arguments : [];

      const argRows = args.map((entry) => {
        const key = escapeHtml(String(entry.label ?? '') || humanizeKey(String(entry.key ?? '')));
        const value = escapeHtml(String(entry.value ?? ''));
        return `<div class="nst3af-agent-tool-confirm__arg"><dt>${key}</dt><dd>${value}</dd></div>`;
      }).join('');

      const argsBlock = argRows !== ''
        ? `<div class="nst3af-agent-tool-confirm__args"><div class="nst3af-agent-tool-confirm__args-title">${escapeHtml(lang('agent.draft.toolArguments', 'Parameters'))}</div>${argRows}</div>`
        : '';

      if (discarded) {
        return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant"><div class="nst3af-agent-msg__who">AI Agent</div><div class="nst3af-agent-msg__body">${escapeHtml(lang('agent.draft.discarded', 'Draft discarded. Nothing was written.'))}</div></div>`;
      }

      if (applied) {
        return '';
      }

      if (draft.applying === true) {
        return renderWorkTraceHtml({
          working: true,
          toolName: displayLabel,
          summary,
          args,
          open: true,
        });
      }

      const applyLabel = isDestructive
        ? (armed ? lang('agent.draft.confirmSecond', 'Apply (2 of 2)') : lang('agent.draft.confirmFirst', 'Confirm (1 of 2)'))
        : lang('agent.draft.execute', 'Apply');

      const severityClass = isDestructive ? ' nst3af-agent-draft--destructive' : ' nst3af-agent-draft--write';
      const armedClass = armed ? ' nst3af-agent-draft--armed' : '';

      return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant">
        <div class="nst3af-agent-msg__who">AI Agent</div>
        <div class="nst3af-agent-draft nst3af-agent-tool-confirm${severityClass}${armedClass}" data-nst3af-agent-draft="1" data-draft-id="${escapeHtml(String(draft.draftId ?? ''))}" data-message-index="${messageIndex}" data-severity="${escapeHtml(severity)}">
          <div class="nst3af-agent-draft__header">
            <span class="nst3af-agent-sev-dot nst3af-agent-sev-dot--${escapeHtml(severity)}" aria-hidden="true"></span>
            <span class="nst3af-agent-draft__title">${escapeHtml(displayLabel)}</span>
            <span class="nst3af-agent-draft__badge">${escapeHtml(lang('agent.draft.previewBadge', 'Preview'))}</span>
          </div>
          <p class="nst3af-agent-tool-confirm__summary">${escapeHtml(summary)}</p>
          ${renderImagePreviews(message.meta?.previews)}
          ${argsBlock}
          ${this.renderDraftTarget(isDestructive)}
          <div class="nst3af-agent-draft__actions">
            <button type="button" class="btn btn-primary btn-sm" data-nst3af-agent-draft-apply="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(String(draft.draftId ?? ''))}">${escapeHtml(applyLabel)}</button>
            <button type="button" class="btn btn-default btn-sm" data-nst3af-agent-draft-discard="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(String(draft.draftId ?? ''))}">${escapeHtml(lang('agent.draft.decline', 'Cancel'))}</button>
          </div>
        </div>
      </div>`;
    },

  /**
     * @param {object} message
     * @returns {string}
     */
    renderReadbackMessage(message) {
      const meta = message.meta ?? {};
      const readback = Array.isArray(meta.readback) ? meta.readback : [];
      const rows = readback.map((entry) => {
        const values = entry.values ?? {};
        const fieldLabels = entry.fieldLabels ?? {};
        const cells = Object.entries(values).map(([key, value]) => (
          `<tr><td>${escapeHtml(String(fieldLabels[key] ?? key))}</td><td>${escapeHtml(String(value ?? ''))}</td></tr>`
        )).join('');
        const recordLabel = String(entry.recordLabel ?? '') || `${entry.table ?? ''}:${Number(entry.uid ?? 0)}`;
        return `<div class="nst3af-agent-readback__record"><strong>${escapeHtml(recordLabel)}</strong><table>${cells}</table></div>`;
      }).join('');

      const undone = meta.undone === true;
      const undoBtn = undone
        ? `<span class="nst3af-agent-applied__undone">${escapeHtml(lang('agent.draft.undoneLabel', 'Undone'))}</span>`
        : (meta.changeId && meta.undoable !== false
          ? `<button type="button" class="btn btn-default btn-sm" data-nst3af-agent-undo="1" data-change-id="${escapeHtml(String(meta.changeId))}">${escapeHtml(lang('agent.draft.undo', 'Undo'))}</button>`
          : '');

      const handoffHtml = meta.schedulerHandoff
        ? this.renderHandoffCard(meta.schedulerHandoff)
        : '';

      return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant">
        <div class="nst3af-agent-msg__who">AI Agent</div>
        <div class="nst3af-agent-applied">
          <details class="nst3af-agent-applied__details">
            <summary class="nst3af-agent-applied__title"><typo3-backend-icon identifier="actions-check" size="small" aria-hidden="true"></typo3-backend-icon> ${escapeHtml(undone ? lang('agent.draft.undoneLabel', 'Undone') : lang('agent.applied.title', 'Changes applied'))}<span class="nst3af-agent-applied__summary">${escapeHtml(String(message.content ?? ''))}</span></summary>
            ${rows}
            ${handoffHtml}
          </details>
          <div class="nst3af-agent-applied__actions">
            ${undone ? '' : this.renderResultLinks(meta.links)}
            ${undoBtn}
          </div>
        </div>
      </div>`;
    },

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
    },

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
          const payload = await new AjaxRequest(url).post({ draftId, sessionUuid: this.activeSessionUuid() }).then((r) => r.resolve());
          if (!payload?.ok) {
            throw new Error(payload?.message ?? lang('agent.error.confirmFailed', 'Confirm failed'));
          }
          draft.destructiveArmed = true;
          this.renderStream();
        } catch (error) {
          const text = await errorText(error);
          this.messages.push({ role: 'assistant', content: text, meta: { type: 'error' } });
          this.renderStream();
        } finally {
          this.isRunning = false;
          this.renderStream();
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

      if (draft.kind !== 'tool_confirmation' && (safeKeptFieldKeys.length === 0 || keptFieldsLackProposedValue(draft, safeKeptFieldKeys))) {
        this.announce(lang('agent.draft.nothingKept', 'Nothing is selected. Include at least one change or cancel.'));
        return;
      }

      const url = ajaxUrl('nst3af_agent_apply_draft');
      if (url === '') {
        return;
      }

      this.isRunning = true;
      draft.applying = true;
      draft.applyStartedAt = Date.now();
      this.renderStream();
      this.startWorkTraceTimer();
      this.announce(lang('agent.work.working', 'Working…'));
      try {
        const payload = await new AjaxRequest(url).post({
          draftId,
          sessionUuid: this.activeSessionUuid(),
          keptFieldKeys: safeKeptFieldKeys,
          applyMode,
          workspaceId: Number(this.context?.workspaceId ?? 0),
          correlationId: message.meta?.correlationId ?? '',
        }).then((r) => r.resolve());
        if (!payload?.ok) {
          throw new Error(payload?.message ?? lang('agent.error.applyFailed', 'Apply failed'));
        }

        draft.applied = true;
        draft.applying = false;
        const result = payload.result ?? {};

        if (draft.kind === 'tool_confirmation') {
          const presented = result.presentation ?? {};
          const workDurationMs = Date.now() - Number(draft.applyStartedAt ?? Date.now());
          message.content = messageContent(presented.content, messageContent(payload.message, lang('agent.draft.toolApplied', 'Tool ran successfully.')));
          message.meta = {
            type: 'tool_result',
            tool: String(result.tool ?? draft.tool ?? ''),
            toolCallLabel: resolveToolDisplayLabel({ ...draft, tool: String(result.tool ?? draft.tool ?? '') }),
            success: presented.success !== false,
            severity: String(draft.severity ?? 'write'),
            severityLabel: 'Write',
            facts: Array.isArray(presented.facts) ? presented.facts : [],
            details: presented.details ?? null,
            previews: Array.isArray(presented.previews) ? presented.previews : [],
            autoRan: false,
            correlationId: result.correlationId ?? message.meta?.correlationId ?? '',
            schedulerHandoff: payload.schedulerHandoff ?? null,
            fromDraftApply: true,
            workDurationMs,
            workSummary: String(draft.summary ?? ''),
            workArguments: Array.isArray(draft.arguments) ? draft.arguments : [],
            links: Array.isArray(payload.links) ? payload.links : [],
            fromRunner: message.meta?.fromRunner === true,
          };
          this.renderStream();
          this.refreshBackendContent();
          this.queueContinuation(message, 'applied', String(message.content ?? ''));
        } else {
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
              undoable: result.undoable !== false,
              correlationId: result.correlationId ?? '',
              schedulerHandoff: payload.schedulerHandoff ?? null,
              links: Array.isArray(payload.links) ? payload.links : [],
            },
          });
          this.renderStream();
          this.refreshBackendContent();
          this.queueContinuation(message, 'applied', String(payload.message ?? ''));
        }
      } catch (error) {
        draft.applying = false;
        const text = await errorText(error);
        this.messages.push({ role: 'assistant', content: text, meta: { type: 'error' } });
        this.renderStream();
      } finally {
        this.clearWorkTraceTimer();
        this.isRunning = false;
        this.showProgress(false);
        if (this.stream) {
          this.stream.removeAttribute('aria-busy');
        }
        this.renderStream();
      }
      await this.flushContinuation();
    },

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
        await new AjaxRequest(url).post({ draftId, sessionUuid: this.activeSessionUuid() });
      }

      message.meta.draft.discarded = true;
      const declinedLabel = resolveToolDisplayLabel(message.meta.draft);
      message.content = lang('agent.draft.discarded', 'Draft discarded. Nothing was written.');
      this.renderStream();
      this.queueContinuation(message, 'declined', '', declinedLabel);
      await this.flushContinuation();
    },

  /**
     * @param {Element|null} input
     */
    selectSuggestionVariant(input) {
      if (!(input instanceof HTMLInputElement)) {
        return;
      }
      const messageIndex = Number.parseInt(input.dataset.messageIndex ?? '-1', 10);
      const fieldKey = input.dataset.fieldKey ?? '';
      const variantIndex = Number.parseInt(input.dataset.variantIndex ?? '0', 10);
      const message = this.messages[messageIndex];
      if (!message?.meta || fieldKey === '') {
        return;
      }
      message.meta.selections = { ...(message.meta.selections ?? {}), [fieldKey]: variantIndex };
      if (message.meta.edits && Object.prototype.hasOwnProperty.call(message.meta.edits, fieldKey)) {
        delete message.meta.edits[fieldKey];
      }
    },

  /**
     * @param {Element|null} input
     */
    enableSuggestionEdit(input) {
      if (!(input instanceof HTMLInputElement)) {
        return;
      }
      const messageIndex = Number.parseInt(input.dataset.messageIndex ?? '-1', 10);
      const fieldKey = input.dataset.fieldKey ?? '';
      const message = this.messages[messageIndex];
      if (!message?.meta || fieldKey === '') {
        return;
      }
      const textarea = this.stream?.querySelector(
        `textarea[data-nst3af-agent-suggestions-edit][data-message-index="${messageIndex}"][data-field-key="${CSS.escape(fieldKey)}"]`,
      );
      const current = textarea instanceof HTMLTextAreaElement ? textarea.value : '';
      message.meta.edits = { ...(message.meta.edits ?? {}), [fieldKey]: current };
    },

  /**
     * @param {Element|null} textarea
     */
    updateSuggestionEdit(textarea) {
      if (!(textarea instanceof HTMLTextAreaElement)) {
        return;
      }
      const messageIndex = Number.parseInt(textarea.dataset.messageIndex ?? '-1', 10);
      const fieldKey = textarea.dataset.fieldKey ?? '';
      const message = this.messages[messageIndex];
      if (!message?.meta || fieldKey === '') {
        return;
      }
      message.meta.edits = { ...(message.meta.edits ?? {}), [fieldKey]: textarea.value };
    },

  /**
     * @param {Element|null} button
     * @param {'all'|'safe'} [applyMode]
     */
    async applySuggestions(button, applyMode = 'all') {
      if (!(button instanceof HTMLButtonElement) || this.isRunning) {
        return;
      }

      const messageIndex = Number.parseInt(button.dataset.messageIndex ?? '-1', 10);
      const draftId = button.dataset.draftId ?? '';
      const message = this.messages[messageIndex];
      const meta = message?.meta;
      if (!meta || meta.type !== 'suggestions' || draftId === '') {
        return;
      }

      const suggestions = meta.suggestions && typeof meta.suggestions === 'object' ? meta.suggestions : {};
      const fields = Array.isArray(suggestions.fields) ? suggestions.fields : [];
      const table = String(suggestions.target?.table ?? '');
      const selections = {};
      const edits = {};
      let editedByEditor = false;

      fields.forEach((field) => {
        const key = String(field.key ?? '');
        if (key === '') {
          return;
        }
        if (applyMode === 'safe' && !isSuggestionFieldSafe(table, key)) {
          return;
        }
        if (meta.edits && Object.prototype.hasOwnProperty.call(meta.edits, key)) {
          edits[key] = String(meta.edits[key] ?? '');
          editedByEditor = true;
          return;
        }
        selections[key] = Number(meta.selections?.[key] ?? 0);
      });

      if (Object.keys(selections).length === 0 && Object.keys(edits).length === 0) {
        this.messages.push({
          role: 'assistant',
          content: lang('agent.draft.noSafeFields', 'No low-risk fields to apply.'),
          meta: { type: 'error' },
        });
        this.renderStream();
        return;
      }

      const url = ajaxUrl('nst3af_agent_apply_draft');
      if (url === '') {
        return;
      }

      this.isRunning = true;
      meta.applying = true;
      this.renderStream();
      this.announce(lang('agent.work.working', 'Working…'));
      try {
        const payload = await new AjaxRequest(url).post({
          draftId,
          sessionUuid: this.activeSessionUuid(),
          selections,
          edits,
          editedByEditor,
          applyMode,
          workspaceId: Number(this.context?.workspaceId ?? 0),
          correlationId: meta.correlationId ?? '',
        }).then((r) => r.resolve());
        if (!payload?.ok) {
          throw new Error(payload?.message ?? lang('agent.error.applyFailed', 'Apply failed'));
        }

        const result = payload.result ?? {};
        meta.applied = true;
        meta.applying = false;
        meta.appliedCount = Number(result.appliedCount ?? Object.keys(selections).length + Object.keys(edits).length);
        meta.changeId = result.changeId ?? '';

        this.messages.push({
          role: 'assistant',
          content: messageContent(
            payload.message,
            lang('agent.suggestions.applied', 'Applied %1$s suggestion field(s).', [String(meta.appliedCount)]),
          ),
          meta: {
            type: 'readback_result',
            readback: result.readback ?? [],
            changeId: result.changeId ?? '',
            undoable: result.undoable !== false,
            correlationId: result.correlationId ?? meta.correlationId ?? '',
            schedulerHandoff: payload.schedulerHandoff ?? null,
            appliedValues: result.appliedValues ?? {},
            links: Array.isArray(payload.links) ? payload.links : [],
          },
        });
        this.renderStream();
        this.refreshBackendContent();
        this.queueContinuation(message, 'applied', String(payload.message ?? ''));
      } catch (error) {
        meta.applying = false;
        const text = await errorText(error);
        this.messages.push({ role: 'assistant', content: text, meta: { type: 'error' } });
        this.renderStream();
      } finally {
        this.isRunning = false;
        if (this.stream) {
          this.stream.removeAttribute('aria-busy');
        }
        this.renderStream();
      }
      await this.flushContinuation();
    },

  /**
     * @param {Element|null} button
     */
    async discardSuggestions(button) {
      if (!(button instanceof HTMLButtonElement) || this.isRunning) {
        return;
      }

      const messageIndex = Number.parseInt(button.dataset.messageIndex ?? '-1', 10);
      const draftId = button.dataset.draftId ?? '';
      const message = this.messages[messageIndex];
      if (!message?.meta || message.meta.type !== 'suggestions' || draftId === '') {
        return;
      }

      const url = ajaxUrl('nst3af_agent_discard_draft');
      if (url !== '') {
        await new AjaxRequest(url).post({ draftId, sessionUuid: this.activeSessionUuid() });
      }

      message.meta.discarded = true;
      const declinedSuggestionLabel = resolveToolDisplayLabel(message.meta);
      message.content = lang('agent.draft.discarded', 'Draft discarded. Nothing was written.');
      this.renderStream();
      this.queueContinuation(message, 'declined', '', declinedSuggestionLabel);
      await this.flushContinuation();
    },

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
        const payload = await new AjaxRequest(url).post({ changeId, sessionUuid: this.activeSessionUuid() }).then((r) => r.resolve());
        if (!payload?.ok) {
          throw new Error(payload?.message ?? lang('agent.error.undoFailed', 'Undo failed'));
        }
        this.markChangeUndone(changeId);
        this.messages.push({
          role: 'assistant',
          content: messageContent(payload.message, lang('agent.draft.undone', 'Change undone.')),
          meta: { type: 'info' },
        });
        this.renderStream();
        this.refreshBackendContent();
      } catch (error) {
        const text = await errorText(error);
        // "Already undone" (or the change is gone): the button must not stay clickable.
        if (error?.response?.status === 400) {
          this.markChangeUndone(changeId);
        }
        this.messages.push({ role: 'assistant', content: text, meta: { type: 'error' } });
        this.renderStream();
      } finally {
        this.isRunning = false;
        this.renderStream();
      }
    },

  /**
     * @param {string} changeId
     */
    markChangeUndone(changeId) {
      for (const message of this.messages) {
        if (message?.meta?.changeId === changeId) {
          message.meta.undone = true;
        }
      }
    },
};

/**
 * Same create offered twice (follow-up before Execute) shares this fingerprint.
 *
 * @param {object} draft
 * @returns {string}
 */
function createDraftFingerprint(draft) {
  const fields = (Array.isArray(draft.fields) ? draft.fields : []).map((field) => [
    String(field?.table ?? ''),
    Number(field?.uid ?? 0),
    String(field?.field ?? ''),
    String(field?.proposed ?? ''),
  ]);
  return JSON.stringify([String(draft.tool ?? ''), fields]);
}

/**
 * A kept change with no proposed value must not be applied.
 *
 * @param {object} draft
 * @param {string[]|null} keys
 * @returns {boolean}
 */
function keptFieldsLackProposedValue(draft, keys = null) {
  const fields = Array.isArray(draft?.fields) ? draft.fields : [];
  return fields.some((field) => {
    if (field?.kept === false) {
      return false;
    }
    if (Array.isArray(keys) && !keys.includes(String(field?.key ?? ''))) {
      return false;
    }
    return String(field?.proposed ?? '').trim() === '';
  });
}
