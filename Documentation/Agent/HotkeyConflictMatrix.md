# AI Agent hotkey conflict matrix (T6)

The global agent opens from the orange **Ask AI Agent** launch bar (toolbar item, optionally cloned into the topbar center on v13+) on **Ctrl/Cmd+Shift+K** by default (`Resources/Public/JavaScript/agent.js`). Editors can opt into **Ctrl/Cmd+K** under AI Agent settings (stored in browser `localStorage`). On **TYPO3 12–14**, the open shortcut is bound on the scaffold document **and** every same-origin iframe (`#typo3-contentIframe`, page tree, …), matching how Live Search reaches module content. Rebind on `typo3-iframe-loaded` / iframe `load`. Escape, autocomplete, and focus-trap keys while the modal is open still use a top-document `keydown` listener.

| Browser / surface | Ctrl/Cmd+Shift+K (default) | Ctrl/Cmd+K (opt-in) | Ctrl/Cmd+J (rejected) |
|---|---|---|---|
| Chrome | Free; fully interceptable | Collides with TYPO3 Live Search | Browser Downloads UI — event often not delivered |
| Safari | Free; fully interceptable | Collides with TYPO3 Live Search | Browser-reserved; not reliably interceptable |
| Firefox | Collides only with Web Console (dev tool) | Collides with TYPO3 Live Search | Downloads; interceptable but inconsistent vs Chrome/Safari |
| Edge | Free; fully interceptable | Collides with TYPO3 Live Search | Downloads / browser UI risk |

| TYPO3 major | Core LiveSearch on Ctrl/Cmd+K | Agent default behaviour | Notes |
|---|---|---|---|
| v12 | Yes (global shortcut) | Agent uses Shift+K; Live Search keeps K | Capture-phase bind + stopImmediatePropagation (v12 Live Search ignores Shift) |
| v13 | Yes | Same | Exact Hotkeys combo on Live Search; same Agent capture bind |
| v14 | Yes | Same as v13 | Same as v13 |

## Choosing Ctrl/Cmd+K instead

AI Agent → Overview → **Keyboard shortcut**. Opt-in to Ctrl/Cmd+K when an install prefers Agent over Live Search. Live Search stays reachable via the toolbar search icon (and module-specific UX). Preference is per browser profile (`nst3af.agent.prefs`).

## Accessibility

The launch bar and modal document the active shortcut in the pill `<kbd>` / footer `<kbd>` and update open controls' `title` / `aria-label` to match. Focus is trapped inside the open modal and restored on close.
