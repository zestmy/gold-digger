//+------------------------------------------------------------------+
//|                                                    Executor.mqh  |
//|                          FXSignalPro - MT5 execution primitives   |
//+------------------------------------------------------------------+
//| Order placement hardened against the rejections documented in     |
//| docs/MT5_EXECUTION.md:                                            |
//|                                                                   |
//|   * symbol resolved at runtime  (XAUUSD -> XAUUSDm / .a / GOLD)   |
//|   * volume snapped DOWN onto volume_step   (avoids 10014)         |
//|   * SL/TP clamped outside stops/freeze level (avoids 10016)       |
//|   * filling mode taken from SYMBOL_FILLING_MODE (avoids 10030)    |
//|   * retry on requote / price-changed        (handles 10004/10020) |
//|                                                                   |
//| This mirrors bot/mt5_executor.py deliberately: whichever executor |
//| is running, the same request should reach the broker.             |
//+------------------------------------------------------------------+
#property copyright "FXSignalPro"

#include <Trade/Trade.mqh>

//+------------------------------------------------------------------+
//| Human-readable explanation for an MT5 retcode.                    |
//| Kept verbatim in step with RETCODES in bot/mt5_executor.py.        |
//+------------------------------------------------------------------+
string FXSExplainRetcode(const uint code)
  {
   switch(code)
     {
      case TRADE_RETCODE_REQUOTE:            return "10004 REQUOTE: price moved between quote and send; widen deviation and retry";
      case TRADE_RETCODE_REJECT:             return "10006 REJECT: broker rejected the request";
      case TRADE_RETCODE_CANCEL:             return "10007 CANCEL: request cancelled";
      case TRADE_RETCODE_PLACED:             return "10008 PLACED: order accepted but not yet filled";
      case TRADE_RETCODE_DONE:               return "10009 DONE: success";
      case TRADE_RETCODE_DONE_PARTIAL:       return "10010 DONE_PARTIAL: only part of the volume was filled";
      case TRADE_RETCODE_ERROR:              return "10011 ERROR: server-side processing error";
      case TRADE_RETCODE_TIMEOUT:            return "10012 TIMEOUT: the order may still have been placed - reconcile before retrying";
      case TRADE_RETCODE_INVALID:            return "10013 INVALID: malformed request";
      case TRADE_RETCODE_INVALID_VOLUME:     return "10014 INVALID_VOLUME: below volume_min, above volume_max, or off the volume_step grid";
      case TRADE_RETCODE_INVALID_PRICE:      return "10015 INVALID_PRICE: stale price or wrong precision";
      case TRADE_RETCODE_INVALID_STOPS:      return "10016 INVALID_STOPS: SL/TP inside the stops level or on the wrong side of price";
      case TRADE_RETCODE_TRADE_DISABLED:     return "10017 TRADE_DISABLED: trading disabled for this account";
      case TRADE_RETCODE_MARKET_CLOSED:      return "10018 MARKET_CLOSED: weekend or the daily maintenance break";
      case TRADE_RETCODE_NO_MONEY:           return "10019 NO_MONEY: insufficient free margin";
      case TRADE_RETCODE_PRICE_CHANGED:      return "10020 PRICE_CHANGED: retry with a fresh tick";
      case TRADE_RETCODE_PRICE_OFF:          return "10021 PRICE_OFF: no quotes available";
      case TRADE_RETCODE_INVALID_EXPIRATION: return "10022 INVALID_EXPIRATION";
      case TRADE_RETCODE_ORDER_CHANGED:      return "10023 ORDER_CHANGED";
      case TRADE_RETCODE_TOO_MANY_REQUESTS:  return "10024 TOO_MANY_REQUESTS: back off before retrying";
      case TRADE_RETCODE_NO_CHANGES:         return "10025 NO_CHANGES";
      case TRADE_RETCODE_SERVER_DISABLES_AT: return "10026 SERVER_DISABLES_AT: algo trading disabled SERVER-SIDE; contact the broker";
      case TRADE_RETCODE_CLIENT_DISABLES_AT: return "10027 CLIENT_DISABLES_AT: click the Algo Trading button in the terminal";
      case TRADE_RETCODE_LOCKED:             return "10028 LOCKED";
      case TRADE_RETCODE_FROZEN:             return "10029 FROZEN: inside the freeze level and cannot be modified";
      case TRADE_RETCODE_INVALID_FILL:       return "10030 INVALID_FILL: filling mode unsupported by this symbol/account";
      case TRADE_RETCODE_CONNECTION:         return "10031 CONNECTION: the terminal lost its broker connection";
      case TRADE_RETCODE_ONLY_REAL:          return "10032 ONLY_REAL: live accounts only";
      case TRADE_RETCODE_LIMIT_ORDERS:       return "10033 LIMIT_ORDERS: pending order limit reached";
      case TRADE_RETCODE_LIMIT_VOLUME:       return "10034 LIMIT_VOLUME: total volume limit reached";
      case TRADE_RETCODE_INVALID_ORDER:      return "10035 INVALID_ORDER";
      case TRADE_RETCODE_POSITION_CLOSED:    return "10036 POSITION_CLOSED: already closed";
      default:                               return StringFormat("%d: unmapped retcode", code);
     }
  }

