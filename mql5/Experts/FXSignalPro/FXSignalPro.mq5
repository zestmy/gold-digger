//+------------------------------------------------------------------+
//|                                                 FXSignalPro.mq5  |
//|              FXSignalPro - Laravel <-> MetaTrader 5 execution EA  |
//+------------------------------------------------------------------+
//| Executes the trade_commands queue from the FXSignalPro dashboard  |
//| and reports fills, heartbeats and logs back to it.                |
//|                                                                   |
//| WHY AN EA RATHER THAN THE PYTHON MetaTrader5 PACKAGE?             |
//| That package is a Windows-only IPC wrapper around a running       |
//| terminal, and the whole class of failures it introduces - the     |
//| terminal not being attached, privilege mismatches, order_send     |
//| returning None - simply does not exist here. This code runs       |
//| inside the terminal. See docs/MT5_EXECUTION.md section 4.         |
//|                                                                   |
//| SETUP                                                             |
//|  1. Copy mql5/Include/FXSignalPro -> <terminal>/MQL5/Include/     |
//|     and mql5/Experts/FXSignalPro  -> <terminal>/MQL5/Experts/     |
//|  2. Compile this file in MetaEditor (F7).                         |
//|  3. Tools > Options > Expert Advisors > "Allow WebRequest for     |
//|     listed URL" and add your dashboard's origin. HTTPS only.      |
//|  4. Issue a token:  php artisan bot:token you@example.com         |
//|     --name="Windows VPS" --account=1                              |
//|  5. Attach to any XAUUSD chart with Algo Trading enabled.         |
//|                                                                   |
//| The chart's own symbol and timeframe are irrelevant: every        |
//| instruction names its symbol, and all work happens on a timer.    |
//+------------------------------------------------------------------+
#property copyright "FXSignalPro"
#property version   "1.00"
#property description "Executes FXSignalPro dashboard commands and reports fills back."

#include <FXSignalPro/Executor.mqh>

//--- Must match TradeCommand::WIRE_VERSION on the Laravel side.
#define FXS_WIRE_VERSION   "GDCMD2"
#define FXS_WIRE_COLUMNS   13
#define FXS_EA_VERSION     "1.0.0"
#define FXS_MAX_PENDING    200

//+------------------------------------------------------------------+
//| Inputs                                                            |
//+------------------------------------------------------------------+
input group             "Connection"
input string   ApiBaseUrl    = "https://fxsignal.pro";               // Dashboard URL (must be whitelisted)
input string   ApiToken      = "";                                   // Token from: php artisan bot:token
input int      PollSeconds   = 5;                                    // Seconds between polls
input int      HttpTimeoutMs = 4000;                                 // Per-request timeout

input group             "Trading"
input string   BaseSymbols   = "XAUUSD";     // Base symbols, comma separated; suffixes resolved per symbol
input double   PipSize       = 0.10;         // Price move of one pip (0 = infer; gold is usually 0.10)
input long     MagicNumber   = 20240101;     // Identifies this EA's positions
input int      Deviation     = 20;           // Max slippage in points (gold needs 20-30)
input int      MaxRetries    = 3;            // Attempts on requote / price-changed

input group             "Strategy data"
//--- Closed bars are pushed to the dashboard, which computes the indicators and
//--- decides whether to enter. The series has to come from this terminal: an ATR
//--- taken from some other vendor's gold feed would size stops against prices this
//--- broker never quoted.
input bool     PushCandles   = true;         // Send closed bars so the dashboard can generate signals
input ENUM_TIMEFRAMES EntryTimeframe = PERIOD_M5; // Must match the strategy's entry timeframe
input ENUM_TIMEFRAMES TrendTimeframe = PERIOD_H1; // Must match the strategy's trend timeframe
input int      HistoryBars   = 300;          // Bars sent on first push (indicator warm-up)
input int      WindowBars    = 5;            // Bars re-sent per new bar, so a dropped push self-heals

input group             "Reconciliation"
//--- The dashboard only ever learns about positions it was told about. A snapshot of
//--- what the terminal actually holds is what corrects `trades` after anything was
//--- missed - a position opened by hand, or closed while nothing was running.
input bool     Reconcile        = true;   // Report open positions so the dashboard can correct itself
input int      ReconcileMinutes = 15;     // Minutes between snapshots
input int      ReplayHistoryDays = 3;     // On attach, re-report closes from this many days back

input group             "Safety"
input int      PendingExpiryMinutes = 120;   // Resting orders expire after this (0 = never)

input bool     DryRun        = false;        // Log commands without executing them
input bool     DemoOnly      = true;         // Refuse to run on a live account

//+------------------------------------------------------------------+
//| State                                                             |
//+------------------------------------------------------------------+
//--- One executor per instrument. Bounded rather than dynamic: each one holds a
//--- symbol's specification and every poll walks the whole list, so a terminal
//--- carrying dozens would spend its timer on HTTP rather than trading. Eight is far
//--- more than any account here runs and makes the array a fixed cost.
#define FXS_MAX_SYMBOLS 8

CFXSExecutor g_exec[FXS_MAX_SYMBOLS];
string       g_base[FXS_MAX_SYMBOLS];      // what the dashboard calls each one
int          g_symbols          = 0;       // how many were resolved at init

bool         g_ready            = false;   // OnInit completed successfully
bool         g_trading_enabled  = false;   // mirrors bot_settings.is_active
datetime     g_last_warned      = 0;       // rate-limits the repeated-warning spam

//--- Newest closed bar already pushed, per series *per symbol*. Shared state here was
//--- the whole of what made this EA single-instrument in practice: one cursor across
//--- several feeds means the first symbol to close a bar suppresses the rest.
datetime     g_last_entry_bar[FXS_MAX_SYMBOLS];
datetime     g_last_trend_bar[FXS_MAX_SYMBOLS];
bool         g_entry_seeded[FXS_MAX_SYMBOLS];
bool         g_trend_seeded[FXS_MAX_SYMBOLS];

//--- Why this EA was told to close a position, kept until the resulting deal shows
//--- up in OnTradeTransaction. A broker deal cannot say which rung of the take-profit
//--- ladder it was - DEAL_REASON only distinguishes "an expert did it" from a stop or
//--- a target - so the dashboard states the rung on the command and it is echoed back
//--- with the fill. Without this every commanded close is recorded as "manual" and the
//--- ladder is invisible in trade_partials.
#define FXS_MAX_CLOSE_REASONS 32
ulong        g_close_ticket[];
string       g_close_reason[];

//--- When the last position snapshot was sent.
datetime     g_last_reconcile   = 0;

//--- Fill reports are queued here by OnTradeTransaction and flushed by OnTimer.
//--- WebRequest is synchronous: calling it inside a trade-transaction handler
//--- would stall the terminal's event thread on every fill.
string       g_pending[];

//+------------------------------------------------------------------+
//| Escape a string for embedding in JSON.                            |
//+------------------------------------------------------------------+
string FXSJsonEscape(const string value)
  {
   string out = value;
   StringReplace(out, "\\", "\\\\");
   StringReplace(out, "\"", "\\\"");
   StringReplace(out, "\n", "\\n");
   StringReplace(out, "\r", "\\r");
   StringReplace(out, "\t", "\\t");
   return out;
  }

