<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
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

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

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

    /**
     * Send an email verification notification to the current user.
     */
    public function sendVerification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form wire:submit="updateProfileInformation" class="mt-6 space-y-6">
        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input wire:model="name" id="name" name="name" type="text" class="mt-1 block w-full" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="email" id="email" name="email" type="email" class="mt-1 block w-full" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! auth()->user()->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-gray-800 dark:text-gray-200">
                        {{ __('Your email address is unverified.') }}

                        <button wire:click.prevent="sendVerification" class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-green-600 dark:text-green-400">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div>
            <x-input-label for="timezone" :value="__('Time zone')" />

            <div class="mt-1 flex gap-2" x-data>
                <select id="timezone" wire:model="timezone"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 sm:text-sm">
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
                        class="shrink-0 rounded-md bg-gray-200 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
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
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            <x-action-message class="me-3" on="profile-updated">
                {{ __('Saved.') }}
            </x-action-message>
        </div>
    </form>
</section>