//+------------------------------------------------------------------+
//| Is this retcode worth another attempt with a refreshed price?     |
//+------------------------------------------------------------------+
bool FXSIsRetryable(const uint code)
  {
   return code == TRADE_RETCODE_REQUOTE
       || code == TRADE_RETCODE_PRICE_CHANGED
       || code == TRADE_RETCODE_PRICE_OFF
       || code == TRADE_RETCODE_INVALID_PRICE
       || code == TRADE_RETCODE_TOO_MANY_REQUESTS;
  }

//+------------------------------------------------------------------+
//| CFXSExecutor                                                      |
//+------------------------------------------------------------------+
class CFXSExecutor
  {
private:
   CTrade            m_trade;
   string            m_symbol;          // resolved broker symbol name
   int               m_digits;
   double            m_point;
   int               m_stops_level;     // points
   int               m_freeze_level;    // points
   double            m_vol_min;
   double            m_vol_max;
   double            m_vol_step;
   double            m_pip_size;        // price move of one "pip"
   int               m_deviation;
   long              m_magic;
   int               m_max_retries;
   string            m_last_error;

   void              CacheSpec(void);
   bool              ApplyFilling(const int attempt);

public:
                     CFXSExecutor(void);

   bool              Init(const string base_symbol, const long magic, const int deviation,
                          const double pip_size_override, const int max_retries);

   //--- accessors
   string            Symbol(void)      const { return m_symbol;       }
   int               Digits(void)      const { return m_digits;       }
   double            Point(void)       const { return m_point;        }
   double            PipSize(void)     const { return m_pip_size;     }
   double            VolumeMin(void)   const { return m_vol_min;      }
   double            VolumeStep(void)  const { return m_vol_step;     }
   int               StopsLevel(void)  const { return m_stops_level;  }
   string            LastError(void)   const { return m_last_error;   }

   //--- helpers (public so the EA can log what it is about to do)
   double            NormalizeVolume(const double volume) const;
   double            MinStopDistance(void) const;
   void              ClampStops(const bool is_buy, const double price, double &sl, double &tp) const;

   //--- operations
   bool              Open(const bool is_buy, const double volume,
                          const double sl_pips, const double tp_pips,
                          const double sl_price_in, const double tp_price_in,
                          const string comment,
                          ulong &out_ticket, double &out_price, double &out_volume,
                          uint &out_retcode);

   bool              OpenPending(const bool is_buy, const double volume,
                                  const double entry_price, const double sl_price_in,
                                  const double tp_price_in, const int expiry_minutes,
                                  const string comment,
                                  ulong &out_ticket, uint &out_retcode);

   bool              ClosePosition(const ulong ticket, const double volume, uint &out_retcode);
   bool              ModifyPosition(const ulong ticket, const double sl_price_in,
                                    const double tp_price_in, uint &out_retcode);
   int               CloseAllOwned(uint &out_retcode);
   int               CountOwnedPositions(void) const;
  };

//+------------------------------------------------------------------+
CFXSExecutor::CFXSExecutor(void) : m_symbol(""), m_digits(2), m_point(0.01),
                                   m_stops_level(0), m_freeze_level(0),
                                   m_vol_min(0.01), m_vol_max(100.0), m_vol_step(0.01),
                                   m_pip_size(0.0), m_deviation(20), m_magic(0),
                                   m_max_retries(3), m_last_error("")
  {
  }

