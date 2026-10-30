# CLI Terminal UX Refactoring Context

## Purpose

Use this context when improving the terminal output of an existing PHP CLI command, especially a TYPO3 extension command or TYPO3 Scheduler-related workflow.

The goal is to make command output clear, consistent, accessible, and useful during normal operation and debugging, while preserving the command's existing behavior.

This file is reusable guidance, not permission to redesign the application. Always inspect the repository and the target command before making changes.

---

## 1. Core objectives

Improve the command-line user experience so that users can quickly understand:

- Which command or workflow is running.
- Which phase is currently active.
- What work is being performed.
- Whether each operation succeeded, was skipped, produced a warning, or failed.
- How much work has completed, when progress can be measured reliably.
- What the final outcome was and what action, if any, the user should take next.

Prefer clear, concise output over decorative output. The CLI should remain professional and easy to scan in a terminal, CI logs, and redirected output.

## 2. Inspect before editing

Before changing code, inspect:

- `composer.json` and `composer.lock`.
- The PHP and TYPO3 versions supported by the extension.
- The target command class and its registration/configuration.
- Any related Scheduler task, command controller, service, or orchestration class.
- Existing output, logging, reporting, and exception-handling mechanisms.
- Existing tests and coding standards.
- The command's callers, scripts, documentation, and CI usage where discoverable.

Trace the current execution path. Identify which layer owns business logic and which layer owns terminal presentation.

Do not assume that the project is a standalone Symfony application. TYPO3 commands commonly use Symfony Console APIs through TYPO3's command infrastructure. Follow the project's existing conventions and supported TYPO3 version.

Summarize the current architecture and propose a minimal implementation approach before editing.

## 3. Consider a reusable base class or helper

Before implementing formatting separately in each command, inspect all CLI commands and Scheduler tasks in the extension and determine whether a shared abstraction would reduce duplication.

If it is appropriate for the existing TYPO3 architecture, introduce a small reusable abstract command base class, output helper, or service that standardizes common presentation behavior across commands and Scheduler-related workflows.

Potential shared responsibilities include:

- Consistent success, info, warning, and error messages.
- Standard phase headings and result summaries.
- Progress bars or indicators where appropriate.
- Consistent tables and status labels.
- Verbosity-aware diagnostic output.
- Safe handling of ANSI-disabled and non-interactive output.

Choose the least complex abstraction that fits the codebase. Do not force unrelated commands or Scheduler tasks to inherit from a common class merely to share formatting.

TYPO3 Scheduler tasks may not share the same execution lifecycle or output capabilities as Symfony Console commands. Keep terminal presentation separate from reusable business logic, and do not assume that a Scheduler task can directly use command input/output objects. If commands and Scheduler tasks share business services, keep those services independent of terminal rendering. Provide a common output helper only where it is compatible with the actual execution contexts.

Before creating the abstraction:

1. Identify which existing commands/tasks would use it.
2. Check whether TYPO3 or the project already provides a suitable base class or helper.
3. Explain the proposed API and why it fits the supported TYPO3 versions.
4. Prefer composition through a helper/service when inheritance would couple unrelated command lifecycles.
5. Keep the abstraction small and extend it only when a real second use case exists.

Test the shared abstraction directly and verify that adopting it does not change command registration, Scheduler execution, business behavior, or exit codes.

---

## 4. Choose the right output tools

First determine what is already installed and how the command is built.

### Preferred options

Use the existing Symfony Console integration when available:

- **`SymfonyStyle`** for consistent titles, sections, notes, warnings, errors, success messages, and tables.
- **`ProgressBar`** when the total number of steps is known and each step can be advanced reliably.
- **`ProgressIndicator`** only for genuinely long-running operations where its animation can actually update. Do not use a PHP spinner around a synchronous blocking call if the terminal cannot be refreshed while the call is blocked.
- **`Table`** for structured, multi-item results that benefit from aligned columns.
- **Console verbosity levels** for controlling diagnostic detail.
- **Symfony Console input/output options** for non-interactive and no-ANSI execution, following the project's existing command setup.