//+------------------------------------------------------------------+
//| Perform one HTTP request against the dashboard.                   |
//| Returns the HTTP status code, or -1 when the request never left.  |
//+------------------------------------------------------------------+
int FXSHttp(const string method, const string path, const string body,
           const string accept, string &out_body)
  {
   out_body = "";

   if(ApiToken == "")
     {
      Print("[FXS] ApiToken is empty - set it in the EA inputs.");
      return -1;
     }

   string url = ApiBaseUrl;
   if(StringLen(url) > 0 && StringSubstr(url, StringLen(url) - 1, 1) == "/")
      url = StringSubstr(url, 0, StringLen(url) - 1);
   url = url + path;

   string headers = "Authorization: Bearer " + ApiToken + "\r\n"
                  + "Accept: " + accept + "\r\n";
   if(body != "")
      headers += "Content-Type: application/json\r\n";

   char data[];
   if(body != "")
      StringToCharArray(body, data, 0, StringLen(body), CP_UTF8);
   else
      ArrayResize(data, 0);

   char   result[];
   string result_headers;

   ResetLastError();
   const int status = WebRequest(method, url, headers, HttpTimeoutMs, data, result, result_headers);

   if(status == -1)
     {
      const int err = GetLastError();
      if(err == 4014)
         PrintFormat("[FXS] WebRequest is not permitted for %s. Tools > Options > Expert Advisors > "
                     "'Allow WebRequest for listed URL' and add exactly this origin.", ApiBaseUrl);
      else
         PrintFormat("[FXS] WebRequest to %s failed with error %d.", url, err);
      return -1;
     }

   if(ArraySize(result) > 0)
      out_body = CharArrayToString(result, 0, WHOLE_ARRAY, CP_UTF8);

   if(status >= 400)
      PrintFormat("[FXS] %s %s -> HTTP %d: %s", method, path, status, out_body);

   return status;
  }

//+------------------------------------------------------------------+
//| Send a log line to the dashboard so it appears on /logs.          |
//| Failures here are swallowed: logging must never break trading.    |
//+------------------------------------------------------------------+
void FXSLog(const string level, const string message)
  {
   PrintFormat("[FXS][%s] %s", level, message);

   const string body = StringFormat("{\"level\":\"%s\",\"source\":\"mql5_ea\",\"message\":\"%s\"}",
                                    level, FXSJsonEscape(message));
   string ignored;
   FXSHttp("POST", "/api/v1/bot/logs", body, "application/json", ignored);
  }

//+------------------------------------------------------------------+
//| Queue a fill report for the next OnTimer flush.                   |
//+------------------------------------------------------------------+
void FXSQueueReport(const string json)
  {
   const int n = ArraySize(g_pending);

   if(n >= FXS_MAX_PENDING)
     {
      //--- Dropping the oldest is the least-bad option: a backlog this size means
      //--- the dashboard has been unreachable for a long time, and the newest fills
      //--- are the ones that still matter for reconciliation.
      Print("[FXS] pending report buffer full; dropping the oldest entry");
      for(int i = 1; i < n; i++)
         g_pending[i - 1] = g_pending[i];
      g_pending[n - 1] = json;
      return;
     }

   ArrayResize(g_pending, n + 1);
   g_pending[n] = json;
  }

//+------------------------------------------------------------------+
//| Flush queued fill reports. Anything that fails stays queued.      |
//+------------------------------------------------------------------+
void FXSFlushReports(void)
  {
   const int n = ArraySize(g_pending);
   if(n == 0)
      return;

   string  remaining[];
   int     kept = 0;

   for(int i = 0; i < n; i++)
     {
      string response;
      const int status = FXSHttp("POST", "/api/v1/bot/fills", g_pending[i], "application/json", response);

      //--- 2xx means recorded. A 4xx means the dashboard rejected it and will keep
      //--- rejecting it, so retrying forever would block every later report.
      if(status >= 200 && status < 300)
         continue;

      if(status >= 400 && status < 500)
        {
         PrintFormat("[FXS] fill report rejected (HTTP %d), discarding: %s", status, response);
         continue;
        }

      ArrayResize(remaining, kept + 1);
      remaining[kept++] = g_pending[i];
     }

   ArrayResize(g_pending, kept);
   for(int i = 0; i < kept; i++)
      g_pending[i] = remaining[i];
  }

//+------------------------------------------------------------------+
//| Report the outcome of a command.                                  |
//+------------------------------------------------------------------+
void FXSReportResult(const long command_id, const bool ok, const uint retcode,
                    const ulong ticket, const double price, const double volume,
                    const string error)
  {
   const string body = StringFormat(
      "{\"ok\":%s,\"retcode\":%d,\"ticket\":%s,\"price\":%s,\"volume\":%s,\"error\":\"%s\"}",
      (ok ? "true" : "false"), retcode, IntegerToString((long)ticket),
      //--- Six places because that is what trades.entry_price stores. Formatting to a
      //--- particular symbol's digits was only ever cosmetic here, and with several
      //--- instruments loaded there is no longer one right answer to pick.
      DoubleToString(price, 6), DoubleToString(volume, 2),
      FXSJsonEscape(error));

   string response;
   FXSHttp("POST", "/api/v1/bot/commands/" + IntegerToString(command_id) + "/result",
          body, "application/json", response);
  }

//+------------------------------------------------------------------+
//| Remember why a commanded close was made.                          |
//+------------------------------------------------------------------+
void FXSRememberCloseReason(const ulong ticket, const string reason)
  {
   if(reason == "")
      return;

   const int n = ArraySize(g_close_ticket);

   for(int i = 0; i < n; i++)
      if(g_close_ticket[i] == ticket)
        {
         g_close_reason[i] = reason;
         return;
        }

   //--- A backlog this size means deals are not arriving at all. Dropping the oldest
   //--- costs one mislabelled partial; growing without limit costs the terminal.
   if(n >= FXS_MAX_CLOSE_REASONS)
     {
      for(int i = 1; i < n; i++)
        {
         g_close_ticket[i - 1] = g_close_ticket[i];
         g_close_reason[i - 1] = g_close_reason[i];
        }
      g_close_ticket[n - 1] = ticket;
      g_close_reason[n - 1] = reason;
      return;
     }

   ArrayResize(g_close_ticket, n + 1);
   ArrayResize(g_close_reason, n + 1);
   g_close_ticket[n] = ticket;
   g_close_reason[n] = reason;
  }

//+------------------------------------------------------------------+
//| Consume a remembered close reason, or "" if there is none.        |
//|                                                                   |
//| Consuming rather than reading: one command produces one deal, and |
//| a reason left behind would be attached to whatever closed this    |
//| position next - including a stop loss.                            |
//+------------------------------------------------------------------+
string FXSTakeCloseReason(const ulong ticket)
  {
   const int n = ArraySize(g_close_ticket);

   for(int i = 0; i < n; i++)
     {
      if(g_close_ticket[i] != ticket)
         continue;

      const string reason = g_close_reason[i];

      for(int j = i + 1; j < n; j++)
        {
         g_close_ticket[j - 1] = g_close_ticket[j];
         g_close_reason[j - 1] = g_close_reason[j];
        }

      ArrayResize(g_close_ticket, n - 1);
      ArrayResize(g_close_reason, n - 1);

      return reason;
     }

   return "";
  }

