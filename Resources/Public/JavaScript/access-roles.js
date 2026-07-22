/**
 * AI Permissions — group permission wizard and matrix.
 *
 * @see EXT:ns_t3af Templates/AccessRoles/Index.html
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import { bindFilterSearchInput, observeBrowserAutocomplete } from '@nitsan/nst3af/disable-browser-autocomplete.js';

const WIZARD_STEPS = ['modules', 'features', 'records', 'limits', 'review'];
const WIZARD_STEP_LABELS = {
  modules: 'Modules',
  features: 'Features',
  records: 'Records',
  limits: 'Limits',
  review: 'Review',
};

/** Step 2 feature level → suggested Step 3 record minimums (from server bootstrap). */
const FEATURE_RECORD_DEFAULTS = [];

/**
 * @param {Record<string, string>} features
 * @param {Record<string, string>} records
 */
function applyFeatureRecordDefaults(features, records, rules) {
  const bulkEnabled = (features.bulkOps ?? 'disabled') !== 'disabled';
  const activeRules = Array.isArray(rules) && rules.length ? rules : FEATURE_RECORD_DEFAULTS;
  for (const rule of activeRules) {
    if (rule.requiresBulkOps && !bulkEnabled) {
      continue;
    }
    const level = features[rule.featureId] ?? 'disabled';
    if (level === 'disabled') {
      continue;
    }
    const target = level === 'manage' ? 'readwrite' : 'read';
    const current = records[rule.recordId] ?? 'none';
    if (current === 'none') {
      records[rule.recordId] = target;
    } else if (current === 'read' && target === 'readwrite') {
      records[rule.recordId] = 'readwrite';
    }
  }
}

/**
 * @returns {unknown}
 */
function readBootstrap() {
  const el = document.getElementById('aiu-access-roles-bootstrap');
  if (!el?.textContent) {
    return {};
  }
  try {
    return JSON.parse(el.textContent);
  } catch {
    return {};
  }
}

function deepClone(obj) {
  return JSON.parse(JSON.stringify(obj));
}

function initAccessRoles() {
  const root = document.getElementById('aiu-access-roles-root');
  if (!(root instanceof HTMLElement)) {
    return;
  }

  observeBrowserAutocomplete(root);

  try {
    initAccessRolesRoot(root);
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    root.innerHTML = `
      <div class="callout callout-danger">
        <div class="callout-content">
          <div class="callout-title">AI Permissions failed to initialize</div>
          <div class="callout-body"><p class="mb-0">${message.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</p></div>
        </div>
      </div>`;
  }
}

