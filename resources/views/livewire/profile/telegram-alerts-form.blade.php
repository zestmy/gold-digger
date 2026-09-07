<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Telegram alerts
 *
 * The chat the alert notifier sends this account's incidents and order announcements
 * to - `users.telegram_chat_id`, read by AlertNotifier::destinationFor.
 *
 * A private chat id is a run of digits; a group's is the same with a leading minus.
 * Nothing else is accepted, because a username or a phone number typed here would fail
 * quietly at delivery time - Telegram answers 400 to both - and the first anyone would
 * know is an incident that reached nobody.
 *
 * Blank is allowed and means "nowhere": the notifier falls through to null rather than to
 * the platform's own channel, which is deliberate, and this form does not undo it.
 */
new class extends Component
{
    public string $telegram_chat_id = '';

    /**
     * The delivery switch, `users.alerts_enabled`, which AlertNotifier consults before
     * sending anything to this account. Off keeps the chat id - a holiday is not a reason
     * to look the number up again afterwards.
     */
    public bool $alerts_enabled = true;

    public function mount(): void
    {
        $this->telegram_chat_id = (string) (Auth::user()->telegram_chat_id ?? '');
        $this->alerts_enabled = (bool) (Auth::user()->alerts_enabled ?? true);
    }

    public function updateTelegramAlerts(): void
    {
        $validated = $this->validate([
            'telegram_chat_id' => ['nullable', 'string', 'max:64', 'regex:/^-?\d+$/'],
            'alerts_enabled' => ['boolean'],
        ], [
            'telegram_chat_id.regex' => 'A chat id is a number - digits only, with a leading minus for a group.',
        ]);

        Auth::user()->fill([
            'telegram_chat_id' => trim((string) ($validated['telegram_chat_id'] ?? '')) === ''
                ? null
                : trim($validated['telegram_chat_id']),
            'alerts_enabled' => (bool) $validated['alerts_enabled'],
        ])->save();

        $this->dispatch('telegram-alerts-updated');
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-100">Telegram alerts</h2>

        <p class="mt-1 text-sm text-gray-400">
            Incidents and every order the copier places are sent here. Message the bot first so it can reply.
        </p>
    </header>

    <form wire:submit="updateTelegramAlerts" class="mt-6 space-y-6">
        <div>
            <label for="telegram_chat_id" class="block text-sm font-medium text-gray-300">Chat id</label>
            <input wire:model="telegram_chat_id" id="telegram_chat_id" name="telegram_chat_id" type="text"
                   inputmode="numeric" autocomplete="off" placeholder="316745398"
                   class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 font-mono text-white shadow-sm focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
            <p class="mt-1 text-xs text-gray-500">
                Digits for a private chat, or a leading minus for a group. Leave it blank and nothing is sent.
            </p>
            <x-input-error class="mt-2" :messages="$errors->get('telegram_chat_id')" />
        </div>

        <label for="alerts_enabled" class="flex cursor-pointer items-start gap-3">
            <input wire:model="alerts_enabled" id="alerts_enabled" name="alerts_enabled" type="checkbox"
                   class="mt-0.5 h-4 w-4 rounded border-gray-600 bg-gray-700 text-yellow-500 focus:ring-yellow-500">
            <span>
                <span class="block text-sm font-medium text-gray-300">Send alerts</span>
                <span class="block text-xs text-gray-500">Off keeps the chat id and sends nothing. Incidents are still recorded under Activity.</span>
            </span>
        </label>

        <div class="flex items-center gap-4">
            <button type="submit"
                    class="rounded-md bg-yellow-500 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-yellow-400">
                Save
            </button>

            <x-action-message class="me-3 text-green-400" on="telegram-alerts-updated">
                Saved.
            </x-action-message>
        </div>
    </form>
</section>
