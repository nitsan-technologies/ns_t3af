# AI Agent hotkey conflict matrix (T6)

The global agent opens from the orange **Ask AI Agent** launch bar (toolbar item, optionally cloned into the topbar center on v13+) on **Ctrl/Cmd+Shift+K** by default (`Resources/Public/JavaScript/agent.js`). Editors can opt into **Ctrl/Cmd+K** under AI Agent settings (stored in browser `localStorage`). On **TYPO3 13+**, the open shortcut is registered through core `@typo3/backend/hotkeys.js` and the scaffold `hotkeys/negotiator.js`, so keypresses inside module content iframes are forwarded to the top-level agent (same mechanism as Live Search). On **TYPO3 12**, `hotkeys.js` is unavailable; the extension falls back to a top-document `keydown` listener (shortcut works when focus is on the scaffold, not inside the content iframe). Escape, autocomplete, and focus-trap keys while the modal is open still use a top-document `keydown` listener on all supported versions.

| Browser / surface | Ctrl/Cmd+Shift+K (default) | Ctrl/Cmd+K (opt-in) | Ctrl/Cmd+J (rejected) |
|---|---|---|---|
| Chrome | Free; fully interceptable | Collides with TYPO3 Live Search | Browser Downloads UI — event often not delivered |
| Safari | Free; fully interceptable | Collides with TYPO3 Live Search | Browser-reserved; not reliably interceptable |
| Firefox | Collides only with Web Console (dev tool) | Collides with TYPO3 Live Search | Downloads; interceptable but inconsistent vs Chrome/Safari |
| Edge | Free; fully interceptable | Collides with TYPO3 Live Search | Downloads / browser UI risk |

| TYPO3 major | Core LiveSearch on Ctrl/Cmd+K | Agent default behaviour | Notes |
|---|---|---|---|
| v12 | Yes (global shortcut) | Agent uses Shift+K; Live Search keeps K | Top-document listener only; no iframe forwarding |
| v13 | Yes | Same | Hotkeys negotiator + `allowOnEditables` |
| v14 | Yes | Same as v13 | Verified against current backend toolbar layout |

## Choosing Ctrl/Cmd+K instead

AI Agent → Overview → **Keyboard shortcut**. Opt-in to Ctrl/Cmd+K when an install prefers Agent over Live Search. Live Search stays reachable via the toolbar search icon (and module-specific UX). Preference is per browser profile (`nst3af.agent.prefs`).

## Accessibility

The launch bar and modal document the active shortcut in the pill `<kbd>` / footer `<kbd>` and update open controls' `title` / `aria-label` to match. Focus is trapped inside the open modal and restored on close.
