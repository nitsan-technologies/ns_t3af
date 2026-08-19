# Licensing

## The short version

AI Foundation (`ns_t3af`) is free and open source under **GPL-2.0-or-later**.
It is published on the TYPO3 Extension Repository and on public GitHub.
There is no price, no domain limit and no time limit.

**It does require a free licence key to run.** The key costs nothing, carries no
commercial terms, no domain limit and no expiry.

## Why a free extension needs a key

Honest answer: it is an architectural constraint, not a commercial gate.

`ns_license` is our licence manager, and it is shared across the whole AI Universe.
It licenses our premium extensions, and it carries the AI Credits entitlement (the
optional prepaid balance you can buy if you would rather not manage provider API keys
yourself). AI Foundation is not currently separable from it, so installing AI Foundation
means installing `ns_license` and entering a free key.

We would rather it did not, and we may change it. Right now it does, and telling you
that plainly is better than letting you discover it after installing.

Note also that this is GPL-2.0-or-later: you may fork this repository and remove the
check. The key is a registration mechanism, never a protection mechanism, and we do not
describe it as one.

## What the licence check sends us

Your licence key and your domain. Nothing else.

No content. No prompts. No images. No IP addresses. No telemetry.

## Where your AI content goes

**Bring your own keys, the default.** Your content goes from your server directly to
the AI provider you configured, using your own API key. That provider bills you. No
T3Planet server sits in the data path.

**AI Credits, optional.** A prepaid balance bought from T3Planet, for teams who would
rather not manage provider keys. In this mode we route the request, so the statement
above does not apply to it. It is never required and it is not the default.

## The rest of the AI Universe is commercial

AI Foundation is free. The extensions that build on it (AI Assistant, AI Chatbot,
AI Search, AI Accessibility, AI Localization, AI Builder) are premium products.
Free foundation, paid children.

## Security

Report a vulnerability to security@t3planet.de. We acknowledge within three business days.
See SECURITY.md.

## Files

- `LICENSE` — the GPL-2.0-or-later text, which governs this code.
- This file — what that means in practice.