//+------------------------------------------------------------------+
//| Resolve the broker's name for base_symbol and cache its limits.   |
//|                                                                   |
//| A hardcoded "XAUUSD" is not tradable on every server: Elev8,       |
//| Exness and XM publish gold with suffixes on some account types.   |
//| Scanning the server's own symbol list is the only reliable way to |
//| find it - and it must be pushed into Market Watch before any      |
//| SymbolInfo* call returns useful data.                             |
//+------------------------------------------------------------------+
bool CFXSExecutor::Init(const string base_symbol, const long magic, const int deviation,
                        const double pip_size_override, const int max_retries)
  {
   m_magic       = magic;
   m_deviation   = deviation;
   m_max_retries = (max_retries > 0 ? max_retries : 1);
   m_symbol      = "";

   const string base = base_symbol;

   //--- exact match first
   if(SymbolSelect(base, true) && SymbolInfoInteger(base, SYMBOL_TRADE_MODE) == SYMBOL_TRADE_MODE_FULL)
     {
      m_symbol = base;
     }
   else
     {
      //--- otherwise scan every symbol the server publishes, preferring the
      //--- shortest name that starts with the base (XAUUSDm beats XAUUSDmicro)
      int    best_len = INT_MAX;
      string best     = "";
      const int total = SymbolsTotal(false);

      for(int i = 0; i < total; i++)
        {
         const string name = SymbolName(i, false);
         if(StringFind(name, base) != 0)
            continue;
         const int len = StringLen(name);
         if(len < best_len)
           {
            best_len = len;
            best     = name;
           }
        }

      if(best != "" && SymbolSelect(best, true))
         m_symbol = best;
     }

   if(m_symbol == "")
     {
      m_last_error = StringFormat("No tradable symbol on this server starts with '%s'", base);
      return false;
     }

   CacheSpec();

   //--- Explicit override always wins. Gold is genuinely ambiguous: the broker's
   //--- point is 0.01, but most gold strategies call 0.10 a pip. Being wrong by
   //--- 10x puts every stop inside the stops level and every order returns 10016.
   if(pip_size_override > 0.0)
      m_pip_size = pip_size_override;
   else
      m_pip_size = (m_digits == 3 || m_digits == 5) ? m_point * 10.0 : m_point;

   m_trade.SetExpertMagicNumber(m_magic);
   m_trade.SetDeviationInPoints(m_deviation);
   m_trade.SetAsyncMode(false);

   return true;
  }

//+------------------------------------------------------------------+
void CFXSExecutor::CacheSpec(void)
  {
   m_digits       = (int)SymbolInfoInteger(m_symbol, SYMBOL_DIGITS);
   m_point        = SymbolInfoDouble(m_symbol, SYMBOL_POINT);
   m_stops_level  = (int)SymbolInfoInteger(m_symbol, SYMBOL_TRADE_STOPS_LEVEL);
   m_freeze_level = (int)SymbolInfoInteger(m_symbol, SYMBOL_TRADE_FREEZE_LEVEL);
   m_vol_min      = SymbolInfoDouble(m_symbol, SYMBOL_VOLUME_MIN);
   m_vol_max      = SymbolInfoDouble(m_symbol, SYMBOL_VOLUME_MAX);
   m_vol_step     = SymbolInfoDouble(m_symbol, SYMBOL_VOLUME_STEP);

   if(m_vol_step <= 0.0)
      m_vol_step = 0.01;
  }

//+------------------------------------------------------------------+
//| Snap a risk-derived lot size onto the broker's volume grid.       |
//|                                                                   |
//| Rounds DOWN, never up: risk sizing produces values like 0.037 and |
//| rounding up would silently take more risk than the setting allows.|
//+------------------------------------------------------------------+
double CFXSExecutor::NormalizeVolume(const double volume) const
  {
   double snapped = MathFloor(volume / m_vol_step) * m_vol_step;

   if(snapped < m_vol_min)
      snapped = m_vol_min;
   if(snapped > m_vol_max)
      snapped = m_vol_max;

   //--- floating point dust: 0.03 can arrive as 0.029999999999999998
   const int step_digits = (int)MathMax(0, MathCeil(-MathLog10(m_vol_step)));
   return NormalizeDouble(snapped, step_digits);
  }

