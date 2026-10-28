<?php

use App\Models\User;
use Bale\Api\Services\TokenManager;
use Bale\Wara\Enums\ParameterFormat;
use Bale\Wara\Enums\TemplateStatus;
use Bale\Wara\Exceptions\TemplateWorkflowException;
use Bale\Wara\Livewire\Pages\Landlord\Docs\Index as DocsIndex;
use Bale\Wara\Livewire\Pages\Landlord\Template\Review as TemplateReview;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\Models\WaraTemplate;
use Bale\Wara\Services\TemplateRenderer;
use Bale\Wara\Services\TemplateWorkflowService;
use Bale\Wara\Support\TemplateValidator;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Paparee\Rakaca\Models\PersonHasService;
use Paparee\Rakaca\Models\RakacaService;
use Spatie\Permission\Models\Permission;

uses()->beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    config()->set('wara.endpoint', 'http://gateway.test');
    config()->set('wara.api_key', 'user:pass');
    config()->set('wara.webhook_secret', 'rahasia-webhook');
    config()->set('wara.default_device_id', '');

    // Migration template masih `.php.stub` (belum dipublish ke aplikasi),
    // jadi jalankan langsung kalau tabelnya belum ada.
    if (! Schema::hasTable('wara_templates')) {
        $migration = require __DIR__.'/../../database/migrations/2026_10_20_000001_create_wara_templates_table.php.stub';
        $migration->up();
    }

    Http::preventStrayRequests();
    Http::fake(['gateway.test/*' => Http::response([
        'id' => 'msg-1',
        'status' => 'accepted',
    ], 200)]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function makeTemplateForTest(array $overrides = []): WaraTemplate
{
    $template = new WaraTemplate;

    $template->forceFill(array_merge([
        'user_uuid' => '11111111-1111-4111-8111-111111111111',
        'name' => 'status_pengajuan_'.uniqid(),
        'category' => 'LAYANAN',
        'status' => TemplateStatus::Draft->value,
        'parameter_format' => ParameterFormat::Positional->value,
        'components' => [
            'header' => ['format' => 'TEXT', 'text' => 'Halo'],
            'body' => ['text' => 'Pengajuan {{1}} berstatus {{2}}.', 'examples' => ['1' => 'A', '2' => 'B']],
            'footer' => ['text' => 'Pemkab'],
            'buttons' => [],
        ],
    ], $overrides))->save();

    return $template;
}

function sessionForTemplateTest(array $overrides = []): WaraSession
{
    static $counter = 0;
    $counter++;

    return WaraSession::query()->create(array_merge([
        'device_id' => 'tpl_dev_'.$counter,
        'jid' => '6288800000'.$counter.'@s.whatsapp.net',
        'display_name' => 'Device template '.$counter,
        'is_present' => true,
        'is_connected' => true,
        'is_logged_in' => true,
        'last_seen_at' => now(),
    ], $overrides));
}

/**
 * @return array{client: WaraClient, session: WaraSession, header: array<string, string>}
 */
function clientWithTokenForTemplateTest(array $scopes = ['wara.send']): array
{
    $session = sessionForTemplateTest();

    $issued = app(TokenManager::class)->issue('Token template', $scopes);

    $client = new WaraClient;
    $client->forceFill([
        'type' => WaraClient::TYPE_TOKEN,
        'api_token_id' => $issued['model']->getKey(),
        'name' => 'Client template',
        'is_active' => true,
    ])->save();

    (new WaraRoute)->forceFill([
        'client_id' => $client->getKey(),
        'purpose' => 'notifikasi',
        'device_id' => $session->device_id,
    ])->save();

    return [
        'client' => $client,
        'session' => $session,
        'header' => ['Authorization' => 'Bearer '.$issued['plain']],
    ];
}

describe('TemplateValidator', function () {
    it('menerima template posisional yang rapi', function () {
        $normalized = app(TemplateValidator::class)->validate([
            'header' => ['format' => 'TEXT', 'text' => 'Halo {{1}}'],
            'body' => ['text' => 'Isi {{1}} dan {{2}}', 'examples' => ['1' => 'a', '2' => 'b']],
            'footer' => ['text' => null],
            'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Lanjut']],
        ], ParameterFormat::Positional);

        expect($normalized['header']['format'])->toBe('TEXT')
            ->and($normalized['body']['text'])->toBe('Isi {{1}} dan {{2}}')
            ->and($normalized['buttons'][0]['type'])->toBe('QUICK_REPLY');
    });

    it('menolak variabel posisional yang berlompatan', function () {
        app(TemplateValidator::class)->validate([
            'header' => ['format' => 'NONE'],
            'body' => ['text' => '{{1}} dan {{3}}', 'examples' => ['1' => 'a', '3' => 'b']],
            'footer' => ['text' => null],
            'buttons' => [],
        ], ParameterFormat::Positional);
    })->throws(ValidationException::class);

    it('menolak contoh parameter yang kosong', function () {
        app(TemplateValidator::class)->validate([
            'header' => ['format' => 'NONE'],
            'body' => ['text' => 'Halo {{1}}', 'examples' => ['1' => '']],
            'footer' => ['text' => null],
            'buttons' => [],
        ], ParameterFormat::Positional);
    })->throws(ValidationException::class);

    it('menerima format bernama', function () {
        $normalized = app(TemplateValidator::class)->validate([
            'header' => ['format' => 'NONE'],
            'body' => ['text' => 'Halo {{nama}}', 'examples' => ['nama' => 'Budi']],
            'footer' => ['text' => null],
            'buttons' => [],
        ], ParameterFormat::Named);

        expect($normalized['body']['examples'])->toBe(['nama' => 'Budi']);
    });

    it('menolak lebih dari dua tombol URL', function () {
        app(TemplateValidator::class)->validate([
            'header' => ['format' => 'NONE'],
            'body' => ['text' => 'Isi', 'examples' => []],
            'footer' => ['text' => null],
            'buttons' => [
                ['type' => 'URL', 'text' => 'A', 'url' => 'https://a.test'],
                ['type' => 'URL', 'text' => 'B', 'url' => 'https://b.test'],
                ['type' => 'URL', 'text' => 'C', 'url' => 'https://c.test'],
            ],
        ], ParameterFormat::Positional);
    })->throws(ValidationException::class);
});

