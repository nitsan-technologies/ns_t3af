# T3AF and your data

T3AF is self-hosted. Your content, prompts, images and visitor data stay on your server.
AI requests go directly from your server to the AI provider you configure, using your own
API keys. T3Planet operates no proxy and is not in the AI data path.

## What the license check transmits
To validate a commercial license, T3AF contacts the T3Planet license server and sends only:
- the license key
- the domain the license is used on
No content, prompts, images, IP addresses, or usage telemetry are transmitted.
Endpoint: <license endpoint>. Source: <link to the exact class/method>.

## Retention
The license server stores only the key-to-domain association needed to validate the license.