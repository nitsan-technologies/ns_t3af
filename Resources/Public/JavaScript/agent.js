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
const OPEN_HOTKEY_BOUND = Symbol.for('nst3af.agent.openHotkey');
const IFRAME_WATCHED = Symbol.for('nst3af.agent.iframeWatch');

/**
 * @param {string} chord
 * @returns {string}
 */
function hotkeyLabel(chord) {
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
 * Mirror of AgentLowRiskFieldMatrix for suggestion "Apply safe" UX.
 *
 * @param {string} table
 * @param {string} fieldKey
 * @returns {boolean}
 */
function isSuggestionFieldSafe(table, fieldKey) {
  const normalizedTable = String(table ?? '').toLowerCase().trim();
  let field = String(fieldKey ?? '').toLowerCase().trim();
  if (field.includes(':')) {
    field = field.slice(field.lastIndexOf(':') + 1);
  }
  const aliases = {
    metatitle: 'seo_title',
    seo_title: 'seo_title',
    metadescription: 'description',
    ogtitle: 'og_title',
    og_title: 'og_title',
    ogdescription: 'og_description',
    og_description: 'og_description',
    alttext: 'alternative',
    alternative: 'alternative',
  };
  field = aliases[field] ?? field;
  const safe = {
    pages: ['seo_title', 'description', 'abstract', 'keywords', 'og_title', 'og_description'],
    sys_file_metadata: ['alternative', 'description', 'title'],
  };
  return (safe[normalizedTable] ?? []).includes(field);
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
  return lang('agent.error.generic', 'Something went wrong.');
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
 * Read the active module iframe URL (Page / List) when same-origin.
 * @returns {URL|null}
 */
function resolveContentIframeUrl() {
  try {
    const backendDoc = getBackendDocument();
    const iframe = backendDoc.querySelector('#typo3-contentIframe, [data-scaffold-content-iframe], iframe[name="list_frame"]');
    if (iframe instanceof HTMLIFrameElement && iframe.contentWindow) {
      return new URL(iframe.contentWindow.location.href);
    }
  } catch {
    // iframe may be cross-origin or not ready.
  }

  try {
    return new URL(window.location.href);
  } catch {
    return null;
  }
}

/**
 * Language column ids selected in the Page module (e.g. languages[0]=0&languages[1]=2).
 * @returns {number[]}
 */
function resolveSelectedLanguageIds() {
  const url = resolveContentIframeUrl();
  if (!url) {
    return [];
  }

  const ids = [];
  url.searchParams.forEach((value, key) => {
    const match = key.match(/^languages\[(\d+)\]$/);
    if (!match) {
      return;
    }
    const id = Number.parseInt(value, 10);
    if (Number.isFinite(id)) {
      ids.push(id);
    }
  });

  if (ids.length > 0) {
    return ids;
  }

  url.searchParams.getAll('languages').forEach((value) => {
    const id = Number.parseInt(value, 10);
    if (Number.isFinite(id)) {
      ids.push(id);
    }
  });

  return ids;
}

/**
 * Pick the translation target from Page module language columns (first non-default).
 * @param {number[]} languageIds
 * @returns {number}
 */
function resolveTargetLanguageId(languageIds) {
  const targets = languageIds.filter((id) => id > 0);
  return targets.length > 0 ? targets[0] : 0;
}

/**
 * @param {string} module
 * @returns {boolean}
 */
function isFileModuleRoute(module) {
  const normalized = String(module ?? '').toLowerCase();

  return normalized === 'media_management' || normalized.startsWith('file');
}

/**
 * Parse FAL list module URL id param (e.g. 1:/user_upload/).
 * @returns {{ storageUid: number, folderIdentifier: string }}
 */
function resolveFileListContext() {
  const url = resolveContentIframeUrl();
  if (!url) {
    return { storageUid: 0, folderIdentifier: '' };
  }

  const rawId = url.searchParams.get('id') ?? '';
  if (rawId === '') {
    return { storageUid: 0, folderIdentifier: '' };
  }

  const id = decodeURIComponent(rawId);
  const colon = id.indexOf(':');
  if (colon <= 0) {
    return { storageUid: 0, folderIdentifier: '' };
  }

  const storageUid = Number.parseInt(id.slice(0, colon), 10);
  let folderIdentifier = id.slice(colon + 1).trim();
  if (folderIdentifier !== '' && !folderIdentifier.startsWith('/')) {
    folderIdentifier = `/${folderIdentifier}`;
  }

  return {
    storageUid: Number.isFinite(storageUid) && storageUid > 0 ? storageUid : 0,
    folderIdentifier,
  };
}

/**
 * @returns {{ pageId: number, module: string, languageId: number, storageUid: number, folderIdentifier: string }}
 */
function resolveBackendContext() {
  const module = resolveModuleRoute();
  const inFileModule = isFileModuleRoute(module);
  const fileList = inFileModule ? resolveFileListContext() : { storageUid: 0, folderIdentifier: '' };

  const state = ModuleStateStorage.current('web');
  let pageId = Number.parseInt(state?.identifier || '0', 10);
  if (!Number.isFinite(pageId) || pageId <= 0) {
    pageId = 0;
  }
  if (inFileModule) {
    pageId = 0;
  }

  const languageId = resolveTargetLanguageId(resolveSelectedLanguageIds());

  return {
    pageId,
    module,
    languageId,
    storageUid: fileList.storageUid,
    folderIdentifier: fileList.folderIdentifier,
  };
}

/**
 * @param {object} source
 * @returns {string}
 */
/**
 * "targetLanguageId" / "target_language" → "Target language id".
 *
 * @param {string} key
 * @returns {string}
 */
/**
 * Generated image or audio file of a tool result (only same-site paths or http(s) URLs).
 *
 * @param {unknown} details
 * @returns {string}
 */
function renderMediaPreview(details) {
  if (!details || typeof details !== 'object') {
    return '';
  }
  let url = String(details.previewImageUrl ?? details.publicUrl ?? '').trim();
  if (/^fileadmin\//i.test(url)) {
    url = `/${url}`;
  }
  if (url === '' || !/^(https?:\/\/|\/)/i.test(url) || url.startsWith('//')) {
    return '';
  }
  const name = String(details.fileName ?? '');
  if (/\.(mp3|wav|ogg|m4a)(\?|$)/i.test(url)) {
    return `<div class="nst3af-agent-media"><audio controls preload="none" src="${escapeHtml(url)}"></audio><span class="nst3af-agent-media__name">${escapeHtml(name)}</span></div>`;
  }
  if (details.previewImageUrl || /\.(png|jpe?g|gif|webp|svg)(\?|$)/i.test(url)) {
    return `<div class="nst3af-agent-media"><a href="${escapeHtml(url)}" target="_blank" rel="noopener"><img src="${escapeHtml(url)}" alt="${escapeHtml(name)}" loading="lazy"></a><span class="nst3af-agent-media__name">${escapeHtml(name)}</span></div>`;
  }
  return '';
}

function humanizeKey(key) {
  const words = String(key).replace(/([a-z0-9])([A-Z])/g, '$1 $2').replace(/[_-]+/g, ' ').trim().toLowerCase();
  return words === '' ? '' : words.charAt(0).toUpperCase() + words.slice(1);
}

function resolveToolDisplayLabel(source) {
  const editorLabel = String(source?.editorLabel ?? source?.toolCallLabel ?? source?.label ?? '').trim();
  if (editorLabel !== '') {
    return editorLabel;
  }

  return String(source?.tool ?? source?.name ?? '').trim();
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
 * @param {number} ms
 * @returns {string}
 */
function formatWorkDuration(ms) {
  const totalSeconds = Math.max(1, Math.round(ms / 1000));
  if (totalSeconds < 60) {
    return `${totalSeconds}s`;
  }

  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;

  return seconds > 0 ? `${minutes}m ${seconds}s` : `${minutes}m`;
}

/**
 * Compact Cursor-style work trace (Working… / Worked for 12s).
 *
 * @param {{
 *   working?: boolean,
 *   durationMs?: number,
 *   toolName?: string,
 *   summary?: string,
 *   args?: Array<{key?: string, value?: string}>,
 *   resultContent?: string,
 *   facts?: Array<{label?: string, value?: string}>,
 *   open?: boolean,
 * }} config
 * @returns {string}
 */
function renderWorkTraceHtml(config) {
  const working = config.working === true;
  const durationMs = Math.max(0, Number(config.durationMs ?? 0));
  const open = config.open ?? working;
  const toolName = String(config.toolName ?? '').trim();
  const summary = String(config.summary ?? '').trim();

  const label = working
    ? lang('agent.work.working', 'Working…')
    : lang('agent.work.workedFor', 'Worked for %1$s').replace('%1$s', formatWorkDuration(durationMs));

  const args = Array.isArray(config.args) ? config.args : [];
  const argRows = args.map((entry) => {
    const key = escapeHtml(String(entry.key ?? ''));
    const value = escapeHtml(String(entry.value ?? ''));
    if (key === '') {
      return '';
    }

    return `<div class="nst3af-agent-work-trace__arg"><dt>${key}</dt><dd>${value}</dd></div>`;
  }).join('');

  const argsSection = argRows !== ''
    ? `<div class="nst3af-agent-work-trace__section"><div class="nst3af-agent-work-trace__section-label">${escapeHtml(lang('agent.draft.toolArguments', 'Parameters'))}</div>${argRows}</div>`
    : '';

  const stepLine = toolName !== ''
    ? `<div class="nst3af-agent-work-trace__step">${working
      ? escapeHtml(lang('agent.draft.runningTool', 'Running %1$s…').replace('%1$s', toolName))
      : `<strong>${escapeHtml(toolName)}</strong>`}</div>`
    : '';

  const summaryLine = summary !== ''
    ? `<div class="nst3af-agent-work-trace__detail">${escapeHtml(summary)}</div>`
    : '';

  const facts = Array.isArray(config.facts) ? config.facts : [];
  const factsRows = facts.map((fact) => (
    `<div class="nst3af-agent-work-trace__arg"><dt>${escapeHtml(String(fact.label ?? ''))}</dt><dd>${escapeHtml(String(fact.value ?? ''))}</dd></div>`
  )).join('');
  const factsSection = factsRows !== ''
    ? `<div class="nst3af-agent-work-trace__section">${factsRows}</div>`
    : '';

  const resultContent = String(config.resultContent ?? '').trim();
  const resultSection = resultContent !== ''
    ? `<div class="nst3af-agent-work-trace__result">${renderMessageBody(resultContent)}</div>`
    : '';

  const spinner = working
    ? '<span class="nst3af-agent-work-trace__spinner" aria-hidden="true"></span>'
    : '';

  const bodyHtml = `${stepLine}${summaryLine}${argsSection}${resultSection}${factsSection}`;

  if (bodyHtml.trim() === '') {
    return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant nst3af-agent-msg--work-trace">
      <div class="nst3af-agent-work-trace nst3af-agent-work-trace--compact"${working ? ' data-nst3af-work-trace-active="1"' : ''}>
        ${spinner}
        <span class="nst3af-agent-work-trace__label" data-nst3af-work-trace-timer>${escapeHtml(label)}</span>
      </div>
    </div>`;
  }

  return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant nst3af-agent-msg--work-trace">
    <details class="nst3af-agent-work-trace"${open ? ' open' : ''}${working ? ' data-nst3af-work-trace-active="1"' : ''}>
      <summary class="nst3af-agent-work-trace__summary">
        ${spinner}
        <span class="nst3af-agent-work-trace__label" data-nst3af-work-trace-timer>${escapeHtml(label)}</span>
      </summary>
      <div class="nst3af-agent-work-trace__body">
        ${stepLine}
        ${summaryLine}
        ${argsSection}
        ${resultSection}
        ${factsSection}
      </div>
    </details>
  </div>`;
}

/**
 * Work-trace fields are live UI only — not useful when reopening the modal.
 *
 * @param {object} meta
 * @returns {object}
 */
function stripEphemeralWorkTraceMeta(meta) {
  if (!meta || typeof meta !== 'object') {
    return {};
  }

  const cleaned = { ...meta };
  delete cleaned.fromDraftApply;
  delete cleaned.workDurationMs;
  delete cleaned.workSummary;
  delete cleaned.workArguments;

  return cleaned;
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
  }

  /**
   * @param {{sessionUuid?: string, fresh?: boolean}} [options]
   */
  async reloadSessionForCurrentScope(options = {}) {
    this.isLoadingSession = true;
    this.panel?.setAttribute('aria-busy', 'true');
    this.renderLoadingSkeleton();
    await this.restoreSession(options);
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
    this.contextNotice = '';
    this.closeDrawers();
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
    if (options.sessionUuid) {
      query.searchParams.set('sessionUuid', options.sessionUuid);
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
      this.continueAfterConfirm = payload.continueAfterConfirm !== false;
      this.credits = payload.credits ?? null;
      this.disclosureDismissed = payload.disclosureDismissed === true;
      if (this.disclosureDismissed) {
        this.disclosure.hidden = true;
      }
      this.loadedScopeKey = this.sessionScopeKey();
    } catch {
      this.context = backendContext;
      this.loadedScopeKey = this.sessionScopeKey();
    }
    this.renderHeaderControls();
    this.renderCredits();
  }

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
  }

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
  }

  /**
   * Drawers start below the header, so the header buttons stay usable.
   *
   * @param {HTMLElement} drawer
   */
  positionDrawer(drawer) {
    const header = this.panel?.querySelector('.nst3af-agent-header');
    drawer.style.top = header instanceof HTMLElement ? `${header.offsetHeight}px` : '0';
  }

  async toggleSessions() {
    if (!(this.sessionsDrawer instanceof HTMLElement)) {
      return;
    }
    const opening = this.sessionsDrawer.hidden;
    this.closeDrawers();
    if (!opening) {
      return;
    }
    this.positionDrawer(this.sessionsDrawer);
    this.sessionsDrawer.hidden = false;
    this.sessionsToggle?.setAttribute('aria-expanded', 'true');
    this.sessionsFilter = this.sessionListSettings.scope === 'user' ? 'all' : (this.sessionListSettings.defaultFilter ?? 'current');
    if (this.sessionsSearch instanceof HTMLInputElement) {
      this.sessionsSearch.value = '';
    }
    await this.loadSessions();
  }

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
  }

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
  }

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
                title="${escapeHtml(lang('agent.session.rename', 'Rename'))}" aria-label="${escapeHtml(lang('agent.session.rename', 'Rename'))}">✎</button>
        <button type="button" class="btn btn-link btn-sm" data-nst3af-agent-session-delete="${uuid}"
                title="${escapeHtml(lang('agent.session.delete', 'Delete'))}" aria-label="${escapeHtml(lang('agent.session.delete', 'Delete'))}">🗑</button>
      </span>
    </li>`;
  }

  /**
   * @param {string} uuid
   */
  async openSession(uuid) {
    if (uuid === '' || this.isRunning) {
      return;
    }
    this.closeDrawers();
    this.contextNotice = '';
    await this.reloadSessionForCurrentScope({ sessionUuid: uuid });
    this.input?.focus();
  }

  async startNewConversation() {
    if (this.isRunning) {
      return;
    }
    this.closeDrawers();
    this.contextNotice = '';
    await this.reloadSessionForCurrentScope({ fresh: true });
    this.announce(lang('agent.session.started', 'New conversation started.'));
    this.input?.focus();
  }

  /**
   * @param {string} uuid
   */
  async renameSession(uuid) {
    this.renamingUuid = uuid;
    this.renderSessions();
  }

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
  }

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
      button.textContent = lang('agent.session.deleteConfirm', 'Delete?');
      window.setTimeout(() => {
        if (button.isConnected) {
          button.dataset.armed = '';
          button.classList.remove('is-armed');
          button.textContent = '🗑';
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
    if (this.session?.uuid === uuid) {
      this.session = null;
      await this.reloadSessionForCurrentScope({ fresh: true });
      this.sessionsDrawer?.removeAttribute('hidden');
    }
    this.renderSessions();
  }

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
  }

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
  }

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
  }

  /**
   * @returns {string}
   */
  renderContextNotice() {
    return this.contextNotice !== ''
      ? `<div class="nst3af-agent-notice nst3af-agent-notice--system" role="status">${escapeHtml(this.contextNotice)}</div>`
      : '';
  }

  /**
   * @returns {string}
   */
  describeContextChange() {
    const page = this.context.details?.page;
    if (page) {
      return lang('agent.session.nowOnPage', 'Now working on page %1$s.', [`${page.title ?? ''} [${page.uid ?? 0}]`]);
    }
    return lang('agent.session.nowInModule', 'Now working in %1$s.', [String(this.context.details?.module?.label ?? '')]);
  }

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
  }

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
  }

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
  }

  async flushContinuation() {
    if (this.pendingContinuation === null || this.executingAll || this.isRunning) {
      return;
    }
    const continuation = this.pendingContinuation;
    this.pendingContinuation = null;
    await this.submitTurn('', {}, '', { continuation });
  }

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
        : lang('agent.composer.placeholder', 'Ask a question, / for tools, @ for records…');
    }
    this.root.querySelector('[data-nst3af-agent-send]')?.toggleAttribute('disabled', empty);
  }

  /**
   * Indexes of cards that still wait for the editor and can run without a second click.
   *
   * @returns {number[]}
   */
  pendingExecutableDrafts() {
    const indexes = [];
    this.messages.forEach((message, index) => {
      const draft = message.meta?.draft;
      if (message.meta?.type === 'inline_draft' && draft && !draft.applied && !draft.discarded && !draft.applying
        && String(draft.severity ?? 'write') !== 'destructive') {
        indexes.push(index);
      }
    });
    return indexes;
  }

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
  }

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
  }

  updateSummarizeButton() {
    if (this.summarizeButton instanceof HTMLButtonElement) {
      this.summarizeButton.hidden = !this.canSummarize();
      this.summarizeButton.disabled = this.isRunning || this.credits?.empty === true;
    }
  }

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
      this.messages.push({ role: 'assistant', content: errorMessage(error), meta: { type: 'error' } });
    } finally {
      this.isRunning = false;
      this.showProgress(false);
      this.renderStream();
    }
  }

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
  }

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
  }

  /**
   * @param {string} option
   */
  async answerClarification(option) {
    if (!this.input || this.isRunning || option === '') {
      return;
    }
    this.input.value = option;
    await this.submitTurn();
  }

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
  }

  /**
   * Backend links open in the content area (the agent stays open), frontend links in a new tab.
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
        return true;
      }
    } catch {
      // Same-origin only; fall back to the default navigation.
    }
    return false;
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
    const icons = { page: '📄', module: '🧩', language: '🌐', record: '✏️', folder: '📁', workspace: '🗂', brand: '🏷' };
    this.contextEl.innerHTML = dimChip + chips.map((chip) => {
      const key = String(chip.key ?? '');
      const icon = icons[key] ? `<span aria-hidden="true">${icons[key]}</span> ` : '';
      const hint = escapeHtml(String(chip.hint ?? ''));
      return `<span class="nst3af-agent-ctxchip nst3af-agent-ctxchip--${escapeHtml(key)}" title="${hint}">${icon}<span class="visually-hidden">${escapeHtml(String(chip.label ?? ''))}: </span>${escapeHtml(String(chip.value ?? ''))}</span>`;
    }).join('');
  }

  async dismissDisclosure() {
    this.disclosureDismissed = true;
    this.disclosure.hidden = true;
    await this.saveDisclosure();
  }

  renderStream() {
    if (!this.stream || this.isLoadingSession) {
      return;
    }

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
          return `<details class="nst3af-agent-step"><summary><span aria-hidden="true">✓</span> ${escapeHtml(resolveToolDisplayLabel(meta))}</summary>${this.renderToolResultMessage(message)}</details>`;
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
      return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant"><div class="nst3af-agent-msg__who">AI Agent</div><div class="nst3af-agent-msg__body">${renderMessageBody(String(message.content ?? ''))}</div>${extra}</div>`;
    }).join('');

    const pending = this.pendingExecutableDrafts();
    const executeAllBar = pending.length >= 2 && !this.isRunning
      ? `<div class="nst3af-agent-execute-all"><button type="button" class="btn btn-primary btn-sm" data-nst3af-agent-execute-all>${escapeHtml(lang('agent.draft.executeAll', 'Execute all (%1$s)', [String(pending.length)]))}</button></div>`
      : '';
    this.stream.innerHTML = this.renderHomeNotice() + html + executeAllBar + this.renderContextNotice();
    this.updateSummarizeButton();
    if (this.messages.length === 0) {
      this.renderGreeting();
    } else {
      this.renderStarters(this.starters);
    }
    if (this.isRunning) {
      this.showProgress(true, this._progressLabel || '');
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
    return workHeaderHtml + `<div class="nst3af-agent-msg nst3af-agent-msg--assistant${statusClass}">
      <div class="nst3af-agent-msg__who">AI Agent</div>
      <div class="nst3af-agent-tcall">
        <div class="nst3af-agent-tcall__head">
          <span class="nst3af-agent-sev-dot nst3af-agent-sev-dot--${escapeHtml(String(meta.severity ?? 'read'))}" aria-hidden="true"></span>
          <strong>${toolLabel}</strong>
          ${autoHtml}
        </div>
        <div class="nst3af-agent-msg__body">${renderMessageBody(String(message.content ?? ''))}</div>
        ${success ? renderMediaPreview(meta.details) : ''}
        ${factsHtml}
        ${traceHtml}
        ${extra}
        ${detailsHtml}
      </div>
    </div>`;
  }

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
        <p class="nst3af-agent-suggestions__credits" role="note">${escapeHtml(creditHint)}</p>
        <div class="nst3af-agent-suggestions__fields">${fieldRows}</div>
        <div class="nst3af-agent-suggestions__actions">
          <button type="button" class="btn btn-primary btn-sm" data-nst3af-agent-suggestions-apply="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(draftId)}">${escapeHtml(lang('agent.suggestions.apply', 'Apply selected'))}</button>
          ${safeBtn}
          <button type="button" class="btn btn-default btn-sm" data-nst3af-agent-suggestions-discard="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(draftId)}">${escapeHtml(lang('agent.draft.discard', 'Discard'))}</button>
        </div>
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
        summary: lang('agent.draft.proposed', 'Review the proposed changes for %1$s before anything is written.').replace('%1$s', toolLabel),
        open: true,
      }) + `<span class="visually-hidden">${escapeHtml(severity)}</span>`;
    }

    const rows = fields.map((field) => {
      const kept = field.kept !== false;
      const dropClass = kept ? '' : ' nst3af-agent-draft__fld--dropped';
      const recordLabel = String(field.recordLabel ?? '') || `${field.table ?? ''}:${Number(field.uid ?? 0)}`;
      const label = `${escapeHtml(recordLabel)} · <strong>${escapeHtml(String(field.fieldLabel ?? '') || String(field.field ?? ''))}</strong>`;
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
      : lang('agent.draft.execute', 'Execute');
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
          <span class="nst3af-agent-draft__title">${escapeHtml(resolveToolDisplayLabel(draft))}</span>
          <span class="nst3af-agent-draft__badge">${escapeHtml(lang('agent.draft.previewBadge', 'Preview'))}</span>
        </div>
        <p class="nst3af-agent-draft__lead">${renderMessageBody(String(message.content ?? ''))}</p>
        <div class="nst3af-agent-draft__cols" aria-hidden="true"><span></span><span>${escapeHtml(lang('agent.draft.colCurrent', 'Now'))}</span><span>${escapeHtml(lang('agent.draft.colProposed', 'New'))}</span><span></span></div>
        <div class="nst3af-agent-draft__fields">${rows}</div>
        ${this.renderDraftTarget(isDestructive)}
        <div class="nst3af-agent-draft__actions">
          <button type="button" class="btn btn-primary btn-sm" data-nst3af-agent-draft-apply="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(String(draft.draftId ?? ''))}">${escapeHtml(applyLabel)}</button>
          ${safeApplyBtn}
          <button type="button" class="btn btn-default btn-sm" data-nst3af-agent-draft-discard="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(String(draft.draftId ?? ''))}">${escapeHtml(lang('agent.draft.decline', 'Decline'))}</button>
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
      : lang('agent.draft.execute', 'Execute');

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
        ${argsBlock}
        ${this.renderDraftTarget(isDestructive)}
        <div class="nst3af-agent-draft__actions">
          <button type="button" class="btn btn-primary btn-sm" data-nst3af-agent-draft-apply="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(String(draft.draftId ?? ''))}">${escapeHtml(applyLabel)}</button>
          <button type="button" class="btn btn-default btn-sm" data-nst3af-agent-draft-discard="1" data-message-index="${messageIndex}" data-draft-id="${escapeHtml(String(draft.draftId ?? ''))}">${escapeHtml(lang('agent.draft.decline', 'Decline'))}</button>
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
      const fieldLabels = entry.fieldLabels ?? {};
      const cells = Object.entries(values).map(([key, value]) => (
        `<tr><td>${escapeHtml(String(fieldLabels[key] ?? key))}</td><td>${escapeHtml(String(value ?? ''))}</td></tr>`
      )).join('');
      const recordLabel = String(entry.recordLabel ?? '') || `${entry.table ?? ''}:${Number(entry.uid ?? 0)}`;
      return `<div class="nst3af-agent-readback__record"><strong>${escapeHtml(recordLabel)}</strong><table>${cells}</table></div>`;
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
        ${rows}
        ${this.renderResultLinks(meta.links)}
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
        const payload = await new AjaxRequest(url).post({ draftId, sessionUuid: this.activeSessionUuid() }).then((r) => r.resolve());
        if (!payload?.ok) {
          throw new Error(payload?.message ?? lang('agent.error.confirmFailed', 'Confirm failed'));
        }
        draft.destructiveArmed = true;
        this.renderStream();
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
            correlationId: result.correlationId ?? '',
            schedulerHandoff: payload.schedulerHandoff ?? null,
            links: Array.isArray(payload.links) ? payload.links : [],
          },
        });
        this.renderStream();
        this.queueContinuation(message, 'applied', String(payload.message ?? ''));
      }
    } catch (error) {
      draft.applying = false;
      const text = errorMessage(error);
      this.messages.push({ role: 'assistant', content: text, meta: { type: 'error' } });
      this.renderStream();
    } finally {
      this.clearWorkTraceTimer();
      this.isRunning = false;
      this.showProgress(false);
      if (this.stream) {
        this.stream.removeAttribute('aria-busy');
      }
    }
    await this.flushContinuation();
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
      await new AjaxRequest(url).post({ draftId, sessionUuid: this.activeSessionUuid() });
    }

    message.meta.draft.discarded = true;
    const declinedLabel = resolveToolDisplayLabel(message.meta.draft);
    message.content = lang('agent.draft.discarded', 'Draft discarded. Nothing was written.');
    this.renderStream();
    this.queueContinuation(message, 'declined', '', declinedLabel);
    await this.flushContinuation();
  }

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
  }

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
  }

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
  }

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
          correlationId: result.correlationId ?? meta.correlationId ?? '',
          schedulerHandoff: payload.schedulerHandoff ?? null,
          appliedValues: result.appliedValues ?? {},
          links: Array.isArray(payload.links) ? payload.links : [],
        },
      });
      this.renderStream();
      this.queueContinuation(message, 'applied', String(payload.message ?? ''));
    } catch (error) {
      meta.applying = false;
      const text = errorMessage(error);
      this.messages.push({ role: 'assistant', content: text, meta: { type: 'error' } });
      this.renderStream();
    } finally {
      this.isRunning = false;
      if (this.stream) {
        this.stream.removeAttribute('aria-busy');
      }
    }
    await this.flushContinuation();
  }

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
      const payload = await new AjaxRequest(url).post({ changeId, sessionUuid: this.activeSessionUuid() }).then((r) => r.resolve());
      if (!payload?.ok) {
        throw new Error(payload?.message ?? lang('agent.error.undoFailed', 'Undo failed'));
      }
      this.messages.push({
        role: 'assistant',
        content: messageContent(payload.message, lang('agent.draft.undone', 'Change undone.')),
        meta: { type: 'info' },
      });
      this.renderStream();
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

  /**
   * @param {string} [explicitTool]
   * @param {object} [toolArguments]
   * @param {string} [starterAction]
   * @param {{continuation?: {outcome: string, label: string, result: string}}} [options]
   */
  async submitTurn(explicitTool = '', toolArguments = {}, starterAction = '', options = {}) {
    if (!this.input || this.isRunning) {
      return;
    }
    if (this.credits?.empty === true) {
      this.announce(lang('agent.credits.empty', 'Your T3Planet credits are used up.'));
      return;
    }

    const continuation = options.continuation ?? null;
    const message = continuation !== null ? 'continue' : this.input.value.trim();
    if (message === '') {
      return;
    }


    this.isRunning = true;
    if (continuation === null) {
      this.input.value = '';
    }
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
      sessionUuid: this.freshSession ? '' : (this.session?.uuid ?? ''),
      fresh: this.freshSession,
      provider: this.session?.provider || this.selectedProvider || 'default',
    };
    if (continuation !== null) {
      body.continuation = continuation;
    }

    const preferStream = explicitTool === '' && starterAction === '';

    const userMessage = continuation !== null
      ? { role: 'user', content: message, meta: { hidden: true, type: 'continuation' } }
      : { role: 'user', content: message, meta: {} };
    try {
      this.messages.push(userMessage);
      this.renderStream();
      this.showProgress(true);
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
        throw new Error(payload?.message ?? lang('agent.error.turnFailed', 'Turn failed'));
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
      if (payload.userMessage && typeof payload.userMessage === 'object') {
        // The server's copy carries where it was written (and the continuation text).
        Object.assign(userMessage, payload.userMessage);
      }
      if (payload.credits !== undefined) {
        this.credits = payload.credits;
        this.renderCredits();
      }
      if (payload.session) {
        this.session = payload.session;
        this.freshSession = false;
      }
      this.contextNotice = '';
      this.renderHeaderControls();

      this.renderContext();
      this.renderStream();
      if (payload.starters) {
        this.starters = payload.starters;
      }
      if (payload.greeting) {
        this.greeting = payload.greeting;
      }
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

      if (eventName === 'progress') {
        const status = String(data.status ?? '');
        let label = lang('agent.live.running', 'Assistant is working…');
        if (status === 'llm') {
          label = lang('agent.live.thinking', 'Thinking…');
        } else if (status === 'tool' && (data.label || data.tool)) {
          label = lang('agent.live.runningTool', 'Running %1$s…').replace('%1$s', String(data.label || data.tool));
        }
        this.updateProgressLabel(label);
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
        throw new Error(data.message ?? lang('agent.error.streamFailed', 'Stream failed'));
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
  }

  /**
   * Conversation the server records a card action in.
   *
   * @returns {string}
   */
  activeSessionUuid() {
    return this.session?.uuid && !this.freshSession ? String(this.session.uuid) : '';
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
    const displayLabel = resolveToolDisplayLabel(tool);
    const aria = this.buildToolAriaLabel(tool, locked);
    return `<button type="button" class="nst3af-agent-autocomplete__item${locked ? ' nst3af-agent-autocomplete__item--locked' : ''}" data-nst3af-agent-ac-item="1" data-locked="${locked ? '1' : '0'}" data-insert="/${escapeHtml(String(tool.name ?? ''))} " aria-label="${escapeHtml(aria)}" role="option"><span class="nst3af-agent-sev-dot nst3af-agent-sev-dot--${escapeHtml(String(tool.severity ?? 'read'))}" aria-hidden="true"></span><span><span class="nst3af-agent-autocomplete__item-title">${escapeHtml(displayLabel)}</span><span class="nst3af-agent-autocomplete__item-desc">${escapeHtml(String(tool.description ?? ''))} · ${escapeHtml(String(tool.ownerLabel ?? ''))} · ${escapeHtml(severityText)}</span></span></button>`;
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
      resolveToolDisplayLabel(tool),
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
   * @param {string} text
   */
  announce(text) {
    if (!this.stream) {
      return;
    }
    this.stream.setAttribute('aria-label', text);
  }

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
  }

  clearWorkTraceTimer() {
    if (this.workTraceTimer) {
      window.clearInterval(this.workTraceTimer);
      this.workTraceTimer = 0;
    }
  }

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
  }

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
      return;
    }

    const text = label !== '' ? label : lang('agent.live.running', 'Assistant is working…');
    this._progressLabel = text;
    const node = document.createElement('div');
    node.dataset.nst3afAgentProgress = '1';
    node.className = 'nst3af-agent-progress';
    node.innerHTML = '<span class="nst3af-agent-progress__spinner" aria-hidden="true"></span><span data-nst3af-agent-progress-label>' + escapeHtml(text) + '</span>';
    this.stream.appendChild(node);
    this.stream.scrollTop = this.stream.scrollHeight;
    this.announce(text);
  }

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

