<?php

namespace App\Livewire\Pages;

use App\Models\BotSettings;
use App\Models\TelegramSignal;
use App\Services\Ai\AiFund;
use App\Services\Telegram\SignalExecutor;
use App\Services\Telegram\SignalIngest;
use App\Services\Telegram\SignalParser;
use App\Services\Telegram\SignalPlan;
use App\Services\Telegram\SignalReviewer;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Copied Signals
 *
 * The Telegram copier's pipeline, stage by stage: captured, parsed, reviewed, executed.
 * The "Copied" tab under Signals, beside the AI feed it is judged against.
 *
 * ## Why the decline rate is at the top
 *
 * The most useful number on this page is the share of signals the reviewer turned down.
 * A copier approving most of what it sees is indistinguishable from having no reviewer,
 * and the difference is invisible in a list of individual verdicts that each read
 * sensibly. Putting the rate where it cannot be missed is what makes that failure
 * findable.
 *
 * The second most useful is the unparsed count, which is how a provider changing format
 * announces itself. Otherwise the messages keep arriving, nothing trades, and it looks
 * like a quiet week.
 *
 * ## Execute is deliberately not a casual button
 *
 * It places a real order against a live account. It appears only on signals that are
 * approved and unacted-on, it states what it will risk before you press it, and every
 * gate is re-checked when you do - an approval from twenty minutes ago is not permission.
 *
 * ## An unparsed message can be read by a person
 *
 * The parser refuses more than it guesses, which is the right rule for a machine acting
 * unattended and the wrong one for a message a reader understands at a glance. So an
 * unparsed signal offers a form: the reader types the fields, the same coherence check
 * the parser applies runs on them, and the signal joins the pipeline at review as if it
 * had parsed - marked as read by a person, so the channel's parse rate stays a fact
 * about the parser and a later reparse never overwrites what was typed.
 */
#[Layout('layouts.app')]
#[Title('Copied signals - FXSignalPro')]
class SignalCopier extends Component
{
    use WithPagination;

    #[Url]
    public string $filter = 'all';

    public ?int $busy = null;

    /** The unparsed signal a reader is correcting, if any, and what they have typed. */
    public ?int $correcting = null;

    public string $c_symbol = '';

    public string $c_direction = 'buy';

    public string $c_entry = '';

    public string $c_zone_high = '';

    public string $c_sl = '';

    public string $c_tps = '';

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Pull anything new off the bot now, rather than waiting for the scheduler.
     */
    public function pollNow(): void
    {
        $result = app(SignalIngest::class)->poll();

        $this->dispatch(
            'notify',
            message: $result['ok']
                ? "{$result['stored']} message(s) captured, {$result['parsed']} parsed."
                : $result['error'],
            type: $result['ok'] ? 'success' : 'error',
        );
    }

    public function reviewNow(int $id): void
    {
        $signal = $this->find($id);

        if ($signal === null || $signal->parse_status !== TelegramSignal::PARSE_OK) {
            return;
        }

        $this->busy = $id;

        $verdict = app(SignalReviewer::class)->review($signal);

        $signal->update([
            'review_status' => $verdict['status'],
            'review_reasoning' => $verdict['reasoning'],
            'review_confidence' => $verdict['confidence'],
            'review_model' => $verdict['model'],
            'reviewed_at' => now(),
        ]);

        $this->busy = null;
        $this->dispatch('notify', message: 'Reviewed: '.strtoupper($verdict['status']), type: 'success');
    }

    /**
     * Queue the order. Everything is re-checked inside the executor.
     */
    public function executeNow(int $id): void
    {
        $signal = $this->find($id);

        if ($signal === null || ! $signal->isActionable()) {
            return;
        }

        $this->busy = $id;

        $result = app(SignalExecutor::class)->execute($signal);

        $this->busy = null;
        $this->dispatch('notify', message: $result['note'], type: $result['ok'] ? 'success' : 'error');
    }

