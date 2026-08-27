<?php

namespace App\Livewire\Pages;

use App\Http\Controllers\Api\Telegram\LoginController;
use App\Models\BotToken;
use App\Models\TelegramAccount;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Telegram Accounts
 *
 * Registers each account a collector signs in as, and issues that collector its own token.
 *
 * ## Why the sign-in is not on this page
 *
 * MTProto authentication needs the code Telegram sends to the phone and, where one is set,
 * a second-factor password. A web form collecting those would put a credential capable of
 * reading every chat on the account through a server that has no reason to ever hold one,
 * and the session file it produced would live there too.
 *
 * So this page does the half that belongs to a dashboard - naming the account, issuing a
 * revocable token, showing what the collector reported back - and hands over the exact
 * command for the half that belongs on the machine where the session will live.
 *
 * ## One token per account
 *
 * Not one shared between them. Revoking a token then stops exactly one collector, which is
 * what you want when a machine is decommissioned or a session is compromised, and it is
 * how the dashboard tells which collector a message came from without being told.
 */
#[Layout('layouts.app')]
#[Title('Telegram Accounts - FXSignalPro')]
class TelegramAccounts extends Component
{
    #[Validate('required|string|max:60')]
    public string $label = '';

    /** Held for this render only, exactly like every other token this system issues. */
    public ?string $issuedToken = null;

    public ?int $issuedFor = null;

    /** The account whose sign-in is being driven from this page. */
    public ?int $signingIn = null;

    #[Validate('nullable|string|max:32')]
    public string $phone = '';

    #[Validate('nullable|string|max:16')]
    public string $code = '';

    #[Validate('nullable|string|max:128')]
    public string $password = '';

    public function add(): void
    {
        $this->validate();

        $hosted = (bool) config('telegram.hosted_by_default');

        // A hosted account has no collector of its own, so it needs no token. Issuing one
        // anyway would put a credential on screen that nothing consumes, and the surest
        // way to teach somebody to ignore a secret is to show them one that does nothing.
        $token = null;
        $plaintext = null;

        if (! $hosted) {
            [$plaintext, $token] = BotToken::generate(Auth::user(), "Collector: {$this->label}");
        }

        $account = TelegramAccount::create([
            'user_id' => Auth::id(),
            'label' => $this->label,
            'bot_token_id' => $token?->id,
            'is_hosted' => $hosted,
        ]);

        $this->issuedToken = $plaintext;
        $this->issuedFor = $account->id;
        $this->label = '';

        $this->dispatch('notify', message: $hosted
            ? 'Account added. Sign in with your phone number below.'
            : 'Account added. Copy the token now - it cannot be shown again.', type: 'success');
    }

    /**
     * Issue a fresh token for an account whose collector needs re-authorising.
     */
    public function reissue(int $id): void
    {
        $account = TelegramAccount::where('user_id', Auth::id())->find($id);

        if ($account === null) {
            return;
        }

        // Nothing authenticates as a hosted account: the platform's worker speaks for it
        // with a credential of its own. Issuing a token here would produce a secret that
        // opens nothing, which is worse than no button at all.
        if ($account->is_hosted) {
            return;
        }

        // The old one is revoked rather than left alive. Two working tokens for one
        // collector is a credential nobody is tracking.
        $account->token?->delete();

        [$plaintext, $token] = BotToken::generate(Auth::user(), "Collector: {$account->label}");

        $account->update(['bot_token_id' => $token->id]);

        $this->issuedToken = $plaintext;
        $this->issuedFor = $account->id;

        $this->dispatch('notify', message: 'New token issued. The previous one no longer authenticates.', type: 'success');
    }

    public function remove(int $id): void
    {
        $account = TelegramAccount::where('user_id', Auth::id())->find($id);

        if ($account === null) {
            return;
        }

        // Channels survive with a null account. Their captured signals and results are
        // history worth keeping, and deleting them to tidy up a machine list would throw
        // away the record of what was traded.
        $account->token?->delete();
        $account->delete();

        $this->dispatch('notify', message: 'Account removed and its token revoked.', type: 'success');
    }

