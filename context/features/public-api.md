# Feature — Public API (AiServiceInterface)

**Status:** Done (semver-stable surface)  
**Deep spec:** `Documentation/Api/PublicApi.rst`, `context/specs/FEATURE_AiProviderManagement.md` §CC-6  
**Architecture:** `context/architecture.md`

---

## What it does

- Single entry point for child extensions: `complete()`, `stream()`, `embed()`.
- Resolves provider by `AiOptions::$providerIdentifier` or default row.
- Dispatches PSR-14 lifecycle events; optional response cache.
- Attributes calls via `extensionKey`, `featureKey` for telemetry and credits.

---

## Key paths

| Piece | Path |
|---|---|
| Interface | `Classes/Api/AiServiceInterface.php` |
| Options DTO | `Classes/Api/AiOptions.php` |
| Responses | `Classes/Api/AiResponse.php`, `EmbeddingResponse.php` |
| Implementation | `Classes/Service/AiService.php` |
| Events | `Classes/Event/BeforeProviderRequestEvent.php`, etc. |

---

## Usage pattern

```php
use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiServiceInterface;

$response = $aiService->complete(
    'Summarize this page.',
    new AiOptions(
        modelId: 'gpt-4o',
        temperature: 0.3,
        extensionKey: 'ns_t3ai',
        featureKey: 'seo.meta_description',
    ),
);
$text = $response->content;
```

---

## Do / Don't

**Do:** Inject `AiServiceInterface` via DI or `GeneralUtility::makeInstance()`.

**Don't:** Use `BaseClient` or `AiRequestService` in new code (deprecated facades).

---

## Verification

Unit tests under `Tests/Unit/` for services and API DTOs. See `tasks/run-quality.md`.