describe('TemplateRenderer', function () {
    it('merakit header, body, footer, dan tombol bernomor', function () {
        $template = makeTemplateForTest([
            'status' => TemplateStatus::Approved->value,
            'components' => [
                'header' => ['format' => 'TEXT', 'text' => 'Halo {{1}}'],
                'body' => ['text' => 'Pengajuan {{2}} selesai.', 'examples' => ['1' => 'A', '2' => 'B']],
                'footer' => ['text' => 'Pemkab'],
                'buttons' => [
                    ['type' => 'QUICK_REPLY', 'text' => 'Lihat', 'url' => null, 'phone_number' => null],
                    ['type' => 'URL', 'text' => 'Buka', 'url' => 'https://x.test', 'phone_number' => null],
                ],
            ],
        ]);

        $rendered = app(TemplateRenderer::class)->render($template, ['1' => 'Budi', '2' => 'SP-1']);

        expect($rendered['text'])->toContain('Halo Budi')
            ->toContain('Pengajuan SP-1 selesai.')
            ->toContain('Pemkab')
            ->toContain('1. Lihat')
            ->toContain('2. Buka')
            ->and($rendered['media'])->toBeNull();
    });

    it('meminta URL media bila header memakai media', function () {
        $template = makeTemplateForTest([
            'status' => TemplateStatus::Approved->value,
            'components' => [
                'header' => ['format' => 'IMAGE', 'text' => null],
                'body' => ['text' => 'Foto kegiatan', 'examples' => []],
                'footer' => ['text' => null],
                'buttons' => [],
            ],
        ]);

        expect(fn () => app(TemplateRenderer::class)->render($template))
            ->toThrow(TemplateWorkflowException::class);

        $rendered = app(TemplateRenderer::class)->render($template, [], 'https://cdn.test/foto.jpg');

        expect($rendered['media'])->toBe(['type' => 'IMAGE', 'url' => 'https://cdn.test/foto.jpg']);
    });

    it('menolak parameter yang belum diisi', function () {
        $template = makeTemplateForTest(['status' => TemplateStatus::Approved->value]);

        expect(fn () => app(TemplateRenderer::class)->render($template, []))
            ->toThrow(TemplateWorkflowException::class);
    });
});