    /**
     * Start a sign-in. The collector for this account does the rest.
     */
    public function beginLogin(int $id): void
    {
        $account = $this->find($id);

        if ($account === null) {
            return;
        }

        $this->validate(['phone' => ['required', 'string', 'max:32']]);

        // A hosted account with no worker behind it would sit on "asking Telegram for a
        // code" for ever, because nothing is listening. Saying so is the difference
        // between a misconfigured deployment and one that looks broken to its customers.
        if ($account->is_hosted && ! self::hostedReady()) {
            $this->dispatch('notify', type: 'error', message: 'Hosted sign-in is not configured on this deployment. Nothing would answer this request.');

            return;
        }

        // Only the number is stored, and only so the page can say which one is being signed
        // in. It is not a secret and is not usable on its own.
        $account->update(['login_phone' => trim($this->phone)]);
        $account->advance(TelegramAccount::REQUESTED);

        $this->signingIn = $id;
        $this->phone = '';

        $this->dispatch('notify', message: 'Asking Telegram for a code. Check your Telegram app.', type: 'info');
    }

    /**
     * Hand the code to the collector, once.
     */
    public function submitCode(int $id): void
    {
        $account = $this->find($id);

        if ($account === null) {
            return;
        }

        $this->validate(['code' => ['required', 'string', 'max:16']]);

        // Into the cache with a short expiry, deleted the instant the collector takes it.
        // Never a column, never a log.
        LoginController::relay($account, 'code', trim($this->code));
        $account->advance(TelegramAccount::CODE_SUBMITTED);

        $this->code = '';
    }

    public function submitPassword(int $id): void
    {
        $account = $this->find($id);

        if ($account === null) {
            return;
        }

        $this->validate(['password' => ['required', 'string', 'max:128']]);

        LoginController::relay($account, 'password', $this->password);
        $account->advance(TelegramAccount::PASSWORD_SUBMITTED);

        $this->password = '';
    }

    /**
     * Abandon a half-finished attempt.
     */
    public function cancelLogin(int $id): void
    {
        $this->find($id)?->advance(TelegramAccount::IDLE);

        $this->signingIn = null;
        $this->phone = $this->code = $this->password = '';
    }

    /**
     * Can this deployment sign anybody in itself?
     *
     * All three or none: the worker cannot connect to Telegram without an application,
     * and cannot reach the dashboard without its token. Checked before a sign-in rather
     * than after, because the failure it prevents is silent.
     */
    public static function hostedReady(): bool
    {
        return filled(config('telegram.app_id'))
            && filled(config('telegram.app_hash'))
            && filled(config('telegram.worker_token'));
    }

    private function find(int $id): ?TelegramAccount
    {
        return TelegramAccount::where('user_id', Auth::id())->find($id);
    }

    public function dismissToken(): void
    {
        $this->issuedToken = null;
        $this->issuedFor = null;
    }

    public function render()
    {
        return view('livewire.pages.telegram-accounts', [
            'accounts' => TelegramAccount::withCount([
                'channels',
                'channels as enabled_channels_count' => fn ($q) => $q->where('is_enabled', true),
            ])->where('user_id', Auth::id())->orderBy('id')->get(),
            'baseUrl' => rtrim((string) config('app.url'), '/'),
            'hostedReady' => self::hostedReady(),
            // Polled only while a conversation is under way, so an idle page is not
            // refetching every few seconds for nothing.
            'awaiting' => TelegramAccount::where('user_id', Auth::id())
                ->whereNotIn('login_state', [TelegramAccount::IDLE, TelegramAccount::ACTIVE, TelegramAccount::FAILED])
                ->exists(),
        ]);
    }
}
