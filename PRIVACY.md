# T3AF and your data

T3AF is self-hosted. Your content, prompts, images, and visitor data stay on your TYPO3
server unless you choose a mode that routes AI requests elsewhere (see below).

## AI Provider Mode — two data paths

### Your Own API Keys (default)

AI requests go directly from your server to the AI provider you configure, using your own
API keys. T3Planet is not in the AI data path for these requests.

### T3Planet Credits (optional)

When you enable **T3Planet Credits** in the AI Foundation backend, AI requests for
`complete()`, `stream()`, `embed()`, and related billed features are sent from your
server to the T3Planet composer API (`/API/AI/*`) using a server-side Bearer token.
T3Planet may invoke upstream AI providers on your behalf and debit your credit balance.

**What may be transmitted in Credits mode:**

- License key(s) and site domain (for token identity and domain matching)
- `feature_key`, `request_uuid`, and request metadata required for billing
- Prompts and model inputs may be stored server-side in billing records (`meta_json`) for
  support, fraud prevention, and cost reconciliation — see your T3Planet terms and DPA

**What must not be exposed:**

- The Bearer token is stored encrypted on your server and must never be sent to end-user
  browsers or frontend JavaScript

API base URL is resolved per environment and cached in `tx_nst3af_runtime_setting.t3planet_api_base_url`
(see `context/features/credits-api-base-url.md`). Optional override: env `T3PLANET_CREDITS_API_BASE_URL`.

## License check (separate from Credits billing)

To validate a commercial license, T3AF contacts the T3Planet license server and sends only:

- the license key
- the domain the license is used on

No content, prompts, images, IP addresses, or usage telemetry are transmitted in this
license validation call.

## Retention

- **License server:** stores the key-to-domain association needed to validate the license.
- **Credits billing (Credits mode only):** retention of billing records and optional prompt
  metadata is governed by T3Planet's credits service policies; configure Credits mode only
  if this processing is acceptable for your site and legal basis.
