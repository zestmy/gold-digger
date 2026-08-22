#!/usr/bin/env python3
"""
MT5 preflight diagnostic.

Answers one question: *why* is this account not executing trades? It walks the ranked
causes in docs/MT5_EXECUTION.md and reports which one is actually true, rather than
leaving you to guess from a bare retcode.

Usage (on the Windows machine running the MT5 terminal):

    pip install -r requirements.txt
    python mt5_preflight.py --symbol XAUUSD

    # explicit login instead of attaching to whatever is already open
    python mt5_preflight.py --login 12345678 --password ... --server Elev8-Demo

    # actually place and immediately close a minimum-lot position (DEMO ONLY)
    python mt5_preflight.py --symbol XAUUSD --live-test

Environment variables MT5_LOGIN, MT5_PASSWORD, MT5_SERVER, MT5_PATH and MT5_SYMBOL are
used as defaults, so credentials need not appear in shell history.

Exit code 0 = every blocking check passed.
"""

from __future__ import annotations

import argparse
import os
import sys

try:
    import MetaTrader5 as mt5
except ImportError:
    sys.exit(
        "FAIL  The MetaTrader5 package is not importable.\n"
        "      It is Windows-only and wraps a locally running MT5 terminal - there is no\n"
        "      Linux build. If this is your DigitalOcean droplet, that alone explains why\n"
        "      no trade executes. See docs/MT5_EXECUTION.md section 4 for the alternatives."
    )

# Import the shared helpers so the diagnostic checks exactly what the executor does.
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from mt5_executor import Mt5Executor  # noqa: E402


class Report:
    """Collects PASS/WARN/FAIL lines and tracks whether anything blocking failed."""

    def __init__(self) -> None:
        self.blocked = False
        self._n = 0

    def _emit(self, tag: str, title: str, detail: str = "") -> None:
        self._n += 1
        print(f"{tag:<5} {self._n:>2}. {title}")
        for line in filter(None, detail.splitlines()):
            print(f"          {line}")

    def ok(self, title: str, detail: str = "") -> None:
        self._emit("PASS", title, detail)

    def warn(self, title: str, detail: str = "") -> None:
        self._emit("WARN", title, detail)

    def fail(self, title: str, detail: str = "") -> None:
        self.blocked = True
        self._emit("FAIL", title, detail)


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Diagnose why MT5 will not execute trades")
    p.add_argument("--symbol", default=os.getenv("MT5_SYMBOL", "XAUUSD"), help="base symbol (default XAUUSD)")
    p.add_argument("--login", type=int, default=os.getenv("MT5_LOGIN"), help="MT5 account number")
    p.add_argument("--password", default=os.getenv("MT5_PASSWORD"), help="MT5 MASTER password (investor is read-only)")
    p.add_argument("--server", default=os.getenv("MT5_SERVER"), help="MT5 server name, verbatim from the terminal")
    p.add_argument("--path", default=os.getenv("MT5_PATH"), help="path to terminal64.exe if several are installed")
    p.add_argument("--volume", type=float, default=None, help="lot size to test with (default: symbol minimum)")
    p.add_argument("--pip-size", type=float, default=None, help="price move of one pip; gold is usually 0.10")
    p.add_argument("--live-test", action="store_true", help="place and immediately close a real order (demo only)")
    return p.parse_args()


def main() -> int:
    args = parse_args()
    r = Report()

    print("=" * 78)
    print("Gold Digger - MT5 preflight")
    print("=" * 78)

    # --- 1. Terminal attach -------------------------------------------------
    init_kwargs = {"timeout": 60_000}
    if args.path:
        init_kwargs["path"] = args.path
    if args.login:
        init_kwargs.update(login=int(args.login), password=args.password, server=args.server)

    if not mt5.initialize(**init_kwargs):
        code, text = mt5.last_error()
        r.fail(
            f"Attach to terminal ({code}: {text})",
            "Terminal not running, not logged in, or running at a different privilege level.\n"
            "If several terminals are installed, pass --path to the right terminal64.exe.",
        )
        return 1
    r.ok("Attached to the MT5 terminal")

    try:
        return run_checks(args, r)
    finally:
        mt5.shutdown()


