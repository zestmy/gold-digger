"""
Hardened MetaTrader 5 order executor.

The `MetaTrader5` package makes it easy to build a request dict that *looks* correct
and is rejected by the server for reasons the dict never mentions. This module wraps
`order_send` with the checks that remove the common rejection causes:

  * symbol name resolved at runtime (brokers publish gold as XAUUSD, XAUUSDm,
    XAUUSD.a, XAUUSD_i, GOLD, ...) and pushed into Market Watch
  * volume normalised to the symbol's volume_step and clamped to [min, max]
  * SL/TP derived from the symbol's own point/digits, then clamped outside
    trade_stops_level and trade_freeze_level
  * filling mode auto-detected from the symbol's filling_mode bitmask instead of
    being hardcoded to FOK/IOC/RETURN
  * order_check() dry run before the real send
  * retry with a refreshed tick on requote / price-changed / price-off
  * every retcode mapped to a human explanation and a remedy

See docs/MT5_EXECUTION.md for the analysis this module is derived from.

Windows only: MetaTrader5 is an IPC wrapper around a running MT5 terminal.
"""

from __future__ import annotations

import logging
import time
from dataclasses import dataclass
from decimal import Decimal, ROUND_FLOOR, ROUND_HALF_UP
from typing import Iterable, Optional

try:
    import MetaTrader5 as mt5
except ImportError as exc:  # pragma: no cover - platform guard
    raise ImportError(
        "The MetaTrader5 package is Windows-only and requires a running MT5 terminal. "
        "On Linux/macOS see docs/MT5_EXECUTION.md section 4 for alternatives."
    ) from exc

log = logging.getLogger(__name__)

# Suffixes brokers commonly append to the base instrument name.
SYMBOL_SUFFIXES = ("", "m", "c", "z", "i", "e", "!", ".a", ".r", ".raw", ".ecn", ".pro", "_i", "-5", "micro")

# Aliases for instruments that some brokers do not name by their ISO pair.
SYMBOL_ALIASES = {
    "XAUUSD": ("XAUUSD", "GOLD", "GOLDUSD", "XAUUSDT"),
    "XAGUSD": ("XAGUSD", "SILVER", "SILVERUSD"),
}

# Retcodes worth retrying: the price simply moved underneath us.
RETRYABLE = frozenset({
    10004,  # REQUOTE
    10008,  # PLACED (async accept; re-read to confirm)
    10015,  # INVALID_PRICE
    10020,  # PRICE_CHANGED
    10021,  # PRICE_OFF
    10024,  # TOO_MANY_REQUESTS
})

