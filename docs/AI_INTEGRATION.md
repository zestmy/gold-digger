# AI Integration

Nine call sites, one client, four model keys, and a single rule that shapes all of them: **the
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

## Model routing

Four keys, because four jobs. Everything defaults to the current Sonnet generation; the
split exists so a more capable model can be bought where it is worth buying, rather than
everywhere at once.

| Key | Env | Used by | The job |
|---|---|---|---|
| `ai.model` | `OPENROUTER_MODEL` | `AutonomousTrader`, `ChartAnalyst`, `ScanAnalyst`, `PairAnalyst` | Reading numbers this system computed |
| `ai.reviewer_model` | `OPENROUTER_REVIEWER_MODEL` | `SignalReviewer`, `FollowUpInterpreter`, `EditInterpreter` | Judging a stranger's words, with a position on the line |
| `ai.proposer_model` | `OPENROUTER_PROPOSER_MODEL` | `StrategyProposer` | Reasoning about indicator behaviour |
| `ai.vision_model` | `OPENROUTER_VISION_MODEL` | `ImageSignalReader` | Transcribing a screenshot |

`reviewer_model` defaults to whatever `OPENROUTER_MODEL` is, so naming it changed nothing
on its own. It exists because those three callers are the only ones that read text written
by somebody else and then move real money — a different job from summarising the trend
card, and one that had been sharing a model with it by accident rather than by decision.
`anthropic/claude-opus-5` is the intended upgrade path for it and for the proposer; both
cost more per call, which is why each is a deliberate `.env` edit rather than a default.

---

## Known limits

**Nothing retries a paid call twice.** Connection failures get one retry; HTTP status codes
get none, on purpose — see the note in `OpenRouter`. A blip during `ai:decide` means a
missed consideration, not a wrong one, and the schedule comes round again in fifteen
minutes.

**`AI_CACHE_MINUTES` is a backstop, not the expiry.** Every cached surface keys on the
newest bar, so a new bar produces a new key whatever the TTL says. Raising it does not make
an answer staler than the bar it was read from; it only stops an idle tab paying twice.

**The fund cap is the only bound that is measured.** Everything else in this system can be
backtested before it costs anything. AI-initiated trading cannot be, and no amount of
prompt care substitutes for that — which is the argument for keeping the cap small enough
that being wrong about all of this is affordable.

---

## An honest statement of what this is worth

Nothing here demonstrates an edge. The walk-forward numbers on the mechanical strategy are
the only evidence this project has about whether any of its ideas make money, and they say
the baseline trades rarely and thinly. A model reading the same indicators is not obviously
better and may be worse.

What it is, is bounded. Run it as an experiment with a number attached — which is what the
fund cap makes it.
