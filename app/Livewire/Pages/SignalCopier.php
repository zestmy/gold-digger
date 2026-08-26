<?php

namespace App\Livewire\Pages;

use App\Models\BotSettings;
use App\Models\TelegramSignal;
use App\Services\Ai\AiFund;
use App\Services\Telegram\SignalExecutor;
use App\Services\Telegram\SignalIngest;
use App\Services\Telegram\SignalPlan;
use App\Services\Telegram\SignalReviewer;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Signal Copier
 *
 * The pipeline, stage by stage: captured, parsed, reviewed, executed.
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
 */
#[Layout('layouts.app')]
#[Title('Signal Copier - FXSignalPro')]
class SignalCopier extends Component
{
    use WithPagination;

    #[Url]
    public string $filter = 'all';

    public ?int $busy = null;

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
