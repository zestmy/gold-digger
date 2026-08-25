<?php

namespace App\Livewire\Pages;

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
#[Title('Telegram Accounts - Gold Digger')]
class TelegramAccounts extends Component
{
    #[Validate('required|string|max:60')]
    public string $label = '';

    /** Held for this render only, exactly like every other token this system issues. */
    public ?string $issuedToken = null;

    public ?int $issuedFor = null;

    public function add(): void
    {
        $this->validate();

        [$plaintext, $token] = BotToken::generate(Auth::user(), "Collector: {$this->label}");

        $account = TelegramAccount::create([
            'user_id' => Auth::id(),
            'label' => $this->label,
            'bot_token_id' => $token->id,
        ]);

        $this->issuedToken = $plaintext;
        $this->issuedFor = $account->id;
        $this->label = '';

        $this->dispatch('notify', message: 'Account added. Copy the token now - it cannot be shown again.', type: 'success');
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
        ]);
    }
}
