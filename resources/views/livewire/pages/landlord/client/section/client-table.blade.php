<div>
    <livewire:core-shared-components::data-table
        :model="\Bale\Wara\Models\WaraClient::class"
        :with="['routes']"
        :columns="[
            ['key' => 'name', 'label' => __('Client'), 'sortable' => true],
            ['key' => 'type', 'label' => __('Tipe'), 'sortable' => true, 'hidden' => 'md'],
            [
                // 🔴 Bukan kolom database - `wara_clients` tidak punya kolom
                // `routes` atau `readiness`. Keduanya dihitung dari relasi dan
                // dari `blockingReadinessIssues()`. Sorting kliknya sengaja
                // dimatikan: tidak ada kolom nyata untuk diurutkan, dan
                // `orderBy('routes')` akan jadi error SQL yang jauh lebih
                // sulit dibaca daripada eager loading yang gagal.
                'key' => 'routes',
                'label' => __('Peta Purpose ke Device'),
                'sortable' => false,
            ],
            [
                'key' => 'readiness',
                'label' => __('Kesiapan'),
                'hidden' => 'lg',
                'sortable' => false,
            ],
            ['key' => 'created_at', 'label' => __('Dibuat'), 'sortable' => true, 'hidden' => 'xl'],
        ]"
        :searchable="['name', 'type']"
        :sort-field="'created_at'"
        :sort-direction="'desc'"
        :per-page="15"
        row-view="wara::livewire.pages.landlord.client.section.client-row"
        card-view="wara::livewire.pages.landlord.client.section.client-card" />
</div>
