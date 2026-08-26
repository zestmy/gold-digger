# AI Integration

Nine call sites, one client, one API key, and a single rule that shapes all of them: **the
model never produces a number that becomes a price.**

Everything is off unless `OPENROUTER_API_KEY` is set. An unconfigured analyst is not an
error — the cards say so and the rest of the dashboard is unaffected.

---

## Why OpenRouter rather than a provider SDK

One key and one wire format in front of every model, so changing model is an `.env` edit
rather than a deploy. The API is OpenAI-shaped; `response_format: json_schema` with
`strict: true` is what keeps answers in typed fields instead of one paragraph.

`App\Services\Ai\OpenRouter` is the only thing that talks to it. Every caller hands it a
system prompt, a brief, a schema name and a JSON Schema, and gets back
`{ok, data, error, model}`. It never throws — a failure is a value the UI can render.

It distinguishes the failures that send you to different places: `401` is a rejected key,
`402` is exhausted credit, a non-JSON body is "the model ignored the schema" rather than a
parse error that reads like the dashboard is broken. The response's `model` field is the
model that *actually* served the request, because OpenRouter reroutes when the requested
one is unavailable and a surprising answer is worth being able to attribute.

---

## The rule: judgement yes, measurement no

Direction is a judgement about evidence, which language models can do. A price level is a
measurement, which they cannot. Every integration splits along that line, and each one
enforces it by construction rather than by asking nicely:

| Surface | What the model decides | How a wrong number is made impossible |
|---|---|---|
| `AutonomousTrader` | Whether there is a trade, and its direction | Stop comes from ATR; targets are multiples of that stop |
| `ChartAnalyst` | Which levels matter, and a plan | Picks levels **by index** from pivots `Structure` measured |
| `ScanAnalyst` | Which of a ranked shortlist to prefer | Names candidates **by number**; prices substituted back here |
| `StrategyProposer` | Candidate parameter sets | Filtered against `ParameterGrid::SWEEPABLE`; `WalkForward` judges |
| `FollowUpInterpreter` | Which of six actions a reply means | Closed enum; anything unmappable returns `none` |
| `ImageSignalReader` | What the screenshot says | Transcribe not interpret; refuses unclear reads; coherence-checked |
| `EditInterpreter` | What a provider changed | Returns a reading and writes it down; never acts |
| `SignalReviewer` | Whether a copied signal is worth taking | Every deterministic gate runs first, in code |
| `PairAnalyst` | Prose describing computed numbers | Nothing it returns reaches `SignalGenerator` |

The worst outcome available to any of them is preferring a worse *real* setup — an ordering
to argue with, not an invented instrument, level or ratio.

### The model can decline, never approve

`SignalReviewer` is the clearest statement of the pattern. Closed session, news blackout,
exhausted fund, a signal too old to still be about this market, a price that has already
run past the entry — all are checked before the model is asked anything. It can only ever
decline something the gates already allowed; it is never in a position to approve something
they blocked.

That ordering is the design, not an optimisation. A model that could talk its way past a
risk control would make every risk control in the system advisory, and they are not
advisory. It also means a blocked signal costs nothing: there is no reason to pay for an
opinion about a trade that cannot be taken.

The prompt then requires a positive case rather than the absence of a negative one, so
"not enough evidence" is a decline. The backtests say loosening filters trades more and
loses more; a reviewer that looked for reasons to take things would be that same mistake
wearing a different hat.

### Ambiguity resolves toward doing less

`FollowUpInterpreter` admits six actions and two operands; an unstated fraction is a half,
because only one direction of guessing can lose money that was already made. There is no
action for widening a stop and none for reversing direction. `ImageSignalReader` refuses a
plausible-but-unverifiable reading, because a misread digit turns 2650 into 2050 — a
well-formed price, passing every sanity check, describing a completely different trade.

---

## What bounds the cost of being wrong

`AiFund` is a money cap, not a permission — capital allocated to a desk. Positions are
sized off what remains of the fund rather than the account balance, realised losses deplete
it, and at zero the desk stops.

This exists because AI-initiated trading **cannot be backtested**. There are no historical
model opinions to replay, so the guarantee the rest of the system offers — that a setting
can be measured before it costs anything — is unavailable here, and a bounded loss replaces
it.

