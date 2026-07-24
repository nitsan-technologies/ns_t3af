import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import { observeBrowserAutocomplete } from './disable-browser-autocomplete.js';

const root = document.querySelector('[data-mcp-server]');
if (root) {
  observeBrowserAutocomplete(root);
  initMcpServerPage(root);
}

function lang(key, fallback) {
  const value = typeof TYPO3 !== 'undefined' && TYPO3.lang ? TYPO3.lang[key] : undefined;
  return value ?? fallback ?? key;
}

function initMcpServerPage(root) {
  showWorkspaceCreatedNotice();
  restoreStoredTokens(root);
  restoreMcpRemotePanel(root);
  const workspaceSelect = root.querySelector('[data-mcp-workspace]');
  const storageKey = 'nst3af.mcp.workspace';

  if (workspaceSelect instanceof HTMLSelectElement) {
    const serverSaved = root.getAttribute('data-mcp-workspace-saved');
    const localSaved = localStorage.getItem(storageKey);
    const preferred = serverSaved !== null && serverSaved !== '' ? serverSaved : localSaved;
    if (preferred !== null && [...workspaceSelect.options].some((o) => o.value === preferred)) {
      workspaceSelect.value = preferred;
    }
    localStorage.setItem(storageKey, workspaceSelect.value);
    persistWorkspacePreference(workspaceSelect.value);
    workspaceSelect.addEventListener('change', () => {
      localStorage.setItem(storageKey, workspaceSelect.value);
      persistWorkspacePreference(workspaceSelect.value);
    });
  }

  const modeSelect = root.querySelector('[data-mcp-mode]');
  if (modeSelect instanceof HTMLSelectElement) {
    const serverMode = root.getAttribute('data-mcp-mode-saved');
    if (serverMode === 'context' || serverMode === 'native') {
      modeSelect.value = serverMode;
    }
    modeSelect.addEventListener('change', () => {
      persistMcpMode(modeSelect.value);
    });
  }

  root.querySelectorAll('[data-copy]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const key = btn.getAttribute('data-copy');
      if (key === 'mcp-remote-url') {
        const tokenUrl = resolveMcpRemoteTokenUrl(root);
        if (tokenUrl) {
          copyText(tokenUrl);
        } else {
          Notification.error(lang('mcpServer.js.tokenCopyUnavailable', 'Full token unavailable. Revoke and create a new token to copy it again.'));
        }
        return;
      }

      const el = root.querySelector(`[data-copy-target="${key}"]`);
      if (!el) return;

      const tokenUid = el.getAttribute('data-token-uid');
      if (tokenUid) {
        const stored = sessionStorage.getItem(tokenStorageKey(tokenUid));
        if (stored) {
          copyText(stored);
          return;
        }
        Notification.error(lang('mcpServer.js.tokenCopyUnavailable', 'Full token unavailable. Revoke and create a new token to copy it again.'));
        return;
      }

      const clientKey = el.getAttribute('data-client-token-value');
      if (clientKey) {
        const stored = sessionStorage.getItem(clientTokenStorageKey(clientKey));
        if (stored) {
          copyText(stored);
          return;
        }
      }
      copyText(el.textContent.trim());
    });
  });

  root.querySelectorAll('[data-copy-snippet]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const key = btn.getAttribute('data-copy-snippet');
      let text = '';
      if (key === 'cli') {
        text = root.querySelector('[data-snippet="cli"]')?.textContent?.trim() ?? '';
      } else if (key === 'mcpremote') {
        const tokenUrl = resolveMcpRemoteTokenUrl(root);
        text = tokenUrl ? buildMcpRemoteConfigJson(tokenUrl) : (root.querySelector('[data-snippet="mcpremote"]')?.textContent?.trim() ?? '');
      } else {
        const code = root.querySelector(`[data-snippet="${key}"]`);
        text = code?.textContent?.trim() ?? '';
      }
      if (text !== '') {
        copyText(text);
      }
    });
  });

  initServerTabs(root);
  initTransportTabs(root);
  initClientTabs(root);
  initMtlsPanel(root);
  storePersonalBearerToken(root);
  bindActions(root, workspaceSelect);
}