//+------------------------------------------------------------------+
//| Minimum distance SL/TP must keep from price, in price units.      |
//+------------------------------------------------------------------+
double CFXSExecutor::MinStopDistance(void) const
  {
   return (double)MathMax(m_stops_level, m_freeze_level) * m_point;
  }

//+------------------------------------------------------------------+
//| Push SL/TP outside the broker's minimum stop distance.            |
//|                                                                   |
//| Also fixes the sign error where a buy's SL ends up above entry,   |
//| which the server reports as the same 10016 as a too-close stop.   |
//| Zero means "no level", and is left untouched.                     |
//+------------------------------------------------------------------+
void CFXSExecutor::ClampStops(const bool is_buy, const double price, double &sl, double &tp) const
  {
   const double min_dist = MinStopDistance();

   if(sl > 0.0)
     {
      const double limit = is_buy ? price - min_dist : price + min_dist;
      if((is_buy && sl > limit) || (!is_buy && sl < limit))
        {
         PrintFormat("[FXS] SL %s is inside the %d-point stops level; moving to %s",
                     DoubleToString(sl, m_digits), MathMax(m_stops_level, m_freeze_level),
                     DoubleToString(limit, m_digits));
         sl = limit;
        }
      sl = NormalizeDouble(sl, m_digits);
     }

   if(tp > 0.0)
     {
      const double limit = is_buy ? price + min_dist : price - min_dist;
      if((is_buy && tp < limit) || (!is_buy && tp > limit))
        {
         PrintFormat("[FXS] TP %s is inside the %d-point stops level; moving to %s",
                     DoubleToString(tp, m_digits), MathMax(m_stops_level, m_freeze_level),
                     DoubleToString(limit, m_digits));
         tp = limit;
        }
      tp = NormalizeDouble(tp, m_digits);
     }
  }

//+------------------------------------------------------------------+
//| Choose the filling mode for this attempt.                         |
//|                                                                   |
//| SYMBOL_FILLING_MODE is a bitmask of what the symbol allows. Market|
//| execution accounts commonly reject RETURN and often reject FOK, so|
//| hardcoding any one value works at one broker and returns 10030 at |
//| the next. Attempt 0 uses the advertised mode; later attempts walk |
//| the remaining candidates.                                         |
//+------------------------------------------------------------------+
bool CFXSExecutor::ApplyFilling(const int attempt)
  {
   const long mask = SymbolInfoInteger(m_symbol, SYMBOL_FILLING_MODE);

   ENUM_ORDER_TYPE_FILLING candidates[3];
   int count = 0;

   if((mask & SYMBOL_FILLING_IOC) != 0) candidates[count++] = ORDER_FILLING_IOC;
   if((mask & SYMBOL_FILLING_FOK) != 0) candidates[count++] = ORDER_FILLING_FOK;
   //--- RETURN is not advertised in the mask; it is valid on instant and exchange
   //--- execution accounts and is the correct last resort.
   candidates[count++] = ORDER_FILLING_RETURN;

   if(attempt >= count)
      return false;

   m_trade.SetTypeFilling(candidates[attempt]);
   return true;
  }