describe('TemplateWorkflowService', function () {
    it('mengalirkan DRAFT -> PENDING -> APPROVED', function () {
        $template = makeTemplateForTest();
        $service = app(TemplateWorkflowService::class);

        $service->submit($template);
        expect($template->fresh()->status)->toBe(TemplateStatus::Pending);

        $service->approve($template->fresh(), '99999999-9999-4999-8999-999999999999');

        $fresh = $template->fresh();
        expect($fresh->status)->toBe(TemplateStatus::Approved)
            ->and($fresh->reviewed_by)->toBe('99999999-9999-4999-8999-999999999999')
            ->and($fresh->reviewed_at)->not->toBeNull();
    });

    it('menolak submit ulang untuk template APPROVED', function () {
        $template = makeTemplateForTest(['status' => TemplateStatus::Approved->value]);

        expect(fn () => app(TemplateWorkflowService::class)->submit($template))
            ->toThrow(TemplateWorkflowException::class);
    });

    it('menolak approve untuk template yang belum PENDING', function () {
        $template = makeTemplateForTest();

        expect(fn () => app(TemplateWorkflowService::class)->approve($template, 'x'))
            ->toThrow(TemplateWorkflowException::class);
    });

    it('menyimpan alasan penolakan', function () {
        $template = makeTemplateForTest(['status' => TemplateStatus::Pending->value]);

        app(TemplateWorkflowService::class)->reject($template, '99999999-9999-4999-8999-999999999999', 'Kurang jelas');

        $fresh = $template->fresh();
        expect($fresh->status)->toBe(TemplateStatus::Rejected)
            ->and($fresh->rejection_reason)->toBe('Kurang jelas');
    });

    it('membuka kembali template APPROVED dari REJECTED lewat DRAFT', function () {
        $template = makeTemplateForTest(['status' => TemplateStatus::Rejected->value, 'rejection_reason' => 'x']);

        app(TemplateWorkflowService::class)->reopen($template);

        expect($template->fresh()->status)->toBe(TemplateStatus::Draft);
    });
});

