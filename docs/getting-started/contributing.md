---
eyebrow: 'Docs · Getting started'
lede:    'You don''t have to maintain a fork to help. The roadmap lists concrete, well-scoped tasks — most of which can be completed without touching protocol-level code. Start there, especially with the additional Part 13 aggregate functions.'

see_also:
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/ROADMAP.md',     meta: 'external', label: 'ROADMAP.md' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/CONTRIBUTING.md', meta: 'external', label: 'CONTRIBUTING.md' }
  - { href: '../operations/client-side-aggregates.md',                              meta: '7 min' }

prev: { label: 'Thinking in OPC UA',        href: './thinking-in-opc-ua.md' }
next: { label: 'Endpoints and discovery',   href: '../connection/endpoints-and-discovery.md' }
---

# Start contributing

This library is small, focused, and explicitly designed to be extended
by the community. If you've read the **Getting started** pages and you
want to give something back, this page tells you the cheapest way in.

The short version:

1. Read [`ROADMAP.md`](https://github.com/php-opcua/opcua-client/blob/master/ROADMAP.md) at the repo root.
2. Pick an item — the suggestions below are the ones with the lowest
   barrier to a useful first PR.
3. Read [`CONTRIBUTING.md`](https://github.com/php-opcua/opcua-client/blob/master/CONTRIBUTING.md) for dev-setup, coding style,
   test layout, and PR checklist.
4. Open an issue first if your change touches more than one file or
   alters a public contract — it avoids duplicate work and gives a
   forum for design feedback before code review.

## Best first PR — a new Part 13 aggregate

`AggregateModule` (see
[Client-side aggregates](../operations/client-side-aggregates.md))
currently implements **five** aggregate functions: `Interpolate`,
`Minimum`, `Maximum`, `Average`, `Count`. OPC UA Part 13 defines
**dozens** more — time-weighted averages, ranges, deltas, quality
counters, transition counts, percent-good/bad windows, standard
deviation, variance, and so on. The roadmap lists every one of them
with a direct link to the canonical Part 13 definition.

**Why this is a great first contribution:**

- **Self-contained.** Each addition is one calculator class, one enum
  case, and one entry in `AggregateModule::$calculators`. No impact on
  the rest of the codebase.
- **Mechanically clear.** The Part 13 specification gives you the
  exact mathematical definition; you transcribe it into PHP and the
  Calculator interface tells you the shape. No protocol decoding, no
  binary encoding, no network state to reason about.
- **Easy to test.** Existing calculators have unit tests in
  `tests/Unit/Module/Aggregate/` — copy one, adapt the fixtures from
  Part 13's worked examples, done.
- **Visible impact.** Anyone running historian queries against a
  server that doesn't expose `HistoryRead Processed` immediately gets
  your aggregate.
- **No upstream sign-off needed.** The Part 13 catalogue is fixed; the
  spec is the spec. There's no design discussion to wade through.

### Anatomy of an aggregate calculator

<!-- @code-block language="text" label="files touched" -->
```text
src/Module/Aggregate/
├── AggregateFunction.php                        ← add the enum case
├── AggregateModule.php                          ← register the calculator
└── Calculator/
    ├── AbstractAggregateCalculator.php          ← reuse the bucket-status helpers
    └── YourNewCalculator.php                    ← the new file
```
<!-- @endcode-block -->

The contract is `AggregateCalculatorInterface::compute(Interval $window, AggregateOptions $opts, DataValue[] $rawValues): DataValue`.
You receive the window, the options, and the full raw buffer; you
return one `DataValue` for that window. `AbstractAggregateCalculator`
already gives you the `percentDataBad/Good` accounting and the helper
that ORs Part 11 InfoBits (`HistorianCalculated`, `HistorianPartial`,
…) into the result's `statusCode`.

### Priority list

The roadmap suggests this order, by perceived usefulness:

| Tier | Aggregates                                                                                  |
| ---- | ------------------------------------------------------------------------------------------- |
| 1    | `TimeAverage` / `TimeAverage2` — what most users actually expect from "Average"             |
| 1    | `Range` / `Range2` — `max − min`                                                            |
| 1    | `Delta` / `DeltaBounds` — last − first (or bounds)                                          |
| 2    | `DurationGood` / `DurationBad` / `PercentGood` / `PercentBad` — quality coverage            |
| 2    | `Total` / `Total2` — integral (energy/flow)                                                 |
| 2    | `StandardDeviation*` / `Variance*` — Sample and Population                                  |
| 3    | `Start` / `End` / `StartBound` / `EndBound` — first/last (or interpolated bounds)           |
| 3    | `MinimumActualTime*` / `MaximumActualTime*` / `Minimum2` / `Maximum2` — Min/Max variants    |
| 3    | `NumberOfTransitions` — value changes (discrete signals)                                    |
| 3    | `DurationInStateZero` / `DurationInStateNonZero` — time at 0 / ≠ 0 (boolean signals)        |
| 4    | `WorstQuality` / `WorstQuality2` — worst StatusCode observed                                |
| 4    | `AnnotationCount` — requires annotation-history support                                     |

The `2` suffix denotes the v1.03+ variants that include
`StartBound`/`EndBound` in the candidate set and propagate quality
more rigorously than the legacy v1 forms. Pick one; ship it; ship the
next.

## Want to ship a whole new extension?

If you have a use case that needs a service set the library doesn't
cover — Query, ProgramStateMachine, a vendor-specific extension that
uses non-standard service NodeIds, or just a higher-level helper —
the `ServiceModule` system is built precisely for that. Each module is
self-contained (protocol services + DTOs + methods registered via
`Client::registerMethod()`) and can be added in-tree as a built-in or
shipped as a separate Composer package.

Read [Extensibility · Modules](../extensibility/modules.md) for the
contract, the lifecycle (`register` → `boot` → `reset`), the kernel
surface available to modules (`executeWithRetry`, `dispatch`, cache
helpers, …), and worked examples from the eight built-in modules.

## Other welcoming starting points

The roadmap has more than aggregates. The ones that are similarly
low-friction:

- **IDE helper stub generator** — a `composer generate-ide-helper`
  command (or `vendor/bin/opcua-ide-helper`) that emits a
  `_ide_helper_opcua.php` with `@method` PHPDoc lines for `Client`,
  reflecting the registered modules. Useful for autocomplete and
  static analysis on `__call()` methods like the ones `AggregateModule`
  exposes. Self-contained, no protocol work.
- **PHPStan level 10** — the codebase already passes level 9
  (`composer phpstan`, no baseline, no ignores). Level 10 treats
  implicit `mixed` strictly; the open problem is typing the dynamic
  method dispatch behind `Client::__call()`. Design-sensitive but
  well-scoped.
- **Documentation** — every page in `docs/` is open to improvement.
  Spotted a stale claim? Open a PR.

## Conventions you'll meet

When you open the codebase:

- **Strict types everywhere.** `declare(strict_types=1);` on every
  file.
- **PSR-12** with one carve-out: **no inline body comments**. Names
  carry the intent; only public-facing PHPDoc blocks describe
  contracts.
- **Pest PHP** for tests, with target coverage ≥ 99.5 %.
- **PHPStan level 9** — `composer phpstan` must pass with no baseline
  entries and no `@phpstan-ignore` comments.
- **Public API contracts** live on `OpcUaClientInterface` and on
  module method signatures — changing them is a breaking change.
- **Custom modules** register methods on the `Client` (not on the
  kernel) — see [Modules](../extensibility/modules.md). Same applies
  to in-tree additions.

`CONTRIBUTING.md` has the canonical version of all of this, plus the
test-server setup (`uanetstandard-test-suite`) you need for
integration tests.

## Where to ask

- **Issue with a clear scope** — open a GitHub issue, label it
  `roadmap` if it's already on the list.
- **Design question** — open a GitHub Discussion before writing code
  that touches the public API or a hot path.
- **Bug report** — open an issue with a wire capture if you can; the
  binary protocol is unforgiving.

Welcome aboard.