//+------------------------------------------------------------------+
//| Open a market position.                                           |
//|                                                                   |
//| Stops may be given in pips (converted with the symbol's own point |
//| size) or as absolute prices; absolute wins when both are present. |
//| Zero means "no level".                                            |
//+------------------------------------------------------------------+
bool CFXSExecutor::Open(const bool is_buy, const double volume,
                        const double sl_pips, const double tp_pips,
                        const double sl_price_in, const double tp_price_in,
                        const string comment,
                        ulong &out_ticket, double &out_price, double &out_volume,
                        uint &out_retcode)
  {
   out_ticket  = 0;
   out_price   = 0.0;
   out_volume  = 0.0;
   out_retcode = 0;
   m_last_error = "";

   const double lots = NormalizeVolume(volume);
   if(lots <= 0.0)
     {
      m_last_error = "Normalised volume is zero; nothing to send";
      return false;
     }

   int filling_attempt = 0;

   for(int attempt = 0; attempt < m_max_retries; attempt++)
     {
      MqlTick tick;
      if(!SymbolInfoTick(m_symbol, tick) || tick.ask <= 0.0 || tick.bid <= 0.0)
        {
         m_last_error = "No tick data; the market may be closed";
         return false;
        }

      const double price = is_buy ? tick.ask : tick.bid;
      const double sign  = is_buy ? 1.0 : -1.0;

      //--- pips -> price, unless an absolute level was supplied
      double sl = sl_price_in;
      double tp = tp_price_in;
      if(sl <= 0.0 && sl_pips > 0.0) sl = price - sign * sl_pips * m_pip_size;
      if(tp <= 0.0 && tp_pips > 0.0) tp = price + sign * tp_pips * m_pip_size;

      ClampStops(is_buy, price, sl, tp);

      if(!ApplyFilling(filling_attempt))
        {
         m_last_error = "No filling mode accepted by this symbol";
         return false;
        }

      const ENUM_ORDER_TYPE type = is_buy ? ORDER_TYPE_BUY : ORDER_TYPE_SELL;
      const bool sent = m_trade.PositionOpen(m_symbol, type, lots,
                                             NormalizeDouble(price, m_digits), sl, tp, comment);

      out_retcode = m_trade.ResultRetcode();

      if(sent && (out_retcode == TRADE_RETCODE_DONE || out_retcode == TRADE_RETCODE_DONE_PARTIAL))
        {
         out_price  = m_trade.ResultPrice();
         out_volume = m_trade.ResultVolume();

         //--- ResultOrder() is the ORDER ticket. Every later close refers to the
         //--- POSITION ticket, and the two coincide only in hedging mode - so read
         //--- the position id off the resulting deal instead of assuming.
         const ulong deal = m_trade.ResultDeal();
         if(deal != 0 && HistoryDealSelect(deal))
            out_ticket = (ulong)HistoryDealGetInteger(deal, DEAL_POSITION_ID);
         else
            out_ticket = m_trade.ResultOrder();

         return true;
        }

      //--- a rejected filling mode is the one failure worth re-sending unchanged
      if(out_retcode == TRADE_RETCODE_INVALID_FILL)
        {
         filling_attempt++;
         attempt--;                       // this attempt did not consume a retry
         if(filling_attempt > 2)
           {
            m_last_error = FXSExplainRetcode(out_retcode);
            return false;
           }
         continue;
        }

      if(FXSIsRetryable(out_retcode) && attempt < m_max_retries - 1)
        {
         PrintFormat("[FXS] attempt %d/%d: %s - retrying", attempt + 1, m_max_retries,
                     FXSExplainRetcode(out_retcode));
         Sleep(200 * (attempt + 1));
         continue;
        }

      m_last_error = FXSExplainRetcode(out_retcode) + " | " + m_trade.ResultRetcodeDescription();
      return false;
     }

   m_last_error = "Gave up after " + IntegerToString(m_max_retries) + " attempts: "
                + FXSExplainRetcode(out_retcode);
   return false;
  }

