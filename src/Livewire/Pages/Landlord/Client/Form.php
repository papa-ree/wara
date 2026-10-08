<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Client;

use Bale\Wara\Exceptions\WaraException;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\Models\WaraWebhookEndpoint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Formulir client: peta `purpose -> device` dan endpoint webhook.
 *
 * 🔴 INI SATU-SATUNYA JALUR untuk mengisi route dan endpoint.
 *
 * `client_id` tidak pernah masuk dari form - client itu adalah objek yang
 * sedang diedit, bukan pilihan. Kalau bisa dipilih dari input, satu form bisa
 * menulis route milik client lain.
 *
 * Validasi TIDAK hanya relies on `assertUrlAllowed()` / `assertSecretUsable()`
 * di sini: keduanya juga dicek lagi di dalam job saat benar-benar mengirim.
 * Kalau hanya divalidasi di form, job yang terlanjur tersimpan di queue bisa
 * melewati guardrail setelah datanya diubah.
 */
#[Layout('rakaca::layouts.app')]
#[Title('Form Client Wara')]
class Form extends Component
{
    use AuthorizesRequests;

    public ?WaraClient $client = null;

    public string $name = '';

    public string $type = WaraClient::TYPE_SERVICE;

    public bool $is_active = true;

    /** Header/footer branding pesan: teks statis yang ditempel ke semua pesan keluar client ini. */
    public bool $headerEnabled = false;

    public ?string $headerText = null;

    public bool $footerEnabled = false;

    public ?string $footerText = null;

    /** Baris route yang sedang diedit, dalam bentuk array supaya bisa di-loop di form. */
    public array $routes = [];

    /** Endpoint webhook client. */
    public array $endpoints = [];

    /** Device yang tersedia untuk dipetakan. */
    public array $devices = [];

    /**
     * 🔴 `api_token_id` milik client TIDAK ada di `$fillable` model dan tidak
     * boleh masuk dari form biasa. Field ini tetap bisa diisi lewat form, tapi
     * hanya lewat jalur yang eksplisit di bawah ini, bukan lewat `fill()`.
     */
    public ?string $apiTokenId = null;

    /** Token yang bisa dipilih, untuk client tipe `token`. */
    public array $apiTokens = [];

    public bool $showEndpointForm = false;

    public ?string $endpointUrl = null;

    public ?string $endpointSecret = null;

    public ?string $endpointEvents = null;

    public bool $endpointEnabled = false;

    public function mount(?WaraClient $client = null): void
    {
        $this->authorize('wara.client.update');

        $this->client = $client?->exists ? $client : null;

        $this->apiTokens = $this->loadApiTokens();

        $this->devices = WaraSession::query()
            ->orderBy('display_name')
            ->orderBy('device_id')
            ->get(['device_id', 'display_name', 'is_logged_in'])
            ->map(fn (WaraSession $s): array => [
                'device_id' => $s->device_id,
                'label' => trim(($s->display_name ?? '').' ('.$s->device_id.')'),
                'ready' => $s->isSendable(),
            ])
            ->all();

        if ($this->client === null) {
            $this->resetForm();

            return;
        }

        $this->name = (string) $this->client->name;
        $this->type = (string) $this->client->type;
        $this->is_active = (bool) $this->client->is_active;
        $this->apiTokenId = $this->client->api_token_id;
        $this->headerEnabled = (bool) $this->client->header_enabled;
        $this->headerText = $this->client->header_text;
        $this->footerEnabled = (bool) $this->client->footer_enabled;
        $this->footerText = $this->client->footer_text;

        $this->routes = $this->client->routes()
            ->orderBy('purpose')
            ->get()
            ->map(fn (WaraRoute $r): array => [
                'id' => $r->getKey(),
                'purpose' => $r->purpose,
                'device_id' => $r->device_id,
                'outbound_enabled' => (bool) $r->outbound_enabled,
                'inbound_webhook_enabled' => (bool) $r->inbound_webhook_enabled,
            ])
            ->all();

        $this->endpoints = $this->client->webhookEndpoints()
            ->orderBy('url')
            ->get()
            ->map(fn (WaraWebhookEndpoint $e): array => [
                'id' => $e->getKey(),
                'url' => $e->url,
                // 🔴 Secret TIDAK pernah dikirim ke browser. Yang ditampilkan
                // hanya status "terisi / belum": begitu ada di HTML, secret itu
                // terekspos ke siapa pun yang bisa melihat halaman ini.
                'secret_set' => $e->hasUsableSecret(),
                'events' => $e->events === null || $e->events === [] ? '' : implode(', ', $e->events),
                'enabled' => (bool) $e->enabled,
            ])
            ->all();
    }

