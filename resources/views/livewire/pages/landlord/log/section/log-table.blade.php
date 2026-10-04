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
                'key' => 'attempt',
                'label' => __('Percobaan'),
                'hidden' => 'xl',
                'sortable' => true,
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

            `session_key` boleh dicari karena plaintext dan ber-index
            (`create_wara_logs_table.php.stub`). Tanpa ini, admin yang sedang
            menelusuri satu sesi login harus membuka tiap baris satu per satu
            hanya untuk menyalin nilainya.
        --}}
        :searchable="['message_id', 'device_id', 'session_key']"
        :filterOptions="$filterOptions"
        sortField="created_at"
        sortDirection="desc"
        :perPage="25" />
</div>