# retcode -> (short name, what it actually means for you)
RETCODES = {
    10004: ("REQUOTE", "Price moved between quote and send. Increase deviation (20-30 points on gold) and retry with a fresh tick."),
    10006: ("REJECT", "Broker rejected the request. Read result.comment for the server's reason."),
    10007: ("CANCEL", "Request cancelled by the trader."),
    10008: ("PLACED", "Order placed but not yet filled (pending order accepted)."),
    10009: ("DONE", "Success."),
    10010: ("DONE_PARTIAL", "Only part of the requested volume was filled."),
    10011: ("ERROR", "Request processing error on the server."),
    10012: ("TIMEOUT", "Request timed out. The order may still have been placed - reconcile before retrying."),
    10013: ("INVALID", "Malformed request. Check required keys (action, type, type_time) and that all numbers are native Python floats, not numpy scalars."),
    10014: ("INVALID_VOLUME", "Lot size is below volume_min, above volume_max, or not a multiple of volume_step."),
    10015: ("INVALID_PRICE", "Price is stale or not normalised to the symbol's digits."),
    10016: ("INVALID_STOPS", "SL/TP is inside trade_stops_level, inside trade_freeze_level, or on the wrong side of price. On gold this is usually a pips-vs-points unit error - see docs/MT5_EXECUTION.md section 2."),
    10017: ("TRADE_DISABLED", "Trading is disabled for this account."),
    10018: ("MARKET_CLOSED", "Market is closed (weekend or the daily maintenance break)."),
    10019: ("NO_MONEY", "Insufficient free margin for this volume."),
    10020: ("PRICE_CHANGED", "Price changed. Retry with a fresh tick."),
    10021: ("PRICE_OFF", "No quotes available to process the request."),
    10022: ("INVALID_EXPIRATION", "Invalid order expiration for this symbol."),
    10023: ("ORDER_CHANGED", "Order state changed underneath the request."),
    10024: ("TOO_MANY_REQUESTS", "Request rate limit hit. Back off before retrying."),
    10025: ("NO_CHANGES", "The request would not change anything."),
    10026: ("SERVER_DISABLES_AT", "Algo trading is disabled SERVER-SIDE for this account. Contact the broker."),
    10027: ("CLIENT_DISABLES_AT", "Algo trading is disabled in the terminal. Click the 'Algo Trading' button in MT5."),
    10028: ("LOCKED", "Request locked for processing."),
    10029: ("FROZEN", "Order or position is frozen (inside trade_freeze_level) and cannot be modified."),
    10030: ("INVALID_FILL", "Filling mode not supported by this symbol/account. Detect it from symbol_info.filling_mode instead of hardcoding."),
    10031: ("CONNECTION", "The terminal lost its connection to the broker."),
    10032: ("ONLY_REAL", "Operation allowed only on live accounts."),
    10033: ("LIMIT_ORDERS", "Pending order limit reached."),
    10034: ("LIMIT_VOLUME", "Total volume limit for this symbol reached."),
    10035: ("INVALID_ORDER", "Invalid or prohibited order type."),
    10036: ("POSITION_CLOSED", "The position was already closed."),
    10038: ("INVALID_CLOSE_VOLUME", "Close volume exceeds the position volume."),
    10039: ("CLOSE_ORDER_EXIST", "A close order for this position already exists."),
    10040: ("LIMIT_POSITIONS", "Maximum open position count reached for this account."),
    10041: ("REJECT_CANCEL", "Pending order activation rejected; the order was cancelled."),
    10042: ("LONG_ONLY", "Symbol allows long positions only."),
    10043: ("SHORT_ONLY", "Symbol allows short positions only."),
    10044: ("CLOSE_ONLY", "Symbol allows closing positions only."),
    10045: ("FIFO_CLOSE", "Account requires FIFO closing order."),
    10046: ("HEDGE_PROHIBITED", "Opposite positions on a single symbol are prohibited."),
}


def explain(retcode: Optional[int]) -> str:
    """Human-readable explanation for an MT5 retcode."""
    if retcode is None:
        code, text = mt5.last_error()
        return f"order_send returned None (last_error {code}: {text})"
    name, meaning = RETCODES.get(retcode, ("UNKNOWN", "No description for this retcode."))
    return f"{retcode} {name}: {meaning}"


class ExecutionError(RuntimeError):
    """Raised when an order cannot be executed and retrying will not help."""

    def __init__(self, message: str, retcode: Optional[int] = None, comment: str = ""):
        super().__init__(message)
        self.retcode = retcode
        self.comment = comment


@dataclass(frozen=True)
class SymbolSpec:
    """The broker-specific constraints that decide whether an order is accepted."""

    name: str
    digits: int
    point: float
    stops_level: int          # minimum SL/TP distance, in points
    freeze_level: int         # distance within which orders cannot be modified, in points
    volume_min: float
    volume_max: float
    volume_step: float
    filling_mask: int         # SYMBOL_FILLING_* bitmask
    spread: int               # current spread in points
    contract_size: float

    @property
    def min_stop_distance(self) -> float:
        """Minimum SL/TP distance from price, expressed in price units."""
        return max(self.stops_level, self.freeze_level) * self.point