function initAccessRolesRoot(root) {
  const bootstrap = readBootstrap();
  /** @type {Array<{uid:number,name:string,memberCount:number,configured:boolean,status:string,moduleCount:number,parentName?:string}>} */
  let groups = Array.isArray(bootstrap.groups) ? bootstrap.groups : [];
  /** @type {Record<string, unknown>} */
  const defaultConfig = bootstrap.defaultConfig && typeof bootstrap.defaultConfig === 'object'
    ? bootstrap.defaultConfig
    : { modules: {}, features: {}, records: {}, limits: {} };
  const presets = Array.isArray(bootstrap.presets) ? bootstrap.presets : [];
  const modulesMeta = bootstrap.modules ?? {};
  const featuresMeta = Array.isArray(bootstrap.features) ? bootstrap.features : [];
  const recordsMeta = Array.isArray(bootstrap.records) ? bootstrap.records : [];
  const featureRecordDefaults = Array.isArray(bootstrap.featureRecordDefaults) ? bootstrap.featureRecordDefaults : [];
  const providers = Array.isArray(bootstrap.providers) ? bootstrap.providers : [];
  let matrix = bootstrap.matrix ?? { groups: [] };
  /** @type {Array<{id:string,label:string,accent?:string,moduleKeys?:string[],adminModuleKeys?:string[],featureIds?:string[],recordIds?:string[]}>} */
  const matrixScopes = Array.isArray(matrix.scopes) ? matrix.scopes : [];
  /** @type {string} */
  let matrixScope = matrixScopes[0]?.id ?? 'ai-universe';

  /** @type {'groups'|'matrix'} */
  let pageTab = 'groups';
  /** @type {'dashboard'|'wizard'} */
  let panelMode = 'dashboard';
  /** @type {number|null} */
  let selectedUid = typeof bootstrap.selectedGroupUid === 'number' && bootstrap.selectedGroupUid > 0
    ? bootstrap.selectedGroupUid
    : (groups[0]?.uid ?? null);
  /** @type {Record<string, unknown>} */
  let wizardConfig = deepClone(defaultConfig);
  /** @type {'preset'|string} */
  let wizardStep = bootstrap.initialStep === 'limits' ? 'limits' : 'preset';
  let showPreset = true;
  /** @type {Record<string, unknown>|null} */
  let groupDetail = null;
  /** @type {Record<string, unknown>|null} */
  let reviewPreview = null;

  const applyUrl = TYPO3.settings.ajaxUrls?.nst3af_access_roles_apply;
  const previewUrl = TYPO3.settings.ajaxUrls?.nst3af_access_roles_preview;
  const moduleLabels = modulesMeta.labels ?? {};
  const jsonPostOptions = { headers: { 'Content-Type': 'application/json' } };

  function selectedGroup() {
    return groups.find((g) => g.uid === selectedUid) ?? null;
  }

  function enabledModuleKeys() {
    return Object.entries(wizardConfig.modules ?? {})
      .filter(([, v]) => v)
      .map(([k]) => k);
  }

  function render() {
    root.innerHTML = `
      <div class="btn-group mb-3" role="group">
        <button type="button" class="btn btn-default ${pageTab === 'groups' ? 'active is-active' : ''}" data-page-tab="groups">Groups &amp; Permissions</button>
        <button type="button" class="btn btn-default ${pageTab === 'matrix' ? 'active is-active' : ''}" data-page-tab="matrix">Permission Matrix</button>
      </div>
      ${pageTab === 'matrix' ? renderMatrix() : renderGroupsLayout()}
    `;
    bindEvents();
  }

  function groupNavIcon(status) {
    if (status === 'configured') {
      return 'actions-check-circle';
    }
    if (status === 'empty') {
      return 'actions-exclamation-triangle';
    }
    return 'actions-user';
  }

  function groupNavMeta(g) {
    if (g.configured) {
      return `${g.memberCount} members · ${g.moduleCount} modules`;
    }
    return `${g.memberCount} members · Not configured yet`;
  }

  function renderGroupsLayout() {
    return `
      <div class="aiu-mcp-tools-shell aiu-ar-layout">
        <aside class="aiu-mcp-tools-shell__sidebar" aria-label="TYPO3 backend user groups">
          <div class="card card-size-small mb-0 w-100">
            <div class="card-header">
              <h3 class="card-title mb-0">TYPO3 Backend Usergroups</h3>
            </div>
            <div class="card-body">
              <a href="${root.dataset.beGroupsUrl ?? '#'}"
                 class="btn btn-default w-100 mb-3"
                 data-be-groups-add
                 target="_blank"
                 rel="noopener">
                ${backendIcon('actions-plus', 'small')}
                Add Backend Usergroup
              </a>
              <input type="search"
                     class="form-control mb-3"
                     placeholder="Find group…"
                     data-group-search
                     autocomplete="off"
                     autocorrect="off"
                     autocapitalize="off"
                     spellcheck="false"
                     data-1p-ignore="true"
                     data-lpignore="true"
                     data-form-type="other"
                     readonly="readonly"
                     aria-label="Find backend user group" />
              <nav class="aiu-mcp-tools-nav aiu-ar-group-list" data-group-list role="list">${renderGroupList()}</nav>
              <p class="aiu-mcp-tools-nav__meta mb-0 mt-3">${groups.length} groups total</p>
            </div>
          </div>
        </aside>
        <div class="aiu-mcp-tools-shell__content">
          <main class="aiu-ar-main">${renderMainPanel()}</main>
        </div>
      </div>
    `;
  }

  function renderGroupList(filter = '') {
    const q = filter.trim().toLowerCase();
    return groups
      .filter((g) => !q || g.name.toLowerCase().includes(q))
      .map((g) => {
        const active = g.uid === selectedUid ? ' active is-active' : '';
        const selected = g.uid === selectedUid ? 'true' : 'false';
        return `
          <button type="button"
                  class="aiu-mcp-tools-nav__item${active}"
                  role="listitem"
                  data-group-uid="${g.uid}"
                  aria-current="${selected}">
            ${backendIcon(groupNavIcon(g.status), 'small')}
            <span class="aiu-mcp-tools-nav__title">
              <h4 class="aiu-mcp-tools-nav__label mb-0 text-truncate">${escapeHtml(g.name)}</h4>
              <span class="aiu-mcp-tools-nav__meta">${escapeHtml(groupNavMeta(g))}</span>
            </span>
          </button>`;
      })
      .join('');
  }

  function renderMainPanel() {
    const g = selectedGroup();
    if (!g) {
      return '<p class="text-variant">Select a backend user group.</p>';
    }
    if (panelMode === 'wizard') {
      return renderWizard(g);
    }
    if (!g.configured) {
      return `
        <div class="card aiu-ar-overlay-card text-center p-5">
          <h2 class="h4">${escapeHtml(g.name)}</h2>
          <p class="text-variant">This group has not been configured for AI Foundation yet. Run the setup wizard to configure module access, feature permissions and record access.</p>
          <button type="button" class="btn btn-primary" data-start-wizard>Start Setup Wizard</button>
        </div>`;
    }
    return renderDashboard(g);
  }

  function backendIcon(identifier, size = 'small') {
    return `<typo3-backend-icon identifier="${escapeHtml(identifier)}" size="${escapeHtml(size)}"></typo3-backend-icon>`;
  }

  function presetIcon(id) {
    const map = {
      consumer: 'actions-user',
      editor: 'actions-open',
      manager: 'actions-shield',
      admin: 'actions-cog',
      administrator: 'actions-cog',
    };
    return map[id] ?? 'actions-star';
  }

  function badgeClass(tone) {
    const allowed = ['info', 'primary', 'success', 'warning', 'danger', 'default'];
    return `badge-${allowed.includes(tone) ? tone : 'info'}`;
  }

  function childModuleIcon(key, meta = {}) {
    return meta.icon ?? 'actions-extension';
  }

  function adminModuleIcon(key) {
    const map = {
      providers: 'actions-key',
      mcpServer: 'actions-server',
      mcpTools: 'actions-cog',
      aiFeatures: 'actions-star',
      aiUsage: 'actions-extension',
      aiPrompts: 'actions-message',
      schedulerCli: 'actions-calendar',
      aiContext: 'actions-lightbulb-on',
      aiLogs: 'actions-list-alternative',
    };
    return map[key] ?? 'actions-cog';
  }

  function renderModuleToggleCard(key, { title, badge, description, icon, checked, compact = false }) {
    const active = checked ? ' is-active' : '';
    const checkedAttr = checked ? ' checked' : '';
    const toggleHtml = '<span class="aiu-wizard__ext-toggle flex-shrink-0" aria-hidden="true"><span class="aiu-wizard__ext-toggle-track"></span></span>';

    if (compact) {
      return `
      <label class="card card-size-small aiu-wizard__ext-card${active} mb-0 w-100 text-start">
        <input type="checkbox" class="aiu-wizard__ext-input" data-module-key="${escapeHtml(key)}"${checkedAttr} />
        <span class="card-body d-flex align-items-center gap-2 py-2">
          <span class="aiu-wizard__ext-icon flex-shrink-0" aria-hidden="true">${backendIcon(icon, 'small')}</span>
          <span class="fw-semibold flex-grow-1 min-w-0 text-truncate">${escapeHtml(title)}</span>
          ${toggleHtml}
        </span>
      </label>`;
    }

    const badgeHtml = badge ? `<span class="badge badge-default flex-shrink-0">${escapeHtml(badge)}</span>` : '';
    const titleRow = badge
      ? `<span class="d-flex align-items-start justify-content-between gap-2"><span class="fw-semibold">${escapeHtml(title)}</span>${badgeHtml}</span>`
      : `<span class="fw-semibold d-block">${escapeHtml(title)}</span>`;
    const descHtml = description
      ? `<span class="text-variant small d-block mt-1">${escapeHtml(description)}</span>`
      : '';

    return `
      <label class="card card-size-small aiu-wizard__ext-card aiu-ar-module-card${active} mb-0 w-100 text-start">
        <input type="checkbox" class="aiu-wizard__ext-input" data-module-key="${escapeHtml(key)}"${checkedAttr} />
        <span class="card-body d-flex align-items-start gap-1 py-3">
          <span class="aiu-wizard__ext-copy flex-grow-1 min-w-0">
            ${titleRow}
            ${descHtml}
          </span>
        </span>
      </label>`;
  }

  function renderCalloutNotice(message) {
    return `
      <div class="callout callout-notice mb-3">
        <div class="callout-content">
          <div class="callout-body"><p class="mb-0">${escapeHtml(message)}</p></div>
        </div>
      </div>`;
  }

  function boolBadge(enabled, onLabel = 'On', offLabel = 'Off') {
    return enabled
      ? `<span class="badge badge-success">${escapeHtml(onLabel)}</span>`
      : `<span class="badge badge-default">${escapeHtml(offLabel)}</span>`;
  }

  function enabledCapBadge(enabled, value, suffix = '') {
    return enabled
      ? `<span class="badge badge-info">${escapeHtml(String(value))}${escapeHtml(suffix)}</span>`
      : '<span class="text-variant">Unlimited</span>';
  }

  function renderLimitsSummary(limits = {}) {
    const providerValue = limits.providerAllowlistEnabled
      ? ((limits.allowedProviders ?? []).length
        ? (limits.allowedProviders ?? []).map((provider) => `<span class="badge badge-default me-1 mb-1">${escapeHtml(provider)}</span>`).join('')
        : '<span class="text-variant">—</span>')
      : boolBadge(false, 'Enabled', 'Disabled');

    const rows = [
      ['Provider allowlist', providerValue],
      ['Model override', boolBadge(!!limits.allowModelOverride, 'Allowed', 'Locked')],
      ['Monthly credit cap', enabledCapBadge(!!limits.creditCapEnabled, limits.creditCapMonthly ?? 0, ' credits')],
      ['Daily request cap', enabledCapBadge(!!limits.dailyRequestCapEnabled, limits.dailyRequestCap ?? 0, ' / day')],
      ['Bulk page limit', enabledCapBadge(!!limits.bulkPageLimitEnabled, limits.bulkPageLimit ?? 0, ' pages')],
      ['Scheduler batch limit', enabledCapBadge(!!limits.schedulerBatchLimitEnabled, limits.schedulerBatchLimit ?? 0, ' rows')],
      ['Workspace enforcement', boolBadge(!!limits.workspaceEnforcement)],
      ['PII masking', boolBadge(!!limits.piiMasking)],
      ['Quality threshold', limits.qualityThresholdEnabled
        ? `<span class="badge badge-info">${escapeHtml(String(limits.qualityThresholdScore ?? 70))}%</span>`
        : boolBadge(false)],
      ['Logging policy', `<span class="badge badge-default">${escapeHtml(String(limits.loggingPolicy ?? 'always'))}</span>`],
      ['Log retention', `${escapeHtml(String(limits.logRetentionDays ?? 30))} days`],
    ];

    if (limits.lockedContextProfile) {
      rows.push(['Locked context profile', `<code>${escapeHtml(limits.lockedContextProfile)}</code>`]);
    }
    if (limits.requiredBrandVoice) {
      rows.push(['Required brand voice', `<code>${escapeHtml(limits.requiredBrandVoice)}</code>`]);
    }

    return `
      <ul class="list-group list-group-flush aiu-ar-summary-list mb-0">
        ${rows.map(([label, value]) => `
          <li class="list-group-item d-flex align-items-center justify-content-between gap-3 px-0 py-2">
            <span class="list-group-item-label">${escapeHtml(label)}</span>
            <span class="flex-shrink-0 text-end">${value}</span>
          </li>
        `).join('')}
      </ul>`;
  }

  function renderDashboard(g) {
    const cfg = groupDetail?.config ?? wizardConfig;
    const alerts = groupDetail?.healthAlerts ?? [];
    return `
      <div class="card mb-3">
        <div class="card-header">
          <div class="card-icon">${backendIcon('actions-user', 'medium')}</div>
          <div class="card-header-body">
            <div class="card-header-body__top d-flex align-items-start justify-content-between gap-2">
              <div class="min-w-0">
                <h2 class="card-title mb-0 text-truncate">${escapeHtml(g.name)}</h2>
                <span class="card-subtitle">${g.memberCount} members · ${g.moduleCount} modules configured</span>
              </div>
              <button type="button" class="btn btn-default flex-shrink-0" data-reconfigure>Re-configure</button>
            </div>
          </div>
        </div>
      </div>
      ${alerts.map((a) => renderCalloutNotice(a)).join('')}
      <div class="row g-3 mb-3">
        <div class="col-12 col-lg-5 col-xl-4 d-flex">
          <div class="card w-100 mb-0">
            <div class="card-header">
              <div class="card-header-body">
                <h3 class="card-title mb-0">AI Safety &amp; Limits</h3>
              </div>
            </div>
            <div class="card-body">${renderLimitsSummary(cfg.limits ?? {})}</div>
          </div>
        </div>
        <div class="col-12 col-lg-7 col-xl-8">
          <div class="card mb-3">
            <div class="card-header">
              <div class="card-header-body">
                <h3 class="card-title mb-0">Module Access</h3>
              </div>
            </div>
            <div class="card-body">${renderModuleSummary(cfg)}</div>
          </div>
          <div class="card mb-3">
            <div class="card-header">
              <div class="card-header-body">
                <h3 class="card-title mb-0">AI Feature Permissions</h3>
                <span class="card-subtitle">be_groups.custom_options (T3Ai)</span>
              </div>
            </div>
            <div class="card-body">${renderFeatureSummary(cfg)}</div>
          </div>
          <div class="card mb-0">
            <div class="card-header">
              <div class="card-header-body">
                <h3 class="card-title mb-0">Record Permissions</h3>
                <span class="card-subtitle">be_groups.tables_select · tables_modify</span>
              </div>
            </div>
            <div class="card-body">${renderRecordSummary(cfg)}</div>
          </div>
        </div>
      </div>
      ${renderScopeSummary()}`;
  }

  function moduleLabel(key) {
    return moduleLabels[key] ?? key;
  }

  function featureLabel(id) {
    return featureMetaById(id)?.label ?? id;
  }

  function recordLabel(id) {
    return recordMetaById(id)?.label ?? id;
  }

  function activeFeatures(cfg) {
    const mods = cfg.modules ?? {};
    const feats = cfg.features ?? {};
    return featuresMeta
      .filter((feature) => feature.relevantModules?.some((moduleKey) => mods[moduleKey]))
      .map((feature) => ({
        id: feature.id,
        label: feature.label,
        level: feats[feature.id] ?? 'disabled',
        type: feature.type ?? 'level',
      }))
      .filter((row) => row.level && row.level !== 'disabled');
  }

  function activeRecords(cfg) {
    const mods = cfg.modules ?? {};
    const feats = cfg.features ?? {};
    const recs = cfg.records ?? {};
    return recordsMeta
      .filter((record) => isRecordAllowed(record, mods, feats))
      .map((record) => ({
        id: record.id,
        label: record.label,
        level: recs[record.id] ?? 'none',
      }))
      .filter((row) => row.level && row.level !== 'none');
  }

  function featureLevelBadgeHtml(level, featureId = '', compact = false) {
    if (!level || level === 'disabled') {
      return '<span class="badge badge-default">Off</span>';
    }
    if (featureId === 'bulkOps') {
      const bulkMap = {
        scoped: ['Scoped', 'badge-info'],
        any: ['Any page', 'badge-warning'],
      };
      const entry = bulkMap[level];
      if (!entry) {
        return '<span class="badge badge-default">Off</span>';
      }
      return `<span class="badge ${entry[1]}">${escapeHtml(entry[0])}</span>`;
    }
    const levelMap = {
      use: [compact ? 'Use' : 'Use', 'badge-info'],
      manage: [compact ? 'Mgr' : 'Manage', 'badge-primary'],
    };
    const entry = levelMap[level] ?? ['On', 'badge-info'];
    return `<span class="badge ${entry[1]}">${escapeHtml(entry[0])}</span>`;
  }

  function recordAccessBadgeHtml(level) {
    if (!level || level === 'none') {
      return '<span class="badge badge-default">No access</span>';
    }
    if (level === 'readwrite') {
      return '<span class="badge badge-success">Read &amp; Write</span>';
    }
    if (level === 'read') {
      return '<span class="badge badge-info">Read</span>';
    }
    return '<span class="badge badge-default">No access</span>';
  }

  function scopeFieldValue(raw) {
    const value = String(raw ?? '').trim();
    if (value === '') {
      return '<span class="badge badge-success">AUTO</span>';
    }
    return `<code class="small">${escapeHtml(value)}</code>`;
  }

  function renderScopeDisplay(scope, rawFallback = '') {
    if (scope && typeof scope === 'object') {
      if (scope.auto) {
        return '<span class="badge badge-success">AUTO</span>';
      }
      if (Array.isArray(scope.items) && scope.items.length) {
        return scope.items.map((item) =>
          `<span class="badge badge-default me-1 mb-1">${escapeHtml(item.label ?? String(item.uid ?? ''))}</span>`,
        ).join('');
      }
      if (scope.display) {
        return `<span class="aiu-ar-scope-label">${escapeHtml(scope.display)}</span>`;
      }
      return '<span class="text-variant">—</span>';
    }
    return scopeFieldValue(rawFallback);
  }

  function groupScope() {
    return groupDetail?.scope ?? null;
  }

  function renderScopeSummary() {
    const scope = groupScope();
    return `
      <div class="row g-3 mb-0">
        <div class="col-md-6">
          <div class="card h-100 mb-0">
            <div class="card-header">
              <div class="card-header-body">
                <h3 class="card-title mb-0">Page Scope</h3>
                <span class="card-subtitle">db_mountpoints — not modified by this wizard</span>
              </div>
            </div>
            <div class="card-body">
              <p class="mb-1 small text-variant">DB mountpoints</p>
              <div>${renderScopeDisplay(scope?.pageScope, groupDetail?.dbMountpoints ?? '')}</div>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card h-100 mb-0">
            <div class="card-header">
              <div class="card-header-body">
                <h3 class="card-title mb-0">Language Scope</h3>
                <span class="card-subtitle">allowed_languages — not modified by this wizard</span>
              </div>
            </div>
            <div class="card-body">
              <p class="mb-1 small text-variant">Allowed languages</p>
              <div>${renderScopeDisplay(scope?.languageScope, groupDetail?.allowedLanguages ?? '')}</div>
            </div>
          </div>
        </div>
      </div>`;
  }

  function isRecordAllowed(record, mods, features) {
    const moduleOk = record.relevantModules?.some((moduleKey) => mods[moduleKey]);
    if (!moduleOk) {
      return false;
    }
    const requiredFeatures = record.relevantFeatures ?? [];
    if (requiredFeatures.length === 0) {
      return true;
    }
    return requiredFeatures.some((featureId) => {
      const level = features[featureId] ?? 'disabled';
      return level !== 'disabled';
    });
  }

  function normalizeWizardConfig() {
    const mods = { ...(defaultConfig.modules ?? {}), ...(wizardConfig.modules ?? {}) };
    wizardConfig.modules = mods;

    for (const feature of featuresMeta) {
      const allowed = feature.relevantModules?.some((moduleKey) => mods[moduleKey]);
      if (!wizardConfig.features) {
        wizardConfig.features = {};
      }
      if (!allowed) {
        wizardConfig.features[feature.id] = 'disabled';
      }
    }

    const feats = { ...(defaultConfig.features ?? {}), ...(wizardConfig.features ?? {}) };

    for (const record of recordsMeta) {
      const allowed = isRecordAllowed(record, mods, feats);
      if (!wizardConfig.records) {
        wizardConfig.records = {};
      }
      if (!allowed) {
        wizardConfig.records[record.id] = 'none';
      }
    }

    applyFeatureRecordDefaults(feats, wizardConfig.records ?? {}, featureRecordDefaults);
  }

  function renderModuleSummary(cfg) {
    const mods = cfg.modules ?? {};
    return Object.entries(mods)
      .filter(([, on]) => on)
      .map(([k]) => `<span class="badge badge-success me-1 mb-1">${escapeHtml(moduleLabel(k))}</span>`)
      .join('') || '<span class="text-variant">None</span>';
  }

  function renderFeatureSummary(cfg) {
    const rows = activeFeatures(cfg);
    if (!rows.length) {
      return '<p class="text-variant mb-0">None</p>';
    }
    return `
      <div class="row g-0 aiu-ar-perm-grid">
        ${rows.map((row) => `
          <div class="col-md-6">
            <div class="aiu-ar-perm-row d-flex align-items-center justify-content-between gap-2">
              <span class="aiu-ar-perm-label text-truncate">${escapeHtml(row.label)}</span>
              <span class="flex-shrink-0">${featureLevelBadgeHtml(row.level, row.id)}</span>
            </div>
          </div>`).join('')}
      </div>`;
  }

  function renderRecordSummary(cfg) {
    const rows = activeRecords(cfg);
    if (!rows.length) {
      return '<p class="text-variant mb-0">None configured</p>';
    }
    return `
    <table class="table table-sm table-striped table-hover table-bordered align-middle mb-0 aiu-ar-record-summary">
      <thead>
        <tr>
          <th>Table</th>
          <th class="text-end">Access level</th>
        </tr>
      </thead>
      <tbody>
        ${rows.map((row) => `
          <tr>
            <td>${escapeHtml(row.label)}</td>
            <td class="text-end">${recordAccessBadgeHtml(row.level)}</td>
          </tr>`).join('')}
      </tbody>
    </table>`;
  }

  function renderWizard(g) {
    if (showPreset && wizardStep === 'preset') {
      return `
        <div class="card">
          <div class="card-header">
            <div class="card-icon">${backendIcon('actions-star', 'medium')}</div>
            <div class="card-header-body">
              <div class="d-flex align-items-start justify-content-between gap-2">
                <div class="min-w-0">
                  <h2 class="card-title mb-0">Start from a preset</h2>
                  <span class="card-subtitle">Pick a role template — the wizard pre-fills all 5 steps. You can review and change anything before applying.</span>
                </div>
                <button type="button" class="btn btn-success flex-shrink-0" data-preset-blank>${backendIcon('actions-plus', 'small')} Start blank</button>
              </div>
            </div>
          </div>
          <div class="card-body">
            <div class="row g-3">${presets.map((p) => `
              <div class="col-md-6 d-flex">
                <button type="button" class="card card-size-small w-100 text-start aiu-ar-preset-card mb-0" data-preset-id="${escapeHtml(p.id)}">
                  <div class="card-header">
                    <div class="card-icon">${backendIcon(presetIcon(p.id), 'medium')}</div>
                    <div class="card-header-body">
                      <div class="d-flex align-items-center justify-content-between gap-2">
                        <h3 class="card-title mb-0">${escapeHtml(p.label)}</h3>
                        ${p.badge ? `<span class="badge ${badgeClass(p.badgeTone)} flex-shrink-0">${escapeHtml(p.badge)}</span>` : ''}
                      </div>
                      <span class="card-subtitle">${escapeHtml(p.tagline ?? '')}</span>
                    </div>
                  </div>
                </button>
              </div>`).join('')}
            </div>
          </div>
        </div>`;
    }

    const stepIndex = WIZARD_STEPS.indexOf(wizardStep);
    return `
      <div class="card">
        <div class="card-header">
          <div class="card-header-body">
            <div class="d-flex align-items-center justify-content-between gap-2">
              <h2 class="card-title text-truncate mb-0">Configuring: ${escapeHtml(g.name)}</h2>
              <button type="button" class="btn btn-default flex-shrink-0" data-wizard-cancel>Cancel</button>
            </div>
            <span class="card-subtitle">${g.memberCount} members · setup wizard</span>
          </div>
        </div>
        <div class="card-body">
          <nav class="aiu-ar-steps d-flex flex-wrap gap-1 mb-4" aria-label="Setup wizard steps">${WIZARD_STEPS.map((s, i) => {
    const done = i < stepIndex;
    const active = s === wizardStep;
    const state = active ? 'is-active active' : (done ? 'is-complete' : '');
    const ariaCurrent = active ? ' aria-current="step"' : '';
    return `<span class="btn btn-default btn-sm aiu-ar-step ${state}"${ariaCurrent}><span class="aiu-ar-step__num" aria-hidden="true">${i + 1}</span><span class="aiu-ar-step__label">${escapeHtml(WIZARD_STEP_LABELS[s] ?? s)}</span></span>`;
  }).join('')}</nav>
          ${renderWizardStepContent()}
        </div>
        <div class="card-footer d-flex justify-content-between">
          <button type="button" class="btn btn-default" data-wizard-back ${stepIndex <= 0 ? 'disabled' : ''}>Back</button>
          <button type="button" class="btn btn-primary" data-wizard-next>${wizardStep === 'review' ? 'Apply to ' + escapeHtml(g.name) : 'Next'}</button>
        </div>
      </div>`;
  }

  function renderWizardStepContent() {
    if (wizardStep === 'modules') {
      return renderStepModules();
    }
    if (wizardStep === 'features') {
      return renderStepFeatures();
    }
    if (wizardStep === 'records') {
      return renderStepRecords();
    }
    if (wizardStep === 'limits') {
      return renderStepLimits();
    }
    if (wizardStep === 'review') {
      return renderStepReview();
    }
    return '';
  }

  function renderStepModules() {
    const child = modulesMeta.child ?? {};
    const admin = modulesMeta.admin ?? {};
    const mods = wizardConfig.modules ?? {};
    let html = '<h3 class="aiu-ar-section-title">AI Child Extensions</h3><div class="row g-3 mb-3">';
    for (const [key, meta] of Object.entries(child)) {
      const on = !!mods[key];
      html += `
        <div class="col-md-6 col-xl-4 d-flex">
          ${renderModuleToggleCard(key, {
    title: meta.sublabel ?? meta.label,
    badge: meta.label,
    description: meta.description ?? meta.sublabel ?? '',
    icon: childModuleIcon(key, meta),
    checked: on,
  })}
        </div>`;
    }
    html += '</div><h3 class="aiu-ar-section-title">AI Foundation Management Modules</h3><div class="row g-2">';
    for (const [key, meta] of Object.entries(admin)) {
      const on = !!mods[key];
      html += `
        <div class="col-md-6 col-xl-4 d-flex">
          ${renderModuleToggleCard(key, {
    title: meta.label,
    description: meta.description ?? '',
    icon: adminModuleIcon(key),
    checked: on,
    compact: true,
  })}
        </div>`;
    }
    html += '</div>';
    return html;
  }

  function renderStepFeatures() {
    const enabled = enabledModuleKeys();
    const byModule = {};
    for (const feature of featuresMeta) {
      if (!feature.relevantModules?.some((moduleKey) => enabled.includes(moduleKey))) {
        continue;
      }
      const groupKey = feature.relevantModules.find((moduleKey) => enabled.includes(moduleKey)) ?? 'other';
      if (!byModule[groupKey]) {
        byModule[groupKey] = [];
      }
      byModule[groupKey].push(feature);
    }
    const groups = Object.entries(byModule);
    if (!groups.length) {
      return '<p class="text-variant">No child extensions selected — go back to step 1.</p>';
    }
    const feats = wizardConfig.features ?? {};
    return groups.map(([moduleKey, rows]) => `
      <div class="aiu-ar-feature-group mb-4">
        <h3 class="aiu-ar-section-title aiu-ar-feature-group__title">${escapeHtml(moduleLabel(moduleKey))}</h3>
        <div class="aiu-ar-feature-list">
          ${rows.map((feature) => {
            const val = feats[feature.id] ?? 'disabled';
            const isBulk = feature.type === 'bulk';
            const options = isBulk
              ? ['disabled', 'scoped', 'any']
              : ['disabled', 'use', 'manage'];
            const optionLabels = { disabled: 'Off', use: 'Use', manage: 'Manage', scoped: 'Scoped', any: 'Any' };
            const manageHint = !isBulk
              ? '<span class="small text-variant d-block mt-1">Use = feature access with Read record defaults. Manage = Read &amp; Write on related Step 3 records.</span>'
              : '';
            return `
              <div class="aiu-ar-feature-row">
                <div class="aiu-ar-feature-row__label">
                  <strong>${escapeHtml(feature.label)}</strong>
                  ${feature.description ? `<span class="small text-variant d-block">${escapeHtml(feature.description)}</span>` : ''}
                  ${manageHint}
                </div>
                <div class="btn-group btn-group-sm aiu-ar-level-picker" role="group" aria-label="${escapeHtml(feature.label)} level">
                  ${options.map((option) =>
                    `<button type="button" class="btn btn-default ${val === option ? 'active is-active' : ''}" data-feature-id="${feature.id}" data-feature-val="${option}">${optionLabels[option] ?? option}</button>`,
                  ).join('')}
                </div>
              </div>`;
          }).join('')}
        </div>
      </div>`).join('');
  }

  function renderStepRecords() {
    const enabled = enabledModuleKeys();
    const feats = { ...(defaultConfig.features ?? {}), ...(wizardConfig.features ?? {}) };
    const rows = recordsMeta.filter(
      (r) => r.relevantModules?.some((m) => enabled.includes(m)) && isRecordAllowed(r, wizardConfig.modules ?? {}, feats),
    );
    if (!rows.length) {
      return '<p class="text-variant">No relevant tables — select modules in step 1 first.</p>';
    }
    const recs = wizardConfig.records ?? {};
    const recordLabels = { none: 'None', read: 'Read', readwrite: 'Read/Write' };
    return `
      <h3 class="aiu-ar-section-title">Record access</h3>
      <div class="table-fit">
        <table class="table table-striped table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Table</th>
              <th class="text-end">Access</th>
            </tr>
          </thead>
          <tbody>${rows.map((r) => {
    const val = recs[r.id] ?? 'none';
    return `<tr><td>${escapeHtml(r.label)}</td><td class="text-end"><div class="btn-group btn-group-sm" role="group" aria-label="${escapeHtml(r.label)} access">${['none', 'read', 'readwrite'].map((o) =>
      `<button type="button" class="btn btn-default ${val === o ? 'active is-active' : ''}" data-record-id="${r.id}" data-record-val="${o}">${recordLabels[o]}</button>`,
    ).join('')}</div></td></tr>`;
  }).join('')}</tbody>
        </table>
      </div>`;
  }

  function renderStepLimits() {
    const l = wizardConfig.limits ?? {};
    return `
      <h3 class="aiu-ar-section-title">Governance &amp; limits</h3>
      <div class="row g-3">
        <div class="col-md-6">
          <div class="aiu-ar-limit-tile h-100">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" id="ws" data-limit-key="workspaceEnforcement" ${l.workspaceEnforcement ? 'checked' : ''} />
              <label class="form-check-label" for="ws">Workspace enforcement</label>
            </div>
            <p class="form-text mb-0">Restrict members to their assigned workspace mountpoints.</p>
          </div>
        </div>
        <div class="col-md-6">
          <div class="aiu-ar-limit-tile h-100">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" id="cc" data-limit-enabled="creditCapEnabled" ${l.creditCapEnabled ? 'checked' : ''} />
              <label class="form-check-label" for="cc">Monthly credit cap</label>
            </div>
            <span class="small text-variant d-block mb-2">Each member of this group may use up to this many T3Planet credits per calendar month. Counted per editor.</span>
            <div class="input-group">
              <input type="number" min="0" class="form-control" data-limit-key="creditCapMonthly" value="${l.creditCapMonthly ?? 500}" aria-label="Monthly credit cap" />
              <span class="input-group-text">credits / month</span>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="aiu-ar-limit-tile h-100">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" id="dr" data-limit-enabled="dailyRequestCapEnabled" ${l.dailyRequestCapEnabled ? 'checked' : ''} />
              <label class="form-check-label" for="dr">Daily request limit</label>
            </div>
            <span class="small text-variant d-block mb-2">Each member may make up to this many AI requests per calendar day. Counted per editor.</span>
            <div class="input-group">
              <input type="number" min="0" class="form-control" data-limit-key="dailyRequestCap" value="${l.dailyRequestCap ?? 100}" aria-label="Daily request limit" />
              <span class="input-group-text">requests / day</span>
            </div>
          </div>
        </div>
      </div>`;
  }

  function renderStepReview() {
    const mods = Object.entries(wizardConfig.modules ?? {}).filter(([, enabled]) => enabled);
    const featRows = activeFeatures(wizardConfig);
    const recRows = activeRecords(wizardConfig);
    const preview = reviewPreview;

    const listBlock = (title, items, emptyLabel = '—') => {
      const heading = items?.length
        ? `<h4 class="aiu-ar-section-title mb-1">${escapeHtml(title)} <span class="badge badge-default">${items.length}</span></h4>`
        : `<h4 class="aiu-ar-section-title mb-1">${escapeHtml(title)}</h4>`;
      const body = items?.length
        ? `<ul class="mb-0 ps-3">${items.map((item) => `<li><code>${escapeHtml(item)}</code></li>`).join('')}</ul>`
        : `<p class="small text-variant mb-0">${emptyLabel}</p>`;
      return `<div class="col-md-6"><div class="aiu-ar-review-block h-100">${heading}${body}</div></div>`;
    };

    const summaryCard = (title, count, rows) => {
      const body = rows?.length
        ? `<ul class="list-group list-group-flush aiu-ar-summary-list mb-0">${rows.map((row) => `
            <li class="list-group-item d-flex align-items-center justify-content-between gap-2 px-3 py-2">
              <span class="min-w-0 text-truncate">${row.label}</span>
              ${row.badge ? `<span class="flex-shrink-0">${row.badge}</span>` : ''}
            </li>`).join('')}</ul>`
        : '<div class="card-body"><span class="text-variant">—</span></div>';
      return `
        <div class="col-md-6 col-xl-4 d-flex">
          <div class="card w-100 mb-0">
            <div class="card-header">
              <div class="card-header-body">
                <div class="d-flex align-items-center justify-content-between gap-2">
                  <h3 class="card-title mb-0">${escapeHtml(title)}</h3>
                  <span class="badge badge-default flex-shrink-0">${count}</span>
                </div>
              </div>
            </div>
            ${body}
          </div>
        </div>`;
    };

    return `
      <div class="callout callout-info mb-3">
        <div class="callout-content">
          <div class="callout-body"><p class="mb-0">Review the configuration below, then click Apply to merge permissions into the backend user group.</p></div>
        </div>
      </div>
      <div class="row g-3 mb-3">
        ${summaryCard('Modules', mods.length, mods.map(([key]) => ({ label: escapeHtml(moduleLabel(key)) })))}
        ${summaryCard('Features', featRows.length, featRows.map((row) => ({ label: escapeHtml(row.label), badge: featureLevelBadgeHtml(row.level, row.id) })))}
        ${summaryCard('Records', recRows.length, recRows.map((row) => ({ label: escapeHtml(row.label), badge: recordAccessBadgeHtml(row.level) })))}
      </div>
      <div class="card mb-3">
        <div class="card-header">
          <div class="card-header-body">
            <h3 class="card-title mb-0">Page &amp; language scope</h3>
            <span class="card-subtitle">From be_groups — not changed by this wizard</span>
          </div>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <p class="mb-1 small text-variant">DB mountpoints</p>
              <div>${renderScopeDisplay(groupScope()?.pageScope, groupDetail?.dbMountpoints ?? '')}</div>
            </div>
            <div class="col-md-6">
              <p class="mb-1 small text-variant">Allowed languages</p>
              <div>${renderScopeDisplay(groupScope()?.languageScope, groupDetail?.allowedLanguages ?? '')}</div>
            </div>
          </div>
        </div>
      </div>
      <div class="card mb-3">
        <div class="card-header">
          <div class="card-header-body">
            <h3 class="card-title mb-0">be_groups fields written on Apply</h3>
            <span class="card-subtitle">Merged with existing group ACL</span>
          </div>
        </div>
        <div class="card-body">
          ${preview ? `
            <div class="row g-3">
              ${listBlock('groupMods', preview.groupMods)}
              ${listBlock('custom_options', preview.customOptions)}
              ${listBlock('tables_select', preview.tablesSelect)}
              ${listBlock('tables_modify', preview.tablesModify)}
            </div>
          ` : '<p class="small text-variant mb-0">Loading preview…</p>'}
        </div>
      </div>
      <div class="card mb-0">
        <div class="card-header"><div class="card-header-body"><h3 class="card-title mb-0">Limits</h3></div></div>
        <div class="card-body">${renderLimitsSummary(wizardConfig.limits ?? {})}</div>
      </div>`;
  }

  function scopeDefinition(scopeId) {
    return matrixScopes.find((scope) => scope.id === scopeId) ?? null;
  }

  function matrixModuleKeys(scope) {
    if (scope === 'ai-universe') {
      return [];
    }
    const def = scopeDefinition(scope);
    if (def?.moduleKeys?.length) {
      return def.moduleKeys;
    }
    return [scope];
  }

  function matrixAdminModuleKeys() {
    const def = scopeDefinition('ai-universe');
    if (def?.adminModuleKeys?.length) {
      return def.adminModuleKeys;
    }
    return ['providers', 'mcpServer', 'mcpTools', 'aiContext', 'aiFeatures', 'aiUsage', 'aiPrompts', 'schedulerCli'];
  }

  function matrixFeatureKeys(scope) {
    const def = scopeDefinition(scope);
    return def?.featureIds ?? [];
  }

  function matrixRecordKeys(scope) {
    const def = scopeDefinition(scope);
    if (def?.recordIds?.length) {
      return def.recordIds;
    }
    const modKeys = matrixModuleKeys(scope);
    if (!modKeys.length) {
      return [];
    }
    return recordsMeta
      .filter((record) => record.relevantModules?.some((moduleKey) => modKeys.includes(moduleKey)))
      .map((record) => record.id);
  }

  function matrixScopeMeta() {
    return matrixScopes.length
      ? matrixScopes.map((scope) => ({
        id: scope.id,
        label: scope.label,
        accent: scope.accent ?? '#64748b',
      }))
      : [{ id: 'ai-universe', label: 'AI Foundation', accent: '#1a56db' }];
  }

  function featureMetaById(id) {
    return featuresMeta.find((feature) => feature.id === id);
  }

  function recordMetaById(id) {
    return recordsMeta.find((record) => record.id === id);
  }

  function matrixShortLabel(text, maxLen = 14) {
    const value = String(text ?? '').trim();
    if (value.length <= maxLen) {
      return value;
    }
    return value.slice(0, maxLen - 1) + '…';
  }

  function matrixCellDash() {
    return '<span class="aiu-ar-matrix-cell aiu-ar-matrix-cell--none" aria-label="No access">—</span>';
  }

  function matrixCheckMarkHtml(title = 'On') {
    return `<span class="aiu-ar-matrix-check" title="${escapeHtml(title)}" aria-label="${escapeHtml(title)}" role="img">${backendIcon('actions-check', 'small')}</span>`;
  }

  function matrixCellOn(title = 'On') {
    return matrixCheckMarkHtml(title);
  }

  function matrixFeatureBadge(level, featureId) {
    if (!level || level === 'disabled') {
      return matrixCellDash();
    }
    if (featureId === 'bulkOps') {
      const bulkMap = {
        scoped: ['Scoped', 'badge-warning'],
        any: ['Any page', 'badge-danger'],
      };
      const entry = bulkMap[level];
      if (!entry) {
        return matrixCellDash();
      }
      return `<span class="badge ${entry[1]}">${escapeHtml(entry[0])}</span>`;
    }
    const levelMap = {
      use: ['Use', 'badge-info'],
      manage: ['Mgr', 'badge-primary'],
    };
    const entry = levelMap[level] ?? ['On', 'badge-info'];
    return `<span class="badge ${entry[1]}">${escapeHtml(entry[0])}</span>`;
  }

  function matrixRecordBadge(level) {
    if (!level || level === 'none') {
      return matrixCellDash();
    }
    if (level === 'readwrite') {
      return '<span class="badge badge-success">R+W</span>';
    }
    if (level === 'read') {
      return '<span class="badge badge-info">Read</span>';
    }
    return matrixCellDash();
  }

  function matrixAuditBadge(policy) {
    const map = {
      always: ['Always', 'badge-success'],
      errors: ['Errors', 'badge-info'],
      disabled: ['Off', 'badge-danger'],
    };
    const entry = map[policy] ?? map.always;
    return `<span class="badge ${entry[1]}">${escapeHtml(entry[0])}</span>`;
  }

  function matrixCreditsCell(row) {
    if (!row.configured) {
      return matrixCellDash();
    }
    const limits = row.limits ?? {};
    if (limits.creditCapEnabled) {
      return `<span class="badge badge-warning">${escapeHtml(String(limits.creditCapMonthly ?? 0))}</span>`;
    }
    return '<span class="aiu-ar-matrix-cell aiu-ar-matrix-cell--muted" title="Unlimited">∞</span>';
  }

  function matrixAdminModuleCell(row, moduleKey) {
    if (!row.configured) {
      return matrixCellDash();
    }
    return row.modules?.[moduleKey] ? matrixCellOn('Module enabled') : matrixCellDash();
  }

  function matrixChildModuleCell(row, moduleKey) {
    if (!row.configured) {
      return matrixCellDash();
    }
    return row.modules?.[moduleKey] ? matrixCellOn('Extension enabled') : matrixCellDash();
  }

  function matrixFeatureCell(row, featureId, moduleKey) {
    if (!row.configured) {
      return matrixCellDash();
    }
    const moduleOff = moduleKey && !row.modules?.[moduleKey];
    const level = row.features?.[featureId] ?? 'disabled';
    const inner = matrixFeatureBadge(level, featureId);
    return moduleOff
      ? `<span class="aiu-ar-matrix-cell aiu-ar-matrix-cell--dim">${inner}</span>`
      : inner;
  }

  function matrixRecordCell(row, recordId, moduleKey) {
    if (!row.configured) {
      return matrixCellDash();
    }
    const moduleOff = moduleKey && !row.modules?.[moduleKey];
    const level = row.records?.[recordId] ?? 'none';
    const inner = matrixRecordBadge(level);
    return moduleOff
      ? `<span class="aiu-ar-matrix-cell aiu-ar-matrix-cell--dim">${inner}</span>`
      : inner;
  }

  function renderMatrixGroupCell(row) {
    const subgroup = row.subgroupOf > 0 || row.parentName
      ? `<span class="aiu-ar-matrix-subgroup">subgroup</span>`
      : '';
    const notConfigured = !row.configured
      ? '<span class="badge badge-warning">Not configured</span>'
      : '';
    return `
      <td class="aiu-ar-matrix-group">
        <strong>${escapeHtml(row.name)}</strong>
        ${subgroup}
        ${notConfigured}
      </td>
      <td class="aiu-ar-matrix-members text-variant">${row.memberCount}</td>`;
  }

  function renderMatrixAiUniverseTable(rows) {
    const adminKeys = matrixAdminModuleKeys();
    const adminCount = adminKeys.length;
    const safetyCount = 3;
    const headerRow1 = `
      <tr class="aiu-ar-matrix-head-row">
        <th colspan="2" class="aiu-ar-matrix-section aiu-ar-matrix-section--group">Group</th>
        <th colspan="${adminCount}" class="aiu-ar-matrix-section aiu-ar-matrix-section--admin">Admin Modules</th>
        <th colspan="${safetyCount}" class="aiu-ar-matrix-section aiu-ar-matrix-section--safety">AI Safety</th>
      </tr>
      <tr class="aiu-ar-matrix-col-row">
        <th>Name</th>
        <th>Members</th>
        ${adminKeys.map((key) => `<th class="aiu-ar-matrix-col" title="${escapeHtml(moduleLabel(key))}">${escapeHtml(matrixShortLabel(moduleLabel(key), 16).toUpperCase())}</th>`).join('')}
        <th class="aiu-ar-matrix-col">Credits</th>
        <th class="aiu-ar-matrix-col">Workspace</th>
        <th class="aiu-ar-matrix-col">Audit</th>
      </tr>`;

    const body = rows.map((row) => {
      const limits = row.limits ?? {};
      const workspace = row.configured && limits.workspaceEnforcement ? matrixCellOn('Workspace enforced') : matrixCellDash();
      const audit = row.configured ? matrixAuditBadge(limits.loggingPolicy ?? 'always') : matrixCellDash();
      return `
        <tr class="${row.configured ? '' : 'aiu-ar-dim'}">
          ${renderMatrixGroupCell(row)}
          ${adminKeys.map((key) => `<td class="text-center">${matrixAdminModuleCell(row, key)}</td>`).join('')}
          <td class="text-center">${matrixCreditsCell(row)}</td>
          <td class="text-center">${workspace}</td>
          <td class="text-center">${audit}</td>
        </tr>`;
    }).join('');

    return `<div class="table-responsive table-fit border-top-0"><table class="table table-sm table-striped table-hover table-bordered align-middle mb-0 aiu-ar-matrix-table"><thead>${headerRow1}</thead><tbody>${body}</tbody></table></div>`;
  }

  function renderMatrixExtensionTable(scope, rows) {
    const modKeys = matrixModuleKeys(scope);
    const featKeys = matrixFeatureKeys(scope);
    const recKeys = matrixRecordKeys(scope);
    const moduleKey = modKeys[0] ?? scope;
    const groupCols = 2;
    const moduleCols = modKeys.length;
    const featureCols = featKeys.length;
    const recordCols = recKeys.length;
    const totalCols = groupCols + moduleCols + featureCols + recordCols;

    let headerRow1 = `<tr class="aiu-ar-matrix-head-row"><th colspan="${groupCols}" class="aiu-ar-matrix-section aiu-ar-matrix-section--group">Group</th>`;
    if (moduleCols) {
      headerRow1 += `<th colspan="${moduleCols}" class="aiu-ar-matrix-section aiu-ar-matrix-section--module">Module</th>`;
    }
    if (featureCols) {
      headerRow1 += `<th colspan="${featureCols}" class="aiu-ar-matrix-section aiu-ar-matrix-section--features">Features / custom_options</th>`;
    }
    if (recordCols) {
      headerRow1 += `<th colspan="${recordCols}" class="aiu-ar-matrix-section aiu-ar-matrix-section--records">Record Tables</th>`;
    }
    headerRow1 += '</tr>';

    let headerRow2 = '<tr class="aiu-ar-matrix-col-row"><th>Name</th><th>Members</th>';
    for (const key of modKeys) {
      headerRow2 += `<th class="aiu-ar-matrix-col">${escapeHtml(matrixShortLabel(moduleLabel(key), 12).toUpperCase())}</th>`;
    }
    for (const key of featKeys) {
      const label = featureMetaById(key)?.label ?? key;
      headerRow2 += `<th class="aiu-ar-matrix-col" title="${escapeHtml(label)}">${escapeHtml(matrixShortLabel(label, 12))}</th>`;
    }
    for (const key of recKeys) {
      const record = recordMetaById(key);
      const label = record?.label ?? key;
      headerRow2 += `<th class="aiu-ar-matrix-col" title="${escapeHtml(label)}">${escapeHtml(matrixShortLabel(label, 14))}</th>`;
    }
    headerRow2 += '</tr>';

    const body = rows.map((row) => {
      let cells = renderMatrixGroupCell(row);
      cells += modKeys.map((key) => `<td class="text-center">${matrixChildModuleCell(row, key)}</td>`).join('');
      cells += featKeys.map((key) => `<td class="text-center">${matrixFeatureCell(row, key, moduleKey)}</td>`).join('');
      cells += recKeys.map((key) => `<td class="text-center">${matrixRecordCell(row, key, moduleKey)}</td>`).join('');
      return `<tr class="${row.configured ? '' : 'aiu-ar-dim'}">${cells}</tr>`;
    }).join('');

    return `<div class="table-responsive table-fit border-top-0"><table class="table table-sm table-striped table-hover table-bordered align-middle mb-0 aiu-ar-matrix-table"><thead>${headerRow1}${headerRow2}</thead><tbody>${body}</tbody></table></div>`;
  }

  function renderMatrixLegend() {
    return `
      <div class="aiu-ar-matrix-legend">
        <span>${matrixCheckMarkHtml('Use / Read / On')} Use / Read / On</span>
        <span><span class="badge badge-primary">Mgr</span> Manage / R+W</span>
        <span><span class="aiu-ar-matrix-cell aiu-ar-matrix-cell--none">—</span> No access</span>
      </div>`;
  }

  function renderMatrix() {
    const rows = matrix.groups ?? [];
    const scopes = matrixScopeMeta();
    const configuredCount = matrix.configuredCount ?? rows.filter((row) => row.configured).length;
    const activeScope = scopes.find((scope) => scope.id === matrixScope) ?? scopes[0];

    return `
      <div class="card aiu-ar-matrix-card" style="--aiu-ar-matrix-accent:${escapeHtml(activeScope.accent)}">
        <div class="card-body">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div>
              <h2 class="h3 mb-1">Permission Matrix</h2>
              <p class="text-variant mb-0">Cross-group overview — ${rows.length} groups, ${configuredCount} configured</p>
            </div>
            ${renderMatrixLegend()}
          </div>
          <div class="btn-group flex-wrap mb-3" role="group">
            ${scopes.map((scope) => `<button type="button" class="btn btn-default ${matrixScope === scope.id ? 'active is-active' : ''}" data-matrix-scope="${scope.id}">${escapeHtml(scope.label)}</button>`).join('')}
          </div>
          ${matrixScope === 'ai-universe'
            ? renderMatrixAiUniverseTable(rows)
            : renderMatrixExtensionTable(matrixScope, rows)}
        </div>
      </div>`;
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  async function loadGroupDetail(uid) {
    const url = TYPO3.settings.ajaxUrls?.nst3af_access_roles_group;
    if (!url) {
      return;
    }
    try {
      const res = await new AjaxRequest(url).withQueryArguments({ uid }).get();
      const data = await res.resolve();
      if (data?.ok && data.group) {
        groupDetail = data.group;
        wizardConfig = deepClone(data.group.config ?? defaultConfig);
      }
    } catch {
      /* ignore */
    }
  }

  async function loadReviewPreview() {
    if (!previewUrl) {
      return;
    }
    try {
      normalizeWizardConfig();
      const res = await new AjaxRequest(previewUrl).post(
        { groupUid: selectedUid, config: wizardConfig },
        jsonPostOptions,
      );
      const data = await res.resolve();
      if (data?.ok && data.preview) {
        reviewPreview = data.preview;
        render();
      }
    } catch {
      /* ignore */
    }
  }

  async function applyConfig() {
    if (!applyUrl || !selectedUid) {
      return;
    }
    try {
      normalizeWizardConfig();
      const res = await new AjaxRequest(applyUrl).post(
        {
          groupUid: selectedUid,
          config: wizardConfig,
        },
        jsonPostOptions,
      );
      const data = await res.resolve();
      if (data?.ok) {
        groups = data.groups ?? groups;
        groupDetail = data.group ?? groupDetail;
        panelMode = 'dashboard';
        showPreset = false;
        reviewPreview = null;
        Notification.success('Applied', 'Group permissions saved.');
        render();
      } else {
        Notification.error('Error', data?.message ?? 'Apply failed');
      }
    } catch (e) {
      Notification.error('Error', String(e));
    }
  }

  function bindEvents() {
    root.querySelectorAll('[data-page-tab]').forEach((btn) => {
      btn.addEventListener('click', () => {
        pageTab = btn.getAttribute('data-page-tab') === 'matrix' ? 'matrix' : 'groups';
        render();
      });
    });

    root.querySelectorAll('[data-matrix-scope]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const scope = btn.getAttribute('data-matrix-scope');
        if (scope) {
          matrixScope = scope;
          render();
        }
      });
    });

    const search = root.querySelector('[data-group-search]');
    if (search instanceof HTMLInputElement) {
      bindFilterSearchInput(search, (value) => {
        const list = root.querySelector('[data-group-list]');
        if (list) {
          list.innerHTML = renderGroupList(value);
          bindGroupItems();
        }
      });
    }

    bindGroupItems();

    root.querySelector('[data-start-wizard]')?.addEventListener('click', () => {
      panelMode = 'wizard';
      wizardStep = 'preset';
      showPreset = true;
      wizardConfig = deepClone(defaultConfig);
      render();
    });

    root.querySelector('[data-reconfigure]')?.addEventListener('click', () => {
      panelMode = 'wizard';
      wizardStep = 'modules';
      showPreset = false;
      reviewPreview = null;
      wizardConfig = deepClone(groupDetail?.config ?? defaultConfig);
      render();
    });

    root.querySelector('[data-wizard-cancel]')?.addEventListener('click', () => {
      panelMode = 'dashboard';
      render();
    });

    root.querySelectorAll('[data-preset-id]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-preset-id');
        const preset = presets.find((p) => p.id === id);
        if (preset?.config) {
          wizardConfig = deepClone(preset.config);
        }
        wizardStep = 'modules';
        showPreset = false;
        render();
      });
    });

    root.querySelector('[data-preset-blank]')?.addEventListener('click', () => {
      wizardConfig = deepClone(defaultConfig);
      wizardStep = 'modules';
      showPreset = false;
      render();
    });

    root.querySelector('[data-wizard-back]')?.addEventListener('click', () => {
      const idx = WIZARD_STEPS.indexOf(wizardStep);
      if (idx > 0) {
        wizardStep = WIZARD_STEPS[idx - 1];
        render();
      }
    });

    root.querySelector('[data-wizard-next]')?.addEventListener('click', async () => {
      if (wizardStep === 'review') {
        applyConfig();
        return;
      }
      normalizeWizardConfig();
      const idx = WIZARD_STEPS.indexOf(wizardStep);
      if (idx >= 0 && idx < WIZARD_STEPS.length - 1) {
        wizardStep = WIZARD_STEPS[idx + 1];
        if (wizardStep === 'review') {
          reviewPreview = null;
          render();
          await loadReviewPreview();
          return;
        }
        render();
      }
    });

    root.querySelectorAll('[data-module-key]').forEach((input) => {
      input.addEventListener('change', () => {
        if (!(input instanceof HTMLInputElement)) {
          return;
        }
        const key = input.getAttribute('data-module-key');
        if (!key) {
          return;
        }
        if (!wizardConfig.modules) {
          wizardConfig.modules = {};
        }
        wizardConfig.modules[key] = input.checked;
        normalizeWizardConfig();
        render();
      });
    });

    root.querySelectorAll('[data-feature-id]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-feature-id');
        const val = btn.getAttribute('data-feature-val');
        if (!id || !val) {
          return;
        }
        if (!wizardConfig.features) {
          wizardConfig.features = {};
        }
        wizardConfig.features[id] = val;
        if (!wizardConfig.records) {
          wizardConfig.records = {};
        }
        applyFeatureRecordDefaults(
          { ...(defaultConfig.features ?? {}), ...(wizardConfig.features ?? {}) },
          wizardConfig.records,
          featureRecordDefaults,
        );
        normalizeWizardConfig();
        render();
      });
    });

    root.querySelectorAll('[data-record-id]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-record-id');
        const val = btn.getAttribute('data-record-val');
        if (!id || !val) {
          return;
        }
        if (!wizardConfig.records) {
          wizardConfig.records = {};
        }
        wizardConfig.records[id] = val;
        render();
      });
    });

    root.querySelectorAll('[data-limit-key]').forEach((input) => {
      input.addEventListener('change', () => {
        if (!(input instanceof HTMLInputElement)) {
          return;
        }
        const key = input.getAttribute('data-limit-key');
        if (!key || !wizardConfig.limits) {
          return;
        }
        wizardConfig.limits[key] = input.type === 'checkbox' ? input.checked : Number(input.value);
      });
    });

    root.querySelectorAll('[data-limit-enabled]').forEach((input) => {
      input.addEventListener('change', () => {
        if (!(input instanceof HTMLInputElement)) {
          return;
        }
        const key = input.getAttribute('data-limit-enabled');
        if (!key || !wizardConfig.limits) {
          return;
        }
        wizardConfig.limits[key] = input.checked;
      });
    });
  }

  function bindGroupItems() {
    root.querySelectorAll('[data-group-uid]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        selectedUid = Number(btn.getAttribute('data-group-uid'));
        panelMode = 'dashboard';
        await loadGroupDetail(selectedUid);
        render();
      });
    });
  }

  if (selectedUid) {
    loadGroupDetail(selectedUid).finally(render);
  } else {
    render();
  }
}

function bootAccessRoles() {
  initAccessRoles();
}

bootAccessRoles();
document.addEventListener('typo3-module-loaded', bootAccessRoles);

export default initAccessRoles;
