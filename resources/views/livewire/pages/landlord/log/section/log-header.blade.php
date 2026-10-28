<div>
    <x-wara::hero
        :title="__('Log Pesan')"
        :subtitle="__('Riwayat pesan masuk dan keluar beserta device pengirimnya.')"
        icon="scroll-text"
        :badge="__('Read-only')"
        badge-color="sky">
        <x-slot name="meta">
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-lock class="size-3.5" />
                {{ __('Log tidak dapat dihapus dari sini') }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-shield-check class="size-3.5" />
                {{ __('Isi pesan disamarkan bila mengandung OTP') }}
            </span>
        </x-slot>

        <x-slot name="actions">
            <a href="{{ route('wara.analytics.index') }}"
               class="inline-flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-sm font-semibold text-white ring-1 ring-white/20 backdrop-blur-sm transition hover:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70">
                <x-lucide-gauge class="size-4" />
                {{ __('Lihat Analitik') }}
            </a>
        </x-slot>
    </x-wara::hero>
</div>
