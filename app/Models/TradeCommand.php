<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Trade Command Model
 *
 * One instruction from the dashboard to whichever executor is running. See the
 * migration for why this is a queue rather than a direct call.
 *
 * The wire format below exists because the MQL5 EA has no JSON parser - MQL5 ships
 * none, and hand-rolling one inside an EA is a poor place to discover a parsing bug.
 * A fixed-column, tab-separated line is parsed with a single StringSplit() call.
 * JSON is still available to any other client; only the EA asks for text/plain.
 */
class TradeCommand extends Model
{
    /** Bumped whenever the column layout changes, so an old EA can refuse politely. */
    /**
     * Bumped to GDCMD2 when `entry_price` was appended for pending orders.
     *
     * The version is checked before the columns are, so an EA that has not been
     * recompiled refuses every command with "dashboard sent GDCMD2, this EA understands
     * GDCMD1" rather than silently reading a thirteen-column line as twelve. Loud and
     * self-explaining beats subtly misaligned, and no trade happens in the meantime -
     * which is the safe direction for a protocol change to fail in.
     */
    public const WIRE_VERSION = 'GDCMD2';

    /** Column order of the tab-separated wire format. Do not reorder - only append. */
    public const WIRE_COLUMNS = [
        'id', 'type', 'symbol', 'direction', 'volume',
        'sl_pips', 'tp_pips', 'sl_price', 'tp_price', 'ticket', 'comment', 'reason',
        // Where a pending order rests. Empty on a market order, which is every command
        // type that existed before this column did.
        'entry_price',
    ];

    protected $fillable = [
        'user_id',
        'broker_account_id',
        'trade_id',
        'type',
        'payload',
        'status',
        'idempotency_key',
        'attempts',
        'result',
        'error',
        'expires_at',
        'claimed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Commands an executor may claim right now.
     *
     * Deliberately excludes expired rows: a market order that waited out its window is
     * no longer the trade the strategy intended, and filling it late is worse than not
     * filling it at all.
     */
    public function scopeClaimable(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Commands still waiting on the executor.
     *
     * Distinct from `claimable`, which answers "may an executor take this now". This answers
     * "is anyone still expecting this to happen", which is what the dashboard needs in order
     * to show a position as closing.
     *
     * The expiry check is the whole point. Nothing marked a lapsed command as expired, so a
     * close that timed out sat at `pending` for ever and Live Trades kept showing the
     * position as closing - with the Close button replaced by that label, leaving no way to
     * retry. Reading the expiry here means the UI is right whether or not the sweep has run.
     */
    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'claimed'])
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Mark lapsed commands as expired.
     *
     * `scopeClaimable` already refuses to hand one out, so this changes no execution
     * behaviour - it stops the queue accumulating rows that look pending for ever, and makes
     * "what happened to that command" answerable from the row itself.
     *
     * Claimed commands are swept too: an executor that took one and then died leaves it
     * claimed, and nothing else would ever move it.
     */
    public static function sweepExpired(): int
    {
        return self::query()
            ->whereIn('status', ['pending', 'claimed'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'completed_at' => now(),
                'error' => 'Expired before an executor completed it.',
                'updated_at' => now(),
            ]);
    }

    /**
     * Restrict to the account this executor is bound to.
     *
     * Commands with a null broker_account_id are account-agnostic (start/stop) and go
     * to every executor for the user.
     */
    public function scopeForAccount(Builder $query, ?int $brokerAccountId): Builder
    {
        if ($brokerAccountId === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereNull('broker_account_id')
            ->orWhere('broker_account_id', $brokerAccountId));
    }

    // =========================================================================
    // ENQUEUE
    // =========================================================================

    /**
     * Add a command to the queue, collapsing duplicates.
     *
     * `firstOrCreate` on the unique idempotency_key is what makes a double-clicked
     * button, or a retried request, produce one position rather than two.
     */
    public static function enqueue(
        User $user,
        string $type,
        array $payload = [],
        ?BrokerAccount $account = null,
        ?string $idempotencyKey = null,
        ?int $expiresInSeconds = null,
    ): self {
        $key = $idempotencyKey ?? (string) Str::uuid();

        return self::firstOrCreate(
            ['idempotency_key' => $key],
            [
                'user_id' => $user->id,
                'broker_account_id' => $account?->id,
                'trade_id' => $payload['trade_id'] ?? null,
                'type' => $type,
                'payload' => $payload,
                'status' => 'pending',
                'expires_at' => $expiresInSeconds ? now()->addSeconds($expiresInSeconds) : null,
            ],
        );
    }

    /**
     * Atomically hand the next pending commands to one executor.
     *
     * The UPDATE-then-SELECT ordering matters: two executors polling simultaneously
     * must not both receive the same command. The conditional update means only one
     * transitions each row out of 'pending', and the loser simply gets fewer rows.
     */
    public static function claimBatch(int $userId, ?int $brokerAccountId, int $limit = 10): iterable
    {
        return DB::transaction(function () use ($userId, $brokerAccountId, $limit) {
            $ids = self::query()
                ->where('user_id', $userId)
                ->claimable()
                ->forAccount($brokerAccountId)
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isEmpty()) {
                return collect();
            }

            self::whereIn('id', $ids)->where('status', 'pending')->update([
                'status' => 'claimed',
                'claimed_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

            return self::whereIn('id', $ids)->orderBy('id')->get();
        });
    }

    // =========================================================================
    // COMPLETION
    // =========================================================================

    public function markDone(array $result = []): void
    {
        $this->update([
            'status' => 'done',
            'result' => $result,
            'error' => null,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error, array $result = []): void
    {
        $this->update([
            'status' => 'failed',
            'result' => $result,
            'error' => $error,
            'completed_at' => now(),
        ]);
    }

    // =========================================================================
    // WIRE FORMAT
    // =========================================================================

    /**
     * Render as one tab-separated line for the MQL5 EA.
     *
     * Absent values are empty strings, never omitted, so the column count is constant
     * and StringSplit() on the EA side always yields the same array shape.
     */
    public function toWireLine(): string
    {
        $payload = $this->payload ?? [];

        $fields = [
            $this->id,
            $this->type,
            $payload['symbol'] ?? '',
            $payload['direction'] ?? '',
            $payload['volume'] ?? '',
            $payload['sl_pips'] ?? '',
            $payload['tp_pips'] ?? '',
            $payload['sl_price'] ?? '',
            $payload['tp_price'] ?? '',
            $payload['ticket'] ?? '',
            $payload['comment'] ?? '',
            // Which ladder step this close represents. The EA cannot infer it from a
            // broker fill, so the strategy that asked for the close states it here.
            $payload['reason'] ?? '',
            $payload['entry_price'] ?? '',
        ];

        // A stray tab or newline in a free-text comment would shift every later column.
        return implode("\t", array_map(
            fn ($v) => str_replace(["\t", "\r", "\n"], ' ', (string) $v),
            $fields,
        ));
    }

    /**
     * Render a whole batch, version header first.
     */
    public static function toWireBatch(iterable $commands): string
    {
        $lines = [self::WIRE_VERSION];

        foreach ($commands as $command) {
            $lines[] = $command->toWireLine();
        }

        return implode("\n", $lines)."\n";
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
