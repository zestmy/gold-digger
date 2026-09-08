# Trading modes

**Auto-Trade → Risk** opens with three cards: **Passive**, **Moderate**, **Aggressive**.
Choosing one writes its values into the same settings the rest of the page edits, and every
signal and every order reflects it from that moment - because the mode *is* those settings,
not a label beside them.

---

## Why a mode

The risk page holds eighteen numbers, every one defensible, and together they are a stance
nobody can read as one. "Half a percent, one position, London only, two to one at the exit,
four factors agreeing" is a cautious account; nobody would recognise it as one from the form.
A mode is the stance said once, in the word a person would use, and the settings are what
the word means.

## What each mode sets

| | Passive | Moderate | Aggressive |
|---|---|---|---|
| Risk per trade | 0.5% | 1% | 2% |
| Daily loss limit | 2% | 3% | 6% |
| Open positions | 1 | 3 | 5 |
| Sessions | London, overlap | London, New York, overlap | Any |
| Reward floor at the exit | 2 : 1 | none | none |
| Confluence floor | 4.0 factors, 2.0 directional | platform default (3.0 / 1.5) | 2.0 / 1.0 |
| News blackout | 30 min either side | 15 min | 10 min |
| AI fund risk per trade | 0.5%, 1 position, 2 trades a day | 1%, 1 position | 2%, 3 positions |
| Copied signals | reviewed by the model | reviewed by the model | trusted as posted while valid |
| Copied position protection | break-even + bank half at 1R | break-even, bank half, trail 1R | trail 1R from 1.5R, nothing banked |

Moderate is the platform's defaults under a name. A new account starts moderate, and the
outcome numbers measured so far were measured under those values.

Anything not in the table - the kill switch, the AI fund cap, the ATR floor, whose levels a
copied signal trades with - is not part of the stance and is left exactly as the account has
it.

## Custom is a description, not a demotion

Nothing reads the mode to decide a trade. The generator reads the floors, the reviewer reads
the review setting, the fund reads its cap. So the mode has to be a true description of the
columns: edit any of them by hand and the account becomes **Custom**, on save, because its
values now match no preset. Pick a mode again to replace them.

The same rule runs the other way. A row edited outside the form - the support console, a
migration - is read from its values, so the word on the page is never stale.

## Where it shows

- **Auto-Trade → Risk** - the three cards, with the current one marked.
- **Signals** - a line under the tabs saying which floors the feed is being held to, so a
  signal marked "below your floor" can be traced to the stance that set the floor.
- **Signals → Copied** - under Aggressive the decline-rate warning gives way to "Provider
  trusted", because a low decline rate is then the intended shape.

## What it is not

Not a claim about profit. Passive loses less when the signals are bad; aggressive makes more
when they are good. Which they are is measured on **Trades → Performance**, under every mode
alike, and the strategy's own parameters - EMA periods, the ADX floor, the ladder - are the
platform's and are not part of a mode.
