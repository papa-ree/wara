<div class="space-y-6">
    <x-core::breadcrumb
        :items="[['label' => __('Wara'), 'route' => 'wara.device.index']]"
        :active="__('Analitik')" />

    {{-- Header — menerima filter read-only + list device/client untuk dropdown --}}
    <livewire:wara.pages.landlord.analytics.section.analytics-header
        :filter="$this->filter"
        :devices="$devices"
        :clients="$clients" />

    {{--
        Skeleton loading — tampil saat selectRange / setCustomRange / setFilters sedang
        diproses oleh Index. wire:target harus cocok dengan nama method listener di Index.php.
    --}}
    <div wire:loading wire:target="selectRange,setCustomRange,setFilters" class="space-y-6">
        {{-- Skeleton: 4 kartu summary --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @for($i = 0; $i < 4; $i++)
                <div class="rounded-xl border border-gray-100 bg-white p-5 shadow-sm dark:border-white/[0.06] dark:bg-gray-900">
                    <div class="flex items-start justify-between gap-3">
                        <div class="h-11 w-11 animate-pulse rounded-xl bg-gray-100 dark:bg-gray-800"></div>
                        <div class="h-5 w-14 animate-pulse rounded-full bg-gray-100 dark:bg-gray-800"></div>
                    </div>
                    <div class="mt-4 space-y-2">
                        <div class="h-8 w-24 animate-pulse rounded-lg bg-gray-100 dark:bg-gray-800"></div>
                        <div class="h-3 w-28 animate-pulse rounded bg-gray-100 dark:bg-gray-800"></div>
                        <div class="h-3 w-20 animate-pulse rounded bg-gray-100 dark:bg-gray-800"></div>
                    </div>
                </div>
            @endfor
        </div>

        {{-- Skeleton: line chart funnel --}}
        <div class="rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900">
            <div class="flex items-center gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06]">
                <div class="h-9 w-9 animate-pulse rounded-xl bg-gray-100 dark:bg-gray-800"></div>
                <div class="space-y-1.5">
                    <div class="h-3.5 w-32 animate-pulse rounded bg-gray-100 dark:bg-gray-800"></div>
                    <div class="h-3 w-48 animate-pulse rounded bg-gray-100 dark:bg-gray-800"></div>
                </div>
            </div>
            <div class="p-5">
                {{-- Fake chart bars --}}
                <div class="flex h-64 items-end gap-1">
                    @foreach([40, 65, 30, 80, 55, 90, 45, 70, 35, 85, 60, 75, 40, 95, 50, 70, 45, 80, 60, 35, 90, 55, 70, 40] as $h)
                        <div class="flex-1 animate-pulse rounded-t bg-gray-100 dark:bg-gray-800"
                             style="height: {{ $h }}%"></div>
                    @endforeach
                </div>
                <div class="mt-3 flex justify-center gap-4">
                    @for($i = 0; $i < 5; $i++)
                        <div class="h-3 w-16 animate-pulse rounded bg-gray-100 dark:bg-gray-800"></div>
                    @endfor
                </div>
            </div>
        </div>

        {{-- Skeleton: 3 breakdown cards --}}
        @for($c = 0; $c < 3; $c++)
            <div class="rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900">
                <div class="flex items-center gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06]">
                    <div class="h-9 w-9 animate-pulse rounded-xl bg-gray-100 dark:bg-gray-800"></div>
                    <div class="h-3.5 w-40 animate-pulse rounded bg-gray-100 dark:bg-gray-800"></div>
                </div>
                <ul class="divide-y divide-gray-100 px-5 dark:divide-white/[0.06]">
                    @for($r = 0; $r < 4; $r++)
                        <li class="flex items-center justify-between py-3.5">
                            <div class="h-3.5 w-36 animate-pulse rounded bg-gray-100 dark:bg-gray-800"></div>
                            <div class="flex gap-1.5">
                                <div class="h-6 w-16 animate-pulse rounded-lg bg-gray-100 dark:bg-gray-800"></div>
                                <div class="h-6 w-16 animate-pulse rounded-lg bg-gray-100 dark:bg-gray-800"></div>
                            </div>
                        </li>
                    @endfor
                </ul>
            </div>
        @endfor
    </div>

    {{-- Konten aktual — disembunyikan saat loading --}}
    <div wire:loading.remove wire:target="selectRange,setCustomRange,setFilters">
        <div class="space-y-6">
            {{--
                🔴 wire:key berubah setiap kali filter berubah (via filterKey()).
                Ini memaksa Livewire me-remount semua child sehingga render() dijalankan
                ulang dengan WaraFilter baru.
            --}}
            <livewire:wara.pages.landlord.analytics.section.analytics-summary
                :filter="$this->filter"
                :wire:key="'summary-'.$this->filterKey()" />

            <livewire:wara.pages.landlord.analytics.section.analytics-funnel
                :filter="$this->filter"
                :wire:key="'funnel-'.$this->filterKey()" />

            <livewire:wara.pages.landlord.analytics.section.analytics-breakdown
                :filter="$this->filter"
                :wire:key="'breakdown-'.$this->filterKey()" />
        </div>
    </div>
</div>
