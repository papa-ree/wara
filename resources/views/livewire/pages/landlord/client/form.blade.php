<div class="space-y-6">
    <x-core::breadcrumb
        :items="[['label' => __('Client Wara'), 'route' => 'wara.client.index']]"
        :active="$client?->exists ? __('Edit Client') : __('Client Baru')" />

    <x-wara::hero
        :title="$client?->exists ? __('Edit Client Wara') : __('Client Wara Baru')"
        :subtitle="__('Petakan tujuan layanan ke device, dan daftarkan endpoint webhook client.')"
        icon="users"
        :badge="__('Client')">
        <x-slot name="meta">
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-route class="size-3.5" />
                {{ __('Purpose ke device') }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-webhook class="size-3.5" />
                {{ __('Endpoint webhook') }}
            </span>
        </x-slot>

        <x-slot name="actions">
            <a href="{{ route('wara.client.index') }}"
               class="inline-flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-sm font-semibold text-white ring-1 ring-white/20 backdrop-blur-sm transition hover:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70">
                <x-lucide-arrow-left class="size-4" />
                {{ __('Kembali ke daftar') }}
            </a>
        </x-slot>
    </x-wara::hero>

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
        <x-wara::card
            :title="__('Identitas Client')"
            :subtitle="__('Nama, tipe, dan token yang mengikat client.')"
            icon="user-round"
            accent="teal">
            <div x-data="{ type: @js(old('type', $type)) }">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <x-core::label :value="__('Nama')" for="name" />
                        <x-core::input id="name" type="text" name="name" value="{{ old('name', $name) }}" class="focus:!border-teal-500 focus:!ring-teal-500" />
                        <x-core::input-error for="name" class="mt-1" />
                    </div>

                    <div>
                        <x-core::label :value="__('Tipe')" for="type" />
                        <select id="type" name="type" x-model="type"
                            class="block w-full py-3 px-4 text-gray-900 placeholder-gray-500 transition-all duration-200 bg-white border border-gray-300 form-input rounded-xl dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent">
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
                    class="block w-full py-3 px-4 text-gray-900 placeholder-gray-500 transition-all duration-200 bg-white border border-gray-300 form-input rounded-xl dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent">
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
                <x-wara::checkbox name="is_active" value="1" :checked="(bool) old('is_active', $is_active)" />
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
        </x-wara::card>

        {{-- Route: purpose -> device --}}
        <x-wara::card
            :title="__('Peta Purpose ke Device')"
            :subtitle="__('Setiap client punya peta sendiri; satu device boleh dipakai banyak client.')"
            icon="route"
            accent="emerald">
            <x-slot name="header">
                <button type="button"
                        onclick="addRouteRow()"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-teal-200 bg-white px-3 py-1.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-50 dark:border-teal-800/40 dark:bg-gray-900 dark:text-teal-300 dark:hover:bg-teal-900/20">
                    <x-lucide-plus class="size-3.5" />
                    {{ __('Tambah Route') }}
                </button>
            </x-slot>

            <x-core::input-error for="routes" class="mb-3" />

            @if ($formRows === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Belum ada route. Tanpa route, client tidak bisa mengirim pesan sama sekali.') }}
                </p>
            @endif

            <div id="routes-container" class="space-y-3">
                @foreach ($formRows as $i => $route)
                    <div data-route-row
                        class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-100 bg-gray-50/40 p-3 dark:border-white/[0.06] dark:bg-gray-900/40">
                        <input type="hidden" name="routes[{{ $i }}][id]" value="{{ $route['id'] ?? '' }}">
                        <div class="w-40">
                            <x-core::label :value="__('Purpose')" />
                            <x-core::input type="text" name="routes[{{ $i }}][purpose]"
                                value="{{ old('routes.'.$i.'.purpose', $route['purpose'] ?? '') }}"
                                placeholder="notifikasi"
                                class="focus:!border-teal-500 focus:!ring-teal-500" />
                            <x-core::input-error :for="'routes.'.$i.'.purpose'" class="mt-1" />
                        </div>

                        <div class="min-w-56 flex-1">
                            <x-core::label :value="__('Device')" />
                            <select name="routes[{{ $i }}][device_id]"
                                class="block w-full py-3 px-4 text-gray-900 placeholder-gray-500 transition-all duration-200 bg-white border border-gray-300 form-input rounded-xl dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent">
                                @foreach ($devices as $d)
                                    <option value="{{ $d['device_id'] }}" @selected((string) old('routes.'.$i.'.device_id', $route['device_id'] ?? '') === (string) $d['device_id'])>
                                        {{ $d['label'] }}{{ $d['ready'] ? '' : ' — belum siap' }}
                                    </option>
                                @endforeach
                            </select>
                            <x-core::input-error :for="'routes.'.$i.'.device_id'" class="mt-1" />
                        </div>

                        <label class="flex items-center gap-1.5 text-xs text-gray-700 dark:text-gray-300">
                            <x-wara::checkbox name="routes[{{ $i }}][outbound_enabled]" value="1"
                                :checked="(bool) old('routes.'.$i.'.outbound_enabled', $route['outbound_enabled'] ?? false)" />
                            {{ __('Kirim') }}
                        </label>

                        <label class="flex items-center gap-1.5 text-xs text-gray-700 dark:text-gray-300">
                            <x-wara::checkbox name="routes[{{ $i }}][inbound_webhook_enabled]" value="1"
                                :checked="(bool) old('routes.'.$i.'.inbound_webhook_enabled', $route['inbound_webhook_enabled'] ?? false)" />
                            {{ __('Webhook') }}
                        </label>

                        <button type="button"
                                onclick="this.closest('[data-route-row]').remove()"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-2 text-sm font-medium text-rose-700 transition hover:bg-rose-50 dark:border-rose-800/40 dark:text-rose-400 dark:hover:bg-rose-900/20">
                            <x-lucide-trash-2 class="size-3.5" />
                            {{ __('Hapus') }}
                        </button>
                    </div>
                @endforeach
            </div>
        </x-wara::card>

        {{-- Endpoint webhook --}}
        <x-wara::card
            :title="__('Endpoint Webhook')"
            :subtitle="__('Terima event masuk dari gateway di endpoint milik client.')"
            icon="webhook"
            accent="sky"
            x-data="{ showEndpoint: false }">
            <x-slot name="header">
                <button type="button"
                        x-on:click="showEndpoint = true"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-sky-200 bg-white px-3 py-1.5 text-xs font-semibold text-sky-700 transition hover:bg-sky-50 dark:border-sky-800/40 dark:bg-gray-900 dark:text-sky-300 dark:hover:bg-sky-900/20">
                    <x-lucide-plus class="size-3.5" />
                    {{ __('Tambah Endpoint') }}
                </button>
            </x-slot>

            @if ($endpoints === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Belum ada endpoint. Client tidak akan menerima event masuk.') }}
                </p>
            @endif

            <div class="space-y-2">
                @foreach ($endpoints as $e)
                    <div wire:key="endpoint-{{ $e['id'] }}"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-100 bg-gray-50/40 p-3 dark:border-white/[0.06] dark:bg-gray-900/40">
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
                            <button type="button"
                                    onclick="deleteEndpoint(this.form, @js($e['id']))"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-medium text-rose-700 transition hover:bg-rose-50 dark:border-rose-800/40 dark:text-rose-400 dark:hover:bg-rose-900/20">
                                <x-lucide-trash-2 class="size-3.5" />
                                {{ __('Hapus') }}
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div x-show="showEndpoint" x-cloak class="mt-4 space-y-3 rounded-xl border border-gray-100 bg-gray-50/40 p-3 dark:border-white/[0.06] dark:bg-gray-900/40">
                <div>
                    <x-core::label :value="__('URL endpoint')" />
                    <x-core::input type="url" name="endpointUrl"
                        value="{{ old('endpointUrl') }}"
                        placeholder="https://contoh.go.id/webhook/wara"
                        class="focus:!border-teal-500 focus:!ring-teal-500" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('https wajib, kecuali localhost. http:// ke host lain ditolak sebagai perlindungan SSRF.') }}
                    </p>
                    <x-core::input-error for="endpointUrl" class="mt-1" />
                </div>

                <div>
                    <x-core::label :value="__('Secret')" />
                    <x-core::input type="password" name="endpointSecret" value="{{ old('endpointSecret') }}" class="focus:!border-teal-500 focus:!ring-teal-500" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Minimal :count karakter. Disimpan terenkripsi dan tidak pernah ditampilkan lagi.', ['count' => \Bale\Wara\Models\WaraWebhookEndpoint::MIN_SECRET_LENGTH]) }}
                    </p>
                    <x-core::input-error for="endpointSecret" class="mt-1" />
                </div>

                <div>
                    <x-core::label :value="__('Event')" />
                    <x-core::input type="text" name="endpointEvents"
                        value="{{ old('endpointEvents') }}"
                        placeholder="message, message.ack"
                        class="focus:!border-teal-500 focus:!ring-teal-500" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Pisahkan dengan koma. Kosong berarti terima semua event.') }}
                    </p>
                    <x-core::input-error for="endpointEvents" class="mt-1" />
                </div>

                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <x-wara::checkbox name="endpointEnabled" value="1" :checked="(bool) old('endpointEnabled', false)" />
                    {{ __('Aktifkan endpoint ini') }}
                </label>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Endpoint disimpan saat Anda menekan Simpan di bawah.') }}
                </p>
            </div>
        </x-wara::card>

        {{-- Branding pesan: teks statis per client yang ditempelkan
             `WaraManager::sendMessage()` ke semua pesan keluar client ini. --}}
        <x-wara::card
            :title="__('Branding Pesan')"
            :subtitle="__('Header/footer global untuk SEMUA pesan yang dikirim atas nama client ini, dipisah dari isi pesan dengan dua baris kosong.')"
            icon="megaphone"
            accent="amber">
            <div class="space-y-6">
                <div x-data="{ headerEnabled: @js((bool) old('headerEnabled', $headerEnabled)) }">
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <x-wara::checkbox id="branding-header-enable" name="headerEnabled" value="1"
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
                        <x-core::textarea id="branding-header-text" name="headerText" :rows="3" class="focus:!border-teal-500 focus:!ring-teal-500"
                            placeholder="{{ __('Salam dari Portal Resmi ...') }}">{{ old('headerText', $headerText) }}</x-core::textarea>
                        <x-core::input-error for="headerText" class="mt-1" />
                    </div>
                </div>

                <div x-data="{ footerEnabled: @js((bool) old('footerEnabled', $footerEnabled)) }">
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <x-wara::checkbox id="branding-footer-enable" name="footerEnabled" value="1"
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
                        <x-core::textarea id="branding-footer-text" name="footerText" :rows="3" class="focus:!border-teal-500 focus:!ring-teal-500"
                            placeholder="{{ __('Balas STOP untuk berhenti ...') }}">{{ old('footerText', $footerText) }}</x-core::textarea>
                        <x-core::input-error for="footerText" class="mt-1" />
                    </div>
                </div>
            </div>
        </x-wara::card>

        <div class="flex justify-end">
            <button type="submit"
                    class="inline-flex items-center gap-2 rounded-xl bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-teal-500/25 transition hover:bg-teal-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900">
                <x-lucide-save class="size-4" />
                {{ __('Simpan') }}
            </button>
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
            row.className = 'flex flex-wrap items-end gap-3 rounded-xl border border-gray-100 bg-gray-50/40 p-3 dark:border-white/[0.06] dark:bg-gray-900/40';
            row.innerHTML =
                '<input type="hidden" name="routes[' + index + '][id]" value="">'
                + '<div class="w-40">'
                +   '<label class="block text-xs font-semibold text-gray-700 mb-1.5 dark:text-gray-300">Purpose</label>'
                +   '<input type="text" name="routes[' + index + '][purpose]" value="" placeholder="notifikasi" '
                +   'class="block w-full py-3 px-4 text-gray-900 placeholder-gray-500 transition-all duration-200 bg-white border border-gray-300 form-input rounded-xl dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent">'
                + '</div>'
                + '<div class="min-w-56 flex-1">'
                +   '<label class="block text-xs font-semibold text-gray-700 mb-1.5 dark:text-gray-300">Device</label>'
                +   '<select name="routes[' + index + '][device_id]" '
                +   'class="block w-full py-3 px-4 text-gray-900 transition-all duration-200 bg-white border border-gray-300 form-input rounded-xl dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent">'
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
                +   'class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-rose-200 px-3 py-2 text-sm font-medium text-rose-700 transition hover:bg-rose-50 dark:border-rose-800/40 dark:text-rose-400 dark:hover:bg-rose-900/20">Hapus</button>';

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