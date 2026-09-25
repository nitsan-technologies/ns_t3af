# AI Agent eval

Two modes, one command: `t3af:agent:eval`.

| Mode | What it checks | Costs |
|---|---|---|
| `--live` | Real scenarios (create a page with content, translate, SEO, delete, replace, …) through the real agent loop with real providers | Provider tokens / T3Planet credits, like normal agent turns |
| default | Replays the routing fixtures in `Tests/Fixtures/AgentEval/` | Nothing (no provider) |

## Live scenarios

```bash
# All scenarios with the default provider, on page 47
vendor/bin/typo3 t3af:agent:eval --live --page=47

# Every provider the agent may use on that page, with a JSON report
vendor/bin/typo3 t3af:agent:eval --live --page=47 --provider=all --report=var/log/agent-eval.json

# Only some scenarios (id or number prefix), two providers
vendor/bin/typo3 t3af:agent:eval --live --page=47 --scenario=04,05,12 --provider=openai,anthropic
```

- **Nothing is written.** Write tools only prepare cards. A scenario step `"applied"` simulates the
  editor's confirmation (the card is marked applied and the new record is added to the result),
  so the turn after a confirmation is tested as well.
- The eval runs as the `_cli_` backend user (admin) with a short-lived backend session, so
  prepared changes can be stored like in the agent window. The session is removed at the end.
- Use a test page (e.g. "Eval") with a few content elements; scenario 07 and 10 prepare a delete.
- Exit code 1 when a run fails; `WARN` and `SKIP` do not fail the command.

### What fails a turn (always checked)

- **No answer:** the turn stopped with the step limit, repeated reads or a used-up tool budget.
- The same read repeated more than once.
- An error message, more than 6 model requests, or more than 90 s.

### Scenario files

`Resources/Private/Agent/Eval/Scenarios/*.json` (one scenario per file, run in file-name order):

```json
{
  "id": "05-create-page-then-content",
  "title": "Page first, then its content with the new page uid as pid",
  "module": "web_layout",
  "requires": ["write_table"],
  "turns": [
    {
      "message": "Create a new subpage \"Eval: AI Universe vs Symfony\" with two short text content elements",
      "expect": { "anyTool": ["write_table", "t3ai_create_page_simple"], "card": true, "forbidTools": ["content_delete"] }
    },
    {
      "applied": { "table": "pages", "uid": 990001, "values": { "title": "Eval: AI Universe vs Symfony", "pid": "{{pageId}}" } },
      "expect": { "anyTool": ["write_table", "t3ai_create_content_element"], "card": true, "argumentsContain": { "pid": 990001 } }
    }
  ]
}
```

| Key | Meaning |
|---|---|
| `module` | Backend module of the context (default `web_layout`) |
| `requires` | Skip the scenario when none of these tools is installed / permitted |
| `turns[].message` | What the editor types |
| `turns[].applied` | Instead of a message: the editor applies the last card; `{table, uid, values}` is the created record |
| `turns[].lenient` | Problems in this turn are warnings, not failures |
| `turns[].skipIfCard` | Skip this turn when the previous turn already prepared a card |

`expect` keys:

| Key | Check |
|---|---|
| `anyTool` | At least one of these tools ran |
| `forbidTools` | None of these tools was called (even if refused) |
| `noTools` | No tool ran |
| `card` | `true`: a prepared change; `false`: none; `"orClarification"`: a change or a question with choices |
| `reply` | A written answer |
| `argumentsContain` | A call of an `anyTool` tool has this key/value at any depth (JSON strings are searched too) |
| `maxToolCalls`, `maxModelRequests`, `maxSeconds`, `maxRepeatedCalls` | Limits for this turn |

`{{pageId}}` in a scenario is replaced by `--page`.

Add a scenario whenever a real conversation went wrong: copy the editor's message, write down what
should have happened, and run it against the providers you support.

## Fixture replay (no provider)

```bash
vendor/bin/typo3 t3af:agent:eval
vendor/bin/typo3 t3af:agent:eval --record --id=seo-meta-keywords --message='Share meta title and keywords' \
  --tool=t3ai_generate_all_seo --field-keys=metaTitle,keywords --routing-source=embeddings --variants-complete=true
```

JSON per case: `id`, `userMessage`, `mocked` (tool / routingSource / fieldKeys / variantsComplete),
`expect` (same keys). `--dir=/path` overrides `Tests/Fixtures/AgentEval/`.

## Tests

- Unit: `AgentScenarioJudgeTest`, `AgentScenarioRunnerTest` (scripted turns, applied step, shipped scenarios are valid), `AgentEvalRunnerTest`.
- Functional: `Tests/Functional/Agent/AgentEvalCommandFunctionalTest.php` (CLI session keeps prepared changes, command writes the report).