Do not add a dependency. `typo3/cms-core` already requires `symfony/console`, and every command in this suite extends `Symfony\Component\Console\Command\Command`. `SymfonyStyle`, `ProgressBar`, `Table`, and `ProgressIndicator` are already available. Do not introduce a second terminal UI library.

Keep arguments and options in `configure()` via `addArgument()` and `addOption()`. That is what every command in this suite does, and it works on the whole supported range. `#[Argument]`, `#[Option]`, and invokable `__invoke()` commands arrived in Symfony Console 7.3. TYPO3 13.4.0 only requires `symfony/console` ^7.1.4, so those APIs are not safe for every `^13.4` install. Current 13.4 and 14.3 both require Console ^7.4. On 7.4, overriding `getDefaultName()` or `getDefaultDescription()` is deprecated. This suite does not override them. `setName()`, `setDescription()`, and `configure()` are still valid.

Section 15 records how this actually runs on TYPO3 13.4 and 14.3.

## 5. Recommended output structure

Use a consistent hierarchy, adapted to the command's actual work:

1. Short command or workflow title.
2. Current phase, if the command has meaningful phases.
3. Progress indicator, only when useful.
4. Concise operation-level results, if users need to see them.
5. Final summary based on actual results.
6. A next-step note only when the user needs to act.

Example structure:

```text
Extension maintenance
=====================

Checking configured items
  [progress indicator when appropriate]

Results
  Item                         Result
  Example item                 Completed
  Another item                 Skipped · no change required
  Third item                   Failed · reason available in verbose output

Summary
  Items checked                12
  Completed                     8
  Skipped                       3
  Failed                        1

Next step: Review the failed item and rerun the command.
```

The example is illustrative only. Use actual domain terminology and actual execution data. Do not invent metrics, outcomes, or next steps.

For a simple command, avoid unnecessary headings, tables, or progress bars. The output should fit the task rather than forcing every command into the same layout.

## 6. Status semantics

Use consistent distinctions between these outcomes where applicable:

- **Success / completed:** The operation actually completed successfully.
- **Up to date / no change:** The operation was checked and no change was needed.
- **Skipped:** The operation was intentionally not performed, with a clear reason where useful.
- **Warning / unavailable:** The operation can continue, but a condition deserves attention.
- **Failed:** The operation did not complete successfully.
- **Pending / requires review:** A human or later workflow step must act before the work can continue.

Do not report a successful outcome merely because an operation was attempted. Derive labels and counts from the actual result/status returned by the underlying service.

A recoverable fallback should generally be a compact warning, not a large, visually dominant block. Keep genuinely important errors easy to notice. Preserve the meaning and severity of existing warnings.

## 7. Progress indicator rules

Use a progress bar only when the workload is measurable and the application can determine when each unit of work has completed.

- Determine the total before starting the bar whenever possible.
- Advance exactly once per completed unit, including units that fail or are skipped, if those units were part of the declared workload.
- Do not advance twice because both a callback and a result handler report the same operation.
- Do not mark work complete before it has actually completed.
- Handle zero-item workloads without division errors, stuck bars, or misleading output.
- Make the current item visible only if doing so is useful and does not make the output excessively noisy.
- Ensure failures do not leave the bar in a misleading state.
- Do not add progress bars to very short operations merely for decoration.

For unknown-duration work, use an indicator only if it can visibly update. Otherwise show a clear phase message and report the result when the blocking operation returns.

## 8. Output modes and terminal compatibility

Verify the command in these modes where supported:

- Normal interactive terminal.
- ANSI disabled / plain-text output.
- Non-interactive execution, including CI and scheduled execution.
- Quiet mode.
- Verbose and debug modes.

Rules:

