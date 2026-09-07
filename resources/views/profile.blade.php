{{-- Settings > Account. The stock Breeze profile page, in this application's shell:
     the same header, the same tab strip and the same cards every other page uses, so
     the account is not the one screen that looks like it came from somewhere else. --}}
<x-app-layout>
    <x-slot name="header">
        Settings
    </x-slot>

    <x-page-tabs group="settings" />

    <div class="mx-auto max-w-3xl space-y-6">
        <div class="rounded-lg border border-gray-700 bg-gray-800 p-6">
            <div class="max-w-xl">
                <livewire:profile.update-profile-information-form />
            </div>
        </div>

        {{-- Where the copier announces itself. Beside the profile rather than under
             Auto-Trade because it is a property of the person being told, not of the
             terminal doing the trading. --}}
        <div class="rounded-lg border border-gray-700 bg-gray-800 p-6">
            <div class="max-w-xl">
                <livewire:profile.telegram-alerts-form />
            </div>
        </div>

        <div class="rounded-lg border border-gray-700 bg-gray-800 p-6">
            <div class="max-w-xl">
                <livewire:profile.update-password-form />
            </div>
        </div>

        <div class="rounded-lg border border-gray-700 bg-gray-800 p-6">
            <livewire:profile.account-security />
        </div>

        <div class="rounded-lg border border-red-500/20 bg-gray-800 p-6">
            <div class="max-w-xl">
                <livewire:profile.delete-user-form />
            </div>
        </div>
    </div>
</x-app-layout>