/**
 * Open shortcut for TYPO3 12–14: bind keydown (capture) on the scaffold and every
 * same-origin iframe. v12 Live Search treats Cmd+K and Cmd+Shift+K as the same
 * chord; we stopImmediatePropagation so only Agent opens on Shift+K. v13/v14 Live
 * Search uses an exact Hotkeys combo, so this is a no-op there except still
 * opening Agent from the iframe.
 *
 * @param {KeyboardEvent} event
 */
function handleOpenHotkey(event) {
  if (controller === null || controller.isOpen) {
    return;
  }
  if (!matchesAgentHotkey(event, readHotkeyPref())) {
    return;
  }
  event.preventDefault();
  event.stopImmediatePropagation();
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

  let slot = topbar.querySelector('.nst3af-agent-launchbar-slot');
  if (!(slot instanceof HTMLElement)) {
    slot = backendDoc.createElement('div');
    slot.className = 'nst3af-agent-launchbar-slot';
    const anchor = topbar.querySelector('.topbar-button-search, .t3js-topbar-button-search');
    if (anchor instanceof HTMLElement && anchor.parentElement) {
      anchor.parentElement.insertBefore(slot, anchor);
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
  void ensureMessageRenderer();
  DocumentService.ready().then(initialize);
}

export default { boot };
