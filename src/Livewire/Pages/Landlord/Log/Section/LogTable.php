<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Log\Section;

use Bale\Wara\Models\WaraSession;
use Illuminate\Support\Collection;
use Livewire\Component;

class LogTable extends Component
{
    /**
     * Device untuk dropdown filter.
     *
     * @var Collection<int, WaraSession>
     */
    public Collection $devices;

    /**
     * Konfigurasi filter untuk data-table.
     *
     * Filter dirender oleh data-table (bukan lewat slot) karena `wire:model`
     * di dalam slot Livewire terikat ke state komponen INDUK, sehingga tidak
     * akan pernah mengubah `activeFilters` milik tabel.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $filterOptions = [];

    public function mount(): void
    {
        $this->devices = WaraSession::query()->orderBy('display_name')->get();

        $this->filterOptions = [
            [
                'key' => 'direction',
                'label' => __('Arah'),
                'allLabel' => __('Semua'),
                'options' => [
                    'out' => __('Keluar'),
                    'in' => __('Masuk'),
                ],
            ],
            [
                'key' => 'device_id',
                'label' => __('Device Pengirim'),
                'allLabel' => __('Semua Device'),
                'options' => $this->devices
                    ->mapWithKeys(fn (WaraSession $device) => [
                        $device->device_id => $device->display_name ?? $device->device_id,
                    ])
                    ->all(),
                // Pesan dari device yang belum ter-sync punya device_id NULL.
                // Nilai 'null' adalah sentinel milik data-table untuk whereNull.
                'nullLabel' => __('Tanpa device'),
            ],
            [
                'key' => 'status',
                'label' => __('Status'),
                'allLabel' => __('Semua Status'),
                'options' => [
                    'accepted' => __('Diterima gateway'),
                    'rejected' => __('Ditolak gateway'),
                    'received' => __('Masuk'),
                    'delivered' => __('Sampai'),
                    'read' => __('Dibaca'),
                    'ignored' => __('Diabaikan'),
                ],
            ],
        ];
    }

    public function render()
    {
        return view('wara::livewire.pages.landlord.log.section.log-table');
    }
}
