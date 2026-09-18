# AI Agent eval CLI

Replay canned routing fixtures without API keys. Live LLM CI is not required.

## Commands

From a TYPO3 instance with EXT:ns_t3af loaded (package `.Build` or host `vendor`):

```bash
# Replay all fixtures (CI-friendly; non-zero exit on failure)
vendor/bin/typo3 t3af:agent:eval

# Or from the package tree when typo3 binary is available
.Build/bin/typo3 t3af:agent:eval
```

Record a stub fixture (no LLM — you supply the mocked plan):

```bash
vendor/bin/typo3 t3af:agent:eval --record \
  --id=seo-meta-keywords \
  --message='Share meta title and keywords' \
  --tool=t3ai_generate_all_seo \
  --field-keys=metaTitle,keywords \
  --routing-source=embeddings \
  --variants-complete=true
```

Optional `--dir=/path/to/fixtures` overrides `Tests/Fixtures/AgentEval/`.

## Fixture shape

JSON per case: `id`, `userMessage`, `mocked` (tool / routingSource / fieldKeys / variantsComplete), `expect` (same keys to assert).

Shipped samples live under `Tests/Fixtures/AgentEval/`. Unit coverage: `Tests/Unit/Agent/AgentEvalRunnerTest.php`.

## CLI name

Only `t3af:agent:eval` (no `nst3af:` alias), same as `t3af:agent:index-tools`.
