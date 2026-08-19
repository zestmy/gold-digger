# Gold Digger — Python bot

Execution-side tooling for the MT5 leg of the trading bot. Start with
[`docs/MT5_EXECUTION.md`](../docs/MT5_EXECUTION.md) — it explains why orders get
rejected and what the alternatives to this path are.

## Platform constraint (read this first)

`MetaTrader5` on PyPI is a thin IPC wrapper around a **running Windows MT5 terminal**.
It has no Linux or macOS build. The DigitalOcean droplet that `DEPLOYMENT.md`
provisions runs the Laravel dashboard and **cannot** run this bot.

You need one of:

- a Windows VPS (~$10–20/month) with the MT5 terminal logged in and left running, or
- one of the non-terminal options in `docs/MT5_EXECUTION.md` §4 (an MQL5 EA, or a
  hosted MT5 API).

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
$env:MT5_SERVER    = "OctaFX-Demo"            # copy verbatim from the terminal
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

## Not built yet

The Laravel side of this — the `trade_commands` queue, `/api/v1/*` endpoints, heartbeats,
and `bot_logs` writes — is proposed in `docs/MT5_EXECUTION.md` §5, not implemented. Its
shape depends on which executor you pick in §4.
