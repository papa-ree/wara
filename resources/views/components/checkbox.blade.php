@props([
    'disabled' => false,
    'checked' => false,
])

{{--
    Checkbox khusus halaman Wara.

    Markup-nya sama dengan `x-core::checkbox`, tetapi warna terpilih memakai
    teal agar konsisten dengan token visual Bale. Komponen core sengaja tidak
    diubah karena dipakai lintas package.
--}}
<input type="checkbox" {{ $checked ? 'checked' : '' }} {{ $disabled ? 'disabled' : '' }}
    {{ $attributes->merge(['class' => 'peer sr-only']) }}>

<span class="shrink-0 flex items-center justify-center size-4.5 rounded-md
             border-2 transition-all duration-200
             border-gray-300 dark:border-gray-600
             peer-checked:border-teal-500 peer-checked:bg-teal-500
             dark:peer-checked:border-teal-500 dark:peer-checked:bg-teal-500
             text-transparent peer-checked:text-white
             {{ $disabled ? 'opacity-50 pointer-events-none' : '' }}">
    <x-lucide-check class="size-2.5" />
</span>