    public function resetForm(): void
    {
        $this->name = '';
        $this->type = WaraClient::TYPE_SERVICE;
        $this->is_active = true;
        $this->apiTokenId = null;
        $this->headerEnabled = false;
        $this->headerText = null;
        $this->footerEnabled = false;
        $this->footerText = null;
        $this->routes = [];
        $this->resetEndpointForm();
    }

    /**
    /**
     * Token yang sudah dimiliki client LAIN, jadi tidak boleh dipilih.
     *
     * 🔴 Client yang sedang diedit dikecualikan. Tanpa itu, admin tidak akan
     * pernah bisa menyimpan client yang sudah punya token - tokennya sendiri
     * termasuk daftar "dimiliki client lain", dan form menolak nilai yang
     * tidak berubah sama sekali.
     *
     * @return list<string>
     */
    protected function tokensOwnedByOtherClients(): array
    {
        return WaraClient::query()
            ->whereNotNull('api_token_id')
            ->when(
                $this->client?->exists,
                fn ($q) => $q->whereKeyNot($this->client->getKey())
            )
            ->pluck('api_token_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * Token yang bisa dipilih untuk client tipe `token`.
     *
     * 🔴 Token yang sudah dipakai client lain TETAP ditampilkan, tapi ditandai
     * dan tidak bisa dipilih lagi. Kalau disembunyikan begitu saja, admin yang
     * sedang memperbaiki client lama akan melihat dropdown kosong dan menyimpulkan
     * tokennya hilang - padahal token itu ada dan milik client ini.
     *
     * 🔴 Dua token yang menunjuk satu client dicegah lewat validasi `save()`,
     * bukan lewat filter daftar. Filter hanya mengubah apa yang terlihat;
     * `DeviceRouter::clientForApiTokenId()` yang benar-benar memutuskan, dan
     * dia akan mengembalikan baris pertama tanpa tahu ada duplikat.
     *
     * `bale/api` dicek dengan `class_exists`, bukan `use` statis: wara tidak
     * mendeklarasikan api sebagai dependency, jadi form harus tetap bisa
     * dirender di instalasi yang tidak memasang `bale/api`.
     *
     * @return array<int, array{id: string, label: string, available: bool}>
     */
    protected function loadApiTokens(): array
    {
        $class = 'Bale\\Api\\Models\\ApiToken';

        if (! class_exists($class)) {
            return [];
        }

        // `pluck(api_token_id, id)` memberi peta token -> client yg memakainya
        // dalam satu query. Versi pertama memakai loop `find()` per token, dan
        // itu N+1 yang tidak terlihat: daftar token tumbuh, dan setiap baris
        // menambah satu query ke database.
        $ownerByToken = WaraClient::query()
            ->whereNotNull('api_token_id')
            ->get(['id', 'name', 'api_token_id'])
            ->keyBy('api_token_id');

        /** @var Collection<int, object> $tokens */
        $tokens = $class::query()->orderBy('name')->get(['id', 'name', 'revoked_at']);

        return $tokens
            ->map(function (object $token) use ($ownerByToken): array {
                $owner = $ownerByToken->get($token->getKey());

                // 🔴 Token milik client YANG SEDANG DIEDIT tetap harus bisa
                // dipilih; kalau di-disabled, menyimpan ulang client token
                // gagal di validasi `required_if`. `dipakai client: :name`
                // untuk diri sendiri juga tidak perlu ditampilkan.
                $isOwnerSelf = (bool) $this->client?->exists
                    && (string) $owner?->getKey() === (string) $this->client?->getKey();

                $label = (string) $token->name;

                if ($token->revoked_at !== null) {
                    $label .= ' — '.__('revoked');
                }

                if ($owner !== null && ! $isOwnerSelf) {
                    $label .= ' — '.__('dipakai client: :name', ['name' => $owner->name]);
                }

                return [
                    'id' => (string) $token->getKey(),
                    'label' => $label,
                    'available' => $owner === null || $isOwnerSelf,
                ];
            })
            ->values()
            ->all();
    }

    public function addRoute(): void
    {
        $this->routes[] = [
            'id' => null,
            'purpose' => '',
            'device_id' => $this->devices[0]['device_id'] ?? '',
            'outbound_enabled' => true,
            'inbound_webhook_enabled' => false,
        ];
    }

    public function removeRoute(int $index): void
    {
        unset($this->routes[$index]);

        $this->routes = array_values($this->routes);
    }

    // -----------------------------------------------------------------------
    // Endpoint webhook
    // -----------------------------------------------------------------------

    public function showEndpointCreator(): void
    {
        $this->resetEndpointForm();
        $this->showEndpointForm = true;
    }

    public function resetEndpointForm(): void
    {
        $this->endpointUrl = null;
        $this->endpointSecret = null;
        $this->endpointEvents = null;
        $this->endpointEnabled = false;
        $this->showEndpointForm = false;
    }

    public function removeEndpoint(string $id): void
    {
        WaraWebhookEndpoint::query()->where('client_id', $this->client->getKey())
            ->whereKey($id)
            ->delete();

        $this->mount($this->client);
    }

    // -----------------------------------------------------------------------

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', 'in:'.implode(',', WaraClient::TYPES)],
            'is_active' => ['boolean'],
            // 🔴 WAJIB untuk client tipe `token`. Validasi `required_if` ini
            // yang mencegah konfigurasi yang isUsable() tolak diam-diam -
            // client token tanpa token akan menerima 403 di setiap request
            // tanpa penjelasan yang bisa dibaca admin.
            'apiTokenId' => [
                'nullable',
                'uuid',
                'required_if:type,'.WaraClient::TYPE_TOKEN,
                Rule::notIn($this->tokensOwnedByOtherClients()),
            ],
            'routes' => ['array'],
            // 🔴 Branding pesan: teks statis, wajib terisi kalau toggle-nya
            // menyala. Tanpa `required_if`, header kosong tetap diterima lalu
            // diam-diam tidak ditempelkan - admin mengira brandingnya aktif.
            'headerEnabled' => ['boolean'],
            'headerText' => ['nullable', 'string', 'max:500', 'required_if:headerEnabled,true'],
            'footerEnabled' => ['boolean'],
            'footerText' => ['nullable', 'string', 'max:500', 'required_if:footerEnabled,true'],
            // 🔴 `routes.*.id` WAJIB punya aturan. Tanpa itu `validate()`
            // mengembalikan array route TANPA kunci `id`, `syncRoutes()`
            // menganggap setiap baris itu baru, dan mengedit client akan
            // menggandakan route-nya alih-alih memperbarui - route lama ikut
            // terhapus karena tidak masuk daftar id yang dipertahankan.
            'routes.*.id' => ['nullable', 'string', 'max:36'],
            'routes.*.purpose' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9\-_]+$/'],
            'routes.*.device_id' => ['required', 'string', 'max:191'],
            'routes.*.outbound_enabled' => ['boolean'],
            'routes.*.inbound_webhook_enabled' => ['boolean'],
        ]);

        $client = $this->client;

        if ($client === null) {
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

            $this->client = $client;
        } else {
            $client->fill([
                'type' => $validated['type'],
                'name' => $validated['name'],
                'is_active' => $validated['is_active'],
                'header_enabled' => (bool) $validated['headerEnabled'],
                'header_text' => $validated['headerText'] ?? null,
                'footer_enabled' => (bool) $validated['footerEnabled'],
                'footer_text' => $validated['footerText'] ?? null,
            ])->save();
        }

        // 🔴 `api_token_id` di luar `$fillable` dan itu disengaja: kolom ini
        // menentukan client ini "dipanggil oleh siapa", jadi harus lewat jalur
        // eksplisit - bukan `fill()` yang isinya bisa datang dari mana saja.
        //
        // Dan saat tipe berubah dari `token` ke `service`, nilainya HARUS
        // dibersihkan. Kalau tidak, client `service` masih memegang token yang
        // tidak pernah dipakai, dan `DeviceRouter::clientForApiTokenId()` tetap
        // memetakannya - jadi request dari token itu mendarat ke client yang
        // justru tidak memakainya, dan route-nya menyesatkan.
        $client->forceFill([
            'api_token_id' => $validated['type'] === WaraClient::TYPE_TOKEN
                ? $validated['apiTokenId']
                : null,
        ])->save();

        $this->syncRoutes($client, $validated['routes'] ?? []);
        $this->persistEndpoint();

        $this->mount($client);

        $this->dispatch('toast', message: __('Client Wara berhasil disimpan.'), type: 'success');
        $this->redirect(route('wara.client.index'), navigate: true);
    }

    /**
     * Simpan peta purpose -> device milik client ini saja.
     *
     * 🔴 Baris tanpa `id` milik client lain DIABAIKAN, bukan diambil. Baris
     * yang sudah dihapus di form dihapus juga.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function syncRoutes(WaraClient $client, array $rows): void
    {
        $keepIds = [];

        foreach ($rows as $row) {
            // 🔴 `?? null` wajib. Livewire bisa membuang kunci bernilai null
            // dari array route ketika property di-set dari luar, jadi
            // `$row['id']` tidak selalu ada - dan baris baru dari `addRoute()`
            // justru yang paling sering kehilangan kunci itu.
            $rowId = $row['id'] ?? null;

            $route = $rowId !== null
                ? WaraRoute::query()->where('client_id', $client->getKey())->whereKey($rowId)->first()
                : null;

            // Baris milik client lain tidak boleh "diambil" - dia diabaikan.
            if ($rowId !== null && $route === null) {
                continue;
            }

            $route ??= new WaraRoute;

            // `client_id` di luar $fillable.
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
                // UNIQUE (client_id, purpose) dilanggar. Pesannya meng menyebut
                // purpose yang bentrok, bukan "gagal" - supaya admin tahu baris
                // mana yang harus dibetulkan.
                $this->addError('routes', __('Purpose "%s" sudah dipakai device lain untuk client ini.', [
                    'purpose' => $row['purpose'],
                ]));

                continue;
            }

            $keepIds[] = $route->getKey();
        }

        WaraRoute::query()
            ->where('client_id', $client->getKey())
            ->whereNotIn('id', $keepIds === [] ? ['-'] : $keepIds)
            ->delete();
    }

    /**
     * Simpan endpoint baru, kalau form endpoint-nya diisi.
     *
     * Validasi URL dan secret dipanggil di sini (tempat admin salah ketik) DAN
     * lagi di dalam job (tempat data benar-benar dipakai).
     */
    protected function persistEndpoint(): void
    {
        if (! $this->showEndpointForm || blank($this->endpointUrl)) {
            return;
        }

        $this->validate([
            'endpointUrl' => ['required', 'url', 'max:1024'],
            'endpointSecret' => ['required', 'string', 'min:'.WaraWebhookEndpoint::MIN_SECRET_LENGTH],
        ]);

        $endpoint = new WaraWebhookEndpoint;

        $endpoint->forceFill([
            'client_id' => $this->client->getKey(),
            'url' => trim((string) $this->endpointUrl),
        ]);

        $endpoint->fill([
            'events' => $this->parseEvents(),
            'enabled' => (bool) $this->endpointEnabled,
        ]);

        // `secret` juga di luar $fillable.
        $endpoint->forceFill(['secret' => (string) $this->endpointSecret]);

        try {
            $endpoint->assertUrlAllowed();
            $endpoint->assertSecretUsable();
        } catch (WaraException $e) {
            // 🔴 Ditangkap jadi error form, bukan exception yang lepas ke user.
            // Pesannya tetap sampai ke admin - hanya bentuknya yang berbeda,
            // dan yang penting endpoint tidak ikut tersimpan.
            $this->addError('endpointUrl', $e->getMessage());

            return;
        }

        $endpoint->save();

        $this->resetEndpointForm();
    }

    /**
     * @return array<int, string>|null
     */
    protected function parseEvents(): ?array
    {
        if (blank($this->endpointEvents)) {
            // null = terima semua event.
            return null;
        }

        $events = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->endpointEvents)
        )));

        return $events === [] ? null : $events;
    }

    public function render()
    {
        return view('wara::livewire.pages.landlord.client.form');
    }
}