It is an additional constraint, never a replacement. The kill switch, session window, news
blackout, daily loss limit and `max_concurrent_trades` bind the AI exactly as they bind the
strategy, because `AutonomousTrader` emits a signal into the same table and goes through the
copier's own executor. A second execution path would be a second place for those gates to
be got wrong, on the one path where being wrong opens a position.

`ai_autonomous` defaults to **false**. When on, `ai:decide` runs every fifteen minutes —
not every five, because the picture changes slower than that and the difference is the same
decision paid for repeatedly.

---

## What leaves your server

Indicator readings, the symbol, recent skip reasons, and the balance the position sizer
works from. No broker credentials and no account number — but it is not nothing.

Chart images go inline as a `data:` URI rather than as a link, because a URL would have to
be publicly reachable for the model to fetch it, which means publishing somebody's chart to
the open internet to read it.

---

## Cost control

Analyses are cached against the newest bar, so a dashboard left open all day costs one call
per bar at most rather than one per poll. Refresh bypasses it.

`ScanAnalyst` makes **one call for the whole shortlist**. Twenty instruments asked about
individually would be twenty calls answering a question that is comparative — "which of
these" cannot be answered by twenty opinions that never saw each other. The cheap shape and
the correct shape agree here.

---

## Findings

Things this audit turned up that are worth fixing. None is breaking anything today.

**1. The model pins are two generations behind.** `config/ai.php` defaults all three keys
to `anthropic/claude-sonnet-4.5`. Sonnet 4.6 and Sonnet 5 have both shipped since. Because
these are OpenRouter slugs rather than Anthropic API ids, check the current slug against
OpenRouter's model list before editing — the naming is theirs, not Anthropic's. It is an
`.env` change and no deploy, which is exactly what the OpenRouter choice bought.

**2. `OPENROUTER_VISION_MODEL` is not in `.env.example`.** `config/ai.php` reads it and
`ImageSignalReader` uses it, but an operator reading `.env.example` cannot discover the
knob exists. `OPENROUTER_BASE_URL`, `OPENROUTER_TIMEOUT` and `AI_CACHE_MINUTES` are
undocumented there too.

**3. The proposer's comment block in `config/ai.php` is orphaned.** It sits directly above
`vision_model`, so the paragraph explaining why the proposer deserves a capable model now
appears to describe the vision key. `proposer_model` is defined below with no comment.

**4. `OpenRouter`'s docblock claims shared retry behaviour that does not exist.** It says
sharing the client means "the retry behaviour, the timeout, the attribution headers" exist
once. The timeout and headers are there; there is no `->retry()`. Either add one — as
`CalendarFeed` does — or drop the claim.

**5. Cache TTLs disagree.** `AiAnalysisCard` honours `config('ai.cache_minutes')` (15).
`ChartAnalyst` and `ScanAnalyst` each hardcode a private `CACHE_MINUTES = 5` and ignore the
config key, so `AI_CACHE_MINUTES` only moves one of the three cached surfaces.

**6. Seven of nine call sites share `ai.model`.** Only `StrategyProposer` reads
`proposer_model` and only `ImageSignalReader` reads `vision_model`; everything else runs on
the one default. That is defensible — the split was drawn between reasoning and summarising
— but `SignalReviewer` and `FollowUpInterpreter` read strangers' text and move real
positions, which is closer to the first job than the second. Worth revisiting deliberately
rather than by default.

**7. `ImageSignalReader`'s config fallback is redundant.** It calls
`config('ai.vision_model', config('ai.model'))`, but `vision_model` is always defined in
`config/ai.php` with its own default, so the second argument can never apply — and it
evaluates `config('ai.model')` on every call regardless. Harmless, but it reads as though
the key might be absent when it cannot be.

---

## An honest statement of what this is worth

Nothing here demonstrates an edge. The walk-forward numbers on the mechanical strategy are
the only evidence this project has about whether any of its ideas make money, and they say
the baseline trades rarely and thinly. A model reading the same indicators is not obviously
better and may be worse.

What it is, is bounded. Run it as an experiment with a number attached — which is what the
fund cap makes it.
