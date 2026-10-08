<?php

namespace Bale\Wara\Http\Controllers;

use Bale\Wara\Exceptions\WaraException;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraWebhookEndpoint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Simpan client Wara lewat form HTML biasa (tanpa Livewire).
 *
 * 🔴 Jalur ini ada karena sebagian deployment tidak pernah mengirim aksi
 * Livewire `wire:*` ke server (klik Simpan tidak melakukan apa-apa, halaman
 * tetap di edit). `<form method="POST">` bekerja di browser apa pun: nilai
 * diambil saat tombol Simpan diklik oleh mekanisme HTML itu sendiri, CSRF
 * dipasang server-side, dan redirect dilakukan server-side.
 *
 * 🔴 Persistence di sini adalah DUPLIKAT dari `ClientForm::save()` +
 * `syncRoutes()` + `persistEndpoint()`. Saat mengubah logika tersebut,
 * perbarui keduanya — `ClientAdminTest` mengunci kedua arah (Livewire test
 * dan HTTP POST test).
 */
class ClientController
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatedData($request, null);

        $client = new WaraClient;
        $client->fill([
            'type' => $validated['type'],
            'name' => $validated['name'],
            'is_active' => $validated['is_active'],
            'header_enabled' => (bool) $validated['headerEnabled'],
            'header_text' => $validated['headerText'] ?? null,
            'footer_enabled' => (bool) $validated['footerEnabled'],
            'footer_text' => $validated['footerText'] ?? null,
        ])->save();

        // 🔴 `api_token_id` di luar $fillable (sama seperti di `ClientForm`).
        $client->forceFill([
            'api_token_id' => $validated['type'] === WaraClient::TYPE_TOKEN
                ? $validated['apiTokenId']
                : null,
        ])->save();

        $this->syncRoutes($client, $validated['routes'] ?? []);
        $this->persistEndpoint($request, $client);

        return redirect()->route('wara.client.index');
    }

    public function update(Request $request, WaraClient $client): RedirectResponse
    {
        // Hapus endpoint tanpa harus validasi seluruh form. Tombol Hapus tiap
        // baris mengisi field tersembunyi `delete_endpoint` lalu men-submit
        // form yang sama, supaya tidak ada form bersarang (HTML invalid).
        if ($request->filled('delete_endpoint')) {
            $endpoint = WaraWebhookEndpoint::query()
                ->where('client_id', $client->getKey())
                ->whereKey($request->string('delete_endpoint'))
                ->firstOrFail();

            $endpoint->delete();

            return back();
        }

        $validated = $this->validatedData($request, $client);

        $client->fill([
            'type' => $validated['type'],
            'name' => $validated['name'],
            'is_active' => $validated['is_active'],
            'header_enabled' => (bool) $validated['headerEnabled'],
            'header_text' => $validated['headerText'] ?? null,
            'footer_enabled' => (bool) $validated['footerEnabled'],
            'footer_text' => $validated['footerText'] ?? null,
        ])->save();

        // 🔴 Tipe berubah ke `service` => token harus dibersihkan.
        $client->forceFill([
            'api_token_id' => $validated['type'] === WaraClient::TYPE_TOKEN
                ? $validated['apiTokenId']
                : null,
        ])->save();

        $this->syncRoutes($client, $validated['routes'] ?? []);
        $this->persistEndpoint($request, $client);

        return redirect()->route('wara.client.index');
    }

    /**
     * Validasi identik dengan `ClientForm::save()`.
     *
     * Nilai boolean di-normalisasi DU LUAR validator: checkbox HTML yang tidak
     * dicentang tidak ikut terkirim, dan `'1'/'on'` bukan boolean valid untuk
     * aturan `boolean` Laravel.
     *
     * @return array<string, mixed>
     */
    protected function validatedData(Request $request, ?WaraClient $client): array
    {
        $rows = $request->input('routes', []);

        foreach ($rows as $index => $row) {
            $rows[$index]['outbound_enabled'] = (bool) ($row['outbound_enabled'] ?? false);
            $rows[$index]['inbound_webhook_enabled'] = (bool) ($row['inbound_webhook_enabled'] ?? false);
        }

        $data = [
            'name' => $request->input('name'),
            'type' => $request->input('type'),
            'is_active' => $request->boolean('is_active'),
            'apiTokenId' => $request->input('apiTokenId'),
            'headerEnabled' => $request->boolean('headerEnabled'),
            'headerText' => $request->input('headerText'),
            'footerEnabled' => $request->boolean('footerEnabled'),
            'footerText' => $request->input('footerText'),
            'routes' => $rows,
        ];

        return Validator::make($data, [
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', 'in:'.implode(',', WaraClient::TYPES)],
            'is_active' => ['boolean'],
            // 🔴 WAJIB untuk client tipe `token`.
            'apiTokenId' => [
                'nullable',
                'uuid',
                'required_if:type,'.WaraClient::TYPE_TOKEN,
                Rule::notIn($this->tokensOwnedByOtherClients($client)),
            ],
            'routes' => ['array'],
            'headerEnabled' => ['boolean'],
            'headerText' => ['nullable', 'string', 'max:500', 'required_if:headerEnabled,true'],
            'footerEnabled' => ['boolean'],
            'footerText' => ['nullable', 'string', 'max:500', 'required_if:footerEnabled,true'],
            'routes.*.id' => ['nullable', 'string', 'max:36'],
            'routes.*.purpose' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9\-_]+$/'],
            'routes.*.device_id' => ['required', 'string', 'max:191'],
            'routes.*.outbound_enabled' => ['boolean'],
            'routes.*.inbound_webhook_enabled' => ['boolean'],
        ])->validate();
    }

    /**
     * @return list<string>
     */
    protected function tokensOwnedByOtherClients(?WaraClient $client): array
    {
        return WaraClient::query()
            ->whereNotNull('api_token_id')
            ->when(
                $client?->exists,
                fn ($q) => $q->whereKeyNot($client->getKey())
            )
            ->pluck('api_token_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * Duplikat `ClientForm::syncRoutes()`, bedanya error dilempar sebagai
     * ValidationException supaya redirect balik ke form dengan pesan.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function syncRoutes(WaraClient $client, array $rows): void
    {
        $keepIds = [];

        foreach ($rows as $row) {
            // 🔴 `?? null` wajib (lihat komentar di `ClientForm::syncRoutes()`).
            $rowId = $row['id'] ?? null;

            $route = $rowId !== null
                ? WaraRoute::query()->where('client_id', $client->getKey())->whereKey($rowId)->first()
                : null;

            if ($rowId !== null && $route === null) {
                continue;
            }

            $route ??= new WaraRoute;

            $route->forceFill([
                'client_id' => $client->getKey(),
                'purpose' => $row['purpose'],
                'device_id' => $row['device_id'],
            ]);

            $route->fill([
                'outbound_enabled' => (bool) $row['outbound_enabled'],
                'inbound_webhook_enabled' => (bool) $row['inbound_webhook_enabled'],
            ]);

            try {
                $route->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'routes' => __('Purpose "%s" sudah dipakai device lain untuk client ini.', [
                        'purpose' => $row['purpose'],
                    ]),
                ]);
            }

            $keepIds[] = $route->getKey();
        }

        WaraRoute::query()
            ->where('client_id', $client->getKey())
            ->whereNotIn('id', $keepIds === [] ? ['-'] : $keepIds)
            ->delete();
    }

    /**
     * Duplikat `ClientForm::persistEndpoint()`. Endpoint baru hanya dibuat
     * kalau form endpoint-nya memang diisi.
     */
    protected function persistEndpoint(Request $request, WaraClient $client): void
    {
        if ($request->missing('endpointUrl') || blank($request->input('endpointUrl'))) {
            return;
        }

        $validated = Validator::make([
            'endpointUrl' => $request->input('endpointUrl'),
            'endpointSecret' => $request->input('endpointSecret'),
            'endpointEvents' => $request->input('endpointEvents'),
            'endpointEnabled' => $request->boolean('endpointEnabled'),
        ], [
            'endpointUrl' => ['required', 'url', 'max:1024'],
            'endpointSecret' => ['required', 'string', 'min:'.WaraWebhookEndpoint::MIN_SECRET_LENGTH],
            'endpointEvents' => ['nullable', 'string', 'max:1024'],
            'endpointEnabled' => ['boolean'],
        ])->validate();

        $endpoint = new WaraWebhookEndpoint;

        $endpoint->forceFill([
            'client_id' => $client->getKey(),
            'url' => trim((string) $validated['endpointUrl']),
        ]);

        $endpoint->fill([
            'events' => $this->parseEvents($validated['endpointEvents']),
            'enabled' => (bool) $validated['endpointEnabled'],
        ]);

        // `secret` juga di luar $fillable.
        $endpoint->forceFill(['secret' => (string) $validated['endpointSecret']]);

        try {
            $endpoint->assertUrlAllowed();
            $endpoint->assertSecretUsable();
        } catch (WaraException $e) {
            // 🔴 Diulang sebagai ValidationException supaya redirect balik ke
            // form dengan pesan, bukan error yang lepas ke user.
            throw ValidationException::withMessages([
                'endpointUrl' => $e->getMessage(),
            ]);
        }

        $endpoint->save();
    }

    /**
     * @return array<int, string>|null
     */
    protected function parseEvents(?string $events): ?array
    {
        if (blank($events)) {
            return null;
        }

        $parsed = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $events)
        )));

        return $parsed === [] ? null : $parsed;
    }
}