function initServerTabs(root) {
  const tabs = root.querySelectorAll('[data-mcp-server-tab]');
  tabs.forEach((btn) => {
    btn.addEventListener('click', () => {
      const tab = btn.getAttribute('data-mcp-server-tab');
      if (!tab) return;

      tabs.forEach((item) => {
        const active = item === btn;
        item.classList.toggle('active', active);
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-selected', active ? 'true' : 'false');
      });

      root.querySelectorAll('[data-mcp-server-panel]').forEach((panel) => {
        const show = panel.getAttribute('data-mcp-server-panel') === tab;
        panel.classList.toggle('is-hidden', !show);
        panel.classList.toggle('is-active', show);
        if (show) {
          panel.removeAttribute('hidden');
        } else {
          panel.setAttribute('hidden', 'hidden');
        }
      });
    });
  });
}

function initTransportTabs(root) {
  const tabs = root.querySelectorAll('[data-mcp-transport-tabs] [data-transport]');
  tabs.forEach((btn) => {
    btn.addEventListener('click', () => {
      const transport = btn.getAttribute('data-transport');
      if (!transport) return;

      tabs.forEach((tab) => {
        const active = tab === btn;
        tab.classList.toggle('active', active);
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
      });

      root.querySelectorAll('[data-transport-panel]').forEach((panel) => {
        const show = panel.getAttribute('data-transport-panel') === transport;
        panel.classList.toggle('is-hidden', !show);
      });
    });
  });
}

function initClientTabs(root) {
  if (root.dataset.mcpClientTabsInit === '1') {
    return;
  }
  root.dataset.mcpClientTabsInit = '1';

  root.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    const btn = target.closest('[data-client-tab]');
    if (!(btn instanceof HTMLElement) || !root.contains(btn)) {
      return;
    }

    const guideRoot = btn.closest('[data-mcp-client-guide]');
    if (!(guideRoot instanceof HTMLElement)) {
      return;
    }

    const transportPanel = guideRoot.closest('[data-transport-panel]');
    if (transportPanel instanceof HTMLElement && transportPanel.classList.contains('is-hidden')) {
      return;
    }

    const client = btn.getAttribute('data-client-tab');
    if (!client) {
      return;
    }

    guideRoot.querySelectorAll('[data-client-tab]').forEach((tab) => {
      const active = tab === btn;
      tab.classList.toggle('is-active', active);
      tab.classList.toggle('active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    guideRoot.querySelectorAll('[data-client-snippet]').forEach((panel) => {
      const show = panel.getAttribute('data-client-snippet') === client;
      panel.classList.toggle('is-hidden', !show);
      panel.hidden = !show;
    });
  });
}

function initMtlsPanel(root) {
  const panel = root.querySelector('[data-mcp-mtls-panel]');
  if (!(panel instanceof HTMLElement)) {
    return;
  }

  const toggle = panel.querySelector('[data-mcp-mtls-toggle]');
  const body = panel.querySelector('[data-mcp-mtls-body]');
  const uploadBtn = panel.querySelector('[data-mcp-mtls-upload]');
  const fileInput = panel.querySelector('[data-mcp-mtls-file]');
  const caField = panel.querySelector('[data-mcp-mtls-ca]');
  const removeBtn = panel.querySelector('[data-mcp-mtls-remove]');

  const syncBodyState = () => {
    if (!(body instanceof HTMLElement) || !(toggle instanceof HTMLInputElement)) {
      return;
    }
    const enabled = toggle.checked && !toggle.disabled;
    body.classList.toggle('is-disabled', !enabled);
    body.setAttribute('aria-disabled', enabled ? 'false' : 'true');
  };

  if (toggle instanceof HTMLInputElement) {
    toggle.addEventListener('change', syncBodyState);
    syncBodyState();
  }

  if (uploadBtn instanceof HTMLButtonElement && fileInput instanceof HTMLInputElement) {
    uploadBtn.addEventListener('click', () => {
      if (uploadBtn.disabled) {
        return;
      }
      fileInput.click();
    });

    fileInput.addEventListener('change', () => {
      const file = fileInput.files?.[0];
      if (!file || !(caField instanceof HTMLTextAreaElement)) {
        return;
      }
      file.text().then((content) => {
        caField.value = content.trim();
        window.location.reload();
      }).catch(() => {
        Notification.error(lang('mcpServer.js.mtlsUploadFailed', 'Failed to read certificate file'));
      });
    });
  }

  if (removeBtn instanceof HTMLButtonElement && caField instanceof HTMLTextAreaElement) {
    removeBtn.addEventListener('click', () => {
      if (removeBtn.disabled) {
        return;
      }
      caField.value = '';
      postPayload(TYPO3.settings.ajaxUrls.nst3af_mcp_mtls_save, {
        mtlsCaCertificate: '',
        mtlsValidationEnabled: toggle instanceof HTMLInputElement && toggle.checked ? '1' : '0',
      }, true);
    });
  }
}