//+------------------------------------------------------------------+
//| Account-currency value of a one-pip move on one lot.              |
//|                                                                   |
//| This is the whole of position sizing, and the dashboard cannot    |
//| work it out: it depends on contract size, tick value and the      |
//| deposit currency, all of which live here. Reported as null when   |
//| the symbol does not supply them, because a wrong value does not   |
//| fail loudly - it silently trades the wrong size.                  |
//+------------------------------------------------------------------+
//+------------------------------------------------------------------+
//| Which executor speaks for a symbol.                               |
//|                                                                   |
//| The dashboard names instruments the way it stores them - XAUUSD - |
//| while the broker may quote XAUUSDm or XAUUSD.raw. Each executor    |
//| resolved its own suffix at init, so both spellings are matched:    |
//| the base name the dashboard sent, and the resolved name the        |
//| terminal actually trades.                                          |
//|                                                                   |
//| Returns -1 for an instrument this terminal was not told to carry,  |
//| which is a refusal rather than a default. Falling back to the      |
//| first symbol would fill a gold order on whatever happened to be    |
//| loaded first, which is the worst available outcome.                |
//+------------------------------------------------------------------+
int FXSIndexFor(const string symbol)
  {
   if(symbol == "")
      return -1;

   for(int i = 0; i < g_symbols; i++)
      if(g_base[i] == symbol || g_exec[i].Symbol() == symbol)
         return i;

   //--- Prefix match last: the dashboard sends the base, the terminal holds the
   //--- suffixed name, and StringFind at position 0 is what ties them together.
   for(int i = 0; i < g_symbols; i++)
      if(StringFind(g_exec[i].Symbol(), symbol) == 0)
         return i;

   return -1;
  }

//+------------------------------------------------------------------+
//| Which executor owns an open position.                             |
//|                                                                   |
//| Closing and modifying take a ticket, but the arithmetic around     |
//| them does not: ModifyPosition clamps against its own symbol's tick |
//| and normalises to its own digits. Routed by the position's symbol  |
//| so a stop on EURUSD is never clamped against the price of gold.    |
//+------------------------------------------------------------------+
int FXSIndexForTicket(const ulong ticket)
  {
   if(!PositionSelectByTicket(ticket))
      return -1;

   return FXSIndexFor(PositionGetString(POSITION_SYMBOL));
  }

double FXSPipValuePerLot(const int idx)
  {
   const string sym = g_exec[idx].Symbol();

   const double tick_value = SymbolInfoDouble(sym, SYMBOL_TRADE_TICK_VALUE);
   const double tick_size  = SymbolInfoDouble(sym, SYMBOL_TRADE_TICK_SIZE);

   if(tick_value <= 0.0 || tick_size <= 0.0 || g_exec[idx].PipSize() <= 0.0)
      return 0.0;

   return tick_value * (g_exec[idx].PipSize() / tick_size);
  }

//+------------------------------------------------------------------+
//| Timeframe as the dashboard names it: PERIOD_M5 -> "M5".           |
//+------------------------------------------------------------------+
string FXSTimeframeName(const ENUM_TIMEFRAMES tf)
  {
   string name = EnumToString(tf);
   StringReplace(name, "PERIOD_", "");
   return name;
  }

//+------------------------------------------------------------------+
//| Convert a server timestamp to UTC.                                |
//|                                                                   |
//| iTime() returns broker-server time, which for most retail brokers |
//| is UTC+2 or UTC+3 and shifts with the broker's own daylight       |
//| saving. Sent unconverted, every bar would be filed under an hour  |
//| it did not happen in, and the dashboard's session filter would    |
//| gate London against the wrong window.                             |
//+------------------------------------------------------------------+
long FXSServerToUtc(const datetime server_time)
  {
   datetime server_now = TimeTradeServer();
   if(server_now == 0)
      server_now = TimeCurrent();

   const datetime gmt_now = TimeGMT();

   //--- Without both clocks there is no offset to apply. Sending the raw server
   //--- time would be a silent hour-scale error, so send nothing instead.
   if(server_now == 0 || gmt_now == 0)
      return 0;

   return (long)server_time - ((long)server_now - (long)gmt_now);
  }

//+------------------------------------------------------------------+
//| Push closed bars of one series to the dashboard.                  |
//|                                                                   |
//| Index 1 is the newest *closed* bar; index 0 is still forming and  |
//| is deliberately never sent. A forming bar's high, low and close   |
//| all still move, so an EMA cross computed on one can appear and    |
//| vanish inside the same bar - an entry the completed bar never     |
//| justified.                                                        |
//+------------------------------------------------------------------+
bool FXSPushCandles(const int idx, const ENUM_TIMEFRAMES tf, const int count)
  {
   if(count < 1)
      return false;

   MqlRates rates[];
   ArraySetAsSeries(rates, false);

   const int copied = CopyRates(g_exec[idx].Symbol(), tf, 1, count, rates);

   if(copied <= 0)
     {
      PrintFormat("[FXS] CopyRates(%s, %s) returned %d - history may still be loading.",
                  g_exec[idx].Symbol(), FXSTimeframeName(tf), copied);
      return false;
     }

   const int digits = g_exec[idx].Digits();
   string bars = "";

   for(int i = 0; i < copied; i++)
     {
      const long utc = FXSServerToUtc(rates[i].time);
      if(utc <= 0)
         continue;

      if(bars != "")
         bars += ",";

      //--- Every numeric goes through IntegerToString/DoubleToString rather than a
      //--- width specifier: this builds JSON, and a locale-formatted double or a
      //--- mis-sized integer specifier would produce a body the dashboard rejects
      //--- wholesale rather than a value that is merely slightly wrong.
      bars += StringFormat(
         "{\"time\":%s,\"open\":%s,\"high\":%s,\"low\":%s,\"close\":%s,"
         "\"tick_volume\":%s,\"spread_points\":%s}",
         IntegerToString(utc),
         DoubleToString(rates[i].open,  digits),
         DoubleToString(rates[i].high,  digits),
         DoubleToString(rates[i].low,   digits),
         DoubleToString(rates[i].close, digits),
         IntegerToString((long)rates[i].tick_volume),
         IntegerToString((long)rates[i].spread));
     }

   if(bars == "")
      return false;

   //--- The instrument's own numbers travel with the bars they describe, so every symbol
   //--- with price history also has a specification and the two cannot drift apart. This is
   //--- what lets the dashboard hold more than one instrument: its heartbeat has room for
   //--- exactly one symbol's figures, and this does not.
   const double pip_value = FXSPipValuePerLot(idx);

   const string spec = StringFormat(
      "{\"pip_size\":%s,\"digits\":%d,\"pip_value_per_lot\":%s,"
      "\"volume_min\":%s,\"volume_step\":%s}",
      (g_exec[idx].PipSize() > 0.0 ? DoubleToString(g_exec[idx].PipSize(), 5) : "null"),
      g_exec[idx].Digits(),
      (pip_value > 0.0 ? DoubleToString(pip_value, 5) : "null"),
      DoubleToString(g_exec[idx].VolumeMin(), 4),
      DoubleToString(g_exec[idx].VolumeStep(), 4));

   const string body = StringFormat(
      "{\"symbol\":\"%s\",\"base_symbol\":\"%s\",\"timeframe\":\"%s\","
      "\"source\":\"mql5_ea\",\"spec\":%s,\"bars\":[%s]}",
      FXSJsonEscape(g_exec[idx].Symbol()), FXSJsonEscape(g_base[idx]), FXSTimeframeName(tf), spec, bars);

   string response;
   const int status = FXSHttp("POST", "/api/v1/bot/candles", body, "application/json", response);

   return (status >= 200 && status < 300);
  }

//+------------------------------------------------------------------+
//| Push one series if its newest closed bar has changed.             |
//|                                                                   |
//| The first push carries HistoryBars so the dashboard's indicators  |
//| can warm up; later ones carry only WindowBars. The overlap is     |
//| what makes a dropped push self-healing - the dashboard upserts,   |
//| so re-sending a stored bar costs nothing and fills any gap.       |
//+------------------------------------------------------------------+
void FXSMaintainSeries(const int idx, const ENUM_TIMEFRAMES tf, datetime &last_bar, bool &seeded)
  {
   const datetime closed = iTime(g_exec[idx].Symbol(), tf, 1);

   if(closed == 0 || closed == last_bar)
      return;

   const int count = seeded ? WindowBars : HistoryBars;

   //--- Only advance the marker on success, so a failed push is retried on the next
   //--- timer rather than being silently skipped until the following bar.
   if(FXSPushCandles(idx, tf, count))
     {
      last_bar = closed;
      seeded   = true;
     }
  }