//+------------------------------------------------------------------+
//| Place a resting order at a level the market has not reached      |
//|                                                                  |
//| A signal naming an entry is asking to be filled there. A market  |
//| order ignores that and takes whatever is available now, which on |
//| a zone signal is a different trade to the one that was reviewed. |
//|                                                                  |
//| Limit or stop is decided here rather than by the caller: a buy   |
//| below price is a limit, a buy above it is a stop, and sending    |
//| the wrong one is refused with 10015. The dashboard cannot see    |
//| the current tick, so it must not be the thing that chooses.      |
//+------------------------------------------------------------------+
bool CFXSExecutor::OpenPending(const bool is_buy, const double volume,
                               const double entry_price, const double sl_price_in,
                               const double tp_price_in, const int expiry_minutes,
                               const string comment,
                               ulong &out_ticket, uint &out_retcode)
  {
   out_ticket  = 0;
   out_retcode = 0;

   if(entry_price <= 0.0)
     {
      m_last_error = "A resting order needs an entry price";
      return false;
     }

   const double lots = NormalizeVolume(volume);
   if(lots <= 0.0)
     {
      m_last_error = "Volume rounds to zero on this symbol's grid";
      return false;
     }

   MqlTick tick;
   if(!SymbolInfoTick(m_symbol, tick) || tick.ask <= 0.0 || tick.bid <= 0.0)
     {
      m_last_error = "No tick data; the market may be closed";
      return false;
     }

   const double entry  = NormalizeDouble(entry_price, m_digits);
   const double market = is_buy ? tick.ask : tick.bid;

   //--- A resting order must sit at least the broker's stops level from the market. Inside
   //--- it the order is refused with 10016 - the rule that governs stops, applied to entry.
   const double min_distance = (double)SymbolInfoInteger(m_symbol, SYMBOL_TRADE_STOPS_LEVEL) * m_point;
   if(MathAbs(entry - market) < min_distance)
     {
      m_last_error = StringFormat(
                        "Entry %s is inside the broker's stops level (%.0f points) of the market at %s",
                        DoubleToString(entry, m_digits), min_distance / m_point,
                        DoubleToString(market, m_digits));
      return false;
     }

   ENUM_ORDER_TYPE type;
   if(is_buy)
      type = (entry < market) ? ORDER_TYPE_BUY_LIMIT : ORDER_TYPE_BUY_STOP;
   else
      type = (entry > market) ? ORDER_TYPE_SELL_LIMIT : ORDER_TYPE_SELL_STOP;

   double sl = sl_price_in;
   double tp = tp_price_in;

   //--- Clamped against the ENTRY, not the market. The stop belongs to the price this order
   //--- will fill at; clamping against a market it has not touched would move it somewhere
   //--- the signal never asked for.
   ClampStops(is_buy, entry, sl, tp);

   if(!ApplyFilling(0))
     {
      m_last_error = "No filling mode accepted by this symbol";
      return false;
     }

   //--- Expiry is not optional. An order resting for ever is a trade waiting to open on a
   //--- setup that stopped existing hours ago.
   datetime expiry = 0;
   ENUM_ORDER_TYPE_TIME timing = ORDER_TIME_GTC;

   if(expiry_minutes > 0)
     {
      expiry = TimeCurrent() + (expiry_minutes * 60);
      timing = ORDER_TIME_SPECIFIED;
     }

   const bool sent = m_trade.OrderOpen(m_symbol, type, lots, 0.0, entry, sl, tp,
                                       timing, expiry, comment);

   out_retcode = m_trade.ResultRetcode();

   if(sent && (out_retcode == TRADE_RETCODE_DONE || out_retcode == TRADE_RETCODE_PLACED))
     {
      out_ticket = m_trade.ResultOrder();
      return true;
     }

   m_last_error = FXSExplainRetcode(out_retcode);
   return false;
  }

//+------------------------------------------------------------------+
//| Close all or part of a position by ticket.                        |
//|                                                                   |
//| Partial closes are how the TP1/TP2/TP3 ladder is executed; each   |
//| one becomes a row in trade_partials.                              |
//+------------------------------------------------------------------+
bool CFXSExecutor::ClosePosition(const ulong ticket, const double volume, uint &out_retcode)
  {
   out_retcode  = 0;
   m_last_error = "";

   if(!PositionSelectByTicket(ticket))
     {
      m_last_error = "Position " + IntegerToString((long)ticket) + " not found (already closed?)";
      return false;
     }

   const double held = PositionGetDouble(POSITION_VOLUME);
   double lots = (volume > 0.0 ? NormalizeVolume(volume) : held);
   if(lots > held)
      lots = held;

   for(int filling_attempt = 0; filling_attempt < 3; filling_attempt++)
     {
      if(!ApplyFilling(filling_attempt))
         break;

      //--- closing the whole position and closing part of it are different calls;
      //--- PositionClosePartial with the full volume is rejected by some servers
      const bool sent = (lots >= held)
                        ? m_trade.PositionClose(ticket, m_deviation)
                        : m_trade.PositionClosePartial(ticket, lots, m_deviation);

      out_retcode = m_trade.ResultRetcode();

      if(sent && (out_retcode == TRADE_RETCODE_DONE || out_retcode == TRADE_RETCODE_DONE_PARTIAL))
         return true;

      if(out_retcode != TRADE_RETCODE_INVALID_FILL)
        {
         m_last_error = FXSExplainRetcode(out_retcode) + " | " + m_trade.ResultRetcodeDescription();
         return false;
        }
     }

   m_last_error = FXSExplainRetcode(out_retcode);
   return false;
  }