class Mt5Executor:
    """Order execution against a running MT5 terminal.

    Args:
        pip_size: price movement one "pip" represents. Leave as None to infer, but
            read the warning in `infer_pip_size` first - gold is ambiguous and getting
            this wrong is the usual cause of retcode 10016.
        deviation: maximum acceptable slippage in points. Gold needs a wider band
            than FX majors; 20-30 is realistic.
        magic: magic number stamped on every order so reconciliation can tell this
            bot's trades from manual ones.
        dry_run: run order_check() but never order_send(). Use it in staging.
    """

    def __init__(
        self,
        *,
        pip_size: Optional[float] = None,
        deviation: int = 20,
        magic: int = 20240101,
        max_retries: int = 3,
        retry_delay: float = 0.25,
        dry_run: bool = False,
    ) -> None:
        self.pip_size = pip_size
        self.deviation = deviation
        self.magic = magic
        self.max_retries = max_retries
        self.retry_delay = retry_delay
        self.dry_run = dry_run
        self._symbol_cache: dict[str, str] = {}

    # ------------------------------------------------------------------
    # Connection
    # ------------------------------------------------------------------

    def connect(
        self,
        *,
        login: Optional[int] = None,
        password: Optional[str] = None,
        server: Optional[str] = None,
        path: Optional[str] = None,
        timeout_ms: int = 60_000,
    ) -> None:
        """Attach to the terminal and verify the session can actually trade.

        `initialize()` returning True is not enough: it succeeds against a terminal
        that is not logged in, and against an investor (read-only) login.
        """
        kwargs: dict = {"timeout": timeout_ms}
        if path:
            kwargs["path"] = path
        if login is not None:
            kwargs.update(login=int(login), password=password, server=server)

        if not mt5.initialize(**kwargs):
            code, text = mt5.last_error()
            raise ExecutionError(
                f"mt5.initialize() failed ({code}: {text}). Is the terminal running and logged in? "
                "See docs/MT5_EXECUTION.md section 2, class A."
            )

        terminal = mt5.terminal_info()
        account = mt5.account_info()

        if account is None:
            raise ExecutionError(
                "Connected to the terminal but no account is logged in. Log into MT5 first."
            )
        if terminal is not None and not terminal.trade_allowed:
            raise ExecutionError(
                "Algo Trading is disabled in the terminal - every order will return 10027. "
                "Enable the 'Algo Trading' button in MT5."
            )
        if not account.trade_allowed:
            raise ExecutionError(
                "The server does not allow trading on this account. This is what an investor "
                "(read-only) password looks like; it is also what a disabled account looks like."
            )
        if not account.trade_expert:
            raise ExecutionError(
                "Expert-advisor trading is disabled server-side for this account (retcode 10026). "
                "Contact the broker."
            )

        log.info(
            "MT5 connected: login=%s server=%s %s balance=%.2f %s",
            account.login,
            account.server,
            "DEMO" if account.trade_mode == mt5.ACCOUNT_TRADE_MODE_DEMO else "LIVE",
            account.balance,
            account.currency,
        )

    def shutdown(self) -> None:
        mt5.shutdown()

    def __enter__(self) -> "Mt5Executor":
        return self

    def __exit__(self, *exc_info) -> None:
        self.shutdown()

    # ------------------------------------------------------------------
    # Symbol resolution
    # ------------------------------------------------------------------

    def resolve_symbol(self, base: str) -> str:
        """Find the broker's actual name for `base` and add it to Market Watch.

        `DEFAULT_SYMBOL=XAUUSD` in .env is a base name, not necessarily a tradable
        one. Octa, Exness and XM all ship gold under suffixed names on some account
        types, and symbol_info() returns None for a name the server does not publish.
        """
        base = base.upper()
        if base in self._symbol_cache:
            return self._symbol_cache[base]

        for candidate in self._candidate_names(base):
            info = mt5.symbol_info(candidate)
            if info is None:
                continue
            if not info.visible and not mt5.symbol_select(candidate, True):
                log.warning("Found %s but could not add it to Market Watch", candidate)
                continue
            if candidate != base:
                log.info("Resolved %s -> %s on this server", base, candidate)
            self._symbol_cache[base] = candidate
            return candidate

        available = mt5.symbols_get(f"*{base[:3]}*") or ()
        raise ExecutionError(
            f"No tradable symbol matches {base!r} on this server. "
            f"Candidates containing {base[:3]!r}: {[s.name for s in available][:20] or 'none'}"
        )

    @staticmethod
    def _candidate_names(base: str) -> Iterable[str]:
        seen: set[str] = set()
        for alias in SYMBOL_ALIASES.get(base, (base,)):
            for suffix in SYMBOL_SUFFIXES:
                name = f"{alias}{suffix}"
                if name not in seen:
                    seen.add(name)
                    yield name

    def spec(self, symbol: str) -> SymbolSpec:
        """Read the broker's constraints for an already-resolved symbol name."""
        info = mt5.symbol_info(symbol)
        if info is None:
            raise ExecutionError(f"symbol_info({symbol!r}) returned None - symbol not in Market Watch")
        return SymbolSpec(
            name=info.name,
            digits=info.digits,
            point=info.point,
            stops_level=getattr(info, "trade_stops_level", 0),
            freeze_level=getattr(info, "trade_freeze_level", 0),
            volume_min=info.volume_min,
            volume_max=info.volume_max,
            volume_step=info.volume_step,
            filling_mask=getattr(info, "filling_mode", 0),
            spread=info.spread,
            contract_size=info.trade_contract_size,
        )

    # ------------------------------------------------------------------
    # Units, volume, stops
    # ------------------------------------------------------------------

    def infer_pip_size(self, spec: SymbolSpec) -> float:
        """Price movement of one pip.

        Explicit `pip_size` always wins. The fallback follows the FX fractional-pricing
        convention (3 or 5 digits => a pip is 10 points, otherwise a pip is one point).

        WARNING for XAUUSD: gold usually quotes with 2 digits, so the fallback returns
        0.01 - but most gold traders (and most published strategies) call 0.10 "a pip".
        If the strategies table stores tp1_pips in the trader sense, you are off by 10x,
        every stop lands inside trade_stops_level, and every order returns 10016. Set
        pip_size explicitly for metals.
        """
        if self.pip_size is not None:
            return self.pip_size
        pip = spec.point * 10 if spec.digits in (3, 5) else spec.point
        if spec.contract_size >= 100 and spec.digits <= 3:
            log.warning(
                "%s: inferring pip_size=%s from digits=%d. For metals this is ambiguous - "
                "pass pip_size explicitly (gold is commonly 0.10).",
                spec.name, pip, spec.digits,
            )
        return pip

    @staticmethod
    def normalize_volume(spec: SymbolSpec, volume: float) -> float:
        """Snap a risk-derived lot size onto the broker's volume grid.

        Risk sizing produces numbers like 0.037. Sending that verbatim returns 10014.
        Rounds DOWN to volume_step so risk is never silently increased.
        """
        step = Decimal(str(spec.volume_step))
        if step <= 0:
            step = Decimal("0.01")
        snapped = (Decimal(str(volume)) / step).to_integral_value(rounding=ROUND_FLOOR) * step
        result = float(snapped)
        if result < spec.volume_min:
            result = spec.volume_min
        if result > spec.volume_max:
            result = spec.volume_max
        # Re-quantise to the step's precision so no float dust survives.
        exponent = step.as_tuple().exponent
        places = -exponent if isinstance(exponent, int) and exponent < 0 else 0
        return float(Decimal(str(result)).quantize(Decimal(1).scaleb(-places), rounding=ROUND_HALF_UP))

    def clamp_stops(
        self,
        spec: SymbolSpec,
        direction: str,
        price: float,
        sl: Optional[float],
        tp: Optional[float],
    ) -> tuple[Optional[float], Optional[float]]:
        """Push SL/TP outside the broker's minimum stop distance.

        Also guards against the sign error where a buy's SL ends up above entry, which
        the server reports as the same 10016 as a too-close stop.
        """
        min_dist = spec.min_stop_distance
        is_buy = direction == "buy"

        if sl is not None:
            limit = price - min_dist if is_buy else price + min_dist
            if (is_buy and sl > limit) or (not is_buy and sl < limit):
                log.warning(
                    "%s: SL %.*f is inside the %d-point stops level; moving to %.*f",
                    spec.name, spec.digits, sl, max(spec.stops_level, spec.freeze_level), spec.digits, limit,
                )
                sl = limit
            sl = round(sl, spec.digits)

        if tp is not None:
            limit = price + min_dist if is_buy else price - min_dist
            if (is_buy and tp < limit) or (not is_buy and tp > limit):
                log.warning(
                    "%s: TP %.*f is inside the %d-point stops level; moving to %.*f",
                    spec.name, spec.digits, tp, max(spec.stops_level, spec.freeze_level), spec.digits, limit,
                )
                tp = limit
            tp = round(tp, spec.digits)

        return sl, tp

    # ------------------------------------------------------------------
    # Filling mode
    # ------------------------------------------------------------------

    @staticmethod
    def filling_candidates(spec: SymbolSpec) -> list[int]:
        """Order-filling modes this symbol accepts, best first.

        symbol_info.filling_mode is a bitmask of what the symbol allows; the value
        passed in the request is a different enum. Mapping them by hand is why the
        same code works at one broker and returns 10030 at another.
        """
        fok_bit = getattr(mt5, "SYMBOL_FILLING_FOK", 1)
        ioc_bit = getattr(mt5, "SYMBOL_FILLING_IOC", 2)

        candidates: list[int] = []
        if spec.filling_mask & ioc_bit:
            candidates.append(mt5.ORDER_FILLING_IOC)
        if spec.filling_mask & fok_bit:
            candidates.append(mt5.ORDER_FILLING_FOK)
        # RETURN is not advertised in the mask; it is valid on instant/exchange
        # execution accounts and is the correct last resort.
        candidates.append(mt5.ORDER_FILLING_RETURN)
        return candidates

    @staticmethod
    def _select_filling(request: dict, fillings: list[int]):
        """Return the first (filling_mode, check_result) that order_check() accepts.

        Only INVALID_FILL means "wrong filling mode". Any other rejection is a real
        problem with the request itself - volume, stops, margin - and is raised as
        itself. Looping on through the remaining filling modes would bury the true
        retcode behind a misleading "no filling mode accepted", which is exactly the
        kind of wrong diagnostic trail this module exists to remove.
        """
        invalid_fill = getattr(mt5, "TRADE_RETCODE_INVALID_FILL", 10030)
        last = None

        for filling in fillings:
            request["type_filling"] = filling
            check = mt5.order_check(request)
            if check is None:
                raise ExecutionError(f"order_check returned None: {mt5.last_error()}")
            if check.retcode in (0, mt5.TRADE_RETCODE_DONE):
                return filling, check
            if check.retcode != invalid_fill:
                raise ExecutionError(
                    explain(check.retcode) + f" (broker comment: {check.comment!r})",
                    retcode=check.retcode,
                    comment=check.comment,
                )
            last = check
            log.debug("Filling mode %s rejected: %s", filling, explain(check.retcode))

        raise ExecutionError(
            f"No filling mode accepted for {request['symbol']} (tried {fillings}). "
            f"{explain(last.retcode)} (broker comment: {last.comment!r})",
            retcode=last.retcode,
            comment=last.comment,
        )

    # ------------------------------------------------------------------
    # Orders
    # ------------------------------------------------------------------

    def market_order(
        self,
        *,
        symbol: str,
        direction: str,
        volume: float,
        sl_pips: Optional[float] = None,
        tp_pips: Optional[float] = None,
        sl_price: Optional[float] = None,
        tp_price: Optional[float] = None,
        comment: str = "gold-digger",
    ):
        """Open a market position, retrying through the causes that are retryable.

        Stops may be given in pips (converted using the symbol's own point size) or
        as absolute prices. Absolute prices win if both are supplied.

        Returns the MqlTradeResult on success. Raises ExecutionError otherwise, with
        the retcode and the broker's comment attached.
        """
        if direction not in ("buy", "sell"):
            raise ValueError(f"direction must be 'buy' or 'sell', got {direction!r}")

        resolved = self.resolve_symbol(symbol)
        spec = self.spec(resolved)
        pip = self.infer_pip_size(spec)
        lots = self.normalize_volume(spec, volume)
        order_type = mt5.ORDER_TYPE_BUY if direction == "buy" else mt5.ORDER_TYPE_SELL
        fillings = self.filling_candidates(spec)

        if lots <= 0:
            raise ExecutionError(f"Normalised volume for {resolved} is {lots}; nothing to send")

        last_result = None
        for attempt in range(1, self.max_retries + 1):
            tick = mt5.symbol_info_tick(resolved)
            if tick is None or not (tick.ask and tick.bid):
                raise ExecutionError(f"No tick data for {resolved}; the market may be closed")

            price = tick.ask if direction == "buy" else tick.bid
            sign = 1.0 if direction == "buy" else -1.0

            sl = sl_price if sl_price is not None else (price - sign * sl_pips * pip if sl_pips else None)
            tp = tp_price if tp_price is not None else (price + sign * tp_pips * pip if tp_pips else None)
            sl, tp = self.clamp_stops(spec, direction, price, sl, tp)

            request = {
                "action": mt5.TRADE_ACTION_DEAL,
                "symbol": resolved,
                "volume": float(lots),
                "type": order_type,
                "price": float(round(price, spec.digits)),
                "deviation": int(self.deviation),
                "magic": int(self.magic),
                "comment": comment[:31],  # MT5 truncates silently past 31 chars
                "type_time": mt5.ORDER_TIME_GTC,
            }
            if sl is not None:
                request["sl"] = float(sl)
            if tp is not None:
                request["tp"] = float(tp)

            # Validate before sending: order_check catches a bad filling mode,
            # volume or stops without putting anything on the market.
            request["type_filling"], check = self._select_filling(request, fillings)

            if self.dry_run:
                log.info("[dry-run] would send %s %s %.2f lots @ %.*f sl=%s tp=%s",
                         direction, resolved, lots, spec.digits, price, sl, tp)
                return check

            result = mt5.order_send(request)
            last_result = result

            if result is None:
                raise ExecutionError(explain(None))
            if result.retcode in (mt5.TRADE_RETCODE_DONE, mt5.TRADE_RETCODE_DONE_PARTIAL):
                log.info(
                    "Filled %s %s %.2f lots @ %.*f (ticket %s, slippage %.*f)",
                    direction, resolved, result.volume, spec.digits, result.price,
                    result.order, spec.digits, abs(result.price - price),
                )
                return result
            if result.retcode in RETRYABLE and attempt < self.max_retries:
                log.warning("Attempt %d/%d: %s - retrying", attempt, self.max_retries, explain(result.retcode))
                time.sleep(self.retry_delay * attempt)
                continue

            raise ExecutionError(
                explain(result.retcode) + f" (broker comment: {result.comment!r})",
                retcode=result.retcode,
                comment=result.comment,
            )

        raise ExecutionError(
            f"Gave up after {self.max_retries} attempts: "
            + explain(last_result.retcode if last_result else None),
            retcode=last_result.retcode if last_result else None,
        )

    def close_position(self, ticket: int, volume: Optional[float] = None, comment: str = "gold-digger close"):
        """Close all or part of a position by ticket.

        Partial closes are how the TP1/TP2/TP3 ladder in the strategies table is
        actually executed; each one becomes a row in trade_partials.
        """
        positions = mt5.positions_get(ticket=ticket)
        if not positions:
            raise ExecutionError(f"Position {ticket} not found (already closed?)")
        position = positions[0]

        spec = self.spec(position.symbol)
        lots = self.normalize_volume(spec, volume if volume is not None else position.volume)
        if lots > position.volume:
            lots = position.volume

        closing_buy = position.type == mt5.POSITION_TYPE_SELL
        tick = mt5.symbol_info_tick(position.symbol)
        if tick is None:
            raise ExecutionError(f"No tick data for {position.symbol}")

        request = {
            "action": mt5.TRADE_ACTION_DEAL,
            "position": int(ticket),
            "symbol": position.symbol,
            "volume": float(lots),
            "type": mt5.ORDER_TYPE_BUY if closing_buy else mt5.ORDER_TYPE_SELL,
            "price": float(round(tick.ask if closing_buy else tick.bid, spec.digits)),
            "deviation": int(self.deviation),
            "magic": int(self.magic),
            "comment": comment[:31],
            "type_time": mt5.ORDER_TIME_GTC,
        }

        request["type_filling"], check = self._select_filling(request, self.filling_candidates(spec))

        if self.dry_run:
            log.info("[dry-run] would close %.2f lots of position %s", lots, ticket)
            return check

        result = mt5.order_send(request)
        if result is None or result.retcode not in (mt5.TRADE_RETCODE_DONE, mt5.TRADE_RETCODE_DONE_PARTIAL):
            raise ExecutionError(
                explain(result.retcode if result else None),
                retcode=result.retcode if result else None,
                comment=result.comment if result else "",
            )
        log.info("Closed %.2f lots of position %s @ %.*f", lots, ticket, spec.digits, result.price)
        return result