- Do not require interactive input for unattended execution.
- Do not rely on color alone to communicate success or failure.
- Keep redirected logs readable; avoid repeated carriage-return output where it becomes confusing.
- Follow the project's existing quiet/verbose semantics.
- Normal output should show useful outcomes without overwhelming users with implementation details.
- Verbose output may include diagnostic context, safe file paths, or detailed warnings.
- Never print secrets, API keys, credentials, authorization headers, or sensitive records.

Do not introduce new CLI options unless they are needed, consistent with the project's conventions, and documented.

## 9. TYPO3-specific considerations

When the target is a TYPO3 extension command or Scheduler-related command:

- Preserve the existing command identifier, registration, arguments, and options unless a change is explicitly required.
- Respect the extension's supported TYPO3 and PHP versions.
- Follow the repository's namespace, dependency injection, service configuration, and command registration conventions.
- Keep business logic in the existing service/domain layer; terminal formatting belongs in the command or a small reusable presentation helper.
- Do not couple Scheduler business logic to terminal-specific formatting.
- Consider that a command may be executed by a human, TYPO3 Scheduler, a deployment script, or CI.
- Do not assume a human is watching the terminal during Scheduler execution.
- Keep logs and persistent reports separate from transient console output.
- Preserve existing logging, report paths, database writes, email/notification behavior, and task lifecycle.
- Ensure exceptions are still handled and logged according to the existing architecture.
- Do not change Scheduler task configuration or execution frequency as part of a presentation-only task.
- If a Scheduler task invokes the same service as a CLI command, avoid duplicating the business logic just to improve CLI output.

A command's output should remain useful when run manually, while scheduled executions should not depend on animation or terminal interaction.

Section 15 is the checked 13.4 / 14.3 behavior for `ns_t3af` and its child extensions. When this section and section 15 disagree, follow section 15.

## 10. Preserve behavior

Treat this as a presentation refactor unless the user explicitly asks for functional changes.

Do not change, as a side effect:

- Business rules or domain calculations.
- Database queries, writes, or transaction boundaries.
- TYPO3 configuration, TCA, command registration, or Scheduler task setup.
- External API calls, their order, or their retry behavior.
- Authentication, permissions, authorization, or approval gates.
- File paths, report formats, generated artifacts, or cleanup behavior.
- Notifications, emails, webhooks, or other integrations.
- Existing command names, arguments, options, and documented usage.
- Exit codes or exception behavior without investigating compatibility.

If a behavior defect is discovered, document it separately. Do not silently bundle unrelated functional changes into the UI refactor.

## 11. Exit codes and failure handling

Inspect the current exit-code contract and all discoverable callers before changing it.

- Preserve existing exit codes unless a deliberate, justified change is approved.
- Distinguish a recoverable per-item failure from a fatal command startup failure according to current project semantics.
- Do not swallow exceptions simply to keep the output attractive.
- Ensure failure messages and summary counts agree with the actual exit status.
- Consider whether shell scripts and CI use `set -e` or chain commands.
- Document any proposed exit-code change and its compatibility risks before implementing it.

A clearer UI must not silently change how scripts or scheduled jobs interpret success.

## 12. Testing requirements

Inspect the project's test setup and coding standards. Add or update tests for the changed presentation behavior.

Cover relevant cases such as:

- Successful execution.
- No work / empty workload.
- Skipped operations.
- Recoverable warnings and fallback behavior.
- One or more failed items.
- Fatal startup or configuration failure.
- Accurate final counts and status labels.
- Progress advancement exactly once per completed item.
- ANSI-disabled and non-interactive output.
- Quiet and verbose output, where supported.
- Exit-code compatibility.
- Preservation of the underlying business behavior.

Use mocks or fixtures for external services. Do not make real API calls, modify production data, send real notifications, or execute destructive actions merely to test formatting.

Run the relevant test suite, static analysis, and coding-standard checks available in the repository. Report the exact commands and outcomes. Never claim an unrun test passed.