function storePersonalBearerToken(root) {
  const panel = root.querySelector('[data-transport-panel="http"]');
  if (!(panel instanceof HTMLElement)) {
    return;
  }

  const uid = panel.getAttribute('data-personal-token-uid');
  const plain = panel.getAttribute('data-personal-token-plain');
  if (!uid || !plain) {
    return;
  }

  sessionStorage.setItem(tokenStorageKey(uid), plain);
  panel.removeAttribute('data-personal-token-plain');

  const target = panel.querySelector('[data-copy-target="personal-bearer-token"]');
  if (target instanceof HTMLElement) {
    target.textContent = plain.slice(0, 24) + '…';
  }
}

function bindActions(root, workspaceSelect) {
  root.querySelector('[data-action="create-workspace"]')?.addEventListener('click', (event) => {
    const button = event.currentTarget;
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }

    button.disabled = true;
    const originalLabel = button.textContent;
    button.textContent = lang('mcpServer.js.workspaceCreating', 'Creating workspace…');

    new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_workspace_create)
      .post({})
      .then(async (response) => response.resolve())
      .then((data) => {
        if (!data?.success || !data.workspaceId) {
          throw new Error(data?.message || lang('mcpServer.js.workspaceCreateFailed', 'Workspace creation failed'));
        }

        localStorage.setItem('nst3af.mcp.workspace', String(data.workspaceId));
        const url = new URL(window.location.href);
        url.searchParams.set('mcpWorkspaceCreated', '1');
        window.location.href = url.toString();
      })
      .catch((error) => {
        button.disabled = false;
        button.textContent = originalLabel;
        Notification.error(error?.message || lang('mcpServer.js.workspaceCreateFailed', 'Workspace creation failed'));
      });
  });

  root.querySelector('[data-action="refresh-connections"]')?.addEventListener('click', () => {
    new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_connections)
      .get()
      .then(async (response) => response.resolve())
      .then(() => window.location.reload())
      .catch(() => Notification.error(lang('mcpServer.js.refreshFailed', 'Refresh failed')));
  });

  root.querySelector('[data-action="revoke-all"]')?.addEventListener('click', () => {
    if (!window.confirm(lang('mcpServer.js.revokeAllConfirm', 'Revoke all active MCP tokens?'))) return;
    new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_token_revoke_all)
      .post({})
      .then(async (response) => response.resolve())
      .then(() => window.location.reload())
      .catch(() => Notification.error(lang('mcpServer.js.revokeFailed', 'Revoke failed')));
  });

  root.querySelectorAll('[data-action="revoke-token"]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const uid = btn.getAttribute('data-uid');
      new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_token_revoke)
        .post({ uid })
        .then(async (response) => response.resolve())
        .then(() => {
          if (uid) {
            sessionStorage.removeItem(tokenStorageKey(uid));
          }
          btn.closest('tr')?.remove();
        })
        .catch(() => Notification.error(lang('mcpServer.js.revokeFailed', 'Revoke failed')));
    });
  });

  root.querySelector('[data-action="issue-mcp-remote"]')?.addEventListener('click', () => {
    const workspaceId = workspaceSelect instanceof HTMLSelectElement ? workspaceSelect.value : '0';
    new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_mcp_remote_token_issue)
      .post({ label: 'mcp-remote token', workspaceId, validityDays: 30 })
      .then(async (response) => response.resolve())
      .then((data) => {
        if (!data?.success || !data.token) {
          throw new Error(data?.message || lang('mcpServer.js.tokenCreateFailed', 'Failed to create token'));
        }
        if (data.uid) {
          sessionStorage.setItem(tokenStorageKey(String(data.uid)), data.token);
        }
        Notification.success(lang('mcpServer.js.tokenCreated', 'mcp-remote token created'));
        window.location.reload();
      })
      .catch((error) => Notification.error(error?.message || lang('mcpServer.js.tokenCreateFailed', 'Failed to create token')));
  });

  root.querySelectorAll('[data-action="issue-client-token"]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const client = btn.getAttribute('data-client');
      if (!client) return;

      const workspaceId = workspaceSelect instanceof HTMLSelectElement ? workspaceSelect.value : '0';
      btn.disabled = true;

      new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_client_token_issue)
        .post({ client, workspaceId })
        .then(async (response) => response.resolve())
        .then((data) => {
          if (!data?.success || !data.token) {
            throw new Error(data?.message || lang('mcpServer.js.tokenCreateFailed', 'Failed to create token'));
          }
          if (data.uid) {
            sessionStorage.setItem(tokenStorageKey(String(data.uid)), data.token);
          }
          sessionStorage.setItem(clientTokenStorageKey(client), data.token);
          Notification.success(lang('mcpServer.js.clientTokenCreated', 'Token created'));
          window.location.reload();
        })
        .catch((error) => {
          btn.disabled = false;
          Notification.error(error?.message || lang('mcpServer.js.tokenCreateFailed', 'Failed to create token'));
        });
    });
  });

  root.querySelector('[data-action="save-advanced"]')?.addEventListener('click', () => {
    const form = root.querySelector('[data-advanced-form]');
    if (!(form instanceof HTMLFormElement)) {
      return;
    }

    const payload = Object.fromEntries(new FormData(form));
    ['enableMcpServer', 'requireAuth', 'rateLimitGlobal', 'logAllToolCalls', 'allowAnonymousReadOnly'].forEach((key) => {
      payload[key] = form.querySelector(`[name="${key}"]`)?.checked ? '1' : '0';
    });
    postPayload(TYPO3.settings.ajaxUrls.nst3af_mcp_advanced_save, payload);
  });

  root.querySelector('[data-action="save-security-scopes"]')?.addEventListener('click', () => {
    const form = root.querySelector('[data-security-oauth-form]');
    if (!(form instanceof HTMLFormElement)) return;
    const payload = Object.fromEntries(new FormData(form));
    payload.enforcePkce = form.querySelector('[name="enforcePkce"]')?.checked ? '1' : '0';
    postPayload(TYPO3.settings.ajaxUrls.nst3af_mcp_security_scopes_save, payload);
  });

  root.querySelector('[data-action="save-mtls-settings"]')?.addEventListener('click', () => {
    const panel = root.querySelector('[data-mcp-mtls-panel]');
    const toggle = panel?.querySelector('[data-mcp-mtls-toggle]');
    const caField = panel?.querySelector('[data-mcp-mtls-ca]');
    postPayload(TYPO3.settings.ajaxUrls.nst3af_mcp_mtls_save, {
      mtlsValidationEnabled: toggle instanceof HTMLInputElement && toggle.checked ? '1' : '0',
      mtlsCaCertificate: caField instanceof HTMLTextAreaElement ? caField.value : '',
    });
  });

  root.querySelector('[data-action="ip-allowlist-add"]')?.addEventListener('click', () => {
    postForm(root.querySelector('[data-ip-allowlist-form]'), TYPO3.settings.ajaxUrls.nst3af_mcp_ip_allowlist_add, true);
  });

  root.querySelectorAll('[data-action="ip-allowlist-remove"]').forEach((btn) => {
    btn.addEventListener('click', () => {
      postPayload(TYPO3.settings.ajaxUrls.nst3af_mcp_ip_allowlist_remove, { uid: btn.getAttribute('data-uid') }, true);
    });
  });

  root.querySelectorAll('[data-action="ip-allowlist-toggle"]').forEach((btn) => {
    btn.addEventListener('click', () => {
      postPayload(TYPO3.settings.ajaxUrls.nst3af_mcp_ip_allowlist_toggle, {
        uid: btn.getAttribute('data-uid'),
        enabled: btn.getAttribute('data-enabled') === '1' ? '0' : '1',
      }, true);
    });
  });

  root.querySelector('[data-action="analytics-export"]')?.addEventListener('click', (event) => {
    const button = event.currentTarget;
    const period = button instanceof HTMLElement ? button.getAttribute('data-period') ?? '7d' : '7d';
    new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_analytics_export)
      .withQueryArguments({ period })
      .get()
      .then(async (response) => response.resolve())
      .then((data) => {
        if (!data?.success || !data.content) {
          throw new Error('Export failed');
        }
        const blob = new Blob([atob(data.content)], { type: 'text/csv;charset=utf-8' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = data.filename || 'mcp-analytics.csv';
        link.click();
        URL.revokeObjectURL(link.href);
        Notification.success(lang('mcpServer.js.exportDone', 'Export downloaded'));
      })
      .catch(() => Notification.error(lang('mcpServer.js.exportFailed', 'Export failed')));
  });

  root.querySelector('[data-action="health-ping-all"]')?.addEventListener('click', () => {
    postPayload(TYPO3.settings.ajaxUrls.nst3af_mcp_health_ping_all, {})
      .then(() => window.location.reload());
  });
}

