<?php

namespace Bale\Wara\Http\Controllers\Api;

use Bale\Wara\Enums\TemplateCategory;
use Bale\Wara\Models\WaraTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/wara/templates` - katalog template APPROVED.
 *
 * Endpoint ini read-only dan tidak menerima `client_id`: katalog template
 * bersifat global untuk instalasi, sedangkan identitas pemanggil tetap
 * ditentukan token (lihat `SendClientResolver`).
 */
class TemplateIndexController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['nullable', Rule::in(TemplateCategory::values())],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $templates = WaraTemplate::query()
            ->approved()
            ->when(
                filled($validated['category'] ?? null),
                fn ($query) => $query->where('category', $validated['category'])
            )
            ->when(
                filled($validated['search'] ?? null),
                fn ($query) => $query->where('name', 'like', '%'.$validated['search'].'%')
            )
            ->orderBy('name')
            ->get()
            ->map(fn (WaraTemplate $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'category' => $template->category->value,
                'parameter_format' => $template->parameter_format->value,
                'header' => data_get($template->components, 'header'),
                'body' => data_get($template->components, 'body'),
                'footer' => data_get($template->components, 'footer'),
                'buttons' => data_get($template->components, 'buttons', []),
                'updated_at' => $template->updated_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $templates]);
    }
}
