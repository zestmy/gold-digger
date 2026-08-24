# Gold Digger — Python MT5 tooling

> **Execution now runs through the MQL5 Expert Advisor in [`../mql5/`](../mql5/)**, not through
> this package. See [`docs/MT5_EA_BRIDGE.md`](../docs/MT5_EA_BRIDGE.md).
>
> What is here is still worth keeping: `mt5_preflight.py` diagnoses a broken account faster than
> attaching an EA does, and `mt5_executor.py` is the reference implementation the MQL5 executor
> mirrors — when the two disagree about how a request should be built, that is a bug.

Start with [`docs/MT5_EXECUTION.md`](../docs/MT5_EXECUTION.md) — it explains why orders get
rejected and what the alternatives to this path are.

## Platform constraint (read this first)

`MetaTrader5` on PyPI is a thin IPC wrapper around a **running MT5 terminal**, and it ships as a
Windows-only build with no Linux or macOS wheel. What it actually requires, though, is that the
terminal and this package sit on the same machine as *each other* — not that the machine runs
Windows. Both under Wine in a container satisfies that, so Linux is possible; it is just not what
the droplet does today.

You need one of:

- a Windows VPS (~$10–20/month) with the MT5 terminal logged in and left running,
- the terminal plus a Windows Python under Wine in Docker — `docs/MT5_EXECUTION.md` §4 option H.
  This is the route that would let the DigitalOcean droplet in `DEPLOYMENT.md` run the bot, but
  the droplet is sized for Laravel and MySQL only and would need resizing first, or
- one of the non-terminal options in `docs/MT5_EXECUTION.md` §4 (an MQL5 EA, or a
  hosted MT5 API).

Nothing in this repo has been run under Wine. Option H is researched, not verified — the setup
instructions below assume Windows.

## Setup

```powershell
cd bot
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
```

Credentials come from the environment, so they stay out of shell history:

```powershell
$env:MT5_LOGIN     = "12345678"
$env:MT5_PASSWORD  = "your-MASTER-password"   # NOT the investor password
$env:MT5_SERVER    = "Elev8-Demo"            # copy verbatim from the terminal
$env:MT5_SYMBOL    = "XAUUSD"
```

## Diagnose a failing account

```powershell
python mt5_preflight.py --symbol XAUUSD --pip-size 0.10
```

Each check prints `PASS` / `WARN` / `FAIL` with the specific remedy: terminal
attach, Algo Trading flag, broker connection, account trade permissions, symbol
resolution, live quotes, pip sizing versus the stops level, volume grid, filling mode,
free margin, an `order_check()` dry run, and current open positions. Exits non-zero if
anything blocking failed.

To place and immediately close a real minimum-lot position (refuses to run on a live
account):

```powershell
python mt5_preflight.py --symbol XAUUSD --pip-size 0.10 --live-test
```

## Place orders

```python
from mt5_executor import Mt5Executor, ExecutionError

with Mt5Executor(pip_size=0.10, deviation=25, magic=20240101) as ex:
    ex.connect()                       # verifies the session can actually trade
    try:
        result = ex.market_order(
            symbol="XAUUSD",           # resolved to XAUUSDm / XAUUSD.a / GOLD as needed
            direction="buy",
            volume=0.037,              # snapped down onto the broker's volume_step
            sl_pips=30,
            tp_pips=15,                # clamped outside trade_stops_level
        )
        print(result.order, result.price)
    except ExecutionError as exc:
        print(exc.retcode, exc)        # retcode already mapped to a remedy
```

`close_position(ticket, volume=...)` handles the partial closes that the TP1/TP2/TP3
ladder in the `strategies` table needs.

## The pip trap

Gold quotes with 2 digits, so the broker's `point` is `0.01` — but most gold strategies
call `0.10` a pip. If `tp1_pips` in the `strategies` table means the trader's pip and the
code assumes the broker's point, every stop lands inside `trade_stops_level` and every
order is rejected with `10016`. **Pass `pip_size` explicitly.** The executor warns when it
has to infer it for a metal.

## Relationship to the EA

The Laravel side — `trade_commands`, `/api/v1/bot/*`, heartbeats, `bot_logs` — is built, and the
MQL5 EA is what consumes it. This package is not wired into that queue; it is a diagnostic and a
reference.

`WireProtocolContractTest` asserts that both executors explain the same critical retcodes, so the
two stay in step.