function postForm(form, route, reload = false) {
  if (!(form instanceof HTMLFormElement) || !route) return Promise.resolve();
  return postPayload(route, Object.fromEntries(new FormData(form)), reload);
}

function postPayload(route, payload, reload = false) {
  return new AjaxRequest(route)
    .post(payload)
    .then(async (response) => response.resolve())
    .then((data) => {
      if (!data?.success) {
        throw new Error(data?.message || lang('mcpServer.js.settingsSaveFailed', 'Save failed'));
      }
      Notification.success(lang('mcpServer.js.settingsSaved', 'Settings saved'));
      if (reload) {
        window.location.reload();
      }
      return data;
    })
    .catch((error) => {
      Notification.error(error?.message || lang('mcpServer.js.settingsSaveFailed', 'Save failed'));
      throw error;
    });
}

function copyText(text) {
  navigator.clipboard.writeText(text).then(
    () => Notification.success(lang('mcpServer.js.copied', 'Copied')),
    () => Notification.error(lang('mcpServer.js.copyFailed', 'Copy failed')),
  );
}

function clientTokenStorageKey(client) {
  return `nst3af.mcp.clientToken.${client}`;
}

function tokenStorageKey(uid) {
  return `nst3af.mcp.token.${uid}`;
}

function restoreStoredTokens(root) {
  root.querySelectorAll('code.aiu-mcp-table__token[data-token-uid]').forEach((el) => {
    const uid = el.getAttribute('data-token-uid');
    if (!uid) return;
    const stored = sessionStorage.getItem(tokenStorageKey(uid));
    if (stored) {
      el.textContent = stored.length > 12 ? `${stored.slice(0, 8)}…` : stored;
      el.setAttribute('title', stored);
    }
  });

  root.querySelectorAll('[data-client-token-value]').forEach((el) => {
    const client = el.getAttribute('data-client-token-value');
    if (!client) return;
    const stored = sessionStorage.getItem(clientTokenStorageKey(client));
    if (stored) {
      el.textContent = stored;
    }
  });
}

