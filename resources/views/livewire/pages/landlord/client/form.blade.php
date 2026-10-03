<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ $client?->exists ? __('Edit Client Wara') : __('Client Wara Baru') }}
            </h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('Petakan tujuan layanan ke device, dan daftarkan endpoint webhook client.') }}
            </p>
        </div>

        <a href="{{ route('wara.client.index') }}"
            class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
            {{ __('Kembali ke daftar') }}
        </a>
    </div>

    {{-- Info penting: kenapa penugasan tidak lagi di halaman Device --}}
    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
        {{ __('Penugasan tujuan layanan milik CLIENT, bukan milik device. Satu device boleh dipakai banyak client, jadi setiap client punya peta sendiri di sini.') }}
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Identitas client --}}
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h2 class="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Identitas Client') }}</h2>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Nama') }}
                    </label>
                    <input id="name" type="text" wire:model="name"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                    @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Tipe') }}
                    </label>
                    <select id="type" wire:model="type"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        @foreach (\Bale\Wara\Models\WaraClient::TYPES as $t)
                            <option value="{{ $t }}">{{ $t }}</option>
                        @endforeach
                    </select>
                    @error('type')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- 🔴 Hanya untuk client tipe `token`. Client token tanpa api
                 token akan menolak SEMUA request dengan 403 yang tidak bisa
                 dibaca pemanggil, jadi ikatannya harus di form ini. --}}
            @if ($type === \Bale\Wara\Models\WaraClient::TYPE_TOKEN)
                <div class="mt-4">
                    <label for="apiTokenId" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('API Token') }}
                    </label>
                    <select id="apiTokenId" wire:model="apiTokenId"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <option value="">{{ __('— belum dipilih —') }}</option>
                        @foreach ($apiTokens as $token)
                            <option value="{{ $token['id'] }}" @disabled(! $token['available'])>
                                {{ $token['label'] }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Token yang sudah dipakai client lain tidak bisa dipilih lagi - satu token hanya boleh memetakan ke satu client.') }}
                    </p>
                    @error('apiTokenId')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            <label class="mt-4 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" wire:model="is_active"
                    class="rounded border-gray-300 text-indigo-600 dark:border-gray-700 dark:bg-gray-800">
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
                <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    <p class="font-medium">{{ __('Client ini belum bisa melayani traffic:') }}</p>
                    <ul class="mt-1 list-inside list-disc space-y-0.5">
                        @foreach ($client->blockingReadinessIssues() as $issue)
                            <li>{{ $issue['message'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- Route: purpose -> device --}}
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Peta Purpose ke Device') }}
                </h2>
                <button type="button" wire:click="addRoute"
                    class="rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                    {{ __('Tambah Route') }}
                </button>
            </div>

            @error('routes')
                <p class="mb-3 text-sm text-red-600">{{ $message }}</p>
            @enderror

            @if ($routes === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Belum ada route. Tanpa route, client tidak bisa mengirim pesan sama sekali.') }}
                </p>
            @endif

            <div class="space-y-3">
                @foreach ($routes as $i => $route)
                    <div wire:key="route-row-{{ $i }}"
                        class="flex flex-wrap items-end gap-3 rounded-md border border-gray-200 p-3 dark:border-gray-800">
                        <div class="w-40">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Purpose') }}</label>
                            <input type="text" wire:model="routes.{{ $i }}.purpose"
                                placeholder="notifikasi"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            @error('routes.'.$i.'.purpose')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="min-w-56 flex-1">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Device') }}</label>
                            <select wire:model="routes.{{ $i }}.device_id"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                                @foreach ($devices as $d)
                                    <option value="{{ $d['device_id'] }}">
                                        {{ $d['label'] }}{{ $d['ready'] ? '' : ' — belum siap' }}
                                    </option>
                                @endforeach
                            </select>
                            @error('routes.'.$i.'.device_id')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <label class="flex items-center gap-1.5 text-xs text-gray-700 dark:text-gray-300">
                            <input type="checkbox" wire:model="routes.{{ $i }}.outbound_enabled"
                                class="rounded border-gray-300 text-indigo-600 dark:border-gray-700 dark:bg-gray-800">
                            {{ __('Kirim') }}
                        </label>

                        <label class="flex items-center gap-1.5 text-xs text-gray-700 dark:text-gray-300">
                            <input type="checkbox" wire:model="routes.{{ $i }}.inbound_webhook_enabled"
                                class="rounded border-gray-300 text-indigo-600 dark:border-gray-700 dark:bg-gray-800">
                            {{ __('Webhook') }}
                        </label>

                        <button type="button" wire:click="removeRoute({{ $i }})"
                            class="text-xs text-red-600 hover:text-red-800">
                            {{ __('Hapus') }}
                        </button>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Endpoint webhook --}}
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Endpoint Webhook') }}
                </h2>
                <button type="button" wire:click="showEndpointCreator"
                    class="rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                    {{ __('Tambah Endpoint') }}
                </button>
            </div>

            @if ($endpoints === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Belum ada endpoint. Client tidak akan menerima event masuk.') }}
                </p>
            @endif

            <div class="space-y-2">
                @foreach ($endpoints as $e)
                    <div wire:key="endpoint-{{ $e['id'] }}"
                        class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-gray-200 px-3 py-2 text-sm dark:border-gray-800">
                        <div class="min-w-0">
                            <span class="font-mono text-gray-800 dark:text-gray-200">{{ $e['url'] }}</span>
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
                                <span class="inline-flex items-center rounded-md bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/20 dark:text-green-400">
                                    {{ __('aktif') }}
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                    {{ __('nonaktif') }}
                                </span>
                            @endif
                            <button type="button" wire:click="removeEndpoint('{{ $e['id'] }}')"
                                wire:confirm="{{ __('Hapus endpoint ini?') }}"
                                class="text-xs text-red-600 hover:text-red-800">
                                {{ __('Hapus') }}
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($showEndpointForm)
                <div class="mt-4 space-y-3 rounded-md border border-gray-200 p-3 dark:border-gray-800">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('URL endpoint') }}</label>
                        <input type="url" wire:model="endpointUrl" placeholder="https://contoh.go.id/webhook/wara"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('https wajib, kecuali localhost. http:// ke host lain ditolak sebagai perlindungan SSRF.') }}
                        </p>
                        @error('endpointUrl')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Secret') }}</label>
                        <input type="password" wire:model="endpointSecret"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Minimal :count karakter. Disimpan terenkripsi dan tidak pernah ditampilkan lagi.', ['count' => \Bale\Wara\Models\WaraWebhookEndpoint::MIN_SECRET_LENGTH]) }}
                        </p>
                        @error('endpointSecret')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Event') }}</label>
                        <input type="text" wire:model="endpointEvents" placeholder="message, message.ack"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Pisahkan dengan koma. Kosong berarti terima semua event.') }}
                        </p>
                        @error('endpointEvents')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model="endpointEnabled"
                            class="rounded border-gray-300 text-indigo-600 dark:border-gray-700 dark:bg-gray-800">
                        {{ __('Aktifkan endpoint ini') }}
                    </label>

                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Endpoint disimpan saat Anda menekan Simpan di bawah.') }}
                    </p>
                </div>
            @endif
        </div>

        <div class="flex justify-end">
            <button type="submit"
                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                {{ __('Simpan') }}
            </button>
        </div>
    </form>
</div>