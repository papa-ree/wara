<div>
    <x-core::breadcrumb
        :items="[['label' => __('Device WhatsApp'), 'route' => 'wara.device.index']]"
        :active="__('Log Pesan')" />

    <div class="mt-6">
        <livewire:wara.pages.landlord.log.section.log-header />

        <div class="mt-8">
            <livewire:wara.pages.landlord.log.section.log-table />
        </div>
    </div>
</div>