//+------------------------------------------------------------------+
//| Keep both series current.                                         |
//+------------------------------------------------------------------+
void FXSMaintainCandles(void)
  {
   if(!PushCandles)
      return;

   //--- Every instrument, each with its own cursor. One shared cursor across several
   //--- feeds meant the first symbol to close a bar suppressed the others' pushes.
   for(int i = 0; i < g_symbols; i++)
     {
      FXSMaintainSeries(i, EntryTimeframe, g_last_entry_bar[i], g_entry_seeded[i]);

      //--- The trend series is an input to the next entry bar, not a trigger of its
      //--- own; the dashboard generates nothing when it arrives.
      if(TrendTimeframe != EntryTimeframe)
         FXSMaintainSeries(i, TrendTimeframe, g_last_trend_bar[i], g_trend_seeded[i]);
     }
  }

//+------------------------------------------------------------------+
//| Report every position this EA owns, so the dashboard can correct  |
//| its own table.                                                    |
//|                                                                   |
//| A snapshot rather than events, because events are precisely what  |
//| goes missing: a position opened while the API was unreachable, or |
//| closed while the terminal was shut, produced no report anyone      |
//| received. Whatever was missed, the next snapshot states outright.  |
//|                                                                   |
//| The magic number travels with it and is the whole of its scope.   |
//| Without one the dashboard adopts what the list names but concludes|
//| nothing from absence - otherwise a second EA on the same account  |
//| would have its positions closed by this one's report.             |
//+------------------------------------------------------------------+
void FXSReportPositions(void)
  {
   string items = "";

   for(int i = PositionsTotal() - 1; i >= 0; i--)
     {
      const ulong ticket = PositionGetTicket(i);
      if(ticket == 0)
         continue;

      if(PositionGetInteger(POSITION_MAGIC) != MagicNumber)
         continue;

      const bool is_buy = (PositionGetInteger(POSITION_TYPE) == POSITION_TYPE_BUY);
      const int  digits = (int)SymbolInfoInteger(PositionGetString(POSITION_SYMBOL), SYMBOL_DIGITS);

      if(items != "")
         items += ",";

      items += StringFormat(
         "{\"ticket\":%s,\"symbol\":\"%s\",\"direction\":\"%s\",\"volume\":%s,"
         "\"entry_price\":%s,\"sl\":%s,\"tp\":%s,\"profit\":%s,\"opened_at\":%s}",
         IntegerToString((long)ticket),
         FXSJsonEscape(PositionGetString(POSITION_SYMBOL)),
         (is_buy ? "buy" : "sell"),
         DoubleToString(PositionGetDouble(POSITION_VOLUME), 2),
         DoubleToString(PositionGetDouble(POSITION_PRICE_OPEN), digits),
         DoubleToString(PositionGetDouble(POSITION_SL), digits),
         DoubleToString(PositionGetDouble(POSITION_TP), digits),
         DoubleToString(PositionGetDouble(POSITION_PROFIT), 2),
         IntegerToString(FXSServerToUtc((datetime)PositionGetInteger(POSITION_TIME))));
     }

   const string body = StringFormat("{\"magic\":%s,\"positions\":[%s]}",
                                    IntegerToString(MagicNumber), items);

   string response;
   FXSHttp("POST", "/api/v1/bot/positions", body, "application/json", response);
  }

//+------------------------------------------------------------------+
//| Re-report closing deals from the recent past.                     |
//|                                                                   |
//| A stop or target that fired while the terminal was shut raised no |
//| OnTradeTransaction anybody saw, so the dashboard still shows the  |
//| position open with no P&L. Replaying history on attach fills that |
//| in through the ordinary /fills path, which keys on the deal ticket|
//| and therefore ignores anything it already has.                    |
//|                                                                   |
//| Only closing deals are replayed. An opening deal would create a   |
//| trade row with no strategy and no levels, which is what the       |
//| position snapshot does properly.                                  |
//+------------------------------------------------------------------+
void FXSReplayClosedDeals(const int days)
  {
   if(days <= 0)
      return;

   const datetime to   = TimeCurrent();
   const datetime from = to - (days * 86400);

   if(!HistorySelect(from, to))
     {
      Print("[FXS] History could not be selected; skipping the replay of past closes.");
      return;
     }

   const int total = HistoryDealsTotal();
   int replayed = 0;

   for(int i = 0; i < total; i++)
     {
      const ulong deal = HistoryDealGetTicket(i);
      if(deal == 0)
         continue;

      if(HistoryDealGetInteger(deal, DEAL_MAGIC) != MagicNumber)
         continue;

      const ENUM_DEAL_ENTRY entry = (ENUM_DEAL_ENTRY)HistoryDealGetInteger(deal, DEAL_ENTRY);
      if(entry != DEAL_ENTRY_OUT && entry != DEAL_ENTRY_OUT_BY)
         continue;

      const long   position_id = HistoryDealGetInteger(deal, DEAL_POSITION_ID);
      const double price       = HistoryDealGetDouble(deal, DEAL_PRICE);
      const double volume      = HistoryDealGetDouble(deal, DEAL_VOLUME);
      const double profit      = HistoryDealGetDouble(deal, DEAL_PROFIT);
      const double commission  = HistoryDealGetDouble(deal, DEAL_COMMISSION);
      const double swap        = HistoryDealGetDouble(deal, DEAL_SWAP);

      const ENUM_DEAL_REASON why = (ENUM_DEAL_REASON)HistoryDealGetInteger(deal, DEAL_REASON);

      //--- The deal names its own instrument, which is the only reliable way to format
      //--- it once this terminal carries more than one.
      const int di = FXSIndexFor(HistoryDealGetString(deal, DEAL_SYMBOL));

      string reason_enum = "manual";
      if(why == DEAL_REASON_SL || why == DEAL_REASON_SO) reason_enum = "sl";
      else if(why == DEAL_REASON_TP)                     reason_enum = "tp3";

      //--- Pips need the entry price, and the entry deal is in this same selection.
      //--- Left at zero when it cannot be found: a wrong pip figure is worse than an
      //--- absent one, and the dashboard treats this as a historical correction.
      double pips = 0.0;

      FXSQueueReport(StringFormat(
         "{\"event\":\"closed\",\"ticket\":%s,\"deal_ticket\":%s,\"volume\":%s,"
         "\"price\":%s,\"pips_profit\":%s,\"profit\":%s,\"commission\":%s,\"swap\":%s,"
         "\"reason\":\"%s\",\"closure_note\":\"replayed from history on attach\"}",
         IntegerToString(position_id), IntegerToString((long)deal),
         DoubleToString(volume, 2), DoubleToString(price, di >= 0 ? g_exec[di].Digits() : 6),
         DoubleToString(pips, 2), DoubleToString(profit, 2),
         DoubleToString(commission, 2), DoubleToString(swap, 2),
         reason_enum));

      replayed++;
     }

   if(replayed > 0)
      FXSLog("info", StringFormat("Replaying %d closing deal(s) from the last %d day(s); "
                                 "any the dashboard already has are ignored.", replayed, days));
  }

