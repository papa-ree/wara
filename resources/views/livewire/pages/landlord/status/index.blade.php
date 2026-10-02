<div>
    <x-core::breadcrumb
        :items="[['label' => __('Device WhatsApp'), 'route' => 'wara.device.index']]"
        :active="__('Status')" />

    <div class="mt-6">
        {{--
            Judul, subjudul, dan tanggal pembaruan dirender oleh komponennya
            sendiri. Halaman ini sengaja tidak membuat header sendiri supaya
            judul tidak tampil dua kali.
        --}}
        <x-core::checklist :data="$status" />
    </div>
</div>