    public function startCorrection(int $id): void
    {
        $signal = $this->find($id);

        if ($signal === null || ! $this->correctable($signal)) {
            return;
        }

        $this->resetValidation();
        $this->correcting = $id;
        $this->c_symbol = (string) ($signal->symbol ?? '');
        $this->c_direction = $signal->direction ?? 'buy';
        $this->c_entry = '';
        $this->c_zone_high = '';
        $this->c_sl = '';
        $this->c_tps = '';
    }

    public function cancelCorrection(): void
    {
        $this->correcting = null;
        $this->resetValidation();
    }

    /**
     * Take the reader's fields as the parse. Reviewed like any other signal from here.
     */
    public function saveCorrection(): void
    {
        $signal = $this->correcting === null ? null : $this->find($this->correcting);

        if ($signal === null || ! $this->correctable($signal)) {
            $this->cancelCorrection();

            return;
        }

        $this->validate([
            'c_symbol' => 'required|string|max:32|regex:/^[A-Za-z0-9._#-]+$/',
            'c_direction' => 'required|in:buy,sell',
            'c_entry' => 'nullable|numeric|gt:0',
            'c_zone_high' => 'nullable|numeric|gt:0',
            // The rule the parser exists to enforce holds for a person too.
            'c_sl' => 'required|numeric|gt:0',
            'c_tps' => ['nullable', 'regex:/^\s*\d+(\.\d+)?(\s*[,\/ ]\s*\d+(\.\d+)?)*\s*$/'],
        ], [
            'c_sl.required' => 'A stop is required. A signal without one is never traded.',
            'c_tps.regex' => 'Targets are numbers separated by commas.',
        ]);

        $entry = $this->c_entry === '' ? null : (float) $this->c_entry;
        $zoneHigh = $this->c_zone_high === '' ? null : (float) $this->c_zone_high;
        $sl = (float) $this->c_sl;
        $tps = array_values(array_map('floatval', preg_split('/[,\/\s]+/', trim($this->c_tps), -1, PREG_SPLIT_NO_EMPTY)));

        if ($zoneHigh !== null && $entry === null) {
            $this->addError('c_entry', 'A zone needs an entry for its near side.');

            return;
        }

        $incoherent = app(SignalParser::class)->coherenceError($this->c_direction, $entry, $sl, $tps);

        if ($incoherent !== null) {
            $this->addError('c_sl', 'That is '.$incoherent.'.');

            return;
        }

        // Targets nearest first, the order everything downstream assumes.
        usort($tps, fn (float $a, float $b) => $this->c_direction === 'buy' ? $a <=> $b : $b <=> $a);

        $signal->update([
            'parse_status' => TelegramSignal::PARSE_OK,
            'parse_error' => null,
            'parsed_by' => TelegramSignal::PARSED_BY_USER,
            'corrected_at' => now(),
            'symbol' => strtoupper($this->c_symbol),
            'direction' => $this->c_direction,
            'entry_price' => $entry,
            'entry_zone_high' => $zoneHigh,
            'sl_price' => $sl,
            'tp_prices' => $tps ?: null,
            // Into the pipeline at review, exactly where a parsed message enters it.
            'review_status' => TelegramSignal::REVIEW_PENDING,
            'review_reasoning' => null,
            'review_confidence' => null,
            'reviewed_at' => null,
        ]);

        $this->cancelCorrection();
        $this->dispatch('notify', message: 'Read as '.strtoupper($this->c_direction).' '.strtoupper($this->c_symbol).'. Awaiting review.', type: 'success');
    }

    /**
     * Only a signal that never parsed and was never acted on. A message the ingest
     * refused because its chat is not a source stays refused - that is a channel setting,
     * not a reading.
     */
    private function correctable(TelegramSignal $signal): bool
    {
        return $signal->kind === TelegramSignal::KIND_SIGNAL
            && $signal->parse_status === TelegramSignal::PARSE_FAILED
            && $signal->execution_status === TelegramSignal::EXEC_NONE
            && $signal->parse_error !== 'Channel is not enabled as a signal source.';
    }

