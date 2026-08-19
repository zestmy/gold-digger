//+------------------------------------------------------------------+
//|                                          GoldDiggerBridge.mq5    |
//|              Gold Digger - Laravel <-> MetaTrader 5 execution EA  |
//+------------------------------------------------------------------+
//| Executes the trade_commands queue from the Gold Digger dashboard  |
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
//|  1. Copy mql5/Include/GoldDigger -> <terminal>/MQL5/Include/      |
//|     and mql5/Experts/GoldDigger  -> <terminal>/MQL5/Experts/      |
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
#property copyright "Gold Digger"
#property version   "1.00"
#property description "Executes Gold Digger dashboard commands and reports fills back."

#include <GoldDigger/GDExecutor.mqh>

//--- Must match TradeCommand::WIRE_VERSION on the Laravel side.
#define GD_WIRE_VERSION   "GDCMD1"
#define GD_WIRE_COLUMNS   12
#define GD_EA_VERSION     "1.0.0"
#define GD_MAX_PENDING    200

//+------------------------------------------------------------------+
//| Inputs                                                            |
//+------------------------------------------------------------------+
input group             "Connection"
input string   ApiBaseUrl    = "https://your-dashboard.example.com"; // Dashboard URL (must be whitelisted)
input string   ApiToken      = "";                                   // Token from: php artisan bot:token
input int      PollSeconds   = 5;                                    // Seconds between polls
input int      HttpTimeoutMs = 4000;                                 // Per-request timeout

input group             "Trading"
input string   BaseSymbol    = "XAUUSD";     // Base symbol; suffixes resolved automatically
input double   PipSize       = 0.10;         // Price move of one pip (0 = infer; gold is usually 0.10)
input long     MagicNumber   = 20240101;     // Identifies this EA's positions
input int      Deviation     = 20;           // Max slippage in points (gold needs 20-30)
input int      MaxRetries    = 3;            // Attempts on requote / price-changed

input group             "Safety"
input bool     DryRun        = false;        // Log commands without executing them
input bool     DemoOnly      = true;         // Refuse to run on a live account

//+------------------------------------------------------------------+
//| State                                                             |
//+------------------------------------------------------------------+
CGDExecutor  g_exec;
bool         g_ready            = false;   // OnInit completed successfully
bool         g_trading_enabled  = false;   // mirrors bot_settings.is_active
datetime     g_last_warned      = 0;       // rate-limits the repeated-warning spam

//--- Fill reports are queued here by OnTradeTransaction and flushed by OnTimer.
//--- WebRequest is synchronous: calling it inside a trade-transaction handler
//--- would stall the terminal's event thread on every fill.
string       g_pending[];

//+------------------------------------------------------------------+
//| Escape a string for embedding in JSON.                            |
//+------------------------------------------------------------------+
string GDJsonEscape(const string value)
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
int GDHttp(const string method, const string path, const string body,
           const string accept, string &out_body)
  {
   out_body = "";

   if(ApiToken == "")
     {
      Print("[GD] ApiToken is empty - set it in the EA inputs.");
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
         PrintFormat("[GD] WebRequest is not permitted for %s. Tools > Options > Expert Advisors > "
                     "'Allow WebRequest for listed URL' and add exactly this origin.", ApiBaseUrl);
      else
         PrintFormat("[GD] WebRequest to %s failed with error %d.", url, err);
      return -1;
     }

   if(ArraySize(result) > 0)
      out_body = CharArrayToString(result, 0, WHOLE_ARRAY, CP_UTF8);

   if(status >= 400)
      PrintFormat("[GD] %s %s -> HTTP %d: %s", method, path, status, out_body);

   return status;
  }

//+------------------------------------------------------------------+
//| Send a log line to the dashboard so it appears on /logs.          |
//| Failures here are swallowed: logging must never break trading.    |
//+------------------------------------------------------------------+
void GDLog(const string level, const string message)
  {
   PrintFormat("[GD][%s] %s", level, message);

   const string body = StringFormat("{\"level\":\"%s\",\"source\":\"mql5_ea\",\"message\":\"%s\"}",
                                    level, GDJsonEscape(message));
   string ignored;
   GDHttp("POST", "/api/v1/bot/logs", body, "application/json", ignored);
  }

