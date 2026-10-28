<div>
    <x-core::breadcrumb
        :items="[['label' => __('Device WhatsApp'), 'route' => 'wara.device.index']]"
        :active="__('Status')" />

    <div class="mt-6 space-y-6">
        <x-wara::hero
            :title="__('Status Wara')"
            :subtitle="__('Ringkasan kesiapan implementasi, fase yang sudah selesai, dan item yang masih terbuka.')"
            icon="list-checks"
            :badge="__('Status')">
            <x-slot name="meta">
                @if (filled($status['updated_at'] ?? null))
                    <span class="inline-flex items-center gap-1.5">
                        <x-lucide-calendar-check class="size-3.5" />
                        {{ __('Terakhir diperbarui') }} {{ $status['updated_at'] }}
                    </span>
                @endif
            </x-slot>
        </x-wara::hero>

        {{--
            Rincian fase dan item tetap dirender oleh komponen checklist.
            Hero di atas memberi konteks halaman; judul kartu checklist
            sengaja dibiarkan agar sumber status tetap satu.
        --}}
        <x-core::checklist :data="$status" />
    </div>
</div>