//+------------------------------------------------------------------+
//| Heartbeat: report liveness, read back the kill switch.            |
//+------------------------------------------------------------------+
void FXSHeartbeat(void)
  {
   //--- Both flags matter and they are different things: the toolbar button is
   //--- terminal-wide, the MQL flag is this EA's own "Allow Algo Trading" checkbox.
   const bool algo = (TerminalInfoInteger(TERMINAL_TRADE_ALLOWED) != 0)
                  && (MQLInfoInteger(MQL_TRADE_ALLOWED) != 0);
   const bool connected = (TerminalInfoInteger(TERMINAL_CONNECTED) != 0);

   //--- Symbol truth. The dashboard needs pip size to turn the strategy's pip-based
   //--- targets into price levels, and pip value to size a position at all. Both are
   //--- sent as null rather than zero when unknown: the dashboard then records the
   //--- signal unexecuted instead of trading a size derived from a guess.
   //--- The heartbeat has room for exactly one symbol's figures and predates this EA
   //--- carrying several, so index 0 keeps that field meaning what it always meant: the
   //--- primary instrument. Every other symbol's specification travels with its own
   //--- candles instead, which is why a second instrument needs no wire change here.
   const double pip_value = FXSPipValuePerLot(0);

   const string pip_size_json  = (g_exec[0].PipSize() > 0.0)
                                 ? DoubleToString(g_exec[0].PipSize(), 5) : "null";
   const string pip_value_json = (pip_value > 0.0)
                                 ? DoubleToString(pip_value, 5) : "null";

   //--- Additive: an older dashboard ignores it, a newer one learns what this terminal
   //--- can actually trade without being told separately.
   string symbols_json = "";

   for(int i = 0; i < g_symbols; i++)
     {
      if(symbols_json != "")
         symbols_json += ",";

      symbols_json += StringFormat("{\"base\":\"%s\",\"resolved\":\"%s\"}",
                                   FXSJsonEscape(g_base[i]), FXSJsonEscape(g_exec[i].Symbol()));
     }

   const string body = StringFormat(
      "{\"source\":\"mql5_ea\",\"version\":\"%s\",\"terminal_build\":%d,"
      "\"algo_trading_enabled\":%s,\"broker_connected\":%s,\"resolved_symbol\":\"%s\","
      "\"pip_size\":%s,\"digits\":%d,\"pip_value_per_lot\":%s,"
      "\"volume_min\":%s,\"volume_step\":%s,"
      "\"balance\":%s,\"equity\":%s,\"margin_free\":%s,\"open_positions\":%d,"
      "\"symbols\":[%s]}",
      FXS_EA_VERSION, (int)TerminalInfoInteger(TERMINAL_BUILD),
      (algo ? "true" : "false"), (connected ? "true" : "false"),
      FXSJsonEscape(g_exec[0].Symbol()),
      pip_size_json, g_exec[0].Digits(), pip_value_json,
      DoubleToString(g_exec[0].VolumeMin(), 4), DoubleToString(g_exec[0].VolumeStep(), 4),
      DoubleToString(AccountInfoDouble(ACCOUNT_BALANCE), 2),
      DoubleToString(AccountInfoDouble(ACCOUNT_EQUITY), 2),
      DoubleToString(AccountInfoDouble(ACCOUNT_MARGIN_FREE), 2),
      //--- Magic-scoped, so this already counts every instrument.
      g_exec[0].CountOwnedPositions(),
      symbols_json);

   string response;
   if(FXSHttp("POST", "/api/v1/bot/heartbeat", body, "application/json", response) != 200)
      return;

   //--- Laravel emits compact JSON, so a substring test is enough here and saves
   //--- carrying a JSON parser into the EA for one boolean.
   g_trading_enabled = (StringFind(response, "\"trading_enabled\":true") >= 0);
  }

