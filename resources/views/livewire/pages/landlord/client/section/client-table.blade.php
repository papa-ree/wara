<div>
    <livewire:core-shared-components::data-table
        :model="\Bale\Wara\Models\WaraClient::class"
        :with="['routes']"
        :columns="[
            ['key' => 'name', 'label' => __('Client'), 'sortable' => true],
            ['key' => 'type', 'label' => __('Tipe'), 'sortable' => true, 'hidden' => 'md'],
            ['key' => 'routes', 'label' => __('Peta Purpose ke Device')],
            ['key' => 'readiness', 'label' => __('Kesiapan'), 'hidden' => 'lg'],
            ['key' => 'created_at', 'label' => __('Dibuat'), 'sortable' => true, 'hidden' => 'xl'],
        ]"
        :searchable="['name', 'type']"
        :sort-field="'created_at'"
        :sort-direction="'desc'"
        :per-page="15"
        row-view="wara::livewire.pages.landlord.client.section.client-row"
        card-view="wara::livewire.pages.landlord.client.section.client-card" />
</div>
