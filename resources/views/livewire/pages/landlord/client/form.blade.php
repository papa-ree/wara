<div class="space-y-6">
    <x-core::breadcrumb
        :items="[['label' => __('Client Wara'), 'route' => 'wara.client.index']]"
        :active="$client?->exists ? __('Edit Client') : __('Client Baru')" />

    <x-core::page-header
        :title="$client?->exists ? __('Edit Client Wara') : __('Client Wara Baru')"
        :subtitle="__('Petakan tujuan layanan ke device, dan daftarkan endpoint webhook client.')">
        <x-slot name="action">
            <x-core::secondary-button
                link
                href="{{ route('wara.client.index') }}"
                label="{{ __('Kembali ke daftar') }}" />
        </x-slot>
    </x-core::page-header>

    {{-- Info penting: kenapa penugasan tidak lagi di halaman Device --}}
    <x-core::alert :title="__('Penugasan milik client')">
        {{ __('Penugasan tujuan layanan milik CLIENT, bukan milik device. Satu device boleh dipakai banyak client, jadi setiap client punya peta sendiri di sini.') }}
    </x-core::alert>

    @php
        // Baris yang dimuat ulang saat validasi gagal: ambil nilai yang baru
        // dikirim (`old`), bukan nilai lama dari database, supaya admin tidak
        // kehilangan isian.
        $formRows = old('routes', $routes);
    @endphp

    {{-- 🔴 Form HTML biasa, BUKAN Livewire. `ClientController` menyimpan semua
         field; tombol Simpan mem-POST nilai form apa adanya (mekanisme HTML,
         bukan aksi Livewire) sehingga bekerja di browser mana pun. --}}
    <form method="POST"
        action="{{ $client?->exists ? route('wara.client.update', $client) : route('wara.client.store') }}"
        class="space-y-6">
        @csrf
        @if ($client?->exists)
            @method('PUT')
        @endif

        {{-- Tombol "Hapus" tiap endpoint mengisi field ini lalu submit ulang
             form yang sama (tanpa form bersarang), ditangani di
             `ClientController::update()`. --}}
        <input type="hidden" name="delete_endpoint" value="">

        {{-- Identitas client --}}
        <x-core::page-container>
            <h2 class="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Identitas Client') }}</h2>

            <div x-data="{ type: @js(old('type', $type)) }">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <x-core::label :value="__('Nama')" for="name" />
                        <x-core::input id="name" type="text" name="name" value="{{ old('name', $name) }}" />
                        <x-core::input-error for="name" class="mt-1" />
                    </div>

                    <div>
                        <x-core::label :value="__('Tipe')" for="type" />
                        <select id="type" name="type" x-model="type"
                            class="block w-full py-3 px-4 text-gray-900 placeholder-gray-500 transition-all duration-200 bg-white border border-gray-300 form-input rounded-xl dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                            @foreach (\Bale\Wara\Models\WaraClient::TYPES as $t)
                                <option value="{{ $t }}" @selected(old('type', $type) === $t)>{{ $t }}</option>
                            @endforeach
                        </select>
                        <x-core::input-error for="type" class="mt-1" />
                    </div>
                </div>

            {{-- 🔴 Hanya untuk client tipe `token`. Client token tanpa api
                 token akan menolak SEMUA request dengan 403 yang tidak bisa
                 dibaca pemanggil, jadi ikatannya harus di form ini. --}}
            <div class="mt-4" x-cloak x-show="type === @js(\Bale\Wara\Models\WaraClient::TYPE_TOKEN)">
                <x-core::label :value="__('API Token')" for="apiTokenId" />
                <select id="apiTokenId" name="apiTokenId"
                    class="block w-full py-3 px-4 text-gray-900 placeholder-gray-500 transition-all duration-200 bg-white border border-gray-300 form-input rounded-xl dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    <option value="">{{ __('— belum dipilih —') }}</option>
                    @foreach ($apiTokens as $token)
                        <option value="{{ $token['id'] }}" @disabled(! $token['available']) @selected((string) old('apiTokenId', $apiTokenId) === (string) $token['id'])>
                            {{ $token['label'] }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Token yang sudah dipakai client lain tidak bisa dipilih lagi - satu token hanya boleh memetakan ke satu client.') }}
                </p>
                <x-core::input-error for="apiTokenId" class="mt-1" />
            </div>
            </div>

            <label class="mt-4 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <x-core::checkbox name="is_active" value="1" :checked="(bool) old('is_active', $is_active)" />
                <span>
                    {{ __('Aktif') }}
                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Client nonaktif berhenti melayani semua traffic, walau route-nya masih ada.') }}
                    </span>
                </span>
            </label>

            {{-- Diagnosis: apa yang MASIH menghalangi client ini melayani
                 traffic. Bedanya dengan peringatan di atas: yang ini kondisi
                 nyata dari database, bukan penjelasan umum. --}}
            @if ($client?->exists && $client->blockingReadinessIssues() !== [])
                <div class="mt-4">
                    <x-core::alert variant="warning" :title="__('Client ini belum bisa melayani traffic:')">
                        <ul class="list-inside list-disc space-y-0.5">
                            @foreach ($client->blockingReadinessIssues() as $issue)
                                <li>{{ $issue['message'] }}</li>
                            @endforeach
                        </ul>
                    </x-core::alert>
                </div>
            @endif
        </x-core::page-container>

        {{-- Route: purpose -> device --}}
        <x-core::page-container>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Peta Purpose ke Device') }}
                </h2>
                <x-core::secondary-button
                    type="button"
                    onclick="addRouteRow()"
                    label="{{ __('Tambah Route') }}" />
            </div>

            <x-core::input-error for="routes" class="mb-3" />

            @if ($formRows === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Belum ada route. Tanpa route, client tidak bisa mengirim pesan sama sekali.') }}
                </p>
            @endif

            <div id="routes-container" class="space-y-3">
                @foreach ($formRows as $i => $route)
                    <div data-route-row
                        class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                        <input type="hidden" name="routes[{{ $i }}][id]" value="{{ $route['id'] ?? '' }}">
                        <div class="w-40">
                            <x-core::label :value="__('Purpose')" />
                            <x-core::input type="text" name="routes[{{ $i }}][purpose]"
                                value="{{ old('routes.'.$i.'.purpose', $route['purpose'] ?? '') }}"
                                placeholder="notifikasi" />
                            <x-core::input-error :for="'routes.'.$i.'.purpose'" class="mt-1" />
                        </div>

                        <div class="min-w-56 flex-1">
                            <x-core::label :value="__('Device')" />
                            <select name="routes[{{ $i }}][device_id]"
                                class="block w-full py-3 px-4 text-gray-900 placeholder-gray-500 transition-all duration-200 bg-white border border-gray-300 form-input rounded-xl dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                                @foreach ($devices as $d)
                                    <option value="{{ $d['device_id'] }}" @selected((string) old('routes.'.$i.'.device_id', $route['device_id'] ?? '') === (string) $d['device_id'])>
                                        {{ $d['label'] }}{{ $d['ready'] ? '' : ' — belum siap' }}
                                    </option>
                                @endforeach
                            </select>
                            <x-core::input-error :for="'routes.'.$i.'.device_id'" class="mt-1" />
                        </div>

                        <label class="flex items-center gap-1.5 text-xs text-gray-700 dark:text-gray-300">
                            <x-core::checkbox name="routes[{{ $i }}][outbound_enabled]" value="1"
                                :checked="(bool) old('routes.'.$i.'.outbound_enabled', $route['outbound_enabled'] ?? false)" />
                            {{ __('Kirim') }}
                        </label>

                        <label class="flex items-center gap-1.5 text-xs text-gray-700 dark:text-gray-300">
                            <x-core::checkbox name="routes[{{ $i }}][inbound_webhook_enabled]" value="1"
                                :checked="(bool) old('routes.'.$i.'.inbound_webhook_enabled', $route['inbound_webhook_enabled'] ?? false)" />
                            {{ __('Webhook') }}
                        </label>

                        <x-core::danger-button
                            type="button"
                            onclick="this.closest('[data-route-row]').remove()"
                            label="{{ __('Hapus') }}" />
                    </div>
                @endforeach
            </div>
        </x-core::page-container>

        {{-- Endpoint webhook --}}
        <x-core::page-container x-data="{ showEndpoint: false }">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Endpoint Webhook') }}
                </h2>
                <x-core::secondary-button
                    type="button"
                    x-on:click="showEndpoint = true"
                    label="{{ __('Tambah Endpoint') }}" />
            </div>

            @if ($endpoints === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Belum ada endpoint. Client tidak akan menerima event masuk.') }}
                </p>
            @endif

            <div class="space-y-2">
                @foreach ($endpoints as $e)
                    <div wire:key="endpoint-{{ $e['id'] }}"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                        <div class="min-w-0">
                            <span class="font-mono text-sm text-gray-800 dark:text-gray-200">{{ $e['url'] }}</span>
                            <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ $e['secret_set'] ? __('secret terisi') : __('secret belum') }}
                                @if ($e['events'] !== '')
                                    · {{ $e['events'] }}
                                @else
                                    · {{ __('semua event') }}
                                @endif
                            </span>
                        </div>
                        <div class="flex items-center gap-3">
                            @if ($e['enabled'])
                                <x-core::badge color="emerald" :label="__('aktif')" />
                            @else
                                <x-core::badge color="gray" :label="__('nonaktif')" />
                            @endif
                            <x-core::danger-button
                                type="button"
                                onclick="deleteEndpoint(this.form, @js($e['id']))"
                                label="{{ __('Hapus') }}" />
                        </div>
                    </div>
                @endforeach
            </div>

            <div x-show="showEndpoint" x-cloak class="mt-4 space-y-3 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                <div>
                    <x-core::label :value="__('URL endpoint')" />
                    <x-core::input type="url" name="endpointUrl"
                        value="{{ old('endpointUrl') }}"
                        placeholder="https://contoh.go.id/webhook/wara" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('https wajib, kecuali localhost. http:// ke host lain ditolak sebagai perlindungan SSRF.') }}
                    </p>
                    <x-core::input-error for="endpointUrl" class="mt-1" />
                </div>

                <div>
                    <x-core::label :value="__('Secret')" />
                    <x-core::input type="password" name="endpointSecret" value="{{ old('endpointSecret') }}" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Minimal :count karakter. Disimpan terenkripsi dan tidak pernah ditampilkan lagi.', ['count' => \Bale\Wara\Models\WaraWebhookEndpoint::MIN_SECRET_LENGTH]) }}
                    </p>
                    <x-core::input-error for="endpointSecret" class="mt-1" />
                </div>

                <div>
                    <x-core::label :value="__('Event')" />
                    <x-core::input type="text" name="endpointEvents"
                        value="{{ old('endpointEvents') }}"
                        placeholder="message, message.ack" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Pisahkan dengan koma. Kosong berarti terima semua event.') }}
                    </p>
                    <x-core::input-error for="endpointEvents" class="mt-1" />
                </div>

                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <x-core::checkbox name="endpointEnabled" value="1" :checked="(bool) old('endpointEnabled', false)" />
                    {{ __('Aktifkan endpoint ini') }}
                </label>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Endpoint disimpan saat Anda menekan Simpan di bawah.') }}
                </p>
            </div>
        </x-core::page-container>

        {{-- Branding pesan: teks statis per client yang ditempelkan
             `WaraManager::sendMessage()` ke semua pesan keluar client ini. --}}
        <x-core::page-container>
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ __('Branding Pesan') }}
            </h2>
            <p class="mb-4 mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Header/footer global untuk SEMUA pesan yang dikirim atas nama client ini, dipisah dari isi pesan dengan dua baris kosong.') }}
            </p>

            <div class="space-y-6">
                <div x-data="{ headerEnabled: @js((bool) old('headerEnabled', $headerEnabled)) }">
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <x-core::checkbox id="branding-header-enable" name="headerEnabled" value="1"
                            :checked="(bool) old('headerEnabled', $headerEnabled)"
                            x-on:change="headerEnabled = $el.checked" />
                        <span>
                            {{ __('Aktifkan header') }}
                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Teks muncul di atas isi pesan.') }}
                            </span>
                        </span>
                    </label>

                    <div x-show="headerEnabled" x-cloak class="mt-3">
                        <x-core::label :value="__('Teks header')" />
                        <x-core::textarea id="branding-header-text" name="headerText" :rows="3"
                            placeholder="{{ __('Salam dari Portal Resmi ...') }}">{{ old('headerText', $headerText) }}</x-core::textarea>
                        <x-core::input-error for="headerText" class="mt-1" />
                    </div>
                </div>

                <div x-data="{ footerEnabled: @js((bool) old('footerEnabled', $footerEnabled)) }">
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <x-core::checkbox id="branding-footer-enable" name="footerEnabled" value="1"
                            :checked="(bool) old('footerEnabled', $footerEnabled)"
                            x-on:change="footerEnabled = $el.checked" />
                        <span>
                            {{ __('Aktifkan footer') }}
                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Teks muncul di bawah isi pesan.') }}
                            </span>
                        </span>
                    </label>

                    <div x-show="footerEnabled" x-cloak class="mt-3">
                        <x-core::label :value="__('Teks footer')" />
                        <x-core::textarea id="branding-footer-text" name="footerText" :rows="3"
                            placeholder="{{ __('Balas STOP untuk berhenti ...') }}">{{ old('footerText', $footerText) }}</x-core::textarea>
                        <x-core::input-error for="footerText" class="mt-1" />
                    </div>
                </div>
            </div>
        </x-core::page-container>

        <div class="flex justify-end">
            <x-core::button type="submit" label="{{ __('Simpan') }}" />
        </div>
    </form>

    {{-- Interaksi ringan (tambah/hapus baris) tanpa Livewire: nilai dibaca
         saat Simpan diklik oleh `<form method="POST">` itu sendiri. --}}
    <script>
        window.__waraDevices = @js($devices);

        window.escapeHtml = function (value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        };

        window.addRouteRow = function () {
            const container = document.getElementById('routes-container');
            const index = container.querySelectorAll('[data-route-row]').length;
            const options = window.__waraDevices.map(function (device) {
                const suffix = device.ready ? '' : ' — belum siap';
                return '<option value="' + window.escapeHtml(device.device_id) + '">'
                    + window.escapeHtml(device.label) + suffix
                    + '</option>';
            }).join('');

            const row = document.createElement('div');
            row.setAttribute('data-route-row', '');
            row.className = 'flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 p-3 dark:border-gray-700';
            row.innerHTML =
                '<input type="hidden" name="routes[' + index + '][id]" value="">'
                + '<div class="w-40">'
                +   '<label class="block text-xs font-semibold text-gray-700 mb-1.5 dark:text-gray-300">Purpose</label>'
                +   '<input type="text" name="routes[' + index + '][purpose]" value="" placeholder="notifikasi" '
                +   'class="block w-full py-3 px-4 text-gray-900 placeholder-gray-500 transition-all duration-200 bg-white border border-gray-300 form-input rounded-xl dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent">'
                + '</div>'
                + '<div class="min-w-56 flex-1">'
                +   '<label class="block text-xs font-semibold text-gray-700 mb-1.5 dark:text-gray-300">Device</label>'
                +   '<select name="routes[' + index + '][device_id]" '
                +   'class="block w-full py-3 px-4 text-gray-900 transition-all duration-200 bg-white border border-gray-300 form-input rounded-xl dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent">'
                +     options
                +   '</select>'
                + '</div>'
                + '<label class="flex items-center gap-1.5 text-xs text-gray-700 dark:text-gray-300">'
                +   '<input type="checkbox" name="routes[' + index + '][outbound_enabled]" value="1" checked>'
                +   'Kirim'
                + '</label>'
                + '<label class="flex items-center gap-1.5 text-xs text-gray-700 dark:text-gray-300">'
                +   '<input type="checkbox" name="routes[' + index + '][inbound_webhook_enabled]" value="1">'
                +   'Webhook'
                + '</label>'
                + '<button type="button" onclick="this.closest(\'[data-route-row]\').remove()" '
                +   'class="inline-flex items-center justify-center gap-x-2 px-6 py-3.5 text-sm font-bold transition-all duration-300 rounded-2xl capitalize text-white border border-transparent bg-linear-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 shadow-lg hover:shadow-red-500/40 focus:ring-red-500 hover:scale-[1.02] active:scale-[0.98]">Hapus</button>';

            container.appendChild(row);
        };

        window.deleteEndpoint = function (form, id) {
            if (window.confirm('Hapus endpoint ini?')) {
                form.delete_endpoint.value = id;
                form.submit();
            }
        };
    </script>
</div>