describe('POST /api/v1/wara/send dengan template', function () {
    it('mengirim template APPROVED', function () {
        $ctx = clientWithTokenForTemplateTest();
        $template = makeTemplateForTest(['status' => TemplateStatus::Approved->value]);

        $this->postJson('/api/v1/wara/send', [
            'to' => '081234567890',
            'template' => $template->name,
            'parameters' => ['1' => 'Budi', '2' => 'SP-1'],
        ], $ctx['header'])
            ->assertOk()
            ->assertJson([
                'status' => 'sent',
                'purpose' => 'notifikasi',
                'template' => $template->name,
            ]);

        Http::assertSent(function (Request $request) use ($ctx): bool {
            return str_ends_with($request->url(), '/send/message')
                && $request->hasHeader('X-Device-Id', $ctx['session']->device_id);
        });
    });

    it('menolak template yang belum disetujui', function () {
        $ctx = clientWithTokenForTemplateTest();
        $template = makeTemplateForTest();

        $this->postJson('/api/v1/wara/send', [
            'to' => '081234567890',
            'template' => $template->name,
            'parameters' => ['1' => 'A', '2' => 'B'],
        ], $ctx['header'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'template_required');

        Http::assertNothingSent();
    });

    it('wajib menyertakan header_media_url untuk template media', function () {
        $ctx = clientWithTokenForTemplateTest();
        $template = makeTemplateForTest([
            'status' => TemplateStatus::Approved->value,
            'components' => [
                'header' => ['format' => 'IMAGE', 'text' => null],
                'body' => ['text' => 'Foto', 'examples' => []],
                'footer' => ['text' => null],
                'buttons' => [],
            ],
        ]);

        $this->postJson('/api/v1/wara/send', [
            'to' => '081234567890',
            'template' => $template->name,
        ], $ctx['header'])->assertStatus(422);

        $this->postJson('/api/v1/wara/send', [
            'to' => '081234567890',
            'template' => $template->name,
            'header_media_url' => 'https://cdn.test/foto.jpg',
        ], $ctx['header'])->assertOk();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/send/image'));
    });

    it('menolak payload tanpa message maupun template', function () {
        $ctx = clientWithTokenForTemplateTest();

        $this->postJson('/api/v1/wara/send', [
            'to' => '081234567890',
        ], $ctx['header'])->assertStatus(422);
    });

    it('menolak nama template yang ambigu', function () {
        $ctx = clientWithTokenForTemplateTest();

        makeTemplateForTest(['status' => TemplateStatus::Approved->value, 'name' => 'sama']);
        makeTemplateForTest([
            'status' => TemplateStatus::Approved->value,
            'name' => 'sama',
            'user_uuid' => '22222222-2222-4222-8222-222222222222',
        ]);

        $this->postJson('/api/v1/wara/send', [
            'to' => '081234567890',
            'template' => 'sama',
            'parameters' => ['1' => 'A', '2' => 'B'],
        ], $ctx['header'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'template_required');

        Http::assertNothingSent();
    });

    it('menolak template yang tidak ada dengan error_code yang sama', function () {
        $ctx = clientWithTokenForTemplateTest();

        $this->postJson('/api/v1/wara/send', [
            'to' => '081234567890',
            'template' => 'tidak-pernah-ada',
        ], $ctx['header'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'template_required')
            ->assertJsonPath('status', 'failed');

        Http::assertNothingSent();
    });
});

describe('GET /api/v1/wara/templates', function () {
    it('hanya mengembalikan template APPROVED', function () {
        $issued = app(TokenManager::class)->issue('Token katalog', ['wara.templates.read']);

        makeTemplateForTest(['status' => TemplateStatus::Approved->value, 'name' => 'disetujui']);
        makeTemplateForTest(['status' => TemplateStatus::Draft->value, 'name' => 'draft']);

        $this->getJson('/api/v1/wara/templates', ['Authorization' => 'Bearer '.$issued['plain']])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'disetujui');
    });
});

function userWithWaraServiceForTest(): User
{
    $user = User::factory()->create();

    $service = RakacaService::query()->create([
        'name' => 'Wara',
        'slug' => 'wara',
        'icon' => 'message-circle',
        'actived' => true,
    ]);

    PersonHasService::query()->create([
        'user_uuid' => $user->uuid,
        'rakaca_service_id' => $service->id,
        'actived' => true,
    ]);

    return $user;
}

describe('panel guest Wara', function () {
    it('menolak operator yang organisasinya tidak berlangganan Wara', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/guest/wara/template')
            ->assertForbidden();
    });

    it('menampilkan daftar template untuk operator yang berlangganan', function () {
        $user = userWithWaraServiceForTest();

        makeTemplateForTest(['user_uuid' => $user->uuid, 'name' => 'punya_saya']);

        $this->actingAs($user)
            ->get('/guest/wara/template')
            ->assertOk()
            ->assertSee('punya_saya');
    });

    it('merender editor template tanpa error kompilasi', function () {
        $this->actingAs(userWithWaraServiceForTest())
            ->get('/guest/wara/template/create')
            ->assertOk()
            ->assertSee('Editor Template');
    });
});

describe('review template oleh admin', function () {
    it('menyetujui template PENDING', function () {
        $admin = User::factory()->create();
        Permission::findOrCreate('wara.template.review', 'web');
        $admin->givePermissionTo('wara.template.review');

        $template = makeTemplateForTest(['status' => TemplateStatus::Pending->value]);

        Livewire::actingAs($admin)
            ->test(TemplateReview::class, ['template' => $template])
            ->call('approve')
            ->assertHasNoErrors();

        expect($template->fresh()->status)->toBe(TemplateStatus::Approved);
    });

    it('menolak template PENDING beserta alasannya', function () {
        $admin = User::factory()->create();
        Permission::findOrCreate('wara.template.review', 'web');
        $admin->givePermissionTo('wara.template.review');

        $template = makeTemplateForTest(['status' => TemplateStatus::Pending->value]);

        Livewire::actingAs($admin)
            ->test(TemplateReview::class, ['template' => $template])
            ->set('rejectionReason', 'Body terlalu umum')
            ->call('reject')
            ->assertHasNoErrors();

        $fresh = $template->fresh();
        expect($fresh->status)->toBe(TemplateStatus::Rejected)
            ->and($fresh->rejection_reason)->toBe('Body terlalu umum');
    });
});

describe('dokumentasi API', function () {
    it('memuat panduan template dan menampilkan token variabel apa adanya', function () {
        $admin = User::factory()->create();
        Permission::findOrCreate('wara.client.read', 'web');
        $admin->givePermissionTo('wara.client.read');

        Livewire::actingAs($admin)
            ->test(DocsIndex::class)
            ->assertOk()
            ->assertSee('Mengirim Berbasis Template')
            ->assertSee('/api/v1/wara/templates')
            ->assertSee('header_media_url')
            ->assertSee('{{1}}');
    });
});