function restoreMcpRemotePanel(root) {
  const tokenUrl = resolveMcpRemoteTokenUrl(root);
  if (!tokenUrl) {
    return;
  }

  const urlEl = root.querySelector('[data-mcp-remote-url]');
  if (urlEl) {
    urlEl.textContent = tokenUrl;
  }

  const configEl = root.querySelector('[data-snippet="mcpremote"]');
  if (configEl) {
    configEl.textContent = buildMcpRemoteConfigJson(tokenUrl);
  }
}

function resolveMcpRemoteTokenUrl(root) {
  const panel = root.querySelector('[data-transport-panel="mcpremote"]');
  const uid = panel?.getAttribute('data-mcp-remote-token-uid');
  if (!uid || uid === '0') {
    return null;
  }

  const stored = sessionStorage.getItem(tokenStorageKey(uid));
  if (!stored) {
    return null;
  }

  const urlEl = root.querySelector('[data-mcp-remote-url]');
  const baseUrl = urlEl?.textContent?.split('?token=')[0]?.trim();
  if (!baseUrl) {
    return null;
  }

  return `${baseUrl}?token=${stored}`;
}

function buildMcpRemoteConfigJson(tokenUrl) {
  return JSON.stringify(
    {
      mcpServers: {
        'typo3-ai-foundation': {
          command: 'npx',
          args: ['mcp-remote', tokenUrl],
        },
      },
    },
    null,
    2,
  );
}

function showWorkspaceCreatedNotice() {
  const url = new URL(window.location.href);
  if (url.searchParams.get('mcpWorkspaceCreated') !== '1') {
    return;
  }

  Notification.success(lang('mcpServer.js.workspaceCreated', 'MCP workspace created. It is now selected for new tokens and CLI commands.'));
  url.searchParams.delete('mcpWorkspaceCreated');
  window.history.replaceState({}, '', url.toString());
}

function persistWorkspacePreference(workspaceId) {
  if (!TYPO3?.settings?.ajaxUrls?.nst3af_mcp_workspace_preference) {
    return;
  }

  new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_workspace_preference)
    .post({ workspaceId })
    .catch(() => {});
}

function persistMcpMode(mcpMode) {
  if (!TYPO3?.settings?.ajaxUrls?.nst3af_mcp_mode_save) {
    return;
  }

  new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_mode_save)
    .post({ mcpMode })
    .then(async (response) => {
      const data = await response.resolve();
      if (!data?.success) {
        throw new Error(data?.message || 'Failed to save MCP mode');
      }
      Notification.success(lang('mcpServer.js.modeSaved', 'MCP mode saved.'));
    })
    .catch((error) => {
      Notification.error(error?.message || lang('mcpServer.js.modeSaveFailed', 'Could not save MCP mode.'));
    });
}

export {};
