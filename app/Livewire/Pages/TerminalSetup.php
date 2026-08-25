<?php

namespace App\Livewire\Pages;

use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\TradeCommand;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Terminal Setup
 *
 * Everything needed to get an Expert Advisor talking to this dashboard, in the order it
 * has to happen.
 *
 * ## The token is shown exactly once
 *
 * `bot_tokens` stores a SHA-256 hash, not the token. That is deliberate - a dashboard
 * compromise leaks no working credentials - and it means an existing token genuinely
 * cannot be displayed again. "Copy the token" therefore means "issue one and copy it now",
 * and the page says so rather than leaving someone hunting for a reveal button that cannot
 * exist.
 *
 * Issuing a replacement does not revoke the old one, so a terminal already running keeps
 * working until you revoke it deliberately.
 */
#[Layout('layouts.app')]
#[Title('Terminal Setup - FXSignalPro')]
class TerminalSetup extends Component
{
    #[Validate('required|string|max:60')]
    public string $tokenName = '';

    #[Validate('nullable|integer')]
    public ?int $brokerAccountId = null;

    /** Held in memory for this render only. It is never stored and cannot be shown again. */
    public ?string $issuedToken = null;

    public function mount(): void
    {
        $this->tokenName = 'MetaTrader terminal';
        $this->brokerAccountId = BrokerAccount::where('user_id', Auth::id())
            ->where('is_active', true)
            ->value('id');
    }

    public function issueToken(): void
    {
        $this->validate();

        $account = $this->brokerAccountId === null
            ? null
            : BrokerAccount::where('user_id', Auth::id())->find($this->brokerAccountId);

        [$plaintext] = BotToken::generate(Auth::user(), $this->tokenName, $account);

        $this->issuedToken = $plaintext;

        $this->dispatch('notify', message: 'Token issued. Copy it now - it cannot be shown again.', type: 'success');
    }

    public function revokeToken(int $id): void
    {
        $token = BotToken::where('user_id', Auth::id())->find($id);

        if ($token === null) {
            return;
        }

        // A terminal still holding this token starts failing authentication on its next
        // poll, which is the intended effect and the reason this is one click away from
        // the tokens it lists.
        $token->delete();

        $this->dispatch('notify', message: 'Token revoked. Any terminal using it will stop authenticating.', type: 'success');
    }

    public function dismissToken(): void
    {
        $this->issuedToken = null;
    }

    public function render()
    {
        return view('livewire.pages.terminal-setup', [
            'tokens' => BotToken::with('brokerAccount')
                ->where('user_id', Auth::id())
                ->orderByDesc('id')
                ->get(),
            'accounts' => BrokerAccount::where('user_id', Auth::id())->orderBy('id')->get(),
            // The exact string that must be whitelisted, from the app's own configuration
            // rather than from whatever the browser happens to be showing.
            'whitelistUrl' => rtrim((string) config('app.url'), '/'),
            'wireVersion' => TradeCommand::WIRE_VERSION,
        ]);
    }
}
