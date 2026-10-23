# <Feature name> — cases

**Prefix:** `T3AF-<CODE>`
**How it works:** `context/features/<file>.md`
**Main code:** `Classes/...`
**Existing tests:** `Tests/Unit/...`, `Tests/Functional/...`

---

## Not applicable

| Row | Reason |
|---|---|
| 9 Queue / CLI | Feature has no queue or CLI entry point |

---

## Positive

### T3AF-<CODE>-001 <Short title — what is true when it works>
P1 · positive · smoke, release · auto
Rows: 1
Test: `Tests/Unit/.../SomethingTest.php::testMethod`
Given <state>
When <action>
Then <observable result, including what is saved>

---

## Negative

### T3AF-<CODE>-0NN <Short title>
P1 · negative · release · manual
Rows: 5, 6, 7
Test: none
Given <failure condition>
When <action>
Then <message shown> · <nothing saved> · <no credits charged>

---

## Edge

## Security

## AI output

---

## Field reference

| Field | Values |
|---|---|
| Priority | P0 · P1 · P2 · P3 (see `../README.md` § Severity) |
| Kind | positive · negative · edge · security · ai |
| Tags | `smoke`, `release` (zero, one or both) |
| Status | `auto` = a test covers it · `manual` = verify in backend / CLI · `gap` = should be automated, no test yet |
| Rows | Checklist rows this case answers (`../checklist.md`) |
| Test | `path::method` when `auto`; `none` otherwise |
| Ticket | Optional ClickUp link when the case came from a bug |

Rules:

- Meta line is always four fields: `P1 · positive · smoke, release · auto`. No tags → `—` in the third slot.
- One behaviour per case. Split instead of writing "and also".
- **Then** must be observable: a saved value, a message, a log row, a credit count, an HTTP status.
- Negative **Then** always says what must **not** change.
- Never renumber. Retired cases stay with `~~strikethrough~~` title and a reason.
