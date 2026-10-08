<div class="space-y-6">
    <x-core::breadcrumb
        :items="[]"
        :active="__('Client Wara')" />

    <x-core::page-header
        :title="__('Client Wara')"
        :subtitle="__('Siapa yang boleh memakai gateway, dan device mana yang dipakai untuk tiap tujuan layanan.')">
        <x-slot name="action">
            <x-core::button
                link
                href="{{ route('wara.client.create') }}"
                label="{{ __('Client Baru') }}">
                <x-slot name="icon">
                    <x-lucide-plus class="w-4 h-4" />
                </x-slot>
            </x-core::button>
        </x-slot>
    </x-core::page-header>

    <livewire:wara.pages.landlord.client.section.client-table />
</div>