## 13. Review the diff carefully

Before finishing:

- Review every modified file and the complete diff.
- Separate presentation changes from unrelated behavioral changes.
- Confirm that no dependency or lockfile changed unnecessarily.
- Check that logs and reports still contain the expected information.
- Verify that the command can still be called using its existing syntax.
- Identify any test or integration that could not be verified.

Do not discard, reset, stash, or overwrite pre-existing user changes. Do not commit unless explicitly asked.

## 14. Final report format

Conclude with a concise report containing:

1. **Architecture:** Where output was previously generated and where it now lives.
2. **Selected features:** Which Console components were used and why.
3. **Files changed:** Each file and the purpose of its change.
4. **Behavior preservation:** Which critical behaviors were checked.
5. **Tests:** Exact commands and actual results.
6. **Example output:** A representative sample matching the implementation.
7. **Unverified areas:** Any live integrations, scheduled execution paths, or tests not run.
8. **Merge recommendation:** Ready, ready with caveats, or needs further work, with reasons.

Clearly distinguish verified facts from assumptions.

---

## 15. TYPO3 13.4 and 14.3 in this suite

Checked against the installed core and the 13.4 branch. This is how commands actually run. It is not a proposal.

### What was checked

| Source | Result |
|---|---|
| This checkout | TYPO3 **14.3.9**, `symfony/console` **v7.4.21** |
| TYPO3 14.3 core `composer.json` | `php` ^8.2, `symfony/console` ^7.4.8 |
| TYPO3 13.4.0 core `composer.json` | `php` ^8.2, `symfony/console` ^7.1.4 |
| Current TYPO3 13.4 branch `composer.json` | `symfony/console` ^7.4 |
| `TYPO3\CMS\Core\Console\Command` | Absent on 14.3 and on the 13.4 branch. Commands extend `Symfony\Component\Console\Command\Command`. |

Package constraints:

| Package | TYPO3 | PHP | Owns CLI commands |
|---|---|---|---|
| `ns_t3af` | `^13.4 \|\| ^14.3` | `>=8.2 <9` | Yes |
| `ns_t3ai` | `^13.4 \|\| ^14.3` | `>=8.2 <8.6` | Yes |
| `ns_t3aa` | `^13.4 \|\| ^14.3` | `>=8.2 <8.6` | Yes |
| `ns_t3cs` | `^13.4 \|\| ^14.3` | `>=8.2 <9` | Yes. Also the shared output base. |
| `ns_t3ac` | `^13.4 \|\| ^14.3` | `>=8.2 <9` | No. Uses `ns_t3cs`. |
| `ns_t3as` | `^13.4 \|\| ^14.3` | `>=8.2 <9` | No. Uses `ns_t3cs`. |

None of these packages support TYPO3 12. The floor is 13.4. A helper in `ns_t3cs` must work on 13.4 and 14.3. The 13.4 scheduler path stays, because 13.4 still stores a serialized task.

### How a command runs on both 13.4 and 14.3

`vendor/bin/typo3` boots `TYPO3\CMS\Core\Console\CommandApplication`. A normal command gets a full container boot, a CLI backend user (`CommandLineUserAuthentication`), and `ob_end_clean()` so output is not buffered. The Symfony application runs with `ArgvInput` and `ConsoleOutput`. Exit codes above 255 are clamped to 255.

Registration is the DI tag `console.command`. `ConsoleCommandPass` collects those tags into `TYPO3\CMS\Core\Console\CommandRegistry` and marks the service public. The first tag is the command name. Later tags are aliases when `alias: true` is set, or when a name was already taken.

`#[AsCommand]` is autoconfigured by core `Configuration/Services.php` on both 13.4 and 14.3. A `|` in the attribute name is the primary name plus aliases (`t3af:mcp:cleanup|nst3af:mcp:cleanup`). The tag's `schedulable` value defaults to `true`.

