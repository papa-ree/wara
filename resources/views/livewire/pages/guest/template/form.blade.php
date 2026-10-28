<div class="mx-auto max-w-6xl space-y-6 p-4 sm:p-6">
    <x-wara::hero
        :title="$template?->exists ? __('Edit Template') : __('Template Baru')"
        :subtitle="__('Template disusun sebagai teks. Tombol akan dikirim sebagai daftar bernomor karena gateway tidak mendukung tombol native.')"
        icon="file-pen-line"
        :badge="__('Guest')">
        <x-slot name="meta">
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-shield-check class="size-3.5" />
                {{ __('Wajib disetujui admin sebelum dipakai') }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-braces class="size-3.5" />
                {{ __('Parameter posisional atau bernama') }}
            </span>
        </x-slot>

        <x-slot name="actions">
            <a href="{{ route('wara.guest.template.index') }}"
               class="inline-flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-sm font-semibold text-white ring-1 ring-white/20 backdrop-blur-sm transition hover:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70">
                <x-lucide-arrow-left class="size-4" />
                {{ __('Kembali') }}
            </a>
        </x-slot>
    </x-wara::hero>

    @if (session('wara_template_status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800/40 dark:bg-emerald-900/20 dark:text-emerald-300">
            {{ session('wara_template_status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-800/40 dark:bg-rose-900/20 dark:text-rose-300">
            <p class="font-medium">{{ __('Periksa kembali isian berikut:') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- ---------------------------------------------------------------- --}}
        {{-- Form                                                             --}}
        {{-- ---------------------------------------------------------------- --}}
        <div class="space-y-5 lg:col-span-2">
            <x-wara::card
                :title="__('Identitas')"
                :subtitle="__('Nama dan kategori menentukan cara template dipanggil lewat API.')"
                icon="badge-info"
                accent="teal">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="template-name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ __('Nama Template') }}
                        </label>
                        <input id="template-name"
                               type="text"
                               wire:model="name"
                               placeholder="status_pengajuan"
                               class="mt-1 block w-full rounded-lg border-gray-300 font-mono text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">
                            {{ __('Hanya huruf kecil, angka, dan garis bawah. Nama ini dipakai API untuk memilih template.') }}
                        </p>
                        @error('name') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="template-category" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ __('Kategori') }}
                        </label>
                        <select id="template-category"
                                wire:model="category"
                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                            @foreach (\Bale\Wara\Enums\TemplateCategory::cases() as $case)
                                <option value="{{ $case->value }}" @selected($category === $case->value)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                        @error('category') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="template-parameter-format" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ __('Format Parameter') }}
                        </label>
                        <select id="template-parameter-format"
                                wire:model.live="parameterFormat"
                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                            @foreach (\Bale\Wara\Enums\ParameterFormat::cases() as $case)
                                <option value="{{ $case->value }}" @selected($parameterFormat === $case->value)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                        @error('parameterFormat') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-wara::card>

            <x-wara::card
                :title="__('Isi Pesan')"
                :subtitle="__('Susun header, body, dan footer sesuai batas karakter gateway.')"
                icon="message-square-text"
                accent="emerald">
                <div class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label for="template-header-format" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ __('Header') }}
                            </label>
                            <select id="template-header-format"
                                    wire:model.live="headerFormat"
                                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                <option value="NONE" @selected($headerFormat === 'NONE')>{{ __('Tanpa header') }}</option>
                                <option value="TEXT" @selected($headerFormat === 'TEXT')>{{ __('Teks') }}</option>
                                <option value="IMAGE" @selected($headerFormat === 'IMAGE')>{{ __('Gambar') }}</option>
                                <option value="VIDEO" @selected($headerFormat === 'VIDEO')>{{ __('Video') }}</option>
                                <option value="DOCUMENT" @selected($headerFormat === 'DOCUMENT')>{{ __('Dokumen') }}</option>
                            </select>
                            @error('headerFormat') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                        </div>

                        @if ($headerFormat === 'TEXT')
                            <div class="sm:col-span-2">
                                <label for="template-header-text" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('Teks Header') }}
                                </label>
                                <input id="template-header-text"
                                       type="text"
                                       wire:model.live.debounce.400ms="headerText"
                                       maxlength="60"
                                       class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                @error('headerText') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                            </div>
                        @elseif (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true))
                            <div class="flex items-end sm:col-span-2">
                                <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800/40 dark:bg-amber-900/20 dark:text-amber-300">
                                    {{ __('URL media tidak disimpan di template. Pemanggil API wajib mengirim header_media_url saat mengirim pesan.') }}
                                </p>
                            </div>
                        @endif
                    </div>

                    <div>
                        <label for="template-body" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ __('Body') }}
                        </label>
                        <textarea id="template-body"
                                  rows="6"
                                  wire:model.live.debounce.400ms="bodyText"
                                  class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"></textarea>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">
                            {{ $parameterHint }}
                        </p>
                        @error('bodyText') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                    </div>

                    @if (! empty($variables))
                        <div>
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ __('Contoh Nilai Parameter') }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-500">
                                {{ __('Contoh dipakai admin untuk menilai isi template. Bukan nilai yang dikirim.') }}
                            </p>

                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                @foreach ($variables as $variable)
                                    @php($token = '{'.'{'.$variable.'}'.'}')
                                    <div>
                                        <label for="example-{{ $variable }}" class="block font-mono text-xs text-gray-600 dark:text-gray-400">
                                            {{ $token }}
                                        </label>
                                        <input id="example-{{ $variable }}"
                                               type="text"
                                               wire:model="examples.{{ $variable }}"
                                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div>
                        <label for="template-footer" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ __('Footer') }}
                        </label>
                        <input id="template-footer"
                               type="text"
                               wire:model="footerText"
                               maxlength="60"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                        @error('footerText') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-wara::card>

            <x-wara::card
                :title="__('Tombol')"
                :subtitle="__('Maksimal 10 tombol. Saat dikirim, tombol tampil sebagai daftar bernomor.')"
                icon="mouse-pointer-click"
                accent="amber">
                <x-slot name="header">
                    <button type="button"
                            wire:click="addButton"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-teal-200 bg-white px-3 py-1.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-50 dark:border-teal-800/40 dark:bg-gray-900 dark:text-teal-300 dark:hover:bg-teal-900/20">
                        <x-lucide-plus class="size-3.5" />
                        {{ __('Tambah Tombol') }}
                    </button>
                </x-slot>

                @error('buttons') <p class="mb-3 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror

                <div class="space-y-3">
                    @forelse ($buttons as $index => $button)
                        <div class="rounded-xl border border-gray-100 bg-gray-50/40 p-3 dark:border-white/[0.06] dark:bg-gray-900/40"
                             wire:key="template-button-{{ $index }}">
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Tipe') }}</label>
                                    <select wire:model="buttons.{{ $index }}.type"
                                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                        @php($buttonType = $button['type'] ?? 'QUICK_REPLY')
                                        <option value="QUICK_REPLY" @selected($buttonType === 'QUICK_REPLY')>{{ __('Balasan Cepat') }}</option>
                                        <option value="URL" @selected($buttonType === 'URL')>{{ __('URL') }}</option>
                                        <option value="PHONE_NUMBER" @selected($buttonType === 'PHONE_NUMBER')>{{ __('Telepon') }}</option>
                                        <option value="COPY_CODE" @selected($buttonType === 'COPY_CODE')>{{ __('Salin Kode') }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Teks') }}</label>
                                    <input type="text"
                                           wire:model="buttons.{{ $index }}.text"
                                           maxlength="25"
                                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                </div>

                                <div class="flex items-end">
                                    <button type="button"
                                            wire:click="removeButton({{ $index }})"
                                            class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-rose-200 px-3 py-2 text-sm font-medium text-rose-700 transition hover:bg-rose-50 dark:border-rose-800/40 dark:text-rose-400 dark:hover:bg-rose-900/20">
                                        <x-lucide-trash-2 class="size-3.5" />
                                        {{ __('Hapus') }}
                                    </button>
                                </div>

                                @if (($button['type'] ?? '') === 'URL')
                                    <div class="sm:col-span-3">
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('URL') }}</label>
                                        <input type="url"
                                               wire:model="buttons.{{ $index }}.url"
                                               placeholder="https://"
                                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                    </div>
                                @endif

                                @if (($button['type'] ?? '') === 'PHONE_NUMBER')
                                    <div class="sm:col-span-3">
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Nomor Telepon') }}</label>
                                        <input type="text"
                                               wire:model="buttons.{{ $index }}.phone_number"
                                               placeholder="62812..."
                                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-500">{{ __('Belum ada tombol.') }}</p>
                    @endforelse
                </div>
            </x-wara::card>

            <div class="flex flex-wrap items-center gap-3">
                <button type="button"
                        wire:click="save(false)"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800">
                    <x-lucide-save class="size-4" />
                    {{ __('Simpan Draft') }}
                </button>

                <button type="button"
                        wire:click="save(true)"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-50">
                    <x-lucide-send class="size-4" />
                    {{ __('Simpan & Ajukan Review') }}
                </button>
            </div>
        </div>

        {{-- ---------------------------------------------------------------- --}}
        {{-- Preview                                                          --}}
        {{-- ---------------------------------------------------------------- --}}
        <div class="lg:col-span-1">
            <div class="sticky top-6">
                <x-wara::card
                    :title="__('Pratinjau')"
                    :subtitle="__('Tampilan pesan dengan contoh parameter.')"
                    icon="eye"
                    accent="sky">
                    <div class="rounded-lg bg-[#e5ddd5] p-3 dark:bg-gray-800">
                        <div class="rounded-lg bg-white px-3 py-2 text-sm text-gray-800 shadow-sm dark:bg-gray-900 dark:text-gray-100">
                            <p class="whitespace-pre-line break-words">{{ $this->previewText() }}</p>
                        </div>
                    </div>

                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-500">
                        {{ __('Pratinjau memakai contoh parameter. Nilai sebenarnya dikirim lewat API.') }}
                    </p>
                </x-wara::card>
            </div>
        </div>
    </div>
</div>
