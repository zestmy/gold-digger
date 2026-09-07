<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';
    public string $email = '';

    /** Display only. Nothing stored or compared ever consults this. */
    public string $timezone = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->timezone = (string) (Auth::user()->timezone ?? '');
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            // Validated against the system's own list rather than a regex: an identifier
            // PHP does not recognise would throw at render time, on every page, for this
            // user only - which is a miserable way to find out.
            'timezone' => ['nullable', 'string', 'timezone'],
        ]);

        // Nothing about verification here: the application never verified an address, so
        // there is no status to reset when one changes.
        $user->fill($validated)->save();

        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Identifiers grouped by region, so the list is navigable rather than merely complete.
     *
     * @return array<string, array<string, string>>
     */
    public function zones(): array
    {
        $grouped = [];

        foreach (\DateTimeZone::listIdentifiers() as $identifier) {
            [$region, $city] = array_pad(explode('/', $identifier, 2), 2, null);

            if ($city === null) {
                continue;
            }

            $grouped[$region][$identifier] = str_replace('_', ' ', $city);
        }

        ksort($grouped);

        return $grouped;
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-100">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-400">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form wire:submit="updateProfileInformation" class="mt-6 space-y-6">
        <div>
            <x-input-label for="name" :value="__('Name')" />
            <input wire:model="name" id="name" name="name" type="text" required autofocus autocomplete="name" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <input wire:model="email" id="email" name="email" type="email" required autocomplete="username" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
            <x-input-error class="mt-2" :messages="$errors->get('email')" />
        </div>

        <div>
            <x-input-label for="timezone" :value="__('Time zone')" />

            <div class="mt-1 flex gap-2" x-data>
                <select id="timezone" wire:model="timezone"
                        class="block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                    <option value="">Use UTC</option>
                    @foreach($this->zones() as $region => $identifiers)
                        <optgroup label="{{ $region }}">
                            @foreach($identifiers as $identifier => $label)
                                <option value="{{ $identifier }}">{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>

                {{-- The browser already knows. Asking somebody to find their own city in a
                     list of four hundred when the answer is one API call away is the kind
                     of small rudeness that makes a settings page feel unfinished. --}}
                <button type="button"
                        x-on:click="$wire.set('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone)"
                        class="shrink-0 rounded-md bg-gray-700 px-3 py-2 text-xs font-medium text-gray-200 hover:bg-gray-600">
                    {{ __('Detect') }}
                </button>
            </div>

            <p class="mt-1 text-xs text-gray-500">
                {{ __('Changes how times are displayed only. Everything is stored in UTC, and hovering any time shows it.') }}
                @if($timezone !== '')
                    <span class="ml-1 text-gray-400">{{ __('Now:') }} {{ now()->setTimezone($timezone)->format('M d, H:i') }}</span>
                @endif
            </p>

            <x-input-error class="mt-2" :messages="$errors->get('timezone')" />
        </div>

        <div class="flex items-center gap-4">
            <button type="submit" class="rounded-md bg-yellow-500 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-yellow-400">{{ __('Save') }}</button>

            <x-action-message class="me-3 text-green-400" on="profile-updated">
                {{ __('Saved.') }}
            </x-action-message>
        </div>
    </form>
</section>
