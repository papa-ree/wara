<div class="space-y-6">
    <x-core::breadcrumb
        :items="[]"
        :active="__('Client Wara')" />

    <x-wara::hero
        :title="__('Client Wara')"
        :subtitle="__('Siapa yang boleh memakai gateway, dan device mana yang dipakai untuk tiap tujuan layanan.')"
        icon="users"
        :badge="__('Gateway')">
        <x-slot name="meta">
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-smartphone class="size-3.5" />
                {{ __('Peta purpose ke device') }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-webhook class="size-3.5" />
                {{ __('Endpoint webhook') }}
            </span>
        </x-slot>

        <x-slot name="actions">
            <a href="{{ route('wara.client.create') }}"
               class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-teal-800 shadow-sm transition hover:bg-teal-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70">
                <x-lucide-plus class="size-4" />
                {{ __('Client Baru') }}
            </a>
        </x-slot>
    </x-wara::hero>

    <livewire:wara.pages.landlord.client.section.client-table />
</div>