//+------------------------------------------------------------------+
//| Queue a fill report for the next OnTimer flush.                   |
//+------------------------------------------------------------------+
void GDQueueReport(const string json)
  {
   const int n = ArraySize(g_pending);

   if(n >= GD_MAX_PENDING)
     {
      //--- Dropping the oldest is the least-bad option: a backlog this size means
      //--- the dashboard has been unreachable for a long time, and the newest fills
      //--- are the ones that still matter for reconciliation.
      Print("[GD] pending report buffer full; dropping the oldest entry");
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
void GDFlushReports(void)
  {
   const int n = ArraySize(g_pending);
   if(n == 0)
      return;

   string  remaining[];
   int     kept = 0;

   for(int i = 0; i < n; i++)
     {
      string response;
      const int status = GDHttp("POST", "/api/v1/bot/fills", g_pending[i], "application/json", response);

      //--- 2xx means recorded. A 4xx means the dashboard rejected it and will keep
      //--- rejecting it, so retrying forever would block every later report.
      if(status >= 200 && status < 300)
         continue;

      if(status >= 400 && status < 500)
        {
         PrintFormat("[GD] fill report rejected (HTTP %d), discarding: %s", status, response);
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
void GDReportResult(const long command_id, const bool ok, const uint retcode,
                    const ulong ticket, const double price, const double volume,
                    const string error)
  {
   const string body = StringFormat(
      "{\"ok\":%s,\"retcode\":%d,\"ticket\":%s,\"price\":%s,\"volume\":%s,\"error\":\"%s\"}",
      (ok ? "true" : "false"), retcode, IntegerToString((long)ticket),
      DoubleToString(price, g_exec.Digits()), DoubleToString(volume, 2),
      GDJsonEscape(error));

   string response;
   GDHttp("POST", "/api/v1/bot/commands/" + IntegerToString(command_id) + "/result",
          body, "application/json", response);
  }

//+------------------------------------------------------------------+
//| Heartbeat: report liveness, read back the kill switch.            |
//+------------------------------------------------------------------+
void GDHeartbeat(void)
  {
   //--- Both flags matter and they are different things: the toolbar button is
   //--- terminal-wide, the MQL flag is this EA's own "Allow Algo Trading" checkbox.
   const bool algo = (TerminalInfoInteger(TERMINAL_TRADE_ALLOWED) != 0)
                  && (MQLInfoInteger(MQL_TRADE_ALLOWED) != 0);
   const bool connected = (TerminalInfoInteger(TERMINAL_CONNECTED) != 0);

   const string body = StringFormat(
      "{\"source\":\"mql5_ea\",\"version\":\"%s\",\"terminal_build\":%d,"
      "\"algo_trading_enabled\":%s,\"broker_connected\":%s,\"resolved_symbol\":\"%s\","
      "\"balance\":%s,\"equity\":%s,\"margin_free\":%s,\"open_positions\":%d}",
      GD_EA_VERSION, (int)TerminalInfoInteger(TERMINAL_BUILD),
      (algo ? "true" : "false"), (connected ? "true" : "false"),
      GDJsonEscape(g_exec.Symbol()),
      DoubleToString(AccountInfoDouble(ACCOUNT_BALANCE), 2),
      DoubleToString(AccountInfoDouble(ACCOUNT_EQUITY), 2),
      DoubleToString(AccountInfoDouble(ACCOUNT_MARGIN_FREE), 2),
      g_exec.CountOwnedPositions());

   string response;
   if(GDHttp("POST", "/api/v1/bot/heartbeat", body, "application/json", response) != 200)
      return;

   //--- Laravel emits compact JSON, so a substring test is enough here and saves
   //--- carrying a JSON parser into the EA for one boolean.
   g_trading_enabled = (StringFind(response, "\"trading_enabled\":true") >= 0);
  }

//+------------------------------------------------------------------+
//| Execute one command line from the wire protocol.                  |
//+------------------------------------------------------------------+
void GDHandleCommand(const string &f[])
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

   if(DryRun)
     {
      GDReportResult(id, false, 0, 0, 0.0, 0.0,
                     StringFormat("DryRun is enabled - command '%s' was not executed", type));
      return;
     }

   //--- start / stop only move the local flag; the authoritative value comes back
   //--- on every heartbeat, so a missed command self-corrects within one poll.
   if(type == "start")
     {
      g_trading_enabled = true;
      GDReportResult(id, true, 0, 0, 0.0, 0.0, "");
      GDLog("info", "Trading enabled by dashboard command");
      return;
     }

   if(type == "stop")
     {
      g_trading_enabled = false;
      GDReportResult(id, true, 0, 0, 0.0, 0.0, "");
      GDLog("info", "Trading disabled by dashboard command - open positions left alone");
      return;
     }

   if(type == "close_all")
     {
      uint retcode = 0;
      const int closed = g_exec.CloseAllOwned(retcode);
      GDReportResult(id, retcode == 0, retcode, 0, 0.0, (double)closed,
                     retcode == 0 ? "" : g_exec.LastError());
      GDLog(retcode == 0 ? "info" : "error",
            StringFormat("Close All: %d position(s) closed", closed));
      return;
     }

   if(type == "close")
     {
      uint retcode = 0;
      if(g_exec.ClosePosition(ticket, volume, retcode))
         GDReportResult(id, true, retcode, ticket, 0.0, volume, "");
      else
         GDReportResult(id, false, retcode, ticket, 0.0, 0.0, g_exec.LastError());
      return;
     }

   if(type == "open")
     {
      //--- The kill switch is checked here, not only at the dashboard. A queued
      //--- entry that was correct when it was written must not execute after
      //--- trading has been turned off.
      if(!g_trading_enabled)
        {
         GDReportResult(id, false, 0, 0, 0.0, 0.0, "Trading is disabled; entry skipped");
         return;
        }

      //--- Symbols other than the configured one are refused rather than guessed at:
      //--- position sizing and pip conversion are calibrated for this instrument.
      if(symbol != "" && StringFind(g_exec.Symbol(), symbol) != 0)
        {
         GDReportResult(id, false, 0, 0, 0.0, 0.0,
                        StringFormat("Command names symbol '%s' but this EA is bound to '%s'",
                                     symbol, g_exec.Symbol()));
         return;
        }

      const bool is_buy = (dir == "buy");
      ulong  out_ticket = 0;
      double out_price  = 0.0;
      double out_volume = 0.0;
      uint   retcode    = 0;

      if(g_exec.Open(is_buy, volume, sl_pips, tp_pips, sl_prc, tp_prc, comment,
                     out_ticket, out_price, out_volume, retcode))
        {
         GDReportResult(id, true, retcode, out_ticket, out_price, out_volume, "");

         //--- Reported here rather than from OnTradeTransaction so the trade can be
         //--- linked back to the command that asked for it.
         GDQueueReport(StringFormat(
            "{\"event\":\"opened\",\"command_id\":%s,\"ticket\":%s,\"symbol\":\"%s\","
            "\"direction\":\"%s\",\"volume\":%s,\"price\":%s,\"magic\":%s,\"spread_pips\":%s}",
            IntegerToString(id), IntegerToString((long)out_ticket),
            GDJsonEscape(g_exec.Symbol()), (is_buy ? "buy" : "sell"),
            DoubleToString(out_volume, 2), DoubleToString(out_price, g_exec.Digits()),
            IntegerToString(MagicNumber),
            DoubleToString(SymbolInfoInteger(g_exec.Symbol(), SYMBOL_SPREAD) * g_exec.Point()
                           / g_exec.PipSize(), 2)));
        }
      else
        {
         GDReportResult(id, false, retcode, 0, 0.0, 0.0, g_exec.LastError());
         GDLog("error", StringFormat("Open rejected: %s", g_exec.LastError()));
        }

      return;
     }

   GDReportResult(id, false, 0, 0, 0.0, 0.0, StringFormat("Unknown command type '%s'", type));
  }

//+------------------------------------------------------------------+
//| Poll for commands and execute them.                               |
//+------------------------------------------------------------------+
void GDPollCommands(void)
  {
   string body;
   //--- text/plain selects the tab-separated wire format; MQL5 has no JSON parser
   //--- and an EA is a poor place to debug a hand-rolled one.
   if(GDHttp("GET", "/api/v1/bot/commands", "", "text/plain", body) != 200)
      return;

   string lines[];
   const int line_count = StringSplit(body, StringGetCharacter("\n", 0), lines);
   if(line_count <= 0)
      return;

   string header = lines[0];
   StringTrimRight(header);
   StringTrimLeft(header);

   if(header != GD_WIRE_VERSION)
     {
      GDLog("critical", StringFormat(
            "Wire protocol mismatch: dashboard sent '%s', this EA understands '%s'. "
            "Recompile the EA from the matching commit.", header, GD_WIRE_VERSION));
      return;
     }

   for(int i = 1; i < line_count; i++)
     {
      string line = lines[i];
      StringTrimRight(line);
      StringTrimLeft(line);
      if(line == "")
         continue;

      string fields[];
      const int n = StringSplit(line, StringGetCharacter("\t", 0), fields);

      if(n != GD_WIRE_COLUMNS)
        {
         GDLog("error", StringFormat("Malformed command line: expected %d columns, got %d",
                                     GD_WIRE_COLUMNS, n));
         continue;
        }

      GDHandleCommand(fields);
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
      Print("[GD] ApiToken is empty. Issue one with: php artisan bot:token you@example.com");
      return INIT_PARAMETERS_INCORRECT;
     }

   if(StringFind(ApiBaseUrl, "https://") != 0)
      Print("[GD] WARNING: ApiBaseUrl is not HTTPS. The token is sent on every request.");

   //--- Refuse a live account unless the operator has explicitly said otherwise.
   //--- The default is the safe direction; discovering this the other way round is
   //--- expensive.
   if(DemoOnly && AccountInfoInteger(ACCOUNT_TRADE_MODE) == ACCOUNT_TRADE_MODE_REAL)
     {
      Print("[GD] This is a LIVE account and DemoOnly is enabled. Refusing to start.");
      return INIT_FAILED;
     }

   if(!g_exec.Init(BaseSymbol, MagicNumber, Deviation, PipSize, MaxRetries))
     {
      PrintFormat("[GD] %s", g_exec.LastError());
      return INIT_FAILED;
     }

   PrintFormat("[GD] Symbol %s -> %s (digits=%d point=%s pip=%s stops_level=%d min_lot=%s)",
               BaseSymbol, g_exec.Symbol(), g_exec.Digits(),
               DoubleToString(g_exec.Point(), 5), DoubleToString(g_exec.PipSize(), 5),
               g_exec.StopsLevel(), DoubleToString(g_exec.VolumeMin(), 2));

   if(PipSize <= 0.0)
      Print("[GD] WARNING: PipSize was inferred. On gold the broker's point is 0.01 but most "
            "strategies mean 0.10 by 'a pip'. Being wrong by 10x makes every order return 10016.");

   if(TerminalInfoInteger(TERMINAL_TRADE_ALLOWED) == 0)
      Print("[GD] WARNING: Algo Trading is OFF in the terminal. Orders will return 10027 "
            "until you click the Algo Trading button.");

   EventSetTimer(PollSeconds > 0 ? PollSeconds : 5);
   g_ready = true;

   GDLog("info", StringFormat("EA %s attached on %s (terminal build %d)",
                              GD_EA_VERSION, g_exec.Symbol(),
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
      GDFlushReports();
      GDLog("info", StringFormat("EA detached (reason %d)", reason));
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
   GDHeartbeat();

   //--- Report before claiming: never take on new work while the record of
   //--- already-executed work is still sitting in the buffer.
   GDFlushReports();

   if(TerminalInfoInteger(TERMINAL_TRADE_ALLOWED) == 0 || MQLInfoInteger(MQL_TRADE_ALLOWED) == 0)
     {
      //--- The heartbeat already told the dashboard, which shows BLOCKED. Warn in
      //--- the log at most once a minute rather than on every poll.
      if(TimeCurrent() - g_last_warned > 60)
        {
         g_last_warned = TimeCurrent();
         Print("[GD] Algo Trading is disabled - not claiming commands.");
        }
      return;
     }

   GDPollCommands();
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

   double pips = 0.0;
   if(entry_price > 0.0 && g_exec.PipSize() > 0.0)
      pips = ((close_price - entry_price) / g_exec.PipSize()) * (was_buy ? 1.0 : -1.0);

   //--- Still open means this was one step of the TP ladder, not the exit.
   const bool still_open = PositionSelectByTicket((ulong)position_id);

   //--- trade_partials.close_reason is a fixed enum that cannot express which
   //--- ladder step a broker-side TP fill was, so the precise reason travels
   //--- alongside it as closure_note.
   string reason_enum = "manual";
   string reason_note = "manual close";

   if(reason == DEAL_REASON_SL)      { reason_enum = "sl";  reason_note = "stop loss hit"; }
   else if(reason == DEAL_REASON_TP) { reason_enum = "tp1"; reason_note = "take profit hit at broker"; }
   else if(reason == DEAL_REASON_SO) { reason_enum = "sl";  reason_note = "stop out (margin call)"; }
   else if(reason == DEAL_REASON_EXPERT) { reason_enum = "manual"; reason_note = "closed by dashboard command"; }

   GDQueueReport(StringFormat(
      "{\"event\":\"%s\",\"ticket\":%s,\"deal_ticket\":%s,\"volume\":%s,\"price\":%s,"
      "\"pips_profit\":%s,\"profit\":%s,\"commission\":%s,\"swap\":%s,"
      "\"reason\":\"%s\",\"closure_note\":\"%s\"}",
      (still_open ? "partial" : "closed"),
      IntegerToString(position_id), IntegerToString((long)trans.deal),
      DoubleToString(volume, 2), DoubleToString(close_price, g_exec.Digits()),
      DoubleToString(pips, 2), DoubleToString(profit, 2),
      DoubleToString(commission, 2), DoubleToString(swap, 2),
      reason_enum, GDJsonEscape(reason_note)));
  }
//+------------------------------------------------------------------+