Arguments and options are declared in `configure()`. `SymfonyStyle` is constructed inside `execute()` from the command's input and output. Nothing in core wraps that for extensions.

### What 14 changes

**Non-schedulable commands.** TYPO3 14.0 adds `#[AsNonSchedulableCommand]` (`TYPO3\CMS\Core\Attribute\AsNonSchedulableCommand`). `CommandRegistry::addLazyCommand()` sees that attribute and forces `schedulable` off. The attribute does not exist on 13.4. On 13.4 the only switch is `schedulable: false` on the `console.command` tag. This suite does not use the attribute. It uses the tag.

**Scheduler storage.** On 13.4 a task is still a serialized object in `tx_scheduler_task`. On 14.0+ the same table is a native TCA record: `tasktype` holds the task class or the CLI command name, and `parameters` is JSON (`Feature #106739`). An upgrade wizard migrates old rows. `ns_t3cs` `SchedulerCommandTaskHelper` writes through `TaskService::getFieldsForRecord()` plus DataHandler when that method exists, and through the old repository path when it does not.

**Looking up a scheduled command.** 14.3 `ExecuteSchedulableCommandTask::execute()` calls `CommandRegistry::get()` and catches `CommandNotFoundException`. 13.4 calls `getCommandByIdentifier()` and catches `UnknownCommandException`. After that lookup, both versions do the same thing with the command.

**Scheduler module label.** In v14 the Scheduler module sits under Administration. In v13 it sits under System. That is a backend label change. It does not change CLI execution.

### Scheduler output on both versions

Core `ExecuteSchedulableCommandTask` builds an `ArrayInput`, calls `setInteractive(false)`, and runs the command with `NullOutput`. The task succeeds only when `run()` returns `0`. Anything written to that output is discarded. Questions, progress animation, and color on that output never reach a human.

The suite's own scheduler tasks do the same. They resolve the command from `CommandRegistry` and call `run($input, new NullOutput())`:

- `ns_t3ai`: `SiteScanTask`, `AbstractBulkTranslateSchedulerTask`, `BulkMassSeoOptimizeTask`
- `ns_t3aa`: `MonitorScanTask`, `BulkVoiceoverTask`

Two command bases already notice `NullOutput` and copy lines to STDERR when `Environment::isCli()` and `STDERR` is defined. In a web or backend context they keep `NullOutput`, because `STDERR` is not usable there.

- `NITSAN\NsT3Cs\Command\AbstractCommand` — color tags, `ProgressBar` only when the output is not `NullOutput`, STDERR `StreamOutput` with decoration forced on for the scheduler path.
- `NITSAN\NsT3Ai\Command\AbstractBulkTranslateCommand` — the same `NullOutput` to STDERR split for translate and retranslate.

A progress bar or `SymfonyStyle` section that only writes to the injected `$output` is visible for `vendor/bin/typo3 …` and invisible when Scheduler or these tasks run the same command. The STDERR copy is the existing scheduled-run log. Preserve it.

### Commands

`ns_t3af` registers with `#[AsCommand]` only. No `console.command` tags. Default `schedulable` is true. Several commands build a `SymfonyStyle`.

| Command | Aliases |
|---|---|
| `t3af:mcp:cleanup` | `nst3af:mcp:cleanup` |
| `t3af:mcp:serve` | `mcp:server`, `nst3af:mcp:serve` |
| `t3af:agent:index-tools` | |
| `t3af:agent:eval` | |
| `t3af:agent:conversations:cleanup` | |
| `t3af:ailabel:export` | |
| `t3af:ailabel:audit` | |
| `t3af:ai-logs:cleanup` | `nst3af:ai-logs:cleanup`, `nst3ai:log:clear`, `nst3ai:clean:ailog`, `ns_t3ai:clean:ailog` |

`SchedulerCliCommandCatalogService` lists `t3af:*` names from `CommandRegistry` for the backend. It hides the retired log-cleanup aliases from that listing. Do not rename commands or drop aliases as part of a presentation change.