//+------------------------------------------------------------------+
//| Execute one command line from the wire protocol.                  |
//+------------------------------------------------------------------+
void FXSHandleCommand(const string &f[])
  {
   const long   id      = StringToInteger(f[0]);
   const string type    = f[1];
   const string symbol  = f[2];
   const string dir     = f[3];
   const double volume  = StringToDouble(f[4]);
   const double sl_pips = StringToDouble(f[5]);
   const double tp_pips = StringToDouble(f[6]);
   const double sl_prc  = StringToDouble(f[7]);
   const double tp_prc  = StringToDouble(f[8]);
   const ulong  ticket  = (ulong)StringToInteger(f[9]);
   const string comment = f[10];
   //--- Which rung of the ladder this close is. Column 11 has been on the wire since
   //--- the protocol was written and was never read; trade management is what fills it.
   const string reason  = f[11];
   //--- Column 13, appended for resting orders. Empty on every command type that
   //--- existed before it did, which is every market order.
   const double entry_prc = StringToDouble(f[12]);

   if(DryRun)
     {
      FXSReportResult(id, false, 0, 0, 0.0, 0.0,
                     StringFormat("DryRun is enabled - command '%s' was not executed", type));
      return;
     }

   //--- start / stop only move the local flag; the authoritative value comes back
   //--- on every heartbeat, so a missed command self-corrects within one poll.
   if(type == "start")
     {
      g_trading_enabled = true;
      FXSReportResult(id, true, 0, 0, 0.0, 0.0, "");
      FXSLog("info", "Trading enabled by dashboard command");
      return;
     }

   if(type == "stop")
     {
      g_trading_enabled = false;
      FXSReportResult(id, true, 0, 0, 0.0, 0.0, "");
      FXSLog("info", "Trading disabled by dashboard command - open positions left alone");
      return;
     }

   if(type == "close_all")
     {
      uint retcode = 0;
      //--- Magic-scoped rather than symbol-scoped, so this one call flattens every
      //--- instrument this EA owns. Looping the executors would re-walk the same
      //--- position list once per symbol and report the count several times over.
      const int closed = g_exec[0].CloseAllOwned(retcode);
      FXSReportResult(id, retcode == 0, retcode, 0, 0.0, (double)closed,
                     retcode == 0 ? "" : g_exec[0].LastError());
      FXSLog(retcode == 0 ? "info" : "error",
            StringFormat("Close All: %d position(s) closed", closed));
      return;
     }

   if(type == "close")
     {
      uint retcode = 0;

      //--- Recorded before the call, because the resulting deal can reach
      //--- OnTradeTransaction as soon as this handler returns.
      FXSRememberCloseReason(ticket, reason);

      //--- Routed by the position rather than the command: a close carries a ticket,
      //--- and the executor doing the arithmetic must be the one that knows that
      //--- instrument's digits.
      const int ci = FXSIndexForTicket(ticket);

      if(ci < 0)
        {
         FXSTakeCloseReason(ticket);
         FXSReportResult(id, false, 0, ticket, 0.0, 0.0,
                        "Position is on an instrument this terminal was not told to carry");
         return;
        }

      if(g_exec[ci].ClosePosition(ticket, volume, retcode))
        {
         FXSReportResult(id, true, retcode, ticket, 0.0, volume, "");
        }
      else
        {
         //--- Nothing closed, so nothing will arrive to consume the reason. Left in
         //--- place it would be attached to whatever closed this position next.
         FXSTakeCloseReason(ticket);
         FXSReportResult(id, false, retcode, ticket, 0.0, 0.0, g_exec[ci].LastError());
        }
      return;
     }

   //--- Moving the stop, typically to break-even once the first rung has filled.
   //--- A zero level means "leave that one alone"; see CFXSExecutor::ModifyPosition.
   if(type == "modify")
     {
      uint retcode = 0;

      const int mi = FXSIndexForTicket(ticket);

      if(mi < 0)
        {
         FXSReportResult(id, false, 0, ticket, 0.0, 0.0,
                        "Position is on an instrument this terminal was not told to carry");
         return;
        }

      if(g_exec[mi].ModifyPosition(ticket, sl_prc, tp_prc, retcode))
        {
         FXSReportResult(id, true, retcode, ticket, 0.0, 0.0, "");
         FXSLog("info", StringFormat("Position #%s stop/target moved (%s)",
                                    IntegerToString((long)ticket),
                                    (reason == "" ? "no reason given" : reason)));
        }
      else
        {
         FXSReportResult(id, false, retcode, ticket, 0.0, 0.0, g_exec[mi].LastError());
         FXSLog("error", StringFormat("Modify rejected on #%s: %s",
                                     IntegerToString((long)ticket), g_exec[mi].LastError()));
        }
      return;
     }

   //--- A resting order at a level the market has not reached. The copier sends this
   //--- when a signal names an entry away from the current price: filling at market
   //--- instead would be a different trade to the one that was reviewed.
   if(type == "open_pending")
     {
      if(!g_trading_enabled)
        {
         FXSReportResult(id, false, 0, 0, 0.0, 0.0, "Trading is disabled; pending entry skipped");
         return;
        }

      //--- Refused rather than defaulted. Falling back to the first symbol would fill
      //--- a gold order on whatever this terminal happened to load first, which is the
      //--- worst outcome available here.
      const int si = FXSIndexFor(symbol);

      if(si < 0)
        {
         FXSReportResult(id, false, 0, 0, 0.0, 0.0,
                        StringFormat("Command names symbol '%s', which this terminal was not told to carry",
                                     symbol));
         return;
        }

      ulong order_ticket = 0;
      uint  retcode      = 0;

      if(g_exec[si].OpenPending(dir == "buy", volume, entry_prc, sl_prc, tp_prc,
                                PendingExpiryMinutes, comment, order_ticket, retcode))
        {
         FXSReportResult(id, true, retcode, order_ticket, entry_prc, volume, "");
         FXSLog("info", StringFormat("Resting %s order placed at %s, expires in %d minutes",
                                    dir, DoubleToString(entry_prc, g_exec[si].Digits()),
                                    PendingExpiryMinutes));
        }
      else
        {
         FXSReportResult(id, false, retcode, 0, 0.0, 0.0, g_exec[si].LastError());
         FXSLog("error", StringFormat("Resting order rejected: %s", g_exec[si].LastError()));
        }

      return;
     }

   if(type == "open")
     {
      //--- The kill switch is checked here, not only at the dashboard. A queued
      //--- entry that was correct when it was written must not execute after
      //--- trading has been turned off.
      if(!g_trading_enabled)
        {
         FXSReportResult(id, false, 0, 0, 0.0, 0.0, "Trading is disabled; entry skipped");
         return;
        }

      //--- Symbols other than the configured one are refused rather than guessed at:
      //--- position sizing and pip conversion are calibrated for this instrument.
      //--- Refused rather than defaulted. Falling back to the first symbol would fill
      //--- a gold order on whatever this terminal happened to load first, which is the
      //--- worst outcome available here.
      const int si = FXSIndexFor(symbol);

      if(si < 0)
        {
         FXSReportResult(id, false, 0, 0, 0.0, 0.0,
                        StringFormat("Command names symbol '%s', which this terminal was not told to carry",
                                     symbol));
         return;
        }

      const bool is_buy = (dir == "buy");
      ulong  out_ticket = 0;
      double out_price  = 0.0;
      double out_volume = 0.0;
      uint   retcode    = 0;

      if(g_exec[si].Open(is_buy, volume, sl_pips, tp_pips, sl_prc, tp_prc, comment,
                     out_ticket, out_price, out_volume, retcode))
        {
         FXSReportResult(id, true, retcode, out_ticket, out_price, out_volume, "");

         //--- Reported here rather than from OnTradeTransaction so the trade can be
         //--- linked back to the command that asked for it.
         FXSQueueReport(StringFormat(
            "{\"event\":\"opened\",\"command_id\":%s,\"ticket\":%s,\"symbol\":\"%s\","
            "\"direction\":\"%s\",\"volume\":%s,\"price\":%s,\"magic\":%s,\"spread_pips\":%s}",
            IntegerToString(id), IntegerToString((long)out_ticket),
            FXSJsonEscape(g_exec[si].Symbol()), (is_buy ? "buy" : "sell"),
            DoubleToString(out_volume, 2), DoubleToString(out_price, g_exec[si].Digits()),
            IntegerToString(MagicNumber),
            DoubleToString(SymbolInfoInteger(g_exec[si].Symbol(), SYMBOL_SPREAD) * g_exec[si].Point()
                           / g_exec[si].PipSize(), 2)));
        }
      else
        {
         FXSReportResult(id, false, retcode, 0, 0.0, 0.0, g_exec[si].LastError());
         FXSLog("error", StringFormat("Open rejected: %s", g_exec[si].LastError()));
        }

      return;
     }

   FXSReportResult(id, false, 0, 0, 0.0, 0.0, StringFormat("Unknown command type '%s'", type));
  }

//+------------------------------------------------------------------+
//| Poll for commands and execute them.                               |
//+------------------------------------------------------------------+
void FXSPollCommands(void)
  {
   string body;
   //--- text/plain selects the tab-separated wire format; MQL5 has no JSON parser
   //--- and an EA is a poor place to debug a hand-rolled one.
   if(FXSHttp("GET", "/api/v1/bot/commands", "", "text/plain", body) != 200)
      return;

   string lines[];
   const int line_count = StringSplit(body, StringGetCharacter("\n", 0), lines);
   if(line_count <= 0)
      return;

   string header = lines[0];
   StringTrimRight(header);
   StringTrimLeft(header);

   if(header != FXS_WIRE_VERSION)
     {
      FXSLog("critical", StringFormat(
            "Wire protocol mismatch: dashboard sent '%s', this EA understands '%s'. "
            "Recompile the EA from the matching commit.", header, FXS_WIRE_VERSION));
      return;
     }

   for(int i = 1; i < line_count; i++)
     {
      string line = lines[i];
      //--- Strip the line terminator and nothing else. StringTrimRight() also eats
      //--- trailing TABs, and here a TAB delimits a column rather than padding one:
      //--- a command with an empty payload (close_all, stop, start) serialises as its
      //--- id and type followed by ten empty columns, which is ten trailing TABs. Trim
      //--- those and a valid 12-column line arrives as 2, so every such command is
      //--- refused as malformed - the kill switch and Close All among them.
      StringReplace(line, "\r", "");
      if(line == "")
         continue;

      string fields[];
      const int n = StringSplit(line, StringGetCharacter("\t", 0), fields);

      //--- More columns than agreed is real drift: the dashboard is sending a format
      //--- this EA does not understand, and guessing which column became which would
      //--- be worse than refusing. The line itself is logged because a column count on
      //--- its own never says what actually arrived - that cost an evening once.
      if(n < 1 || n > FXS_WIRE_COLUMNS)
        {
         FXSLog("error", StringFormat("Malformed command line: expected %d columns, got %d: '%s'",
                                     FXS_WIRE_COLUMNS, n, line));
         continue;
        }

      //--- Fewer columns means the trailing ones were empty, which is the ordinary
      //--- shape of a payloadless command: close_all and stop are an id, a type, and
      //--- ten empty columns. WIRE_COLUMNS is append-only, so a missing trailing column
      //--- can only mean empty. Pad rather than refuse, and let each command type
      //--- reject the fields it actually needs - Open() already refuses a zero volume.
      if(n < FXS_WIRE_COLUMNS)
        {
         ArrayResize(fields, FXS_WIRE_COLUMNS);
         for(int f = n; f < FXS_WIRE_COLUMNS; f++)
            fields[f] = "";
        }

      FXSHandleCommand(fields);
     }
  }

