<div>
    <x-core::breadcrumb
        :items="[['label' => __('Wara'), 'route' => 'wara.device.index']]"
        :active="__('Analitik')" />

    <div class="mt-6">
        <livewire:wara.pages.landlord.analytics.section.analytics-header :range="$range" />

        <div class="mt-8">
            <livewire:wara.pages.landlord.analytics.section.analytics-summary :range="$range" />
        </div>

        <div class="mt-6">
            <livewire:wara.pages.landlord.analytics.section.analytics-funnel :range="$range" />
        </div>

        <div class="mt-6">
            <livewire:wara.pages.landlord.analytics.section.analytics-breakdown :range="$range" />
        </div>
    </div>
</div>