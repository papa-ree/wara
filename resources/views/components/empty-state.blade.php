@props([
    'icon' => 'inbox',
    'title',
    'description' => null,
])

<div class="flex flex-col items-center justify-center px-6 py-10 text-center">
    <span class="flex size-12 items-center justify-center rounded-full bg-teal-50 text-teal-400 dark:bg-teal-900/20 dark:text-teal-500">
        <x-dynamic-component :component="'lucide-'.$icon" class="size-6" />
    </span>

    <p class="mt-3 text-sm font-medium text-gray-700 dark:text-gray-300">
        {{ $title }}
    </p>

    @if ($description)
        <p class="mt-1 max-w-sm text-xs text-gray-500 dark:text-gray-400">
            {{ $description }}
        </p>
    @endif

    @isset($action)
        <div class="mt-4">
            {{ $action }}
        </div>
    @endisset
</div>