def run_checks(args: argparse.Namespace, r: Report) -> int:
    # --- 2. Terminal state --------------------------------------------------
    terminal = mt5.terminal_info()
    if terminal is None:
        r.fail("Read terminal_info()", "The IPC channel is unusable.")
        return 1

    if terminal.trade_allowed:
        r.ok("Algo Trading is enabled in the terminal")
    else:
        r.fail(
            "Algo Trading is DISABLED in the terminal",
            "Every order will return 10027 CLIENT_DISABLES_AT.\n"
            "Fix: click the 'Algo Trading' button in the MT5 toolbar.",
        )

    if terminal.connected:
        r.ok(f"Terminal connected to the broker (build {terminal.build})")
    else:
        r.fail("Terminal is NOT connected to the broker", "Orders will return 10031 CONNECTION.")

    # --- 3. Account state ---------------------------------------------------
    account = mt5.account_info()
    if account is None:
        r.fail(
            "No account logged in",
            "initialize() can succeed against a terminal with no session. Log into MT5.",
        )
        return 1

    mode = {0: "DEMO", 1: "CONTEST", 2: "LIVE"}.get(account.trade_mode, "UNKNOWN")
    r.ok(
        f"Logged in: {account.login} @ {account.server} [{mode}]",
        f"balance={account.balance:.2f} {account.currency}  equity={account.equity:.2f}  "
        f"free margin={account.margin_free:.2f}  leverage=1:{account.leverage}",
    )

    if account.trade_allowed:
        r.ok("Server allows trading on this account")
    else:
        r.fail(
            "Server does NOT allow trading on this account",
            "This is exactly what an investor (read-only) password looks like.\n"
            "Fix: log in with the MASTER password. If that is already the case, the\n"
            "account is disabled broker-side.",
        )

    if account.trade_expert:
        r.ok("Server allows expert-advisor / API trading")
    else:
        r.fail(
            "Server has DISABLED expert-advisor trading for this account",
            "Orders will return 10026 SERVER_DISABLES_AT. Only the broker can change this.",
        )

    # --- 4. Symbol resolution ----------------------------------------------
    # dry_run: this executor only ever runs order_check(), never order_send().
    ex = Mt5Executor(pip_size=args.pip_size, dry_run=True)
    try:
        symbol = ex.resolve_symbol(args.symbol)
    except Exception as exc:
        r.fail(f"Resolve symbol {args.symbol!r}", str(exc))
        return 1

    if symbol == args.symbol.upper():
        r.ok(f"Symbol {symbol} exists and is in Market Watch")
    else:
        r.warn(
            f"Symbol {args.symbol} is published as {symbol} on this server",
            f"Hardcoding DEFAULT_SYMBOL={args.symbol} in .env will fail. Store the resolved\n"
            f"name ({symbol}) against the broker account and use it everywhere.",
        )

    spec = ex.spec(symbol)
    r.ok(
        f"Symbol constraints for {spec.name}",
        f"digits={spec.digits}  point={spec.point}  contract_size={spec.contract_size}\n"
        f"volume: min={spec.volume_min} max={spec.volume_max} step={spec.volume_step}\n"
        f"stops_level={spec.stops_level} pts  freeze_level={spec.freeze_level} pts  "
        f"spread={spec.spread} pts",
    )

    # --- 5. Market open? ----------------------------------------------------
    tick = mt5.symbol_info_tick(symbol)
    if tick is None or not (tick.bid and tick.ask):
        r.fail(
            "No live quotes for this symbol",
            "The market is closed (weekend / daily break) or the symbol is not subscribed.\n"
            "Orders will return 10018 MARKET_CLOSED or 10021 PRICE_OFF.",
        )
    else:
        r.ok(
            "Live quotes are flowing",
            f"bid={tick.bid:.{spec.digits}f}  ask={tick.ask:.{spec.digits}f}  "
            f"spread={(tick.ask - tick.bid):.{spec.digits}f}",
        )

    # --- 6. Pip sizing sanity ----------------------------------------------
    pip = ex.infer_pip_size(spec)
    min_stop_pips = spec.min_stop_distance / pip if pip else 0
    detail = (
        f"pip_size={pip} (1 pip = {pip / spec.point:.0f} points)\n"
        f"minimum SL/TP distance = {spec.min_stop_distance:.{spec.digits}f} "
        f"= {min_stop_pips:.1f} pips at this pip size"
    )
    if min_stop_pips > 5:
        r.warn(
            "Stops level is wide relative to a scalping target",
            detail + "\nAny TP closer than this is rejected with 10016 INVALID_STOPS.\n"
            "Check tp1_pips/tp2_pips in the strategies table against this number.",
        )
    else:
        r.ok("Stops level leaves room for scalping targets", detail)

    if args.pip_size is None and spec.contract_size >= 100:
        r.warn(
            "pip_size was inferred, not configured",
            "Gold is ambiguous: the broker's point is "
            f"{spec.point} but most gold strategies call 0.10 a pip.\n"
            "Getting this wrong by 10x puts every stop inside the stops level (10016).\n"
            "Re-run with --pip-size 0.10 to compare.",
        )

    # --- 7. Volume normalisation -------------------------------------------
    requested = args.volume if args.volume is not None else spec.volume_min
    lots = ex.normalize_volume(spec, requested)
    if abs(lots - requested) > 1e-9:
        r.warn(
            f"Requested volume {requested} snapped to {lots}",
            "Risk-derived lot sizes must be rounded onto volume_step or the server\n"
            "returns 10014 INVALID_VOLUME.",
        )
    else:
        r.ok(f"Test volume {lots} sits on the broker's volume grid")

    # --- 8. Filling mode ----------------------------------------------------
    names = {
        mt5.ORDER_FILLING_FOK: "FOK",
        mt5.ORDER_FILLING_IOC: "IOC",
        mt5.ORDER_FILLING_RETURN: "RETURN",
    }
    candidates = ex.filling_candidates(spec)
    r.ok(
        "Filling modes to try, in order",
        f"symbol filling_mode bitmask = {spec.filling_mask}\n"
        f"candidates: {', '.join(names.get(c, str(c)) for c in candidates)}\n"
        "Hardcoding a single mode is what produces 10030 INVALID_FILL at some brokers.",
    )

    # --- 9. Margin check ----------------------------------------------------
    if tick is not None:
        margin = mt5.order_calc_margin(mt5.ORDER_TYPE_BUY, symbol, lots, tick.ask)
        if margin is None:
            r.warn("Could not compute required margin", str(mt5.last_error()))
        elif margin > account.margin_free:
            r.fail(
                f"Not enough free margin for {lots} lots",
                f"required={margin:.2f} {account.currency}  free={account.margin_free:.2f}\n"
                "Orders will return 10019 NO_MONEY.",
            )
        else:
            r.ok(
                f"Margin is sufficient for {lots} lots",
                f"required={margin:.2f} {account.currency}  free={account.margin_free:.2f}",
            )

    # --- 10. Dry-run order_check -------------------------------------------
    if tick is not None:
        try:
            ex.market_order(
                symbol=args.symbol,
                direction="buy",
                volume=lots,
                sl_pips=50,
                tp_pips=50,
                comment="preflight",
            )
            r.ok(
                "order_check() dry run accepted",
                "The request the executor builds is valid for this account and symbol.\n"
                "If real orders still fail from here, the cause is timing (requote) or\n"
                "something the strategy layer passes in - log the full request dict.",
            )
        except Exception as exc:
            r.fail("order_check() dry run rejected", str(exc))

    # --- 11. Optional live round trip --------------------------------------
    if args.live_test:
        if account.trade_mode == 2:
            r.fail(
                "Refusing --live-test on a LIVE account",
                "Re-run against a demo account, or place the test trade by hand.",
            )
        else:
            live = Mt5Executor(pip_size=args.pip_size, dry_run=False)
            try:
                result = live.market_order(
                    symbol=args.symbol, direction="buy", volume=lots,
                    sl_pips=50, tp_pips=50, comment="preflight",
                )
                r.ok(f"Live test order filled at {result.price} (ticket {result.order})")
                try:
                    live.close_position(result.order)
                    r.ok("Live test position closed again")
                except Exception as exc:
                    r.fail(
                        f"Opened but could NOT close position {result.order}",
                        f"{exc}\nClose it manually in the terminal.",
                    )
            except Exception as exc:
                r.fail("Live test order rejected", str(exc))

    # --- 12. Existing bot positions ----------------------------------------
    positions = mt5.positions_get() or ()
    r.ok(
        f"{len(positions)} open position(s) on the account",
        "\n".join(
            f"#{p.ticket} {p.symbol} {p.volume} lots magic={p.magic} pnl={p.profit:.2f}"
            for p in positions[:10]
        ) or "none",
    )

    print("=" * 78)
    if r.blocked:
        print("RESULT: blocking problems found. Fix the FAIL lines above, in order.")
        print("        Ranked causes and remedies: docs/MT5_EXECUTION.md section 2.")
        return 1
    print("RESULT: no blocking problems. Execution should work from this machine.")
    print("        If orders still fail, capture the full request dict and retcode and")
    print("        look it up in docs/MT5_EXECUTION.md section 2.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