`ns_t3ai` registers in `Configuration/Services.yaml` and `Configuration/Services.php`.

| Command | Aliases | Schedulable |
|---|---|---|
| `t3af:cache:flush` | `nst3ai:cache:flush` | false |
| `t3af:scan:site` | | true. `SiteScanTask` runs it with `NullOutput`. |
| `t3af:exception:solve` | `nst3ai:exception:solve` | false. Registered only when the `OpenAI` class exists. |
| `t3af:glossary:list` | `nst3ai:glossary:list` | false |
| `t3af:glossary:remove` | `nst3ai:glossary:remove` | true |
| `t3af:bulk:seo-optimize` | `nst3ai:bulk:seo-optimize` | true on v13, false on v14 |
| `t3af:bulk:translate` | `nst3ai:bulk:translate` | true on v13, false on v14 |
| `t3af:bulk:retranslate` | `nst3ai:bulk:retranslate` | true on v13, false on v14 |

The v14 split is `version_compare` on `version_main` in `ns_t3ai` `Configuration/Services.php`. On v14 those three bulk commands stay callable from the shell, and the description points at the native Scheduler task. Do not flip `schedulable` while changing output.

`ns_t3aa` registers in `Configuration/Services.yaml`. All four are schedulable on 13 and 14. `MonitorScanTask` and `BulkVoiceoverTask` still run the command with `NullOutput`.

| Command | Aliases |
|---|---|
| `nst3aa:regenerate-widget` | |
| `nst3aa:bulk:metadata` | `aiuniverse:bulk:metadata` |
| `nst3aa:monitor:run` | |
| `nst3aa:bulk:voiceover` | |

`ns_t3cs` registers in `Configuration/Services.yaml`. `TrainingCommand` extends `AbstractCommand` and is the heaviest output path (progress bar, per-item lines, cleanup phase).

| Command | Aliases | Schedulable |
|---|---|---|
| `nst3af:training` | `aiuniverse:training`, `t3cs:training` | true |
| `t3cs:keyword:compounds` | | false |
| `t3af:history:cleanup` | `t3cs:history:cleanup` | true |

`ns_t3ac` and `ns_t3as` have no command classes. Chatbot and search training is `nst3af:training`.

### Rules that follow from this

- Use `SymfonyStyle`, `ProgressBar`, and `Table` from the existing `symfony/console`. Do not add a package.
- Keep `configure()` / `addArgument()` / `addOption()`.
- Do not adopt `#[Argument]`, `#[Option]`, or `__invoke()`-only commands while `^13.4` includes 13.4.0.
- Do not use `#[AsNonSchedulableCommand]` in code that also runs on 13.4. Keep `schedulable` on the service tag.
- Do not change command names, aliases, `schedulable`, or the exit-code contract. Scheduler treats any non-zero code as a failed task.
- Interactive prompts must not run when input is non-interactive. Scheduler always sets that.
- A progress bar belongs only on output that is not `NullOutput`. Scheduled runs already use the STDERR copy in `AbstractCommand` and `AbstractBulkTranslateCommand`.
- `ns_t3af` commands do not extend `AbstractCommand`. Do not force them onto the `ns_t3cs` base. A shared helper has to work for a plain Symfony command and for that base, on TYPO3 13.4 and 14.3.

---

## Task-specific instructions to fill in before use

- **Extension / package:** [name]
- **Target TYPO3 version(s):** [version range]
- **Target command identifier:** [command identifier]
- **Related Scheduler task or service:** [class/service, if known]
- **Current problem:** [describe the current terminal output issue]
- **Desired outcome:** [describe what should be easier to understand]
- **Constraints:** Preserve existing behavior unless explicitly approved otherwise.

Begin by inspecting the repository and reporting the current command architecture and proposed minimal changes. Then implement, test, and report. Do not stop at a plan unless a genuine blocker requires clarification.