//+------------------------------------------------------------------+
//| OnInit                                                            |
//+------------------------------------------------------------------+
int OnInit(void)
  {
   g_ready = false;

   if(ApiToken == "")
     {
      Print("[FXS] ApiToken is empty. Issue one with: php artisan bot:token you@example.com");
      return INIT_PARAMETERS_INCORRECT;
     }

   if(StringFind(ApiBaseUrl, "https://") != 0)
      Print("[FXS] WARNING: ApiBaseUrl is not HTTPS. The token is sent on every request.");

   //--- Refuse a live account unless the operator has explicitly said otherwise.
   //--- The default is the safe direction; discovering this the other way round is
   //--- expensive.
   if(DemoOnly && AccountInfoInteger(ACCOUNT_TRADE_MODE) == ACCOUNT_TRADE_MODE_REAL)
     {
      Print("[FXS] This is a LIVE account and DemoOnly is enabled. Refusing to start.");
      return INIT_FAILED;
     }

   //--- One executor per instrument, each resolving its own broker suffix.
   //---
   //--- A symbol that will not resolve fails the whole init rather than being skipped.
   //--- Starting with three of four instruments looks identical to starting with all
   //--- four until an order for the missing one is refused hours later, and the
   //--- dashboard would meanwhile be generating signals for a feed nothing is pushing.
   string requested[];
   const int wanted = StringSplit(BaseSymbols, ',', requested);

   if(wanted < 1)
     {
      Print("[FXS] BaseSymbols is empty. Name at least one instrument.");
      return INIT_PARAMETERS_INCORRECT;
     }

   if(wanted > FXS_MAX_SYMBOLS)
     {
      PrintFormat("[FXS] %d symbols requested but this build carries at most %d. "
                  "Every poll walks the whole list, so a longer one spends the timer on HTTP.",
                  wanted, FXS_MAX_SYMBOLS);
      return INIT_PARAMETERS_INCORRECT;
     }

   g_symbols = 0;

   for(int i = 0; i < wanted; i++)
     {
      string base = requested[i];
      StringTrimLeft(base);
      StringTrimRight(base);

      if(base == "")
         continue;

      //--- PipSize is the input's value for the first symbol only. A single figure
      //--- cannot be right for gold and EURUSD at once, so everything after the first
      //--- infers its own - and says so, because an inferred pip that is wrong by 10x
      //--- makes every order on that instrument return 10016.
      const double pip_for = (g_symbols == 0) ? PipSize : 0.0;

      if(!g_exec[g_symbols].Init(base, MagicNumber, Deviation, pip_for, MaxRetries))
        {
         PrintFormat("[FXS] %s (%s)", g_exec[g_symbols].LastError(), base);
         return INIT_FAILED;
        }

      g_base[g_symbols] = base;

      //--- Cursors start clean rather than inheriting whatever the array held.
      g_last_entry_bar[g_symbols] = 0;
      g_last_trend_bar[g_symbols] = 0;
      g_entry_seeded[g_symbols]   = false;
      g_trend_seeded[g_symbols]   = false;

      PrintFormat("[FXS] Symbol %s -> %s (digits=%d point=%s pip=%s stops_level=%d min_lot=%s)",
                  base, g_exec[g_symbols].Symbol(), g_exec[g_symbols].Digits(),
                  DoubleToString(g_exec[g_symbols].Point(), 5),
                  DoubleToString(g_exec[g_symbols].PipSize(), 5),
                  g_exec[g_symbols].StopsLevel(),
                  DoubleToString(g_exec[g_symbols].VolumeMin(), 2));

      if(g_symbols > 0 && g_exec[g_symbols].PipSize() <= 0.0)
         PrintFormat("[FXS] WARNING: pip size for %s could not be inferred.", base);

      g_symbols++;
     }

   if(g_symbols < 1)
     {
      Print("[FXS] BaseSymbols named nothing usable.");
      return INIT_PARAMETERS_INCORRECT;
     }

   if(PipSize <= 0.0)
      Print("[FXS] WARNING: PipSize was inferred. On gold the broker's point is 0.01 but most "
            "strategies mean 0.10 by 'a pip'. Being wrong by 10x makes every order return 10016.");

   if(TerminalInfoInteger(TERMINAL_TRADE_ALLOWED) == 0)
      Print("[FXS] WARNING: Algo Trading is OFF in the terminal. Orders will return 10027 "
            "until you click the Algo Trading button.");

   //--- The dashboard rejects a push of more than 1000 bars outright, and a first push
   //--- shorter than the indicator warm-up produces no signals at all while looking
   //--- like it is working. Both are cheaper to catch here than to diagnose later.
   if(PushCandles)
     {
      if(HistoryBars < 100 || HistoryBars > 1000)
        {
         Print("[FXS] HistoryBars must be between 100 and 1000. "
               "Below 100 the dashboard's ADX and EMA never warm up; above 1000 the push is refused.");
         return INIT_PARAMETERS_INCORRECT;
        }

      if(WindowBars < 2 || WindowBars > HistoryBars)
        {
         Print("[FXS] WindowBars must be at least 2 and no larger than HistoryBars.");
         return INIT_PARAMETERS_INCORRECT;
        }

      PrintFormat("[FXS] Candle push on: %s entry, %s trend (%d bars first, %d per bar after). "
                  "These must match the strategy's timeframes on the dashboard.",
                  FXSTimeframeName(EntryTimeframe), FXSTimeframeName(TrendTimeframe),
                  HistoryBars, WindowBars);

      const double pip_value = FXSPipValuePerLot(0);

      //--- Without this the dashboard cannot size a position and will record every
      //--- signal as lot_size_unavailable, which looks like the strategy never firing.
      if(pip_value <= 0.0)
         Print("[FXS] WARNING: this symbol reports no tick value, so pip value per lot is unknown. "
               "The dashboard will refuse to size positions rather than guess.");
      else
         PrintFormat("[FXS] Pip value per lot: %s (pip size %s)",
                     DoubleToString(pip_value, 5), DoubleToString(g_exec[0].PipSize(), 5));
     }

   if(Reconcile && ReconcileMinutes < 1)
     {
      Print("[FXS] ReconcileMinutes must be at least 1.");
      return INIT_PARAMETERS_INCORRECT;
     }

   EventSetTimer(PollSeconds > 0 ? PollSeconds : 5);
   g_ready = true;

   if(Reconcile)
     {
      //--- Attach is the moment the dashboard is most likely to be wrong: whatever
      //--- happened while this was detached happened unobserved. Replay the closes
      //--- first, so a position that has already gone is settled before the snapshot
      //--- reports it missing and the dashboard has to close it without figures.
      FXSReplayClosedDeals(ReplayHistoryDays);
      FXSFlushReports();

      g_last_reconcile = TimeCurrent();
      FXSReportPositions();
     }

   string carried = "";

   for(int i = 0; i < g_symbols; i++)
      carried += (i == 0 ? "" : ", ") + g_exec[i].Symbol();

   FXSLog("info", StringFormat("EA %s attached carrying %s (terminal build %d)",
                              FXS_EA_VERSION, carried,
                              (int)TerminalInfoInteger(TERMINAL_BUILD)));

   return INIT_SUCCEEDED;
  }

//+------------------------------------------------------------------+
//| OnDeinit                                                          |
//+------------------------------------------------------------------+
void OnDeinit(const int reason)
  {
   EventKillTimer();

   if(g_ready)
     {
      //--- One last flush: fills recorded but never reported would leave the
      //--- dashboard permanently out of step with the account.
      FXSFlushReports();
      FXSLog("info", StringFormat("EA detached (reason %d)", reason));
     }
  }

