# The Telegram copier: reading what the parser could not

The copier's pipeline is capture, parse, review, execute. This note is about the parse
stage's misses, because they are the failure that looks like nothing happening.

---

## Unparsed is a result, not a failure

`SignalParser` refuses more than it guesses. A message with no readable stop is refused
rather than completed from ATR; a message that says BUY and SELL is ambiguous rather than
"probably the first one". That is the right rule for a machine acting unattended, and it
leaves a class of message that a person reading it understands at a glance.

Every such message is stored with the parser's complaint (`parse_error`) and shown on
**Signals → Copied** with a `not parsed` tag. The channel's parse rate on **Providers** is
where a provider changing their format announces itself.

---

## A person can read it

An unparsed signal offers **Read it myself**. The reader types the symbol, direction, an
entry (blank for market) with an optional far side of the zone, the stop, and the targets.
Then:

- The fields go through the parser's own `coherenceError()` - a stop on the wrong side of
  entry, or a target on the stop's side of it, is refused with the same wording a
  misreading gets. A person can mistype a level as easily as a regex can misread one, and
  the result would be the same inverted trade.
- A stop is required. The rule the parser exists to enforce holds for a person too.
- Targets are stored nearest first, whatever order they were typed in.
- The signal joins the pipeline at **review**, exactly where a parsed message enters it.
  Nothing is traded on the strength of having been typed; the reviewer's gates and the
  executor's re-check apply as they do to every signal.

The row is marked `parsed_by = user` with a `corrected_at`, and the card shows
**read by you**. That marking is what keeps two numbers honest: the channel's parse rate
stays a fact about the parser, and a reparse never overwrites what a person wrote.

Messages the ingest refused because their chat is not an enabled source cannot be
corrected. That is a channel setting, not a reading.

---

## Judging a change to the parser

```bash
php artisan telegram:reparse            # re-run the parser over the last 30 days of misses
php artisan telegram:reparse --days=90
php artisan telegram:reparse --dry-run  # report only
```

It runs today's parser over every text message that failed to parse, was never acted on,
and was never corrected by a person or read from an image. The output is the measure of
the change - `N unparsed, M now parse` - followed by the complaints that remain, most
common first, which is the list of what to fix next.

A recovered message enters the pipeline at review with `parsed_by = parser`. It is not
traded because it finally parsed; the reviewer decides whether a signal that old is still
worth anything, and its drift gate usually says no.

---

## Where each reading came from

| `parsed_by` | Meaning |
|---|---|
| `parser` | The text parser read it, at ingest or on a later reparse |
| `image` | `ImageSignalReader` transcribed it from a picture |
| `user` | A person typed the levels in; `corrected_at` says when |
| null | It never parsed |
