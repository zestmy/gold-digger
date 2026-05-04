# Gold Digger Architecture

## Project Goal

Gold Digger is a personal automated trading bot for XAUUSD (gold) scalping. The system follows a multi-timeframe trend-following strategy with partial profit-taking.

**Current Scope**: Personal use with single user, but the database schema is designed for future multi-tenant SaaS expansion.

## System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        User's Browser                            │
│                    (Gold Digger Dashboard)                       │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                     Laravel Dashboard                            │
│  - Livewire components for real-time UI                         │
│  - Filament admin panel for data management                     │
│  - REST API endpoints (Phase 6)                                 │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      MySQL Database                              │
│  - Shared between Laravel and Python                            │
│  - Trades, signals, logs, settings                              │
└─────────────────────────────────────────────────────────────────┘
                              ▲
                              │
┌─────────────────────────────────────────────────────────────────┐
│                     Python Trading Bot                           │
│  - MetaTrader5 library for MT5 connection                       │
│  - Strategy execution engine                                    │
│  - Signal generation and order management                       │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Octa MT5 Broker                             │
│  - Live/demo trading accounts                                   │
│  - Market data feed                                             │
│  - Order execution                                              │
└─────────────────────────────────────────────────────────────────┘
```

## Stack Rationale

### Why Laravel + MySQL + Python Split?

1. **Laravel for Dashboard**
   - Excellent ecosystem (Livewire, Filament) for rapid UI development
   - Robust authentication and session management
   - Easy to add REST API endpoints later

2. **MySQL for Data**
   - ACID compliance for financial data integrity
   - Both Laravel and Python have mature MySQL drivers
   - Easy to query and analyze trading data

3. **Python for Trading Engine**
   - MetaTrader5 library only works with Python
   - Rich ecosystem for financial calculations (pandas, numpy)
   - Can run 24/7 on Windows VPS where MT5 terminal runs

### Why This Separation?

- **Decoupling**: Dashboard can restart without affecting trading
- **Scalability**: Each component can be scaled independently
- **Reliability**: If dashboard crashes, bot keeps trading
- **Development**: Teams can work on components in parallel

## Database Design Rationale

### Why Separate `trade_partials` Table?

Our strategy uses progressive profit-taking:
- TP1: Close 50% of position
- TP2: Close 30% of remaining
- TP3: Close remaining 20%

Each partial close is a separate MT5 deal with its own:
- Execution price
- Timestamp
- P&L calculation

A separate table allows:
- Unknown number of partials (0 to many per trade)
- Individual P&L tracking per partial
- Analysis: "How often does TP2 hit after TP1?"

### Why Track Spread/Commission/Swap Separately?

Itemized cost tracking enables:
- **Transparency**: See exactly where costs come from
- **Analysis**: Which costs hurt most? (e.g., high spread during news)
- **Broker Comparison**: True cost comparison between brokers
- **Optimization**: Identify times with best/worst spreads

### Why Separate `trade_screenshots` Table?

- Multiple screenshots per trade (entry, each TP, exit)
- Large file paths don't clutter the trades table
- Easy to query "all screenshots for trade X"
- Future: AI analysis of chart patterns

### Why Pre-aggregate in `daily_summaries`?

Dashboard performance is critical for real-time monitoring:
- **Instant Loading**: No need to scan all trades for stats
- **Historical Accuracy**: Captures point-in-time balance snapshots
- **Complex Metrics**: Drawdown calculations are expensive live
- **Reduced DB Load**: One row per day vs joining thousands of trades

## Communication Architecture

### Phase 2: Direct MySQL Communication

```
Laravel Dashboard <──── MySQL ────> Python Bot
```

Simple approach for personal use:
- Python writes trades/signals directly to MySQL
- Laravel reads and displays data
- Settings table controls bot behavior

### Phase 6: REST API + WebSocket

```
Laravel ←── REST API ──→ Python
        ←── WebSocket ──→ (real-time updates)
```

For better separation:
- REST API for commands (start/stop, close position)
- WebSocket for real-time trade updates
- Laravel becomes the single source of truth

## Phase Roadmap

### Phase 1: Foundation (Current)

**1A - Project Setup** ✓
- Laravel project with Breeze + Livewire
- Database schema and migrations
- Filament admin panel
- Dashboard skeleton with placeholder components

**1B - Core UI**
- Trade history page with filters
- Strategy configuration forms
- Settings management
- Live trades display

**1C - Analytics**
- Equity curve charts (Chart.js/ApexCharts)
- Performance metrics (win rate, profit factor, Sharpe)
- Drawdown analysis
- Cost breakdown charts

### Phase 2: MT5 Integration

- Python bot skeleton
- MT5 connection and authentication
- Broker account sync
- Basic order placement/modification

### Phase 3: Strategy Engine

- Signal generation logic
- Entry/exit rules implementation
- Partial close management
- Risk calculations (position sizing)

### Phase 4: Real-time Features

- WebSocket connection for live updates
- Real-time price display
- Push notifications for trade events
- Screenshot capture on trade events

### Phase 5: Advanced Analytics

- Machine learning signal filtering
- Monte Carlo simulations
- Walk-forward optimization
- Correlation analysis

### Phase 6: API & Automation

- REST API for external integrations
- Telegram/Discord bot notifications
- Scheduled reports
- Backup and recovery automation

### Phase 7: Risk Management

- Daily loss limits with auto-shutdown
- Drawdown protection
- Volatility-based position sizing
- News event filtering (external calendar API)

### Phase 8: Multi-account

- Multiple broker account support
- Account comparison dashboard
- Aggregate statistics
- Cross-account risk management

### Phase 9: Backtesting

- Historical data import
- Strategy backtester
- Parameter optimization
- Walk-forward analysis

### Phase 10: SaaS Preparation

- Multi-tenant database isolation
- Subscription management
- Usage billing
- Onboarding flow

## Key Design Decisions

### Multi-tenant Ready from Day 1

Every table has `user_id` foreign key:
- Easy to add row-level security later
- Can filter data per user
- Supports team accounts (future)

### Encrypted Sensitive Data

Broker `account_number` uses Laravel's encrypted cast:
- Data encrypted at rest in database
- Automatically decrypted when accessed
- Protection if database is compromised

### Enum Status Fields

Using enums for `status` and `direction`:
- Type safety at database level
- Clear valid values
- Efficient storage

### Timestamps Everywhere

Every table has `created_at` and `updated_at`:
- Full audit trail
- Easy debugging
- Required for certain analyses

## Security Considerations

1. **Broker Credentials**: Stored in `.env`, never in database
2. **Account Numbers**: Encrypted at rest using Laravel's encryption
3. **Authentication**: Laravel Breeze with CSRF protection
4. **API Security** (Phase 6): API keys with rate limiting
5. **Database**: Limited permissions per service

## File Storage

Screenshots stored in `storage/app/public/screenshots/`:
- Organized by year/month: `screenshots/2024/01/`
- Naming: `trade_{id}_{type}.png`
- Accessible via `/storage/screenshots/...`