    private function find(int $id): ?TelegramSignal
    {
        return TelegramSignal::where('user_id', Auth::id())->find($id);
    }

    public function render()
    {
        // Signals only. A reply belongs under the position it manages, not beside it in a
        // flat list where "secure half" reads as a signal that failed to parse - and a
        // layer is not a message anybody posted.
        $base = TelegramSignal::where('user_id', Auth::id())
            ->where('kind', TelegramSignal::KIND_SIGNAL);

        $counts = [
            'total' => (clone $base)->count(),
            'unparsed' => (clone $base)->where('parse_status', TelegramSignal::PARSE_FAILED)->count(),
            'reviewed' => (clone $base)->whereIn('review_status', [TelegramSignal::REVIEW_APPROVED, TelegramSignal::REVIEW_DECLINED])->count(),
            'approved' => (clone $base)->where('review_status', TelegramSignal::REVIEW_APPROVED)->count(),
            'executed' => (clone $base)->where('execution_status', TelegramSignal::EXEC_EXECUTED)->count(),
            'pending' => (clone $base)->awaitingReview()->count(),
        ];

        $query = match ($this->filter) {
            'parsed' => (clone $base)->where('parse_status', TelegramSignal::PARSE_OK),
            'unparsed' => (clone $base)->where('parse_status', TelegramSignal::PARSE_FAILED),
            'approved' => (clone $base)->where('review_status', TelegramSignal::REVIEW_APPROVED),
            'declined' => (clone $base)->where('review_status', TelegramSignal::REVIEW_DECLINED),
            'executed' => (clone $base)->whereIn('execution_status', [TelegramSignal::EXEC_QUEUED, TelegramSignal::EXEC_EXECUTED]),
            default => $base,
        };

        $settings = BotSettings::where('user_id', Auth::id())->first();

        $signals = $query->with(['followUps' => fn ($q) => $q->orderBy('id')])
            ->orderByDesc('id')->paginate(15);

        return view('livewire.pages.signal-copier', [
            'signals' => $signals,
            'plans' => $this->plans($signals->items(), $settings),
            'counts' => $counts,
            // Of the ones actually judged. Including unreviewed messages in the denominator
            // would flatter the rate by counting chatter as a decline.
            'declineRate' => $counts['reviewed'] > 0
                ? (int) round((($counts['reviewed'] - $counts['approved']) / $counts['reviewed']) * 100)
                : null,
            'fund' => app(AiFund::class)->state($settings, (int) Auth::id()),
        ]);
    }

    /**
     * What each listed signal would actually be traded with.
     *
     * Only worth showing where it differs from the message, which is what `summary()`
     * decides. Under `copier_levels = strategy` the card was showing the provider's
     * numbers beside a verdict written about entirely different ones - the reviewer
     * declining a 0.23:1 trade while the card displayed the posted 1.60:1 - and there was
     * no way to tell from the screen that they were two different trades.
     *
     * One planner for the whole page, so the account and symbol resolve once rather than
     * once per row.
     *
     * @param  array<int, TelegramSignal>  $signals
     * @return array<int, string>
     */
    private function plans(array $signals, ?BotSettings $settings): array
    {
        $planner = new SignalPlan;
        $plans = [];

        foreach ($signals as $signal) {
            if ($signal->parse_status !== TelegramSignal::PARSE_OK) {
                continue;
            }

            $plan = $planner->for($signal, $settings);

            if ($plan['source'] !== SignalPlan::SOURCE_STRATEGY) {
                continue;
            }

            $plans[$signal->id] = [
                'entry' => $plan['entry'],
                'sl' => $plan['sl'],
                'tps' => $plan['tps'],
                'summary' => $planner->summary($plan),
            ];
        }

        return $plans;
    }
}