//+------------------------------------------------------------------+
//| OnTimer - the whole work loop                                     |
//+------------------------------------------------------------------+
void OnTimer(void)
  {
   if(!g_ready)
      return;

   //--- Heartbeat first: it carries the kill-switch state that the commands
   //--- claimed in this same tick are then evaluated against.
   FXSHeartbeat();

   //--- Report before claiming: never take on new work while the record of
   //--- already-executed work is still sitting in the buffer.
   FXSFlushReports();

   //--- Push bars before polling. A newly closed bar makes the dashboard evaluate the
   //--- strategy and, if it enters, queue the command inside that same request - so
   //--- the poll below claims it on this tick instead of waiting for the next one.
   //---
   //--- Deliberately above the Algo Trading check: bars are data, not trading. A
   //--- terminal with the button off should still keep the series current and still
   //--- record what the strategy would have done. The dashboard sees the same flag on
   //--- the heartbeat and skips those signals with "algo_trading_disabled" rather than
   //--- queueing entries that could only be refused.
   FXSMaintainCandles();

   //--- A correction, not a feed: /fills still reports every event as it happens, and
   //--- this only catches what no event covered.
   if(Reconcile && (TimeCurrent() - g_last_reconcile) >= (ReconcileMinutes * 60))
     {
      g_last_reconcile = TimeCurrent();
      FXSReportPositions();
     }

   if(TerminalInfoInteger(TERMINAL_TRADE_ALLOWED) == 0 || MQLInfoInteger(MQL_TRADE_ALLOWED) == 0)
     {
      //--- The heartbeat already told the dashboard, which shows BLOCKED. Warn in
      //--- the log at most once a minute rather than on every poll.
      if(TimeCurrent() - g_last_warned > 60)
        {
         g_last_warned = TimeCurrent();
         Print("[FXS] Algo Trading is disabled - not claiming commands.");
        }
      return;
     }

   FXSPollCommands();
  }

//+------------------------------------------------------------------+
//| OnTradeTransaction                                                |
//|                                                                   |
//| The only way to learn about closes the EA did not ask for: a stop |
//| loss or take profit hit at the broker while nothing was polling.  |
//| Without this the dashboard would show positions that closed hours |
//| ago as still open.                                                |
//+------------------------------------------------------------------+
void OnTradeTransaction(const MqlTradeTransaction &trans,
                        const MqlTradeRequest &request,
                        const MqlTradeResult &result)
  {
   if(!g_ready || trans.type != TRADE_TRANSACTION_DEAL_ADD)
      return;

   if(!HistoryDealSelect(trans.deal))
      return;

   if(HistoryDealGetInteger(trans.deal, DEAL_MAGIC) != MagicNumber)
      return;

   const ENUM_DEAL_ENTRY entry = (ENUM_DEAL_ENTRY)HistoryDealGetInteger(trans.deal, DEAL_ENTRY);

   //--- Opens are reported from the command path, where the command id is known.
   if(entry != DEAL_ENTRY_OUT && entry != DEAL_ENTRY_OUT_BY)
      return;

   const long   position_id = HistoryDealGetInteger(trans.deal, DEAL_POSITION_ID);
   const double close_price = HistoryDealGetDouble(trans.deal, DEAL_PRICE);
   const double volume      = HistoryDealGetDouble(trans.deal, DEAL_VOLUME);
   const double profit      = HistoryDealGetDouble(trans.deal, DEAL_PROFIT);
   const double commission  = HistoryDealGetDouble(trans.deal, DEAL_COMMISSION);
   const double swap        = HistoryDealGetDouble(trans.deal, DEAL_SWAP);
   const ENUM_DEAL_TYPE dt  = (ENUM_DEAL_TYPE)HistoryDealGetInteger(trans.deal, DEAL_TYPE);

   //--- Read this now, before HistorySelectByPosition() below replaces the history
   //--- cache. Re-reading it afterwards depends on the new selection still covering
   //--- this deal, which is true today and is not worth relying on.
   const ENUM_DEAL_REASON reason = (ENUM_DEAL_REASON)HistoryDealGetInteger(trans.deal, DEAL_REASON);

   //--- A closing SELL deal closes a BUY position, and vice versa.
   const bool was_buy = (dt == DEAL_TYPE_SELL);

   //--- Pips are computed here, in the only place that knows the symbol's point
   //--- size. Deriving them dashboard-side would mean guessing the multiplier that
   //--- causes the entire 10016 class of bugs.
   double entry_price = 0.0;
   if(HistorySelectByPosition(position_id))
     {
      const int deals = HistoryDealsTotal();
      for(int i = 0; i < deals; i++)
        {
         const ulong d = HistoryDealGetTicket(i);
         if(d != 0 && HistoryDealGetInteger(d, DEAL_ENTRY) == DEAL_ENTRY_IN)
           {
            entry_price = HistoryDealGetDouble(d, DEAL_PRICE);
            break;
           }
        }
     }

   //--- Pips are a per-instrument unit: a gold pip is 0.10 and a EURUSD pip is 0.0001,
   //--- so taking the figure from whichever executor loaded first would have been wrong
   //--- by orders of magnitude on every symbol but one.
   const int ti = FXSIndexFor(HistoryDealGetString(trans.deal, DEAL_SYMBOL));

   double pips = 0.0;
   if(entry_price > 0.0 && ti >= 0 && g_exec[ti].PipSize() > 0.0)
      pips = ((close_price - entry_price) / g_exec[ti].PipSize()) * (was_buy ? 1.0 : -1.0);

   //--- Still open means this was one step of the TP ladder, not the exit.
   const bool still_open = PositionSelectByTicket((ulong)position_id);

   //--- trade_partials.close_reason is a fixed enum that cannot express which
   //--- ladder step a broker-side TP fill was, so the precise reason travels
   //--- alongside it as closure_note.
   string reason_enum = "manual";
   string reason_note = "manual close";

   if(reason == DEAL_REASON_SL)      { reason_enum = "sl";  reason_note = "stop loss hit"; }
   else if(reason == DEAL_REASON_TP) { reason_enum = "tp3"; reason_note = "take profit hit at broker"; }
   else if(reason == DEAL_REASON_SO) { reason_enum = "sl";  reason_note = "stop out (margin call)"; }
   else if(reason == DEAL_REASON_EXPERT)
     {
      //--- Only an EXPERT close can have been commanded, so only this branch consults
      //--- the store. A stop or target that happened to fire on a position with a
      //--- stale entry must not inherit its rung.
      const string commanded = FXSTakeCloseReason((ulong)position_id);

      if(commanded != "")
        {
         reason_enum = commanded;
         reason_note = "closed by dashboard command (" + commanded + ")";
        }
      else
        {
         reason_enum = "manual";
         reason_note = "closed by dashboard command";
        }
     }

   FXSQueueReport(StringFormat(
      "{\"event\":\"%s\",\"ticket\":%s,\"deal_ticket\":%s,\"volume\":%s,\"price\":%s,"
      "\"pips_profit\":%s,\"profit\":%s,\"commission\":%s,\"swap\":%s,"
      "\"reason\":\"%s\",\"closure_note\":\"%s\"}",
      (still_open ? "partial" : "closed"),
      IntegerToString(position_id), IntegerToString((long)trans.deal),
      DoubleToString(volume, 2), DoubleToString(close_price, ti >= 0 ? g_exec[ti].Digits() : 6),
      DoubleToString(pips, 2), DoubleToString(profit, 2),
      DoubleToString(commission, 2), DoubleToString(swap, 2),
      reason_enum, FXSJsonEscape(reason_note)));
  }
//+------------------------------------------------------------------+