//+------------------------------------------------------------------+
//| Move the stop and/or take profit of an open position.             |
//|                                                                   |
//| Zero means "leave this one alone", so the dashboard can move the  |
//| stop to break-even without having to restate a take profit it     |
//| does not want changed. Passing 0.0 straight to PositionModify     |
//| would instead *remove* the level, turning a break-even move into  |
//| a silent removal of the target.                                   |
//|                                                                   |
//| The requested levels go through the same ClampStops() as an entry.|
//| A broker-side stops level is measured from the *current* price,   |
//| so a break-even stop is rejected with 10016 exactly when price    |
//| has come back close to entry - which is precisely when it is      |
//| being asked for.                                                  |
//+------------------------------------------------------------------+
bool CFXSExecutor::ModifyPosition(const ulong ticket, const double sl_price_in,
                                  const double tp_price_in, uint &out_retcode)
  {
   out_retcode  = 0;
   m_last_error = "";

   if(!PositionSelectByTicket(ticket))
     {
      m_last_error = "Position " + IntegerToString((long)ticket) + " not found (already closed?)";
      return false;
     }

   const bool is_buy = (PositionGetInteger(POSITION_TYPE) == POSITION_TYPE_BUY);

   double sl = (sl_price_in > 0.0) ? sl_price_in : PositionGetDouble(POSITION_SL);
   double tp = (tp_price_in > 0.0) ? tp_price_in : PositionGetDouble(POSITION_TP);

   MqlTick tick;
   if(!SymbolInfoTick(m_symbol, tick) || tick.ask <= 0.0 || tick.bid <= 0.0)
     {
      m_last_error = "No tick data; the market may be closed";
      return false;
     }

   //--- Clamp against the price the position would be closed at.
   const double price = is_buy ? tick.bid : tick.ask;
   ClampStops(is_buy, price, sl, tp);

   sl = NormalizeDouble(sl, m_digits);
   tp = NormalizeDouble(tp, m_digits);

   //--- Nothing to do. Reported as success: the position already sits where the
   //--- dashboard asked it to, and a failure here would be retried for ever.
   if(MathAbs(sl - PositionGetDouble(POSITION_SL)) < m_point / 2.0
      && MathAbs(tp - PositionGetDouble(POSITION_TP)) < m_point / 2.0)
     {
      out_retcode = TRADE_RETCODE_DONE;
      return true;
     }

   const bool sent = m_trade.PositionModify(ticket, sl, tp);
   out_retcode = m_trade.ResultRetcode();

   if(sent && (out_retcode == TRADE_RETCODE_DONE || out_retcode == TRADE_RETCODE_PLACED))
      return true;

   m_last_error = FXSExplainRetcode(out_retcode) + " | " + m_trade.ResultRetcodeDescription();
   return false;
  }

//+------------------------------------------------------------------+
//| Count positions opened by this EA (magic number filter).          |
//+------------------------------------------------------------------+
int CFXSExecutor::CountOwnedPositions(void) const
  {
   int owned = 0;

   for(int i = PositionsTotal() - 1; i >= 0; i--)
     {
      const ulong ticket = PositionGetTicket(i);
      if(ticket == 0)
         continue;
      if(PositionGetInteger(POSITION_MAGIC) == m_magic)
         owned++;
     }

   return owned;
  }

//+------------------------------------------------------------------+
//| Emergency flatten: close every position this EA owns.             |
//|                                                                   |
//| Iterates backwards because closing a position removes it from the |
//| list and shifts every later index.                                |
//| Positions opened by hand or by another EA are deliberately left   |
//| alone - the magic number is the only thing separating them.       |
//+------------------------------------------------------------------+
int CFXSExecutor::CloseAllOwned(uint &out_retcode)
  {
   int closed = 0;
   out_retcode = 0;

   for(int i = PositionsTotal() - 1; i >= 0; i--)
     {
      const ulong ticket = PositionGetTicket(i);
      if(ticket == 0)
         continue;
      if(PositionGetInteger(POSITION_MAGIC) != m_magic)
         continue;

      uint retcode = 0;
      if(ClosePosition(ticket, 0.0, retcode))
         closed++;
      else
        {
         out_retcode = retcode;
         PrintFormat("[FXS] close-all could not close #%s: %s", IntegerToString((long)ticket), m_last_error);
        }
     }

   return closed;
  }
//+------------------------------------------------------------------+
