# i18n scan command and CI mode - design

**Date:** 2026-08-01
**Status:** approved, ready for implementation planning
**Scope:** Phase B1. Phase B2 and Phase C get their own specs.

## Why this exists

Phase A built the scanner and exposed it at `GET api/i18n/report/scan`. That
makes the report reachable from a browser or an HTTP client, and nowhere else.

The package has no artisan commands at all. Nothing can run in CI, in a deploy
script, or over SSH on a box with no web server reachable. A check that cannot
fail a build is a check nobody runs.

## Decomposition

The original Phase B list was five items, and three of them write to disk:
deterministic ordering rewrites files, the import conflict policy is a write
behaviour, and protected locales exist to guard writes. Phase A was strictly
read-only, which is what made it safe: a scanning bug could only ever produce a
wrong report, never a damaged file.

Phase B therefore splits at that line.

| | Deliverable | Writes? |
|---|---|---|
| **B1** (this spec) | The `i18n:scan` command and CI mode | No |
| **B2** | `i18n:format`, import conflict policy, protected locales | Yes |
| **C** | Quality dashboard UI | No |

B1 is useful on its own the day it lands: a CI job can fail a build on missing
translations without anything in the package gaining the ability to write.

## Decisions taken

| Decision | Choice |
|---|---|
| Command surface | One command, `i18n:scan`, narrowed with `--only` |
| Output | `table` by default, `--format=json` for machines |
| Environment gate | `enabled_environments` guards HTTP only; commands always run |
| Unreliable `unused` in CI | Refuse to report it and exit non-zero, rather than skip silently |
| Sharing with HTTP | Both call `ScanReport`; the command never calls the endpoint |

## The command

```bash
php artisan i18n:scan [--only=…] [--locales=…] [--format=table|json] [--fail] [--refresh]
```

| Flag | Meaning |
|---|---|
| `--only` | `missing`, `unused`, `dynamic`, `ambiguous`; comma-separated for several |
| `--locales` | Comma-separated; narrows `missing`, since the other three are locale-independent |
| `--format` | `table` (default) or `json` |
| `--fail` | Exit non-zero when the selected categories hold any finding, except as noted below |
| `--refresh` | Bypass the scan cache |

`--locales` takes the same comma-separated form the HTTP endpoint uses, so the
two surfaces speak one dialect.

### The environment gate does not bind commands

`enabled_environments` defaults to `['local']` and guards the HTTP surface only.
The gate exists to avoid exposing a translation-editing surface in production; a
read-only report carries no such risk, and CI usually runs under `testing`,
where a gate would silently disable the very check being added.

This was settled during Phase A's design and is restated here because it is what
makes CI mode possible at all.

## Exit codes

```
0   clean
1   --fail was given and a selected category holds findings
2   the scan is incomplete (warnings present) and unused was requested
64  usage error (unknown --only value, malformed locale)
```

**Code 2 is deliberately distinct from code 1.** "I found unused keys" and "I
cannot tell you about unused keys" are different outcomes, and a CI pipeline may
want to treat them differently. Collapsing them would make a build broken by an
unparseable file indistinguishable from a build broken by a real finding.

64 follows the `sysexits.h` convention for a usage error, which keeps it clearly
apart from both.

**When both 1 and 2 apply, 2 wins.** An incomplete scan is the more fundamental
problem: the findings that would have justified exit 1 were themselves computed
from a walk that is known to have gaps, so reporting them as the reason would
overstate what the run established.

### `--fail` never gates on `dynamic` unless asked for explicitly

`dynamic` records call sites whose key could not be read, such as `__($key)`.
That is a legitimate, common pattern, not a defect. Almost every real codebase
has some, so a `--fail` that counted `dynamic` would fail on the first run
everywhere and the flag would be worthless by default.

So: with no `--only`, `--fail` considers `missing`, `unused` and `ambiguous`,
and ignores `dynamic`. `--only=dynamic --fail` does gate on it, for a team that
has decided it wants no dynamic keys at all. `ambiguous` is included by default
because a literal that fits no store genuinely is broken.

### How the unused rule plays out

