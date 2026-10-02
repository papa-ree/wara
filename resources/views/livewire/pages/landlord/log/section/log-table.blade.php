<div>
    <livewire:core-shared-components::data-table
        model="Bale\Wara\Models\WaraLog"
        rowView="wara::livewire.pages.landlord.log.section.log-row"
        :columns="[
            [
                'key' => 'body',
                'label' => __('Isi Pesan'),
            ],
            [
                'key' => 'device_id',
                'label' => __('Device Pengirim'),
                'hidden' => 'lg',
            ],
            [
                'key' => 'direction',
                'label' => __('Arah'),
                'hidden' => 'sm',
            ],
            [
                'key' => 'status',
                'label' => __('Status'),
                'hidden' => 'md',
            ],
            [
                'key' => 'created_at',
                'label' => __('Waktu'),
                'sortable' => true,
            ],
        ]"
        {{--
            `phone` dan `body` SENGAJA tidak ada di `searchable`: keduanya
            terenkripsi, dan ciphertext bersifat acak sehingga tidak akan
            pernah cocok dengan LIKE. Pencarian per nomor belum tersedia —
            lihat docs/wara/PLAN.md.
        --}}
        :searchable="['message_id', 'device_id']"
        :filterOptions="$filterOptions"
        sortField="created_at"
        sortDirection="desc"
        :perPage="25" />
</div>
