<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Client Wara') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('Siapa yang boleh memakai gateway, dan device mana yang dipakai untuk tiap tujuan layanan.') }}
            </p>
        </div>

        <a href="{{ route('wara.client.create') }}"
            class="flex-none rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
            {{ __('Client Baru') }}
        </a>
    </div>

    <livewire:wara.pages.landlord.client.section.client-table />
</div>