Phase A withholds `unused` whenever any file failed to parse, because a key used
only inside a file that could not be read looks unused and acting on that
deletes a key the application calls at runtime.

The command carries that through:

- `--only=unused`, or no `--only` at all, **and** warnings present: `unused` is
  not printed, the reason is stated, and the command exits `2`. This happens
  with or without `--fail`, because the problem is not whether to fail the build
  but that an untrustworthy list must not be shown at all.
- `--only=missing` with warnings present: exit `0`. Warnings do not damage
  `missing`. An incomplete scan sees fewer keys, but every key it does see is
  genuinely used and genuinely absent.
- `--only=missing,unused` with warnings present: `missing` is printed as normal,
  `unused` is withheld with its reason, and the command exits `2`. Withholding
  one category does not suppress the others; the developer still gets everything
  the run could establish, and the exit code reports the most serious outcome.

The consequence is that a project with one unparseable file cannot use the
`unused` check until that file is fixed. That is intended: the dependency is
made visible and actionable rather than silently skipped, and a green build that
skipped its only check is worse than a red one that explains itself.

## Architecture

Two new classes, plus one line in the service provider, which has never
registered a command before.

```
src/Console/Commands/ScanCommand.php   parse flags, call ScanReport, choose the exit code
src/Support/ScanOutputFormatter.php    turn a report into a table or into json
```

The command is thin and holds no logic of its own. Filtering, category
semantics and the warning rules all live in `ScanReport` from Phase A and stay
there.

The formatter is separate for two reasons. The command extends Laravel's
`Command` and cannot be tested without a container, while formatting is a pure
transformation that unit-tests cleanly. And Phase C's dashboard will need the
same transformation.

**The command does not call the HTTP endpoint.** Both surfaces call `ScanReport`
directly. Routing CLI through HTTP would make CI depend on a running web server.

## Output

### Table

One table per category, empty categories skipped entirely.

```
missing (tr)
  key                     store      group
  auth.failed             group      auth
  billing.invoice_sent    ambiguous  -

unused
  passwords.reset.sent

warnings
  app/Broken.php    Unclosed '(' on line 3
```

The `store` column comes from the field added to `missing` during Phase A's
final review. A row showing `ambiguous` **cannot be auto-created**, because
where it belongs is unknown. Surfacing the store in the table communicates that
at a glance, which a bare key list cannot.

### JSON

Byte-for-byte the structure `ScanReport::generate()` returns, with no wrapper.
The endpoint and the CLI therefore emit the same shape, and a consumer moving
between them writes no translation layer.

## Error handling

- **Unknown `--only` value:** exit `64` naming the value and listing the valid
  ones. A typo must not silently scan everything.
- **Malformed locale:** exit `64`. `LangPaths::isValidLocale()` already exists
  and is what the HTTP surface uses; reuse it rather than writing a second rule.
- **Scan warnings:** never fatal in themselves. They are printed as their own
  section and only affect the exit code through the `unused` rule above.
- **An exception escaping `ScanReport`:** allowed to surface as a normal command
  failure. Phase A already turned every per-file problem into a warning, so
  anything still escaping is a genuine fault worth a stack trace.

## Testing

Command tests use Laravel's `artisan()` helper against the existing fixture
tree. The ones that carry weight:

- **Exit code 2** when `Broken.php` is in the tree and `--only=unused` is asked
  for, together with an assertion that `unused` was not printed at all.
- **Exit code 0** in that same tree with `--only=missing`, proving warnings do
  not damage `missing`.
- **Exit 0 without `--fail` despite findings, exit 1 with it.**
- **`--format=json` output matches `ScanReport::generate()` exactly.** This is
  the test that keeps the CLI and the endpoint from drifting apart.
- **Exit 64** for a misspelled category name.

The formatter gets its own unit tests: empty categories are skipped, and an
`ambiguous` row is marked.

## Family standards

PHP `^8.4`, Laravel `^13.0`, Pest 5, PHPStan level 8 with no suppressions, Pint,
a CHANGELOG entry, and a README section covering the command, its flags and the
exit codes.
