<div>
    <livewire:core-shared-components::data-table
        model="Bale\Wara\Models\WaraSession"
        rowView="wara::livewire.pages.landlord.device.section.device-row"
        :with="['routes']"
        :columns="[
            [
                'key' => 'device_id',
                'label' => __('Device'),
                'sortable' => true,
            ],
            [
                // 🔴 BUKAN kolom database. `wara_sessions.purpose` sudah
                // dihapus - penugasan milik (client, purpose). Kolom ini
                // hanya label header; sorting kliknya sengaja dimatikan
                // karena tidak ada kolom `purpose` untuk diurutkan.
                'key' => 'routes',
                'label' => __('Dipakai Client'),
                'hidden' => 'lg',
                'sortable' => false,
            ],
            [
                'key' => 'is_logged_in',
                'label' => __('Status'),
                'hidden' => 'sm',
            ],
            [
                'key' => 'last_seen_at',
                'label' => __('Terakhir terlihat'),
                'hidden' => 'md',
                'sortable' => true,
            ],
            [
                'key' => 'actions',
                'label' => '',
                'sortable' => false,
            ],
        ]"
        :searchable="['device_id', 'display_name']"
        sortField="last_seen_at"
        sortDirection="desc"
        :perPage="20" />
</div>
